<?php
require_once __DIR__.'/includes/auth.php';
$admin = is_admin_logged_in() ? DB::fetch('SELECT role,status FROM admins WHERE id=?', [(int)$_SESSION['admin_id']]) : null;
if (!$admin || $admin['role'] !== 'super_admin' || !(int)$admin['status']) { http_response_code(403); exit('فقط مدیر کل به کلید اتصال دسترسی دارد.'); }
header('Cache-Control: no-store');
$key = require __DIR__.'/config/desk-sync-key.php';
$pageTitle = 'اتصال دسکتاپ به سایت';
require __DIR__.'/includes/header.php';
?>
<section class="card p-6"><h1>اتصال دسکتاپ به این مدرسه</h1><p>در نسخهٔ دسکتاپ، بخش «همگام‌سازی با سایت»، نشانی کامل HTTPS فایل <code dir="ltr">desk-sync-api.php</code> همین سایت و کلید زیر را وارد کنید. برای سایت داخل زیرپوشه، نام زیرپوشه را هم در نشانی بیاورید.</p><p>کلید زیر ویژهٔ همین نصب است و دسترسی همگام‌سازی به اطلاعات مدرسه می‌دهد؛ آن را مانند رمز مدیر محرمانه نگه دارید.</p><label>کلید همگام‌سازی<input readonly dir="ltr" style="width:100%;padding:12px;font-family:monospace" value="<?=htmlspecialchars($key,ENT_QUOTES,'UTF-8')?>"></label><p>برای باطل‌کردن کلید لو‌رفته، مدیر هاست باید مقدار فایل <code>config/desk-sync-key.php</code> را با یک مقدار تصادفی ۶۴ رقمی هگز جدید عوض کند و کلید تازه را در دستگاه‌های مجاز وارد کند. هر دو مسیر همگام‌سازی از همین فایل می‌خوانند.</p><p><strong>پیش از اولین همگام‌سازی، از هر دو طرف پشتیبان بگیرید.</strong> اتصال را فقط به مدرسهٔ خودتان انجام دهید؛ دریافت اولیهٔ اطلاعات سایت می‌تواند داده‌های محلی را جایگزین کند.</p></section>
<?php require __DIR__.'/includes/footer.php'; ?>
