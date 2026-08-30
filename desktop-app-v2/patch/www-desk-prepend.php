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

    /* ---- 0) race-proof captcha (desktop only) ----
     * The single-user desktop runs several requests in parallel (favicon,
     * heartbeat, a second tab...). If any of them regenerates the captcha,
     * the answer the user is typing gets silently invalidated. Fix: keep
     * the last few generated answers and accept any of them (they expire
     * after 15 minutes). Defined here so includes/functions.php skips its
     * own versions (both are wrapped in function_exists checks).
     */
    function generate_captcha() {
        $n1 = rand(2, 9);
        $n2 = rand(2, 9);
        $_SESSION['captcha_ans'] = $n1 + $n2; // keep legacy key in sync
        $pool = $_SESSION['captcha_pool'] ?? [];
        $pool[] = ['a' => $n1 + $n2, 't' => time()];
        if (count($pool) > 8) $pool = array_slice($pool, -8);
        $_SESSION['captcha_pool'] = $pool;
        return tr_num("$n1 + $n2 = ?", 'fa');
    }
    function verify_captcha($ans) {
        $given = (int) tr_num((string) $ans, 'en');
        $pool = $_SESSION['captcha_pool'] ?? [];
        if (isset($_SESSION['captcha_ans'])) $pool[] = ['a' => (int) $_SESSION['captcha_ans'], 't' => time()];
        if (!$pool) return true; // nothing generated yet — matches legacy behaviour
        foreach ($pool as $p) {
            if ((int) $p['a'] === $given && time() - (int) $p['t'] < 900) {
                unset($_SESSION['captcha_pool']); // one-shot: no replay
                unset($_SESSION['captcha_ans']);
                return true;
            }
        }
        return false;
    }

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
