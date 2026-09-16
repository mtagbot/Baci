<?php
function ceg_render_choice($teacher,$year,$grade,$subject,$id='',$modal=true) {
    $sources=ceg_sources($teacher,$year,$grade,$subject);
    foreach($sources as &$source){$source['has_design']=(bool)DB::fetch('SELECT exam_id FROM exam_designs WHERE exam_id=?',[$source['id']]) || !empty($source['question_file']);}unset($source);
    uasort($sources,function($a,$b){return ((int)$b['has_design']<=>(int)$a['has_design']) ?: ((int)$b['id']<=>(int)$a['id']);});
    $count=count(ceg_assignments($teacher,$year,$grade,$subject));
    ?>
    <div <?php if($modal): ?>id="<?php echo clean($id); ?>" class="ceg-modal" hidden<?php endif; ?> role="dialog" aria-modal="true" aria-label="روش طراحی آزمون پایه‌ای">
    <div class="card ceg-dialog">
        <h3 class="font-bold">طراحی مشترک <?php echo clean($subject.' — '.$grade); ?></h3>
        <p><?php echo tr_num($count,'fa'); ?> کلاس تخصیص‌یافتهٔ شما در این گروه قرار می‌گیرند.</p>
        <p><?php echo $sources ? 'آزمون کلاسی قبلی وجود دارد. همان آزمون را برای کلاس‌های دیگر کپی کنم یا برای همهٔ کلاس‌های این گروه از نو شروع کنیم؟' : 'برای این گروه آزمون کلاسی قبلی وجود ندارد؛ طراحی تازه شروع شود؟'; ?></p>
        <p class="text-xs text-muted">شروع تازه، طرح مشترک خالی می‌سازد؛ آزمون‌ها و فایل‌های قبلی حذف نمی‌شوند. تا قبل از تأیید هیچ تغییری ثبت نمی‌شود.</p>
        <form method="POST" action="class-exam-group.php" target="_blank" class="no-ajax" onsubmit="window.classExamPending=true;var m=this.closest('.ceg-modal');if(m)setTimeout(function(){m.hidden=true;},0);">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="year" value="<?php echo clean($year); ?>">
            <input type="hidden" name="grade" value="<?php echo clean($grade); ?>">
            <input type="hidden" name="subject" value="<?php echo clean($subject); ?>">
            <?php if($sources): ?>
            <label>آزمون منبع برای کپی
                <select name="source_exam_id" class="form-select" data-no-search="1">
                <?php foreach($sources as $source): ?>
                <option value="<?php echo (int)$source['id']; ?>"><?php echo clean($source['class_name'].' — '.$source['exam_month'].' — #'.$source['id'].($source['has_design']?' — دارای طراحی/فایل':' — بدون طراحی ذخیره‌شده')); ?></option>
                <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" name="mode" value="copy" class="btn btn-primary">کپی آزمون انتخابی برای گروه</button>
            <?php endif; ?>
            <button type="submit" name="mode" value="fresh" class="btn btn-warning">شروع از نو برای همهٔ کلاس‌های گروه</button>
            <?php if($modal): ?><button type="button" class="btn btn-outline" onclick="this.closest('.ceg-modal').hidden=true">انصراف</button><?php else: ?><a class="btn btn-outline" href="teacher-panel.php?tab=exams&amp;year=<?php echo urlencode($year); ?>">انصراف / بازگشت</a><?php endif; ?>
        </form>
    </div></div>
<?php }
