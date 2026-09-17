<?php
/**
 * Student Management (students.php) - v4.29.1
 * Comprehensive student profile with new fields (7reporte.csv format)
 * v4.29.1: year-transfer copy now carries all new profile fields.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/academic_year_helpers.php';
require_once __DIR__ . '/includes/school_roles.php';
require_once __DIR__ . '/includes/school_sort.php';
require_once __DIR__ . '/includes/student_profile_fields.php';
$formErrors=[]; $submittedStudent=null;
ensure_school_roles_schema();


if (!current_teacher_is_executive()) require_permission('manage_students');
try { DB::execute("ALTER TABLE students ADD COLUMN academic_year varchar(20) DEFAULT NULL"); } catch (Exception $e) {}
try { DB::execute("CREATE TABLE IF NOT EXISTS report_parent_reviews (id int(11) NOT NULL AUTO_INCREMENT, report_id int(11) NOT NULL, platform enum('bale','telegram') NOT NULL, chat_id varchar(80) NOT NULL, reviewed_at_jalali varchar(30) NOT NULL, PRIMARY KEY(id), UNIQUE KEY uniq_review (report_id, platform, chat_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (Exception $e) {}
sync_student_photos_from_uploads();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfer_students'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { set_flash_message('error','خطای CSRF'); redirect('students.php'); }
    $ids = array_values(array_filter(array_map('intval', $_POST['student_ids'] ?? [])));
    $sourceYear = trim($_POST['source_year'] ?? '');
    $destYear = unify_academic_year(trim($_POST['dest_year'] ?? get_setting('current_academic_year','1404/1405')));
    $destGrade = trim($_POST['dest_grade'] ?? '');
    $destClass = norm_class_str($_POST['dest_class'] ?? '');
    if (!$ids || $destYear === '' || $destGrade === '' || $destClass === '') {
        set_flash_message('error', 'برای انتقال، انتخاب دانش‌آموزان، سال، پایه و کلاس مقصد الزامی است.');
        redirect('students.php');
    }
    $moved=0; $copied=0; $updated=0; $errors=[]; $movedNames=[]; $copiedNames=[]; $updatedNames=[];
    foreach ($ids as $sid) {
        try {
            $st = DB::fetch("SELECT * FROM students WHERE id=?", [$sid]); if(!$st) { $errors[]="ID $sid یافت نشد"; continue; }
            $fullName = trim(($st['first_name'] ?? '') . ' ' . ($st['last_name'] ?? ''));
            $srcYear = $sourceYear ?: ($st['academic_year'] ?: get_setting('current_academic_year','1404/1405'));
            if ($srcYear === $destYear) {
                // MOVE: same academic year => update current record, do not duplicate.
                DB::execute("UPDATE students SET academic_year=?, grade_level=?, class_name=? WHERE id=?", [$destYear,$destGrade,$destClass,$sid]);
                $moved++; $movedNames[]=$fullName;
            } else {
                // COPY/UPDATE: different year => keep source record and upsert destination record by national_id + academic_year.
                $exists = DB::fetch("SELECT id FROM students WHERE national_id=? AND academic_year=?", [$st['national_id'],$destYear]);
                if ($exists) {
                    DB::execute("UPDATE students SET grade_level=?, class_name=?, first_name=?, last_name=?, father_name=?, phone=?, father_phone=?, mother_phone=?, photo_url=?, password=?, status='active', is_temp=0 WHERE id=?", [$destGrade,$destClass,$st['first_name'],$st['last_name'],$st['father_name'],$st['phone'],$st['father_phone'],$st['mother_phone'],$st['photo_url'],$st['password'],$exists['id']]);
                    $updated++; $updatedNames[]=$fullName;
                } else {
                    // v4.29.1: carry ALL comprehensive profile fields when copying to a new academic year
                    DB::execute("INSERT INTO students (national_id, student_code, serial_number, first_name, last_name, father_name, birth_date, birth_year, birth_month, birth_day, gender, grade_level, class_name, photo_url, password, phone, father_phone, mother_phone, student_mobile, postal_code, home_address, home_phone, birth_place, place_issued, religion_title, nationality_title, is_foreign, housing_title, cover_title, specific_disease_title, has_sport_limitation, sport_limitation_desc, mother_qualification, mother_job, father_qualification, father_job, mother_name, mother_first_name, mother_last_name, mother_deceased, father_deceased, father_guardian, mother_guardian, last_year_average, status, is_temp, academic_year) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                        [$st['national_id'],$st['student_code'],$st['serial_number'],$st['first_name'],$st['last_name'],$st['father_name'],$st['birth_date'],$st['birth_year'] ?? null,$st['birth_month'] ?? null,$st['birth_day'] ?? null,$st['gender'],$destGrade,$destClass,$st['photo_url'],$st['password'],$st['phone'],$st['father_phone'],$st['mother_phone'],$st['student_mobile'] ?? null,$st['postal_code'] ?? null,$st['home_address'] ?? null,$st['home_phone'] ?? null,$st['birth_place'] ?? null,$st['place_issued'] ?? null,$st['religion_title'] ?? null,$st['nationality_title'] ?? null,$st['is_foreign'] ?? 0,$st['housing_title'] ?? null,$st['cover_title'] ?? null,$st['specific_disease_title'] ?? null,$st['has_sport_limitation'] ?? 0,$st['sport_limitation_desc'] ?? null,$st['mother_qualification'] ?? null,$st['mother_job'] ?? null,$st['father_qualification'] ?? null,$st['father_job'] ?? null,$st['mother_name'] ?? null,$st['mother_first_name'] ?? null,$st['mother_last_name'] ?? null,$st['mother_deceased'] ?? 0,$st['father_deceased'] ?? 0,$st['father_guardian'] ?? 0,$st['mother_guardian'] ?? 0,$st['last_year_average'] ?? null,'active',0,$destYear]);
                    $copied++; $copiedNames[]=$fullName;
                }
            }
        } catch (Exception $e) { $errors[] = 'خطا برای ID ' . $sid . ': ' . $e->getMessage(); }
    }
    // Ensure destination class exists for the target year.
    $cls = DB::fetch("SELECT id FROM classes WHERE academic_year=? AND name=?", [$destYear, $destClass]);
    if (!$cls) DB::execute("INSERT INTO classes (name, grade, academic_year) VALUES (?, ?, ?)", [$destClass, $destGrade, $destYear]);
    $msg = "انتقال به سال $destYear، پایه $destGrade، کلاس $destClass انجام شد.\n";
    $msg .= "جابجا شده‌ها ($moved): " . ($movedNames ? implode('، ', $movedNames) : '---') . "\n";
    $msg .= "ایجاد شده‌ها ($copied): " . ($copiedNames ? implode('، ', $copiedNames) : '---') . "\n";
    $msg .= "بروزرسانی/همپوشانی ($updated): " . ($updatedNames ? implode('، ', $updatedNames) : '---');
    if ($errors) $msg .= "\nخطاها: " . implode(' | ', $errors);
    set_flash_message($errors ? 'warning' : 'success', $msg);
    redirect('students.php?academic_year=' . urlencode($destYear) . '&grade_level=' . urlencode($destGrade) . '&class_name=' . urlencode($destClass));
}

// Handle Bulk Delete Students - NEW in v4.28.19
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete_students'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { set_flash_message('error','خطای CSRF'); redirect('students.php'); }
    $ids = array_values(array_filter(array_map('intval', $_POST['student_ids'] ?? [])));
    if (!$ids) {
        set_flash_message('error','هیچ دانش‌آموزی برای حذف دسته‌جمعی انتخاب نشده');
        redirect('students.php');
    }
    $deleted = 0;
    $deletedNames = [];
    foreach ($ids as $sid) {
        try {
            $st = DB::fetch("SELECT * FROM students WHERE id=?", [$sid]);
            if (!$st) continue;
            $fullName = trim(($st['first_name'] ?? '') . ' ' . ($st['last_name'] ?? ''));
            // Delete related reports
            DB::execute("DELETE FROM reports WHERE student_id=?", [$sid]);
            // Delete discipline records
            try { DB::execute("DELETE FROM student_discipline_records WHERE student_id=?", [$sid]); } catch (Exception $e) {}
            // Delete bot links
            try { DB::execute("DELETE FROM bale_bot_users WHERE student_id=?", [$sid]); } catch (Exception $e) {}
            try { DB::execute("DELETE FROM telegram_bot_users WHERE student_id=?", [$sid]); } catch (Exception $e) {}
            // Delete exam seating
            try { DB::execute("DELETE FROM exam_student_seating WHERE student_id=?", [$sid]); } catch (Exception $e) {}
            try { DB::execute("DELETE FROM exam_assignments WHERE student_id=?", [$sid]); } catch (Exception $e) {}
            // Delete online exam attempts
            try {
                $atts = DB::fetchAll("SELECT id FROM online_exam_attempts WHERE student_id=?", [$sid]);
                $attIds = array_column($atts, 'id');
                if (!empty($attIds)) {
                    $ph = implode(',', array_fill(0, count($attIds), '?'));
                    DB::execute("DELETE FROM online_exam_answers WHERE attempt_id IN ($ph)", $attIds);
                    DB::execute("DELETE FROM online_exam_proctoring_logs WHERE attempt_id IN ($ph)", $attIds);
                    DB::execute("DELETE FROM online_exam_live_sessions WHERE attempt_id IN ($ph)", $attIds);
                    DB::execute("DELETE FROM online_exam_webcam_requests WHERE attempt_id IN ($ph)", $attIds);
                    DB::execute("DELETE FROM online_exam_webcam_snapshots WHERE attempt_id IN ($ph)", $attIds);
                    DB::execute("DELETE FROM online_exam_attempts WHERE id IN ($ph)", $attIds);
                }
            } catch (Exception $e) {}
            // Finally delete student
            DB::execute("DELETE FROM students WHERE id=?", [$sid]);
            $deleted++;
            $deletedNames[] = $fullName;
        } catch (Exception $e) {
            error_log('Bulk delete error: '.$e->getMessage());
        }
    }
    log_activity($_SESSION['admin_id'] ?? null, 'حذف دسته‌جمعی دانش‌آموزان', "تعداد $deleted دانش‌آموز حذف شد: ".implode('، ', $deletedNames));
    set_flash_message('success', "✅ $deleted دانش‌آموز با موفقیت به صورت دسته‌جمعی حذف شد:
".implode('، ', $deletedNames));
    redirect('students.php');
}

$action = $_GET['action'] ?? '';
$studentId = (int)($_GET['id'] ?? 0);

// Handle Delete Student
if ($action === 'delete' && $studentId > 0) {
    DB::execute("DELETE FROM students WHERE id = ?", [$studentId]);
    DB::execute("DELETE FROM reports WHERE student_id = ?", [$studentId]);
    log_activity($_SESSION['admin_id'], 'حذف دانش‌آموز', "دانش‌آموز ID: $studentId حذف شد.");
    set_flash_message('success', 'دانش‌آموز مورد نظر با موفقیت حذف شد.');
    redirect('students.php');
}

// Handle Reset Password
if ($action === 'reset_pass' && $studentId > 0) {
    $st = DB::fetch("SELECT national_id FROM students WHERE id = ?", [$studentId]);
    if ($st) {
        $newPass = password_hash($st['national_id'], PASSWORD_DEFAULT);
        DB::execute("UPDATE students SET password = ? WHERE id = ?", [$newPass, $studentId]);
        log_activity($_SESSION['admin_id'], 'بازنشانی کلمه عبور دانش‌آموز', "رمز دانش‌آموز ID: $studentId بازنشانی شد.");
        set_flash_message('success', 'کلمه عبور دانش‌آموز به کد ملی وی بازنشانی شد.');
    }
    redirect('students.php');
}

// Handle Save/Edit POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_student'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'خطای امنیتی CSRF');
        redirect('students.php');
    }
    $nid    = student_ascii_digits($_POST['national_id'] ?? '');
    $code   = trim($_POST['student_code'] ?? '');
    try { $oldSerial=$studentId>0?student_record_serial(DB::fetch('SELECT serial_number,student_code FROM students WHERE id=?',[$studentId])?:[]):''; $ser=student_serial_from_form($_POST,$oldSerial); } catch(InvalidArgumentException $e) { $formErrors[]=$e->getMessage(); $ser=''; }
    $fname  = trim($_POST['first_name'] ?? '');
    $lname  = trim($_POST['last_name'] ?? '');
    $father = trim($_POST['father_name'] ?? '');
    $bdate  = trim($_POST['birth_date'] ?? ''); // v4.68.0: فیلد متنی حذف شد؛ از سه بخش ساخته می‌شود
    $grade  = trim($_POST['grade_level'] ?? 'دهم');
    $cname  = norm_class_str($_POST['class_name'] ?? '101');
    // v4.29.2: academic year is now selected in the form and saved with the record
    $stYear = unify_academic_year(trim($_POST['academic_year'] ?? get_setting('current_academic_year', '1404/1405')));
    if ($grade === '' && $cname !== '') { $grade = infer_grade_from_class_name($cname); }
    $phone  = student_ascii_digits($_POST['phone'] ?? '');
    $fMob   = student_ascii_digits($_POST['father_phone'] ?? '');
    $mMob   = student_ascii_digits($_POST['mother_phone'] ?? '');
    $status = $_POST['status'] ?? 'active';

    // New comprehensive fields
    $postal = student_ascii_digits($_POST['postal_code'] ?? '');
    $homeAddr = trim($_POST['home_address'] ?? '');
    $homePh = student_ascii_digits($_POST['home_phone'] ?? '');
    $birthPlace = trim($_POST['birth_place'] ?? '');
    $placeIssued = trim($_POST['place_issued'] ?? '');
    $religion = trim($_POST['religion_title'] ?? '');
    $nationality = trim($_POST['nationality_title'] ?? 'ایرانی');
    $isForeign = isset($_POST['is_foreign']) ? 1 : 0;
    $housing = trim($_POST['housing_title'] ?? '');
    $cover = trim($_POST['cover_title'] ?? '');
    $disease = trim($_POST['specific_disease_title'] ?? '');
    $hasSport = isset($_POST['has_sport_limitation']) ? 1 : 0;
    $motherQual = trim($_POST['mother_qualification'] ?? '');
    $motherJob = trim($_POST['mother_job'] ?? '');
    $fatherQual = trim($_POST['father_qualification'] ?? '');
    $fatherJob = trim($_POST['father_job'] ?? '');
    $motherName = trim($_POST['mother_name'] ?? '');
    $motherLast = trim($_POST['mother_last_name'] ?? '');
    $motherFirst = $motherName;
    if ($motherName !== '' && $motherLast === '') {
        $parts = preg_split('/\s+/', $motherName);
        if (count($parts)>=2) { $motherFirst = $parts[0]; $motherLast = implode(' ', array_slice($parts,1)); } else { $motherFirst = $motherName; }
    }
    $sMobile = student_ascii_digits($_POST['student_mobile'] ?? '');
    $lastAvg = trim($_POST['last_year_average'] ?? '');
    $bYear = (int)student_ascii_digits($_POST['birth_year'] ?? 0);
    $bMonth = (int)student_ascii_digits($_POST['birth_month'] ?? 0);
    $bDay = (int)student_ascii_digits($_POST['birth_day'] ?? 0);
    // v4.68.0: تاریخ تولد والدین (سه‌بخشی؛ اختیاری)
    $fbY=(int)student_ascii_digits($_POST['father_birth_year']??0); $fbM=(int)student_ascii_digits($_POST['father_birth_month']??0); $fbD=(int)student_ascii_digits($_POST['father_birth_day']??0);
    $mbY=(int)student_ascii_digits($_POST['mother_birth_year']??0); $mbM=(int)student_ascii_digits($_POST['mother_birth_month']??0); $mbD=(int)student_ascii_digits($_POST['mother_birth_day']??0);
    $fatherBdate = ($fbY && $fbM && $fbD) ? sprintf('%04d/%02d/%02d', $fbY, $fbM, $fbD) : '';
    $motherBdate = ($mbY && $mbM && $mbD) ? sprintf('%04d/%02d/%02d', $mbY, $mbM, $mbD) : '';
    $motherDeceased = isset($_POST['mother_deceased']) ? 1 : 0;
    $fatherDeceased = isset($_POST['father_deceased']) ? 1 : 0;
    // v4.34.0: guardian flags (default no)
    $fatherGuardian = isset($_POST['father_guardian']) ? 1 : 0;
    $motherGuardian = isset($_POST['mother_guardian']) ? 1 : 0;
    if ($bYear && $bMonth && $bDay) { $bdate = sprintf('%04d/%02d/%02d', $bYear, $bMonth, $bDay); }

    if (empty($nid) || empty($fname) || empty($lname)) {
        $formErrors[]='کد ملی، نام و نام خانوادگی الزامی است.';
    }
    if(!$isForeign && !preg_match('/^[0-9]{10}$/D',$nid))$formErrors[]='کد ملی باید ۱۰ رقم باشد.';
    if($postal!==''&&!preg_match('/^[0-9]{10}$/D',$postal))$formErrors[]='کد پستی باید ۱۰ رقم باشد.';
    foreach(['birth','father_birth','mother_birth'] as $prefix)foreach(['year','month','day'] as $part){$raw=student_ascii_digits($_POST[$prefix.'_'.$part]??'');if($raw!==''&&!ctype_digit($raw))$formErrors[]='اجزای تاریخ تولد باید فقط عدد باشند.';}
    foreach([[$bYear,$bMonth,$bDay],[$fbY,$fbM,$fbD],[$mbY,$mbM,$mbD]] as $dateParts){
        [$yy,$mm,$dd]=$dateParts;
        if(($yy||$mm||$dd)&&($yy<1300||$yy>1500||$mm<1||$mm>12||$dd<1||$dd>($mm>6?30:31)))$formErrors[]='تاریخ تولد را کامل و با سال، ماه و روز معتبر وارد کنید.';
    }
    if($formErrors){$action=$studentId>0?'edit':'add';$submittedStudent=$_POST;}
    else {
    $savedId=$studentId;
    if ($studentId > 0) {
        DB::execute("UPDATE students SET national_id=?, student_code=?, serial_number=?, first_name=?, last_name=?, father_name=?, birth_date=?, birth_year=?, birth_month=?, birth_day=?, grade_level=?, class_name=?, academic_year=?, phone=?, father_phone=?, mother_phone=?, student_mobile=?, postal_code=?, home_address=?, home_phone=?, birth_place=?, place_issued=?, religion_title=?, nationality_title=?, is_foreign=?, housing_title=?, cover_title=?, specific_disease_title=?, has_sport_limitation=?, mother_qualification=?, mother_job=?, father_qualification=?, father_job=?, mother_name=?, mother_first_name=?, mother_last_name=?, mother_deceased=?, father_deceased=?, father_guardian=?, mother_guardian=?, father_birth_date=?, mother_birth_date=?, last_year_average=?, status=?, is_temp=0 WHERE id=?",
        [$nid, $code, $ser, $fname, $lname, $father, $bdate, $bYear, $bMonth, $bDay, $grade, $cname, $stYear, $phone, $fMob, $mMob, $sMobile, $postal, $homeAddr, $homePh, $birthPlace, $placeIssued, $religion, $nationality, $isForeign, $housing, $cover, $disease, $hasSport, $motherQual, $motherJob, $fatherQual, $fatherJob, $motherName, $motherFirst, $motherLast, $motherDeceased, $fatherDeceased, $fatherGuardian, $motherGuardian, $fatherBdate, $motherBdate, $lastAvg, $status, $studentId]);
        log_activity($_SESSION['admin_id'], 'ویرایش دانش‌آموز جامع', "اطلاعات کامل دانش‌آموز $fname $lname ویرایش شد.");
        set_flash_message('success', 'اطلاعات کامل دانش‌آموز با تمام فیلدهای جدید با موفقیت ویرایش شد.');
    } else {
        $pass = password_hash($nid, PASSWORD_DEFAULT);
        DB::execute("INSERT INTO students (national_id, student_code, serial_number, first_name, last_name, father_name, birth_date, birth_year, birth_month, birth_day, grade_level, class_name, academic_year, password, phone, father_phone, mother_phone, student_mobile, postal_code, home_address, home_phone, birth_place, place_issued, religion_title, nationality_title, is_foreign, housing_title, cover_title, specific_disease_title, has_sport_limitation, mother_qualification, mother_job, father_qualification, father_job, mother_name, mother_first_name, mother_last_name, mother_deceased, father_deceased, father_guardian, mother_guardian, father_birth_date, mother_birth_date, last_year_average, status, is_temp) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [$nid, $code, $ser, $fname, $lname, $father, $bdate, $bYear, $bMonth, $bDay, $grade, $cname, $stYear, $pass, $phone, $fMob, $mMob, $sMobile, $postal, $homeAddr, $homePh, $birthPlace, $placeIssued, $religion, $nationality, $isForeign, $housing, $cover, $disease, $hasSport, $motherQual, $motherJob, $fatherQual, $fatherJob, $motherName, $motherFirst, $motherLast, $motherDeceased, $fatherDeceased, $fatherGuardian, $motherGuardian, $fatherBdate, $motherBdate, $lastAvg, $status, 0]);
        $savedId=(int)DB::lastInsertId();
        log_activity($_SESSION['admin_id'], 'افزودن دانش‌آموز جامع', "دانش‌آموز جدید $fname $lname با پرونده کامل افزوده شد.");
        set_flash_message('success', 'دانش‌آموز جدید با پرونده کامل و تمام فیلدهای جدید با موفقیت ثبت شد.');
    }
    if(array_key_exists('gender',$_POST)){ $gender=in_array($_POST['gender'],['male','female'],true)?$_POST['gender']:null; DB::execute('UPDATE students SET gender=? WHERE id=?',[$gender,$savedId]); }
    if(array_key_exists('sport_limitation_desc',$_POST))DB::execute('UPDATE students SET sport_limitation_desc=? WHERE id=?',[trim($_POST['sport_limitation_desc']),$savedId]);
    // Ensure the class exists for the selected year so it appears in future dropdowns
    if ($cname !== '') {
        $clsRow = DB::fetch("SELECT id FROM classes WHERE name=? AND academic_year=?", [$cname, $stYear]);
        if (!$clsRow) { try { DB::execute("INSERT INTO classes (name, grade, academic_year) VALUES (?,?,?)", [$cname, $grade ?: infer_grade_from_class_name($cname), $stYear]); } catch (Exception $e) {} }
    }
    redirect('students.php');
    }
}

/* v4.68.0: ستون تاریخ تولد پدر و مادر — افزودن امن در صورت نبود */
try {
    $stCols = DB::fetchAll("SHOW COLUMNS FROM students LIKE 'father_birth_date'");
    if (empty($stCols)) DB::execute("ALTER TABLE students ADD COLUMN father_birth_date VARCHAR(20) DEFAULT NULL");
    $stCols2 = DB::fetchAll("SHOW COLUMNS FROM students LIKE 'mother_birth_date'");
    if (empty($stCols2)) DB::execute("ALTER TABLE students ADD COLUMN mother_birth_date VARCHAR(20) DEFAULT NULL");
} catch (Throwable $e) { error_log('students parent-birth columns: ' . $e->getMessage()); }

