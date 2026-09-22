<?php
/**
 * Global Config Loader
 */
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

$defaults = require ROOT_PATH . '/config/defaults.php';
$dbConfig = [];

if (file_exists(ROOT_PATH . '/config/database.php')) {
    $dbConfig = require ROOT_PATH . '/config/database.php';
}

return [
    'defaults' => $defaults,
    'db'       => $dbConfig,
    'installed'=> file_exists(ROOT_PATH . '/config/installed.lock'),
];
