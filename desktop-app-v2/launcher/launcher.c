/*
 * SchoolDesk Pro launcher (Windows 7+), v2.3.
 *
 * The desktop edition runs the COMPLETE school management system (the same
 * PHP codebase as the website) locally:
 *
 *   SchoolDeskPro.exe
 *     |- php\php.exe -S 127.0.0.1:<port> -t www   (hidden child process)
 *     |- app window: Microsoft Edge / Google Chrome in --app mode
 *        (modern engine; the old embedded-IE/MSHTML approach rendered the
 *         site broken and its JS never ran — v2.2 bug)
 *     |- fallback: system default browser + small native status window
 *
 * - single instance (named mutex); second launch opens a new app window
 * - picks a free port automatically (8123..8199), stores it in data\port.txt
 * - waits for the PHP server to answer before opening the window
 * - kills the PHP child when the app exits (job object: even on crash)
 */
#ifdef _WIN32

#include <winsock2.h>
#include <windows.h>
#include <stdio.h>
#include <string.h>
#include <shellapi.h>

static char g_dir[MAX_PATH];

static void exe_dir(void) {
  GetModuleFileNameA(NULL, g_dir, MAX_PATH);
  char *p = strrchr(g_dir, '\\');
  if (p) *p = 0;
}

/* try to connect to 127.0.0.1:port — 1 when something listens */
static int port_in_use(int port) {
  SOCKET s = socket(AF_INET, SOCK_STREAM, 0);
  if (s == INVALID_SOCKET) return 0;
  struct sockaddr_in a;
  memset(&a, 0, sizeof(a));
  a.sin_family = AF_INET;
  a.sin_addr.s_addr = htonl(0x7f000001);
  a.sin_port = htons((unsigned short) port);
  u_long nb = 1;
  ioctlsocket(s, FIONBIO, &nb);
  connect(s, (struct sockaddr *) &a, sizeof(a));
  fd_set w;
  FD_ZERO(&w);
  FD_SET(s, &w);
  struct timeval tv = { 0, 200000 };
  int r = select(0, NULL, &w, NULL, &tv);
  closesocket(s);
  return r == 1;
}

static int wait_for_server(int port, int timeout_ms) {
  int waited = 0;
  while (waited < timeout_ms) {
    if (port_in_use(port)) return 1;
    Sleep(150);
    waited += 150;
  }
  return 0;
}

/* ------------------------------------------------------------------ */
/* modern browser discovery (Edge, then Chrome)                        */
/* ------------------------------------------------------------------ */

static int file_exists(const char *p) {
  DWORD a = GetFileAttributesA(p);
  return a != INVALID_FILE_ATTRIBUTES && !(a & FILE_ATTRIBUTE_DIRECTORY);
}

static int reg_app_path(const char *exe, char *out, DWORD outsz) {
  char key[256];
  snprintf(key, sizeof(key),
           "SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\App Paths\\%s", exe);
  const HKEY roots[2] = { HKEY_LOCAL_MACHINE, HKEY_CURRENT_USER };
  for (int i = 0; i < 2; i++) {
    DWORD sz = outsz;
    if (RegGetValueA(roots[i], key, NULL, RRF_RT_REG_SZ, NULL, out, &sz)
            == ERROR_SUCCESS && file_exists(out))
      return 1;
  }
  return 0;
}

/* WebView2 runtime: روی ویندوز ۱۰/۱۱ تقریباً همیشه هست (حتی وقتی Edge
   به‌ظاهر حذف شده). مسیر msedgewebview2.exe را از رجیستری می‌خوانیم.
   این موتور از خودِ Edge می‌آید و همان --app را می‌فهمد. */
