<?php
/**
 * doctor.php — عیب‌یاب مستقل SchoolDesk Pro (V4.214.2)
 * ---------------------------------------------------------------------------
 * چرا؟ وقتی سایت «HTTP ERROR 500» می‌دهد، هیچ سرنخی به دست کاربر نمی‌رسد.
 * این فایل بدون وابستگی به بقیهٔ برنامه (بدون session، بدون دیتابیس اجباری)
 * محیط اجرا را گزارش می‌کند تا در یک نگاه معلوم شود مشکل از نسخهٔ PHP،
 * افزونهٔ گم‌شده، سطح دسترسی فایل‌ها، یا `.htaccess` هاست است.
 *
 * ⚠ این فایل هیچ داده‌ای از دانش‌آموزان/تنظیمات نشان نمی‌دهد، ولی مثل هر
 *   ابزار عیب‌یابی باید **بعد از استفاده حذف شود**.
 *
 * استفاده: آدرس سایت + /doctor.php
 */

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$root = __DIR__;
/* Diagnostics report the PHP process that served this request, not the user's
   desktop. Release metadata wins; ambiguous legacy installs default to site. */
$releaseMeta = [];
$releaseMetaPath = $root . '/config/release.php';
if (is_file($releaseMetaPath)) {
    try { $loadedMeta = require $releaseMetaPath; if (is_array($loadedMeta)) $releaseMeta = $loadedMeta; } catch (Throwable $e) {}
}
$distribution = strtolower((string)($releaseMeta['distribution'] ?? ''));
if (!in_array($distribution, ['site', 'desktop'], true)) $distribution = 'site';
$esc = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

$php        = PHP_VERSION;
$sapi       = function_exists('php_sapi_name') ? php_sapi_name() : '-';
$os         = PHP_OS_FAMILY ?? PHP_OS;
$server     = $_SERVER['SERVER_SOFTWARE'] ?? '-';
$memory     = ini_get('memory_limit');
$maxExec    = ini_get('max_execution_time');
$displayErr = ini_get('display_errors');
$opcache    = function_exists('opcache_get_status') ? (opcache_get_status(false) ? 'روشن' : 'خاموش/غیرفعال') : 'در دسترس نیست';
$uploadMax  = ini_get('upload_max_filesize');
$postMax    = ini_get('post_max_size');

/* ── افزونه‌ها ─────────────────────────────────────────────────────────── */
$needed = [
    'pdo' => 'اتصال پایهٔ دیتابیس',
    'pdo_mysql' => 'دیتابیس MySQL/MariaDB (سایت)',
    'pdo_sqlite' => 'دیتابیس SQLite (فقط نصب دسکتاپ)',
    'mysqli' => 'درایور جایگزین MySQL',
    'mbstring' => 'متن فارسی',
    'json' => 'API و کش',
    'openssl' => 'امضای چاپ و توکن',
    'curl' => 'ربات و همگام‌سازی',
    'gd' => 'تصویر دانش‌آموز و کارت',
    'zip' => 'به‌روزرسانی دسکتاپ',
    'fileinfo' => 'تشخیص نوع فایل',
    'session' => 'ورود کاربران',
    'dom' => 'Word/PDF',
    'zlib' => 'فشرده‌سازی',
];
$requiredExt = ['pdo', 'mbstring', 'json', 'openssl', 'curl', 'gd', 'fileinfo', 'session', 'dom', 'zlib'];
$requiredExt[] = $distribution === 'desktop' ? 'pdo_sqlite' : 'pdo_mysql';
if ($distribution === 'desktop') $requiredExt[] = 'zip';
$ext = [];
foreach ($needed as $e => $why) $ext[$e] = [extension_loaded($e), $why, in_array($e, $requiredExt, true)];

