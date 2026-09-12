<?php
/**
 * Authentication & Authorization Helper
 */

require_once __DIR__ . '/functions.php';
try_remember_login();

if (!function_exists('is_admin_logged_in')) {
    function is_admin_logged_in() {
        return isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
    }
}

if (!function_exists('is_student_logged_in')) {
    function is_student_logged_in() {
        return isset($_SESSION['student_id']) && !empty($_SESSION['student_id']);
    }
}

if (!function_exists('is_teacher_logged_in')) {
    function is_teacher_logged_in() {
        return isset($_SESSION['teacher_id']) && !empty($_SESSION['teacher_id']);
    }
}

if (!function_exists('require_admin')) {
    function require_admin() {
        if (!is_admin_logged_in()) {
            redirect('index.php?view=login');
        }
    }
}

if (!function_exists('require_student')) {
    function require_student() {
        if (!is_student_logged_in()) {
            redirect('index.php?view=login');
        }
    }
}

if (!function_exists('current_admin')) {
    function current_admin() {
        if (is_admin_logged_in()) {
            return DB::fetch("SELECT * FROM admins WHERE id = ?", [$_SESSION['admin_id']]);
        }
        return null;
    }
}

if (!function_exists('current_student')) {
    function current_student() {
        if (is_student_logged_in()) {
            return DB::fetch("SELECT * FROM students WHERE id = ?", [$_SESSION['student_id']]);
        }
        return null;
    }
}

if (!function_exists('has_permission')) {
    function has_permission($permissionKey) {
        if (!is_admin_logged_in()) return false;
        if (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'super_admin') {
            return true;
        }
        $admin = current_admin();
        if (!$admin) return false;
        if ($admin['role'] === 'super_admin') return true;
        
        $perms = json_decode($admin['permissions'] ?? '[]', true) ?: [];
        if (in_array('all', $perms) || in_array($permissionKey, $perms)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('require_permission')) {
    function require_permission($permissionKey) {
        require_admin();
        if (!has_permission($permissionKey)) {
            set_flash_message('error', 'شما مجوز دسترسی به این بخش را ندارید.');
            redirect('index.php?view=dashboard');
        }
    }
}
