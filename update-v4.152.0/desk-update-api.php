<?php
/**
 * File: desk-update-api.php — تحویل به‌روزرسانی دسکتاپ به نسخهٔ نصب‌شدهٔ همان مدرسه.
 *
 * روی همان سایتی قرار می‌گیرد که desk-sync-api.php در آن است و با همان کلید
 * هر نصب (config/desk-sync-key.php) احراز هویت می‌کند. کلید در بدنهٔ POST
 * فرستاده می‌شود تا در لاگ آدرس‌ها نیفتد.
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
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') desk_update_api_json(['ok' => false, 'error' => 'POST required'], 405);
$stored = is_file(__DIR__.'/config/desk-sync-key.php') ? require __DIR__.'/config/desk-sync-key.php' : '';
$input = json_decode((string)file_get_contents('php://input'), true);
/* Some proxies/servers hand a JSON POST over as a form field instead of a raw
   body; accept both so the endpoint keeps working behind them. */
if (!is_array($input)) $input = json_decode((string)($_POST['payload'] ?? ''), true);
if (!is_array($input)) $input = is_array($_POST) ? $_POST : [];
$key = (string)($input['key'] ?? '');
$action = (string)($input['action'] ?? '');
if (!is_string($stored) || strlen($stored) < 32 || !hash_equals($stored, $key)) desk_update_api_json(['ok' => false, 'error' => 'invalid key'], 403);

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
