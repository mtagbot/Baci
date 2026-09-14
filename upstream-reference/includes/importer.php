<?php
/**
 * Advanced CSV / Excel Importer Engine (includes/importer.php)
 * Handles both Standard Structured CSVs and Multi-Block Iranian School Report Cards (Report.csv).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

if (!function_exists('parse_school_blocks_csv')) {
    function parse_school_blocks_csv($filepath) {
        if (!file_exists($filepath)) return [];
        
        $lines = [];
        if (($handle = fopen($filepath, "r")) !== false) {
            while (($data = fgetcsv($handle)) !== false) {
                $lines[] = $data;
            }
            fclose($handle);
        }

        $students = [];
        $totalLines = count($lines);
        $currentYear = '1404/1405';
        $currentMonth = 'آبان';
        $schoolName = get_setting('school_name', 'دبیرستان بصیرت');

        for ($i = 0; $i < $totalLines; $i++) {
            $row = $lines[$i];
            $rowStr = implode(' ', $row);

            // Detect Year or Month
            if (strpos($rowStr, 'سال تحصیلی:') !== false) {
                foreach ($row as $col) {
                    if (strpos($col, '14') !== false) {
                        $currentYear = trim(str_replace('سال تحصیلی:', '', $col));
                    }
                }
            }
            foreach (['مهر','آبان','آذر','دی','بهمن','اسفند','فروردین','اردیبهشت','خرداد','نوبت اول','نوبت دوم'] as $mName) {
                if (in_array($mName, array_map('trim', $row))) {
                    $currentMonth = $mName;
                }
            }

            // Detect student block header
            if (strpos($rowStr, 'نام و نام خانوادگی:') !== false) {
                $nameRow = $row;
                $classRow = ($i + 1 < $totalLines) ? $lines[$i + 1] : [];

                // Extract Left Student (Index 6)
                $s1Name = isset($nameRow[6]) ? trim($nameRow[6]) : '';
                $s1Class = isset($classRow[6]) ? trim(str_replace('کلاس:', '', $classRow[6])) : 'عمومی';

                // Extract Right Student (Search indices > 10)
                $s2Name = '';
                $s2Class = 'عمومی';
                foreach ($nameRow as $cIdx => $val) {
                    if ($cIdx > 10 && trim($val) !== '' && trim($val) !== 'نام و نام خانوادگی:') {
                        $s2Name = trim($val);
                        break;
                    }
                }
                foreach ($classRow as $cIdx => $val) {
                    if ($cIdx > 10 && trim($val) !== '' && trim($val) !== 'کلاس:') {
                        $s2Class = trim(str_replace('کلاس:', '', $val));
                        break;
                    }
                }

                // Collect Grades downwards starting from i + 3
                $s1Grades = [];
                $s1Gpa = 0;
                $s2Grades = [];
                $s2Gpa = 0;

                for ($j = $i + 2; $j < min($totalLines, $i + 25); $j++) {
                    $gRow = $lines[$j];
                    $gRowStr = implode(' ', $gRow);
                    if (strpos($gRowStr, 'مهر و امضاء') !== false) break;

                    // Left Student Grades (score=9, subj=10, coef=6 or 8)
                    $subj1 = isset($gRow[10]) ? trim($gRow[10]) : '';
                    if ($subj1 !== '' && $subj1 !== 'نام درس') {
                        if ($subj1 === 'مــعدل' || strpos($subj1, 'معدل') !== false) {
                            $s1Gpa = isset($gRow[9]) ? (float)str_replace('/', '.', trim($gRow[9])) : 0;
                        } else {
                            $score1 = isset($gRow[9]) ? (float)str_replace('/', '.', trim($gRow[9])) : 0;
                            $coef1  = isset($gRow[6]) && is_numeric(trim($gRow[6])) ? (float)trim($gRow[6]) : 2;
                            $s1Grades[] = ['subject_name' => $subj1, 'score' => $score1, 'coefficient' => $coef1, 'max_score' => 20];
                        }
                    }

                    // Right Student Grades (score=24, subj=25, coef=21 or 23)
                    $subj2 = isset($gRow[25]) ? trim($gRow[25]) : '';
                    if ($subj2 !== '' && $subj2 !== 'نام درس') {
                        if ($subj2 === 'مــعدل' || strpos($subj2, 'معدل') !== false) {
                            $s2Gpa = isset($gRow[24]) ? (float)str_replace('/', '.', trim($gRow[24])) : 0;
                        } else {
                            $score2 = isset($gRow[24]) ? (float)str_replace('/', '.', trim($gRow[24])) : 0;
                            $coef2  = isset($gRow[21]) && is_numeric(trim($gRow[21])) ? (float)trim($gRow[21]) : 2;
                            $s2Grades[] = ['subject_name' => $subj2, 'score' => $score2, 'coefficient' => $coef2, 'max_score' => 20];
                        }
                    }
                }

                if (!empty($s1Name) && !empty($s1Grades)) {
                    $parts = explode(' ', $s1Name, 2);
                    $students[] = [
                        'national_id' => 'TEMP-' . abs(crc32($s1Name . $s1Class)) % 899999 + 100000,
                        'first_name'  => $parts[0],
                        'last_name'   => $parts[1] ?? '',
                        'class_name'  => $s1Class ?: 'هفتم/1',
                        'grade_level' => explode('/', $s1Class)[0] ?? 'هفتم',
                        'academic_year' => $currentYear,
                        'term'        => $currentMonth,
                        'report_month'=> $currentMonth,
                        'gpa'         => $s1Gpa,
                        'grades'      => $s1Grades,
                        'is_temp'     => 1,
                        'full_name'   => $s1Name
                    ];
                }

                if (!empty($s2Name) && !empty($s2Grades)) {
                    $parts = explode(' ', $s2Name, 2);
                    $students[] = [
                        'national_id' => 'TEMP-' . abs(crc32($s2Name . $s2Class)) % 899999 + 100000,
                        'first_name'  => $parts[0],
                        'last_name'   => $parts[1] ?? '',
                        'class_name'  => $s2Class ?: 'هفتم/1',
                        'grade_level' => explode('/', $s2Class)[0] ?? 'هفتم',
                        'academic_year' => $currentYear,
                        'term'        => $currentMonth,
                        'report_month'=> $currentMonth,
                        'gpa'         => $s2Gpa,
                        'grades'      => $s2Grades,
                        'is_temp'     => 1,
                        'full_name'   => $s2Name
                    ];
                }
            }
        }
        return $students;
    }
}

if (!function_exists('parse_any_import_file')) {
    function parse_any_import_file($filepath) {
        if (!file_exists($filepath)) return [];

        // Check if it's block-based (Report.csv format)
        $contentSample = file_get_contents($filepath, false, null, 0, 4000);
        if (strpos($contentSample, 'نام و نام خانوادگی:') !== false || strpos($contentSample, 'نمودار دروس') !== false) {
            $blockStudents = parse_school_blocks_csv($filepath);
            // Convert to flat grade rows for uniform preview if needed, or return grouped items
            $rows = [];
            foreach ($blockStudents as $st) {
                foreach ($st['grades'] as $g) {
                    $rows[] = [
                        'national_id'   => $st['national_id'],
                        'first_name'    => $st['first_name'],
                        'last_name'     => $st['last_name'],
                        'class_name'    => $st['class_name'],
                        'grade_level'   => $st['grade_level'],
                        'academic_year' => $st['academic_year'],
                        'term'          => $st['term'],
                        'report_month'  => $st['report_month'],
                        'subject_name'  => $g['subject_name'],
                        'score'         => $g['score'],
                        'max_score'     => $g['max_score'],
                        'coefficient'   => $g['coefficient'],
                        'full_name'     => $st['full_name'] ?? ($st['first_name'] . ' ' . $st['last_name'])
                    ];
                }
            }
            return $rows;
        }

        // Standard flat structured CSV
        $rows = [];
        if (($handle = fopen($filepath, "r")) !== false) {
            $firstLine = fgets($handle);
            if (substr($firstLine, 0, 3) == "\xEF\xBB\xBF") {
                $firstLine = substr($firstLine, 3);
            }
            $headers = str_getcsv(trim($firstLine));
            while (($data = fgetcsv($handle)) !== false) {
                if (count($data) == count($headers)) {
                    $rows[] = array_combine($headers, $data);
                } elseif (count($data) > 1) {
                    $row = [];
                    foreach ($headers as $idx => $h) {
                        $row[$h] = $data[$idx] ?? '';
                    }
                    $rows[] = $row;
                }
            }
            fclose($handle);
        }
        return $rows;
    }
}

if (!function_exists('norm_persian_str')) {
    function norm_persian_str($str) {
        $str = str_replace(['ي', 'ك', 'آ', 'إ', 'أ', '‌', 'ة', 'ؤ'], ['ی', 'ک', 'ا', 'ا', 'ا', ' ', 'ه', 'و'], (string)$str);
        return preg_replace('/[\s\-\/\_]+/', '', trim($str));
    }
}

if (!function_exists('norm_class_str')) {
    function norm_class_str($str) {
        return preg_replace('/[\s\-\/\_]+/', '', trim((string)$str));
    }
}

if (!function_exists('standardize_all_class_names')) {
    function standardize_all_class_names() {
        try {
            $pdo = DB::getInstance()->getPdo();
            if (!$pdo) return;
            $reports = DB::fetchAll("SELECT id, class_name FROM reports");
            foreach ($reports as $r) {
                $clean = norm_class_str($r['class_name']);
                if ($clean !== $r['class_name'] && !empty($clean)) {
                    DB::execute("UPDATE reports SET class_name = ? WHERE id = ?", [$clean, $r['id']]);
                }
            }
            $students = DB::fetchAll("SELECT id, class_name FROM students");
            foreach ($students as $s) {
                $clean = norm_class_str($s['class_name']);
                if ($clean !== $s['class_name'] && !empty($clean)) {
                    DB::execute("UPDATE students SET class_name = ? WHERE id = ?", [$clean, $s['id']]);
                }
            }
        } catch (Exception $e) {}
    }
}

if (!function_exists('match_existing_student')) {
    function match_existing_student($fullName, $fname, $lname, $cname, $nid = '') {
        if (!empty($nid) && strpos($nid, 'TEMP-') !== 0) {
            $st = DB::fetch("SELECT * FROM students WHERE national_id = ?", [$nid]);
            if ($st) return $st;
        }
        $st = DB::fetch("SELECT * FROM students WHERE first_name = ? AND last_name = ? AND class_name = ?", [$fname, $lname, $cname]);
        if ($st) return $st;

        $allStudents = DB::fetchAll("SELECT * FROM students ORDER BY (is_temp = 0) DESC, id ASC");
        $normInputName = norm_persian_str($fullName ?: ($fname . ' ' . $lname));
        $normInputCls  = norm_class_str($cname);

        foreach ($allStudents as $s) {
            $dbNormName = norm_persian_str($s['first_name'] . ' ' . $s['last_name']);
            $dbNormCls  = norm_class_str($s['class_name']);
            if ($dbNormName === $normInputName) {
                if ($dbNormCls === $normInputCls || empty($normInputCls)) {
                    return $s;
                }
            }
        }
        return null;
    }
}
if (!function_exists('import_csv')) {
    function import_csv($file_path, $admin_id = null) {
        standardize_all_class_names();
        $rows = parse_any_import_file($file_path);
        if (empty($rows)) {
            return ['status' => 'error', 'message' => 'هیچ داده معتبری در فایل یافت نشد.'];
        }

        $importedCount = 0;
        $errors = [];

        foreach ($rows as $idx => $r) {
            $res = process_student_data($r);
            if ($res['status'] === 'success') {
                $importedCount++;
            } else {
                $errors[] = "ردیف " . ($idx + 1) . ": " . $res['message'];
            }
        }

        save_import_history([
            'file_name' => basename($file_path),
            'total_records' => count($rows),
            'imported_records' => $importedCount,
            'status' => empty($errors) ? 'completed' : 'completed_with_warnings',
            'error_message' => implode("\n", array_slice($errors, 0, 10))
        ]);

        return [
            'status' => 'success',
            'total' => count($rows),
            'imported' => $importedCount,
            'errors' => $errors
        ];
    }
}

if (!function_exists('process_student_data')) {
    function process_student_data($data) {
        try {
            $nid = tr_num(trim($data['national_id'] ?? $data['national_code'] ?? ''), 'en');
            $fname  = trim($data['first_name'] ?? '');
            $lname  = trim($data['last_name'] ?? '');
            $fullName = trim($data['full_name'] ?? ($fname . ' ' . $lname));
            if (empty($fname) && !empty($fullName)) {
                $parts = explode(' ', $fullName, 2);
                $fname = $parts[0];
                $lname = $parts[1] ?? '';
            }

            $cname  = trim($data['class_name'] ?? '101');
            $grade  = trim($data['grade_level'] ?? $data['grade_name'] ?? 'هفتم');
            $year   = trim($data['academic_year'] ?? '1404/1405');
            $term   = trim($data['term'] ?? 'نوبت اول');
            $month  = trim($data['report_month'] ?? $data['month'] ?? 'آبان');
            $sname  = trim($data['subject_name'] ?? $data['course_name'] ?? 'درس عمومی');
            $score  = (float)str_replace('/', '.', ($data['score'] ?? 0));
            $max    = (float)($data['max_score'] ?? 20);
            $coeff  = (float)($data['coefficient'] ?? 2);

            // 1. Try matching student using smart normalized comparison
            $student = match_existing_student($fullName, $fname, $lname, $cname, $nid);

            if ($student) {
                $studentId = $student['id'];
                // Update class or grade if needed
                DB::execute("UPDATE students SET class_name=?, grade_level=? WHERE id=?", [$cname, $grade, $studentId]);
            } else {
                return ['status' => 'error', 'message' => "دانش‌آموز «" . ($fullName ?: "$fname $lname") . "» (کلاس $cname) در سیستم یافت نشد. ابتدا مشخصات دانش‌آموز را از بخش ایمپورت دانش‌آموزان ثبت کنید."];
            }

            $cls = DB::fetch("SELECT id FROM classes WHERE name = ?", [$cname]);
            if (!$cls) {
                DB::execute("INSERT INTO classes (name, grade, academic_year) VALUES (?, ?, ?)", [$cname, $grade, $year]);
            }

            $rep = DB::fetch("SELECT id FROM reports WHERE student_id = ? AND academic_year = ? AND term = ? AND report_month = ?",
            [$studentId, $year, $term, $month]);

            if (!$rep) {
                DB::execute("INSERT INTO reports (student_id, class_name, academic_year, term, report_month, total_score, gpa, discipline_score) VALUES (?, ?, ?, ?, ?, ?, ?, NULL)",
                [$studentId, $cname, $year, $term, $month, $score * $coeff, $score]);
                $reportId = DB::lastInsertId();
            } else {
                $reportId = $rep['id'];
            }

            $status = $score >= 10 ? 'passed' : 'failed';
            DB::execute("INSERT INTO report_grades (report_id, subject_name, score, max_score, coefficient, status) VALUES (?, ?, ?, ?, ?, ?)",
            [$reportId, $sname, $score, $max, $coeff, $status]);

            // Recalculate GPA without discipline score (pure arithmetic average)
            $allGrades = DB::fetchAll("SELECT * FROM report_grades WHERE report_id = ?", [$reportId]);
            $calc = extract_clean_grades_and_discipline($allGrades, null);
            if ($calc['count'] > 0) {
                DB::execute("UPDATE reports SET total_score = ?, gpa = ?, discipline_score = COALESCE(?, discipline_score) WHERE id = ?",
                    [$calc['calculated_gpa'] * $calc['count'], $calc['calculated_gpa'], $calc['discipline_score'], $reportId]);
            }

            return ['status' => 'success'];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
