/*
 * SchoolDesk — نسخه دسکتاپ پنل مدیریت مدرسه (v1.0.0)
 * Portable single-EXE desktop client for the school management system.
 *
 *  - Embedded HTTP server (mongoose) serving an embedded modern UI on 127.0.0.1
 *  - Embedded SQLite database: local mirror + offline change queue
 *  - Background sync thread: pushes queued changes / pulls fresh data
 *    from the site server (sync-api.php) whenever internet is available.
 *  - Windows 7/8/10/11 (x86 & x64). No runtime, no install, no dependencies.
 *
 * Build (see build.sh):
 *   zig cc -target x86_64-windows-gnu -Os main.c mongoose.c sqlite3.c
 *          -lws2_32 -lwinhttp -lshell32 -Wl,--subsystem,windows -o SchoolDesk-x64.exe
 */

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>

#include "mongoose.h"
#include "sqlite3.h"
#include "blobs.h" /* generated: BLOB_UI_HTML, BLOB_FONT_REG, BLOB_FONT_BOLD */

#define APP_VERSION "1.3.0"
#define SYNC_PERIOD_TICKS 60 /* x500ms = 30s */

#ifdef _WIN32
#include <windows.h>
#include <winhttp.h>
#include <shellapi.h>
#define SLEEP_MS(ms) Sleep(ms)
#else
#include <pthread.h>
#include <unistd.h>
#define SLEEP_MS(ms) usleep((ms) * 1000)
#endif

volatile int g_quit = 0;
volatile int g_sync_req = 0;
#ifdef _WIN32
int webwin_run(const char *url); /* webwin.c — native window host */
#endif
static char g_db_path[1024];
static sqlite3 *g_db = NULL; /* main (UI) thread connection */

/* ------------------------------------------------------------------ */
/* Small helpers                                                       */
/* ------------------------------------------------------------------ */

static void exe_dir(char *out, size_t cap) {
#ifdef _WIN32
  char buf[1024];
  DWORD n = GetModuleFileNameA(NULL, buf, sizeof(buf));
  if (n == 0) { snprintf(out, cap, "."); return; }
  buf[n] = 0;
  char *p = strrchr(buf, '\\');
  if (p) *p = 0;
  snprintf(out, cap, "%s", buf);
#else
  char buf[1024];
  ssize_t n = readlink("/proc/self/exe", buf, sizeof(buf) - 1);
  if (n <= 0) { snprintf(out, cap, "."); return; }
  buf[n] = 0;
  char *p = strrchr(buf, '/');
  if (p) *p = 0;
  snprintf(out, cap, "%s", buf);
#endif
}

/* ---- Jalali date (same algorithm as jdf.php gregorian_to_jalali) ---- */
static void greg_to_jalali(int gy, int gm, int gd, int *jy, int *jm, int *jd) {
  int g_d_m[] = {0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334};
  int gy2 = (gm > 2) ? (gy + 1) : gy;
  long days = 355666L + (365L * gy) + ((gy2 + 3) / 4) - ((gy2 + 99) / 100) +
              ((gy2 + 399) / 400) + gd + g_d_m[gm - 1];
  *jy = -1595 + (int) (33 * (days / 12053));
  days %= 12053;
  *jy += 4 * (int) (days / 1461);
  days %= 1461;
  if (days > 365) { *jy += (int) ((days - 1) / 365); days = (days - 1) % 365; }
  if (days < 186) { *jm = 1 + (int) (days / 31); *jd = 1 + (int) (days % 31); }
  else { *jm = 7 + (int) ((days - 186) / 30); *jd = 1 + (int) ((days - 186) % 30); }
}

static void jalali_today(char *out, size_t cap) { /* "1404/06/08" */
  time_t t = time(NULL);
  struct tm *lt = localtime(&t);
  int jy, jm, jd;
  greg_to_jalali(lt->tm_year + 1900, lt->tm_mon + 1, lt->tm_mday, &jy, &jm, &jd);
  snprintf(out, cap, "%04d/%02d/%02d", jy, jm, jd);
}

static void now_hm(char *out, size_t cap) { /* "08:31" */
  time_t t = time(NULL);
  struct tm *lt = localtime(&t);
  snprintf(out, cap, "%02d:%02d", lt->tm_hour, lt->tm_min);
}

/* JSON string escaper (for values we build ourselves) */
static void json_esc(const char *in, char *out, size_t cap) {
  size_t o = 0;
  for (const unsigned char *p = (const unsigned char *) in; *p && o + 8 < cap; p++) {
    switch (*p) {
      case '"': out[o++] = '\\'; out[o++] = '"'; break;
      case '\\': out[o++] = '\\'; out[o++] = '\\'; break;
      case '\n': out[o++] = '\\'; out[o++] = 'n'; break;
      case '\r': out[o++] = '\\'; out[o++] = 'r'; break;
      case '\t': out[o++] = '\\'; out[o++] = 't'; break;
      default:
        if (*p < 0x20) { o += (size_t) snprintf(out + o, cap - o, "\\u%04x", *p); }
        else out[o++] = (char) *p;
    }
  }
  out[o] = 0;
}

/* ------------------------------------------------------------------ */
/* SQLite                                                              */
/* ------------------------------------------------------------------ */

static int db_open(sqlite3 **pdb) {
  if (sqlite3_open(g_db_path, pdb) != SQLITE_OK) return -1;
  sqlite3_busy_timeout(*pdb, 8000);
  sqlite3_exec(*pdb, "PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL;", 0, 0, 0);
  return 0;
}

static void db_init(sqlite3 *db) {
  const char *ddl =
      "CREATE TABLE IF NOT EXISTS meta(k TEXT PRIMARY KEY, v TEXT);"
      "CREATE TABLE IF NOT EXISTS students(id INTEGER PRIMARY KEY AUTOINCREMENT,"
      " server_id INTEGER, uuid TEXT UNIQUE, national_id TEXT, first_name TEXT,"
      " last_name TEXT, father_name TEXT, grade_level TEXT, class_name TEXT,"
      " phone TEXT, father_phone TEXT, status TEXT DEFAULT 'active', deleted INTEGER DEFAULT 0);"
      "CREATE TABLE IF NOT EXISTS classes(id INTEGER PRIMARY KEY AUTOINCREMENT,"
      " server_id INTEGER, uuid TEXT UNIQUE, name TEXT, grade TEXT,"
      " academic_year TEXT, deleted INTEGER DEFAULT 0);"
      "CREATE TABLE IF NOT EXISTS teachers(id INTEGER PRIMARY KEY AUTOINCREMENT,"
      " server_id INTEGER, uuid TEXT UNIQUE, full_name TEXT, national_id TEXT,"
      " personnel_code TEXT, mobile TEXT, status TEXT DEFAULT '1', deleted INTEGER DEFAULT 0);"
      "CREATE TABLE IF NOT EXISTS attendance(id INTEGER PRIMARY KEY AUTOINCREMENT,"
      " uuid TEXT UNIQUE, student_uuid TEXT, date_jalali TEXT, status TEXT,"
      " minutes_late INTEGER DEFAULT 0, scan_time TEXT, deleted INTEGER DEFAULT 0,"
      " UNIQUE(student_uuid, date_jalali));"
      "CREATE TABLE IF NOT EXISTS grades(id INTEGER PRIMARY KEY AUTOINCREMENT,"
      " uuid TEXT UNIQUE, student_uuid TEXT, report_month TEXT, subject_name TEXT,"
      " score REAL, deleted INTEGER DEFAULT 0,"
      " UNIQUE(student_uuid, report_month, subject_name));"
      "CREATE TABLE IF NOT EXISTS subjects(id INTEGER PRIMARY KEY AUTOINCREMENT,"
      " name TEXT UNIQUE);"
      "CREATE TABLE IF NOT EXISTS queue(id INTEGER PRIMARY KEY AUTOINCREMENT,"
      " entity TEXT, op TEXT, payload TEXT,"
      " created_at TEXT DEFAULT (datetime('now','localtime')),"
      " tries INTEGER DEFAULT 0, last_error TEXT);";
  sqlite3_exec(db, ddl, 0, 0, 0);
}

