<?php
/** Class-exam identity: teacher + academic year + class + subject.
 * Existing duplicates are deliberately retained. No schema/data migration.
 */
require_once __DIR__ . '/exams_helper.php';
require_once __DIR__ . '/class_schedule_sync.php';
require_once __DIR__ . '/academic_year_helpers.php';

function class_exam_assignments($teacherId, $year) {
    $rows = DB::fetchAll('SELECT DISTINCT class_name,subject_name,academic_year FROM class_schedules WHERE teacher_id=? AND academic_year=?',[(int)$teacherId,$year]);
    $grades = [];
    foreach (DB::fetchAll('SELECT name AS class_name,grade AS grade_level FROM classes WHERE academic_year=? UNION ALL SELECT DISTINCT class_name,grade_level FROM students WHERE academic_year=?',[$year,$year]) as $c) {
        $key = norm_class_str($c['class_name']);
        if (!isset($grades[$key]) && !empty($c['grade_level'])) $grades[$key] = $c['grade_level'];
    }
    foreach ($rows as &$r) {
        $r['grade_level'] = $grades[norm_class_str($r['class_name'])] ?? infer_grade_from_class_name($r['class_name']);
    }
    unset($r);
    return $rows;
}
function class_exam_existing($teacherId, $year, $class, $subject) {
    $rows = DB::fetchAll("SELECT * FROM exam_schedules WHERE exam_kind='class' AND teacher_id=? AND academic_year=? AND subject_name=? ORDER BY id DESC",[(int)$teacherId,$year,$subject]);
    return array_values(array_filter($rows,function($r) use($class){return norm_class_str($r['class_name']) === norm_class_str($class);}));
}
function class_exam_grade_classes($exam) {
    $grade = trim($exam['grade_level'] ?? '') ?: infer_grade_from_class_name($exam['class_name']);
    if ($grade === '') return [];
    $classes = [];
    foreach (class_exam_assignments($exam['teacher_id'],$exam['academic_year']) as $as) {
        if ($as['subject_name'] === $exam['subject_name'] && $as['grade_level'] === $grade) $classes[] = $as['class_name'];
    }
    // Preserve imported class-name spelling without excluding normalized student records.
    $normalized = array_map('norm_class_str',$classes);
    list($yearSql,$yearParams) = exam_year_students_sql($exam['academic_year']);
    foreach (DB::fetchAll("SELECT DISTINCT s.class_name FROM students s WHERE s.status='active' AND $yearSql",$yearParams) as $studentClass) {
        if (in_array(norm_class_str($studentClass['class_name']),$normalized,true)) $classes[] = $studentClass['class_name'];
    }
    return array_values(array_unique($classes));
}
function class_exam_get_or_create($teacherId, $year, $class, $subject) {
    // Schema setup must be done by the caller BEFORE taking this transaction.
    $pdo = DB::getInstance()->getPdo();
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    if ($sqlite) $pdo->exec('BEGIN IMMEDIATE'); else $pdo->beginTransaction();
    try {
        // MySQL: serialize all creates by this teacher even when no exam exists yet.
        $teacher = DB::fetch('SELECT id,full_name FROM teachers WHERE id=?'.($sqlite ? '' : ' FOR UPDATE'),[(int)$teacherId]);
        if (!$teacher) throw new RuntimeException('دبیر یافت نشد.');
        $existing = class_exam_existing($teacherId,$year,$class,$subject);
        if ($existing) $id = (int)$existing[0]['id'];
        else {
            $assignment = null;
            foreach (class_exam_assignments($teacherId,$year) as $as) if (norm_class_str($as['class_name']) === norm_class_str($class) && $as['subject_name'] === $subject) $assignment = $as;
            if (!$assignment) throw new RuntimeException('این درس و کلاس در این سال به شما تخصیص ندارد.');
            DB::execute('INSERT INTO exam_schedules (academic_year,exam_month,grade_level,class_name,subject_name,teacher_id,teacher_name,exam_date_jalali,exam_day_name,start_time,duration_minutes,exam_room_default,question_file,header_config,is_active,exam_kind,created_at_jalali) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[
                $year,'آزمون کلاسی',$assignment['grade_level'],$assignment['class_name'],$subject,(int)$teacherId,$teacher['full_name'],'','','',45,'',null,'{}',1,'class',jdate('Y/m/d H:i')
            ]);
            $id = (int)DB::lastInsertId();
        }
        if ($sqlite) $pdo->exec('COMMIT'); else $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($sqlite) $pdo->exec('ROLLBACK'); elseif ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
