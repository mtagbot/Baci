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
    $cName  = norm_class_str($_POST['class_name'] ?? '');
    $day    = trim($_POST['day_of_week'] ?? '');
    $period = trim($_POST['period_num'] ?? '');
    $subj   = trim($_POST['subject_name'] ?? '');
    $tId    = (int)($_POST['teacher_id'] ?? 0);
    $isHalf = !empty($_POST['is_half']) && $_POST['is_half'] === '1';
    $subj2  = trim($_POST['subject_name2'] ?? '');
    $tId2   = (int)($_POST['teacher_id2'] ?? 0);
    if ($cName === '' || $day === '' || $period === '') { echo json_encode(['ok' => false, 'message' => 'سلول نامعتبر']); exit; }
    try {
        $tName = ''; if ($tId > 0) { $tObj = DB::fetch("SELECT full_name FROM teachers WHERE id=?", [$tId]); $tName = $tObj['full_name'] ?? ''; }
        $tName2 = ''; if ($tId2 > 0) { $tObj2 = DB::fetch("SELECT full_name FROM teachers WHERE id=?", [$tId2]); $tName2 = $tObj2['full_name'] ?? ''; }
        // replace-all strategy: delete existing rows of this slot, insert fresh
        DB::execute("DELETE FROM class_schedules WHERE academic_year=? AND class_name=? AND day_of_week=? AND period_num=?", [$year, $cName, $day, $period]);
        $saved = 0;
        if ($subj !== '') {
            DB::execute("INSERT INTO class_schedules (academic_year, class_name, day_of_week, period_num, subject_name, teacher_name, teacher_id) VALUES (?,?,?,?,?,?,?)",
                [$year, $cName, $day, $period, $subj, $tName, $tId ?: null]);
            $saved++;
            if ($isHalf && $subj2 !== '' && $subj2 !== $subj) {
                DB::execute("INSERT INTO class_schedules (academic_year, class_name, day_of_week, period_num, subject_name, teacher_name, teacher_id) VALUES (?,?,?,?,?,?,?)",
                    [$year, $cName, $day, $period, $subj2, $tName2, $tId2 ?: null]);
                $saved++;
            }
        }
        echo json_encode(['ok' => true, 'saved' => $saved, 'teacher' => $tName, 'teacher2' => $tName2], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'message' => 'خطای پایگاه داده: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
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
$yearsList = get_academic_years_for_filter();
if (empty($yearsList)) { $yearsList = [['academic_year' => $year]]; }

// v4.39.0 - unified dropdown sources (no free-text fields anywhere in this page)
$stdGrades = ['هفتم','هشتم','نهم','دهم','یازدهم','دوازدهم'];
$gradeOptions = [];
foreach (get_unified_grade_options($year) as $go) { $g = trim($go['grade_level'] ?? ''); if ($g !== '' && !in_array($g, $gradeOptions, true)) $gradeOptions[] = $g; }
foreach ($stdGrades as $g) { if (!in_array($g, $gradeOptions, true)) $gradeOptions[] = $g; }

$classNameOptions = [];
foreach ($classesList as $co) { $c = trim($co['name'] ?? ''); if ($c !== '' && !in_array($c, $classNameOptions, true)) $classNameOptions[] = $c; }
foreach (get_unified_class_options($year) as $co) { $c = trim($co['class_name'] ?? ''); if ($c !== '' && !in_array($c, $classNameOptions, true)) $classNameOptions[] = $c; }

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
                <a href="classes.php?tab=classes&year=<?php echo urlencode($year); ?>" class="btn <?php echo $tab === 'classes' ? 'btn-primary font-bold' : 'btn-outline'; ?>">🏫 کلاس‌ها</a>
                <a href="classes.php?tab=schedule&year=<?php echo urlencode($year); ?>" class="btn <?php echo $tab === 'schedule' ? 'btn-primary font-bold' : 'btn-outline'; ?>">📅 برنامه هفتگی و دبیران</a>
                <a href="classes.php?tab=<?php echo urlencode($tab); ?>&year=<?php echo urlencode($year); ?>&sync_schedule=1&csrf_token=<?php echo urlencode(csrf_token()); ?>" class="btn btn-success text-xs" title="ایجاد رکوردهای کلاس و درس از روی برنامه هفتگی">🔄 همگام‌سازی با برنامه</a>
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
        $matrixDays = ['شنبه','یک‌شنبه','دوشنبه','سه‌شنبه','چهارشنبه'];
        $matrixClasses = $classNameOptions;
    ?>
    <div class="card shadow-lg space-y-4">
        <div class="flex justify-between items-center border-b pb-3">
            <div>
                <h3 class="font-bold text-primary">📅 برنامه هفتگی سال <?php echo tr_num($year, 'fa'); ?> — ۴ زنگ</h3>
                <p class="text-xs text-muted mt-1">هر خانه را انتخاب کنید؛ تغییرات <b>بلافاصله و خودکار</b> ذخیره می‌شود (نیازی به دکمه تایید نیست).</p>
            </div>
            <div class="flex gap-2 items-center">
                <span id="wsStatus" class="text-xs font-bold" style="color:#64748b">آماده</span>
                <form method="GET" class="flex items-center gap-2">
                    <input type="hidden" name="tab" value="schedule">
                    <select name="year" class="form-select text-xs font-bold" onchange="this.form.submit()">
                        <?php foreach ($yearsList as $yl): ?>
                            <option value="<?php echo clean($yl['academic_year']); ?>" <?php echo $year === $yl['academic_year'] ? 'selected' : ''; ?>><?php echo clean($yl['academic_year']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <a href="import-schedule.php" class="btn btn-primary text-xs">⚡ ایمپورت barname.csv</a>
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
        table.ws thead th{position:sticky;top:0;z-index:3;background:linear-gradient(135deg,#4f46e5,#3730a3);color:#fff !important;font-size:.8rem;padding:10px 6px;text-align:center}
        table.ws .ws-day{position:sticky;right:0;z-index:2;background:linear-gradient(135deg,#0ea5e9,#0369a1) !important;color:#fff !important;font-weight:800;font-size:.85rem;text-align:center;vertical-align:middle;min-width:70px}
        table.ws .ws-period{position:sticky;right:70px;z-index:2;background:#f1f5f9 !important;font-size:.68rem;font-weight:700;color:#334155;text-align:center;white-space:nowrap;min-width:60px}
        .dark table.ws .ws-period{background:#1e293b !important;color:#cbd5e1}
        table.ws .ws-period.t{color:#059669;font-size:.62rem}
        .ws-sel{width:100%;border:1px solid transparent;background:transparent;font-family:inherit;font-size:.72rem;font-weight:700;padding:5px 4px;border-radius:8px;cursor:pointer;color:inherit}
        .ws-sel:hover{border-color:#c7d2fe;background:rgba(79,70,229,.05)}
        .ws-sel:focus{outline:none;border-color:#4f46e5;background:rgba(79,70,229,.08)}
        .ws-sel.empty{color:#94a3b8;font-weight:400}
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
        </style>

        <div class="ws-wrap">
        <table class="ws" id="wsTable">
            <thead>
                <tr>
                    <th style="position:sticky;right:0;z-index:4;background:#312e81">روز / زنگ</th>
                    <?php foreach ($matrixClasses as $mc): ?>
                        <th><?php echo clean($mc); ?></th>
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
                    <?php foreach ($matrixClasses as $mc):
                        $k = norm_class_str($mc) . '|' . $day . '|' . $period;
                        $rows = $slotMap[$k] ?? [];
                        $r1 = $rows[0] ?? null; $r2 = $rows[1] ?? null;
                        $cellId = 'c' . md5($k);
                    ?>
                    <td class="ws-cell" id="<?php echo $cellId; ?>s"
                        data-class="<?php echo clean($mc); ?>" data-day="<?php echo clean($day); ?>" data-period="<?php echo clean($period); ?>">
                        <select class="ws-sel ws-subj <?php echo $r1 ? '' : 'empty'; ?>" onchange="wsCellChanged(this)">
                            <option value="">— خالی —</option>
                            <optgroup label="دروس رسمی متوسطه اول">
                                <?php foreach ($standardSubjects as $sn): ?><option value="<?php echo clean($sn); ?>" <?php echo ($r1 && trim($r1['subject_name']) === $sn) ? 'selected' : ''; ?>><?php echo clean($sn); ?></option><?php endforeach; ?>
                            </optgroup>
                            <?php $extraS = array_values(array_diff($subjectNameOptions, $standardSubjects)); if ($extraS): ?>
                            <optgroup label="سایر دروس">
                                <?php foreach ($extraS as $sn): ?><option value="<?php echo clean($sn); ?>" <?php echo ($r1 && trim($r1['subject_name']) === $sn) ? 'selected' : ''; ?>><?php echo clean($sn); ?></option><?php endforeach; ?>
                            </optgroup>
                            <?php endif; ?>
                            <?php if ($r1 && trim($r1['subject_name']) !== '' && !in_array(trim($r1['subject_name']), $subjectPickerOptions, true)): ?>
                                <option value="<?php echo clean($r1['subject_name']); ?>" selected><?php echo clean($r1['subject_name']); ?></option>
                            <?php endif; ?>
                        </select>
                        <label class="ws-half"><input type="checkbox" class="ws-halfchk" <?php echo $r2 ? 'checked' : ''; ?> onchange="wsHalfToggled(this)"> تک زنگ (هفته در میان)</label>
                        <div class="ws-h2" style="<?php echo $r2 ? '' : 'display:none'; ?>">
                            <select class="ws-sel ws-subj2 <?php echo $r2 ? '' : 'empty'; ?>" onchange="wsCellChanged(this)">
                                <option value="">— درس دوم —</option>
                                <optgroup label="دروس رسمی متوسطه اول">
                                    <?php foreach ($standardSubjects as $sn): ?><option value="<?php echo clean($sn); ?>" <?php echo ($r2 && trim($r2['subject_name']) === $sn) ? 'selected' : ''; ?>><?php echo clean($sn); ?></option><?php endforeach; ?>
                                </optgroup>
                                <?php if ($extraS): ?>
                                <optgroup label="سایر دروس">
                                    <?php foreach ($extraS as $sn): ?><option value="<?php echo clean($sn); ?>" <?php echo ($r2 && trim($r2['subject_name']) === $sn) ? 'selected' : ''; ?>><?php echo clean($sn); ?></option><?php endforeach; ?>
                                </optgroup>
                                <?php endif; ?>
                                <?php if ($r2 && trim($r2['subject_name']) !== '' && !in_array(trim($r2['subject_name']), $subjectPickerOptions, true)): ?>
                                    <option value="<?php echo clean($r2['subject_name']); ?>" selected><?php echo clean($r2['subject_name']); ?></option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </td>
                    <?php endforeach; ?>
                </tr>
                <!-- teacher row -->
                <tr class="<?php echo $isLastPeriod ? 'ws-day-sep' : ''; ?>">
                    <td class="ws-period t">دبیر</td>
                    <?php foreach ($matrixClasses as $mc):
                        $k = norm_class_str($mc) . '|' . $day . '|' . $period;
                        $rows = $slotMap[$k] ?? [];
                        $r1 = $rows[0] ?? null; $r2 = $rows[1] ?? null;
                        $cellId = 'c' . md5($k);
                    ?>
                    <td class="ws-cell" id="<?php echo $cellId; ?>t" data-pair="<?php echo $cellId; ?>s">
                        <select class="ws-sel ws-teach <?php echo ($r1 && $r1['teacher_id']) ? '' : 'empty'; ?>" onchange="wsTeacherChanged(this)">
                            <option value="0">— دبیر —</option>
                            <?php foreach ($teachersList as $tl): ?>
                                <option value="<?php echo (int)$tl['id']; ?>" <?php echo ($r1 && (int)$r1['teacher_id'] === (int)$tl['id']) ? 'selected' : ''; ?>><?php echo clean($tl['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="ws-h2" style="<?php echo $r2 ? '' : 'display:none'; ?>">
                            <select class="ws-sel ws-teach2 <?php echo ($r2 && $r2['teacher_id']) ? '' : 'empty'; ?>" onchange="wsTeacherChanged(this)">
                                <option value="0">— دبیر درس دوم —</option>
                                <?php foreach ($teachersList as $tl): ?>
                                    <option value="<?php echo (int)$tl['id']; ?>" <?php echo ($r2 && (int)$r2['teacher_id'] === (int)$tl['id']) ? 'selected' : ''; ?>><?php echo clean($tl['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
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

        <script>
        var WS_CSRF = <?php echo json_encode(csrf_token()); ?>;
        var WS_URL = 'classes.php?tab=schedule&year=<?php echo urlencode($year); ?>';
        var wsTimers = {};

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
            var subj  = td.querySelector('.ws-subj').value;
            var half  = td.querySelector('.ws-halfchk').checked;
            var subj2 = td.querySelector('.ws-subj2') ? td.querySelector('.ws-subj2').value : '';
            var t1 = tdT ? tdT.querySelector('.ws-teach').value : '0';
            var t2 = tdT && tdT.querySelector('.ws-teach2') ? tdT.querySelector('.ws-teach2').value : '0';
            return {
                cls: td.dataset.class, day: td.dataset.day, period: td.dataset.period,
                subj: subj, half: half && subj2 !== '' ? '1' : '0', subj2: subj2, t1: t1, t2: t2, tdT: tdT
            };
        }
        function wsSave(td){
            var d = wsCollect(td);
            /* debounce per-cell: rapid consecutive changes = one request */
            clearTimeout(wsTimers[td.id]);
            wsTimers[td.id] = setTimeout(function(){
                td.classList.remove('saved','err'); td.classList.add('saving');
                if (d.tdT) { d.tdT.classList.remove('saved','err'); d.tdT.classList.add('saving'); }
                wsStatus('در حال ذخیره...', '#d97706');
                var body = new URLSearchParams();
                body.set('ajax_cell_save', '1'); body.set('csrf_token', WS_CSRF);
                body.set('class_name', d.cls); body.set('day_of_week', d.day); body.set('period_num', d.period);
                body.set('subject_name', d.subj); body.set('teacher_id', d.t1);
                body.set('is_half', d.half); body.set('subject_name2', d.subj2); body.set('teacher_id2', d.t2);
                fetch(WS_URL, { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'}, body: body.toString() })
                    .then(function(r){ return r.json(); })
                    .then(function(j){
                        td.classList.remove('saving'); if (d.tdT) d.tdT.classList.remove('saving');
                        if (j.ok) {
                            td.classList.add('saved'); if (d.tdT) d.tdT.classList.add('saved');
                            setTimeout(function(){ td.classList.remove('saved'); if (d.tdT) d.tdT.classList.remove('saved'); }, 1300);
                            wsStatus('ذخیره شد ✓', '#059669');
                        } else {
                            td.classList.add('err'); if (d.tdT) d.tdT.classList.add('err');
                            wsStatus(j.message || 'خطا در ذخیره', '#dc2626');
                        }
                    })
                    .catch(function(){
                        td.classList.remove('saving'); if (d.tdT) d.tdT.classList.remove('saving');
                        td.classList.add('err'); if (d.tdT) d.tdT.classList.add('err');
                        wsStatus('خطای شبکه — دوباره تلاش کنید', '#dc2626');
                    });
            }, 350);
        }
        function wsCellChanged(sel){
            sel.classList.toggle('empty', !sel.value);
            var td = wsCellOf(sel);
            if (td) wsSave(td);
        }
        function wsTeacherChanged(sel){
            sel.classList.toggle('empty', sel.value === '0');
            var td = wsCellOf(sel);
            if (td) wsSave(td);
        }
        function wsHalfToggled(chk){
            var td = chk.closest('td.ws-cell');
            var tdT = document.getElementById(td.id.replace(/s$/, 't'));
            var on = chk.checked;
            var row2s = td.querySelector('.ws-h2');
            var row2t = tdT ? tdT.querySelector('.ws-h2') : null;
            if (row2s) row2s.style.display = on ? '' : 'none';
            if (row2t) row2t.style.display = on ? '' : 'none';
            if (!on) {
                /* turning تک زنگ off: clear second subject+teacher and save */
                var s2 = td.querySelector('.ws-subj2'); if (s2) s2.value = '';
                var t2 = tdT ? tdT.querySelector('.ws-teach2') : null; if (t2) t2.value = '0';
                wsSave(td);
            }
            /* turning on: wait until درس دوم انتخاب شود — wsCellChanged ذخیره می‌کند */
        }
        </script>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
