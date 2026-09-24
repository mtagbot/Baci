<?php
/**
 * Student Profiles Bulk Importer - v4.29.1 Comprehensive (7reporte.csv with 31 columns)
 * Handles both old Report-sudents.csv and new comprehensive 7reporte.csv
 * v4.29.1: CSV password column support, zero-padded birth date, fixed sample link,
 *          UTF-8/Windows-1256 tolerant parsing.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/academic_year_helpers.php';
require_once __DIR__ . '/includes/importer.php';
require_once __DIR__ . '/includes/school_sort.php';

require_permission('manage_students');

$step = $_GET['step'] ?? 1;

// Helper to parse student CSV - supports both old and new comprehensive format
function parseStudentProfileCsv($filepath) {
    if (!file_exists($filepath)) return [];

    // v4.29.1: Encoding-tolerant read (UTF-8 BOM / UTF-8 / Windows-1256 legacy Excel exports)
    $rawContent = file_get_contents($filepath);
    if ($rawContent === false) return [];
    if (substr($rawContent, 0, 3) === "\xEF\xBB\xBF") {
        $rawContent = substr($rawContent, 3);
    }
    if (function_exists('mb_check_encoding') && !mb_check_encoding($rawContent, 'UTF-8')) {
        $converted = @iconv('Windows-1256', 'UTF-8//IGNORE', $rawContent);
        if ($converted !== false && $converted !== '') $rawContent = $converted;
    }

    $rows = [];
    $lines = preg_split('/\r\n|\r|\n/', $rawContent);
    $headers = [];
    foreach ($lines as $lineNo => $line) {
        if (trim($line) === '') continue;
        $data = str_getcsv($line);
        if (empty($headers)) {
            $headers = array_map('trim', $data);
            continue;
        }
        // Skip fully empty rows (e.g. ",,,,,,")
        $hasValue = false;
        foreach ($data as $c) { if (trim((string)$c) !== '') { $hasValue = true; break; } }
        if (!$hasValue) continue;

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

    $parsed = [];
    foreach ($rows as $r) {
        $nid = tr_num(trim($r['Datastudents_nationalCode'] ?? $r['national_id'] ?? $r['national_code'] ?? ''), 'en');
        if (empty($nid)) continue;

        $fname  = trim($r['Datastudents_firstName'] ?? $r['first_name'] ?? '');
        $lname  = trim($r['Datastudents_lastName'] ?? $r['last_name'] ?? '');
        $father = trim($r['Datastudents_fatherName'] ?? $r['father_name'] ?? '');
        $cname  = norm_class_str($r['Datastudents_className'] ?? $r['class_name'] ?? 'هفتم1');
        $gradeLevel = trim($r['Datastudents_grade'] ?? $r['grade_level'] ?? '');
        if ($gradeLevel === '') { $gradeLevel = infer_grade_from_class_name($cname); }
        $rawSer = trim($r['Datastudents_serial'] ?? $r['serial_number'] ?? '');
        $serial = extract_certificate_serial($rawSer);
        $stCode = trim($r['Datastudents_code'] ?? $r['student_code'] ?? $serial);

        $fMob   = tr_num(trim($r['Datastudents_fatherMobile'] ?? $r['father_phone'] ?? ''), 'en');
        $mMob   = tr_num(trim($r['Datastudents_motherMobile'] ?? $r['mother_phone'] ?? ''), 'en');
        $studentMobile = tr_num(trim($r['Datastudents_mobile'] ?? $r['mobile'] ?? ''), 'en');

        // New comprehensive fields from 7reporte.csv
        $postalCode = tr_num(trim($r['Datastudents_postalCode'] ?? $r['postal_code'] ?? ''), 'en');
        $homeAddress = trim($r['Datastudents_homeAddress'] ?? $r['home_address'] ?? '');
        $homePhone = tr_num(trim($r['Datastudents_homePhone'] ?? $r['home_phone'] ?? ''), 'en');
        $religion = trim($r['Datastudents_religionTitle'] ?? $r['religion_title'] ?? '');
        $nationality = trim($r['Datastudents_nationalityTitle'] ?? $r['nationality_title'] ?? 'ایرانی');
        $isForeign = ($nationality !== '' && $nationality !== 'ایرانی') ? 1 : 0;
        $coverTitle = trim($r['Datastudents_coverTitle'] ?? $r['cover_title'] ?? '');
        $specificDisease = trim($r['Datastudents_specificDiseaseExactTitle'] ?? $r['specific_disease_title'] ?? '');
        $housing = trim($r['Datastudents_housingTitle'] ?? $r['housing_title'] ?? '');
        $motherQual = trim($r['Datastudents_motherQualification'] ?? $r['mother_qualification'] ?? '');
        $motherJob = trim($r['Datastudents_motherJobExactTitle'] ?? $r['mother_job'] ?? '');
        $fatherQual = trim($r['Datastudents_fatherQualification'] ?? $r['father_qualification'] ?? '');
        $fatherJob = trim($r['Datastudents_fatherJobExactTitle'] ?? $r['father_job'] ?? '');
        $placeIssued = trim($r['Datastudents_placeIssued'] ?? $r['place_issued'] ?? '');
        $birthPlace = trim($r['Datastudents_birthPlace'] ?? $r['birth_place'] ?? '');
        $birthYear = (int)trim($r['Datastudents_birthYear'] ?? $r['birth_year'] ?? 0);
        $birthMonth = (int)trim($r['Datastudents_birthMonth'] ?? $r['birth_month'] ?? 0);
        $birthDay = (int)trim($r['Datastudents_birthDay'] ?? $r['birth_day'] ?? 0);
        $csvPassword = trim($r['Datastudents_password'] ?? $r['password'] ?? '');
        $motherNameFull = trim($r['Datastudents_motherName'] ?? $r['mother_name'] ?? '');
        $motherFirst = ''; $motherLast = '';
        if ($motherNameFull !== '') {
            $parts = preg_split('/\s+/', $motherNameFull);
            if (count($parts)>=2) {
                $motherFirst = $parts[0];
                $motherLast = implode(' ', array_slice($parts,1));
            } else {
                $motherFirst = $motherNameFull;
            }
        }
        $lastYearAvg = trim($r['Datastudents_lastYearAverage'] ?? $r['last_year_average'] ?? '');

        $hasSportLimitation = 0;
        $sportDesc = '';
        if ($specificDisease !== '') {
            if (mb_strpos($specificDisease, 'ورزش')!==false || mb_strpos($specificDisease, 'فوتبال')!==false || mb_strpos($specificDisease, 'محدود')!==false || mb_strpos($specificDisease, 'دروازه')!==false) {
                $hasSportLimitation = 1;
                $sportDesc = $specificDisease;
            }
        }

        // v4.29.1: zero-padded two-digit month/day => 1393/04/20
        $bdate  = ($birthYear && $birthYear != '0')
            ? sprintf('%04d/%02d/%02d', $birthYear, $birthMonth, $birthDay)
            : trim($r['birth_date'] ?? '');

        $exists = DB::fetch("SELECT id, first_name, last_name, class_name, serial_number FROM students WHERE national_id = ?", [$nid]);

        $parsed[] = [
            'national_id'  => $nid,
            'student_code' => $stCode,
            'first_name'   => $fname,
            'last_name'    => $lname,
            'father_name'  => $father,
            'class_name'   => $cname,
            'grade_level'  => $gradeLevel,
            'serial'       => $serial,
            'raw_serial'   => $rawSer,
            'father_phone' => $fMob,
            'mother_phone' => $mMob,
            'student_mobile' => $studentMobile,
            'birth_date'   => $bdate,
            'birth_year' => $birthYear,
            'birth_month' => $birthMonth,
            'birth_day' => $birthDay,
            'postal_code' => $postalCode,
            'home_address' => $homeAddress,
            'home_phone' => $homePhone,
            'religion_title' => $religion,
            'nationality_title' => $nationality,
            'is_foreign' => $isForeign,
            'cover_title' => $coverTitle,
            'specific_disease_title' => $specificDisease,
            'has_sport_limitation' => $hasSportLimitation,
            'sport_limitation_desc' => $sportDesc,
            'housing_title' => $housing,
            'mother_qualification' => $motherQual,
            'mother_job' => $motherJob,
            'father_qualification' => $fatherQual,
            'father_job' => $fatherJob,
            'place_issued' => $placeIssued,
            'birth_place' => $birthPlace,
            'mother_name' => $motherNameFull,
            'mother_first_name' => $motherFirst,
            'mother_last_name' => $motherLast,
            'last_year_average' => $lastYearAvg,
            'csv_password' => $csvPassword,
            'exists'       => $exists ? true : false,
            'old_data'     => $exists
        ];
    }
    return $parsed;
}

// Step 1: Handle Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_students') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'خطای امنیتی CSRF');
        redirect('import-students.php');
    }
    if (isset($_FILES['students_file']) && $_FILES['students_file']['error'] === UPLOAD_ERR_OK) {
        $destFolder = __DIR__ . '/uploads';
        if (!is_dir($destFolder)) mkdir($destFolder, 0777, true);
        
        $filename = 'students_' . time() . '.csv';
        $destPath = $destFolder . '/' . $filename;
        move_uploaded_file($_FILES['students_file']['tmp_name'], $destPath);

        $_SESSION['import_students_file'] = $filename;
        $_SESSION['import_students_year'] = unify_academic_year(trim($_POST['academic_year'] ?? get_current_academic_year()));
        log_activity($_SESSION['admin_id'] ?? null, 'آپلود فایل مشخصات دانش‌آموزان', "فایل $filename بارگذاری شد.");
        redirect('import-students.php?step=2');
    } else {
        set_flash_message('error', 'خطا در بارگذاری فایل. لطفاً مجدداً تلاش کنید.');
        redirect('import-students.php');
    }
}

// Step 2: Handle Execution - comprehensive with all new fields
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'execute_students') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'خطای امنیتی CSRF');
        redirect('import-students.php');
    }
    $selectedIds = $_POST['selected_nids'] ?? [];
    $importYear = unify_academic_year(trim($_POST['academic_year'] ?? get_setting('current_academic_year', '1404/1405')));
    $filename = $_SESSION['import_students_file'] ?? '';
    $filepath = __DIR__ . '/uploads/' . $filename;

    if (!file_exists($filepath) || empty($selectedIds)) {
        set_flash_message('error', 'هیچ رکوردی انتخاب نشده یا فایل منقضی شده است.');
        redirect('import-students.php');
    }

    $allParsed = parseStudentProfileCsv($filepath);
    $updatedCount = 0;
    $insertedCount = 0;

    foreach ($allParsed as $st) {
        if (!in_array($st['national_id'], $selectedIds)) continue;

        $nid   = $st['national_id'];
        $stCode= $st['student_code'];
        $fname = $st['first_name'];
        $lname = $st['last_name'];
        $father= $st['father_name'];
        $cname = $st['class_name'];
        $ser   = $st['serial'];
        $grade = $st['grade_level'] ?: infer_grade_from_class_name($cname);
        $fMob  = $st['father_phone'];
        $mMob  = $st['mother_phone'];
        $sMob  = $st['student_mobile'];
        $bdate = $st['birth_date'];
        $phone = $fMob ?: ($mMob ?: ($sMob ?: ''));

        // v4.29.1: password priority = 6-digit certificate serial > CSV password column > national code
        $plainPass = $ser ?: (($st['csv_password'] ?? '') !== '' ? $st['csv_password'] : $nid);
        $passHash = password_hash($plainPass, PASSWORD_DEFAULT);

        // Comprehensive fields
        $postal = $st['postal_code'];
        $homeAddr = $st['home_address'];
        $homePh = $st['home_phone'];
        $religion = $st['religion_title'];
        $nationality = $st['nationality_title'];
        $isForeign = $st['is_foreign'];
        $cover = $st['cover_title'];
        $disease = $st['specific_disease_title'];
        $hasSport = $st['has_sport_limitation'];
        $sportDesc = $st['sport_limitation_desc'];
        $housing = $st['housing_title'];
        $motherQual = $st['mother_qualification'];
        $motherJob = $st['mother_job'];
        $fatherQual = $st['father_qualification'];
        $fatherJob = $st['father_job'];
        $placeIssued = $st['place_issued'];
        $birthPlace = $st['birth_place'];
        $bYear = $st['birth_year'];
        $bMonth = $st['birth_month'];
        $bDay = $st['birth_day'];
        $motherName = $st['mother_name'];
        $motherFirst = $st['mother_first_name'];
        $motherLast = $st['mother_last_name'];
        $lastAvg = $st['last_year_average'];

        $existing = DB::fetch("SELECT id FROM students WHERE national_id = ? AND academic_year = ?", [$nid, $importYear]);
        if ($existing) {
            DB::execute(
                "UPDATE students SET first_name=?, last_name=?, father_name=?, class_name=?, grade_level=?, student_code=?, serial_number=?, father_phone=?, mother_phone=?, student_mobile=?, phone=?, birth_date=?, birth_year=?, birth_month=?, birth_day=?, postal_code=?, home_address=?, home_phone=?, religion_title=?, nationality_title=?, is_foreign=?, cover_title=?, specific_disease_title=?, has_sport_limitation=?, sport_limitation_desc=?, housing_title=?, mother_qualification=?, mother_job=?, father_qualification=?, father_job=?, place_issued=?, birth_place=?, mother_name=?, mother_first_name=?, mother_last_name=?, last_year_average=?, password=?, academic_year=?, is_temp=0 WHERE id=?",
                [$fname, $lname, $father, $cname, $grade, $stCode, $ser, $fMob, $mMob, $sMob, $phone, $bdate, $bYear, $bMonth, $bDay, $postal, $homeAddr, $homePh, $religion, $nationality, $isForeign, $cover, $disease, $hasSport, $sportDesc, $housing, $motherQual, $motherJob, $fatherQual, $fatherJob, $placeIssued, $birthPlace, $motherName, $motherFirst, $motherLast, $lastAvg, $passHash, $importYear, $existing['id']]
            );
            $updatedCount++;
        } else {
            DB::execute(
                "INSERT INTO students (national_id, student_code, serial_number, first_name, last_name, father_name, class_name, grade_level, phone, father_phone, mother_phone, student_mobile, birth_date, birth_year, birth_month, birth_day, postal_code, home_address, home_phone, religion_title, nationality_title, is_foreign, cover_title, specific_disease_title, has_sport_limitation, sport_limitation_desc, housing_title, mother_qualification, mother_job, father_qualification, father_job, place_issued, birth_place, mother_name, mother_first_name, mother_last_name, last_year_average, password, status, is_temp, academic_year) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$nid, $stCode, $ser, $fname, $lname, $father, $cname, $grade, $phone, $fMob, $mMob, $sMob, $bdate, $bYear, $bMonth, $bDay, $postal, $homeAddr, $homePh, $religion, $nationality, $isForeign, $cover, $disease, $hasSport, $sportDesc, $housing, $motherQual, $motherJob, $fatherQual, $fatherJob, $placeIssued, $birthPlace, $motherName, $motherFirst, $motherLast, $lastAvg, $passHash, 'active', 0, $importYear]
            );
            $insertedCount++;
        }

        $cls = DB::fetch("SELECT id FROM classes WHERE name = ?", [$cname]);
        if (!$cls && !empty($cname)) {
            DB::execute("INSERT INTO classes (name, grade, academic_year) VALUES (?, ?, ?)", [$cname, $grade ?: infer_grade_from_class_name($cname), $importYear]);
        }
    }

    log_activity($_SESSION['admin_id'] ?? null, 'ایمپورت و آپدیت دانش‌آموزان جامع', "تعداد $updatedCount بروزرسانی و $insertedCount جدید با پرونده کامل ثبت شد.");
    set_flash_message('success', "✅ عملیات با پرونده کامل انجام شد: $updatedCount دانش‌آموز بروزرسانی و $insertedCount دانش‌آموز جدید با تمام فیلدهای جدید (کد پستی، آدرس، بیماری، شغل والدین، محل تولد...) ثبت شد.");
    redirect('import-students.php?step=3&updated=' . $updatedCount . '&inserted=' . $insertedCount);
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold text-gray-800 dark:text-white">👥 ایمپورت جامع پرونده دانش‌آموزان (فرمت جدید 7reporte.csv)</h2>
            <p class="text-sm text-muted">ورود پرونده کامل با کد پستی، آدرس، بیماری، محدودیت ورزش، شغل و تحصیلات والدین، محل تولد و...</p>
        </div>
        <div class="flex gap-2">
            <a href="export-students.php" class="btn btn-success gap-1 text-sm"><span>📤 اکسپورت جامع دانش‌آموزان</span></a>
            <a href="download-sample-7reporte.php" class="btn btn-outline gap-1 text-sm border-blue-500 text-blue-600"><span>📥 دانلود نمونه فرمت جدید (7reporte.csv)</span></a>
        </div>
    </div>

    <div class="wizard-steps card">
        <div class="wizard-step <?php echo $step >= 1 ? 'active' : ''; ?>">۱. بارگذاری پرونده کامل</div>
        <div class="wizard-step <?php echo $step >= 2 ? 'active' : ''; ?>">۲. بازبینی و تایید</div>
        <div class="wizard-step <?php echo $step >= 3 ? 'completed' : ''; ?>">۳. نتیجه ثبت</div>
    </div>

    <?php if ($step == 1): ?>
    <div class="grid grid-cols-2 gap-6">
        <div class="card shadow-lg">
            <h3 class="font-bold mb-4 text-primary">آپلود فایل جامع دانش‌آموزان (31 ستون)</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="action" value="upload_students">
                <div class="mb-4 p-3 border-2 border-indigo-100 rounded bg-indigo-50/30">
                    <label class="block text-xs font-bold mb-1">📅 سال تحصیلی مقصد (انتخابی - فرمت جامع) - تمام رکوردها در این سال ذخیره می‌شوند</label>
                    <select name="academic_year" class="form-select font-bold w-full">
                        <?php foreach(get_academic_years_for_filter() as $yy): ?>
                            <option value="<?php echo clean($yy['academic_year']); ?>" <?php echo get_current_academic_year()===$yy['academic_year']?'selected':''; ?>><?php echo clean($yy['academic_year']); ?><?php echo !empty($yy['is_default'])?' - پیش‌فرض جاری':''; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="block text-xs font-semibold mb-2">انتخاب فایل جامع (CSV - 31 ستون)</label>
                    <input type="file" name="students_file" class="form-input p-2" accept=".csv" required>
                    <small class="text-xs text-muted block mt-1">فرمت جدید: 7reporte.csv با کد پستی، آدرس، بیماری، شغل والدین و...</small>
                </div>
                <div class="p-4 bg-blue-50 rounded border border-blue-200 text-xs mb-4 space-y-2">
                    <p class="font-bold text-blue-800">📋 فیلدهای جدید فرمت جامع (31 ستون):</p>
                    <ul class="list-disc pr-4 space-y-1 font-mono text-[11px]">
                        <li>Datastudents_postalCode - کد پستی 10 رقمی</li>
                        <li>Datastudents_homeAddress - آدرس منزل</li>
                        <li>Datastudents_homePhone - تلفن منزل</li>
                        <li>Datastudents_nationalityTitle - ایرانی / اتباع (تیک اتباع)</li>
                        <li>Datastudents_specificDiseaseExactTitle - بیماری خاص + محدودیت ورزش</li>
                        <li>Datastudents_motherQualification - تحصیلات مادر</li>
                        <li>Datastudents_motherJobExactTitle - شغل مادر</li>
                        <li>Datastudents_fatherQualification - تحصیلات پدر</li>
                        <li>Datastudents_fatherJobExactTitle - شغل پدر</li>
                        <li>Datastudents_placeIssued - محل صدور</li>
                        <li>Datastudents_birthPlace - محل تولد</li>
                        <li>Datastudents_birthYear/Month/Day - روز/ماه/سال تولد</li>
                        <li>Datastudents_motherName - نام مادر + نام خانوادگی مادر</li>
                        <li>Datastudents_lastYearAverage - معدل سال قبل</li>
                    </ul>
                </div>
                <button type="submit" class="btn btn-primary w-full py-3 font-bold text-sm shadow">آپلود و بررسی پرونده کامل &larr;</button>
            </form>
        </div>

        <div class="card shadow-lg space-y-3 text-xs leading-6">
            <h3 class="font-bold">راهنمای فرمت جدید جامع</h3>
            <p>فایل جدید شامل تمام اطلاعات هویتی و تکمیلی پرونده است. سیستم به صورت خودکار تشخیص می‌دهد:</p>
            <p>• <b>اتباع؟</b> اگر ملیت غیر از ایرانی باشد → تیک اتباع بله</p>
            <p>• <b>محدودیت ورزش؟</b> اگر بیماری شامل "ورزش محدود" یا "فوتبال" باشد → تیک بله</p>
            <p>• <b>کد پستی</b> 10 رقمی، <b>آدرس</b> متنی، <b>تلفن منزل</b> عددی</p>
            <p>• <b>تحصیلات و شغل والدین</b> متنی</p>
            <p>• <b>محل تولد/صدور</b> متنی + روز/ماه/سال تولد عددی</p>
            <p>• <b>نام مادر</b> کامل و نام خانوادگی مادر جداگانه</p>
            <p class="mt-2 p-2 bg-green-50 border rounded">تمام فیلدهای جدید در دیتابیس با فرمت جامع ذخیره می‌شود و در تمام بخش‌ها قابل نمایش/ویرایش است.</p>
        </div>
    </div>

    <?php elseif ($step == 2):
        $filename = $_SESSION['import_students_file'] ?? '';
        $filepath = __DIR__ . '/uploads/' . $filename;
        $parsedStudents = parseStudentProfileCsv($filepath);
        $existCount = 0; $newCount = 0;
        foreach ($parsedStudents as $ps) { if ($ps['exists']) $existCount++; else $newCount++; }
    ?>
    <form method="POST" action="import-students.php?step=2">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="action" value="execute_students">
        <div class="card mb-3 p-4 border-2 border-indigo-100 bg-indigo-50/30">
            <label class="text-xs font-bold">📅 سال تحصیلی ثبت پرونده کامل (انتخابی - فرمت جامع)</label>
            <select name="academic_year" class="form-select w-60 font-bold">
                <?php $defaultImportYear = $_SESSION['import_students_year'] ?? get_current_academic_year(); foreach(get_academic_years_for_filter() as $yy): ?>
                    <option value="<?php echo clean($yy['academic_year']); ?>" <?php echo $defaultImportYear===$yy['academic_year']?'selected':''; ?>><?php echo clean($yy['academic_year']); ?><?php echo !empty($yy['is_default'])?' - پیش‌فرض جاری':''; ?></option>
                <?php endforeach; ?>
            </select>
            <span class="text-[11px] text-muted mr-2">تمام پرونده‌های کامل در سال انتخابی ذخیره می‌شوند - <?php echo count($parsedStudents); ?> نفر</span>
        </div>

        <div class="card bg-blue-50 border-blue-200 mb-6 flex justify-between items-center shadow">
            <div>
                <h3 class="font-bold text-blue-900">پیش‌نمایش پرونده کامل از فایل <?php echo clean($filename); ?></h3>
                <p class="text-xs text-blue-800 mt-1">کل: <b><?php echo tr_num(count($parsedStudents), 'fa'); ?></b> | موجود: <span class="badge bg-amber-500 text-white"><?php echo tr_num($existCount, 'fa'); ?></span> | جدید: <span class="badge bg-green-600 text-white"><?php echo tr_num($newCount, 'fa'); ?></span></p>
            </div>
            <button type="submit" class="btn btn-success px-6 py-3 font-bold shadow-lg">✅ تایید و ثبت پرونده کامل در سال انتخابی &larr;</button>
        </div>

        <div class="card shadow-lg">
            <div class="flex justify-between items-center mb-4 border-b pb-3">
                <h4 class="font-bold">پرونده‌های کامل دانش‌آموزان (31 ستون جدید):</h4>
                <label class="flex items-center gap-2 text-xs font-bold cursor-pointer"><input type="checkbox" id="selectAllStudents" checked onclick="toggleAllCheckboxes(this)"><span>انتخاب همه</span></label>
            </div>
            <div class="table-container">
                <table class="text-[11px]">
                    <thead><tr><th>انتخاب</th><th>کد ملی</th><th>نام دانش‌آموز</th><th>کلاس</th><th>کد پستی</th><th>آدرس</th><th>بیماری/ورزش</th><th>مادر (تحصیلات/شغل)</th><th>پدر (تحصیلات/شغل)</th><th>محل تولد</th><th>وضعیت</th></tr></thead>
                    <tbody>
                        <?php foreach ($parsedStudents as $st): ?>
                        <tr class="<?php echo $st['exists'] ? 'bg-amber-50' : ''; ?>">
                            <td><input type="checkbox" name="selected_nids[]" value="<?php echo clean($st['national_id']); ?>" class="student-chk" checked></td>
                            <td class="font-mono font-bold"><?php echo clean($st['national_id']); ?></td>
                            <td class="font-bold"><?php echo clean($st['first_name'] . ' ' . $st['last_name']); ?><div class="text-[10px] text-muted">پدر: <?php echo clean($st['father_name']); ?> - مادر: <?php echo clean($st['mother_name']); ?></div></td>
                            <td><span class="badge badge-info"><?php echo clean($st['class_name']); ?></span></td>
                            <td class="font-mono"><?php echo clean($st['postal_code'] ?: '---'); ?></td>
                            <td class="truncate max-w-[150px]"><?php echo clean($st['home_address'] ?: '---'); ?></td>
                            <td><?php echo clean($st['specific_disease_title'] ?: '---'); ?><?php if($st['has_sport_limitation']) echo '<br><span class="badge badge-warning">محدودیت ورزش</span>'; ?><?php if($st['is_foreign']) echo '<br><span class="badge badge-danger">اتباع</span>'; ?></td>
                            <td><?php echo clean($st['mother_qualification'] ?: '---'); ?> / <?php echo clean($st['mother_job'] ?: '---'); ?></td>
                            <td><?php echo clean($st['father_qualification'] ?: '---'); ?> / <?php echo clean($st['father_job'] ?: '---'); ?></td>
                            <td><?php echo clean($st['birth_place'] ?: '---'); ?> - <?php echo clean($st['birth_year'].'/'.$st['birth_month'].'/'.$st['birth_day']); ?></td>
                            <td><?php echo $st['exists'] ? '<span class="badge bg-amber-500 text-white text-xs">آپدیت 🔄</span>' : '<span class="badge bg-green-600 text-white text-xs">جدید ➕</span>'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </form>
    <script>function toggleAllCheckboxes(m){document.querySelectorAll('.student-chk').forEach(c=>c.checked=m.checked);}</script>

    <?php elseif ($step == 3): ?>
    <div class="card text-center py-8 shadow-lg">
        <div class="text-5xl mb-4">🎉</div>
        <h2 class="text-2xl font-bold text-green-600 mb-2">پرونده کامل دانش‌آموزان ثبت شد!</h2>
        <p class="text-sm mb-6">تعداد <b><?php echo tr_num($_GET['updated'] ?? 0, 'fa'); ?></b> بروزرسانی و <b><?php echo tr_num($_GET['inserted'] ?? 0, 'fa'); ?></b> جدید با تمام فیلدهای جامع (کد پستی، آدرس، بیماری، شغل والدین...) ثبت شد.</p>
        <div class="flex justify-center gap-4"><a href="students.php" class="btn btn-primary px-6">مدیریت دانش‌آموزان</a><a href="export-students.php" class="btn btn-success px-6">اکسپورت جامع</a></div>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
