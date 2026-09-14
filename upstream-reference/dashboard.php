<?php
/**
 * Dashboard Router (dashboard.php)
 */
require_once __DIR__ . '/includes/auth.php';

if (is_admin_logged_in()) {
    redirect('index.php?view=dashboard');
} elseif (is_student_logged_in()) {
    redirect('student-panel.php');
} else {
    redirect('index.php');
}
