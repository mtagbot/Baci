<?php
/**
 * File: desk-update-api.php — تحویل به‌روزرسانی دسکتاپ به نسخهٔ نصب‌شدهٔ همان مدرسه.
 *
 * روی همان سایتی قرار می‌گیرد که desk-sync-api.php در آن است و با همان کلید
 * هر نصب احراز هویت می‌کند (v2.107.0: هم config/desk-sync-key.php و هم
 * کلید داخل desk-sync-api.php پذیرفته می‌شود — دقیقاً همان‌هایی که همگام‌سازی
 * قبول می‌کند). کلید در بدنهٔ POST فرستاده می‌شود تا در لاگ آدرس‌ها نیفتد.
 *
 *   {"action":"latest","key":"..."}                → آخرین بستهٔ منتشرشده
 *   {"action":"download","key":"...","id":"..."}   → خود فایل ZIP
 */
require_once __DIR__.'/config/config.php';
require_once __DIR__.'/includes/desk_updates_store.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function desk_update_api_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' && empty($_GET['sdp_z']) && empty($_GET['payload'])) desk_update_api_json(['ok' => false, 'error' => 'POST required'], 405);
function desk_update_unpack_token(string $token): ?array {
    $token = trim($token);
    if (strlen($token) < 4) return null;
    $prefix = substr($token, 0, 2);
    $b64 = strtr(substr($token, 2), '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad > 0) $b64 .= str_repeat('=', 4 - $pad);
    $bin = base64_decode($b64, true);
    if (!is_string($bin) || $bin === '') return null;
    $json = $prefix === 'z1' ? (function_exists('gzinflate') ? @gzinflate($bin) : false) : ($prefix === 'b1' ? $bin : false);
    if (!is_string($json) || $json === '') return null;
    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : null;
}
/**
 * v2.107.0: this endpoint must accept EXACTLY the key the sync endpoint
 * accepts. In the field the sync key lives in one of two places:
 *   1) config/desk-sync-key.php — site installs (release installer) and
 *      every pre-release install that created the private key file.
 *   2) the literal DESK_SYNC_KEY inside desk-sync-api.php — desktop-paired
 *      installs, where the pairing step writes the SDP-… key into that file
 *      and into the desktop settings table (see desktop-app-v2/BUILD-NOTES).
 * class-exam-sync-api.php inherits one of the two, so it needs no extra source.
 * Accepting only source 1 made every device that synced perfectly report
 * «invalid key» in its update banner — sync worked, updates never did.
 */
function desk_update_api_keys(): array {
    $keys = [];
    $private = __DIR__.'/config/desk-sync-key.php';
    if (is_file($private)) { $v = require $private; if (is_string($v) && strlen($v) >= 16 && strpos($v, 'CHANGE-ME') === false) $keys[] = $v; }
    $file = __DIR__.'/desk-sync-api.php';
    if (is_file($file)) {
        $src = (string)@file_get_contents($file, false, null, 0, 131072);
        if (preg_match("~define\\s*\\(\\s*['\"]DESK_SYNC_KEY['\"]\\s*,\\s*'((?:[^'\\\\]|\\\\.){16,200})'~", $src, $m)) {
            $keys[] = str_replace(["\\\\", "\\'"], ["\\", "'"], $m[1]);
        }
    }
    return array_values(array_unique(array_filter($keys, function ($k) { return is_string($k) && strlen($k) >= 16 && strpos($k, 'CHANGE-ME') === false; })));
}
$rawBody = (string)file_get_contents('php://input');
$input = null;
$sdpZ = $_POST['sdp_z'] ?? $_GET['sdp_z'] ?? null;
if (!is_string($sdpZ) && $rawBody !== '') {
    $formZ = [];
    parse_str($rawBody, $formZ);
    if (isset($formZ['sdp_z']) && is_string($formZ['sdp_z'])) $sdpZ = $formZ['sdp_z'];
}
if (is_string($sdpZ) && $sdpZ !== '') $input = desk_update_unpack_token($sdpZ);
if (!is_array($input)) $input = json_decode($rawBody, true);
/* Some proxies/servers hand a JSON POST over as a form field instead of a raw
   body; accept both so the endpoint keeps working behind them. */
if (!is_array($input)) {
    $formPayload = $_POST['payload'] ?? $_GET['payload'] ?? null;
    if (!is_string($formPayload) && $rawBody !== '') {
        $form = [];
        parse_str($rawBody, $form);
        if (isset($form['payload']) && is_string($form['payload'])) $formPayload = $form['payload'];
    }
    if (is_string($formPayload) && $formPayload !== '') $input = json_decode($formPayload, true);
}
if (!is_array($input)) $input = is_array($_POST) && $_POST ? $_POST : [];
$key = (string)($input['key'] ?? '');
$action = (string)($input['action'] ?? '');
$keys = desk_update_api_keys();
if (!$keys) desk_update_api_json(['ok' => false, 'code' => 'key_missing',
    'error' => 'کلید همگام‌سازی روی این سایت تنظیم نشده است؛ مقدار config/desk-sync-key.php را تنظیم کنید.'], 403);
$authorized = false;
foreach ($keys as $candidate) { if (hash_equals($candidate, $key)) { $authorized = true; break; } }
/* No key material is ever echoed back, only a precise, actionable diagnosis. */
if (!$authorized) desk_update_api_json(['ok' => false, 'code' => 'key_mismatch',
    'error' => 'کلید به‌روزرسانی با کلید همگام‌سازی سایت یکسان نیست؛ کلید را از صفحهٔ «اتصال دسکتاپ به این مدرسه» بردارید و در صفحهٔ «همگام‌سازی» دستگاه وارد کنید.'], 403);

$updates = desk_updates_index();
if ($action === 'latest') {
    $latest = desk_updates_latest($updates);
    if (!$latest) desk_update_api_json(['ok' => true, 'update' => null]);
    desk_update_api_json(['ok' => true, 'update' => [
        'id' => (string)$latest['id'], 'version' => (string)($latest['version'] ?? ''),
        'notes' => (string)($latest['notes'] ?? ''), 'created' => (string)($latest['created'] ?? ''),
        'bytes' => (int)($latest['bytes'] ?? 0), 'sha256' => (string)($latest['sha256'] ?? ''),
    ]]);
}
if ($action === 'download') {
    $id = (string)($input['id'] ?? '');
    $entry = desk_updates_id_ok($id) ? desk_updates_find($updates, $id) : null;
    if (!$entry) desk_update_api_json(['ok' => false, 'error' => 'این بسته منتشر نشده است.'], 404);
    $path = desk_updates_path($entry);
    if (!is_file($path)) desk_update_api_json(['ok' => false, 'error' => 'فایل بسته روی سرور پیدا نشد.'], 404);
    $sha = hash_file('sha256', $path);
    if (!is_string($sha) || !hash_equals(strtolower((string)$entry['sha256']), $sha)) desk_update_api_json(['ok' => false, 'error' => 'چک‌سام فایل ذخیره‌شده معتبر نیست.'], 500);
    header('Content-Type: application/zip');
    header('Content-Length: '.(string)filesize($path));
    header('X-Desk-Sha256: '.$sha);
    header('Content-Disposition: attachment; filename="'.basename((string)$entry['file']).'"');
    readfile($path);
    exit;
}
desk_update_api_json(['ok' => false, 'error' => 'unknown action'], 400);
