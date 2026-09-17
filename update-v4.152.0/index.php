<?php
/**
 * Main Front Controller & Router / Default Student Login & Quick Inquiry
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/header_tiles.php';

// Check installer lock
if (!file_exists(__DIR__ . '/config/installed.lock') && !isset($_GET['ignore_install'])) {
    redirect('installer.php');
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$view   = $_GET['view'] ?? 'dashboard';

// Handle Logout
if ($action === 'logout') {
    if (is_admin_logged_in()) {
        log_activity($_SESSION['admin_id'] ?? null, 'خروج از سیستم', 'مدیر از سیستم خارج شد.');
    }
    clear_remember_login();
    // v4.33.0: full teardown — wipe all role keys, delete the session cookie
    // and destroy the server-side session so nothing survives logout.
    auth_clear_all_roles();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $cp = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $cp['path'], $cp['domain'], $cp['secure'], $cp['httponly']);
    }
    session_destroy();
    redirect('index.php?view=login');
}

// Check Throttle
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !check_login_throttle($ip)) {
    set_flash_message('error', 'تعداد تلاش‌های ناموفق شما بیش از حد مجاز است. لطفاً ۱۰ دقیقه دیگر مجدداً تلاش فرمایید.');
    redirect('index.php');
}

// Handle Admin Login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_type']) && $_POST['login_type'] === 'admin') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'خطای امنیتی CSRF رخ داد.');
        redirect('index.php?view=login&tab=admin');
    }
    /* v4.132.0: کد امنیتی فقط بعد از اولین ورود ناموفقِ همین حساب.
       نام کاربری را قبل از دروازه لازم داریم، پس ترتیب عوض شد. */
    $username = clean($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    require_once __DIR__ . '/includes/header_tiles.php';
    if (!login_captcha_gate('admin', $username, $_POST['captcha'] ?? '')) {
        set_flash_message('error', 'پاسخ سوال امنیتی (کد امنیتی) نادرست است.');
        redirect('index.php?view=login&tab=admin');
    }

    $admin = DB::fetch("SELECT * FROM admins WHERE username = ? AND status = 1", [$username]);
    if ($admin && verify_user_password($password, $admin['password'], ['table'=>'admins','id'=>$admin['id']])) {
        login_guard_success('admin', $username);
        // v4.33.0: exclusive role session — wipes any other role + regenerates session id
        auth_login_as('admin', [
            'admin_id'       => $admin['id'],
            'admin_username' => $admin['username'],
            'admin_name'     => $admin['name'],
            'admin_role'     => $admin['role'],
        ]);
        set_remember_login('admin', $admin['id']);
        log_activity($admin['id'], 'ورود به سیستم', "مدیر {$admin['name']} وارد سیستم شد.");
        set_flash_message('success', "خوش آمدید، {$admin['name']}");
        redirect('index.php?view=dashboard');
    } else {
        login_guard_fail('admin', $username);
        record_failed_login();
        set_flash_message('error', 'نام کاربری یا کلمه عبور اشتباه است یا حساب کاربری غیرفعال است.');
        redirect('index.php?view=login&tab=admin');
    }
}

