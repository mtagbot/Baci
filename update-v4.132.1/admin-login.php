<?php
/**
 * Secret Admin & Teacher Login (admin-login.php)
 * Hidden from public index.php for enhanced security.
 */

require_once __DIR__ . '/includes/auth.php';
if (is_admin_logged_in()) redirect('index.php?view=dashboard');
if (function_exists('is_teacher_logged_in') && is_teacher_logged_in()) redirect('teacher-panel.php');
// v4.33.0: a logged-in student must not see the staff login page with student menus
if (is_student_logged_in()) redirect('student-panel.php');

$action = $_POST['action'] ?? '';

/* SchoolDesk Pro: on the desktop, session-based flash messages can get lost
   when session storage misbehaves — carry the error code in the URL too,
   so the user ALWAYS sees why the login failed. */
function sdp_fail($code, $tab = 'admin') {
    $q = 'err=' . $code . ($tab === 'teacher' ? '&tab=teacher' : '');
    redirect('admin-login.php?' . $q);
}

// Check Throttle
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !check_login_throttle($ip)) {
    set_flash_message('error', 'تعداد تلاش‌های ناموفق شما بیش از حد مجاز است. لطفاً ۱۰ دقیقه دیگر مجدداً تلاش فرمایید.');
    sdp_fail('throttle');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_type']) && $_POST['login_type'] === 'admin') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'خطای امنیتی CSRF');
        sdp_fail('csrf');
    }
    /* v4.132.0: کد امنیتی فقط بعد از اولین تلاش ناموفقِ همین حساب */
    $user = clean($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';

    require_once __DIR__ . '/includes/header_tiles.php';
    if (!login_captcha_gate('admin', $user, $_POST['captcha'] ?? '')) {
        set_flash_message('error', 'کد امنیتی نادرست است.');
        sdp_fail('captcha');
    }

    $adm = DB::fetch("SELECT * FROM admins WHERE username = ? AND status = 1", [$user]);
    if ($adm && verify_user_password($pass, $adm['password'], ['table'=>'admins','id'=>$adm['id']])) {
        login_guard_success('admin', $user);
        // v4.33.0: exclusive role session
        auth_login_as('admin', [
            'admin_id'       => $adm['id'],
            'admin_username' => $adm['username'],
            'admin_name'     => $adm['name'],
            'admin_role'     => $adm['role'],
        ]);
        set_remember_login('admin', $adm['id']);
        log_activity($adm['id'], 'ورود به سیستم مدیریت', "مدیر {$adm['name']} وارد شد.");
        set_flash_message('success', "خوش آمدید، {$adm['name']}");
        redirect('index.php?view=dashboard');
    } else {
        login_guard_fail('admin', $user);
        record_failed_login();
        set_flash_message('error', 'نام کاربری یا کلمه عبور مدیریت نادرست است.');
        sdp_fail('badpass');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_type']) && $_POST['login_type'] === 'teacher') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'خطای امنیتی CSRF');
        sdp_fail('csrf', 'teacher');
    }
    $nid  = tr_num(clean($_POST['national_id'] ?? ''), 'en');
    $pass = $_POST['password'] ?? '';

    require_once __DIR__ . '/includes/header_tiles.php';
    if (!login_captcha_gate('teacher', $nid, $_POST['captcha'] ?? '')) {
        set_flash_message('error', 'کد امنیتی نادرست است.');
        sdp_fail('captcha', 'teacher');
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
        set_flash_message('error', 'کد ملی یا رمز ورود دبیر نادرست است.');
        sdp_fail('badpass', 'teacher');
    }
}

