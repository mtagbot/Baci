<?php
// File: online-exam-grading.php - Manual grading for descriptive questions
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/online_exam_helpers.php';
require_once __DIR__ . '/includes/school_roles.php';
ensure_school_roles_schema();
ensure_online_exams_schema();

if (!empty($_SESSION['admin_id'])) {
    require_permission('manage_reports');
    $roleType='admin'; $roleId=$_SESSION['admin_id'];
} elseif (!empty($_SESSION['teacher_id'])) {
    $roleType='teacher'; $roleId=$_SESSION['teacher_id'];
} else {
    redirect('admin-login.php');
}

$teacherId = $_SESSION['teacher_id'] ?? null;
$isExecutive = $teacherId ? (teacher_has_executive($teacherId) || teacher_has_deputy($teacherId) || teacher_has_counselor($teacherId)) : false;

$examId = (int)($_GET['exam_id'] ?? 0);
$attemptId = (int)($_GET['attempt_id'] ?? 0);

if ($examId<=0 && $attemptId>0) {
    $tmp = DB::fetch("SELECT exam_id FROM online_exam_attempts WHERE id=?", [$attemptId]);
    if ($tmp) $examId = (int)$tmp['exam_id'];
}

if ($examId<=0) { set_flash_message('error','آزمون نامعتبر'); redirect('online-exams.php'); }

$exam = DB::fetch("SELECT * FROM online_exams WHERE id=?", [$examId]);
if (!$exam) { set_flash_message('error','آزمون یافت نشد'); redirect('online-exams.php'); }

// Permission check for teacher
if ($teacherId && !$isExecutive && (int)$exam['teacher_id'] !== (int)$teacherId) {
    set_flash_message('error','دسترسی غیرمجاز - فقط طراح آزمون یا معاون می‌تواند تصحیح کند'); redirect('online-exams.php');
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_grades'])) {
    if (!verify_csrf($_POST['csrf_token']??'')) { set_flash_message('error','CSRF'); redirect($_SERVER['REQUEST_URI']); }
    $grades = $_POST['points'] ?? [];
    $comments = $_POST['comment'] ?? [];
    $totalEarned = 0;
    foreach ($grades as $answerId=>$points) {
        $answerId = (int)$answerId;
        $points = (float)$points;
        $comment = trim($comments[$answerId] ?? '');
        $ans = DB::fetch("SELECT * FROM online_exam_answers WHERE id=?", [$answerId]);
        if (!$ans) continue;
        // Verify attempt belongs to this exam
        $att = DB::fetch("SELECT exam_id FROM online_exam_attempts WHERE id=?", [$ans['attempt_id']]);
        if (!$att || (int)$att['exam_id'] !== $examId) continue;
        DB::execute("UPDATE online_exam_answers SET points_earned=?, teacher_comment=?, graded_by_type=?, graded_by_id=?, graded_at=?, needs_manual=0 WHERE id=?", [$points, $comment, $roleType, $roleId, date('Y-m-d H:i:s'), $answerId]);
    }
    // Recalculate total score for this attempt if attempt_id given, or for all attempts of exam if grading bulk?
    if ($attemptId>0) {
        $sum = DB::fetch("SELECT SUM(points_earned) s FROM online_exam_answers WHERE attempt_id=?", [$attemptId]);
        $total = (float)($sum['s']??0);
        $max = DB::fetch("SELECT SUM(points) m FROM online_questions WHERE exam_id=? AND question_type!='info'", [$examId]);
        $maxVal = (float)($max['m']??0);
        DB::execute("UPDATE online_exam_attempts SET score=?, max_score=? WHERE id=?", [$total, $maxVal, $attemptId]);
        set_flash_message('success','نمرات دستی ذخیره شد - نمره کل بروزرسانی شد: '.tr_num($total,'fa'));
    } else {
        // Recalculate for all attempts of this exam
        $attempts = DB::fetchAll("SELECT id FROM online_exam_attempts WHERE exam_id=?", [$examId]);
        foreach ($attempts as $a) {
            $sum = DB::fetch("SELECT SUM(points_earned) s FROM online_exam_answers WHERE attempt_id=?", [$a['id']]);
            $total = (float)($sum['s']??0);
            $max = DB::fetch("SELECT SUM(points) m FROM online_questions WHERE exam_id=? AND question_type!='info'", [$examId]);
            $maxVal = (float)($max['m']??0);
            DB::execute("UPDATE online_exam_attempts SET score=?, max_score=? WHERE id=?", [$total, $maxVal, $a['id']]);
        }
        set_flash_message('success','تمام نمرات دستی ذخیره و معدل‌ها بروزرسانی شد');
    }
    redirect($_SERVER['REQUEST_URI']);
}

