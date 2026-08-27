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

if (!function_exists('get_master_academic_years_list')) {
    /**
     * v4.38.0 - Single source of truth: plain array of unified year strings
     * taken ONLY from the master table academic_years (default first, newest next).
     */
    function get_master_academic_years_list() {
        try {
            $rows = DB::fetchAll("SELECT year_name FROM academic_years ORDER BY is_default DESC, year_name DESC");
            $out = [];
            foreach ($rows as $r) {
                $u = unify_academic_year($r['year_name']);
                $v = $u !== '' ? $u : trim((string)$r['year_name']);
                if ($v !== '' && !in_array($v, $out, true)) $out[] = $v;
            }
            return $out;
        } catch (Exception $e) { return []; }
    }
}

if (!function_exists('get_current_academic_year')) {
    function get_current_academic_year() {
        $year = get_setting('current_academic_year', '');
        $unified = unify_academic_year($year);
        if ($unified !== '') $year = $unified;
        // v4.38.0 - self-heal: the current year MUST exist in the master table.
        // If it points to a deleted year, fall back to default/newest master year.
        $master = get_master_academic_years_list();
        if (!empty($master) && ($year === '' || !in_array($year, $master, true))) {
            $year = $master[0];
            try { set_setting('current_academic_year', $year); } catch (Exception $e) {}
        }
        return $year !== '' ? $year : '1404/1405';
    }
}

if (!function_exists('resolve_academic_year_request')) {
    /**
     * v4.38.0 - Every page must pass its requested year (GET/POST) through this.
     * Returns the unified year ONLY if it exists in the master table;
     * otherwise falls back to the current default year. This guarantees that
     * a deleted academic year can never be selected or displayed anywhere.
     */
    function resolve_academic_year_request($requested) {
        $req = unify_academic_year($requested);
        if ($req === '') $req = trim((string)$requested);
        $master = get_master_academic_years_list();
        if ($req !== '' && (empty($master) || in_array($req, $master, true))) return $req;
        return get_current_academic_year();
    }
}

