<?php
/** Release_V1.0: fail closed until an explicit, successful first installation. */
function release_require_install(): void {
    if (defined('RELEASE_INSTALLER') && RELEASE_INSTALLER === true) return;
    $root = dirname(__DIR__);
    if (is_file($root.'/config/installed.lock') && is_file($root.'/config/database.php')) return;
    if (PHP_SAPI === 'cli') throw new RuntimeException('Release_V1.0 is not installed. Run installer.php first.');
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $file = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? $root.'/index.php');
    $relative = substr($file, strlen(str_replace('\\', '/', $root)));
    $base = $relative !== '' && str_ends_with($script, $relative) ? substr($script, 0, -strlen($relative)) : dirname($script);
    $base = '/'.trim($base, '/.');
    header('Cache-Control: no-store');
    header('Location: '.rtrim($base, '/').'/installer.php', true, 302);
    exit;
}
release_require_install();
