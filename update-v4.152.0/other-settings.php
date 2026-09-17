<?php
// File: other-settings.php
require_once __DIR__ . '/includes/auth.php';
require_admin();
require_once __DIR__ . '/includes/management_hub.php';
require_once __DIR__ . '/includes/header.php';
render_management_hub('settings');
require_once __DIR__ . '/includes/footer.php';
