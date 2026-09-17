<?php
/**
 * Classes, Subjects & Weekly Schedule Management (classes.php)
 * v4.39.0 - All selectors are dropdowns (no free text), auto subject codes,
 *           inline editable weekly-schedule rows (class/day/period/subject/teacher).
 * v4.44.0 - Half-period (تک زنگ) support: two subjects can share one period,
 *           taught on alternating weeks; add-form toggle + grouped display.
 * v4.45.0 - Subject form: official Iranian middle-school (متوسطه اول) subject
 *           list as a dropdown + manual typing option (سایر...).
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/academic_year_helpers.php';
require_once __DIR__ . '/includes/class_schedule_sync.php';
require_once __DIR__ . '/includes/school_sort.php';
require_once __DIR__ . '/includes/school_roles.php';
ensure_school_roles_schema();
if (!current_teacher_is_executive()) require_permission('manage_classes');

$tab  = $_GET['tab'] ?? 'classes';
/* v4.62.0: تب «دروس» (تعریف درس و تخصیص دبیر) حذف شد — تعریف درس/دبیر فقط
   یک بار و در «برنامه هفتگی و دبیران» انجام می‌شود (حذف دوباره‌کاری). */
if ($tab === 'subjects') $tab = 'schedule';
if (current_teacher_is_executive() && !in_array($tab, ['classes'], true)) $tab = 'classes';
// v4.38.0 - year must exist in master academic_years table (unified structure)
$year = resolve_academic_year_request($_GET['year'] ?? get_current_academic_year());

// Only allocation fields participate in the optimistic edit version; other columns survive UPDATE.
function ws_slot_rows($year,$class,$day,$period,$lock=false) {
    $suffix = $lock && DB::getInstance()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' ? ' FOR UPDATE' : '';
    $rows = DB::fetchAll('SELECT * FROM class_schedules WHERE academic_year=? AND day_of_week=? AND period_num=? ORDER BY id'.$suffix,[$year,$day,$period]);
    return array_values(array_filter($rows,function($r) use($class){return norm_class_str($r['class_name']) === norm_class_str($class);}));
}
function ws_slot_revision($rows) {
    $values = [];
    foreach ($rows as $r) $values[] = array_map(function($key) use($r){return (string)($r[$key] ?? '');},['id','subject_name','teacher_id','teacher_name']);
    return hash('sha256',json_encode($values,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}

// Handle Add Class
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_class'])) {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        // v4.39.0 - class name is composed from selectable grade + selectable number
        $grade = trim($_POST['grade'] ?? '');
        $classNo = trim($_POST['class_no'] ?? '');
        $name  = norm_class_str($_POST['name'] ?? '');
        if ($name === '' && $grade !== '') $name = norm_class_str($grade . $classNo);
        $y = resolve_academic_year_request(trim($_POST['academic_year'] ?? ''));

        if ($name) {
            $dup = DB::fetch("SELECT id FROM classes WHERE name=? AND academic_year=?", [$name, $y]);
            if ($dup) {
                set_flash_message('error', "کلاس $name قبلاً در این سال تحصیلی ثبت شده است.");
            } else {
                DB::execute("INSERT INTO classes (name, grade, academic_year) VALUES (?, ?, ?)", [$name, $grade, $y]);
                set_flash_message('success', "کلاس $name با موفقیت ایجاد شد.");
            }
        }
    }
    redirect("classes.php?tab=classes&year=" . urlencode($y));
}

// Handle Add Subject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_subject'])) {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        // v4.45.0 - subject name: dropdown of official متوسطه اول subjects + manual typing
        $name  = trim($_POST['name_select'] ?? '');
        if ($name === '__manual__' || $name === '') $name = trim($_POST['name'] ?? '');
        $grade = trim($_POST['grade_level'] ?? '');
        // Auto-generate unique subject code (system-assigned, not user input)
        $code = '';
        try {
            $maxRow = DB::fetch("SELECT MAX(id) m FROM subjects");
            $n = (int)($maxRow['m'] ?? 0) + 1;
            do {
                $code = 'DRS-' . str_pad($n, 4, '0', STR_PAD_LEFT);
                $codeDup = DB::fetch("SELECT id FROM subjects WHERE code=?", [$code]);
                $n++;
            } while ($codeDup);
        } catch (Exception $e) { $code = 'DRS-' . substr((string)time(), -6); }
        $tId   = (int)($_POST['teacher_id'] ?? 0);
        $tName = '';
        if ($tId > 0) {
            $tObj = DB::fetch("SELECT full_name FROM teachers WHERE id = ?", [$tId]);
            $tName = $tObj ? $tObj['full_name'] : '';
        }

        if ($name) {
            DB::execute("INSERT INTO subjects (name, code, grade_level, teacher_name, teacher_id, coefficient) VALUES (?, ?, ?, ?, ?, 1)", [$name, $code, $grade, $tName, $tId ?: null]);
            set_flash_message('success', "درس $name با کد خودکار $code ثبت گردید.");
        } else {
            set_flash_message('error', 'نام درس را انتخاب یا وارد کنید.');
        }
    }
    redirect("classes.php?tab=subjects&year=" . urlencode($year));
}

if (isset($_GET['del_class'])) {
    DB::execute("DELETE FROM classes WHERE id = ?", [(int)$_GET['del_class']]);
    set_flash_message('success', 'کلاس مورد نظر حذف شد.');
    redirect("classes.php?tab=classes&year=" . urlencode($year));
}

if (isset($_GET['del_subject'])) {
    DB::execute("DELETE FROM subjects WHERE id = ?", [(int)$_GET['del_subject']]);
    set_flash_message('success', 'درس مورد نظر حذف شد.');
    redirect("classes.php?tab=subjects&year=" . urlencode($year));
}

