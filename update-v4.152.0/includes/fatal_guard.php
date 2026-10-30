<?php
/**
 * includes/fatal_guard.php — خطای کشنده، خوانا و ثبت‌شده (V4.209.1)
 * ---------------------------------------------------------------------------
 * چرا؟ روی بعضی هاست‌ها (مثلاً cPanel با نسخهٔ تازهٔ PHP یا بدون افزونهٔ
 * pdo_mysql) خطای PHP به‌جای پیام، یک صفحهٔ سفید یا «HTTP ERROR 500» خالی
 * می‌شود؛ کاربر هیچ سرنخی ندارد و پشتیبانی هم باید حدس بزند.
 *
 * این فایل دو کار می‌کند:
 *   ۱) هر خطای کشنده را در `config/php-error.log` ثبت می‌کند (تاریخ، پیام،
 *      فایل، خط، آدرس درخواست) — بدون نیاز به دسترسی به error_log هاست.
 *   ۲) اگر PHP خودش خطا را نشان نمی‌دهد، یک صفحهٔ کوتاه فارسی با متن خطا
 *      چاپ می‌کند تا کاربر بتواند همان یک خط را برای پشتیبانی بفرستد.
 *
 * عمداً هیچ چیز دیگری را تغییر نمی‌دهد: نه output buffering را دست می‌زند،
 * نه روی خطاهای غیرکشنده (warning/deprecated) کاری می‌کند، و اگر خروجی
 * صفحه شروع شده باشد فقط ثبت می‌کند و دخالت نمی‌کند.
 */

if (!function_exists('sdp_fatal_guard')) {
    function sdp_fatal_guard() {
        $err = error_get_last();
        if (!is_array($err) || empty($err['type'])) return;
        $fatalTypes = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
        if (!($err['type'] & $fatalTypes)) return;

        $root = dirname(__DIR__);
        $logDir = is_dir($root . '/config') ? $root . '/config' : $root;
        $logFile = $logDir . '/php-error.log';

        $message = (string)($err['message'] ?? '');
        $file    = (string)($err['file'] ?? '');
        $line    = (int)($err['line'] ?? 0);
        $uri     = (string)($_SERVER['REQUEST_URI'] ?? '');
        $php     = defined('PHP_VERSION') ? PHP_VERSION : (function_exists('phpversion') ? phpversion() : '');

        /* ثبت: هر خط یک خط؛ فایل بیش از ۵۱۲ کیلوبایت نشود. */
        $entry = gmdate('c') . ' | php ' . $php . ' | ' . $message
               . ' | ' . $file . ':' . $line . ' | ' . $uri . "\n";
        $ok = @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
        if ($ok !== false && @filesize($logFile) > 524288) {
            @file_put_contents($logFile, gmdate('c') . " | log rotated\n");
        }

        /* اگر PHP خودش خطا را نشان می‌دهد یا خروجی شروع شده، دخالت نکن. */
        $display = strtolower((string)ini_get('display_errors'));
        if (!headers_sent() && !in_array($display, ['1', 'on', 'true', 'yes'], true)) {
            @http_response_code(500);
            @header('Content-Type: text/html; charset=utf-8');
            @header('Cache-Control: no-store');
            $short = htmlspecialchars(mb_substr($message, 0, 300), ENT_QUOTES, 'UTF-8');
            $where = htmlspecialchars(basename($file) . ':' . $line, ENT_QUOTES, 'UTF-8');
            echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
               . '<meta name="viewport" content="width=device-width,initial-scale=1">'
               . '<title>خطای داخلی</title></head>'
               . '<body style="font-family:Tahoma,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:40px 16px">'
               . '<div style="max-width:640px;margin:auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:24px">'
               . '<h1 style="font-size:18px;margin:0 0 12px">خطای داخلی رخ داد</h1>'
               . '<p style="font-size:13px;line-height:2;margin:0 0 8px">این پیام برای تشخیص سریع است؛ همین یک خط را برای پشتیبانی بفرستید:</p>'
               . '<pre style="direction:ltr;text-align:left;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:10px;font-size:12px;overflow:auto">'
               . $where . "\n" . $short . '</pre>'
               . '<p style="font-size:12px;color:#64748b;line-height:2;margin:12px 0 0">'
               . 'متن کامل در فایل <code>config/php-error.log</code> ثبت شد. '
               . 'اگر تازه به‌روزرسانی کرده‌اید، نسخهٔ PHP هاست را روی همان نسخهٔ قبلی (مثلاً ۸.۳) بگذارید و '
               . 'دوباره تلاش کنید.</p>'
               . '</div></body></html>';
        }
    }
    register_shutdown_function('sdp_fatal_guard');
}
