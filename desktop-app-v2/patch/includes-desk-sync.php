<?php
// File: includes/desk_sync.php  (SchoolDesk Pro — bidirectional auto-sync engine)
/**
 * Keeps the desktop SQLite database and the school's live MySQL website
 * in sync, automatically and in both directions.
 *
 * Design:
 *  - Every change on either side is recorded in a `desk_change_log`
 *    table (SQLite triggers here, MySQL triggers on the server —
 *    installed automatically by desk-sync-api.php).
 *  - The DESKTOP is always the initiator: it pushes its own changes and
 *    pulls the server's changes over plain HTTPS/HTTP JSON.
 *  - First run performs a full snapshot import from the server.
 *  - Conflicts: last-write-wins using the change timestamp.
 *  - While applying remote changes, local triggers are suppressed via
 *    the `desk_sync_suppress` marker table, so applied rows do not echo
 *    back to the server.
 *  - New desktop rows use IDs >= 5,000,000 (sqlite_sequence bump), so
 *    desktop-created and server-created rows can never collide.
 */

if (!defined('DESK_SYNC_TABLES')) {
    define('DESK_SYNC_TABLES', json_encode([
        'admins',
        'academic_years','teachers','discipline_titles','classes','subjects',
        'class_schedules','students','student_discipline_records','reports',
        'report_grades','report_locks','exam_schedules','exam_designs',
        'exam_question_bank','exam_assignments','exam_student_seating',
        'online_exam_categories','online_question_categories','online_question_bank',
        'online_exams','online_questions','online_exam_attempts','online_exam_answers',
        'grade_messages','counseling_requests','student_attendance','student_qr_tags',
        // bot integration: registrations, staff bot sessions, templates, logs —
        // so everything the site's Bale/Telegram bots know is also on the desktop
        'bale_bot_users','telegram_bot_users','bot_admin_sessions',
        'bot_message_templates','bot_button_templates','bot_login_tokens','bot_message_logs',
        // v2.11.0: full parity with the site dashboard — admin activity log,
        // notifications, SMS log and parent report reviews sync too
        'activity_logs','notifications','sms_logs','report_parent_reviews',
        'settings',
    ]));
}

class DeskSync {

    const ID_BASE = 5000000;      // desktop-created rows start here
    const BATCH   = 400;
    const MIN_INTERVAL = 120;     // seconds between automatic runs
    const LOCK_TTL     = 180;     // stale-lock takeover
    const RECON_INTERVAL = 3600;  // v2.11.0: mirror-reconcile at most once per hour

    /* ---------------- settings helpers (raw, no cache) ---------------- */

    public static function getCfg($key, $default = '') {
        try {
            $r = DB::fetch("SELECT key_value FROM settings WHERE key_name = ?", [$key]);
            return $r ? (string)$r['key_value'] : $default;
        } catch (Throwable $e) { return $default; }
    }

    public static function setCfg($key, $value) {
        DB::execute("INSERT INTO settings (key_name, key_value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)", [$key, $value]);
    }

    public static function enabled() {
        return self::getCfg('desk_sync_enabled') === '1'
            && self::getCfg('desk_sync_url') !== ''
            && self::getCfg('desk_sync_key') !== '';
    }

    /* retry ladder (seconds) when the server cannot be reached:
       attempts 1-5 → every 10s, 6-10 → every 20s, 11+ → every 60s.
       After 15 failed attempts (5+5+5) the UI asks the user to check
       the internet connection; retrying continues every minute. */
    public static function backoffDelay($fails) {
        if ($fails <= 5)  return 10;
        if ($fails <= 10) return 20;
        return 60;
    }

    public static function status() {
        return [
            'enabled'   => self::enabled(),
            'url'       => self::getCfg('desk_sync_url'),
            'last_run'  => (int) self::getCfg('desk_sync_last', '0'),
            'last_ok'   => (int) self::getCfg('desk_sync_last_ok', '0'),
            'last_err'  => self::getCfg('desk_sync_err'),
            'cursor'    => (int) self::getCfg('desk_sync_cursor', '0'),
            'snapshot'  => self::getCfg('desk_sync_snapshot_done') === '1',
            'pushed'    => (int) self::getCfg('desk_sync_pushed', '0'),
            'pulled'    => (int) self::getCfg('desk_sync_pulled', '0'),
        ];
    }

