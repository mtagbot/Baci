<?php
/**
 * includes/card_back.php — پشت کارت ورود (v4.137.0)
 *
 * خواستهٔ کارفرما: «هر دو طرف کارت تگ باشد، برای راحتی کار.»
 *
 * پس پشت کارت دیگر فقط متن مقررات نیست. یک QR حتی بزرگ‌تر
 * (۳۶ میلی‌متر در برابر ۳۰ میلی‌متر روی کارت) وسط آن نشسته، چون این
 * سمت جای کمتری برای اطلاعات لازم دارد. نتیجه: کارت در هولدر به هر
 * طرف که باشد، اسکن می‌شود و اپراتور لازم نیست کارت را بچرخاند.
 *
 * نام و کلاس دانش‌آموز اینجا هم تکرار شده تا وقتی کارت برعکس است،
 * بدون برگرداندن بشود فهمید مال کیست.
 */
?>
<div class="card-back<?php echo !empty($D['cut']) ? ' cut' : ''; ?>">
    <div class="card-back-in">
        <div class="card-back-qr-box">
            <canvas class="card-back-qr" data-qr="<?php echo clean($s['qr']); ?>"></canvas>
            <div class="card-back-qr-cap">اسکن برای حضور و غیاب</div>
        </div>

        <svg class="card-back-orn" viewBox="0 0 100 100" aria-hidden="true"><use href="#orn-flower" xlink:href="#orn-flower"></use></svg>

        <div class="card-back-side">
            <div class="card-back-name"><?php echo clean($fullName); ?></div>
            <div class="card-back-sub">
                <?php echo clean($s['class_name'] ?: '—'); ?><?php if (!empty($s['grade_level'])): ?> · پایهٔ <?php echo clean($s['grade_level']); ?><?php endif; ?>
            </div>
            <ul class="card-back-l">
                <li>همراه‌داشتن کارت الزامی است.</li>
                <li>کارت شخصی است و واگذاری آن مجاز نیست.</li>
                <li>در صورت مفقودی به دفتر اطلاع دهید.</li>
            </ul>
        </div>

        <div class="card-back-foot">
            <?php echo clean($schoolShort); ?>
            <?php $ph = get_setting('school_phone', ''); if ($ph !== ''): ?>
                — تلفن: <?php echo clean(tr_num($ph, 'fa')); ?>
            <?php endif; ?>
        </div>
    </div>
</div>
