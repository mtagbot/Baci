<?php
// File: desk-sync-api.php   (روی سرور/سایت اصلی آپلود شود — کنار index.php)
/**
 * SchoolDesk Pro — server-side sync endpoint (MySQL).
 *
 * Upload this single file to the ROOT of the live site. On the first
 * handshake it automatically creates its change-log table and installs
 * AFTER INSERT/UPDATE/DELETE triggers on all synced tables, so every
 * change made on the website is recorded and can be pulled by the
 * desktop app. Changes pushed by the desktop are applied with the
 * triggers temporarily disabled (via @desk_sync_suppress session var).
 *
 * SECURITY: set your own long random key below — the SAME key must be
 * entered in the desktop app's sync settings page.
 */

define('DESK_SYNC_KEY', 'SDP-63fd161031e1f130a6530e6d161c65f1f86b87ca1d03fb99');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

const SYNC_TABLES = [
    'admins',
    'academic_years','teachers','discipline_titles','classes','subjects',
    'class_schedules','students','student_discipline_records','reports',
    'report_grades','report_locks','exam_schedules','exam_designs',
    'exam_question_bank','exam_assignments','exam_student_seating',
    'online_exam_categories','online_question_categories','online_question_bank',
    'online_exams','online_questions','online_exam_attempts','online_exam_answers',
    'grade_messages','counseling_requests','student_attendance','student_qr_tags',
    'bale_bot_users','telegram_bot_users','bot_admin_sessions',
    'bot_message_templates','bot_button_templates','bot_login_tokens','bot_message_logs',
    'activity_logs','notifications','sms_logs','report_parent_reviews',
    'settings',
];

/* settings uses a string primary key; everything else uses `id` */
function pk_col($t) { return $t === 'settings' ? 'key_name' : 'id'; }

function jout($data) { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function jfail($msg) { jout(['ok' => false, 'error' => $msg]); }

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) jfail('bad request');
if (strpos(DESK_SYNC_KEY, 'CHANGE-ME') === 0 || strlen(DESK_SYNC_KEY) < 16) jfail('server key not configured — edit desk-sync-api.php');
if (!hash_equals(DESK_SYNC_KEY, (string)($in['key'] ?? ''))) { http_response_code(200); jfail('invalid key'); }

$pdo = DB::getInstance()->getPdo();
if (!$pdo) jfail('database unavailable');
$action = $in['action'] ?? '';

/* ------------------------------------------------------------------ */
/* helpers                                                             */
/* ------------------------------------------------------------------ */

function table_exists($pdo, $t) {
    try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); return true; }
    catch (Exception $e) { return false; }
}

function suppress_triggers($pdo, $on) {
    // MySQL: session variable checked inside the triggers
    try { $pdo->exec($on ? "SET @desk_sync_suppress = 1" : "SET @desk_sync_suppress = NULL"); } catch (Exception $e) {}
    // SQLite fallback (when this API runs on a SQLite-based mirror)
    try {
        if ($on) $pdo->exec("INSERT INTO desk_sync_suppress (flag) VALUES (1)");
        else     $pdo->exec("DELETE FROM desk_sync_suppress");
    } catch (Exception $e) {}
}

function table_columns($pdo, $t) {
    static $cache = [];
    if (!isset($cache[$t])) {
        $cols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM `$t`") as $r) $cols[] = $r['Field'];
        $cache[$t] = $cols;
    }
    return $cache[$t];
}