if (!function_exists('cascade_delete_academic_year_data')) {
    /**
     * v4.38.0 - Central cascade cleanup for ALL data chained to an academic year.
     * Used by academic-years.php (full delete) and by the orphan-data cleaner.
     * Handles both formats (1404/1405 and 1404-1405). Returns array of deleted counts.
     */
    function cascade_delete_academic_year_data($yearRaw) {
        $yearUnified = unify_academic_year($yearRaw);
        $base = $yearUnified !== '' ? $yearUnified : $yearRaw;
        $yearVariants = array_values(array_unique(array_filter([
            $yearRaw, $yearUnified,
            str_replace('/', '-', $base), str_replace('-', '/', $base),
            str_replace('/', '-', (string)$yearRaw), str_replace('-', '/', (string)$yearRaw),
        ], function($v){ return $v !== '' && $v !== null; })));
        if (empty($yearVariants)) return [];
        $ph = implode(',', array_fill(0, count($yearVariants), '?'));
        $deleted = [];

        // Students of this year (needed for dependent per-student records)
        $studentIds = [];
        try { $studentIds = array_column(DB::fetchAll("SELECT id FROM students WHERE academic_year IN ($ph)", $yearVariants), 'id'); } catch (Exception $e) {}

        // Reports -> report_grades + report_parent_reviews
        try {
            $reportIds = array_column(DB::fetchAll("SELECT id FROM reports WHERE academic_year IN ($ph)", $yearVariants), 'id');
            if (!empty($reportIds)) {
                $phR = implode(',', array_fill(0, count($reportIds), '?'));
                try { DB::execute("DELETE FROM report_grades WHERE report_id IN ($phR)", $reportIds); } catch (Exception $e) {}
                try { DB::execute("DELETE FROM report_parent_reviews WHERE report_id IN ($phR)", $reportIds); } catch (Exception $e) {}
                DB::execute("DELETE FROM reports WHERE id IN ($phR)", $reportIds);
                $deleted['reports'] = count($reportIds);
            }
        } catch (Exception $e) { error_log('Cascade reports failed: '.$e->getMessage()); }

        // Per-student dependent tables
        if (!empty($studentIds)) {
            $phS = implode(',', array_fill(0, count($studentIds), '?'));
            foreach (['student_discipline_records','bale_bot_users','telegram_bot_users','grade_messages','counseling_requests'] as $t) {
                try { DB::execute("DELETE FROM `$t` WHERE student_id IN ($phS)", $studentIds); } catch (Exception $e) {}
            }
            $deleted['student_related'] = count($studentIds);
        }

        // In-person exams: designs + assignments chained to exam_schedules of this year
        try {
            $examIds = array_column(DB::fetchAll("SELECT id FROM exam_schedules WHERE academic_year IN ($ph)", $yearVariants), 'id');
            if (!empty($examIds)) {
                $phE = implode(',', array_fill(0, count($examIds), '?'));
                foreach (['exam_designs','exam_assignments'] as $t) {
                    try { DB::execute("DELETE FROM `$t` WHERE exam_id IN ($phE)", $examIds); } catch (Exception $e) {}
                }
            }
        } catch (Exception $e) {}

        // Online exams chain: questions, attempts, answers, proctoring, webcam
        try {
            $oeIds = array_column(DB::fetchAll("SELECT id FROM online_exams WHERE academic_year IN ($ph)", $yearVariants), 'id');
            if (!empty($oeIds)) {
                $phO = implode(',', array_fill(0, count($oeIds), '?'));
                try { DB::execute("DELETE FROM online_questions WHERE exam_id IN ($phO)", $oeIds); } catch (Exception $e) {}
                try {
                    $attIds = array_column(DB::fetchAll("SELECT id FROM online_exam_attempts WHERE exam_id IN ($phO)", $oeIds), 'id');
                    if (!empty($attIds)) {
                        $phA = implode(',', array_fill(0, count($attIds), '?'));
                        foreach (['online_exam_answers','online_exam_proctoring_logs','online_exam_live_sessions','online_exam_webcam_requests','online_exam_webcam_snapshots'] as $t) {
                            try { DB::execute("DELETE FROM `$t` WHERE attempt_id IN ($phA)", $attIds); } catch (Exception $e) {}
                        }
                        DB::execute("DELETE FROM online_exam_attempts WHERE id IN ($phA)", $attIds);
                        $deleted['online_attempts'] = count($attIds);
                    }
                } catch (Exception $e) {}
                DB::execute("DELETE FROM online_exams WHERE id IN ($phO)", $oeIds);
                $deleted['online_exams'] = count($oeIds);
            }
        } catch (Exception $e) { error_log('Cascade online exams failed: '.$e->getMessage()); }

        // Flat tables carrying academic_year
        foreach (['students','classes','class_schedules','exam_schedules','exam_student_seating','online_question_categories','grade_entry_permissions','report_locks','exam_question_bank'] as $table) {
            try {
                $before = DB::fetch("SELECT COUNT(*) c FROM `$table` WHERE `academic_year` IN ($ph)", $yearVariants);
                DB::execute("DELETE FROM `$table` WHERE `academic_year` IN ($ph)", $yearVariants);
                if (($before['c'] ?? 0) > 0) $deleted[$table] = (int)$before['c'];
            } catch (Exception $e) {}
        }
        return $deleted;
    }
}

