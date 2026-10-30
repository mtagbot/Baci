<?php
/**
 * performance.php — v4.208.0
 * داشبورد کارایی و سرعت سایت.
 *
 * این صفحه پاسخ می‌دهد به سه پرسشِ عملی:
 *   ۱) الان گلوگاه کجاست؟ (زمان پاسخ، تعداد و زمان کوئری‌ها، حافظه، OPcache)
 *   ۲) کش‌ها و باندل‌ها سالم‌اند یا نه؟ (cache/perf، assets/cache، ایندکس‌ها)
 *   ۳) چه تنظیمی روی سرور لازم است تا همه‌چیز در «بهترین حالت» باشد؟
 *      (چهار snippet آمادهٔ کپی: nginx، Apache، php.ini، و چک‌لیست)
 *
 * همهٔ عملیات نوشتاری: POST + CSRF + مجوز system_settings.
 * هیچ داده‌ای حذف نمی‌شود؛ فقط کش‌ها و نشانه‌های مایگریشن بازسازی می‌شوند.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('system_settings');
require_once __DIR__ . '/includes/perf.php';
require_once __DIR__ . '/includes/appearance.php';

$perfRoot = perf_root();
$perfMsg = [];
$perfErr = [];
$isSqlite = false;
try { $isSqlite = DB::isSqliteDriver(); } catch (Throwable $e) { $isSqlite = false; }

/* ─────────────── عملیات ─────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do'])) {
    if (!is_string($_POST['csrf_token'] ?? null) || !verify_csrf($_POST['csrf_token'])) {
        $perfErr[] = 'توکن CSRF نامعتبر است؛ صفحه را دوباره باز کنید.';
    } else {
        $do = (string)$_POST['do'];
        try {
            if ($do === 'build_assets') {
                $cssN = 0; $jsN = 0;
                if (perf_asset_bundle(['assets/css/style.css', 'assets/css/ui-modern.css'], 'css')) $cssN++;
                if (perf_asset_bundle(['assets/css/school-ui.css', 'assets/css/management-hub.css'], 'css')) $cssN++;
                if (perf_asset_bundle(['assets/js/school-icons.js', 'assets/js/school-ui.js', 'assets/js/main.js', 'assets/js/ui-modern.js', 'assets/js/searchable-select.js', 'assets/js/session-watch.js'], 'js')) $jsN++;
                $perfMsg[] = "باندل‌های دارایی ساخته شد: $cssN فایل CSS و $jsN فایل JS.";
            } elseif ($do === 'clean_assets') {
                $n = perf_asset_clean(0);
                $perfMsg[] = 'پاک‌سازی باندل‌های قدیمی انجام شد (' . $n . ' فایل). باندل‌های در حال استفاده با اولین بازدید بازساخته می‌شوند.';
            } elseif ($do === 'flush_cache') {
                $n = perf_cache_flush();
                $perfMsg[] = 'کش داده پاک شد (' . $n . ' فایل).';
            } elseif ($do === 'indexes') {
                $log = perf_ensure_indexes(true);
                $perfMsg[] = 'ایندکس‌ها بررسی شدند (' . count($log) . ' مورد).';
                foreach ($log as $line) { if (strpos($line, '→') !== false && strpos($line, 'ساخته شد') === false && strpos($line, 'موجود') === false) $perfErr[] = $line; }
            } elseif ($do === 'reset_ddl') {
                $n = perf_ddl_reset();
                $perfMsg[] = 'نشانه‌های مایگریشن پاک شد (' . $n . ' نشانه). در اولین بازدید بعدی، ساختار جداول یک‌بار دیگر بازبینی می‌شود.';
            }
        } catch (Throwable $e) {
            $perfErr[] = 'خطا: ' . $e->getMessage();
        }
    }
}

/* ─────────────── سنجه‌های این درخواست ─────────────── */
$perfStats = class_exists('DB') && method_exists('DB', 'stats')
    ? DB::stats()
    : ['queries' => 0, 'selects' => 0, 'writes' => 0, 'ddl' => 0, 'ddl_skipped' => 0, 'sql_ms' => 0, 'memo_hits' => 0, 'slow' => []];

