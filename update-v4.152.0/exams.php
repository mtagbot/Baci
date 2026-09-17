<?php
// File: exams.php
/**
 * Exams management: schedule, seat/room assignment, question headers and printing.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/academic_year_helpers.php';
require_once __DIR__ . '/includes/class_exam_groups.php';
require_once __DIR__ . '/includes/school_roles.php';
ensure_school_roles_schema();
$classDeputyOnly=!is_admin_logged_in() && !current_teacher_is_executive() && !empty($_SESSION['teacher_id']) && teacher_has_deputy((int)$_SESSION['teacher_id']);
if (!current_teacher_is_executive() && !$classDeputyOnly) require_permission('manage_reports');
if($classDeputyOnly && ($_SERVER['REQUEST_METHOD']==='POST' || isset($_GET['delete']) || isset($_GET['edit'])))die('دسترسی معاون در این بخش فقط مشاهده، چاپ و حذف آزمون کلاسی از دکمهٔ اختصاصی است.');
ensure_exams_schema(); ceg_schema();
$tab=$classDeputyOnly?'class':($_GET['tab']??'schedule');
/* v4.71.0: خارج از پنل مدیریت فقط نام خانوادگی دبیر نمایش داده شود */
if (!function_exists('exams_teacher_label')) { function exams_teacher_label($n){ $n=trim((string)$n); if($n==='') return $n; return is_admin_logged_in() ? $n : teacher_family_name($n); } } $year=resolve_academic_year_request($_GET['year']??get_current_academic_year()); // v4.38.0 unified
if ($_SERVER['REQUEST_METHOD']==='POST') {
 if(!verify_csrf($_POST['csrf_token']??'')){set_flash_message('error','خطای CSRF');redirect('exams.php');}
 if(isset($_POST['mark_printed'])){ DB::execute('UPDATE exam_schedules SET is_printed=? WHERE id=?', [isset($_POST['is_printed'])?1:0, (int)$_POST['exam_id']]); set_flash_message('success','وضعیت چاپ آزمون ذخیره شد.'); $pfQS=''; if(($_POST['pf_month']??'')!=='') $pfQS.='&pf_month='.urlencode($_POST['pf_month']); if(($_POST['pf_printed']??'')!=='') $pfQS.='&pf_printed='.urlencode($_POST['pf_printed']); redirect('exams.php?tab=print&year='.urlencode($year).$pfQS); }
 if(isset($_POST['copy_class_design'])){
  $src=(int)($_POST['source_exam_id']??0); $target=explode('|', $_POST['target_assignment']??'');
  if(count($target)>=3){
    [$tyear,$tclass,$tsubject]=$target;
    $sourceRow=DB::fetch('SELECT * FROM exam_schedules WHERE id=?',[$src]);
    if($sourceRow && ($sourceRow['exam_kind']??'')==='class_deleted'){set_flash_message('error','آزمون منبع حذف شده است.');redirect('exams.php?tab=class&year='.urlencode($year));}
    ceg_write_begin($sourceRow);
    $sourceGroup=$sourceRow?ceg_for_exam($sourceRow):null;
    if($sourceGroup){ceg_write_end(false);set_flash_message('error','برای آزمون گروهی یا مستثنی‌شده از صفحهٔ طراحی استفاده کنید؛ کپی مستقیم این بخش مجاز نیست.');redirect('exams.php?tab=class&year='.urlencode($year));}
    $srcExam=DB::fetch('SELECT * FROM exam_schedules WHERE id=?',[$src]); $design=DB::fetch('SELECT * FROM exam_designs WHERE exam_id=?',[$src]);
    if($srcExam && $design){
      $teacherId=(int)$srcExam['teacher_id']; $teacherName=$srcExam['teacher_name']; $grade=infer_grade_from_class_name($tclass);
      $dst=DB::fetch("SELECT id FROM exam_schedules WHERE exam_kind='class' AND academic_year=? AND class_name=? AND subject_name=? AND teacher_id=? ORDER BY id DESC LIMIT 1",[$tyear,$tclass,$tsubject,$teacherId]);
      if($dst){$destRow=DB::fetch('SELECT * FROM exam_schedules WHERE id=?',[$dst['id']]);$destGroup=ceg_for_exam($destRow);if($destGroup){ceg_write_end(false);set_flash_message('error','کلاس مقصد عضو آزمون پایه است؛ ابتدا آن را مستثنی کنید.');redirect('exams.php?tab=class&year='.urlencode($year));}}
      if($dst && (int)$dst['id']===$src){ceg_write_end(false);set_flash_message('error','منبع و مقصد یک آزمون هستند؛ تغییری انجام نشد.');redirect('exams.php?tab=class&year='.urlencode($year));}
      if(!$dst){ DB::execute("INSERT INTO exam_schedules (academic_year,exam_month,grade_level,class_name,subject_name,teacher_id,teacher_name,exam_date_jalali,exam_day_name,start_time,duration_minutes,exam_room_default,question_file,header_config,is_active,exam_kind,created_at_jalali) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",[$tyear,'آزمون کلاسی',$grade,$tclass,$tsubject,$teacherId,$teacherName,'','','',45,'',$srcExam['question_file'],$srcExam['header_config'] ?: '{}',1,'class',jdate('Y/m/d H:i')]); $dstId=DB::lastInsertId(); }
      else { $dstId=(int)$dst['id']; DB::execute('UPDATE exam_schedules SET question_file=?, header_config=? WHERE id=?',[$srcExam['question_file'],$srcExam['header_config'],$dstId]); }
      $cacheDir=__DIR__.'/uploads/exams/pdf-pages/exam_'.$dstId; foreach(glob($cacheDir.'/*')?:[] as $cf) if(is_file($cf)) @unlink($cf);
      /* v4.96.0: کش تصاویر منبع هم کپی + نشانه استفاده مجدد تا در بانک دوباره ثبت نشود */
      $srcCache=__DIR__.'/uploads/exams/pdf-pages/exam_'.$src;
      if(is_dir($srcCache)){ @mkdir($cacheDir,0775,true); foreach(glob($srcCache.'/page_*.jpg')?:[] as $pf) @copy($pf,$cacheDir.'/'.basename($pf)); if(is_file($srcCache.'/pages.txt')) @copy($srcCache.'/pages.txt',$cacheDir.'/pages.txt');
        $originSrc=is_file($srcCache.'/origin.txt')?trim((string)@file_get_contents($srcCache.'/origin.txt')):(string)$src;
        @file_put_contents($cacheDir.'/origin.txt',$originSrc!==''?$originSrc:(string)$src); }
      $ex=DB::fetch('SELECT id FROM exam_designs WHERE exam_id=?',[$dstId]);
      if($ex) DB::execute('UPDATE exam_designs SET design_json=?,designer_teacher_id=?,designer_name=?,saved_by_admin_id=?,updated_at_jalali=? WHERE exam_id=?',[$design['design_json'],$design['designer_teacher_id'],$design['designer_name'],$_SESSION['admin_id']??null,jalali_now(),$dstId]);
      else DB::execute('INSERT INTO exam_designs (exam_id,design_json,designer_teacher_id,designer_name,saved_by_admin_id,updated_at_jalali) VALUES (?,?,?,?,?,?)',[$dstId,$design['design_json'],$design['designer_teacher_id'],$design['designer_name'],$_SESSION['admin_id']??null,jalali_now()]);
      ceg_write_end(true);
      set_flash_message('success','طراحی آزمون کلاسی برای کلاس/درس مقصد اعمال شد.');
    }
  }
  ceg_write_end(false);
  redirect('exams.php?tab=class&year='.urlencode($year));
 }
 if(isset($_POST['save_exam'])){
  $changing=DB::fetch('SELECT * FROM exam_schedules WHERE id=?',[(int)($_POST['exam_id']??0)]);
  if($changing && in_array($changing['exam_kind']??'',['class','class_deleted'],true)){set_flash_message('error','آزمون کلاسی را از بخش آزمون‌های کلاسی ویرایش کنید.');redirect('exams.php?tab=class&year='.urlencode($year));}

  $id=(int)($_POST['exam_id']??0); $tid=(int)($_POST['teacher_id']??0); $tname=''; if($tid){$t=DB::fetch('SELECT full_name FROM teachers WHERE id=?',[$tid]);$tname=$t['full_name']??'';}
  $file=$_POST['existing_question_file']??'';
  if(isset($_FILES['question_file']) && $_FILES['question_file']['error']===UPLOAD_ERR_OK){$ext=strtolower(pathinfo($_FILES['question_file']['name'],PATHINFO_EXTENSION)); if(in_array($ext,['pdf','png','jpg','jpeg','webp'])){$dir=__DIR__.'/uploads/exams'; if(!is_dir($dir))mkdir($dir,0755,true); $fn='question_'.time().'_'.bin2hex(random_bytes(3)).'.'.$ext; move_uploaded_file($_FILES['question_file']['tmp_name'],$dir.'/'.$fn); $file='uploads/exams/'.$fn;}}
  $p=[unify_academic_year(trim($_POST['academic_year']??$year)),trim($_POST['grade_level']??''),trim($_POST['class_name']??''),trim($_POST['subject_name']??''),$tid?:null,$tname,trim($_POST['exam_date_jalali']??''),trim($_POST['start_time']??''),(int)($_POST['duration_minutes']??90),trim($_POST['exam_room_default']??''),$file,json_encode($_POST['header']??[],JSON_UNESCAPED_UNICODE)];
  if($id) DB::execute('UPDATE exam_schedules SET academic_year=?,grade_level=?,class_name=?,subject_name=?,teacher_id=?,teacher_name=?,exam_date_jalali=?,start_time=?,duration_minutes=?,exam_room_default=?,question_file=?,header_config=? WHERE id=?',array_merge($p,[$id]));
  else DB::execute('INSERT INTO exam_schedules (academic_year,grade_level,class_name,subject_name,teacher_id,teacher_name,exam_date_jalali,start_time,duration_minutes,exam_room_default,question_file,header_config,created_at_jalali) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',array_merge($p,[jdate('Y/m/d H:i')]));
  set_flash_message('success','برنامه امتحانی ذخیره شد.'); redirect('exams.php?tab=schedule&year='.urlencode($p[0]));
 }
 if(isset($_POST['create_grade_exams'])){
  $y=unify_academic_year(trim($_POST['academic_year']??$year)); $month=trim($_POST['exam_month']??'خرداد'); $grade=trim($_POST['grade_level']??'');
  $enabled=$_POST['enabled_subjects']??[]; $globalClasses=$_POST['enabled_classes']??[]; $created=0;
  foreach($enabled as $key){
    [$subject,$teacherId]=array_pad(explode('|',$key,2),2,'0');
    /* v4.95.0: تاریخ از سه سلکت سال/ماه/روز ساخته می‌شود (فرمت 1404/07/15) */
    $dy=(int)($_POST['date_y'][$key]??0); $dm=(int)($_POST['date_m'][$key]??0); $dd=(int)($_POST['date_d'][$key]??0);
    $date = ($dy>=1300 && $dm>=1 && $dm<=12 && $dd>=1 && $dd<=31) ? sprintf('%04d/%02d/%02d',$dy,$dm,$dd) : trim($_POST['date'][$key]??'');
    $day=trim($_POST['day'][$key]??''); $time=trim($_POST['time'][$key]??''); $duration=(int)($_POST['duration'][$key]??90);
    if($subject===''||$date===''||$time==='') continue;
    $enabledClasses = $_POST['classes'][$key] ?? $globalClasses;
    foreach($enabledClasses as $className){
      $className=norm_class_str($className); if($className==='') continue;
      $teacherName=''; $tid=(int)$teacherId;
      if($tid){$t=DB::fetch('SELECT full_name FROM teachers WHERE id=?',[$tid]);$teacherName=$t['full_name']??'';}
      if(!$tid){$row=DB::fetch('SELECT teacher_id, teacher_name FROM class_schedules WHERE academic_year=? AND class_name=? AND subject_name=? AND teacher_id IS NOT NULL LIMIT 1',[$y,$className,$subject]); $tid=(int)($row['teacher_id']??0); $teacherName=$row['teacher_name']??'';}
      DB::execute('INSERT INTO exam_schedules (academic_year,exam_month,grade_level,class_name,subject_name,teacher_id,teacher_name,exam_date_jalali,exam_day_name,start_time,duration_minutes,is_active,created_at_jalali) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',[$y,$month,$grade,$className,$subject,$tid?:null,$teacherName,$date,$day,$time,$duration,1,jdate('Y/m/d H:i')]);
      $created++;
    }
  }
  /* v4.95.0: حافظه ۱ روزه ماه و تاریخ آخرین فعال‌سازی برای فعال‌سازی بعدی */
  if (isset($date) && $date !== '') @setcookie('exam_last_date', $date, time()+86400, '/');
  @setcookie('exam_last_month', rawurlencode($month), time()+86400, '/');
  set_flash_message('success',tr_num($created,'fa').' آزمون برای پایه/کلاس‌های انتخابی فعال شد.'); redirect('exams.php?tab=schedule&year='.urlencode($y));
 }
 if(isset($_POST['assign_bulk'])){
  /* v4.72.0: شماره صندلی «سراسری» است — ماه امتحانی دخیل نیست. برای هر
     دانش‌آموز فقط یک رکورد در سال نگه داشته می‌شود (exam_month=''). */
  $y=unify_academic_year(trim($_POST['academic_year']??$year)); $room=trim($_POST['exam_room']??''); $start=(int)($_POST['seat_start']??1); $ids=array_map('intval',$_POST['student_ids']??[]); $i=0;
  foreach($ids as $sid){
    $seat=tr_num((string)($start+$i),'en');
    DB::execute('DELETE FROM exam_student_seating WHERE academic_year=? AND student_id=?',[$y,$sid]);
    DB::execute('INSERT INTO exam_student_seating (academic_year,exam_month,student_id,seat_number,exam_room,created_at_jalali) VALUES (?,?,?,?,?,?)',[$y,'',$sid,$seat,$room,jdate('Y/m/d H:i')]);
    $i++;
  }
  set_flash_message('success',tr_num($i,'fa').' شماره صندلی سراسری (مستقل از ماه و آزمون) اختصاص یافت.'); redirect('exams.php?tab=assign&year='.urlencode($y));
 }
 if(isset($_POST['auto_assign_seats'])){
  /* v4.72.0: شماره‌گذاری خودکار پیش‌فرض:
     - هر پایه از شماره ۱ شروع می‌شود.
     - کلاس‌های پایه به ترتیب (کلاس ۱، ۲، ...)؛ دانش‌آموزان هر کلاس به ترتیب
       نام خانوادگی؛ کلاس بعدی از ادامه آخرین شماره کلاس قبلی. */
  $y=unify_academic_year(trim($_POST['academic_year']??$year));
  // v4.78.0: seat numbering for year $y covers ONLY that year's students
  list($exySql,$exyParams)=exam_year_students_sql($y);
  $all=DB::fetchAll("SELECT s.id,s.first_name,s.last_name,s.class_name,s.grade_level FROM students s WHERE s.status='active' AND $exySql",$exyParams);
  $byGrade=[];
  foreach($all as $st){
    $g=trim($st['grade_level']??'')?:infer_grade_from_class_name($st['class_name']??'');
    if($g==='')$g='نامشخص';
    $byGrade[$g][]=$st;
  }
  DB::execute('DELETE FROM exam_student_seating WHERE academic_year=?',[$y]);
  $total=0;
  foreach($byGrade as $g=>$rows){
    // گروه‌بندی بر اساس کلاس و مرتب‌سازی کلاس‌ها
    $byClass=[];
    foreach($rows as $st){ $byClass[norm_class_str($st['class_name']??'')][] = $st; }
    $classKeys=array_keys($byClass);
    usort($classKeys, fn($a,$b)=> class_number_weight($a) <=> class_number_weight($b) ?: persian_compare($a, $b));
    $num=1;
    foreach($classKeys as $ck){
      $clsStudents=$byClass[$ck];
      persian_usort_students($clsStudents);
      foreach($clsStudents as $st){
        DB::execute('INSERT INTO exam_student_seating (academic_year,exam_month,student_id,seat_number,exam_room,created_at_jalali) VALUES (?,?,?,?,?,?)',[$y,'',$st['id'],tr_num((string)$num,'en'),'',jdate('Y/m/d H:i')]);
        $num++; $total++;
      }
    }
  }
  set_flash_message('success','شماره‌گذاری خودکار انجام شد: '.tr_num($total,'fa').' دانش‌آموز (هر پایه از ۱، به ترتیب کلاس و نام خانوادگی).');
  redirect('exams.php?tab=assign&year='.urlencode($y));
 }
 if(isset($_POST['clear_all_seats'])){
  /* v4.72.0: پاک کردن همه شماره صندلی‌های سال */
  $y=unify_academic_year(trim($_POST['academic_year']??$year));
  DB::execute('DELETE FROM exam_student_seating WHERE academic_year=?',[$y]);
  set_flash_message('success','همه شماره صندلی‌های سال '.$y.' پاک شد.');
  redirect('exams.php?tab=assign&year='.urlencode($y));
 }
}
if(isset($_GET['delete'])){
  $delId=(int)$_GET['delete'];
  $deleting=DB::fetch('SELECT * FROM exam_schedules WHERE id=?',[$delId]);
  if($deleting && (in_array($deleting['exam_kind']??'',['class','class_deleted'],true) || ceg_for_exam($deleting))){set_flash_message('error','برای حفاظت از آزمون مشترک، حذف اعضا/منبع گروه از این مسیر مجاز نیست.');redirect('exams.php?tab=class&year='.urlencode($year));}

  /* v4.99.0: طراحی ذخیره‌شده مستقل از آزمون در بانک می‌ماند — قبل از حذف آزمون، بایگانی می‌شود */
  if(function_exists('exam_archive_design_before_source_change')){try{exam_archive_design_before_source_change($delId);}catch(Exception $e){}}
  DB::execute('DELETE FROM exam_schedules WHERE id=?',[$delId]);DB::execute('DELETE FROM exam_assignments WHERE exam_id=?',[$delId]);set_flash_message('success','امتحان حذف شد.');redirect('exams.php');}
