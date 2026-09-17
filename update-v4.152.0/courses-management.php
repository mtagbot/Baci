<?php
// File: courses-management.php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/school_roles.php';
ensure_school_roles_schema();
if (!current_teacher_is_executive()) require_permission('manage_classes');
require_once __DIR__ . '/includes/management_hub.php';
require_once __DIR__ . '/includes/header.php';
render_management_hub('courses');
require_once __DIR__ . '/includes/footer.php';