require_once __DIR__ . '/includes/header.php';

if ($action === 'add' || $action === 'edit'):
    $editData = null;
    if ($action === 'edit' && $studentId > 0) {
        $editData = DB::fetch("SELECT * FROM students WHERE id = ?", [$studentId]);
    }
    if($submittedStudent!==null)$editData=$submittedStudent;
    // v4.29.2: cascading Year -> Grade -> Class selects instead of free text inputs
    $formYearOptions = get_academic_years_for_filter();
    $formYGCMap = get_year_grade_class_map($formYearOptions);
    $formSelectedYear = $editData['academic_year'] ?? get_setting('current_academic_year', '1404/1405');
    $formSelectedGrade = $editData['grade_level'] ?? '';
    $formSelectedClass = $editData['class_name'] ?? '';
?>
<?php require __DIR__.'/includes/student_profile_form.php'; ?>
        <script>
        // v4.29.2: cascading Year -> Grade -> Class for the manual student form
        window.stYGCMap = <?php echo json_encode($formYGCMap, JSON_UNESCAPED_UNICODE); ?>;
        window.stInitGrade = <?php echo json_encode($formSelectedGrade, JSON_UNESCAPED_UNICODE); ?>;
        window.stInitClass = <?php echo json_encode($formSelectedClass, JSON_UNESCAPED_UNICODE); ?>;
        function refreshStGrades(){
            const ySel=document.getElementById('stYearSelect'), gSel=document.getElementById('stGradeSelect'), cSel=document.getElementById('stClassSelect');
            if(!ySel||!gSel||!cSel) return;
            const y=ySel.value||'';
            gSel.innerHTML=''; cSel.innerHTML='<option value="">ابتدا پایه را انتخاب کنید</option>';
            const grades=(window.stYGCMap[y]?.grades)||[];
            if(!grades.length){ gSel.innerHTML='<option value="">پایه‌ای برای این سال تعریف نشده</option>'; return; }
            gSel.add(new Option('انتخاب پایه...','',false,false));
            grades.forEach(g=>gSel.add(new Option(g,g,false,g===window.stInitGrade)));
            refreshStClasses();
        }
        function refreshStClasses(){
            const ySel=document.getElementById('stYearSelect'), gSel=document.getElementById('stGradeSelect'), cSel=document.getElementById('stClassSelect');
            if(!ySel||!gSel||!cSel) return;
            const y=ySel.value||'', g=gSel.value||'';
            cSel.innerHTML='';
            if(!g){ cSel.innerHTML='<option value="">ابتدا پایه را انتخاب کنید</option>'; return; }
            const classes=(window.stYGCMap[y]?.classes?.[g])||[];
            if(!classes.length){ cSel.innerHTML='<option value="">کلاسی برای این پایه تعریف نشده</option>'; return; }
            cSel.add(new Option('انتخاب کلاس...','',false,false));
            classes.forEach(c=>cSel.add(new Option(c,c,false,c===window.stInitClass)));
        }
        document.addEventListener('DOMContentLoaded',()=>setTimeout(refreshStGrades,50));
        </script>
