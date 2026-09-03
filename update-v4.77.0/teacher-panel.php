<?php
/**
 * Teacher Portal & Grading Panel (teacher-panel.php)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/school_roles.php';
require_once __DIR__ . '/includes/grade_permissions.php';
ensure_school_roles_schema();
ensure_grade_permissions_schema();

if (!isset($_SESSION['teacher_id'])) {
    redirect('admin-login.php?tab=teacher');
}

$teacherId = $_SESSION['teacher_id'];
$teacher = DB::fetch("SELECT * FROM teachers WHERE id = ?", [$teacherId]);
$teacherYear = trim($_GET['year'] ?? get_setting('current_academic_year','1404/1405'));
$_SESSION['teacher_year'] = $teacherYear;

if (!$teacher) {
    session_destroy();
    redirect('admin-login.php?tab=teacher');
}

$action = $_GET['action'] ?? '';

// Handle Grade Update by Teacher
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_grades'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'خطای امنیتی CSRF');
        redirect('teacher-panel.php');
    }
    $cname  = norm_class_str($_POST['class_name'] ?? '');
    $subj   = trim($_POST['subject_name'] ?? '');
    $year   = trim($_POST['academic_year'] ?? '1404/1405');
    $month  = trim($_POST['report_month'] ?? 'آبان');

    if (!can_teacher_enter_grade($teacherId, $year, $month, $cname, $subj)) {
        set_flash_message('error', 'ثبت/ویرایش نمره این درس/کلاس در این ماه برای شما غیرفعال شده است.');
        redirect('teacher-panel.php?tab=grading&year=' . urlencode($year));
    }
    $studentIds = $_POST['student_id'] ?? [];
    $scores     = $_POST['score'] ?? [];

    $count = 0;
    foreach ($studentIds as $idx => $stId) {
        $stId = (int)$stId;
        $val  = trim($scores[$idx] ?? '');
        // Ensure report exists/fetch for deletion or update
        $rep = DB::fetch("SELECT id FROM reports WHERE student_id = ? AND academic_year = ? AND report_month = ?", [$stId, $year, $month]);
        if ($val === '') {
            if ($rep) DB::execute("DELETE FROM report_grades WHERE report_id=? AND subject_name=?", [$rep['id'], $subj]);
            continue;
        }
        $score = (float)str_replace('/', '.', $val);

        // Ensure report exists
        
        if (!$rep) {
            DB::execute("INSERT INTO reports (student_id, class_name, academic_year, term, report_month, total_score, gpa) VALUES (?, ?, ?, ?, ?, 0, 0)",
                [$stId, $cname, $year, $month, $month]);
            $repId = DB::lastInsertId();
        } else {
            $repId = $rep['id'];
        }

        // Update or insert report_grades
        $rg = DB::fetch("SELECT id FROM report_grades WHERE report_id = ? AND subject_name = ?", [$repId, $subj]);
        $status = $score == 21.0 ? 'none' : ($score >= 10 ? 'passed' : 'failed');
        if ($rg) {
            DB::execute("UPDATE report_grades SET score = ?, status = ? WHERE id = ?", [$score, $status, $rg['id']]);
        } else {
            DB::execute("INSERT INTO report_grades (report_id, subject_name, score, max_score, coefficient, status) VALUES (?, ?, ?, 20, 2, ?)",
                [$repId, $subj, $score, $status]);
        }
        
        // Recalculate GPA (arithmetic average excluding discipline)
        $allG = DB::fetchAll("SELECT * FROM report_grades WHERE report_id = ?", [$repId]);
        $calc = extract_clean_grades_and_discipline($allG, null);
        if ($calc['count'] > 0) {
            DB::execute("UPDATE reports SET total_score = ?, gpa = ?, discipline_score = COALESCE(?, discipline_score) WHERE id = ?",
                [$calc['calculated_gpa'] * $calc['count'], $calc['calculated_gpa'], $calc['discipline_score'], $repId]);
        }
        
        $count++;
    }

    set_flash_message('success', "نمرات $count دانش‌آموز در درس $subj ثبت و معدل‌ها به‌روز شد.");
    redirect("teacher-panel.php?action=grade&class=" . urlencode($cname) . "&subject=" . urlencode($subj) . "&year=" . urlencode($year) . "&month=" . urlencode($month));
}

// Handle Teacher Reply to Message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_msg'])) {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        $mId = (int)$_POST['msg_id'];
        $rep = trim($_POST['reply_text'] ?? '');
        DB::execute("UPDATE grade_messages SET reply = ?, status = 'replied', replied_at = NOW() WHERE id = ?", [$rep, $mId]);
        set_flash_message('success', 'پاسخ شما به دانش‌آموز ارسال شد.');
    }
    redirect('teacher-panel.php?tab=messages');
}

require_once __DIR__ . '/includes/header.php';
$schedules = DB::fetchAll("SELECT * FROM class_schedules WHERE teacher_id = ? AND academic_year = ? ORDER BY id ASC", [$teacherId, $teacherYear]);
$teacherYears = get_academic_years_for_filter(); // Unified - only from master table, teacher's own years filtered in UI if needed

// Distinct assigned classes & subjects
$assignments = DB::fetchAll("SELECT DISTINCT class_name, subject_name, academic_year FROM class_schedules WHERE teacher_id = ? AND academic_year = ?", [$teacherId, $teacherYear]);
$tab = $_GET['tab'] ?? 'schedule';
?>
<div class="space-y-6">
    <?php
    /* v4.61.0: hero + app-style grid moved to a shared include so it appears
       on every teacher page (online exams, question bank, ...). */
    require_once __DIR__ . '/includes/teacher_nav.php';
    render_teacher_nav();
    ?>

    <?php if ($action === 'grade' && isset($_GET['class']) && isset($_GET['subject'])): 
        $cls = norm_class_str($_GET['class']);
        $sub = trim($_GET['subject']);
        $year = trim($_GET['year'] ?? $teacherYear);
        $month = trim($_GET['month'] ?? 'آبان');
        $monthsList = ['مهر', 'آبان', 'آذر', 'دی', 'نوبت اول', 'بهمن', 'اسفند', 'فروردین', 'اردیبهشت', 'خرداد', 'نوبت دوم', 'شهریور'];

        $students = DB::fetchAll("SELECT id, first_name, last_name, national_id FROM students WHERE class_name = ? AND status='active'", [$cls]);
        persian_usort_by($students, ['last_name','first_name']);   // v4.77.0: آ قبل از ا
    ?>
    <div class="card shadow-lg">
        <div class="flex justify-between items-center border-b pb-3 mb-4">
            <div>
                <h3 class="font-bold text-lg text-primary">📝 لیست نمره‌دهی درس <b><?php echo clean($sub); ?></b> - کلاس <b><?php echo clean($cls); ?></b></h3>
                <span class="text-xs text-muted">انتخاب ماه / نوبت تحصیلی جهت ثبت یا ویرایش نمرات:</span>
            </div>
            <a href="teacher-panel.php?tab=grading&year=<?php echo urlencode($year); ?>" class="btn btn-secondary text-xs">&rarr; بازگشت به لیست کلاس‌ها</a>
        </div>

        <!-- v4.62.0: month picker as an app-style GRID (was a cramped inline row) -->
        <style>
        .tp-months{display:grid;grid-template-columns:repeat(6,1fr);gap:8px;margin-bottom:24px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:10px}
        .dark .tp-months{background:#1e293b;border-color:#334155}
        .tp-month{display:flex;align-items:center;justify-content:center;min-height:44px;border-radius:10px;font-size:.75rem;font-weight:700;text-align:center;color:#475569;background:#fff;border:1px solid #e2e8f0;text-decoration:none !important;transition:all .15s ease}
        .dark .tp-month{background:#0f172a;border-color:#334155;color:#cbd5e1}
        .tp-month:hover{border-color:#059669;color:#059669;transform:translateY(-1px)}
        .tp-month.active{background:linear-gradient(135deg,#059669,#065f46);border-color:#059669;color:#fff !important;box-shadow:0 4px 10px rgba(5,150,105,.35)}
        .tp-month.term{background:#fffbeb;border-color:#fcd34d;color:#92400e}
        .dark .tp-month.term{background:#292112;border-color:#a16207;color:#fbbf24}
        .tp-month.term.active{background:linear-gradient(135deg,#d97706,#92400e);border-color:#d97706;color:#fff !important;box-shadow:0 4px 10px rgba(217,119,6,.35)}
        @media(max-width:768px){.tp-months{grid-template-columns:repeat(4,1fr)}}
        @media(max-width:480px){.tp-months{grid-template-columns:repeat(3,1fr)}}
        </style>
        <div class="tp-months">
            <?php foreach ($monthsList as $mItem): $isTerm = in_array($mItem, ['نوبت اول', 'نوبت دوم'], true); ?>
                <a href="teacher-panel.php?action=grade&class=<?php echo urlencode($cls); ?>&subject=<?php echo urlencode($sub); ?>&year=<?php echo urlencode($year); ?>&month=<?php echo urlencode($mItem); ?>"
                   class="tp-month<?php echo $isTerm ? ' term' : ''; ?><?php echo $month === $mItem ? ' active' : ''; ?>">
                    <?php echo clean($mItem); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="save_grades" value="1">
            <input type="hidden" name="class_name" value="<?php echo clean($cls); ?>">
            <input type="hidden" name="subject_name" value="<?php echo clean($sub); ?>">
            <input type="hidden" name="academic_year" value="<?php echo clean($year); ?>">
            <input type="hidden" name="report_month" value="<?php echo clean($month); ?>">

            <div class="table-container mb-6">
                <table>
                    <thead>
                        <tr>
                            <th>ردیف</th>
                            <th>کد ملی</th>
                            <th>نام و نام خانوادگی دانش‌آموز</th>
                            <th>نمره ثبت‌شده (۰ تا ۲۰)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $idx => $st): 
                            $currGrade = DB::fetch("SELECT rg.score FROM report_grades rg JOIN reports r ON rg.report_id = r.id WHERE r.student_id = ? AND r.report_month = ? AND rg.subject_name = ?", [$st['id'], $month, $sub]);
                            $scVal = $currGrade ? $currGrade['score'] : '';
                        ?>
                        <tr>
                            <td class="font-mono text-center"><?php echo tr_num($idx + 1, 'fa'); ?></td>
                            <td class="font-mono"><?php echo tr_num($st['national_id'], 'fa'); ?></td>
                            <td class="font-bold"><?php echo clean($st['first_name'] . ' ' . $st['last_name']); ?></td>
                            <td class="w-48 text-center">
                                <input type="hidden" name="student_id[]" value="<?php echo $st['id']; ?>">
                                <input type="number" step="0.25" min="0" max="21" name="score[]" class="form-input text-center font-bold text-base w-32 mx-auto" placeholder="بدون نمره" value="<?php echo clean($scVal); ?>">
                            </td>
                        </tr>
                        <?php endforeach; if (empty($students)): ?>
                        <tr><td colspan="4" class="text-center text-muted">دانش‌آموزی در این کلاس یافت نشد.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" class="btn btn-success px-8 py-3 font-bold shadow-lg">💾 ذخیره و ثبت نمرات کلاس &larr;</button>
        </form>
    </div>

    <?php elseif ($tab === 'grading'): ?>
    <div class="card shadow-lg">
        <h3 class="font-bold mb-4 text-primary">📚 کلاس‌ها و دروس تخصیص‌یافته به شما جهت ثبت نمره</h3>
        <div class="grid grid-cols-3 gap-4">
            <?php foreach ($assignments as $as): ?>
            <div class="p-5 rounded-xl border border-color bg-slate-50 dark:bg-slate-800 flex flex-col justify-between shadow-sm hover:shadow-md transition">
                <div>
                    <span class="badge badge-info mb-2"><?php echo clean($as['class_name']); ?></span>
                    <h4 class="font-extrabold text-lg text-gray-800 dark:text-white"><?php echo clean($as['subject_name']); ?></h4>
                    <span class="text-xs text-muted block mt-1">سال تحصیلی: <?php echo tr_num($as['academic_year'], 'fa'); ?></span>
                </div>
                <a href="teacher-panel.php?action=grade&class=<?php echo urlencode($as['class_name']); ?>&subject=<?php echo urlencode($as['subject_name']); ?>&year=<?php echo urlencode($as['academic_year']); ?>" class="btn btn-primary mt-4 w-full text-xs font-bold">📝 ورود به لیست نمرات &larr;</a>
                <a href="class-exam-create.php?new=1&class=<?php echo urlencode($as['class_name']); ?>&subject=<?php echo urlencode($as['subject_name']); ?>&year=<?php echo urlencode($as['academic_year']); ?>" target="_blank" class="btn btn-success mt-2 w-full text-xs font-bold">➕ طراحی آزمون جدید</a>
                <?php $existingClassExams = DB::fetchAll("SELECT id, exam_month, created_at_jalali, is_printed FROM exam_schedules WHERE exam_kind='class' AND teacher_id=? AND academic_year=? AND class_name=? AND subject_name=? ORDER BY id DESC", [$teacherId, $as['academic_year'], $as['class_name'], $as['subject_name']]); ?>
                <?php if ($existingClassExams): ?>
                <form method="GET" action="class-exam-create.php" target="_blank" class="mt-2 space-y-1 no-ajax">
                    <input type="hidden" name="class" value="<?php echo clean($as['class_name']); ?>">
                    <input type="hidden" name="subject" value="<?php echo clean($as['subject_name']); ?>">
                    <input type="hidden" name="year" value="<?php echo clean($as['academic_year']); ?>">
                    <select name="exam_id" class="form-select text-xs">
                        <?php foreach($existingClassExams as $ex): ?>
                            <option value="<?php echo (int)$ex['id']; ?>"><?php echo clean(($ex['exam_month'] ?: 'آزمون کلاسی') . ' - #' . $ex['id'] . (!empty($ex['is_printed']) ? ' - چاپ شده' : '')); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-warning w-full text-xs font-bold">🧪 ویرایش آزمون کلاسی</button>
                </form>
                <?php endif; ?>
            </div>
            <?php endforeach; if (empty($assignments)): ?>
            <p class="col-span-3 text-center text-muted py-8">درسی به شما در برنامه هفتگی تخصیص نیافته است.</p>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif ($tab === 'messages'): 
        $msgs = DB::fetchAll("SELECT gm.*, s.first_name, s.last_name, s.national_id FROM grade_messages gm JOIN students s ON gm.student_id = s.id WHERE gm.teacher_id = ? ORDER BY gm.id DESC", [$teacherId]);
    ?>
    <div class="card shadow-lg">
        <h3 class="font-bold mb-4">💬 پیام‌ها و اعتراضات دانش‌آموزان به نمرات دروس شما</h3>
        <div class="space-y-4">
            <?php foreach ($msgs as $m): ?>
            <div class="p-4 rounded-xl border bg-slate-50 dark:bg-slate-800 space-y-3">
                <div class="flex justify-between items-center border-b pb-2">
                    <div>
                        <span class="font-bold text-gray-800 dark:text-white"><?php echo clean($m['first_name'] . ' ' . $m['last_name']); ?></span>
                        <span class="badge badge-info text-xs ml-2"><?php echo clean($m['subject_name']); ?></span>
                    </div>
                    <span class="text-xs text-muted font-mono"><?php echo jdate('Y/m/d H:i', strtotime($m['created_at'])); ?></span>
                </div>
                <p class="text-xs text-gray-700 dark:text-gray-300 leading-relaxed"><?php echo nl2br(clean($m['message'])); ?></p>
                <?php if ($m['reply']): ?>
                <div class="p-3 bg-green-50 dark:bg-slate-700 rounded border border-green-200 text-xs">
                    <span class="font-bold text-green-800 dark:text-green-300 block mb-1">پاسخ شما:</span>
                    <p class="text-green-900 dark:text-green-200"><?php echo nl2br(clean($m['reply'])); ?></p>
                </div>
                <?php else: ?>
                <form method="POST" class="flex gap-2">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="reply_msg" value="1">
                    <input type="hidden" name="msg_id" value="<?php echo $m['id']; ?>">
                    <input type="text" name="reply_text" class="form-input text-xs flex-1" placeholder="پاسخ یا توضیحات دبیر..." required>
                    <button type="submit" class="btn btn-success text-xs px-4">ارسال پاسخ</button>
                </form>
                <?php endif; ?>
            </div>
            <?php endforeach; if (empty($msgs)): ?>
            <p class="text-center text-muted py-6">هیچ پیامی ثبت نشده است.</p>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif ($tab === 'exams'):
        require_once __DIR__ . '/includes/exams_helper.php';
        ensure_exams_schema();
        /* v4.70.0: مرتب‌سازی طوری که آزمون‌های «پایه یکسان + درس یکسان» پشت‌سرهم
         * قرار بگیرند تا خانه مشترک «طراحی پایه‌ای» با rowspan روی همه ردیف‌های
         * گروه بنشیند. */
        $teacherExams = DB::fetchAll("SELECT * FROM exam_schedules WHERE teacher_id = ? AND is_active = 1 ORDER BY academic_year DESC, exam_month ASC, grade_level ASC, subject_name ASC, class_name ASC, exam_date_jalali ASC, start_time ASC", [$teacherId]);
        // شمارش اعضای هر گروه: سال|ماه|پایه|درس
        $tpGroupCounts = [];
        foreach ($teacherExams as $tex) {
            $gk = $tex['academic_year'] . '|' . $tex['exam_month'] . '|' . trim($tex['grade_level'] ?? '') . '|' . trim($tex['subject_name'] ?? '');
            $tpGroupCounts[$gk] = ($tpGroupCounts[$gk] ?? 0) + 1;
        }
        $tpSeenGroups = [];
    ?>
    <div class="card shadow-lg">
        <h3 class="font-bold mb-4">📝 آزمون‌های فعال من و طراحی سوالات</h3>
        <div class="table-container"><table><thead><tr><th>سال/ماه</th><th>کلاس/پایه</th><th>درس</th><th>تاریخ</th><th>ساعت</th><th>طراحی</th><th>طراحی پایه‌ای (مشترک کلاس‌های پایه)</th></tr></thead><tbody>
        <?php foreach($teacherExams as $ex):
            $gk = $ex['academic_year'] . '|' . $ex['exam_month'] . '|' . trim($ex['grade_level'] ?? '') . '|' . trim($ex['subject_name'] ?? '');
            $gCount = $tpGroupCounts[$gk] ?? 1;
            $gFirst = empty($tpSeenGroups[$gk]); $tpSeenGroups[$gk] = true;
            $exGrade = trim($ex['grade_level'] ?? '') ?: infer_grade_from_class_name($ex['class_name'] ?? '');
        ?><tr><td><?php echo clean($ex['academic_year'].' / '.$ex['exam_month']); ?></td><td><?php echo clean($ex['grade_level'].' '.$ex['class_name']); ?></td><td class="font-bold"><?php echo clean($ex['subject_name']); ?></td><td><?php echo tr_num($ex['exam_date_jalali'],'fa'); ?></td><td><?php echo tr_num($ex['start_time'],'fa'); ?></td><td><a class="btn btn-primary text-xs" target="_blank" href="exam-print.php?type=questions&exam_id=<?php echo $ex['id']; ?>&dt=<?php echo urlencode(make_exam_design_token($ex['id'], 'teacher', $teacherId)); ?>">ورود به ویرایش مرحله اول</a></td>
        <?php if ($gFirst): /* v4.70.0: یک خانه مشترک با rowspan برای همه کلاس‌های پایه/درس یکسان */ ?>
            <td rowspan="<?php echo (int)$gCount; ?>" class="text-center align-middle" style="background:rgba(16,185,129,.06);vertical-align:middle">
                <?php if ($gCount > 1 && $exGrade !== ''): ?>
                    <a class="btn btn-success text-xs" target="_blank" title="یک طراحی مشترک برای هر <?php echo tr_num($gCount,'fa'); ?> کلاس پایه <?php echo clean($exGrade); ?> — چاپ برای تمام دانش‌آموزان پایه" href="exam-print.php?type=questions&exam_id=<?php echo $ex['id']; ?>&grade_all=1&dt=<?php echo urlencode(make_exam_design_token($ex['id'], 'teacher', $teacherId)); ?>">طراحی پایه‌ای <?php echo clean($exGrade); ?><br><span class="text-[10px] font-normal">(<?php echo tr_num($gCount,'fa'); ?> کلاس)</span></a>
                <?php elseif ($exGrade !== ''): ?>
                    <a class="btn btn-outline text-xs" target="_blank" title="این درس در این پایه فقط یک کلاس دارد — طراحی برای کل پایه" href="exam-print.php?type=questions&exam_id=<?php echo $ex['id']; ?>&grade_all=1&dt=<?php echo urlencode(make_exam_design_token($ex['id'], 'teacher', $teacherId)); ?>">طراحی پایه‌ای</a>
                <?php else: ?><span class="text-xs text-muted">—</span><?php endif; ?>
            </td>
        <?php endif; ?>
        </tr><?php endforeach; if(!$teacherExams): ?><tr><td colspan="7" class="text-center text-muted">آزمون فعالی برای شما ثبت نشده است.</td></tr><?php endif; ?>
        </tbody></table></div>
        <p class="text-xs text-muted mt-2">ستون «طراحی پایه‌ای»: برای درس‌های یکسان با پایه همسان، یک خانه مشترک بین همه ردیف‌های آن گروه است — طراحی یک‌بار انجام می‌شود و برای تمام دانش‌آموزان همه کلاس‌های آن پایه تکثیر و چاپ می‌گردد.</p>
        <p class="text-xs text-muted mt-3">دبیر می‌تواند از بانک سوالات استفاده کند و سوال درج‌شده روی برگه آزمون خود را ویرایش کند؛ ویرایش مستقیم بانک اطلاعاتی سوالات فقط برای مدیر سیستم فعال است.</p>
    </div>

    <?php else: ?>
    <div class="card shadow-lg">
        <h3 class="font-bold mb-4">📅 جدول برنامه هفتگی تدریس شما</h3>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>روز هفته</th>
                        <th>زنگ اول</th>
                        <th>زنگ دوم</th>
                        <th>زنگ سوم</th>
                        <th>زنگ چهارم</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $days = ['شنبه', 'یک‌شنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه'];
                    foreach ($days as $d): 
                    ?>
                    <tr>
                        <td class="font-bold bg-slate-100 dark:bg-slate-800"><?php echo clean($d); ?></td>
                        <?php for ($p=1; $p<=4; $p++): 
                            $sc = DB::fetch("SELECT class_name, subject_name FROM class_schedules WHERE teacher_id = ? AND day_of_week = ? AND period_num = ?", [$teacherId, $d, 'زنگ ' . $p]);
                        ?>
                        <td class="text-center">
                            <?php if ($sc): ?>
                                <span class="badge badge-info block mb-1"><?php echo clean($sc['class_name']); ?></span>
                                <span class="font-bold text-xs block"><?php echo clean($sc['subject_name']); ?></span>
                            <?php else: ?>
                                <span class="text-muted text-xs">---</span>
                            <?php endif; ?>
                        </td>
                        <?php endfor; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
