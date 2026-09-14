<?php
/**
 * Admin Activity Logger
 */

if (!function_exists('log_activity')) {
    function log_activity($admin_id, $action, $description = '') {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $admin_username = 'system';
            if ($admin_id) {
                $admin = DB::fetch("SELECT username FROM admins WHERE id = ?", [$admin_id]);
                if ($admin) {
                    $admin_username = $admin['username'];
                }
            } else {
                if (isset($_SESSION['admin_username'])) {
                    $admin_username = $_SESSION['admin_username'];
                }
            }
            DB::execute(
                "INSERT INTO activity_logs (admin_id, admin_username, action, description, ip_address, created_at) VALUES (?, ?, ?, ?, ?, NOW())",
                [$admin_id ?: null, $admin_username, $action, $description, $ip]
            );
        } catch (Exception $e) {
            // Silently ignore logging errors if DB unavailable
        }
    }
}
