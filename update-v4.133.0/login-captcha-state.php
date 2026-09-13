<?php
/**
 * login-captcha-state.php — v4.132.0
 *
 * می‌گوید آیا برای این «نقش + شناسه» باید کد امنیتی نشان داده شود.
 * فرم ورود وقتی کاربر نام کاربری را وارد می‌کند این را صدا می‌زند و
 * کادر کپچا را نشان می‌دهد یا پنهان می‌کند.
 *
 * چرا این نشت اطلاعاتی نیست: پاسخ فقط می‌گوید «این شناسه سابقهٔ ورود
 * ناموفق دارد». چیزی دربارهٔ *وجود داشتن* حساب نمی‌گوید — برای یک
 * شناسهٔ کاملاً ساختگی هم اگر یک بار اشتباه وارد شده باشد true
 * برمی‌گرداند. پس نمی‌شود با آن فهرست کاربران را استخراج کرد.
 *
 * نکتهٔ مهم: نبودِ این فایل یا خطای شبکه نباید ورود را بشکند — سمت
 * کلاینت در صورت خطا کپچا را نشان می‌دهد (fail-safe، نه fail-open).
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header_tiles.php';

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$allowedRoles = ['admin', 'student', 'teacher', 'inquiry'];
$role = $_GET['role'] ?? '';
$id   = $_GET['id'] ?? '';

if (!in_array($role, $allowedRoles, true)) {
    echo json_encode(['need' => true]);   // ورودی نامعتبر → سخت‌گیرانه
    exit;
}

/* شناسهٔ خیلی بلند را نمی‌پذیریم تا کوئری بی‌مورد نزنیم */
if (mb_strlen((string)$id) > 190) {
    echo json_encode(['need' => true]);
    exit;
}

try {
    echo json_encode(['need' => login_needs_captcha($role, $id)]);
} catch (Throwable $e) {
    echo json_encode(['need' => true]);
}
