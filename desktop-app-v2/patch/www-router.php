<?php
// File: router.php — PHP built-in server router for SchoolDesk Pro.
// Serves static assets directly and routes everything else like Apache would.
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . $uri;

// block dotfiles and traversal
if (strpos($uri, '..') !== false || preg_match('#/\.#', $uri)) {
    http_response_code(404);
    exit;
}

// existing static file -> let the built-in server handle it (mime types etc.)
if ($uri !== '/' && file_exists($file) && !is_dir($file) && !preg_match('/\.php$/i', $uri)) {
    return false;
}

// existing php file -> run it
if (preg_match('/\.php$/i', $uri) && file_exists($file)) {
    chdir(dirname($file));
    $_SERVER['SCRIPT_NAME'] = $uri;
    $_SERVER['SCRIPT_FILENAME'] = $file;
    $_SERVER['PHP_SELF'] = $uri;
    require $file;
    return true;
}

// directory with index.php
if (is_dir($file) && file_exists(rtrim($file, '/') . '/index.php')) {
    $idx = rtrim($file, '/') . '/index.php';
    chdir(dirname($idx));
    $_SERVER['SCRIPT_NAME'] = rtrim($uri, '/') . '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $idx;
    $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
    require $idx;
    return true;
}

// default -> site root index.php
chdir(__DIR__);
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
require __DIR__ . '/index.php';