static char *meta_get(sqlite3 *db, const char *k) { /* caller frees */
  sqlite3_stmt *st;
  char *res = NULL;
  if (sqlite3_prepare_v2(db, "SELECT v FROM meta WHERE k=?1", -1, &st, 0) == SQLITE_OK) {
    sqlite3_bind_text(st, 1, k, -1, SQLITE_TRANSIENT);
    if (sqlite3_step(st) == SQLITE_ROW) {
      const unsigned char *v = sqlite3_column_text(st, 0);
      if (v) res = strdup((const char *) v);
    }
    sqlite3_finalize(st);
  }
  return res;
}

static void meta_set(sqlite3 *db, const char *k, const char *v) {
  sqlite3_stmt *st;
  if (sqlite3_prepare_v2(db, "INSERT INTO meta(k,v) VALUES(?1,?2)"
                             " ON CONFLICT(k) DO UPDATE SET v=?2", -1, &st, 0) == SQLITE_OK) {
    sqlite3_bind_text(st, 1, k, -1, SQLITE_TRANSIENT);
    sqlite3_bind_text(st, 2, v ? v : "", -1, SQLITE_TRANSIENT);
    sqlite3_step(st);
    sqlite3_finalize(st);
  }
}

/* Run a query expecting a single TEXT result; binds are (n, values[]) */
static char *db_text(sqlite3 *db, const char *sql, int nbind, const char **binds) {
  sqlite3_stmt *st;
  char *res = NULL;
  if (sqlite3_prepare_v2(db, sql, -1, &st, 0) != SQLITE_OK) return NULL;
  for (int i = 0; i < nbind; i++)
    sqlite3_bind_text(st, i + 1, binds[i] ? binds[i] : "", -1, SQLITE_TRANSIENT);
  if (sqlite3_step(st) == SQLITE_ROW) {
    const unsigned char *v = sqlite3_column_text(st, 0);
    res = strdup(v ? (const char *) v : "");
  }
  sqlite3_finalize(st);
  return res;
}

static int db_exec_b(sqlite3 *db, const char *sql, int nbind, const char **binds) {
  sqlite3_stmt *st;
  if (sqlite3_prepare_v2(db, sql, -1, &st, 0) != SQLITE_OK) return -1;
  for (int i = 0; i < nbind; i++)
    sqlite3_bind_text(st, i + 1, binds[i] ? binds[i] : "", -1, SQLITE_TRANSIENT);
  int rc = sqlite3_step(st);
  sqlite3_finalize(st);
  return (rc == SQLITE_DONE || rc == SQLITE_ROW) ? 0 : -1;
}

static long db_long(sqlite3 *db, const char *sql) {
  sqlite3_stmt *st;
  long v = 0;
  if (sqlite3_prepare_v2(db, sql, -1, &st, 0) == SQLITE_OK) {
    if (sqlite3_step(st) == SQLITE_ROW) v = (long) sqlite3_column_int64(st, 0);
    sqlite3_finalize(st);
  }
  return v;
}

/* ------------------------------------------------------------------ */
/* HTTP client (background sync)                                       */
/* ------------------------------------------------------------------ */

/* parse http(s)://host[:port]/path */
static int parse_url(const char *url, int *https, char *host, size_t hcap,
                     int *port, char *path, size_t pcap) {
  const char *p = url;
  if (!strncmp(p, "https://", 8)) { *https = 1; p += 8; *port = 443; }
  else if (!strncmp(p, "http://", 7)) { *https = 0; p += 7; *port = 80; }
  else return -1;
  size_t i = 0;
  while (*p && *p != ':' && *p != '/' && i + 1 < hcap) host[i++] = *p++;
  host[i] = 0;
  if (i == 0) return -1;
  if (*p == ':') { p++; *port = atoi(p); while (*p && *p != '/') p++; }
  snprintf(path, pcap, "%s", *p ? p : "/");
  return 0;
}

#ifdef _WIN32
/* one HTTP round-trip; on 3xx *loc receives the absolute Location header */
static int http_post_once(const char *url, const char *body, int insecure,
                          char **out, size_t *outlen, char *loc, size_t loccap) {
  int https = 0, port = 0, status = -1;
  char host[256], path[1024];
  *out = NULL; *outlen = 0;
  if (loc && loccap) loc[0] = 0;
  if (parse_url(url, &https, host, sizeof(host), &port, path, sizeof(path)))
    return -1;

  wchar_t whost[256], wpath[1024];
  MultiByteToWideChar(CP_UTF8, 0, host, -1, whost, 256);
  MultiByteToWideChar(CP_UTF8, 0, path, -1, wpath, 1024);

  HINTERNET ses = WinHttpOpen(L"SchoolDesk/1.0",
                              WINHTTP_ACCESS_TYPE_DEFAULT_PROXY,
                              WINHTTP_NO_PROXY_NAME, WINHTTP_NO_PROXY_BYPASS, 0);
  if (!ses) return -1;
  /* enable TLS 1.1/1.2 explicitly (Windows 7 compat) */
  DWORD protos = 0x00000080 /*TLS1*/ | 0x00000200 /*TLS1.1*/ | 0x00000800 /*TLS1.2*/;
  WinHttpSetOption(ses, WINHTTP_OPTION_SECURE_PROTOCOLS, &protos, sizeof(protos));
  WinHttpSetTimeouts(ses, 8000, 8000, 15000, 20000);

  HINTERNET con = WinHttpConnect(ses, whost, (INTERNET_PORT) port, 0);
  if (con) {
    HINTERNET req = WinHttpOpenRequest(con, L"POST", wpath, NULL,
                                       WINHTTP_NO_REFERER,
                                       WINHTTP_DEFAULT_ACCEPT_TYPES,
                                       https ? WINHTTP_FLAG_SECURE : 0);
    if (req) {
      /* handle redirects OURSELVES: WinHTTP drops the POST body when
       * auto-following (http->https or www redirects broke login) */
      DWORD rp = WINHTTP_OPTION_REDIRECT_POLICY_NEVER;
      WinHttpSetOption(req, WINHTTP_OPTION_REDIRECT_POLICY, &rp, sizeof(rp));
      if (insecure && https) {
        DWORD f = SECURITY_FLAG_IGNORE_UNKNOWN_CA |
                  SECURITY_FLAG_IGNORE_CERT_CN_INVALID |
                  SECURITY_FLAG_IGNORE_CERT_DATE_INVALID;
        WinHttpSetOption(req, WINHTTP_OPTION_SECURITY_FLAGS, &f, sizeof(f));
      }
      DWORD blen = (DWORD) strlen(body);
      if (WinHttpSendRequest(req, L"Content-Type: application/json\r\n",
                             (DWORD) -1L, (LPVOID) body, blen, blen, 0) &&
          WinHttpReceiveResponse(req, NULL)) {
        DWORD st = 0, stl = sizeof(st);
        WinHttpQueryHeaders(req, WINHTTP_QUERY_STATUS_CODE | WINHTTP_QUERY_FLAG_NUMBER,
                            WINHTTP_HEADER_NAME_BY_INDEX, &st, &stl,
                            WINHTTP_NO_HEADER_INDEX);
        status = (int) st;
        if (status >= 301 && status <= 308 && loc && loccap) {
          wchar_t wloc[1024];
          DWORD wl = sizeof(wloc);
          if (WinHttpQueryHeaders(req, WINHTTP_QUERY_LOCATION,
                                  WINHTTP_HEADER_NAME_BY_INDEX, wloc, &wl,
                                  WINHTTP_NO_HEADER_INDEX))
            WideCharToMultiByte(CP_UTF8, 0, wloc, -1, loc, (int) loccap, NULL, NULL);
        }
        size_t cap = 65536, len = 0;
        char *buf = (char *) malloc(cap);
        DWORD got = 0;
        do {
          if (len + 16384 + 1 > cap) { cap *= 2; buf = (char *) realloc(buf, cap); }
          got = 0;
          if (!WinHttpReadData(req, buf + len, 16384, &got)) break;
          len += got;
        } while (got > 0);
        buf[len] = 0;
        *out = buf; *outlen = len;
      }
      WinHttpCloseHandle(req);
    }
    WinHttpCloseHandle(con);
  }
  WinHttpCloseHandle(ses);
  return status;
}

