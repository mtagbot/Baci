<?php
// File: includes/bot_queue_ui.php
/**
 * v4.172.0: بخش «صف ماندگار اعلان‌ها» — از صفحهٔ مدیریت ربات به «تنظیمات دیگر»
 * (bot-queue.php) منتقل شد. همان محتوا و همان منطق؛ فقط جای نمایش عوض شده و
 * هیچ کوئری یا رفتاری تغییر نکرده است.
 *
 * چرا فایل جدا شد: صفحهٔ ربات هم روی سایت و هم روی دسکتاپ اجرا می‌شود و
 * نسخهٔ دسکتاپ این بخش را ندارد. با فایل مستقل، هر دو توزیع بدون دست‌زدن به
 * bot_admin_ui.php به این بخش دسترسی دارند.
 */

require_once __DIR__ . '/bot_helpers.php';   // bot_outbox_* و bot_valid_platform
if (!function_exists('bot_admin_render_queue_section')) {

    function bot_admin_render_queue_section($platform) {
        $platform = bot_valid_platform($platform);
    bot_outbox_schema();
    $queueCounts=bot_outbox_sql('SELECT state,COUNT(*) AS n FROM bot_outbox WHERE platform=? GROUP BY state',[$platform])->fetchAll(PDO::FETCH_ASSOC);
    /* v4.168.0: فهرست فشردهٔ صف — پیام‌های state='blocked' (خطای ۴۰۳) بخش
       اختصاصی خودشان را پایین‌تر با نام ولی/دانش‌آموز دارند، پس اینجا نمی‌آیند؛
       بقیه بر اساس (وضعیت + خطا) گروه می‌شوند تا صفحه با ده‌ها ردیف تکراریِ
       یک خطای یکسان پُر نشود. متن خطا هم خلاصه و خوانا نمایش داده می‌شود. */
    $queueRows=bot_outbox_sql("SELECT state,owner,attempts,last_error FROM bot_outbox WHERE platform=? AND state NOT IN ('sent','blocked') ORDER BY created_at DESC LIMIT 40",[$platform])->fetchAll(PDO::FETCH_ASSOC);
    $stateNames=['pending'=>'در انتظار تلاش','sending'=>'در حال ارسال','relayed'=>'در صف سایت','blocked'=>'مسدود (بدون تلاش)','sent'=>'ارسال تأییدشده'];
    $botErrLabel=function($e){
        $e=trim((string)$e);
        if($e==='')return '';
        $code='';$desc='';
        if(preg_match('/HTTP (\d{3})/',$e,$m))$code=$m[1];
        if(preg_match('/"description"\s*:\s*"([^"]{1,160})"/u',$e,$d))$desc=trim($d[1]);
        if($code==='403')return 'خطای ۴۰۳ — کاربر ربات را مسدود کرده است';
        if($code!=='')return 'خطای HTTP '.$code.($desc!==''?' — '.$desc:'');
        return function_exists('mb_substr')?mb_substr($e,0,120,'UTF-8'):substr($e,0,120);
    };
    $queueGroups=[];
    foreach($queueRows as $q){
        $err=$botErrLabel($q['last_error']);
        $sig=$q['state'].'|'.$q['owner'].'|'.$err;
        if(!isset($queueGroups[$sig]))$queueGroups[$sig]=['state'=>$q['state'],'owner'=>$q['owner'],'err'=>$err,'n'=>0,'minA'=>PHP_INT_MAX,'maxA'=>0];
        $queueGroups[$sig]['n']++;
        $queueGroups[$sig]['minA']=min($queueGroups[$sig]['minA'],(int)$q['attempts']);
        $queueGroups[$sig]['maxA']=max($queueGroups[$sig]['maxA'],(int)$q['attempts']);
    }
    ?>
    <section class="card space-y-3" aria-labelledby="bot-queue-heading">
        <h3 id="bot-queue-heading" class="font-bold">صف ماندگار اعلان‌ها</h3>
        <p class="text-xs text-muted">اعلان‌های متنی همهٔ نقش‌ها پس از قطعی حفظ می‌شوند. «در صف» به معنی تحویل به پیام‌رسان نیست. اتصال حساب کاربر به ربات و دسترسی سرور به API لازم است.</p>
        <div class="flex gap-3 flex-wrap" role="status"><?php foreach($queueCounts as $q): ?><span><?php echo clean($stateNames[$q['state']]??$q['state']); ?>: <?php echo tr_num((int)$q['n'],'fa'); ?></span><?php endforeach; ?><?php if(!$queueCounts): ?>صف خالی است.<?php endif; ?></div>
        <?php $qShown=0; foreach($queueGroups as $g): if($qShown>=6)break; $qShown++; ?><div class="soft-panel text-xs" style="overflow-wrap:anywhere;word-break:break-word">
            <b><?php echo clean($stateNames[$g['state']]??$g['state']); ?></b> — <?php echo tr_num((int)$g['n'],'fa'); ?> پیام<?php if($g['owner']==='relay'): ?> · ارسال از سایت<?php endif; ?> · تلاش: <?php echo $g['minA']===$g['maxA']?tr_num((int)$g['minA'],'fa'):tr_num((int)$g['minA'],'fa').' تا '.tr_num((int)$g['maxA'],'fa'); ?>
            <?php if($g['err']!==''): ?><p class="text-muted"><?php echo clean($g['err']); ?></p><?php endif; ?>
        </div><?php endforeach; ?><?php if(count($queueGroups)>6): ?><p class="text-xs text-muted">… و <?php echo tr_num(count($queueGroups)-6,'fa'); ?> گروه دیگر</p><?php endif; ?>
        <?php
        /* v4.164.0: نام دانش‌آموزانِ ولی‌هایی که ربات را مسدود کرده‌اند — از
           chat_id پیام‌های ۴۰۳‌خورده و جدول اتصال همان پلتفرم. */
        $blockedChats = bot_outbox_blocked_chats($platform);
        if ($blockedChats): ?>
        <div class="soft-panel text-xs space-y-1" style="overflow-wrap:anywhere;word-break:break-word">
            <b>ولی‌هایی که ربات را مسدود کرده‌اند (خطای ۴۰۳):</b>
            <p class="text-muted">پیام‌های این چت‌ها تا رفع مسدودی تحویل نمی‌شود ولی سالم در صف می‌مانند و پس از سه تلاش ناموفق از چرخهٔ تلاش خارج می‌شوند (تلاش بی‌نتیجهٔ بیشتر انجام نمی‌شود). ولی باید ربات را از فهرست مسدودها خارج کند و دوباره /start بزند؛ همهٔ پیام‌های معوقهٔ او سپس خودکار ارسال می‌شوند.</p>
            <?php foreach ($blockedChats as $bc): ?><div>• <?php if ($bc['students']): ?><b><?php echo clean(implode('، ', $bc['students'])); ?></b><?php else: ?>چت ناشناس <code dir="ltr"><?php echo clean($bc['chat_id']); ?></code><?php endif; ?> — <?php echo tr_num((int)$bc['jobs'], 'fa'); ?> پیام معوق · <?php echo tr_num((int)$bc['attempts'], 'fa'); ?> تلاش</div><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if(bot_outbox_desktop() && get_setting('desk_bot_outbox_error','')!==''): ?><p class="text-xs"><?php echo clean(get_setting('desk_bot_outbox_error','')); ?></p><?php endif; ?>
        <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><button class="btn btn-secondary text-xs" name="retry_bot_outbox" value="1">آماده‌سازی صف برای تلاش مجدد</button></form>
        <p class="text-xs text-muted">برای ادامهٔ ارسال روی سایت حتی پس از بسته‌شدن نرم‌افزار، Cron هاست را هر دقیقه روی <code dir="ltr">php /absolute/path/reports/cron/bot-outbox-worker.php</code> تنظیم کنید. مسیر نمونه را با مسیر واقعی هاست عوض کنید. این worker جدا از دریافت پیام‌های ربات است.</p>
    <?php
    }
}
