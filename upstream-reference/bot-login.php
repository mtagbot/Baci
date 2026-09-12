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

if ($row['target_type'] === 'admin') {
    $admin = DB::fetch("SELECT * FROM admins WHERE id=? AND status=1", [$row['target_id']]);
    if ($admin) {
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_username'] = $admin['username'];
        $_SESSION['admin_name'] = $admin['name'];
        $_SESSION['admin_role'] = $admin['role'];
        log_activity($admin['id'], 'ورود یک‌بارمصرف از ربات', 'مدیر از طریق لینک امن ربات وارد شد.');
        redirect('index.php?view=dashboard');
    }
}

if ($row['target_type'] === 'teacher') {
    $t = DB::fetch("SELECT * FROM teachers WHERE id=? AND status=1", [$row['target_id']]);
    if ($t) {
        $_SESSION['teacher_id'] = $t['id'];
        $_SESSION['teacher_name'] = $t['full_name'];
        $_SESSION['teacher_nid'] = $t['national_id'];
        redirect('teacher-panel.php');
    }
}

if ($row['target_type'] === 'student') {
    $s = DB::fetch("SELECT * FROM students WHERE id=? AND status='active'", [$row['target_id']]);
    if ($s) {
        $_SESSION['student_id'] = $s['id'];
        $_SESSION['student_name'] = $s['first_name'] . ' ' . $s['last_name'];
        $_SESSION['student_nid'] = $s['national_id'];
        redirect('student-panel.php');
    }
}
set_flash_message('error', 'حساب مقصد لینک ربات یافت نشد.');
redirect('index.php');