static int find_webview2(char *out, DWORD outsz) {
  static const char *keys[] = {
    "SOFTWARE\\WOW6432Node\\Microsoft\\EdgeUpdate\\Clients\\{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}",
    "SOFTWARE\\Microsoft\\EdgeUpdate\\Clients\\{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}",
  };
  const HKEY roots[2] = { HKEY_LOCAL_MACHINE, HKEY_CURRENT_USER };
  char ver[128];
  for (size_t k = 0; k < sizeof(keys) / sizeof(keys[0]); k++) {
    for (int i = 0; i < 2; i++) {
      DWORD sz = sizeof(ver);
      if (RegGetValueA(roots[i], keys[k], "pv", RRF_RT_REG_SZ, NULL, ver, &sz)
              != ERROR_SUCCESS) continue;
      static const char *bases[] = {
        "C:\\Program Files (x86)\\Microsoft\\EdgeWebView\\Application",
        "C:\\Program Files\\Microsoft\\EdgeWebView\\Application",
      };
      for (size_t b = 0; b < sizeof(bases) / sizeof(bases[0]); b++) {
        snprintf(out, outsz, "%s\\%s\\msedgewebview2.exe", bases[b], ver);
        if (file_exists(out)) return 1;
      }
    }
  }
  return 0;
}

/* مسیر نصب Edge/Chrome را از کلید BLBeacon هم می‌خوانیم — روی بعضی
   سیستم‌ها App Paths پاک است ولی این هست. */
static int reg_install_dir(HKEY root, const char *key, const char *exe,
                           char *out, DWORD outsz) {
  char dir[MAX_PATH];
  DWORD sz = sizeof(dir);
  if (RegGetValueA(root, key, "InstallLocation", RRF_RT_REG_SZ, NULL, dir, &sz)
          == ERROR_SUCCESS) {
    snprintf(out, outsz, "%s\\%s", dir, exe);
    if (file_exists(out)) return 1;
  }
  return 0;
}

/*
 * پیدا کردن موتور مدرن برای پنجرهٔ برنامه.
 *
 * v2.4 — چرا این تابع این‌قدر سمج شد:
 * گزارش کاربر این بود که «روی بعضی کامپیوترها برنامه در مرورگر سیستم باز
 * می‌شود». علتش این بود که جست‌وجو فقط دو کلید App Paths را می‌دید؛ اگر
 * آن کلید نبود (نصب سازمانی، Edge فقط برای یک کاربر، پاک‌کننده‌های
 * رجیستری) بلافاصله به مرورگر پیش‌فرض می‌افتاد.
 *
 * حالا به ترتیب بررسی می‌شوند:
 *   ۱) App Paths (سریع‌ترین)
 *   ۲) InstallLocation در کلیدهای Uninstall
 *   ۳) مسیرهای متداول نصب، از جمله زیر %LOCALAPPDATA% و %PROGRAMFILES%
 *   ۴) WebView2 runtime — روی ویندوز ۱۰/۱۱ عملاً همیشه موجود است
 * فقط اگر هر چهار مورد شکست بخورد سراغ مرورگر پیش‌فرض می‌رویم.
 */
