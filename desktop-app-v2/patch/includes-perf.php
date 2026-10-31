<?php
/**
 * includes/perf.php — هستهٔ کارایی SchoolDesk Pro (V4.208.0)
 * ---------------------------------------------------------------------------
 * این فایل سه کار انجام می‌دهد و هیچ رفتاری از رابط کاربری را تغییر نمی‌دهد:
 *
 *  ۱) «یک‌بار انجام بده» برای کارهای سنگینِ تکراری
 *     · perf_schema_once()  → DDL (ALTER/CREATE) فقط یک‌بار به‌ازای هر نسخه
 *     · perf_daily_once()   → کارهای دوره‌ای مثل همگام‌سازی عکس‌ها
 *
 *  ۲) کش دادهٔ سبک و قابل‌اعتماد (perf_cache_remember)
 *     · روی فایل، با «نسخهٔ کش» که با هر نوشتن در دیتابیس باطل می‌شود
 *     · اگر پوشهٔ کش قابل نوشتن نبود، بی‌صدا به اجرای مستقیم برمی‌گردد
 *
 *  ۳) بسته‌بندی دارایی‌های استاتیک (perf_asset_bundle)
 *     · ۱۲ درخواست CSS/JS به ۲ درخواست با کش یک‌ساله تبدیل می‌شود
 *     · خروجی در assets/cache/ با نامِ هش‌دار نوشته می‌شود (immutable)
 *
 * اصل حاکم: هر تابع باید در بدترین حالت (پوشهٔ غیرقابل‌نوشتن، فایل گم‌شده،
 * نسخهٔ قدیمی PHP) همان نتیجهٔ قبلی را بدهد — فقط کندتر. هیچ خطای جدیدی
 * نباید به کاربر نشان داده شود. به همین دلیل همهٔ نوشتن‌ها با @ و
 * try/catch محافظت شده‌اند.
 */

if (!defined('PERF_CORE_VERSION')) {
    define('PERF_CORE_VERSION', '4.209.3');
}

/* ═══════════════════════════════════════════════════════════════════════
   ۱) پیکربندی و نسخه
   ═══════════════════════════════════════════════════════════════════════ */

if (!function_exists('perf_root')) {
    /** ریشهٔ نصب (پوشهٔ سایت/دسکتاپ). */
    function perf_root() {
        return dirname(__DIR__);
    }
}

if (!function_exists('perf_config')) {
    /**
     * پیکربندی اختیاری از config/perf.php.
     * نبودِ فایل یعنی «همه‌چیز روشن با پیش‌فرض‌های امن».
     * برای خاموش‌کردن یک بخش، مثلاً: <?php return ['assets'=>false];
     */
    function perf_config($key = null, $default = null) {
        static $cfg = null;
        if ($cfg === null) {
            $cfg = [];
            $file = perf_root() . '/config/perf.php';
            if (is_file($file)) {
                try { $loaded = require $file; if (is_array($loaded)) $cfg = $loaded; } catch (Throwable $e) { $cfg = []; }
            }
        }
        if ($key === null) return $cfg;
        return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
    }
}