// Handle Student Login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_type']) && $_POST['login_type'] === 'student') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'خطای امنیتی CSRF رخ داد.');
        redirect('index.php?view=login&tab=student');
    }
    $nationalId = tr_num(clean($_POST['national_id'] ?? ''), 'en');
    $password   = tr_num($_POST['password'] ?? '', 'en'); // 6 digit serial

    require_once __DIR__ . '/includes/header_tiles.php';
    if (!login_captcha_gate('student', $nationalId, $_POST['captcha'] ?? '')) {
        set_flash_message('error', 'پاسخ سوال امنیتی نادرست است.');
        redirect('index.php?view=login&tab=student');
    }

    // Academic year coherence: prefer current default year if student exists in multiple years
    $currentYear = get_setting('current_academic_year','1404/1405');
    $currentYearNorm = str_replace('-','/',$currentYear);
    $student = null;
    // Try exact current year first
    try {
        $student = DB::fetch("SELECT * FROM students WHERE national_id = ? AND (academic_year=? OR academic_year=?) AND status='active' ORDER BY id DESC LIMIT 1", [$nationalId, $currentYear, $currentYearNorm]);
    } catch (Exception $e) {}
    if (!$student) {
        $student = DB::fetch("SELECT * FROM students WHERE national_id = ? AND status = 'active' ORDER BY academic_year DESC, id DESC LIMIT 1", [$nationalId]);
    }
    if ($student) {
        $valid = false;
        if (!empty($student['password'])) {
            if (verify_user_password($password, $student['password'], ['table'=>'students','id'=>$student['id']])) {
                $valid = true;
            }
        } elseif ($password === $student['serial_number'] || $password === $student['national_id']) {
            $valid = true;
        }

        if ($valid) {
            login_guard_success('student', $nationalId);
            // v4.33.0: exclusive role session
            auth_login_as('student', [
                'student_id'   => $student['id'],
                'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                'student_nid'  => $student['national_id'],
            ]);
            set_remember_login('student', $student['id']);
            set_flash_message('success', "خوش آمدید، {$_SESSION['student_name']}");
            redirect('student-panel.php');
        }
    }
    login_guard_fail('student', $nationalId);
    record_failed_login();
    set_flash_message('error', 'کد ملی یا سریال ۶ رقمی شناسنامه نادرست است.');
    redirect('index.php?view=login&tab=student');
}

// Handle Quick Inquiry POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_type']) && $_POST['login_type'] === 'inquiry') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'خطای امنیتی CSRF');
        redirect('index.php?view=login&tab=inquiry');
    }
    $year       = trim($_POST['academic_year'] ?? '1404/1405');
    $nationalId = tr_num(clean($_POST['national_id'] ?? ''), 'en');
    $serial     = tr_num($_POST['serial_number'] ?? '', 'en');

    require_once __DIR__ . '/includes/header_tiles.php';
    if (!login_captcha_gate('inquiry', $nationalId, $_POST['captcha'] ?? '')) {
        set_flash_message('error', 'پاسخ سوال امنیتی نادرست است.');
        redirect('index.php?view=login&tab=inquiry');
    }

    // Prefer requested year, then current default
    $student = null;
    try {
        $student = DB::fetch("SELECT * FROM students WHERE national_id = ? AND (academic_year=? OR academic_year=?) AND status='active' ORDER BY id DESC LIMIT 1", [$nationalId, $year, str_replace('-','/',$year)]);
    } catch (Exception $e) {}
    if (!$student) {
        $student = DB::fetch("SELECT * FROM students WHERE national_id = ? AND status = 'active' ORDER BY academic_year DESC LIMIT 1", [$nationalId]);
    }
    if ($student && (($student['serial_number'] !== '' && $student['serial_number'] !== null && hash_equals((string)$student['serial_number'], (string)$serial))
                     || verify_user_password($serial, $student['password'], ['table'=>'students','id'=>$student['id']]))) {
        login_guard_success('inquiry', $nationalId);
        // v4.33.0: exclusive role session
        auth_login_as('student', [
            'student_id'   => $student['id'],
            'student_name' => $student['first_name'] . ' ' . $student['last_name'],
            'student_nid'  => $student['national_id'],
        ]);
        set_remember_login('student', $student['id']);
        set_flash_message('success', "استعلام موفق کارنامه‌های سال تحصیلی $year");
        redirect("student-panel.php?year=" . urlencode($year));
    }
    login_guard_fail('inquiry', $nationalId);
    record_failed_login();
    set_flash_message('error', 'اطلاعات استعلام (کد ملی یا سریال ۶ رقمی) با سیستم مطابقت ندارد.');
    redirect('index.php?view=login&tab=inquiry');
}