    /* ---------------- HTTP ---------------- */

    private static function call($action, $payload = [], $fast = false) {
        /* v2.11.0: $fast = lightweight probes (ping). When the internet is
           down, the old 10s-connect + 40s-total timeouts held the desktop's
           single PHP worker hostage — every click in the app waited behind
           the stuck sync request. Probes now give up within seconds. */
        $connectT = $fast ? 4 : 6;
        $totalT   = $fast ? 6 : 40;
        $url = self::getCfg('desk_sync_url');
        $payload['action'] = $action;
        $payload['key']    = self::getCfg('desk_sync_key');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connectT,
            CURLOPT_TIMEOUT        => $totalT,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        // https failed at connection level → retry once over plain http.
        // v2.11.0: skipped when DNS itself failed (device offline) — the
        // fallback could never succeed and only doubled the frozen time.
        if ($body === false && stripos($url, 'https://') === 0 && stripos($err, 'resolve') === false) {
            $ch = curl_init('http://' . substr($url, 8));
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $connectT,
                CURLOPT_TIMEOUT        => $totalT,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
            ]);
            $body = curl_exec($ch);
            if ($body !== false) { $err = ''; $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); }
            curl_close($ch);
        }
        if ($body === false) {
            $hint = '';
            if (stripos($err, 'resolve') !== false) {
                $host = parse_url($url, PHP_URL_HOST);
                $hint = ' — آدرس دامنه «' . $host . '» پیدا نشد؛ املای آدرس سایت را در تنظیمات بررسی کنید';
            }
            throw new Exception('اتصال به سرور برقرار نشد: ' . $err . $hint);
        }
        if ($code !== 200)   throw new Exception('پاسخ نامعتبر سرور (HTTP ' . $code . ')');
        $j = json_decode($body, true);
        if (!is_array($j))   throw new Exception('پاسخ سرور JSON نیست — آدرس desk-sync-api.php را بررسی کنید');
        if (empty($j['ok'])) throw new Exception('خطای سرور: ' . ($j['error'] ?? 'نامشخص'));
        return $j;
    }

    /* ---------------- local schema helpers ---------------- */

    private static function columns($tbl) {
        static $cache = [];
        if (!isset($cache[$tbl])) {
            $rows = DB::fetchAll("SELECT name FROM pragma_table_info(" . DB::getInstance()->getPdo()->quote($tbl) . ")");
            $cache[$tbl] = array_map(function ($r) { return $r['name']; }, $rows);
        }
        return $cache[$tbl];
    }

    private static function suppress($on) {
        if ($on) DB::execute("INSERT INTO desk_sync_suppress (flag) VALUES (1)");
        else     DB::execute("DELETE FROM desk_sync_suppress");
    }

    /**
     * Make desktop-created IDs collision-free vs server IDs.
     * A +1000 safety margin above the highest known ID means the server
     * can create up to 1000 new rows per table between two sync cycles
     * without ever colliding with a desktop-created row.
     */
    public static function bumpSequences() {
        $tables = json_decode(DESK_SYNC_TABLES, true);
        foreach ($tables as $t) {
            try {
                $max = DB::fetch("SELECT COALESCE(MAX(id),0) m FROM \"$t\"");
                $base = max(self::ID_BASE, (int)($max['m'] ?? 0) + 1000);
                $cur = DB::fetch("SELECT seq FROM sqlite_sequence WHERE name = ?", [$t]);
                if ($cur) {
                    if ((int)$cur['seq'] < $base) DB::execute("UPDATE sqlite_sequence SET seq = ? WHERE name = ?", [$base, $t]);
                } else {
                    DB::execute("INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)", [$t, $base]);
                }
            } catch (Throwable $e) { /* table without AUTOINCREMENT */ }
        }
    }

    /* self-heal: make sure every synced table has its local change triggers
       (covers databases created by an older version of the app) */
    public static function ensureLocalTriggers() {
        $tables = json_decode(DESK_SYNC_TABLES, true);
        foreach ($tables as $t) {
            if ($t === 'settings') continue; // has custom key_name triggers in the schema
            try {
                $have = DB::fetch("SELECT COUNT(*) c FROM sqlite_master WHERE type='trigger' AND name = ?", ["trg_sync_{$t}_i"]);
                if ((int)($have['c'] ?? 0) > 0) continue;
                $exists = DB::fetch("SELECT COUNT(*) c FROM sqlite_master WHERE type='table' AND name = ?", [$t]);
                if ((int)($exists['c'] ?? 0) === 0) continue;
                foreach ([['i','INSERT','NEW'], ['u','UPDATE','NEW'], ['d','DELETE','OLD']] as $x) {
                    list($suf, $evt, $ref) = $x;
                    DB::getInstance()->getPdo()->exec(
                        "CREATE TRIGGER IF NOT EXISTS trg_sync_{$t}_{$suf} AFTER {$evt} ON \"{$t}\"\n" .
                        "WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)\n" .
                        "BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('{$t}', {$ref}.id, '" . substr($evt, 0, 1) . "', strftime('%s','now')); END"
                    );
                }
            } catch (Throwable $e) { /* ignore */ }
        }
    }

    /* ---------------- apply one remote change locally ---------------- */

    private static function pkCol($tbl) {
        return $tbl === 'settings' ? 'key_name' : 'id';
    }

    /* settings keys that must stay local (sync/runtime state, not school data) */
    private static function settingsLocal($key) {
        return strpos((string)$key, 'desk_') === 0;
    }

    private static function applyChange($c) {
        $tbl = $c['tbl']; $rid = $c['rid']; $op = $c['op'];
        $tables = json_decode(DESK_SYNC_TABLES, true);
        if (!in_array($tbl, $tables, true)) return;
        if ($tbl === 'settings' && self::settingsLocal($rid)) return;
        $pk = self::pkCol($tbl);
        if ($op === 'D' || empty($c['row'])) {
            DB::execute("DELETE FROM \"$tbl\" WHERE \"$pk\" = ?", [$rid]);
            return;
        }
        $row  = $c['row'];
        $cols = array_values(array_intersect(self::columns($tbl), array_keys($row)));
        if (!$cols) return;
        $ph   = implode(',', array_fill(0, count($cols), '?'));
        $cl   = '"' . implode('","', $cols) . '"';
        $vals = [];
        foreach ($cols as $col) $vals[] = $row[$col];
        DB::execute("REPLACE INTO \"$tbl\" ($cl) VALUES ($ph)", $vals);
    }

    /* ---------------- collect local changes to push ---------------- */

    private static function collectLocal($limit) {
        $rows = DB::fetchAll("SELECT id, tbl, rid, op, ts FROM desk_change_log ORDER BY id ASC LIMIT " . (int)$limit);
        if (!$rows) return [[], 0];
        $tables = json_decode(DESK_SYNC_TABLES, true);
        $latest = []; $maxId = 0;
        foreach ($rows as $r) {
            $maxId = max($maxId, (int)$r['id']);
            if (!in_array($r['tbl'], $tables, true)) continue;
            if ($r['tbl'] === 'settings' && self::settingsLocal($r['rid'])) continue;
            $latest[$r['tbl'] . '|' . $r['rid']] = $r;          // later entries overwrite
        }
        $changes = [];
        foreach ($latest as $r) {
            $c = ['tbl' => $r['tbl'], 'rid' => $r['rid'], 'op' => $r['op'], 'ts' => (int)$r['ts']];
            if ($r['op'] !== 'D') {
                $pk = self::pkCol($r['tbl']);
                $row = DB::fetch("SELECT * FROM \"{$r['tbl']}\" WHERE \"$pk\" = ?", [$r['rid']]);
                if ($row) { $c['op'] = 'U'; $c['row'] = $row; }
                else      { $c['op'] = 'D'; }
            }
            $changes[] = $c;
        }
        return [$changes, $maxId];
    }

    /* ---------------- main cycle ---------------- */

    public static function runIfDue() {
        if (!self::enabled()) return ['ok' => false, 'skipped' => 'disabled'];
        $last = (int) self::getCfg('desk_sync_last', '0');
        if (time() - $last < self::MIN_INTERVAL) return ['ok' => true, 'skipped' => 'recent'];
        return self::run();
    }

    /** rows waiting to be pushed to the site */
    public static function pendingCount() {
        try {
            $r = DB::fetch("SELECT COUNT(*) c FROM desk_change_log");
            return (int)($r['c'] ?? 0);
        } catch (Throwable $e) { return 0; }
    }

    /**
     * Real-time heartbeat (called every few seconds by the UI):
     *  - pushes local changes IMMEDIATELY after any edit,
     *  - pulls site changes within seconds (cheap `ping` checks the site's
     *    change counter, a full cycle runs only when something is new),
     *  - when offline, retries on the 10s/20s/60s ladder and keeps every
     *    change safely in the local change-log until the connection is back.
     */
    public static function tick() {
        if (!self::enabled()) return ['ok' => false, 'skipped' => 'disabled', 'pending' => 0, 'fails' => 0, 'alert' => false];
        $now     = time();
        $pending = self::pendingCount();
        $fails   = (int) self::getCfg('desk_sync_fails', '0');
        $nextTry = (int) self::getCfg('desk_sync_next_try', '0');
        $base    = ['pending' => $pending, 'fails' => $fails, 'alert' => $fails >= 15,
                    'retry_in' => max(0, $nextTry - $now)];

        // offline back-off window still open → wait
        if ($fails > 0 && $now < $nextTry) return ['ok' => true, 'skipped' => 'backoff'] + $base;

        $due = $pending > 0 || self::getCfg('desk_sync_snapshot_done') !== '1';

        // nothing to push → ask the site (cheap) whether IT has news
        if (!$due) {
            try {
                $res = self::call('ping', [], true);
                if ((int)($res['log_max'] ?? 0) > (int) self::getCfg('desk_sync_cursor', '0')) $due = true;
                if ($fails > 0) { self::setCfg('desk_sync_fails', '0'); self::setCfg('desk_sync_next_try', '0'); $fails = 0; }
            } catch (Throwable $e) {
                if (self::isOffline($e)) return self::noteFailure($now) + ['ok' => true, 'skipped' => 'offline', 'pending' => $pending];
                // old server file (no ping action) → fall back to the periodic cycle
                $due = ($now - (int) self::getCfg('desk_sync_last', '0')) >= self::MIN_INTERVAL;
            }
        }

        // periodic safety cycle even when both sides look quiet
        if (!$due && ($now - (int) self::getCfg('desk_sync_last', '0')) >= self::MIN_INTERVAL) $due = true;

        if (!$due) return ['ok' => true, 'skipped' => 'idle'] + $base;

        $res = self::run();
        $pending = self::pendingCount();
        if (!empty($res['ok'])) {
            self::setCfg('desk_sync_fails', '0');
            self::setCfg('desk_sync_next_try', '0');
            return $res + ['pending' => $pending, 'fails' => 0, 'alert' => false, 'retry_in' => 0];
        }
        if (isset($res['error']) && mb_strpos((string)$res['error'], 'اتصال به سرور برقرار نشد') !== false) {
            return self::noteFailure($now) + $res + ['pending' => $pending];
        }
        return $res + ['pending' => $pending, 'fails' => $fails, 'alert' => $fails >= 15, 'retry_in' => 0];
    }

    private static function isOffline(Throwable $e) {
        return mb_strpos($e->getMessage(), 'اتصال به سرور برقرار نشد') !== false;
    }

    /** register one failed attempt and schedule the next one on the ladder */
    private static function noteFailure($now) {
        $fails = (int) self::getCfg('desk_sync_fails', '0') + 1;
        $delay = self::backoffDelay($fails);
        self::setCfg('desk_sync_fails', (string)$fails);
        self::setCfg('desk_sync_next_try', (string)($now + $delay));
        self::setCfg('desk_sync_err', 'اتصال به سرور برقرار نشد — تلاش بعدی تا ' . $delay . ' ثانیه دیگر (تلاش ' . $fails . ')');
        return ['fails' => $fails, 'alert' => $fails >= 15, 'retry_in' => $delay];
    }

    /**
     * v2.11.0: mirror reconcile — the guarantee that desktop and site are
     * IDENTICAL, not just "no missed events". Compares a cheap per-table
     * fingerprint (row count + sum of ids) with the server; any table that
     * differs (leftover demo rows, rows changed before the sync file was
     * installed, a missed trigger, a crash mid-import...) is wiped and
     * re-imported from the site in full. Runs on every manual sync and
     * automatically at most once per hour. Tables with unpushed local
     * changes are skipped — user data is never thrown away.
     */
    private static function reconcile(&$pulled) {
        $sum = self::call('recon');                    // old server file → exception, caught by caller
        $srv = $sum['tables'] ?? [];
        $fixed = [];
        foreach (json_decode(DESK_SYNC_TABLES, true) as $tbl) {
            if (!isset($srv[$tbl])) continue;          // server file older than this table
            $pk = self::pkCol($tbl);
            try {
                $pending = DB::fetch("SELECT 1 x FROM desk_change_log WHERE tbl = ? LIMIT 1", [$tbl]);
                if ($pending) continue;                // unpushed local edits — never overwrite
                if ($pk === 'id') {
                    $loc = DB::fetch("SELECT COUNT(*) n, COALESCE(SUM(id),0) s FROM \"$tbl\"");
                    $same = ((int)$loc['n'] === (int)($srv[$tbl]['n'] ?? -1))
                         && ((string)(int)$loc['s'] === (string)(int)($srv[$tbl]['s'] ?? -1));
                } else {                               // settings: count of non-desk keys
                    $loc = DB::fetch("SELECT COUNT(*) n FROM \"$tbl\" WHERE key_name NOT LIKE 'desk!_%' ESCAPE '!'");
                    $same = ((int)$loc['n'] === (int)($srv[$tbl]['n'] ?? -1));
                }
                if ($same) continue;
                // ---- mismatch → wipe + full re-import of this one table ----
                $aft = ($pk === 'id') ? 0 : '';
                $first = true;
                do {
                    $res  = self::call('snapshot', ['tbl' => $tbl, 'after' => $aft, 'limit' => self::BATCH]);
                    $rows = $res['rows'] ?? [];
                    self::suppress(true);
                    try {
                        if ($first) {
                            if ($tbl === 'admins' && !$rows) break; // never lock the user out
                            if ($tbl === 'settings') DB::execute("DELETE FROM \"settings\" WHERE key_name NOT LIKE 'desk!_%' ESCAPE '!'");
                            else                     DB::execute("DELETE FROM \"$tbl\"");
                            $first = false;
                        }
                        foreach ($rows as $row) {
                            if (!isset($row[$pk])) continue;
                            self::applyChange(['tbl' => $tbl, 'rid' => $row[$pk], 'op' => 'U', 'row' => $row]);
                            $aft = ($pk === 'id') ? max((int)$aft, (int)$row[$pk]) : max((string)$aft, (string)$row[$pk]);
                            $pulled++;
                        }
                    } finally { self::suppress(false); }
                } while (count($rows) >= self::BATCH);
                try { DB::execute("DELETE FROM desk_change_log WHERE tbl = ?", [$tbl]); } catch (Throwable $e) {}
                $fixed[] = $tbl;
            } catch (Throwable $e) {
                if (mb_strpos($e->getMessage(), 'اتصال به سرور') !== false) throw $e; // offline → stop
                /* per-table problem: keep reconciling the rest */
            }
        }
        if ($fixed) self::bumpSequences();
        self::setCfg('desk_sync_recon_last', (string) time());
        return $fixed;
    }

    public static function run($force = false) {
        if (!self::enabled()) return ['ok' => false, 'error' => 'sync disabled'];

        // lock
        $lock = (int) self::getCfg('desk_sync_lock', '0');
        if ($lock && time() - $lock < self::LOCK_TTL) return ['ok' => true, 'skipped' => 'locked'];
        self::setCfg('desk_sync_lock', (string) time());
        self::setCfg('desk_sync_last', (string) time());

        $pushed = 0; $pulled = 0; $warn = ''; $out0 = '';
        try {
            self::ensureLocalTriggers();
            self::bumpSequences();

            // 1) handshake (also auto-installs server-side triggers)
            $hs = self::call('handshake');
            // old server file? → it does not know recently added tables (e.g. bots)
            if (isset($hs['tables']) && is_array($hs['tables'])) {
                $missingSrv = array_diff(json_decode(DESK_SYNC_TABLES, true), $hs['tables']);
                if ($missingSrv) $warn = 'فایل desk-sync-api.php روی سایت قدیمی است — فایل جدید (پوشه server همین بسته) را دوباره آپلود کنید تا این جدول‌ها هم همگام شوند: ' . implode('، ', $missingSrv);
            } elseif (!isset($hs['tables'])) {
                $warn = 'فایل desk-sync-api.php روی سایت قدیمی است — فایل جدید (پوشه server همین بسته) را دوباره آپلود کنید.';
            }

            // 2) first-time snapshot
            if (self::getCfg('desk_sync_snapshot_done') !== '1') {
                $state = json_decode(self::getCfg('desk_sync_state', '{}'), true) ?: [];
                $tables = json_decode(DESK_SYNC_TABLES, true);
                $ti  = (int)($state['ti'] ?? 0);
                $aft = $state['after'] ?? 0;
                while ($ti < count($tables)) {
                    $tbl = $tables[$ti];
                    $pk  = self::pkCol($tbl);
                    if ($aft === 0 && $pk !== 'id') $aft = '';
                    try {
                        $res = self::call('snapshot', ['tbl' => $tbl, 'after' => $aft, 'limit' => self::BATCH]);
                    } catch (Throwable $e) {
                        // old server file does not know this table — skip it,
                        // and remember that so it is imported later (2b) once
                        // the server file has been updated.
                        if (mb_strpos($e->getMessage(), 'bad table') !== false) {
                            $skipped = json_decode(self::getCfg('desk_sync_skipped', '[]'), true) ?: [];
                            if (!in_array($tbl, $skipped, true)) { $skipped[] = $tbl; self::setCfg('desk_sync_skipped', json_encode($skipped)); }
                            $ti++; $aft = 0;
                            self::setCfg('desk_sync_state', json_encode(['ti' => $ti, 'after' => $aft]));
                            continue;
                        }
                        throw $e;
                    }
                    $rows = $res['rows'] ?? [];
                    /* v2.11.0: full-mirror snapshot — before importing the FIRST
                       batch of a table, wipe the local copy (demo/seed rows and
                       stale data must never survive; the site is the source of
                       truth). settings keeps its desk_* keys (sync config!),
                       and admins is only wiped when the server actually sent
                       admins (never lock the user out on a server glitch). */
                    $freshStart = ($aft === 0 || $aft === '' || $aft === '0');
                    if ($freshStart && !($tbl === 'admins' && !$rows)) {
                        self::suppress(true);
                        try {
                            if ($tbl === 'settings') DB::execute("DELETE FROM \"settings\" WHERE key_name NOT LIKE 'desk!_%' ESCAPE '!'");
                            else                     DB::execute("DELETE FROM \"$tbl\"");
                        } catch (Throwable $e) { /* keep going */ }
                        finally { self::suppress(false); }
                    }
                    if ($rows) {
                        self::suppress(true);
                        try {
                            foreach ($rows as $row) {
                                if (!isset($row[$pk])) continue;
                                self::applyChange(['tbl' => $tbl, 'rid' => $row[$pk], 'op' => 'U', 'row' => $row]);
                                $aft = ($pk === 'id') ? max((int)$aft, (int)$row[$pk]) : max((string)$aft, (string)$row[$pk]);
                                $pulled++;
                            }
                        } finally { self::suppress(false); }
                    }
                    if (count($rows) < self::BATCH) { $ti++; $aft = 0; }
                    self::setCfg('desk_sync_state', json_encode(['ti' => $ti, 'after' => $aft]));
                }
                // snapshot rows must not be pushed back
                DB::execute("DELETE FROM desk_change_log");
                self::setCfg('desk_sync_cursor', (string)(isset($res['log_max']) ? $res['log_max'] : 0));
                self::setCfg('desk_sync_snapshot_done', '1');
                $skipped = json_decode(self::getCfg('desk_sync_skipped', '[]'), true) ?: [];
                self::setCfg('desk_sync_snapped', json_encode(array_values(array_diff(
                    json_decode(DESK_SYNC_TABLES, true), $skipped))));
                self::setCfg('desk_sync_skipped', '[]');
                self::bumpSequences();
            }

            // 2b) tables added in an app update AFTER the first snapshot:
            //     import them once, without redoing the whole snapshot.
            $snapped = json_decode(self::getCfg('desk_sync_snapped', '[]'), true) ?: [];
            if (!$snapped && self::getCfg('desk_sync_snapshot_done') === '1') {
                // upgraded from a pre-2.4 install: everything except the
                // bot tables was already imported by the original snapshot
                $snapped = array_values(array_diff(json_decode(DESK_SYNC_TABLES, true), [
                    'bale_bot_users','telegram_bot_users','bot_admin_sessions',
                    'bot_message_templates','bot_button_templates','bot_login_tokens','bot_message_logs',
                ]));
                self::setCfg('desk_sync_snapped', json_encode($snapped));
            }
            $newTables = array_diff(json_decode(DESK_SYNC_TABLES, true), $snapped);
            // self-heal: bot tables that are EMPTY locally are (re)imported even
            // if an older version wrongly marked them as already snapped
            // (v2.4.0 recorded skipped tables as done). Costs one tiny call per
            // empty table and is skipped when local deletions are still pending.
            foreach (['bale_bot_users','telegram_bot_users','bot_admin_sessions',
                      'bot_message_templates','bot_button_templates','bot_login_tokens','bot_message_logs'] as $bt) {
                if (in_array($bt, $newTables, true)) continue;
                try {
                    $n = DB::fetch("SELECT COUNT(*) AS c FROM \"$bt\"");
                    if ((int)($n['c'] ?? 0) > 0) continue;
                    $pending = DB::fetch("SELECT 1 AS x FROM desk_change_log WHERE tbl = ? LIMIT 1", [$bt]);
                    if ($pending) continue; // local edits not pushed yet — do not overwrite
                    $newTables[] = $bt;
                } catch (Throwable $e) { /* table missing locally → schema upgrade handles it */ }
            }
            $srvTables = (isset($hs['tables']) && is_array($hs['tables'])) ? $hs['tables'] : null;
            foreach ($newTables as $tbl) {
                if ($srvTables !== null && !in_array($tbl, $srvTables, true)) continue; // server file too old for this table
                $pk = self::pkCol($tbl);
                $aft = ($pk === 'id') ? 0 : '';
                $srvOld = false;
                do {
                    try {
                        $res = self::call('snapshot', ['tbl' => $tbl, 'after' => $aft, 'limit' => self::BATCH]);
                    } catch (Throwable $e) {
                        if (mb_strpos($e->getMessage(), 'bad table') !== false) { $rows = []; $srvOld = true; break; }
                        throw $e;
                    }
                    $rows = $res['rows'] ?? [];
                    if ($rows) {
                        self::suppress(true);
                        try {
                            foreach ($rows as $row) {
                                if (!isset($row[$pk])) continue;
                                self::applyChange(['tbl' => $tbl, 'rid' => $row[$pk], 'op' => 'U', 'row' => $row]);
                                $aft = ($pk === 'id') ? max((int)$aft, (int)$row[$pk]) : max((string)$aft, (string)$row[$pk]);
                                $pulled++;
                            }
                        } finally { self::suppress(false); }
                    }
                } while (count($rows) >= self::BATCH);
                // rows imported this way must not echo back
                try { DB::execute("DELETE FROM desk_change_log WHERE tbl = ?", [$tbl]); } catch (Throwable $e) {}
                if ($srvOld) {
                    // server file too old for this table — leave it un-snapped so
                    // it is retried once the new desk-sync-api.php is uploaded
                    $snapped = array_values(array_diff($snapped, [$tbl]));
                } elseif (!in_array($tbl, $snapped, true)) {
                    $snapped[] = $tbl;
                }
                self::setCfg('desk_sync_snapped', json_encode(array_values($snapped)));
                self::bumpSequences();
            }

            // 3) pull server changes
            do {
                $cursor = (int) self::getCfg('desk_sync_cursor', '0');
                $res = self::call('pull', ['cursor' => $cursor, 'limit' => self::BATCH]);
                $changes = $res['changes'] ?? [];
                if ($changes) {
                    self::suppress(true);
                    try {
                        foreach ($changes as $c) { self::applyChange($c); $pulled++; }
                    } finally { self::suppress(false); }
                }
                self::setCfg('desk_sync_cursor', (string)($res['next_cursor'] ?? $cursor));
            } while (!empty($res['more']));

            // 4) push local changes
            do {
                list($changes, $maxId) = self::collectLocal(self::BATCH);
                if (!$changes) break;
                self::call('push', ['changes' => $changes]);
                DB::execute("DELETE FROM desk_change_log WHERE id <= ?", [$maxId]);
                $pushed += count($changes);
            } while (count($changes) >= 1 && $maxId > 0 && DB::fetch("SELECT id FROM desk_change_log LIMIT 1"));

            // 5) v2.11.0: mirror reconcile — desktop must EQUAL the site, not
            //    just receive its events. Every manual sync + hourly otherwise.
            $reconLast = (int) self::getCfg('desk_sync_recon_last', '0');
            if ($force || time() - $reconLast >= self::RECON_INTERVAL) {
                try {
                    $fixed = self::reconcile($pulled);
                    if ($fixed) $out0 = 'جدول‌های ناهمسان دوباره از سایت دریافت شدند: ' . implode('، ', $fixed);
                } catch (Throwable $e) {
                    if (mb_strpos($e->getMessage(), 'اتصال به سرور') !== false) throw $e;
                    if (mb_strpos($e->getMessage(), 'unknown action') !== false && $warn === '')
                        $warn = 'فایل desk-sync-api.php روی سایت قدیمی است — فایل جدید (پوشه server همین بسته) را دوباره آپلود کنید تا مقایسه کامل دسکتاپ/سایت هم فعال شود.';
                }
            }

            self::setCfg('desk_sync_err', $warn);
            self::setCfg('desk_sync_last_ok', (string) time());
            self::setCfg('desk_sync_pushed', (string)((int)self::getCfg('desk_sync_pushed','0') + $pushed));
            self::setCfg('desk_sync_pulled', (string)((int)self::getCfg('desk_sync_pulled','0') + $pulled));
            $out = ['ok' => true, 'pushed' => $pushed, 'pulled' => $pulled];
            if (!empty($out0)) $out['reconciled'] = $out0;
            if ($warn !== '') $out['warning'] = $warn;
        } catch (Throwable $e) {
            self::setCfg('desk_sync_err', $e->getMessage());
            $out = ['ok' => false, 'error' => $e->getMessage(), 'pushed' => $pushed, 'pulled' => $pulled];
        }
        self::setCfg('desk_sync_lock', '0');
        return $out;
    }
}
