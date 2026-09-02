<?php
// File: attendance-scan-api.php  (v4.56.0)
/**
 * JSON API for the QR attendance scanner kiosk.
 * Auth: scanner key (settings.attendance_scanner_key) — the kiosk device
 * doesn't need an admin session, so a tablet/PC at the gate can run all day.
 * Also accepts logged-in admins/deputies without the key.
 *
 * v4.56.0 fixes «خطای شبکه» on phones:
 *  - The JSON answer is flushed to the client IMMEDIATELY; slow work
 *    (Bale/Telegram parent notifications, auto-absent finalizer) runs
 *    AFTER the response, so the scanner never waits on bot HTTP calls.
 *  - display_errors is forced off + all stray output is buffered and
 *    discarded, so a PHP warning can no longer corrupt the JSON body.
 *  - Fatal errors (Throwable + shutdown handler) also return clean JSON.
 */

define('ATT_DEFER_NOTIFY', true);           // helpers queue notifications instead of blocking
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
while (ob_get_level()) { ob_end_clean(); }
ob_start();                                  // swallow any stray warnings/BOM/whitespace

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/attendance_helpers.php';

$GLOBALS['att_json_sent'] = false;

/** Send the JSON body to the client NOW, keep PHP alive for background work. */
function att_json_emit($arr) {
    if (!empty($GLOBALS['att_json_sent'])) return;
    $GLOBALS['att_json_sent'] = true;
    $body = json_encode($arr, JSON_UNESCAPED_UNICODE);
    while (ob_get_level()) { ob_end_clean(); }   // drop anything PHP tried to print
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Content-Length: ' . strlen($body));
        header('Connection: close');
    }
    echo $body;
    @ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
    else { @flush(); }
}

function att_json($arr) { att_json_emit($arr); exit; }

/* If PHP dies fatally mid-request, still hand the scanner valid JSON. */
register_shutdown_function(function () {
    if (!empty($GLOBALS['att_json_sent'])) return;
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        att_json_emit(['ok' => false, 'code' => 'error', 'message' => 'خطای داخلی سرور. جزئیات در لاگ سرور ثبت شد.']);
    }
});

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;            // form-encoded fallback (some hosts block JSON bodies)

$key = trim((string)($in['key'] ?? $_GET['key'] ?? ''));
$storedKey = get_setting('attendance_scanner_key', '');
$authed = ($storedKey !== '' && $key !== '' && hash_equals($storedKey, $key));
if (!$authed) {
    // fallback: logged-in staff session
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $authed = !empty($_SESSION['admin_id']) || !empty($_SESSION['teacher_id']);
}
if (!$authed) att_json(['ok' => false, 'code' => 'auth', 'message' => 'کلید اسکنر معتبر نیست.']);

$action = (string)($in['action'] ?? $_GET['action'] ?? 'scan');

if ($action === 'scan') {
    $payload = (string)($in['payload'] ?? '');
    try {
        @set_time_limit(300);
        $res = att_process_scan($payload);
        att_json_emit($res);                 // scanner gets its answer instantly
        // ---- everything below runs AFTER the response ----
        if (function_exists('att_run_deferred')) att_run_deferred();  // parent notifications
        // Opportunistic auto-absent run (so the kiosk alone keeps the system live).
        try { att_finalize_absents(false); } catch (Throwable $e) { error_log('finalize after scan: ' . $e->getMessage()); }
    } catch (Throwable $e) {
        att_json_emit(['ok' => false, 'code' => 'error', 'message' => 'خطای سرور: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'status') {
    try {
        ensure_attendance_schema_v2();
        $today = att_today();
        $t = att_setting_times();
        $df = att_day_forms($today);
        $dfPh = implode(',', array_fill(0, count($df), '?'));
        // v4.76.0: stats cover ONLY students of the default academic year
        list($yearSql, $yearParams) = att_year_sql('s');
        $st = DB::fetch("SELECT SUM(a.status='present') present, SUM(a.status='late') late, SUM(a.status='absent') absent FROM student_attendance a JOIN students s ON s.id=a.student_id WHERE a.date_jalali IN ($dfPh) AND $yearSql", array_merge($df, $yearParams));
        $totalActive = DB::fetch("SELECT COUNT(*) c FROM students s WHERE s.status='active' AND $yearSql", $yearParams);
        $recent = DB::fetchAll("SELECT a.status, a.scan_time, a.minutes_late, s.first_name, s.last_name, s.class_name FROM student_attendance a JOIN students s ON s.id=a.student_id WHERE a.date_jalali IN ($dfPh) AND a.source='qr' AND $yearSql ORDER BY a.id DESC LIMIT 8", array_merge($df, $yearParams));
        att_json([
            'ok' => true,
            'date' => tr_num($today, 'fa'),
            'time' => tr_num(att_now_time(), 'fa'),
            'present_until' => tr_num($t['present_until'], 'fa'),
            'absent_at' => tr_num($t['absent_at'], 'fa'),
            'present' => (int)($st['present'] ?? 0),
            'late' => (int)($st['late'] ?? 0),
            'absent' => (int)($st['absent'] ?? 0),
            'total' => (int)($totalActive['c'] ?? 0),
            'recent' => array_map(function ($r) {
                return [
                    'name' => trim($r['first_name'] . ' ' . $r['last_name']),
                    'class' => $r['class_name'],
                    'time' => tr_num((string)$r['scan_time'], 'fa'),
                    'status' => $r['status'] === 'present' ? 'حضور' : ('تأخیر ' . tr_num((int)$r['minutes_late'], 'fa') . "'"),
                    'late' => $r['status'] === 'late',
                ];
            }, $recent),
        ]);
    } catch (Throwable $e) {
        att_json(['ok' => false, 'code' => 'error', 'message' => 'خطای سرور: ' . $e->getMessage()]);
    }
}

if ($action === 'finalize') {
    try {
        @set_time_limit(600);
        $res = att_finalize_absents(true);
        att_json_emit(['ok' => true] + $res);
        if (function_exists('att_run_deferred')) att_run_deferred();
    } catch (Throwable $e) {
        att_json_emit(['ok' => false, 'code' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

att_json(['ok' => false, 'code' => 'unknown_action', 'message' => 'درخواست ناشناخته.']);
