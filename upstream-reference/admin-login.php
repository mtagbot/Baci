<?php
/**
 * Secret Admin & Teacher Login (admin-login.php)
 * Hidden from public index.php for enhanced security.
 */

require_once __DIR__ . '/includes/auth.php';
if (is_admin_logged_in()) redirect('index.php?view=dashboard');
if (function_exists('is_teacher_logged_in') && is_teacher_logged_in()) redirect('teacher-panel.php');

$action = $_POST['action'] ?? '';

// Check Throttle
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !check_login_throttle($ip)) {
    set_flash_message('error', 'تعداد تلاش‌های ناموفق شما بیش از حد مجاز است. لطفاً ۱۰ دقیقه دیگر مجدداً تلاش فرمایید.');
    redirect('admin-login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_type']) && $_POST['login_type'] === 'admin') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'خطای امنیتی CSRF');
        redirect('admin-login.php');
    }
    if (!verify_captcha($_POST['captcha'] ?? '')) {
        record_failed_login();
        set_flash_message('error', 'کد امنیتی نادرست است.');
        redirect('admin-login.php');
    }
    $user = clean($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';

    $adm = DB::fetch("SELECT * FROM admins WHERE username = ? AND status = 1", [$user]);
    if ($adm && (password_verify($pass, $adm['password']) || $pass === $adm['password'])) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['admin_id']       = $adm['id'];
        $_SESSION['admin_username'] = $adm['username'];
        $_SESSION['admin_name']     = $adm['name'];
        $_SESSION['admin_role']     = $adm['role'];
        set_remember_login('admin', $adm['id']);
        log_activity($adm['id'], 'ورود به سیستم مدیریت', "مدیر {$adm['name']} وارد شد.");
        set_flash_message('success', "خوش آمدید، {$adm['name']}");
        redirect('index.php?view=dashboard');
    } else {
        record_failed_login();
        set_flash_message('error', 'نام کاربری یا کلمه عبور مدیریت نادرست است.');
        redirect('admin-login.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_type']) && $_POST['login_type'] === 'teacher') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'خطای امنیتی CSRF');
        redirect('admin-login.php?tab=teacher');
    }
    if (!verify_captcha($_POST['captcha'] ?? '')) {
        record_failed_login();
        set_flash_message('error', 'کد امنیتی نادرست است.');
        redirect('admin-login.php?tab=teacher');
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
        set_flash_message('error', 'کد ملی یا رمز ورود دبیر نادرست است.');
        redirect('admin-login.php?tab=teacher');
    }
}

require_once __DIR__ . '/includes/header.php';
$tab = $_GET['tab'] ?? 'admin';
$captchaQ = generate_captcha();
?>
<div class="max-w-md w-full mx-auto my-12">
    <div class="card p-8 shadow-2xl rounded-2xl border-2 border-primary bg-white dark:bg-slate-900">
        <div class="text-center mb-6 border-b pb-4">
            <h2 class="text-2xl font-extrabold text-primary mb-1">🔐 ورود اختصاصی کادر مدرسه</h2>
            <p class="text-xs text-muted font-medium">پنل مدیریت و پورتال اختصاصی دبیران</p>
        </div>

        <div class="flex border-b border-color mb-6 text-xs font-bold">
            <button onclick="switchAdminTab('adminTab')" id="btnAdminTab" class="flex-1 py-3 transition-colors <?php echo $tab === 'admin' ? 'border-b-2 border-primary text-primary font-extrabold' : 'text-muted'; ?>">⚙️ ورود مدیران</button>
            <button onclick="switchAdminTab('teacherTab')" id="btnTeacherTab" class="flex-1 py-3 transition-colors <?php echo $tab === 'teacher' ? 'border-b-2 border-green-600 text-green-600 font-extrabold' : 'text-muted'; ?>">👨‍🏫 ورود دبیران</button>
        </div>

        <!-- Admin Form -->
        <form id="adminTab" method="POST" action="admin-login.php" style="<?php echo $tab === 'admin' ? 'display:block;' : 'display:none;'; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="login_type" value="admin">
            <div class="mb-4">
                <label class="block text-xs font-bold mb-1.5">نام کاربری مدیریت *</label>
                <input type="text" name="username" class="form-input dir-ltr text-left font-mono" placeholder="" required autofocus>
            </div>
            <div class="mb-4">
                <label class="block text-xs font-bold mb-1.5">کلمه عبور *</label>
                <input type="password" name="password" class="form-input dir-ltr text-left font-mono" placeholder="" required>
            </div>
            <div class="mb-6 bg-slate-50 dark:bg-slate-800 p-3 rounded-lg border">
                <label class="block text-xs font-bold mb-1 text-primary">کد امنیتی: <span class="text-base font-mono"><?php echo $captchaQ; ?></span></label>
                <input type="number" name="captcha" class="form-input font-mono text-center" placeholder="" required>
            </div>
            <button type="submit" class="btn btn-primary w-full py-3.5 text-base font-extrabold shadow-lg">ورود به پنل مدیریت &larr;</button>
        </form>

        <!-- Teacher Form -->
        <form id="teacherTab" method="POST" action="admin-login.php?tab=teacher" style="<?php echo $tab === 'teacher' ? 'display:block;' : 'display:none;'; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="login_type" value="teacher">
            <div class="mb-4">
                <label class="block text-xs font-bold mb-1.5">کد ملی دبیر (۱۰ رقم - نام کاربری) *</label>
                <input type="text" name="national_id" class="form-input dir-ltr text-left font-mono" placeholder="" required>
            </div>
            <div class="mb-4">
                <label class="block text-xs font-bold mb-1.5">کد پرسنلی / کلمه عبور *</label>
                <input type="password" name="password" class="form-input dir-ltr text-left font-mono" placeholder="" required>
            </div>
            <div class="mb-6 bg-slate-50 dark:bg-slate-800 p-3 rounded-lg border">
                <label class="block text-xs font-bold mb-1 text-green-600">کد امنیتی: <span class="text-base font-mono"><?php echo $captchaQ; ?></span></label>
                <input type="number" name="captcha" class="form-input font-mono text-center" required>
            </div>
            <button type="submit" class="btn btn-success w-full py-3.5 text-base font-extrabold shadow-lg">ورود به پورتال دبیران &larr;</button>
        </form>
    </div>
</div>
<script>
function switchAdminTab(tId) {
    document.getElementById('adminTab').style.display = 'none';
    document.getElementById('teacherTab').style.display = 'none';
    document.getElementById('btnAdminTab').className = 'flex-1 py-3 transition-colors text-muted';
    document.getElementById('btnTeacherTab').className = 'flex-1 py-3 transition-colors text-muted';
    document.getElementById(tId).style.display = 'block';
    if(tId === 'adminTab') document.getElementById('btnAdminTab').className = 'flex-1 py-3 transition-colors border-b-2 border-primary text-primary font-extrabold';
    if(tId === 'teacherTab') document.getElementById('btnTeacherTab').className = 'flex-1 py-3 transition-colors border-b-2 border-green-600 text-green-600 font-extrabold';
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