if (!function_exists('perf_app_version')) {
    /** نسخهٔ کد (config/version.php) — مبنای «یک‌بار در هر نسخه» بودن مایگریشن‌ها. */
    function perf_app_version() {
        static $v = null;
        if ($v === null) {
            $v = '0';
            $file = perf_root() . '/config/version.php';
            if (is_file($file)) {
                try {
                    $stamp = require $file;
                    if (is_array($stamp)) $v = (string)($stamp['site_version'] ?? $stamp['desktop_version'] ?? '0');
                } catch (Throwable $e) { $v = '0'; }
            }
        }
        return $v;
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   ۲) پوشه‌های حالت و کش
   ═══════════════════════════════════════════════════════════════════════ */

if (!function_exists('perf_first_writable_dir')) {
    /**
     * اولین پوشهٔ قابل نوشتن از فهرست پیشنهادی؛ null یعنی هیچ‌کدام.
     * محافظت با هر دو سینتکس Apache 2.2 و 2.4 نوشته می‌شود تا روی هیچ
     * سروری خطای ۵۰۰ تولید نکند.
     */
    function perf_first_writable_dir(array $candidates, $protect = true) {
        $htaccess = "Options -Indexes\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
        foreach ($candidates as $dir) {
            if ($dir === '') continue;
            if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
            if (is_dir($dir) && is_writable($dir)) {
                if ($protect && !is_file($dir . '/.htaccess')) { @file_put_contents($dir . '/.htaccess', $htaccess); }
                return rtrim($dir, '/\\');
            }
        }
        return null;
    }
}

if (!function_exists('perf_state_dir')) {
    /**
     * محل نگه‌داری نشانه‌های «یک‌بار انجام شد».
     * config/.state نخستین گزینه است چون پوشهٔ config از قبل محافظت‌شده است.
     */
    function perf_state_dir() {
        static $dir = false;
        if ($dir === false) {
            $root = perf_root();
            $dir = perf_first_writable_dir([
                $root . '/config/.state',
                $root . '/cache/.state',
                rtrim(sys_get_temp_dir(), '/\\') . '/schooldesk-state',
            ]);
        }
        return $dir;
    }
}

if (!function_exists('perf_cache_dir')) {
    /** پوشهٔ کش داده. در صورت عدم دسترسی، null (کش خاموش می‌شود). */
    function perf_cache_dir() {
        static $dir = false;
        if ($dir === false) {
            $dir = perf_first_writable_dir([perf_root() . '/cache/perf']);
        }
        return $dir;
    }
}

if (!function_exists('perf_safe_key')) {
    function perf_safe_key($value) {
        return preg_replace('/[^A-Za-z0-9._-]/', '-', (string)$value);
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   ۳) «یک‌بار در هر نسخه» — جایگزین امنِ DDL در هر درخواست
   ═══════════════════════════════════════════════════════════════════════ */

if (!function_exists('perf_schema_once')) {
    /**
     * اجرای یک مایگریشن فقط یک‌بار برای هر نسخه.
     *
     * پیش از این، هر بار باز شدن یک صفحه چند ALTER TABLE/CREATE TABLE اجرا
     * می‌شد (پنج ALTER در ensure_school_roles_schema، سه DDL در students.php و…).
     * روی MySQL هر ALTER یک رفت‌وبرگشت + تلاش برای قفل متادیتا است؛ نتیجه:
     * TTFB نزدیک یک ثانیه برای صفحهٔ مدیریت دانش‌آموزان.
     *
     * @param string   $id شناسهٔ یکتای مایگریشن
     * @param callable $fn کار سنگین
     * @return bool true اگر همین حالا اجرا شد
     */
    function perf_schema_once($id, callable $fn) {
        static $done = [];
        $markerId = perf_safe_key($id) . '-' . perf_safe_key(perf_app_version());
        if (isset($done[$markerId])) return false;
        $dir = perf_state_dir();
        $marker = $dir ? $dir . '/schema-' . $markerId . '.lock' : null;
        if ($marker && is_file($marker)) { $done[$markerId] = true; return false; }
        try { $fn(); } catch (Throwable $e) { /* همان رفتار قبلی: خطا نادیده گرفته می‌شود */ }
        if ($marker) { @file_put_contents($marker, gmdate('c')); }
        $done[$markerId] = true;
        return true;
    }
}

if (!function_exists('perf_schema_mark')) {
    /** ثبت دستی «انجام شد» (مثلاً وقتی مایگریشن داخل نصب انجام شده است). */
    function perf_schema_mark($id) {
        $dir = perf_state_dir();
        if (!$dir) return false;
        return false !== @file_put_contents($dir . '/schema-' . perf_safe_key($id) . '-' . perf_safe_key(perf_app_version()) . '.lock', gmdate('c'));
    }
}

if (!function_exists('perf_schema_is_marked')) {
    function perf_schema_is_marked($id) {
        $dir = perf_state_dir();
        if (!$dir) return false;
        return is_file($dir . '/schema-' . perf_safe_key($id) . '-' . perf_safe_key(perf_app_version()) . '.lock');
    }
}

if (!function_exists('perf_daily_once')) {
    /**
     * کاری که حداکثر یک‌بار در هر بازهٔ زمانی اجرا می‌شود (نه در هر بازدید).
     * برای کارهای دوره‌ای مثل همگام‌سازی عکس‌های داخل uploads.
     */
    function perf_daily_once($id, callable $fn, $seconds = 3600, $signature = '') {
        static $done = [];
        $key = perf_safe_key($id);
        if (isset($done[$key])) return false;
        $done[$key] = true;
        $dir = perf_state_dir();
        if (!$dir) { try { $fn(); } catch (Throwable $e) {} return true; }
        $marker = $dir . '/daily-' . $key . '.lock';
        $stamp = is_file($marker) ? (string)@file_get_contents($marker) : '';
        $parts = explode('|', $stamp);
        $when = (int)($parts[0] ?? 0);
        $sig = (string)($parts[1] ?? '');
        if ($when > 0 && (time() - $when) < max(1, (int)$seconds) && ($signature === '' || $sig === (string)$signature)) {
            return false;
        }
        try { $fn(); } catch (Throwable $e) {}
        @file_put_contents($marker, time() . '|' . $signature);
        return true;
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   ۳.۵) حذف تکرار DDL در سطح لایهٔ دیتابیس
   ───────────────────────────────────────────────────────────────────────
   در کل کد بیست‌ها دستور ALTER TABLE / CREATE TABLE وجود دارد که «برای
   اطمینان» در هر درخواست دوباره اجرا می‌شوند، چون خطای «ستون موجود است»
   بی‌صدا بلعیده می‌شود. روی MySQL هر ALTER حتی در همین حالت یک رفت‌وبرگشت
   شبکه + تلاش برای قفل متادیتا دارد.

   این دو تابع، بدون تغییر هیچ فایلی از کد، فقط همان DDL‌هایی را دوباره اجرا
   می‌کنند که هنوز در این نسخه موفق نشده‌اند. اولین اجرای موفق ثبت می‌شود.
   DROP/TRUNCATE هرگز کش نمی‌شوند.
   ═══════════════════════════════════════════════════════════════════════ */

if (!function_exists('perf_ddl_key')) {
    function perf_ddl_key($sql) {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim((string)$sql)));
        return 'ddl-' . sha1($normalized) . '-' . perf_safe_key(perf_app_version()) . '.ok';
    }
}

if (!function_exists('perf_ddl_skip')) {
    /** آیا این دستور قبلاً در همین نسخه با موفقیت اجرا شده است؟ */
    function perf_ddl_skip($sql) {
        if (!perf_config('ddl_cache', true)) return false;
        $sql = (string)$sql;
        if (!preg_match('/^\s*(?:ALTER|CREATE)\s+/i', $sql)) return false;
        if (preg_match('/^\s*CREATE\s+(?:TEMPORARY|OR\s+REPLACE)/i', $sql)) return false;
        $dir = perf_state_dir();
        if (!$dir) return false;
        return is_file($dir . '/' . perf_ddl_key($sql));
    }
}

if (!function_exists('perf_ddl_mark')) {
    /** ثبت موفقیت یک DDL برای نسخهٔ جاری. */
    function perf_ddl_mark($sql) {
        if (!perf_config('ddl_cache', true)) return false;
        $sql = (string)$sql;
        if (!preg_match('/^\s*(?:ALTER|CREATE)\s+/i', $sql)) return false;
        if (preg_match('/^\s*CREATE\s+(?:TEMPORARY|OR\s+REPLACE)/i', $sql)) return false;
        $dir = perf_state_dir();
        if (!$dir) return false;
        return false !== @file_put_contents($dir . '/' . perf_ddl_key($sql), gmdate('c'));
    }
}

if (!function_exists('perf_ddl_reset')) {
    /** پاک‌کردن نشانه‌های DDL — برای وقتی که مدیر می‌خواهد مایگریشن‌ها دوباره اجرا شوند. */
    function perf_ddl_reset() {
        $dir = perf_state_dir();
        if (!$dir) return 0;
        $n = 0;
        foreach ((array)@glob($dir . '/ddl-*.ok') as $f) { if (@unlink($f)) $n++; }
        foreach ((array)@glob($dir . '/schema-*.lock') as $f) { if (@unlink($f)) $n++; }
        return $n;
    }
}

if (!function_exists('perf_write_is_ephemeral')) {
    /**
     * آیا این نوشتن، فقط «حالت گذرا» را عوض می‌کند؟
     *
     * بودن/نبودنِ این تفکیک مهم است: جدول user_sessions هر ۶۰ ثانیه برای
     * به‌روزرسانی «آخرین فعالیت» نوشته می‌شود. اگر هر نوشتن کش داده را باطل
     * کند، کش آمار فهرست دانش‌آموزان عملاً هر دقیقه یک‌بار از نو ساخته
     * می‌شود. نوشتن در این جدول‌ها هیچ‌کدام از اعداد کش‌شده را عوض نمی‌کند.
     */
    function perf_write_is_ephemeral($sql) {
        static $ephemeral = [
            'user_sessions' => true,
            'activity_logs' => true,
            'desk_change_log' => true,
            'notifications' => true,
            'bot_outbox' => true,
            'sessions' => true,
        ];
        if (!preg_match('/^\s*(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+`?([A-Za-z0-9_]+)`?/i', (string)$sql, $m)) return false;
        return isset($ephemeral[strtolower($m[1])]);
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   ۴) کش داده — با نسخه‌ای که هر نوشتن آن را باطل می‌کند
   ═══════════════════════════════════════════════════════════════════════ */

if (!function_exists('perf_cache_version_file')) {
    function perf_cache_version_file() {
        $dir = perf_cache_dir();
        return $dir ? $dir . '/version' : null;
    }
}

if (!function_exists('perf_cache_version')) {
    /** شناسهٔ نسخهٔ کش؛ هر نوشتن در دیتابیس آن را عوض می‌کند. */
    function perf_cache_version() {
        if (array_key_exists('__perf_cache_version', $GLOBALS)) return $GLOBALS['__perf_cache_version'];
        $v = '0';
        $file = perf_cache_version_file();
        if ($file && is_file($file)) {
            $raw = (string)@file_get_contents($file);
            if ($raw !== '') $v = $raw;
        }
        $GLOBALS['__perf_cache_version'] = $v;
        return $v;
    }
}

if (!function_exists('perf_cache_bump')) {
    /** باطل‌کردن کش — حداکثر یک‌بار در هر درخواست. */
    function perf_cache_bump() {
        static $bumped = false;
        if ($bumped) return;
        $bumped = true;
        $file = perf_cache_version_file();
        if (!$file) return;
        @file_put_contents($file, sprintf('%.6F', microtime(true)), LOCK_EX);
        // نسخهٔ درون‌حافظه‌ای همین درخواست هم باید عوض شود
        unset($GLOBALS['__perf_cache_version']);
    }
}

if (!function_exists('perf_cache_flush')) {
    /** پاک‌کردن کامل کش داده (نه دارایی‌های استاتیک). */
    function perf_cache_flush() {
        $dir = perf_cache_dir();
        if (!$dir) return 0;
        $n = 0;
        foreach ((array)@glob($dir . '/*.cache') as $file) {
            if (is_file($file) && @unlink($file)) $n++;
        }
        perf_cache_bump();
        return $n;
    }
}

if (!function_exists('perf_cache_remember')) {
    /**
     * کش فایل‌محورِ نتیجهٔ یک محاسبهٔ سنگین.
     *
     * @param string   $key   کلید یکتا
     * @param int      $ttl   ثانیهٔ اعتبار
     * @param callable $fn    تولیدکنندهٔ داده
     * @return mixed
     */
    function perf_cache_remember($key, $ttl, callable $fn) {
        if (!perf_config('data_cache', true) || (int)$ttl <= 0) return $fn();
        $dir = perf_cache_dir();
        if (!$dir) return $fn();
        $file = $dir . '/' . sha1($key . '|' . perf_cache_version()) . '.cache';
        if (is_file($file)) {
            $age = time() - (int)@filemtime($file);
            if ($age >= 0 && $age <= $ttl) {
                $raw = @file_get_contents($file);
                if (is_string($raw) && $raw !== '') {
                    $data = @unserialize($raw, ['allowed_classes' => false]);
                    if ($data !== false || $raw === serialize(false)) return $data;
                }
            }
        }
        $value = $fn();
        @file_put_contents($file . '.' . getmypid() . '.tmp', serialize($value), LOCK_EX);
        @rename($file . '.' . getmypid() . '.tmp', $file);
        return $value;
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   ۵) بسته‌بندی دارایی‌های استاتیک (CSS/JS)
   ═══════════════════════════════════════════════════════════════════════ */

if (!function_exists('perf_minify_css')) {
    /**
     * کوچک‌سازی محافظه‌کارانهٔ CSS.
     * رشته‌ها (content:"…") و url(...) دست‌نخورده می‌مانند.
     */
    function perf_minify_css($css) {
        $out = '';
        $len = strlen($css);
        $i = 0;
        $quote = null;
        while ($i < $len) {
            $ch = $css[$i];
            if ($quote !== null) {
                $out .= $ch;
                if ($ch === '\\' && $i + 1 < $len) { $out .= $css[$i + 1]; $i += 2; continue; }
                if ($ch === $quote) $quote = null;
                $i++;
                continue;
            }
            if ($ch === '"' || $ch === "'") { $quote = $ch; $out .= $ch; $i++; continue; }
            if ($ch === '/' && $i + 1 < $len && $css[$i + 1] === '*') {
                $end = strpos($css, '*/', $i + 2);
                $i = ($end === false) ? $len : $end + 2;
                continue;
            }
            if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
                $out .= ' ';
                while ($i < $len && strpos(" \t\n\r", $css[$i]) !== false) $i++;
                continue;
            }
            $out .= $ch;
            $i++;
        }
        // فاصله‌های بی‌مصرف
        $out = preg_replace('/\s*([{};:,>])\s*/', '$1', $out);
        $out = preg_replace('/;}/', '}', $out);
        return trim($out);
    }
}

if (!function_exists('perf_asset_signature')) {
    function perf_asset_signature(array $files) {
        $sig = [];
        foreach ($files as $rel) {
            $rel = ltrim(str_replace('\\', '/', (string)$rel), '/');
            $path = perf_root() . '/' . $rel;
            if (!is_file($path)) continue;
            $sig[] = $rel . ':' . filesize($path) . ':' . filemtime($path);
        }
        return $sig ? implode('|', $sig) : '';
    }
}

if (!function_exists('perf_screen_font_map')) {
    /**
     * نگاشت «فایل قلمِ uploads» به «نسخهٔ WOFF2 موجود در assets/fonts/screen».
     * از manifest می‌خوانیم و نام فایل را هم کلید می‌کنیم تا مسیرهای
     * .woff2/.woff/.ttf هر سه به همان یک فایل سالم برسند.
     */
    function perf_screen_font_map() {
        static $map = null;
        if ($map !== null) return $map;
        $map = [];
        $dir = perf_root() . '/assets/fonts/screen/';
        if (is_file($dir . 'manifest.json')) {
            $json = json_decode((string)@file_get_contents($dir . 'manifest.json'), true);
            if (is_array($json)) {
                foreach ($json as $src => $item) {
                    $url = is_array($item) ? (string)($item['url'] ?? '') : '';
                    if ($url === '' || !is_file(perf_root() . '/' . $url)) continue;
                    $map[basename((string)$src)] = $url;
                }
            }
        }
        foreach ([
            'Yekan.woff2' => 'assets/fonts/screen/Yekan.woff2',
            'Yekan.woff' => 'assets/fonts/screen/Yekan.woff2',
            'Yekan.ttf' => 'assets/fonts/screen/Yekan.woff2',
            'Sahel.woff2' => 'assets/fonts/screen/Sahel.woff2',
            'Sahel.ttf' => 'assets/fonts/screen/Sahel.woff2',
            'Vazirmatn-Regular.woff2' => 'assets/fonts/screen/Vazirmatn-Regular.woff2',
            'Vazirmatn-Regular.ttf' => 'assets/fonts/screen/Vazirmatn-Regular.woff2',
            'Vazirmatn-Bold.woff2' => 'assets/fonts/screen/Vazirmatn-Bold.woff2',
            'Vazirmatn-Bold.ttf' => 'assets/fonts/screen/Vazirmatn-Bold.woff2',
        ] as $k => $v) {
            if (!isset($map[$k]) && is_file(perf_root() . '/' . $v)) $map[$k] = $v;
        }
        return $map;
    }
}

if (!function_exists('perf_css_fix_font_urls')) {
    /**
     * تعمیر آدرس قلم‌ها در CSS هنگام بسته‌بندی.
     *
     * style.css مسیر ../../uploads/Yekan/Yekan.woff2 را اعلام می‌کند، ولی روی
     * بیشتر نصب‌ها فقط .ttf وجود دارد. آن درخواست ۴۰۴ می‌شود و در nginx به PHP
     * می‌رسد و کل برنامه را برای یک فایل اجرا می‌کند (در trace واقعی ۷۲۵ms و
     * بعد ۱۱۲ms با مایم‌تایپ text/html = صفحهٔ PHP به‌جای فونت).
     *
     *   • فایل مقصد موجود است → دست نمی‌زند
     *   • نسخهٔ WOFF2 در assets/fonts/screen هست → همان جایگزین می‌شود
     *   • وگرنه آن گزینه حذف می‌شود؛ اگر هیچ نماند کل @font-face حذف می‌شود
     */
    function perf_css_fix_font_urls($css) {
        $map = perf_screen_font_map();
        $out = preg_replace_callback('/src\s*:\s*([^;}]+)/i', function ($m) use ($map) {
            $decl = $m[1];
            if (stripos($decl, 'url(') === false) return $m[0];
            $parts = preg_split('/\s*,\s*(?=(?:local|url)\()/i', trim($decl));
            $kept = [];
            foreach ($parts as $part) {
                if (!preg_match('/url\(\s*[\x27"]?([^\x27")]+)[\x27"]?\s*\)/i', $part, $u)) { $kept[] = $part; continue; }
                $raw = trim($u[1]);
                if (stripos($raw, 'uploads/') === false) { $kept[] = $part; continue; }
                $rel = ltrim(preg_replace('#^(\.\./)+#', '', $raw), '/');
                $rel = str_replace('\\', '/', $rel);
                if (is_file(perf_root() . '/' . $rel)) { $kept[] = $part; continue; }
                $base = basename($rel);
                if (isset($map[$base])) {
                    $target = '../fonts/screen/' . basename($map[$base]);
                    $kept[] = preg_replace('/url\(\s*[\x27"]?[^\x27")]+[\x27"]?\s*\)/i', 'url(' . $target . ')', $part);
                }
            }
            if (!$kept) return '';
            return 'src:' . implode(',', $kept);
        }, $css);
        return $out === null ? $css : $out;
    }
}

if (!function_exists('perf_css_drop_empty_faces')) {
    /** حذف @font-faceهایی که پس از تعمیر هیچ src سالمی ندارند. */
    function perf_css_drop_empty_faces($css) {
        $out = preg_replace_callback('/@font-face\s*\{[^{}]*\}/i', function ($m) {
            return preg_match('/src\s*:/i', $m[0]) ? $m[0] : '';
        }, $css);
        return $out === null ? $css : $out;
    }
}

if (!function_exists('perf_asset_bundle')) {
    /**
     * ساخت (یا یافتن) یک فایل بستهٔ CSS/JS از چند فایل منبع.
     *
     * @return string|null مسیر نسبی قابل استفاده در href/src یا null
     */
    function perf_asset_bundle(array $files, $type = 'css') {
        $type = strtolower($type) === 'js' ? 'js' : 'css';
        if (!perf_config('assets', true)) return null;
        $sig = perf_asset_signature($files);
        if ($sig === '') return null;
        $hash = substr(sha1($sig . '|' . $type . '|' . PERF_CORE_VERSION), 0, 14);
        $relOut = 'assets/cache/' . $type . '-' . $hash . '.' . $type;
        $absOut = perf_root() . '/' . $relOut;
        if (is_file($absOut) && filesize($absOut) > 0) return $relOut;

        $dir = perf_root() . '/assets/cache';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (!is_dir($dir) || !is_writable($dir)) return null;
        /* روی Apache، همین پوشه باید با کش یک‌ساله سرو شود؛ وگرنه هر بار
           بازدید مرورگر دوباره باندل را می‌گیرد. (nginx با perf-config تنظیم
           می‌شود.) محتوای فایل فقط «اجازهٔ دسترسی + کش» است. */
        if (function_exists('perf_cache_web_headers')) perf_cache_web_headers($dir);

        $buffer = '';
        foreach ($files as $rel) {
            $rel = ltrim(str_replace('\\', '/', (string)$rel), '/');
            $path = perf_root() . '/' . $rel;
            if (!is_file($path)) continue;
            $body = @file_get_contents($path);
            if ($body === false) continue;
            if ($type === 'css') {
                /* V4.209.0 — آدرس قلم‌های ناموجود پیش از کوچک‌سازی تعمیر می‌شود. */
                $body = perf_css_drop_empty_faces(perf_css_fix_font_urls($body));
                $buffer .= perf_minify_css($body) . "\n";
            } else {
                // جداسازی با ; تا فایلی که بدون «;» تمام شده، فایل بعدی را نشکند.
                $buffer .= $body . "\n;\n";
            }
        }
        if ($buffer === '') return null;
        if ($type === 'css') $buffer = "/* SchoolDesk Pro asset bundle — generated; do not edit. */\n" . $buffer;
        $tmp = $absOut . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $buffer) === false) return null;
        if (!@rename($tmp, $absOut)) { @unlink($tmp); return null; }
        return $relOut;
    }
}

