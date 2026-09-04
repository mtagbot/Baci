<?php
// File: telegram-poll.php
/**
 * v4.85.0 — Polling receiver for Telegram (for hosts where INBOUND foreign
 * traffic is blocked and Telegram's webhook can never reach the site).
 *
 * Runs from a cron job (every minute). It pulls updates with getUpdates —
 * an OUTBOUND call that goes through the configured relay (telegram_api_base)
 * — and hands each update to the existing telegram-webhook.php engine via an
 * internal HTTP POST to the site's own domain, signed with the same
 * X-Telegram-Bot-Api-Secret-Token the webhook already verifies.
 *
 * Enable it from the Telegram bot admin page («فعال‌سازی دریافت با Cron»);
 * that action deletes the Telegram webhook (polling and webhook are mutually
 * exclusive on Telegram's side) and generates the secret used in ?k=.
 */

require_once __DIR__ . '/includes/bot_helpers.php';

$platform = 'telegram';

// The desktop app must never poll: the site is the single receiver, and two
// concurrent getUpdates consumers would steal each other's updates.
if (PHP_SAPI === 'cli-server') {
    http_response_code(200);
    exit('polling runs on the live site only');
}

$isCli = (PHP_SAPI === 'cli');
$pollSecret = get_setting('telegram_poll_secret', '');
if (!$isCli) {
    if ($pollSecret === '' || !hash_equals($pollSecret, (string)($_GET['k'] ?? ''))) {
        http_response_code(403);
        exit('forbidden');
    }
}

header('Content-Type: text/plain; charset=utf-8');

if (get_setting('telegram_receive_mode', 'webhook') !== 'polling') {
    exit("polling-disabled\nحالت دریافت با Cron فعال نیست. از صفحه «ربات تلگرام» فعالش کنید.");
}
if (get_setting(bot_token_key($platform), '') === '') {
    exit('no-token');
}

// Single-runner lock: overlapping cron hits must not double-process updates.
$lockFile = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'tg-poll-' . md5(__DIR__) . '.lock';
$lock = @fopen($lockFile, 'c');
if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
    exit('busy');
}

$offset = (int)get_setting('telegram_poll_offset', '0');

try {
    $res = bot_api_request($platform, 'getUpdates', ['offset' => $offset, 'limit' => 50, 'timeout' => 0]);
} catch (Exception $e) {
    // 409 = a webhook is still registered; polling requires it removed.
    if (strpos($e->getMessage(), '409') !== false || stripos($e->getMessage(), 'webhook') !== false) {
        try { bot_api_request($platform, 'deleteWebhook', ['drop_pending_updates' => false]); } catch (Exception $e2) {}
        try {
            $res = bot_api_request($platform, 'getUpdates', ['offset' => $offset, 'limit' => 50, 'timeout' => 0]);
        } catch (Exception $e3) {
            exit('api-error: ' . $e3->getMessage());
        }
    } else {
        exit('api-error: ' . $e->getMessage());
    }
}

$updates = $res['result'] ?? [];
if (!$updates) {
    exit('no-updates');
}

// Target = this site's own telegram-webhook.php (same-domain call; allowed
// even on hosts that block foreign traffic). When triggered over HTTP the URL
// is derived from the request and remembered for CLI cron runs.
if ($isCli) {
    $hookUrl = get_setting('telegram_poll_hook_url', '');
} else {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '')), '/');
    if ($base === '.') $base = '';
    $hookUrl = $protocol . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base . '/telegram-webhook.php';
    if (get_setting('telegram_poll_hook_url', '') !== $hookUrl) set_setting('telegram_poll_hook_url', $hookUrl);
}
if ($hookUrl === '') {
    exit("no-hook-url\nیک بار پیمایشگر را از طریق آدرس وب (نه CLI) اجرا کنید تا آدرس وبهوک داخلی ذخیره شود.");
}

$whSecret = get_setting('telegram_webhook_secret', '');
if ($whSecret === '') { $whSecret = bin2hex(random_bytes(24)); set_setting('telegram_webhook_secret', $whSecret); }

@set_time_limit(90);
$deadline = time() + 40;   // stay well under typical cron/PHP limits
$done = 0;

foreach ($updates as $u) {
    $offset = max($offset, (int)($u['update_id'] ?? 0) + 1);
    $ch = curl_init($hookUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json; charset=utf-8',
            'X-Telegram-Bot-Api-Secret-Token: ' . $whSecret,
        ],
        CURLOPT_POSTFIELDS     => json_encode($u, JSON_UNESCAPED_UNICODE),
    ]);
    curl_exec($ch);
    if (curl_errno($ch)) error_log('telegram-poll dispatch failed: ' . curl_error($ch));
    $done++;
    set_setting('telegram_poll_offset', (string)$offset);   // never re-deliver on next run
    if (time() > $deadline) break;
}

echo "processed:{$done} offset:{$offset}";
