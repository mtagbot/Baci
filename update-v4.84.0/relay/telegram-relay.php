<?php
// File: telegram-relay.php
/**
 * واسط (رله) API تلگرام — نسخه PHP
 * ───────────────────────────────────
 * اگر به‌جای Cloudflare Workers یک هاست خارجی (خارج از ایران) دارید،
 * فقط همین یک فایل را روی آن هاست آپلود کنید و آدرس آن هاست را در
 * پنل ربات تلگرام سایت، فیلد «آدرس واسط (رله) API تلگرام» وارد کنید.
 *
 * مثال: فایل روی https://example.com/telegram-relay.php است →
 *        در فیلد واسط بنویسید: https://example.com/telegram-relay.php
 *
 * امنیت: این رله فقط مسیرهای /bot<token>/... را عبور می‌دهد و هیچ
 * اطلاعاتی ذخیره نمی‌کند. در صورت تمایل می‌توانید RELAY_SECRET را مقدار
 * دهید تا فقط سایت خودتان بتواند از آن استفاده کند (همان مقدار را باید
 * انتهای آدرس واسط بنویسید: ...telegram-relay.php?k=SECRET).
 */

define('RELAY_SECRET', ''); // اختیاری؛ خالی = بدون قفل

if (RELAY_SECRET !== '' && (($_GET['k'] ?? '') !== RELAY_SECRET)) {
    http_response_code(403);
    exit('forbidden');
}

// مسیر بعد از نام فایل: /telegram-relay.php/bot<token>/<method>
$path = $_SERVER['PATH_INFO'] ?? '';
if ($path === '' && isset($_SERVER['REQUEST_URI'])) {
    $p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $pos = strpos($p, '.php');
    if ($pos !== false) $path = substr($p, $pos + 4);
}
if (strpos($path, '/bot') !== 0) {
    http_response_code(200);
    exit('ok');
}

$url = 'https://api.telegram.org' . $path;
$qs = $_GET; unset($qs['k']);
if ($qs) $url .= '?' . http_build_query($qs);

$ch = curl_init($url);
$headers = [];
foreach (['Content-Type', 'X-Telegram-Bot-Api-Secret-Token'] as $h) {
    $k = 'HTTP_' . strtoupper(str_replace('-', '_', $h));
    if (!empty($_SERVER[$k])) $headers[] = $h . ': ' . $_SERVER[$k];
}
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_SSL_VERIFYPEER => true,
]);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    curl_setopt($ch, CURLOPT_POST, true);
    $isMultipart = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false;
    if ($isMultipart) {
        // php://input is empty for multipart — rebuild the form from $_POST/$_FILES
        $fields = $_POST;
        foreach ($_FILES as $name => $f) {
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $fields[$name] = new CURLFile($f['tmp_name'], $f['type'] ?: 'application/octet-stream', $f['name']);
            }
        }
        // multipart needs its own Content-Type boundary — drop the forwarded one
        $headers = array_values(array_filter($headers, fn($h) => stripos($h, 'Content-Type:') !== 0));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
    }
}
$res  = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/json';
if ($res === false) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error_code' => 502, 'description' => 'relay: ' . curl_error($ch)]);
    exit;
}
http_response_code($code ?: 200);
header('Content-Type: ' . $type);
echo $res;
