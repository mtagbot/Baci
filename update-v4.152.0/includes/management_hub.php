<?php
/** Shared layout only. Permissions and all actions remain in the original controllers. */
function school_management_hubs() {
    return [
        'courses'=>['title'=>'مدیریت دروس','tabs'=>[
            'classes'=>['کلاس‌ها و دروس','classes.php?embedded=1'],
            'teachers'=>['دبیران','import-teachers.php?tab=list&embedded=1'],
            'schedule'=>['برنامه هفتگی','import-schedule.php?embedded=1'],
            'years'=>['سال تحصیلی','academic-years.php?embedded=1']]],
        'reports'=>['title'=>'مدیریت کارنامه‌ها','tabs'=>[
            'reports'=>['کارنامه‌ها','reports.php?embedded=1'],
            'wizard'=>['ایمپورت Wizard','import.php?embedded=1'],
            'analytics'=>['تحلیل و مقایسه','analytics.php?embedded=1'],
            'grades'=>['مدیریت ثبت نمره','grade-entry-management.php?embedded=1'],
            'broadcast'=>['اعلام سراسری','report-broadcast.php?embedded=1']]],
        'recovery'=>['title'=>'بازیابی دانش‌آموز','tabs'=>[
            'students'=>['ایمپورت دانش‌آموزان','import-students.php?embedded=1'],
            'photos'=>['آپلود تصاویر ZIP','import-photos.php?embedded=1']]],
        'messages'=>['title'=>'پیامک و اعلان‌ها','tabs'=>[
            'notifications'=>['اعلان‌ها','notifications.php?embedded=1'],
            'sms'=>['سامانه پیامکی','sms-panel.php?embedded=1']]],
        'settings'=>['title'=>'تنظیمات دیگر','tabs'=>[
            'logs'=>['لاگ فعالیت','activity-logs.php?embedded=1'],
            'migration'=>['مایگریشن','migration-updater.php?embedded=1'],
            'backups'=>['پشتیبان‌گیری','backups.php?embedded=1'],
            'api'=>['مستندات API','api/docs.php?embedded=1']]]
    ];
}
function render_management_hub($key) {
    $hub=school_management_hubs()[$key];$tabs=$hub['tabs'];
    $requested=$_GET['hub_tab']??'';
    $active=is_string($requested)&&isset($tabs[$requested])?$requested:array_key_first($tabs);
    $current=$tabs[$active];
    ?>
    <section class="integrated-hub school-management-hub" data-school-hub="<?php echo clean($key); ?>" aria-labelledby="hubHeading">
        <header class="hub-heading"><h2 id="hubHeading"><?php echo clean($hub['title']); ?></h2><span><?php echo tr_num((string)count($tabs),'fa'); ?> بخش</span></header>
        <nav class="integrated-tabs hub-tabs" role="tablist" aria-label="<?php echo clean($hub['title']); ?>">
        <?php foreach($tabs as $id=>$tab): $on=$id===$active; ?>
            <a id="hubTab-<?php echo clean($id); ?>" class="hub-tab<?php echo $on?' is-active':''; ?>" role="tab" aria-selected="<?php echo $on?'true':'false'; ?>" aria-controls="hubPanel" href="?hub_tab=<?php echo rawurlencode($id); ?>#hubPanel" data-hub-key="<?php echo clean($id); ?>" data-hub-src="<?php echo clean($tab[1]); ?>"><?php echo clean($tab[0]); ?></a>
        <?php endforeach; ?>
        </nav>
        <div id="hubPanel" class="hub-panel" role="tabpanel" aria-labelledby="hubTab-<?php echo clean($active); ?>">
            <p id="hubLoadStatus" class="hub-load-status" role="status" aria-live="polite"></p>
            <iframe id="hubFrame" title="<?php echo clean($current[0]); ?>" src="<?php echo clean($current[1]); ?>"></iframe>
        </div>
        <noscript><p class="hub-fallback">اگر قاب داخلی در مرورگر شما باز نمی‌شود، <a href="<?php echo clean(str_replace(['&embedded=1','?embedded=1'],'',$current[1])); ?>">بخش انتخاب‌شده را مستقیم باز کنید</a>.</p></noscript>
    </section>
    <script defer src="assets/js/management-hub.js?v=20260917f"></script>
    <?php
}
