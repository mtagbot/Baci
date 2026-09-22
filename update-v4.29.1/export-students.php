<?php
// File: export-students.php - v4.29.1 Comprehensive export (7reporte.csv 31-column format)
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

// v4.29.1: Full comprehensive format compatible with 7reporte.csv (re-importable) + extra admin columns
fputcsv($output, [
    'academic_year','grade_level','Status',
    'Datastudents_lastYearAverage','Datastudents_password','Datastudents_code',
    'Datastudents_postalCode','Datastudents_homeAddress','Datastudents_homePhone',
    'Datastudents_religionTitle','Datastudents_nationalityTitle','Datastudents_coverTitle',
    'Datastudents_specificDiseaseExactTitle','Datastudents_housingTitle',
    'Datastudents_motherMobile','Datastudents_motherQualification','Datastudents_motherJobExactTitle',
    'Datastudents_fatherMobile','Datastudents_fatherQualification','Datastudents_fatherJobExactTitle',
    'Datastudents_placeIssued','Datastudents_birthPlace',
    'Datastudents_birthYear','Datastudents_birthMonth','Datastudents_birthDay',
    'Datastudents_motherName','Datastudents_mobile','Datastudents_fatherName',
    'Datastudents_nationalCode','Datastudents_serial','Datastudents_className',
    'Datastudents_firstName','Datastudents_lastName',
    'is_foreign','has_sport_limitation','mother_deceased','father_deceased','mother_last_name'
]);
foreach ($students as $st) {
    // Prefer dedicated numeric birth columns; fall back to parsing birth_date
    $bYear = $st['birth_year'] ?? '';
    $bMonth = $st['birth_month'] ?? '';
    $bDay = $st['birth_day'] ?? '';
    if (!$bYear && !empty($st['birth_date'])) {
        $bparts = explode('/', $st['birth_date']);
        $bYear = $bparts[0] ?? ''; $bMonth = $bparts[1] ?? ''; $bDay = $bparts[2] ?? '';
    }
    fputcsv($output, [
        $st['academic_year'] ?? '',
        $st['grade_level'] ?? '',
        $st['status'] ?? '',
        $st['last_year_average'] ?? '',
        '', // password never exported (hashed)
        $st['student_code'] ?? '',
        $st['postal_code'] ?? '',
        $st['home_address'] ?? '',
        $st['home_phone'] ?? '',
        $st['religion_title'] ?? '',
        $st['nationality_title'] ?? '',
        $st['cover_title'] ?? '',
        $st['specific_disease_title'] ?? '',
        $st['housing_title'] ?? '',
        $st['mother_phone'] ?? '',
        $st['mother_qualification'] ?? '',
        $st['mother_job'] ?? '',
        $st['father_phone'] ?? ($st['phone'] ?? ''),
        $st['father_qualification'] ?? '',
        $st['father_job'] ?? '',
        $st['place_issued'] ?? '',
        $st['birth_place'] ?? '',
        $bYear, $bMonth, $bDay,
        $st['mother_name'] ?? '',
        $st['student_mobile'] ?? '',
        $st['father_name'] ?? '',
        $st['national_id'] ?? '',
        $st['serial_number'] ?? '',
        $st['class_name'] ?? '',
        $st['first_name'] ?? '',
        $st['last_name'] ?? '',
        !empty($st['is_foreign']) ? '1' : '0',
        !empty($st['has_sport_limitation']) ? '1' : '0',
        !empty($st['mother_deceased']) ? '1' : '0',
        !empty($st['father_deceased']) ? '1' : '0',
        $st['mother_last_name'] ?? '',
    ]);
}
fclose($output); exit;
