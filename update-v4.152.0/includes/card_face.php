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
$_customLogo = $cardCustomLogo ?? $logoUrl ?? '';
/* v4.139.0: شکل کارت — کلاس اضافی روی همان قالب، تا یک منبع بماند.
   نکته: clean() روی مقدار trim می‌زند، پس فاصلهٔ جداکنندهٔ کلاس‌ها
   باید بیرون از clean() چاپ شود؛ وگرنه کلاس‌ها به‌هم می‌چسبند. */
$_layout    = $D['layout'] ?? 'full';
$_layCls    = ($_layout === 'sq') ? 'sq' : (($_layout === 'qrmax') ? 'qrmax' : (($_layout === 'custom') ? 'custom' : ''));
$_metaLine  = trim(implode(' · ', array_filter([$province, $region, $unitType])));
list($_heroArt, $_headArt, ) = card_theme_art($_cardTheme);
?>
<div class="card-id th-<?php echo clean($_cardTheme); ?><?php echo $_customLogo !== '' ? ' has-custom-logo' : ''; ?><?php echo $_layCls !== '' ? ' ' . clean($_layCls) : ''; ?><?php echo $_cardCut ? ' cut' : ''; ?>">
    <svg class="card-bg" aria-hidden="true"><rect width="100%" height="100%"/></svg>
    <?php if ($_cardTheme === 'tile'): ?><div class="card-frame"></div><?php endif; ?>

    <div class="card-head">
        <img class="card-custom-logo card-custom-only" <?php if($_customLogo !== ''): ?>src="<?php echo clean($_customLogo); ?>"<?php endif; ?> alt="">
        <svg class="card-custom-mark card-custom-only" viewBox="0 0 100 65" aria-hidden="true">
            <g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M50 59C31 43 6 57 3 38M50 59C69 43 94 57 97 38M50 58C30 36 8 43 8 21C24 16 42 32 50 58M50 58C70 36 92 43 92 21C76 16 58 32 50 58"/>
                <path d="M50 58C30 26 16 31 20 9C36 7 47 33 50 58M50 58C70 26 84 31 80 9C64 7 53 33 50 58M50 58C38 28 29 16 36 3C48 2 49 31 50 58M50 58C62 28 71 16 64 3C52 2 51 31 50 58M50 57V8M5 58C20 64 35 57 50 62C65 57 80 64 95 58"/>
                <circle cx="50" cy="4" r="2"/>
            </g>
        </svg>
        <svg class="card-head-orn" viewBox="0 0 100 100" aria-hidden="true"><use href="#orn-<?php echo clean($_headArt); ?>" xlink:href="#orn-<?php echo clean($_headArt); ?>"></use></svg>
        <?php if (!empty($logoUrl)): ?>
            <img class="card-logo" src="<?php echo clean($logoUrl); ?>" alt="">
        <?php endif; ?>
        <?php if ($_layout === 'sq'): ?>
            <?php /* v4.140.0: در حالت مربع فقط «مدرسه — نام دانش‌آموز».
                  کلاس و پایه عمداً نیستند (خواستهٔ کارفرما) و همین یک
                  خط، کل اطلاعات کارت است تا بقیهٔ سطح به QR برسد. */ ?>
            <div class="card-school"><?php echo clean($schoolShort); ?> — <?php echo clean($fullName); ?></div>
        <?php else: ?>
            <div class="card-school"><?php echo clean($schoolShort); ?></div>
            <?php if ($_metaLine !== ''): ?>
                <div class="card-meta"><?php echo clean($_metaLine); ?></div>
            <?php endif; ?>
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
                    <div class="card-row card-grade"><span class="lb">پایه:</span> <?php echo clean($s['grade_level']); ?></div>
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

        <div class="card-custom-site card-custom-only" dir="ltr"><?php echo clean($cardCustomText ?? 'Bacirat.ir'); ?></div>
        <div class="card-foot">
            <?php if (!empty($D['year'])): ?>
                <div class="card-year">سال تحصیلی <b><?php echo clean(tr_num($year, 'fa')); ?></b></div>
            <?php endif; ?>
        </div>

        <?php if ($_cardTheme === 'classic' || $_cardTheme === 'titr'): ?><div class="card-band"></div><?php endif; ?>
    </div>
</div>