// Handle Teacher Login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_type']) && $_POST['login_type'] === 'teacher') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'خطای امنیتی CSRF');
        redirect('index.php?view=login&tab=teacher');
    }
    $nid  = tr_num(clean($_POST['national_id'] ?? ''), 'en');
    $pass = $_POST['password'] ?? '';

    require_once __DIR__ . '/includes/header_tiles.php';
    if (!login_captcha_gate('teacher', $nid, $_POST['captcha'] ?? '')) {
        set_flash_message('error', 'کد امنیتی نادرست است.');
        redirect('index.php?view=login&tab=teacher');
    }

    $t = DB::fetch("SELECT * FROM teachers WHERE national_id = ? AND status = 1", [$nid]);
    if ($t && verify_user_password($pass, $t['password'], ['table'=>'teachers','id'=>$t['id']])) {
        login_guard_success('teacher', $nid);
        // v4.33.0: exclusive role session
        auth_login_as('teacher', [
            'teacher_id'   => $t['id'],
            'teacher_name' => $t['full_name'],
            'teacher_nid'  => $t['national_id'],
        ]);
        set_remember_login('teacher', $t['id']);
        set_flash_message('success', "خوش آمدید، استاد {$t['full_name']}");
        redirect('teacher-panel.php');
    } else {
        login_guard_fail('teacher', $nid);
        record_failed_login();
        set_flash_message('error', 'کد ملی یا کلمه عبور دبیر نادرست است.');
        redirect('index.php?view=login&tab=teacher');
    }
}

// v4.33.0: logged-in users must never see the login view (e.g. via the
// browser Back button) — send each role to its own panel BEFORE any output.
if ($view === 'login') {
    if (function_exists('is_teacher_logged_in') && is_teacher_logged_in()) redirect('teacher-panel.php');
    if (is_admin_logged_in())   redirect('index.php?view=dashboard');
    if (is_student_logged_in()) redirect('student-panel.php');
}

// Require header after POST processing
require_once __DIR__ . '/includes/header.php';

// Redirect to login if not authenticated
if (function_exists('is_teacher_logged_in') && is_teacher_logged_in()) {
    redirect('teacher-panel.php');
}
if (!is_admin_logged_in() && !is_student_logged_in()) {
    $view = 'login';
}

if (is_student_logged_in() && $view !== 'login') {
    redirect('student-panel.php');
}

// If Admin Dashboard
if ($view === 'dashboard' && is_admin_logged_in()):
    /* v4.83.0: dashboard stats are scoped to the DEFAULT academic year, so the
       numbers always describe the year the school is actually working in.
       Rows with an empty year (legacy data) are counted for the default year
       too — unify_academic_year() keeps stored formats comparable. */
    $dashYear = get_current_academic_year();
    $stats = [
        'students' => DB::fetch("SELECT COUNT(*) as c FROM students WHERE status='active' AND (academic_year=? OR academic_year IS NULL OR academic_year='')", [$dashYear])['c'] ?? 0,
        'classes'  => DB::fetch("SELECT COUNT(*) as c FROM classes WHERE academic_year=? OR academic_year IS NULL OR academic_year=''", [$dashYear])['c'] ?? 0,
        'reports'  => DB::fetch("SELECT COUNT(*) as c FROM reports WHERE academic_year=? OR academic_year IS NULL OR academic_year=''", [$dashYear])['c'] ?? 0,
        'admins'   => DB::fetch("SELECT COUNT(*) as c FROM admins")['c'] ?? 0,
    ];
    $recentLogs = DB::fetchAll("SELECT * FROM activity_logs ORDER BY id DESC LIMIT 5");
    $recentReports = DB::fetchAll("SELECT r.*, s.first_name, s.last_name FROM reports r JOIN students s ON r.student_id = s.id ORDER BY r.id DESC LIMIT 5");
    $recentDiscipline = DB::fetchAll("SELECT d.*, s.first_name, s.last_name, s.class_name FROM student_discipline_records d JOIN students s ON s.id=d.student_id ORDER BY d.id DESC LIMIT 8");
