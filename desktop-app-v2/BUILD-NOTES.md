# SchoolDesk Pro — build notes

ZIP build steps (performed for each release):
1. payload www/ = /tmp/site + patch files (db.php, db_sqlite_compat.php,
   desk_sync.php, desk-sync.php page, router.php, schema-sqlite.sql,
   header/footer patches, config/database.php sqlite, installed.lock).
2. php/ = slimmed php-8.1.34-nts-vs16-x64 + php.ini + vcruntime/msvcp DLLs.
3. launcher: zig cc -target x86_64-windows-gnu launcher.c webwin.c app.res.
4. PAIRING: generate KEY="SDP-<48 hex>", then
   - sed the key into server/desk-sync-api.php (DESK_SYNC_KEY)
   - append to www/sql/schema-sqlite.sql:
     INSERT OR IGNORE INTO "settings" VALUES ('desk_sync_key', '<KEY>');
   The key ships only inside the ZIP, never in git.
5. data/ ships empty (sessions/, uploads/ with .keep).
