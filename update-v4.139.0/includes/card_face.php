<?php
/**
 * includes/card_face.php — روی کارت ورود (v4.138.0)
 *
 * چیدمان: QR ستون راست را کامل می‌گیرد (۴۰ میلی‌متر)، اطلاعات در
 * ستون چپ. این تنها آرایشی بود که QR این اندازه را بدون له‌کردن متن
 * جا می‌داد — و طبق خواستهٔ کارفرما، عملکرد بر چیدمان مقدم است.
 *
 * تصویر شاخص هر طرح از card_theme_art() می‌آید، پس افزودن طرح جدید
 * به این فایل دست نمی‌زند.
 *
 * انتظار دارد ست شده باشند: $s (با کلید qr)، $fullName، $D،
 * $schoolShort، $province، $region، $unitType، $logoUrl، $year
 */
$_cardTheme = $D['theme'] ?? 'classic';
$_cardCut   = !empty($D['cut']);
/* v4.139.0: شکل کارت — کلاس اضافی روی همان قالب، تا یک منبع بماند.
   نکته: clean() روی مقدار trim می‌زند، پس فاصلهٔ جداکنندهٔ کلاس‌ها
   باید بیرون از clean() چاپ شود؛ وگرنه کلاس‌ها به‌هم می‌چسبند. */
$_layout    = $D['layout'] ?? 'full';
$_layCls    = ($_layout === 'sq') ? 'sq' : (($_layout === 'qrmax') ? 'qrmax' : '');
$_metaLine  = trim(implode(' · ', array_filter([$province, $region, $unitType])));
list($_heroArt, $_headArt, ) = card_theme_art($_cardTheme);
?>
<div class="card-id th-<?php echo clean($_cardTheme); ?><?php echo $_layCls !== '' ? ' ' . clean($_layCls) : ''; ?><?php echo $_cardCut ? ' cut' : ''; ?>">
    <svg class="card-bg" aria-hidden="true"><rect width="100%" height="100%"/></svg>
    <?php if ($_cardTheme === 'tile'): ?><div class="card-frame"></div><?php endif; ?>

    <div class="card-head">
        <svg class="card-head-orn" viewBox="0 0 100 100" aria-hidden="true"><use href="#orn-<?php echo clean($_headArt); ?>" xlink:href="#orn-<?php echo clean($_headArt); ?>"></use></svg>
        <?php if (!empty($logoUrl)): ?>
            <img class="card-logo" src="<?php echo clean($logoUrl); ?>" alt="">
        <?php endif; ?>
        <div class="card-school"><?php echo clean($schoolShort); ?></div>
        <?php if ($_metaLine !== ''): ?>
            <div class="card-meta"><?php echo clean($_metaLine); ?></div>
        <?php endif; ?>
    </div>

    <div class="card-body">
        <?php /* QR — بزرگ‌ترین عنصر کارت */ ?>
        <div class="card-qr-box">
            <canvas class="card-qr" data-qr="<?php echo clean($s['qr']); ?>"></canvas>
            <div class="card-qr-cap">کارت ورود دانش‌آموز</div>
        </div>

        <div class="card-info">
            <div class="card-name"><?php echo clean($fullName); ?></div>
            <div class="card-rows">
                <div class="card-row"><span class="lb">کلاس:</span> <b><?php echo clean($s['class_name'] ?: '—'); ?></b></div>
                <?php if (!empty($s['grade_level'])): ?>
                    <div class="card-row"><span class="lb">پایه:</span> <?php echo clean($s['grade_level']); ?></div>
                <?php endif; ?>
                <?php if (!empty($D['nid'])): ?>
                    <div class="card-row card-nid"><span class="lb">کد ملی:</span> <?php echo clean(tr_num($s['national_id'], 'fa')); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <?php /* تصویر شاخص طرح — پشت ستون چپ */ ?>
        <svg class="card-hero" viewBox="0 0 100 100" aria-hidden="true"><use href="#orn-<?php echo clean($_heroArt); ?>" xlink:href="#orn-<?php echo clean($_heroArt); ?>"></use></svg>

        <?php if (!empty($D['photo'])): ?>
            <?php if (!empty($s['photo_url'])): ?>
                <img class="card-photo" src="<?php echo clean($s['photo_url']); ?>" alt="">
            <?php else: ?>
                <div class="card-photo-ph">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="12" cy="8" r="3.6"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>
                    </svg>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="card-foot">
            <?php if (!empty($D['year'])): ?>
                <div class="card-year">سال تحصیلی <b><?php echo clean(tr_num($year, 'fa')); ?></b></div>
            <?php endif; ?>
        </div>

        <?php if ($_cardTheme === 'classic' || $_cardTheme === 'titr'): ?><div class="card-band"></div><?php endif; ?>
    </div>
</div>
