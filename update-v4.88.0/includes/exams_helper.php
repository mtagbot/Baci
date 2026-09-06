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
if (!function_exists('exam_year_students_sql')) {
    /**
     * v4.78.0: exam printing must target ONLY students active in the exam's
     * OWN academic year. Returns [sqlFragment, params] to append to a query
     * over `students <alias>`. Same year-matching logic as students.php /
     * attendance (direct match, or legacy NULL-year students attached to
     * that year through reports/classes/schedules).
     */
    function exam_year_students_sql($examYear, $alias = 's') {
        $y = trim((string)$examYear);
        if (function_exists('unify_academic_year')) { $u = unify_academic_year($y); if ($u !== '') $y = $u; }
        if ($y === '' && function_exists('get_current_academic_year')) $y = get_current_academic_year();
        if ($y === '') return ['1=1', []];
        $a = $alias;
        $sql = "($a.academic_year=? OR (($a.academic_year IS NULL OR $a.academic_year='') AND ("
             . "EXISTS(SELECT 1 FROM reports exyr WHERE exyr.student_id=$a.id AND exyr.academic_year=?)"
             . " OR EXISTS(SELECT 1 FROM class_schedules exycs WHERE exycs.academic_year=? AND exycs.class_name=$a.class_name)"
             . " OR EXISTS(SELECT 1 FROM classes exyc WHERE exyc.academic_year=? AND exyc.name=$a.class_name))))";
        return [$sql, [$y, $y, $y, $y]];
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
        usort($out, fn($a,$b)=>persian_compare($a['subject_name'], $b['subject_name']));
        return $out;
    }
}

if (!function_exists('get_student_global_seat')) {
    /* v4.72.0: شماره صندلی «سراسری» — مستقل از ماه امتحانی. جدید‌ترین رکورد سال. */
    function get_student_global_seat($year, $studentId) {
        return DB::fetch("SELECT * FROM exam_student_seating WHERE academic_year=? AND student_id=? ORDER BY id DESC LIMIT 1", [$year, (int)$studentId]);
    }
}

if (!function_exists('exam_replicate_design_to_grade_siblings')) {
    /**
     * v4.88.0 — grade-wide designs must exist for EVERY class of the grade.
     * When a design is saved on the exam of one class, copy it (plus the
     * source question file and header config) to the sibling exams of the
     * same grade+month+subject that have no NEWER design of their own.
     * Returns the number of sibling exams updated.
     */
    function exam_replicate_design_to_grade_siblings($examId) {
        $examId = (int)$examId;
        $exam = DB::fetch("SELECT * FROM exam_schedules WHERE id=?", [$examId]);
        $design = DB::fetch("SELECT * FROM exam_designs WHERE exam_id=?", [$examId]);
        if (!$exam || !$design) return 0;
        if ((string)($exam['exam_kind'] ?? 'official') === 'class') return 0;   // class exams stay per-class
        $grade = trim((string)($exam['grade_level'] ?? ''));
        if ($grade === '' && function_exists('infer_grade_from_class_name')) $grade = (string)infer_grade_from_class_name($exam['class_name'] ?? '');
        if ($grade === '') return 0;
        $siblings = DB::fetchAll(
            "SELECT id FROM exam_schedules WHERE id<>? AND academic_year=? AND exam_month=? AND subject_name=? AND is_active=1 AND COALESCE(exam_kind,'official')<>'class' AND (grade_level=? OR class_name LIKE ?)",
            [$examId, $exam['academic_year'], $exam['exam_month'], $exam['subject_name'], $grade, $grade . '%']
        );
        $copied = 0;
        foreach ($siblings as $sib) {
            $dstId = (int)$sib['id'];
            $ex = DB::fetch("SELECT id, updated_at_jalali FROM exam_designs WHERE exam_id=?", [$dstId]);
            // Never clobber a design that someone saved for that class MORE RECENTLY,
            // and skip siblings that are already in sync (same timestamp) so repeated
            // runs (page-load backfills) stay cheap and idempotent.
            if ($ex && strcmp((string)$ex['updated_at_jalali'], (string)$design['updated_at_jalali']) >= 0) continue;
            if ($ex) DB::execute("UPDATE exam_designs SET design_json=?, designer_teacher_id=?, designer_name=?, saved_by_admin_id=?, updated_at_jalali=? WHERE exam_id=?",
                [$design['design_json'], $design['designer_teacher_id'], $design['designer_name'], $design['saved_by_admin_id'], $design['updated_at_jalali'], $dstId]);
            else DB::execute("INSERT INTO exam_designs (exam_id, design_json, designer_teacher_id, designer_name, saved_by_admin_id, updated_at_jalali) VALUES (?,?,?,?,?,?)",
                [$dstId, $design['design_json'], $design['designer_teacher_id'], $design['designer_name'], $design['saved_by_admin_id'], $design['updated_at_jalali']]);
            // The design references the SOURCE exam's uploaded file/pages: keep the
            // sibling exam pointing at the same question file + header config.
            DB::execute("UPDATE exam_schedules SET question_file=?, header_config=? WHERE id=?", [$exam['question_file'], $exam['header_config'], $dstId]);
            // share the converted page cache so the sibling never re-converts
            $srcCache = __DIR__ . '/../uploads/exams/pdf-pages/exam_' . $examId;
            $dstCache = __DIR__ . '/../uploads/exams/pdf-pages/exam_' . $dstId;
            if (is_dir($srcCache)) {
                @mkdir($dstCache, 0775, true);
                foreach (glob($dstCache . '/page_*.jpg') ?: [] as $old) @unlink($old);
                foreach (glob($srcCache . '/page_*.jpg') ?: [] as $pf) @copy($pf, $dstCache . '/' . basename($pf));
                if (is_file($srcCache . '/pages.txt')) @copy($srcCache . '/pages.txt', $dstCache . '/pages.txt');
            }
            $copied++;
        }
        return $copied;
    }
}