if (!function_exists('perf_asset_url')) {
    /**
     * لینک نهایی دارایی: بستهٔ هش‌دار → وگرنه همان فایل اصلی با ?v=mtime
     * (یعنی اگر پوشهٔ assets/cache قابل نوشتن نبود، سایت مثل قبل کار می‌کند).
     */
    function perf_asset_url(array $files, $type = 'css') {
        $bundle = perf_asset_bundle($files, $type);
        if ($bundle) return $bundle;
        $first = '';
        foreach ($files as $rel) {
            $rel = ltrim(str_replace('\\', '/', (string)$rel), '/');
            if (is_file(perf_root() . '/' . $rel)) { $first = $rel; break; }
        }
        if ($first === '') return '';
        $v = @filemtime(perf_root() . '/' . $first);
        return $first . ($v ? '?v=' . $v : '');
    }
}

if (!function_exists('perf_css_base_files')) {
    /** فایل‌های باندل پایه (هر صفحه‌ای لازم دارد). */
    function perf_css_base_files() {
        return ['assets/css/style.css', 'assets/css/ui-modern.css'];
    }
}

if (!function_exists('perf_css_app_files')) {
    /** لایهٔ رابط مدرسه + استایل اختصاصی صفحه (اختیاری). */
    function perf_css_app_files(array $extra = []) {
        return array_merge(['assets/css/school-ui.css', 'assets/css/management-hub.css'], $extra);
    }
}