require_once __DIR__ . '/includes/header.php';
$tab = $_GET['tab'] ?? 'admin';
$captchaQ = generate_captcha();
?>
<script>document.body.classList.add('auth-page');</script>
<div class="auth-wrap my-8">
    <div class="auth-card">
        <div class="auth-brand">
            <div class="auth-logo">🔐</div>
            <div>
                <div class="auth-brand-title">ورود اختصاصی کادر مدرسه</div>
                <div class="auth-brand-sub">پنل مدیریت و پورتال اختصاصی دبیران</div>
            </div>
            <div class="auth-brand-features">
                <div class="auth-feature"><span class="fi">🗂️</span> مدیریت پرونده دانش‌آموزان و کلاس‌ها</div>
                <div class="auth-feature"><span class="fi">📝</span> ثبت نمرات و صدور کارنامه</div>
                <div class="auth-feature"><span class="fi">📈</span> گزارش‌های تحلیلی و آماری</div>
            </div>
        </div>
        <div class="auth-form-side">
        <div class="auth-form-title">ورود کادر مدرسه</div>
        <div class="auth-form-sub">نام کاربری و رمز عبور اختصاصی خود را وارد کنید</div>
        <?php
        $sdpErr = $_GET['err'] ?? '';
        $sdpMsgs = [
            'csrf'    => 'نشست شما منقضی شده بود؛ صفحه نو شد — دوباره وارد شوید.',
            'captcha' => 'پاسخ کد امنیتی نادرست بود؛ حاصل جمع را دوباره وارد کنید.',
            'badpass' => 'نام کاربری یا کلمه عبور نادرست است.',
            'throttle'=> 'تعداد تلاش‌های ناموفق زیاد است؛ ۱۰ دقیقه صبر کنید.',
        ];
        if ($sdpErr && isset($sdpMsgs[$sdpErr])): ?>
        <div style="margin:10px 0;padding:12px 14px;border-radius:10px;border:2px solid #fca5a5;background:#fee2e2;color:#7f1d1d;font-size:13px;line-height:1.9;font-weight:bold;">
            <?php echo $sdpMsgs[$sdpErr]; ?>
        </div>
        <?php endif; ?>

        <div class="auth-tabs" role="tablist">
            <button type="button" onclick="switchAdminTab('adminTab')" id="btnAdminTab" class="auth-tab <?php echo $tab === 'admin' ? 'active' : ''; ?>">⚙️ ورود مدیران</button>
            <button type="button" onclick="switchAdminTab('teacherTab')" id="btnTeacherTab" class="auth-tab <?php echo $tab === 'teacher' ? 'active' : ''; ?>">👨‍🏫 ورود دبیران</button>
        </div>

        <!-- Admin Form -->
        <form id="adminTab" class="auth-panel" method="POST" action="admin-login.php" style="<?php echo $tab === 'admin' ? 'display:block;' : 'display:none;'; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="login_type" value="admin">
            <div class="auth-field">
                <label>نام کاربری مدیریت</label>
                <div class="auth-input-wrap">
                    <span class="auth-icon">👤</span>
                    <input type="text" name="username" class="form-input dir-ltr text-left font-mono js-cap-identity" autocomplete="username" placeholder="username" required autofocus>
                </div>
            </div>
            <div class="auth-field">
                <label>کلمه عبور</label>
                <div class="auth-input-wrap">
                    <span class="auth-icon">🔑</span>
                    <input type="password" name="password" id="aPass" class="form-input dir-ltr text-left font-mono" autocomplete="current-password" placeholder="******" required>
                    <button type="button" class="auth-eye" onclick="togglePass('aPass', this)" tabindex="-1" aria-label="نمایش رمز">👁</button>
                </div>
            </div>
            <?php /* v4.132.0: کد امنیتی فقط بعد از اولین تلاش ناموفقِ همین حساب */ ?>
            <div class="auth-captcha js-captcha-wrap" data-cap-role="admin" hidden>
                <span class="auth-captcha-label">کد امنیتی:</span>
                <span class="auth-captcha-q"><?php echo $captchaQ; ?></span>
                <input type="number" name="captcha" class="form-input js-captcha-input" placeholder="پاسخ" disabled>
            </div>
            <button type="submit" class="btn btn-primary auth-submit">ورود به پنل مدیریت &larr;</button>
        </form>

        <!-- Teacher Form -->
        <form id="teacherTab" class="auth-panel" method="POST" action="admin-login.php?tab=teacher" style="<?php echo $tab === 'teacher' ? 'display:block;' : 'display:none;'; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="login_type" value="teacher">
            <div class="auth-field">
                <label>کد ملی دبیر (نام کاربری)</label>
                <div class="auth-input-wrap">
                    <span class="auth-icon">👤</span>
                    <input type="text" name="national_id" class="form-input dir-ltr text-left font-mono js-cap-identity" inputmode="numeric" autocomplete="username" placeholder="کد ملی ۱۰ رقمی" required>
                </div>
            </div>
            <div class="auth-field">
                <label>کد پرسنلی / کلمه عبور</label>
                <div class="auth-input-wrap">
                    <span class="auth-icon">🔑</span>
                    <input type="password" name="password" id="tPass" class="form-input dir-ltr text-left font-mono" autocomplete="current-password" placeholder="******" required>
                    <button type="button" class="auth-eye" onclick="togglePass('tPass', this)" tabindex="-1" aria-label="نمایش رمز">👁</button>
                </div>
            </div>
            <?php /* v4.132.0: کد امنیتی فقط بعد از اولین تلاش ناموفقِ همین حساب */ ?>
            <div class="auth-captcha js-captcha-wrap" data-cap-role="teacher" hidden>
                <span class="auth-captcha-label">کد امنیتی:</span>
                <span class="auth-captcha-q"><?php echo $captchaQ; ?></span>
                <input type="number" name="captcha" class="form-input js-captcha-input" placeholder="پاسخ" disabled>
            </div>
            <button type="submit" class="btn btn-success auth-submit">ورود به پورتال دبیران &larr;</button>
        </form>

        <div class="auth-foot">
            <a href="index.php?view=login">&rarr; بازگشت به صفحه ورود دانش‌آموزان</a>
            <?php if (PHP_SAPI === 'cli-server' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)): ?>
                <span style="margin:0 8px;color:#cbd5e1">|</span>
                <a href="desk-doctor.php" style="color:#94a3b8">مشکل در ورود؟ (عیب‌یاب)</a>
            <?php endif; ?>
        </div>
        </div><!-- /auth-form-side -->
    </div>
</div>
<script>
function switchAdminTab(tId) {
    ['adminTab','teacherTab'].forEach(function(id){
        document.getElementById(id).style.display = (id === tId) ? 'block' : 'none';
    });
    document.getElementById('btnAdminTab').classList.toggle('active', tId === 'adminTab');
    document.getElementById('btnTeacherTab').classList.toggle('active', tId === 'teacherTab');
    var first = document.querySelector('#' + tId + ' input[type="text"]');
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
<?php require_once __DIR__ . '/includes/footer.php'; ?>
