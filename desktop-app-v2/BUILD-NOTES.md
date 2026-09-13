# SchoolDesk Pro — build notes

v2.65.0 changes (launcher v2.4):
- app window now opens MAXIMIZED (`--start-maximized`). Deliberately NOT
  `--start-fullscreen`: in `--app` mode there is no F11 and no visible
  close button, so true fullscreen traps the user.
- the "don't close this window" status window is GONE. When no modern
  engine is found we now sit in the notification area (tray icon) with a
  right-click menu (open / exit) and double-click to reopen. The PHP
  server keeps running in the background with no desktop window.
- browser discovery is much more stubborn, because users reported the app
  opening in the system browser: App Paths -> Uninstall\InstallLocation
  -> fixed paths -> %LOCALAPPDATA%/%ProgramFiles% variants -> **WebView2
  runtime** (msedgewebview2.exe, present on virtually every Win10/11).
  Default browser is now genuinely the last resort.
- launcher icon source now lives in git at launcher/res/app.ico +
  app.rc (it was previously only inside the shipped .exe).

Build the launcher (verified in this repo with zig 0.16):
    python -m ziglang rc res/app.rc            # -> app.res
    python -m ziglang cc -target x86_64-windows-gnu -O2 -s \
        launcher.c app.res -lws2_32 -ladvapi32 -lshell32 -luser32 -lgdi32 \
        -Wl,--subsystem,windows -o SchoolDeskPro.exe

v2.3.0 changes:
- launcher no longer embeds MSHTML/IE (webwin.c is retired — the old
  embedded engine rendered the app broken and JS never ran). The new
  launcher.c opens Edge/Chrome in `--app` mode with an isolated profile
  under data\browser-profile; fallback = default browser + tiny native
  status window. Build needs only launcher.c + app.res.
- sync now includes `admins` and `settings` (string PK `key_name`,
  keys starting with `desk_` never sync). After the first sync the
  desktop uses the SITE's admin credentials.

ZIP build steps (performed for each release):
1. payload www/ = /tmp/site + patch files (db.php, db_sqlite_compat.php,
   desk_sync.php, desk-sync.php page, router.php, schema-sqlite.sql,
   header/footer patches, config/database.php sqlite, installed.lock).
2. php/ = slimmed php-8.1.34-nts-vs16-x64 + php.ini + vcruntime/msvcp DLLs.
3. launcher: zig cc -target x86_64-windows-gnu -O2 -s launcher.c app.res
   -lws2_32 -ladvapi32 -lshell32 -luser32 -lgdi32 -Wl,--subsystem,windows
4. PAIRING: generate KEY="SDP-<48 hex>", then
   - sed the key into server/desk-sync-api.php (DESK_SYNC_KEY)
   - append to www/sql/schema-sqlite.sql:
     INSERT OR IGNORE INTO "settings" VALUES ('desk_sync_key', '<KEY>');
   The key ships only inside the ZIP, never in git.
5. data/ ships empty (sessions/, uploads/ with .keep).
