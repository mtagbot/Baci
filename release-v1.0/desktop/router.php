<?php
// Loopback desktop server only. Never expose configuration, backups or uploaded scripts.
$raw = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = rawurldecode(is_string($raw) ? $raw : '/');
if (strpos($uri, "\0") !== false || strpos($uri, '\\') !== false || preg_match('~(?:^|/)\.~', $uri) || preg_match('~^/(?:config|includes|sql|vendor|backups)(?:/|$)~i',$uri) || preg_match('~\.(?:sqlite(?:3|-wal|-shm)?|db|sql|ini|log|bak|backup|env|lock|py|md|json)$~i',$uri) || preg_match('~^/uploads/.*\.(?:php\d?|phtml|phar|cgi|pl|py|sh)(?:/|$)~i',$uri)) {
    http_response_code(403); echo 'Forbidden'; return true;
}
$file = __DIR__.$uri;
$installed = is_file(__DIR__.'/config/installed.lock') && is_file(__DIR__.'/config/database.php');
if (!$installed && $uri !== '/installer.php' && (!is_file($file) || strtolower(pathinfo($file, PATHINFO_EXTENSION))==='php')) {
    header('Location: /installer.php',true,302); return true;
}
if ($installed && is_file(__DIR__.'/desk-prepend.php')) require_once __DIR__.'/desk-prepend.php';
if (is_file($file)) return false;
if ($uri === '/' || $uri === '') { require __DIR__.'/index.php'; return true; }
http_response_code(404); echo 'Not Found'; return true;