/* ── دسترسی نوشتن ──────────────────────────────────────────────────────── */
$paths = ['config', 'cache', 'assets', 'assets/cache', 'uploads', 'backups'];
$writ = [];
foreach ($paths as $p) {
    $abs = $root . '/' . $p;
    $writ[$p] = [
        'exists' => is_dir($abs),
        'writable' => is_dir($abs) ? is_writable($abs) : (@is_writable($root) ? 'میراث ریشه' : false),
    ];
}

/* ── .htaccess: آیا هندلر PHP هاست دست‌نخورده است؟ ──────────────────────── */
$ht = @file_get_contents($root . '/.htaccess');
/* Commands guarded by <IfModule> are conditional, not proof of a broken host. */
$htUnconditional = $ht === false ? '' : preg_replace('/<IfModule\b[^>]*>.*?<\/IfModule>/is', '', $ht);
$htState = [
    'exists' => $ht !== false,
    'size' => $ht === false ? 0 : strlen($ht),
    'handler' => $ht !== false && (bool)preg_match('/^\s*(?:AddHandler|SetHandler|Action|FcgidWrapper)\b[^\r\n]*(?:php|fcgi)/mi', $ht),
    'options' => $ht !== false && (bool)preg_match('/^\s*Options\s/mi', $htUnconditional),
    'require' => $ht !== false && (bool)preg_match('/^\s*Require\s/mi', $htUnconditional),
];

/* ── آزمون دیتابیس (بدون نمایش رمز) ─────────────────────────────────────── */
$db = ['ok' => null, 'driver' => '-', 'error' => ''];
try {
    if (is_file($root . '/config/config.php')) {
        $cfg = require $root . '/config/config.php';
        if (is_array($cfg)) {
            $driver = $cfg['db_driver'] ?? $cfg['driver'] ?? ($cfg['database']['driver'] ?? '؟');
            $db['driver'] = is_string($driver) ? $driver : '؟';
        }
    }
    if (is_file($root . '/includes/db.php')) {
        require_once $root . '/includes/db.php';
        if (class_exists('DB')) {
            $pdo = DB::getInstance()->getPdo();
            if ($pdo) {
                $db['ok'] = true;
                $db['driver'] = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                $pdo->query('SELECT 1');
            } else {
                $db['ok'] = false;
                $db['error'] = 'اتصال برقرار نشد (getPdo خالی برگرداند)';
            }
        } else {
            $db['ok'] = false;
            $db['error'] = 'کلاس DB در includes/db.php پیدا نشد';
        }
    } else {
        $db['ok'] = false;
        $db['error'] = 'فایل includes/db.php نیست';
    }
} catch (Throwable $e) {
    $db['ok'] = false;
    $db['error'] = get_class($e) . ': ' . $e->getMessage();
}
/* پاک‌سازی: مسیر مطلق و هر چیزی شبیه نام کاربری/رمز حذف شود. */
$db['error'] = str_replace($root, '', $db['error']);
$db['error'] = preg_replace('/(password|passwd|pwd)\s*=\s*[^;,\s]+/i', '$1=***', $db['error']);

/* ── آزمون بوت: آیا هستهٔ برنامه بالا می‌آید؟ ───────────────────────────── */
$boot = ['ok' => null, 'error' => '', 'functions' => []];
if (isset($_GET['boot']) && $_GET['boot'] === '1') {
    ob_start();
    try {
        require_once $root . '/includes/functions.php';
        $boot['ok'] = true;
    } catch (Throwable $e) {
        $boot['ok'] = false;
        $boot['error'] = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }
    $echoed = ob_get_clean();
    $boot['output'] = mb_substr((string)$echoed, 0, 400);
    foreach (['perf_bundle_url', 'app_screen_font_preload', 'get_setting', 'csrf_token', 'DB'] as $f) {
        $boot['functions'][$f] = function_exists($f) || class_exists($f);
    }
    $last = error_get_last();
    if ($last && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $boot['ok'] = false;
        $boot['error'] = $boot['error'] ?: ($last['message'] . ' @ ' . basename($last['file']) . ':' . $last['line']);
    }
}

