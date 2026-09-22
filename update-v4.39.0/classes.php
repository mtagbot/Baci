<?php
/**
 * Classes, Subjects & Weekly Schedule Management (classes.php)
 * v4.39.0 - All selectors are dropdowns (no free text), auto subject codes,
 *           inline editable weekly-schedule rows (class/day/period/subject/teacher).
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/academic_year_helpers.php';
require_once __DIR__ . '/includes/class_schedule_sync.php';
require_once __DIR__ . '/includes/school_sort.php';
require_once __DIR__ . '/includes/school_roles.php';
ensure_school_roles_schema();
if (!current_teacher_is_executive()) require_permission('manage_classes');

$tab  = $_GET['tab'] ?? 'classes';
if (current_teacher_is_executive() && !in_array($tab, ['classes','subjects'], true)) $tab = 'classes';
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
        // v4.39.0 - subject name from dropdown ('__new__' reveals a text input), auto code
        $name  = trim($_POST['name_select'] ?? '');
        if ($name === '__new__' || $name === '') $name = trim($_POST['name_new'] ?? ($name === '__new__' ? '' : trim($_POST['name'] ?? '')));
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_sched'])) {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        $cName = norm_class_str($_POST['class_name'] ?? '');
        $day = trim($_POST['day_of_week'] ?? '');
        $period = trim($_POST['period_num'] ?? '');
        $subj = trim($_POST['subject_name'] ?? '');
        $tId = (int)($_POST['teacher_id'] ?? 0);
        $tName = '';
        if ($tId > 0) { $tObj = DB::fetch("SELECT full_name FROM teachers WHERE id = ?", [$tId]); $tName = $tObj ? $tObj['full_name'] : ''; }
        if ($cName !== '' && $day !== '' && $subj !== '') {
            DB::execute("INSERT INTO class_schedules (academic_year, class_name, day_of_week, period_num, subject_name, teacher_name, teacher_id) VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$year, $cName, $day, $period, $subj, $tName, $tId ?: null]);
            set_flash_message('success', "زنگ جدید ثبت شد: $cName - $day - $period - $subj");
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

$schedList    = DB::fetchAll("SELECT cs.*, t.full_name as t_name FROM class_schedules cs LEFT JOIN teachers t ON cs.teacher_id = t.id WHERE $schedWhereSql ORDER BY cs.class_name ASC, cs.id ASC", $schedParams);
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

$daysList = ['شنبه','یک‌شنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه'];
$periodsList = []; for ($i = 1; $i <= 8; $i++) $periodsList[] = 'زنگ ' . $i;

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
                <a href="classes.php?tab=subjects&year=<?php echo urlencode($year); ?>" class="btn <?php echo $tab === 'subjects' ? 'btn-primary font-bold' : 'btn-outline'; ?>">📚 دروس</a>
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

    <?php elseif ($tab === 'subjects'): ?>
    <div class="grid grid-cols-3 gap-6">
        <div class="card col-span-1 shadow-lg">
            <h3 class="font-bold mb-4 text-primary">تعریف درس تحصیلی و تخصیص دبیر</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="add_subject" value="1">
                <div class="mb-4">
                    <label class="block text-xs font-semibold mb-1">نام درس *</label>
                    <select name="name_select" id="subjNameSel" class="form-select font-bold" required onchange="toggleNewSubjInput()">
                        <option value="">--- انتخاب درس ---</option>
                        <?php foreach ($subjectNameOptions as $sn): ?>
                            <option value="<?php echo clean($sn); ?>"><?php echo clean($sn); ?></option>
                        <?php endforeach; ?>
                        <option value="__new__">+ تعریف درس جدید...</option>
                    </select>
                    <input type="text" name="name_new" id="subjNameNew" class="form-input mt-2" placeholder="نام درس جدید را بنویسید" style="display:none">
                </div>
                <div class="mb-4">
                    <label class="block text-xs font-semibold mb-1">کد درس (تخصیص خودکار توسط سیستم)</label>
                    <div class="form-input dir-ltr text-left font-mono" style="background:var(--bg-secondary,#f1f5f9);cursor:default"><?php echo clean($nextSubjectCode); ?></div>
                </div>
                <div class="mb-4">
                    <label class="block text-xs font-semibold mb-1">پایه / رشته</label>
                    <select name="grade_level" class="form-select font-bold">
                        <option value="">--- عمومی (همه پایه‌ها) ---</option>
                        <?php foreach ($gradeOptions as $g): ?>
                            <option value="<?php echo clean($g); ?>"><?php echo clean($g); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-6">
                    <label class="block text-xs font-semibold mb-1">انتخاب دبیر / سرپرست درس</label>
                    <select name="teacher_id" class="form-select font-bold">
                        <option value="0">--- انتخاب دبیر ---</option>
                        <?php foreach ($teachersList as $tl): ?>
                            <option value="<?php echo $tl['id']; ?>"><?php echo clean($tl['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-success w-full py-2.5 font-bold">+ ثبت درس و تخصیص دبیر</button>
            </form>
            <script>
            function toggleNewSubjInput(){
                var s=document.getElementById('subjNameSel'), i=document.getElementById('subjNameNew');
                if(s&&i){ var isNew = s.value==='__new__'; i.style.display = isNew?'block':'none'; i.required = isNew; if(!isNew) i.value=''; }
            }
            </script>
        </div>
        <div class="card col-span-2 shadow-lg space-y-5">
            <div>
                <h3 class="font-bold text-primary mb-3">دروس واقعی استخراج‌شده از برنامه هفتگی سال <?php echo tr_num($year, 'fa'); ?></h3>
                <p class="text-xs text-muted mb-3">این جدول از `class_schedules` ساخته می‌شود؛ بنابراین درس‌های اختصاص‌یافته به هر کلاس، حتی اگر با فرم دستی تعریف نشده باشند، نمایش داده می‌شوند.</p>
                <div class="table-container max-h-[430px]">
                    <table>
                        <thead><tr><th>کلاس</th><th>نام درس</th><th>دبیر</th><th>روزهای هفته</th><th>تعداد زنگ</th><th>منبع</th></tr></thead>
                        <tbody>
                            <?php foreach ($scheduleSubjectsList as $ss): ?>
                            <tr>
                                <td class="font-bold text-primary"><?php echo clean($ss['class_name']); ?></td>
                                <td class="font-bold"><?php echo clean($ss['subject_name']); ?></td>
                                <td><span class="badge badge-info"><?php echo clean($ss['teacher_name'] ?: 'تعیین نشده'); ?></span></td>
                                <td class="text-xs"><?php echo clean($ss['days_text']); ?></td>
                                <td><span class="badge badge-success"><?php echo tr_num((int)$ss['lesson_count'], 'fa'); ?></span></td>
                                <td><span class="badge badge-warning">برنامه هفتگی</span></td>
                            </tr>
                            <?php endforeach; if (empty($scheduleSubjectsList)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-6">درسی از برنامه هفتگی برای این سال یافت نشد.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div>
                <h3 class="font-bold text-primary mb-3">دروس تعریف‌شده با فرم دستی / همگام‌شده</h3>
                <div class="table-container max-h-[350px]">
                    <table>
                        <thead><tr><th>شناسه</th><th>نام درس</th><th>کد درس</th><th>پایه / رشته</th><th>دبیر مربوطه</th><th>حذف</th></tr></thead>
                        <tbody>
                            <?php foreach ($subjectsList as $sb): ?>
                            <tr>
                                <td class="font-mono text-xs">#<?php echo $sb['id']; ?></td>
                                <td class="font-bold"><?php echo clean($sb['name']); ?></td>
                                <td class="font-mono text-xs"><?php echo clean($sb['code']); ?></td>
                                <td><?php echo clean($sb['grade_level']); ?></td>
                                <td><span class="badge bg-indigo-100 text-indigo-800 border"><?php echo clean($sb['teacher_name'] ?: 'تعیین نشده'); ?></span></td>
                                <td><a href="classes.php?tab=subjects&year=<?php echo urlencode($year); ?>&del_subject=<?php echo $sb['id']; ?>" onclick="return confirm('حذف؟')" class="btn btn-danger text-xs px-2 py-1">حذف</a></td>
                            </tr>
                            <?php endforeach; if (empty($subjectsList)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-6">درس دستی/همگام‌شده‌ای ثبت نشده است.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php elseif ($tab === 'schedule'): ?>
    <div class="card shadow-lg space-y-4">
        <div class="flex justify-between items-center border-b pb-3">
            <h3 class="font-bold text-primary">📅 مدیریت جامع برنامه هفتگی و دروس تخصیص‌یافته به دبیران در سال <?php echo tr_num($year, 'fa'); ?></h3>
            <a href="import-schedule.php" class="btn btn-primary text-xs">⚡ ایمپورت و بروزرسانی با فایل barname.csv</a>
        </div>

        <form method="GET" class="grid grid-cols-6 gap-3 items-end bg-slate-50 dark:bg-slate-800 p-3 rounded border">
            <input type="hidden" name="tab" value="schedule">
            <div>
                <label class="block text-xs font-semibold mb-1">۱. سال تحصیلی</label>
                <select name="year" class="form-select text-xs font-bold" onchange="this.form.submit()">
                    <?php foreach ($yearsList as $yl): ?>
                        <option value="<?php echo clean($yl['academic_year']); ?>" <?php echo $year === $yl['academic_year'] ? 'selected' : ''; ?>><?php echo clean($yl['academic_year']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">۲. کلاس</label>
                <select name="class" class="form-select text-xs font-bold" onchange="this.form.submit()">
                    <option value="">همه کلاس‌ها</option>
                    <?php foreach ($classNameOptions as $cn): ?>
                        <option value="<?php echo clean($cn); ?>" <?php echo $filterClass === $cn ? 'selected' : ''; ?>><?php echo clean($cn); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">۳. دبیر مربوطه</label>
                <select name="teacher_id" class="form-select text-xs font-bold" onchange="this.form.submit()">
                    <option value="">همه دبیران</option>
                    <?php foreach ($teachersList as $tl): ?>
                        <option value="<?php echo $tl['id']; ?>" <?php echo $filterTeacher == $tl['id'] ? 'selected' : ''; ?>><?php echo clean($tl['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">۴. درس تحصیلی</label>
                <select name="subject_name" class="form-select text-xs font-bold" onchange="this.form.submit()">
                    <option value="">همه دروس</option>
                    <?php foreach ($distinctSubjs as $ds): ?>
                        <option value="<?php echo clean($ds['subject_name']); ?>" <?php echo $filterSubject === $ds['subject_name'] ? 'selected' : ''; ?>><?php echo clean($ds['subject_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">۵. جستجوی متنی</label>
                <input type="text" name="q" class="form-input text-xs" placeholder="کلاس، درس یا نام دبیر..." value="<?php echo clean($filterQ); ?>">
            </div>
            <div class="flex gap-1">
                <button type="submit" class="btn btn-primary w-full text-xs font-bold">فیلتر</button>
                <?php if ($filterTeacher || $filterSubject || $filterClass || $filterQ): ?><a href="classes.php?tab=schedule&year=<?php echo urlencode($year); ?>" class="btn btn-secondary text-xs px-2">حذف</a><?php endif; ?>
            </div>
        </form>

        <!-- v4.39.0 - add a new schedule row with dropdowns -->
        <form method="POST" class="grid grid-cols-6 gap-2 items-end bg-emerald-50 dark:bg-slate-800 p-3 rounded border" style="border-color:#6ee7b7">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="add_sched" value="1">
            <div>
                <label class="block text-xs font-semibold mb-1">کلاس *</label>
                <select name="class_name" class="form-select text-xs font-bold" required>
                    <option value="">---</option>
                    <?php foreach ($classNameOptions as $cn): ?><option value="<?php echo clean($cn); ?>"><?php echo clean($cn); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">روز هفته *</label>
                <select name="day_of_week" class="form-select text-xs font-bold" required>
                    <?php foreach ($daysList as $d): ?><option value="<?php echo clean($d); ?>"><?php echo clean($d); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">زنگ</label>
                <select name="period_num" class="form-select text-xs font-bold">
                    <?php foreach ($periodsList as $pn): ?><option value="<?php echo clean($pn); ?>"><?php echo clean($pn); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">درس تحصیلی *</label>
                <select name="subject_name" class="form-select text-xs font-bold" required>
                    <option value="">---</option>
                    <?php foreach ($subjectNameOptions as $sn): ?><option value="<?php echo clean($sn); ?>"><?php echo clean($sn); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">دبیر</label>
                <select name="teacher_id" class="form-select text-xs font-bold">
                    <option value="0">تعیین نشده</option>
                    <?php foreach ($teachersList as $tl): ?><option value="<?php echo $tl['id']; ?>"><?php echo clean($tl['full_name']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-success text-xs font-bold">+ افزودن زنگ جدید</button>
        </form>

        <div class="table-container max-h-[600px]">
            <table>
                <thead><tr><th>کلاس</th><th>روز هفته</th><th>زنگ</th><th>درس تحصیلی</th><th>دبیر تخصیص‌یافته</th><th style="min-width:130px">عملیات</th></tr></thead>
                <tbody>
                    <?php foreach ($schedList as $sc): $rid = (int)$sc['id']; ?>
                    <!-- display row -->
                    <tr id="schedView<?php echo $rid; ?>">
                        <td class="font-bold text-primary"><?php echo clean($sc['class_name']); ?></td>
                        <td><?php echo clean($sc['day_of_week']); ?></td>
                        <td><span class="badge badge-info"><?php echo clean($sc['period_num']); ?></span></td>
                        <td class="font-bold"><?php echo clean($sc['subject_name']); ?></td>
                        <td><span class="badge bg-green-100 text-green-800 border"><?php echo clean($sc['t_name'] ?: ($sc['teacher_name'] ?: 'تعیین نشده')); ?></span></td>
                        <td>
                            <div class="flex gap-1">
                                <button type="button" class="btn btn-primary text-xs px-2 py-1" onclick="toggleSchedEdit(<?php echo $rid; ?>, true)">ویرایش</button>
                                <a href="classes.php?tab=schedule&year=<?php echo urlencode($year); ?>&del_sched=<?php echo $rid; ?>" onclick="return confirm('این زنگ کلاسی حذف شود؟')" class="btn btn-danger text-xs px-2 py-1">حذف</a>
                            </div>
                        </td>
                    </tr>
                    <!-- inline edit row (all dropdowns) -->
                    <tr id="schedEdit<?php echo $rid; ?>" style="display:none;background:rgba(79,70,229,0.05)">
                        <td>
                            <select name="class_name" form="schedForm<?php echo $rid; ?>" class="form-select text-xs font-bold" required>
                                <?php $found=false; foreach ($classNameOptions as $cn): $sel = norm_class_str($sc['class_name'])===norm_class_str($cn); if($sel)$found=true; ?>
                                    <option value="<?php echo clean($cn); ?>" <?php echo $sel?'selected':''; ?>><?php echo clean($cn); ?></option>
                                <?php endforeach; if(!$found && trim($sc['class_name'])!==''): ?>
                                    <option value="<?php echo clean($sc['class_name']); ?>" selected><?php echo clean($sc['class_name']); ?></option>
                                <?php endif; ?>
                            </select>
                        </td>
                        <td>
                            <select name="day_of_week" form="schedForm<?php echo $rid; ?>" class="form-select text-xs font-bold" required>
                                <?php $found=false; foreach ($daysList as $d): $sel = trim($sc['day_of_week'])===$d; if($sel)$found=true; ?>
                                    <option value="<?php echo clean($d); ?>" <?php echo $sel?'selected':''; ?>><?php echo clean($d); ?></option>
                                <?php endforeach; if(!$found && trim($sc['day_of_week'])!==''): ?>
                                    <option value="<?php echo clean($sc['day_of_week']); ?>" selected><?php echo clean($sc['day_of_week']); ?></option>
                                <?php endif; ?>
                            </select>
                        </td>
                        <td>
                            <select name="period_num" form="schedForm<?php echo $rid; ?>" class="form-select text-xs font-bold">
                                <?php $found=false; foreach ($periodsList as $pn): $sel = trim($sc['period_num'])===$pn; if($sel)$found=true; ?>
                                    <option value="<?php echo clean($pn); ?>" <?php echo $sel?'selected':''; ?>><?php echo clean($pn); ?></option>
                                <?php endforeach; if(!$found && trim($sc['period_num'])!==''): ?>
                                    <option value="<?php echo clean($sc['period_num']); ?>" selected><?php echo clean($sc['period_num']); ?></option>
                                <?php endif; ?>
                            </select>
                        </td>
                        <td>
                            <select name="subject_name" form="schedForm<?php echo $rid; ?>" class="form-select text-xs font-bold" required>
                                <?php $found=false; foreach ($subjectNameOptions as $sn): $sel = trim($sc['subject_name'])===$sn; if($sel)$found=true; ?>
                                    <option value="<?php echo clean($sn); ?>" <?php echo $sel?'selected':''; ?>><?php echo clean($sn); ?></option>
                                <?php endforeach; if(!$found && trim($sc['subject_name'])!==''): ?>
                                    <option value="<?php echo clean($sc['subject_name']); ?>" selected><?php echo clean($sc['subject_name']); ?></option>
                                <?php endif; ?>
                            </select>
                        </td>
                        <td>
                            <select name="teacher_id" form="schedForm<?php echo $rid; ?>" class="form-select text-xs font-bold">
                                <option value="0">تعیین نشده</option>
                                <?php foreach ($teachersList as $tl): ?>
                                    <option value="<?php echo $tl['id']; ?>" <?php echo (int)$sc['teacher_id']===(int)$tl['id']?'selected':''; ?>><?php echo clean($tl['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <form method="POST" id="schedForm<?php echo $rid; ?>" class="flex gap-1">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                <input type="hidden" name="update_sched" value="1">
                                <input type="hidden" name="sched_id" value="<?php echo $rid; ?>">
                                <button type="submit" class="btn btn-success text-xs px-2 py-1">ذخیره</button>
                                <button type="button" class="btn btn-secondary text-xs px-2 py-1" onclick="toggleSchedEdit(<?php echo $rid; ?>, false)">انصراف</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; if (empty($schedList)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-6">برنامه‌ای برای سال تحصیلی <?php echo clean($year); ?> ثبت نشده است. از فرم بالا یک زنگ اضافه کنید یا از بخش ایمپورت برنامه هفتگی استفاده فرمایید.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <script>
        function toggleSchedEdit(id, on){
            var v=document.getElementById('schedView'+id), e=document.getElementById('schedEdit'+id);
            if(v&&e){ v.style.display = on?'none':''; e.style.display = on?'':'none'; }
        }
        </script>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