require_once __DIR__ . '/includes/header.php';
$exams=DB::fetchAll("SELECT es.*, COALESCE(t.full_name, es.teacher_name) t_name FROM exam_schedules es LEFT JOIN teachers t ON t.id=es.teacher_id WHERE es.academic_year=? AND COALESCE(es.exam_kind,'official') NOT IN ('class','class_deleted') ORDER BY es.exam_date_jalali, es.start_time",[$year]);
$classes=get_unified_class_options($year); $grades=get_unified_grade_options($year); $teachers=DB::fetchAll('SELECT id,full_name FROM teachers WHERE status=1'); usort($teachers, fn($a,$b)=>persian_compare($a['full_name'],$b['full_name']));
$edit=isset($_GET['edit'])?DB::fetch('SELECT * FROM exam_schedules WHERE id=?',[(int)$_GET['edit']]):null; $selectedExam=(int)($_GET['exam_id']??($exams[0]['id']??0));
/* v4.95.0: ماه آزمون و تاریخ آخرین فعال‌سازی ۱ روز در کوکی می‌ماند تا ثبت آزمون بعدی راحت باشد */
$cookieMonth = isset($_COOKIE['exam_last_month']) ? trim(rawurldecode((string)$_COOKIE['exam_last_month'])) : '';
$examMonth = trim($_GET['exam_month'] ?? ($cookieMonth !== '' ? $cookieMonth : 'خرداد'));
$cookieDate = isset($_COOKIE['exam_last_date']) ? trim((string)$_COOKIE['exam_last_date']) : '';
$dparts = preg_split('#[/\-]#', tr_num($cookieDate, 'en'));
$defY = (int)($dparts[0] ?? 0); $defM = (int)($dparts[1] ?? 0); $defD = (int)($dparts[2] ?? 0);
if ($defY < 1300 || $defM < 1 || $defM > 12 || $defD < 1 || $defD > 31) { $defY = (int)tr_num(jdate('Y'),'en'); $defM = (int)tr_num(jdate('m'),'en'); $defD = (int)tr_num(jdate('d'),'en'); }
$selectedGrade = trim($_GET['grade_level'] ?? ($grades[0]['grade_level'] ?? ''));
$selectedClassFilter = trim($_GET['class_name'] ?? '');
$sortMode = trim($_GET['sort'] ?? 'name');
$gradeSubjects = exam_subjects_for_grade($year, $selectedGrade);
$gradeClasses = array_values(array_filter($classes, fn($c)=>$selectedGrade==='' || infer_grade_from_class_name($c['class_name'])===$selectedGrade || ($c['grade_level']??'')===$selectedGrade));
?>
<div class="space-y-6"><?php /* v4.61.0: teacher hero + app-style grid on every teacher page */ require_once __DIR__ . '/includes/teacher_nav.php'; render_teacher_nav(); ?><div class="page-hero flex justify-between items-center"><div><h2 class="text-2xl font-bold">📝 امتحانات</h2><p class="text-sm text-muted">مدیریت برنامه امتحانی، صندلی، کلاس امتحان و چاپ سربرگ سوالات</p></div><div class="flex gap-2"><a class="btn <?php echo $tab==='schedule'?'btn-primary':'btn-outline'; ?>" href="exams.php?tab=schedule&year=<?php echo urlencode($year); ?>">آزمون‌های ماهانه مدرسه</a><a class="btn <?php echo $tab==='class'?'btn-primary':'btn-outline'; ?>" href="exams.php?tab=class&amp;year=<?php echo urlencode($year); ?>">آزمون‌های کلاسی دبیران</a><a class="btn <?php echo $tab==='assign'?'btn-primary':'btn-outline'; ?>" href="exams.php?tab=assign&year=<?php echo urlencode($year); ?>">صندلی و کلاس</a><a class="btn <?php echo $tab==='print'?'btn-primary':'btn-outline'; ?>" href="exams.php?tab=print&year=<?php echo urlencode($year); ?>">چاپ</a><?php if(!(function_exists('current_teacher_is_executive') && current_teacher_is_executive() && !is_admin_logged_in())): ?><a class="btn btn-warning" href="exam-question-bank.php">بانک سوالات</a><?php endif; ?></div></div>
<?php if($tab==='schedule'): ?>
<div class="card compact-exam-builder">
  <form method="GET" class="grid grid-cols-4 gap-3 items-end mb-3"><input type="hidden" name="tab" value="schedule"><div><label class="text-xs">سال تحصیلی (انتخابی)</label><select name="year" class="form-select font-bold" onchange="this.form.submit()">
                        <?php foreach(get_academic_years_for_filter() as $yy): ?>
                            <option value="<?php echo clean($yy['academic_year']); ?>" <?php echo $year===$yy['academic_year']?'selected':''; ?>><?php echo clean($yy['academic_year']); ?><?php echo !empty($yy['is_default'])?' - پیش‌فرض':''; ?></option>
                        <?php endforeach; ?>
                    </select></div><div><label class="text-xs">ماه امتحانی</label><select name="exam_month" class="form-select"><?php foreach(['مهر','آبان','آذر','دی','بهمن','اسفند','فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور'] as $m): ?><option value="<?php echo $m; ?>" <?php echo $examMonth===$m?'selected':''; ?>><?php echo $m; ?></option><?php endforeach; ?></select></div><div><label class="text-xs">پایه</label><select name="grade_level" class="form-select"><?php foreach($grades as $g): ?><option value="<?php echo clean($g['grade_level']); ?>" <?php echo $selectedGrade===$g['grade_level']?'selected':''; ?>><?php echo clean($g['grade_level']); ?></option><?php endforeach; ?></select></div><button class="btn btn-primary">نمایش دروس پایه</button></form>
  <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="create_grade_exams" value="1"><input type="hidden" name="academic_year" value="<?php echo clean($year); ?>"><input type="hidden" name="grade_level" value="<?php echo clean($selectedGrade); ?>"><div class="flex items-end gap-3 mb-3 p-2 rounded border" style="background:rgba(99,102,241,.05);border-color:#c7d2fe"><div><label class="text-xs font-bold">ماه آزمون (این فعال‌سازی)</label><select name="exam_month" class="form-select font-bold"><?php /* v4.70.0: ماه آزمون انتخابی — فعال‌سازی برای هر ۱۲ ماه سال */ foreach(['مهر','آبان','آذر','دی','بهمن','اسفند','فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور'] as $am): ?><option value="<?php echo $am; ?>" <?php echo $examMonth===$am?'selected':''; ?>><?php echo $am; ?></option><?php endforeach; ?></select></div><p class="text-xs text-muted pb-1">آزمون‌های انتخاب‌شده زیر برای همین ماه ثبت و فعال می‌شوند؛ برای ماه دیگر، ماه را عوض کنید و دوباره فعال‌سازی بزنید.</p></div>
    <div class="grid grid-cols-2 gap-3"><div><b>دروس برنامه هفتگی پایه <?php echo clean($selectedGrade); ?></b><div class="table-container max-h-[350px]"><table><thead><tr><th>فعال</th><th>درس</th><th>دبیر خودکار</th><th>تاریخ/روز/ساعت</th></tr></thead><tbody><?php foreach($gradeSubjects as $gs): $key=$gs['subject_name'].'|'.($gs['teacher_id']??0); ?><tr><td><input type="checkbox" name="enabled_subjects[]" value="<?php echo clean($key); ?>"></td><td class="font-bold"><?php echo clean($gs['subject_name']); ?><div class="text-xs text-muted">کلاس‌های این درس:</div><div class="flex flex-wrap gap-1"><?php foreach($gradeClasses as $gc): ?><label class="text-xs"><input type="checkbox" name="classes[<?php echo clean($key); ?>][]" value="<?php echo clean($gc['class_name']); ?>" checked> <?php echo clean($gc['class_name']); ?></label><?php endforeach; ?></div></td><td><?php echo clean($gs['teacher_name'] ? exams_teacher_label($gs['teacher_name']) : 'از برنامه هفتگی'); ?></td><td><?php /* v4.95.0: تاریخ انتخابی سال/ماه/روز — پیش‌فرض = آخرین ثبت (کوکی ۱ روزه) یا امروز */ ?><div class="flex gap-1"><select name="date_y[<?php echo clean($key); ?>]" class="form-select" style="min-width:74px"><?php for($yy=$defY-1;$yy<=$defY+1;$yy++): ?><option value="<?php echo $yy; ?>" <?php echo $yy===$defY?'selected':''; ?>><?php echo tr_num($yy,'fa'); ?></option><?php endfor; ?></select><select name="date_m[<?php echo clean($key); ?>]" class="form-select" style="min-width:88px"><?php $pmn=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند']; for($mm=1;$mm<=12;$mm++): ?><option value="<?php echo $mm; ?>" <?php echo $mm===$defM?'selected':''; ?>><?php echo $pmn[$mm-1]; ?></option><?php endfor; ?></select><select name="date_d[<?php echo clean($key); ?>]" class="form-select" style="min-width:60px"><?php for($ddx=1;$ddx<=31;$ddx++): ?><option value="<?php echo $ddx; ?>" <?php echo $ddx===$defD?'selected':''; ?>><?php echo tr_num($ddx,'fa'); ?></option><?php endfor; ?></select></div><select name="day[<?php echo clean($key); ?>]" class="form-select"><?php foreach(['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه'] as $wd): ?><option value="<?php echo $wd; ?>"><?php echo $wd; ?></option><?php endforeach; ?></select><input class="form-input" name="time[<?php echo clean($key); ?>]" placeholder="08:00"><input class="form-input" name="duration[<?php echo clean($key); ?>]" value="90"><small class="text-xs">فایل سوالات پس از ایجاد آزمون از بخش ویرایش تکی بارگذاری می‌شود.</small></td></tr><?php endforeach; if(!$gradeSubjects): ?><tr><td colspan="4" class="text-center text-muted">برای این پایه در برنامه هفتگی درسی یافت نشد.</td></tr><?php endif; ?></tbody></table></div></div><div><b>کلاس‌های فعال آزمون در این پایه</b><div class="table-container max-h-[350px]"><table><thead><tr><th>فعال</th><th>کلاس</th></tr></thead><tbody><?php foreach($gradeClasses as $gc): ?><tr><td><input type="checkbox" name="enabled_classes[]" value="<?php echo clean($gc['class_name']); ?>" checked></td><td class="font-bold"><?php echo clean($gc['class_name']); ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
    <button class="btn btn-success mt-3">فعال‌سازی آزمون‌های انتخاب‌شده</button>
  </form>
