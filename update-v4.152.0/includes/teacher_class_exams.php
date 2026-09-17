<?php
require_once __DIR__.'/class_exam_group_choice.php';
$buckets=[];
foreach($classRows as $as){$key=ceg_key($teacherId,$as['academic_year'],$as['grade_level'],$as['subject_name']);$buckets[$key][]=$as;}
?>
<h3 class="font-bold mb-3">آزمون‌های کلاسی دبیر</h3>
<div class="table-container"><table id="teacherClassExams"><thead><tr><th>سال / پایه</th><th>کلاس</th><th>درس</th><th>آزمون کلاسی</th><th>طراحی پایه‌ای (مشترک کلاس‌های پایه)</th></tr></thead><tbody>
<?php foreach($buckets as $key=>$bucket):
    $first=$bucket[0];$group=ceg_find($teacherId,$first['academic_year'],$first['grade_level'],$first['subject_name']);
    $eligible=ceg_assignments($teacherId,$first['academic_year'],$first['grade_level'],$first['subject_name']);
    $members=$group?DB::fetchAll('SELECT * FROM class_exam_group_members WHERE group_id=?',[$group['id']]):[];
    foreach($bucket as $index=>$as):
        $member=null;foreach($members as $m)if(norm_class_str($m['class_name'])===norm_class_str($as['class_name']))$member=$m;
        $shared=$member && !$member['excluded'];
?>
<tr class="class-exam-row" data-class="<?php echo clean($as['class_name']); ?>" data-subject="<?php echo clean($as['subject_name']); ?>">
<td><?php echo clean($as['academic_year'].' / '.$as['grade_level']); ?></td><td><?php echo clean($as['class_name']); ?></td><td><?php echo clean($as['subject_name']); ?></td>
<td>
<?php if($shared): ?>
    <span class="badge badge-info">عضو آزمون پایه</span>
    <a class="btn btn-primary text-xs" target="_blank" href="class-exam-create.php?<?php echo clean(http_build_query(['class'=>$as['class_name'],'subject'=>$as['subject_name'],'year'=>$as['academic_year'],'exam_id'=>$member['exam_id']])); ?>">ویرایش / چاپ این کلاس</a>
    <form method="POST" action="class-exam-group.php" class="no-ajax" onsubmit="if(!confirm('این کلاس از گروه جدا شود؟ نسخه‌ای مستقل از آخرین طرح ذخیره‌شده برای آن نگه داشته می‌شود.'))return false;">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="mode" value="detach">
        <input type="hidden" name="year" value="<?php echo clean($as['academic_year']); ?>"><input type="hidden" name="group_id" value="<?php echo (int)$group['id']; ?>"><input type="hidden" name="member_id" value="<?php echo (int)$member['exam_id']; ?>">
        <button class="btn btn-outline text-xs">مستثنی کردن از آزمون پایه</button>
    </form>
<?php else: ?>
    <?php if($member && !empty($member['detached_exam_id'])): ?><span class="badge badge-warning">مستثنی از آزمون پایه — طراحی مستقل</span><a class="btn btn-primary text-xs" target="_blank" href="exam-print.php?type=questions&amp;exam_id=<?php echo (int)$member['detached_exam_id']; ?>&amp;dt=<?php echo urlencode(make_exam_design_token((int)$member['detached_exam_id'],'teacher',$teacherId)); ?>">طراحی / چاپ مستقل این کلاس</a><?php elseif($member): ?><span class="badge badge-warning">خارج از آزمون پایه</span><?php endif; ?>
    <form method="<?php echo $as['exams']?'GET':'POST'; ?>" action="class-exam-create.php" target="_blank" class="no-ajax flex flex-wrap gap-2" onsubmit="if(this.method.toLowerCase()==='post')window.classExamPending=true;">
        <input type="hidden" name="class" value="<?php echo clean($as['class_name']); ?>"><input type="hidden" name="subject" value="<?php echo clean($as['subject_name']); ?>"><input type="hidden" name="year" value="<?php echo clean($as['academic_year']); ?>">
        <?php if($as['exams']): ?>
        <select name="exam_id" class="form-select text-xs" aria-label="انتخاب آزمون کلاسی">
            <?php foreach($as['exams'] as $ce): ?><option value="<?php echo (int)$ce['id']; ?>"><?php echo clean(($ce['exam_month']?:'آزمون کلاسی').' — #'.$ce['id'].(empty($ce['is_active'])?' — غیرفعال':'')); ?></option><?php endforeach; ?>
        </select><button class="btn btn-warning text-xs"><svg data-ui-icon="science" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M8 2h8m-6 0v7L3 21h18L14 9V2M7 15h10"/></svg> ویرایش آزمون کلاسی</button>
        <?php elseif($as['assigned']): ?><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><button class="btn btn-success text-xs"><svg data-ui-icon="add" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3v18M3 12h18"/></svg> طراحی آزمون جدید</button><?php endif; ?>
    </form>
