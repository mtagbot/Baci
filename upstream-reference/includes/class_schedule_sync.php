<?php
// File: includes/class_schedule_sync.php
/**
 * Synchronizes classes/subjects views with imported weekly schedules.
 * The weekly schedule is treated as an authoritative source for the selected academic year.
 */
require_once __DIR__ . '/functions.php';

if (!function_exists('infer_grade_from_class_name')) {
    function infer_grade_from_class_name($className) {
        $name = (string)$className;
        foreach (['هفتم', 'هشتم', 'نهم', 'دهم', 'یازدهم', 'دوازدهم'] as $grade) {
            if (mb_strpos($name, $grade) !== false) return $grade;
        }
        if (preg_match('/\b(7|۷)\b/u', $name)) return 'هفتم';
        if (preg_match('/\b(8|۸)\b/u', $name)) return 'هشتم';
        if (preg_match('/\b(9|۹)\b/u', $name)) return 'نهم';
        return '';
    }
}

if (!function_exists('sync_schedule_to_classes_subjects')) {
    function sync_schedule_to_classes_subjects($academicYear) {
        $academicYear = trim((string)$academicYear);
        if ($academicYear === '') return ['classes' => 0, 'subjects' => 0];
        $createdClasses = 0;
        $createdSubjects = 0;

        // Create missing class records from schedule rows so all tabs share the same year/class universe.
        $schedClasses = DB::fetchAll("SELECT class_name, COUNT(*) AS lesson_count FROM class_schedules WHERE academic_year = ? AND class_name IS NOT NULL AND class_name <> '' GROUP BY class_name ORDER BY class_name", [$academicYear]);
        foreach ($schedClasses as $sc) {
            $className = norm_class_str($sc['class_name']);
            if ($className === '') continue;
            $exists = DB::fetch("SELECT id FROM classes WHERE academic_year = ? AND name = ? LIMIT 1", [$academicYear, $className]);
            if (!$exists) {
                DB::execute("INSERT INTO classes (name, grade, academic_year, teacher_name) VALUES (?, ?, ?, ?)", [$className, infer_grade_from_class_name($className), $academicYear, null]);
                $createdClasses++;
            }
        }

        // Create missing global subject records from schedule subjects, without depending on manual subject form.
        // subjects table has no academic_year/class column; therefore uniqueness is by subject name + teacher_id.
        $schedSubjects = DB::fetchAll("SELECT subject_name, teacher_id, MAX(teacher_name) AS teacher_name, COUNT(*) AS lesson_count FROM class_schedules WHERE academic_year = ? AND subject_name IS NOT NULL AND subject_name <> '' GROUP BY subject_name, teacher_id ORDER BY subject_name", [$academicYear]);
        foreach ($schedSubjects as $ss) {
            $subjectName = trim($ss['subject_name']);
            if ($subjectName === '') continue;
            $teacherId = $ss['teacher_id'] !== null && $ss['teacher_id'] !== '' ? (int)$ss['teacher_id'] : null;
            if ($teacherId) {
                $exists = DB::fetch("SELECT id FROM subjects WHERE name = ? AND teacher_id = ? LIMIT 1", [$subjectName, $teacherId]);
            } else {
                $exists = DB::fetch("SELECT id FROM subjects WHERE name = ? AND teacher_id IS NULL LIMIT 1", [$subjectName]);
            }
            if (!$exists) {
                DB::execute("INSERT INTO subjects (name, code, grade_level, teacher_name, teacher_id, coefficient) VALUES (?, ?, ?, ?, ?, 1)", [
                    $subjectName,
                    'SCH-' . substr(sha1($academicYear . '|' . $subjectName . '|' . (string)$teacherId), 0, 8),
                    'برنامه هفتگی ' . $academicYear,
                    $ss['teacher_name'] ?: '',
                    $teacherId
                ]);
                $createdSubjects++;
            }
        }
        return ['classes' => $createdClasses, 'subjects' => $createdSubjects];
    }
}

if (!function_exists('get_unified_classes_for_year')) {
    function get_unified_classes_for_year($academicYear) {
        return DB::fetchAll("SELECT
                MIN(id) AS id,
                name,
                MAX(grade) AS grade,
                academic_year,
                MAX(teacher_name) AS teacher_name,
                SUM(is_manual) AS manual_count,
                SUM(schedule_count) AS schedule_count
            FROM (
                SELECT id, name, grade, academic_year, teacher_name, 1 AS is_manual, 0 AS schedule_count
                FROM classes WHERE academic_year = ?
                UNION ALL
                SELECT NULL AS id, class_name AS name, '' AS grade, academic_year, NULL AS teacher_name, 0 AS is_manual, COUNT(*) AS schedule_count
                FROM class_schedules WHERE academic_year = ? AND class_name IS NOT NULL AND class_name <> '' GROUP BY class_name, academic_year
            ) u
            GROUP BY name, academic_year
            ORDER BY name ASC", [$academicYear, $academicYear]);
    }
}

if (!function_exists('get_schedule_subjects_for_year')) {
    function get_schedule_subjects_for_year($academicYear) {
        return DB::fetchAll("SELECT
                cs.class_name,
                cs.subject_name,
                cs.teacher_id,
                COALESCE(t.full_name, MAX(cs.teacher_name), '') AS teacher_name,
                COUNT(*) AS lesson_count,
                GROUP_CONCAT(DISTINCT cs.day_of_week ORDER BY FIELD(cs.day_of_week,'شنبه','یک‌شنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه') SEPARATOR '، ') AS days_text
            FROM class_schedules cs
            LEFT JOIN teachers t ON t.id = cs.teacher_id
            WHERE cs.academic_year = ? AND cs.subject_name IS NOT NULL AND cs.subject_name <> ''
            GROUP BY cs.class_name, cs.subject_name, cs.teacher_id, t.full_name
            ORDER BY cs.class_name ASC, cs.subject_name ASC", [$academicYear]);
    }
}
