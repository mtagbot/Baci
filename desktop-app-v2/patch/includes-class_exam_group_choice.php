<?php
function ceg_render_choice($teacher,$year,$grade,$subject,$id='',$modal=true,$entryToken='') {
    /* v4.180.0 — فرم این پنجره در «پنجرهٔ تازه» باز می‌شود و نشست پنل را همراه
       ندارد؛ توکن امضاشده را با خودش می‌برد تا کاربر به صفحهٔ ورود نیفتد. */
    if((string)$entryToken==='')$entryToken=make_class_exam_entry_token($teacher,$year,'',$subject,$grade,0,86400,false);
    $sources=ceg_sources($teacher,$year,$grade,$subject);
    foreach($sources as &$source){$source['has_design']=(bool)DB::fetch('SELECT exam_id FROM exam_designs WHERE exam_id=?',[$source['id']]) || !empty($source['question_file']);}unset($source);
    uasort($sources,function($a,$b){return ((int)$b['has_design']<=>(int)$a['has_design']) ?: ((int)$b['id']<=>(int)$a['id']);});
    $count=count(ceg_assignments($teacher,$year,$grade,$subject));
    $classOptions=ceg_grade_class_options($year,$grade,$teacher,$subject);
    ?>
    <div <?php if($modal): ?>id="<?php echo clean($id); ?>" class="ceg-modal" hidden<?php endif; ?> role="dialog" aria-modal="true" aria-label="روش طراحی آزمون پایه‌ای">
    <div class="card ceg-dialog">
        <h3 class="font-bold">طراحی مشترک <?php echo clean($subject.' — '.$grade); ?></h3>
        <p>کلاس‌های پایه را برای این طراحی انتخاب کنید. کنار هر کلاس مشخص شده است که در این درس تدریس دارید یا ندارید.</p>
        <div class="ceg-class-list" style="max-height:240px;overflow:auto;border:1px solid #cbd5e1;border-radius:8px;padding:8px;margin:10px 0">
        <?php foreach($classOptions as $option): ?><label style="display:flex;justify-content:space-between;gap:12px;padding:6px;border-bottom:1px solid #e2e8f0"><span><input type="checkbox" name="selected_classes[]" value="<?php echo clean($option['class_name']); ?>" <?php echo $option['teaches']?'checked':''; ?>> <?php echo clean($option['class_name']); ?></span><small class="<?php echo $option['teaches']?'text-success':'text-muted'; ?>"><?php echo $option['teaches']?'تدریس دارید':'تدریس ندارید'; ?></small></label><?php endforeach; ?>
        <?php if(!$classOptions): ?><p class="text-muted">کلاسی در این پایه پیدا نشد.</p><?php endif; ?>
        </div>
        <p><?php echo $sources ? 'آزمون کلاسی قبلی وجود دارد. همان آزمون را برای کلاس‌های دیگر کپی کنم یا برای همهٔ کلاس‌های این گروه از نو شروع کنیم؟' : 'برای این گروه آزمون کلاسی قبلی وجود ندارد؛ طراحی تازه شروع شود؟'; ?></p>
        <p class="text-xs text-muted">شروع تازه، طرح مشترک خالی می‌سازد؛ آزمون‌ها و فایل‌های قبلی حذف نمی‌شوند. تا قبل از تأیید هیچ تغییری ثبت نمی‌شود.</p>
        <form method="POST" action="class-exam-group.php" target="_blank" class="no-ajax" onsubmit="if(!this.querySelector('input[name=\"selected_classes[]\"]:checked')){alert('حداقل یک کلاس را انتخاب کنید.');return false;}window.classExamPending=true;var m=this.closest('.ceg-modal');if(m)setTimeout(function(){m.hidden=true;},0);">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="ct" value="<?php echo clean($entryToken); ?>">
            <input type="hidden" name="year" value="<?php echo clean($year); ?>">
            <input type="hidden" name="grade" value="<?php echo clean($grade); ?>">
            <input type="hidden" name="subject" value="<?php echo clean($subject); ?>">
            <input type="hidden" name="class_selection" value="1">
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