static int find_browser(char *out, DWORD outsz) {
  static const char *exes[2] = { "msedge.exe", "chrome.exe" };
  for (int i = 0; i < 2; i++)
    if (reg_app_path(exes[i], out, outsz)) return 1;

  /* کلیدهای Uninstall */
  if (reg_install_dir(HKEY_LOCAL_MACHINE,
        "SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\Microsoft Edge",
        "msedge.exe", out, outsz)) return 1;
  if (reg_install_dir(HKEY_LOCAL_MACHINE,
        "SOFTWARE\\WOW6432Node\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\Microsoft Edge",
        "msedge.exe", out, outsz)) return 1;

  /* مسیرهای ثابت */
  static const char *fixed[] = {
    "C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe",
    "C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe",
    "C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe",
    "C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe",
  };
  for (size_t i = 0; i < sizeof(fixed) / sizeof(fixed[0]); i++)
    if (file_exists(fixed[i])) { strncpy(out, fixed[i], outsz - 1); out[outsz - 1] = 0; return 1; }

  /* نصب‌های کاربری زیر %LOCALAPPDATA% و مسیرهای %PROGRAMFILES% غیرپیش‌فرض */
  struct { const char *env, *rel; } user_paths[] = {
    { "LOCALAPPDATA", "Google\\Chrome\\Application\\chrome.exe" },
    { "LOCALAPPDATA", "Microsoft\\Edge\\Application\\msedge.exe" },
    { "LOCALAPPDATA", "Chromium\\Application\\chrome.exe" },
    { "ProgramFiles", "Microsoft\\Edge\\Application\\msedge.exe" },
    { "ProgramFiles(x86)", "Microsoft\\Edge\\Application\\msedge.exe" },
    { "ProgramFiles", "Google\\Chrome\\Application\\chrome.exe" },
    { "ProgramFiles(x86)", "Google\\Chrome\\Application\\chrome.exe" },
  };
  for (size_t i = 0; i < sizeof(user_paths) / sizeof(user_paths[0]); i++) {
    char base[MAX_PATH];
    DWORD n = GetEnvironmentVariableA(user_paths[i].env, base, sizeof(base));
    if (n == 0 || n >= sizeof(base)) continue;
    snprintf(out, outsz, "%s\\%s", base, user_paths[i].rel);
    if (file_exists(out)) return 1;
  }

  /* آخرین و مهم‌ترین شانس: موتور WebView2 */
  if (find_webview2(out, outsz)) return 1;
  return 0;
}

/* launch browser in app mode; returns process handle (or NULL) */
static HANDLE launch_app_window(const char *browser, const char *url) {
  /* profile dir: app folder when writable, %TEMP% otherwise */
  char prof[MAX_PATH * 2];
  snprintf(prof, sizeof(prof), "%s\\data\\browser-profile", g_dir);
  CreateDirectoryA(prof, NULL);
  DWORD attrs = GetFileAttributesA(prof);
  if (attrs == INVALID_FILE_ATTRIBUTES || !(attrs & FILE_ATTRIBUTE_DIRECTORY)) {
    char tmp[MAX_PATH];
    if (GetTempPathA(sizeof(tmp), tmp) > 0) {
      snprintf(prof, sizeof(prof), "%sSchoolDeskPro-profile", tmp);
      CreateDirectoryA(prof, NULL);
    }
  }
  char cmd[MAX_PATH * 4];
  snprintf(cmd, sizeof(cmd),
           "\"%s\" --app=%s "
           "--user-data-dir=\"%s\" "
           "--no-first-run --no-default-browser-check --disable-sync "
           "--disable-features=Translate,msImplicitSignin "
           /* v2.4: پنجره تمام‌صفحه باز شود.
              --start-maximized پنجره را بیشینه می‌کند (نوار عنوان و دکمه‌های
              پنجره سر جایشان می‌مانند). عمداً از --start-fullscreen استفاده
              نشد: در حالت تمام‌صفحهٔ واقعی، کاربر هیچ دکمهٔ بستن نمی‌بیند و
              در --app mode کلید F11 هم وجود ندارد، یعنی کاربر گیر می‌افتاد.
              --window-position=0,0 برای وقتی است که ویندوز اندازهٔ ذخیره‌شدهٔ
              قبلی را بازیابی می‌کند. */
           "--start-maximized --window-position=0,0",
           browser, url, prof);
  STARTUPINFOA si;
  PROCESS_INFORMATION pi;
  memset(&si, 0, sizeof(si));
  si.cb = sizeof(si);
  if (!CreateProcessA(NULL, cmd, NULL, NULL, FALSE, 0, NULL, g_dir, &si, &pi))
    return NULL;
  CloseHandle(pi.hThread);
  return pi.hProcess;
}