if (!function_exists('perf_js_core_files')) {
    /**
     * اسکریپت‌های مشترک همهٔ صفحات.
     * session-watch عمداً اینجا نیست تا باندل «پنل» و «چاپ/اسکنر» یکی باشد و
     * یک‌بار دانلود و برای همیشه کش شود.
     */
    function perf_js_core_files() {
        return ['assets/js/school-icons.js', 'assets/js/school-ui.js', 'assets/js/main.js',
                'assets/js/ui-modern.js', 'assets/js/searchable-select.js'];
    }
}

if (!function_exists('perf_bundle_url')) {
    /**
     * آدرس باندل برای فهرست فایل‌ها؛ «?v=…» و تکرارها پاک می‌شوند.
     * رشتهٔ خالی یعنی باندل ساخته نشد (پوشهٔ کش قابل نوشتن نیست) و فراخوان
     * باید همان <link>/<script>های قبلی را بگذارد.
     */
    function perf_bundle_url(array $files, $type = 'css') {
        $clean = [];
        foreach ($files as $f) {
            $f = trim((string)$f);
            if ($f === '') continue;
            $f = (string)preg_replace('/[?#].*$/', '', $f);
            $f = ltrim(str_replace('\\', '/', $f), '/');
            $clean[$f] = true;
        }
        if (!$clean) return '';
        if (!function_exists('perf_asset_url')) return '';
        return (string)perf_asset_url(array_keys($clean), $type);
    }
}