/* follows up to 3 redirects (keeping method+body), returns final status */
static int http_post(const char *url, const char *body, int insecure,
                     char **out, size_t *outlen) {
  char cur[1200], loc[1200];
  snprintf(cur, sizeof(cur), "%s", url);
  int status = -1;
  for (int hop = 0; hop < 4; hop++) {
    status = http_post_once(cur, body, insecure, out, outlen, loc, sizeof(loc));
    if (status >= 301 && status <= 308 && loc[0]) {
      if (!strncmp(loc, "http://", 7) || !strncmp(loc, "https://", 8)) {
        snprintf(cur, sizeof(cur), "%s", loc);
      } else if (loc[0] == '/') { /* relative redirect: keep scheme+host */
        int https = 0, port = 0;
        char host[256], path[1024];
        if (parse_url(cur, &https, host, sizeof(host), &port, path, sizeof(path)))
          return status;
        if ((https && port == 443) || (!https && port == 80))
          snprintf(cur, sizeof(cur), "%s://%s%s", https ? "https" : "http", host, loc);
        else
          snprintf(cur, sizeof(cur), "%s://%s:%d%s", https ? "https" : "http", host, port, loc);
      } else {
        return status;
      }
      if (*out) { free(*out); *out = NULL; *outlen = 0; }
      continue;
    }
    return status;
  }
  return status;
}
#else
/* Linux debug build: use system curl (sandbox testing only) */
static int http_post(const char *url, const char *body, int insecure,
                     char **out, size_t *outlen) {
  char tmpl[] = "/tmp/sd_bodyXXXXXX";
  int fd = mkstemp(tmpl);
  if (fd < 0) return -1;
  FILE *f = fdopen(fd, "w");
  fputs(body, f);
  fclose(f);
  char cmd[2048];
  snprintf(cmd, sizeof(cmd),
           "curl -s -m 25 -L --post301 --post302 --post303 %s -X POST "
           "-H 'Content-Type: application/json' "
           "--data-binary @%s -w '\\n%%{http_code}' '%s' 2>/dev/null",
           insecure ? "-k" : "", tmpl, url);
  FILE *p = popen(cmd, "r");
  if (!p) { unlink(tmpl); return -1; }
  size_t cap = 65536, len = 0;
  char *buf = (char *) malloc(cap);
  int c;
  while ((c = fgetc(p)) != EOF) {
    if (len + 2 > cap) { cap *= 2; buf = (char *) realloc(buf, cap); }
    buf[len++] = (char) c;
  }
  pclose(p);
  unlink(tmpl);
  buf[len] = 0;
  char *nl = strrchr(buf, '\n');
  int status = -1;
  if (nl) { status = atoi(nl + 1); *nl = 0; len = (size_t)(nl - buf); }
  *out = buf; *outlen = len;
  return status;
}
#endif

/* ------------------------------------------------------------------ */
/* Sync engine (background thread, own sqlite connection)              */
/* ------------------------------------------------------------------ */

static void sync_status(sqlite3 *db, const char *state, const char *msg) {
  meta_set(db, "sync_state", state);
  meta_set(db, "sync_msg", msg ? msg : "");
}

static void build_api_url(const char *base, char *out, size_t cap) {
  size_t n = strlen(base);
  while (n > 0 && base[n - 1] == '/') n--;
  if (n > 12 && !strncmp(base + n - 12, "sync-api.php", 12))
    snprintf(out, cap, "%.*s", (int) n, base);
  else
    snprintf(out, cap, "%.*s/sync-api.php", (int) n, base);
}

/* returns 0 on success */
static int sync_push_batch(sqlite3 *db, const char *api, const char *key, int insecure) {
  const char *kb[1] = { key };
  char *ops = db_text(db,
      "SELECT json_group_array(json_object('op_id',id,'entity',entity,'op',op,"
      "'payload',json(payload))) FROM (SELECT * FROM queue ORDER BY id LIMIT 40)",
      0, NULL);
  (void) kb;
  if (!ops || !strcmp(ops, "[]")) { free(ops); return 0; }

  char kesc[256];
  json_esc(key, kesc, sizeof(kesc));
  size_t blen = strlen(ops) + 512;
  char *body = (char *) malloc(blen);
  snprintf(body, blen, "{\"action\":\"push\",\"api_key\":\"%s\",\"ops\":%s}", kesc, ops);
  free(ops);

  char *resp = NULL;
  size_t rlen = 0;
  int st = http_post(api, body, insecure, &resp, &rlen);
  free(body);
  if (st != 200 || !resp) { free(resp); return -1; }

  const char *rb[1] = { resp };
  /* remove acknowledged ops */
  db_exec_b(db,
      "DELETE FROM queue WHERE id IN (SELECT json_extract(value,'$.op_id')"
      " FROM json_each(?1,'$.results') WHERE json_extract(value,'$.ok')=1)",
      1, rb);
  /* record errors on failed ops */
  db_exec_b(db,
      "UPDATE queue SET tries=tries+1, last_error=COALESCE((SELECT json_extract(value,'$.error')"
      " FROM json_each(?1,'$.results') WHERE json_extract(value,'$.op_id')=queue.id"
      " AND json_extract(value,'$.ok')=0),last_error)"
      " WHERE id IN (SELECT json_extract(value,'$.op_id') FROM json_each(?1,'$.results')"
      " WHERE json_extract(value,'$.ok')=0)",
      1, rb);
  /* map returned server ids onto local rows (by uuid) */
  const char *tables[3] = { "students", "classes", "teachers" };
  const char *ent[3] = { "student", "class", "teacher" };
  for (int i = 0; i < 3; i++) {
    char sql[640];
    snprintf(sql, sizeof(sql),
        "UPDATE %s SET server_id=(SELECT json_extract(value,'$.server_id')"
        " FROM json_each(?1,'$.results') WHERE json_extract(value,'$.uuid')=%s.uuid"
        " AND json_extract(value,'$.entity')='%s' AND json_extract(value,'$.ok')=1"
        " AND json_extract(value,'$.server_id') IS NOT NULL)"
        " WHERE uuid IN (SELECT json_extract(value,'$.uuid') FROM json_each(?1,'$.results')"
        " WHERE json_extract(value,'$.entity')='%s' AND json_extract(value,'$.ok')=1"
        " AND json_extract(value,'$.server_id') IS NOT NULL)",
        tables[i], tables[i], ent[i], ent[i]);
    db_exec_b(db, sql, 1, rb);
    /* purge locally-deleted rows that have no pending ops left */
    snprintf(sql, sizeof(sql),
        "DELETE FROM %s WHERE deleted=1 AND uuid NOT IN"
        " (SELECT json_extract(payload,'$.uuid') FROM queue WHERE entity='%s')",
        tables[i], ent[i]);
    sqlite3_exec(db, sql, 0, 0, 0);
  }
  free(resp);
  return 0;
}

