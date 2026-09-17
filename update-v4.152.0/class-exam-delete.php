<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/school_roles.php';
require_once __DIR__.'/includes/class_exam_groups.php';
ensure_school_roles_schema();ensure_exams_schema();ceg_schema();
$teacher=(int)($_SESSION['teacher_id']??0);
$role=$teacher?DB::fetch('SELECT is_executive,is_deputy FROM teachers WHERE id=?',[$teacher]):null;
$manager=(is_admin_logged_in() && has_permission('manage_reports')) || ($role && (!empty($role['is_executive']) || !empty($role['is_deputy'])));
$input=$_SERVER['REQUEST_METHOD']==='POST'?$_POST:[];
foreach(['exam_id','year','return_to','csrf_token'] as $key)if(isset($input[$key])&&!is_scalar($input[$key]))die('درخواست نامعتبر');
$year=resolve_academic_year_request($input['year']??get_current_academic_year());
$back=($manager && ($input['return_to']??'')==='management'?'exams.php?tab=class':'teacher-panel.php?tab=exams').'&year='.urlencode($year);
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);die('حذف فقط با دکمهٔ تأیید و درخواست POST مجاز است.');}
if(!verify_csrf($input['csrf_token']??'')){set_flash_message('error','خطای امنیتی؛ صفحه را تازه‌سازی کنید.');redirect($back);}
$exam=DB::fetch('SELECT * FROM exam_schedules WHERE id=?',[(int)($input['exam_id']??0)]);
if(!$exam || (!$manager && (!$teacher || (int)$exam['teacher_id']!==$teacher))){set_flash_message('error','اجازهٔ حذف این آزمون را ندارید.');redirect($back);}
try {
    ceg_delete_class_exam((int)$exam['id']);
    set_flash_message('success','آزمون کلاسی از فهرست حذف شد؛ طرح و فایل‌های بایگانی و آزمون سایر کلاس‌ها حفظ شدند.');
}catch(Throwable $e){set_flash_message('error',$e instanceof PDOException?'حذف انجام نشد؛ اطلاعات قبلی حفظ شده است.':$e->getMessage());}
redirect($back);