</div>
<div class="grid grid-cols-3 gap-6"><div class="card"><h3 class="font-bold mb-3"><?php echo $edit?'ویرایش امتحان':'تعریف امتحان تکی/اصلاحی'; ?></h3><form method="POST" enctype="multipart/form-data" class="space-y-3"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="save_exam" value="1"><input type="hidden" name="exam_id" value="<?php echo (int)($edit['id']??0); ?>"><input type="hidden" name="existing_question_file" value="<?php echo clean($edit['question_file']??''); ?>"><select name="academic_year" class="form-select font-bold">
                        <option value="">انتخاب سال تحصیلی...</option>
                        <?php foreach(get_academic_years_for_filter() as $yy): ?>
                            <option value="<?php echo clean($yy['academic_year']); ?>" <?php echo ($edit['academic_year']??$year)===$yy['academic_year']?'selected':''; ?>><?php echo clean($yy['academic_year']); ?></option>
                        <?php endforeach; ?>
                    </select><select name="grade_level" class="form-select"><option value="">همه پایه/از کلاس</option><?php foreach($grades as $g): ?><option value="<?php echo clean($g['grade_level']); ?>" <?php echo ($edit['grade_level']??'')===$g['grade_level']?'selected':''; ?>><?php echo clean($g['grade_level']); ?></option><?php endforeach; ?></select><select name="class_name" class="form-select"><option value="">همه کلاس‌های پایه</option><?php foreach($classes as $c): ?><option value="<?php echo clean($c['class_name']); ?>" <?php echo ($edit['class_name']??'')===$c['class_name']?'selected':''; ?>><?php echo clean($c['class_name']); ?></option><?php endforeach; ?></select><select name="subject_name" class="form-select" required><option value="">انتخاب درس از برنامه هفتگی</option><?php foreach($gradeSubjects as $gs): ?><option value="<?php echo clean($gs['subject_name']); ?>" <?php echo ($edit['subject_name']??'')===$gs['subject_name']?'selected':''; ?>><?php echo clean($gs['subject_name'].' - '.($gs['teacher_name']?exams_teacher_label($gs['teacher_name']):'دبیر برنامه')); ?></option><?php endforeach; ?></select><select name="teacher_id" class="form-select"><option value="0">دبیر خودکار/انتخاب دستی</option><?php foreach($teachers as $t): ?><option value="<?php echo $t['id']; ?>" <?php echo ($edit['teacher_id']??0)==$t['id']?'selected':''; ?>><?php echo clean(exams_teacher_label($t['full_name'])); ?></option><?php endforeach; ?></select><input name="exam_date_jalali" class="form-input" required placeholder="1404/03/10" value="<?php echo clean($edit['exam_date_jalali']??''); ?>"><input name="start_time" class="form-input" required placeholder="08:00" value="<?php echo clean($edit['start_time']??''); ?>"><input name="duration_minutes" type="number" class="form-input" value="<?php echo clean($edit['duration_minutes']??90); ?>"><input name="exam_room_default" class="form-input" placeholder="کلاس/سالن پیش‌فرض" value="<?php echo clean($edit['exam_room_default']??''); ?>"><label class="text-xs">فایل سوالات بدون سربرگ PDF/تصویر</label><input type="file" name="question_file" class="form-input" accept=".pdf,.png,.jpg,.jpeg,.webp"><button class="btn btn-success w-full">ذخیره امتحان</button></form></div><div class="card col-span-2"><div class="table-container"><table><thead><tr><th>تاریخ</th><th>ساعت</th><th>پایه/کلاس</th><th>درس</th><th>دبیر</th><th>عملیات</th></tr></thead><tbody><?php foreach($exams as $e): ?><tr><td><?php echo tr_num($e['exam_date_jalali'],'fa'); ?></td><td><?php echo tr_num($e['start_time'],'fa'); ?></td><td><?php echo clean(($e['grade_level']?:'').' '.$e['class_name']); ?></td><td class="font-bold"><?php echo clean($e['subject_name']); ?></td><td><?php echo clean(exams_teacher_label($e['t_name'])); ?></td><td><a class="btn btn-secondary text-xs" href="exams.php?tab=schedule&edit=<?php echo $e['id']; ?>">ویرایش</a><a class="btn btn-danger text-xs" onclick="return confirm('حذف؟')" href="exams.php?delete=<?php echo $e['id']; ?>">حذف</a></td></tr><?php endforeach; ?></tbody></table></div></div></div>
