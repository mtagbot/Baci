<?php
// File: messages-management.php
require_once __DIR__ . '/includes/auth.php';
require_permission('send_sms');
require_once __DIR__ . '/includes/management_hub.php';
require_once __DIR__ . '/includes/header.php';
render_management_hub('messages');
require_once __DIR__ . '/includes/footer.php';
