<?php
// File: includes/exams_helper.php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/school_sort.php';

if (!function_exists('jalali_now')) {
    function jalali_now() { return jdate('Y/m/d H:i'); }
}

if (!function_exists('ensure_exams_schema')) {
    function ensure_exams_schema() {
        DB::execute("CREATE TABLE IF NOT EXISTS exam_schedules (
            id int(11) NOT NULL AUTO_INCREMENT,
            academic_year varchar(20) NOT NULL,
            exam_month varchar(50) DEFAULT NULL,
            grade_level varchar(50) DEFAULT NULL,
            class_name varchar(100) DEFAULT NULL,
            subject_name varchar(150) NOT NULL,
            teacher_id int(11) DEFAULT NULL,
            teacher_name varchar(150) DEFAULT NULL,
            exam_date_jalali varchar(20) NOT NULL,
            exam_day_name varchar(30) DEFAULT NULL,
            start_time varchar(10) NOT NULL,
            duration_minutes int(11) NOT NULL DEFAULT 90,
            exam_room_default varchar(100) DEFAULT NULL,
            question_file varchar(255) DEFAULT NULL,
            header_config text DEFAULT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at_jalali varchar(30) NOT NULL,
            PRIMARY KEY(id), KEY idx_year_class (academic_year,class_name), KEY idx_date (exam_date_jalali)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        try { DB::execute("ALTER TABLE exam_schedules ADD COLUMN exam_month varchar(50) DEFAULT NULL"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE exam_schedules ADD COLUMN exam_day_name varchar(30) DEFAULT NULL"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE exam_schedules ADD COLUMN is_active tinyint(1) NOT NULL DEFAULT 1"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE exam_schedules ADD COLUMN is_printed tinyint(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE exam_schedules ADD COLUMN exam_kind varchar(30) NOT NULL DEFAULT 'official'"); } catch (Exception $e) {}

        DB::execute("CREATE TABLE IF NOT EXISTS exam_assignments (
            id int(11) NOT NULL AUTO_INCREMENT,
            exam_id int(11) NOT NULL,
            student_id int(11) NOT NULL,
            seat_number varchar(20) DEFAULT NULL,
            exam_room varchar(100) DEFAULT NULL,
            created_at_jalali varchar(30) NOT NULL,
            PRIMARY KEY(id), UNIQUE KEY uniq_exam_student (exam_id, student_id), KEY idx_student (student_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Seating is independent from individual exams; it is assigned for a year/month/student.
        DB::execute("CREATE TABLE IF NOT EXISTS exam_student_seating (
            id int(11) NOT NULL AUTO_INCREMENT,
            academic_year varchar(20) NOT NULL,
            exam_month varchar(50) NOT NULL,
            student_id int(11) NOT NULL,
            seat_number varchar(20) DEFAULT NULL,
            exam_room varchar(100) DEFAULT NULL,
            created_at_jalali varchar(30) NOT NULL,
            PRIMARY KEY(id), UNIQUE KEY uniq_year_month_student (academic_year, exam_month, student_id), KEY idx_student (student_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        DB::execute("CREATE TABLE IF NOT EXISTS exam_designs (
            id int(11) NOT NULL AUTO_INCREMENT,
            exam_id int(11) NOT NULL,
            design_json longtext NOT NULL,
            designer_teacher_id int(11) DEFAULT NULL,
            designer_name varchar(150) DEFAULT NULL,
            saved_by_admin_id int(11) DEFAULT NULL,
            updated_at_jalali varchar(30) NOT NULL,
            PRIMARY KEY(id), UNIQUE KEY uniq_exam (exam_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        DB::execute("CREATE TABLE IF NOT EXISTS exam_question_bank (
            id int(11) NOT NULL AUTO_INCREMENT,
            source_exam_id int(11) DEFAULT NULL,
            academic_year varchar(20) DEFAULT NULL,
            exam_month varchar(50) DEFAULT NULL,
            subject_name varchar(150) NOT NULL,
            teacher_id int(11) DEFAULT NULL,
            designer_name varchar(150) DEFAULT NULL,
            question_type varchar(30) DEFAULT 'text',
            question_html longtext NOT NULL,
            score varchar(20) DEFAULT NULL,
            meta_json text DEFAULT NULL,
            created_at_jalali varchar(30) NOT NULL,
            updated_at_jalali varchar(30) DEFAULT NULL,
            PRIMARY KEY(id), KEY idx_subject (subject_name), KEY idx_year_month (academic_year, exam_month), KEY idx_teacher (teacher_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach (['exam_header_top'=>'25','exam_header_height'=>'155','exam_header_logo_x'=>'20','exam_header_logo_y'=>'20','exam_header_font_size'=>'12'] as $k=>$v) if (get_setting($k,'')==='') set_setting($k,$v);
    }
}

if (!function_exists('exam_can_design')) {
    function exam_can_design($examId) {
        if (is_admin_logged_in()) return true;
        if (!empty($_SESSION['teacher_id'])) {
            $exam = DB::fetch("SELECT teacher_id FROM exam_schedules WHERE id=?", [(int)$examId]);
            return $exam && (int)$exam['teacher_id'] === (int)$_SESSION['teacher_id'];
        }
        return false;
    }
}
if (!function_exists('exam_student_schedule')) {
    function exam_student_schedule($studentId) {
        ensure_exams_schema();
        return DB::fetchAll("SELECT es.*, COALESCE(seat.seat_number, ea.seat_number) AS seat_number, COALESCE(seat.exam_room, ea.exam_room, es.exam_room_default) AS exam_room, COALESCE(t.full_name, es.teacher_name) AS t_name FROM exam_schedules es LEFT JOIN exam_assignments ea ON ea.exam_id=es.id AND ea.student_id=? LEFT JOIN teachers t ON t.id=es.teacher_id JOIN students s ON s.id=? LEFT JOIN exam_student_seating seat ON seat.id=(SELECT MAX(id) FROM exam_student_seating WHERE student_id=s.id AND academic_year=es.academic_year) WHERE es.is_active=1 AND (es.class_name=s.class_name OR (COALESCE(es.class_name,'')='' AND es.grade_level=s.grade_level) OR ea.student_id=s.id) ORDER BY es.exam_date_jalali ASC, es.start_time ASC", [$studentId,$studentId]);
    }
}
if (!function_exists('exam_subjects_for_grade')) {
    function exam_subjects_for_grade($year, $grade) {
        $all = get_schedule_subjects_for_year($year);
        $out=[]; $seen=[];
        foreach ($all as $r) {
            $g = infer_grade_from_class_name($r['class_name']);
            if ($grade !== '' && $g !== $grade) continue;
            $key = $r['subject_name'].'|'.($r['teacher_id']??'');
            if (!isset($seen[$key])) { $seen[$key]=1; $out[]=$r; }
        }
        usort($out, fn($a,$b)=>strcmp(norm_persian_str($a['subject_name']), norm_persian_str($b['subject_name'])));
        return $out;
    }
}

if (!function_exists('get_student_global_seat')) {
    /* v4.72.0: شماره صندلی «سراسری» — مستقل از ماه امتحانی. جدید‌ترین رکورد سال. */
    function get_student_global_seat($year, $studentId) {
        return DB::fetch("SELECT * FROM exam_student_seating WHERE academic_year=? AND student_id=? ORDER BY id DESC LIMIT 1", [$year, (int)$studentId]);
    }
}