function is_sqlite($pdo) {
    try { return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'; }
    catch (Exception $e) { return false; }
}

function ensure_infra($pdo) {
    if (is_sqlite($pdo)) {
        // SQLite mirror: schema file already provides desk_change_log + triggers
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS desk_change_log (
        id BIGINT NOT NULL AUTO_INCREMENT,
        tbl VARCHAR(64) NOT NULL,
        rid VARCHAR(128) NOT NULL,
        op CHAR(1) NOT NULL,
        ts INT NOT NULL,
        PRIMARY KEY (id),
        KEY idx_tbl (tbl, rid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach (SYNC_TABLES as $t) {
        if (!table_exists($pdo, $t)) continue;
        $pk = pk_col($t);
        foreach (['i' => 'INSERT', 'u' => 'UPDATE', 'd' => 'DELETE'] as $suf => $evt) {
            $trg = "desk_sync_{$t}_{$suf}";
            $ref = ($evt === 'DELETE') ? 'OLD' : 'NEW';
            $cond = "@desk_sync_suppress IS NULL";
            if ($t === 'settings') $cond .= " AND $ref.key_name NOT LIKE 'desk!_%' ESCAPE '!'";
            try {
                $exists = $pdo->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
                    WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = " . $pdo->quote($trg))->fetchColumn();
                if ($exists) continue;
                $pdo->exec("CREATE TRIGGER `$trg` AFTER $evt ON `$t` FOR EACH ROW
                    BEGIN
                        IF $cond THEN
                            INSERT INTO desk_change_log (tbl, rid, op, ts)
                            VALUES ('$t', $ref.`$pk`, '" . substr($evt, 0, 1) . "', UNIX_TIMESTAMP());
                        END IF;
                    END");
            } catch (Exception $e) { /* no TRIGGER privilege → poll-only mode still works for push */ }
        }
    }
}

/* ------------------------------------------------------------------ */
/* actions                                                             */
/* ------------------------------------------------------------------ */

/* lightweight poll: lets the desktop know instantly (every few seconds)
   whether the site has produced new changes — no trigger install, no data */
if ($action === 'ping') {
    $max = 0;
    try { $max = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM desk_change_log")->fetchColumn(); } catch (Exception $e) {}
    jout(['ok' => true, 'log_max' => $max]);
}

if ($action === 'handshake') {
    ensure_infra($pdo);
    $trg = 0;
    try {
        $trg = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'desk_sync_%'")->fetchColumn();
    } catch (Exception $e) {}
    jout(['ok' => true, 'server' => 'desk-sync-api v2.13', 'triggers' => $trg, 'tables' => SYNC_TABLES]);
}

/* v2.11.0: cheap per-table fingerprint (row count + sum of ids) so the desktop
   can verify it is a TRUE MIRROR of the site and re-import any table that
   drifted (demo rows, pre-sync edits, missed trigger, crash mid-import). */
if ($action === 'recon') {
    $out = [];
    foreach (SYNC_TABLES as $t) {
        if (!table_exists($pdo, $t)) continue;
        try {
            if ($t === 'settings') {
                $r = $pdo->query("SELECT COUNT(*) n FROM `settings` WHERE key_name NOT LIKE 'desk!_%' ESCAPE '!'")->fetch(PDO::FETCH_ASSOC);
                $out[$t] = ['n' => (int)$r['n'], 's' => 0];
            } else {
                $r = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(`id`),0) s FROM `$t`")->fetch(PDO::FETCH_ASSOC);
                $out[$t] = ['n' => (int)$r['n'], 's' => (string)(int)$r['s']];
            }
        } catch (Exception $e) { /* skip */ }
    }
    jout(['ok' => true, 'tables' => $out]);
}

if ($action === 'snapshot') {
    $tbl = (string)($in['tbl'] ?? '');
    if (!in_array($tbl, SYNC_TABLES, true)) jfail('bad table');
    if (!table_exists($pdo, $tbl)) jout(['ok' => true, 'rows' => [], 'log_max' => 0]);
    $pk = pk_col($tbl);
    $after = $in['after'] ?? 0;
    $limit = min(1000, max(1, (int)($in['limit'] ?? 400)));
    $extra = ($tbl === 'settings') ? " AND key_name NOT LIKE 'desk!_%' ESCAPE '!'" : '';
    $st = $pdo->prepare("SELECT * FROM `$tbl` WHERE `$pk` > ?$extra ORDER BY `$pk` ASC LIMIT $limit");
    $st->execute([$after]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $logMax = 0;
    try { $logMax = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM desk_change_log")->fetchColumn(); } catch (Exception $e) {}
    jout(['ok' => true, 'rows' => $rows, 'log_max' => $logMax]);
}

if ($action === 'pull') {
    ensure_infra($pdo);
    $cursor = (int)($in['cursor'] ?? 0);
    $limit  = min(1000, max(1, (int)($in['limit'] ?? 400)));
    $st = $pdo->prepare("SELECT id, tbl, rid, op, ts FROM desk_change_log WHERE id > ? ORDER BY id ASC LIMIT $limit");
    $st->execute([$cursor]);
    $log = $st->fetchAll(PDO::FETCH_ASSOC);
    $next = $cursor;
    $latest = [];
    foreach ($log as $r) {
        $latest[$r['tbl'] . '|' . $r['rid']] = $r;
        $next = max($next, (int)$r['id']);
    }
    $changes = [];
    foreach ($latest as $r) {
        $c = ['tbl' => $r['tbl'], 'rid' => $r['rid'], 'op' => $r['op'], 'ts' => (int)$r['ts']];
        if ($r['op'] !== 'D' && table_exists($pdo, $r['tbl'])) {
            $pk = pk_col($r['tbl']);
            $st2 = $pdo->prepare("SELECT * FROM `{$r['tbl']}` WHERE `$pk` = ?");
            $st2->execute([$r['rid']]);
            $row = $st2->fetch(PDO::FETCH_ASSOC);
            if ($row) { $c['op'] = 'U'; $c['row'] = $row; } else { $c['op'] = 'D'; }
        }
        $changes[] = $c;
    }
    jout(['ok' => true, 'changes' => $changes, 'next_cursor' => $next, 'more' => count($log) >= $limit]);
}

if ($action === 'push') {
    ensure_infra($pdo);
    $changes = $in['changes'] ?? [];
    if (!is_array($changes)) jfail('bad changes');
    $applied = 0;
    suppress_triggers($pdo, true);
    foreach ($changes as $c) {
        $tbl = (string)($c['tbl'] ?? '');
        if (!in_array($tbl, SYNC_TABLES, true) || !table_exists($pdo, $tbl)) continue;
        $rid = $c['rid'] ?? null;
        if ($rid === null) continue;
        $pk = pk_col($tbl);
        if ($tbl === 'settings' && strpos((string)$rid, 'desk_') === 0) continue;
        try {
            if (($c['op'] ?? '') === 'D' || empty($c['row'])) {
                $st = $pdo->prepare("DELETE FROM `$tbl` WHERE `$pk` = ?");
                $st->execute([$rid]);
            } else {
                $row  = $c['row'];
                $cols = array_values(array_intersect(table_columns($pdo, $tbl), array_keys($row)));
                if (!$cols) continue;
                $cl = '`' . implode('`,`', $cols) . '`';
                $ph = implode(',', array_fill(0, count($cols), '?'));
                $up = [];
                foreach ($cols as $col) if ($col !== $pk) $up[] = "`$col` = VALUES(`$col`)";
                $sql = "INSERT INTO `$tbl` ($cl) VALUES ($ph)"
                     . ($up ? " ON DUPLICATE KEY UPDATE " . implode(',', $up) : "");
                $st = $pdo->prepare($sql);
                $vals = [];
                foreach ($cols as $col) $vals[] = $row[$col];
                $st->execute($vals);
            }
            $applied++;
        } catch (Exception $e) { /* skip broken row, keep going */ }
    }
    suppress_triggers($pdo, false);
    jout(['ok' => true, 'applied' => $applied]);
}

/* v2.12.0: file-sync actions — the desktop must mirror not only rows but the
   FILES they reference (exam source PDFs, converted page images, student
   photos, stamps). All paths are locked inside uploads/. */
function desk_safe_rel($p) {
    $p = str_replace('\\', '/', trim((string)$p));
    $p = ltrim($p, '/');
    if ($p === '' || strpos($p, '..') !== false || strpos($p, "\0") !== false) return '';
    if (strpos($p, 'uploads/') !== 0) return '';
    return $p;
}

if ($action === 'files_list') {
    $dirs = $in['dirs'] ?? [];
    if (!is_array($dirs)) $dirs = [];
    $out = [];
    foreach (array_slice($dirs, 0, 200) as $d) {
        $rel = desk_safe_rel($d);
        if ($rel === '') continue;
        $full = __DIR__ . '/' . $rel;
        $files = [];
        if (is_dir($full)) {
            foreach (scandir($full) ?: [] as $fn) {
                if ($fn === '' || $fn[0] === '.') continue;
                $fp = $full . '/' . $fn;
                if (is_file($fp)) $files[] = ['p' => $rel . '/' . $fn, 's' => (int)filesize($fp), 'm' => (int)filemtime($fp)];
            }
        }
        $out[$rel] = $files;
    }
    jout(['ok' => true, 'dirs' => $out]);
}

if ($action === 'files_stat') {
    $paths = $in['paths'] ?? [];
    if (!is_array($paths)) $paths = [];
    $out = [];
    foreach (array_slice($paths, 0, 2000) as $p) {
        $rel = desk_safe_rel($p);
        if ($rel === '') continue;
        $full = __DIR__ . '/' . $rel;
        $out[$rel] = is_file($full) ? ['s' => (int)filesize($full), 'm' => (int)filemtime($full)] : ['s' => -1, 'm' => 0];
    }
    jout(['ok' => true, 'files' => $out]);
}

if ($action === 'file_get') {
    $rel = desk_safe_rel($in['path'] ?? '');
    if ($rel === '') jfail('bad path');
    $full = __DIR__ . '/' . $rel;
    if (!is_file($full)) jfail('not found');
    $size = (int)filesize($full);
    if ($size > 25 * 1024 * 1024) jfail('file too large');
    jout(['ok' => true, 'b64' => base64_encode((string)file_get_contents($full)), 's' => $size, 'm' => (int)filemtime($full)]);
}

/* v2.13.0: reverse exam-design path — an exam DESIGNED ON THE DESKTOP with a
   PDF source cannot be rendered there (the portable app has no Imagick or
   Ghostscript). The desktop pushes the PDF via file_put and then calls this
   action: the SITE converts the PDF to page images (same proven logic as
   exam-source-api.php) and returns the list, so the desktop pulls them back
   and both sides show the exam identically. */
if ($action === 'exam_pages') {
    $examId = (int)($in['exam_id'] ?? 0);
    if ($examId <= 0) jfail('bad exam id');
    $qf = '';
    try {
        $st = $pdo->prepare('SELECT question_file FROM exam_schedules WHERE id = ?');
        $st->execute([$examId]);
        $qf = (string)($st->fetchColumn() ?: '');
    } catch (Exception $e) { /* row may not have synced yet */ }
    // desktop may convert BEFORE its exam row reaches the site → accept the
    // explicit path too (still locked inside uploads/)
    if ($qf === '' && !empty($in['path'])) $qf = (string)$in['path'];
    $rel = desk_safe_rel($qf);
    if ($rel === '') jout(['ok' => true, 'pages' => []]);
    $full = __DIR__ . '/' . $rel;
    if (!is_file($full)) jout(['ok' => true, 'pages' => []]);
    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') jout(['ok' => true, 'pages' => [$rel]]);  // image source needs no conversion

    $cacheDir = __DIR__ . '/uploads/exams/pdf-pages/exam_' . $examId;
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    $declaredPages = max(0, (int)($in['declared_pages'] ?? 0));
    $pages = [];
    // valid cache (newer than the pdf, and complete) → reuse
    foreach (glob($cacheDir . '/page_*.jpg') ?: [] as $img) {
        if (filemtime($img) < filemtime($full)) @unlink($img); else $pages[] = 'uploads/exams/pdf-pages/exam_' . $examId . '/' . basename($img);
    }
    if ($pages && ($declaredPages <= 0 || count($pages) >= $declaredPages)) { sort($pages); jout(['ok' => true, 'pages' => $pages]); }
    $pages = [];
    if ($declaredPages <= 0 && class_exists('Imagick')) {
        try { $probe = new Imagick(); $probe->pingImage($full); $declaredPages = max(1, (int)$probe->getNumberImages()); $probe->clear(); } catch (Exception $e) { $declaredPages = 1; }
    }
    if ($declaredPages <= 0) $declaredPages = 1;
    // 1) Imagick — render one source page per output image (never flatten)
    if (class_exists('Imagick')) {
        for ($i = 0; $i < $declaredPages; $i++) {
            try {
                $page = new Imagick(); $page->setResolution(180, 180); $page->readImage($full . '[' . $i . ']');
                $page->setIteratorIndex(0); $page->setImageBackgroundColor('white');
                if (defined('Imagick::ALPHACHANNEL_REMOVE')) $page->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $page->setImageFormat('jpeg'); $page->setImageCompressionQuality(90);
                $file = $cacheDir . '/page_' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT) . '.jpg';
                $page->writeImage($file); $page->clear();
                $pages[] = 'uploads/exams/pdf-pages/exam_' . $examId . '/' . basename($file);
            } catch (Exception $e) { break; }
        }
    }
    // 2) Ghostscript CLI fallback
    if (count($pages) < $declaredPages && function_exists('exec')) {
        foreach (glob($cacheDir . '/page_*.jpg') ?: [] as $old) @unlink($old);
        $pages = [];
        for ($i = 1; $i <= $declaredPages; $i++) {
            $file = $cacheDir . '/page_' . str_pad((string)$i, 3, '0', STR_PAD_LEFT) . '.jpg';
            @exec('gs -q -dSAFER -dBATCH -dNOPAUSE -sDEVICE=jpeg -r180 -dJPEGQ=90 -dFirstPage=' . (int)$i . ' -dLastPage=' . (int)$i . ' -sOutputFile=' . escapeshellarg($file) . ' ' . escapeshellarg($full) . ' 2>&1', $o1, $c1);
            if ($c1 === 0 && is_file($file)) $pages[] = 'uploads/exams/pdf-pages/exam_' . $examId . '/' . basename($file);
        }
    }
    // 3) ImageMagick CLI fallback (hosts without the PHP extension)
    if (count($pages) < $declaredPages && function_exists('exec')) {
        foreach (glob($cacheDir . '/page_*.jpg') ?: [] as $old) @unlink($old);
        $pages = [];
        foreach (['magick', 'convert'] as $bin) {
            for ($i = 0; $i < $declaredPages; $i++) {
                $file = $cacheDir . '/page_' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT) . '.jpg';
                @exec($bin . ' -density 180 ' . escapeshellarg($full . '[' . $i . ']') . ' -background white -alpha remove -quality 90 ' . escapeshellarg($file) . ' 2>&1', $o2, $c2);
                if ($c2 === 0 && is_file($file)) $pages[] = 'uploads/exams/pdf-pages/exam_' . $examId . '/' . basename($file);
            }
            if (count($pages) >= $declaredPages) break;
        }
    }
    sort($pages);
    jout(['ok' => true, 'pages' => array_slice($pages, 0, $declaredPages), 'converted' => count($pages) > 0]);
}

if ($action === 'file_put') {
    $rel = desk_safe_rel($in['path'] ?? '');
    if ($rel === '') jfail('bad path');
    $b64 = (string)($in['b64'] ?? '');
    $data = base64_decode($b64, true);
    if ($data === false || strlen($data) > 25 * 1024 * 1024) jfail('bad data');
    $full = __DIR__ . '/' . $rel;
    $dir = dirname($full);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (@file_put_contents($full, $data) === false) jfail('write failed');
    $m = (int)($in['m'] ?? 0);
    if ($m > 0) @touch($full, $m);
    jout(['ok' => true, 's' => strlen($data)]);
}

jfail('unknown action');
