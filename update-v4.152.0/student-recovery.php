<?php
// File: student-recovery.php
require_once __DIR__ . '/includes/auth.php';
require_permission('import_data');
require_once __DIR__ . '/includes/management_hub.php';
require_once __DIR__ . '/includes/header.php';
render_management_hub('recovery');
require_once __DIR__ . '/includes/footer.php';
