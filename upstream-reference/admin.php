<?php
/**
 * Admin Panel Alias (admin.php)
 */
require_once __DIR__ . '/includes/auth.php';
require_admin();
redirect('index.php?view=dashboard');