<?php elseif($tab==='class'): ?>
<form method="GET" class="card flex gap-2 items-end"><input type="hidden" name="tab" value="class"><label>سال تحصیلی<select name="year" class="form-select" onchange="this.form.submit()"><?php foreach(get_academic_years_for_filter() as $yy): ?><option value="<?php echo clean($yy['academic_year']); ?>" <?php echo $year===$yy['academic_year']?'selected':''; ?>><?php echo clean($yy['academic_year']); ?></option><?php endforeach; ?></select></label><button class="btn btn-outline">نمایش</button></form>
<?php include __DIR__.'/includes/admin_class_exams.php'; ?>
<?php elseif($tab==='assign'):
// v4.78.0: the assign tab lists ONLY students of the selected year
list($exySqlA,$exyParamsA)=exam_year_students_sql($year);
$students = DB::fetchAll("SELECT s.* FROM students s WHERE s.status='active' AND $exySqlA",$exyParamsA);
if ($selectedGrade !== '') $students = array_values(array_filter($students, fn($s)=>($s['grade_level']===$selectedGrade || infer_grade_from_class_name($s['class_name'])===$selectedGrade)));
if ($selectedClassFilter !== '') $students = array_values(array_filter($students, fn($s)=>$s['class_name']===$selectedClassFilter));
if ($sortMode==='class') persian_usort_classes($students,'class_name','grade_level'); else persian_usort_students($students);
?>
<div class="card"><form method="GET" class="grid grid-cols-6 gap-3 items-end"><input type="hidden" name="tab" value="assign"><div><label class="text-xs">سال تحصیلی (انتخابی)</label><select name="year" class="form-select font-bold" onchange="this.form.submit()">
                        <?php foreach(get_academic_years_for_filter() as $yy): ?>
                            <option value="<?php echo clean($yy['academic_year']); ?>" <?php echo $year===$yy['academic_year']?'selected':''; ?>><?php echo clean($yy['academic_year']); ?></option>
                        <?php endforeach; ?>
                    </select></div><div><label class="text-xs">پایه</label><select name="grade_level" class="form-select"><option value="">همه</option><?php foreach($grades as $g): ?><option value="<?php echo clean($g['grade_level']); ?>" <?php echo $selectedGrade===$g['grade_level']?'selected':''; ?>><?php echo clean($g['grade_level']); ?></option><?php endforeach; ?></select></div><div><label class="text-xs">کلاس</label><select name="class_name" class="form-select"><option value="">همه</option><?php foreach($classes as $c): ?><option value="<?php echo clean($c['class_name']); ?>" <?php echo $selectedClassFilter===$c['class_name']?'selected':''; ?>><?php echo clean($c['class_name']); ?></option><?php endforeach; ?></select></div><div><label class="text-xs">مرتب‌سازی</label><select name="sort" class="form-select"><option value="name" <?php echo $sortMode==='name'?'selected':''; ?>>نام خانوادگی</option><option value="class" <?php echo $sortMode==='class'?'selected':''; ?>>پایه/کلاس</option></select></div><button class="btn btn-primary">نمایش</button></form></div>
