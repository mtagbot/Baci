<?php
// File: bot-inbox.php  (v4.177.0)
/**
 * «پیام‌های دریافتی ربات» — صندوق ورودی.
 *
 * هر متنی که کاربران متصل به ربات بفرستند (مثلاً پیام‌های بی‌هدف یا اشتباهی)
 * اینجا با نام کاربر، نام دانش‌آموز (اگر پیوند خورده باشد) و زمان ثبت می‌شود.
 * از «تنظیمات دیگر ← پیام‌های دریافتی ربات» قابل پیگیری است.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bot_helpers.php';
require_once __DIR__ . '/includes/bot_inbox.php';

require_permission('send_sms');

bot_inbox_schema();

$inboxPlatform = bot_valid_platform($_GET['platform'] ?? 'bale');
$inboxFilters  = bot_inbox_filters();
$inboxFilters['platform'] = $inboxPlatform;
$inboxPerPage  = 50;
$inboxTotal    = bot_inbox_total($inboxFilters);
$inboxPages    = max(1, (int)ceil($inboxTotal / $inboxPerPage));
$inboxPage     = max(1, min($inboxPages, (int)($_GET['page'] ?? 1)));
$inboxRows     = bot_inbox_rows($inboxFilters, $inboxPage, $inboxPerPage);
$inboxKinds    = bot_inbox_kind_counts($inboxPlatform);
$inboxTitles   = ['bale' => 'بله', 'telegram' => 'تلگرام'];

/* v4.177.1 — اگر برای این پیام‌رسان پیامی نیست ولی برای آن یکی هست، به مدیر
   می‌گوییم پیام‌ها کجا هستند (تب پیش‌فرض «بله» است و تلگرام را نشان نمی‌دهد). */
$inboxOtherKey  = $inboxPlatform === 'bale' ? 'telegram' : 'bale';
$inboxOtherRows = bot_inbox_total(['platform' => $inboxOtherKey, 'q' => '', 'kind' => '', 'student' => '']);

