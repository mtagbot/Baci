<?php
// File: bot-queue.php
/**
 * v4.172.0: صف ماندگار اعلان‌های ربات — از صفحهٔ مدیریت ربات به «تنظیمات دیگر»
 * منتقل شد. این صفحه فقط وضعیت صف و دکمهٔ «آماده‌سازی صف برای تلاش مجدد» را
 * دارد؛ توکن، وبهوک، ارسال هدفمند و متن‌ها روی صفحهٔ خود ربات می‌مانند.
 *
 * چرا جدا شد: صفحهٔ ربات برای کارهای روزانهٔ ربات است و بخش صف فقط وقتی مهم
 * است که چیزی ارسال نشده — دیدنش در همان صفحه، خطای واقعی ربات را پنهان
 * می‌کرد. حالا در «تنظیمات دیگر ← صف اعلان‌های ربات» قابل پیگیری است.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bot_queue_ui.php';

require_permission('send_sms');

$queuePlatform = bot_valid_platform($_GET['platform'] ?? 'bale');

/* همان منطق دکمهٔ صف که قبلاً در bot_admin_handle_request بود — عیناً، با
   CSRF و بدون دور زدن محدودیت نرخ پیام‌رسان یا قفل فعال worker. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retry_bot_outbox'])) {
    if (!is_string($_POST['csrf_token'] ?? null) || !verify_csrf($_POST['csrf_token'])) {
        set_flash_message('error', 'خطای امنیتی CSRF. لطفاً صفحه را دوباره بارگذاری کنید.');
        redirect('bot-queue.php?platform=' . $queuePlatform);
    }
    $queuePlatform = bot_valid_platform($_POST['platform'] ?? $queuePlatform);
    bot_outbox_schema();
    bot_outbox_sql("UPDATE bot_outbox SET next_try=0 WHERE platform=? AND state IN ('pending','relayed')", [$queuePlatform]);
    // Keep provider rate limits and live leases intact. Never retry a sent job.
    set_setting('desk_bot_outbox_next', '0');
    set_flash_message('success', 'صف برای تلاش مجدد آماده شد؛ ارسال توسط worker انجام می‌شود.');
    redirect('bot-queue.php?platform=' . $queuePlatform);
}

require_once __DIR__ . '/includes/header.php';
$queueTitles = ['bale' => 'بله', 'telegram' => 'تلگرام'];
?>
<div class="space-y-6">
    <div class="page-hero flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold"><svg data-ui-icon="bot" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="7" width="18" height="14" rx="4"/><path d="M12 7V2M10 2h4M7 12v2m10-2v2m-9 3h8"/></svg> صف اعلان‌های ربات</h2>
            <p class="text-sm text-muted">پیام‌های متنی همهٔ نقش‌ها پس از قطعی حفظ می‌شوند. «در صف» به معنی تحویل به پیام‌رسان نیست.</p>
        </div>
        <div class="flex gap-2">
            <a href="bot-queue.php?platform=bale" class="btn <?php echo $queuePlatform === 'bale' ? 'btn-primary' : 'btn-secondary'; ?> text-xs">بله</a>
            <a href="bot-queue.php?platform=telegram" class="btn <?php echo $queuePlatform === 'telegram' ? 'btn-primary' : 'btn-secondary'; ?> text-xs">تلگرام</a>
            <a href="other-settings.php" class="btn btn-outline text-xs">بازگشت به تنظیمات دیگر</a>
        </div>
    </div>
    <?php bot_admin_render_queue_section($queuePlatform); ?>
</div>
<?php
require_once __DIR__ . '/includes/footer.php';