/* ============================================================
 * v4.63.0: AJAX cell save for the tile-matrix weekly schedule.
 * One cell = class+day+period. Payload: subject(s)+teacher(s)+half flag.
 * Auto-saves on change — no submit button. Empty subject clears the cell.
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_cell_save'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { echo json_encode(['ok' => false, 'message' => 'خطای CSRF — صفحه را رفرش کنید']); exit; }
    if (!is_admin_logged_in() || !has_permission('manage_classes')) { echo json_encode(['ok'=>false,'message'=>'دسترسی غیرمجاز']); exit; }
    // Never interpret a truncated request as an instruction to clear a saved slot.
    foreach (['class_name','day_of_week','period_num','subject_name','teacher_id','is_half','subject_name2','teacher_id2','revision'] as $key) {
        if (!isset($_POST[$key]) || !is_scalar($_POST[$key])) { echo json_encode(['ok'=>false,'message'=>'درخواست ناقص است؛ چیزی تغییر نکرد.']); exit; }
    }
    if (isset($_GET['year']) && unify_academic_year($_GET['year']) !== $year) { echo json_encode(['ok'=>false,'message'=>'سال تحصیلی نامعتبر است؛ چیزی تغییر نکرد.']); exit; }
    $cName  = norm_class_str($_POST['class_name'] ?? '');
    $day    = trim($_POST['day_of_week'] ?? '');
    $period = trim($_POST['period_num'] ?? '');
    $subj   = trim($_POST['subject_name'] ?? '');
    $tId    = (int)($_POST['teacher_id'] ?? 0);
    $isHalf = !empty($_POST['is_half']) && $_POST['is_half'] === '1';
    $subj2  = trim($_POST['subject_name2'] ?? '');
    $tId2   = (int)($_POST['teacher_id2'] ?? 0);
    if ($cName === '' || $day === '' || $period === '') { echo json_encode(['ok' => false, 'message' => 'سلول نامعتبر']); exit; }
    $pdo = DB::getInstance()->getPdo();
    try {
        if (!in_array($day, ['شنبه','یک‌شنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه'], true) || !in_array($period, ['زنگ 1','زنگ 2','زنگ 3','زنگ 4'], true)) throw new RuntimeException('روز یا زنگ نامعتبر است.');
        $known = false;
        foreach (get_unified_class_options($year) as $cl) if (norm_class_str($cl['class_name']) === $cName) $known = true;
        if (!$known) throw new RuntimeException('کلاس در این سال تحصیلی یافت نشد.');
        if ($subj === '' && empty($_POST['clear_slot'])) throw new RuntimeException('ابتدا درس را انتخاب کنید.');
        $pdo->beginTransaction();
        $rows = ws_slot_rows($year,$cName,$day,$period,true);
        if (!hash_equals(ws_slot_revision($rows),(string)$_POST['revision'])) throw new RuntimeException('این خانه در جای دیگری تغییر کرده؛ برای جلوگیری از بازنویسی اطلاعات، صفحه را تازه‌سازی کنید.');
        $storedClass = $rows[0]['class_name'] ?? $cName;
        if (count($rows) > 2) throw new RuntimeException('این زنگ بیش از دو رکورد دارد؛ برای حفظ اطلاعات، ویرایش خودکار انجام نشد.');
        $targets = $subj === '' ? [] : [[$subj,$tId]];
        if ($subj !== '' && $isHalf && $subj2 !== '') $targets[] = [$subj2,$tId2];
        $names = [];
        foreach ($targets as $i => $target) {
            list($subjectValue,$teacherValue) = $target;
            if ($teacherValue < 0) throw new RuntimeException('دبیر نامعتبر است.');
            $teacherRow = $teacherValue ? DB::fetch('SELECT full_name FROM teachers WHERE id=?',[$teacherValue]) : null;
            if ($teacherValue && !$teacherRow) throw new RuntimeException('دبیر یافت نشد؛ چیزی تغییر نکرد.');
            $teacherName = $teacherRow['full_name'] ?? '';
            // CSV imports can contain a teacher name without a linked teacher ID.
            if (!$teacherValue && empty($_POST['teacher_edited'.($i+1)]) && !empty($rows[$i]) && empty($rows[$i]['teacher_id'])) $teacherName = $rows[$i]['teacher_name'];
            $names[] = $teacherName;
            if (isset($rows[$i])) {
                DB::execute('UPDATE class_schedules SET subject_name=?, teacher_name=?, teacher_id=? WHERE id=?',[$subjectValue,$teacherName,$teacherValue ?: null,$rows[$i]['id']]);
            } else {
                DB::execute('INSERT INTO class_schedules (academic_year,class_name,day_of_week,period_num,subject_name,teacher_name,teacher_id) VALUES (?,?,?,?,?,?,?)',[$year,$storedClass,$day,$period,$subjectValue,$teacherName,$teacherValue ?: null]);
            }
        }
        // Only the explicitly cleared slot/disabled second half is removed.
        for ($i=count($targets); $i<count($rows); $i++) DB::execute('DELETE FROM class_schedules WHERE id=?',[$rows[$i]['id']]);
        $revision = ws_slot_revision(ws_slot_rows($year,$cName,$day,$period));
        $pdo->commit();
        echo json_encode(['ok'=>true,'revision'=>$revision,'saved'=>count($targets),'teacher'=>$names[0] ?? '', 'teacher2'=>$names[1] ?? ''],JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok'=>false,'message'=>($e instanceof PDOException ? 'ذخیره انجام نشد؛ اطلاعات قبلی حفظ شد.' : $e->getMessage())],JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* v4.68.0: انتقال (کپی) برنامه هفتگی از یک سال تحصیلی به سال دیگر */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['copy_schedule_year'])) {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        $srcYear = unify_academic_year(trim($_POST['src_year'] ?? ''));
        $dstYear = unify_academic_year(trim($_POST['dst_year'] ?? ''));
        $overwrite = !empty($_POST['overwrite_dst']);
        if ($srcYear === '' || $dstYear === '') {
            set_flash_message('error', 'سال مبدا و مقصد را انتخاب کنید.');
        } elseif ($srcYear === $dstYear) {
            set_flash_message('error', 'سال مبدا و مقصد نباید یکسان باشند.');
        } else {
            try {
                $srcRows = DB::fetchAll("SELECT * FROM class_schedules WHERE academic_year = ?", [$srcYear]);
                if (empty($srcRows)) {
                    set_flash_message('error', "برنامه هفتگی سال $srcYear خالی است — چیزی برای کپی وجود ندارد.");
                } else {
                    if ($overwrite) {
                        DB::execute("DELETE FROM class_schedules WHERE academic_year = ?", [$dstYear]);
                    }
                    $copied = 0; $skipped = 0;
                    foreach ($srcRows as $sr) {
                        if (!$overwrite) {
                            $dup = DB::fetch("SELECT id FROM class_schedules WHERE academic_year=? AND class_name=? AND day_of_week=? AND period_num=? AND subject_name=?",
                                [$dstYear, $sr['class_name'], $sr['day_of_week'], $sr['period_num'], $sr['subject_name']]);
                            if ($dup) { $skipped++; continue; }
                        }
                        DB::execute("INSERT INTO class_schedules (academic_year, class_name, day_of_week, period_num, subject_name, teacher_name, teacher_id) VALUES (?,?,?,?,?,?,?)",
                            [$dstYear, $sr['class_name'], $sr['day_of_week'], $sr['period_num'], $sr['subject_name'], $sr['teacher_name'], $sr['teacher_id'] ?: null]);
                        $copied++;
                    }
                    // کلاس‌های مقصد را هم در جدول classes بساز تا در ستون‌ها دیده شوند
                    $srcClasses = [];
                    foreach ($srcRows as $sr) { $cn = norm_class_str($sr['class_name']); if ($cn !== '') $srcClasses[$cn] = $sr['class_name']; }
                    foreach ($srcClasses as $cn) {
                        $exists = DB::fetch("SELECT id FROM classes WHERE name=? AND academic_year=?", [$cn, $dstYear]);
                        if (!$exists) { try { DB::execute("INSERT INTO classes (name, grade, academic_year) VALUES (?,?,?)", [$cn, infer_grade_from_class_name($cn), $dstYear]); } catch (Exception $e) {} }
                    }
                    $msg = "کپی برنامه هفتگی از $srcYear به $dstYear انجام شد: $copied زنگ کپی شد";
                    if ($skipped) $msg .= "، $skipped مورد تکراری رد شد";
                    if ($overwrite) $msg .= " (برنامه قبلی مقصد پاک شد)";
                    set_flash_message('success', $msg . '.');
                }
            } catch (Throwable $e) {
                set_flash_message('error', 'خطا در کپی برنامه: ' . $e->getMessage());
            }
        }
    }
    redirect("classes.php?tab=schedule&year=" . urlencode($_POST['dst_year'] ?? $year));
}

// v4.39.0 - inline edit of a weekly-schedule row (all fields are dropdowns)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_sched'])) {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        $sid = (int)($_POST['sched_id'] ?? 0);
        $cName = norm_class_str($_POST['class_name'] ?? '');
        $day = trim($_POST['day_of_week'] ?? '');
        $period = trim($_POST['period_num'] ?? '');
        $subj = trim($_POST['subject_name'] ?? '');
        $tId = (int)($_POST['teacher_id'] ?? 0);
        $tName = '';
        if ($tId > 0) { $tObj = DB::fetch("SELECT full_name FROM teachers WHERE id = ?", [$tId]); $tName = $tObj ? $tObj['full_name'] : ''; }
        if ($sid > 0 && $cName !== '' && $day !== '' && $subj !== '') {
            DB::execute("UPDATE class_schedules SET class_name=?, day_of_week=?, period_num=?, subject_name=?, teacher_id=?, teacher_name=? WHERE id=?",
                [$cName, $day, $period, $subj, $tId ?: null, $tName, $sid]);
            set_flash_message('success', "زنگ کلاسی #$sid بروزرسانی شد ($cName - $day - $subj).");
        } else {
            set_flash_message('error', 'کلاس، روز هفته و درس باید انتخاب شوند.');
        }
    }
    redirect("classes.php?tab=schedule&year=" . urlencode($year));
}

