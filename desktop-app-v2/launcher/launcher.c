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

static int find_browser(char *out, DWORD outsz) {
  static const char *exes[2] = { "msedge.exe", "chrome.exe" };
  for (int i = 0; i < 2; i++)
    if (reg_app_path(exes[i], out, outsz)) return 1;

  /* common install locations (covers systems with a broken App Paths key) */
  static const char *fixed[] = {
    "C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe",
    "C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe",
    "C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe",
    "C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe",
  };
  for (size_t i = 0; i < sizeof(fixed) / sizeof(fixed[0]); i++)
    if (file_exists(fixed[i])) { strncpy(out, fixed[i], outsz - 1); out[outsz - 1] = 0; return 1; }

  char la[MAX_PATH];
  DWORD n = GetEnvironmentVariableA("LOCALAPPDATA", la, sizeof(la));
  if (n > 0 && n < sizeof(la)) {
    snprintf(out, outsz, "%s\\Google\\Chrome\\Application\\chrome.exe", la);
    if (file_exists(out)) return 1;
  }
  return 0;
}

/* launch browser in app mode; returns process handle (or NULL) */
static HANDLE launch_app_window(const char *browser, const char *url) {
  char cmd[MAX_PATH * 4];
  snprintf(cmd, sizeof(cmd),
           "\"%s\" --app=%s "
           "--user-data-dir=\"%s\\data\\browser-profile\" "
           "--no-first-run --no-default-browser-check --disable-sync "
           "--disable-features=Translate,msImplicitSignin "
           "--window-size=1280,860",
           browser, url, g_dir);
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
/* fallback status window (default browser mode)                       */
/* ------------------------------------------------------------------ */

static LRESULT CALLBACK StatusWndProc(HWND h, UINT m, WPARAM w, LPARAM l) {
  if (m == WM_DESTROY) { PostQuitMessage(0); return 0; }
  return DefWindowProcW(h, m, w, l);
}

static void run_status_window(void) {
  WNDCLASSW wc;
  memset(&wc, 0, sizeof(wc));
  wc.lpfnWndProc = StatusWndProc;
  wc.hInstance = GetModuleHandleW(NULL);
  wc.lpszClassName = L"SchoolDeskStatusWnd";
  wc.hbrBackground = (HBRUSH) (COLOR_WINDOW + 1);
  wc.hCursor = LoadCursorW(NULL, MAKEINTRESOURCEW(32512)); /* IDC_ARROW */
  wc.hIcon = LoadIconW(GetModuleHandleW(NULL), MAKEINTRESOURCEW(1));
  RegisterClassW(&wc);

  /* "SchoolDesk Pro در مرورگر باز شد. این پنجره را باز نگه دارید؛
      برای خروج کامل از برنامه، این پنجره را ببندید." */
  HWND h = CreateWindowExW(WS_EX_APPWINDOW, L"SchoolDeskStatusWnd",
      L"SchoolDesk Pro",
      (WS_OVERLAPPEDWINDOW & ~WS_MAXIMIZEBOX & ~WS_THICKFRAME) | WS_VISIBLE,
      CW_USEDEFAULT, CW_USEDEFAULT, 460, 170, NULL, NULL, wc.hInstance, NULL);

  CreateWindowExW(0, L"STATIC",
      L"SchoolDesk Pro \x062F\x0631 \x0645\x0631\x0648\x0631\x06AF\x0631 \x0628\x0627\x0632 \x0634\x062F.\n"
      L"\x0627\x06CC\x0646 \x067E\x0646\x062C\x0631\x0647 \x0631\x0627 \x0628\x0627\x0632 \x0646\x06AF\x0647 \x062F\x0627\x0631\x06CC\x062F\x061B "
      L"\x0628\x0631\x0627\x06CC \x062E\x0631\x0648\x062C \x06A9\x0627\x0645\x0644\x060C \x0627\x06CC\x0646 \x067E\x0646\x062C\x0631\x0647 \x0631\x0627 \x0628\x0628\x0646\x062F\x06CC\x062F.",
      WS_CHILD | WS_VISIBLE | SS_CENTER, 20, 35, 400, 70, h, NULL, wc.hInstance, NULL);

  MSG msg;
  while (GetMessageW(&msg, NULL, 0, 0) > 0) {
    TranslateMessage(&msg);
    DispatchMessageW(&msg);
  }
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
           "-S 127.0.0.1:%d -t \"%s\\www\" \"%s\\www\\router.php\"",
           g_dir, g_dir, g_dir, g_dir, g_dir, g_dir, port, g_dir, g_dir);

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
    /* no Edge/Chrome (e.g. bare Windows 7): default browser + status window */
    ShellExecuteA(NULL, "open", url, NULL, NULL, SW_SHOWNORMAL);
    run_status_window();
  }

  TerminateProcess(pi.hProcess, 0);
  CloseHandle(pi.hProcess);
  if (job) CloseHandle(job);
  WSACleanup();
  return 0;
}

#endif /* _WIN32 */
