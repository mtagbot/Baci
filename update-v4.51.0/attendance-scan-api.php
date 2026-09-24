<?php
// File: attendance-scan-api.php  (v4.51.0)
/**
 * JSON API for the QR attendance scanner kiosk.
 * Auth: scanner key (settings.attendance_scanner_key) — the kiosk device
 * doesn't need an admin session, so a tablet/PC at the gate can run all day.
 * Also accepts logged-in admins/deputies without the key.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/attendance_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function att_json($arr) { echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;

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
        $res = att_process_scan($payload);
        // Opportunistic auto-absent run (so the kiosk alone keeps the system live).
        try { att_finalize_absents(false); } catch (Exception $e) {}
        att_json($res);
    } catch (Exception $e) {
        att_json(['ok' => false, 'code' => 'error', 'message' => 'خطای سرور: ' . $e->getMessage()]);
    }
}

if ($action === 'status') {
    ensure_attendance_schema_v2();
    $today = jdate('Y/m/d');
    $t = att_setting_times();
    $st = DB::fetch("SELECT SUM(status='present') present, SUM(status='late') late, SUM(status='absent') absent FROM student_attendance WHERE date_jalali=?", [$today]);
    $totalActive = DB::fetch("SELECT COUNT(*) c FROM students WHERE status='active'");
    $recent = DB::fetchAll("SELECT a.status, a.scan_time, a.minutes_late, s.first_name, s.last_name, s.class_name FROM student_attendance a JOIN students s ON s.id=a.student_id WHERE a.date_jalali=? AND a.source='qr' ORDER BY a.id DESC LIMIT 8", [$today]);
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
}

if ($action === 'finalize') {
    try {
        $res = att_finalize_absents(true);
        att_json(['ok' => true] + $res);
    } catch (Exception $e) {
        att_json(['ok' => false, 'code' => 'error', 'message' => $e->getMessage()]);
    }
}

att_json(['ok' => false, 'code' => 'unknown_action', 'message' => 'درخواست ناشناخته.']);