/* ─────────────── وضعیت زیرساخت ─────────────── */
$phpVersion = PHP_VERSION;
$opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;
$opcacheOn = is_array($opcache) && !empty($opcache['opcache_enabled']);
$apcuOn = function_exists('apcu_enabled') && @apcu_enabled();
$realpathCache = (int)ini_get('realpath_cache_size');
$memoryLimit = ini_get('memory_limit');

function perf_fmt_bytes($n) {
    $n = (float)$n;
    foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
        if ($n < 1024) return round($n, $n < 10 ? 1 : 0) . ' ' . $unit;
        $n /= 1024;
    }
    return round($n, 1) . ' TB';
}

/* کش داده */
$cacheDir = perf_cache_dir();
$cacheFiles = $cacheDir ? (count((array)@glob($cacheDir . '/*.cache'))) : 0;
$cacheBytes = 0;
if ($cacheDir) foreach ((array)@glob($cacheDir . '/*.cache') as $f) { $cacheBytes += (int)@filesize($f); }
$stateDir = perf_state_dir();
$ddlMarks = $stateDir ? count((array)@glob($stateDir . '/ddl-*.ok')) : 0;

/* دارایی‌ها */
$assetDir = $perfRoot . '/assets/cache';
$assetFiles = [];
$assetBytes = 0;
if (is_dir($assetDir)) {
    foreach (array_merge((array)@glob($assetDir . '/*.css'), (array)@glob($assetDir . '/*.js')) as $f) {
        $assetFiles[] = ['name' => basename($f), 'bytes' => (int)@filesize($f), 'time' => (int)@filemtime($f)];
        $assetBytes += (int)@filesize($f);
    }
    usort($assetFiles, fn($a, $b) => $b['time'] <=> $a['time']);
}
$assetWritable = is_dir($assetDir) ? is_writable($assetDir) : is_writable($perfRoot . '/assets');

/* فونت‌ها */
$fontDir = $perfRoot . '/assets/fonts/screen';
$fontFiles = [];
if (is_dir($fontDir)) {
    foreach (array_merge((array)@glob($fontDir . '/*.woff2'), (array)@glob($fontDir . '/*.woff'), (array)@glob($fontDir . '/*.ttf')) as $f) $fontFiles[] = ['name' => basename($f), 'bytes' => (int)@filesize($f)];
}
$fontSpec = app_screen_font_spec();
$fontSpecBold = app_screen_font_spec(true);
$fontOk = $fontSpec['url'] !== '' && is_file($perfRoot . '/' . $fontSpec['url']);

/* ایندکس‌ها */
$indexReport = [];
try {
    foreach (perf_index_list() as $item) {
        [$table, $name, $cols] = $item;
        $exists = false;
        try {
            if ($isSqlite) {
                foreach (DB::fetchAll("PRAGMA index_list('" . $table . "')") as $row) {
                    if (($row['name'] ?? '') === $name) { $exists = true; break; }
                }
            } else {
                $row = DB::fetch("SELECT 1 AS ok FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1", [$table, $name]);
                $exists = !empty($row);
            }
        } catch (Throwable $e) { $exists = false; }
        $indexReport[] = ['table' => $table, 'name' => $name, 'cols' => $cols, 'exists' => $exists];
    }
} catch (Throwable $e) {}
$missingIndexes = array_values(array_filter($indexReport, fn($r) => !$r['exists']));

/* حجم دادهٔ جداول کلیدی (فقط برای آگاهی) */
$tableSizes = [];
if (!$isSqlite) {
    try {
        $rows = DB::fetchAll("SELECT table_name AS t, table_rows AS r, ROUND((data_length + index_length)/1024/1024, 1) AS mb FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('students','reports','report_grades','student_discipline_records','class_schedules')");
        foreach ($rows as $r) $tableSizes[] = ['table' => $r['t'], 'rows' => (int)$r['r'], 'mb' => (float)$r['mb']];
    } catch (Throwable $e) {}
}