/* ------------------------------------------------------------------ */
/* حالت پس‌زمینه: آیکون کنار ساعت، بدون هیچ پنجره‌ای                    */
/* ------------------------------------------------------------------ */
/*
 * v2.4 — چرا پنجرهٔ «این پنجره را نبندید» حذف شد:
 * کاربر گزارش داد که روی بعضی سیستم‌ها یک پنجرهٔ کوچک با این متن باز
 * می‌شود. آن پنجره تنها کارش زنده نگه داشتن پروسه بود تا سرور محلی
 * (PHP) بسته نشود — ولی کاربر را می‌ترساند و شبیه نقص به‌نظر می‌رسید.
 *
 * حالا به‌جایش یک آیکون کنار ساعت ویندوز می‌نشیند: هیچ پنجره‌ای روی
 * میز کار نیست، سرویس در پس‌زمینه کار می‌کند، و کاربر هر وقت خواست با
 * راست‌کلیک روی آیکون «خروج» را می‌زند. دابل‌کلیک هم دوباره برنامه را
 * باز می‌کند.
 */

#define WM_SDP_TRAY (WM_APP + 1)
#define IDM_SDP_OPEN 0xE001
#define IDM_SDP_EXIT 0xE002

static NOTIFYICONDATAW g_nid;
static char g_tray_url[128];

static void tray_open_again(void) {
  char browser[MAX_PATH];
  if (find_browser(browser, sizeof(browser))) {
    HANDLE b = launch_app_window(browser, g_tray_url);
    if (b) CloseHandle(b);
  } else {
    ShellExecuteA(NULL, "open", g_tray_url, NULL, NULL, SW_SHOWNORMAL);
  }
}

static LRESULT CALLBACK TrayWndProc(HWND h, UINT m, WPARAM w, LPARAM l) {
  if (m == WM_SDP_TRAY) {
    if (l == WM_LBUTTONDBLCLK) { tray_open_again(); return 0; }
    if (l == WM_RBUTTONUP || l == WM_CONTEXTMENU) {
      POINT pt;
      GetCursorPos(&pt);
      HMENU menu = CreatePopupMenu();
      /* «باز کردن برنامه» / «خروج» */
      AppendMenuW(menu, MF_STRING, IDM_SDP_OPEN,
          L"\x0628\x0627\x0632 \x06A9\x0631\x062F\x0646 \x0628\x0631\x0646\x0627\x0645\x0647");
      AppendMenuW(menu, MF_SEPARATOR, 0, NULL);
      AppendMenuW(menu, MF_STRING, IDM_SDP_EXIT,
          L"\x062E\x0631\x0648\x062C");
      /* لازم است وگرنه منو بعد از کلیکِ بیرون بسته نمی‌شود */
      SetForegroundWindow(h);
      TrackPopupMenu(menu, TPM_RIGHTBUTTON, pt.x, pt.y, 0, h, NULL);
      PostMessageW(h, WM_NULL, 0, 0);
      DestroyMenu(menu);
      return 0;
    }
    return 0;
  }
  if (m == WM_COMMAND) {
    if (LOWORD(w) == IDM_SDP_OPEN) { tray_open_again(); return 0; }
    if (LOWORD(w) == IDM_SDP_EXIT) { DestroyWindow(h); return 0; }
  }
  if (m == WM_DESTROY) {
    Shell_NotifyIconW(NIM_DELETE, &g_nid);
    PostQuitMessage(0);
    return 0;
  }
  return DefWindowProcW(h, m, w, l);
}

/* پنجره‌ای می‌سازد که هرگز نمایش داده نمی‌شود؛ فقط گیرندهٔ پیام‌های
   آیکون کنار ساعت است. */
