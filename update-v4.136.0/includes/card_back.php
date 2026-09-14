<?php
/**
 * includes/card_back.php — پشت کارت ورود (v4.136.0)
 *
 * فقط وقتی چاپ می‌شود که کاربر گزینهٔ «روی و پشت» را انتخاب کند.
 * چون کارت‌ها پشت‌سرهم می‌آیند، هر پشت بلافاصله بعد از روی همان کارت
 * قرار می‌گیرد تا در چاپ دورو، دو طرف یک کارت روبه‌روی هم بیفتند.
 */
?>
<div class="card-back<?php echo !empty($D['cut']) ? ' cut' : ''; ?>">
    <div class="card-back-t">مقررات استفاده از کارت</div>
    <ul class="card-back-l">
        <li>همراه‌داشتن کارت در ساعات حضور در آموزشگاه الزامی است.</li>
        <li>کارت شخصی است و واگذاری آن به دیگری مجاز نیست.</li>
        <li>ورود و خروج با اسکن کد روی کارت ثبت می‌شود.</li>
        <li>در صورت مفقودی، مراتب را فوراً به دفتر آموزشگاه اطلاع دهید.</li>
    </ul>
    <svg class="card-back-orn" viewBox="0 0 100 100" aria-hidden="true"><use href="#orn-flower" xlink:href="#orn-flower"></use></svg>
    <div class="card-back-foot">
        <?php echo clean($schoolName); ?>
        <?php if (!empty($province) || !empty($region)): ?>
            — <?php echo clean(trim($province . ' ' . $region)); ?>
        <?php endif; ?>
        <?php $ph = get_setting('school_phone', ''); if ($ph !== ''): ?>
            <br>تلفن: <?php echo clean(tr_num($ph, 'fa')); ?>
        <?php endif; ?>
    </div>
</div>
