<?php
// File: report-grade-transfer.php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/school_roles.php';
require_once __DIR__ . '/includes/academic_year_helpers.php';
require_once __DIR__ . '/includes/school_sort.php';
ensure_school_roles_schema();
$canDeputyReports = is_teacher_logged_in() && (teacher_has_deputy((int)$_SESSION['teacher_id']) || teacher_has_executive((int)$_SESSION['teacher_id']));
if (!$canDeputyReports) require_permission('manage_reports');

function rgt_class_sql($column) { return "REPLACE(REPLACE(REPLACE(REPLACE($column,' ',''),'-',''),'/',''),'_','') = ?"; }
function rgt_months() { return ['مهر','آبان','آذر','دی','بهمن','اسفند','فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','نوبت اول','نوبت دوم']; }
function rgt_term($month) { return in_array($month,['بهمن','اسفند','فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','نوبت دوم'],true)?'نوبت دوم':'نوبت اول'; }
function rgt_years() {
    $rows=[];foreach(get_academic_years_for_filter() as $item){$value=is_array($item)?(string)($item['academic_year']??''):(string)$item;$value=unify_academic_year($value);if($value!=='')$rows[]=$value;}
    $current=unify_academic_year(get_current_academic_year());
    if($current!==''&&!in_array($current,$rows,true))$rows[]=$current;
    return array_values(array_unique($rows));
}
function rgt_classes($year) {
    $out=[];
    foreach(get_unified_class_options($year) as $r){$name=trim((string)($r['class_name']??''));if($name!=='')$out[norm_class_str($name)]=$name;}
    return $out;
}
function rgt_students($year,$class) {
    $where=["(s.status='active' OR EXISTS(SELECT 1 FROM reports rr WHERE rr.student_id=s.id AND rr.academic_year=?))","(s.academic_year=? OR s.academic_year IS NULL OR s.academic_year='' OR EXISTS(SELECT 1 FROM reports yr WHERE yr.student_id=s.id AND yr.academic_year=?))"];
    $params=[$year,$year,$year];
    if($class!==''){$where[]='('.rgt_class_sql('s.class_name').' OR EXISTS(SELECT 1 FROM reports cr WHERE cr.student_id=s.id AND cr.academic_year=? AND '.rgt_class_sql('cr.class_name').'))';$params[]=norm_class_str($class);$params[]=$year;$params[]=norm_class_str($class);}
    $rows=DB::fetchAll('SELECT DISTINCT s.id,s.first_name,s.last_name,s.national_id,s.class_name FROM students s WHERE '.implode(' AND ',$where).' ORDER BY s.last_name,s.first_name,s.id',$params);
    if(function_exists('persian_usort_students'))persian_usort_students($rows);
    return $rows;
}
function rgt_subjects($year,$class) {
    $sql='SELECT DISTINCT subject_name FROM class_schedules WHERE academic_year=? AND TRIM(COALESCE(subject_name,\'\'))<>\'\'';$params=[$year];
    if($class!==''){$sql.=' AND '.rgt_class_sql('class_name');$params[]=norm_class_str($class);}
    $rows=DB::fetchAll($sql.' ORDER BY subject_name',$params);return array_values(array_filter(array_map(static function($r){return trim((string)$r['subject_name']);},$rows),'strlen'));
}
function rgt_filters($prefix,$input,$years) {
    $year=unify_academic_year(trim((string)($input[$prefix.'_year']??'')));
    if($year===''||!in_array($year,$years,true))throw new RuntimeException('برای مبدا و مقصد باید یک سال تحصیلی مشخص انتخاب شود.');
    $month=trim((string)($input[$prefix.'_month']??'all'));
    if($month!=='all'&&!in_array($month,rgt_months(),true))throw new RuntimeException('ماه انتخاب‌شده معتبر نیست.');
    return ['year'=>$year,'month'=>$month,'class'=>trim((string)($input[$prefix.'_class']??'')),'student'=>trim((string)($input[$prefix.'_student']??'all')),'subject'=>trim((string)($input[$prefix.'_subject']??'all'))];
}
function rgt_matching_reports(array $src) {
    $where=['r.academic_year=?'];$params=[$src['year']];
    if($src['month']!=='all'){$where[]='r.report_month=?';$params[]=$src['month'];}
    if($src['class']!==''){$where[]=rgt_class_sql('r.class_name');$params[]=norm_class_str($src['class']);}
    if($src['student']!=='all'){$where[]='r.student_id=?';$params[]=(int)$src['student'];}
    $rows=DB::fetchAll('SELECT r.*,s.national_id,s.first_name,s.last_name FROM reports r JOIN students s ON s.id=r.student_id WHERE '.implode(' AND ',$where).' ORDER BY r.id ASC',$params);
    // For duplicate reports of the same student/month, the newest one is authoritative.
    $latest=[];
    foreach($rows as $r){$key=(int)$r['student_id'].'|'.(string)$r['report_month'];$latest[$key]=$r;}
    return array_values($latest);
}
function rgt_target_subject_exists($year,$class,$subject) {
    if(trim($subject)==='')return false;
    $sql='SELECT id FROM class_schedules WHERE academic_year=? AND subject_name=?';$params=[$year,$subject];
    if($class!==''){$sql.=' AND '.rgt_class_sql('class_name');$params[]=norm_class_str($class);}
    return (bool)DB::fetch($sql.' LIMIT 1',$params);
}
function rgt_recalc_report($reportId) {
    $rows=DB::fetchAll('SELECT score,coefficient FROM report_grades WHERE report_id=?',[$reportId]);$weighted=0.0;$coeff=0.0;
    foreach($rows as $r){$score=(float)$r['score'];$c=(float)$r['coefficient'];if($c<=0)$c=1.0;if($score!=21.0){$weighted+=$score*$c;$coeff+=$c;}}
    $gpa=$coeff>0?round($weighted/$coeff,2):0.0;DB::execute('UPDATE reports SET total_score=?,gpa=? WHERE id=?',[$weighted,$gpa,$reportId]);
}
function rgt_transfer(array $src,array $dst,$mode) {
    if(!in_array($mode,['move','copy'],true))throw new RuntimeException('نوع عملیات انتقال معتبر نیست.');
    if($src['student']==='all'&&$dst['student']!=='all')throw new RuntimeException('وقتی دانش‌آموز مبدا روی «همه» است، دانش‌آموز مقصد نیز باید «همه» باشد تا نمرات چند دانش‌آموز روی یک نفر بازنویسی نشود.');
    if($src['month']==='all'&&$dst['month']!=='all')throw new RuntimeException('اگر ماه مبدا «همه» است، ماه مقصد نیز باید «همه» باشد تا نمره‌های چند ماه روی هم نوشته نشوند.');
    if($src['subject']==='all'&&$dst['subject']!=='all')throw new RuntimeException('برای نگاشت یک درس مقصد، ابتدا یک درس مشخص در مبدا انتخاب کنید.');

    $sourceReports=rgt_matching_reports($src);$sourceReportIds=array_map(static function($r){return (int)$r['id'];},$sourceReports);
    $targetStudents=rgt_students($dst['year'],$dst['class']);$targetById=[];$targetByNational=[];
    foreach($targetStudents as $s){$targetById[(int)$s['id']]=$s;$nid=trim((string)($s['national_id']??''));if($nid!=='')$targetByNational[$nid]=$s;}
    $sourceToReport=[];foreach($sourceReports as $r)$sourceToReport[(int)$r['id']]=$r;
    $grades=[];
    if($sourceReportIds){$ph=implode(',',array_fill(0,count($sourceReportIds),'?'));$grades=DB::fetchAll("SELECT * FROM report_grades WHERE report_id IN ($ph) ORDER BY id",$sourceReportIds);}
    $summary=['copied'=>0,'moved'=>0,'missing_student'=>0,'missing_subject'=>0,'locked'=>0,'source_locked'=>0,'same'=>0];$recalc=[];
    $pdo=DB::getInstance()->getPdo();$pdo->beginTransaction();
    try {
        foreach($grades as $g){
            $sourceReport=$sourceToReport[(int)$g['report_id']]??null;if(!$sourceReport)continue;
            if($src['subject']!=='all'&&$g['subject_name']!==$src['subject'])continue;
            $sourceStudentId=(int)$sourceReport['student_id'];
            if($dst['student']!=='all')$destStudent=$targetById[(int)$dst['student']]??null;
            else {$nid=trim((string)($sourceReport['national_id']??''));$destStudent=$nid!==''?($targetByNational[$nid]??null):($targetById[$sourceStudentId]??null);}
            if(!$destStudent){$summary['missing_student']++;continue;}
            if($mode==='move'&&(int)$sourceReport['is_locked']===1){$summary['source_locked']++;continue;}
            $destClass=$dst['class']!==''?$dst['class']:trim((string)$destStudent['class_name']);
            if($destClass===''&&$dst['class']===''){$summary['missing_student']++;continue;}
            $destMonth=$dst['month']==='all'?(string)$sourceReport['report_month']:$dst['month'];
            $destSubject=$dst['subject']==='all'?(string)$g['subject_name']:$dst['subject'];
            if(!rgt_target_subject_exists($dst['year'],$destClass,$destSubject)){$summary['missing_subject']++;continue;}
            $destReport=DB::fetch('SELECT id,is_locked FROM reports WHERE student_id=? AND academic_year=? AND report_month=? AND '.rgt_class_sql('class_name').' ORDER BY id DESC LIMIT 1',[(int)$destStudent['id'],$dst['year'],$destMonth,norm_class_str($destClass)]);
            if(!$destReport && $dst['class']==='')$destReport=DB::fetch('SELECT id,is_locked FROM reports WHERE student_id=? AND academic_year=? AND report_month=? ORDER BY id DESC LIMIT 1',[(int)$destStudent['id'],$dst['year'],$destMonth]);
            if($destReport&&(int)$destReport['is_locked']===1){$summary['locked']++;continue;}
            if(!$destReport){DB::execute('INSERT INTO reports (student_id,class_name,academic_year,term,report_month,total_score,gpa) VALUES (?,?,?,?,?,0,0)',[(int)$destStudent['id'],$destClass,$dst['year'],rgt_term($destMonth),$destMonth]);$destReport=['id'=>(int)DB::lastInsertId()];}
            $destReportId=(int)$destReport['id'];$sourceReportId=(int)$sourceReport['id'];
            $existing=DB::fetch('SELECT id FROM report_grades WHERE report_id=? AND subject_name=? ORDER BY id DESC LIMIT 1',[$destReportId,$destSubject]);
            if($mode==='move'&&$destReportId===$sourceReportId&&$destSubject===$g['subject_name']){$summary['same']++;continue;}
            if($existing){DB::execute('UPDATE report_grades SET score=?,max_score=?,coefficient=?,status=? WHERE id=?',[$g['score'],$g['max_score'],$g['coefficient'],$g['status'],$existing['id']]);}
            else{DB::execute('INSERT INTO report_grades (report_id,subject_name,score,max_score,coefficient,status) VALUES (?,?,?,?,?,?)',[$destReportId,$destSubject,$g['score'],$g['max_score'],$g['coefficient'],$g['status']]);}
            $recalc[$destReportId]=true;
            if($mode==='move'){
                DB::execute('DELETE FROM report_grades WHERE id=?',[(int)$g['id']]);$recalc[$sourceReportId]=true;$summary['moved']++;
            }else $summary['copied']++;
        }
        foreach(array_keys($recalc) as $rid)rgt_recalc_report((int)$rid);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return $summary;
}

$years=rgt_years();$current=unify_academic_year(get_current_academic_year());if($current===''&&$years)$current=$years[0];
$filters=[];
foreach(['src','dst'] as $prefix){
    $filters[$prefix]=[
        'year'=>unify_academic_year(trim((string)($_GET[$prefix.'_year']??$_POST[$prefix.'_year']??$current))),
        'month'=>trim((string)($_GET[$prefix.'_month']??$_POST[$prefix.'_month']??'all')),
        'class'=>trim((string)($_GET[$prefix.'_class']??$_POST[$prefix.'_class']??'')),
        'student'=>trim((string)($_GET[$prefix.'_student']??$_POST[$prefix.'_student']??'all')),
        'subject'=>trim((string)($_GET[$prefix.'_subject']??$_POST[$prefix.'_subject']??'all')),
    ];
}
$embedded=(($_GET['embedded']??$_POST['embedded']??'')==='1');
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['show_grade_filters'])){
    try{$src=rgt_filters('src',$_POST,$years);$dst=rgt_filters('dst',$_POST,$years);}
    catch(Throwable $e){set_flash_message('error',$e instanceof RuntimeException?$e->getMessage():'فیلترهای انتخاب‌شده معتبر نیستند.');}
    $qs=['embedded'=>$embedded?'1':null];foreach(['src'=>$src??$filters['src'],'dst'=>$dst??$filters['dst']] as $prefix=>$f)foreach($f as $field=>$value)$qs[$prefix.'_'.$field]=$value;
    $qs=array_filter($qs,static function($v){return $v!==null;});redirect('report-grade-transfer.php?'.http_build_query($qs));
}
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['run_grade_transfer'])){
    try{
        if(!verify_csrf($_POST['csrf_token']??''))throw new RuntimeException('اعتبار فرم منقضی شده است؛ صفحه را تازه کنید.');
        $src=rgt_filters('src',$_POST,$years);$dst=rgt_filters('dst',$_POST,$years);$mode=(string)($_POST['run_grade_transfer']??'');
        $summary=rgt_transfer($src,$dst,$mode);
        $verb=$mode==='move'?'انتقال':'رونوشت';
        $msg=$verb.' انجام شد: '.tr_num($summary[$mode==='move'?'moved':'copied'],'fa').' نمره؛ '.tr_num($summary['missing_student'],'fa').' دانش‌آموز مقصد پیدا نشد؛ '.tr_num($summary['missing_subject'],'fa').' درس در مقصد تعریف نشده بود؛ '.tr_num($summary['locked']+$summary['source_locked'],'fa').' مورد قفل‌شده رد شد.';
        if($summary['same'])$msg.=' '.tr_num($summary['same'],'fa').' مورد یکسان بدون تغییر باقی ماند.';
        log_activity($_SESSION['admin_id']??null,$mode==='move'?'انتقال نمرات کارنامه':'رونوشت نمرات کارنامه',$msg);
        set_flash_message('success',$msg);
    }catch(Throwable $e){set_flash_message('error',$e instanceof RuntimeException?$e->getMessage():'عملیات ناموفق بود؛ تغییرات انجام‌شده بازگردانده شد.');}
    $qs=['embedded'=>$embedded?'1':null];foreach($filters as $prefix=>$f)foreach($f as $field=>$value)$qs[$prefix.'_'.$field]=$value;
    $qs=array_filter($qs,static function($v){return $v!==null;});redirect('report-grade-transfer.php?'.http_build_query($qs));
}
foreach(['src','dst'] as $prefix){
    if($filters[$prefix]['year']===''||!in_array($filters[$prefix]['year'],$years,true))$filters[$prefix]['year']=$current;
    if($filters[$prefix]['month']!=='all'&&!in_array($filters[$prefix]['month'],rgt_months(),true))$filters[$prefix]['month']='all';
    if($filters[$prefix]['student']!=='all'&&!ctype_digit($filters[$prefix]['student']))$filters[$prefix]['student']='all';
}
$srcReports=rgt_matching_reports($filters['src']);$srcGradesCount=0;
if($srcReports){$ids=array_map(static function($r){return (int)$r['id'];},$srcReports);$ph=implode(',',array_fill(0,count($ids),'?'));$sql="SELECT COUNT(*) n FROM report_grades WHERE report_id IN ($ph)";$params=$ids;if($filters['src']['subject']!=='all'){$sql.=' AND subject_name=?';$params[]=$filters['src']['subject'];}$srcGradesCount=(int)(DB::fetch($sql,$params)['n']??0);}
require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-4 rgt-wrap">
    <h2 class="font-bold text-lg">انتقال نمرات کارنامه</h2>
    <p class="text-xs text-muted">سال تحصیلی در هر دو سمت باید مشخص باشد. در حالت «همهٔ درس‌ها»، فقط درس‌هایی منتقل می‌شوند که در برنامهٔ سال و کلاس مقصد تعریف شده باشند. برای تغییر نام درس، یک درس مشخص در مبدا و درس متناظر مقصد را انتخاب کنید.</p>
    <form method="POST" action="report-grade-transfer.php<?php echo $embedded?'?embedded=1':''; ?>" class="card space-y-4 rgt-actions">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="embedded" value="<?php echo $embedded?'1':'0'; ?>">
        <?php foreach(['src'=>'مبدا','dst'=>'مقصد'] as $prefix=>$title): $f=$filters[$prefix];$classOptions=rgt_classes($f['year']);$studentOptions=rgt_students($f['year'],$f['class']);$subjectOptions=rgt_subjects($f['year'],$f['class']); ?>
        <fieldset class="rgt-fieldset"><legend><?php echo clean($title); ?></legend><div class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
            <label>سال تحصیلی<select class="form-select" name="<?php echo $prefix; ?>_year" required><?php foreach($years as $y): ?><option value="<?php echo clean($y); ?>" <?php echo $f['year']===$y?'selected':''; ?>><?php echo clean($y); ?></option><?php endforeach; ?></select></label>
            <label>ماه تحصیلی<select class="form-select" name="<?php echo $prefix; ?>_month"><option value="all" <?php echo $f['month']==='all'?'selected':''; ?>>همه</option><?php foreach(rgt_months() as $m): ?><option value="<?php echo clean($m); ?>" <?php echo $f['month']===$m?'selected':''; ?>><?php echo clean($m); ?></option><?php endforeach; ?></select></label>
            <label>کلاس<select class="form-select" name="<?php echo $prefix; ?>_class"><option value="">همه</option><?php foreach($classOptions as $key=>$name): ?><option value="<?php echo clean($name); ?>" <?php echo norm_class_str($f['class'])===$key?'selected':''; ?>><?php echo clean($name); ?></option><?php endforeach; ?></select></label>
            <label>دانش‌آموز<select class="form-select" name="<?php echo $prefix; ?>_student"><option value="all">همه</option><?php foreach($studentOptions as $st): ?><option value="<?php echo (int)$st['id']; ?>" <?php echo (string)$f['student']===(string)$st['id']?'selected':''; ?>><?php echo clean(trim($st['first_name'].' '.$st['last_name']).' — '.$st['class_name']); ?></option><?php endforeach; ?></select></label>
            <label>درس<select class="form-select" name="<?php echo $prefix; ?>_subject"><option value="all">همه</option><?php foreach($subjectOptions as $sub): ?><option value="<?php echo clean($sub); ?>" <?php echo $f['subject']===$sub?'selected':''; ?>><?php echo clean($sub); ?></option><?php endforeach; ?></select></label>
        </div></fieldset>
        <?php endforeach; ?>
        <div class="flex flex-wrap gap-2 items-center"><button class="btn btn-primary" name="show_grade_filters" value="1" type="submit">نمایش فیلترها</button><span class="text-xs text-muted">تعداد نمره‌های منطبق با مبدا در فیلترهای نمایش‌داده‌شده: <?php echo tr_num($srcGradesCount,'fa'); ?></span></div>
        <div class="border-t pt-4 space-y-3">
            <p class="text-sm">«انتقال نمرات» نمره را در کارنامهٔ مقصد ثبت می‌کند و فقط پس از ثبت موفق، ردیف نمرهٔ مبدا را حذف می‌کند. اگر دانش‌آموز یا درس مقصد پیدا نشود، مبدا حفظ می‌شود. «رونوشت نمرات» نمرهٔ مبدا را نگه می‌دارد.</p>
            <div class="flex flex-wrap gap-2"><button class="btn btn-warning" name="run_grade_transfer" value="move" type="submit" onclick="return confirm('نمرات منطبق پس از ثبت موفق در مقصد از مبدا حذف می‌شوند. ادامه می‌دهید؟')">انتقال نمرات</button><button class="btn btn-success" name="run_grade_transfer" value="copy" type="submit">رونوشت نمرات</button></div>
        </div>
    </form>
</div>
<style>.rgt-fieldset{border:1px solid #cbd5e1;border-radius:12px;padding:14px}.rgt-fieldset legend{font-weight:700;padding:0 8px}.rgt-actions{display:grid;gap:12px}</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