// v4.39.0 - add a new weekly-schedule row from dropdowns
// v4.44.0 - optional تک زنگ: a second subject+teacher sharing the SAME period (alternating weeks)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_sched'])) {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        $cName = norm_class_str($_POST['class_name'] ?? '');
        $day = trim($_POST['day_of_week'] ?? '');
        $period = trim($_POST['period_num'] ?? '');
        $subj = trim($_POST['subject_name'] ?? '');
        $tId = (int)($_POST['teacher_id'] ?? 0);
        $tName = '';
        if ($tId > 0) { $tObj = DB::fetch("SELECT full_name FROM teachers WHERE id = ?", [$tId]); $tName = $tObj ? $tObj['full_name'] : ''; }

        $isHalf = !empty($_POST['is_half_period']);
        $subj2 = trim($_POST['subject_name2'] ?? '');
        $tId2 = (int)($_POST['teacher_id2'] ?? 0);
        $tName2 = '';
        if ($tId2 > 0) { $tObj2 = DB::fetch("SELECT full_name FROM teachers WHERE id = ?", [$tId2]); $tName2 = $tObj2 ? $tObj2['full_name'] : ''; }

        if ($cName !== '' && $day !== '' && $subj !== '') {
            if ($isHalf && ($subj2 === '' || $subj2 === $subj)) {
                set_flash_message('error', 'برای تک زنگ باید درس دوم را انتخاب کنید و با درس اول متفاوت باشد.');
            } else {
                DB::execute("INSERT INTO class_schedules (academic_year, class_name, day_of_week, period_num, subject_name, teacher_name, teacher_id) VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$year, $cName, $day, $period, $subj, $tName, $tId ?: null]);
                if ($isHalf) {
                    DB::execute("INSERT INTO class_schedules (academic_year, class_name, day_of_week, period_num, subject_name, teacher_name, teacher_id) VALUES (?, ?, ?, ?, ?, ?, ?)",
                        [$year, $cName, $day, $period, $subj2, $tName2, $tId2 ?: null]);
                    set_flash_message('success', "تک زنگ ثبت شد: $cName - $day - $period - «$subj / $subj2» (هفته در میان)");
                } else {
                    set_flash_message('success', "زنگ جدید ثبت شد: $cName - $day - $period - $subj");
                }
            }
        } else {
            set_flash_message('error', 'کلاس، روز هفته و درس باید انتخاب شوند.');
        }
    }
    redirect("classes.php?tab=schedule&year=" . urlencode($year));
}

if (isset($_GET['del_sched'])) {
    DB::execute("DELETE FROM class_schedules WHERE id = ?", [(int)$_GET['del_sched']]);
    set_flash_message('success', 'زنگ کلاسی حذف شد.');
    redirect("classes.php?tab=schedule&year=" . urlencode($year));
}

if (isset($_GET['sync_schedule'])) {
    if (!verify_csrf($_GET['csrf_token'] ?? '')) {
        set_flash_message('error', 'درخواست همگام‌سازی معتبر نیست.');
    } else {
        $sync = sync_schedule_to_classes_subjects($year);
        set_flash_message('success', 'همگام‌سازی انجام شد: ' . tr_num($sync['classes'], 'fa') . ' کلاس و ' . tr_num($sync['subjects'], 'fa') . ' درس از برنامه هفتگی به ساختار پایه اضافه شد.');
    }
    redirect("classes.php?tab=" . urlencode($tab) . "&year=" . urlencode($year));
}

require_once __DIR__ . '/includes/header.php';

// Important: imported weekly schedules are also an authoritative source for classes/subjects.
// Therefore these lists are unified with class_schedules instead of relying only on manual forms.
$classesList  = get_unified_classes_for_year($year);
persian_usort_classes($classesList, 'name', 'grade');
$subjectsList = DB::fetchAll("SELECT * FROM subjects ORDER BY name ASC, teacher_name ASC");
$scheduleSubjectsList = get_schedule_subjects_for_year($year);
persian_usort_classes($scheduleSubjectsList, 'class_name', 'grade_level');
$filterTeacher = (int)($_GET['teacher_id'] ?? 0);
$filterSubject = trim($_GET['subject_name'] ?? '');
$filterClass   = trim($_GET['class'] ?? '');
$filterQ       = trim($_GET['q'] ?? '');

$schedWhere = ["cs.academic_year = ?"];
$schedParams = [$year];
if ($filterClass !== '') { $schedWhere[] = "cs.class_name = ?"; $schedParams[] = $filterClass; }
if ($filterTeacher > 0) { $schedWhere[] = "cs.teacher_id = ?"; $schedParams[] = $filterTeacher; }
if (!empty($filterSubject)) { $schedWhere[] = "cs.subject_name = ?"; $schedParams[] = $filterSubject; }
if (!empty($filterQ)) { $schedWhere[] = "(cs.class_name LIKE ? OR cs.subject_name LIKE ? OR cs.teacher_name LIKE ? OR t.full_name LIKE ?)"; $schedParams[] = "%$filterQ%"; $schedParams[] = "%$filterQ%"; $schedParams[] = "%$filterQ%"; $schedParams[] = "%$filterQ%"; }
$schedWhereSql = implode(' AND ', $schedWhere);

$schedList    = DB::fetchAll("SELECT cs.*, t.full_name as t_name FROM class_schedules cs LEFT JOIN teachers t ON cs.teacher_id = t.id WHERE $schedWhereSql ORDER BY cs.class_name ASC, FIELD(cs.day_of_week,'شنبه','یک‌شنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه'), cs.period_num ASC, cs.id ASC", $schedParams);

// v4.44.0 - تک زنگ detection: count rows sharing the same class+day+period slot
// (unfiltered query so a slot is recognized as shared even when filters hide its pair)
$slotCounts = [];
try {
    $slotRows = DB::fetchAll("SELECT class_name, day_of_week, period_num, COUNT(*) c FROM class_schedules WHERE academic_year = ? GROUP BY class_name, day_of_week, period_num", [$year]);
    foreach ($slotRows as $sr) { $slotCounts[$sr['class_name'].'|'.$sr['day_of_week'].'|'.$sr['period_num']] = (int)$sr['c']; }
} catch (Exception $e) {}
$slotMates = [];
foreach ($schedList as $scRow) {
    $k = $scRow['class_name'].'|'.$scRow['day_of_week'].'|'.$scRow['period_num'];
    $slotMates[$k][] = $scRow['subject_name'];
}
$distinctSubjs= DB::fetchAll("SELECT DISTINCT subject_name FROM class_schedules WHERE academic_year = ?", [$year]);
$teachersList = DB::fetchAll("SELECT id, full_name FROM teachers WHERE status = 1 ORDER BY full_name ASC");
usort($teachersList, fn($a,$b)=>persian_compare($a["full_name"],$b["full_name"]));   // v4.77.0: آ قبل از ا
$yearsList = get_academic_years_for_filter();
if (empty($yearsList)) { $yearsList = [['academic_year' => $year]]; }

// v4.39.0 - unified dropdown sources (no free-text fields anywhere in this page)
$stdGrades = ['هفتم','هشتم','نهم','دهم','یازدهم','دوازدهم'];
$gradeOptions = [];
foreach (get_unified_grade_options($year) as $go) { $g = trim($go['grade_level'] ?? ''); if ($g !== '' && !in_array($g, $gradeOptions, true)) $gradeOptions[] = $g; }
foreach ($stdGrades as $g) { if (!in_array($g, $gradeOptions, true)) $gradeOptions[] = $g; }

