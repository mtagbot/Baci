<?php
/**
 * Detailed Interactive Report Card View (report-view.php)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bot_helpers.php';
require_once __DIR__ . '/includes/school_roles.php';
ensure_school_roles_schema();
require_once __DIR__ . '/includes/header.php';

$reportId = (int)($_GET['id'] ?? 0);
$report = DB::fetch("SELECT r.*, s.first_name, s.last_name, s.national_id, s.father_name, s.grade_level, s.photo_url FROM reports r JOIN students s ON r.student_id = s.id WHERE r.id = ?", [$reportId]);

if (!$report) {
    set_flash_message('error', 'کارنامه مورد نظر یافت نشد.');
    redirect(is_admin_logged_in() ? 'reports.php' : 'student-panel.php');
}

// Check permission / locking
$staffFileAccess = current_teacher_has_student_file_access();
if (is_student_logged_in()) {
    if ($report['student_id'] != $_SESSION['student_id'] || $report['is_locked']) {
        set_flash_message('error', 'شما مجاز به مشاهده این کارنامه نیستید یا کارنامه مسدود است.');
        redirect('student-panel.php');
    }
} elseif (is_admin_logged_in() || $staffFileAccess) {
    // Admin, deputy and counselor may view the educational dossier without student credentials.
    // Locked reports are visible to authorized staff for supervision/counseling, but not to students/parents.
} else {
    redirect('index.php?view=login');
}

// Handle Student Message Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_grade_msg'])) {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        $subj = trim($_POST['subject_name'] ?? '');
        $msg  = trim($_POST['msg_text'] ?? '');
        if ($subj && $msg && is_student_logged_in()) {
            // Find teacher for this subject and class
            $tSc = DB::fetch("SELECT teacher_id FROM class_schedules WHERE class_name = ? AND subject_name = ? AND academic_year = ? LIMIT 1", [$report['class_name'], $subj, $report['academic_year']]);
            $tId = $tSc ? $tSc['teacher_id'] : null;
            DB::execute("INSERT INTO grade_messages (student_id, teacher_id, report_id, subject_name, message, created_at) VALUES (?, ?, ?, ?, ?, NOW())",
                [$_SESSION['student_id'], $tId, $reportId, $subj, $msg]);
            $gmNewId = (int)DB::lastInsertId();
            if ($tId) {
                /* v4.87.0: rich objection alert to the subject teacher on BOTH
                   bots — includes the student's own text and the inline
                   «پاسخ به اعتراض» button (reply + optional grade change). */
                try {
                    require_once __DIR__ . '/includes/bot_role_engine.php';
                    bot_notify_grade_objection($gmNewId);
                } catch (Exception $e) { error_log('objection notify failed: ' . $e->getMessage()); }
            }
            set_flash_message('success', 'پیام و درخواست شما برای دبیر این درس ارسال شد.');
        }
    }
    redirect("report-view.php?id=" . $reportId);
}

$rawGrades = DB::fetchAll("SELECT * FROM report_grades WHERE report_id = ?", [$reportId]);
$parsedData = extract_clean_grades_and_discipline($rawGrades, $report['discipline_score']);
$grades = $parsedData['regular_grades'];
$computedGpa = $parsedData['count'] > 0 ? $parsedData['calculated_gpa'] : $report['gpa'];
$discScore = $parsedData['discipline_score'];

if ($parsedData['count'] > 0 && abs((float)$report['gpa'] - (float)$computedGpa) > 0.001) {
    DB::execute("UPDATE reports SET gpa = ?, total_score = ? WHERE id = ?", [$computedGpa, $computedGpa * $parsedData['count'], $reportId]);
    $report['gpa'] = $computedGpa;
}

// Compute dynamic overall ranks
$classReports = DB::fetchAll("SELECT id, student_id, gpa FROM reports WHERE academic_year = ? AND term = ? AND report_month = ? AND class_name = ? ORDER BY gpa DESC",
    [$report['academic_year'], $report['term'], $report['report_month'], $report['class_name']]);
