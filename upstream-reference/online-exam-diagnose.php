<?php
// File: online-exam-diagnose.php - Diagnose online exam system
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/online_exam_helpers.php';
require_once __DIR__ . '/includes/school_roles.php';

echo "<h2>🔍 بررسی سلامت سیستم آزمون آنلاین v4.28.1</h2><pre style='background:#f5f5f5;padding:15px;border-radius:8px;'>";

echo "PHP Version: ".phpversion()."\n";
echo "HTTPS: ".(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off' ? 'Yes' : 'No - Geolocation requires HTTPS!')."\n";
echo "Session: admin=".($_SESSION['admin_id']??'no')." teacher=".($_SESSION['teacher_id']??'no')." student=".($_SESSION['student_id']??'no')."\n\n";

echo "Checking tables...\n";
try {
    ensure_school_roles_schema();
    echo "✅ school_roles schema ok\n";
} catch (Exception $e) { echo "❌ school_roles: ".$e->getMessage()."\n"; }

try {
    ensure_online_exams_schema();
    echo "✅ online_exams schema ok\n";
} catch (Exception $e) { echo "❌ online_exams: ".$e->getMessage()."\n"; }

$tables = ['online_exam_categories','online_question_categories','online_exams','online_question_bank','online_questions','online_exam_attempts','online_exam_answers','online_exam_proctoring_logs','online_exam_live_sessions','online_exam_webcam_requests','online_exam_webcam_snapshots'];
foreach ($tables as $t) {
    try {
        $c = DB::fetch("SELECT COUNT(*) c FROM $t");
        echo "✅ $t: ".$c['c']." rows\n";
    } catch (Exception $e) {
        echo "❌ $t: ".$e->getMessage()."\n";
    }
}

echo "\nChecking folders...\n";
$dirs = ['uploads/online-exams','uploads/online-exams/questions','uploads/online-exams/answers','uploads/online-exams/webcam/temp','uploads/online-exams/webcam/saved','uploads/online-exams/media'];
foreach ($dirs as $d) {
    $full = __DIR__.'/'.$d;
    echo (is_dir($full) ? "✅" : "❌")." $d ".(is_dir($full) ? (is_writable($full)?'writable':'NOT writable') : 'MISSING')."\n";
    if (!is_dir($full)) { @mkdir($full,0755,true); echo "  -> Created\n"; }
}

echo "\nChecking files...\n";
$files = ['online-exams.php','online-exam-form.php','online-exam-questions.php','online-exam-take.php','online-exam-api.php','online-exam-monitor.php','student-online-exams.php','includes/header.php','includes/online_exam_helpers.php'];
foreach ($files as $f) {
    $full = __DIR__.'/'.$f;
    echo (file_exists($full) ? "✅" : "❌")." $f\n";
}

echo "\nChecking PHP extensions...\n";
echo "PDO: ".(extension_loaded('pdo')?'yes':'NO')."\n";
echo "PDO MySQL: ".(extension_loaded('pdo_mysql')?'yes':'NO')."\n";
echo "GD: ".(extension_loaded('gd')?'yes':'NO')."\n";
echo "Fileinfo: ".(extension_loaded('fileinfo')?'yes':'NO')."\n";

echo "\n\n--- Recommendations ---\n";
if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS']=='off') {
    echo "⚠️ برای کارکرد موقعیت مکانی GPS، سایت باید https باشد. الان http است!\n";
}
echo "If online-exams.php gives 500, check error_log in hosting cPanel.\n";
echo "Common fix: Ensure PHP version is 7.4+ (prefer 8.0+), and ensure includes/header.php is updated.\n";

echo "</pre>";
echo "<p><a href='online-exams.php'>رفتن به مدیریت آزمون‌ها</a> | <a href='student-online-exams.php'>آزمون‌های دانش‌آموز</a></p>";
?>
