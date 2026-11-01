<?php
/**
 * Student Dashboard (student-panel.php) — v4.210.0
 *
 * این صفحه از «همه‌چیز در یک صفحه» به داشبورد تبدیل شد:
 *   · سربرگ دانش‌آموز + منوی کاشی (برنامه هفتگی، برنامه امتحانی، موارد
 *     انضباطی، کارنامه‌ها، آزمون مجازی) — همان الگوی پنل دبیر
 *   · اطلاعیه‌های مدرسه (تنها بخشی که صفحهٔ جدا ندارد)
 * بقیهٔ اطلاعات به صفحه‌های مستقل خودشان منتقل شده‌اند.
 *
 * دکمهٔ «حذف دانش‌آموز از حساب» حذف شد؛ خروج از حساب همچنان از دکمهٔ
 * «خروج» در نوار بالای صفحه انجام می‌شود.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/school_roles.php';
require_student();
ensure_school_roles_schema();

// Handle student logout via POST (kept for older handbooks/links)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['student_logout'])) {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        clear_remember_login();
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
        }
        session_destroy();
        redirect('index.php?view=login');
    }
}

$studentId = $_SESSION['student_id'];
$student = DB::fetch("SELECT * FROM students WHERE id = ?", [$studentId]);

if (!$student) {
    session_destroy();
    redirect('index.php?view=login');
}

/* v4.210.0 — ورود از مسیر «استعلام کارنامه» با ?year= به همین صفحه می‌آمد
   و کارنامه‌ها را همین‌جا می‌دید. چون کارنامه‌ها به صفحهٔ مستقل منتقل شده،
   همان مسیر مستقیم به صفحهٔ کارنامه‌ها می‌رود تا رفتار قبلی حفظ شود. */
if (isset($_GET['year']) && trim((string)$_GET['year']) !== '' && is_file(__DIR__ . '/student-reports.php')) {
    redirect('student-reports.php?year=' . urlencode(trim((string)$_GET['year'])));
}

require_once __DIR__ . '/includes/exams_helper.php';
require_once __DIR__ . '/includes/online_exam_helpers.php';
ensure_online_exams_schema();

if (is_file(__DIR__ . '/includes/student_nav.php')) require_once __DIR__ . '/includes/student_nav.php';

// School announcements only — every other section now lives on its own page.
$notifs = DB::fetchAll(
    "SELECT * FROM notifications WHERE target_type = 'all' OR (target_type = 'class' AND target_value = ?) ORDER BY id DESC LIMIT 5",
    [$student['class_name']]
);

$tileCounts = function_exists('student_nav_counts') ? student_nav_counts($student) : [];

require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-6">
    <?php if (function_exists('render_student_nav')) render_student_nav('dashboard', $student, $tileCounts); ?>

    <!-- Notifications Section -->
    <?php if (!empty($notifs)): ?>
    <div class="card border-blue-200 bg-blue-50 space-y-3">
        <h3 class="font-bold text-blue-900 flex items-center gap-1">
            <span>💬 اطلاعیه‌های مدرسه:</span>
        </h3>
        <?php foreach ($notifs as $nt): ?>
        <div class="p-3 bg-white rounded border border-blue-100 text-sm">
            <h4 class="font-bold text-blue-800"><?php echo clean($nt['title']); ?></h4>
            <p class="text-xs text-gray-600 mt-1"><?php echo nl2br(clean($nt['message'])); ?></p>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <h3 class="font-bold mb-2">راهنمای سریع</h3>
        <p class="text-xs text-muted leading-relaxed">
            برای دیدن هر بخش، روی کاشی آن در بالای صفحه بزنید:
            «برنامه هفتگی» جدول زنگ‌های کلاس شما، «برنامه امتحانی» زمان و محل آزمون‌های حضوری،
            «موارد انضباطی» موارد ثبت‌شده، «کارنامه‌ها» نمرات و دریافت فایل کارنامه،
            و «آزمون مجازی» آزمون‌های آنلاین است.
        </p>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