?>
<div class="space-y-6">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-2xl font-bold">داشبورد مدیریت تحلیلی</h2>
            <p class="text-sm text-muted">نمای کلی وضعیت سیستم، دانش‌آموزان و کارنامه‌ها — سال تحصیلی <?php echo tr_num(clean($dashYear), 'fa'); ?></p>
        </div>
        <div class="flex gap-2">
            <a href="import-students.php" class="btn btn-success gap-1 shadow">
                <span><svg data-ui-icon="users" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="9" cy="7" r="3"/><path d="M2 21v-3c0-6 14-6 14 0v3M16 4c5 0 5 6 1 6M19 14c3 1 3 4 3 7"/></svg> ایمپورت دانش‌آموزان</span>
            </a>
            <a href="import.php" class="btn btn-primary gap-1 shadow">
                <span><svg data-ui-icon="bolt" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m14 2-11 12h8l-1 8 11-12h-8Z"/></svg> ایمپورت کارنامه جدید</span>
            </a>
            <a href="reports.php?action=new" class="btn btn-outline gap-1">
                <span>+ ثبت دستی کارنامه</span>
            </a>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-4 gap-4 mb-6">
        <div class="card flex items-center justify-between border-l-4 border-l-blue-500 shadow-md">
            <div>
                <p class="text-xs text-muted font-semibold">تعداد دانش‌آموزان فعال</p>
                <h3 class="text-2xl font-bold mt-1 font-mono"><?php echo tr_num($stats['students'], 'fa'); ?> نفر</h3>
            </div>
            <div class="text-blue-500 text-3xl"><svg data-ui-icon="users" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="9" cy="7" r="3"/><path d="M2 21v-3c0-6 14-6 14 0v3M16 4c5 0 5 6 1 6M19 14c3 1 3 4 3 7"/></svg></div>
        </div>
        <div class="card flex items-center justify-between border-l-4 border-l-green-500 shadow-md">
            <div>
                <p class="text-xs text-muted font-semibold">تعداد کلاس‌ها</p>
                <h3 class="text-2xl font-bold mt-1 font-mono"><?php echo tr_num($stats['classes'], 'fa'); ?> کلاس</h3>
            </div>
            <div class="text-green-500 text-3xl"><svg data-ui-icon="school" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m3 9 9-6 9 6v12H3Z"/><path d="M9 21v-6h6v6M7 11h.01M17 11h.01M12 7v3M10.5 8.5h3"/></svg></div>
        </div>
        <div class="card flex items-center justify-between border-l-4 border-l-amber-500 shadow-md">
            <div>
                <p class="text-xs text-muted font-semibold">کل کارنامه‌های ثبت‌شده</p>
                <h3 class="text-2xl font-bold mt-1 font-mono"><?php echo tr_num($stats['reports'], 'fa'); ?> کارنامه</h3>
            </div>
            <div class="text-amber-500 text-3xl"><svg data-ui-icon="chart" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 3v18h18M7 17v-4m5 4V8m5 9V4"/></svg></div>
        </div>
        <div class="card flex items-center justify-between border-l-4 border-l-purple-500 shadow-md">
            <div>
                <p class="text-xs text-muted font-semibold">مدیران و کادر فعال</p>
                <h3 class="text-2xl font-bold mt-1 font-mono"><?php echo tr_num($stats['admins'], 'fa'); ?> نفر</h3>
            </div>
            <div class="text-purple-500 text-3xl"><svg data-ui-icon="shield" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m12 2 9 4v6c0 5-9 10-9 10S3 17 3 12V6Zm-5 9 3 3 7-7"/></svg></div>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-6">
        <div class="card shadow-lg">
            <h4 class="font-bold mb-3">آخرین موارد انضباطی</h4>
            <div class="table-container"><table><thead><tr><th>دانش‌آموز</th><th>کلاس</th><th>عنوان</th><th>تاریخ</th></tr></thead><tbody>
                <?php foreach($recentDiscipline as $d): ?><tr><td class="font-bold"><?php echo clean($d['first_name'].' '.$d['last_name']); ?></td><td><?php echo clean($d['class_name']); ?></td><td><?php echo clean($d['title_text']); ?></td><td><?php echo tr_num($d['occurred_at_jalali'],'fa'); ?></td></tr><?php endforeach; if(!$recentDiscipline): ?><tr><td colspan="4" class="text-center text-muted">موردی ثبت نشده است.</td></tr><?php endif; ?>
            </tbody></table></div>
        </div>
        <div class="card shadow-lg">
            <h4 class="font-bold mb-3">ارسال سریع پیام هدفمند</h4>
            <div class="grid grid-cols-4 gap-2 mb-3"><a class="btn btn-primary text-xs" href="bale-bot.php">بله</a><a class="btn btn-primary text-xs" href="telegram-bot.php">تلگرام</a><a class="btn btn-success text-xs" href="sms-panel.php">پیامک</a><a class="btn btn-warning text-xs" href="notifications.php">اعلان</a></div>
            <p class="text-xs text-muted">برای ارسال سریع هدفمند با انتخاب پایه، کلاس یا دانش‌آموز از پنل مربوط استفاده کنید.</p>
        </div>
    </div>

    <!-- Recent Tables -->
    <div class="grid grid-cols-2 gap-6">
        <div class="card shadow-lg">
            <div class="flex justify-between items-center mb-4 border-b pb-2">
                <h4 class="font-bold">آخرین کارنامه‌های ثبت‌شده</h4>
                <a href="reports.php" class="text-xs text-primary font-bold">مشاهده همه &larr;</a>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>دانش‌آموز</th>
                            <th>کلاس</th>
                            <th>نوبت/ماه</th>
                            <th>معدل</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentReports as $rep): ?>
                        <tr>
                            <td class="font-bold"><?php echo clean($rep['first_name'] . ' ' . $rep['last_name']); ?></td>
                            <td><span class="badge badge-info"><?php echo clean($rep['class_name']); ?></span></td>
                            <td><?php echo clean($rep['term'] . ' (' . $rep['report_month'] . ')'); ?></td>
                            <td><span class="badge badge-success font-mono"><?php echo format_score($rep['gpa']); ?></span></td>
                        </tr>
                        <?php endforeach; if (empty($recentReports)): ?>
                        <tr><td colspan="4" class="text-center text-muted">موردی یافت نشد.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card shadow-lg">
            <div class="flex justify-between items-center mb-4 border-b pb-2">
                <h4 class="font-bold">آخرین فعالیت‌های مدیران</h4>
                <a href="activity-logs.php" class="text-xs text-primary font-bold">مشاهده لاگ‌ها &larr;</a>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>مدیر</th>
                            <th>عملیات</th>
                            <th>زمان</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentLogs as $log): ?>
                        <tr>
                            <td class="font-bold"><?php echo clean($log['admin_username']); ?></td>
                            <td><?php echo clean($log['action']); ?></td>
                            <td class="text-xs text-muted font-mono"><?php echo jdate('Y/m/d H:i', strtotime($log['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; if (empty($recentLogs)): ?>
                        <tr><td colspan="3" class="text-center text-muted">لاگی موجود نیست.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php
elseif ($view === 'login'):
    $activeTab = $_GET['tab'] ?? 'student';
    $captchaQ = generate_captcha();
    $logoUrl  = get_setting('logo_url', '');
?>
<script>document.body.classList.add('auth-page');</script>
<div class="auth-wrap my-8">
    <div class="auth-card">
        <div class="auth-brand">
            <div class="auth-logo"><?php if (!empty($logoUrl)): ?><img src="<?php echo clean($logoUrl); ?>" alt="لوگو"><?php else: ?><svg data-ui-icon="student" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m2 7 10-4 10 4-10 4Zm3 2v5c3 3 11 3 14 0V9M22 7v7M5 21c1-5 13-5 14 0"/></svg><?php endif; ?></div>
            <div>
                <div class="auth-brand-title"><?php echo clean(get_setting('school_name', 'سامانه مدیریت کارنامه‌های تحصیلی')); ?></div>
                <div class="auth-brand-sub">سامانه یکپارچه کارنامه، ارزشیابی و آزمون آنلاین</div>
            </div>
            <div class="auth-brand-features">
                <div class="auth-feature"><span class="fi"><svg data-ui-icon="chart" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 3v18h18M7 17v-4m5 4V8m5 9V4"/></svg></span> مشاهده و دریافت کارنامه‌های تحصیلی</div>
                <div class="auth-feature"><span class="fi"><svg data-ui-icon="science" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M8 2h8m-6 0v7L3 21h18L14 9V2M7 15h10"/></svg></span> شرکت در آزمون‌های آنلاین مدرسه</div>
                <div class="auth-feature"><span class="fi"><svg data-ui-icon="bell" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 18h16l-2-4V9c0-8-12-8-12 0v5Zm6 3h4M12 2V1"/></svg></span> اعلان‌ها و پیام‌های آموزشی</div>
                <div class="auth-feature"><span class="fi"><svg data-ui-icon="lock" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="4" y="10" width="16" height="12" rx="2"/><path d="M7 10V6c0-6 10-6 10 0v4M12 15v3"/></svg></span> ورود امن با کد ملی و سریال شناسنامه</div>
            </div>
        </div>
        <div class="auth-form-side">
        <div class="auth-form-title">ورود به سامانه</div>
        <div class="auth-form-sub">جهت ورود یا استعلام کارنامه، اطلاعات هویتی خود را وارد نمایید</div>

        <div class="auth-tabs" role="tablist">
            <button type="button" onclick="switchTab('studentTab')" id="btnStudentTab" class="auth-tab <?php echo $activeTab === 'student' ? 'active' : ''; ?>"><svg data-ui-icon="student" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m2 7 10-4 10 4-10 4Zm3 2v5c3 3 11 3 14 0V9M22 7v7M5 21c1-5 13-5 14 0"/></svg> دانش‌آموزان</button>
            <button type="button" onclick="switchTab('inquiryTab')" id="btnInquiryTab" class="auth-tab <?php echo $activeTab === 'inquiry' ? 'active' : ''; ?>"><svg data-ui-icon="search" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/></svg> استعلام سریع</button>
            <button type="button" onclick="switchTab('teacherTab')" id="btnTeacherTab" class="auth-tab <?php echo $activeTab === 'teacher' ? 'active' : ''; ?>"><svg data-ui-icon="users" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="9" cy="7" r="3"/><path d="M2 21v-3c0-6 14-6 14 0v3M16 4c5 0 5 6 1 6M19 14c3 1 3 4 3 7"/></svg> دبیران</button>
        </div>

        <!-- 1) Student Login Form (Default) -->
        <form id="studentTab" class="auth-panel" method="POST" action="index.php?view=login&tab=student" style="<?php echo $activeTab === 'student' ? 'display:block;' : 'display:none;'; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="login_type" value="student">
            <div class="auth-field">
                <label>کد ملی دانش‌آموز (نام کاربری)</label>
                <div class="auth-input-wrap">
                    <span class="auth-icon"><svg data-ui-icon="user" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="7" r="4"/><path d="M4 21v-2c0-8 16-8 16 0v2"/></svg></span>
                    <input type="text" name="national_id" class="form-input dir-ltr text-left font-mono js-cap-identity" inputmode="numeric" autocomplete="username" placeholder="کد ملی ۱۰ رقمی" required autofocus>
                </div>
            </div>
            <div class="auth-field">
                <label>سریال ۶ رقمی شناسنامه (رمز عبور)</label>
                <div class="auth-input-wrap">
                    <span class="auth-icon"><svg data-ui-icon="key" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="8" cy="8" r="5"/><path d="m12 12 9 9m-5-5 3-3m-6 0 3-3"/></svg></span>
                    <input type="password" name="password" id="stPass" class="form-input dir-ltr text-left font-mono" autocomplete="current-password" placeholder="******" required>
                    <button type="button" class="auth-eye" onclick="togglePass('stPass', this)" tabindex="-1" aria-label="نمایش رمز"><svg data-ui-icon="eye" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 12c5-10 15-10 20 0-5 10-15 10-20 0Z"/><circle cx="12" cy="12" r="3"/></svg></button>
                </div>
            </div>
            <?php /* v4.132.0: کد امنیتی فقط بعد از اولین تلاش ناموفقِ همین حساب.
                  پیش‌فرض مخفی است و با data-role/identity زنده تصمیم می‌گیرد. */ ?>
            <div class="auth-captcha js-captcha-wrap" data-cap-role="student" <?php echo login_needs_captcha('student','') ? '' : 'hidden'; ?>>
                <span class="auth-captcha-label">سوال امنیتی:</span>
                <span class="auth-captcha-q"><?php echo $captchaQ; ?></span>
                <input type="number" name="captcha" class="form-input js-captcha-input" placeholder="پاسخ" <?php echo login_needs_captcha('student','') ? 'required' : 'disabled'; ?>>
            </div>
            <button type="submit" class="btn btn-success auth-submit">ورود و مشاهده کارنامه‌های من &larr;</button>
        </form>

        <!-- 2) Quick Inquiry Form -->
        <form id="inquiryTab" class="auth-panel" method="POST" action="index.php?view=login&tab=inquiry" style="<?php echo $activeTab === 'inquiry' ? 'display:block;' : 'display:none;'; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="login_type" value="inquiry">
            <div class="auth-field">
                <label>سال تحصیلی مورد استعلام</label>
                <div class="auth-input-wrap">
                    <span class="auth-icon"><svg data-ui-icon="calendar" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 11h18M8 15h2m4 0h2m-8 3h2"/></svg></span>
                    <select name="academic_year" class="form-select font-bold" style="padding-inline-start:2.3rem;min-height:42px;">
                        <option value="1404/1405">1404/1405 (سال جاری)</option>
                        <option value="1403/1404">1403/1404</option>
                        <option value="1402/1403">1402/1403</option>
                    </select>
                </div>
            </div>
            <div class="auth-field">
                <label>کد ملی دانش‌آموز</label>
                <div class="auth-input-wrap">
                    <span class="auth-icon"><svg data-ui-icon="user" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="7" r="4"/><path d="M4 21v-2c0-8 16-8 16 0v2"/></svg></span>
                    <input type="text" name="national_id" class="form-input dir-ltr text-left font-mono js-cap-identity" inputmode="numeric" placeholder="کد ملی ۱۰ رقمی" required>
                </div>
            </div>
            <div class="auth-field">
                <label>سریال ۶ رقمی شناسنامه</label>
                <div class="auth-input-wrap">
                    <span class="auth-icon"><svg data-ui-icon="card" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="2" y="4" width="20" height="16" rx="2"/><circle cx="8" cy="10" r="2"/><path d="M5 17c0-5 6-5 6 0M14 9h5m-5 5h5"/></svg></span>
                    <input type="text" name="serial_number" class="form-input dir-ltr text-left font-mono" inputmode="numeric" placeholder="۶ رقم" required>
                </div>
            </div>
            <?php /* v4.132.0: کد امنیتی فقط بعد از اولین تلاش ناموفقِ همین حساب.
                  پیش‌فرض مخفی است و با data-role/identity زنده تصمیم می‌گیرد. */ ?>
            <div class="auth-captcha js-captcha-wrap" data-cap-role="inquiry" <?php echo login_needs_captcha('inquiry','') ? '' : 'hidden'; ?>>
                <span class="auth-captcha-label">سوال امنیتی:</span>
                <span class="auth-captcha-q"><?php echo $captchaQ; ?></span>
                <input type="number" name="captcha" class="form-input js-captcha-input" placeholder="پاسخ" <?php echo login_needs_captcha('inquiry','') ? 'required' : 'disabled'; ?>>
            </div>
            <button type="submit" class="btn btn-primary auth-submit"><svg data-ui-icon="search" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/></svg> استعلام و دریافت کارنامه تحصیلی</button>
        </form>

        <!-- 3) Teacher Login Form -->
        <form id="teacherTab" class="auth-panel" method="POST" action="index.php?view=login&tab=teacher" style="<?php echo $activeTab === 'teacher' ? 'display:block;' : 'display:none;'; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="login_type" value="teacher">
            <div class="auth-field">
                <label>کد ملی دبیر (نام کاربری)</label>
                <div class="auth-input-wrap">
                    <span class="auth-icon"><svg data-ui-icon="user" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="7" r="4"/><path d="M4 21v-2c0-8 16-8 16 0v2"/></svg></span>
                    <input type="text" name="national_id" class="form-input dir-ltr text-left font-mono js-cap-identity" inputmode="numeric" autocomplete="username" placeholder="کد ملی ۱۰ رقمی" required>
                </div>
            </div>
            <div class="auth-field">
                <label>کد پرسنلی / رمز ورود</label>
                <div class="auth-input-wrap">
                    <span class="auth-icon"><svg data-ui-icon="key" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="8" cy="8" r="5"/><path d="m12 12 9 9m-5-5 3-3m-6 0 3-3"/></svg></span>
                    <input type="password" name="password" id="tPass" class="form-input dir-ltr text-left font-mono" autocomplete="current-password" placeholder="******" required>
                    <button type="button" class="auth-eye" onclick="togglePass('tPass', this)" tabindex="-1" aria-label="نمایش رمز"><svg data-ui-icon="eye" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 12c5-10 15-10 20 0-5 10-15 10-20 0Z"/><circle cx="12" cy="12" r="3"/></svg></button>
                </div>
            </div>
            <?php /* v4.132.0: کد امنیتی فقط بعد از اولین تلاش ناموفقِ همین حساب.
                  پیش‌فرض مخفی است و با data-role/identity زنده تصمیم می‌گیرد. */ ?>
            <div class="auth-captcha js-captcha-wrap" data-cap-role="teacher" <?php echo login_needs_captcha('teacher','') ? '' : 'hidden'; ?>>
                <span class="auth-captcha-label">سوال امنیتی:</span>
                <span class="auth-captcha-q"><?php echo $captchaQ; ?></span>
                <input type="number" name="captcha" class="form-input js-captcha-input" placeholder="پاسخ" <?php echo login_needs_captcha('teacher','') ? 'required' : 'disabled'; ?>>
            </div>
            <button type="submit" class="btn btn-primary auth-submit">ورود به پورتال دبیران و ثبت نمرات &larr;</button>
        </form>

        <div class="auth-foot">
            <a href="admin-login.php"><svg data-ui-icon="lock" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="4" y="10" width="16" height="12" rx="2"/><path d="M7 10V6c0-6 10-6 10 0v4M12 15v3"/></svg> ورود اختصاصی کادر مدیریت مدرسه &larr;</a>
        </div>
        </div><!-- /auth-form-side -->
    </div>
</div>
<script>
function switchTab(tabId) {
    ['studentTab','inquiryTab','teacherTab'].forEach(function(id){
        document.getElementById(id).style.display = (id === tabId) ? 'block' : 'none';
        var btn = document.getElementById('btn' + id.charAt(0).toUpperCase() + id.slice(1).replace('Tab','') + 'Tab');
        if (btn) btn.classList.toggle('active', id === tabId);
    });
    var first = document.querySelector('#' + tabId + ' input[type="text"], #' + tabId + ' select');
    if (first) try { first.focus(); } catch(e) {}
}
function togglePass(id, btn) {
    var el = document.getElementById(id);
    if (!el) return;
    el.type = el.type === 'password' ? 'text' : 'password';
    btn.textContent = el.type === 'password' ? '👁' : '🙈';
}
</script>
<script src="assets/js/login-captcha.js"></script>
<?php endif; require_once __DIR__ . '/includes/footer.php'; ?>