$classNameOptions = []; $classNameNormSeen = []; // v4.64.0: dedupe با norm_class_str (رفع کلاس تکراری/شبح)
foreach ($classesList as $co) { $c = trim($co['name'] ?? ''); $n = norm_class_str($c); if ($n !== '' && !isset($classNameNormSeen[$n])) { $classNameNormSeen[$n] = 1; $classNameOptions[] = $c; } }
foreach (get_unified_class_options($year) as $co) { $c = trim($co['class_name'] ?? ''); $n = norm_class_str($c); if ($n !== '' && !isset($classNameNormSeen[$n])) { $classNameNormSeen[$n] = 1; $classNameOptions[] = $c; } }

$subjectNameOptions = [];
foreach ($distinctSubjs as $so) { $s = trim($so['subject_name'] ?? ''); if ($s !== '' && !in_array($s, $subjectNameOptions, true)) $subjectNameOptions[] = $s; }
foreach ($subjectsList as $so) { $s = trim($so['name'] ?? ''); if ($s !== '' && !in_array($s, $subjectNameOptions, true)) $subjectNameOptions[] = $s; }
sort($subjectNameOptions);

// v4.45.0 - Official متوسطه اول subjects (grades 7-9, per chap.sch.ir book list)
// including common variants used by schools; merged with subjects already in the system.
$standardSubjects = [
    'آموزش قرآن',
    'پیام‌های آسمان',
    'فارسی',
    'املای فارسی',
    'انشا و نگارش',
    'عربی',
    'زبان انگلیسی',
    'ریاضی',
    'علوم تجربی',
    'مطالعات اجتماعی',
    'فرهنگ و هنر',
    'کار و فناوری',
    'تفکر و سبک زندگی',
    'تربیت بدنی و سلامت',
    'آمادگی دفاعی',
    'هنر',
    'کامپیوتر',
    'پرورشی',
    'مشاوره',
    'آزمایشگاه علوم',
    'مهارت‌های زندگی',
];
$subjectPickerOptions = $standardSubjects;
foreach ($subjectNameOptions as $sn) { if (!in_array($sn, $subjectPickerOptions, true)) $subjectPickerOptions[] = $sn; }

$daysList = ['شنبه','یک‌شنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه'];
$periodsList = []; for ($i = 1; $i <= 4; $i++) $periodsList[] = 'زنگ ' . $i; // v4.63.0: برنامه هفتگی ۴ زنگ

