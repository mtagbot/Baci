<?php
/**
 * Main Front Controller & Router / Default Student Login & Quick Inquiry
 */

require_once __DIR__ . '/includes/auth.php';

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
    session_destroy();
    redirect('index.php');
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
    if (!verify_captcha($_POST['captcha'] ?? '')) {
        record_failed_login();
        set_flash_message('error', 'پاسخ سوال امنیتی (کد امنیتی) نادرست است.');
        redirect('index.php?view=login&tab=admin');
    }
    $username = clean($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $admin = DB::fetch("SELECT * FROM admins WHERE username = ? AND status = 1", [$username]);
    if ($admin && (password_verify($password, $admin['password']) || $password === $admin['password'] || md5($password) === $admin['password'])) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['admin_id']       = $admin['id'];
        $_SESSION['admin_username'] = $admin['username'];
        $_SESSION['admin_name']     = $admin['name'];
        $_SESSION['admin_role']     = $admin['role'];
        set_remember_login('admin', $admin['id']);
        log_activity($admin['id'], 'ورود به سیستم', "مدیر {$admin['name']} وارد سیستم شد.");
        set_flash_message('success', "خوش آمدید، {$admin['name']}");
        redirect('index.php?view=dashboard');
    } else {
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
    if (!verify_captcha($_POST['captcha'] ?? '')) {
        record_failed_login();
        set_flash_message('error', 'پاسخ سوال امنیتی نادرست است.');
        redirect('index.php?view=login&tab=student');
    }
    $nationalId = tr_num(clean($_POST['national_id'] ?? ''), 'en');
    $password   = tr_num($_POST['password'] ?? '', 'en'); // 6 digit serial

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
            if (password_verify($password, $student['password']) || $password === $student['password'] || $password === $student['serial_number'] || $password === $student['national_id']) {
                $valid = true;
            }
        } elseif ($password === $student['serial_number'] || $password === $student['national_id']) {
            $valid = true;
        }

        if ($valid) {
            $_SESSION['login_attempts'] = 0;
            $_SESSION['student_id']   = $student['id'];
            $_SESSION['student_name'] = $student['first_name'] . ' ' . $student['last_name'];
            $_SESSION['student_nid']  = $student['national_id'];
            set_remember_login('student', $student['id']);
            set_flash_message('success', "خوش آمدید، {$_SESSION['student_name']}");
            redirect('student-panel.php');
        }
    }
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
    if (!verify_captcha($_POST['captcha'] ?? '')) {
        record_failed_login();
        set_flash_message('error', 'پاسخ سوال امنیتی نادرست است.');
        redirect('index.php?view=login&tab=inquiry');
    }
    $year       = trim($_POST['academic_year'] ?? '1404/1405');
    $nationalId = tr_num(clean($_POST['national_id'] ?? ''), 'en');
    $serial     = tr_num($_POST['serial_number'] ?? '', 'en');

    // Prefer requested year, then current default
    $student = null;
    try {
        $student = DB::fetch("SELECT * FROM students WHERE national_id = ? AND (academic_year=? OR academic_year=?) AND status='active' ORDER BY id DESC LIMIT 1", [$nationalId, $year, str_replace('-','/',$year)]);
    } catch (Exception $e) {}
    if (!$student) {
        $student = DB::fetch("SELECT * FROM students WHERE national_id = ? AND status = 'active' ORDER BY academic_year DESC LIMIT 1", [$nationalId]);
    }
    if ($student && ($serial === $student['serial_number'] || password_verify($serial, $student['password']) || $serial === $student['password'])) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['student_id']   = $student['id'];
        $_SESSION['student_name'] = $student['first_name'] . ' ' . $student['last_name'];
        $_SESSION['student_nid']  = $student['national_id'];
        set_remember_login('student', $student['id']);
        set_flash_message('success', "استعلام موفق کارنامه‌های سال تحصیلی $year");
        redirect("student-panel.php?year=" . urlencode($year));
    }
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
    if (!verify_captcha($_POST['captcha'] ?? '')) {
        record_failed_login();
        set_flash_message('error', 'کد امنیتی نادرست است.');
        redirect('index.php?view=login&tab=teacher');
    }
    $nid  = tr_num(clean($_POST['national_id'] ?? ''), 'en');
    $pass = $_POST['password'] ?? '';

    $t = DB::fetch("SELECT * FROM teachers WHERE national_id = ? AND status = 1", [$nid]);
    if ($t && (password_verify($pass, $t['password']) || $pass === $t['password'] || $pass === $t['personnel_code'])) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['teacher_id']   = $t['id'];
        $_SESSION['teacher_name'] = $t['full_name'];
        $_SESSION['teacher_nid']  = $t['national_id'];
        set_remember_login('teacher', $t['id']);
        set_flash_message('success', "خوش آمدید، استاد {$t['full_name']}");
        redirect('teacher-panel.php');
    } else {
        record_failed_login();
        set_flash_message('error', 'کد ملی یا کلمه عبور دبیر نادرست است.');
        redirect('index.php?view=login&tab=teacher');
    }
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
    $stats = [
        'students' => DB::fetch("SELECT COUNT(*) as c FROM students WHERE status='active'")['c'] ?? 0,
        'classes'  => DB::fetch("SELECT COUNT(*) as c FROM classes")['c'] ?? 0,
        'reports'  => DB::fetch("SELECT COUNT(*) as c FROM reports")['c'] ?? 0,
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
            <p class="text-sm text-muted">نمای کلی وضعیت سیستم، دانش‌آموزان و کارنامه‌ها</p>
        </div>
        <div class="flex gap-2">
            <a href="import-students.php" class="btn btn-success gap-1 shadow">
                <span>👥 ایمپورت دانش‌آموزان</span>
            </a>
            <a href="import.php" class="btn btn-primary gap-1 shadow">
                <span>⚡ ایمپورت کارنامه جدید</span>
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
            <div class="text-blue-500 text-3xl">👥</div>
        </div>
        <div class="card flex items-center justify-between border-l-4 border-l-green-500 shadow-md">
            <div>
                <p class="text-xs text-muted font-semibold">تعداد کلاس‌ها</p>
                <h3 class="text-2xl font-bold mt-1 font-mono"><?php echo tr_num($stats['classes'], 'fa'); ?> کلاس</h3>
            </div>
            <div class="text-green-500 text-3xl">🏫</div>
        </div>
        <div class="card flex items-center justify-between border-l-4 border-l-amber-500 shadow-md">
            <div>
                <p class="text-xs text-muted font-semibold">کل کارنامه‌های ثبت‌شده</p>
                <h3 class="text-2xl font-bold mt-1 font-mono"><?php echo tr_num($stats['reports'], 'fa'); ?> کارنامه</h3>
            </div>
            <div class="text-amber-500 text-3xl">📊</div>
        </div>
        <div class="card flex items-center justify-between border-l-4 border-l-purple-500 shadow-md">
            <div>
                <p class="text-xs text-muted font-semibold">مدیران و کادر فعال</p>
                <h3 class="text-2xl font-bold mt-1 font-mono"><?php echo tr_num($stats['admins'], 'fa'); ?> نفر</h3>
            </div>
            <div class="text-purple-500 text-3xl">🛡️</div>
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
?>
<div class="max-w-lg w-full mx-auto my-10">
    <div class="card p-8 shadow-2xl rounded-2xl border-2 border-primary bg-white dark:bg-slate-900">
        <div class="text-center mb-6 border-b pb-4">
            <h2 class="text-2xl font-extrabold text-primary mb-1"><?php echo clean(get_setting('school_name', 'سامانه مدیریت کارنامه‌های تحصیلی')); ?></h2>
            <p class="text-xs text-muted font-medium">لطفاً جهت ورود یا استعلام کارنامه اطلاعات هویتی خود را وارد نمایید</p>
        </div>

        <div class="flex border-b border-color mb-6 text-xs font-bold">
            <button onclick="switchTab('studentTab')" id="btnStudentTab" class="flex-1 py-3 transition-colors <?php echo $activeTab === 'student' ? 'border-b-2 border-green-600 text-green-600 font-extrabold' : 'text-muted'; ?>">🎓 ورود دانش‌آموزان</button>
            <button onclick="switchTab('inquiryTab')" id="btnInquiryTab" class="flex-1 py-3 transition-colors <?php echo $activeTab === 'inquiry' ? 'border-b-2 border-amber-600 text-amber-600 font-extrabold' : 'text-muted'; ?>">🔍 استعلام سریع کارنامه</button>
            <button onclick="switchTab('teacherTab')" id="btnTeacherTab" class="flex-1 py-3 transition-colors <?php echo $activeTab === 'teacher' ? 'border-b-2 border-indigo-600 text-indigo-600 font-extrabold' : 'text-muted'; ?>">👨‍🏫 ورود دبیران</button>
        </div>

        <!-- 1) Student Login Form (Default) -->
        <form id="studentTab" method="POST" action="index.php?view=login&tab=student" style="<?php echo $activeTab === 'student' ? 'display:block;' : 'display:none;'; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="login_type" value="student">
            <div class="mb-4">
                <label class="block text-xs font-bold mb-1.5 text-gray-700 dark:text-gray-200">کد ملی دانش‌آموز (۱۰ رقم - نام کاربری) *</label>
                <input type="text" name="national_id" class="form-input dir-ltr text-left font-mono text-base" placeholder="" required autofocus>
            </div>
            <div class="mb-4">
                <label class="block text-xs font-bold mb-1.5 text-gray-700 dark:text-gray-200">سریال ۶ رقمی شناسنامه (رمز عبور) *</label>
                <input type="password" name="password" class="form-input dir-ltr text-left font-mono text-base" placeholder="" required>
            </div>
            <div class="mb-6 bg-slate-50 dark:bg-slate-800 p-3 rounded-lg border">
                <label class="block text-xs font-bold mb-1 text-primary">سوال امنیتی ضد اسپم: <span class="text-base font-mono"><?php echo $captchaQ; ?></span></label>
                <input type="number" name="captcha" class="form-input font-mono text-center" placeholder="" required>
            </div>
            <button type="submit" class="btn btn-success w-full py-3.5 text-base font-extrabold shadow-lg">ورود و مشاهده کارنامه‌های من &larr;</button>
        </form>

        <!-- 2) Quick Inquiry Form -->
        <form id="inquiryTab" method="POST" action="index.php?view=login&tab=inquiry" style="<?php echo $activeTab === 'inquiry' ? 'display:block;' : 'display:none;'; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="login_type" value="inquiry">
            <div class="mb-4">
                <label class="block text-xs font-bold mb-1.5">انتخاب سال تحصیلی مورد استعلام</label>
                <select name="academic_year" class="form-select font-bold">
                    <option value="1404/1405">1404/1405 (سال جاری)</option>
                    <option value="1403/1404">1403/1404</option>
                    <option value="1402/1403">1402/1403</option>
                </select>
            </div>
            <div class="mb-4">
                <label class="block text-xs font-bold mb-1.5">کد ملی دانش‌آموز (۱۰ رقم) *</label>
                <input type="text" name="national_id" class="form-input dir-ltr text-left font-mono" placeholder="" required>
            </div>
            <div class="mb-4">
                <label class="block text-xs font-bold mb-1.5">سریال ۶ رقمی شناسنامه *</label>
                <input type="text" name="serial_number" class="form-input dir-ltr text-left font-mono" placeholder="" required>
            </div>
            <div class="mb-6 bg-slate-50 dark:bg-slate-800 p-3 rounded-lg border">
                <label class="block text-xs font-bold mb-1 text-amber-600">سوال امنیتی: <span class="text-base font-mono"><?php echo $captchaQ; ?></span></label>
                <input type="number" name="captcha" class="form-input font-mono text-center" placeholder="" required>
            </div>
            <button type="submit" class="btn btn-primary w-full py-3.5 text-base font-extrabold shadow-lg">🔍 استعلام و دریافت کارنامه تحصیلی</button>
        </form>

        <!-- 3) Teacher Login Form -->
        <form id="teacherTab" method="POST" action="index.php?view=login&tab=teacher" style="<?php echo $activeTab === 'teacher' ? 'display:block;' : 'display:none;'; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="login_type" value="teacher">
            <div class="mb-4">
                <label class="block text-xs font-bold mb-1.5 text-gray-700 dark:text-gray-200">کد ملی دبیر (۱۰ رقم - نام کاربری) *</label>
                <input type="text" name="national_id" class="form-input dir-ltr text-left font-mono text-base" placeholder="" required>
            </div>
            <div class="mb-4">
                <label class="block text-xs font-bold mb-1.5 text-gray-700 dark:text-gray-200">کد پرسنلی / رمز ورود *</label>
                <input type="password" name="password" class="form-input dir-ltr text-left font-mono text-base" placeholder="" required>
            </div>
            <div class="mb-6 bg-slate-50 dark:bg-slate-800 p-3 rounded-lg border">
                <label class="block text-xs font-bold mb-1 text-indigo-600">سوال امنیتی: <span class="text-base font-mono"><?php echo $captchaQ; ?></span></label>
                <input type="number" name="captcha" class="form-input font-mono text-center" placeholder="" required>
            </div>
            <button type="submit" class="btn btn-primary w-full py-3.5 text-base font-extrabold shadow-lg">ورود به پورتال دبیران و ثبت نمرات &larr;</button>
        </form>

        <div class="text-center mt-6 pt-4 border-t border-gray-100 dark:border-slate-800">
            <a href="admin-login.php" class="text-xs text-muted hover:text-primary font-semibold">🔐 ورود اختصاصی کادر مدیریت مدرسه &larr;</a>
        </div>
    </div>
</div>
<script>
function switchTab(tabId) {
    document.getElementById('studentTab').style.display = 'none';
    document.getElementById('inquiryTab').style.display = 'none';
    document.getElementById('teacherTab').style.display = 'none';

    document.getElementById('btnStudentTab').className = 'flex-1 py-3 transition-colors text-muted';
    document.getElementById('btnInquiryTab').className = 'flex-1 py-3 transition-colors text-muted';
    document.getElementById('btnTeacherTab').className = 'flex-1 py-3 transition-colors text-muted';

    document.getElementById(tabId).style.display = 'block';
    if(tabId === 'studentTab') document.getElementById('btnStudentTab').className = 'flex-1 py-3 transition-colors border-b-2 border-green-600 text-green-600 font-extrabold';
    if(tabId === 'inquiryTab') document.getElementById('btnInquiryTab').className = 'flex-1 py-3 transition-colors border-b-2 border-amber-600 text-amber-600 font-extrabold';
    if(tabId === 'teacherTab') document.getElementById('btnTeacherTab').className = 'flex-1 py-3 transition-colors border-b-2 border-indigo-600 text-indigo-600 font-extrabold';
}
</script>
<?php endif; require_once __DIR__ . '/includes/footer.php'; ?>
