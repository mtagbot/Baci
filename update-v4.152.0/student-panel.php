<?php
/**
 * Student Dashboard & Reports Panel (student-panel.php)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/school_roles.php';
require_once __DIR__ . '/includes/exams_helper.php';
require_student();
ensure_school_roles_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_current_student'])) {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        clear_remember_login();
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
        }
        session_destroy();
        redirect('index.php?view=login&removed=1');
    }
}

// Handle student logout via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['student_logout'])) {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        clear_remember_login();
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
        }
        session_destroy();
        redirect('index.php?view=login');
    }
}

$studentId = $_SESSION['student_id'];
$student = DB::fetch("SELECT * FROM students WHERE id = ?", [$studentId]);

if (!$student) {
    session_destroy();
    redirect('index.php?view=login');
}

require_once __DIR__ . '/includes/online_exam_helpers.php';
ensure_online_exams_schema();

require_once __DIR__ . '/includes/header.php';

// Fetch reports for this student
$reports = DB::fetchAll("SELECT * FROM reports WHERE student_id = ? ORDER BY id DESC", [$studentId]);

// Fetch relevant notifications
$notifs = DB::fetchAll("SELECT * FROM notifications WHERE target_type = 'all' OR (target_type = 'class' AND target_value = ?) ORDER BY id DESC LIMIT 5", [$student['class_name']]);
$disciplineItems = DB::fetchAll("SELECT title_text, occurred_at_jalali FROM student_discipline_records WHERE student_id = ? ORDER BY id DESC", [$studentId]);
$examItems = exam_student_schedule($studentId);

// Prepare chart data for unlocked reports
$chartLabels = [];
$chartGpas   = [];
foreach (array_reverse($reports) as $rep) {
    if (!$rep['is_locked']) {
        $chartLabels[] = clean($rep['term'] . ' (' . $rep['report_month'] . ')');
        $chartGpas[]   = (float)$rep['gpa'];
    }
}
?>
<div class="space-y-6">
    <!-- Student Profile Banner -->
    <div class="card bg-gradient-to-l from-blue-600 to-indigo-700 text-white p-6 rounded-2xl shadow-md flex justify-between items-center">
        <div>
            <span class="badge bg-white bg-opacity-20 text-white mb-2">پنل کاربری دانش‌آموز</span>
            <h2 class="text-2xl font-bold mb-1"><?php echo clean($student['first_name'] . ' ' . $student['last_name']); ?></h2>
            <p class="text-xs text-blue-100">کد ملی: <span class="font-mono"><?php echo clean($student['national_id']); ?></span> | کلاس: <b><?php echo clean($student['class_name']); ?></b> | پایه: <b><?php echo clean($student['grade_level']); ?></b></p>
        </div>
        <div class="text-left hidden md:block space-y-2">
            <span class="text-3xl block"><svg data-ui-icon="student" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m2 7 10-4 10 4-10 4Zm3 2v5c3 3 11 3 14 0V9M22 7v7M5 21c1-5 13-5 14 0"/></svg></span>
            <form method="POST" onsubmit="return confirm('این دانش‌آموز از حساب/نشست فعلی حذف شود؟')">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="remove_current_student" value="1">
                <button class="btn btn-danger text-xs"><svg data-ui-icon="delete" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 6h18M9 6V3h6v3M5 6l1 16h12l1-16M10 10v8m4-8v8"/></svg> حذف دانش‌آموز از حساب</button>
            </form>
        </div>
    </div>

    <!-- Online Exams Section for Student -->
    <?php
    $currentDefaultYear = get_current_academic_year();
    $studentYear = normalize_academic_year($student['academic_year'] ?? '');
    $yearConds = ["(e.academic_year='' OR e.academic_year IS NULL)"];
    $yearParamsOnline = [];
    if ($currentDefaultYear !== '') {
        $yearConds[] = "e.academic_year=?";
        $yearParamsOnline[] = $currentDefaultYear;
        $yearConds[] = "e.academic_year=?";
        $yearParamsOnline[] = str_replace('/', '-', $currentDefaultYear);
    }
    if ($studentYear !== '' && $studentYear !== normalize_academic_year($currentDefaultYear)) {
        $yearConds[] = "e.academic_year=?";
        $yearParamsOnline[] = $student['academic_year'];
    }
    $yearWhereOnline = "(" . implode(" OR ", $yearConds) . ")";
    $sqlOnline = "SELECT e.*, (SELECT COUNT(*) FROM online_questions WHERE exam_id=e.id) qcount FROM online_exams e WHERE e.status='published' AND e.is_active=1 AND $yearWhereOnline AND (e.class_name='' OR e.class_name=? OR e.class_name IS NULL) ORDER BY e.start_datetime DESC LIMIT 5";
    $paramsOnline = array_merge($yearParamsOnline, [$student['class_name']]);
    try {
        $onlineExamsForStudent = DB::fetchAll($sqlOnline, $paramsOnline);
    } catch (Exception $e) {
        $onlineExamsForStudent = DB::fetchAll("SELECT e.*, (SELECT COUNT(*) FROM online_questions WHERE exam_id=e.id) qcount FROM online_exams e WHERE e.status='published' AND e.is_active=1 AND (e.class_name='' OR e.class_name=? OR e.class_name IS NULL) ORDER BY e.start_datetime DESC LIMIT 5", [$student['class_name']]);
    }
    if ($onlineExamsForStudent) {
        $liveAttempts = DB::fetchAll("SELECT * FROM online_exam_attempts WHERE student_id=? AND status='in_progress' ORDER BY id DESC", [$studentId]);
    } else { $liveAttempts = []; }
    ?>
    <?php if (!empty($onlineExamsForStudent) || !empty($liveAttempts)): ?>
    <div class="card border-indigo-200 bg-indigo-50 space-y-3">
        <div class="flex justify-between items-center">
            <h3 class="font-bold text-indigo-900 flex items-center gap-1"><svg data-ui-icon="science" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M8 2h8m-6 0v7L3 21h18L14 9V2M7 15h10"/></svg> آزمون‌های آنلاین شما: <span class="text-xs">(سال جاری: <?php echo clean($currentDefaultYear); ?> - سال شما: <?php echo clean($student['academic_year'] ?? 'نامشخص'); ?>)</span></h3>
            <a href="student-online-exams.php" class="btn btn-primary text-xs">مشاهده همه آزمون‌ها</a>
        </div>
        <?php if ($studentYear !== '' && $currentDefaultYear !== '' && $studentYear !== normalize_academic_year($currentDefaultYear)): ?>
        <div class="p-2 bg-amber-100 border border-amber-200 rounded text-xs text-amber-800"><svg data-ui-icon="warning" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3 2 21h20Z"/><path d="M12 9v5m0 3v.1"/></svg> شما دانش‌آموز سال تحصیلی جاری (<?php echo clean($currentDefaultYear); ?>) نمی‌باشید. سال شما: <?php echo clean($student['academic_year']); ?></div>
        <?php endif; ?>
        <?php if ($liveAttempts): ?>
        <div class="p-2 bg-amber-100 border border-amber-200 rounded text-xs text-amber-800"><svg data-ui-icon="warning" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3 2 21h20Z"/><path d="M12 9v5m0 3v.1"/></svg> شما <?php echo count($liveAttempts); ?> آزمون ناتمام در حال برگزاری دارید. با کلیک روی ادامه آزمون می‌توانید بازگردید (زمان شما حتی در خارج صفحه محاسبه می‌شود)</div>
        <?php endif; ?>
        <div class="grid grid-cols-1 gap-2">
        <?php foreach ($onlineExamsForStudent as $oe): 
            $att = DB::fetch("SELECT * FROM online_exam_attempts WHERE exam_id=? AND student_id=? ORDER BY id DESC LIMIT 1", [$oe['id'], $studentId]);
            $isLive = $att && $att['status']=='in_progress';
        ?>
            <div class="p-3 bg-white rounded border flex justify-between items-center">
                <div><span class="font-bold text-sm"><?php echo clean($oe['title']); ?></span><div class="text-xs text-muted"><?php echo clean($oe['subject_name']); ?> | <?php echo tr_num($oe['duration_minutes'],'fa'); ?> دقیقه | <?php echo tr_num($oe['qcount'],'fa'); ?> سوال</div></div>
                <div>
                    <?php if ($isLive): ?><a href="online-exam-take.php?attempt_id=<?php echo $att['id']; ?>" class="btn btn-warning text-xs">▶️ ادامه آزمون</a>
                    <?php else: 
                        list($canTake,$reason) = online_exam_can_student_take($oe, $student);
                        if ($canTake): ?><a href="online-exam-take.php?exam_id=<?php echo $oe['id']; ?>" class="btn btn-success text-xs"><svg data-ui-icon="bolt" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m14 2-11 12h8l-1 8 11-12h-8Z"/></svg> شروع آزمون</a>
                        <?php else: ?><span class="text-[11px] text-muted"><?php echo clean($reason); ?></span><?php if($att): ?><a href="online-exam-result.php?attempt_id=<?php echo $att['id']; ?>" class="btn btn-secondary text-xs ml-1">نتیجه</a><?php endif; ?><?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Notifications Section -->
    <?php if (!empty($notifs)): ?>
    <div class="card border-blue-200 bg-blue-50 space-y-3">
        <h3 class="font-bold text-blue-900 flex items-center gap-1">
            <span><svg data-ui-icon="message" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 3h18v14H9l-6 5ZM7 8h10M7 12h7"/></svg> اطلاعیه‌های مدرسه:</span>
        </h3>
        <?php foreach ($notifs as $nt): ?>
        <div class="p-3 bg-white rounded border border-blue-100 text-sm">
            <h4 class="font-bold text-blue-800"><?php echo clean($nt['title']); ?></h4>
            <p class="text-xs text-gray-600 mt-1"><?php echo nl2br(clean($nt['message'])); ?></p>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Exam schedule visible to student/parents -->
    <div class="card border-blue-200 bg-blue-50 space-y-3">
        <h3 class="font-bold text-blue-900"><svg data-ui-icon="exam" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="5" y="4" width="14" height="18" rx="2"/><path d="M9 2h6v4H9Zm0 8h6m-6 4h3m-3 4h6"/></svg> برنامه امتحانی</h3>
        <?php if ($examItems): ?>
        <div class="table-container"><table><thead><tr><th>تاریخ</th><th>ساعت</th><th>درس</th><th>دبیر</th><th>کلاس امتحانی</th><th>صندلی</th></tr></thead><tbody>
            <?php foreach ($examItems as $ex): ?><tr><td><?php echo tr_num($ex['exam_date_jalali'], 'fa'); ?></td><td><?php echo tr_num($ex['start_time'], 'fa'); ?></td><td class="font-bold"><?php echo clean($ex['subject_name']); ?></td><td><?php echo clean($ex['t_name']); ?></td><td><?php echo clean($ex['exam_room'] ?: $ex['exam_room_default']); ?></td><td><?php echo tr_num($ex['seat_number'] ?: '---', 'fa'); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php else: ?><p class="text-sm text-muted">برنامه امتحانی برای شما ثبت نشده است.</p><?php endif; ?>
    </div>

    <!-- Discipline Section visible to student/parents: title and Jalali date only, never internal deputy notes. -->
    <div class="card border-amber-200 bg-amber-50 space-y-3">
        <h3 class="font-bold text-amber-900"><svg data-ui-icon="warning" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3 2 21h20Z"/><path d="M12 9v5m0 3v.1"/></svg> موارد انضباطی ثبت‌شده</h3>
        <?php if ($disciplineItems): ?>
        <div class="table-container"><table><thead><tr><th>عنوان مورد</th><th>تاریخ و زمان شمسی</th></tr></thead><tbody>
            <?php foreach ($disciplineItems as $di): ?><tr><td class="font-bold"><?php echo clean($di['title_text']); ?></td><td><?php echo tr_num($di['occurred_at_jalali'], 'fa'); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php else: ?><p class="text-sm text-muted">موردی ثبت نشده است.</p><?php endif; ?>
    </div>

    <!-- Progress Chart -->
    <?php if (count($chartGpas) > 0): ?>
    <div class="card">
        <h3 class="font-bold mb-4">نمودار روند پیشرفت تحصیلی شما (معدل‌های کارنامه)</h3>
        <canvas id="studentProgressChart" height="150"></canvas>
    </div>
    <?php endif; ?>

    <!-- Reports Table -->
    <div class="card">
        <h3 class="font-bold mb-4">کارنامه‌های صادرشده برای شما</h3>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>سال تحصیلی</th>
                        <th>نوبت / ماه</th>
                        <th>مجموع نمرات</th>
                        <th>معدل کل</th>
                        <th>وضعیت دسترسی</th>
                        <th>عملیات و دریافت فایل</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $rep): ?>
                    <tr>
                        <td class="font-bold"><?php echo clean($rep['academic_year']); ?></td>
                        <td><?php echo clean($rep['term'] . ' (' . $rep['report_month'] . ')'); ?></td>
                        <td><?php echo format_score($rep['total_score']); ?></td>
                        <td>
                            <?php if ($rep['is_locked']): ?>
                                <span class="text-muted">---</span>
                            <?php else: ?>
                                <span class="badge badge-info text-sm"><?php echo format_score($rep['gpa']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($rep['is_locked']): ?>
                                <span class="badge badge-danger">مشاهده مسدود است <svg data-ui-icon="lock" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="4" y="10" width="16" height="12" rx="2"/><path d="M7 10V6c0-6 10-6 10 0v4M12 15v3"/></svg></span>
                            <?php else: ?>
                                <span class="badge badge-success">آزاد و قابل دریافت <svg data-ui-icon="unlock" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="4" y="10" width="16" height="12" rx="2"/><path d="M7 10V6c0-6 10-6 10 0M12 15v3"/></svg></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($rep['is_locked']): ?>
                                <span class="text-xs text-red-600">به دلیل امور اداری/مالی مسدود است.</span>
                            <?php else: ?>
                                <div class="flex gap-2 justify-end">
                                    <a href="report-view.php?id=<?php echo $rep['id']; ?>" class="btn btn-primary text-xs px-3 py-1">مشاهده کارنامه</a>
                                    <a href="report-print.php?id=<?php echo $rep['id']; ?>&pt=<?php echo urlencode(make_report_print_token($rep['id'], $studentId)); ?>" target="_self" class="btn btn-secondary text-xs px-3 py-1"><svg data-ui-icon="print" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M7 8V3h10v5M7 17H3V9h18v8h-4M7 14h10v8H7ZM17 11h.1"/></svg> چاپ کارنامه</a>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; if (empty($reports)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-6">هنوز کارنامه‌ای برای شما صادر نشده است.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Chart !== 'undefined') {
        const ctx = document.getElementById('studentProgressChart');
        if (ctx) {
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($chartLabels, JSON_UNESCAPED_UNICODE); ?>,
                    datasets: [{
                        label: 'معدل کل نوبت',
                        data: <?php echo json_encode($chartGpas); ?>,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.1)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 6,
                        pointHoverRadius: 8
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
