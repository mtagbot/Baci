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

    /* ---- 1b) v2.11.0: background sync daemon ----
     * All sync network I/O runs in a separate hidden php.exe process, so
     * page requests NEVER wait behind a network timeout (this was the
     * "app is slow without internet" bug). Pages only touch a status file.
     * Cost per request here: one filemtime check.
     */
    $sdpData = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    @touch($sdpData . DIRECTORY_SEPARATOR . 'app-alive.txt');
    $sdpHb = $sdpData . DIRECTORY_SEPARATOR . 'sync-heartbeat.json';
    if (!is_file($sdpHb) || time() - (int)@filemtime($sdpHb) > 30) {
        /* daemon not running (first start, or it exited/crashed) → spawn it.
           A stale-start guard avoids double-spawn from parallel requests. */
        $sdpSpawn = $sdpData . DIRECTORY_SEPARATOR . 'sync-daemon.start';
        if (!is_file($sdpSpawn) || time() - (int)@filemtime($sdpSpawn) > 30) {
            @touch($sdpSpawn);
            $sdpPhp    = PHP_BINARY;                    // the bundled php.exe
            $sdpPhpDir = dirname($sdpPhp);
            $sdpScript = __DIR__ . DIRECTORY_SEPARATOR . 'desk-sync-daemon.php';
            // pass ini/ext explicitly — the daemon must load the same
            // extensions (pdo_sqlite, curl, mbstring...) as the launcher does
            $sdpArgs = '';
            $sdpIni  = php_ini_loaded_file();           // exactly what THIS process loaded
            if (!$sdpIni && is_file($sdpPhpDir . DIRECTORY_SEPARATOR . 'php.ini'))
                $sdpIni = $sdpPhpDir . DIRECTORY_SEPARATOR . 'php.ini';
            if ($sdpIni) $sdpArgs .= ' -c ' . escapeshellarg($sdpIni);
            $sdpExt = (string) ini_get('extension_dir'); // inherit the live extension dir
            if ($sdpExt === '' && is_dir($sdpPhpDir . DIRECTORY_SEPARATOR . 'ext'))
                $sdpExt = $sdpPhpDir . DIRECTORY_SEPARATOR . 'ext';
            if ($sdpExt !== '') $sdpArgs .= ' -d extension_dir=' . escapeshellarg($sdpExt);
            $sdpArgs .= ' -d error_log=' . escapeshellarg($sdpData . DIRECTORY_SEPARATOR . 'php-error.log');
            if (is_file($sdpPhpDir . DIRECTORY_SEPARATOR . 'cacert.pem')) {
                $sdpArgs .= ' -d curl.cainfo=' . escapeshellarg($sdpPhpDir . DIRECTORY_SEPARATOR . 'cacert.pem')
                          . ' -d openssl.cafile=' . escapeshellarg($sdpPhpDir . DIRECTORY_SEPARATOR . 'cacert.pem');
            }
            if (DIRECTORY_SEPARATOR === '\\') {
                // Windows: start detached + hidden (popen returns immediately)
                @pclose(@popen('start /B "" ' . escapeshellarg($sdpPhp) . $sdpArgs . ' ' . escapeshellarg($sdpScript), 'r'));
            } else {
                @exec(escapeshellarg($sdpPhp) . $sdpArgs . ' ' . escapeshellarg($sdpScript) . ' > /dev/null 2>&1 &');
            }
        }
    }

    /* ---- 1c) v2.12.0: sync follows EVERY change immediately ----
     * Any request that can modify data (POSTs = saves, edits, scans...)
     * signals the sync daemon at the END of the request; the daemon wakes
     * up within a second and pushes the change to the site right away. */
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        register_shutdown_function(function () use ($sdpData) {
            @touch($sdpData . DIRECTORY_SEPARATOR . 'sync-kick.txt');
        });
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
        // Exempt endpoints that do NOT rely on cookies at all:
        //  - desk-doctor.php   (must work even with broken cookies)
        //  - attendance-scan-api.php (QR kiosk posts with a key, no session —
        //    v2.6.0 fix: the cookie test used to reply with an HTML error page,
        //    which the scanner showed as «پاسخ سرور قابل خواندن نیست»)
        //  - desk-sync-api.php / bale-webhook.php / telegram-webhook.php (keyed/webhook)
        $sdpUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $sdpNoCookieOk = ['desk-doctor.php', 'attendance-scan-api.php', 'desk-sync-api.php', 'bale-webhook.php', 'telegram-webhook.php'];
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
            && !in_array(basename((string)$sdpUri), $sdpNoCookieOk, true)) {
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