<?php endif; ?>
<?php if($as['exams'])ceg_render_delete_form($shared?$member['exam_id']:$as['exams'][0]['id'],$as['academic_year'],false,!$shared); ?>
</td>
<?php if($index===0): ?>
<td rowspan="<?php echo count($bucket); ?>" style="vertical-align:middle;text-align:center;background:rgba(16,185,129,.06)">
    <?php if($eligible && $first['grade_level']!==''): ?>
    <a class="btn btn-success text-xs ceg-start" <?php if(!$group): ?>data-dialog="ceg-<?php echo $key; ?>"<?php endif; ?> target="_blank" href="class-exam-group.php?<?php echo clean(http_build_query(['year'=>$first['academic_year'],'grade'=>$first['grade_level'],'subject'=>$first['subject_name']])); ?>">طراحی پایه‌ای کلاسی — <?php echo clean($first['grade_level']); ?></a>
    <?php if($group): ?><div class="text-xs">کلاس مستثنی‌شده در چاپ مشترک نمی‌آید.</div><?php endif; ?>
    <?php else: ?>—<?php endif; ?>
</td>
<?php endif; ?>
</tr><?php endforeach; endforeach; ?>
<?php if(!$buckets): ?><tr><td colspan="5">آزمون کلاسی یا کلاس تخصیص‌یافته‌ای وجود ندارد.</td></tr><?php endif; ?>
</tbody></table></div>
<?php foreach($buckets as $key=>$bucket){$as=$bucket[0];if(!ceg_find($teacherId,$as['academic_year'],$as['grade_level'],$as['subject_name']) && ceg_assignments($teacherId,$as['academic_year'],$as['grade_level'],$as['subject_name']))ceg_render_choice($teacherId,$as['academic_year'],$as['grade_level'],$as['subject_name'],'ceg-'.$key);} ?>
<style>.ceg-modal[hidden]{display:none!important}.ceg-modal{position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px}.ceg-dialog{max-width:620px;width:100%;max-height:90vh;overflow:auto}.ceg-dialog p,.ceg-dialog form{margin-top:12px}.ceg-dialog button{margin:8px 0 0 8px}</style>
<script>
document.addEventListener('click',function(e){var a=e.target.closest('.ceg-start[data-dialog]');if(!a)return;var modal=document.getElementById(a.getAttribute('data-dialog'));if(modal){e.preventDefault();modal.hidden=false;modal.querySelector('select,button').focus();}});
document.addEventListener('keydown',function(e){if(e.key==='Escape')document.querySelectorAll('.ceg-modal').forEach(function(m){m.hidden=true;});});
</script>
<p class="text-xs text-muted">یک طرح مشترک برای اعضای گروه ذخیره می‌شود. مستثنی کردن، نسخه‌ای مستقل از آخرین طرح ذخیره‌شده می‌سازد؛ طرح‌ها و فایل‌های قبل از گروه‌بندی حذف نمی‌شوند.</p>
