<?php
/**
 * includes/session_tracker.php — v4.68.0
 * ردیابی نشست‌های فعال هر کاربر (مدیر / دبیر / دانش‌آموز) روی همه دستگاه‌ها.
 * هر بارگذاری صفحه، رکورد نشست جاری upsert می‌شود (نام دستگاه، سیستم‌عامل،
 * مرورگر، IP و آخرین فعالیت). صفحه my-sessions.php لیست را نشان می‌دهد و
 * امکان بستن نشست هر دستگاه را می‌دهد: session_id هدف در بلک‌لیست جدول
 * علامت می‌خورد و اولین درخواست بعدی آن دستگاه با session_destroy خارج می‌شود.
 */

if (!function_exists('ensure_user_sessions_schema')) {
    function ensure_user_sessions_schema() {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            DB::execute("CREATE TABLE IF NOT EXISTS user_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session_id VARCHAR(128) NOT NULL,
                user_type VARCHAR(20) NOT NULL,
                user_id INT NOT NULL,
                user_name VARCHAR(190) DEFAULT NULL,
                device_label VARCHAR(190) DEFAULT NULL,
                os_name VARCHAR(80) DEFAULT NULL,
                browser_name VARCHAR(80) DEFAULT NULL,
                ip_address VARCHAR(64) DEFAULT NULL,
                user_agent TEXT,
                is_revoked TINYINT(1) NOT NULL DEFAULT 0,
                created_at_jalali VARCHAR(30) DEFAULT NULL,
                last_seen_at INT NOT NULL DEFAULT 0,
                last_seen_jalali VARCHAR(40) DEFAULT NULL,
                UNIQUE KEY uq_session (session_id),
                KEY idx_user (user_type, user_id),
                KEY idx_seen (last_seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) { error_log('user_sessions schema: ' . $e->getMessage()); }
    }
}

if (!function_exists('st_parse_user_agent')) {
    /** تشخیص سیستم‌عامل، مرورگر و نوع دستگاه از User-Agent (بدون کتابخانه خارجی) */
    function st_parse_user_agent($ua) {
        $ua = (string)$ua;
        // OS
        $os = 'نامشخص';
        if (preg_match('/Windows NT 11/i', $ua)) $os = 'Windows 11';
        elseif (preg_match('/Windows NT 10/i', $ua)) $os = 'Windows 10/11';
        elseif (preg_match('/Windows NT 6\.3/i', $ua)) $os = 'Windows 8.1';
        elseif (preg_match('/Windows NT 6\.1/i', $ua)) $os = 'Windows 7';
        elseif (preg_match('/Windows/i', $ua)) $os = 'Windows';
        elseif (preg_match('/Android\s*([\d.]+)?/i', $ua, $m)) $os = 'Android' . (isset($m[1]) && $m[1] !== '' ? ' ' . $m[1] : '');
        elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) $os = 'iOS';
        elseif (preg_match('/Mac OS X/i', $ua)) $os = 'macOS';
        elseif (preg_match('/Linux/i', $ua)) $os = 'Linux';
        // Browser (order matters)
        $br = 'نامشخص';
        if (preg_match('/Edg(?:e|A|iOS)?\/([\d.]+)/i', $ua, $m)) $br = 'Edge ' . strtok($m[1], '.');
        elseif (preg_match('/OPR\/([\d.]+)/i', $ua, $m)) $br = 'Opera ' . strtok($m[1], '.');
        elseif (preg_match('/SamsungBrowser\/([\d.]+)/i', $ua, $m)) $br = 'Samsung Internet ' . strtok($m[1], '.');
        elseif (preg_match('/Firefox\/([\d.]+)/i', $ua, $m)) $br = 'Firefox ' . strtok($m[1], '.');
        elseif (preg_match('/CriOS\/([\d.]+)/i', $ua, $m)) $br = 'Chrome iOS ' . strtok($m[1], '.');
        elseif (preg_match('/Chrome\/([\d.]+)/i', $ua, $m)) $br = 'Chrome ' . strtok($m[1], '.');
        elseif (preg_match('/Version\/([\d.]+).*Safari/i', $ua, $m)) $br = 'Safari ' . strtok($m[1], '.');
        elseif (preg_match('/Safari/i', $ua)) $br = 'Safari';
        // Device label
        $dev = 'رایانه دسکتاپ';
        if (preg_match('/iPad/i', $ua)) $dev = 'تبلت iPad';
        elseif (preg_match('/iPhone/i', $ua)) $dev = 'گوشی iPhone';
        elseif (preg_match('/Android/i', $ua)) {
            $dev = preg_match('/Mobile/i', $ua) ? 'گوشی اندروید' : 'تبلت اندروید';
            if (preg_match('/;\s*([^;)]{2,40})\s+Build\//i', $ua, $m)) $dev .= ' (' . trim($m[1]) . ')';
        } elseif (preg_match('/Mobile/i', $ua)) $dev = 'موبایل';
        elseif (preg_match('/Macintosh/i', $ua)) $dev = 'رایانه Mac';
        elseif (preg_match('/Windows/i', $ua)) $dev = 'رایانه ویندوزی';
        elseif (preg_match('/Linux/i', $ua)) $dev = 'رایانه لینوکسی';
        return ['os' => $os, 'browser' => $br, 'device' => $dev];
    }
}

