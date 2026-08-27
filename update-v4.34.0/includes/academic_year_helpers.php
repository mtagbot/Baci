<?php
// File: includes/academic_year_helpers.php
/**
 * Academic Year Unified Format Helper
 * Unified format: YYYY/YYYY with slash, e.g., 1405/1406
 * This file ensures all academic year inputs, imports, and operations are normalized to unified format
 */

if (!function_exists('unify_academic_year')) {
    /**
     * Normalize any academic year string to unified format YYYY/YYYY
     * Handles: 1405-1406, 1405 / 1406, 1405_1406, 1405.1406, 1405-06, 1405/06, 1405, سال تحصیلی 1405-1406, etc.
     * Returns unified format like 1405/1406 or empty string if not parseable
     */
    function unify_academic_year($input) {
        if ($input === null) return '';
        $input = trim((string)$input);
        if ($input === '' || $input === '0') return '';

        // Quick return if already unified format YYYY/YYYY
        if (preg_match('/^\s*(1[3-4]\d{2})\/(1[3-4]\d{2})\s*$/', $input, $m)) {
            $y1 = (int)$m[1]; $y2 = (int)$m[2];
            if ($y1 > $y2) { $tmp=$y1; $y1=$y2; $y2=$tmp; }
            return $y1 . '/' . $y2;
        }

        // Normalize separators: en-dash, em-dash, underscore, dot, backslash to dash/slash
        $input = str_replace(['–', '—', '−', '_', '.', '\\', '٫'], ['-', '-', '-', '/', '/', '/', '/'], $input);
        // Remove Persian/Arabic extra text but keep digits and separators
        // Keep only digits, slash, dash, spaces for parsing
        $input = preg_replace('/[^\d\/\-\s]/u', ' ', $input);
        $input = trim(preg_replace('/\s+/', ' ', $input));

        // Pattern 1: YYYY/YYYY or YYYY-YYYY (full 4-digit both)
        if (preg_match('/(1[3-4]\d{2})\s*[\/\-]\s*(1[3-4]\d{2})/', $input, $m)) {
            $y1 = (int)$m[1]; $y2 = (int)$m[2];
            if ($y1 > $y2) { $tmp=$y1; $y1=$y2; $y2=$tmp; }
            // Ensure consecutive academic year (second = first +1), if not, force to first+1 for unification
            // But if years are like 1403 and 1405 (gap), we keep first two as found? For safety, if gap >1, still use first as start and first+1 as end to keep unified
            if (abs($y2 - $y1) !== 1) {
                // If gap is large, still use y1 as start and y1+1 as end to maintain unified format
                // Unless y2 is clearly intended as second year (like 1405 and 1406), then keep
                // We will check if y2 == y1+1, keep, otherwise force y1+1
                if ($y2 !== $y1+1) {
                    // If y2 is far, use y1+1
                    $y2 = $y1+1;
                }
            }
            return $y1 . '/' . $y2;
        }

        // Pattern 2: YYYY/YY or YYYY-YY (second is 2-digit)
        if (preg_match('/(1[3-4]\d{2})\s*[\/\-]\s*(\d{2})\b/', $input, $m)) {
            $y1 = (int)$m[1];
            $y2short = (int)$m[2];
            $century = intval($y1 / 100) * 100; // e.g., 1400
            $y2 = $century + $y2short;
            if ($y2 <= $y1) $y2 += 100; // handle 1399-00 => 1400
            // Force consecutive
            $y2 = $y1 + 1;
            return $y1 . '/' . $y2;
        }

        // Pattern 3: Single YYYY (e.g., 1405) -> 1405/1406
        if (preg_match('/\b(1[3-4]\d{2})\b/', $input, $m)) {
            $y1 = (int)$m[1];
            return $y1 . '/' . ($y1+1);
        }

        // Pattern 4: Find any two 4-digit years in text
        if (preg_match_all('/(1[3-4]\d{2})/', $input, $all)) {
            $years = array_map('intval', $all[1]);
            $years = array_unique($years);
            sort($years);
            if (count($years) >= 2) {
                $y1 = $years[0]; $y2 = $years[1];
                if ($y1 > $y2) { $tmp=$y1; $y1=$y2; $y2=$tmp; }
                return $y1 . '/' . $y2;
            } elseif (count($years) == 1) {
                $y1 = $years[0];
                return $y1 . '/' . ($y1+1);
            }
        }

        // Pattern 5: Try to extract from format like 140506 (6 digits?) - e.g., 140506 means 1405/1406?
        if (preg_match('/\b(1[3-4])(\d{2})(1[3-4])(\d{2})\b/', $input, $m)) {
            $y1 = (int)($m[1].$m[2]);
            $y2 = (int)($m[3].$m[4]);
            if ($y1 > $y2) { $tmp=$y1; $y1=$y2; $y2=$tmp; }
            return $y1 . '/' . $y2;
        }

        return '';
    }
}

