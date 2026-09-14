<?php
// File: export-students.php
/** Bulk Student Profiles Exporter with academic year/grade/class filters */
require_once __DIR__ . '/includes/auth.php';
require_permission('manage_students');
$year = trim($_GET['academic_year'] ?? $_GET['year'] ?? '');
$grade = trim($_GET['grade_level'] ?? '');
$class = trim($_GET['class_name'] ?? '');
$where=['1=1']; $params=[];
if($year !== '' && $year !== 'all'){ $where[]='academic_year=?'; $params[]=$year; }
if($grade !== ''){ $where[]='grade_level=?'; $params[]=$grade; }
if($class !== ''){ $where[]='class_name=?'; $params[]=$class; }
$students = DB::fetchAll('SELECT * FROM students WHERE '.implode(' AND ',$where).' ORDER BY academic_year DESC, class_name ASC, last_name ASC, first_name ASC', $params);
$filename = "Students_Export_" . ($year ?: 'all') . '_' . jdate('Y-m-d') . ".csv";
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache'); header('Expires: 0');
$output = fopen('php://output', 'w'); fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
fputcsv($output, ['academic_year','grade_level','Datastudents_motherMobile','Datastudents_fatherMobile','Datastudents_birthYear','Datastudents_birthMonth','Datastudents_birthDay','Datastudents_nationalCode','Datastudents_serial','Datastudents_className','Datastudents_firstName','Datastudents_lastName','Datastudents_code','Status']);
foreach ($students as $st) {
    $bparts = explode('/', $st['birth_date'] ?? '');
    fputcsv($output, [$st['academic_year'] ?? '', $st['grade_level'] ?? '', $st['mother_phone'] ?? '', $st['father_phone'] ?? $st['phone'] ?? '', $bparts[0] ?? '', $bparts[1] ?? '', $bparts[2] ?? '', $st['national_id'], $st['serial_number'] ?? '', $st['class_name'], $st['first_name'], $st['last_name'], $st['student_code'] ?? '', $st['status']]);
}
fclose($output); exit;