// Preview of the next auto-generated subject code
$nextSubjectCode = 'DRS-0001';
try { $maxRow = DB::fetch("SELECT MAX(id) m FROM subjects"); $nextSubjectCode = 'DRS-' . str_pad((int)($maxRow['m'] ?? 0) + 1, 4, '0', STR_PAD_LEFT); } catch (Exception $e) {}
// v4.38.0 - deleted years are never re-injected into the filter; $year is already
// validated against the master table by resolve_academic_year_request().
?>
<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold">مدیریت کلاس‌ها، دروس و برنامه هفتگی تدریس</h2>
            <p class="text-sm text-muted">تعریف پایه‌ها، دروس و تخصیص اساتید به تفکیک سال تحصیلی</p>
        </div>
        <div class="flex items-center gap-4">
            <form method="GET" class="flex items-center gap-2">
                <input type="hidden" name="tab" value="<?php echo clean($tab); ?>">
                <label class="text-xs font-bold">فیلتر سال تحصیلی:</label>
                <select name="year" class="form-select text-xs font-bold w-36" onchange="this.form.submit()">
                    <?php foreach ($yearsList as $yl): ?>
                        <option value="<?php echo clean($yl['academic_year']); ?>" <?php echo $year === $yl['academic_year'] ? 'selected' : ''; ?>><?php echo clean($yl['academic_year']); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <div class="flex gap-2 text-sm border-b pb-1">
                <a href="classes.php?tab=classes&year=<?php echo urlencode($year); ?>" class="btn <?php echo $tab === 'classes' ? 'btn-primary font-bold' : 'btn-outline'; ?>"><svg data-ui-icon="school" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m3 9 9-6 9 6v12H3Z"/><path d="M9 21v-6h6v6M7 11h.01M17 11h.01M12 7v3M10.5 8.5h3"/></svg> کلاس‌ها</a>
                <a href="classes.php?tab=schedule&year=<?php echo urlencode($year); ?>" class="btn <?php echo $tab === 'schedule' ? 'btn-primary font-bold' : 'btn-outline'; ?>"><svg data-ui-icon="calendar" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 11h18M8 15h2m4 0h2m-8 3h2"/></svg> برنامه هفتگی و دبیران</a>
                <a href="classes.php?tab=<?php echo urlencode($tab); ?>&year=<?php echo urlencode($year); ?>&sync_schedule=1&csrf_token=<?php echo urlencode(csrf_token()); ?>" class="btn btn-success text-xs" title="ایجاد رکوردهای کلاس و درس از روی برنامه هفتگی"><svg data-ui-icon="refresh" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 3v6h-6M3 21v-6h6M20 9a8 8 0 0 0-14-4M4 15a8 8 0 0 0 14 4"/></svg> همگام‌سازی با برنامه</a>
            </div>
        </div>
    </div>

    <?php if ($tab === 'classes'): ?>
    <div class="grid grid-cols-3 gap-6">
        <div class="card col-span-1 shadow-lg">
            <h3 class="font-bold mb-4 text-primary">ایجاد کلاس جدید در سال <?php echo tr_num($year, 'fa'); ?></h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="add_class" value="1">
                <div class="mb-4">
                    <label class="block text-xs font-semibold mb-1">پایه تحصیلی *</label>
                    <select name="grade" id="clsGradeSel" class="form-select font-bold" required onchange="updateClassPreview()">
                        <option value="">--- انتخاب پایه ---</option>
                        <?php foreach ($gradeOptions as $g): ?>
                            <option value="<?php echo clean($g); ?>"><?php echo clean($g); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="block text-xs font-semibold mb-1">شماره کلاس *</label>
                    <select name="class_no" id="clsNoSel" class="form-select font-bold" required onchange="updateClassPreview()">
                        <?php for ($i = 1; $i <= 12; $i++): ?>
                            <option value="<?php echo $i; ?>"><?php echo tr_num($i, 'fa'); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="block text-xs font-semibold mb-1">نام کلاس (ساخت خودکار)</label>
                    <div id="clsNamePreview" class="form-input font-bold" style="background:var(--bg-secondary,#f1f5f9);cursor:default">---</div>
                </div>
                <div class="mb-6">
                    <label class="block text-xs font-semibold mb-1">سال تحصیلی</label>
                    <select name="academic_year" class="form-select font-bold">
                        <?php foreach(get_academic_years_for_filter() as $yy): ?>
                            <option value="<?php echo clean($yy['academic_year']); ?>" <?php echo $year===$yy['academic_year']?'selected':''; ?>><?php echo clean($yy['academic_year']); ?><?php echo !empty($yy['is_default'])?' - پیش‌فرض':''; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-success w-full py-2.5 font-bold">+ ثبت کلاس</button>
            </form>
            <script>
            function updateClassPreview(){
                var g=document.getElementById('clsGradeSel'), n=document.getElementById('clsNoSel'), p=document.getElementById('clsNamePreview');
                if(g&&n&&p){ p.textContent = g.value ? (g.value + n.value) : '---'; }
            }
            document.addEventListener('DOMContentLoaded', updateClassPreview);
            </script>
        </div>
        <div class="card col-span-2 shadow-lg">
            <div class="table-container">
                <table>
                    <thead><tr><th>شناسه</th><th>نام کلاس</th><th>پایه تحصیلی</th><th>سال تحصیلی</th><th>زنگ‌های برنامه</th><th>منبع</th><th>حذف</th></tr></thead>
                    <tbody>
                        <?php foreach ($classesList as $cl): ?>
                        <tr>
                            <td class="font-mono text-xs"><?php echo $cl['id'] ? '#' . clean($cl['id']) : 'برنامه'; ?></td>
                            <td class="font-bold"><?php echo clean($cl['name']); ?></td>
                            <td><?php echo clean($cl['grade'] ?: infer_grade_from_class_name($cl['name'])); ?></td>
                            <td><?php echo clean($cl['academic_year']); ?></td>
                            <td><span class="badge badge-info"><?php echo tr_num((int)$cl['schedule_count'], 'fa'); ?></span></td>
                            <td><?php echo ((int)$cl['manual_count'] > 0 && (int)$cl['schedule_count'] > 0) ? '<span class="badge badge-success">فرم + برنامه</span>' : (((int)$cl['schedule_count'] > 0) ? '<span class="badge badge-warning">برنامه هفتگی</span>' : '<span class="badge badge-info">فرم دستی</span>'); ?></td>
                            <td><?php if (!empty($cl['id'])): ?><a href="classes.php?tab=classes&year=<?php echo urlencode($year); ?>&del_class=<?php echo $cl['id']; ?>" onclick="return confirm('حذف این کلاس؟')" class="btn btn-danger text-xs px-2 py-1">حذف</a><?php else: ?><span class="text-xs text-muted">برای حذف، زنگ‌های برنامه را حذف کنید</span><?php endif; ?></td>
                        </tr>
                        <?php endforeach; if (empty($classesList)): ?>
                        <tr><td colspan="7" class="text-center text-muted">کلاسی در این سال تعریف نشده است.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php elseif ($tab === 'schedule'):
        /* ==========================================================
         * v4.63.0: TILE-MATRIX weekly schedule (4 periods).
         * Columns = classes. Main rows = weekdays; each weekday holds
         * 4 period rows + a teacher row under each period (8 rows).
         * Every cell is select-based and AUTO-SAVES on change (AJAX,
         * no submit button). Each cell supports «تک زنگ» (two subjects
         * alternating weekly).
         * ========================================================== */
        // slot map: class|day|period => rows (1 or 2 = تک زنگ)
        $slotMap = [];
        foreach ($schedList as $r) {
            $k = norm_class_str($r['class_name']) . '|' . trim($r['day_of_week']) . '|' . trim($r['period_num']);
            $slotMap[$k][] = $r;
        }
        $matrixDays = ['شنبه','یک‌شنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه']; // v4.64.0: پنجشنبه اضافه شد
        /* v4.64.0: ستون‌ها فقط کلاس‌های تعریف‌شده — dedupe با norm_class_str تا
         * «نهم 3» و «نهم3» یک ستون شوند و ستون شبح ساخته نشود. کلاس‌هایی که
         * در برنامه رکورد دارند ولی تعریف نشده‌اند هم اضافه می‌شوند تا داده
         * پنهان نماند. */
        /* v4.89.0: نگاشت id→نام دبیر برای رندر سبک خانه‌ها */
        $teacherNamesById = [];
        foreach ($teachersList as $tl) $teacherNamesById[(int)$tl['id']] = $tl['full_name'];
        $matrixClasses = []; $wsSeenNorm = [];
        foreach ($classesList as $co) {
            $c = trim($co['name'] ?? ''); $n = norm_class_str($c);
            if ($n !== '' && !isset($wsSeenNorm[$n])) { $wsSeenNorm[$n] = 1; $matrixClasses[] = $c; }
        }
        foreach ($schedList as $r) {
            $n = norm_class_str($r['class_name'] ?? '');
            if ($n !== '' && !isset($wsSeenNorm[$n])) { $wsSeenNorm[$n] = 1; $matrixClasses[] = $n; }
        }
        if (empty($matrixClasses)) $matrixClasses = $classNameOptions;
    ?>
    <div class="card shadow-lg space-y-4">
        <div class="flex justify-between items-center border-b pb-3">
            <div>
                <h3 class="font-bold text-primary"><svg data-ui-icon="calendar" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 11h18M8 15h2m4 0h2m-8 3h2"/></svg> برنامه هفتگی سال <?php echo tr_num($year, 'fa'); ?> — ۴ زنگ</h3>
                <p class="text-xs text-muted mt-1">هر خانه را انتخاب کنید؛ تغییرات <b>بلافاصله و خودکار</b> ذخیره می‌شود (نیازی به دکمه تایید نیست).</p>
            </div>
            <div class="flex gap-2 items-center">
                <button type="button" class="btn btn-outline text-xs" onclick="wsRetrySaving()">تلاش دوباره برای ذخیره</button>
                <span id="wsStatus" class="text-xs font-bold" style="color:#64748b">آماده</span>
                <form method="GET" class="flex items-center gap-2">
                    <input type="hidden" name="tab" value="schedule">
                    <select name="year" class="form-select text-xs font-bold" onchange="this.form.submit()">
                        <?php foreach ($yearsList as $yl): ?>
                            <option value="<?php echo clean($yl['academic_year']); ?>" <?php echo $year === $yl['academic_year'] ? 'selected' : ''; ?>><?php echo clean($yl['academic_year']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <a href="import-schedule.php" class="btn btn-primary text-xs"><svg data-ui-icon="bolt" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m14 2-11 12h8l-1 8 11-12h-8Z"/></svg> ایمپورت barname.csv</a>
            </div>
        </div>

        <?php if (empty($matrixClasses)): ?>
        <div class="text-center text-muted py-10">ابتدا از تب «کلاس‌ها» حداقل یک کلاس تعریف کنید.</div>
        <?php else: ?>

        <style>
        .ws-wrap{overflow:auto;max-height:75vh;border:1px solid #e2e8f0;border-radius:14px}
        .dark .ws-wrap{border-color:#334155}
        table.ws{border-collapse:separate;border-spacing:0;width:100%;min-width:<?php echo 130 + count($matrixClasses) * 180; ?>px}
        table.ws th,table.ws td{border-bottom:1px solid #e2e8f0;border-left:1px solid #e2e8f0;padding:4px 6px;background:#fff}
        .dark table.ws th,.dark table.ws td{border-color:#334155;background:#0f172a;color:#e2e8f0}
        table.ws thead th{position:sticky;top:0;z-index:3;background:linear-gradient(135deg,#e0e7ff,#c7d2fe);color:#111827 !important;font-size:.8rem;font-weight:800;padding:10px 6px;text-align:center;border-bottom:2px solid #818cf8}
        .dark table.ws thead th{background:linear-gradient(135deg,#1e293b,#334155);color:#e2e8f0 !important;border-bottom-color:#6366f1}
        table.ws .ws-day{position:sticky;right:0;z-index:2;background:linear-gradient(135deg,#0ea5e9,#0369a1) !important;color:#fff !important;font-weight:800;font-size:.85rem;text-align:center;vertical-align:middle;min-width:70px}
        table.ws .ws-period{position:sticky;right:70px;z-index:2;background:#f1f5f9 !important;font-size:.68rem;font-weight:700;color:#334155;text-align:center;white-space:nowrap;min-width:60px}
        .dark table.ws .ws-period{background:#1e293b !important;color:#cbd5e1}
        table.ws .ws-period.t{color:#059669;font-size:.62rem}
        .ws-sel{width:100%;border:1px solid transparent;background:transparent;font-family:inherit;font-size:.72rem;font-weight:700;padding:5px 4px;border-radius:8px;cursor:pointer;color:inherit}
        .ws-sel:hover{border-color:#c7d2fe;background:rgba(79,70,229,.05)}
        .ws-sel:focus{outline:none;border-color:#4f46e5;background:rgba(79,70,229,.08)}
        .ws-sel.empty{color:#94a3b8;font-weight:400}
        /* v4.89.0: خانه‌های سبک — select فقط هنگام کلیک ساخته می‌شود (کارایی روی سیستم‌های ضعیف) */
        .ws-val{width:100%;border:1px solid transparent;background:transparent;font-family:inherit;font-size:.72rem;font-weight:700;padding:5px 4px;border-radius:8px;cursor:pointer;color:inherit;min-height:26px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .ws-val:hover{border-color:#c7d2fe;background:rgba(79,70,229,.05)}
        .ws-val:focus{outline:none;border-color:#4f46e5;background:rgba(79,70,229,.08)}
        .ws-val.empty{color:#94a3b8;font-weight:400}
        .ws-cell{position:relative}
        .ws-cell.saving{background:#fef9c3 !important}
        .dark .ws-cell.saving{background:#3b3410 !important}
        .ws-cell.saved{animation:wsFlash 1.2s ease}
        @keyframes wsFlash{0%{background:#dcfce7}100%{background:#fff}}
        .dark .ws-cell.saved{animation:none;background:#0f2a1a !important}
        .ws-cell.err{background:#fee2e2 !important}
        .ws-half{display:flex;align-items:center;gap:4px;font-size:.6rem;color:#64748b;padding:1px 4px;cursor:pointer;user-select:none}
        .ws-half input{accent-color:#d97706}
        .ws-h2{border-top:1px dashed #fcd34d;margin-top:2px;padding-top:2px}
        .ws-day-sep td,.ws-day-sep th{border-bottom:3px solid #94a3b8 !important}
        .dark .ws-day-sep td,.dark .ws-day-sep th{border-bottom-color:#475569 !important}
        /* v4.66.0: پالت پاستلی برای تفکیک بصری ستون هر کلاس (متن مشکی خوانا) */
        table.ws td.wsc-0{background:#fff1f2}table.ws th.wsc-0{background:#ffe4e6}
        table.ws td.wsc-1{background:#fff7ed}table.ws th.wsc-1{background:#ffedd5}
        table.ws td.wsc-2{background:#ecfdf5}table.ws th.wsc-2{background:#d1fae5}
        table.ws td.wsc-3{background:#eff6ff}table.ws th.wsc-3{background:#dbeafe}
        table.ws td.wsc-4{background:#f5f3ff}table.ws th.wsc-4{background:#ede9fe}
        table.ws td.wsc-5{background:#fdf2f8}table.ws th.wsc-5{background:#fce7f3}
        table.ws td.wsc-6{background:#f0fdfa}table.ws th.wsc-6{background:#ccfbf1}
        table.ws td.wsc-7{background:#f7fee7}table.ws th.wsc-7{background:#ecfccb}
        table.ws thead th[class*="wsc-"]{color:#111827 !important}
        /* dark mode: tint خیلی ملایم تا متن روشن خوانا بماند */
        .dark table.ws td[class*="wsc-"]{background:#111c30}
        .dark table.ws thead th[class*="wsc-"]{background:linear-gradient(135deg,#1e293b,#334155);color:#e2e8f0 !important}
        </style>

        <div class="ws-wrap">
        <table class="ws" id="wsTable" data-sort-ready="1"><!-- data-sort-ready: مرتب‌سازی سراسری main.js این جدول را بهم می‌ریزد -->
            <thead>
                <tr>
                    <th style="position:sticky;right:0;z-index:4;background:#c7d2fe;color:#111827;min-width:70px">روز</th>
                    <th style="position:sticky;right:70px;z-index:4;background:#c7d2fe;color:#111827;min-width:60px">زنگ</th>
                    <?php foreach ($matrixClasses as $ci => $mc): ?>
                        <th class="wsc-<?php echo $ci % 8; ?>"><?php echo clean($mc); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($matrixDays as $day): ?>
                <?php foreach ($periodsList as $pi => $period): $isLastPeriod = ($pi === count($periodsList) - 1); ?>
                <!-- subject row -->
                <tr>
                    <?php if ($pi === 0): ?>
                    <td class="ws-day" rowspan="<?php echo count($periodsList) * 2; ?>"><?php echo clean($day); ?></td>
                    <?php endif; ?>
                    <td class="ws-period"><?php echo clean($period); ?></td>
                    <?php foreach ($matrixClasses as $ci => $mc):
                        $k = norm_class_str($mc) . '|' . $day . '|' . $period;
                        $rows = $slotMap[$k] ?? [];
                        $r1 = $rows[0] ?? null; $r2 = $rows[1] ?? null;
                        $cellId = 'c' . md5($k);
                    ?>
                    <?php /* v4.89.0 PERFORMANCE: هر خانه فقط یک div سبک است؛
                             select واقعی تنها هنگام کلیک با جاوااسکریپت ساخته
                             می‌شود (روی سیستم‌های ضعیف ده‌ها برابر سبک‌تر). */
                        $s1 = $r1 ? trim($r1['subject_name']) : '';
                        $s2 = $r2 ? trim($r2['subject_name']) : '';
                    ?>
                    <td class="ws-cell wsc-<?php echo $ci % 8; ?>" id="<?php echo $cellId; ?>s"
                        data-class="<?php echo clean($mc); ?>" data-day="<?php echo clean($day); ?>" data-period="<?php echo clean($period); ?>"
                        data-revision="<?php echo ws_slot_revision($rows); ?>" data-s1="<?php echo clean($s1); ?>" data-s2="<?php echo clean($s2); ?>">
                        <div class="ws-val ws-subj <?php echo $s1 !== '' ? '' : 'empty'; ?>" tabindex="0" role="button" data-kind="subj" data-slot="1"><?php echo $s1 !== '' ? clean($s1) : '— خالی —'; ?></div>
                        <label class="ws-half"><input type="checkbox" class="ws-halfchk" <?php echo $r2 ? 'checked' : ''; ?>> تک زنگ (هفته در میان)</label>
                        <div class="ws-h2" style="<?php echo $r2 ? '' : 'display:none'; ?>">
                            <div class="ws-val ws-subj2 <?php echo $s2 !== '' ? '' : 'empty'; ?>" tabindex="0" role="button" data-kind="subj" data-slot="2"><?php echo $s2 !== '' ? clean($s2) : '— درس دوم —'; ?></div>
                        </div>
                    </td>
                    <?php endforeach; ?>
                </tr>
                <!-- teacher row -->
                <tr class="<?php echo $isLastPeriod ? 'ws-day-sep' : ''; ?>">
                    <td class="ws-period t">دبیر</td>
                    <?php foreach ($matrixClasses as $ci => $mc):
                        $k = norm_class_str($mc) . '|' . $day . '|' . $period;
                        $rows = $slotMap[$k] ?? [];
                        $r1 = $rows[0] ?? null; $r2 = $rows[1] ?? null;
                        $cellId = 'c' . md5($k);
                    ?>
                    <?php
                        $t1 = ($r1 && $r1['teacher_id']) ? (int)$r1['teacher_id'] : 0;
                        $t2 = ($r2 && $r2['teacher_id']) ? (int)$r2['teacher_id'] : 0;
                        $t1n = $t1 && isset($teacherNamesById[$t1]) ? $teacherNamesById[$t1] : ($r1['teacher_name'] ?? '');
                        $t2n = $t2 && isset($teacherNamesById[$t2]) ? $teacherNamesById[$t2] : ($r2['teacher_name'] ?? '');
                    ?>
                    <td class="ws-cell wsc-<?php echo $ci % 8; ?>" id="<?php echo $cellId; ?>t" data-pair="<?php echo $cellId; ?>s"
                        data-t1="<?php echo $t1; ?>" data-t2="<?php echo $t2; ?>">
                        <div class="ws-val ws-teach <?php echo $t1 ? '' : 'empty'; ?>" tabindex="0" role="button" data-kind="teach" data-slot="1"><?php echo $t1n !== '' ? clean($t1n) : '— دبیر —'; ?></div>
                        <div class="ws-h2" style="<?php echo $r2 ? '' : 'display:none'; ?>">
                            <div class="ws-val ws-teach2 <?php echo $t2 ? '' : 'empty'; ?>" tabindex="0" role="button" data-kind="teach" data-slot="2"><?php echo $t2n !== '' ? clean($t2n) : '— دبیر درس دوم —'; ?></div>
                        </div>
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="text-[11px] text-muted">راهنما: «تک زنگ» یعنی دو درس در همان زنگ که هفته در میان تدریس می‌شوند. برای خالی‌کردن یک زنگ، گزینه «— خالی —» را انتخاب کنید.</p>

        <?php /* v4.68.0: انتقال برنامه هفتگی به سال دیگر */ ?>
        <div class="card" style="border:1px dashed #a5b4fc;background:rgba(99,102,241,.04)">
            <h4 class="font-bold text-sm mb-2"><svg data-ui-icon="upload" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 16v5h18v-5M12 16V3m-5 5 5-5 5 5"/></svg> انتقال برنامه هفتگی به سال تحصیلی دیگر</h4>
            <form method="POST" class="flex flex-wrap items-end gap-3" onsubmit="return confirm('برنامه هفتگی سال مبدا به سال مقصد کپی شود؟')">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="copy_schedule_year" value="1">
                <div>
                    <label class="block text-xs font-semibold mb-1">سال مبدا (کپی از)</label>
                    <select name="src_year" class="form-select text-xs font-bold">
                        <?php foreach ($yearsList as $yl): ?>
                            <option value="<?php echo clean($yl['academic_year']); ?>" <?php echo $year === $yl['academic_year'] ? 'selected' : ''; ?>><?php echo clean($yl['academic_year']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1">سال مقصد (کپی به)</label>
                    <select name="dst_year" class="form-select text-xs font-bold">
                        <?php foreach ($yearsList as $yl): ?>
                            <option value="<?php echo clean($yl['academic_year']); ?>"><?php echo clean($yl['academic_year']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <label class="flex items-center gap-2 text-xs font-semibold pb-2">
                    <input type="checkbox" name="overwrite_dst" value="1"> جایگزینی کامل (برنامه فعلی مقصد پاک شود)
                </label>
                <button class="btn btn-primary text-xs">انتقال برنامه</button>
            </form>
            <p class="text-[11px] text-muted mt-2">بدون تیک «جایگزینی کامل»، زنگ‌های تکراری در مقصد رد می‌شوند و بقیه اضافه می‌شوند.</p>
        </div>

        <script>
        var WS_CSRF = <?php echo json_encode(csrf_token()); ?>;
        var WS_URL = 'classes.php?tab=schedule&year=<?php echo urlencode($year); ?>';
        var wsTimers = {};
        /* v4.89.0 PERFORMANCE: لیست دروس/دبیران فقط یک بار به صورت JSON؛
           selectها هنگام کلیک ساخته و بعد از انتخاب حذف می‌شوند. با این کار
           به جای هزاران <option> در DOM، فقط چند صد div سبک داریم. */
        var WS_STD_SUBJECTS = <?php echo json_encode(array_values($standardSubjects), JSON_UNESCAPED_UNICODE); ?>;
        var WS_EXTRA_SUBJECTS = <?php echo json_encode(array_values(array_diff($subjectNameOptions, $standardSubjects)), JSON_UNESCAPED_UNICODE); ?>;
        var WS_TEACHERS = <?php echo json_encode(array_map(function($t){ return ['id' => (int)$t['id'], 'n' => $t['full_name']]; }, $teachersList), JSON_UNESCAPED_UNICODE); ?>;

        function wsStatus(txt, color){
            var el = document.getElementById('wsStatus');
            if (el) { el.textContent = txt; el.style.color = color || '#64748b'; }
        }
        function wsCellOf(el){
            var td = el.closest('td.ws-cell');
            if (td && td.dataset.pair) td = document.getElementById(td.dataset.pair); /* teacher cell -> subject cell */
            return td;
        }
        function wsCollect(td){
            var tdT = document.getElementById(td.id.replace(/s$/, 't'));
            var subj  = td.dataset.s1 || '';
            var half  = td.querySelector('.ws-halfchk').checked;
            var subj2 = td.dataset.s2 || '';
            var t1 = tdT ? (tdT.dataset.t1 || '0') : '0';
            var t2 = tdT ? (tdT.dataset.t2 || '0') : '0';
            return {
                cls: td.dataset.class, day: td.dataset.day, period: td.dataset.period,
                subj: subj, half: half && subj2 !== '' ? '1' : '0', subj2: subj2, t1: t1, t2: t2, tdT: tdT
            };
        }
        var wsBusy = {}, wsPending = {}, wsDirty = {};
        function wsSave(td){
            wsDirty[td.id] = true; wsPending[td.id] = true;
            td.classList.remove('saved'); wsStatus('در انتظار ذخیره...', '#d97706');
            clearTimeout(wsTimers[td.id]);
            wsTimers[td.id] = setTimeout(function(){ wsFlush(td); },350);
        }
        function wsFlush(td){
            if (wsBusy[td.id] || !wsPending[td.id]) return;
            wsBusy[td.id] = true; wsPending[td.id] = false;
            var d = wsCollect(td); td.dataset.saveError = '';
            td.classList.remove('saved','err'); td.classList.add('saving');
            if (d.tdT) { d.tdT.classList.remove('saved','err'); d.tdT.classList.add('saving'); }
            wsStatus('در حال ذخیره...', '#d97706');
            var body = new URLSearchParams();
            body.set('ajax_cell_save','1'); body.set('csrf_token',WS_CSRF);
            body.set('class_name',d.cls); body.set('day_of_week',d.day); body.set('period_num',d.period);
            body.set('subject_name',d.subj); body.set('teacher_id',d.t1);
            body.set('is_half',d.half); body.set('subject_name2',d.subj2); body.set('teacher_id2',d.t2);
            body.set('revision',td.dataset.revision);
            body.set('clear_slot',td.dataset.clearSlot || '0');
            body.set('teacher_edited1',d.tdT ? d.tdT.dataset.edited1 || '0' : '0');
            body.set('teacher_edited2',d.tdT ? d.tdT.dataset.edited2 || '0' : '0');
            fetch(WS_URL,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString()})
                .then(function(r){ if (!r.ok) throw new Error('network'); return r.json(); })
                .then(function(j){
                    if (!j.ok) throw new Error(j.message || 'ذخیره انجام نشد');
                    td.dataset.revision = j.revision;
                    if (!wsPending[td.id]) {
                        wsDirty[td.id] = false; td.dataset.clearSlot = '0';
                        if (d.tdT) { d.tdT.dataset.edited1 = '0'; d.tdT.dataset.edited2 = '0'; }
                        td.classList.add('saved'); if (d.tdT) d.tdT.classList.add('saved');
                    }
                })
                .catch(function(e){ td.classList.add('err'); if(d.tdT)d.tdT.classList.add('err'); td.dataset.saveError = e.message; })
                .then(function(){
                    wsBusy[td.id] = false;
                    td.classList.remove('saving'); if(d.tdT)d.tdT.classList.remove('saving');
                    if (wsPending[td.id]) wsFlush(td);
                    else {
                        var dirty = Object.keys(wsDirty).some(function(k){return wsDirty[k];});
                        wsStatus(dirty ? 'ذخیره کامل نشده — '+(td.dataset.saveError || 'منتظر بمانید یا تلاش دوباره را بزنید') : 'ذخیره شد ✓',dirty ? '#dc2626' : '#059669');
                    }
                });
        }
        function wsRetrySaving(){ Object.keys(wsDirty).forEach(function(k){if(wsDirty[k]) wsSave(document.getElementById(k));}); }
        window.addEventListener('beforeunload',function(e){
            if(Object.keys(wsDirty).some(function(k){return wsDirty[k];})) { e.preventDefault(); e.returnValue=''; }
        });
        /* ---------- v4.89.0: lazy select — ساخت select فقط هنگام کلیک ---------- */
        function wsBuildSubjectSelect(current, slot){
            var sel = document.createElement('select');
            sel.className = 'ws-sel';
            sel.setAttribute('data-no-search','1'); // Own lifecycle: do not wrap this transient select.
            var mk = function(v, t){ var o = document.createElement('option'); o.value = v; o.textContent = t; if (v === current) o.selected = true; return o; };
            sel.appendChild(mk('', slot === 2 ? '— درس دوم —' : '— خالی —'));
            var og1 = document.createElement('optgroup'); og1.label = 'دروس رسمی متوسطه اول';
            WS_STD_SUBJECTS.forEach(function(s){ og1.appendChild(mk(s, s)); });
            sel.appendChild(og1);
            if (WS_EXTRA_SUBJECTS.length){
                var og2 = document.createElement('optgroup'); og2.label = 'سایر دروس';
                WS_EXTRA_SUBJECTS.forEach(function(s){ og2.appendChild(mk(s, s)); });
                sel.appendChild(og2);
            }
            if (current && WS_STD_SUBJECTS.indexOf(current) === -1 && WS_EXTRA_SUBJECTS.indexOf(current) === -1) sel.appendChild(mk(current, current));
            return sel;
        }
        function wsBuildTeacherSelect(current, slot){
            var sel = document.createElement('select');
            sel.className = 'ws-sel';
            sel.setAttribute('data-no-search','1'); // Own lifecycle: do not wrap this transient select.
            var mk = function(v, t){ var o = document.createElement('option'); o.value = String(v); o.textContent = t; if (String(v) === String(current)) o.selected = true; return o; };
            sel.appendChild(mk(0, slot === 2 ? '— دبیر درس دوم —' : '— دبیر —'));
            WS_TEACHERS.forEach(function(t){ sel.appendChild(mk(t.id, t.n)); });
            return sel;
        }
        function wsOpenPicker(valEl){
            if (valEl.dataset.open === '1') return;
            valEl.dataset.open = '1';
            var kind = valEl.dataset.kind, slot = parseInt(valEl.dataset.slot || '1', 10);
            var td = valEl.closest('td.ws-cell');
            var current = kind === 'subj' ? (td.dataset['s' + slot] || '') : (td.dataset['t' + slot] || '0');
            var source = wsCellOf(valEl);
            if (kind === 'teach' && !(source.dataset['s'+slot] || '')) {
                valEl.dataset.open = ''; wsStatus('ابتدا درس این خانه را انتخاب کنید.','#dc2626'); return;
            }
            var sel = kind === 'subj' ? wsBuildSubjectSelect(current, slot) : wsBuildTeacherSelect(current, slot);
            if (kind === 'teach' && current !== '0' && !Array.prototype.some.call(sel.options,function(o){return o.value===current;})) {
                var kept = document.createElement('option'); kept.value=current; kept.textContent=valEl.textContent; kept.selected=true; sel.appendChild(kept);
            }
            valEl.style.display = 'none';
            valEl.parentNode.insertBefore(sel, valEl);
            var done = function(commit){
                if (!sel.parentNode) return;
                if (commit) {
                    var v = sel.value;
                    if (kind === 'subj' && current && v === '' && !confirm('درس و تخصیص دبیر همین '+(slot===1?'زنگ (هر دو نیم‌زنگ)':'نیم‌زنگ')+' پاک شود؟')) { done(false); return; }
                    if (kind === 'subj' && slot === 1) td.dataset.clearSlot = v === '' ? '1' : '0';
                    var txt = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].textContent : '';
                    if (kind === 'subj') {
                        td.dataset['s' + slot] = v;
                        valEl.textContent = v !== '' ? txt : (slot === 2 ? '— درس دوم —' : '— خالی —');
                        valEl.classList.toggle('empty', v === '');
                        if (v === '') {
                            var teachers = document.getElementById(td.id.replace(/s$/,'t'));
                            var slots = slot === 1 ? [1,2] : [2];
                            slots.forEach(function(n){
                                if(teachers) {
                                    teachers.dataset['t'+n] = '0'; teachers.dataset['edited'+n] = '1';
                                    var label = teachers.querySelector(n===1?'.ws-teach':'.ws-teach2');
                                    if(label){label.textContent=n===1?'— دبیر —':'— دبیر درس دوم —';label.classList.add('empty');}
                                }
                            });
                            if(slot===1){
                                td.dataset.s2=''; td.querySelector('.ws-halfchk').checked=false;
                                td.querySelector('.ws-subj2').textContent='— درس دوم —';
                                td.querySelector('.ws-h2').style.display='none';
                                if(teachers) teachers.querySelector('.ws-h2').style.display='none';
                            }
                        }

                    } else {
                        td.dataset['t' + slot] = v;
                        td.dataset['edited' + slot] = '1';
                        valEl.textContent = v !== '0' ? txt : (slot === 2 ? '— دبیر درس دوم —' : '— دبیر —');
                        valEl.classList.toggle('empty', v === '0');
                    }
                }
                sel.parentNode.removeChild(sel);
                valEl.style.display = '';
                valEl.dataset.open = '';
                if (commit) { var tdS = wsCellOf(valEl); if (tdS) wsSave(tdS); }
            };
            sel.addEventListener('change', function(){ done(true); });
            sel.addEventListener('blur', function(){ setTimeout(function(){ done(false); }, 150); });
            sel.addEventListener('keydown', function(e){ if (e.key === 'Escape') done(false); });
            sel.focus();
            /* باز کردن خودکار لیست در مرورگرهای پشتیبان */
            if (typeof sel.showPicker === 'function') { try { sel.showPicker(); } catch(e){} }
        }
        function wsHalfToggled(chk){
            var td = chk.closest('td.ws-cell');
            var tdT = document.getElementById(td.id.replace(/s$/, 't'));
            var on = chk.checked;
            if (!on && td.dataset.s2 && !confirm('درس و دبیر نیم‌زنگ دوم همین خانه حذف شود؟')) { chk.checked=true; return; }
            var row2s = td.querySelector('.ws-h2');
            var row2t = tdT ? tdT.querySelector('.ws-h2') : null;
            if (row2s) row2s.style.display = on ? '' : 'none';
            if (row2t) row2t.style.display = on ? '' : 'none';
            if (!on) {
                /* turning تک زنگ off: clear second subject+teacher and save */
                td.dataset.s2 = '';
                var v2 = td.querySelector('.ws-subj2'); if (v2) { v2.textContent = '— درس دوم —'; v2.classList.add('empty'); }
                if (tdT) {
                    tdT.dataset.t2 = '0';
                    var tv2 = tdT.querySelector('.ws-teach2'); if (tv2) { tv2.textContent = '— دبیر درس دوم —'; tv2.classList.add('empty'); }
                }
                if (td.dataset.s1) wsSave(td);
            }
            /* turning on: wait until درس دوم انتخاب شود */
        }
        /* یک event delegation سراسری به‌جای صدها onclick جدا */
        (function(){
            var tbl = document.getElementById('wsTable');
            if (!tbl) return;
            tbl.addEventListener('click', function(e){
                var v = e.target.closest('.ws-val');
                if (v) { wsOpenPicker(v); return; }
                var chk = e.target.closest('.ws-halfchk');
                if (chk) wsHalfToggled(chk);
            });
            tbl.addEventListener('keydown', function(e){
                if ((e.key === 'Enter' || e.key === ' ') && e.target.classList && e.target.classList.contains('ws-val')) {
                    e.preventDefault();
                    wsOpenPicker(e.target);
                }
            });
        })();
        </script>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
