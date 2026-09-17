<?php
/** Honest recovery for three missing baseline modules: keep HTTP 404, provide a way out. */
require_once __DIR__.'/auth.php';
$routes=[
 'import.php'=>['ورود کارنامه‌ها','reports-management.php','manage_reports'],
 'import-photos.php'=>['ورود گروهی عکس‌ها','student-recovery.php','import_data'],
 'grade-entry-management.php'=>['مدیریت ثبت نمره','reports-management.php','manage_reports']
];
$route=$routes[basename($_SERVER['SCRIPT_NAME']??'')]??['ابزار درخواستی','index.php','manage_reports'];
require_permission($route[2]);
http_response_code(404);
header('Cache-Control: no-store');
require_once __DIR__.'/header.php';
?>
<section class="card" style="max-width:760px;margin:24px auto;padding:24px">
 <h1>بخش «<?php echo clean($route[0]); ?>» در نسخهٔ مبنا موجود نیست</h1>
 <p>فایل اصلی این ابزار در بستهٔ کامل قبلی وجود نداشت. این صفحه فقط مسیر بازگشت را فراهم می‌کند؛ عملیات ورود یا ثبت اطلاعات انجام نشده است.</p>
 <p>برای فعال‌شدن این قابلیت، باید فایل اصلی ابزار جداگانه بازیابی و آزموده شود. سایر بخش‌های برنامه قابل استفاده‌اند.</p>
 <a class="btn btn-primary" href="<?php echo clean($route[1]); ?>">بازگشت به بخش مربوط</a>
 <a class="btn btn-secondary" href="index.php">صفحهٔ اصلی</a>
</section>
<?php require_once __DIR__.'/footer.php'; ?>