/* ─────────────── snippetهای سرور ─────────────── */
$nginxSnippet = <<<'NGINX'
# ── SchoolDesk Pro — تنظیمات پیشنهادی nginx (v4.208.0) ─────────────────
# این بلوک را داخل server { ... } سایت قرار دهید.
# هدف: ۱) فایل استاتیک هرگز به PHP نرسد (404 سریع، نه اجرای کل برنامه)
#       ۲) کش یک‌ساله برای CSS/JS/فونت
#       ۳) فشرده‌سازی brotli/gzip
gzip on;
gzip_comp_level 6;
gzip_min_length 512;
gzip_vary on;
gzip_proxied any;
gzip_types text/plain text/css text/xml application/json application/javascript
           application/xml application/xml+rss image/svg+xml font/woff2 font/ttf;
# اگر ماژول brotli نصب است:
# brotli on; brotli_comp_level 5;
# brotli_types text/css application/javascript application/json image/svg+xml;

# assets/cache و دارایی‌های استاتیک: مستقیم از دیسک، کش یک‌ساله
location ^~ /assets/ {
    try_files $uri =404;          # ← کلید اصلی: فایل گم‌شده به PHP نمی‌رود
    access_log off;
    expires 1y;
    add_header Cache-Control "public, max-age=31536000, immutable";
}
location ^~ /assets/cache/ {
    try_files $uri =404;
    access_log off;
    expires 1y;
    add_header Cache-Control "public, max-age=31536000, immutable";
}
location ~* ^/(uploads|backups|cache|config|sql)/.*\.(php|phtml)$ { deny all; }
location ^~ /uploads/ {
    try_files $uri =404;          # فونت/عکس گم‌شده = 404 سریع (نه ۷۰۰ms PHP)
    access_log off;
    expires 30d;
}
open_file_cache          max=4000 inactive=60s;
open_file_cache_valid    30s;
open_file_cache_min_uses 2;
open_file_cache_errors   on;

# PHP: بافر خروجی و کش واقعی فایل‌ها در OPcache
location ~ \.php$ {
    try_files $uri =404;
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;   # مسیر واقعی خود را بگذارید
    fastcgi_buffers 16 16k;
    fastcgi_buffer_size 32k;
    fastcgi_read_timeout 60s;
}
NGINX;

$apacheSnippet = <<<'APACHE'
# ── SchoolDesk Pro — .htaccess پیشنهادی (v4.208.0) ─────────────────────
# در ریشهٔ سایت قرار دهید (کنار index.php).
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/css text/plain text/xml \
        application/javascript application/json application/xml image/svg+xml
</IfModule>
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType text/css                "access plus 1 year"
    ExpiresByType application/javascript  "access plus 1 year"
    ExpiresByType font/woff2              "access plus 1 year"
    ExpiresByType image/svg+xml           "access plus 1 month"
</IfModule>
<IfModule mod_headers.c>
    <FilesMatch "\.(css|js|woff2|woff|ttf)$">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </FilesMatch>
</IfModule>
# فایل گم‌شده نباید به PHP برسد
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^(assets|uploads)/ - [R=404,L]
</IfModule>
APACHE;

$phpIniSnippet = <<<'INI'
; ── SchoolDesk Pro — php.ini پیشنهادی (v4.208.0) ──────────────────────
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=256
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0      ; بعد از هر آپدیت، PHP-FPM را restart کنید
opcache.revalidate_freq=0
opcache.save_comments=1
opcache.fast_shutdown=1
; کش مسیر فایل‌ها (هر بار stat کمتر)
realpath_cache_size=4M
realpath_cache_ttl=600
; سشن و خروجی
output_buffering=4096
zlib.output_compression=0
; اگر APCu موجود است (اختیاری)
; apc.enabled=1
; apc.shm_size=64M
INI;

