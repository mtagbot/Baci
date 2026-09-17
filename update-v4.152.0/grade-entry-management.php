<?php
// File: grade-entry-management.php
require_once __DIR__ . '/includes/auth.php';
require_permission('manage_reports');
require_once __DIR__ . '/includes/report_import_wizard.php';
require_once __DIR__ . '/includes/grade_permissions.php';
require_once __DIR__ . '/includes/school_sort.php';
ensure_grade_permissions_schema();
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!is_string($_POST['csrf_token']??null) || !verify_csrf($_POST['csrf_token'])) throw new InvalidArgumentException('اعتبار فرم منقضی شده است.');
        if (isset($_POST['save_rule'])) {
            $year=ri_year($_POST['academic_year']??'');
            $teacher=ri_text($_POST['teacher_id']??'0',20);
            if (!ctype_digit($teacher) || ((int)$teacher>0 && !DB::fetch('SELECT id FROM teachers WHERE id=?',[(int)$teacher]))) throw new InvalidArgumentException('دبیر انتخابی معتبر نیست.');
            DB::execute('INSERT INTO grade_entry_permissions (academic_year,report_month,grade_level,class_name,subject_name,teacher_id,can_enter,can_edit,note) VALUES (?,?,?,?,?,?,?,?,?)',[$year,ri_text($_POST['report_month']??'',50),ri_text($_POST['grade_level']??'',50),norm_class_str(ri_text($_POST['class_name']??'',100)),ri_text($_POST['subject_name']??'',150),(int)$teacher,isset($_POST['can_enter'])?1:0,isset($_POST['can_edit'])?1:0,ri_text($_POST['note']??'',255)]);
            set_flash_message('success','قانون ثبت نمره ذخیره شد.');
        } elseif (isset($_POST['delete_rule'])) {
            $id=ri_text($_POST['id']??'',20);
            if (!ctype_digit($id) || !DB::fetch('SELECT id FROM grade_entry_permissions WHERE id=?',[(int)$id])) throw new InvalidArgumentException('قانون انتخابی یافت نشد.');
            DB::execute('DELETE FROM grade_entry_permissions WHERE id=?',[(int)$id]); set_flash_message('success','قانون حذف شد.');
        } else throw new InvalidArgumentException('عملیات نامعتبر است.');
    } catch (InvalidArgumentException $e) { set_flash_message('error',$e->getMessage()); }
    catch (Throwable $e) { error_log('Grade rule failed: '.$e->getMessage()); set_flash_message('error','ذخیره انجام نشد؛ اتصال پایگاه‌داده را بررسی کنید.'); }
    redirect('grade-entry-management.php'.((($_GET['embedded']??'')==='1')?'?embedded=1':''));
}
require_once __DIR__ . '/includes/header.php';
$year=get_current_academic_year();
$years = get_academic_years_for_filter();
$grades=get_unified_grade_options($year); $classes=get_unified_class_options($year);
$subjects=DB::fetchAll("SELECT DISTINCT subject_name FROM class_schedules WHERE subject_name<>'' UNION SELECT DISTINCT name AS subject_name FROM subjects WHERE name<>'' ORDER BY subject_name");
$teachers=DB::fetchAll("SELECT id,full_name FROM teachers WHERE status=1 ORDER BY full_name");
$rules=DB::fetchAll("SELECT gp.*, t.full_name FROM grade_entry_permissions gp LEFT JOIN teachers t ON t.id=gp.teacher_id ORDER BY gp.id DESC LIMIT 300");
?>
<div class="space-y-4"><h2>مدیریت ثبت نمره</h2><p>دامنهٔ قانون را با سال، ماه، پایه، کلاس، درس و دبیر تعیین کنید. خالی یعنی همه. در منطق فعلی پنل دبیر، ممنوع بودن ثبت یا ویرایش در هر قانون منطبق، هر دو عملیات را مسدود می‌کند؛ قانون مجاز، ممنوعیت قبلی را خنثی نمی‌کند. برای بازکردن دسترسی، قانون مسدودکننده را حذف کنید.</p>
 <div class="card"><form method="POST" class="grid grid-cols-6 gap-2 items-end"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="save_rule" value="1"><div><label for="rule-academic_year">سال</label><select id="rule-academic_year" name="academic_year" class="form-select"><?php foreach($years as $y): ?><option value="<?php echo clean($y['academic_year']); ?>" <?php echo $y['academic_year']===$year?'selected':''; ?>><?php echo clean($y['academic_year']); ?></option><?php endforeach; ?></select></div><div><label for="rule-report_month">ماه</label><input id="rule-report_month" name="report_month" class="form-input" placeholder="خالی=همه"></div><div><label for="rule-grade_level">پایه</label><select id="rule-grade_level" name="grade_level" class="form-select"><option value="">همه</option><?php foreach($grades as $g): ?><option value="<?php echo clean($g['grade_level']); ?>"><?php echo clean($g['grade_level']); ?></option><?php endforeach; ?></select></div><div><label for="rule-class_name">کلاس</label><select id="rule-class_name" name="class_name" class="form-select"><option value="">همه</option><?php foreach($classes as $c): ?><option value="<?php echo clean($c['class_name']); ?>"><?php echo clean($c['class_name']); ?></option><?php endforeach; ?></select></div><div><label for="rule-subject_name">درس</label><select id="rule-subject_name" name="subject_name" class="form-select"><option value="">همه</option><?php foreach($subjects as $s): ?><option value="<?php echo clean($s['subject_name']); ?>"><?php echo clean($s['subject_name']); ?></option><?php endforeach; ?></select></div><div><label for="rule-teacher_id">دبیر</label><select id="rule-teacher_id" name="teacher_id" class="form-select"><option value="0">همه</option><?php foreach($teachers as $t): ?><option value="<?php echo $t['id']; ?>"><?php echo clean($t['full_name']); ?></option><?php endforeach; ?></select></div><label><input type="checkbox" name="can_enter" checked> اجازه ثبت</label><label><input type="checkbox" name="can_edit" checked> اجازه ویرایش</label><input aria-label="یادداشت قانون" name="note" class="form-input" placeholder="یادداشت"><button class="btn btn-success">ثبت قانون</button></form></div>
 <div class="card"><div class="table-container"><table><thead><tr><th>سال/ماه</th><th>پایه/کلاس</th><th>درس</th><th>دبیر</th><th>ثبت</th><th>ویرایش</th><th>حذف</th></tr></thead><tbody><?php foreach($rules as $r): ?><tr><td><?php echo clean($r['academic_year'].' / '.($r['report_month']?:'همه')); ?></td><td><?php echo clean(($r['grade_level']?:'همه').' / '.($r['class_name']?:'همه')); ?></td><td><?php echo clean($r['subject_name']?:'همه'); ?></td><td><?php echo clean($r['full_name']?:'همه'); ?></td><td><?php echo $r['can_enter']?'مجاز':'مسدود'; ?></td><td><?php echo $r['can_edit']?'مجاز':'مسدود'; ?></td><td><form method="POST" onsubmit="return confirm('این قانون حذف شود؟')"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="delete_rule" value="1"><input type="hidden" name="id" value="<?php echo $r['id']; ?>"><button class="btn btn-danger text-xs">حذف</button></form></td></tr><?php endforeach; ?></tbody></table></div></div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
