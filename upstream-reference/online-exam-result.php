<?php
// File: online-exam-result.php - Show exam result
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/online_exam_helpers.php';
ensure_online_exams_schema();

if (empty($_SESSION['student_id']) && empty($_SESSION['teacher_id']) && empty($_SESSION['admin_id'])) redirect('index.php');

$attemptId = (int)($_GET['attempt_id'] ?? 0);
if ($attemptId<=0) { set_flash_message('error','attempt نامعتبر'); redirect('index.php'); }

$attempt = DB::fetch("SELECT a.*, e.title, e.show_results, e.passing_score, e.id as exam_id, s.first_name, s.last_name, s.class_name FROM online_exam_attempts a JOIN online_exams e ON e.id=a.exam_id JOIN students s ON s.id=a.student_id WHERE a.id=?", [$attemptId]);

if (!$attempt) { set_flash_message('error','آزمون یافت نشد'); redirect('index.php'); }

// Permission check
if (!empty($_SESSION['student_id']) && (int)$attempt['student_id'] !== (int)$_SESSION['student_id']) { set_flash_message('error','غیرمجاز'); redirect('student-panel.php'); }
if (!empty($_SESSION['teacher_id'])) {
    // check teacher owns exam or executive
    require_once __DIR__.'/includes/school_roles.php';
    $examOwner = DB::fetch("SELECT teacher_id FROM online_exams WHERE id=?", [$attempt['exam_id']]);
    $isExec = teacher_has_executive($_SESSION['teacher_id']) || teacher_has_deputy($_SESSION['teacher_id']) || teacher_has_counselor($_SESSION['teacher_id']);
    if (!$isExec && $examOwner && (int)$examOwner['teacher_id'] !== (int)$_SESSION['teacher_id']) { set_flash_message('error','غیرمجاز'); redirect('teacher-panel.php'); }
}

$questions = DB::fetchAll("SELECT * FROM online_questions WHERE exam_id=? ORDER BY order_index ASC", [$attempt['exam_id']]);
$answers = [];
$ansRows = DB::fetchAll("SELECT * FROM online_exam_answers WHERE attempt_id=?", [$attemptId]);
foreach($ansRows as $a) $answers[$a['question_id']] = $a;

$canShowDetails = true;
if (!empty($_SESSION['student_id'])) {
    if ($attempt['show_results']==='never') $canShowDetails=false;
    if ($attempt['show_results']==='after_end') {
        $examFull = DB::fetch("SELECT end_datetime FROM online_exams WHERE id=?", [$attempt['exam_id']]);
        if ($examFull && $examFull['end_datetime'] && strtotime($examFull['end_datetime']) > time()) $canShowDetails=false;
    }
}

$proctorLogs = DB::fetchAll("SELECT * FROM online_exam_proctoring_logs WHERE attempt_id=? ORDER BY id ASC", [$attemptId]);

