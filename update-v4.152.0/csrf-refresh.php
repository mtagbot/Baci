<?php
// File: csrf-refresh.php
/**
 * v4.175.0: توکن تازه برای فرم‌های ورود — بدون بار روی سرور.
 *
 * چرا لازم شد: در مرورگر داخلی پیام‌رسان‌ها (دکمهٔ «اپلیکیشن» بله) صفحهٔ ورود
 * گاهی از حافظهٔ پنهان مرورگر (bfcache) یا با نشستی کهنه باز می‌شود و توکن فرم
 * با توکن نشست نمی‌خواند؛ کاربر پیام «اعتبار فرم به پایان رسیده» می‌گرفت و
 * نمی‌دانست چه شده. این نقطهٔ پایانی تنها یک توکن تازه برمی‌گرداند و اسکریپت
 * کوچک صفحهٔ ورود، فیلد پنهان فرم را با آن به‌روز می‌کند.
 *
 * توکن «امضاشده» است (salt + HMAC با راز سرور)، پس حتی اگر کوکی نشست روی
 * درخواست نباشد هم معتبر می‌ماند.
 */
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

/* csrf_rotate توکن تازه می‌سازد و توکن قبلی را در حلقهٔ «اخیر» نگه می‌دارد تا
   تب‌های دیگرِ باز هم معتبر بمانند. */
$token = function_exists('csrf_rotate') ? csrf_rotate() : (function_exists('csrf_token') ? csrf_token() : '');
echo json_encode(['token' => $token], JSON_UNESCAPED_UNICODE);
