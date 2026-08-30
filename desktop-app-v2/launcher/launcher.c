/*
 * SchoolDesk Pro launcher (Windows 7+).
 *
 * The desktop edition runs the COMPLETE school management system (the same
 * PHP codebase as the website) locally:
 *
 *   launcher.exe
 *     |- php\php.exe -S 127.0.0.1:<port> -t www   (hidden child process)
 *     |- native app window (MSHTML host, webwin.c) pointed at the local site
 *
 * - single instance (named mutex); second launch focuses the open window
 * - picks a free port automatically (8123..8199)
 * - waits for the PHP server to answer before showing the window
 * - kills the PHP child when the app exits (job object: even on crash)
 */
#ifdef _WIN32

#include <stdio.h>
#include <string.h>
#include <windows.h>
#include <winsock2.h>
#include <shellapi.h>

volatile int g_quit = 0;      /* consumed by webwin.c */
volatile int g_sync_req = 0;  /* unused in Pro (no sync engine) but referenced by webwin */

int webwin_run(const char *url);

static char g_dir[MAX_PATH];

static void exe_dir(void) {
  GetModuleFileNameA(NULL, g_dir, MAX_PATH);
  char *p = strrchr(g_dir, '\\');
  if (p) *p = 0;
}

/* try to connect to 127.0.0.1:port — 0 when something listens */
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

int WINAPI WinMain(HINSTANCE hi, HINSTANCE hp, LPSTR cmd, int show) {
  (void) hi; (void) hp; (void) cmd; (void) show;

  /* single instance */
  HANDLE mtx = CreateMutexW(NULL, TRUE, L"SchoolDeskProSingleton");
  if (mtx && GetLastError() == ERROR_ALREADY_EXISTS) {
    HWND w = FindWindowW(L"SchoolDeskWnd", NULL);
    if (w) {
      ShowWindow(w, SW_SHOW);
      ShowWindow(w, SW_RESTORE);
      SetForegroundWindow(w);
    }
    return 0;
  }

  WSADATA wd;
  WSAStartup(MAKEWORD(2, 2), &wd);
  exe_dir();

  /* data dirs */
  char path[MAX_PATH * 2];
  snprintf(path, sizeof(path), "%s\\data", g_dir);          CreateDirectoryA(path, NULL);
  snprintf(path, sizeof(path), "%s\\data\\sessions", g_dir); CreateDirectoryA(path, NULL);
  snprintf(path, sizeof(path), "%s\\data\\uploads", g_dir);  CreateDirectoryA(path, NULL);

  /* choose port */
  int port = 8123;
  while (port_in_use(port) && port < 8200) port++;

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

  if (!wait_for_server(port, 15000)) {
    TerminateProcess(pi.hProcess, 0);
    MessageBoxW(NULL,
        L"\x0633\x0631\x0648\x0631 \x0645\x062D\x0644\x06CC \x0628\x0627\x0644\x0627 \x0646\x06CC\x0627\x0645\x062F \x2014 "
        L"\x0641\x0627\x06CC\x0644 data\\php-error.log \x0631\x0627 \x0628\x0628\x06CC\x0646\x06CC\x062F",
        L"SchoolDesk Pro", MB_ICONERROR);
    return 1;
  }

  char url[128];
  snprintf(url, sizeof(url), "http://127.0.0.1:%d/", port);

  int rc = webwin_run(url); /* blocks; 1 = fell back to system browser */
  if (rc == 1) {
    /* keep server alive while the user works in the browser: wait for php */
    WaitForSingleObject(pi.hProcess, INFINITE);
  }

  TerminateProcess(pi.hProcess, 0);
  CloseHandle(pi.hProcess);
  if (job) CloseHandle(job);
  WSACleanup();
  return 0;
}

#endif /* _WIN32 */
