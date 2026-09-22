<?php
// File: student-online-exams.php - Student view available online exams - v4.28.8 with academic year coherence
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/online_exam_helpers.php';
require_student();
ensure_online_exams_schema();

$studentId = $_SESSION['student_id'];
$student = DB::fetch("SELECT * FROM students WHERE id=?", [$studentId]);

$currentDefaultYear = get_current_academic_year(); // From settings
$studentYearRaw = $student['academic_year'] ?? '';
$studentYear = normalize_academic_year($studentYearRaw);
$currentDefaultYearNorm = normalize_academic_year($currentDefaultYear);

// If student year empty, treat as current default for display, but warn?
$isInCurrentYear = true;
$yearMismatchMessage = '';
if ($studentYear !== '' && $currentDefaultYearNorm !== '' && $studentYear !== $currentDefaultYearNorm) {
    // Student is not in current default year
    $isInCurrentYear = false;
    $yearMismatchMessage = "شما دانش‌آموز سال تحصیلی جاری نمی‌باشید. سال تحصیلی شما: ".clean($studentYearRaw ?: 'نامشخص')." - سال تحصیلی جاری سیستم: ".clean($currentDefaultYear)." - لطفا با مدیریت تماس بگیرید یا آزمون‌های سال خود را ببینید.";
}

// For exam listing, show exams that match EITHER current default year OR student's year OR empty (old exams)
// This fixes issue where exam set to 1405/1406 doesn't show when student is 1404/1405 but default is 1405/1406
// We will show all exams that are either current year or student's year

$yearConditions = [];
$yearParams = [];
if ($currentDefaultYearNorm !== '') {
    $yearConditions[] = "e.academic_year=?";
    $yearParams[] = $currentDefaultYear;
    // Also try with normalized dash/slash variants
    $yearConditions[] = "e.academic_year=?";
    $yearParams[] = str_replace('/', '-', $currentDefaultYear);
    $yearConditions[] = "e.academic_year=?";
    $yearParams[] = str_replace('-', '/', $currentDefaultYear);
}
if ($studentYear !== '' && $studentYear !== $currentDefaultYearNorm) {
    $yearConditions[] = "e.academic_year=?";
    $yearParams[] = $student['academic_year'] ?? '';
    $yearConditions[] = "e.academic_year=?";
    $yearParams[] = $studentYear;
    $yearConditions[] = "e.academic_year=?";
    $yearParams[] = str_replace('/', '-', $studentYear);
}
$yearConditions[] = "e.academic_year='' OR e.academic_year IS NULL";

$yearWhere = "(" . implode(" OR ", $yearConditions) . ")";

$sql = "SELECT e.*, c.name as category_name, t.full_name as teacher_name 
        FROM online_exams e 
        LEFT JOIN online_exam_categories c ON c.id=e.category_id 
        LEFT JOIN teachers t ON t.id=e.teacher_id 
        WHERE e.status='published' AND e.is_active=1 
        AND $yearWhere
        AND (e.class_name='' OR e.class_name=? OR e.class_name IS NULL) 
        ORDER BY e.start_datetime DESC, e.id DESC";

$params = array_merge($yearParams, [$student['class_name']]);

try {
    $exams = DB::fetchAll($sql, $params);
} catch (Exception $e) {
    // Fallback: show all published exams for student's class without year filter
    $exams = DB::fetchAll("SELECT e.*, c.name as category_name, t.full_name as teacher_name FROM online_exams e LEFT JOIN online_exam_categories c ON c.id=e.category_id LEFT JOIN teachers t ON t.id=e.teacher_id WHERE e.status='published' AND e.is_active=1 AND (e.class_name='' OR e.class_name=? OR e.class_name IS NULL) ORDER BY e.id DESC", [$student['class_name']]);
}

