<?php
// File: includes/header.php - v4.28.13 - Online Exams + Unified Academic Year
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/academic_year_helpers.php';
// v4.68.0: ردیابی نشست‌های فعال + خروج اجباری نشست‌های بسته‌شده
require_once __DIR__ . '/session_tracker.php';
try { track_user_session(); } catch (Throwable $e) { error_log('session tracker: ' . $e->getMessage()); }
// v4.33.0: never let the browser cache panel pages — prevents the Back button
// from showing a stale page (e.g. old menus after logout/role switch).
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}
try { ensure_academic_years_unified_schema(); } catch (Exception $e) {}
$schoolName = get_setting('school_name', 'سیستم مدیریت کارنامه دانش‌آموزی');
$themeColor = get_setting('theme_color', '#2563eb');
$accentColor= get_setting('accent_color', '#d97706');
$themeMode  = get_setting('theme_mode', 'light');
$fontFamily = get_setting('font_family', 'Vazirmatn');
$bSize      = get_setting('body_font_size', '14px');
$bColor     = get_setting('body_text_color', '');
$hFont      = get_setting('heading_font_family', 'Vazirmatn');
$hSize      = get_setting('heading_font_size', '22px');
$hWeight    = get_setting('heading_font_weight', '700');
$hColor     = get_setting('heading_text_color', '');
$tSize      = get_setting('table_font_size', '13px');
$tBg        = get_setting('table_header_bg', '');
$customFont = get_setting('custom_font_url', '');
$logoUrl    = get_setting('logo_url', '');
$isEmbedded = isset($_GET['embedded']) && $_GET['embedded'] === '1';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo clean($schoolName); ?> - پنل مدیریت و کارنامه</title>
    <script>try{localStorage.setItem('theme','light');document.documentElement.classList.remove('dark');}catch(e){}</script>
    <meta name="theme-color" content="<?php echo clean($themeColor); ?>"><!-- v4.30.0 -->
    <link rel="preload" href="uploads/Vazirmatn/Vazirmatn-Regular.woff2" as="font" type="font/woff2" crossorigin><!-- v4.30.0: faster first paint -->
    <link rel="preload" href="uploads/Vazirmatn/Vazirmatn-Bold.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/ui-modern.css"><!-- v4.30.0: high-end UI layer -->
    <style>
        @font-face { font-family: 'Vazirmatn'; src: url('uploads/Vazirmatn/Vazirmatn-Regular.woff2') format('woff2'), url('uploads/Vazirmatn/Vazirmatn-Regular.ttf') format('truetype'); font-display: swap; }
        @font-face { font-family: 'Vazirmatn'; src: url('uploads/Vazirmatn/Vazirmatn-Bold.woff2') format('woff2'), url('uploads/Vazirmatn/Vazirmatn-Bold.ttf') format('truetype'); font-weight: 700; font-display: swap; }
        @font-face { font-family: 'Sahel'; src: url('uploads/Sahel/Sahel.woff2') format('woff2'), url('uploads/Sahel/Sahel.ttf') format('truetype'); font-display: swap; }
        @font-face { font-family: 'Yekan'; src: url('uploads/Yekan/Yekan.woff2') format('woff2'), url('uploads/Yekan/Yekan.ttf') format('truetype'); font-display: swap; }
        <?php if ($fontFamily === 'CustomUploadedFont' && !empty($customFont)): ?>
        @font-face { font-family: 'CustomUploadedFont'; src: url('<?php echo clean($customFont); ?>'); font-display: swap; }
        <?php endif; ?>
        :root {
            --primary: <?php echo clean($themeColor); ?> !important;
            --admin-gold: <?php echo clean($accentColor); ?> !important;
            font-family: '<?php echo clean($fontFamily); ?>', Tahoma, sans-serif !important;
            font-size: <?php echo clean($bSize); ?>;
        }
        body { font-family: '<?php echo clean($fontFamily); ?>', Tahoma, sans-serif !important; <?php if ($bColor): ?>color: <?php echo clean($bColor); ?>;<?php endif; ?> }
        h1, h2, h3, h4, h5, h6 { font-family: '<?php echo clean($hFont === 'CustomUploadedFont' ? $fontFamily : $hFont); ?>', Tahoma, sans-serif !important; font-weight: <?php echo clean($hWeight); ?> !important; <?php if ($hColor): ?>color: <?php echo clean($hColor); ?> !important;<?php endif; ?> }
        h1, h2 { font-size: <?php echo clean($hSize); ?>; }
        table { font-size: <?php echo clean($tSize); ?> !important; }
        <?php if ($tBg): ?>th { background-color: <?php echo clean($tBg); ?> !important; }<?php endif; ?>
        <?php if ($isEmbedded): ?>
        .navbar, .sidebar, footer { display: none !important; }
        main.flex-1 { width: 100vw !important; height: 100vh !important; padding: .75rem !important; overflow: auto !important; }
        body > .flex { height: 100vh !important; }
        <?php endif; ?>
    </style>
