<?php
// File: online-exam-preview.php - Preview as student view (read-only)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/online_exam_helpers.php';
ensure_online_exams_schema();

if (empty($_SESSION['admin_id']) && empty($_SESSION['teacher_id'])) redirect('admin-login.php');
$examId = (int)($_GET['exam_id']??0);
$exam = DB::fetch("SELECT * FROM online_exams WHERE id=?", [$examId]);
if (!$exam) { echo 'آزمون یافت نشد'; exit; }
$questions = DB::fetchAll("SELECT * FROM online_questions WHERE exam_id=? ORDER BY order_index ASC", [$examId]);

require_once __DIR__ . '/includes/header.php';
?>
<div class="max-w-4xl mx-auto space-y-4">
    <div class="card"><h2 class="font-bold">پیش‌نمایش آزمون: <?php echo clean($exam['title']); ?></h2><p class="text-xs text-muted">این نمای دانش‌آموز است - پاسخ‌ها ذخیره نمی‌شود</p></div>
    <?php foreach($questions as $idx=>$q): $qd=json_decode($q['question_data'], true)?:[]; ?>
    <div class="card">
        <div class="font-bold mb-2">سوال <?php echo $idx+1; ?>: <?php echo $q['question_text']; ?></div>
        <?php if(!empty($qd['options'])): foreach($qd['options'] as $opt): ?><div class="text-sm p-1">○ <?php echo clean($opt['text']); ?></div><?php endforeach; endif; ?>
        <?php if($q['question_type']==='short_text'): ?><input class="form-input" placeholder="پاسخ کوتاه"><?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
