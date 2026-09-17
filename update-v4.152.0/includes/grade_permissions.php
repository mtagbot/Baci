<?php
// File: includes/grade_permissions.php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/school_sort.php';

if (!function_exists('ensure_grade_permissions_schema')) {
    function ensure_grade_permissions_schema() {
        DB::execute("CREATE TABLE IF NOT EXISTS grade_entry_permissions (
            id int(11) NOT NULL AUTO_INCREMENT,
            academic_year varchar(20) NOT NULL,
            report_month varchar(50) DEFAULT NULL,
            grade_level varchar(50) DEFAULT NULL,
            class_name varchar(100) DEFAULT NULL,
            subject_name varchar(150) DEFAULT NULL,
            teacher_id int(11) DEFAULT NULL,
            can_enter tinyint(1) NOT NULL DEFAULT 1,
            can_edit tinyint(1) NOT NULL DEFAULT 1,
            note varchar(255) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id), KEY idx_scope (academic_year, report_month, class_name, subject_name, teacher_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('can_teacher_enter_grade')) {
    function can_teacher_enter_grade($teacherId, $year, $month, $className, $subjectName) {
        ensure_grade_permissions_schema();
        $studentGrade = infer_grade_from_class_name($className);
        $rules = DB::fetchAll("SELECT * FROM grade_entry_permissions WHERE academic_year=? AND (report_month IS NULL OR report_month='' OR report_month=?) AND (teacher_id IS NULL OR teacher_id=0 OR teacher_id=?) AND (class_name IS NULL OR class_name='' OR class_name=?) AND (subject_name IS NULL OR subject_name='' OR subject_name=?) AND (grade_level IS NULL OR grade_level='' OR grade_level=?) ORDER BY id DESC", [$year,$month,(int)$teacherId,$className,$subjectName,$studentGrade]);
        foreach ($rules as $r) {
            if ((int)$r['can_enter'] === 0 || (int)$r['can_edit'] === 0) return false;
        }
        return true;
    }
}