<?php
else:
    $search = trim($_GET['q'] ?? '');
    $systemDefaultYear = get_setting('current_academic_year', '');
    $latestYearRow = DB::fetch("SELECT academic_year FROM (SELECT academic_year FROM reports WHERE academic_year<>'' UNION SELECT academic_year FROM class_schedules WHERE academic_year<>'' UNION SELECT academic_year FROM classes WHERE academic_year<>'' UNION SELECT academic_year FROM students WHERE academic_year IS NOT NULL AND academic_year<>'') y ORDER BY academic_year DESC LIMIT 1");
    // Use the system default academic year everywhere, falling back to the newest known year only if no default is configured.
    $defaultYear = $systemDefaultYear !== '' ? $systemDefaultYear : ($latestYearRow['academic_year'] ?? '1404/1405');
    $filterYear = trim($_GET['academic_year'] ?? $defaultYear);
    $filterGrade = trim($_GET['grade_level'] ?? '');
    $filterClass = trim($_GET['class_name'] ?? '');
    $disciplineFilter = trim($_GET['discipline_filter'] ?? '');
    $academicFilter = trim($_GET['academic_filter'] ?? '');
    $minGpa = trim($_GET['min_gpa'] ?? '');
    $maxGpa = trim($_GET['max_gpa'] ?? '');
    $subjectFilter = trim($_GET['subject_name'] ?? '');

    $where = ["s.status IN ('active','inactive')"];
    $params = [];
    if ($search !== '') {
        $where[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.national_id LIKE ? OR s.student_code LIKE ? OR s.father_name LIKE ?)";
        for ($i=0; $i<5; $i++) $params[] = "%$search%";
    }
    if ($filterGrade !== '') { $where[] = "s.grade_level = ?"; $params[] = $filterGrade; }
    if ($filterClass !== '') { $where[] = "s.class_name = ?"; $params[] = $filterClass; }
    if ($disciplineFilter === 'has_records') $where[] = "dr.discipline_count > 0";
    if ($disciplineFilter === 'no_records') $where[] = "COALESCE(dr.discipline_count,0) = 0";
    if ($disciplineFilter === 'recent_30') $where[] = "dr.discipline_count > 0 AND dr.last_disc_jalali >= ?"; // Jalali lexical works with yyyy/mm/dd
    if ($disciplineFilter === 'recent_30') $params[] = jdate('Y/m/d', time() - 30*86400);
    if ($minGpa !== '') { $where[] = "lr.latest_gpa >= ?"; $params[] = (float)$minGpa; }
    if ($maxGpa !== '') { $where[] = "lr.latest_gpa <= ?"; $params[] = (float)$maxGpa; }
    if ($academicFilter === 'excellent') $where[] = "lr.latest_gpa >= 17";
    if ($academicFilter === 'weak') $where[] = "lr.latest_gpa > 0 AND lr.latest_gpa < 12";
    if ($academicFilter === 'failed') $where[] = "fg.failed_count > 0";
    if ($subjectFilter !== '') { $where[] = "sg.student_id IS NOT NULL"; }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT s.*, COALESCE(dr.discipline_count,0) AS discipline_count, COALESCE(dr.unreviewed_count,0) AS unreviewed_count, COALESCE(rr.unreviewed_reports,0) AS unreviewed_reports, dr.last_disc_jalali, lr.latest_gpa, lr.latest_month, COALESCE(fg.failed_count,0) AS failed_count
        FROM students s
        LEFT JOIN (SELECT student_id, COUNT(*) AS discipline_count, SUM(CASE WHEN COALESCE(review_status,'pending')='pending' THEN 1 ELSE 0 END) AS unreviewed_count, MAX(occurred_at_jalali) AS last_disc_jalali FROM student_discipline_records GROUP BY student_id) dr ON dr.student_id=s.id
        LEFT JOIN (SELECT r1.student_id, r1.gpa AS latest_gpa, r1.report_month AS latest_month FROM reports r1 JOIN (SELECT student_id, MAX(id) AS max_id FROM reports GROUP BY student_id) x ON x.max_id=r1.id) lr ON lr.student_id=s.id
        LEFT JOIN (SELECT r.student_id, COUNT(*) AS unreviewed_reports FROM reports r LEFT JOIN report_parent_reviews rv ON rv.report_id=r.id WHERE rv.id IS NULL GROUP BY r.student_id) rr ON rr.student_id=s.id
        LEFT JOIN (SELECT r.student_id, COUNT(*) AS failed_count FROM reports r JOIN report_grades rg ON rg.report_id=r.id WHERE rg.score < 10 GROUP BY r.student_id) fg ON fg.student_id=s.id
        LEFT JOIN (SELECT DISTINCT r.student_id FROM reports r JOIN report_grades rg ON rg.report_id=r.id WHERE rg.subject_name = ?) sg ON sg.student_id=s.id
        WHERE $whereSql";
    $allParams = array_merge([$subjectFilter], $params);
    if ($filterYear !== '') {
        $sql .= " AND (s.academic_year = ? OR (s.academic_year IS NULL AND EXISTS(SELECT 1 FROM reports ry WHERE ry.student_id=s.id AND ry.academic_year=?)) OR (s.academic_year IS NULL AND EXISTS(SELECT 1 FROM class_schedules cs WHERE cs.academic_year=? AND cs.class_name=s.class_name)) OR (s.academic_year IS NULL AND EXISTS(SELECT 1 FROM classes c WHERE c.academic_year=? AND c.name=s.class_name)))";
        $allParams[] = $filterYear; $allParams[] = $filterYear; $allParams[] = $filterYear; $allParams[] = $filterYear;
    }
    $studentsList = DB::fetchAll($sql, $allParams);
    persian_usort_students($studentsList);
    $studentsList = array_slice($studentsList, 0, 500);

    $yearOptions = get_academic_years_for_filter();
    $gradeOptions = get_unified_grade_options($filterYear ?: get_setting('current_academic_year','1404/1405'));
    $classOptions = get_unified_class_options($filterYear ?: get_setting('current_academic_year','1404/1405'));
    $subjectOptions = DB::fetchAll("SELECT DISTINCT subject_name FROM class_schedules WHERE subject_name<>'' UNION SELECT DISTINCT name AS subject_name FROM subjects WHERE name<>'' UNION SELECT DISTINCT subject_name FROM report_grades WHERE subject_name<>'' ORDER BY subject_name");
