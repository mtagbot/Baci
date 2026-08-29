<?php
// File: exam-question-bank.php
/**
 * Admin-only question bank and saved exam designs manager.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/exams_helper.php';
require_once __DIR__ . '/includes/school_roles.php';
ensure_school_roles_schema();
$isExecutiveOnly = current_teacher_is_executive() && !is_admin_logged_in();
if (!$isExecutiveOnly) require_permission('manage_reports');
ensure_exams_schema();
if ($_SERVER['REQUEST_METHOD']==='POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    if (!$isExecutiveOnly && isset($_POST['delete_question'])) DB::execute("DELETE FROM exam_question_bank WHERE id=?", [(int)$_POST['question_id']]);
    if (!$isExecutiveOnly && isset($_POST['save_designer'])) DB::execute("UPDATE exam_question_bank SET designer_name=?, updated_at_jalali=? WHERE id=?", [trim($_POST['designer_name']??''), jalali_now(), (int)$_POST['question_id']]);
    if (!$isExecutiveOnly && isset($_POST['delete_design'])) DB::execute("DELETE FROM exam_designs WHERE exam_id=?", [(int)$_POST['exam_id']]);
    if (isset($_POST['copy_design'])) { $src=(int)$_POST['source_exam_id']; $dst=(int)$_POST['target_exam_id']; $d=DB::fetch('SELECT design_json,designer_teacher_id,designer_name FROM exam_designs WHERE exam_id=?',[$src]); $srcExam=DB::fetch('SELECT question_file, header_config FROM exam_schedules WHERE id=?',[$src]); if($d&&$dst){$ex=DB::fetch('SELECT id FROM exam_designs WHERE exam_id=?',[$dst]); if($ex) DB::execute('UPDATE exam_designs SET design_json=?,designer_teacher_id=?,designer_name=?,saved_by_admin_id=?,updated_at_jalali=? WHERE exam_id=?',[$d['design_json'],$d['designer_teacher_id'],$d['designer_name'],$_SESSION['admin_id']??null,jalali_now(),$dst]); else DB::execute('INSERT INTO exam_designs (exam_id,design_json,designer_teacher_id,designer_name,saved_by_admin_id,updated_at_jalali) VALUES (?,?,?,?,?,?)',[$dst,$d['design_json'],$d['designer_teacher_id'],$d['designer_name'],$_SESSION['admin_id']??null,jalali_now()]); if($srcExam){ DB::execute('UPDATE exam_schedules SET question_file=?, header_config=? WHERE id=?',[$srcExam['question_file'],$srcExam['header_config'],$dst]); $cacheDir=__DIR__.'/uploads/exams/pdf-pages/exam_'.$dst; foreach(glob($cacheDir.'/*')?:[] as $cf) if(is_file($cf)) @unlink($cf); } }}
    set_flash_message('success','عملیات بانک سوالات انجام شد.'); redirect('exam-question-bank.php');
}
require_once __DIR__ . '/includes/header.php';
$q=trim($_GET['q']??''); $year=trim($_GET['year']??''); $month=trim($_GET['month']??'');
$where=['1=1']; $params=[];
if($q){$where[]='(subject_name LIKE ? OR question_html LIKE ? OR designer_name LIKE ?)'; $params[]="%$q%"; $params[]="%$q%"; $params[]="%$q%";}
if($year){$where[]='academic_year=?';$params[]=$year;} if($month){$where[]='exam_month=?';$params[]=$month;}
$questions=DB::fetchAll('SELECT * FROM exam_question_bank WHERE '.implode(' AND ',$where).' ORDER BY id DESC LIMIT 500',$params);
$designs=DB::fetchAll('SELECT d.*, es.subject_name, es.academic_year, es.exam_month, es.class_name FROM exam_designs d JOIN exam_schedules es ON es.id=d.exam_id ORDER BY d.id DESC LIMIT 200');
$targetExams=DB::fetchAll('SELECT es.* FROM exam_schedules es LEFT JOIN exam_designs d ON d.exam_id=es.id WHERE d.id IS NULL ORDER BY es.academic_year DESC, es.exam_month DESC, es.class_name, es.subject_name LIMIT 500');
?>
<style>
.bank-question-card{position:relative;overflow:auto;max-height:120px;border:1px solid #e2e8f0;border-radius:8px;padding:6px;background:#fff}
.bank-question-card img{position:static!important;display:inline-block!important;max-width:100%!important;height:auto!important;left:auto!important;top:auto!important;transform:none!important}
.bank-question-card .q-actions{display:none!important}
</style>
<div class="space-y-4">
 <div class="card"><form method="GET" class="grid grid-cols-4 gap-3 items-end"><div><label class="text-xs">جستجو</label><input name="q" class="form-input" value="<?php echo clean($q); ?>"></div><div><label class="text-xs">سال</label><input name="year" class="form-input" value="<?php echo clean($year); ?>"></div><div><label class="text-xs">ماه</label><select name="month" class="form-select"><option value="">همه ماه‌ها</option><?php foreach (['مهر','آبان','آذر','دی','بهمن','اسفند','فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','نوبت اول','نوبت دوم'] as $qbM): ?><option value="<?php echo $qbM; ?>" <?php echo $month===$qbM?'selected':''; ?>><?php echo $qbM; ?></option><?php endforeach; ?></select></div><button class="btn btn-primary">فیلتر</button></form></div>
 <div class="grid <?php echo $isExecutiveOnly ? 'grid-cols-1' : 'grid-cols-2'; ?> gap-4">
  <?php if(!$isExecutiveOnly): ?><div class="card"><h3 class="font-bold mb-3">بانک سوالات طراحی‌شده</h3><div class="table-container"><table><thead><tr><th>درس/سال/ماه</th><th>طراح</th><th>سوال</th><th>عملیات</th></tr></thead><tbody><?php foreach($questions as $qq): ?><tr><td><b><?php echo clean($qq['subject_name']); ?></b><br><small><?php echo clean($qq['academic_year'].' / '.$qq['exam_month']); ?></small></td><td><form method="POST" class="flex gap-1"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="save_designer" value="1"><input type="hidden" name="question_id" value="<?php echo $qq['id']; ?>"><input name="designer_name" class="form-input text-xs" value="<?php echo clean($qq['designer_name']); ?>"><button class="btn btn-success text-xs">ذخیره</button></form></td><td><div class="bank-question-card"><?php echo $qq['question_html']; ?></div><small>بارم: <?php echo clean($qq['score']); ?></small></td><td><form method="POST" onsubmit="return confirm('حذف سوال از بانک؟')"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="delete_question" value="1"><input type="hidden" name="question_id" value="<?php echo $qq['id']; ?>"><button class="btn btn-danger text-xs">حذف</button></form></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
  <div class="card"><h3 class="font-bold mb-3">آزمون‌های دارای طراحی ذخیره‌شده</h3><div class="table-container"><table><thead><tr><th>آزمون</th><th>طراح</th><th>آخرین ذخیره</th><th>عملیات</th></tr></thead><tbody><?php foreach($designs as $d): ?><tr><td><b><?php echo clean($d['subject_name']); ?></b><br><small><?php echo clean($d['academic_year'].' / '.$d['exam_month'].' / '.$d['class_name']); ?></small></td><td><?php echo clean($d['designer_name']); ?></td><td><?php echo tr_num($d['updated_at_jalali'],'fa'); ?></td><td><a class="btn btn-primary text-xs" target="_blank" href="exam-print.php?type=questions&exam_id=<?php echo $d['exam_id']; ?>">مشاهده/ویرایش</a><form method="POST" class="inline-flex gap-1"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="copy_design" value="1"><input type="hidden" name="source_exam_id" value="<?php echo $d['exam_id']; ?>"><select name="target_exam_id" class="form-select text-xs"><?php foreach($targetExams as $te): ?><option value="<?php echo $te['id']; ?>"><?php echo clean($te['academic_year'].'/'.$te['exam_month'].' - '.$te['class_name'].' - '.$te['subject_name']); ?></option><?php endforeach; ?></select><button class="btn btn-success text-xs">استفاده در آزمون دیگر</button></form><form method="POST" class="inline" onsubmit="return confirm('طراحی ذخیره‌شده حذف شود؟')"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="delete_design" value="1"><input type="hidden" name="exam_id" value="<?php echo $d['exam_id']; ?>"><button class="btn btn-danger text-xs">حذف طراحی</button></form></td></tr><?php endforeach; ?></tbody></table></div></div>
 </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