</head>
<body class="bg-body text-main min-h-screen flex flex-col font-sans">
<script>
/* v4.66.0: یک دکمه، دو رفتار — موبایل: کشوی بازشو (sidebar-open)؛
   دسکتاپ/تبلت: جمع‌کردن سایدبار (sidebar-collapsed) با حافظه در localStorage.
   این اسکریپت بلافاصله بعد از body است تا حالت ذخیره‌شده قبل از رندر سایدبار اعمال شود. */
function mtagToggleSidebar(){
    if (window.innerWidth > 900) {
        document.body.classList.toggle('sidebar-collapsed');
        try { localStorage.setItem('mtag_sidebar_collapsed', document.body.classList.contains('sidebar-collapsed') ? '1' : '0'); } catch (e) {}
    } else {
        document.body.classList.toggle('sidebar-open');
    }
}
(function(){ try { if (window.innerWidth > 900 && localStorage.getItem('mtag_sidebar_collapsed') === '1') document.body.classList.add('sidebar-collapsed'); } catch (e) {} })();
</script>

<?php if (is_admin_logged_in() || is_student_logged_in() || (function_exists('is_teacher_logged_in') && is_teacher_logged_in())): ?>
<header class="navbar flex items-center justify-between px-6 py-4 border-b border-color bg-card shadow-sm">
    <?php /* v4.67.0: دکمه ☰ + لوگو + نام مدرسه + زیرنویس در «یک گروه» ابتدای هدر —
          قبلاً ☰ فرزند جدا بود و justify-between برند را وسط هدر می‌انداخت. */ ?>
    <div class="flex items-center gap-3">
        <button type="button" class="hamburger-btn" id="sidebarToggleBtn" title="باز و بسته کردن منوی کناری" onclick="mtagToggleSidebar()">☰</button>
        <?php if (!empty($logoUrl)): ?><img src="<?php echo clean($logoUrl); ?>" alt="Logo" class="h-10 w-10 object-contain rounded"><?php endif; ?>
        <div><h1 class="text-lg font-bold"><?php echo clean($schoolName); ?></h1><span class="text-xs text-muted"><?php echo is_admin_logged_in() ? 'پنل مدیریت سیستم' : (is_student_logged_in() ? 'پنل دانش‌آموزی' : 'پنل دبیران'); ?></span></div>
    </div>
    <div class="flex items-center gap-4">
        <span class="text-sm font-medium">مورخ: <?php echo jdate('l j F Y'); ?></span>
        <div class="user-menu-wrap">
            <button type="button" class="btn btn-secondary user-menu-btn" onclick="document.body.classList.toggle('user-menu-open')">👤 کاربری</button>
            <div class="user-menu-dropdown">
                <?php /* v4.60.0: bg-green-600 / bg-amber-600 don't exist in this project's CSS,
                      which left white text on a white badge. Explicit inline colors. */ ?>
                <?php /* v4.68.0: کلیک روی نام کاربر → صفحه نشست‌های فعال همه دستگاه‌ها */ ?>
                <?php if (is_admin_logged_in()): ?><a href="my-sessions.php" class="badge w-full justify-center text-xs" style="background:var(--primary,#2563eb);color:#fff;text-decoration:none;cursor:pointer" title="مشاهده نشست‌های فعال در همه دستگاه‌ها"><?php echo clean($_SESSION['admin_name'] ?? 'مدیر'); ?></a><a href="index.php?action=logout" class="btn btn-danger w-full text-xs">خروج</a>
                <?php elseif (is_student_logged_in()): ?><a href="my-sessions.php" class="badge w-full justify-center text-xs" style="background:#059669;color:#fff;text-decoration:none;cursor:pointer" title="مشاهده نشست‌های فعال در همه دستگاه‌ها"><?php echo clean($_SESSION['student_name'] ?? 'دانش‌آموز'); ?></a><a href="index.php?action=logout" class="btn btn-danger w-full text-xs">خروج</a>
                <?php elseif (function_exists('is_teacher_logged_in') && is_teacher_logged_in()): ?><a href="my-sessions.php" class="badge w-full justify-center text-xs" style="background:#d97706;color:#fff;text-decoration:none;cursor:pointer" title="مشاهده نشست‌های فعال در همه دستگاه‌ها"><?php echo clean(function_exists('teacher_family_name') ? teacher_family_name($_SESSION['teacher_name'] ?? 'دبیر') : ($_SESSION['teacher_name'] ?? 'دبیر')); ?></a><a href="logout.php" class="btn btn-danger w-full text-xs">خروج</a><?php endif; ?>
            </div>
        </div>
    </div>