?>
<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold">مدیریت پرونده دانش‌آموزان</h2>
            <p class="text-sm text-muted">فیلتر پیشرفته، عملیات دسته‌جمعی و گزارش‌گیری اداری</p>
        </div>
        <?php /* v4.134.0: «موارد انضباطی» و «افزودن دانش‌آموز جدید» به نوار
              عملیاتِ کنار نتایج منتقل شدند تا همهٔ دکمه‌های کار با
              دانش‌آموزان یک‌جا باشند. اینجا دیگر دکمه‌ای نیست. */ ?>
    </div>

    <div class="card p-4">
        <form method="GET" class="filters-line">
            <div class="filter-primary"><label class="text-xs font-bold">جستجو</label><input type="text" name="q" class="form-input" placeholder="نام، نام خانوادگی، کد ملی، نام پدر..." value="<?php echo clean($search); ?>"></div>
            <div class="filter-primary"><label class="text-xs font-bold">سال تحصیلی</label><select name="academic_year" class="form-select"><option value="">همه</option><?php foreach($yearOptions as $yo): ?><option value="<?php echo clean($yo['academic_year']); ?>" <?php echo $filterYear===$yo['academic_year']?'selected':''; ?>><?php echo clean($yo['academic_year']); ?></option><?php endforeach; ?></select></div>
            <div class="filter-primary"><label class="text-xs font-bold">پایه</label><select name="grade_level" class="form-select"><option value="">همه</option><?php foreach($gradeOptions as $go): ?><option value="<?php echo clean($go['grade_level']); ?>" <?php echo $filterGrade===$go['grade_level']?'selected':''; ?>><?php echo clean($go['grade_level']); ?></option><?php endforeach; ?></select></div>
            <div class="flex gap-2"><button class="btn btn-primary">فیلتر</button><a href="students.php" class="btn btn-secondary">حذف</a></div>
            <details class="filter-more" <?php echo ($filterClass||$disciplineFilter||$academicFilter||$subjectFilter||$minGpa||$maxGpa)?'open':''; ?>><summary>گزینه‌های بیشتر</summary>
                <div class="grid grid-cols-5 gap-2 mt-2">
                    <div><label class="text-xs font-bold">کلاس</label><select name="class_name" class="form-select"><option value="">همه</option><?php foreach($classOptions as $co): ?><option value="<?php echo clean($co['class_name']); ?>" <?php echo $filterClass===$co['class_name']?'selected':''; ?>><?php echo clean($co['class_name']); ?></option><?php endforeach; ?></select></div>
                    <div><label class="text-xs font-bold">فیلتر انضباطی</label><select name="discipline_filter" class="form-select"><option value="">همه</option><option value="has_records" <?php echo $disciplineFilter==='has_records'?'selected':''; ?>>دارای مورد</option><option value="no_records" <?php echo $disciplineFilter==='no_records'?'selected':''; ?>>بدون مورد</option><option value="recent_30" <?php echo $disciplineFilter==='recent_30'?'selected':''; ?>>۳۰ روز اخیر</option></select></div>
                    <div><label class="text-xs font-bold">فیلتر تحصیلی</label><select name="academic_filter" class="form-select"><option value="">همه</option><option value="excellent" <?php echo $academicFilter==='excellent'?'selected':''; ?>>معدل عالی</option><option value="weak" <?php echo $academicFilter==='weak'?'selected':''; ?>>زیر ۱۲</option><option value="failed" <?php echo $academicFilter==='failed'?'selected':''; ?>>نمره زیر ۱۰</option></select></div>
                    <div><label class="text-xs font-bold">درس خاص</label><select name="subject_name" class="form-select"><option value="">همه</option><?php foreach($subjectOptions as $so): ?><option value="<?php echo clean($so['subject_name']); ?>" <?php echo $subjectFilter===$so['subject_name']?'selected':''; ?>><?php echo clean($so['subject_name']); ?></option><?php endforeach; ?></select></div>
                    <div class="grid grid-cols-2 gap-1"><div><label class="text-xs">حداقل معدل</label><input name="min_gpa" class="form-input" value="<?php echo clean($minGpa); ?>"></div><div><label class="text-xs">حداکثر</label><input name="max_gpa" class="form-input" value="<?php echo clean($maxGpa); ?>"></div></div>
                </div>
            </details>
        </form>
    </div>

    <form method="POST" action="student-bulk-report.php" id="bulkStudentsForm" class="card">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <div class="flex justify-between items-center mb-4 flex-wrap gap-2">
            <h3 class="font-bold">نتایج: <?php echo tr_num(count($studentsList),'fa'); ?> دانش‌آموز</h3>
            <div class="flex gap-2 flex-wrap">
                <?php /* v4.134.0: از سربرگ صفحه به اینجا منتقل شدند. */ ?>
                <a href="deputy-panel.php" class="btn btn-warning text-xs">موارد انضباطی</a>
                <a href="students.php?action=add" class="btn btn-primary text-xs">+ افزودن دانش‌آموز جدید</a>
                <button type="button" class="btn btn-primary text-xs" onclick="openReportModal()">گزارش</button>
                <button type="button" class="btn btn-warning text-xs" onclick="openTransferModal()">انتقال دانش‌آموزان</button>
                <button type="submit" formaction="students.php" formmethod="POST" name="bulk_delete_students" value="1" class="btn btn-danger text-xs" onclick="return confirm('⚠️ آیا از حذف دسته‌جمعی دانش‌آموزان انتخاب شده اطمینان دارید؟\n\nاین عملیات حذف می‌کند:\n- پرونده دانش‌آموز\n- کارنامه‌ها\n- موارد انضباطی\n- اتصالات ربات\n- آزمون‌ها\n\nقابل بازگشت نیست!');" style="background:#dc2626;color:white;">حذف انتخاب شده‌ها</button>
            </div>
        </div>
        <div class="table-container">
            <table id="studentsTable">
                <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.st-check').forEach(c=>c.checked=this.checked)"></th><th>کد ملی</th><th>نام خانوادگی</th><th>نام</th><th>کلاس</th><th>پایه</th><th>آخرین معدل</th><th>انضباط</th><th>بررسی نشده</th><th>بررسی کارنامه</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                <tbody>
                <?php foreach ($studentsList as $st): ?>
                    <tr class="student-row" data-student-id="<?php echo (int)$st['id']; ?>">
                        <td><input type="checkbox" class="st-check" name="student_ids[]" value="<?php echo (int)$st['id']; ?>"></td>
                        <td class="font-mono text-xs dir-ltr"><?php echo clean($st['national_id']); ?></td>
                        <td class="font-bold"><?php echo clean($st['last_name']); ?></td>
                        <td class="font-bold"><?php echo clean($st['first_name']); ?></td>
                        <td><?php echo clean($st['class_name']); ?></td>
                        <td><?php echo clean($st['grade_level']); ?></td>
                        <td><?php echo $st['latest_gpa'] !== null ? format_score($st['latest_gpa']) : '---'; ?></td>
                        <td><span class="badge <?php echo $st['discipline_count']>0?'badge-warning':'badge-success'; ?>"><?php echo tr_num($st['discipline_count'],'fa'); ?> مورد</span></td>
                        <td><span class="badge <?php echo $st['unreviewed_count']>0?'badge-danger':'badge-success'; ?>"><?php echo tr_num($st['unreviewed_count'],'fa'); ?></span></td>
                        <td><button type="button" class="badge <?php echo $st['unreviewed_reports']>0?'badge-danger':'badge-success'; ?>" onclick="openStudentModal('reports', <?php echo $st['id']; ?>)"><?php echo tr_num($st['unreviewed_reports'],'fa'); ?></button></td>
                        <td><?php echo $st['status'] === 'active' ? '<span class="badge badge-success">فعال</span>' : '<span class="badge badge-danger">غیرفعال</span>'; ?></td>
                        <td><div class="flex gap-1 justify-end flex-wrap items-center"><button type="button" onclick="openStudentModal('reports', <?php echo $st['id']; ?>)" class="btn btn-outline text-xs px-2 py-1 text-blue-600 row-act-main">کارنامه‌ها</button><button type="button" onclick="openStudentModal('discipline', <?php echo $st['id']; ?>)" class="btn btn-warning text-xs px-2 py-1 row-act-main">انضباط</button><button type="button" class="btn btn-outline text-xs px-2 py-1 row-menu-btn" onclick="openRowMenu(event, <?php echo $st['id']; ?>)" title="عملیات" aria-label="عملیات">&#8942;</button></div></td>
                    </tr>
                <?php endforeach; if (!$studentsList): ?><tr><td colspan="12" class="text-center text-muted">دانش‌آموزی با این مشخصات یافت نشد.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        $transferMap = [];
        foreach ($yearOptions as $yo) {
            $yy = $yo['academic_year'];
            $transferMap[$yy] = ['grades'=>[], 'classes'=>[]];
            // Strictly collect grades/classes that exist in the selected academic year.
            $yearRows = DB::fetchAll("SELECT grade_level, class_name FROM students WHERE academic_year=? AND class_name<>'' UNION SELECT grade AS grade_level, name AS class_name FROM classes WHERE academic_year=? AND name<>'' UNION SELECT '' AS grade_level, class_name FROM class_schedules WHERE academic_year=? AND class_name<>''", [$yy,$yy,$yy]);
            foreach ($yearRows as $yr) {
                $className = norm_class_str($yr['class_name'] ?? '');
                if ($className === '') continue;
                $g = trim($yr['grade_level'] ?? '') ?: infer_grade_from_class_name($className);
                if ($g === '') continue;
                if (!in_array($g, $transferMap[$yy]['grades'], true)) $transferMap[$yy]['grades'][] = $g;
                if (!isset($transferMap[$yy]['classes'][$g])) $transferMap[$yy]['classes'][$g] = [];
                if (!in_array($className, $transferMap[$yy]['classes'][$g], true)) $transferMap[$yy]['classes'][$g][] = $className;
            }
            usort($transferMap[$yy]['grades'], fn($a,$b)=>grade_sort_weight($a)<=>grade_sort_weight($b));
            foreach ($transferMap[$yy]['classes'] as &$clist) {
                usort($clist, fn($a,$b)=>(grade_sort_weight(infer_grade_from_class_name($a))<=>grade_sort_weight(infer_grade_from_class_name($b))) ?: (class_number_weight($a)<=>class_number_weight($b)) ?: persian_compare($a, $b));
            }
            unset($clist);
        }
        ?>
        <div id="transferModal" class="modal-backdrop" style="display:none"><div class="modal-card card"><h3 class="font-bold text-primary mb-3">انتقال دانش‌آموزان انتخاب‌شده</h3><input type="hidden" name="source_year" value="<?php echo clean($filterYear); ?>"><div class="grid grid-cols-3 gap-3"><div><label class="text-xs">سال مقصد</label><select id="destYearSelect" name="dest_year" class="form-select" onchange="refreshTransferGrades()"><option value="">انتخاب سال...</option><?php foreach($yearOptions as $yo): ?><option value="<?php echo clean($yo['academic_year']); ?>" <?php echo $filterYear===$yo['academic_year']?'selected':''; ?>><?php echo clean($yo['academic_year']); ?></option><?php endforeach; ?></select></div><div><label class="text-xs">پایه مقصد</label><select id="destGradeSelect" name="dest_grade" class="form-select" onchange="refreshTransferClasses()"><option value="">ابتدا سال را انتخاب کنید</option></select></div><div><label class="text-xs">کلاس مقصد</label><select id="destClassSelect" name="dest_class" class="form-select"><option value="">ابتدا پایه را انتخاب کنید</option></select></div></div><p class="text-xs text-muted mt-3">این عملیات روی دیتابیس اعمال می‌شود. اگر سال مقصد همان سال فعلی باشد، Move انجام می‌شود؛ اگر متفاوت باشد، Copy/Update انجام شده و رکورد مبدا حفظ می‌شود.</p><div class="flex justify-end gap-2 mt-4"><button type="button" class="btn btn-secondary" onclick="closeTransferModal()">انصراف</button><button type="submit" formaction="students.php" formmethod="POST" name="transfer_students" value="1" class="btn btn-success" onclick="return confirmTransferStudents()">انتقال</button></div></div></div>
        <script>
        window.transferMap = <?php echo json_encode($transferMap, JSON_UNESCAPED_UNICODE); ?>;
        window.initialTransferGrade = <?php echo json_encode($filterGrade, JSON_UNESCAPED_UNICODE); ?>;
        window.initialTransferClass = <?php echo json_encode($filterClass, JSON_UNESCAPED_UNICODE); ?>;
        function refreshTransferGrades(){
            const y=document.getElementById('destYearSelect')?.value||'';
            const gSel=document.getElementById('destGradeSelect'), cSel=document.getElementById('destClassSelect');
            if(!gSel||!cSel)return;
            gSel.innerHTML=''; cSel.innerHTML='<option value="">ابتدا پایه را انتخاب کنید</option>';
            const grades=(window.transferMap[y]?.grades)||[];
            if(!grades.length){gSel.innerHTML='<option value="">پایه‌ای برای این سال نیست</option>';return;}
            grades.forEach(g=>gSel.add(new Option(g,g,false,g===window.initialTransferGrade)));
            refreshTransferClasses();
        }
        function refreshTransferClasses(){
            const y=document.getElementById('destYearSelect')?.value||'', g=document.getElementById('destGradeSelect')?.value||'';
            const cSel=document.getElementById('destClassSelect'); if(!cSel)return;
            cSel.innerHTML=''; const classes=(window.transferMap[y]?.classes?.[g])||[];
            if(!classes.length){cSel.innerHTML='<option value="">کلاسی برای این پایه نیست</option>';return;}
            classes.forEach(c=>cSel.add(new Option(c,c,false,c===window.initialTransferClass)));
        }
        document.addEventListener('DOMContentLoaded',()=>setTimeout(refreshTransferGrades,50));
        </script>
        <link rel="stylesheet" href="assets/css/student-workflow.css?v=20260917c"><div id="reportModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-label="گزارش دانش‌آموزان انتخاب‌شده" style="display:none">
            <div class="modal-card card">
                <h3 class="font-bold text-primary mb-3">گزارش دانش‌آموزان انتخاب‌شده</h3>
                <div class="mb-3"><label class="text-xs font-bold block mb-1">نوع گزارش</label>
                    <select name="report_type" class="form-select w-full">
                        <option value="info">گزارش اطلاعات</option>
                        <option value="discipline">گزارش انضباطی</option>
                        <option value="grades">گزارش تحلیلی نمرات</option>
                    </select>
                </div>
                <input type="hidden" name="fields_version" value="2">
                <p class="text-muted">هر ستون را جداگانه انتخاب کنید. اطلاعات محرمانهٔ ورود در گزارش قرار نمی‌گیرد.</p>
                <div class="report-field-actions"><button type="button" class="btn btn-secondary" onclick="selectReportFields(true)">انتخاب همهٔ ستون‌ها</button><button type="button" class="btn btn-secondary" onclick="selectReportFields(false)">پاک کردن انتخاب‌ها</button></div>
                <div class="report-field-groups">
                <?php foreach(student_profile_groups() as $groupKey=>$group): ?>
                <fieldset><legend><?php echo clean($group[0]); ?></legend><div class="report-field-options">
                <?php foreach($group[1] as $fieldKey=>$fieldLabel): ?><label><input type="checkbox" name="fields[]" value="<?php echo $fieldKey; ?>" <?php echo in_array($fieldKey,['first_name','last_name','national_id','father_name','class_name','father_phone','mother_phone'],true)?'checked':''; ?>> <?php echo clean($fieldLabel); ?></label><?php endforeach; ?>
                </div></fieldset><?php endforeach; ?>
                </div>
                <div class="grid grid-cols-3 gap-3 mt-4"><div><label class="text-xs">فرمت خروجی</label><select name="format" class="form-select"><option value="xls">Excel</option><option value="doc">Word</option><option value="html">HTML/چاپ PDF</option></select></div><div><label class="text-xs">از تاریخ شمسی</label><input name="from_jalali" class="form-input" placeholder="1404/01/01"></div><div><label class="text-xs">تا تاریخ شمسی</label><input name="to_jalali" class="form-input" placeholder="1404/12/29"></div><div><label class="text-xs">عنوان انضباطی</label><input name="discipline_title" class="form-input" placeholder="مثلاً تأخیر"></div><div><label class="text-xs">تعداد موارد</label><input name="discipline_count" class="form-input" placeholder="مثلاً 2"></div><div><label class="text-xs">درس</label><input name="grade_subject" class="form-input" placeholder="ریاضی"></div><div><label class="text-xs">ماه نمره</label><select name="grade_month" class="form-select"><option value="">همه</option><?php foreach (['مهر','آبان','آذر','دی','بهمن','اسفند','فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','نوبت اول','نوبت دوم'] as $gmM): ?><option value="<?php echo $gmM; ?>"><?php echo $gmM; ?></option><?php endforeach; ?></select></div><div><label class="text-xs">نمره</label><input name="grade_score" class="form-input" placeholder="20"></div></div>
                <div class="flex justify-end gap-2 mt-5"><button type="button" class="btn btn-secondary" onclick="closeReportModal()">انصراف</button><button class="btn btn-success">دانلود گزارش</button></div>
            </div>
        </div>
    </form>