require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-6">
    <div class="page-hero flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold"><svg data-ui-icon="bot" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="7" width="18" height="14" rx="4"/><path d="M12 7V2M10 2h4M7 12v2m10-2v2m-9 3h8"/></svg> پیام‌های دریافتی ربات</h2>
            <p class="text-sm text-muted">هر متنی که کاربران به ربات فرستادند اینجا ثبت می‌شود — از جمله پیام‌های بی‌هدف یا اشتباهی.</p>
        </div>
        <div class="flex gap-2">
            <a href="bot-inbox.php?platform=bale" class="btn <?php echo $inboxPlatform === 'bale' ? 'btn-primary' : 'btn-secondary'; ?> text-xs">بله</a>
            <a href="bot-inbox.php?platform=telegram" class="btn <?php echo $inboxPlatform === 'telegram' ? 'btn-primary' : 'btn-secondary'; ?> text-xs">تلگرام</a>
            <a href="other-settings.php" class="btn btn-outline text-xs">بازگشت به تنظیمات دیگر</a>
        </div>
    </div>

    <div class="card p-4">
        <form method="GET" class="filters-line">
            <input type="hidden" name="platform" value="<?php echo clean($inboxPlatform); ?>">
            <div class="filter-primary"><label class="text-xs font-bold">جستجو</label>
                <input type="text" name="q" class="form-input" placeholder="متن پیام، نام کاربری یا شناسهٔ گفتگو…" value="<?php echo clean($inboxFilters['q']); ?>"></div>
            <div class="filter-primary"><label class="text-xs font-bold">نوع پیام</label>
                <select name="kind" class="form-select">
                    <option value="">همه</option>
                    <?php foreach ($inboxKinds as $k => $c): if ($c <= 0 && $k === 'other') continue; ?>
                        <option value="<?php echo clean($k); ?>" <?php echo $inboxFilters['kind'] === $k ? 'selected' : ''; ?>>
                            <?php echo clean(['text' => 'متن', 'photo' => 'عکس', 'document' => 'فایل', 'voice' => 'صدا', 'video' => 'ویدیو', 'sticker' => 'استیکر', 'contact' => 'مخاطب', 'location' => 'موقعیت', 'other' => 'سایر'][$k] ?? $k); ?>
                            (<?php echo clean(tr_num((string)$c, 'fa')); ?>)
                        </option>
                    <?php endforeach; ?>
                </select></div>
            <div class="filter-primary"><label class="text-xs font-bold">فرستنده</label>
                <select name="student" class="form-select">
                    <option value="">همه</option>
                    <option value="linked" <?php echo $inboxFilters['student'] === 'linked' ? 'selected' : ''; ?>>دانش‌آموز پیوندخورده</option>
                    <option value="unknown" <?php echo $inboxFilters['student'] === 'unknown' ? 'selected' : ''; ?>>ناشناس</option>
                </select></div>
            <div class="flex gap-2"><button class="btn btn-primary">فیلتر</button><a href="bot-inbox.php?platform=<?php echo clean($inboxPlatform); ?>" class="btn btn-secondary">حذف</a></div>
        </form>
    </div>

    <div class="card p-4">
        <div class="flex justify-between items-center flex-wrap gap-2" style="margin-bottom:10px">
            <h3 class="font-bold text-sm">پیام‌های <?php echo clean($inboxTitles[$inboxPlatform]); ?></h3>
            <span class="text-xs text-muted">
                مجموع <?php echo clean(tr_num((string)$inboxTotal, 'fa')); ?> پیام ·
                صفحهٔ <?php echo clean(tr_num((string)$inboxPage, 'fa')); ?> از <?php echo clean(tr_num((string)$inboxPages, 'fa')); ?>
            </span>
        </div>
        <?php if ($inboxTotal === 0 && $inboxOtherRows > 0): ?>
        <div class="card p-3" style="margin-bottom:10px;border-right:3px solid var(--primary,#2563eb)">
            <p class="text-xs" style="line-height:2">
                برای «<?php echo clean($inboxTitles[$inboxPlatform]); ?>» پیامی ثبت نشده است، اما
                <?php echo clean(tr_num((string)$inboxOtherRows, 'fa')); ?> پیام در
                «<?php echo clean($inboxTitles[$inboxOtherKey]); ?>» هست —
                <a href="bot-inbox.php?platform=<?php echo clean($inboxOtherKey); ?>" class="font-bold">دیدن <?php echo clean($inboxTitles[$inboxOtherKey]); ?></a>
            </p>
        </div>
        <?php endif; ?>
        <?php if ($inboxPages > 1): ?>
        <nav class="flex gap-2 items-center flex-wrap" style="margin-bottom:10px" aria-label="صفحه‌های پیام‌ها">
            <?php for ($pg = 1; $pg <= $inboxPages; $pg++):
                $qs = ['platform=' . $inboxPlatform, 'page=' . $pg];
                if ($inboxFilters['q'] !== '') $qs[] = 'q=' . urlencode($inboxFilters['q']);
                if ($inboxFilters['kind'] !== '') $qs[] = 'kind=' . urlencode($inboxFilters['kind']);
                if ($inboxFilters['student'] !== '') $qs[] = 'student=' . urlencode($inboxFilters['student']);
            ?>
                <a class="btn <?php echo $pg === $inboxPage ? 'btn-primary' : 'btn-outline'; ?> text-xs" href="bot-inbox.php?<?php echo implode('&amp;', $qs); ?>"><?php echo clean(tr_num((string)$pg, 'fa')); ?></a>
            <?php endfor; ?>
        </nav>
        <?php endif; ?>
        <div class="table-container">
            <table>
                <thead><tr><th style="width:60px">#</th><th style="width:150px">فرستنده</th><th style="width:150px">دانش‌آموز</th><th>متن پیام</th><th style="width:90px">نوع</th><th style="width:150px">زمان</th></tr></thead>
                <tbody>
                <?php foreach ($inboxRows as $r): ?>
                    <tr>
                        <td class="font-mono text-xs"><?php echo clean(tr_num((string)$r['id'], 'fa')); ?></td>
                        <td class="font-mono text-xs dir-ltr"><?php echo clean($r['username'] !== null && $r['username'] !== '' ? '@' . $r['username'] : $r['chat_id']); ?></td>
                        <td class="text-xs">
                            <?php if (!empty($r['first_name']) || !empty($r['last_name'])): ?>
                                <span class="font-bold"><?php echo clean(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))); ?></span>
                                <div class="text-muted"><?php echo clean($r['class_name'] ?? ''); ?></div>
                            <?php else: ?><span class="text-muted">ناشناس</span><?php endif; ?>
                        </td>
                        <td style="white-space:pre-wrap;line-height:1.9"><?php echo clean((string)$r['message']); ?></td>
                        <td class="text-xs"><?php echo clean($r['kind'] ?? 'text'); ?></td>
                        <td class="text-xs text-muted" dir="ltr"><?php echo clean(jdate('Y/m/d H:i:s', strtotime((string)$r['created_at']))); ?></td>
                    </tr>
                <?php endforeach; if (!$inboxRows): ?>
                    <tr><td colspan="6" class="text-center text-muted py-6">هنوز پیامی دریافت نشده است.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (!$inboxRows && $inboxOtherRows <= 0): ?>
        <div class="card p-4" style="margin-top:12px;border-right:3px solid var(--warning,#d97706)">
            <h4 class="font-bold text-sm" style="margin-bottom:6px">چرا پیامی نمی‌بینم؟</h4>
            <ul class="text-xs text-muted" style="line-height:2.2;list-style:disc;padding-inline-start:18px">
                <li>این صفحه فقط پیام‌های <b>دریافتیِ بعد از نصب نسخهٔ ۴.۱۷۷.۱</b> را نشان می‌دهد؛ پیام‌های قدیمی‌تر هیچ‌گاه نگه داشته نمی‌شدند.</li>
                <li>پیام باید به <b>ربات</b> فرستاده شود (مثلاً در گفتگوی خصوصی با ربات مدرسه)، نه به یک کانال یا گروه.</li>
                <li>اگر ربات به پیام شما جواب داد ولی اینجا نیست، یعنی قلاب ثبت در
                    <span dir="ltr">includes/bot_webhook_engine.php</span> و ماژول
                    <span dir="ltr">includes/bot_inbox.php</span> نصب نشده‌اند — هر دو فایل باید روی سایت باشند.</li>
                <li>پاک‌سازی خودکار (پیش‌فرض ۶۰ روز) پیام‌های کهنه‌تر را حذف می‌کند؛ مقدار را در «سلامت پایگاه داده ← قواعد نگهداشت» تغییر دهید یا خاموش کنید.</li>
            </ul>
        </div>
        <?php endif; ?>
        <p class="text-muted text-xs" style="line-height:2;margin-top:10px">
            پیام‌های ورودی تا زمانی که لازم باشد نگه داشته می‌شوند؛ پاک‌سازی خودکارِ این جدول (پیش‌فرض ۶۰ روز) را می‌توانید در
            «سلامت پایگاه داده ← قواعد نگهداشت» تغییر دهید یا خاموش کنید. برای پیام‌های خروجی، «لاگ فعالیت» و صف اعلان‌ها را ببینید.
        </p>
    </div>
</div>
<?php
require_once __DIR__ . '/includes/footer.php';
