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

/* v4.129.0 — گزارش پایش به دو شکل متفاوت نشان داده می‌شود:
     دانش‌آموز : فقط یک جملهٔ خلاصه (بدون IP، بدون مختصات، بدون فهرست رویداد،
                بدون کد خام لاتین).
     کادر مدرسه: زمان‌بندی رویدادها، ولی فقط متن فارسی — کد خام، IP و مختصات
                اینجا تکرار نمی‌شود. */
$isStudentViewer = !empty($_SESSION['student_id']) && empty($_SESSION['admin_id']) && empty($_SESSION['teacher_id']);
$studentSummary  = online_proctoring_student_summary($attempt);

require_once __DIR__ . '/includes/em_icons.php';
$pageCss = 'assets/css/exam-ui.css';
require_once __DIR__ . '/includes/header.php';
?>
<div class="max-w-5xl mx-auto space-y-4">
    <div class="card bg-gradient-to-l from-indigo-600 to-purple-700 text-white">
        <h2 class="text-lg sm:text-xl font-bold">نتیجهٔ آزمون: <?php echo clean($attempt['title']); ?></h2>
        <p class="text-sm opacity-90"><?php echo clean($attempt['first_name'].' '.$attempt['last_name']); ?> · <?php echo clean($attempt['class_name']); ?></p>
        <div class="flex gap-x-5 gap-y-2 mt-3 text-sm flex-wrap">
            <span>نمره: <b class="em-num"><?php echo tr_num($attempt['score'],'fa'); ?> / <?php echo tr_num($attempt['max_score'],'fa'); ?></b></span>
            <span>درصد: <b class="em-num"><?php echo $attempt['max_score']>0? tr_num(round($attempt['score']/$attempt['max_score']*100,1),'fa').'%':'—'; ?></b></span>
            <?php if ((float)$attempt['passing_score'] > 0): ?>
            <span>وضعیت: <b><?php echo $attempt['score'] >= (float)$attempt['passing_score'] ? 'قبول' : 'مردود'; ?></b></span>
            <?php endif; ?>
            <span>زمان ارسال: <span class="em-num"><?php echo tr_num($attempt['submitted_at']??$attempt['end_time'],'fa'); ?></span></span>
            <?php /* v4.129.0: IP دادهٔ فنی پایش است؛ به دانش‌آموز نشان داده نمی‌شود. */ ?>
            <?php if (!$isStudentViewer): ?>
            <span>IP: <span class="em-num"><?php echo clean($attempt['ip_address']??''); ?></span></span>
            <span>خروج: <span class="em-num"><?php echo tr_num($attempt['exit_count']??0,'fa'); ?></span> · تب: <span class="em-num"><?php echo tr_num($attempt['tab_switch_count']??0,'fa'); ?></span> · کپی: <span class="em-num"><?php echo tr_num($attempt['copy_attempts']??0,'fa'); ?></span></span>
            <?php endif; ?>
        </div>
    </div>

    <!-- v4.129.0 — گزارش پایش -->
    <div class="card">
        <h3 class="font-bold mb-3 flex items-center gap-2"><?php em_icon('shield'); ?> گزارش اقدامات شما در آزمون</h3>

        <?php if ($isStudentViewer): ?>
            <?php /* دانش‌آموز: فقط یک جمله. بدون فهرست رویداد، بدون IP،
                     بدون مختصات، بدون کد خامِ لاتین. */ ?>
            <div class="flex items-start gap-2.5 p-3 rounded-lg"
                 style="background:<?php echo ($studentSummary['exit']+$studentSummary['tab']+$studentSummary['copy']) > 0 ? 'rgba(245,158,11,.08)' : 'rgba(16,185,129,.08)'; ?>">
                <span style="color:<?php echo ($studentSummary['exit']+$studentSummary['tab']+$studentSummary['copy']) > 0 ? '#b45309' : '#047857'; ?>">
                    <?php em_icon(($studentSummary['exit']+$studentSummary['tab']+$studentSummary['copy']) > 0 ? 'info' : 'check', 'ic ic-lg'); ?>
                </span>
                <p class="text-sm leading-7"><?php echo clean($studentSummary['text']); ?></p>
            </div>
            <p class="em-hint mt-2">این موارد به‌صورت خودکار ثبت و برای کادر مدرسه قابل بررسی است.</p>
        <?php else: ?>
            <?php /* کادر مدرسه: زمان‌بندی رویدادها — فقط متن فارسی.
                     کد خامِ event_type، IP و مختصات اینجا نمی‌آید. */ ?>
            <?php if (empty($proctorLogs)): ?>
                <span class="em-state is-ok"><?php em_icon('check'); ?> رویدادی ثبت نشده است</span>
            <?php else: ?>
                <div class="max-h-[420px] overflow-auto em-scroll-x">
                    <?php foreach ($proctorLogs as $log): ?>
                        <div class="em-log is-<?php echo online_proctoring_event_level($log['event_type']); ?>">
                            <span class="em-log__t"><?php echo tr_num($log['created_at'], 'fa'); ?></span>
                            <?php /* v4.129.0: فقط متن فارسی رویداد.
                                   event_data متن آزادِ سمت کلاینت است (mode=high،
                                   voice_id=123، Ctrl+C، متن خطای مرورگر) و می‌تواند
                                   کد خام یا دادهٔ فنی داشته باشد؛ عمداً نشان داده
                                   نمی‌شود. دادهٔ خام در دیتابیس باقی است. */ ?>
                            <span class="em-log__x">
                                <span class="em-log__k"><?php echo clean(online_proctoring_event_label($log['event_type'])); ?></span>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <hr class="em-sep">
                <p class="em-hint"><b>خلاصه:</b>
                    خروج <span class="em-num"><?php echo tr_num($attempt['exit_count']??0,'fa'); ?></span> ·
                    جابجایی تب <span class="em-num"><?php echo tr_num($attempt['tab_switch_count']??0,'fa'); ?></span> ·
                    تلاش کپی <span class="em-num"><?php echo tr_num($attempt['copy_attempts']??0,'fa'); ?></span>
                    <?php if (!empty($attempt['geo_lat'])): ?>
                    · موقعیت <span class="em-num"><?php echo clean($attempt['geo_lat'].', '.$attempt['geo_lng']); ?></span>
                    <?php else: ?>
                    · موقعیت از روی IP
                    <?php endif; ?>
                </p>
            <?php endif; ?>
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
                        <?php elseif(!empty($ad['file_path'])): ?><a href="<?php echo clean($ad['file_path']); ?>" target="_blank" class="btn btn-secondary text-xs">مشاهده فایل <?php echo clean($ad['file_name']??''); ?></a><?php endif; ?>
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