if (!function_exists('st_current_user')) {
    /** [type, id, name] کاربر جاری یا null */
    function st_current_user() {
        if (function_exists('is_admin_logged_in') && is_admin_logged_in()) {
            return ['admin', (int)($_SESSION['admin_id'] ?? 0), (string)($_SESSION['admin_name'] ?? 'مدیر')];
        }
        if (function_exists('is_teacher_logged_in') && is_teacher_logged_in()) {
            return ['teacher', (int)($_SESSION['teacher_id'] ?? 0), (string)($_SESSION['teacher_name'] ?? 'دبیر')];
        }
        if (function_exists('is_student_logged_in') && is_student_logged_in()) {
            return ['student', (int)($_SESSION['student_id'] ?? 0), (string)($_SESSION['student_name'] ?? 'دانش‌آموز')];
        }
        return null;
    }
}

if (!function_exists('track_user_session')) {
    /**
     * در هر بارگذاری صفحه صدا زده می‌شود:
     * 1) اگر نشست جاری revoke شده باشد → خروج اجباری همین دستگاه.
     * 2) در غیر این صورت رکورد نشست upsert می‌شود.
     */
    function track_user_session() {
        $u = st_current_user();
        if (!$u) return;
        list($type, $uid, $uname) = $u;
        if ($uid <= 0) return;
        ensure_user_sessions_schema();
        $sid = session_id();
        if ($sid === '') return;
        try {
            $row = DB::fetch("SELECT id, is_revoked, last_seen_at FROM user_sessions WHERE session_id = ?", [$sid]);
            if ($row && (int)$row['is_revoked'] === 1) {
                // این دستگاه از صفحه «نشست‌های فعال» خارج شده — جلسه را ببند
                DB::execute("DELETE FROM user_sessions WHERE session_id = ?", [$sid]);
                $_SESSION = [];
                if (!headers_sent()) {
                    if (ini_get('session.use_cookies')) {
                        $p = session_get_cookie_params();
                        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
                    }
                }
                @session_destroy();
                if (!headers_sent()) { header('Location: index.php'); }
                exit;
            }
            $now = time();
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
            if (strpos($ip, ',') !== false) $ip = trim(strtok($ip, ','));
            $nowJ = function_exists('jdate') ? jdate('Y/m/d H:i') : date('Y/m/d H:i');
            if ($row) {
                // برای کاهش بار DB فقط اگر ۶۰ ثانیه گذشته آپدیت کن
                if ($now - (int)$row['last_seen_at'] >= 60) {
                    DB::execute("UPDATE user_sessions SET last_seen_at=?, last_seen_jalali=?, ip_address=? WHERE session_id=?",
                        [$now, $nowJ, substr($ip, 0, 64), $sid]);
                }
            } else {
                $info = st_parse_user_agent($ua);
                DB::execute("INSERT INTO user_sessions (session_id, user_type, user_id, user_name, device_label, os_name, browser_name, ip_address, user_agent, created_at_jalali, last_seen_at, last_seen_jalali) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                    [$sid, $type, $uid, $uname, $info['device'], $info['os'], $info['browser'], substr($ip, 0, 64), substr($ua, 0, 2000), $nowJ, $now, $nowJ]);
                // نظافت: نشست‌های بی‌تحرک بیش از ۶۰ روز
                try { DB::execute("DELETE FROM user_sessions WHERE last_seen_at < ?", [$now - 60 * 86400]); } catch (Throwable $e) {}
            }
        } catch (Throwable $e) { error_log('track_user_session: ' . $e->getMessage()); }
    }
}