$checklist = [
    ['ok' => $opcacheOn, 'label' => 'OPcache فعال است', 'hint' => 'بدون OPcache، هر درخواست همهٔ فایل‌های PHP را از نو کامپایل می‌کند (کندی پایدار در TTFB).'],
    ['ok' => $realpathCache >= 1048576, 'label' => 'realpath_cache_size حداقل ۱ مگابایت', 'hint' => 'مقدار پیش‌فرض ۴۰۹۶ بایت است و برای این تعداد فایل کافی نیست.'],
    ['ok' => $assetWritable, 'label' => 'پوشهٔ assets/cache قابل نوشتن است', 'hint' => 'بدون آن، باندل دارایی‌ها ساخته نمی‌شود و مرورگر ۱۱ فایل جدا می‌گیرد.'],
    ['ok' => $cacheDir !== null, 'label' => 'پوشهٔ cache/perf قابل نوشتن است', 'hint' => 'کش آمار فهرست دانش‌آموزان و کارنامه‌ها به آن وابسته است.'],
    ['ok' => empty($missingIndexes), 'label' => 'همهٔ ایندکس‌های حیاتی موجودند', 'hint' => 'بدون این ایندکس‌ها، فهرست دانش‌آموزان و کارنامه‌ها کل جدول‌ها را اسکن می‌کنند.'],
    ['ok' => $fontOk, 'label' => 'قلم انتخاب‌شده واقعاً موجود است', 'hint' => 'قلم گم‌شده باعث درخواست ۴۰۴ می‌شود که کل برنامهٔ PHP را اجرا می‌کند.'],
    ['ok' => version_compare($phpVersion, '8.1', '>='), 'label' => 'PHP 8.1 یا جدیدتر', 'hint' => 'نسخه‌های قدیمی‌تر چند برابر کندترند.'],
];
$checkOk = count(array_filter($checklist, fn($c) => $c['ok']));
$requestMs = perf_elapsed_ms();

require_once __DIR__ . '/includes/header.php';
?>
<div class="page-hero card p-4 mb-4 flex items-center justify-between flex-wrap gap-2">
    <div>
        <h2 class="text-xl font-bold">کارایی و سرعت سایت</h2>
        <p class="text-muted text-xs mt-1">سنجه‌های واقعی همین درخواست + وضعیت کش‌ها و باندل‌ها + تنظیمات پیشنهادی سرور — نگارش <?php echo clean(perf_app_version()); ?></p>
    </div>
    <div class="flex gap-2 flex-wrap">
        <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="do" value="build_assets">
            <button class="btn btn-primary btn-sm" type="submit">ساخت مجدد باندل‌ها</button></form>
        <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="do" value="indexes">
            <button class="btn btn-secondary btn-sm" type="submit">بررسی و ساخت ایندکس‌ها</button></form>
        <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="do" value="flush_cache">
            <button class="btn btn-outline btn-sm" type="submit">پاک کردن کش داده</button></form>
    </div>
</div>

<?php foreach ($perfMsg as $m): ?><div class="card p-3 mb-2" role="status"><?php echo clean($m); ?></div><?php endforeach; ?>
<?php foreach ($perfErr as $m): ?><div class="card p-3 mb-2" role="alert" style="border-color:#fca5a5;background:#fee2e2;color:#7f1d1d"><?php echo clean($m); ?></div><?php endforeach; ?>

<section class="card p-4 mb-4">
    <h3 class="font-bold mb-2">۱) وضعیت این درخواست</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;">
        <div><div class="text-muted text-xs">زمان تولید صفحه</div><div class="text-xl font-bold"><?php echo number_format($requestMs, 0); ?> ms</div></div>
        <div><div class="text-muted text-xs">کوئری‌های اجراشده</div><div class="text-xl font-bold"><?php echo (int)$perfStats['queries']; ?></div></div>
        <div><div class="text-muted text-xs">زمان SQL</div><div class="text-xl font-bold"><?php echo number_format((float)$perfStats['sql_ms'], 1); ?> ms</div></div>
        <div><div class="text-muted text-xs">DDL صرف‌نظر‌شده</div><div class="text-xl font-bold"><?php echo (int)($perfStats['ddl_skipped'] ?? 0); ?></div></div>
        <div><div class="text-muted text-xs">حافظهٔ اوج</div><div class="text-xl font-bold"><?php echo perf_fmt_bytes(memory_get_peak_usage(true)); ?></div></div>
        <div><div class="text-muted text-xs">سقف حافظه</div><div class="text-xl font-bold"><?php echo clean($memoryLimit); ?></div></div>
    </div>
    <?php if (!empty($perfStats['slow'])): ?>
    <p class="text-muted text-xs mt-3">کوئری‌های کندتر از ۱۲۰ میلی‌ثانیه در این درخواست:</p>
    <ul class="text-xs mt-1"><?php foreach ($perfStats['slow'] as $s): ?><li><?php echo number_format($s['ms'], 1); ?> ms — <code><?php echo clean($s['sql']); ?></code></li><?php endforeach; ?></ul>
    <?php endif; ?>