</header>

<div class="flex flex-1">
    <?php if (is_admin_logged_in()): ?>
    <aside class="sidebar w-64 bg-card border-l border-color p-4 flex flex-col gap-1.5 shrink-0 shadow-lg">
        <div class="sidebar-scroll">
        <div class="sidebar-section-title">نمای کلی</div>
        <a href="index.php?view=dashboard" class="sidebar-item <?php echo (!isset($_GET['view']) || $_GET['view'] == 'dashboard') && basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">داشبورد مدیریت</a>
        <?php if (has_permission('manage_students')): ?>
            <div class="sidebar-section-title">پرونده دانش‌آموزی</div>
            <a href="students.php" class="sidebar-item <?php echo in_array(basename($_SERVER['PHP_SELF']), ['students.php','deputy-panel.php']) ? 'active' : ''; ?>">مدیریت دانش‌آموزان</a>
            <?php /* v4.90.0: «موارد انضباطی» از منو حذف شد — دسترسی مدیر از دکمه داخل «مدیریت دانش‌آموزان» */ ?>
            <a href="attendance.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'attendance.php' ? 'active' : ''; ?>">حضور و غیاب</a>
        <?php endif; ?>
        <?php if (has_permission('manage_classes')): ?>
            <div class="sidebar-section-title">آموزش، کلاس و دبیران</div>
            <a href="courses-management.php" class="sidebar-item <?php echo in_array(basename($_SERVER['PHP_SELF']), ['courses-management.php','classes.php','import-teachers.php','import-schedule.php']) ? 'active' : ''; ?>">مدیریت دروس</a>
        <?php endif; ?>
        <?php if (has_permission('manage_reports')): ?>
            <div class="sidebar-section-title">ارزشیابی و امتحانات</div>
            <a href="reports-management.php" class="sidebar-item <?php echo in_array(basename($_SERVER['PHP_SELF']), ['reports-management.php','reports.php','import.php','analytics.php']) ? 'active' : ''; ?>">مدیریت کارنامه‌ها</a>
            <a href="exams.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'exams.php' ? 'active' : ''; ?>">امتحانات حضوری</a>
            <!-- Virtual exams moved under evaluation, below presencial -->
            <a href="online-exams.php" class="sidebar-item <?php echo in_array(basename($_SERVER['PHP_SELF']), ['online-exams.php','online-exam-form.php','online-exam-questions.php','online-exam-monitor.php','online-exam-results.php','online-exam-result.php','online-exam-grading.php','online-exam-categories.php','online-question-bank.php']) ? 'active' : ''; ?>">آزمون‌های مجازی (آنلاین)</a>
        <?php endif; ?>
        <?php if (has_permission('import_data')): ?>
            <div class="sidebar-section-title">ورود و خروج داده</div>
            <a href="student-recovery.php" class="sidebar-item <?php echo in_array(basename($_SERVER['PHP_SELF']), ['student-recovery.php','import-students.php','import-photos.php']) ? 'active' : ''; ?>">بازیابی دانش‌آموز</a>
        <?php endif; ?>
        <?php if (has_permission('send_sms')): ?>
            <div class="sidebar-section-title">ارتباطات و ربات‌ها</div>
            <a href="messages-management.php" class="sidebar-item <?php echo in_array(basename($_SERVER['PHP_SELF']), ['messages-management.php','notifications.php','sms-panel.php']) ? 'active' : ''; ?>">پیامک و اعلان‌ها</a>
            <a href="bale-bot.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'bale-bot.php' ? 'active' : ''; ?>">بازوی بله</a>
            <a href="telegram-bot.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'telegram-bot.php' ? 'active' : ''; ?>">ربات تلگرام</a>
            <a href="bot-accounts.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'bot-accounts.php' ? 'active' : ''; ?>">اکانت‌های متصل</a>
        <?php endif; ?>
        <div class="sidebar-section-title">سیستم</div>
        <a href="other-settings.php" class="sidebar-item <?php echo in_array(basename($_SERVER['PHP_SELF']), ['other-settings.php','backups.php','activity-logs.php','migration-updater.php']) ? 'active' : ''; ?>">تنظیمات دیگر</a>
        <a href="desk-sync.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'desk-sync.php' ? 'active' : ''; ?>">همگام‌سازی با سایت</a>
        <?php if (has_permission('system_settings')): ?><a href="db-optimizer.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'db-optimizer.php' ? 'active' : ''; ?>">سلامت پایگاه داده</a><?php endif; ?>
        <?php if (has_permission('system_settings')): ?><a href="settings.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>">سفارشی‌سازی</a><?php endif; ?>
        <?php if (($_SESSION['admin_role'] ?? '') === 'super_admin'): ?><a href="admins.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'admins.php' ? 'active' : ''; ?>">مدیریت مدیران</a><?php endif; ?>
        </div>
    </aside>
    <?php elseif (is_student_logged_in()): ?>
    <aside class="sidebar w-64 bg-card border-l border-color p-4 flex flex-col gap-2 shrink-0 shadow-lg">
        <a href="student-panel.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'student-panel.php' ? 'active' : ''; ?>">داشبورد و کارنامه‌های من</a>
        <div class="sidebar-section-title">ارزشیابی و امتحانات</div>
        <a href="student-online-exams.php" class="sidebar-item <?php echo in_array(basename($_SERVER['PHP_SELF']), ['student-online-exams.php','online-exam-take.php','online-exam-result.php']) ? 'active' : ''; ?>">آزمون‌های مجازی من</a>
    </aside>
    <?php elseif (function_exists('is_teacher_logged_in') && is_teacher_logged_in()): ?>
    <aside class="sidebar w-64 bg-card border-l border-color p-4 flex flex-col gap-2 shrink-0 shadow-lg">
        <a href="teacher-panel.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'teacher-panel.php' ? 'active' : ''; ?>">پنل دبیر</a>
        <div class="sidebar-section-title">ارزشیابی و امتحانات</div>
        <a href="teacher-panel.php?tab=exams" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'teacher-panel.php' && ($_GET['tab']??'')=='exams' ? 'active' : ''; ?>">امتحانات حضوری / طراحی تشریحی</a>
        <a href="online-exams.php" class="sidebar-item <?php echo in_array(basename($_SERVER['PHP_SELF']), ['online-exams.php','online-exam-form.php','online-exam-questions.php','online-exam-monitor.php','online-exam-results.php','online-exam-grading.php','online-question-bank.php']) ? 'active' : ''; ?>">آزمون‌های مجازی (آنلاین)</a>
        <?php require_once __DIR__ . '/school_roles.php'; ensure_school_roles_schema(); if (teacher_has_deputy($_SESSION['teacher_id'])): ?><a href="deputy-panel.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'deputy-panel.php' ? 'active' : ''; ?>">پنل معاونت</a><a href="attendance.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'attendance.php' ? 'active' : ''; ?>">حضور و غیاب</a><?php endif; if (teacher_has_counselor($_SESSION['teacher_id'])): ?><a href="counselor-panel.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'counselor-panel.php' ? 'active' : ''; ?>">پنل مشاور</a><?php endif; if (teacher_has_executive($_SESSION['teacher_id'])): ?><a href="executive-panel.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'executive-panel.php' ? 'active' : ''; ?>">معاون اجرایی</a><?php endif; ?>
    </aside>
    <?php endif; ?>

    <main class="flex-1 p-6 overflow-y-auto">
        <?php
        $flash = get_flash_message();
        if ($flash):
            $type = $flash['type'];
            $message = $flash['message'];
            // Define colors with inline styles to ensure visibility even without Tailwind
            if ($type === 'success') {
                $bg = '#dcfce7'; $border = '#86efac'; $textColor = '#14532d'; $icon = '✅'; $title = 'عملیات موفق';
            } elseif ($type === 'error') {
                $bg = '#fee2e2'; $border = '#fca5a5'; $textColor = '#7f1d1d'; $icon = '❌'; $title = 'خطا / عدم موفقیت';
            } elseif ($type === 'warning') {
                $bg = '#fef9c3'; $border = '#fde047'; $textColor = '#713f12'; $icon = '⚠️'; $title = 'هشدار';
            } else { // info
                $bg = '#dbeafe'; $border = '#93c5fd'; $textColor = '#1e3a8a'; $icon = 'ℹ️'; $title = 'پیام سیستم';
            }
        ?>
        <div class="flash-message" style="margin-bottom:16px;padding:14px 18px;border-radius:12px;border:2px solid <?php echo $border; ?>;background:<?php echo $bg; ?>;color:<?php echo $textColor; ?>;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;box-shadow:0 4px 12px rgba(0,0,0,0.08);font-size:13px;line-height:1.8;white-space:pre-line;">
            <div style="display:flex;gap:10px;align-items:flex-start;flex:1;">
                <span style="font-size:20px;flex-shrink:0;"><?php echo $icon; ?></span>
                <div style="flex:1;">
                    <div style="font-weight:bold;font-size:13px;margin-bottom:2px;"><?php echo $title; ?></div>
                    <div><?php echo nl2br(clean($message)); ?></div>
                </div>
            </div>
            <button onclick="this.parentElement.remove();" style="flex-shrink:0;background:rgba(0,0,0,0.1);border:0;border-radius:6px;padding:2px 8px;font-weight:bold;cursor:pointer;color:<?php echo $textColor; ?>;">×</button>
        </div>
        <script>
            // Auto hide flash after 8 seconds for success/info, keep error/warning longer
            (function(){
                let type = <?php echo json_encode($type); ?>;
                let duration = (type==='success' || type==='info') ? 8000 : 15000;
                setTimeout(()=>{
                    let el = document.querySelector('.flash-message');
                    if(el) { el.style.transition='opacity 0.5s'; el.style.opacity='0'; setTimeout(()=>el.remove(),500); }
                }, duration);
            })();
        </script>
        <?php endif; ?>
<?php else: ?>
    <main class="flex-1 flex items-center justify-center p-6">
<?php endif; ?>
