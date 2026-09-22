<?php
// File: discipline-bulk-report.php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/school_roles.php';
require_once __DIR__ . '/includes/school_sort.php';
ensure_school_roles_schema();
if (!(is_admin_logged_in() && has_permission('manage_students')) && !(is_teacher_logged_in() && teacher_has_deputy($_SESSION['teacher_id']))) die('غیرمجاز');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? '')) die('درخواست نامعتبر');
$ids = array_values(array_filter(array_map('intval', $_POST['student_ids'] ?? [])));
$type = $_POST['report_type'] ?? 'full'; $from=trim($_POST['from_jalali']??''); $to=trim($_POST['to_jalali']??''); $titleFilter=trim($_POST['title_text']??''); $justFilter=trim($_POST['justified_filter']??''); $reviewFilter=trim($_POST['review_filter']??'');
if (!$ids) die('دانش‌آموزی انتخاب نشده است.');
$ph=implode(',',array_fill(0,count($ids),'?'));
$students=DB::fetchAll("SELECT * FROM students WHERE id IN ($ph)",$ids);
persian_usort_students($students);
header('Content-Type: application/vnd.ms-excel; charset=utf-8'); header('Content-Disposition: attachment; filename=discipline-report.xls');
?><!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><style>body{font-family:Tahoma}table{width:100%;border-collapse:collapse}th,td{border:1px solid #333;padding:6px}th{background:#eee}.head{text-align:center;border:2px solid #000;margin-bottom:12px;padding:10px}</style></head><body><div class="head"><h2><?php echo clean(get_setting('school_name','آموزشگاه')); ?></h2><h3>گزارش انضباطی دانش‌آموزان</h3><p>تاریخ گزارش: <?php echo tr_num(jdate('Y/m/d H:i'),'fa'); ?></p></div><table><thead><tr><th>ردیف</th><th>دانش‌آموز</th><th>کلاس</th><th>تعداد کل موارد</th><th>موجه</th><th>بررسی‌شده</th><th>آخرین مورد</th><th>جزئیات</th></tr></thead><tbody><?php foreach($students as $i=>$s):
$params=[$s['id']]; $w=['student_id=?']; if($from){$w[]='occurred_at_jalali>=?';$params[]=$from;} if($to){$w[]='occurred_at_jalali<=?';$params[]=$to;} if($titleFilter){$w[]='title_text LIKE ?';$params[]='%'.$titleFilter.'%';} if($justFilter==='justified'){$w[]='is_justified=1';} if($justFilter==='unjustified'){$w[]='is_justified=0';} if($reviewFilter){$w[]='review_status=?';$params[]=$reviewFilter;}
$recs=DB::fetchAll('SELECT * FROM student_discipline_records WHERE '.implode(' AND ',$w).' ORDER BY occurred_at_jalali DESC',$params);
$details=[]; $just=0; $rev=0; foreach($recs as $r){ if(!empty($r['is_justified']))$just++; if(($r['review_status']??'')==='reviewed')$rev++; $details[]=$r['occurred_at_jalali'].' - '.$r['title_text'].' - '.(!empty($r['is_justified'])?'موجه':'ناموجه').' - '.($r['review_status']??'pending'); }
?><tr><td><?php echo tr_num($i+1,'fa'); ?></td><td><?php echo clean($s['last_name'].'، '.$s['first_name']); ?></td><td><?php echo clean($s['class_name']); ?></td><td><?php echo tr_num(count($recs),'fa'); ?></td><td><?php echo tr_num($just,'fa'); ?></td><td><?php echo tr_num($rev,'fa'); ?></td><td><?php echo tr_num($recs[0]['occurred_at_jalali']??'---','fa'); ?></td><td><?php echo clean(implode(' | ',$details)); ?></td></tr><?php endforeach; ?></tbody></table></body></html>