$classRank = 1;
$totalClass = count($classReports);
foreach ($classReports as $idx => $cr) { if ($cr['id'] == $reportId) { $classRank = $idx + 1; break; } }

$gradeReports = DB::fetchAll("SELECT r.id, r.student_id, r.gpa FROM reports r JOIN students s ON r.student_id = s.id WHERE r.academic_year = ? AND r.term = ? AND r.report_month = ? ORDER BY r.gpa DESC",
    [$report['academic_year'], $report['term'], $report['report_month']]);
$gradeRank = 1;
$totalGrade = count($gradeReports);
foreach ($gradeReports as $idx => $gr) { if ($gr['id'] == $reportId) { $gradeRank = $idx + 1; break; } }

// Monthly progress across the year for this student
$monthlyReports = DB::fetchAll("SELECT report_month, term, gpa FROM reports WHERE student_id = ? AND academic_year = ? ORDER BY id ASC",
    [$report['student_id'], $report['academic_year']]);

$rLine1  = get_setting('report_header_line1', 'بسمه تعالی');
$rLine2  = get_setting('report_header_line2', 'دبیرستان غیردولتی بصیرت');
$sProv   = get_setting('school_province', 'تهران');
$sReg    = get_setting('school_region', 'منطقه ۳ آموزش و پرورش');
$sUnit   = get_setting('school_unit_type', 'متوسطه اول پسرانه');
$pSig    = get_setting('principal_signature_url', '');
$sStamp  = get_setting('school_stamp_url', '');