require_once __DIR__ . '/includes/header.php';
?>
<div class="max-w-5xl mx-auto space-y-4">
    <div class="card bg-gradient-to-l from-indigo-600 to-purple-700 text-white">
        <h2 class="text-xl font-bold">📊 نتیجه آزمون: <?php echo clean($attempt['title']); ?></h2>
        <p class="text-sm">دانش‌آموز: <?php echo clean($attempt['first_name'].' '.$attempt['last_name']); ?> - کلاس: <?php echo clean($attempt['class_name']); ?></p>
        <div class="flex gap-4 mt-3 text-sm flex-wrap">
            <span>نمره: <b><?php echo tr_num($attempt['score'],'fa'); ?> / <?php echo tr_num($attempt['max_score'],'fa'); ?></b></span>
            <span>درصد: <b><?php echo $attempt['max_score']>0? tr_num(round($attempt['score']/$attempt['max_score']*100,1),'fa').'%':'-'; ?></b></span>
            <span>وضعیت: <?php echo $attempt['score']>= (float)$attempt['passing_score'] && $attempt['passing_score']>0 ? '✅ قبول' : ($attempt['passing_score']>0?'❌ مردود':'-'); ?></span>
            <span>زمان ارسال: <?php echo tr_num($attempt['submitted_at']??$attempt['end_time'],'fa'); ?></span>
            <span>IP: <?php echo clean($attempt['ip_address']??''); ?></span>
            <span>خروج: <?php echo tr_num($attempt['exit_count']??0,'fa'); ?> - تب: <?php echo tr_num($attempt['tab_switch_count']??0,'fa'); ?> - کپی: <?php echo tr_num($attempt['copy_attempts']??0,'fa'); ?></span>
        </div>
    </div>

    <!-- Proctoring Report - Visible to student and staff after exam -->
    <div class="card border-2 <?php echo (!empty($proctorLogs) && count($proctorLogs)>5) ? 'border-red-200 bg-red-50/30' : 'border-green-200 bg-green-50/30'; ?>">
        <h3 class="font-bold mb-3">🛡️ گزارش اقدامات شما در حین آزمون (ثبت خودکار ضدتقلب)</h3>
        <p class="text-xs text-muted mb-2">تمام خروج‌ها، جابجایی تب، کپی، موقعیت و ... به صورت خودکار ثبت شده و برای کادر مدرسه قابل مشاهده است. این گزارش برای شفافیت به شما هم نمایش داده می‌شود.</p>
        <?php if(empty($proctorLogs)): ?>
            <p class="text-xs text-green-700">✅ هیچ تخلفی ثبت نشده - آزمون شما بدون خروج و جابجایی بود</p>
        <?php else: ?>
            <div class="max-h-[300px] overflow-auto text-xs space-y-1">
                <?php foreach($proctorLogs as $log): ?>
                    <div class="p-2 border rounded bg-white flex justify-between">
                        <div>
                            <b class="text-red-600"><?php echo clean(online_proctoring_event_label($log['event_type'])); ?></b>
                            <span class="text-muted"> - <?php echo tr_num($log['created_at'],'fa'); ?> - IP: <?php echo clean($log['ip_address']); ?></span>
                            <div class="text-[11px] text-gray-600"><?php echo clean($log['event_data']); ?></div>
                        </div>
                        <div class="text-[10px] text-muted"><?php echo clean($log['event_type']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-2 text-xs">
                <b>خلاصه:</b> خروج از صفحه: <?php echo tr_num($attempt['exit_count']??0,'fa'); ?> بار - جابجایی تب: <?php echo tr_num($attempt['tab_switch_count']??0,'fa'); ?> بار - تلاش کپی: <?php echo tr_num($attempt['copy_attempts']??0,'fa'); ?> بار - موقعیت ثبت شده: <?php echo clean($attempt['geo_lat'] ? $attempt['geo_lat'].','.$attempt['geo_lng'] : 'نامشخص (IP)'); ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if(!$canShowDetails): ?>
        <div class="card text-center py-12"><p class="text-muted">نمایش جزئیات پاسخ‌ها توسط طراح آزمون غیرفعال شده است.</p><p class="text-xs mt-2">نمره شما: <?php echo tr_num($attempt['score'],'fa'); ?></p></div>
    <?php else: ?>
        <div class="space-y-4">
        <?php foreach($questions as $idx=>$q): $ans = $answers[$q['id']] ?? null; $qd=json_decode($q['question_data'], true)?:[]; ?>
            <div class="card">
                <div class="flex justify-between"><span class="font-bold">سوال <?php echo tr_num($idx+1,'fa'); ?> - <?php echo online_question_types()[$q['question_type']]['label']??$q['question_type']; ?></span><span class="badge <?php echo $ans && $ans['is_correct'] ? 'badge-success' : ($ans && $ans['is_correct']===null?'badge-secondary':'badge-danger'); ?>"><?php echo $ans ? tr_num($ans['points_earned'],'fa').' / '.tr_num($q['points'],'fa') : 'بدون پاسخ'; ?></span></div>
                <div class="mt-2 text-sm"><?php echo $q['question_text']; ?></div>
                <?php if($ans): $ad=json_decode($ans['answer_data'], true)?:[]; ?>
                    <div class="mt-3 p-2 bg-slate-50 rounded text-sm border">
                        <b>پاسخ شما:</b>
                        <?php if(!empty($ad['selected'])): ?>
                            <?php if(is_array($ad['selected'])): echo clean(implode('، ', $ad['selected'])); else: echo clean($ad['selected']); endif; ?>
                        <?php elseif(!empty($ad['value'])): echo nl2br(clean($ad['value'])); ?>
                        <?php elseif(!empty($ad['blanks'])): foreach($ad['blanks'] as $k=>$v) echo 'خالی '.($k+1).': '.clean($v).'<br>'; ?>
                        <?php elseif(!empty($ad['matches'])): foreach($ad['matches'] as $k=>$v) echo 'تطبیق '.($k+1).': '.clean($v).'<br>'; ?>
                        <?php elseif(!empty($ad['file_path'])): ?><a href="<?php echo clean($ad['file_path']); ?>" target="_blank" class="btn btn-secondary text-xs">📎 مشاهده فایل <?php echo clean($ad['file_name']??''); ?></a><?php endif; ?>
                        <?php if(empty($ad)): echo 'بدون پاسخ'; endif; ?>
                    </div>
                    <?php if(!empty($qd['options'])): ?>
                        <div class="mt-2 text-xs"><b>گزینه‌های صحیح:</b> <?php foreach($qd['options'] as $opt) if(!empty($opt['is_correct'])) echo '✅ '.clean($opt['text']).' '; ?></div>
                    <?php endif; ?>
                    <?php if(!empty($qd['correct_answer'])): ?><div class="mt-1 text-xs"><b>پاسخ صحیح:</b> <?php echo clean($qd['correct_answer']); ?></div><?php endif; ?>
                <?php else: ?>
                    <div class="mt-2 text-xs text-muted">پاسخی ثبت نشده</div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="flex gap-2">
        <?php if(!empty($_SESSION['student_id'])): ?><a href="student-online-exams.php" class="btn btn-secondary">لیست آزمون‌های من</a><?php endif; ?>
        <?php if(!empty($_SESSION['teacher_id']) || !empty($_SESSION['admin_id'])): ?><a href="online-exam-results.php?exam_id=<?php echo $attempt['exam_id']; ?>" class="btn btn-primary">همه نتایج این آزمون</a><a href="online-exam-monitor.php?exam_id=<?php echo $attempt['exam_id']; ?>" class="btn btn-warning">مانیتورینگ</a><?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
