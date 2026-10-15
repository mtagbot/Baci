<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/class_exam_groups.php';
require_once __DIR__.'/includes/class_exam_group_choice.php';
$input=$_SERVER['REQUEST_METHOD']==='POST'?$_POST:$_GET;
/* v4.180.0 — طراحی/ویرایش پایه‌ای هم با توکن امضاشده کار می‌کند (پنجرهٔ تازه
   نشست ندارد و پیش‌تر به صفحهٔ ورود می‌افتاد). بدون توکن، مسیر نشست دست‌نخورده است. */
$entryToken=verify_class_exam_entry_token($input['ct']??'');
if($entryToken&&($input['grade']??'')!==''&&trim($entryToken['g'])!==trim($input['grade']))$entryToken=false;
if($entryToken&&($input['subject']??'')!==''&&trim($entryToken['s'])!==trim($input['subject']))$entryToken=false;
if(empty($_SESSION['teacher_id'])&&!$entryToken)redirect('admin-login.php?tab=teacher');
$teacher=(int)($_SESSION['teacher_id']??($entryToken['t']??0));
ensure_exams_schema();ceg_schema();
foreach(['year','grade','subject','mode','group_id','member_id','source_exam_id','csrf_token','ct'] as $key)if(isset($input[$key])&&!is_scalar($input[$key]))die('درخواست نامعتبر');
$year=resolve_academic_year_request($input['year']??($entryToken['y']??get_current_academic_year()));
$grade=trim($input['grade']??($entryToken['g']??''));$subject=trim($input['subject']??($entryToken['s']??''));
$back='teacher-panel.php?tab=exams&year='.urlencode($year);
if(isset($input['year'])&&unify_academic_year($input['year'])!==$year){set_flash_message('error','سال تحصیلی نامعتبر است.');redirect($back);}
if($_SERVER['REQUEST_METHOD']==='POST'){
    /* با توکن امضاشده نیازی به CSRF نیست: خودِ توکن اجازهٔ صریح و زمان‌دار است. */
    if(!$entryToken&&!verify_csrf($input['csrf_token']??'')){set_flash_message('error','خطای امنیتی؛ صفحه را تازه‌سازی کنید.');redirect($back);}
    try{
        if(($input['mode']??'')==='detach'){
            $id=ceg_detach($teacher,(int)($input['group_id']??0),(int)($input['member_id']??0));
            set_flash_message('success','کلاس با موفقیت مستثنی شد. از دکمهٔ طراحی مستقل همان ردیف استفاده کنید؛ تغییرات آن روی گروه اثر ندارد.');
            redirect($back);
        }
        $group=ceg_start($teacher,$year,$grade,$subject,$input['mode']??'',(int)($input['source_exam_id']??0));
    }catch(Throwable $e){set_flash_message('error',$e instanceof PDOException?'ثبت گروه انجام نشد؛ اطلاعات قبلی حفظ شده است.':$e->getMessage());redirect($back);}
}else{
    $group=ceg_find($teacher,$year,$grade,$subject);
    if(!$group){
        if(!ceg_assignments($teacher,$year,$grade,$subject)){set_flash_message('error','کلاسی برای این درس و پایه تخصیص ندارید.');redirect($back);}
        require __DIR__.'/includes/header.php';
        ceg_render_choice($teacher,$year,$grade,$subject,'',false,$entryToken?(string)$input['ct']:'');
        require __DIR__.'/includes/footer.php';exit;
    }
}
$id=(int)$group['design_exam_id'];
redirect('exam-print.php?type=questions&exam_id='.$id.'&grade_all=1&dt='.urlencode(make_exam_design_token($id,'teacher',$teacher)));