static void run_tray_mode(const char *url) {
  strncpy(g_tray_url, url, sizeof(g_tray_url) - 1);
  g_tray_url[sizeof(g_tray_url) - 1] = 0;

  WNDCLASSW wc;
  memset(&wc, 0, sizeof(wc));
  wc.lpfnWndProc = TrayWndProc;
  wc.hInstance = GetModuleHandleW(NULL);
  wc.lpszClassName = L"SchoolDeskTrayWnd";
  RegisterClassW(&wc);

  /* HWND_MESSAGE = پنجرهٔ فقط-پیام: نه روی میز کار دیده می‌شود نه در
     نوار وظیفه. دقیقاً همان «اجرا در پس‌زمینه» که کاربر خواست. */
  HWND h = CreateWindowExW(0, L"SchoolDeskTrayWnd", L"SchoolDesk Pro",
                           0, 0, 0, 0, 0, HWND_MESSAGE, NULL, wc.hInstance, NULL);
  if (!h) return;

  memset(&g_nid, 0, sizeof(g_nid));
  g_nid.cbSize = sizeof(g_nid);
  g_nid.hWnd = h;
  g_nid.uID = 1;
  g_nid.uFlags = NIF_ICON | NIF_MESSAGE | NIF_TIP;
  g_nid.uCallbackMessage = WM_SDP_TRAY;
  g_nid.hIcon = LoadIconW(GetModuleHandleW(NULL), MAKEINTRESOURCEW(1));
  if (!g_nid.hIcon) g_nid.hIcon = LoadIconW(NULL, MAKEINTRESOURCEW(32512));
  /* «SchoolDesk Pro — در حال اجرا» */
  wcscpy(g_nid.szTip,
      L"SchoolDesk Pro \x2014 \x062F\x0631 \x062D\x0627\x0644 \x0627\x062C\x0631\x0627");
  Shell_NotifyIconW(NIM_ADD, &g_nid);

  MSG msg;
  while (GetMessageW(&msg, NULL, 0, 0) > 0) {
    TranslateMessage(&msg);
    DispatchMessageW(&msg);
  }
  Shell_NotifyIconW(NIM_DELETE, &g_nid);
}

/* ------------------------------------------------------------------ */