if (!function_exists('normalize_academic_year')) {
    // Alias for backward compatibility - now uses unified format
    function normalize_academic_year($year) {
        $unified = unify_academic_year($year);
        return $unified !== '' ? $unified : trim($year);
    }
}

if (!function_exists('get_current_academic_year')) {
    function get_current_academic_year() {
        $year = get_setting('current_academic_year', '1404/1405');
        $unified = unify_academic_year($year);
        return $unified !== '' ? $unified : $year;
    }
}

if (!function_exists('get_all_academic_years_unified')) {
    function get_all_academic_years_unified() {
        // Unified: ONLY from academic_years master table - if year deleted from master, it won't appear anywhere
        try {
            $rows = DB::fetchAll("SELECT year_name as academic_year FROM academic_years WHERE year_name<>'' ORDER BY year_name DESC");
            $unified = [];
            foreach ($rows as $r) {
                $raw = $r['academic_year'] ?? '';
                $u = unify_academic_year($raw);
                if ($u !== '' && !in_array($u, $unified)) $unified[] = $u;
                elseif ($raw !== '' && !in_array($raw, $unified)) $unified[] = $raw; // keep raw if can't unify
            }
            if (empty($unified)) {
                // Fallback only if master table empty - seed from existing data once
                $fallbackRows = DB::fetchAll("SELECT DISTINCT academic_year FROM students WHERE academic_year IS NOT NULL AND academic_year<>'' UNION SELECT DISTINCT academic_year FROM reports WHERE academic_year<>'' UNION SELECT DISTINCT academic_year FROM classes WHERE academic_year<>'' UNION SELECT DISTINCT academic_year FROM class_schedules WHERE academic_year<>'' UNION SELECT DISTINCT academic_year FROM exam_schedules WHERE academic_year<>'' UNION SELECT DISTINCT academic_year FROM online_exams WHERE academic_year<>'' ORDER BY academic_year DESC");
                foreach ($fallbackRows as $r) {
                    $raw = $r['academic_year'] ?? '';
                    $u = unify_academic_year($raw);
                    if ($u !== '' && !in_array($u, $unified)) $unified[] = $u;
                }
                if (empty($unified)) $unified = [get_current_academic_year()];
            }
            return $unified;
        } catch (Exception $e) {
            return [get_current_academic_year()];
        }
    }
}

if (!function_exists('get_academic_years_for_filter')) {
    function get_academic_years_for_filter() {
        // Returns years ONLY from master table academic_years for all filters in system
        // If year deleted from master, it won't appear in any filter - unified system
        try {
            $rows = DB::fetchAll("SELECT year_name as academic_year, is_default, status FROM academic_years WHERE status=1 ORDER BY is_default DESC, year_name DESC");
            $result = [];
            foreach ($rows as $r) {
                $u = unify_academic_year($r['academic_year']);
                $result[] = [
                    'academic_year' => $u ?: $r['academic_year'],
                    'year_name' => $u ?: $r['academic_year'],
                    'is_default' => $r['is_default'] ?? 0,
                    'status' => $r['status'] ?? 1
                ];
            }
            return $result;
        } catch (Exception $e) {
            return [['academic_year'=>get_current_academic_year(), 'year_name'=>get_current_academic_year(), 'is_default'=>1, 'status'=>1]];
        }
    }
}

