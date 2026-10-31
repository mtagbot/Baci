<?php
// Preserve clean-install guards when upgrading Release_V1.0; older installs have no guard file.
if (is_file(__DIR__.'/install_guard.php')) require_once __DIR__.'/install_guard.php';
// V4.208.0: هستهٔ کارایی (کش داده، «یک‌بار در هر نسخه» برای DDL، باندل دارایی‌ها).
if (is_file(__DIR__.'/perf.php')) require_once __DIR__.'/perf.php';
elseif (is_file(__DIR__.'/perf_fallback.php')) require_once __DIR__.'/perf_fallback.php';
// V4.209.1: خطای کشنده ثبت و (اگر PHP خاموش کرده باشد) خوانا نمایش داده شود.
if (is_file(__DIR__.'/fatal_guard.php')) require_once __DIR__.'/fatal_guard.php';
// File: includes/functions.php
/**
 * Core Helper Functions
 */
if (!ob_get_level()) {
    ob_start();
}

$__isSdpSyncRelay = !defined('SDP_SYNC_ACTIVE') && (!empty($_REQUEST['sdp_sync']) || !empty($_REQUEST['sdp_z']) || !empty($_REQUEST['sdp_cid']) || !empty($_REQUEST['sdp_bridge']) || !empty($_SERVER['HTTP_X_SDP_Z']));
if (!$__isSdpSyncRelay && session_status() === PHP_SESSION_NONE) {
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

if (!function_exists('csrf_secret')) {
    /**
     * v4.175.0: راز سرور برای توکن CSRF «امضاشده». در تنظیمات نگه داشته می‌شود
     * تا بین چند worker/نود یکسان بماند و با دیپلوی فایل از بین نرود.
     */
    function csrf_secret() {
        $s = (string)get_setting('csrf_secret', '');
        if ($s === '' || strlen($s) < 32) {
            $s = bin2hex(random_bytes(32));
            set_setting('csrf_secret', $s);
        }
        return $s;
    }
}

if (!function_exists('csrf_signed_token')) {
    /**
     * v4.175.0: توکن امضاشده که برای راستی‌آزمایی به نشست نیاز ندارد —
     * salt تصادفی + HMAC با راز سرور. وقتی مرورگر کوکی نشست را نفرستد (مرورگر
     * داخلی پیام‌رسان‌ها، حالت خصوصی، نخستین درخواست پس از باز شدن لینک) همین
     * توکن اجازه می‌دهد فرم همچنان معتبر بماند.
     */
    function csrf_signed_token() {
        $salt = bin2hex(random_bytes(12));
        return $salt . '.' . hash_hmac('sha256', $salt, csrf_secret());
    }
}

if (!function_exists('csrf_signed_valid')) {
    /** راستی‌آزمایی توکن امضاشده، بدون هیچ نشستی. */
    function csrf_signed_valid($token) {
        if (!is_string($token)) return false;
        $parts = explode('.', $token);
        if (count($parts) !== 2) return false;
        $salt = $parts[0]; $sig = $parts[1];
        if (!preg_match('/^[a-f0-9]{24}$/', $salt) || !preg_match('/^[a-f0-9]{64}$/', $sig)) return false;
        return hash_equals(hash_hmac('sha256', $salt, csrf_secret()), $sig);
    }
}

if (!function_exists('csrf_token')) {
    /**
     * v4.175.0: توکن پایدارِ هر نشست، اما امضاشده. پایدار می‌ماند تا چند تب یا
     * صفحهٔ بازگشتی مرورگر (bfcache) توکن کهنه نسازد.
     */
    function csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = csrf_signed_token();
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('verify_csrf')) {
    /**
     * v4.175.0: سه مسیر پذیرش، به ترتیب قوت:
     *   ۱) توکن فعلی نشست (رایج‌ترین حالت).
     *   ۲) یکی از توکن‌های اخیرِ نشست (چند تب / توکن تازه‌شده).
     *   ۳) توکن امضاشدهٔ خودمان، فقط وقتی مرورگر هیچ کوکی نشستی نفرستاده. این
     *      همان حالتی است که مرورگر داخلی بله می‌سازد: کوکی نشست روی POST نرسیده،
     *      پس نشستی برای سوءاستفاده وجود ندارد و فرم باید کار کند — نه اینکه کاربر
     *      پیام «اعتبار فرم به پایان رسیده» بگیرد. اگر کوکی نشست آمده باشد، این
     *      مسیر بسته می‌شود تا حملهٔ CSRF روی نشستِ کاربر باز نشود.
     */
    function verify_csrf($token) {
        if (!is_string($token) || $token === '') return false;
        if (!empty($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $token)) return true;
        foreach ((array)($_SESSION['csrf_ring'] ?? []) as $old) {
            if (is_string($old) && $old !== '' && hash_equals($old, $token)) return true;
        }
        $cookieSent = isset($_COOKIE[session_name()]);
        if (!$cookieSent && empty($_SESSION['csrf_token']) && csrf_signed_valid($token)) return true;
        return false;
    }
}

if (!function_exists('csrf_remember_recent')) {
    /** v4.175.0: توکن قبلی را در حلقهٔ «اخیر» نگه می‌دارد تا چند تب امن بماند. */
    function csrf_remember_recent($token) {
        if (!is_string($token) || $token === '') return;
        $ring = array_values(array_filter((array)($_SESSION['csrf_ring'] ?? []), function ($t) use ($token) {
            return is_string($t) && $t !== '' && !hash_equals($t, $token);
        }));
        array_unshift($ring, $token);
        $_SESSION['csrf_ring'] = array_slice($ring, 0, 5);
    }
}

if (!function_exists('csrf_rotate')) {
    /**
     * v4.175.0: توکن تازهٔ نشست. توکن قبلی در حلقهٔ «اخیر» می‌ماند، پس تب‌هایی که
     * هنوز فرم قدیمی دارند هم رد نمی‌شوند (علت واقعی پیام «اعتبار فرم به پایان
     * رسیده» در مرورگرهای داخلی پیام‌رسان).
     */
    function csrf_rotate() {
        $old = isset($_SESSION['csrf_token']) ? (string)$_SESSION['csrf_token'] : '';
        $_SESSION['csrf_token'] = csrf_signed_token();
        if ($old !== '') csrf_remember_recent($old);
        return $_SESSION['csrf_token'];
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
        /* v4.90.0: منبع قطعی = فیلد مجزای last_name در جدول teachers.
           اگر آرایه دبیر (رکورد DB) داده شود مستقیم از فیلد استفاده می‌شود؛
           اگر رشته باشد، اول با جدول دبیران تطبیق داده می‌شود (نگاشت
           full_name → last_name با یک کوئری کش‌شده) و فقط اگر پیدا نشد،
           به حدس توکنی قدیمی برمی‌گردیم. */
        if (is_array($fullName)) {
            $lastF = trim((string)($fullName['last_name'] ?? ''));
            if ($lastF !== '') return $lastF;
            $fullName = (string)($fullName['full_name'] ?? '');
        }
        $fullName = trim((string)$fullName);
        if ($fullName === '') return $fullName;
        static $famMap = null;
        if ($famMap === null) {
            $famMap = [];
            try {
                if (class_exists('DB')) {
                    foreach (DB::fetchAll("SELECT full_name, last_name FROM teachers WHERE COALESCE(last_name,'')<>''") as $fr) {
                        $famMap[trim((string)$fr['full_name'])] = trim((string)$fr['last_name']);
                    }
                }
            } catch (Exception $e) { $famMap = []; }
        }
        $prefixes = ['استاد', 'آقای', 'خانم', 'دکتر', 'مهندس', 'حاج', 'سید', 'سیده'];
        $tokens = preg_split('/\s+/u', $fullName);
        $kept = [];
        while (!empty($tokens) && in_array($tokens[0], $prefixes, true)) { $kept[] = array_shift($tokens); }
        $bare = trim(implode(' ', $tokens));
        if (isset($famMap[$fullName])) return trim(implode(' ', array_merge($kept, [$famMap[$fullName]])));
        if ($bare !== '' && isset($famMap[$bare])) return trim(implode(' ', array_merge($kept, [$famMap[$bare]])));
        if (count($tokens) >= 2) array_shift($tokens); // حدس قدیمی: حذف توکن اول
        $out = trim(implode(' ', array_merge($kept, $tokens)));
        return $out !== '' ? $out : $fullName;
    }
}

if (!function_exists('teacher_respectful_name')) {
    /**
     * v4.89.0: نام محترمانه دبیر برای پیام‌های ربات: «آقای رسولی».
     * اگر نام از قبل پیشوند احترام (آقای/خانم/استاد/دکتر/مهندس) داشته باشد،
     * همان حفظ می‌شود؛ در غیر این صورت «آقای» قبل از نام خانوادگی می‌آید.
     */
    function teacher_respectful_name($fullName) {
        /* v4.90.0: آرایه رکورد دبیر هم پذیرفته می‌شود (فیلد last_name قطعی). */
        $fam = function_exists('teacher_family_name') ? teacher_family_name($fullName) : (is_array($fullName) ? trim((string)($fullName['full_name'] ?? '')) : trim((string)$fullName));
        if ($fam === '') return $fam;
        $honorifics = ['استاد', 'آقای', 'خانم', 'دکتر', 'مهندس'];
        $first = preg_split('/\s+/u', $fam)[0] ?? '';
        if (in_array($first, $honorifics, true)) return $fam;
        return 'آقای ' . $fam;
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
        // Independent persistent IP ceiling: clearing cookies must not reset abuse protection.
        require_once __DIR__.'/header_tiles.php';login_guard_ensure_schema();
        try {
            $row=DB::fetch('SELECT fail_count,last_fail FROM login_guard WHERE identity=?',[login_guard_key('ip',hash('sha256',(string)$ip))]);
            if($row && (int)$row['fail_count']>=30 && (int)$row['last_fail']>time()-600)return false;
        } catch(Exception $e){return false;}
        return true;
    }
}

if (!function_exists('record_failed_login')) {
    function record_failed_login() {
        require_once __DIR__.'/header_tiles.php';
        login_guard_fail('ip',hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'127.0.0.1')));
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
    /**
     * اتصال فایل‌های داخل uploads/photos به پروندهٔ دانش‌آموزان.
     *
     * V4.208.0 — این تابع پیش از این در «هر باز شدن» صفحهٔ مدیریت دانش‌آموزان
     * اجرا می‌شد: یک scandir روی پوشهٔ عکس‌ها و برای «هر فایل» یک SELECT و
     * احتمالأ یک UPDATE. با چند صد دانش‌آموز یعنی چند صد رفت‌وبرگشت دیتابیس
     * در هر بارگذاری صفحه — یکی از دو عامل اصلی کندی TTFB.
     *
     * اکنون:
     *   · با $force=false (پیش‌فرض در نمایش صفحه) فقط وقتی پوشه تغییر کرده
     *     اجرا می‌شود؛ اثر انگشت = تعداد فایل + جدیدترین mtime.
     *   · در یک گذر، همهٔ فایل‌ها خوانده و با «یک» کوئری، نگاشت national_id
     *     ساخته می‌شود؛ سپس فقط موارد لازم UPDATE می‌شوند (بدون SELECT تکراری).
     *   · ورودی/خروجی و امضای تابع برای سازگاری حفظ شده است.
     */
    function sync_student_photos_from_uploads($limit = 1000, $force = false) {
        $dir = dirname(__DIR__) . '/uploads/photos';
        if (!is_dir($dir)) return ['matched'=>0,'skipped'=>0];

        $files=[]; $newest=0; $total=0;
        foreach (scandir($dir) ?: [] as $fn) {
            if ($fn === '.' || $fn === '..') continue;
            $path=$dir.'/'.$fn;
            if (!is_file($path)) continue;
            $total++;
            $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) continue;
            $newest=max($newest,(int)@filemtime($path));
            $files[$fn]=$path;
            if (count($files) >= $limit) break;
        }
        if (!$files) return ['matched'=>0,'skipped'=>0];

        $signature=$total.':'.count($files).':'.$newest;
        $run = function () use ($files, $signature) {
            $put=[];
            foreach ($files as $fn => $path) {
                $base = tr_num(pathinfo($fn, PATHINFO_FILENAME), 'en');
                $digits = preg_replace('/[^0-9]/', '', $base);
                if (strlen($digits) === 9) $digits = '0'.$digits;
                if (strlen($digits) !== 10) continue;
                $put[$digits]='uploads/photos/'.$fn;
            }
            if (!$put) return ['matched'=>0,'skipped'=>count($files)];
            // یک کوئری برای همهٔ شناسه‌های ملی به‌جای یکی‌یکی
            $ids=array_keys($put); $have=[]; $matched=0;
            foreach (array_chunk($ids, 400) as $chunk) {
                $ph=implode(',', array_fill(0, count($chunk), '?'));
                try {
                    foreach (DB::fetchAll("SELECT national_id, photo_url FROM students WHERE national_id IN ($ph)", $chunk) as $row) {
                        $have[(string)$row['national_id']]=(string)($row['photo_url'] ?? '');
                    }
                } catch (Exception $e) { /* مثل قبل: بی‌صدا رد شو */ }
            }
            foreach ($put as $nid => $rel) {
                if (array_key_exists($nid, $have) && $have[$nid] === $rel) continue;
                if (!array_key_exists($nid, $have)) continue;   // دانش‌آموزی با این کد ملی نیست
                try { DB::execute("UPDATE students SET photo_url=? WHERE national_id=?", [$rel, $nid]); $matched++; }
                catch (Exception $e) { }
            }
            return ['matched'=>$matched,'skipped'=>count($files)-$matched];
        };
        if ($force) return $run();
        $result=['matched'=>0,'skipped'=>0];
        perf_daily_once('photo_sync', function () use ($run, &$result) { $result=$run(); }, 3600, $signature);
        return $result;
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
        unset($_SESSION['st_origin_sid']);
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
    function set_remember_login($type,$id) {st_issue_remember($type,$id);}
}
if (!function_exists('clear_remember_login')) {
    function clear_remember_login() {st_forget_remember();}
}
if (!function_exists('try_remember_login')) {
    function try_remember_login() {st_restore_remember();}
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

if (!function_exists('make_class_exam_entry_token')) {
    /**
     * v4.180.0 — توکن امضاشدهٔ «ورود به ویرایش آزمون کلاسی» (کلاسی و پایه‌ای).
     *
     * چرا؟ دکمه‌های ویرایش آزمون کلاسی به صفحهٔ class-exam-create.php و
     * class-exam-group.php می‌رفتند که فقط با «نشست پنل» کار می‌کنند. در پنجرهٔ
     * تازه — مرورگر داخلی بله یا پنجرهٔ جدایی که اپ دسکتاپ باز می‌کند — نشست
     * وجود ندارد و کاربر به صفحهٔ ورود می‌افتاد، در حالی که دکمه‌های «طراحی
     * آزمون جدید» با توکن طراحی (dt) بدون ورود کار می‌کردند. این توکن همان
     * کار را برای ویرایش/ساخت انجام می‌دهد: امضای HMAC و انقضا.
     *
     * $allowCreate فقط برای دکمهٔ «طراحی آزمون جدید» روشن می‌شود؛ با آن، باز
     * کردن لینک (GET) می‌تواند آزمون را بسازد. برای دکمهٔ ویرایش، ساختِ ناخواسته
     * ممکن نیست و فقط آزمون موجود باز می‌شود.
     */
    function make_class_exam_entry_token($teacherId, $year, $class, $subject, $grade = '', $examId = 0, $ttl = 86400, $allowCreate = false) {
        $secret = get_setting('class_exam_entry_secret', '');
        if ($secret === '') { $secret = bin2hex(random_bytes(24)); set_setting('class_exam_entry_secret', $secret); }
        $body = json_encode([
            'v' => 1,
            't' => (int)$teacherId,
            'y' => (string)$year,
            'c' => (string)$class,
            's' => (string)$subject,
            'g' => (string)$grade,
            'e' => (int)$examId,
            'n' => $allowCreate ? 1 : 0,
            'x' => time() + (int)$ttl,
        ], JSON_UNESCAPED_UNICODE);
        return rtrim(strtr(base64_encode($body . '|' . hash_hmac('sha256', $body, $secret)), '+/', '-_'), '=');
    }
}
if (!function_exists('verify_class_exam_entry_token')) {
    /**
     * توکن ورود به ویرایش آزمون کلاسی را می‌سنجد و در صورت درستی داده‌هایش را
     * برمی‌گرداند، وگرنه false. اگر آرایهٔ $match بدهید، همهٔ کلیدهای آن باید
     * برابر باشند (مثلاً ['c' => 'هفتم1']).
     */
    function verify_class_exam_entry_token($token, $match = []) {
        $token = (string)$token;
        if ($token === '') return false;
        $secret = get_setting('class_exam_entry_secret', '');
        if ($secret === '') return false;
        $raw = base64_decode(strtr($token, '-_', '+/'));
        if (!$raw) return false;
        $cut = strrpos($raw, '|');
        if ($cut === false) return false;
        $body = substr($raw, 0, $cut);
        $sig = substr($raw, $cut + 1);
        if (!hash_equals(hash_hmac('sha256', $body, $secret), $sig)) return false;
        $data = json_decode($body, true);
        if (!is_array($data) || (int)($data['v'] ?? 0) !== 1) return false;
        if ((int)($data['x'] ?? 0) < time()) return false;
        foreach ($match as $key => $value) {
            if ((string)($data[$key] ?? '') !== (string)$value) return false;
        }
        return $data;
    }
}

if (!function_exists('display_score')) {
    function display_score($score) {
        if ($score === null || $score === '') return '---';
        return ((float)$score == 21.0) ? 'غیبت' : format_score($score);
    }
}

/* =====================================================================
 * v4.131.0 — احراز هویت رمز عبور، بدون شاخهٔ «رمز خام»
 * ---------------------------------------------------------------------
 * تا v4.130.0 هر مسیر ورود این شکل بود:
 *
 *     password_verify($pass, $row['password']) || $pass === $row['password']
 *         || $pass === $row['personnel_code']      // دبیر
 *         || $pass === $row['national_id']         // دانش‌آموز
 *
 * دو اشکال داشت. اول اینکه رکورد هش‌نشده با رمز خام باز می‌شد. دوم و
 * مهم‌تر: کد ملی و کد پرسنلی عملاً رمز عبور معتبر بودند — و کد ملی در
 * همین سامانه نام کاربری هم هست، یعنی آن حساب‌ها در عمل بی‌رمز بودند.
 *
 * حالا فقط هش پذیرفته می‌شود. رکوردهای قدیمیِ هش‌نشده هم قفل نمی‌شوند:
 * اگر مقدار ذخیره‌شده هش نباشد و با رمز واردشده برابر باشد، همان لحظه
 * به هش تبدیل و در دیتابیس نوشته می‌شود (مهاجرت تدریجی و بی‌صدا).
 * پس از اولین ورود موفق، دیگر هیچ رمز خامی در جدول نمی‌ماند.
 *
 * «کد ملی / کد پرسنلی به‌عنوان رمز» دیگر هرگز پذیرفته نمی‌شود.
 * ===================================================================== */

if (!function_exists('password_is_hashed')) {
    /** آیا این مقدار، خروجی password_hash است؟ (bcrypt / argon2) */
    function password_is_hashed($stored) {
        if (!is_string($stored) || $stored === '') return false;
        $info = password_get_info($stored);
        return !empty($info['algo']);
    }
}

if (!function_exists('verify_user_password')) {
    /**
     * تنها نقطهٔ بررسی رمز در کل سامانه.
     *
     * @param string $plain  رمزی که کاربر وارد کرده
     * @param string $stored مقدار ستون password
     * @param array  $rehash ['table'=>'admins','id'=>5]  برای مهاجرت خودکار
     * @return bool
     */
    function verify_user_password($plain, $stored, array $rehash = []) {
        if (!is_string($plain) || $plain === '' || !is_string($stored) || $stored === '') {
            return false;
        }

        /* حالت عادی: مقدار ذخیره‌شده هش است */
        if (password_is_hashed($stored)) {
            if (!password_verify($plain, $stored)) return false;
            /* هش قدیمی (مثلاً cost پایین‌تر) را به‌روز کن */
            if (!empty($rehash['table']) && password_needs_rehash($stored, PASSWORD_DEFAULT)) {
                password_store_hash($rehash['table'], $rehash['id'] ?? 0, $plain);
            }
            return true;
        }

        /* رکورد قدیمیِ md5 (نسخه‌های خیلی قدیم سامانه فقط برای مدیران).
           v4.131.0 در اولین نسخه این شاخه را حذف کرده بود و همان باعث
           می‌شد مدیرِ md5‌دار دیگر نتواند وارد شود. برگشت، ولی این بار
           رمز بلافاصله به bcrypt ارتقا می‌یابد پس md5 ماندگار نمی‌شود. */
        if (strlen($stored) === 32 && ctype_xdigit($stored)
            && hash_equals(strtolower($stored), md5($plain))) {
            if (!empty($rehash['table'])) {
                password_store_hash($rehash['table'], $rehash['id'] ?? 0, $plain);
            }
            return true;
        }

        /* رکورد قدیمیِ هش‌نشده: فقط تطابق دقیق، و بلافاصله هش می‌شود.
           hash_equals برای جلوگیری از نشت زمانی. */
        if (hash_equals($stored, $plain)) {
            if (!empty($rehash['table'])) {
                password_store_hash($rehash['table'], $rehash['id'] ?? 0, $plain);
            }
            return true;
        }

        return false;
    }
}

if (!function_exists('student_serial_matches')) {
    /**
     * v4.172.0: تطبیق «سریال شناسنامه» با تحمل خطاهای رایج واردکردن.
     *
     * چرا لازم شد: در ورود به پنل دانش‌آموز، تا امروز اگر ستون password پر
     * بود فقط همان رمز پذیرفته می‌شد و سریال شناسنامه نادیده گرفته می‌شد —
     * در حالی که ربات (اتصال حساب) و «استعلام» هر دو رمز *یا* سریال را
     * می‌پذیرفتند. نتیجه: تعدادی از دانش‌آموزان که در ربات با کد ملی و سریال
     * شناسنامه متصل شده بودند، در پنل پیام «اطلاعات نادرست» می‌گرفتند.
     *
     * این تابع فقط *تطبیق* را یکسان می‌کند (نه سیاست را): ارقام فارسی/عربی،
     * فاصله، خط تیره و پرانتز حذف می‌شوند و صفرهای ابتدایی بی‌اثر می‌شوند تا
     * سریال ذخیره‌شدهٔ «۰۱۲۳۴۵» با «۱۲۳۴۵» هم مطابقت کند. هیچ رمزی هرگز
     * «بازتیب» نمی‌شود؛ رمز همچنان اول و سریال فقط جایگزینِ گزینهٔ دوم است.
     */
    function student_serial_matches($stored, $given) {
        $norm = function ($v) {
            $v = tr_num((string)$v, 'en');
            $v = preg_replace('/\D+/', '', $v);            // فقط ارقام
            $v = ltrim($v, '0');                            // صفر ابتدایی بی‌معنی است
            return $v === '' ? null : $v;
        };
        $b = $norm($given);
        if ($b === null || strlen($b) < 4) return false;    // ورودهای خیلی کوتاه پذیرفته نمی‌شوند
        if (hash_equals((string)$norm($stored), $b)) return true;
        /* سریال مرکب ثبت مدرسه («ب/۲۶/۲۶۵۴۸۶»): همان پارسر رسمی فرم — ربات
           (اتصال حساب) هم همین بخش ۶ رقمی را می‌پذیرد، پس پنل هم باید بپذیرد
           تا دانش‌آموز متصل‌شده در ربات در پنل رد نشود. */
        if (is_file(__DIR__ . '/student_profile_fields.php')) {
            require_once __DIR__ . '/student_profile_fields.php';
            if (function_exists('student_serial_parts')) {
                $sp = student_serial_parts((string)$stored);
                if ($sp['recognized'] && $sp['number'] !== '' && $sp['letter'] !== '' && $sp['suffix'] !== '') {
                    return hash_equals((string)$norm($sp['number']), $b);
                }
            }
        }
        return false;
    }
}

if (!function_exists('staff_personnel_matches')) {
    /**
     * v4.173.0: «کد پرسنلی» دبیر به‌عنوان عامل اتصال حساب در پنل هم پذیرفته
     * شود — همان قراردادی که ربات (مرحلهٔ «کد پرسنلی» ورود کادر مدرسه) از قبل
     * داشت و برچسب فرم («کد پرسنلی / رمز ورود») هم از قبل به آن قول داده بود.
     *
     * رمز ثبت‌شدهٔ مدرسه اولویت دارد و کد پرسنلی فقط جایگزینِ گزینهٔ دوم است؛
     * هیچ رمزی «بازتیب» نمی‌شود و کد پرسنلی هرگز جای رمز را نمی‌گیرد.
     *
     * تطبیق: اول دقیقاً مثل ربات (hash_equals با مقدار خام)، بعد نرمال‌سازی برای
     * کدهای کاملاً عددی (ارقام فارسی، فاصله/خط تیره، صفر ابتدایی). کد دارای حرف
     * یا کوتاه‌تر از ۴ رقم فقط به‌صورت دقیق پذیرفته می‌شود تا این مسیر سطح حملهٔ
     * جدید نسازد (ربات هم دقیق تطبیق می‌کند).
     */
    function staff_personnel_matches($stored, $given) {
        $stored = trim((string)$stored);
        $givenRaw = trim((string)$given);
        if ($stored === '' || $givenRaw === '') return false;   // مدرسه کد پرسنلی ثبت نکرده
        if (hash_equals($stored, $givenRaw)) return true;       // همان منطق ربات
        if (preg_match('/\D/', $stored)) return false;          // کد غیرعددی: فقط دقیق
        $norm = function ($v) {
            $v = tr_num((string)$v, 'en');
            $v = preg_replace('/\D+/', '', $v);
            $v = ltrim($v, '0');
            return $v === '' ? null : $v;
        };
        $a = $norm($stored);
        $b = $norm($givenRaw);
        if ($a === null || $b === null) return false;
        if (strlen($a) < 4 || strlen($b) < 4) return false;     // کد خیلی کوتاه رمز نمی‌شود
        return hash_equals($a, $b);
    }
}

if (!function_exists('password_store_hash')) {
    /** رمز را هش و ذخیره می‌کند. نام جدول از فهرست سفید می‌آید. */
    function password_store_hash($table, $id, $plain) {
        $allowed = ['admins', 'teachers', 'students'];
        if (!in_array($table, $allowed, true) || (int)$id <= 0) return;
        /* functions.php خودش db.php را require نمی‌کند؛ اگر کلاس DB بالا
           نیامده باشد فراخوانی استاتیک Error می‌دهد نه Exception. */
        if (!class_exists('DB')) return;
        try {
            DB::execute("UPDATE `$table` SET password = ? WHERE id = ?",
                [password_hash($plain, PASSWORD_DEFAULT), (int)$id]);
        } catch (Throwable $e) { /* ورود نباید به‌خاطر شکست مهاجرت رد شود */ }
    }
}

if (!function_exists('report_year_variants')) {
    /**
     * واریانت‌های معادل یک سال تحصیلی (مثلاً 1404/1405 و 1404-1405) را برمی‌گرداند.
     */
    function report_year_variants($year): array {
        $raw = trim((string)$year);
        if ($raw === '') return [''];
        $en = tr_num($raw, 'en');
        $norm = preg_replace('#/+#', '/', str_replace(['-', '–', '—', ' '], ['/', '/', '/', ''], $en));
        $norm = trim($norm, '/');
        $dash = str_replace('/', '-', $norm);
        return array_values(array_unique(array_filter([$raw, $en, $norm, $dash], function ($v) {
            return $v !== '';
        })));
    }
}

if (!function_exists('report_student_grade_level')) {
    /**
     * پایهٔ تحصیلی دانش‌آموز این کارنامه را از اطلاعات کارنامه، جدول دانش‌آموزان یا نام کلاس استخراج می‌کند.
     */
    function report_student_grade_level(array $report): string {
        $g = trim((string)($report['grade_level'] ?? ''));
        $c = trim((string)($report['class_name'] ?? ''));
        if ($g === '' && !empty($report['student_id']) && class_exists('DB')) {
            try {
                $st = DB::fetch("SELECT grade_level, class_name FROM students WHERE id = ?", [(int)$report['student_id']]);
                if ($st) {
                    $g = trim((string)($st['grade_level'] ?? ''));
                    if ($c === '') $c = trim((string)($st['class_name'] ?? ''));
                }
            } catch (Throwable $e) {}
        }
        if ($g === '' && $c !== '' && function_exists('infer_grade_from_class_name')) {
            $g = trim((string)infer_grade_from_class_name($c));
        }
        return $g;
    }
}

if (!function_exists('fetch_report_rank_scope')) {
    /**
     * لیست کارنامه‌های هم‌دوره (کلاس یا پایه) را صرفاً در همان سال تحصیلی و از میان دانش‌آموزان همان پایه/کلاس برمی‌گرداند.
     */
    function fetch_report_rank_scope(array $report, string $scope = 'grade'): array {
        if (!class_exists('DB')) return [];
        $years = report_year_variants($report['academic_year'] ?? '');
        $yMarks = implode(',', array_fill(0, count($years), '?'));
        $month = trim((string)($report['report_month'] ?? ''));
        $term = trim((string)($report['term'] ?? ''));
        $params = array_merge($years, $years);
        $where = [
            "r.academic_year IN ($yMarks)",
            "(s.academic_year IS NULL OR s.academic_year = '' OR s.academic_year IN ($yMarks))"
        ];
        if ($month !== '') {
            $where[] = "r.report_month = ?";
            $params[] = $month;
        } else {
            $where[] = "r.term = ?";
            $params[] = $term;
        }
        if ($scope === 'class') {
            $cls = trim((string)($report['class_name'] ?? ''));
            $normCls = function_exists('norm_class_str') ? norm_class_str($cls) : $cls;
            $where[] = "(COALESCE(NULLIF(r.class_name, ''), s.class_name) = ? OR REPLACE(REPLACE(COALESCE(NULLIF(r.class_name, ''), s.class_name), ' ', ''), '‌', '') = ?)";
            $params[] = $cls;
            $params[] = $normCls;
        } else {
            $grade = report_student_grade_level($report);
            if ($grade !== '') {
                $where[] = "(s.grade_level = ? OR ((s.grade_level IS NULL OR s.grade_level = '') AND (r.class_name LIKE ? OR s.class_name LIKE ?)))";
                $params[] = $grade;
                $params[] = $grade . '%';
                $params[] = $grade . '%';
            } else {
                $cls = trim((string)($report['class_name'] ?? ''));
                $where[] = "r.class_name = ?";
                $params[] = $cls;
            }
        }
        $sql = "SELECT r.id, r.student_id, r.gpa FROM reports r JOIN students s ON r.student_id = s.id WHERE " . implode(' AND ', $where) . " ORDER BY r.id DESC";
        $rows = DB::fetchAll($sql, $params);
        $byStudent = [];
        $targetRepId = (int)($report['id'] ?? 0);
        $targetStuId = (int)($report['student_id'] ?? 0);
        foreach ($rows as $r) {
            $sid = (int)$r['student_id'];
            if (!isset($byStudent[$sid]) || ((int)$r['id'] === $targetRepId)) {
                $byStudent[$sid] = [
                    'student_id' => $sid,
                    'gpa' => ($sid === $targetStuId && isset($report['gpa'])) ? (float)$report['gpa'] : (float)$r['gpa']
                ];
            }
        }
        $list = array_values($byStudent);
        usort($list, function ($a, $b) use ($targetStuId) {
            $diff = (float)$b['gpa'] <=> (float)$a['gpa'];
            if (abs((float)$b['gpa'] - (float)$a['gpa']) < 0.0001) {
                if ((int)$a['student_id'] === $targetStuId) return -1;
                if ((int)$b['student_id'] === $targetStuId) return 1;
                return (int)$a['student_id'] <=> (int)$b['student_id'];
            }
            return $diff;
        });
        return $list;
    }
}

if (!function_exists('fetch_subject_rank_scope')) {
    /**
     * لیست نمرات یک درس در همان ماه و همان سال تحصیلی (در سطح کلاس یا پایه) را بدون احتساب غیبت (نمره ۲۱) برمی‌گرداند.
     */
    function fetch_subject_rank_scope(array $report, string $subjectName, string $scope = 'grade'): array {
        if (!class_exists('DB')) return [];
        $subjectName = trim($subjectName);
        if ($subjectName === '') return [];
        $years = report_year_variants($report['academic_year'] ?? '');
        $yMarks = implode(',', array_fill(0, count($years), '?'));
        $month = trim((string)($report['report_month'] ?? ''));
        $term = trim((string)($report['term'] ?? ''));
        $params = array_merge($years, $years);
        $where = [
            "r.academic_year IN ($yMarks)",
            "(s.academic_year IS NULL OR s.academic_year = '' OR s.academic_year IN ($yMarks))",
            "rg.subject_name = ?",
            "rg.score != 21"
        ];
        $params[] = $subjectName;
        if ($month !== '') {
            $where[] = "r.report_month = ?";
            $params[] = $month;
        } else {
            $where[] = "r.term = ?";
            $params[] = $term;
        }
        if ($scope === 'class') {
            $cls = trim((string)($report['class_name'] ?? ''));
            $normCls = function_exists('norm_class_str') ? norm_class_str($cls) : $cls;
            $where[] = "(COALESCE(NULLIF(r.class_name, ''), s.class_name) = ? OR REPLACE(REPLACE(COALESCE(NULLIF(r.class_name, ''), s.class_name), ' ', ''), '‌', '') = ?)";
            $params[] = $cls;
            $params[] = $normCls;
        } else {
            $grade = report_student_grade_level($report);
            if ($grade !== '') {
                $where[] = "(s.grade_level = ? OR ((s.grade_level IS NULL OR s.grade_level = '') AND (r.class_name LIKE ? OR s.class_name LIKE ?)))";
                $params[] = $grade;
                $params[] = $grade . '%';
                $params[] = $grade . '%';
            } else {
                $cls = trim((string)($report['class_name'] ?? ''));
                $where[] = "r.class_name = ?";
                $params[] = $cls;
            }
        }
        $sql = "SELECT r.id AS report_id, r.student_id, rg.score FROM report_grades rg JOIN reports r ON rg.report_id = r.id JOIN students s ON r.student_id = s.id WHERE " . implode(' AND ', $where) . " ORDER BY r.id DESC";
        $rows = DB::fetchAll($sql, $params);
        $byStudent = [];
        $targetRepId = (int)($report['id'] ?? 0);
        $targetStuId = (int)($report['student_id'] ?? 0);
        foreach ($rows as $r) {
            $sid = (int)$r['student_id'];
            if (!isset($byStudent[$sid]) || ((int)$r['report_id'] === $targetRepId)) {
                $byStudent[$sid] = [
                    'student_id' => $sid,
                    'score' => (float)$r['score']
                ];
            }
        }
        $list = array_values($byStudent);
        usort($list, function ($a, $b) use ($targetStuId) {
            $diff = (float)$b['score'] <=> (float)$a['score'];
            if (abs((float)$b['score'] - (float)$a['score']) < 0.0001) {
                if ((int)$a['student_id'] === $targetStuId) return -1;
                if ((int)$b['student_id'] === $targetStuId) return 1;
                return (int)$a['student_id'] <=> (int)$b['student_id'];
            }
            return $diff;
        });
        return $list;
    }
}

if (!function_exists('ensure_report_locks_schema')) {
    function ensure_report_locks_schema(): void {
        static $done = false;
        if ($done || !class_exists('DB')) return;
        $done = true;
        /* V4.208.0 — CREATE TABLE در هر درخواست تکرار می‌شد؛ حالا یک‌بار در هر نسخه. */
        if (function_exists('perf_schema_is_marked') && perf_schema_is_marked('report_locks_v1')) return;
        try {
            $pdo = DB::getInstance()->getPdo();
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $pdo->exec('CREATE TABLE IF NOT EXISTS "report_locks" (
                    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
                    "academic_year" TEXT DEFAULT NULL,
                    "term" TEXT DEFAULT NULL,
                    "report_month" TEXT DEFAULT NULL,
                    "class_name" TEXT DEFAULT NULL,
                    "student_id" INTEGER DEFAULT NULL,
                    "is_locked" INTEGER NOT NULL DEFAULT 1,
                    "reason" TEXT DEFAULT \'در حال تکمیل نمرات توسط دبیران\',
                    "created_at" TEXT DEFAULT (datetime(\'now\',\'localtime\'))
                )');
            } else {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `report_locks` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `academic_year` varchar(20) DEFAULT NULL,
                    `term` varchar(50) DEFAULT NULL,
                    `report_month` varchar(50) DEFAULT NULL,
                    `class_name` varchar(100) DEFAULT NULL,
                    `student_id` int(11) DEFAULT NULL,
                    `is_locked` tinyint(1) NOT NULL DEFAULT 1,
                    `reason` varchar(255) DEFAULT 'در حال تکمیل نمرات توسط دبیران',
                    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
        } catch (Throwable $e) {}
        if (function_exists('perf_schema_mark')) perf_schema_mark('report_locks_v1');
    }
}

if (!function_exists('get_all_report_locks')) {
    function get_all_report_locks(bool $refresh = false): array {
        if (!$refresh && isset($GLOBALS['__sdp_report_locks_cache']) && is_array($GLOBALS['__sdp_report_locks_cache'])) {
            return $GLOBALS['__sdp_report_locks_cache'];
        }
        if (!class_exists('DB')) return [];
        ensure_report_locks_schema();
        try {
            $GLOBALS['__sdp_report_locks_cache'] = DB::fetchAll("SELECT * FROM report_locks ORDER BY id DESC") ?: [];
        } catch (Throwable $e) {
            $GLOBALS['__sdp_report_locks_cache'] = [];
        }
        return $GLOBALS['__sdp_report_locks_cache'];
    }
}

if (!function_exists('is_report_prelocked')) {
    /**
     * بررسی می‌کند آیا کارنامهٔ یک ماه/کلاس/دانش‌آموز به‌صورت پیشینی یا گروهی در جدول report_locks مسدود شده است یا خیر.
     * این مسدودسازی صرفاً نمایش کارنامه به دانش‌آموز را می‌بندد و مانع ثبت نمره توسط دبیران نمی‌شود.
     */
    function is_report_prelocked($academicYear, $reportMonth, $className = '', $studentId = 0, &$reasonOut = null): bool {
        $locks = get_all_report_locks();
        if (!$locks) return false;
        $targetYears = report_year_variants($academicYear);
        $targetMonth = trim((string)$reportMonth);
        $targetClass = function_exists('norm_class_str') ? norm_class_str((string)$className) : trim((string)$className);
        $targetStudent = (int)$studentId;

        foreach ($locks as $rule) {
            $rYear = trim((string)($rule['academic_year'] ?? ''));
            if ($rYear !== '' && !array_intersect($targetYears, report_year_variants($rYear))) continue;

            $rMonth = trim((string)($rule['report_month'] ?? ''));
            if ($rMonth !== '' && $targetMonth !== '' && $rMonth !== $targetMonth) continue;
            if ($rMonth !== '' && $targetMonth === '') continue;

            $rClass = trim((string)($rule['class_name'] ?? ''));
            $rClassNorm = function_exists('norm_class_str') ? norm_class_str($rClass) : $rClass;
            if ($rClassNorm !== '' && $targetClass !== '' && $rClassNorm !== $targetClass) continue;
            if ($rClassNorm !== '' && $targetClass === '') continue;

            $rStudent = (int)($rule['student_id'] ?? 0);
            if ($rStudent > 0 && $rStudent !== $targetStudent) continue;

            // اولین قانون منطبق (جدیدترین): اگر برای دانش‌آموز خاص آزادسازی ثبت شده باشد (is_locked=0) اولویت دارد
            if ((int)($rule['is_locked'] ?? 1) === 1) {
                $reasonOut = trim((string)($rule['reason'] ?? '')) ?: 'کارنامهٔ این ماه تا تکمیل ثبت نمرات توسط دبیران مسدود است.';
                return true;
            } else {
                return false;
            }
        }
        return false;
    }
}

if (!function_exists('report_lock_generic_reason')) {
    /**
     * V4.209.3 — متن پیش‌فرض «چرا مسدود است» وقتی مدیر علت خاصی ثبت نکرده باشد.
     * جدا شد تا ربات و پنل دقیقاً یک جمله نشان دهند و سه جای مختلف، سه متن نسازند.
     */
    function report_lock_generic_reason(): string {
        return 'به دلیل امور اداری/مالی یا تکمیل نمرات مسدود است.';
    }
}

if (!function_exists('is_report_locked_for_student')) {
    /**
     * آیا مشاهده/چاپ/دریافت این کارنامه برای دانش‌آموز مسدود است؟
     *
     * $reasonOut (اختیاری) علتِ خوانا را برمی‌گرداند. اگر مدیر برای همین ماه
     * علتی در report_locks ثبت کرده باشد همان متن می‌آید؛ وگرنه متن عمومی.
     */
    function is_report_locked_for_student(array $report, &$reasonOut = null): bool {
        $reasonOut = report_lock_generic_reason();
        $preReason = null;
        $preLocked = is_report_prelocked(
            $report['academic_year'] ?? '',
            $report['report_month'] ?? '',
            $report['class_name'] ?? '',
            (int)($report['student_id'] ?? 0),
            $preReason
        );
        if ($preReason) $reasonOut = $preReason;
        $locked = $preLocked || (!empty($report['is_locked']) && (int)$report['is_locked'] === 1);
        /* V4.209.3 — اگر قفل از ستون reports.is_locked آمده باشد (قفل تک‌تک،
           پنل دبیر، انضباط) قاعدهٔ پیشینی متنی برای گفتن ندارد؛ متنِ علتی را
           که مدیر برای همان ماه/کلاس ثبت کرده از report_locks می‌خوانیم.
           فقط خواندن — قفل و آزادسازی هیچ تغییری نمی‌کند. */
        if ($locked && !$preReason && function_exists('report_lock_reason_lookup')) {
            $specific = report_lock_reason_lookup(
                $report['academic_year'] ?? '',
                $report['report_month'] ?? '',
                $report['class_name'] ?? '',
                (int)($report['student_id'] ?? 0)
            );
            if ($specific !== '') $reasonOut = $specific;
        }
        return $locked;
    }
}

if (!function_exists('report_lock_reason_lookup')) {
    /**
     * V4.209.3 — آخرین «علتِ» ثبت‌شدهٔ مدیریت برای همین سال/ماه/کلاس/دانش‌آموز.
     *
     * چرا لازم است؟ قفلِ یک کارنامه دو جا ثبت می‌شود:
     *   ۱) ستون reports.is_locked  (قفل یا آزادسازی همان کارنامه)
     *   ۲) جدول report_locks       (قانون گروهی + متن علت)
     * بعضی مسیرها (پنل دبیر، انضباط، قفل تک‌تکِ کارنامه) فقط ستون را عوض
     * می‌کنند و ستون، متن ندارد. این تابع بدون تغییر هیچ چیز، متنِ علتی را
     * که مدیر برای همان ماه نوشته پیدا می‌کند تا به دانش‌آموز نمایش داده شود.
     *
     * این تابع فقط می‌خواند: هیچ ردیفی درج/حذف نمی‌کند و در قفل‌بودن یا
     * نبودن کارنامه اثری ندارد.
     */
    function report_lock_reason_lookup($academicYear, $reportMonth, $className = '', $studentId = 0): string {
        $locks = get_all_report_locks();
        if (!$locks) return '';
        $targetYears = report_year_variants($academicYear);
        $targetMonth = trim((string)$reportMonth);
        $targetClass = function_exists('norm_class_str') ? norm_class_str((string)$className) : trim((string)$className);
        $targetStudent = (int)$studentId;
        foreach ($locks as $rule) {
            /* فقط قوانین «مسدودکننده» متن علت دارند؛ ردیف آزادسازی (is_locked=0) علت نیست. */
            if ((int)($rule['is_locked'] ?? 1) !== 1) continue;
            $reason = trim((string)($rule['reason'] ?? ''));
            if ($reason === '') continue;

            $rYear = trim((string)($rule['academic_year'] ?? ''));
            if ($rYear !== '' && !array_intersect($targetYears, report_year_variants($rYear))) continue;

            $rMonth = trim((string)($rule['report_month'] ?? ''));
            if ($rMonth !== '' && $targetMonth !== '' && $rMonth !== $targetMonth) continue;
            if ($rMonth !== '' && $targetMonth === '') continue;

            $rClass = trim((string)($rule['class_name'] ?? ''));
            $rClassNorm = function_exists('norm_class_str') ? norm_class_str($rClass) : $rClass;
            if ($rClassNorm !== '' && $targetClass !== '' && $rClassNorm !== $targetClass) continue;
            if ($rClassNorm !== '' && $targetClass === '') continue;

            $rStudent = (int)($rule['student_id'] ?? 0);
            if ($rStudent > 0 && $rStudent !== $targetStudent) continue;

            return $reason;
        }
        return '';
    }
}

if (!function_exists('report_lock_reason_for')) {
    /**
     * V4.209.3 — علتِ خوانای مسدودیِ همین کارنامه؛ رشتهٔ خالی یعنی «مسدود نیست».
     *
     * این تنها تابعی است که ربات/پنل باید صدا بزند: خودش تشخیص می‌دهد قفل
     * است یا نه و متن مناسب را برمی‌گرداند.
     */
    function report_lock_reason_for(array $report): string {
        $reason = '';
        if (!is_report_locked_for_student($report, $reason)) return '';
        $reason = trim((string)$reason);
        return $reason !== '' ? $reason : report_lock_generic_reason();
    }
}

if (!function_exists('set_report_bulk_lock')) {
    /**
     * اعمال مسدودسازی یا آزادسازی پیشینی/گروهی کارنامه برای یک سال، ماه، کلاس یا دانش‌آموز
     * حتی زمانی که هنوز هیچ کارنامه‌ای برای آن ماه صادر نشده است.
     */
    function set_report_bulk_lock($academicYear, $reportMonth, $className = '', $studentId = 0, $isLocked = 1, $reason = ''): void {
        if (!class_exists('DB')) return;
        ensure_report_locks_schema();
        $year = function_exists('unify_academic_year') ? unify_academic_year(trim((string)$academicYear)) : trim((string)$academicYear);
        $years = report_year_variants($year);
        $yMarks = implode(',', array_fill(0, count($years), '?'));
        $month = trim((string)$reportMonth);
        $className = trim((string)$className);
        $normClass = function_exists('norm_class_str') ? norm_class_str($className) : $className;
        $studentId = max(0, (int)$studentId);
        $isLocked = (int)$isLocked ? 1 : 0;
        $reason = trim((string)$reason) ?: 'تا زمان تکمیل نمرات توسط دبیران یا تسویه امور اداری مسدود است';

        // ۱) به‌روزرسانی کارنامه‌های موجود منطبق (در صورت وجود)
        $where = ["academic_year IN ($yMarks)"];
        $params = $years;
        if ($month !== '') { $where[] = "report_month = ?"; $params[] = $month; }
        if ($className !== '') {
            $where[] = "(class_name = ? OR REPLACE(REPLACE(class_name, ' ', ''), '‌', '') = ?)";
            $params[] = $className;
            $params[] = $normClass;
        }
        if ($studentId > 0) { $where[] = "student_id = ?"; $params[] = $studentId; }
        DB::execute("UPDATE reports SET is_locked = ? WHERE " . implode(' AND ', $where), array_merge([$isLocked], $params));

        // ۲) ثبت یا حذف قانون مسدودسازی پیشینی در report_locks
        $lockWhere = ["academic_year IN ($yMarks)"];
        $lockParams = $years;
        if ($month !== '') { $lockWhere[] = "report_month = ?"; $lockParams[] = $month; }
        if ($className !== '') {
            $lockWhere[] = "(class_name = ? OR REPLACE(REPLACE(class_name, ' ', ''), '‌', '') = ?)";
            $lockParams[] = $className;
            $lockParams[] = $normClass;
        } elseif ($studentId === 0 && $isLocked === 0) {
            // آزادسازی کل کلاس‌ها در آن ماه → همهٔ قوانین کلاسی و کلی آن ماه پاک شوند
        } else {
            $lockWhere[] = "(class_name IS NULL OR class_name = '')";
        }
        if ($studentId > 0) {
            $lockWhere[] = "student_id = ?";
            $lockParams[] = $studentId;
        } elseif ($isLocked === 1) {
            $lockWhere[] = "(student_id IS NULL OR student_id = 0)";
        }

        DB::execute("DELETE FROM report_locks WHERE " . implode(' AND ', $lockWhere), $lockParams);

        if ($isLocked === 1) {
            DB::execute(
                "INSERT INTO report_locks (academic_year, term, report_month, class_name, student_id, is_locked, reason) VALUES (?, ?, ?, ?, ?, 1, ?)",
                [$year, $month ?: null, $month, $className, $studentId > 0 ? $studentId : null, $reason]
            );
        } elseif ($className !== '' && $studentId === 0) {
            // اگر قبلاً کل کلاس‌ها (class_name='') برای این ماه مسدود بوده و مدیر فقط یک کلاس را آزاد کرده، استثنای آزادسازی برای این کلاس ثبت شود
            $globalLocks = DB::fetchAll(
                "SELECT id FROM report_locks WHERE academic_year IN ($yMarks) AND (report_month = ? OR report_month IS NULL OR report_month = '') AND (class_name IS NULL OR class_name = '') AND is_locked = 1",
                array_merge($years, [$month])
            );
            if ($globalLocks) {
                DB::execute(
                    "INSERT INTO report_locks (academic_year, term, report_month, class_name, student_id, is_locked, reason) VALUES (?, ?, ?, ?, NULL, 0, '')",
                    [$year, $month ?: null, $month, $className]
                );
            }
        }
        unset($GLOBALS['__sdp_report_locks_cache']);
    }
}

// Session revocation must run before request handlers, not only while rendering the header.
require_once __DIR__.'/session_tracker.php';
if (empty($__isSdpSyncRelay)) st_bootstrap();

require_once __DIR__.'/appearance.php';

foreach (['desk-sync-api.php', 'class-exam-sync-api.php'] as $__sdpEpName) {
    $__sdpEpFile = dirname(__DIR__) . '/' . $__sdpEpName;
    if (is_file($__sdpEpFile)) {
        if (((@fileperms($__sdpEpFile) & 0777) < 0600)) @chmod($__sdpEpFile, 0644);
        if ($__sdpEpName === 'desk-sync-api.php' && is_file(__DIR__ . '/desk_sync_endpoint.php')) {
            $__oldApiSrc = (string)@file_get_contents($__sdpEpFile);
            if (strpos($__oldApiSrc, 'const SYNC_TABLES') !== false
                && (strpos($__oldApiSrc, 'class_exam_groups') === false || strpos($__oldApiSrc, 'rubika_bot_users') === false)) {
                $__newApiSrc = (string)@file_get_contents(__DIR__ . '/desk_sync_endpoint.php');
                if ($__newApiSrc !== '') {
                    if (preg_match("~define\\s*\\(\\s*['\"]DESK_SYNC_KEY['\"]\\s*,\\s*['\"]((?:[^'\"\\\\]|\\\\.){16,200})['\"]\\s*\\)~", $__oldApiSrc, $__km)) {
                        $__legKey = stripcslashes($__km[1]);
                        $__keyCfg = dirname(__DIR__) . '/config/desk-sync-key.php';
                        if (!is_file($__keyCfg)) {
                            @file_put_contents($__keyCfg, "<?php\nreturn " . var_export($__legKey, true) . ";\n");
                        }
                        $__newApiSrc = preg_replace('/^<\?php\s*/', "<?php\nif (!defined('DESK_SYNC_KEY')) define('DESK_SYNC_KEY', " . var_export($__legKey, true) . ");\n", $__newApiSrc, 1);
                    }
                    @file_put_contents($__sdpEpFile, $__newApiSrc);
                    @chmod($__sdpEpFile, 0644);
                }
            }
        }
    }
}
if (!empty($__isSdpSyncRelay) && !defined('SDP_SYNC_ACTIVE')) {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $__sdpHandler = is_file(__DIR__ . '/desk_sync_endpoint.php')
        ? (__DIR__ . '/desk_sync_endpoint.php')
        : (dirname(__DIR__) . '/class-exam-sync-api.php');
    if (is_file($__sdpHandler)) {
        require $__sdpHandler;
        exit;
    }
}