</div>
<div id="studentAjaxModal" class="modal-backdrop" style="display:none"><div class="modal-card card"><button type="button" class="btn btn-danger text-xs" style="float:left" onclick="closeStudentModal()">×</button><div id="studentAjaxContent"></div></div></div>
<div id="studentHoverTooltip" class="student-hover-tooltip" style="display:none"></div>

<script>

function openStudentModal(type, id){
  const modal=document.getElementById('studentAjaxModal'), content=document.getElementById('studentAjaxContent');
  content.innerHTML='در حال بارگذاری...'; modal.style.display='flex';
  fetch('student-modal.php?type='+encodeURIComponent(type)+'&student_id='+id).then(r=>r.json()).then(j=>{content.innerHTML=j.html||'خطا';});
}
function closeStudentModal(){document.getElementById('studentAjaxModal').style.display='none';}
function saveDisciplineAjax(form){
  const fd=new FormData(form); fetch('student-modal.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{document.getElementById('studentAjaxContent').innerHTML=j.html;}); return false;
}
function deleteDisciplineAjax(studentId, recordId){
  if(!confirm('حذف شود؟')) return; const fd=new FormData(); fd.append('csrf_token','<?php echo csrf_token(); ?>'); fd.append('type','discipline_delete'); fd.append('student_id',studentId); fd.append('record_id',recordId);
  fetch('student-modal.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{document.getElementById('studentAjaxContent').innerHTML=j.html;});
}
let hoverTimer=null;
document.querySelectorAll('.student-row').forEach(row=>{
  row.addEventListener('mousemove', e=>{
    const tip=document.getElementById('studentHoverTooltip');
    const id=row.dataset.studentId;
    clearTimeout(hoverTimer);
    hoverTimer=setTimeout(()=>{
      fetch('student-modal.php?type=hover&student_id='+id).then(r=>r.json()).then(j=>{tip.innerHTML=j.html; tip.style.display='block'; positionTip(e,tip);});
    },120);
  });
  row.addEventListener('mouseleave', ()=>{clearTimeout(hoverTimer); document.getElementById('studentHoverTooltip').style.display='none';});
});
function positionTip(e,tip){
  const pad=14, w=320, h=230; let x=e.clientX+18, y=e.clientY+18;
  if(x+w>window.innerWidth) x=e.clientX-w-18; if(y+h>window.innerHeight) y=e.clientY-h-18;
  tip.style.left=Math.max(pad,x)+'px'; tip.style.top=Math.max(pad,y)+'px';
}

function selectReportFields(on){document.querySelectorAll('#reportModal input[name="fields[]"]').forEach(function(e){e.checked=on;});}
function openReportModal(){
  if(!document.querySelector('.st-check:checked')){ alert('ابتدا حداقل یک دانش‌آموز را انتخاب کنید.'); return; }
  window.reportReturnFocus=document.activeElement;var modal=document.getElementById('reportModal');modal.style.display='flex';modal.querySelector('select').focus();
}
// v4.36.0: keep this no-arg version even after main.js loads
document.addEventListener('DOMContentLoaded', function(){ window.openReportModal = openReportModal; });
function closeReportModal(){ document.getElementById('reportModal').style.display='none';if(window.reportReturnFocus)window.reportReturnFocus.focus(); }
document.addEventListener('keydown',function(e){var modal=document.getElementById('reportModal');if(!modal||modal.style.display==='none')return;if(e.key==='Escape'){e.preventDefault();closeReportModal();return;}if(e.key==='Tab'){var list=Array.prototype.filter.call(modal.querySelectorAll('input,select,button,a[href]'),function(el){return !el.disabled&&(el.offsetWidth||el.offsetHeight);});var first=list[0],last=list[list.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}},true);

/* v4.36.0: per-row kebab (⋮) actions menu — one shared floating menu */
let rowMenuEl = null;
function ensureRowMenu(){
  if (rowMenuEl && document.body.contains(rowMenuEl)) return rowMenuEl;
  rowMenuEl = document.createElement('div');
  rowMenuEl.id = 'rowActionsMenu';
  rowMenuEl.style.cssText = 'position:fixed;z-index:5000;display:none;min-width:160px;background:var(--bg-card,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:12px;box-shadow:0 16px 40px -12px rgba(16,24,40,.25);padding:6px;flex-direction:column;gap:2px;';
  document.body.appendChild(rowMenuEl);
  return rowMenuEl;
}
function openRowMenu(e, id){
  e.stopPropagation();
  const m = ensureRowMenu();
  const itemCss = 'display:block;width:100%;text-align:right;padding:8px 12px;border:0;background:transparent;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;color:var(--text-main,#0f172a);text-decoration:none;font-family:inherit;';
  // v4.37.0: on mobile ALL row actions live in this menu
  let mobileItems = '';
  if (window.innerWidth <= 900) {
    mobileItems =
      '<button type="button" style="'+itemCss+'" onmouseover="this.style.background=\'rgba(79,70,229,.08)\'" onmouseout="this.style.background=\'transparent\'" onclick="closeRowMenu();openStudentModal(\'reports\','+id+')">کارنامه‌ها</button>' +
      '<button type="button" style="'+itemCss+'" onmouseover="this.style.background=\'rgba(79,70,229,.08)\'" onmouseout="this.style.background=\'transparent\'" onclick="closeRowMenu();openStudentModal(\'discipline\','+id+')">انضباط</button>';
  }
  m.innerHTML = mobileItems +
    '<button type="button" style="'+itemCss+'" onmouseover="this.style.background=\'rgba(79,70,229,.08)\'" onmouseout="this.style.background=\'transparent\'" onclick="closeRowMenu();openStudentModal(\'info\','+id+')">اطلاعات</button>' +
    '<a href="students.php?action=edit&id='+id+'" style="'+itemCss+'" onmouseover="this.style.background=\'rgba(79,70,229,.08)\'" onmouseout="this.style.background=\'transparent\'">ویرایش</a>' +
    '<a href="students.php?action=reset_pass&id='+id+'" style="'+itemCss+'" onmouseover="this.style.background=\'rgba(79,70,229,.08)\'" onmouseout="this.style.background=\'transparent\'" onclick="return confirm(\'رمز عبور دانش‌آموز به کد ملی بازنشانی شود؟\')">بازنشانی رمز</a>' +
    '<a href="students.php?action=delete&id='+id+'" style="'+itemCss+'color:#dc2626;" onmouseover="this.style.background=\'rgba(220,38,38,.08)\'" onmouseout="this.style.background=\'transparent\'" onclick="return confirm(\'آیا از حذف دانش‌آموز اطمینان دارید؟\')">حذف</a>';
  m.style.display = 'flex';
  // position near the button, keep inside viewport
  const r = e.currentTarget.getBoundingClientRect();
  const mw = 170, mh = 170;
  let x = Math.min(r.left, window.innerWidth - mw - 8);
  let y = r.bottom + 6;
  if (y + mh > window.innerHeight) y = r.top - mh - 6;
  m.style.left = Math.max(8, x) + 'px';
  m.style.top  = Math.max(8, y) + 'px';
}
function closeRowMenu(){ if (rowMenuEl) rowMenuEl.style.display = 'none'; }
window.openRowMenu = openRowMenu;
window.closeRowMenu = closeRowMenu;
document.addEventListener('click', function(ev){ if (rowMenuEl && !ev.target.closest('#rowActionsMenu') && !ev.target.closest('.row-menu-btn')) closeRowMenu(); });
window.addEventListener('scroll', closeRowMenu, true);
document.addEventListener('keydown', function(ev){ if (ev.key === 'Escape') closeRowMenu(); });
</script>
<?php endif; require_once __DIR__ . '/includes/footer.php'; ?>
