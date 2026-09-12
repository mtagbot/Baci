<?php
// File: online-exam-results.php - List results for an online exam v4.28.8 with manual grading
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/online_exam_helpers.php';
require_once __DIR__ . '/includes/school_roles.php';
ensure_school_roles_schema();
ensure_online_exams_schema();

if (!empty($_SESSION['admin_id'])) require_permission('manage_reports');
elseif (empty($_SESSION['teacher_id'])) redirect('admin-login.php');

$teacherId = $_SESSION['teacher_id'] ?? null;
$isExecutive = $teacherId ? (teacher_has_executive($teacherId) || teacher_has_deputy($teacherId)) : false;

$examId = (int)($_GET['exam_id'] ?? 0);
$attemptId = (int)($_GET['attempt_id'] ?? 0);

if ($attemptId>0) {
    redirect('online-exam-result.php?attempt_id='.$attemptId);
}

if ($examId<=0) { set_flash_message('error','آزمون نامعتبر'); redirect('online-exams.php'); }
$exam = DB::fetch("SELECT * FROM online_exams WHERE id=?", [$examId]);
if (!$exam) { set_flash_message('error','آزمون یافت نشد'); redirect('online-exams.php'); }
if ($teacherId && !$isExecutive && (int)$exam['teacher_id'] !== (int)$teacherId) { set_flash_message('error','غیرمجاز'); redirect('online-exams.php'); }

$attempts = DB::fetchAll("SELECT a.*, s.first_name, s.last_name, s.class_name, s.national_id, (SELECT COUNT(*) FROM online_exam_answers WHERE attempt_id=a.id AND needs_manual=1) pending_manual FROM online_exam_attempts a JOIN students s ON s.id=a.student_id WHERE a.exam_id=? ORDER BY a.score DESC, a.submitted_at ASC", [$examId]);

$avg = 0; $max=0; $min=1000; $count=count($attempts);
if ($count>0) {
    $scores = array_column($attempts,'score');
    $avg = array_sum($scores)/max(1,$count);
    $max = max($scores); $min = min($scores);
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-4">
    <div class="flex justify-between items-center">
        <div><h2 class="text-xl font-bold">📊 نتایج آزمون: <?php echo clean($exam['title']); ?></h2><p class="text-xs text-muted">تعداد شرکت‌کنندگان: <?php echo tr_num($count,'fa'); ?> | میانگین: <?php echo tr_num(round($avg,2),'fa'); ?> | بیشترین: <?php echo tr_num($max,'fa'); ?> | کمترین: <?php echo tr_num($min,'fa'); ?> | همه تاریخ‌ها شمسی</p></div>
        <div class="flex gap-2"><a href="online-exams.php" class="btn btn-secondary text-xs">لیست آزمون‌ها</a><a href="online-exam-grading.php?exam_id=<?php echo $examId; ?>" class="btn btn-success text-xs">📝 تصحیح دستی تشریحی</a><a href="online-exam-monitor.php?exam_id=<?php echo $examId; ?>" class="btn btn-warning text-xs">📡 مانیتورینگ زنده</a><a href="online-exam-questions.php?exam_id=<?php echo $examId; ?>" class="btn btn-primary text-xs">سوالات</a></div>
    </div>

    <div class="card">
        <div class="table-container">
            <table class="text-xs">
                <thead><tr><th>رتبه</th><th>دانش‌آموز</th><th>کلاس</th><th>کد ملی</th><th>نمره</th><th>درصد</th><th>وضعیت</th><th>نیاز به تصحیح دستی</th><th>تلاش</th><th>زمان ارسال (شمسی)</th><th>تخلفات (تب/خروج/کپی)</th><th>عملیات</th></tr></thead>
                <tbody>
                    <?php foreach($attempts as $idx=>$a): ?>
                    <tr class="<?php echo $a['pending_manual']>0?'bg-amber-50':''; ?>">
                        <td><?php echo tr_num($idx+1,'fa'); ?></td>
                        <td class="font-bold"><?php echo clean($a['first_name'].' '.$a['last_name']); ?></td>
                        <td><?php echo clean($a['class_name']); ?></td>
                        <td class="font-mono"><?php echo tr_num($a['national_id'],'fa'); ?></td>
                        <td><span class="badge <?php echo $a['score']>= $exam['passing_score'] && $exam['passing_score']>0?'badge-success':'badge-info'; ?>\"><?php echo tr_num($a['score'],'fa'); ?> / <?php echo tr_num($a['max_score'],'fa'); ?></span></td>
                        <td><?php echo $a['max_score']>0? tr_num(round($a['score']/$a['max_score']*100,1),'fa').'%':'-'; ?></td>
                        <td><?php echo clean($a['status']); ?></td>
                        <td><span class="badge <?php echo $a['pending_manual']>0?'badge-warning':'badge-success'; ?>"><?php echo tr_num($a['pending_manual'],'fa'); ?> سوال</span></td>
                        <td><?php echo tr_num($a['attempt_number'],'fa'); ?></td>
                        <td><?php echo tr_num(jdate('Y/m/d H:i', strtotime($a['submitted_at']?:$a['end_time'])),'fa'); ?></td>
                        <td><?php echo tr_num($a['tab_switch_count'],'fa'); ?>/<?php echo tr_num($a['exit_count'],'fa'); ?>/<?php echo tr_num($a['copy_attempts'],'fa'); ?></td>
                        <td>
                            <a href="online-exam-result.php?attempt_id=<?php echo $a['id']; ?>" class="btn btn-primary text-[10px]">جزئیات + تخلفات فارسی</a>
                            <a href="online-exam-grading.php?exam_id=<?php echo $examId; ?>&attempt_id=<?php echo $a['id']; ?>" class="btn btn-success text-[10px]">📝 تصحیح دستی</a>
                        </td>
                    </tr>
                    <?php endforeach; if(empty($attempts)): ?><tr><td colspan="12" class="text-center text-muted py-8">هنوز کسی شرکت نکرده</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
