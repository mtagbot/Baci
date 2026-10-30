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
?>
<footer class="school-footer<?php echo $showConnection?' has-desk-state':''; ?>" aria-label="اطلاعات سامانه مدرسه">
<div class="school-footer-inner">
<div class="school-footer-brand"><strong><?php echo clean(get_setting('school_name','سامانه مدیریت مدرسه')); ?></strong></div>
<div class="school-footer-credit">طراحی و توسعه : معاونت فناوری متوسطه اول</div>
<?php if($footerLogged): ?><nav class="school-footer-links" aria-label="نشست کاربری"><a href="my-sessions.php">نشست‌های من</a></nav><?php endif; ?>
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
<script defer src="assets/vendor/chart.umd.min.js?v=4.6.1"></script>
<?php endif; ?>
<?php /* V4.208.0 — این سه فایل در هدر و همراه با school-ui/school-icons/session-watch
       در یک باندل واحد (assets/cache/js-<hash>.js) بارگذاری شده‌اند؛ بارگذاری
       دوباره هم درخواست اضافه می‌سازد و هم کد را دو بار اجرا می‌کند.
       فقط اگر باندل ساخته نشده باشد (پوشهٔ cache غیرقابل‌نوشتن) اینجا تک‌تک می‌آیند. */ ?>
<?php if (empty($GLOBALS['__perf_js_bundled'])): ?>
<script defer src="assets/js/main.js"></script>
<script defer src="assets/js/ui-modern.js?v=20261005a"></script>
<script defer src="assets/js/searchable-select.js?v=20260917e"></script>
<?php endif; ?>
<?php if (PHP_SAPI === 'cli-server'): ?><script defer src="assets/js/desk-shell.js"></script><?php endif; ?>
<?php if($showConnection): ?><script defer src="assets/js/desk-connection.js?v=20260917f"></script><?php endif; ?>
</body>
</html>
