<?php
/** Selected profile columns: an explicit allowlist, with HTML/Word/Excel outputs. */
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/school_roles.php';
require_once __DIR__.'/includes/student_profile_fields.php';
require_permission('manage_students');
ensure_school_roles_schema();
function student_report_error($text){http_response_code(400);echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>گزارش دانش‌آموزان</title><body><p>'.clean($text).'</p></body></html>';exit;}
if($_SERVER['REQUEST_METHOD']!=='POST'||!verify_csrf($_POST['csrf_token']??''))student_report_error('درخواست نامعتبر است؛ گزارش را از فهرست دانش‌آموزان انتخاب کنید.');
$ids=array_values(array_unique(array_filter(array_map('intval',is_array($_POST['student_ids']??null)?$_POST['student_ids']:[]),fn($i)=>$i>0)));
if(!$ids)student_report_error('دانش‌آموزی انتخاب نشده است.');
$type=in_array($_POST['report_type']??'',['info','discipline','grades'],true)?$_POST['report_type']:'info';
$format=in_array($_POST['format']??'',['html','doc','xls'],true)?$_POST['format']:'html';
$columns=student_report_columns($_POST['fields']??(isset($_POST['fields_version'])?[]:['identity','contact','academic','discipline']));
if(!$columns)student_report_error('حداقل یک ستون گزارش را انتخاب کنید.');
$students=[]; // Chunk IN clauses for older SQLite variable limits; never truncate selection.
foreach(array_chunk($ids,400) as $chunk)$students=array_merge($students,DB::fetchAll('SELECT * FROM students WHERE id IN ('.implode(',',array_fill(0,count($chunk),'?')).')',$chunk));
usort($students,fn($a,$b)=>persian_compare($a['last_name'],$b['last_name'])?:persian_compare($a['first_name'],$b['first_name']));
$rows=[];$academic=(bool)array_intersect(array_keys($columns),['latest_gpa','latest_report','failed_grades']);
$discipline=(bool)array_intersect(array_keys($columns),['discipline_count','discipline_details']);
foreach($students as $s){
 $extra=[];$gradeRows=[];
 if($academic){
  $latest=DB::fetch('SELECT gpa,term,report_month FROM reports WHERE student_id=? ORDER BY id DESC LIMIT 1',[$s['id']]);
  $failed=DB::fetch('SELECT COUNT(*) c FROM reports r JOIN report_grades rg ON rg.report_id=r.id WHERE r.student_id=? AND rg.score<10',[$s['id']]);
  $extra=['latest_gpa'=>$latest?format_score($latest['gpa']):'—','latest_report'=>$latest?$latest['term'].' / '.$latest['report_month']:'—','failed_grades'=>$failed['c']??0];
 }
 if($type==='grades'){
  $where=['r.student_id=?'];$args=[$s['id']];
  foreach(['grade_subject'=>'rg.subject_name','grade_month'=>'r.report_month','grade_score'=>'rg.score'] as $input=>$column)if(($_POST[$input]??'')!==''){$where[]=$column.'=?';$args[]=$input==='grade_score'?(float)student_ascii_digits($_POST[$input]):trim($_POST[$input]);}
  $gradeRows=DB::fetchAll('SELECT r.report_month,rg.subject_name,rg.score FROM reports r JOIN report_grades rg ON rg.report_id=r.id WHERE '.implode(' AND ',$where).' ORDER BY r.id DESC',$args);
  if(!$gradeRows)continue;
  $extra['failed_grades']=implode(' | ',array_map(fn($g)=>$g['report_month'].' — '.$g['subject_name'].': '.$g['score'],$gradeRows));
 }
 if($discipline||$type==='discipline'){
  $where=['student_id=?'];$args=[$s['id']];
  if(!empty($_POST['discipline_title'])){$where[]='title_text LIKE ?';$args[]='%'.trim($_POST['discipline_title']).'%';}
  foreach(['from_jalali'=>'>=','to_jalali'=>'<='] as $input=>$op)if(($_POST[$input]??'')!==''){
   $date=student_ascii_digits($_POST[$input]);if(!preg_match('~^\d{4}/\d{2}/\d{2}$~D',$date))student_report_error('بازهٔ تاریخ انضباطی را به شکل سال/ماه/روز وارد کنید.');
   $where[]='occurred_at_jalali '.$op.' ?';$args[]=$date;
  }
  $disc=DB::fetchAll('SELECT occurred_at_jalali,title_text FROM student_discipline_records WHERE '.implode(' AND ',$where).' ORDER BY occurred_at_jalali DESC',$args);
  if($type==='discipline'&&($_POST['discipline_count']??'')!==''&&count($disc)!==(int)student_ascii_digits($_POST['discipline_count']))continue;
  $extra['discipline_count']=count($disc);$extra['discipline_details']=implode(' | ',array_map(fn($d)=>$d['occurred_at_jalali'].' — '.$d['title_text'],$disc));
 }
 $row=[];foreach($columns as $key=>$label)$row[$key]=array_key_exists($key,$extra)?(string)$extra[$key]:student_profile_value($s,$key);$rows[]=$row;
}
$school=get_setting('school_name','آموزشگاه');
$title=['info'=>'گزارش اطلاعات دانش‌آموزان','discipline'=>'گزارش انضباطی دانش‌آموزان','grades'=>'گزارش تحلیلی نمرات دانش‌آموزان'][$type];
$filename='student-report-'.$type.'-'.date('Ymd-His');
header('Cache-Control: private, no-store');
if($format==='xls'){header('Content-Type: application/vnd.ms-excel; charset=utf-8');header("Content-Disposition: attachment; filename=$filename.xls");}
elseif($format==='doc'){header('Content-Type: application/msword; charset=utf-8');header("Content-Disposition: attachment; filename=$filename.doc");}
else header('Content-Type: text/html; charset=utf-8');
$font=app_font_spec();$exportFont=$format==='html'?$font['family']:app_export_font_family();
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo clean($title); ?></title>
<?php if($format==='html')echo app_appearance_head(); ?>
<style>
body{direction:rtl;margin:16px;color:#111;background:#fff}body,body *{font-family:<?php echo json_encode($exportFont,JSON_HEX_TAG|JSON_HEX_AMP); ?>,Tahoma,sans-serif!important}
.report-heading{text-align:center;padding:12px;border-bottom:2px solid #333;margin-bottom:16px}.report-meta{display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;font-size:12px}
table{width:100%;border-collapse:collapse}th,td{border:1px solid #555;padding:7px;font-size:12px;vertical-align:top;overflow-wrap:anywhere}th{background:#eee}thead{display:table-header-group}.sign{margin-top:30px;display:flex;justify-content:space-around}.report-actions{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px;align-items:center}.report-actions button,.report-actions a{font-size:14px;padding:10px 16px;border:1px solid #64748b;border-radius:8px;color:#173a65;background:#f1f5f9;text-decoration:none}.report-scroll{overflow:auto}#report-print-status{font-size:13px}
@media print{@page{size:<?php echo count($columns)>18?'A3':'A4'; ?> <?php echo count($columns)>7?'landscape':'portrait'; ?>;margin:10mm}.report-actions,.school-return-nav{display:none!important}.report-scroll{overflow:visible}body{margin:0}th,td{padding:3px;font-size:<?php echo count($columns)>12?'8':'10'; ?>pt}tr{break-inside:avoid}}
</style>
<?php if($format==='html'): ?><script defer src="assets/js/school-navigation.js?v=20260917d"></script><script defer src="assets/js/student-report-print.js?v=20260917c"></script><?php endif; ?>
</head><body data-school-return="preview" data-report-font="<?php echo clean($font['family']); ?>" data-report-local-font="<?php echo $font['url']!==''?'1':'0'; ?>">
<?php if($format==='html'): ?><div class="report-actions"><a href="students.php">بازگشت به دانش‌آموزان</a><button type="button" id="report-print">چاپ / ذخیره PDF</button><span id="report-print-status" role="status">آماده‌سازی قلم برای چاپ…</span></div><?php endif; ?>
<div class="report-heading"><h2><?php echo clean($school); ?></h2><h3><?php echo clean($title); ?></h3><div class="report-meta"><span>تاریخ: <?php echo tr_num(jdate('Y/m/d H:i'),'fa'); ?></span><span>تعداد ردیف‌های گزارش: <?php echo tr_num(count($rows),'fa'); ?> | دانش‌آموزان انتخاب‌شده: <?php echo tr_num(count($students),'fa'); ?></span></div></div>
<div class="report-scroll"><table><thead><tr><th>ردیف</th><?php foreach($columns as $label): ?><th><?php echo clean($label); ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach($rows as $i=>$row): ?><tr><td><?php echo tr_num($i+1,'fa'); ?></td><?php foreach($row as $key=>$value): ?><td<?php echo in_array($key,['national_id','serial_number','serial_suffix','student_code','phone','father_phone','mother_phone','student_mobile','home_phone','postal_code'],true)?' dir="ltr" style="mso-number-format:\'\@\';text-align:center"':''; ?>><?php echo clean($value===''?'—':$value); ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div><?php if(!$rows): ?><p>ردیفی مطابق فیلترهای انتخاب‌شده یافت نشد.</p><?php endif; ?>
<div class="sign"><span>مسئول آموزش</span><span>معاون / ناظم</span><span>مدیر مدرسه</span></div>
</body></html>