static int sync_pull(sqlite3 *db, const char *api, const char *key, int insecure) {
  char kesc[256], body[512];
  json_esc(key, kesc, sizeof(kesc));
  snprintf(body, sizeof(body), "{\"action\":\"pull\",\"api_key\":\"%s\"}", kesc);
  char *resp = NULL;
  size_t rlen = 0;
  int st = http_post(api, body, insecure, &resp, &rlen);
  if (st != 200 || !resp) { free(resp); return -1; }

  struct mg_str js = mg_str_n(resp, rlen);
  bool okv = false;
  if (!mg_json_get_bool(js, "$.ok", &okv) || !okv) { free(resp); return -1; }

  const char *rb[1] = { resp };
  sqlite3_exec(db, "BEGIN", 0, 0, 0);
  sqlite3_exec(db, "DELETE FROM students; DELETE FROM classes; DELETE FROM teachers;"
                   " DELETE FROM attendance; DELETE FROM grades; DELETE FROM subjects;", 0, 0, 0);
  db_exec_b(db,
      "INSERT INTO students(server_id,uuid,national_id,first_name,last_name,father_name,"
      "grade_level,class_name,phone,father_phone,status) SELECT"
      " json_extract(value,'$.id'), json_extract(value,'$.uuid'),"
      " json_extract(value,'$.national_id'), json_extract(value,'$.first_name'),"
      " json_extract(value,'$.last_name'), json_extract(value,'$.father_name'),"
      " json_extract(value,'$.grade_level'), json_extract(value,'$.class_name'),"
      " json_extract(value,'$.phone'), json_extract(value,'$.father_phone'),"
      " COALESCE(json_extract(value,'$.status'),'active') FROM json_each(?1,'$.students')",
      1, rb);
  db_exec_b(db,
      "INSERT INTO classes(server_id,uuid,name,grade,academic_year) SELECT"
      " json_extract(value,'$.id'), json_extract(value,'$.uuid'),"
      " json_extract(value,'$.name'), json_extract(value,'$.grade'),"
      " json_extract(value,'$.academic_year') FROM json_each(?1,'$.classes')",
      1, rb);
  db_exec_b(db,
      "INSERT INTO teachers(server_id,uuid,full_name,national_id,personnel_code,mobile,status)"
      " SELECT json_extract(value,'$.id'), json_extract(value,'$.uuid'),"
      " json_extract(value,'$.full_name'), json_extract(value,'$.national_id'),"
      " json_extract(value,'$.personnel_code'), json_extract(value,'$.mobile'),"
      " COALESCE(json_extract(value,'$.status'),'1') FROM json_each(?1,'$.teachers')",
      1, rb);
  db_exec_b(db,
      "INSERT OR IGNORE INTO attendance(uuid,student_uuid,date_jalali,status,minutes_late,scan_time)"
      " SELECT json_extract(value,'$.uuid'), json_extract(value,'$.student_uuid'),"
      " json_extract(value,'$.date_jalali'), json_extract(value,'$.status'),"
      " COALESCE(json_extract(value,'$.minutes_late'),0), json_extract(value,'$.scan_time')"
      " FROM json_each(?1,'$.attendance')",
      1, rb);
  db_exec_b(db,
      "INSERT OR IGNORE INTO grades(uuid,student_uuid,report_month,subject_name,score)"
      " SELECT json_extract(value,'$.uuid'), json_extract(value,'$.student_uuid'),"
      " json_extract(value,'$.report_month'), json_extract(value,'$.subject_name'),"
      " json_extract(value,'$.score') FROM json_each(?1,'$.grades')",
      1, rb);
  db_exec_b(db,
      "INSERT OR IGNORE INTO subjects(name)"
      " SELECT value FROM json_each(?1,'$.subjects') WHERE value IS NOT NULL AND value<>''",
      1, rb);
  sqlite3_exec(db, "COMMIT", 0, 0, 0);

  char *sname = mg_json_get_str(js, "$.school_name");
  char *year = mg_json_get_str(js, "$.year");
  if (sname) { meta_set(db, "school_name", sname); free(sname); }
  if (year) { meta_set(db, "year", year); free(year); }

  char *now = db_text(db, "SELECT datetime('now','localtime')", 0, NULL);
  meta_set(db, "last_sync", now ? now : "");
  free(now);
  free(resp);
  return 0;
}

static void do_sync(sqlite3 *db) {
  char *base = meta_get(db, "server_url");
  char *key = meta_get(db, "api_key");
  char *ins = meta_get(db, "insecure");
  int insecure = (ins && !strcmp(ins, "1"));
  free(ins);
  if (!base || !*base || !key || !*key) {
    sync_status(db, "unconfigured", "اتصال به سرور هنوز پیکربندی نشده است");
    free(base); free(key);
    return;
  }
  char api[1200];
  build_api_url(base, api, sizeof(api));

  sync_status(db, "syncing", "در حال همگام‌سازی...");
  int fail = 0;
  /* push everything queued */
  for (int round = 0; round < 50; round++) {
    long pend = db_long(db, "SELECT COUNT(*) FROM queue");
    if (pend == 0) break;
    long before = pend;
    if (sync_push_batch(db, api, key, insecure) != 0) { fail = 1; break; }
    long after = db_long(db, "SELECT COUNT(*) FROM queue");
    if (after >= before) break; /* server rejected everything — stop looping */
  }
  long remaining = db_long(db, "SELECT COUNT(*) FROM queue");
  if (!fail && remaining == 0) {
    if (sync_pull(db, api, key, insecure) == 0)
      sync_status(db, "ok", "همگام با سرور");
    else { fail = 1; sync_status(db, "offline", "سرور در دسترس نیست — داده‌ها محلی نگه‌داری می‌شوند"); }
  } else if (fail) {
    sync_status(db, "offline", "سرور در دسترس نیست — تغییرات در صف نگه‌داری می‌شوند");
  } else {
    sync_status(db, "pending", "برخی تغییرات توسط سرور پذیرفته نشدند — جزئیات در صف");
  }
  free(base);
  free(key);
}