<div class="card" style="border:1px dashed #a5b4fc;background:rgba(99,102,241,.04)">
  <div class="flex flex-wrap items-center justify-between gap-3">
    <div>
      <b>شماره‌گذاری سراسری صندلی</b>
      <p class="text-xs text-muted mt-1">شماره هر دانش‌آموز در تمام آزمون‌ها (حتی کلاسی) یکسان است و به ماه امتحانی وابسته نیست. پیش‌فرض: هر پایه از ۱ شروع می‌شود؛ کلاس اول به ترتیب نام خانوادگی، کلاس بعدی از ادامه آخرین شماره کلاس قبلی.</p>
    </div>
    <div class="flex gap-2 flex-wrap">
      <form method="POST" onsubmit="return confirm('شماره‌گذاری خودکار پیش‌فرض انجام شود؟ شماره‌های فعلی این سال جایگزین می‌شوند.')"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="auto_assign_seats" value="1"><input type="hidden" name="academic_year" value="<?php echo clean($year); ?>"><button class="btn btn-success">شماره‌گذاری خودکار پیش‌فرض</button></form>
      <form method="POST" onsubmit="return confirm('همه شماره صندلی‌های اختصاص‌یافته این سال پاک شوند؟')"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="clear_all_seats" value="1"><input type="hidden" name="academic_year" value="<?php echo clean($year); ?>"><button class="btn btn-danger">پاک کردن همه شماره‌ها</button></form>
    </div>
  </div>