if (!function_exists('ensure_academic_years_unified_schema')) {
    function ensure_academic_years_unified_schema() {
        try {
            DB::execute("CREATE TABLE IF NOT EXISTS academic_years (id int(11) NOT NULL AUTO_INCREMENT, year_name varchar(20) NOT NULL, is_default tinyint(1) NOT NULL DEFAULT 0, status tinyint(1) NOT NULL DEFAULT 1, created_at datetime DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id), UNIQUE KEY uniq_year (year_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Exception $e) {}

        // Normalize existing academic_years table entries
        try {
            $rows = DB::fetchAll("SELECT id, year_name FROM academic_years");
            foreach ($rows as $r) {
                $unified = unify_academic_year($r['year_name']);
                if ($unified !== '' && $unified !== $r['year_name']) {
                    // Check if unified already exists
                    $exists = DB::fetch("SELECT id FROM academic_years WHERE year_name=?", [$unified]);
                    if ($exists) {
                        // If unified exists, delete old duplicate if not default
                        $isDefault = DB::fetch("SELECT is_default FROM academic_years WHERE id=?", [$r['id']]);
                        if (empty($isDefault['is_default'])) {
                            DB::execute("DELETE FROM academic_years WHERE id=?", [$r['id']]);
                        }
                    } else {
                        DB::execute("UPDATE academic_years SET year_name=? WHERE id=?", [$unified, $r['id']]);
                    }
                }
            }
        } catch (Exception $e) {}

        // Normalize current_academic_year setting
        try {
            $current = get_setting('current_academic_year','');
            if ($current !== '') {
                $unified = unify_academic_year($current);
                if ($unified !== '' && $unified !== $current) {
                    set_setting('current_academic_year', $unified);
                }
            }
        } catch (Exception $e) {}

        // Fix teachers unique key: should be (national_id, academic_year) not just national_id, to allow same teacher in multiple years
        try { DB::execute("ALTER TABLE teachers DROP INDEX national_id"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE teachers DROP INDEX `national_id`"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE teachers DROP KEY national_id"); } catch (Exception $e) {}
        // Try to add composite unique if not exists
        try { DB::execute("ALTER TABLE teachers ADD UNIQUE KEY uniq_nid_year (national_id, academic_year)"); } catch (Exception $e) {}
        // Also ensure academic_year column exists
        try { DB::execute("ALTER TABLE teachers ADD COLUMN academic_year varchar(20) DEFAULT NULL"); } catch (Exception $e) {}

        // Ensure new student profile columns for comprehensive file (7reporte.csv) - v4.29.0
        $newStudentCols = [
            "postal_code varchar(20) DEFAULT NULL",
            "home_address text DEFAULT NULL",
            "home_phone varchar(30) DEFAULT NULL",
            "religion_title varchar(150) DEFAULT NULL",
            "nationality_title varchar(150) DEFAULT NULL",
            "is_foreign tinyint(1) DEFAULT 0",
            "cover_title varchar(150) DEFAULT NULL",
            "specific_disease_title text DEFAULT NULL",
            "has_sport_limitation tinyint(1) DEFAULT 0",
            "sport_limitation_desc varchar(250) DEFAULT NULL",
            "housing_title varchar(150) DEFAULT NULL",
            "mother_qualification varchar(150) DEFAULT NULL",
            "mother_job varchar(200) DEFAULT NULL",
            "father_qualification varchar(150) DEFAULT NULL",
            "father_job varchar(200) DEFAULT NULL",
            "place_issued varchar(150) DEFAULT NULL",
            "birth_place varchar(150) DEFAULT NULL",
            "birth_year int(11) DEFAULT NULL",
            "birth_month int(11) DEFAULT NULL",
            "birth_day int(11) DEFAULT NULL",
            "mother_name varchar(200) DEFAULT NULL",
            "mother_first_name varchar(150) DEFAULT NULL",
            "mother_last_name varchar(150) DEFAULT NULL",
            "mother_deceased tinyint(1) DEFAULT 0",
            "father_deceased tinyint(1) DEFAULT 0",
            "father_guardian tinyint(1) DEFAULT 0",
            "mother_guardian tinyint(1) DEFAULT 0",
            "last_year_average varchar(20) DEFAULT NULL",
            "student_mobile varchar(30) DEFAULT NULL",
            "is_sport_limited tinyint(1) DEFAULT 0",
        ];
        foreach ($newStudentCols as $colDef) {
            try {
                $colName = trim(explode(' ', $colDef)[0]);
                DB::execute("ALTER TABLE students ADD COLUMN $colDef");
            } catch (Exception $e) {}
        }

        // Normalize all tables with academic_year column
        $tablesWithYear = [
            'students' => 'academic_year',
            'reports' => 'academic_year',
            'classes' => 'academic_year',
            'class_schedules' => 'academic_year',
            'exam_schedules' => 'academic_year',
            'exam_student_seating' => 'academic_year',
            'online_exams' => 'academic_year',
            'online_question_categories' => 'academic_year',
            'teachers' => 'academic_year',
            'grade_entry_permissions' => 'academic_year',
            'report_locks' => 'academic_year',
        ];

        foreach ($tablesWithYear as $table => $col) {
            try {
                $rows = DB::fetchAll("SELECT id, `$col` as ay FROM `$table` WHERE `$col` IS NOT NULL AND `$col`<>'' LIMIT 1000");
                foreach ($rows as $r) {
                    $raw = $r['ay'] ?? '';
                    $unified = unify_academic_year($raw);
                    if ($unified !== '' && $unified !== $raw) {
                        DB::execute("UPDATE `$table` SET `$col`=? WHERE id=?", [$unified, $r['id']]);
                    }
                }
            } catch (Exception $e) {
                // Table may not exist or column not exist, ignore
            }
        }
    }
}
?>
