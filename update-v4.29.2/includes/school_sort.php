<?php
// File: includes/school_sort.php - v4.29.2
/**
 * Unified Persian ordering helpers for grades, classes and students.
 * v4.29.2: added get_year_grade_class_map() for cascading Year->Grade->Class selects.
 */
require_once __DIR__ . '/functions.php';
if (file_exists(__DIR__ . '/class_schedule_sync.php')) require_once __DIR__ . '/class_schedule_sync.php';

if (!function_exists('grade_sort_weight')) {
    function grade_sort_weight($grade) {
        $g = norm_persian_str((string)$grade);
        $map = [
            'اول' => 1, 'دوم' => 2, 'سوم' => 3, 'چهارم' => 4, 'پنجم' => 5, 'ششم' => 6,
            'هفتم' => 7, 'هشتم' => 8, 'نهم' => 9, 'دهم' => 10, 'یازدهم' => 11, 'دوازدهم' => 12,
        ];
        foreach ($map as $k => $v) if (mb_strpos($g, norm_persian_str($k)) !== false) return $v;
        $en = tr_num((string)$grade, 'en');
        if (preg_match('/\d+/', $en, $m)) return (int)$m[0];
        return 99;
    }
}

if (!function_exists('class_number_weight')) {
    function class_number_weight($className) {
        $en = tr_num((string)$className, 'en');
        preg_match_all('/\d+/', $en, $m);
        if (!empty($m[0])) return (int)end($m[0]);
        return 999;
    }
}

if (!function_exists('persian_usort_students')) {
    function persian_usort_students(&$rows) {
        usort($rows, function($a, $b) {
            $la = $a['last_name'] ?? ''; $lb = $b['last_name'] ?? '';
            $cmp = strcmp(norm_persian_str($la), norm_persian_str($lb));
            if ($cmp !== 0) return $cmp;
            return strcmp(norm_persian_str($a['first_name'] ?? ''), norm_persian_str($b['first_name'] ?? ''));
        });
    }
}

if (!function_exists('persian_usort_grades')) {
    function persian_usort_grades(&$rows, $key = 'grade_level') {
        usort($rows, function($a, $b) use ($key) {
            $wa = grade_sort_weight($a[$key] ?? ''); $wb = grade_sort_weight($b[$key] ?? '');
            if ($wa !== $wb) return $wa <=> $wb;
            return strcmp(norm_persian_str($a[$key] ?? ''), norm_persian_str($b[$key] ?? ''));
        });
    }
}

if (!function_exists('persian_usort_classes')) {
    function persian_usort_classes(&$rows, $classKey = 'class_name', $gradeKey = 'grade_level') {
        usort($rows, function($a, $b) use ($classKey, $gradeKey) {
            $ga = $a[$gradeKey] ?? infer_grade_from_class_name($a[$classKey] ?? '');
            $gb = $b[$gradeKey] ?? infer_grade_from_class_name($b[$classKey] ?? '');
            $wg = grade_sort_weight($ga) <=> grade_sort_weight($gb);
            if ($wg !== 0) return $wg;
            $cn = class_number_weight($a[$classKey] ?? '') <=> class_number_weight($b[$classKey] ?? '');
            if ($cn !== 0) return $cn;
            return strcmp(norm_persian_str($a[$classKey] ?? ''), norm_persian_str($b[$classKey] ?? ''));
        });
    }
}

if (!function_exists('get_unified_grade_options')) {
    function get_unified_grade_options($year = '') {
        $params = [];
        $sql = "SELECT DISTINCT grade_level AS grade_level FROM students WHERE grade_level IS NOT NULL AND grade_level<>''";
        $rows = DB::fetchAll($sql, $params);
        if ($year !== '') {
            $scheduleClasses = DB::fetchAll("SELECT DISTINCT class_name FROM class_schedules WHERE academic_year=? AND class_name<>''", [$year]);
            foreach ($scheduleClasses as $c) $rows[] = ['grade_level' => infer_grade_from_class_name($c['class_name'])];
            $classGrades = DB::fetchAll("SELECT DISTINCT grade AS grade_level FROM classes WHERE academic_year=? AND grade<>''", [$year]);
            $rows = array_merge($rows, $classGrades);
        }
        $seen=[]; $out=[];
        foreach ($rows as $r) { $g=trim($r['grade_level']??''); if($g!=='' && !isset($seen[$g])) { $seen[$g]=1; $out[]=['grade_level'=>$g]; } }
        persian_usort_grades($out);
        return $out;
    }
}