if ($attemptId>0) {
    // Single attempt grading
    $attempt = DB::fetch("SELECT a.*, s.first_name, s.last_name, s.class_name, s.national_id FROM online_exam_attempts a JOIN students s ON s.id=a.student_id WHERE a.id=? AND a.exam_id=?", [$attemptId, $examId]);
    if (!$attempt) { set_flash_message('error','تلاش یافت نشد'); redirect('online-exam-results.php?exam_id='.$examId); }
    $answers = DB::fetchAll("SELECT ans.*, q.question_text, q.question_type, q.points as max_points, q.question_data FROM online_exam_answers ans JOIN online_questions q ON q.id=ans.question_id WHERE ans.attempt_id=? ORDER BY q.order_index", [$attemptId]);
} else {
    // List attempts that have manual grading needed
    $attempts = DB::fetchAll("SELECT a.*, s.first_name, s.last_name, s.class_name, (SELECT COUNT(*) FROM online_exam_answers WHERE attempt_id=a.id AND needs_manual=1) pending_count FROM online_exam_attempts a JOIN students s ON s.id=a.student_id WHERE a.exam_id=? ORDER BY a.score DESC", [$examId]);
    $answers = [];
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-4 max-w-6xl mx-auto">
    <div class="flex justify-between items-center">
        <div>
            <h2 class="text-xl font-bold">تصحیح دستی آزمون: <?php echo clean($exam['title']); ?></h2>
            <p class="text-xs text-muted">سوالات تشریحی، جای خالی، آپلود فایل، صدا، تخته سفید نیاز به تصحیح دستی توسط دبیر دارند. نمره آنها بعد از تایید شما لحاظ می‌شود.</p>
        </div>
        <div class="flex gap-2">
            <a href="online-exams.php" class="btn btn-secondary text-xs">لیست آزمون‌ها</a>
            <a href="online-exam-results.php?exam_id=<?php echo $examId; ?>" class="btn btn-primary text-xs">نتایج</a>
            <a href="online-exam-monitor.php?exam_id=<?php echo $examId; ?>" class="btn btn-warning text-xs">مانیتورینگ</a>
        </div>
    </div>

    <?php if ($attemptId>0): ?>
        <div class="card">
            <h3 class="font-bold">دانش‌آموز: <?php echo clean($attempt['first_name'].' '.$attempt['last_name']); ?> - کلاس: <?php echo clean($attempt['class_name']); ?> - کد ملی: <?php echo tr_num($attempt['national_id'],'fa'); ?> - نمره فعلی: <?php echo tr_num($attempt['score'],'fa'); ?> / <?php echo tr_num($attempt['max_score'],'fa'); ?></h3>
            <p class="text-xs text-muted">تعداد تخلفات: خروج <?php echo tr_num($attempt['exit_count'],'fa'); ?> - تب <?php echo tr_num($attempt['tab_switch_count'],'fa'); ?> - کپی <?php echo tr_num($attempt['copy_attempts'],'fa'); ?></p>
        </div>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="save_grades" value="1">
            <?php foreach($answers as $ans): $qd=json_decode($ans['question_data'], true)?:[]; $ad=json_decode($ans['answer_data'], true)?:[]; $needsManual = !empty($ans['needs_manual']) || in_array($ans['question_type'], ['text','short_text','fill_blank','file_upload','voice_upload','whiteboard']); ?>
                <div class="card border-l-4 <?php echo $needsManual ? 'border-amber-400 bg-amber-50/30' : 'border-green-200'; ?>">
                    <div class="flex justify-between">
                        <b>سوال: <?php echo $ans['question_text']; ?></b>
                        <span class="badge">حداکثر نمره: <?php echo tr_num($ans['max_points'],'fa'); ?></span>
                    </div>
                    <div class="mt-2 grid grid-cols-2 gap-4">
                        <div>
                            <h4 class="text-xs font-bold">پاسخ دانش‌آموز:</h4>
                            <div class="p-2 bg-white border rounded text-sm mt-1">
                                <?php if(!empty($ad['value'])): echo nl2br(clean($ad['value'])); ?>
                                <?php elseif(!empty($ad['blanks'])): foreach($ad['blanks'] as $k=>$v) echo "خالی ".($k+1).": ".clean($v)."<br>"; ?>
                                <?php elseif(!empty($ad['matches'])): foreach($ad['matches'] as $k=>$v) echo "تطبیق ".($k+1).": ".clean($v)."<br>"; ?>
                                <?php elseif(!empty($ad['file_path'])): ?><a href="<?php echo clean($ad['file_path']); ?>" target="_blank" class="btn btn-secondary text-xs">مشاهده فایل <?php echo clean($ad['file_name']??''); ?></a><?php endif; ?>
                                <?php if(!empty($ad['file_path']) && strpos($ad['file_path'],'whiteboard')!==false): ?><div class="mt-2"><img src="<?php echo clean($ad['file_path']); ?>" class="max-w-full border rounded"></div><?php endif; ?>
                                <?php if(empty($ad) || (empty($ad['value']) && empty($ad['file_path']) && empty($ad['blanks']) && empty($ad['matches']))): echo '<span class="text-muted">بدون پاسخ</span>'; endif; ?>
                            </div>
                            <?php if($ans['question_type']=='voice_upload' && !empty($ad['file_path'])): ?><audio controls src="<?php echo clean($ad['file_path']); ?>" class="w-full mt-2"></audio><?php endif; ?>
                        </div>
                        <div>
                            <label class="text-xs font-bold">نمره دستی (از <?php echo tr_num($ans['max_points'],'fa'); ?>):</label>
                            <input type="number" step="0.25" min="0" max="<?php echo $ans['max_points']; ?>" name="points[<?php echo $ans['id']; ?>]" value="<?php echo $ans['points_earned']; ?>" class="form-input text-sm">
                            <label class="text-xs mt-2 block">نظر / توضیح برای دانش‌آموز:</label>
                            <textarea name="comment[<?php echo $ans['id']; ?>]" class="form-input text-xs" rows="2"><?php echo clean($ans['teacher_comment']??''); ?></textarea>
                            <?php if($needsManual): ?><span class="badge badge-warning text-[10px] mt-1">نیاز به تصحیح دستی</span><?php else: ?><span class="badge badge-success text-[10px] mt-1">تصحیح خودکار</span><?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; if(empty($answers)): ?>
                <div class="card text-center py-8 text-muted">پاسخی برای این تلاش یافت نشد</div>
            <?php endif; ?>
            <button class="btn btn-success w-full py-3 font-bold">ذخیره نمرات دستی و بروزرسانی نمره کل</button>
        </form>

    <?php else: ?>
        <div class="card">
            <h3 class="font-bold mb-3">لیست دانش‌آموزان شرکت‌کننده - نیاز به تصحیح دستی</h3>
            <div class="table-container">
                <table class="text-xs">
                    <thead><tr><th>دانش‌آموز</th><th>کلاس</th><th>نمره فعلی</th><th>نیاز به تصحیح</th><th>تخلفات</th><th>عملیات</th></tr></thead>
                    <tbody>
                        <?php foreach($attempts as $a): ?>
                        <tr class="<?php echo $a['pending_count']>0?'bg-amber-50':''; ?>">
                            <td class="font-bold"><?php echo clean($a['first_name'].' '.$a['last_name']); ?></td>
                            <td><?php echo clean($a['class_name']); ?></td>
                            <td><?php echo tr_num($a['score'],'fa'); ?> / <?php echo tr_num($a['max_score'],'fa'); ?></td>
                            <td><span class="badge <?php echo $a['pending_count']>0?'badge-warning':'badge-success'; ?>"><?php echo tr_num($a['pending_count'],'fa'); ?> سوال</span></td>
                            <td>خروج:<?php echo tr_num($a['exit_count'],'fa'); ?> تب:<?php echo tr_num($a['tab_switch_count'],'fa'); ?> کپی:<?php echo tr_num($a['copy_attempts'],'fa'); ?></td>
                            <td>
                                <a href="?exam_id=<?php echo $examId; ?>&attempt_id=<?php echo $a['id']; ?>" class="btn btn-primary text-[10px]">تصحیح دستی</a>
                                <a href="online-exam-result.php?attempt_id=<?php echo $a['id']; ?>" class="btn btn-secondary text-[10px]">نتیجه</a>
                            </td>
                        </tr>
                        <?php endforeach; if(empty($attempts)): ?><tr><td colspan="6" class="text-center py-6 text-muted">هنوز کسی شرکت نکرده</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