$subjectLabels = [];
$subjectScores = [];
?>
<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex justify-between items-center no-print">
        <a href="<?php echo is_admin_logged_in() ? 'reports.php' : 'student-panel.php'; ?>" class="btn btn-secondary">&rarr; بازگشت به لیست کارنامه‌ها</a>
        <div class="flex gap-2">
            <a href="report-print.php?id=<?php echo $reportId; ?><?php echo is_student_logged_in() ? '&pt=' . urlencode(make_report_print_token($reportId, $report['student_id'])) : ''; ?>" target="_self" class="btn btn-primary gap-1 shadow"><svg data-ui-icon="print" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M7 8V3h10v5M7 17H3V9h18v8h-4M7 14h10v8H7ZM17 11h.1"/></svg> چاپ کارنامه</a>
            <?php if (is_admin_logged_in() || (function_exists('current_teacher_has_student_file_access') && current_teacher_has_student_file_access())): ?>
            <a href="reports.php?action=edit&id=<?php echo $reportId; ?>" class="btn btn-warning gap-1 shadow"><svg data-ui-icon="edit" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m14 5 5 5M3 21l5-1L21 7c2-2-2-6-4-4L4 16Z"/></svg> ویرایش کارنامه</a>
            <?php endif; ?>
            <?php if (is_admin_logged_in()): ?>
            <a href="export-excel.php?id=<?php echo $reportId; ?>" class="btn btn-success gap-1 shadow"><svg data-ui-icon="chart" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 3v18h18M7 17v-4m5 4V8m5 9V4"/></svg> دانلود Excel</a>
            <a href="export-pdf.php?id=<?php echo $reportId; ?>" class="btn btn-danger gap-1 shadow"><svg data-ui-icon="report" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14 2H5v20h14V7Zm0 0v5h5M8 17v-3m4 3v-6m4 6v-4"/></svg> دانلود PDF واقعی</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Main Card -->
    <div class="card p-8 border-2 border-primary shadow-2xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white rounded-2xl">
        <!-- Header Banner with Swapped Layout -->
        <div class="flex justify-between items-center border-b-2 border-gray-200 dark:border-slate-700 pb-6 mb-6">
            <!-- Right side: Province, Region, Date -->
            <div class="w-44 text-right text-xs leading-relaxed space-y-1">
                <p>استان: <b><?php echo clean($sProv); ?></b></p>
                <p>منطقه: <b><?php echo clean($sReg); ?></b></p>
                <p>نوع واحد: <b><?php echo clean($sUnit); ?></b></p>
                <p>تاریخ صدور: <span class="font-mono font-bold"><?php echo jdate('Y/m/d'); ?></span></p>
            </div>

            <!-- Center: Headers & Title -->
            <div class="text-center flex-1 px-4">
                <h1 class="text-base font-bold text-gray-600 dark:text-gray-300 mb-1"><?php echo clean($rLine1); ?></h1>
                <h2 class="text-xl font-extrabold text-primary mb-1"><?php echo clean($rLine2); ?></h2>
                <h3 class="text-md font-bold text-gray-800 dark:text-white">کارنامه تحصیلی دانش‌آموز - <?php echo clean($report['term'] . ' (' . $report['report_month'] . ')'); ?> سال <?php echo tr_num($report['academic_year'], 'fa'); ?></h3>
            </div>

            <!-- Left side: Student Photo Box -->
            <div class="flex flex-col items-center gap-1.5 w-24">
                <div class="rounded-lg border-2 border-primary overflow-hidden bg-gray-100 flex items-center justify-center shadow" style="width:5rem;height:6.67rem">
                    <?php if (!empty($report['photo_url'])): ?>
                        <img src="<?php echo clean($report['photo_url']); ?>" alt="عکس دانش‌آموز" class="w-full h-full object-cover">
                    <?php else: ?>
                        <span class="text-2xl"><svg data-ui-icon="user" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="7" r="4"/><path d="M4 21v-2c0-8 16-8 16 0v2"/></svg></span>
                    <?php endif; ?>
                </div>
                <span class="text-[10px] text-muted font-mono"><?php echo tr_num($report['national_id'], 'fa'); ?></span>
            </div>
        </div>

        <!-- Student Info Grid -->
        <div class="grid grid-cols-4 gap-4 p-4 bg-slate-50 dark:bg-slate-800 rounded-xl border border-color mb-6 text-sm">
            <div><span class="text-xs text-muted block">نام و نام خانوادگی:</span><b class="font-bold"><?php echo clean($report['first_name'] . ' ' . $report['last_name']); ?></b></div>
            <div><span class="text-xs text-muted block">کد ملی (نام کاربری):</span><b class="font-mono"><?php echo tr_num($report['national_id'], 'fa'); ?></b></div>
            <div><span class="text-xs text-muted block">نام پدر:</span><b><?php echo clean($report['father_name'] ?: '---'); ?></b></div>
            <div><span class="text-xs text-muted block">کلاس و پایه:</span><b class="text-primary"><?php echo clean($report['class_name'] . ' (' . $report['grade_level'] . ')'); ?></b></div>
        </div>

        <!-- Analytical Ranks Banner -->
        <div class="grid grid-cols-3 gap-4 mb-6">
            <div class="p-4 rounded-xl bg-gradient-to-r from-blue-500 to-indigo-600 text-white text-center shadow-md">
                <span class="text-xs opacity-90 block">معدل کل نوبت</span>
                <span class="text-3xl font-extrabold font-mono tracking-tight mt-1 block"><?php echo format_score($computedGpa); ?></span>
            </div>
            <div class="p-4 rounded-xl bg-gradient-to-r from-amber-500 to-orange-600 text-white text-center shadow-md">
                <span class="text-xs opacity-90 block">رتبه معدل در کلاس (<?php echo tr_num($totalClass, 'fa'); ?> نفر)</span>
                <span class="text-3xl font-extrabold font-mono tracking-tight mt-1 block">#<?php echo tr_num($classRank, 'fa'); ?></span>
            </div>
            <div class="p-4 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-600 text-white text-center shadow-md">
                <span class="text-xs opacity-90 block">رتبه معدل در پایه (<?php echo tr_num($totalGrade, 'fa'); ?> نفر)</span>
                <span class="text-3xl font-extrabold font-mono tracking-tight mt-1 block">#<?php echo tr_num($gradeRank, 'fa'); ?></span>
            </div>
        </div>

        <!-- Grades Table -->
        <div class="table-container mb-6">
            <table class="w-full border text-right rounded-lg overflow-hidden">
                <thead>
                    <tr class="bg-slate-100 dark:bg-slate-800">
                        <th class="border p-3 text-center">ردیف</th>
                        <th class="border p-3">نام درس</th>
                        <th class="border p-3 text-center">نمره اخذشده</th>
                        <th class="border p-3 text-center">رتبه در کلاس</th>
                        <th class="border p-3 text-center">رتبه در پایه</th>
                        <th class="border p-3 text-center">وضعیت ارزشیابی</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grades as $idx => $g): 
                        $subjectLabels[] = clean($g['subject_name']);
                        $subjectScores[] = (float)$g['score'];

                        // Subject Class Rank
                        $sClassReps = DB::fetchAll("SELECT rg.score FROM report_grades rg JOIN reports r ON rg.report_id = r.id WHERE r.academic_year = ? AND r.term = ? AND r.report_month = ? AND r.class_name = ? AND rg.subject_name = ? ORDER BY rg.score DESC",
                            [$report['academic_year'], $report['term'], $report['report_month'], $report['class_name'], $g['subject_name']]);
                        $sClsRank = 1;
                        foreach ($sClassReps as $si => $scr) { if ($crScore = (float)$scr['score'] <= (float)$g['score']) { $sClsRank = $si + 1; break; } }

                        // Subject Grade Rank
                        $sGradeReps = DB::fetchAll("SELECT rg.score FROM report_grades rg JOIN reports r ON rg.report_id = r.id WHERE r.academic_year = ? AND r.term = ? AND r.report_month = ? AND rg.subject_name = ? ORDER BY rg.score DESC",
                            [$report['academic_year'], $report['term'], $report['report_month'], $g['subject_name']]);
                        $sGrdRank = 1;
                        foreach ($sGradeReps as $si => $sgr) { if ((float)$sgr['score'] <= (float)$g['score']) { $sGrdRank = $si + 1; break; } }
                    ?>
                    <tr class="border-b hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        <td class="border p-3 font-mono text-xs text-center"><?php echo tr_num($idx + 1, 'fa'); ?></td>
                        <td class="border p-3 font-bold"><?php echo clean($g['subject_name']); ?></td>
                        <td class="border p-3 font-mono text-center font-extrabold text-base <?php echo ((float)$g['score'] == 21.0) ? 'text-amber-600' : ($g['score'] < 10 ? 'text-red-600' : 'text-blue-600 dark:text-blue-400'); ?>">
                            <?php echo display_score($g['score']); ?>
                        </td>
                        <td class="border p-3 font-mono text-xs text-center"><span class="badge bg-amber-100 text-amber-800 border">#<?php echo tr_num($sClsRank, 'fa'); ?></span></td>
                        <td class="border p-3 font-mono text-xs text-center"><span class="badge bg-green-100 text-green-800 border">#<?php echo tr_num($sGrdRank, 'fa'); ?></span></td>
                        <td class="border p-3 text-center">
                            <?php echo ((float)$g['score'] == 21.0) ? '<span class="badge badge-warning">غیبت</span>' : ($g['score'] >= 10 ? '<span class="badge bg-green-100 text-green-800 border">قبول ✔</span>' : '<span class="badge bg-red-100 text-red-800 border">مردود ✖</span>'); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="bg-slate-100 dark:bg-slate-800 font-bold text-base">
                        <td colspan="2" class="border p-3 text-left">معدل نهایی کارنامه:</td>
                        <td class="border p-3 text-center text-xl text-primary font-mono font-extrabold"><?php echo format_score($computedGpa); ?></td>
                        <td class="border p-3 text-center text-xs text-amber-700">رتبه کلاس: #<?php echo tr_num($classRank, 'fa'); ?></td>
                        <td colspan="2" class="border p-3 text-center text-xs text-green-700">رتبه پایه: #<?php echo tr_num($gradeRank, 'fa'); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <?php if (!empty($report['teacher_comments'])): ?>
        <div class="p-4 rounded-xl bg-amber-50 dark:bg-slate-800 border border-amber-200 text-sm mb-6">
            <span class="font-bold text-amber-900 dark:text-amber-400 block mb-1"><svg data-ui-icon="message" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 3h18v14H9l-6 5ZM7 8h10M7 12h7"/></svg> نظر مربی و مدیر راهنما:</span>
            <p class="text-amber-800 dark:text-amber-200 text-xs leading-relaxed"><?php echo nl2br(clean($report['teacher_comments'])); ?></p>
        </div>
        <?php endif; ?>

        <!-- Student Grade Messages & Appeals Section -->
        <?php if (is_student_logged_in()): 
            $myMsgs = DB::fetchAll("SELECT * FROM grade_messages WHERE report_id = ? AND student_id = ? ORDER BY id DESC", [$reportId, $_SESSION['student_id']]);
        ?>
        <div class="card p-5 border bg-slate-50 dark:bg-slate-800 rounded-xl mb-6 no-print">
            <h4 class="font-bold text-sm text-primary mb-4 flex items-center gap-1">
                <span><svg data-ui-icon="message" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 3h18v14H9l-6 5ZM7 8h10M7 12h7"/></svg> پرسش، اعتراض و ارتباط مستقیم با دبیران دروس این کارنامه:</span>
            </h4>
            <form method="POST" class="grid grid-cols-4 gap-3 mb-6 bg-white dark:bg-slate-900 p-4 rounded-lg border">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="send_grade_msg" value="1">
                <div>
                    <label class="block text-xs font-semibold mb-1">انتخاب درس مورد نظر</label>
                    <select name="subject_name" class="form-select text-xs" required>
                        <?php foreach ($grades as $g): ?>
                            <option value="<?php echo clean($g['subject_name']); ?>"><?php echo clean($g['subject_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-span-2">
                    <label class="block text-xs font-semibold mb-1">متن پیام یا دلیل اعتراض به نمره</label>
                    <input type="text" name="msg_text" class="form-input text-xs" placeholder="استاد گرامی، در نمره آزمون کتبی..." required>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="btn btn-primary w-full text-xs font-bold py-2.5">ارسال به دبیر درس &larr;</button>
                </div>
            </form>

            <?php if (!empty($myMsgs)): ?>
            <div class="space-y-3">
                <h5 class="font-bold text-xs text-gray-600 dark:text-gray-300">تاریخچه مکالمات شما با دبیران:</h5>
                <?php foreach ($myMsgs as $mm): ?>
                <div class="p-3 bg-white dark:bg-slate-900 rounded border text-xs space-y-2">
                    <div class="flex justify-between font-bold text-primary border-b pb-1">
                        <span>درس: <?php echo clean($mm['subject_name']); ?></span>
                        <span class="font-mono text-muted"><?php echo jdate('Y/m/d H:i', strtotime($mm['created_at'])); ?></span>
                    </div>
                    <p class="text-gray-700 dark:text-gray-200"><?php echo clean($mm['message']); ?></p>
                    <?php if ($mm['reply']): ?>
                    <div class="p-2 bg-green-50 dark:bg-slate-800 rounded border border-green-200 text-green-900 dark:text-green-300">
                        <b>پاسخ دبیر:</b> <?php echo clean($mm['reply']); ?>
                    </div>
                    <?php else: ?>
                    <span class="text-[10px] text-amber-600 block">در انتظار بررسی و پاسخ دبیر...</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Cutting Slip & Signatures -->
        <div class="mt-8 pt-4">
            <div class="flex items-center gap-2 text-gray-400 my-6">
                <span class="text-lg"><svg data-ui-icon="crop" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 2v16h16M2 6h16v16M3 21 21 3"/></svg></span>
                <div class="flex-1 border-t-2 border-dashed border-gray-400"></div>
                <span class="text-xs font-mono">محل برش و اعاده به مدرسه</span>
            </div>
            
            <p class="text-xs text-gray-700 dark:text-gray-300 font-semibold leading-relaxed mb-8">
                اینجانب ............................................................................ ولی دانش‌آموز <b class="text-primary"><?php echo clean($report['first_name'] . ' ' . $report['last_name']); ?></b> گزارش عملکرد تحصیلی دانش‌آموز خود را در نوبت <b class="text-primary"><?php echo clean($report['term'] . ' (' . $report['report_month'] . ')'); ?></b> مشاهده و بررسی نموده‌ام.
            </p>

            <div class="flex justify-around items-end mt-4 text-sm font-bold text-center">
                <div class="w-56">
                    <p class="mb-8">امضا و تاریخ رویت ولی دانش‌آموز</p>
                    <div class="border-b border-gray-400 w-40 mx-auto"></div>
                </div>
                <div class="w-56">
                    <p class="mb-8">مهر و امضای مدیر مدرسه</p>
                    <div class="border-b border-gray-400 w-40 mx-auto"></div>
                </div>
            </div>
        </div>

        <!-- Analytical Charts Grid -->
        <div class="grid grid-cols-2 gap-6 no-print">
            <div class="card p-4 border bg-slate-50 dark:bg-slate-800 rounded-xl">
                <h4 class="font-bold text-sm mb-4"><svg data-ui-icon="chart" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 3v18h18M7 17v-4m5 4V8m5 9V4"/></svg> نمودار مقایسه‌ای نمرات دروس:</h4>
                <canvas id="reportSubjectChart" height="180"></canvas>
            </div>
            <div class="card p-4 border bg-slate-50 dark:bg-slate-800 rounded-xl">
                <h4 class="font-bold text-sm mb-4"><svg data-ui-icon="chart" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 3v18h18M7 17v-4m5 4V8m5 9V4"/></svg> روند تغییرات معدل در طول سال تحصیلی:</h4>
                <canvas id="monthlyProgressChart" height="180"></canvas>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Chart !== 'undefined') {
        const ctxSubj = document.getElementById('reportSubjectChart');
        if (ctxSubj) {
            new Chart(ctxSubj, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($subjectLabels, JSON_UNESCAPED_UNICODE); ?>,
                    datasets: [{
                        label: 'نمره درس',
                        data: <?php echo json_encode($subjectScores); ?>,
                        backgroundColor: function(context) {
                            const val = context.raw;
                            const p=<?php echo json_encode(app_palette()); ?>;return val >= 17 ? p.success : (val >= 14 ? p.primary : (val >= 10 ? p.accent : p.danger));
                        },
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    scales: { y: { min: 0, max: 20 } },
                    plugins: { legend: { display: false } }
                }
            });
        }

        const ctxProg = document.getElementById('monthlyProgressChart');
        if (ctxProg) {
            <?php
            $pLabels = []; $pGpas = [];
            foreach ($monthlyReports as $mr) {
                $pLabels[] = clean($mr['term'] . ' (' . $mr['report_month'] . ')');
                $pGpas[] = (float)$mr['gpa'];
            }
            ?>
            new Chart(ctxProg, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($pLabels, JSON_UNESCAPED_UNICODE); ?>,
                    datasets: [{
                        label: 'معدل نوبت',
                        data: <?php echo json_encode($pGpas); ?>,
                        borderColor: <?php echo json_encode(app_palette()['primary']); ?>,
                        backgroundColor: <?php echo json_encode(app_palette()['primary'].'26'); ?>,
                        fill: true,
                        tension: 0.35,
                        pointRadius: 6,
                        pointBackgroundColor: '#2563eb'
                    }]
                },
                options: {
                    responsive: true,
                    scales: { y: { min: 0, max: 20 } }
                }
            });
        }
    }
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
