<?php
define('RELEASE_INSTALLER', true);
date_default_timezone_set('Asia/Tehran');
error_reporting(E_ALL);
ini_set('display_errors','0');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline'; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
$root = __DIR__;
// Query flags, removed cookies, and even deleting ONLY the lock cannot reopen setup.
if (is_file($root.'/config/installed.lock') || is_file($root.'/config/database.php') || is_file($root.'/config/desk-sync-key.php')) {
    http_response_code(403);
    exit('<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>نصب قفل است</title><p>نصب قبلاً انجام شده یا تنظیمات موجود است؛ نصب دوباره و بازنشانی مدیر مجاز نیست.</p><p><a href="index.php">صفحهٔ ورود</a></p></html>');
}
session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off']);
session_start();
require_once __DIR__.'/includes/release_install.php';
require_once __DIR__.'/includes/jdf.php';
if (empty($_SESSION['release_csrf'])) $_SESSION['release_csrf'] = bin2hex(random_bytes(32));
$error = ''; $success = false;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!is_string($_POST['csrf']??null) || !hash_equals($_SESSION['release_csrf'], $_POST['csrf'])) {
        http_response_code(403); $error = 'درخواست معتبر نیست؛ صفحه را دوباره باز کنید.';
    } else {
        try { release_install($_POST); $success = true; }
        catch (Throwable $e) {
            http_response_code(400);
            // Database messages can contain credentials, hostnames or user data; never echo them.
            $error = $e instanceof PDOException ? 'اتصال یا ساخت دیتابیس کامل نشد. مشخصات اتصال و مجوزهای CREATE / ALTER / INDEX / SELECT / INSERT / UPDATE / DELETE را بررسی کنید. نصب موفق ثبت نشده است.' : $e->getMessage();
            if (!($e instanceof RuntimeException)) $error = 'نصب کامل نشد؛ پیش‌نیازها و مجوز نوشتن را بررسی کنید.';
        }
    }
}
function ri_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$desktop = release_distribution()==='desktop';
$checks = release_prerequisites();
$jy = (int)jdate('Y','','','Asia/Tehran','en'); $jm = (int)jdate('n','','','Asia/Tehran','en'); $startYear = $jm>=7 ? $jy : $jy-1;
$year = $startYear.'/'.($startYear+1);
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>نصب Release_V1.0</title>
<style>@font-face{font-family:Vazir;src:url('uploads/Vazirmatn/Vazirmatn-Regular.ttf')}*{box-sizing:border-box}body{font-family:Vazir,Tahoma,sans-serif;background:#f1f5f9;color:#172b45;margin:0;line-height:1.9}.wrap{max-width:860px;margin:32px auto;padding:26px;background:white;border-radius:16px;box-shadow:0 10px 35px #172b4510}h1{margin:0;color:#123d67;font-size:27px}.muted{color:#52647a}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}label{display:block}input{display:block;width:100%;border:1px solid #bccbdc;border-radius:7px;padding:11px;font:inherit;background:#fff}fieldset{border:1px solid #d7e0eb;border-radius:10px;padding:16px;margin:20px 0}legend{font-weight:bold;padding:0 8px}.notice{padding:14px;border-radius:8px;background:#eff6ff}.error{background:#fff1f2;color:#9f1239}.good{color:#12643c}.bad{color:#b91c1c}button,.button{display:inline-block;background:#155e75;color:white;border:0;padding:12px 26px;border-radius:8px;font:inherit;text-decoration:none;cursor:pointer}code{direction:ltr;display:inline-block}ul.checks{display:grid;grid-template-columns:1fr 1fr;list-style:none;padding:0;font-family:monospace;font-size:13px;direction:ltr}a{color:#155e75}@media(max-width:620px){.wrap{margin:0;padding:18px;border-radius:0}.grid,ul.checks{grid-template-columns:1fr}}</style></head><body><main class="wrap">
<h1>نصب کامل Release_V1.0</h1><p class="muted"><?= $desktop?'نسخهٔ دسکتاپ — دیتابیس محلی SQLite':'نسخهٔ سایت — دیتابیس MySQL' ?> · شامل تغییرات سایت 4.152.0 و دسکتاپ 2.83.0</p>
<?php if ($success): ?>
<section class="notice"><h2>نصب با موفقیت پایان یافت</h2><p>فقط حساب مدیر و سال تحصیلی انتخابی شما ساخته شد؛ دانش‌آموز، معلم، کلاس یا آزمون نمونه‌ای اضافه نشده است. نصب مجدد اکنون قفل است.</p><p>با نام کاربری و رمزی که همین حالا تعیین کردید وارد شوید. این بسته رمز پیش‌فرض ندارد.</p><?php if (!$desktop): ?><p>فایل <code>installer.php</code> و کلید <code>config/install-access.php</code> را پس از نصب از هاست حذف کنید. کلید اتصال دسکتاپ در بخش «همگام‌سازی با سایت» حساب مدیر کل قابل دریافت است.</p><?php endif; ?><a class="button" href="admin-login.php">ورود مدیر</a></section>
<?php else: ?>
<p class="notice">این نصب‌یار فقط برای نصب از صفر است، نه ارتقای مدرسهٔ موجود. هیچ فایل تنظیمات یا جدول موجودی بازنویسی یا پاک نمی‌شود. از نصب این بسته روی پوشهٔ برنامهٔ فعلی خودداری کنید.</p>
<?php if ($error): ?><p class="notice error" role="alert"><?=ri_h($error)?></p><p>اگر بخشی از ساخت دیتابیس انجام شده اما نصب کامل نشده، آن را حفظ و بررسی کنید؛ برای تلاش جدید از دیتابیس/پوشهٔ خالی دیگری استفاده کنید. پاک‌کردن قفل، راه بازیابی رمز نیست.</p><?php endif; ?>
<h2>بررسی پیش‌نیازهای واقعی</h2><ul class="checks"><?php foreach ($checks as $name=>$ok): ?><li class="<?=$ok?'good':'bad'?>"><?=$ok?'✓':'✗'?> <?=ri_h($name)?></li><?php endforeach; ?></ul>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=ri_h($_SESSION['release_csrf'])?>">
<?php if (!$desktop): ?><fieldset><legend>۱. اجازهٔ نصب روی هاست</legend><p>در مدیر فایل هاست، <code>config/install-access.example.php</code> را با نام <code>config/install-access.php</code> کپی کنید و مقدار خالی آن را با یک عبارت محرمانهٔ تصادفی حداقل ۳۲ نویسه‌ای جایگزین کنید. همان عبارت را اینجا وارد کنید. از HTTPS استفاده کنید؛ این مرحله اجازه نمی‌دهد اولین بازدیدکنندهٔ ناشناس مالک سامانه شود.</p><label>کلید اجازهٔ نصب<input type="password" name="install_access" minlength="32" required autocomplete="new-password"></label></fieldset>
<fieldset><legend>۲. دیتابیس خالی MySQL</legend><p>ابتدا بانک اطلاعاتی خالی و کاربر دارای مجوزهای لازم را در پنل هاست بسازید. نیازی به ورود دستی فایل SQL نیست.</p><div class="grid"><label>میزبان<input name="db_host" value="localhost" dir="ltr" required></label><label>درگاه<input name="db_port" value="3306" inputmode="numeric" dir="ltr" required></label><label>نام دیتابیس<input name="db_name" dir="ltr" required></label><label>نام کاربر دیتابیس<input name="db_user" dir="ltr" required></label><label>رمز دیتابیس<input type="password" name="db_pass" dir="ltr" autocomplete="new-password"></label></div></fieldset><?php endif; ?>
<fieldset><legend>مشخصات مدرسه و مدیر کل</legend><div class="grid"><label>نام مدرسه<input name="school_name" maxlength="150" required></label><label>سال تحصیلی<input name="academic_year" value="<?=ri_h($year)?>" dir="ltr" required></label><label>نام مدیر<input name="admin_name" maxlength="100" required></label><label>نام کاربری مدیر (انگلیسی)<input name="admin_username" pattern="[A-Za-z0-9_.-]{3,50}" dir="ltr" required></label><label>رمز مدیر (حداقل ۱۲ نویسهٔ انگلیسی)<input type="password" name="admin_password" minlength="12" maxlength="72" autocomplete="new-password" required></label><label>تکرار رمز مدیر<input type="password" name="admin_password_confirm" minlength="12" maxlength="72" autocomplete="new-password" required></label></div></fieldset>
<button type="submit">ساخت دیتابیس و نصب کامل</button></form>
<?php endif; ?><p class="muted">راهنمای کامل: README-FA.md داخل بسته · بسته مستقل است و نصب وصله‌های قبلی لازم نیست.</p></main></body></html>
