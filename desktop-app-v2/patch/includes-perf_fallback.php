<?php
/**
 * includes/perf_fallback.php — جایگزین «بی‌خطر» هستهٔ کارایی (V4.208.0)
 * ---------------------------------------------------------------------------
 * اگر برای هر دلیلی فایل includes/perf.php روی نصب موجود نباشد (مثلاً کسی
 * فقط header.php یا functions.php را از این بسته کپی کرده باشد)، این فایل
 * توابع هم‌نام را با رفتار «همان نسخهٔ قبلی» تعریف می‌کند تا صفحه هرگز با
 * خطای Fatal از کار نیفتد:
 *
 *   · perf_asset_url()       → رشتهٔ خالی ⇒ header همان <link>های جداگانه را می‌گذارد
 *   · perf_schema_once()     → همان DDL را (مثل قبل) اجرا می‌کند
 *   · perf_daily_once()      → هر بار اجرا می‌کند (مثل قبل)
 *   · perf_cache_remember()  → بدون کش، مستقیم مقدار را برمی‌گرداند
 *   · perf_ddl_skip()        → false (هیچ DDL نادیده گرفته نمی‌شود)
 *
 * یعنی بدترین حالت = سرعت نسخهٔ قبل، نه خرابی. خودِ این فایل در بستهٔ اصلاحی
 * همراh perf.php منتشر می‌شود و فقط در حالت «کپی ناقص» به کار می‌آید.
 */

if (!function_exists('perf_asset_url')) {
    function perf_asset_url($files, $type = 'css') { return ''; }
}

if (!function_exists('perf_asset_bundle')) {
    function perf_asset_bundle($files, $type = 'css') { return null; }
}

if (!function_exists('perf_asset_clean')) {
    function perf_asset_clean($keepSeconds = 604800) { return 0; }
}

if (!function_exists('perf_schema_once')) {
    function perf_schema_once($id, callable $fn) {
        try { $fn(); } catch (Throwable $e) { }
        return true;
    }
}

if (!function_exists('perf_schema_mark')) {
    function perf_schema_mark($id) { return false; }
}

if (!function_exists('perf_schema_is_marked')) {
    function perf_schema_is_marked($id) { return false; }
}

if (!function_exists('perf_daily_once')) {
    function perf_daily_once($id, callable $fn, $ttl = 3600, $signature = '') {
        try { return $fn(); } catch (Throwable $e) { return null; }
    }
}

if (!function_exists('perf_cache_remember')) {
    function perf_cache_remember($key, $ttl, callable $fn) { return $fn(); }
}

if (!function_exists('perf_cache_bump')) {
    function perf_cache_bump() { return false; }
}

if (!function_exists('perf_cache_flush')) {
    function perf_cache_flush() { return 0; }
}

if (!function_exists('perf_ddl_skip')) {
    function perf_ddl_skip($sql) { return false; }
}

if (!function_exists('perf_ddl_mark')) {
    function perf_ddl_mark($sql) { return false; }
}

if (!function_exists('perf_ddl_reset')) {
    function perf_ddl_reset() { return 0; }
}

if (!function_exists('perf_write_is_ephemeral')) {
    function perf_write_is_ephemeral($sql) { return false; }
}

if (!function_exists('perf_ensure_indexes')) {
    function perf_ensure_indexes($force = false) { return []; }
}

if (!function_exists('perf_index_list')) {
    function perf_index_list() { return []; }
}

if (!function_exists('perf_elapsed_ms')) {
    function perf_elapsed_ms() { return 0.0; }
}

if (!function_exists('perf_app_version')) {
    function perf_app_version() { return '0'; }
}

if (!function_exists('perf_config')) {
    function perf_config($key = null, $default = null) {
        return ($key === null) ? [] : $default;
    }
}

/* ── توابع کمکی داخلی: همین امضاها، بدون کش و بدون نوشتن روی دیسک ───────── */

