<?php
/**
 * SchoolDesk Pro — request prelude (loaded via auto_prepend_file, desktop only).
 *
 * Fixes the "silent login loop" class of failures:
 *  1) If PHP cannot WRITE session files (read-only folder, antivirus,
 *     odd path), every login silently bounces back to the login page —
 *     even the error flash message is lost, because it too lives in the
 *     session. Here we probe the session directory with a real write and
 *     fall back to the system temp dir when it fails.
 *  2) If the embedded browser is not sending cookies back at all, no
 *     session can ever persist. We detect that with a plain test cookie
 *     and show a clear Persian explanation instead of failing silently.
 */
if (PHP_SAPI === 'cli-server') {

    /* ---- 1) session storage must actually be writable ---- */
    $sp = ini_get('session.save_path');
    $ok = false;
    if ($sp && @is_dir($sp)) {
        $probe = rtrim($sp, '/\\') . DIRECTORY_SEPARATOR . '.probe-' . getmypid();
        $ok = @file_put_contents($probe, '1') !== false;
        if ($ok) @unlink($probe);
    }
    if (!$ok) {
        $alt = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'schooldesk-sessions';
        @mkdir($alt, 0777, true);
        $probe = $alt . DIRECTORY_SEPARATOR . '.probe-' . getmypid();
        if (@file_put_contents($probe, '1') !== false) {
            @unlink($probe);
            @ini_set('session.save_path', $alt);
        }
    }

    /* ---- 2) cookie round-trip self test ---- */
    if (!isset($_COOKIE['sdp_ck'])) {
        @setcookie('sdp_ck', '1', [
            'expires'  => time() + 86400 * 365,
            'path'     => '/',
            'samesite' => 'Lax',
        ]);
        // A form POST always follows at least one GET of the same app,
        // so by POST time the test cookie must have come back.
        // desk-doctor.php is exempt: it must work even with broken cookies.
        $sdpUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
            && basename((string)$sdpUri) !== 'desk-doctor.php') {
            http_response_code(200);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
               . '<title>SchoolDesk Pro</title></head>'
               . '<body style="font-family:Tahoma,sans-serif;background:#f6f7fb;margin:0;padding:40px">'
               . '<div style="max-width:560px;margin:0 auto;background:#fff;border:2px solid #ef4444;'
               . 'border-radius:14px;padding:28px;line-height:2">'
               . '<h2 style="margin-top:0;color:#b91c1c">مرورگر کوکی‌ها را ذخیره نمی‌کند</h2>'
               . '<p>به همین دلیل ورود انجام نمی‌شود و بدون پیام به صفحه ورود برمی‌گردید.</p>'
               . '<p><b>راه حل:</b></p><ol>'
               . '<li>برنامه را کامل ببندید و دوباره SchoolDeskPro.exe را اجرا کنید.</li>'
               . '<li>اگر تکرار شد، در مرورگر Chrome یا Edge خودتان ذخیره کوکی‌ها را فعال کنید '
               . '(تنظیمات ← حریم خصوصی ← کوکی‌ها).</li>'
               . '<li>اگر باز هم تکرار شد، پوشه برنامه را در مسیر ساده‌ای مثل '
               . '<code dir="ltr">C:\SchoolDeskPro</code> کپی کنید و از آنجا اجرا کنید.</li>'
               . '</ol>'
               . '<p><a href="' . htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/', ENT_QUOTES) . '">'
               . 'بازگشت و تلاش دوباره</a></p>'
               . '</div></body></html>';
            exit;
        }
    }
}