#ifdef _WIN32
static DWORD WINAPI sync_thread(LPVOID arg) {
#else
static void *sync_thread(void *arg) {
#endif
  (void) arg;
  sqlite3 *db = NULL;
  if (db_open(&db) != 0) goto out;
  db_init(db);
  int tick = SYNC_PERIOD_TICKS - 4; /* first sync ~2s after start */
  while (!g_quit) {
    SLEEP_MS(500);
    tick++;
    if (g_sync_req || tick >= SYNC_PERIOD_TICKS) {
      g_sync_req = 0;
      tick = 0;
      do_sync(db);
    }
  }
out:
  if (db) sqlite3_close(db);
#ifdef _WIN32
  return 0;
#else
  return NULL;
#endif
}

/* ------------------------------------------------------------------ */
/* Local HTTP API                                                      */
/* ------------------------------------------------------------------ */

static void reply_json(struct mg_connection *c, int code, const char *body) {
  mg_printf(c,
            "HTTP/1.1 %d OK\r\nContent-Type: application/json; charset=utf-8\r\n"
            "Cache-Control: no-store\r\nContent-Length: %d\r\n\r\n",
            code, (int) strlen(body));
  mg_send(c, body, strlen(body));
}

static void reply_blob(struct mg_connection *c, const char *mime,
                       const unsigned char *data, size_t len, int cache) {
  mg_printf(c,
            "HTTP/1.1 200 OK\r\nContent-Type: %s\r\n%s"
            "Content-Length: %d\r\n\r\n",
            mime, cache ? "Cache-Control: max-age=604800\r\n" : "Cache-Control: no-store\r\n",
            (int) len);
  mg_send(c, data, len);
}

static void api_state(struct mg_connection *c) {
  char *sname = meta_get(g_db, "school_name");
  char *year = meta_get(g_db, "year");
  char *last = meta_get(g_db, "last_sync");
  char *state = meta_get(g_db, "sync_state");
  char *msg = meta_get(g_db, "sync_msg");
  char *surl = meta_get(g_db, "server_url");
  long ns = db_long(g_db, "SELECT COUNT(*) FROM students WHERE deleted=0");
  long nc = db_long(g_db, "SELECT COUNT(*) FROM classes WHERE deleted=0");
  long nt = db_long(g_db, "SELECT COUNT(*) FROM teachers WHERE deleted=0");
  long nq = db_long(g_db, "SELECT COUNT(*) FROM queue");
  char *key = meta_get(g_db, "api_key");
  char e1[300], e2[128], e3[128], e4[300], e5[600];
  json_esc(sname ? sname : "", e1, sizeof(e1));
  json_esc(year ? year : "", e2, sizeof(e2));
  json_esc(last ? last : "", e3, sizeof(e3));
  json_esc(surl ? surl : "", e4, sizeof(e4));
  json_esc(msg ? msg : "", e5, sizeof(e5));
  char body[2600];
  snprintf(body, sizeof(body),
           "{\"ok\":true,\"version\":\"%s\",\"configured\":%s,\"school_name\":\"%s\","
           "\"year\":\"%s\",\"last_sync\":\"%s\",\"server_url\":\"%s\","
           "\"sync_state\":\"%s\",\"sync_msg\":\"%s\","
           "\"students\":%ld,\"classes\":%ld,\"teachers\":%ld,\"queue\":%ld}",
           APP_VERSION, (key && *key) ? "true" : "false", e1, e2, e3, e4,
           state ? state : "unconfigured", e5, ns, nc, nt, nq);
  reply_json(c, 200, body);
  free(sname); free(year); free(last); free(state); free(msg); free(surl); free(key);
}

static void api_list(struct mg_connection *c, struct mg_http_message *hm, const char *entity) {
  char q[128] = "", cls[128] = "";
  mg_http_get_var(&hm->query, "q", q, sizeof(q));
  mg_http_get_var(&hm->query, "class", cls, sizeof(cls));
  char like[140];
  snprintf(like, sizeof(like), "%%%s%%", q);
  char *json = NULL;
  if (!strcmp(entity, "students")) {
    const char *b[3] = { like, like, cls };
    json = db_text(g_db,
        "SELECT COALESCE(json_group_array(json_object('uuid',uuid,'server_id',server_id,"
        "'national_id',national_id,'first_name',first_name,'last_name',last_name,"
        "'father_name',father_name,'grade_level',grade_level,'class_name',class_name,"
        "'phone',phone,'father_phone',father_phone,'status',status)),'[]') FROM"
        " (SELECT * FROM students WHERE deleted=0 AND"
        "  (?1='%%%%' OR first_name LIKE ?1 OR last_name LIKE ?2 OR national_id LIKE ?1)"
        "  AND (?3='' OR class_name=?3)"
        "  ORDER BY class_name, last_name, first_name)",
        3, b);
  } else if (!strcmp(entity, "classes")) {
    json = db_text(g_db,
        "SELECT COALESCE(json_group_array(json_object('uuid',uuid,'server_id',server_id,"
        "'name',name,'grade',grade,'academic_year',academic_year,"
        "'student_count',(SELECT COUNT(*) FROM students s WHERE s.class_name=classes.name AND s.deleted=0))),'[]')"
        " FROM (SELECT * FROM classes WHERE deleted=0 ORDER BY grade, name) classes",
        0, NULL);
  } else {
    const char *b[2] = { like, like };
    json = db_text(g_db,
        "SELECT COALESCE(json_group_array(json_object('uuid',uuid,'server_id',server_id,"
        "'full_name',full_name,'national_id',national_id,'personnel_code',personnel_code,"
        "'mobile',mobile,'status',status)),'[]') FROM"
        " (SELECT * FROM teachers WHERE deleted=0 AND"
        "  (?1='%%%%' OR full_name LIKE ?1 OR national_id LIKE ?2)"
        "  ORDER BY full_name)",
        2, b);
  }
  if (!json) { reply_json(c, 500, "{\"ok\":false}"); return; }
  size_t cap = strlen(json) + 32;
  char *body = (char *) malloc(cap);
  snprintf(body, cap, "{\"ok\":true,\"items\":%s}", json);
  reply_json(c, 200, body);
  free(body);
  free(json);
}

/* Apply a CRUD op locally + enqueue for server sync */
static void api_mutate(struct mg_connection *c, struct mg_http_message *hm) {
  struct mg_str js = mg_str_n(hm->body.ptr, hm->body.len);
  char *entity = mg_json_get_str(js, "$.entity");
  char *op = mg_json_get_str(js, "$.op");
  int plen = 0;
  int poff = mg_json_get(js, "$.payload", &plen);
  if (!entity || !op || poff < 0 || plen <= 0) {
    free(entity); free(op);
    reply_json(c, 400, "{\"ok\":false,\"msg\":\"درخواست نامعتبر\"}");
    return;
  }
  char *payload = (char *) malloc((size_t) plen + 1);
  memcpy(payload, hm->body.ptr + poff, (size_t) plen);
  payload[plen] = 0;
  const char *pb[1] = { payload };

  int ok = 0;
  if (!strcmp(entity, "student")) {
    if (!strcmp(op, "create"))
      ok = db_exec_b(g_db,
          "INSERT INTO students(uuid,national_id,first_name,last_name,father_name,"
          "grade_level,class_name,phone,father_phone,status) VALUES("
          "json_extract(?1,'$.uuid'), json_extract(?1,'$.national_id'),"
          "json_extract(?1,'$.first_name'), json_extract(?1,'$.last_name'),"
          "json_extract(?1,'$.father_name'), json_extract(?1,'$.grade_level'),"
          "json_extract(?1,'$.class_name'), json_extract(?1,'$.phone'),"
          "json_extract(?1,'$.father_phone'), 'active')", 1, pb) == 0;
    else if (!strcmp(op, "update"))
      ok = db_exec_b(g_db,
          "UPDATE students SET national_id=json_extract(?1,'$.national_id'),"
          "first_name=json_extract(?1,'$.first_name'), last_name=json_extract(?1,'$.last_name'),"
          "father_name=json_extract(?1,'$.father_name'), grade_level=json_extract(?1,'$.grade_level'),"
          "class_name=json_extract(?1,'$.class_name'), phone=json_extract(?1,'$.phone'),"
          "father_phone=json_extract(?1,'$.father_phone'),"
          "status=COALESCE(json_extract(?1,'$.status'),status)"
          " WHERE uuid=json_extract(?1,'$.uuid')", 1, pb) == 0;
    else
      ok = db_exec_b(g_db, "UPDATE students SET deleted=1 WHERE uuid=json_extract(?1,'$.uuid')",
                     1, pb) == 0;
  } else if (!strcmp(entity, "class")) {
    if (!strcmp(op, "create"))
      ok = db_exec_b(g_db,
          "INSERT INTO classes(uuid,name,grade,academic_year) VALUES("
          "json_extract(?1,'$.uuid'), json_extract(?1,'$.name'),"
          "json_extract(?1,'$.grade'), json_extract(?1,'$.academic_year'))", 1, pb) == 0;
    else if (!strcmp(op, "update"))
      ok = db_exec_b(g_db,
          "UPDATE classes SET name=json_extract(?1,'$.name'), grade=json_extract(?1,'$.grade')"
          " WHERE uuid=json_extract(?1,'$.uuid')", 1, pb) == 0;
    else
      ok = db_exec_b(g_db, "UPDATE classes SET deleted=1 WHERE uuid=json_extract(?1,'$.uuid')",
                     1, pb) == 0;
  } else if (!strcmp(entity, "teacher")) {
    if (!strcmp(op, "create"))
      ok = db_exec_b(g_db,
          "INSERT INTO teachers(uuid,full_name,national_id,personnel_code,mobile,status)"
          " VALUES(json_extract(?1,'$.uuid'), json_extract(?1,'$.full_name'),"
          "json_extract(?1,'$.national_id'), json_extract(?1,'$.personnel_code'),"
          "json_extract(?1,'$.mobile'), '1')", 1, pb) == 0;
    else if (!strcmp(op, "update"))
      ok = db_exec_b(g_db,
          "UPDATE teachers SET full_name=json_extract(?1,'$.full_name'),"
          "national_id=json_extract(?1,'$.national_id'),"
          "personnel_code=json_extract(?1,'$.personnel_code'),"
          "mobile=json_extract(?1,'$.mobile') WHERE uuid=json_extract(?1,'$.uuid')", 1, pb) == 0;
    else
      ok = db_exec_b(g_db, "UPDATE teachers SET deleted=1 WHERE uuid=json_extract(?1,'$.uuid')",
                     1, pb) == 0;
  }

  if (ok) {
    /* enqueue for background sync (include server_id when known) */
    const char *qb[3] = { entity, op, payload };
    db_exec_b(g_db,
        "INSERT INTO queue(entity,op,payload) VALUES(?1,?2,"
        " json_set(?3,'$.server_id',"
        "  (SELECT server_id FROM (SELECT server_id, uuid FROM students WHERE ?1='student'"
        "   UNION ALL SELECT server_id, uuid FROM classes WHERE ?1='class'"
        "   UNION ALL SELECT server_id, uuid FROM teachers WHERE ?1='teacher')"
        "   WHERE uuid=json_extract(?3,'$.uuid'))))",
        3, qb);
    g_sync_req = 1;
    reply_json(c, 200, "{\"ok\":true}");
  } else {
    reply_json(c, 500, "{\"ok\":false,\"msg\":\"خطا در ذخیره محلی (شاید کد ملی تکراری است)\"}");
  }
  free(entity); free(op); free(payload);
}

static void api_queue(struct mg_connection *c) {
  char *json = db_text(g_db,
      "SELECT COALESCE(json_group_array(json_object('id',id,'entity',entity,'op',op,"
      "'created_at',created_at,'tries',tries,'last_error',last_error,"
      "'title',COALESCE(json_extract(payload,'$.first_name')||' '||json_extract(payload,'$.last_name'),"
      "json_extract(payload,'$.name'), json_extract(payload,'$.full_name'),''))),'[]')"
      " FROM (SELECT * FROM queue ORDER BY id)",
      0, NULL);
  if (!json) { reply_json(c, 500, "{\"ok\":false}"); return; }
  size_t cap = strlen(json) + 32;
  char *body = (char *) malloc(cap);
  snprintf(body, cap, "{\"ok\":true,\"items\":%s}", json);
  reply_json(c, 200, body);
  free(body); free(json);
}

/* ---- attendance: list one day (students joined with that day's records) ---- */
static void api_attendance(struct mg_connection *c, struct mg_http_message *hm) {
  char date[24] = "", cls[128] = "";
  mg_http_get_var(&hm->query, "date", date, sizeof(date));
  mg_http_get_var(&hm->query, "class", cls, sizeof(cls));
  if (date[0] == 0) jalali_today(date, sizeof(date));
  const char *b[2] = { date, cls };
  char *json = db_text(g_db,
      "SELECT COALESCE(json_group_array(json_object('uuid',s.uuid,"
      "'first_name',s.first_name,'last_name',s.last_name,'class_name',s.class_name,"
      "'att_status',a.status,'minutes_late',a.minutes_late,'scan_time',a.scan_time)),'[]')"
      " FROM (SELECT * FROM students WHERE deleted=0 AND (?2='' OR class_name=?2)"
      "       ORDER BY class_name, last_name, first_name) s"
      " LEFT JOIN attendance a ON a.student_uuid=s.uuid AND a.date_jalali=?1 AND a.deleted=0",
      2, b);
  if (!json) { reply_json(c, 500, "{\"ok\":false}"); return; }
  char de[32];
  json_esc(date, de, sizeof(de));
  size_t cap = strlen(json) + 96;
  char *body = (char *) malloc(cap);
  snprintf(body, cap, "{\"ok\":true,\"date\":\"%s\",\"items\":%s}", de, json);
  reply_json(c, 200, body);
  free(body); free(json);
}

/* ---- attendance: set one student's status for a date ---- */
static void api_attendance_set(struct mg_connection *c, struct mg_http_message *hm) {
  struct mg_str js = mg_str_n(hm->body.ptr, hm->body.len);
  char *stu = mg_json_get_str(js, "$.student_uuid");
  char *date = mg_json_get_str(js, "$.date_jalali");
  char *status = mg_json_get_str(js, "$.status"); /* present|late|absent|clear */
  long mlate = mg_json_get_long(js, "$.minutes_late", 0);
  if (!stu || !status) {
    free(stu); free(date); free(status);
    reply_json(c, 400, "{\"ok\":false,\"msg\":\"درخواست نامعتبر\"}");
    return;
  }
  char today[24];
  if (!date || !*date) { jalali_today(today, sizeof(today)); }
  else snprintf(today, sizeof(today), "%s", date);
  char hm2[8], ml[16], au[80];
  now_hm(hm2, sizeof(hm2));
  snprintf(ml, sizeof(ml), "%ld", mlate);
  /* deterministic uuid per (student,date) keeps ops idempotent */
  snprintf(au, sizeof(au), "att-%.36s-%s", stu, today);
  for (char *p = au; *p; p++) if (*p == '/') *p = '-';

  int ok;
  if (!strcmp(status, "clear")) {
    const char *b[2] = { stu, today };
    ok = db_exec_b(g_db,
        "DELETE FROM attendance WHERE student_uuid=?1 AND date_jalali=?2", 2, b) == 0;
  } else {
    const char *b[6] = { au, stu, today, status, ml, hm2 };
    ok = db_exec_b(g_db,
        "INSERT INTO attendance(uuid,student_uuid,date_jalali,status,minutes_late,scan_time)"
        " VALUES(?1,?2,?3,?4,?5,?6)"
        " ON CONFLICT(student_uuid,date_jalali) DO UPDATE SET"
        " status=?4, minutes_late=?5, scan_time=?6, deleted=0", 6, b) == 0;
  }
  if (ok) {
    const char *qb[6] = { au, stu, today, status, ml, hm2 };
    db_exec_b(g_db,
        "INSERT INTO queue(entity,op,payload) VALUES('attendance','set',"
        " json_object('uuid',?1,'student_uuid',?2,'date_jalali',?3,'status',?4,"
        " 'minutes_late',CAST(?5 AS INTEGER),'scan_time',?6,"
        " 'server_id',(SELECT server_id FROM students WHERE uuid=?2)))", 6, qb);
    g_sync_req = 1;
    reply_json(c, 200, "{\"ok\":true}");
  } else {
    reply_json(c, 500, "{\"ok\":false,\"msg\":\"خطا در ذخیره محلی\"}");
  }
  free(stu); free(date); free(status);
}

/* ---- attendance: monthly summary per student ---- */
static void api_attendance_report(struct mg_connection *c, struct mg_http_message *hm) {
  char month[24] = "", cls[128] = ""; /* month = "1404/06" */
  mg_http_get_var(&hm->query, "month", month, sizeof(month));
  mg_http_get_var(&hm->query, "class", cls, sizeof(cls));
  if (month[0] == 0) {
    char d[24];
    jalali_today(d, sizeof(d));
    memcpy(month, d, 7);
    month[7] = 0;
  }
  char like[32];
  snprintf(like, sizeof(like), "%s/%%", month);
  const char *b[2] = { like, cls };
  char *json = db_text(g_db,
      "SELECT COALESCE(json_group_array(json_object('uuid',s.uuid,"
      "'first_name',s.first_name,'last_name',s.last_name,'class_name',s.class_name,"
      "'present',(SELECT COUNT(*) FROM attendance a WHERE a.student_uuid=s.uuid AND a.deleted=0"
      "  AND a.date_jalali LIKE ?1 AND a.status='present'),"
      "'late',(SELECT COUNT(*) FROM attendance a WHERE a.student_uuid=s.uuid AND a.deleted=0"
      "  AND a.date_jalali LIKE ?1 AND a.status='late'),"
      "'absent',(SELECT COUNT(*) FROM attendance a WHERE a.student_uuid=s.uuid AND a.deleted=0"
      "  AND a.date_jalali LIKE ?1 AND a.status='absent'))),'[]')"
      " FROM (SELECT * FROM students WHERE deleted=0 AND (?2='' OR class_name=?2)"
      " ORDER BY class_name, last_name) s",
      2, b);
  if (!json) { reply_json(c, 500, "{\"ok\":false}"); return; }
  char me[32];
  json_esc(month, me, sizeof(me));
  size_t cap = strlen(json) + 96;
  char *body = (char *) malloc(cap);
  snprintf(body, cap, "{\"ok\":true,\"month\":\"%s\",\"items\":%s}", me, json);
  reply_json(c, 200, body);
  free(body); free(json);
}

/* ---- grades: sheet for class+month (students x subject scores) ---- */
static void api_grades(struct mg_connection *c, struct mg_http_message *hm) {
  char month[40] = "", cls[128] = "", subj[128] = "";
  mg_http_get_var(&hm->query, "month", month, sizeof(month));
  mg_http_get_var(&hm->query, "class", cls, sizeof(cls));
  mg_http_get_var(&hm->query, "subject", subj, sizeof(subj));
  const char *b[3] = { month, cls, subj };
  char *json = db_text(g_db,
      "SELECT COALESCE(json_group_array(json_object('uuid',s.uuid,"
      "'first_name',s.first_name,'last_name',s.last_name,'class_name',s.class_name,"
      "'score',(SELECT g.score FROM grades g WHERE g.student_uuid=s.uuid AND g.deleted=0"
      "  AND g.report_month=?1 AND g.subject_name=?3),"
      "'avg',(SELECT ROUND(AVG(g.score),2) FROM grades g WHERE g.student_uuid=s.uuid"
      "  AND g.deleted=0 AND g.report_month=?1),"
      "'cnt',(SELECT COUNT(*) FROM grades g WHERE g.student_uuid=s.uuid AND g.deleted=0"
      "  AND g.report_month=?1))),'[]')"
      " FROM (SELECT * FROM students WHERE deleted=0 AND (?2='' OR class_name=?2)"
      " ORDER BY class_name, last_name, first_name) s",
      3, b);
  char *subjects = db_text(g_db,
      "SELECT COALESCE(json_group_array(name),'[]') FROM (SELECT name FROM subjects ORDER BY name)",
      0, NULL);
  if (!json) { free(subjects); reply_json(c, 500, "{\"ok\":false}"); return; }
  size_t cap = strlen(json) + (subjects ? strlen(subjects) : 4) + 64;
  char *body = (char *) malloc(cap);
  snprintf(body, cap, "{\"ok\":true,\"items\":%s,\"subjects\":%s}", json,
           subjects ? subjects : "[]");
  reply_json(c, 200, body);
  free(body); free(json); free(subjects);
}

/* ---- grades: set/clear one score ---- */
static void api_grade_set(struct mg_connection *c, struct mg_http_message *hm) {
  struct mg_str js = mg_str_n(hm->body.ptr, hm->body.len);
  char *stu = mg_json_get_str(js, "$.student_uuid");
  char *month = mg_json_get_str(js, "$.report_month");
  char *subj = mg_json_get_str(js, "$.subject_name");
  char *score = mg_json_get_str(js, "$.score"); /* "" = clear */
  if (!stu || !month || !subj || !*month || !*subj) {
    free(stu); free(month); free(subj); free(score);
    reply_json(c, 400, "{\"ok\":false,\"msg\":\"درخواست نامعتبر\"}");
    return;
  }
  char gu[220];
  snprintf(gu, sizeof(gu), "gr-%.36s-%.40s-%.60s", stu, month, subj);
  for (char *p = gu; *p; p++) if (*p == '/' || *p == ' ') *p = '-';

  int ok;
  if (!score || !*score) {
    const char *b[3] = { stu, month, subj };
    ok = db_exec_b(g_db,
        "DELETE FROM grades WHERE student_uuid=?1 AND report_month=?2 AND subject_name=?3",
        3, b) == 0;
  } else {
    const char *b[5] = { gu, stu, month, subj, score };
    ok = db_exec_b(g_db,
        "INSERT INTO grades(uuid,student_uuid,report_month,subject_name,score)"
        " VALUES(?1,?2,?3,?4,CAST(?5 AS REAL))"
        " ON CONFLICT(student_uuid,report_month,subject_name)"
        " DO UPDATE SET score=CAST(?5 AS REAL), deleted=0", 5, b) == 0;
    const char *sb[1] = { subj };
    db_exec_b(g_db, "INSERT OR IGNORE INTO subjects(name) VALUES(?1)", 1, sb);
  }
  if (ok) {
    const char *qb[5] = { gu, stu, month, subj, score ? score : "" };
    db_exec_b(g_db,
        "INSERT INTO queue(entity,op,payload) VALUES('grade','set',"
        " json_object('uuid',?1,'student_uuid',?2,'report_month',?3,"
        " 'subject_name',?4,'score',?5,"
        " 'server_id',(SELECT server_id FROM students WHERE uuid=?2)))", 5, qb);
    g_sync_req = 1;
    reply_json(c, 200, "{\"ok\":true}");
  } else {
    reply_json(c, 500, "{\"ok\":false,\"msg\":\"خطا در ذخیره محلی\"}");
  }
  free(stu); free(month); free(subj); free(score);
}

/* ---- subjects: add one ---- */
static void api_subject_add(struct mg_connection *c, struct mg_http_message *hm) {
  struct mg_str js = mg_str_n(hm->body.ptr, hm->body.len);
  char *name = mg_json_get_str(js, "$.name");
  if (!name || !*name) {
    free(name);
    reply_json(c, 400, "{\"ok\":false,\"msg\":\"نام درس الزامی است\"}");
    return;
  }
  const char *b[1] = { name };
  db_exec_b(g_db, "INSERT OR IGNORE INTO subjects(name) VALUES(?1)", 1, b);
  reply_json(c, 200, "{\"ok\":true}");
  free(name);
}

/* ---- report card: one student, one month (with class ranks) ---- */
static void api_reportcard(struct mg_connection *c, struct mg_http_message *hm) {
  char stu[80] = "", month[40] = "";
  mg_http_get_var(&hm->query, "student", stu, sizeof(stu));
  mg_http_get_var(&hm->query, "month", month, sizeof(month));
  const char *b[2] = { stu, month };
  char *info = db_text(g_db,
      "SELECT json_object('first_name',first_name,'last_name',last_name,"
      "'national_id',national_id,'father_name',father_name,'class_name',class_name,"
      "'grade_level',grade_level) FROM students WHERE uuid=?1", 1, b);
  char *rows = db_text(g_db,
      "SELECT COALESCE(json_group_array(json_object('subject_name',subject_name,"
      "'score',score,"
      "'class_rank',(SELECT 1+COUNT(*) FROM grades g2 JOIN students s2 ON s2.uuid=g2.student_uuid"
      "  WHERE g2.deleted=0 AND g2.report_month=?2 AND g2.subject_name=g.subject_name"
      "  AND s2.class_name=(SELECT class_name FROM students WHERE uuid=?1)"
      "  AND g2.score>g.score))),'[]')"
      " FROM (SELECT * FROM grades WHERE student_uuid=?1 AND report_month=?2 AND deleted=0"
      " ORDER BY subject_name) g",
      2, b);
  char *avg = db_text(g_db,
      "SELECT COALESCE(ROUND(AVG(score),2),'') FROM grades"
      " WHERE student_uuid=?1 AND report_month=?2 AND deleted=0", 2, b);
  char *rank = db_text(g_db,
      "SELECT 1+COUNT(*) FROM (SELECT g.student_uuid, AVG(g.score) a FROM grades g"
      " JOIN students s ON s.uuid=g.student_uuid WHERE g.deleted=0 AND g.report_month=?2"
      " AND s.class_name=(SELECT class_name FROM students WHERE uuid=?1)"
      " GROUP BY g.student_uuid)"
      " WHERE a>(SELECT AVG(score) FROM grades WHERE student_uuid=?1"
      " AND report_month=?2 AND deleted=0)", 2, b);
  if (!info) {
    free(rows); free(avg); free(rank);
    reply_json(c, 404, "{\"ok\":false,\"msg\":\"دانش‌آموز یافت نشد\"}");
    return;
  }
  size_t cap = strlen(info) + strlen(rows ? rows : "[]") + 256;
  char *body = (char *) malloc(cap);
  snprintf(body, cap,
           "{\"ok\":true,\"student\":%s,\"grades\":%s,\"avg\":\"%s\",\"class_rank\":%s}",
           info, rows ? rows : "[]", avg ? avg : "", (rank && *rank) ? rank : "0");
  reply_json(c, 200, body);
  free(body); free(info); free(rows); free(avg); free(rank);
}

/* Connect & login to the site server, obtain API key */
static void api_settings(struct mg_connection *c, struct mg_http_message *hm) {
  struct mg_str js = mg_str_n(hm->body.ptr, hm->body.len);
  char *url = mg_json_get_str(js, "$.server_url");
  char *user = mg_json_get_str(js, "$.username");
  char *pass = mg_json_get_str(js, "$.password");
  bool insecure = false;
  mg_json_get_bool(js, "$.insecure", &insecure);
  if (!url || !*url || !user || !pass) {
    free(url); free(user); free(pass);
    reply_json(c, 400, "{\"ok\":false,\"msg\":\"همه فیلدها الزامی است\"}");
    return;
  }
  char api[1200];
  build_api_url(url, api, sizeof(api));
  char ue[256], pe[256];
  json_esc(user, ue, sizeof(ue));
  json_esc(pass, pe, sizeof(pe));
  char body[900];
  snprintf(body, sizeof(body),
           "{\"action\":\"login\",\"username\":\"%s\",\"password\":\"%s\"}", ue, pe);
  char *resp = NULL;
  size_t rlen = 0;
  int st = http_post(api, body, insecure ? 1 : 0, &resp, &rlen);
  if (st < 0 || !resp || rlen == 0) {
    free(resp); free(url); free(user); free(pass);
    reply_json(c, 200,
        "{\"ok\":false,\"msg\":\"اتصال به سرور برقرار نشد — اینترنت/آدرس را بررسی کنید. "
        "اگر SSL سایت معتبر نیست گزینه (پذیرش گواهی SSL نامعتبر) را فعال کنید. "
        "برای تست، آدرس sync-api.php را در مرورگر باز کنید\"}");
    return;
  }
  if (st != 200) {
    char frag[120], fe[240], eb[560];
    snprintf(frag, sizeof(frag), "%.100s", resp);
    json_esc(frag, fe, sizeof(fe));
    free(resp); free(url); free(user); free(pass);
    snprintf(eb, sizeof(eb),
             "{\"ok\":false,\"msg\":\"سرور کد %d برگرداند (%s%s) — مسیر sync-api.php را بررسی کنید. "
             "پاسخ سرور: %s\"}",
             st, st == 404 ? "فایل پیدا نشد" : st == 403 ? "دسترسی رد شد" : st >= 500 ? "خطای داخلی سرور" : "خطا",
             "", fe);
    reply_json(c, 200, eb);
    return;
  }
  struct mg_str rj = mg_str_n(resp, rlen);
  bool okv = false;
  bool got = mg_json_get_bool(rj, "$.ok", &okv);
  if (!got) { /* 200 but not our JSON — wrong URL (HTML page?) */
    char frag[120], fe[240], eb[560];
    snprintf(frag, sizeof(frag), "%.100s", resp);
    json_esc(frag, fe, sizeof(fe));
    free(resp); free(url); free(user); free(pass);
    snprintf(eb, sizeof(eb),
             "{\"ok\":false,\"msg\":\"پاسخ سرور JSON معتبر نبود — احتمالا آدرس اشتباه است یا فایل sync-api.php آپلود نشده. ابتدای پاسخ: %s\"}", fe);
    reply_json(c, 200, eb);
    return;
  }
  if (!okv) {
    char *m = mg_json_get_str(rj, "$.msg");
    char me[300];
    json_esc(m ? m : "ورود ناموفق — نام کاربری یا رمز اشتباه است", me, sizeof(me));
    char eb[420];
    snprintf(eb, sizeof(eb), "{\"ok\":false,\"msg\":\"%s\"}", me);
    reply_json(c, 200, eb);
    free(m); free(resp); free(url); free(user); free(pass);
    return;
  }
  char *key = mg_json_get_str(rj, "$.api_key");
  char *sname = mg_json_get_str(rj, "$.school_name");
  if (!key || !*key) { /* ok:true but no key — e.g. ping JSON via a redirect */
    free(key); free(sname); free(resp); free(url); free(user); free(pass);
    reply_json(c, 200,
        "{\"ok\":false,\"msg\":\"سرور پاسخ داد اما کلید ورود صادر نشد — احتمالا آدرس به صفحه دیگری هدایت می‌شود. "
        "آدرس دقیق سایت (با https و www اگر دارد) را وارد کنید\"}");
    return;
  }
  meta_set(g_db, "server_url", url);
  meta_set(g_db, "api_key", key ? key : "");
  meta_set(g_db, "insecure", insecure ? "1" : "0");
  if (sname) meta_set(g_db, "school_name", sname);
  g_sync_req = 1;
  reply_json(c, 200, "{\"ok\":true}");
  free(key); free(sname); free(resp); free(url); free(user); free(pass);
}

static void ev_handler(struct mg_connection *c, int ev, void *ev_data) {
  if (ev != MG_EV_HTTP_MSG) return;
  struct mg_http_message *hm = (struct mg_http_message *) ev_data;

  if (mg_http_match_uri(hm, "/")) {
    reply_blob(c, "text/html; charset=utf-8", BLOB_UI_HTML, sizeof(BLOB_UI_HTML) - 1, 0);
  } else if (mg_http_match_uri(hm, "/fonts/vazir.woff2")) {
    reply_blob(c, "font/woff2", BLOB_FONT_REG, sizeof(BLOB_FONT_REG), 1);
  } else if (mg_http_match_uri(hm, "/fonts/vazir-bold.woff2")) {
    reply_blob(c, "font/woff2", BLOB_FONT_BOLD, sizeof(BLOB_FONT_BOLD), 1);
  } else if (mg_http_match_uri(hm, "/api/state")) {
    api_state(c);
  } else if (mg_http_match_uri(hm, "/api/students")) {
    api_list(c, hm, "students");
  } else if (mg_http_match_uri(hm, "/api/classes")) {
    api_list(c, hm, "classes");
  } else if (mg_http_match_uri(hm, "/api/teachers")) {
    api_list(c, hm, "teachers");
  } else if (mg_http_match_uri(hm, "/api/mutate")) {
    api_mutate(c, hm);
  } else if (mg_http_match_uri(hm, "/api/attendance")) {
    api_attendance(c, hm);
  } else if (mg_http_match_uri(hm, "/api/attendance-set")) {
    api_attendance_set(c, hm);
  } else if (mg_http_match_uri(hm, "/api/attendance-report")) {
    api_attendance_report(c, hm);
  } else if (mg_http_match_uri(hm, "/api/grades")) {
    api_grades(c, hm);
  } else if (mg_http_match_uri(hm, "/api/grade-set")) {
    api_grade_set(c, hm);
  } else if (mg_http_match_uri(hm, "/api/subject-add")) {
    api_subject_add(c, hm);
  } else if (mg_http_match_uri(hm, "/api/reportcard")) {
    api_reportcard(c, hm);
  } else if (mg_http_match_uri(hm, "/api/queue")) {
    api_queue(c);
  } else if (mg_http_match_uri(hm, "/api/settings")) {
    api_settings(c, hm);
  } else if (mg_http_match_uri(hm, "/api/sync-now")) {
    g_sync_req = 1;
    reply_json(c, 200, "{\"ok\":true}");
  } else if (mg_http_match_uri(hm, "/api/quit")) {
    reply_json(c, 200, "{\"ok\":true}");
    g_quit = 1;
  } else {
    reply_json(c, 404, "{\"ok\":false,\"msg\":\"not found\"}");
  }
}

/* ------------------------------------------------------------------ */
/* Entry                                                               */
/* ------------------------------------------------------------------ */

#ifdef _WIN32
static DWORD WINAPI http_thread(LPVOID arg) {
  struct mg_mgr *mgr = (struct mg_mgr *) arg;
  while (!g_quit) mg_mgr_poll(mgr, 100);
  return 0;
}
#endif

static int app_main(void) {
  char dir[1024];
  exe_dir(dir, sizeof(dir));
  snprintf(g_db_path, sizeof(g_db_path), "%s%cschooldesk.db", dir,
#ifdef _WIN32
           '\\'
#else
           '/'
#endif
  );

  if (db_open(&g_db) != 0) return 1;
  db_init(g_db);

  struct mg_mgr mgr;
  mg_mgr_init(&mgr);

  char url[128];
  int port = 48620;
  const char *env_port = getenv("SCHOOLDESK_PORT");
  const char *env_host = getenv("SCHOOLDESK_HOST"); /* debug/preview only */
  if (env_port) port = atoi(env_port);
  struct mg_connection *lc = NULL;
  for (int i = 0; i < 20 && !lc; i++) {
    snprintf(url, sizeof(url), "http://%s:%d",
             env_host ? env_host : "127.0.0.1", port + i);
    lc = mg_http_listen(&mgr, url, ev_handler, NULL);
    if (lc) port = port + i;
  }
  if (!lc) { mg_mgr_free(&mgr); return 2; }

  /* background sync thread */
#ifdef _WIN32
  HANDLE th = CreateThread(NULL, 0, sync_thread, NULL, 0, NULL);
#else
  pthread_t th;
  pthread_create(&th, NULL, sync_thread, NULL);
#endif

  char open_url[160];
  snprintf(open_url, sizeof(open_url), "http://127.0.0.1:%d/", port);

#ifdef _WIN32
  /* Run the HTTP server on a worker thread; the native window (message
   * loop) owns the main thread. */
  HANDLE hs = CreateThread(NULL, 0, http_thread, &mgr, 0, NULL);
  webwin_run(open_url); /* blocks until exit from tray menu */
  g_quit = 1;
  WaitForSingleObject(hs, 3000);
  WaitForSingleObject(th, 3000);
#else
  printf("SchoolDesk running at %s\n", open_url);
  fflush(stdout);
  while (!g_quit) mg_mgr_poll(&mgr, 100);
  pthread_join(th, NULL);
#endif

  mg_mgr_free(&mgr);
  sqlite3_close(g_db);
  return 0;
}

#ifdef _WIN32
int WINAPI WinMain(HINSTANCE h, HINSTANCE p, LPSTR cmd, int show) {
  (void) h; (void) p; (void) cmd; (void) show;
  return app_main();
}
int main(void) { return app_main(); } /* console fallback if linked as console */
#else
int main(void) { return app_main(); }
#endif