if (!function_exists('find_orphan_academic_years')) {
    /**
     * v4.38.0 - Detect chained data whose academic year no longer exists in the
     * master table (e.g. year deleted with the simple "remove from list" option).
     * Returns [ 'YYYY/YYYY' => ['total'=>N, 'tables'=>['classes'=>n, ...]] ].
     */
    function find_orphan_academic_years() {
        $master = get_master_academic_years_list();
        $masterVariants = [];
        foreach ($master as $m) {
            $masterVariants[] = $m;
            $masterVariants[] = str_replace('/', '-', $m);
            $masterVariants[] = str_replace('-', '/', $m);
        }
        $tables = ['students','classes','class_schedules','reports','exam_schedules','exam_student_seating','online_exams','online_question_categories','grade_entry_permissions','report_locks','exam_question_bank']; // v4.40.0 teachers are year-independent
        $orphans = [];
        foreach ($tables as $table) {
            try {
                $rows = DB::fetchAll("SELECT `academic_year` AS ay, COUNT(*) c FROM `$table` WHERE `academic_year` IS NOT NULL AND `academic_year`<>'' GROUP BY `academic_year`");
            } catch (Exception $e) { continue; }
            foreach ($rows as $r) {
                $raw = trim((string)$r['ay']);
                if ($raw === '') continue;
                $u = unify_academic_year($raw);
                $key = $u !== '' ? $u : $raw;
                if (in_array($raw, $masterVariants, true) || in_array($key, $masterVariants, true)) continue;
                if (!isset($orphans[$key])) $orphans[$key] = ['total'=>0, 'tables'=>[]];
                $orphans[$key]['total'] += (int)$r['c'];
                $orphans[$key]['tables'][$table] = ($orphans[$key]['tables'][$table] ?? 0) + (int)$r['c'];
            }
        }
        ksort($orphans);
        return $orphans;
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

        // v4.40.0 - Teachers are YEAR-INDEPENDENT: one record per person, usable in every year.
        // One-time migration: merge duplicates (keep newest), remap references, drop year coupling.
        try {
            if (get_setting('teachers_year_independent_migrated', '') !== '1') {
                migrate_teachers_year_independent();
                set_setting('teachers_year_independent_migrated', '1');
            }
        } catch (Exception $e) { error_log('teachers year-independent migration failed: '.$e->getMessage()); }

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

if (!function_exists('migrate_teachers_year_independent')) {
    /**
     * v4.40.0 - Make teachers year-independent:
     * 1. For every national_id with multiple records, keep the NEWEST (highest id)
     *    and remap all references (teacher_id in dependent tables) to it.
     * 2. Delete the older duplicate records.
     * 3. Clear the academic_year column (teachers belong to all years).
     * 4. Replace the (national_id, academic_year) unique key with a unique on national_id.
     * Safe to run multiple times (idempotent).
     */
    function migrate_teachers_year_independent() {
        // Tables that reference teachers.id
        $refCols = [
            'class_schedules' => 'teacher_id',
            'subjects' => 'teacher_id',
            'exam_schedules' => 'teacher_id',
            'exam_question_bank' => 'teacher_id',
            'exam_designs' => 'designer_teacher_id',
            'grade_messages' => 'teacher_id',
            'grade_entry_permissions' => 'teacher_id',
            'online_exams' => 'teacher_id',
            'online_question_categories' => 'teacher_id',
            'online_question_bank' => 'teacher_id',
            'bot_admin_sessions' => 'teacher_id',
            'student_discipline_records' => 'created_by_teacher_id',
        ];

        $merged = 0;
        try {
            $dups = DB::fetchAll("SELECT national_id, COUNT(*) c FROM teachers WHERE national_id IS NOT NULL AND national_id<>'' GROUP BY national_id HAVING c > 1");
        } catch (Exception $e) { $dups = []; }

        foreach ($dups as $d) {
            try {
                // Keep the newest record (highest id = registered last)
                $rows = DB::fetchAll("SELECT id, is_deputy, is_counselor, is_executive, mobile, personnel_code FROM teachers WHERE national_id=? ORDER BY id DESC", [$d['national_id']]);
                if (count($rows) < 2) continue;
                $keep = $rows[0];
                $keepId = (int)$keep['id'];
                $oldIds = [];
                // Merge useful data from older records into the kept one (roles OR-ed, fill empty fields)
                $isDeputy = (int)$keep['is_deputy']; $isCounselor = (int)$keep['is_counselor']; $isExecutive = (int)$keep['is_executive'];
                $mobile = trim((string)$keep['mobile']); $pcode = trim((string)$keep['personnel_code']);
                for ($i = 1; $i < count($rows); $i++) {
                    $oldIds[] = (int)$rows[$i]['id'];
                    $isDeputy = $isDeputy ?: (int)$rows[$i]['is_deputy'];
                    $isCounselor = $isCounselor ?: (int)$rows[$i]['is_counselor'];
                    $isExecutive = $isExecutive ?: (int)$rows[$i]['is_executive'];
                    if ($mobile === '') $mobile = trim((string)$rows[$i]['mobile']);
                    if ($pcode === '') $pcode = trim((string)$rows[$i]['personnel_code']);
                }
                try { DB::execute("UPDATE teachers SET is_deputy=?, is_counselor=?, is_executive=?, mobile=?, personnel_code=? WHERE id=?", [$isDeputy, $isCounselor, $isExecutive, $mobile, $pcode, $keepId]); } catch (Exception $e) {}

                // Remap references from old ids to the kept id
                $phOld = implode(',', array_fill(0, count($oldIds), '?'));
                foreach ($refCols as $table => $col) {
                    try { DB::execute("UPDATE `$table` SET `$col`=? WHERE `$col` IN ($phOld)", array_merge([$keepId], $oldIds)); } catch (Exception $e) {}
                }
                // Delete old duplicates
                DB::execute("DELETE FROM teachers WHERE id IN ($phOld)", $oldIds);
                $merged += count($oldIds);
            } catch (Exception $e) { error_log('teacher dedupe failed for '.$d['national_id'].': '.$e->getMessage()); }
        }

        // Teachers no longer belong to a year
        try { DB::execute("UPDATE teachers SET academic_year=NULL WHERE academic_year IS NOT NULL"); } catch (Exception $e) {}

        // Replace composite unique key with unique on national_id
        try { DB::execute("ALTER TABLE teachers DROP INDEX uniq_nid_year"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE teachers DROP INDEX national_id"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE teachers ADD UNIQUE KEY uniq_national_id (national_id)"); } catch (Exception $e) {}

        return $merged;
    }
}
?>