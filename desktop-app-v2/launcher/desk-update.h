/*
 * Staged desktop self-update (launcher side).
 *
 * The web app can download a new SchoolDeskPro.exe from the school site (see
 * includes/desk_update.php). Windows will not let a running program overwrite
 * its own image file, and a process started by the PHP worker dies with the
 * app's job object — so the swap is done by a small detached cmd script that
 * this launcher starts on the NEXT boot, then exits without opening the app.
 *
 * The helper waits for this process to exit, keeps the previous build as
 * SchoolDeskPro.exe.old, starts the new build and verifies it stayed alive;
 * if it did not, it restores the previous build and records the failure.
 *
 * Protocol files (all under data\update\launcher\):
 *   SchoolDeskPro.exe         staged, already SHA256-verified by the web app
 *   expected.json             written with the staged package
 *   apply-launcher-update.cmd detached helper (replace + verify + rollback)
 *   failed.txt                written by the helper when it had to roll back
 */
#ifndef SDP_DESK_UPDATE_H
#define SDP_DESK_UPDATE_H

static int sdp_file_exists(const char *path) {
  DWORD attrs = GetFileAttributesA(path);
  return attrs != INVALID_FILE_ATTRIBUTES && !(attrs & FILE_ATTRIBUTE_DIRECTORY);
}

/* 1 when a fully staged launcher update is waiting for the next boot. */
static int sdp_staged_update_ready(const char *base) {
  char staged[MAX_PATH * 2], expected[MAX_PATH * 2];
  snprintf(staged, sizeof(staged), "%s\\data\\update\\launcher\\SchoolDeskPro.exe", base);
  snprintf(expected, sizeof(expected), "%s\\data\\update\\launcher\\expected.json", base);
  return sdp_file_exists(staged) && sdp_file_exists(expected);
}

/* Start the detached helper and return; the caller must exit without opening
   the app so the helper can replace this executable. */
static int sdp_handoff_update(const char *base) {
  char helper[MAX_PATH * 2], cmd[MAX_PATH * 3];
  snprintf(helper, sizeof(helper), "%s\\data\\update\\launcher\\apply-launcher-update.cmd", base);
  if (!sdp_file_exists(helper)) return 0;
  /* CREATE_NO_WINDOW + detached: the helper outlives this process and is not
     part of the PHP job object (created later, for php.exe only). */
  snprintf(cmd, sizeof(cmd), "cmd.exe /c \"\"%s\"\"", helper);
  STARTUPINFOA si;
  PROCESS_INFORMATION pi;
  memset(&si, 0, sizeof(si));
  si.cb = sizeof(si);
  si.dwFlags = STARTF_USESHOWWINDOW;
  si.wShowWindow = SW_HIDE;
  if (!CreateProcessA(NULL, cmd, NULL, NULL, FALSE, CREATE_NO_WINDOW | DETACHED_PROCESS,
                      NULL, base, &si, &pi))
    return 0;
  CloseHandle(pi.hThread);
  CloseHandle(pi.hProcess);
  return 1;
}

#endif /* SDP_DESK_UPDATE_H */