// For each, get attempt info
foreach ($exams as &$ex) {
    $att = DB::fetch("SELECT * FROM online_exam_attempts WHERE exam_id=? AND student_id=? ORDER BY id DESC LIMIT 1", [$ex['id'],$studentId]);
    $ex['last_attempt'] = $att;
    $ex['attempts_count'] = DB::fetch("SELECT COUNT(*) c FROM online_exam_attempts WHERE exam_id=? AND student_id=? AND status IN ('submitted','auto_submitted','expired')", [$ex['id'],$studentId])['c'] ?? 0;
    list($can, $reason) = online_exam_can_student_take($ex, $student);
    if ($att && $att['status']=='in_progress') $can=true;
    $ex['can_take'] = $can;
    $ex['cant_reason'] = $reason;
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-6 max-w-5xl mx-auto">
    <div class="card bg-gradient-to-l from-indigo-600 to-blue-700 text-white p-6">
        <h2 class="text-xl font-bold">🧪 آزمون‌های آنلاین من</h2>
        <p class="text-sm text-indigo-100">
            کلاس: <?php echo clean($student['class_name']); ?> - 
            <?php echo clean($student['first_name'].' '.$student['last_name']); ?> - 
            سال تحصیلی شما: <?php echo clean($studentYearRaw ?: 'نامشخص'); ?> - 
            سال جاری سیستم: <?php echo clean($currentDefaultYear); ?>
        </p>
        <?php if (!$isInCurrentYear): ?>
            <div class="mt-3 p-3 bg-amber-200 text-amber-900 rounded text-sm font-bold">
                ⚠️ <?php echo $yearMismatchMessage; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$isInCurrentYear): ?>
    <div class="card border-amber-300 bg-amber-50">
        <h3 class="font-bold text-amber-800">شما دانش‌آموز سال تحصیلی جاری نمی‌باشید</h3>
        <p class="text-sm text-amber-700 mt-1">
            سال تحصیلی پیش‌فرض سیستم از قسمت مدیریت دروس → تب سال تحصیلی روی <b><?php echo clean($currentDefaultYear); ?></b> تنظیم شده است.<br>
            اما سال تحصیلی ثبت شده برای شما <b><?php echo clean($studentYearRaw ?: 'خالی'); ?></b> است.<br>
            آزمون‌های سال جاری ممکن است برای شما نمایش داده نشود. لطفا از مدیریت بخواهید سال تحصیلی شما را به سال جاری تغییر دهد یا شما را به سال جاری منتقل کند (از مدیریت دانش‌آموزان → انتقال).
        </p>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 gap-4">
        <?php foreach($exams as $ex): $att=$ex['last_attempt']; ?>
        <div class="card border-l-4 <?php echo $ex['can_take']?'border-green-500':'border-gray-300'; ?>">
            <div class="flex justify-between items-start">
                <div class="flex-1">
                    <h3 class="font-bold text-base">
                        <?php echo clean($ex['title']); ?> 
                        <?php if(!empty($ex['category_name'])) echo '<span class="badge badge-info text-xs">'.clean($ex['category_name']).'</span>'; ?>
                        <span class="badge badge-secondary text-xs">سال: <?php echo clean($ex['academic_year']); ?></span>
                    </h3>
                    <p class="text-xs text-muted mt-1"><?php echo clean($ex['description']); ?></p>
                    <div class="flex flex-wrap gap-3 text-xs mt-2">
                        <span>👨‍🏫 <?php echo clean($ex['teacher_name']?:'مدیر'); ?></span>
                        <span>📚 <?php echo clean($ex['subject_name']); ?></span>
                        <span>⏱️ <?php echo tr_num($ex['duration_minutes'],'fa'); ?> دقیقه</span>
                        <span>❓ <?php echo tr_num(DB::fetch("SELECT COUNT(*) c FROM online_questions WHERE exam_id=?", [$ex['id']])['c'],'fa'); ?> سوال</span>
                        <?php if($ex['start_datetime']): ?><span>🟢 شروع: <?php echo tr_num(jdate('Y/m/d H:i', strtotime($ex['start_datetime'])),'fa'); ?> (<?php echo clean($ex['academic_year']); ?>)</span><?php endif; ?>
                        <?php if($ex['end_datetime']): ?><span>🔴 پایان: <?php echo tr_num(jdate('Y/m/d H:i', strtotime($ex['end_datetime'])),'fa'); ?> (شمسی: <?php echo tr_num(jdate('Y/m/d H:i', strtotime($ex['end_datetime'])),'fa'); ?>)</span><?php endif; ?>
                        <span>🔄 تلاش‌ها: <?php echo tr_num($ex['attempts_count'],'fa'); ?>/<?php echo tr_num($ex['max_attempts'],'fa'); ?></span>
                    </div>
                    <?php if($att): ?>
                    <div class="mt-2 p-2 bg-slate-50 rounded text-xs">آخرین تلاش: وضعیت <?php echo $att['status']; ?> - نمره <?php echo tr_num($att['score'],'fa'); ?> / <?php echo tr_num($att['max_score'],'fa'); ?> - <?php echo tr_num(jdate('Y/m/d H:i', strtotime($att['start_time'])),'fa'); ?></div>
                    <?php endif; ?>
                    <?php if(!$ex['can_take'] && !$att): ?><div class="mt-2 text-xs text-red-600">⚠️ <?php echo clean($ex['cant_reason']); ?></div><?php endif; ?>
                </div>
                <div class="flex flex-col gap-1 ml-3">
                    <?php if($att && $att['status']=='in_progress'): ?>
                        <a href="online-exam-take.php?attempt_id=<?php echo $att['id']; ?>" class="btn btn-warning text-xs">▶️ ادامه آزمون (زمان از قبل محاسبه می‌شود)</a>
                    <?php elseif($ex['can_take']): ?>
                        <a href="online-exam-take.php?exam_id=<?php echo $ex['id']; ?>" class="btn btn-success text-xs">🚀 شروع آزمون</a>
                    <?php else: ?>
                        <?php if($att): ?><a href="online-exam-result.php?attempt_id=<?php echo $att['id']; ?>" class="btn btn-primary text-xs">📊 مشاهده نتیجه</a><?php endif; ?>
                    <?php endif; ?>
                    <?php if($att && in_array($att['status'], ['submitted','auto_submitted','expired'])): ?>
                        <a href="online-exam-result.php?attempt_id=<?php echo $att['id']; ?>" class="btn btn-secondary text-xs">📊 نتیجه آخرین تلاش</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; if(empty($exams)): ?>
        <div class="card text-center py-12 text-muted">
            <p>در حال حاضر آزمون آنلاینی برای شما تعریف نشده.</p>
            <p class="text-xs mt-2">سال جاری سیستم: <?php echo clean($currentDefaultYear); ?> - سال شما: <?php echo clean($studentYearRaw ?: 'خالی'); ?> - کلاس شما: <?php echo clean($student['class_name']); ?></p>
            <p class="text-xs mt-1">اگر سال تحصیلی آزمون روی <?php echo clean($currentDefaultYear); ?> است و شما نمی‌بینید، از مدیریت بخواهید سال شما را هم به <?php echo clean($currentDefaultYear); ?> تغییر دهد.</p>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