</div>
<form method="POST" class="card"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="assign_bulk" value="1"><input type="hidden" name="academic_year" value="<?php echo clean($year); ?>"><div class="grid grid-cols-3 gap-3 mb-4"><input name="exam_room" class="form-input" placeholder="کلاس امتحانی مستقل"><input name="seat_start" class="form-input" value="1" placeholder="شروع شماره صندلی (اختصاص دستی)"><button class="btn btn-primary">اختصاص دستی به انتخاب‌شده‌ها</button></div><div class="table-container"><table><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.ex-st').forEach(c=>c.checked=this.checked)"></th><th>نام</th><th>کلاس</th><th>صندلی/کلاس فعلی</th></tr></thead><tbody><?php foreach($students as $s): $as=DB::fetch('SELECT * FROM exam_student_seating WHERE academic_year=? AND student_id=? ORDER BY id DESC LIMIT 1',[$year,$s['id']]); ?><tr><td><input class="ex-st" type="checkbox" name="student_ids[]" value="<?php echo $s['id']; ?>"></td><td class="font-bold"><?php echo clean($s['last_name'].'، '.$s['first_name']); ?></td><td><?php echo clean($s['class_name']); ?></td><td><?php echo clean(($as['exam_room']??'').' / '.($as['seat_number']??'')); ?></td></tr><?php endforeach; ?></tbody></table></div></form>
<?php else: ?><div class="card"><h3 class="font-bold mb-3">چاپ برنامه، سوالات و کارت صندلی</h3><div class="flex gap-2 mb-3"><a target="_blank" class="btn btn-success" href="exam-print.php?type=seatcards&year=<?php echo urlencode($year); ?>">چاپ کارت صندلی (شماره‌های سراسری سال)</a></div>
<?php /* v4.96.0: فیلتر ماه تحصیلی + وضعیت چاپ برای هر دو جدول تب چاپ */
$pfMonth = trim($_GET['pf_month'] ?? '');
$pfPrint = trim($_GET['pf_printed'] ?? '');
$pfMonths = ['مهر','آبان','آذر','دی','بهمن','اسفند','فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور'];
?>
<form method="GET" class="flex items-end gap-3 mb-3 p-2 rounded border" style="background:rgba(99,102,241,.05);border-color:#c7d2fe"><input type="hidden" name="tab" value="print"><input type="hidden" name="year" value="<?php echo clean($year); ?>"><div><label class="text-xs font-bold">ماه تحصیلی</label><select name="pf_month" class="form-select"><option value="">همه ماه‌ها</option><?php foreach($pfMonths as $pm): ?><option value="<?php echo $pm; ?>" <?php echo $pfMonth===$pm?'selected':''; ?>><?php echo $pm; ?></option><?php endforeach; ?></select></div><div><label class="text-xs font-bold">وضعیت چاپ</label><select name="pf_printed" class="form-select"><option value="">همه</option><option value="1" <?php echo $pfPrint==='1'?'selected':''; ?>>چاپ شده</option><option value="0" <?php echo $pfPrint==='0'?'selected':''; ?>>چاپ نشده</option></select></div><button class="btn btn-primary">اعمال فیلتر</button><?php if($pfMonth!==''||$pfPrint!==''): ?><a class="btn btn-outline" href="exams.php?tab=print&year=<?php echo urlencode($year); ?>">حذف فیلتر</a><?php endif; ?></form><?php
/* v4.88.0: طراحی پایه‌ای که روی یک کلاس ذخیره شده باید برای بقیه کلاس‌های
   همان پایه هم آماده باشد — قبل از رندر جدول چاپ، همگام‌سازی خودکار
   (idempotent — طراحی‌های همگام بدون هزینه رد می‌شوند). */