/* ── آخرین خطاهای ثبت‌شده ──────────────────────────────────────────────── */
$logLines = [];
$logFile = $root . '/config/php-error.log';
if (is_file($logFile)) {
    $raw = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $logLines = array_slice($raw, -8);
    $logLines = array_map(function ($l) use ($root) { return str_replace($root, '', $l); }, $logLines);
}
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>عیب‌یاب SchoolDesk Pro</title>
<style>
    body{font-family:Tahoma,system-ui,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:24px 12px;line-height:1.9}
    .wrap{max-width:900px;margin:auto}
    h1{font-size:20px;margin:0 0 4px}
    .sub{color:#64748b;font-size:12px;margin-bottom:18px}
    .card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:16px 18px;margin:0 0 14px}
    .card h2{font-size:14px;margin:0 0 10px;color:#334155}
    table{width:100%;border-collapse:collapse;font-size:12.5px}
    td,th{padding:6px 8px;border-bottom:1px solid #f1f5f9;text-align:right;vertical-align:top}
    th{color:#64748b;font-weight:600;font-size:11.5px}
    code{background:#f1f5f9;padding:1px 5px;border-radius:5px;direction:ltr;display:inline-block;font-size:11.5px}
    .ok{color:#15803d;font-weight:700}
    .bad{color:#b91c1c;font-weight:700}
    .warn{color:#b45309;font-weight:700}
    pre{direction:ltr;text-align:left;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:10px;font-size:11.5px;overflow:auto;margin:8px 0 0}
    .btn{display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:8px 14px;border-radius:10px;font-size:12.5px;margin-top:8px}
    .danger{background:#fef2f2;border-color:#fecaca}
</style>
</head>
<body>
<div class="wrap">
    <h1>عیب‌یاب SchoolDesk Pro</h1>
    <div class="sub">این فایل روی همین میزبان اجرا می‌شود و محیط همان درخواست وب را گزارش می‌کند؛ اجرای آن به معنی اجرای برنامهٔ دسکتاپ نیست. برای عیب‌یاب ویژهٔ دسکتاپ از <code>desk-doctor.php</code> در برنامهٔ محلی استفاده کنید. پس از عیب‌یابی، <code>doctor.php</code> را حذف کنید.</div>

    <div class="card">
        <h2>۱) محیط اجرا</h2>
        <table>
            <tr><th>توزیع شناسایی‌شده</th><td><?= $esc($distribution === 'desktop' ? 'دسکتاپ (متادیتای انتشار)' : 'سایت/میزبان وب') ?></td></tr>
            <tr><th>نسخهٔ PHP</th><td><code><?= $esc($php) ?></code></td></tr>
            <tr><th>رابط اجرا (SAPI)</th><td><code><?= $esc($sapi) ?></code></td></tr>
            <tr><th>سیستم</th><td><code><?= $esc($os) ?></code></td></tr>
            <tr><th>وب‌سرور</th><td><code><?= $esc($server) ?></code></td></tr>
            <tr><th>OPcache</th><td><?= $esc($opcache) ?></td></tr>
            <tr><th>memory_limit</th><td><code><?= $esc($memory) ?></code></td></tr>
            <tr><th>max_execution_time</th><td><code><?= $esc($maxExec) ?></code></td></tr>
            <tr><th>display_errors</th><td><code><?= $esc($displayErr) ?></code></td></tr>
            <tr><th>upload / post</th><td><code><?= $esc($uploadMax) ?></code> / <code><?= $esc($postMax) ?></code></td></tr>
        </table>
    </div>

    <div class="card">
        <h2>۲) افزونه‌های لازم</h2>
        <table>
            <tr><th>افزونه</th><th>وضعیت</th><th>کاربرد</th></tr>
            <?php foreach ($ext as $name => [$on, $why, $required]): ?>
                <tr>
                    <td><code><?= $esc($name) ?></code></td>
                    <td class="<?= $on ? 'ok' : ($required ? 'bad' : 'warn') ?>"><?= $on ? 'موجود' : ($required ? 'غایب — لازم برای این توزیع' : 'غایب — اختیاری برای این توزیع') ?></td>
                    <td><?= $esc($why) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="card">
        <h2>۳) دسترسی نوشتن پوشه‌ها</h2>
        <table>
            <tr><th>پوشه</th><th>وجود</th><th>قابل نوشتن</th></tr>
            <?php foreach ($writ as $p => $st): ?>
                <tr>
                    <td><code><?= $esc($p) ?></code></td>
                    <td class="<?= $st['exists'] ? 'ok' : 'warn' ?>"><?= $st['exists'] ? 'هست' : 'نیست (خودکار ساخته می‌شود)' ?></td>
                    <td class="<?= $st['writable'] === true ? 'ok' : 'bad' ?>"><?= $st['writable'] === true ? 'بله' : $esc($st['writable'] === false ? 'خیر' : $st['writable']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p style="font-size:12px;color:#64748b;margin:8px 0 0">اگر <code>config</code> قابل نوشتن نیست، کش و ضدتکرار DDL کار نمی‌کند (بدون خطا، فقط کندتر).</p>
    </div>

    <div class="card">
        <h2>۴) سازگاری وب‌سرور و <code>.htaccess</code> (cPanel، DirectAdmin و LiteSpeed)</h2>
        <table>
            <tr><th>فایل هست</th><td class="<?= $htState['exists'] ? 'ok' : 'warn' ?>"><?= $htState['exists'] ? 'بله' : 'خیر' ?></td></tr>
            <tr><th>اندازه</th><td><code><?= (int)$htState['size'] ?></code> بایت</td></tr>
            <tr><th>دستور handler صریح در <code>.htaccess</code></th><td><?= $htState['handler'] ? 'پیدا شد' : 'پیدا نشد؛ برای PHP-FPM و بسیاری از تنظیمات پنل‌ها طبیعی است' ?></td></tr>
            <tr><th><code>Options</code> بدون محافظ</th><td class="<?= $htState['options'] ? 'warn' : 'ok' ?>"><?= $htState['options'] ? 'دارد؛ ممکن است در بعضی میزبان‌ها مجاز نباشد' : 'ندارد' ?></td></tr>
            <tr><th><code>Require</code> بدون محافظ</th><td class="<?= $htState['require'] ? 'warn' : 'ok' ?>"><?= $htState['require'] ? 'دارد؛ فقط در صورت خطای 500 بررسی شود' : 'ندارد (دستورهای محافظت‌شده با IfModule نادیده گرفته شده‌اند)' ?></td></tr>
        </table>
        <div class="card danger" style="margin:10px 0 0">
            <strong style="font-size:12.5px">نکته برای cPanel و DirectAdmin:</strong>
            <p style="font-size:12px;margin:6px 0 0">
                چون همین صفحه با PHP اجرا شده، handler فعلی برای این درخواست فعال است؛ PHP-FPM معمولاً handler را در پنل نگه می‌دارد، نه در فایل <code>.htaccess</code>. نسخهٔ PHP را فقط از MultiPHP Manager در cPanel یا تنظیمات PHP دامنه در DirectAdmin تغییر دهید. اگر سایت کار می‌کند، چیزی را تغییر ندهید. در صورت خطای 500، ابتدا Error Log هاست را بررسی کنید و از پشتیبانی میزبان بخواهید خط دقیق را ببیند؛ فایل اصلی <code>.htaccess</code> را تغییرنام ندهید، چون ممکن است rewrite و قواعد امنیتی را از کار بیندازد.
            </p>
        </div>
    </div>

    <div class="card">
        <h2>۵) هم‌خوانی با نصب قدیمی (قبل از Release_V1.0)</h2>
        <?php
        $skeleton = [
            'includes/install_guard.php' => 'قفل نصب امن (Release_V1.0)',
            'includes/db_sqlite_compat.php' => 'شیم SQLite (نسخهٔ دسکتاپ)',
            'includes/release_install.php' => 'مایگریشن نصب',
            'config/database.php' => 'تنظیمات دیتابیس',
        ];
        ?>
        <table>
            <tr><th>فایل</th><th>وضعیت</th><th>کاربرد</th></tr>
            <?php foreach ($skeleton as $f => $why): $on = is_file($root . '/' . $f); ?>
                <tr>
                    <td><code><?= $esc($f) ?></code></td>
                    <td class="<?= $on ? 'ok' : 'warn' ?>"><?= $on ? 'هست' : 'نیست' ?></td>
                    <td><?= $esc($why) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p style="font-size:12px;color:#64748b;margin:8px 0 0">
            نبودِ <code>install_guard.php</code> و <code>db_sqlite_compat.php</code> طبیعی است اگر سایت از نسخه‌ای قدیمی‌تر از
            <code>Release_V1.0</code> آمده باشد؛ از V4.209.2 به بعد هیچ فایلی آن‌ها را با <code>require</code> بی‌قید صدا نمی‌زند.
            نبودِ <code>config/database.php</code> یا <code>installed.lock</code> یعنی نصب ناقص است.
        </p>
    </div>

    <div class="card">
        <h2>۶) اتصال به دیتابیس</h2>
        <table>
            <tr><th>درایور</th><td><code><?= $esc($db['driver']) ?></code></td></tr>
            <tr><th>نتیجه</th>
                <td class="<?= $db['ok'] === true ? 'ok' : ($db['ok'] === false ? 'bad' : 'warn') ?>">
                    <?= $db['ok'] === true ? 'متصل شد' : ($db['ok'] === false ? 'ناموفق' : 'آزمون نشد') ?>
                </td></tr>
            <?php if ($db['error'] !== ''): ?>
                <tr><th>پیام خطا</th><td><code><?= $esc($db['error']) ?></code></td></tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="card">
        <h2>۷) آزمون بالا آمدن هستهٔ برنامه</h2>
        <?php if ($boot['ok'] === null): ?>
            <p style="font-size:12.5px;margin:0">برای اجرای آزمون (یک‌بار)، روی دکمه بزنید. این کار همان مسیری را می‌رود که صفحات سایت می‌روند.</p>
            <a class="btn" href="?boot=1">اجرای آزمون بوت</a>
        <?php else: ?>
            <p style="font-size:13px;margin:0 0 8px" class="<?= $boot['ok'] ? 'ok' : 'bad' ?>">
                <?= $boot['ok'] ? 'هستهٔ برنامه بدون خطا بالا آمد' : 'هستهٔ برنامه خطا داد' ?>
            </p>
            <?php if ($boot['error'] !== ''): ?><pre><?= $esc($boot['error']) ?></pre><?php endif; ?>
            <table style="margin-top:10px">
                <tr><th>تابع/کلاس</th><th>وضعیت</th></tr>
                <?php foreach ($boot['functions'] as $f => $on): ?>
                    <tr><td><code><?= $esc($f) ?></code></td><td class="<?= $on ? 'ok' : 'bad' ?>"><?= $on ? 'هست' : 'نیست' ?></td></tr>
                <?php endforeach; ?>
            </table>
            <?php if (!empty($boot['output'])): ?><pre><?= $esc($boot['output']) ?></pre><?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>۸) آخرین خطاهای ثبت‌شده (<code>config/php-error.log</code>)</h2>
        <?php if (!$logLines): ?>
            <p style="font-size:12.5px;margin:0;color:#64748b">چیزی ثبت نشده است.</p>
        <?php else: ?>
            <pre><?php foreach ($logLines as $l) echo $esc($l) . "\n"; ?></pre>
        <?php endif; ?>
    </div>

    <div class="sub" style="margin-top:18px">SchoolDesk Pro · عیب‌یاب V4.214.2 — بعد از پایان کار این فایل را حذف کنید.</div>
</div>
</body>
</html>
