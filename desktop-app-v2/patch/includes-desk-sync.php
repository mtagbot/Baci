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
        'settings',
    ]));
}

class DeskSync {

    const ID_BASE = 5000000;      // desktop-created rows start here
    const BATCH   = 400;
    const MIN_INTERVAL = 120;     // seconds between automatic runs
    const LOCK_TTL     = 180;     // stale-lock takeover

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

    private static function call($action, $payload = []) {
        $url = self::getCfg('desk_sync_url');
        $payload['action'] = $action;
        $payload['key']    = self::getCfg('desk_sync_key');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 40,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) throw new Exception('اتصال به سرور برقرار نشد: ' . $err);
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

    public static function run() {
        if (!self::enabled()) return ['ok' => false, 'error' => 'sync disabled'];

        // lock
        $lock = (int) self::getCfg('desk_sync_lock', '0');
        if ($lock && time() - $lock < self::LOCK_TTL) return ['ok' => true, 'skipped' => 'locked'];
        self::setCfg('desk_sync_lock', (string) time());
        self::setCfg('desk_sync_last', (string) time());

        $pushed = 0; $pulled = 0;
        try {
            self::bumpSequences();

            // 1) handshake (also auto-installs server-side triggers)
            self::call('handshake');

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
                    $res = self::call('snapshot', ['tbl' => $tbl, 'after' => $aft, 'limit' => self::BATCH]);
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
                    if (count($rows) < self::BATCH) { $ti++; $aft = 0; }
                    self::setCfg('desk_sync_state', json_encode(['ti' => $ti, 'after' => $aft]));
                }
                // snapshot rows must not be pushed back
                DB::execute("DELETE FROM desk_change_log");
                self::setCfg('desk_sync_cursor', (string)($res['log_max'] ?? 0));
                self::setCfg('desk_sync_snapshot_done', '1');
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

            self::setCfg('desk_sync_err', '');
            self::setCfg('desk_sync_last_ok', (string) time());
            self::setCfg('desk_sync_pushed', (string)((int)self::getCfg('desk_sync_pushed','0') + $pushed));
            self::setCfg('desk_sync_pulled', (string)((int)self::getCfg('desk_sync_pulled','0') + $pulled));
            $out = ['ok' => true, 'pushed' => $pushed, 'pulled' => $pulled];
        } catch (Throwable $e) {
            self::setCfg('desk_sync_err', $e->getMessage());
            $out = ['ok' => false, 'error' => $e->getMessage(), 'pushed' => $pushed, 'pulled' => $pulled];
        }
        self::setCfg('desk_sync_lock', '0');
        return $out;
    }
}
