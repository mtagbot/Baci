<?php
// File: student-modal.php
/**
 * AJAX modal/tooltip content for admin student list.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/school_roles.php';
require_permission('manage_students');
ensure_school_roles_schema();
header('Content-Type: application/json; charset=utf-8');
$type = $_GET['type'] ?? $_POST['type'] ?? 'hover';
$studentId = (int)($_GET['student_id'] ?? $_POST['student_id'] ?? 0);
$student = DB::fetch("SELECT * FROM students WHERE id=?", [$studentId]);
if (!$student) { echo json_encode(['ok'=>false,'html'=>'دانش‌آموز یافت نشد.'], JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { echo json_encode(['ok'=>false,'html'=>'خطای امنیتی CSRF'], JSON_UNESCAPED_UNICODE); exit; }
    if ($type === 'discipline_save') {
        $titleId=(int)($_POST['title_id']??0); $tr=$titleId?DB::fetch('SELECT title FROM discipline_titles WHERE id=?',[$titleId]):null;
        $title = trim($tr['title'] ?? ($_POST['title_text'] ?? ''));
        $note = trim($_POST['internal_note'] ?? '');
        $occur = trim($_POST['occurred_at_jalali'] ?? jalali_now());
        $notify = isset($_POST['notify_parents']) ? 1 : 0; $just=isset($_POST['is_justified'])?1:0; $review=trim($_POST['review_status']??'pending'); $reviewNote=trim($_POST['review_note']??'');
        if ($title !== '') {
            DB::execute("INSERT INTO student_discipline_records (student_id,title_id,title_text,internal_note,occurred_at_jalali,notify_parents,is_justified,review_status,review_note,created_by_teacher_id,created_at_jalali) VALUES (?,?,?,?,?,?,?,?,?,?,?)", [$studentId,$titleId?:null,$title,$note,$occur,$notify,$just,$review,$reviewNote,null,jalali_now()]);
            $newRid = DB::lastInsertId();
            if ($notify) notify_student_discipline_bots($studentId,$title,$occur,$newRid);
        }
        $type = 'discipline';
    }
    if ($type === 'discipline_update') {
        DB::execute("UPDATE student_discipline_records SET title_text=?, occurred_at_jalali=?, internal_note=?, is_justified=?, review_status=?, updated_at_jalali=? WHERE id=? AND student_id=?", [trim($_POST['title_text']??''), trim($_POST['occurred_at_jalali']??''), trim($_POST['internal_note']??''), isset($_POST['is_justified'])?1:0, trim($_POST['review_status']??'pending'), jalali_now(), (int)$_POST['record_id'], $studentId]);
        $type = 'discipline';
    }
    if ($type === 'discipline_delete') {
        DB::execute("DELETE FROM student_discipline_records WHERE id=? AND student_id=?", [(int)$_POST['record_id'],$studentId]);
        $type = 'discipline';
    }
}

ob_start();
$profileCard = '<div class="soft-panel flex gap-3 items-center mb-3"><div class="avatar-lg">' . (!empty($student['photo_url']) ? '<img src="'.clean($student['photo_url']).'">' : '👤') . '</div><div><b>'.clean($student['first_name'].' '.$student['last_name']).'</b><br><span class="text-xs">'.clean($student['class_name'].' / '.$student['grade_level']).'</span><br><span class="text-xs">پدر: '.tr_num($student['father_phone'] ?: '---','fa').' | مادر: '.tr_num($student['mother_phone'] ?: '---','fa').'</span></div></div>';
if ($type === 'info'):
?>
<?php echo $profileCard; ?><h3 class="font-bold text-primary mb-3">اطلاعات پرونده دانش‌آموز</h3>
<div class="grid grid-cols-2 gap-3">
  <div class="soft-panel"><b>نام:</b> <?php echo clean($student['first_name'].' '.$student['last_name']); ?><br><b>کد ملی:</b> <?php echo tr_num($student['national_id'],'fa'); ?><br><b>نام پدر:</b> <?php echo clean($student['father_name'] ?: '---'); ?><br><b>تاریخ تولد:</b> <?php echo tr_num($student['birth_date'] ?: '---','fa'); ?></div>
  <div class="soft-panel"><b>پایه/کلاس:</b> <?php echo clean($student['grade_level'].' / '.$student['class_name']); ?><br><b>تلفن:</b> <?php echo tr_num($student['phone'] ?: '---','fa'); ?><br><b>پدر:</b> <?php echo tr_num($student['father_phone'] ?: '---','fa'); ?><br><b>مادر:</b> <?php echo tr_num($student['mother_phone'] ?: '---','fa'); ?></div>
</div>
<div class="mt-3"><a class="btn btn-secondary text-xs" target="_blank" href="students.php?action=edit&id=<?php echo $studentId; ?>">ویرایش پرونده</a></div>
<?php
elseif ($type === 'hover'):
?>
<div class="student-hover-card">
    <div class="flex gap-3 items-center">
        <div class="avatar-lg"><?php if(!empty($student['photo_url'])): ?><img src="<?php echo clean($student['photo_url']); ?>"><?php else: ?>👤<?php endif; ?></div>
        <div><b><?php echo clean($student['first_name'].' '.$student['last_name']); ?></b><br><span class="text-xs text-muted"><?php echo clean($student['class_name'].' / '.$student['grade_level']); ?></span></div>
    </div>
    <hr>
    <div class="text-xs space-y-1">
        <div>کد ملی: <?php echo tr_num($student['national_id'],'fa'); ?></div>
        <div>نام پدر: <?php echo clean($student['father_name'] ?: '---'); ?></div>
        <div>موبایل: <?php echo tr_num($student['phone'] ?: '---','fa'); ?></div>
        <div>پدر: <?php echo tr_num($student['father_phone'] ?: '---','fa'); ?></div>
        <div>مادر: <?php echo tr_num($student['mother_phone'] ?: '---','fa'); ?></div>
    </div>
</div>
<?php elseif ($type === 'reports'):
$reports = DB::fetchAll("SELECT r.*, (SELECT COUNT(*) FROM report_parent_reviews rv WHERE rv.report_id=r.id) AS review_count FROM reports r WHERE r.student_id=? ORDER BY r.id DESC", [$studentId]);
?>
<?php echo $profileCard; ?><h3 class="font-bold text-primary mb-3">کارنامه‌های <?php echo clean($student['first_name'].' '.$student['last_name']); ?></h3>
<div class="table-container modal-table"><table><thead><tr><th>سال</th><th>ماه/نوبت</th><th>معدل</th><th>بررسی والدین</th><th>وضعیت</th><th>مدیریت</th></tr></thead><tbody>
<?php foreach($reports as $r): ?><tr><td><?php echo clean($r['academic_year']); ?></td><td><?php echo clean($r['term'].' / '.$r['report_month']); ?></td><td><?php echo format_score($r['gpa']); ?></td><td><?php echo ((int)$r['review_count']>0)?'<span class="badge badge-success">بررسی شده</span>':'<span class="badge badge-danger">بررسی نشده</span>'; ?></td><td><?php echo $r['is_locked']?'قفل':'آزاد'; ?></td><td><a class="btn btn-primary text-xs" target="_blank" href="report-view.php?id=<?php echo $r['id']; ?>&staff=1">مشاهده</a> <a class="btn btn-secondary text-xs" target="_blank" href="reports.php?action=edit&id=<?php echo $r['id']; ?>">ویرایش</a> <a class="btn btn-outline text-xs" target="_blank" href="report-print.php?id=<?php echo $r['id']; ?>&staff=1">چاپ</a></td></tr><?php endforeach; if(!$reports): ?><tr><td colspan="5" class="text-center text-muted">کارنامه‌ای ثبت نشده است.</td></tr><?php endif; ?>
</tbody></table></div><div class="mt-3"><a class="btn btn-success text-xs" target="_blank" href="reports.php?action=new&student_id=<?php echo $studentId; ?>">+ ثبت کارنامه جدید</a></div>
<?php elseif ($type === 'discipline'):
$records=DB::fetchAll("SELECT * FROM student_discipline_records WHERE student_id=? ORDER BY id DESC",[$studentId]);
?>
<?php echo $profileCard; ?><h3 class="font-bold text-primary mb-3">مدیریت انضباط: <?php echo clean($student['first_name'].' '.$student['last_name']); ?></h3>
<form class="ajax-discipline-form grid grid-cols-2 gap-3 mb-4" onsubmit="return saveDisciplineAjax(this)">
<input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="type" value="discipline_save"><input type="hidden" name="student_id" value="<?php echo $studentId; ?>">
<?php $titlesForModal=DB::fetchAll('SELECT * FROM discipline_titles WHERE status=1 ORDER BY title'); ?><select name="title_id" class="form-select"><option value="">انتخاب عنوان ذخیره‌شده</option><?php foreach($titlesForModal as $tt): ?><option value="<?php echo $tt['id']; ?>"><?php echo clean($tt['title']); ?></option><?php endforeach; ?></select><input name="title_text" class="form-input" placeholder="یا عنوان جدید"><input name="occurred_at_jalali" class="form-input" value="<?php echo jalali_now(); ?>"><select name="review_status" class="form-select"><option value="pending">در انتظار بررسی</option><option value="reviewed">بررسی‌شده</option><option value="closed">مختومه</option></select><textarea name="internal_note" class="form-textarea" placeholder="یادداشت داخلی"></textarea><textarea name="review_note" class="form-textarea" placeholder="متن بررسی"></textarea><label class="text-xs"><input type="checkbox" name="is_justified"> موجه</label><label class="text-xs"><input type="checkbox" name="notify_parents"> ارسال اعلان</label><button class="btn btn-success">ثبت مورد</button>
</form>
<div class="table-container modal-table"><table><thead><tr><th>عنوان</th><th>تاریخ شمسی</th><th>موجه</th><th>بررسی</th><th>یادداشت داخلی</th><th>اعلان</th><th>حذف</th></tr></thead><tbody>
<?php foreach($records as $r): ?><tr><td colspan="7"><form onsubmit="return updateDisciplineAjax(this)" class="grid grid-cols-6 gap-2 items-center"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="type" value="discipline_update"><input type="hidden" name="student_id" value="<?php echo $studentId; ?>"><input type="hidden" name="record_id" value="<?php echo $r['id']; ?>"><input name="title_text" class="form-input text-xs" value="<?php echo clean($r['title_text']); ?>"><input name="occurred_at_jalali" class="form-input text-xs" value="<?php echo clean($r['occurred_at_jalali']); ?>"><label class="text-xs"><input type="checkbox" name="is_justified" <?php echo !empty($r['is_justified'])?'checked':''; ?>> موجه</label><select name="review_status" class="form-select text-xs"><option value="pending" <?php echo ($r['review_status']??'')==='pending'?'selected':''; ?>>در انتظار</option><option value="reviewed" <?php echo ($r['review_status']??'')==='reviewed'?'selected':''; ?>>بررسی‌شده</option><option value="closed" <?php echo ($r['review_status']??'')==='closed'?'selected':''; ?>>مختومه</option></select><input name="internal_note" class="form-input text-xs" value="<?php echo clean($r['internal_note']); ?>"><span><button class="btn btn-success text-xs">ذخیره</button> <button type="button" class="btn btn-danger text-xs" onclick="deleteDisciplineAjax(<?php echo $studentId; ?>,<?php echo $r['id']; ?>)">حذف</button></span></form></td></tr><?php endforeach; if(!$records): ?><tr><td colspan="5" class="text-center text-muted">موردی ثبت نشده است.</td></tr><?php endif; ?>
</tbody></table></div>
<?php endif;
$html = ob_get_clean();
echo json_encode(['ok'=>true,'html'=>$html], JSON_UNESCAPED_UNICODE);
