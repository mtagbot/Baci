<?php
// Debug page for live monitoring
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/online_exam_helpers.php';
require_once __DIR__ . '/includes/school_roles.php';
ensure_online_exams_schema();

if (!empty($_SESSION['admin_id'])) require_permission('manage_reports');
elseif (empty($_SESSION['teacher_id'])) { echo "غیرمجاز"; exit; }

$examId = (int)($_GET['exam_id'] ?? 0);
echo "<h2>🔍 دیباگ مانیتورینگ زنده - Exam ID: $examId</h2><pre style='background:#f5f5f5;padding:15px;'>";

echo "Server time: ".date('Y-m-d H:i:s')."\n";
echo "Exam ID: $examId\n\n";

echo "=== online_exam_attempts in_progress ===\n";
try {
    $atts = DB::fetchAll("SELECT a.*, s.first_name, s.last_name, s.class_name FROM online_exam_attempts a JOIN students s ON s.id=a.student_id WHERE a.exam_id=? AND a.status='in_progress' ORDER BY a.id DESC", [$examId]);
    echo "Count: ".count($atts)."\n";
    foreach ($atts as $a) {
        echo "- Attempt #{$a['id']} Student: {$a['first_name']} {$a['last_name']} Class: {$a['class_name']} Start: {$a['start_time']} IP: {$a['ip_address']} Score: {$a['score']}\n";
    }
    if (empty($atts)) echo "هیچ attempt در حال برگزاری نیست - دانش‌آموز باید آزمون را شروع کرده باشد (دکمه شروع بعد دسترسی‌ها)\n";
} catch (Exception $e) { echo "Error: ".$e->getMessage()."\n"; }

echo "\n=== online_exam_live_sessions ===\n";
try {
    $lives = DB::fetchAll("SELECT ls.*, s.first_name, s.last_name FROM online_exam_live_sessions ls JOIN students s ON s.id=ls.student_id WHERE ls.exam_id=? ORDER BY ls.last_heartbeat DESC", [$examId]);
    echo "Count: ".count($lives)."\n";
    foreach ($lives as $ls) {
        echo "- Live Attempt #{$ls['attempt_id']} Student: {$ls['first_name']} {$ls['last_name']} Last Heartbeat: {$ls['last_heartbeat']} IP: {$ls['ip_address']} Lat: {$ls['lat']} Lng: {$ls['lng']} Status: {$ls['status']}\n";
    }
    if (empty($lives)) echo "هیچ live session نیست - heartbeat از طرف دانش‌آموز ارسال نشده. دلایل:\n- دانش‌آموز هنوز وارد مرحله آزمون نشده (در مرحله بررسی دسترسی‌ها مانده)\n- JS heartbeat بلاک شده (AdBlock?)\n- خطای JS در کنسول مرورگر دانش‌آموز\n- سرور time zone مشکل\n";
} catch (Exception $e) { echo "Error: ".$e->getMessage()."\n"; }

echo "\n=== Recent heartbeats (last 10 min) ===\n";
try {
    $recent = DB::fetchAll("SELECT * FROM online_exam_live_sessions WHERE exam_id=? AND last_heartbeat > DATE_SUB(NOW(), INTERVAL 10 MINUTE) ORDER BY last_heartbeat DESC", [$examId]);
    echo "Count last 10 min: ".count($recent)."\n";
} catch (Exception $e) { echo "Error: ".$e->getMessage()."\n"; }

echo "\n=== Tables existence ===\n";
$tables = ['online_exam_attempts','online_exam_live_sessions','online_exams','students'];
foreach ($tables as $t) {
    try {
        $c = DB::fetch("SELECT COUNT(*) c FROM $t");
        echo "✅ $t: {$c['c']} rows\n";
    } catch (Exception $e) { echo "❌ $t: ".$e->getMessage()."\n"; }
}

echo "\n=== Recommendations ===\n";
echo "1. اگر attempts هست ولی live_sessions خالی است: heartbeat ارسال نشده - دانش‌آموز را بگید کنسول مرورگر (F12) را چک کند\n";
echo "2. اگر live_sessions هست ولی monitor نشان نمی‌دهد: مشکل JS یا API - کنسول مرورگر معلم را چک کن (F12 -> Console)\n";
echo "3. مطمئن شوید دانش‌آموز بعد از بررسی دسترسی‌ها دکمه 'شروع آزمون' را زده و وارد مرحله سوالات شده\n";
echo "4. در این صفحه دیباگ، اگر attempt هست می‌توانید دستی Live Session بسازید: ?create_live=1&attempt_id=X\n";

if (isset($_GET['create_live']) && isset($_GET['attempt_id'])) {
    $aid = (int)$_GET['attempt_id'];
    $att = DB::fetch("SELECT * FROM online_exam_attempts WHERE id=? AND exam_id=?", [$aid, $examId]);
    if ($att) {
        try {
            $now = date('Y-m-d H:i:s');
            $exists = DB::fetch("SELECT id FROM online_exam_live_sessions WHERE attempt_id=?", [$aid]);
            if ($exists) {
                DB::execute("UPDATE online_exam_live_sessions SET last_heartbeat=?, status='active' WHERE attempt_id=?", [$now, $aid]);
                echo "Live session updated for attempt $aid\n";
            } else {
                DB::execute("INSERT INTO online_exam_live_sessions (attempt_id, student_id, exam_id, last_heartbeat, ip_address, status) VALUES (?,?,?,?,?, 'active')", [$aid, $att['student_id'], $examId, $now, $_SERVER['REMOTE_ADDR']]);
                echo "Live session created for attempt $aid\n";
            }
        } catch (Exception $e) { echo "Error creating live: ".$e->getMessage()."\n"; }
    }
}

echo "</pre>";
echo "<p><a href='online-exam-monitor.php?exam_id=$examId'>بازگشت به مانیتورینگ</a></p>";
?>
