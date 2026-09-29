<?php
// Run every minute using hosting Cron: php /absolute/path/reports/cron/bot-outbox-worker.php
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only');}
require_once dirname(__DIR__).'/includes/bot_helpers.php';
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();

/* v4.172.0: نگهداشت خودکار صف — جدول bot_outbox تنها جدولی بود که بدون هیچ
   پاکسازی بی‌نهایت رشد می‌کرد (هر اعلانِ ارسال‌شده یک ردیف ماندگار). حالا
   worker یک بار در روز (بسته به تنظیم «نگهداشت رسیدهای ارسال‌شده») فقط
   رسیدهای ارسال‌شدهٔ قدیمی و پیام‌های مسدودِ کهنه را پاک می‌کند.
   قواعد ایمنی (دست‌نخورده): صف در انتظار/در حال ارسال/صف دسکتاپ (relay) و
   رکوردهای تازه‌تر از سقف هرگز حذف نمی‌شوند؛ یک کوئری سبکِ ایندکس‌دار. */
function bot_outbox_retention_sweep() {
    if (!function_exists('bot_outbox_schema')) return 0;
    try {
        bot_outbox_schema();
        $sentDays = max(1, (int)get_setting('dbopt_outbox_sent_days', '30'));
        $blockedDays = max(1, (int)get_setting('dbopt_outbox_blocked_days', '90'));
        // یک بار در روز کافی است: زمان آخرین پاکسازی در همان تنظیمات می‌ماند.
        $last = (int)get_setting('dbopt_outbox_retention_last', '0');
        if ($last > time() - 82800) return 0;   // ۲۳ ساعت
        $driver = 'sqlite';
        try { $driver = DB::getInstance()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME); } catch (Throwable $e) {}
        $cut = function ($days) use ($driver) {
            return $driver === 'sqlite'
                ? "strftime('%s','now','-{$days} days')"
                : "UNIX_TIMESTAMP(NOW() - INTERVAL {$days} DAY)";
        };
        $removed = 0;
        // رسیدِ ارسال‌شده با sent_at سنجیده می‌شود؛ پیام مسدود هرگز ارسال نشده
        // پس سنش created_at است.
        foreach ([['sent', 'sent_at', $sentDays], ['blocked', 'created_at', $blockedDays]] as [$state, $col, $days]) {
            $limit = $cut($days);
            $st = bot_outbox_sql("DELETE FROM bot_outbox WHERE owner='local' AND state=? AND $col > 0 AND $col < $limit", [$state]);
            $removed += $st ? (int)$st->rowCount() : 0;
        }
        set_setting('dbopt_outbox_retention_last', (string)time());
        if ($removed > 0) error_log("bot_outbox retention: $removed قدیمی رکورد حذف شد");
        return $removed;
    } catch (Throwable $e) {
        error_log('bot_outbox retention skipped: ' . $e->getMessage());
        return 0;
    }
}

try {
    echo 'sent='.bot_outbox_drain(100,40).PHP_EOL;
    $pruned = bot_outbox_retention_sweep();
    if ($pruned) echo 'retention='.$pruned.PHP_EOL;
}
catch(Throwable $e){fwrite(STDERR,"Notification queue unavailable; messages retained.\n");exit(1);}
