<?php
// File: bot-login.php
/**
 * One-time secure login bridge generated from Bale/Telegram bot menus.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/school_roles.php';
ensure_school_roles_schema();

$token = trim($_GET['token'] ?? '');
$row = $token ? DB::fetch("SELECT * FROM bot_login_tokens WHERE token=?", [$token]) : null;
if (!$row || !empty($row['used_at_jalali']) || (int)$row['expires_at'] < time()) {
    set_flash_message('error', 'لینک ورود ربات نامعتبر یا منقضی شده است.');
    redirect('index.php');
}
DB::execute("UPDATE bot_login_tokens SET used_at_jalali=? WHERE id=?", [jalali_now(), $row['id']]);

// v4.46.0: every login must go through auth_login_as() so the previous
// role is wiped and the session id is regenerated (anti session-fixation).
// Falls back to direct $_SESSION writes on older installs.
if (!function_exists('bot_login_apply_session')) {
    function bot_login_apply_session($role, array $data) {
        if (function_exists('auth_login_as')) {
            auth_login_as($role, $data);
            return;
        }
        if (function_exists('session_regenerate_id')) { @session_regenerate_id(true); }
        foreach ($data as $k => $v) { $_SESSION[$k] = $v; }
    }
}

if ($row['target_type'] === 'admin') {
    $admin = DB::fetch("SELECT * FROM admins WHERE id=? AND status=1", [$row['target_id']]);
    if ($admin) {
        bot_login_apply_session('admin', [
            'admin_id' => $admin['id'],
            'admin_username' => $admin['username'],
            'admin_name' => $admin['name'],
            'admin_role' => $admin['role'],
        ]);
        log_activity($admin['id'], 'ورود یک‌بارمصرف از ربات', 'مدیر از طریق لینک امن ربات وارد شد.');
        redirect('index.php?view=dashboard');
    }
}

if ($row['target_type'] === 'teacher') {
    $t = DB::fetch("SELECT * FROM teachers WHERE id=? AND status=1", [$row['target_id']]);
    if ($t) {
        bot_login_apply_session('teacher', [
            'teacher_id' => $t['id'],
            'teacher_name' => $t['full_name'],
            'teacher_nid' => $t['national_id'],
        ]);
        redirect('teacher-panel.php');
    }
}

if ($row['target_type'] === 'student') {
    $s = DB::fetch("SELECT * FROM students WHERE id=? AND status='active'", [$row['target_id']]);
    if ($s) {
        bot_login_apply_session('student', [
            'student_id' => $s['id'],
            'student_name' => $s['first_name'] . ' ' . $s['last_name'],
            'student_nid' => $s['national_id'],
        ]);
        redirect('student-panel.php');
    }
}
set_flash_message('error', 'حساب مقصد لینک ربات یافت نشد.');
redirect('index.php');