if (function_exists('exam_replicate_design_to_grade_siblings')) {
    foreach (DB::fetchAll("SELECT d.exam_id FROM exam_designs d JOIN exam_schedules es ON es.id=d.exam_id WHERE es.academic_year=? AND es.is_active=1 AND COALESCE(es.exam_kind,'official') NOT IN ('class','class_deleted') ORDER BY d.id DESC LIMIT 100", [$year]) as $rgd) {
        try { exam_replicate_design_to_grade_siblings((int)$rgd['exam_id']); } catch (Exception $e) {}
    }
}
/* v4.71.0: چاپ پایه‌ای مثل پنل دبیر — ردیف‌های «پایه یکسان + درس یکسان» گروه
   می‌شوند و یک خانه مشترک با rowspan دکمه چاپ پایه‌ای را نگه می‌دارد
   (نه دکمه جدا برای هر ردیف). */
$printExams = $exams;
/* v4.96.0: اعمال فیلترهای ماه/چاپ روی جدول چاپ */
if ($pfMonth !== '') $printExams = array_values(array_filter($printExams, function($pe) use ($pfMonth) { return (string)($pe['exam_month'] ?? '') === $pfMonth; }));
if ($pfPrint !== '') $printExams = array_values(array_filter($printExams, function($pe) use ($pfPrint) { return (int)!empty($pe['is_printed']) === (int)$pfPrint; }));
usort($printExams, function($a, $b) {
    $ga = (trim($a['grade_level'] ?? '') ?: infer_grade_from_class_name($a['class_name'] ?? ''));
    $gb = (trim($b['grade_level'] ?? '') ?: infer_grade_from_class_name($b['class_name'] ?? ''));
    return [$a['exam_month'] ?? '', $ga, $a['subject_name'] ?? '', $a['class_name'] ?? '']
       <=> [$b['exam_month'] ?? '', $gb, $b['subject_name'] ?? '', $b['class_name'] ?? ''];
});
$pxGroupCounts = [];
foreach ($printExams as $pe) {
    $pg = (trim($pe['grade_level'] ?? '') ?: infer_grade_from_class_name($pe['class_name'] ?? ''));
    $pk = ($pe['exam_month'] ?? '') . '|' . $pg . '|' . trim($pe['subject_name'] ?? '');
    $pxGroupCounts[$pk] = ($pxGroupCounts[$pk] ?? 0) + 1;
}
$pxSeen = [];
?><h3 class="font-bold">آزمون‌های ماهانه مدرسه</h3><div class="table-container"><table id="adminMonthlyExams"><thead><tr><th>امتحان</th><th>برنامه جدولی</th><th>طراحی سوال کلاس</th><th>سوالات با سربرگ</th><th>چاپ پایه‌ای (مشترک کلاس‌های پایه)</th><th>چاپ شده؟</th></tr></thead><tbody><?php foreach($printExams as $e):
    $eGradeLevel = trim($e['grade_level'] ?? '') ?: infer_grade_from_class_name($e['class_name'] ?? '');
    $pk = ($e['exam_month'] ?? '') . '|' . $eGradeLevel . '|' . trim($e['subject_name'] ?? '');
    $pCount = $pxGroupCounts[$pk] ?? 1;
    $pFirst = empty($pxSeen[$pk]); $pxSeen[$pk] = true;