if (!function_exists('perf_cache_web_headers')) {
    /**
     * پوشهٔ عمومی assets/cache باید با کش یک‌ساله و immutable سرو شود؛
     * وگرنه باندلِ هش‌دار در هر بازدید دوباره داوری می‌شود.
     */
    function perf_cache_web_headers($dir) {
        $file = rtrim($dir, '/\\') . '/.htaccess';
        if (is_file($file)) return;
        @file_put_contents($file,
            "Options -Indexes\n"
            . "<IfModule mod_expires.c>\n    ExpiresActive On\n"
            . "    ExpiresByType text/css \"access plus 1 year\"\n"
            . "    ExpiresByType application/javascript \"access plus 1 year\"\n</IfModule>\n"
            . "<IfModule mod_headers.c>\n    Header set Cache-Control \"public, max-age=31536000, immutable\"\n</IfModule>\n");
    }
}

if (!function_exists('perf_inline_js')) {
    /**
     * تبدیل یک قطعهٔ JS ثابت به فایل کش‌شده با نام هش‌دار.
     * یک <script> درون‌خطی ۷ کیلوبایتی در trace واقعی ۴۳ms از رشتهٔ اصلی را
     * گرفت؛ همان کد به‌صورت فایل، یک‌بار کامپایل و برای همیشه کش می‌شود.
     */
    function perf_inline_js($code, $name, $minBytes = 1200) {
        $code = (string)$code;
        if (strlen($code) < (int)$minBytes || !perf_config('assets', true)) return '<script>' . $code . '</script>';
        $dir = perf_root() . '/assets/cache';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return '<script>' . $code . '</script>';
        if (!is_dir($dir) || !is_writable($dir)) return '<script>' . $code . '</script>';
        /* همان کش یک‌سالهٔ immutable که باندل‌ها دارند؛ وگرنه فایل درون‌خطیِ
           تازه‌ساخته هم در هر بازدید شرطی (304) گرفته می‌شود. */
        if (function_exists('perf_cache_web_headers')) {
            perf_cache_web_headers($dir);
        }
        $hash = substr(sha1('inline|' . $name . '|' . $code . '|' . PERF_CORE_VERSION), 0, 14);
        $rel = 'assets/cache/js-i' . $hash . '.js';
        $abs = perf_root() . '/' . $rel;
        if (!is_file($abs)) {
            $tmp = $abs . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, $code) === false || !@rename($tmp, $abs)) { @unlink($tmp); return '<script>' . $code . '</script>'; }
        }
        return '<script defer src="' . $rel . '"></script>';
    }
}

