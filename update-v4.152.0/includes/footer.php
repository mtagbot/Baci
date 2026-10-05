<?php /* v4.60.0 fix: teachers were missing from this condition, so for the
   teacher panel the wrapping <div class="flex flex-1"> was never closed and
   the footer rendered INSIDE the flex row (stuck to the left on desktop).
   Teachers get a sidebar + main exactly like admin/student, so they must
   close both tags too. */
if (is_admin_logged_in() || is_student_logged_in() || (function_exists('is_teacher_logged_in') && is_teacher_logged_in())): ?>
    </main>
</div>
<?php else: ?>
    </main>
<?php endif; ?>

<?php
$footerLogged=is_admin_logged_in()||is_student_logged_in()||(function_exists('is_teacher_logged_in')&&is_teacher_logged_in());
$footerRelease=is_file(dirname(__DIR__).'/config/release.php')?require dirname(__DIR__).'/config/release.php':[];
$footerDesk=($footerRelease['distribution']??(PHP_SAPI==='cli-server'?'desktop':'site'))==='desktop';
$showConnection=$footerDesk&&empty($isEmbedded);
/* v4.178.0: شمارهٔ نسخه از config/version.php — نه از release.php، تا بستهٔ
   اصلاحی مجبور نباشد فایلی را بازنویسی کند که درایور بانک را انتخاب می‌کند. */
$footerVersionFile=dirname(__DIR__).'/config/version.php';
$footerVersion=is_file($footerVersionFile)?(require $footerVersionFile):[];
$footerVersionLabel=(string)($footerDesk?($footerVersion['desktop_version']??''):($footerVersion['site_version']??''));
?>
<footer class="school-footer<?php echo $showConnection?' has-desk-state':''; ?>" aria-label="اطلاعات سامانه مدرسه">
<div class="school-footer-inner">
<div class="school-footer-brand"><span class="school-footer-mark"><svg data-ui-icon="school" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m3 9 9-6 9 6v12H3Z"/><path d="M9 21v-6h6v6M7 11h.01M17 11h.01M12 7v3M10.5 8.5h3"/></svg></span><div><strong><?php echo clean(get_setting('school_name','سامانه مدیریت مدرسه')); ?></strong><small>آموزش، ارزشیابی و ارتباطات مدرسه</small></div><?php if($footerVersionLabel!==''): ?><span class="school-footer-version" title="نسخهٔ کد نصب‌شده">نسخهٔ <?php echo clean($footerVersionLabel); ?></span><?php endif; ?></div>
<div class="school-footer-credit">طراحی و توسعه : معاونت فناوری متوسطه اول</div>
<nav class="school-footer-links" aria-label="پیوندهای پایین صفحه"><a href="#main-content">بازگشت به محتوا</a><?php if($footerLogged): ?><a href="my-sessions.php">نشست‌های من</a><?php endif; ?></nav>
<?php if($showConnection): ?>
<details id="deskConnection" class="desk-connection" data-state="<?php echo $footerLogged?'checking':'signed-out'; ?>" data-monitor="<?php echo $footerLogged?'1':'0'; ?>">
<summary aria-controls="deskConnectionPanel"><span class="desk-connection-ring" aria-hidden="true"></span><span id="deskConnectionLabel" role="status" aria-live="polite" aria-atomic="true"><?php echo $footerLogged?'در حال بررسی اتصال':'برای بررسی وضعیت وارد شوید'; ?></span><span aria-hidden="true" class="desk-connection-more">⌃</span></summary>
<div class="desk-connection-panel" id="deskConnectionPanel"><strong>اتصال و همگام‌سازی</strong>
<p id="deskConnectionNetwork">وضعیت شبکه هنوز بررسی نشده است.</p><p id="deskConnectionServer">اتصال به سرور مدرسه هنوز تأیید نشده است.</p><p id="deskConnectionDetail">تغییرات ابتدا در بانک اطلاعاتی برنامه نگهداری می‌شوند.</p><small id="deskConnectionTime">آخرین همگام‌سازی موفق: هنوز بررسی نشده</small>
<?php if(is_admin_logged_in()): ?><a href="desk-sync.php">تنظیمات و جزئیات همگام‌سازی</a><?php endif; ?>
</div></details>
<?php endif; ?>
</div></footer>

<?php if(!empty($needsCharts)||in_array(basename($_SERVER['PHP_SELF']??''),['analytics.php','student-panel.php','report-view.php'],true)): ?>
<script defer src="assets/vendor/chart.umd.min.js"></script>
<?php endif; ?>
<script defer src="assets/js/main.js"></script>
<script defer src="assets/js/ui-modern.js?v=20260917e"></script>
<script defer src="assets/js/searchable-select.js?v=20260917e"></script>
<?php if (PHP_SAPI === 'cli-server'): ?><script defer src="assets/js/desk-shell.js"></script><?php endif; ?>
<?php if($showConnection): ?><script defer src="assets/js/desk-connection.js?v=20260917f"></script><?php endif; ?>
</body>
</html>
