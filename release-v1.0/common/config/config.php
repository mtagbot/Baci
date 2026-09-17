<?php
require_once dirname(__DIR__).'/includes/install_guard.php';
if (!defined('APP_NAME')) define('APP_NAME', 'سامانه مدیریت مدرسه');
if (!defined('APP_VERSION')) define('APP_VERSION', '4.152.0');
if (!defined('APP_RELEASE')) define('APP_RELEASE', 'Release_V1.0');
if (!defined('DB_TYPE')) define('DB_TYPE', (require __DIR__.'/release.php')['distribution'] === 'desktop' ? 'sqlite' : 'mysql');
if (!defined('TIMEZONE')) define('TIMEZONE', 'Asia/Tehran');
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 86400);
if (!defined('UPLOAD_DIR')) define('UPLOAD_DIR', dirname(__DIR__).'/uploads/');
if (!defined('MAX_UPLOAD_SIZE')) define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024);
if (!defined('ITEMS_PER_PAGE')) define('ITEMS_PER_PAGE', 25);
date_default_timezone_set(TIMEZONE);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__));
$defaults = require __DIR__.'/defaults.php';
if (is_file(__DIR__.'/database.php')) $dbConfig = require __DIR__.'/database.php';
return ['defaults'=>$defaults, 'db'=>$dbConfig??[], 'installed'=>is_file(__DIR__.'/installed.lock')];