</section>

<section class="card p-4 mb-4">
    <h3 class="font-bold mb-2">۲) چک‌لیست سرعت — <?php echo $checkOk; ?> از <?php echo count($checklist); ?> مورد تأیید</h3>
    <div style="display:grid;gap:8px;">
    <?php foreach ($checklist as $c): ?>
        <div style="display:flex;gap:8px;align-items:flex-start">
            <span style="flex:0 0 auto;font-weight:bold;color:<?php echo $c['ok'] ? '#15803d' : '#b91c1c'; ?>"><?php echo $c['ok'] ? '✅' : '⚠️'; ?></span>
            <div><div><?php echo clean($c['label']); ?></div><?php if (!$c['ok']): ?><div class="text-muted text-xs"><?php echo clean($c['hint']); ?></div><?php endif; ?></div>
        </div>
    <?php endforeach; ?>
    </div>
</section>

<section class="card p-4 mb-4">
    <h3 class="font-bold mb-2">۳) کش داده و باندل دارایی‌ها</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">
        <div><div class="text-muted text-xs">پوشهٔ کش داده</div><div><?php echo $cacheDir ? '<code>' . clean(str_replace($perfRoot, '', $cacheDir)) . '</code>' : '<span style="color:#b91c1c">غیرقابل نوشتن</span>'; ?></div>
            <div class="text-muted text-xs"><?php echo $cacheFiles; ?> فایل، <?php echo perf_fmt_bytes($cacheBytes); ?></div></div>
        <div><div class="text-muted text-xs">نشانه‌های مایگریشن (DDL)</div><div class="text-xl font-bold"><?php echo $ddlMarks; ?></div>
            <div class="text-muted text-xs">هر نشانه یعنی یک DDL در این نسخه انجام شده و دیگر تکرار نمی‌شود.</div></div>
        <div><div class="text-muted text-xs">باندل‌های دارایی</div><div class="text-xl font-bold"><?php echo count($assetFiles); ?></div>
            <div class="text-muted text-xs"><?php echo perf_fmt_bytes($assetBytes); ?> مجموع</div></div>
        <div><div class="text-muted text-xs">نسخهٔ کش</div><div><code><?php echo clean(substr((string)perf_cache_version(), 0, 20)); ?></code></div>
            <div class="text-muted text-xs">با هر نوشتن در دیتابیس عوض می‌شود.</div></div>
    </div>
    <div class="flex gap-2 flex-wrap mt-3">
        <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="do" value="clean_assets">
            <button class="btn btn-outline btn-sm" type="submit">پاک کردن باندل‌های قدیمی</button></form>
        <form method="POST" onsubmit="return confirm('نشانه‌های مایگریشن پاک شوند؟ در بازدید بعدی ساختار جداول یک‌بار دیگر بازبینی می‌شود (چند ثانیه کندی).');">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="do" value="reset_ddl">
            <button class="btn btn-warning btn-sm" type="submit">بازسازی نشانه‌های مایگریشن</button></form>
    </div>
    <?php if ($assetFiles): ?>
    <details class="mt-3"><summary class="text-xs">فهرست باندل‌ها (<?php echo count($assetFiles); ?>)</summary>
        <ul class="text-xs mt-1"><?php foreach (array_slice($assetFiles, 0, 12) as $a): ?><li><code><?php echo clean($a['name']); ?></code> — <?php echo perf_fmt_bytes($a['bytes']); ?></li><?php endforeach; ?></ul>
    </details>
    <?php endif; ?>
</section>

