<?php
// File: student-bulk-report.php
/**
 * Bulk administrative reports for selected students: info, discipline, grades.
 * Outputs Excel-compatible HTML, Word-compatible HTML, or printable HTML.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/school_roles.php';
require_permission('manage_students');
ensure_school_roles_schema();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? '')) die('درخواست نامعتبر است.');
$ids = array_values(array_filter(array_map('intval', $_POST['student_ids'] ?? [])));
if (!$ids) die('دانش‌آموزی انتخاب نشده است.');
$type = $_POST['report_type'] ?? 'info';
$format = $_POST['format'] ?? 'xls';
$fields = $_POST['fields'] ?? ['identity','contact','academic','discipline'];
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$students = DB::fetchAll("SELECT * FROM students WHERE id IN ($placeholders)", $ids);
usort($students, fn($a,$b)=>persian_compare($a['last_name'], $b['last_name']) ?: persian_compare($a['first_name'], $b['first_name']));   // v4.77.0: آ قبل از ا
$school = get_setting('school_name','آموزشگاه');
$titleMap = ['info'=>'گزارش اطلاعات دانش‌آموزان','discipline'=>'گزارش انضباطی دانش‌آموزان','grades'=>'گزارش تحلیلی نمرات دانش‌آموزان'];
$title = $titleMap[$type] ?? $titleMap['info'];
$filename = 'student-report-' . $type . '-' . date('Ymd-His');
if ($format === 'xls') { header('Content-Type: application/vnd.ms-excel; charset=utf-8'); header("Content-Disposition: attachment; filename=$filename.xls"); }
elseif ($format === 'doc') { header('Content-Type: application/msword; charset=utf-8'); header("Content-Disposition: attachment; filename=$filename.doc"); }
else { header('Content-Type: text/html; charset=utf-8'); }
function yes_field($f,$fields){return in_array($f,$fields,true);} 
?>
<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title><?php echo clean($title); ?></title><style>body{font-family:Tahoma,sans-serif;direction:rtl} .header{text-align:center;border:2px solid #000;padding:12px;margin-bottom:16px}.meta{display:flex;justify-content:space-between;font-size:12px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #333;padding:6px;font-size:12px}th{background:#eee}.sign{margin-top:40px;display:flex;justify-content:space-around}</style><?php echo app_appearance_head(); ?></head><body>
<div class="header"><h2><?php echo clean($school); ?></h2><h3><?php echo clean($title); ?></h3><div class="meta"><span>تاریخ گزارش: <?php echo tr_num(jdate('Y/m/d H:i'),'fa'); ?></span><span>تعداد دانش‌آموزان: <?php echo tr_num(count($students),'fa'); ?></span></div></div>
<table><thead><tr><th>ردیف</th><?php if(yes_field('identity',$fields)): ?><th>نام و نام خانوادگی</th><th>کد ملی</th><th>نام پدر</th><th>پایه</th><th>کلاس</th><?php endif; ?><?php if(yes_field('contact',$fields)): ?><th>تلفن</th><th>موبایل پدر</th><th>موبایل مادر</th><?php endif; ?><?php if(yes_field('academic',$fields)): ?><th>آخرین معدل</th><th>آخرین کارنامه</th><th>نمرات زیر ۱۰</th><?php endif; ?><?php if(yes_field('discipline',$fields)): ?><th>تعداد موارد انضباطی</th><th>آخرین مورد</th><?php endif; ?></tr></thead><tbody>
<?php foreach($students as $i=>$s):
 $latest=DB::fetch("SELECT gpa, term, report_month FROM reports WHERE student_id=? ORDER BY id DESC LIMIT 1",[$s['id']]);
 $failed=DB::fetch("SELECT COUNT(*) c FROM reports r JOIN report_grades rg ON rg.report_id=r.id WHERE r.student_id=? AND rg.score<10",[$s['id']]);
 $discWhere=['student_id=?']; $discParams=[$s['id']]; if(!empty($_POST['discipline_title'])){$discWhere[]='title_text LIKE ?';$discParams[]='%'.trim($_POST['discipline_title']).'%';}
 $discRows=DB::fetchAll('SELECT * FROM student_discipline_records WHERE '.implode(' AND ',$discWhere).' ORDER BY occurred_at_jalali DESC',$discParams);
 if($type==='discipline' && $_POST['discipline_count']!=='' && count($discRows)!=(int)$_POST['discipline_count']) continue;
 $gradeRows=[]; if($type==='grades'){ $gw=['r.student_id=?']; $gp=[$s['id']]; if(!empty($_POST['grade_subject'])){$gw[]='rg.subject_name=?';$gp[]=trim($_POST['grade_subject']);} if(!empty($_POST['grade_month'])){$gw[]='r.report_month=?';$gp[]=trim($_POST['grade_month']);} if($_POST['grade_score']!==''){$gw[]='rg.score=?';$gp[]=(float)$_POST['grade_score'];} $gradeRows=DB::fetchAll('SELECT r.academic_year,r.report_month,r.term,rg.subject_name,rg.score FROM reports r JOIN report_grades rg ON rg.report_id=r.id WHERE '.implode(' AND ',$gw).' ORDER BY r.id DESC',$gp); if(!$gradeRows) continue; }
?>
<tr><td><?php echo tr_num($i+1,'fa'); ?></td><?php if(yes_field('identity',$fields)): ?><td><?php echo clean($s['last_name'].'، '.$s['first_name']); ?></td><td><?php echo tr_num($s['national_id'],'fa'); ?></td><td><?php echo clean($s['father_name']); ?></td><td><?php echo clean($s['grade_level']); ?></td><td><?php echo clean($s['class_name']); ?></td><?php endif; ?><?php if(yes_field('contact',$fields)): ?><td><?php echo tr_num($s['phone'],'fa'); ?></td><td><?php echo tr_num($s['father_phone'],'fa'); ?></td><td><?php echo tr_num($s['mother_phone'],'fa'); ?></td><?php endif; ?><?php if(yes_field('academic',$fields)): ?><td><?php echo $latest?format_score($latest['gpa']):'---'; ?></td><td><?php echo $latest?clean($latest['term'].'/'.$latest['report_month']):'---'; ?></td><td><?php echo $type==='grades' ? clean(implode(' | ', array_map(fn($g)=>$g['report_month'].'-'.$g['subject_name'].': '.$g['score'], $gradeRows))) : tr_num($failed['c']??0,'fa'); ?></td><?php endif; ?><?php if(yes_field('discipline',$fields)): ?><td><?php echo tr_num(count($discRows),'fa'); ?></td><td><?php echo clean(implode(' | ', array_map(fn($d)=>$d['occurred_at_jalali'].'-'.$d['title_text'], $discRows)) ?: '---'); ?></td><?php endif; ?></tr>
<?php endforeach; ?>
</tbody></table><div class="sign"><span>مسئول آموزش</span><span>معاون/ناظم</span><span>مدیر مدرسه</span></div>
<?php if($format==='html'): ?><script>window.print()</script><?php endif; ?></body></html>