if (!function_exists('perf_root')) {
    function perf_root() { return dirname(__DIR__); }
}

if (!function_exists('perf_first_writable_dir')) {
    function perf_first_writable_dir(array $candidates, $protect = true) {
        foreach ($candidates as $dir) {
            if ($dir !== '' && is_dir($dir) && is_writable($dir)) return rtrim($dir, '/\\');
        }
        return null;
    }
}

if (!function_exists('perf_state_dir')) {
    function perf_state_dir() { return null; }
}

if (!function_exists('perf_cache_dir')) {
    function perf_cache_dir() { return null; }
}

if (!function_exists('perf_safe_key')) {
    function perf_safe_key($value) { return preg_replace('/[^A-Za-z0-9._-]/', '-', (string)$value); }
}

if (!function_exists('perf_ddl_key')) {
    function perf_ddl_key($sql) { return 'ddl-' . sha1(strtolower(preg_replace('/\s+/', ' ', trim((string)$sql)))); }
}

if (!function_exists('perf_cache_version_file')) {
    function perf_cache_version_file() { return ''; }
}

if (!function_exists('perf_cache_version')) {
    function perf_cache_version() { return ''; }
}

if (!function_exists('perf_cache_web_headers')) {
    /* پوشهٔ کش عمومی؛ در حالت بدون perf.php، باندلی ساخته نمی‌شود و این
       تابع هیچ کاری ندارد — فقط نباید «تعریف‌نشده» باشد. */
    function perf_cache_web_headers($dir) { return false; }
}

if (!function_exists('perf_minify_css')) {
    function perf_minify_css($css) { return $css; }
}

/* ── V4.209.0: پوشش کامل توابع تازه (باندل صفحه‌ای، فونت، اسکریپت درون‌خطی) ──
 * بدون این‌ها اگر فقط header.php از بستهٔ جدید کپی شده باشد و perf.php نه،
 * صفحه با «Call to undefined function perf_bundle_url()» از کار می‌افتاد.
 * همهٔ این جایگزین‌ها رفتار «قبل از V4.209.0» را برمی‌گردانند، نه خرابی. */

if (!function_exists('perf_screen_font_map')) {
    function perf_screen_font_map() { return []; }
}

if (!function_exists('perf_css_fix_font_urls')) {
    function perf_css_fix_font_urls($css) { return $css; }
}

if (!function_exists('perf_css_drop_empty_faces')) {
    function perf_css_drop_empty_faces($css) { return $css; }
}

if (!function_exists('perf_css_base_files')) {
    function perf_css_base_files() {
        return ['assets/css/style.css', 'assets/css/ui-modern.css'];
    }
}

if (!function_exists('perf_css_app_files')) {
    function perf_css_app_files(array $extra = []) {
        return array_merge(['assets/css/school-ui.css', 'assets/css/management-hub.css'], $extra);
    }
}

if (!function_exists('perf_js_core_files')) {
    function perf_js_core_files() {
        return ['assets/js/school-icons.js', 'assets/js/school-ui.js', 'assets/js/main.js',
                'assets/js/ui-modern.js', 'assets/js/searchable-select.js'];
    }
}

if (!function_exists('perf_bundle_url')) {
    /* رشتهٔ خالی ⇒ فراخوان همان <link>/<script>های جداگانهٔ قبلی را می‌گذارد. */
    function perf_bundle_url(array $files, $type = 'css') { return ''; }
}

if (!function_exists('perf_inline_js')) {
    function perf_inline_js($code, $name, $minBytes = 1200) { return '<script>' . $code . '</script>'; }
}

if (!function_exists('perf_asset_signature')) {
    function perf_asset_signature(array $files) { return ''; }
}

if (!function_exists('perf_boot')) {
    function perf_boot() { }
}

if (!function_exists('perf_view_cache_enabled')) {
    function perf_view_cache_enabled() { return false; }
}