if (!function_exists('get_unified_class_options')) {
    function get_unified_class_options($year = '') {
        $rows = DB::fetchAll("SELECT DISTINCT class_name, grade_level FROM students WHERE class_name IS NOT NULL AND class_name<>''");
        if ($year !== '') {
            $rows = array_merge($rows, DB::fetchAll("SELECT DISTINCT name AS class_name, grade AS grade_level FROM classes WHERE academic_year=? AND name<>''", [$year]));
            $sched = DB::fetchAll("SELECT DISTINCT class_name, '' AS grade_level FROM class_schedules WHERE academic_year=? AND class_name<>''", [$year]);
            foreach ($sched as &$s) $s['grade_level'] = infer_grade_from_class_name($s['class_name']);
            $rows = array_merge($rows, $sched);
        }
        $seen=[]; $out=[];
        foreach ($rows as $r) { $c=norm_class_str($r['class_name']??''); if($c!=='' && !isset($seen[$c])) { $seen[$c]=1; $out[]=['class_name'=>$c,'grade_level'=>$r['grade_level'] ?: infer_grade_from_class_name($c)]; } }
        persian_usort_classes($out);
        return $out;
    }
}

if (!function_exists('get_year_grade_class_map')) {
    /**
     * v4.29.2: Build a map of academic years => grades => classes for cascading selects.
     * Structure: [ '1404/1405' => ['grades'=>['هفتم',...], 'classes'=>['هفتم'=>['هفتم1','هفتم2'], ...]], ... ]
     * Sources: students, classes and class_schedules tables (same logic as students.php transfer modal).
     */
    function get_year_grade_class_map($yearOptions = null) {
        if ($yearOptions === null && function_exists('get_academic_years_for_filter')) {
            $yearOptions = get_academic_years_for_filter();
        }
        $map = [];
        foreach ((array)$yearOptions as $yo) {
            $yy = is_array($yo) ? ($yo['academic_year'] ?? '') : (string)$yo;
            if ($yy === '' || isset($map[$yy])) continue;
            $map[$yy] = ['grades' => [], 'classes' => []];
            try {
                $yearRows = DB::fetchAll(
                    "SELECT grade_level, class_name FROM students WHERE academic_year=? AND class_name<>'' " .
                    "UNION SELECT grade AS grade_level, name AS class_name FROM classes WHERE academic_year=? AND name<>'' " .
                    "UNION SELECT '' AS grade_level, class_name FROM class_schedules WHERE academic_year=? AND class_name<>''",
                    [$yy, $yy, $yy]
                );
            } catch (Exception $e) { $yearRows = []; }
            foreach ($yearRows as $yr) {
                $className = norm_class_str($yr['class_name'] ?? '');
                if ($className === '') continue;
                $g = trim($yr['grade_level'] ?? '') ?: infer_grade_from_class_name($className);
                if ($g === '') continue;
                if (!in_array($g, $map[$yy]['grades'], true)) $map[$yy]['grades'][] = $g;
                if (!isset($map[$yy]['classes'][$g])) $map[$yy]['classes'][$g] = [];
                if (!in_array($className, $map[$yy]['classes'][$g], true)) $map[$yy]['classes'][$g][] = $className;
            }
            usort($map[$yy]['grades'], fn($a,$b)=>grade_sort_weight($a)<=>grade_sort_weight($b));
            foreach ($map[$yy]['classes'] as &$clist) {
                usort($clist, fn($a,$b)=>(grade_sort_weight(infer_grade_from_class_name($a))<=>grade_sort_weight(infer_grade_from_class_name($b))) ?: (class_number_weight($a)<=>class_number_weight($b)) ?: strcmp(norm_persian_str($a), norm_persian_str($b)));
            }
            unset($clist);
        }
        return $map;
    }
}