if (!function_exists('perf_asset_clean')) {
    /** حذف بسته‌های بی‌استفاده (فایل‌هایی که دیگر به آن‌ها ارجاعی نیست). */
    function perf_asset_clean($keepSeconds = 604800) {
        $dir = perf_root() . '/assets/cache';
        if (!is_dir($dir)) return 0;
        $n = 0;
        // GLOB_BRACE روی همهٔ سیستم‌ها (مثلاً Alpine/musl) موجود نیست؛ دو glob جدا.
        foreach (array_merge((array)@glob($dir . '/*.css'), (array)@glob($dir . '/*.js')) as $file) {
            if (is_file($file) && (time() - (int)@filemtime($file)) > $keepSeconds && @unlink($file)) $n++;
        }
        return $n;
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   ۶) ایندکس‌های حیاتی — یک‌بار در هر نسخه
   ═══════════════════════════════════════════════════════════════════════ */

if (!function_exists('perf_index_list')) {
    /**
     * ایندکس‌هایی که کوئری‌های صفحهٔ «مدیریت دانش‌آموزان» و کارنامه‌ها
     * بدون آن‌ها کل جدول را می‌گردند. همه با IF NOT EXISTS تلاش می‌شوند و
     * خطای «از قبل هست» بی‌صدا نادیده گرفته می‌شود.
     */
    function perf_index_list() {
        return [
            ['students', 'idx_students_year_grade_class', '(academic_year, grade_level, class_name)'],
            ['students', 'idx_students_year', '(academic_year)'],
            ['students', 'idx_students_class_year', '(class_name, academic_year)'],
            ['reports', 'idx_reports_year_student', '(academic_year, student_id)'],
            ['reports', 'idx_reports_month', '(report_month)'],
            ['report_grades', 'idx_grades_report_subject', '(report_id, subject_name)'],
            ['report_grades', 'idx_grades_subject', '(subject_name)'],
            ['student_discipline_records', 'idx_discipline_student_status', '(student_id, review_status)'],
        ];
    }
}

if (!function_exists('perf_ensure_indexes')) {
    /**
     * ساخت ایندکس‌های گم‌شده؛ فقط یک‌بار برای هر نسخه.
     * روی SQLite با CREATE INDEX IF NOT EXISTS، روی MySQL با ALTER TABLE ADD INDEX.
     * @return array پیام‌های نتیجه (برای صفحهٔ کارایی)
     */
    function perf_ensure_indexes($force = false) {
        $log = [];
        $run = function () use (&$log) {
            $sqlite = false;
            try { $sqlite = DB::isSqliteDriver(); } catch (Throwable $e) { $sqlite = false; }
            foreach (perf_index_list() as $item) {
                [$table, $name, $cols] = $item;
                try {
                    if ($sqlite) {
                        DB::execute('CREATE INDEX IF NOT EXISTS ' . $name . ' ON ' . $table . ' ' . $cols);
                    } else {
                        DB::execute('ALTER TABLE `' . $table . '` ADD INDEX `' . $name . '` ' . $cols);
                    }
                    $log[] = $table . '.' . $name . ' → ساخته شد';
                } catch (Throwable $e) {
                    $msg = (string)$e->getMessage();
                    $log[] = $table . '.' . $name . (stripos($msg, 'Duplicate') !== false || stripos($msg, 'exists') !== false
                        ? ' → از قبل موجود است' : ' → ' . mb_substr($msg, 0, 120));
                }
            }
        };
        if ($force) { $run(); } else { perf_schema_once('perf_indexes_v1', $run); }
        return $log;
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   ۷) سنجه‌های درخواست (برای صفحهٔ کارایی و تست‌ها)
   ═══════════════════════════════════════════════════════════════════════ */

if (!function_exists('perf_boot')) {
    function perf_boot() {
        if (empty($GLOBALS['__perf_t0'])) $GLOBALS['__perf_t0'] = microtime(true);
    }
}

if (!function_exists('perf_elapsed_ms')) {
    function perf_elapsed_ms() {
        $t0 = $GLOBALS['__perf_t0'] ?? null;
        return $t0 ? (microtime(true) - $t0) * 1000 : 0.0;
    }
}

if (!function_exists('perf_view_cache_enabled')) {
    /** آیا کش داده روشن است؟ */
    function perf_view_cache_enabled() {
        return (bool)perf_config('data_cache', true) && perf_cache_dir() !== null;
    }
}

perf_boot();