?><tr><td><?php echo clean($e['subject_name'].' - '.$e['exam_date_jalali'].' - '.$e['class_name']); ?></td><td><a target="_blank" class="btn btn-primary text-xs" href="exam-print.php?type=schedule&exam_id=<?php echo $e['id']; ?>">چاپ برنامه</a></td><td><a target="_blank" class="btn btn-success text-xs" href="exam-print.php?type=questions&exam_id=<?php echo $e['id']; ?>&dt=<?php echo urlencode(make_exam_design_token($e['id'], is_admin_logged_in() ? 'admin' : 'exec', $_SESSION['admin_id'] ?? ($_SESSION['teacher_id'] ?? 0))); ?>">طراحی اختصاصی این کلاس</a></td><td><a target="_blank" class="btn btn-warning text-xs" href="exam-print.php?type=questions&exam_id=<?php echo $e['id']; ?>&dt=<?php echo urlencode(make_exam_design_token($e['id'], is_admin_logged_in() ? 'admin' : 'exec', $_SESSION['admin_id'] ?? ($_SESSION['teacher_id'] ?? 0))); ?>">چاپ سربرگ سوالات</a></td><?php if ($pFirst): ?><td rowspan="<?php echo (int)$pCount; ?>" class="text-center" style="background:rgba(16,185,129,.06);vertical-align:middle"><?php if ($eGradeLevel !== ''): ?><a target="_blank" class="btn <?php echo $pCount > 1 ? 'btn-success' : 'btn-outline'; ?> text-xs" title="یک طراحی مشترک برای همه کلاس‌های پایه <?php echo clean($eGradeLevel); ?> — چاپ برای تمام دانش‌آموزان پایه" href="exam-print.php?type=questions&exam_id=<?php echo $e['id']; ?>&grade_all=1&dt=<?php echo urlencode(make_exam_design_token($e['id'], is_admin_logged_in() ? 'admin' : 'exec', $_SESSION['admin_id'] ?? ($_SESSION['teacher_id'] ?? 0))); ?>">چاپ پایه‌ای <?php echo clean($eGradeLevel); ?><?php if ($pCount > 1): ?><br><span class="text-[10px] font-normal">(<?php echo tr_num($pCount,'fa'); ?> کلاس)</span><?php endif; ?></a><?php else: ?><span class="text-xs text-muted">پایه نامشخص</span><?php endif; ?></td><?php endif; ?><td><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="mark_printed" value="1"><input type="hidden" name="exam_id" value="<?php echo $e['id']; ?>"><input type="hidden" name="pf_month" value="<?php echo clean($pfMonth); ?>"><input type="hidden" name="pf_printed" value="<?php echo clean($pfPrint); ?>"><label class="text-xs"><input type="checkbox" name="is_printed" onchange="this.form.submit()" <?php echo !empty($e['is_printed'])?'checked':''; ?>> بله</label></form></td></tr><?php endforeach; if(!$printExams): ?><tr><td colspan="6" class="text-center text-muted">آزمونی مطابق فیلتر انتخابی یافت نشد.</td></tr><?php endif; ?></tbody></table></div></div>
<?php include __DIR__.'/includes/admin_class_exams.php'; ?><?php endif; ?></div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
