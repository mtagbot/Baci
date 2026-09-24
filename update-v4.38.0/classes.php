<?php
/**
 * Classes, Subjects & Weekly Schedule Management (classes.php)
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
        $name  = norm_class_str($_POST['name'] ?? '');
        $grade = trim($_POST['grade'] ?? '');
        $y = resolve_academic_year_request(trim($_POST['academic_year'] ?? ''));

        if ($name) {
            DB::execute("INSERT INTO classes (name, grade, academic_year) VALUES (?, ?, ?)", [$name, $grade, $y]);
            set_flash_message('success', 'کلاس جدید با موفقیت ایجاد شد.');
        }
    }
    redirect("classes.php?tab=classes&year=" . urlencode($y));
}

// Handle Add Subject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_subject'])) {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        $name  = trim($_POST['name'] ?? '');
        $code  = trim($_POST['code'] ?? '');
        $grade = trim($_POST['grade_level'] ?? '');
        $tId   = (int)($_POST['teacher_id'] ?? 0);
        $tName = '';
        if ($tId > 0) {
            $tObj = DB::fetch("SELECT full_name FROM teachers WHERE id = ?", [$tId]);
            $tName = $tObj ? $tObj['full_name'] : '';
        }

        if ($name) {
            DB::execute("INSERT INTO subjects (name, code, grade_level, teacher_name, teacher_id, coefficient) VALUES (?, ?, ?, ?, ?, 1)", [$name, $code, $grade, $tName, $tId ?: null]);
            set_flash_message('success', 'درس جدید به همراه دبیر مربوطه ثبت گردید.');
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
$filterQ       = trim($_GET['q'] ?? '');

$schedWhere = ["cs.academic_year = ?"];
$schedParams = [$year];
if ($filterTeacher > 0) { $schedWhere[] = "cs.teacher_id = ?"; $schedParams[] = $filterTeacher; }
if (!empty($filterSubject)) { $schedWhere[] = "cs.subject_name = ?"; $schedParams[] = $filterSubject; }
if (!empty($filterQ)) { $schedWhere[] = "(cs.class_name LIKE ? OR cs.subject_name LIKE ? OR cs.teacher_name LIKE ? OR t.full_name LIKE ?)"; $schedParams[] = "%$filterQ%"; $schedParams[] = "%$filterQ%"; $schedParams[] = "%$filterQ%"; $schedParams[] = "%$filterQ%"; }
$schedWhereSql = implode(' AND ', $schedWhere);

$schedList    = DB::fetchAll("SELECT cs.*, t.full_name as t_name FROM class_schedules cs LEFT JOIN teachers t ON cs.teacher_id = t.id WHERE $schedWhereSql ORDER BY cs.class_name ASC, cs.id ASC", $schedParams);
$distinctSubjs= DB::fetchAll("SELECT DISTINCT subject_name FROM class_schedules WHERE academic_year = ?", [$year]);
$teachersList = DB::fetchAll("SELECT id, full_name FROM teachers WHERE status = 1 ORDER BY full_name ASC");
$yearsList = get_academic_years_for_filter();
if (empty($yearsList)) { $yearsList = [['academic_year' => $year]]; }
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
                    <label class="block text-xs font-semibold mb-1">نام کلاس (مثال: هفتم1) *</label>
                    <input type="text" name="name" class="form-input" required>
                </div>
                <div class="mb-4">
                    <label class="block text-xs font-semibold mb-1">پایه تحصیلی</label>
                    <input type="text" name="grade" class="form-input">
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
                <div class="mb-4"><label class="block text-xs font-semibold mb-1">نام درس *</label><input type="text" name="name" class="form-input" required></div>
                <div class="mb-4"><label class="block text-xs font-semibold mb-1">کد درس</label><input type="text" name="code" class="form-input dir-ltr text-left"></div>
                <div class="mb-4"><label class="block text-xs font-semibold mb-1">پایه / رشته</label><input type="text" name="grade_level" class="form-input"></div>
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

        <form method="GET" class="grid grid-cols-5 gap-3 items-end bg-slate-50 dark:bg-slate-800 p-3 rounded border">
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
                <label class="block text-xs font-semibold mb-1">۲. دبیر مربوطه</label>
                <select name="teacher_id" class="form-select text-xs font-bold" onchange="this.form.submit()">
                    <option value="">همه دبیران</option>
                    <?php foreach ($teachersList as $tl): ?>
                        <option value="<?php echo $tl['id']; ?>" <?php echo $filterTeacher == $tl['id'] ? 'selected' : ''; ?>><?php echo clean($tl['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">۳. درس تحصیلی</label>
                <select name="subject_name" class="form-select text-xs font-bold" onchange="this.form.submit()">
                    <option value="">همه دروس</option>
                    <?php foreach ($distinctSubjs as $ds): ?>
                        <option value="<?php echo clean($ds['subject_name']); ?>" <?php echo $filterSubject === $ds['subject_name'] ? 'selected' : ''; ?>><?php echo clean($ds['subject_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">۴. جستجوی متنی</label>
                <input type="text" name="q" class="form-input text-xs" placeholder="کلاس، درس یا نام دبیر..." value="<?php echo clean($filterQ); ?>">
            </div>
            <div class="flex gap-1">
                <button type="submit" class="btn btn-primary w-full text-xs font-bold">فیلتر</button>
                <?php if ($filterTeacher || $filterSubject || $filterQ): ?><a href="classes.php?tab=schedule&year=<?php echo urlencode($year); ?>" class="btn btn-secondary text-xs px-2">حذف</a><?php endif; ?>
            </div>
        </form>

        <div class="table-container max-h-[600px]">
            <table>
                <thead><tr><th>کلاس</th><th>روز هفته</th><th>زنگ</th><th>نام درس</th><th>نام دبیر تخصیص‌یافته</th><th>حذف</th></tr></thead>
                <tbody>
                    <?php foreach ($schedList as $sc): ?>
                    <tr>
                        <td class="font-bold text-primary"><?php echo clean($sc['class_name']); ?></td>
                        <td><?php echo clean($sc['day_of_week']); ?></td>
                        <td><span class="badge badge-info"><?php echo clean($sc['period_num']); ?></span></td>
                        <td class="font-bold"><?php echo clean($sc['subject_name']); ?></td>
                        <td><span class="badge bg-green-100 text-green-800 border"><?php echo clean($sc['t_name'] ?: $sc['teacher_name']); ?></span></td>
                        <td><a href="classes.php?tab=schedule&year=<?php echo urlencode($year); ?>&del_sched=<?php echo $sc['id']; ?>" onclick="return confirm('حذف؟')" class="text-red-500 font-bold">&times;</a></td>
                    </tr>
                    <?php endforeach; if (empty($schedList)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-6">برنامه‌ای برای سال تحصیلی <?php echo clean($year); ?> ثبت نشده است. از بخش ایمپورت برنامه هفتگی استفاده فرمایید.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