int WINAPI WinMain(HINSTANCE hi, HINSTANCE hp, LPSTR cmd, int show) {
  (void) hi; (void) hp; (void) cmd; (void) show;
  exe_dir();

  /* single instance: second launch just opens another app window */
  HANDLE mtx = CreateMutexW(NULL, TRUE, L"SchoolDeskProSingleton");
  if (mtx && GetLastError() == ERROR_ALREADY_EXISTS) {
    char pf[MAX_PATH], url[128], browser[MAX_PATH];
    snprintf(pf, sizeof(pf), "%s\\data\\port.txt", g_dir);
    FILE *f = fopen(pf, "r");
    int port = 0;
    if (f) { if (fscanf(f, "%d", &port) != 1) port = 0; fclose(f); }
    if (port > 0 && port_in_use(port)) {
      snprintf(url, sizeof(url), "http://127.0.0.1:%d/", port);
      if (find_browser(browser, sizeof(browser))) {
        HANDLE b = launch_app_window(browser, url);
        if (b) CloseHandle(b);
      } else {
        ShellExecuteA(NULL, "open", url, NULL, NULL, SW_SHOWNORMAL);
      }
    }
    return 0;
  }

  WSADATA wd;
  WSAStartup(MAKEWORD(2, 2), &wd);

  /* data dirs */
  char path[MAX_PATH * 2];
  snprintf(path, sizeof(path), "%s\\data", g_dir);          CreateDirectoryA(path, NULL);
  snprintf(path, sizeof(path), "%s\\data\\sessions", g_dir); CreateDirectoryA(path, NULL);
  snprintf(path, sizeof(path), "%s\\data\\uploads", g_dir);  CreateDirectoryA(path, NULL);

  /* choose port */
  int port = 8123;
  while (port_in_use(port) && port < 8200) port++;
  snprintf(path, sizeof(path), "%s\\data\\port.txt", g_dir);
  FILE *pf = fopen(path, "w");
  if (pf) { fprintf(pf, "%d", port); fclose(pf); }

  /* start hidden PHP built-in server */
  char cmdline[MAX_PATH * 4];
  snprintf(cmdline, sizeof(cmdline),
           "\"%s\\php\\php.exe\" -c \"%s\\php\\php.ini\" "
           "-d extension_dir=\"%s\\php\\ext\" "
           "-d error_log=\"%s\\data\\php-error.log\" "
           "-d session.save_path=\"%s\\data\\sessions\" "
           "-d upload_tmp_dir=\"%s\\data\\uploads\" "
           "-d curl.cainfo=\"%s\\php\\cacert.pem\" "
           "-d openssl.cafile=\"%s\\php\\cacert.pem\" "
           "-S 127.0.0.1:%d -t \"%s\\www\" \"%s\\www\\router.php\"",
           g_dir, g_dir, g_dir, g_dir, g_dir, g_dir, g_dir, g_dir, port, g_dir, g_dir);

  STARTUPINFOA si;
  PROCESS_INFORMATION pi;
  memset(&si, 0, sizeof(si));
  si.cb = sizeof(si);
  si.dwFlags = STARTF_USESHOWWINDOW;
  si.wShowWindow = SW_HIDE;

  /* job object: PHP dies with us, even if we crash */
  HANDLE job = CreateJobObjectW(NULL, NULL);
  if (job) {
    JOBOBJECT_EXTENDED_LIMIT_INFORMATION jli;
    memset(&jli, 0, sizeof(jli));
    jli.BasicLimitInformation.LimitFlags = JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE;
    SetInformationJobObject(job, JobObjectExtendedLimitInformation, &jli, sizeof(jli));
  }

  if (!CreateProcessA(NULL, cmdline, NULL, NULL, FALSE,
                      CREATE_NO_WINDOW | CREATE_SUSPENDED, NULL, g_dir, &si, &pi)) {
    MessageBoxW(NULL,
        L"\x0627\x062C\x0631\x0627\x06CC PHP \x0645\x0645\x06A9\x0646 \x0646\x0634\x062F \x2014 "
        L"\x067E\x0648\x0634\x0647 php \x06A9\x0646\x0627\x0631 \x0628\x0631\x0646\x0627\x0645\x0647 \x0631\x0627 \x0628\x0631\x0631\x0633\x06CC \x06A9\x0646\x06CC\x062F",
        L"SchoolDesk Pro", MB_ICONERROR);
    return 1;
  }
  if (job) AssignProcessToJobObject(job, pi.hProcess);
  ResumeThread(pi.hThread);
  CloseHandle(pi.hThread);

  if (!wait_for_server(port, 20000)) {
    TerminateProcess(pi.hProcess, 0);
    MessageBoxW(NULL,
        L"\x0633\x0631\x0648\x0631 \x0645\x062D\x0644\x06CC \x0628\x0627\x0644\x0627 \x0646\x06CC\x0627\x0645\x062F \x2014 "
        L"\x0641\x0627\x06CC\x0644 data\\php-error.log \x0631\x0627 \x0628\x0628\x06CC\x0646\x06CC\x062F",
        L"SchoolDesk Pro", MB_ICONERROR);
    return 1;
  }

  char url[128];
  snprintf(url, sizeof(url), "http://127.0.0.1:%d/", port);

  char browser[MAX_PATH];
  int fallback = 1;
  if (find_browser(browser, sizeof(browser))) {
    /* app window with a modern engine; when it closes, we shut down */
    HANDLE b = launch_app_window(browser, url);
    if (b) {
      DWORD t0 = GetTickCount();
      WaitForSingleObject(b, INFINITE);
      CloseHandle(b);
      /* died within 4s => browser failed to start properly: fall back */
      fallback = (GetTickCount() - t0 < 4000) ? 1 : 0;
    }
  }
  if (fallback) {
    /* v2.4: هیچ موتور مدرنی پیدا نشد (مثلاً ویندوز ۷ خام).
       مرورگر پیش‌فرض باز می‌شود، ولی به‌جای آن پنجرهٔ «نبندید»، فقط یک
       آیکون کنار ساعت می‌نشیند و سرور در پس‌زمینه کار می‌کند. */
    ShellExecuteA(NULL, "open", url, NULL, NULL, SW_SHOWNORMAL);
    run_tray_mode(url);
  }

  TerminateProcess(pi.hProcess, 0);
  CloseHandle(pi.hProcess);
  if (job) CloseHandle(job);
  WSACleanup();
  return 0;
}

#endif /* _WIN32 */
