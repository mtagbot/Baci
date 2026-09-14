<?php
/**
 * Logout Handler (logout.php)
 */
require_once __DIR__ . '/includes/functions.php';
clear_remember_login();
session_destroy();
redirect('index.php');