<section class="card p-4 mb-4">
    <h3 class="font-bold mb-2">۴) ایندکس‌های حیاتی</h3>
    <p class="text-muted text-xs mb-2">این ایندکس‌ها یک‌بار در هر نسخه ساخته می‌شوند. نبودشان یعنی جست‌وجو در فهرست دانش‌آموزان، کارنامه‌ها و نمرات، کل جدول را می‌گردد.</p>
    <table class="w-full text-xs"><thead><tr><th style="text-align:right">جدول</th><th style="text-align:right">ایندکس</th><th style="text-align:right">ستون‌ها</th><th style="text-align:right">وضعیت</th></tr></thead><tbody>
    <?php foreach ($indexReport as $r): ?>
        <tr><td><?php echo clean($r['table']); ?></td><td><code><?php echo clean($r['name']); ?></code></td><td><?php echo clean($r['cols']); ?></td>
            <td><?php echo $r['exists'] ? '<span style="color:#15803d">موجود</span>' : '<span style="color:#b91c1c">غایب</span>'; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php if ($tableSizes): ?>
    <p class="text-muted text-xs mt-3">حجم جداول کلیدی:</p>
    <ul class="text-xs"><?php foreach ($tableSizes as $t): ?><li><?php echo clean($t['table']); ?> — <?php echo number_format($t['rows']); ?> ردیف (تقریبی)، <?php echo $t['mb']; ?> MB</li><?php endforeach; ?></ul>
    <?php endif; ?>
</section>

<section class="card p-4 mb-4">
    <h3 class="font-bold mb-2">۵) قلم و دارایی‌های گرافیکی</h3>
    <p class="text-xs">قلم انتخاب‌شده: <strong><?php echo clean($fontSpec['family']); ?></strong> — فایل: <code><?php echo clean($fontSpec['url'] !== '' ? $fontSpec['url'] : '—'); ?></code>
        <?php echo $fontOk ? '<span style="color:#15803d">(موجود)</span>' : '<span style="color:#b91c1c">(ناموجود!)</span>'; ?>
        <?php if ($fontSpecBold['url'] !== $fontSpec['url']): ?> · وزن ضخیم: <code><?php echo clean($fontSpecBold['url']); ?></code><?php endif; ?></p>
    <p class="text-muted text-xs mt-1">نسخهٔ فشردهٔ WOFF2 موجود در بسته: <?php echo count($fontFiles); ?> فایل. اگر قلم انتخابی WOFF2 دارد، همان با کش یک‌ساله ارائه می‌شود؛ فایل TTF فقط برای PDF و خروجی اکسل استفاده می‌شود.</p>
</section>

<section class="card p-4 mb-4">
    <h3 class="font-bold mb-2">۶) تنظیمات پیشنهادی سرور</h3>
    <p class="text-muted text-xs mb-2">این بلوک‌ها را روی سرور اعمال کنید. مهم‌ترین مورد، بلوک <code>try_files $uri =404</code> برای مسیر <code>/assets/</code> و <code>/uploads/</code> است: یک فایل گم‌شده (مثل قلمی که وجود ندارد) نباید به PHP برسد — در trace واقعی، یک درخواست فونت گم‌شده ۷۲۵ میلی‌ثانیه طول کشید چون کل برنامه را اجرا می‌کرد.</p>

    <details<?php echo !$opcacheOn ? ' open' : ''; ?>><summary class="text-sm font-bold">nginx (توصیه‌شده)</summary>
        <pre dir="ltr" style="max-height:320px;overflow:auto;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;font-size:11px;line-height:1.7"><?php echo clean($nginxSnippet); ?></pre>
    </details>
    <details class="mt-2"><summary class="text-sm font-bold">Apache (.htaccess)</summary>
        <pre dir="ltr" style="max-height:320px;overflow:auto;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;font-size:11px;line-height:1.7"><?php echo clean($apacheSnippet); ?></pre>
    </details>
    <details class="mt-2"><summary class="text-sm font-bold">php.ini (OPcache)</summary>
        <pre dir="ltr" style="max-height:320px;overflow:auto;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;font-size:11px;line-height:1.7"><?php echo clean($phpIniSnippet); ?></pre>
    </details>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
