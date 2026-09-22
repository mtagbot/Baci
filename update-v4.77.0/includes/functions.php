<?php
// File: includes/functions.php
/**
 * Core Helper Functions
 */
if (!ob_get_level()) {
    ob_start();
}

if (session_status() === PHP_SESSION_NONE) {
    // Keep authenticated users signed in until they explicitly click logout.
    // The cookie is persistent and renewed on each request; logout.php/index?action=logout still destroys it.
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $sessionLifetime = 60 * 60 * 24 * 30; // 1 month
    @ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    @ini_set('session.cookie_lifetime', (string)$sessionLifetime);
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    if (!headers_sent() && session_id()) {
        setcookie(session_name(), session_id(), [
            'expires' => time() + $sessionLifetime,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

require_once __DIR__ . '/jdf.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/academic_year_helpers.php';

if (!function_exists('clean')) {
    function clean($data) {
        if (is_array($data)) {
            return array_map('clean', $data);
        }
        if ($data === null) {
            $data = '';
        }
        return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('get_setting')) {
    /**
     * v4.31.0: Per-request settings cache.
     * Loads the whole (small) settings table ONCE per request instead of
     * one query per key — headers alone used to fire 15+ queries per page.
     */
    function get_setting($key, $default = '') {
        if (empty($GLOBALS['__settings_cache_loaded'])) {
            try {
                $rows = DB::fetchAll("SELECT key_name, key_value FROM settings");
                $GLOBALS['__settings_cache'] = [];
                foreach ($rows as $row) {
                    $GLOBALS['__settings_cache'][$row['key_name']] = $row['key_value'];
                }
                $GLOBALS['__settings_cache_loaded'] = true;
            } catch (Exception $e) {
                // Bulk load failed (e.g. before install) — fall back to the old per-key query.
                try {
                    $res = DB::fetch("SELECT key_value FROM settings WHERE key_name = ?", [$key]);
                    return $res ? $res['key_value'] : $default;
                } catch (Exception $e2) {
                    return $default;
                }
            }
        }
        return array_key_exists($key, $GLOBALS['__settings_cache']) ? $GLOBALS['__settings_cache'][$key] : $default;
    }
}

if (!function_exists('set_setting')) {
    function set_setting($key, $value) {
        try {
            $exists = DB::fetch("SELECT key_name FROM settings WHERE key_name = ?", [$key]);
            if ($exists) {
                DB::execute("UPDATE settings SET key_value = ? WHERE key_name = ?", [$value, $key]);
            } else {
                DB::execute("INSERT INTO settings (key_name, key_value) VALUES (?, ?)", [$key, $value]);
            }
            // v4.31.0: keep the per-request cache coherent
            if (!empty($GLOBALS['__settings_cache_loaded'])) {
                $GLOBALS['__settings_cache'][$key] = $value;
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('verify_csrf')) {
    function verify_csrf($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('set_flash_message')) {
    function set_flash_message($type, $message) {
        $_SESSION['flash_message'] = [
            'type'    => $type, // 'success', 'error', 'warning', 'info'
            'message' => $message
        ];
    }
}

if (!function_exists('get_flash_message')) {
    function get_flash_message() {
        if (isset($_SESSION['flash_message'])) {
            $flash = $_SESSION['flash_message'];
            unset($_SESSION['flash_message']);
            return $flash;
        }
        return null;
    }
}

if (!function_exists('norm_persian_str')) {
    function norm_persian_str($str) {
        $str = str_replace(['ي', 'ك', 'آ', 'إ', 'أ', '‌', 'ة', 'ؤ'], ['ی', 'ک', 'ا', 'ا', 'ا', ' ', 'ه', 'و'], (string)$str);
        return preg_replace('/[\s\-\/\_]+/', '', trim($str));
    }
}

if (!function_exists('persian_sort_key')) {
    /**
     * v4.77.0: TRUE Persian alphabetical collation — «آ» sorts BEFORE «ا»
     * (so «آبی» comes before «ابری» and «آبرومند» before «ابراهیم‌زاده»).
     * Every Persian letter is mapped to a fixed-width weight in the official
     * alphabet order; the resulting key compares correctly with plain strcmp.
     * Used by ALL alphabetical sorts across the system (lists, tables,
     * printing, exams, exports, bots).
     */
    function persian_sort_key($str) {
        static $map = null;
        if ($map === null) {
            $alphabet = ['آ','ا','ب','پ','ت','ث','ج','چ','ح','خ','د','ذ','ر','ز','ژ','س','ش','ص','ض','ط','ظ','ع','غ','ف','ق','ک','گ','ل','م','ن','و','ه','ی'];
            $map = [];
            foreach ($alphabet as $i => $ch) $map[$ch] = sprintf('1%02d', $i);
        }
        $s = (string)$str;
        // unify Arabic variants — but keep «آ» distinct from «ا»!
        $s = str_replace(['ي','ك','أ','إ','ٱ','ة','ؤ','ئ'], ['ی','ک','ا','ا','ا','ه','و','ی'], $s);
        if (function_exists('tr_num')) $s = tr_num($s, 'en');
        // drop ZWNJ / whitespace / separators so «حسینی نژاد» == «حسینی‌نژاد»
        $s = preg_replace('/[\x{200C}\x{200D}\s\-\/\_\.]+/u', '', trim($s));
        $out = '';
        $len = mb_strlen($s, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($s, $i, 1, 'UTF-8');
            $out .= $map[$ch] ?? ('2' . $ch);   // non-Persian chars come after letters
        }
        return $out;
    }
}

if (!function_exists('persian_compare')) {
    function persian_compare($a, $b) {
        return strcmp(persian_sort_key($a), persian_sort_key($b));
    }
}

if (!function_exists('persian_usort_by')) {
    /**
     * v4.77.0: sort an array of rows by one or more text columns using the
     * true Persian collation (آ before ا). Example:
     *   persian_usort_by($rows, ['class_name','last_name','first_name']);
     */
    function persian_usort_by(&$rows, $keys) {
        $keys = (array)$keys;
        usort($rows, function ($a, $b) use ($keys) {
            foreach ($keys as $k) {
                $c = persian_compare($a[$k] ?? '', $b[$k] ?? '');
                if ($c !== 0) return $c;
            }
            return 0;
        });
    }
}

if (!function_exists('teacher_family_name')) {
    /**
     * v4.71.0: خارج از پنل مدیریت فقط «نام خانوادگی» دبیر نمایش داده می‌شود.
     * پیشوندهای احترام (استاد/آقای/خانم/دکتر/مهندس) حفظ می‌شوند و بعد از آن‌ها
     * فقط نام خانوادگی می‌آید. نام خانوادگی = همه توکن‌ها بعد از اولین توکنِ
     * نام (تا نام‌های خانوادگی مرکب مثل «حسینی نژاد» سالم بمانند).
     */
    function teacher_family_name($fullName) {
        $fullName = trim((string)$fullName);
        if ($fullName === '') return $fullName;
        $prefixes = ['استاد', 'آقای', 'خانم', 'دکتر', 'مهندس', 'حاج', 'سید', 'سیده'];
        $tokens = preg_split('/\s+/u', $fullName);
        $kept = [];
        while (!empty($tokens) && in_array($tokens[0], $prefixes, true)) { $kept[] = array_shift($tokens); }
        if (count($tokens) >= 2) array_shift($tokens); // حذف نام کوچک
        $out = trim(implode(' ', array_merge($kept, $tokens)));
        return $out !== '' ? $out : $fullName;
    }
}

if (!function_exists('norm_class_str')) {
    function norm_class_str($str) {
        return preg_replace('/[\s\-\/\_]+/', '', trim((string)$str));
    }
}

if (!function_exists('extract_certificate_serial')) {
    function extract_certificate_serial($raw) {
        $raw = tr_num((string)$raw, 'en');
        if (preg_match('/(\d{6})/', $raw, $matches)) {
            return $matches[1];
        }
        $digits = preg_replace('/[^0-9]/', '', $raw);
        if (strlen($digits) >= 6) {
            return substr($digits, -6);
        }
        return str_pad($digits, 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('extract_clean_grades_and_discipline')) {
    function extract_clean_grades_and_discipline($grades, $fallbackDiscipline = null) {
        $regular = [];
        $disc = $fallbackDiscipline;
        $sum = 0;
        $count = 0;

        foreach ($grades as $g) {
            $sName = trim($g['subject_name'] ?? '');
            $score = ($g['score'] !== null && $g['score'] !== '') ? (float)str_replace('/', '.', $g['score']) : null;

            if ($sName === 'انضباط' || strpos($sName, 'انضباط') !== false) {
                if ($score !== null && $score != 21.0) {
                    $disc = $score;
                }
            } else {
                $regular[] = $g;
                // Score 21 is the local sentinel for absence (غیبت) and must not affect GPA.
                if ($score !== null && $score >= 0 && $score != 21.0) {
                    $sum += $score;
                    $count++;
                }
            }
        }

        $gpa = $count > 0 ? round($sum / $count, 2) : 0;
        return [
            'regular_grades'   => $regular,
            'discipline_score' => $disc,
            'calculated_gpa'   => $gpa,
            'count'            => $count
        ];
    }
}
if (!function_exists('format_score')) {
    function format_score($score) {
        return tr_num(number_format((float)$score, 2), 'fa');
    }
}

if (!function_exists('render_sms_template')) {
    function render_sms_template($template, $student = [], $extra = []) {
        $schoolName = get_setting('school_name', 'دبیرستان غیردولتی بصیرت');
        $today = jdate('Y/m/d');

        $replacements = [
            '{student_name}'  => trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')),
            '{student_code}'  => $student['national_id'] ?? '',
            '{serial_number}' => $student['serial_number'] ?? '',
            '{class_name}'    => $student['class_name'] ?? '',
            '{grade_level}'   => $student['grade_level'] ?? '',
            '{father_name}'   => $student['father_name'] ?? '',
            '{gpa}'           => isset($student['latest_gpa']) ? format_score($student['latest_gpa']) : ($extra['gpa'] ?? '---'),
            '{report_month}'  => $student['latest_month'] ?? ($extra['report_month'] ?? 'آبان'),
            '{teacher_name}'  => $extra['teacher_name'] ?? '',
            '{subject_name}'  => $extra['subject_name'] ?? '',
            '{school_name}'   => $schoolName,
            '{date}'          => $today
        ];

        return str_replace(array_keys($replacements), array_values($replacements), (string)$template);
    }
}
if (!function_exists('generate_captcha')) {
    function generate_captcha() {
        $n1 = rand(2, 9);
        $n2 = rand(2, 9);
        $_SESSION['captcha_ans'] = $n1 + $n2;
        return tr_num("$n1 + $n2 = ?", 'fa');
    }
}

if (!function_exists('verify_captcha')) {
    function verify_captcha($ans) {
        if (!isset($_SESSION['captcha_ans'])) return true;
        return (int)tr_num((string)$ans, 'en') === (int)$_SESSION['captcha_ans'];
    }
}

if (!function_exists('check_login_throttle')) {
    function check_login_throttle($ip) {
        if (isset($_SESSION['login_attempts']) && $_SESSION['login_attempts'] >= 6) {
            if (time() - ($_SESSION['lockout_time'] ?? 0) < 600) {
                return false;
            } else {
                $_SESSION['login_attempts'] = 0;
            }
        }
        return true;
    }
}

if (!function_exists('record_failed_login')) {
    function record_failed_login() {
        $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
        if ($_SESSION['login_attempts'] >= 6) {
            $_SESSION['lockout_time'] = time();
        }
    }
}

if (!function_exists('redirect')) {
    function redirect($url) {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            header("Location: " . $url);
        } else {
            echo '<script>window.location.href="' . $url . '";</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . $url . '"></noscript>';
        }
        exit;
    }
}

if (!function_exists('sync_student_photos_from_uploads')) {
    function sync_student_photos_from_uploads($limit = 1000) {
        $dir = dirname(__DIR__) . '/uploads/photos';
        if (!is_dir($dir)) return ['matched'=>0,'skipped'=>0];
        $matched=0; $skipped=0; $n=0;
        foreach (scandir($dir) ?: [] as $fn) {
            if ($fn === '.' || $fn === '..') continue;
            if (++$n > $limit) break;
            $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) { $skipped++; continue; }
            $base = tr_num(pathinfo($fn, PATHINFO_FILENAME), 'en');
            $digits = preg_replace('/[^0-9]/', '', $base);
            if (strlen($digits) === 9) $digits = '0'.$digits;
            if (strlen($digits) !== 10) { $skipped++; continue; }
            $rel = 'uploads/photos/' . $fn;
            try {
                $st = DB::fetch("SELECT id, photo_url FROM students WHERE national_id=? AND (photo_url IS NULL OR photo_url='' OR photo_url<>?) LIMIT 1", [$digits, $rel]);
                if ($st) { DB::execute("UPDATE students SET photo_url=? WHERE national_id=?", [$rel, $digits]); $matched++; }
            } catch (Exception $e) { $skipped++; }
        }
        return ['matched'=>$matched,'skipped'=>$skipped];
    }
}

if (!function_exists('make_report_print_token')) {
    function make_report_print_token($reportId, $studentId, $ttl = 900) {
        $secret = get_setting('print_link_secret', '');
        if ($secret === '') { $secret = bin2hex(random_bytes(24)); set_setting('print_link_secret', $secret); }
        $exp = time() + (int)$ttl;
        $payload = (int)$reportId . '|' . (int)$studentId . '|' . $exp;
        $sig = hash_hmac('sha256', $payload, $secret);
        return rtrim(strtr(base64_encode($payload . '|' . $sig), '+/', '-_'), '=');
    }
}

if (!function_exists('verify_report_print_token')) {
    function verify_report_print_token($token, $reportId, $studentId) {
        $secret = get_setting('print_link_secret', '');
        if ($secret === '' || $token === '') return false;
        $raw = base64_decode(strtr($token, '-_', '+/'));
        if (!$raw) return false;
        $parts = explode('|', $raw);
        if (count($parts) !== 4) return false;
        [$rid, $sid, $exp, $sig] = $parts;
        if ((int)$rid !== (int)$reportId || (int)$sid !== (int)$studentId || (int)$exp < time()) return false;
        $payload = $rid . '|' . $sid . '|' . $exp;
        return hash_equals(hash_hmac('sha256', $payload, $secret), $sig);
    }
}

/* ------------------------------------------------------------------
 * v4.33.0: Exclusive role sessions
 * A session must hold exactly ONE role (admin OR teacher OR student).
 * Every successful login MUST go through auth_login_as() which wipes
 * all other role keys and regenerates the session id (fixation-safe).
 * ------------------------------------------------------------------ */
if (!function_exists('auth_clear_all_roles')) {
    function auth_clear_all_roles() {
        unset(
            $_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_name'], $_SESSION['admin_role'],
            $_SESSION['student_id'], $_SESSION['student_name'], $_SESSION['student_nid'],
            $_SESSION['teacher_id'], $_SESSION['teacher_name'], $_SESSION['teacher_nid']
        );
    }
}
if (!function_exists('auth_login_as')) {
    /**
     * Log the current session in as exactly one role.
     * $role: 'admin' | 'teacher' | 'student'
     * $data: role-specific session keys (admin_id, admin_name, ...)
     */
    function auth_login_as($role, array $data) {
        auth_clear_all_roles();
        // New session id on privilege change — prevents fixation and
        // guarantees no leftovers from the previous account.
        if (function_exists('session_regenerate_id')) { @session_regenerate_id(true); }
        foreach ($data as $k => $v) { $_SESSION[$k] = $v; }
        $_SESSION['auth_role'] = $role;
        $_SESSION['login_attempts'] = 0;
    }
}

if (!function_exists('remember_secret')) {
    function remember_secret() {
        $secret = get_setting('remember_login_secret', '');
        if ($secret === '') { $secret = bin2hex(random_bytes(32)); set_setting('remember_login_secret', $secret); }
        return $secret;
    }
}
if (!function_exists('set_remember_login')) {
    function set_remember_login($type, $id) {
        $exp = time() + 60*60*24*30;
        $payload = $type . '|' . (int)$id . '|' . $exp;
        $token = rtrim(strtr(base64_encode($payload . '|' . hash_hmac('sha256', $payload, remember_secret())), '+/', '-_'), '=');
        setcookie('school_remember', $token, ['expires'=>$exp,'path'=>'/','secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),'httponly'=>true,'samesite'=>'Lax']);
    }
}
if (!function_exists('clear_remember_login')) {
    function clear_remember_login() {
        setcookie('school_remember', '', ['expires'=>time()-3600,'path'=>'/','secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),'httponly'=>true,'samesite'=>'Lax']);
    }
}
if (!function_exists('try_remember_login')) {
    function try_remember_login() {
        if (!empty($_SESSION['admin_id']) || !empty($_SESSION['student_id']) || !empty($_SESSION['teacher_id']) || empty($_COOKIE['school_remember'])) return;
        $raw = base64_decode(strtr($_COOKIE['school_remember'], '-_', '+/'));
        if (!$raw) return;
        $p = explode('|', $raw);
        if (count($p) !== 4) return;
        [$type,$id,$exp,$sig] = $p;
        $payload = $type.'|'.(int)$id.'|'.(int)$exp;
        if ((int)$exp < time() || !hash_equals(hash_hmac('sha256', $payload, remember_secret()), $sig)) { clear_remember_login(); return; }
        if ($type === 'admin') {
            $u = DB::fetch("SELECT * FROM admins WHERE id=? AND status=1", [(int)$id]);
            if ($u) { $_SESSION['admin_id']=$u['id']; $_SESSION['admin_username']=$u['username']; $_SESSION['admin_name']=$u['name']; $_SESSION['admin_role']=$u['role']; set_remember_login('admin',$u['id']); }
        } elseif ($type === 'student') {
            $u = DB::fetch("SELECT * FROM students WHERE id=? AND status='active'", [(int)$id]);
            if ($u) { $_SESSION['student_id']=$u['id']; $_SESSION['student_name']=$u['first_name'].' '.$u['last_name']; $_SESSION['student_nid']=$u['national_id']; set_remember_login('student',$u['id']); }
        } elseif ($type === 'teacher') {
            $u = DB::fetch("SELECT * FROM teachers WHERE id=? AND status=1", [(int)$id]);
            if ($u) { $_SESSION['teacher_id']=$u['id']; $_SESSION['teacher_name']=$u['full_name']; $_SESSION['teacher_nid']=$u['national_id']; set_remember_login('teacher',$u['id']); }
        }
    }
}

if (!function_exists('make_exam_design_token')) {
    function make_exam_design_token($examId, $type = 'user', $id = 0, $ttl = 86400) {
        $secret = get_setting('exam_design_link_secret', '');
        if ($secret === '') { $secret = bin2hex(random_bytes(24)); set_setting('exam_design_link_secret', $secret); }
        $exp = time() + (int)$ttl;
        $payload = (int)$examId . '|' . $type . '|' . (int)$id . '|' . $exp;
        return rtrim(strtr(base64_encode($payload . '|' . hash_hmac('sha256', $payload, $secret)), '+/', '-_'), '=');
    }
}
if (!function_exists('verify_exam_design_token')) {
    function verify_exam_design_token($token, $examId) {
        $secret = get_setting('exam_design_link_secret', '');
        if ($secret === '' || $token === '') return false;
        $raw = base64_decode(strtr($token, '-_', '+/'));
        if (!$raw) return false;
        $p = explode('|', $raw);
        if (count($p) !== 5) return false;
        [$eid,$type,$id,$exp,$sig] = $p;
        if ((int)$eid !== (int)$examId || (int)$exp < time()) return false;
        $payload = $eid.'|'.$type.'|'.(int)$id.'|'.(int)$exp;
        return hash_equals(hash_hmac('sha256', $payload, $secret), $sig);
    }
}

if (!function_exists('display_score')) {
    function display_score($score) {
        if ($score === null || $score === '') return '---';
        return ((float)$score == 21.0) ? 'غیبت' : format_score($score);
    }
}
