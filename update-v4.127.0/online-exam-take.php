<?php
// File: online-exam-take.php - Student exam taking with anti-cheat (v4.28.1 fix)
// Fixes: timer starts after permission, location timeout robust
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/online_exam_helpers.php';
require_student();
ensure_online_exams_schema();

/* v4.126.0: شناسه‌ها از «هر» منبع موجود خوانده می‌شوند — GET یا POST.
   اگر فرمی بدون query string ارسال شود (رفرش، تاریخچه مرورگر، bookmark،
   بازگردانی صفحه در موبایل) دانش‌آموز دیگر از آزمون بیرون نمی‌افتد. */
$examId    = (int)($_REQUEST['exam_id']    ?? $_GET['exam_id']    ?? 0);
$attemptId = (int)($_REQUEST['attempt_id'] ?? $_GET['attempt_id'] ?? 0);

if ($examId<=0 && $attemptId>0) {
    $att = DB::fetch("SELECT exam_id FROM online_exam_attempts WHERE id=? AND student_id=?", [$attemptId, $_SESSION['student_id']]);
    if ($att) $examId = (int)$att['exam_id'];
}

/* v4.126.0: اگر attempt_id به attempt واقعیِ همین دانش‌آموز اشاره نمی‌کند
   (لینک کهنه، id حدس‌زده، attempt حذف‌شده) دور ریخته می‌شود تا شناسه بی‌اعتبار
   در فرم و در بازیابی نشت نکند. */
if ($attemptId > 0 && !DB::fetch("SELECT id FROM online_exam_attempts WHERE id=? AND student_id=?", [$attemptId, $_SESSION['student_id']])) {
    $attemptId = 0;
}

/* v4.126.0 بازیابی ۱: آخرین آزمونی که همین دانش‌آموز باز کرده بود (از نشست) */
if ($examId<=0 && !empty($_SESSION['online_exam_current']['exam_id'])) {
    $cand = (int)$_SESSION['online_exam_current']['exam_id'];
    if (DB::fetch("SELECT id FROM online_exams WHERE id=?", [$cand])) {
        $examId = $cand;
        if ($attemptId<=0) $attemptId = (int)($_SESSION['online_exam_current']['attempt_id'] ?? 0);
    }
}

/* v4.126.0 بازیابی ۲: attempt در جریان دانش‌آموز (خروج و ورود مجدد بدون لینک) */
if ($examId<=0) {
    $live = DB::fetch("SELECT id, exam_id FROM online_exam_attempts WHERE student_id=? AND status='in_progress' ORDER BY id DESC LIMIT 1", [$_SESSION['student_id']]);
    if ($live) { $attemptId = (int)$live['id']; $examId = (int)$live['exam_id']; }
}

if ($examId<=0) {
    /* v4.126.0: بن‌بست «آزمون نامعتبر» حذف شد. دانش‌آموز به فهرست آزمون‌های
       خودش می‌رود — صفحه‌ای که هدر و منو دارد و می‌تواند از آن ادامه دهد. */
    set_flash_message('warning', 'آزمون در حال اجرایی برای ادامه پیدا نشد. لطفاً از فهرست «آزمون‌های مجازی من» آزمون مورد نظر را انتخاب کنید.');
    redirect('student-online-exams.php');
}

$exam = DB::fetch("SELECT * FROM online_exams WHERE id=?", [$examId]);
$student = DB::fetch("SELECT * FROM students WHERE id=?", [$_SESSION['student_id']]);
if (!$exam || !$student) { set_flash_message('error','اطلاعات یافت نشد'); redirect('student-panel.php'); }

list($canTake, $reason) = online_exam_can_student_take($exam, $student);

$existingAttempt = null;
if ($attemptId>0) {
    $existingAttempt = DB::fetch("SELECT * FROM online_exam_attempts WHERE id=? AND student_id=? AND exam_id=?", [$attemptId, $_SESSION['student_id'], $examId]);
} else {
    $existingAttempt = DB::fetch("SELECT * FROM online_exam_attempts WHERE exam_id=? AND student_id=? AND status='in_progress' ORDER BY id DESC LIMIT 1", [$examId, $_SESSION['student_id']]);
}

if ($existingAttempt) {
    $attemptId = (int)$existingAttempt['id'];
    /* v4.126.0: attempt ارسال‌شده/اتمام‌یافته هرگز دوباره به شکل برگه آزمون
       باز نمی‌شود — مستقیم به صفحه نتیجه می‌رود (قبلاً برگه آزمون دوباره رندر
       می‌شد و انتخاب گزینه هیچ اثری نداشت، که برای دانش‌آموز گیج‌کننده بود). */
    if (in_array($existingAttempt['status'], ['submitted', 'auto_submitted'], true)) {
        redirect('online-exam-result.php?attempt_id=' . $attemptId);
    }
    // If attempt already has timer_started flag? We use start_time as real start after permission. If attempt exists but timer not yet started (start_time is recent creation), we will reset on frontend after permission.
    $canTake = true;
    /* v4.119.0: انقضا فقط بر اساس first_started_at (شروع واقعی تایمر) سنجیده می‌شود.
       start_time هنگام ساخت attempt ست می‌شود و ممکن است دقایق قبل از شروع واقعی باشد. */
    $duration = (int)$exam['duration_minutes'] * 60;
    $timerStartedReal = !empty($existingAttempt['is_timer_started']) && !empty($existingAttempt['first_started_at']);
    if ($timerStartedReal && $existingAttempt['status']=='in_progress') {
        $firstStart = strtotime($existingAttempt['first_started_at']);
        if (time() - $firstStart > $duration + 30) { /* ۳۰ ثانیه گریس شبکه */
            /* v4.119.0: انقضای سمت سرور = ثبت خودکار پاسخ‌های ذخیره‌شده (نه expire خالی) */
            online_exam_finalize_attempt($attemptId, 'auto_submitted');
            set_flash_message('error','زمان آزمون به پایان رسیده — پاسخ‌های ذخیره‌شده شما ثبت شد');
            redirect('online-exam-result.php?attempt_id='.$attemptId);
        }
    }
} else {
    if (!$canTake) { set_flash_message('error',$reason); redirect('student-panel.php'); }
    $attemptNumber = 1;
    $cnt = DB::fetch("SELECT COUNT(*) c FROM online_exam_attempts WHERE exam_id=? AND student_id=?", [$examId, $_SESSION['student_id']]);
    $attemptNumber = (int)($cnt['c']??0)+1;
    // Create attempt with start_time = NOW but will be RESET after permission checks (per fix)
    DB::execute("INSERT INTO online_exam_attempts (exam_id, student_id, attempt_number, start_time, status, ip_address, user_agent) VALUES (?,?,?,?,?, ?, ?)", [$examId, $_SESSION['student_id'], $attemptNumber, date('Y-m-d H:i:s'), 'in_progress', $_SERVER['REMOTE_ADDR']??'', $_SERVER['HTTP_USER_AGENT']??'']);
    $attemptId = DB::lastInsertId();
    $existingAttempt = DB::fetch("SELECT * FROM online_exam_attempts WHERE id=?", [$attemptId]);
}

$questions = DB::fetchAll("SELECT * FROM online_questions WHERE exam_id=? ORDER BY order_index ASC, id ASC", [$examId]);
/* v4.119.0: ترتیب تصادفی «پایدار» بر اساس attempt — با رفرش/ورود مجدد ترتیب سوالات عوض نمی‌شود */
if (!empty($exam['randomize_questions'])) { mt_srand($attemptId * 7919 + $examId); shuffle($questions); mt_srand(); }

$settings = $exam['settings_json'] ? json_decode($exam['settings_json'], true) : [];
$proctor = $settings['proctoring'] ?? [];
$display = $settings['display'] ?? [];

$maxScore = 0; foreach($questions as $q) if($q['question_type']!=='info') $maxScore+=(float)$q['points'];

// Remaining time - use first_started_at if available (timer started), otherwise full duration until permission
$effectiveStart = $existingAttempt['first_started_at'] ?? $existingAttempt['start_time'] ?? null;
$isTimerStarted = !empty($existingAttempt['is_timer_started']) && !empty($existingAttempt['first_started_at']);
if ($isTimerStarted && $effectiveStart) {
    $startTs = strtotime($effectiveStart);
    $endTs = $startTs + (int)$exam['duration_minutes']*60;
    $remaining = $endTs - time();
    if ($remaining <0) $remaining = 0;
} else {
    // Not started yet - show full duration, timer will start after permission stage
    $remaining = (int)$exam['duration_minutes']*60;
}
$qAnswered = DB::fetch("SELECT COUNT(*) c FROM online_exam_answers WHERE attempt_id=?", [$attemptId]);

$watermarkText = $exam['watermark_text'] ?: 'آزمون هوشمند - نظارت فعال - شناسه: '.$student['national_id'].' - آزمون:'.$examId.' - تقلب ممنوع';

/* v4.126.0: «آزمون جاری» در نشست ثبت می‌شود تا اگر درخواست بعدی بدون
   شناسه رسید (رفرش/تاریخچه/موبایل)، خودکار به همین آزمون برگردیم. */
$_SESSION['online_exam_current'] = ['exam_id' => (int)$examId, 'attempt_id' => (int)$attemptId];

require_once __DIR__ . '/includes/header.php';
?>
<style>
/* ============ v4.120.0: رابط مدرن آزمون آنلاین — سبک، بدون CDN ============ */
:root{--ex-brand:#4f46e5;--ex-brand2:#4338ca;--ex-ok:#10b981;--ex-warn:#f59e0b;--ex-bad:#ef4444;--ex-ink:#0f172a;--ex-mut:#64748b;--ex-line:#e2e8f0;--ex-bg:#f4f6fb}
.anti-copy { user-select:none; -webkit-user-select:none; -moz-user-select:none; -ms-user-select:none; }
.watermark-overlay { position:fixed; top:0; left:0; width:100%; height:100%; pointer-events:none; z-index:999; opacity:0.03; background-repeat:repeat; overflow:hidden; }
.watermark-text { position:absolute; font-size:14px; color:#000; transform:rotate(-30deg); white-space:nowrap; opacity:0.8; }
#webcamPreview { position:fixed; bottom:15px; left:15px; width:150px; height:112px; background:#000; border:2px solid var(--ex-bad); border-radius:14px; overflow:hidden; z-index:1000; box-shadow:0 8px 24px rgba(0,0,0,.35); }
#webcamPreview video { width:100%; height:100%; object-fit:cover; }
#webcamPreview .label { position:absolute; top:3px; left:3px; background:rgba(239,68,68,.92); color:white; font-size:9px; padding:2px 6px; border-radius:6px; }
/* ---- نوار وضعیت چسبان بالای آزمون ---- */
.exam-topbar{position:sticky;top:0;z-index:900;background:rgba(255,255,255,.94);backdrop-filter:blur(10px);border-bottom:1px solid var(--ex-line);border-radius:0 0 16px 16px;box-shadow:0 4px 18px rgba(15,23,42,.06);padding:10px 16px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.exam-topbar .ex-title{font-weight:800;font-size:15px;color:var(--ex-ink);flex:1;min-width:160px}
.ex-chip{display:inline-flex;align-items:center;gap:5px;background:#eef2ff;color:var(--ex-brand2);border-radius:999px;padding:3px 11px;font-size:11.5px;font-weight:700;white-space:nowrap}
.timer-pill{display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#4f46e5,#6366f1);color:#fff;padding:7px 16px;border-radius:999px;font-weight:800;font-size:15px;font-variant-numeric:tabular-nums;box-shadow:0 4px 14px rgba(79,70,229,.35);transition:background .4s}
.timer-pill.t-warn{background:linear-gradient(135deg,#f59e0b,#f97316);box-shadow:0 4px 14px rgba(245,158,11,.4)}
.timer-pill.t-danger{background:linear-gradient(135deg,#dc2626,#ef4444);box-shadow:0 4px 14px rgba(220,38,38,.45);animation:exPulse 1s infinite}
@keyframes exPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.05)}}
.ex-progress{width:100%;height:7px;background:#e8ecf7;border-radius:999px;overflow:hidden;margin-top:8px}
.ex-progress>div{height:100%;background:linear-gradient(90deg,#4f46e5,#818cf8);border-radius:999px;transition:width .4s;box-shadow:0 0 8px rgba(79,70,229,.4)}
.ex-progress-label{font-size:10.5px;color:var(--ex-mut);margin-top:3px;text-align:left}
/* ---- کارت سوال مدرن ---- */
.question-box{border:1px solid var(--ex-line);border-radius:16px;padding:0;background:#fff;margin-bottom:18px;overflow:hidden;box-shadow:0 2px 10px rgba(15,23,42,.04);transition:box-shadow .2s,border-color .2s;scroll-margin-top:90px}
.question-box:focus-within{border-color:#c7d2fe;box-shadow:0 6px 24px rgba(79,70,229,.10)}
.question-box.q-answered{border-color:#bbf7d0}
.q-head{display:flex;justify-content:space-between;align-items:center;gap:8px;background:linear-gradient(135deg,#f8faff,#eef2ff);border-bottom:1px solid #e5e9f8;padding:10px 16px}
.q-num-badge{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:30px;border-radius:10px;background:var(--ex-brand);color:#fff;font-weight:800;font-size:13px;padding:0 8px}
.q-points{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:999px;padding:1px 10px;font-size:10.5px;font-weight:700;white-space:nowrap}
.q-type-tag{color:var(--ex-mut);font-size:10.5px;white-space:nowrap}
.q-done-tick{width:22px;height:22px;border-radius:50%;display:none;align-items:center;justify-content:center;background:var(--ex-ok);color:#fff;font-size:12px;flex:none}
.q-answered .q-done-tick{display:inline-flex}
.q-body{padding:14px 16px}
/* ---- گزینه‌ها: کارت‌های لمس‌پذیر بزرگ ---- */
.answer-area label.flex{border:1.5px solid var(--ex-line)!important;border-radius:12px!important;padding:11px 14px!important;margin-bottom:8px!important;transition:all .15s;align-items:center;background:#fff;min-height:46px}
.answer-area label.flex:hover{border-color:#a5b4fc!important;background:#f5f7ff!important}
.answer-area label.flex:has(input:checked){border-color:var(--ex-brand)!important;background:#eef2ff!important;box-shadow:0 2px 8px rgba(79,70,229,.12)}
.answer-area input[type=radio],.answer-area input[type=checkbox]{width:18px;height:18px;accent-color:var(--ex-brand);flex:none}
.answer-area textarea,.answer-area input[type=text],.answer-area select{border:1.5px solid var(--ex-line);border-radius:12px;padding:10px 12px;font-family:inherit;font-size:14px;transition:border-color .15s,box-shadow .15s;width:100%}
.answer-area textarea:focus,.answer-area input[type=text]:focus,.answer-area select:focus{outline:none;border-color:var(--ex-brand);box-shadow:0 0 0 3px rgba(79,70,229,.12)}
/* ---- ناوبری سوالات (نقشه) ---- */
.q-map{position:fixed;bottom:15px;right:15px;z-index:950;background:rgba(255,255,255,.96);backdrop-filter:blur(8px);border:1px solid var(--ex-line);border-radius:14px;box-shadow:0 8px 28px rgba(15,23,42,.14);padding:9px;max-width:226px}
.q-map-title{font-size:10px;color:var(--ex-mut);margin-bottom:6px;font-weight:700}
.q-map-grid{display:flex;flex-wrap:wrap;gap:4px;max-height:120px;overflow:auto}
.q-map-btn{width:30px;height:30px;border-radius:9px;border:1.5px solid var(--ex-line);background:#fff;font-size:11px;font-weight:700;cursor:pointer;color:var(--ex-ink);transition:all .12s;font-family:inherit}
.q-map-btn:hover{border-color:var(--ex-brand);color:var(--ex-brand)}
.q-map-btn.answered{background:var(--ex-ok);border-color:var(--ex-ok);color:#fff}
/* ---- ذخیره خودکار ---- */
.autosave-dot{display:inline-flex;align-items:center;gap:5px;font-size:10.5px;color:var(--ex-mut)}
.autosave-dot i{width:8px;height:8px;border-radius:50%;background:#cbd5e1;display:inline-block;transition:background .3s}
.autosave-dot.saving i{background:var(--ex-warn);animation:exPulse .6s infinite}
.autosave-dot.saved i{background:var(--ex-ok)}
/* ---- دکمه ارسال نهایی ---- */
.submit-hero{background:linear-gradient(135deg,#059669,#10b981);color:#fff;border:0;border-radius:14px;padding:14px 46px;font-size:16px;font-weight:800;font-family:inherit;cursor:pointer;box-shadow:0 8px 24px rgba(16,185,129,.35);transition:transform .15s,box-shadow .15s}
.submit-hero:hover{transform:translateY(-2px);box-shadow:0 12px 32px rgba(16,185,129,.45)}
/* ---- مرحله مجوزها ---- */
.perm-card{border:1.5px solid var(--ex-line);border-radius:14px;padding:13px 16px;background:#fff;transition:all .2s}
.perm-card.ok{border-color:#86efac;background:#f0fdf4}
.perm-card.warn{border-color:#fcd34d;background:#fffbeb}
@media (max-width:640px){.q-map{max-width:160px}.q-map-grid{max-height:88px}.exam-topbar{padding:8px 10px;gap:8px}.timer-pill{font-size:13px;padding:6px 12px}}
@media print { body { display:none; } }
</style>

<div class="watermark-overlay" id="watermarkOverlay"></div>
<div id="webcamPreview" class="hidden"><span class="label" style="cursor:move;">🔴 وب‌کم فعال - قابل جابجایی</span><video id="webcamVideo" autoplay muted playsinline></video><canvas id="webcamCanvas" class="hidden"></canvas><div style="position:absolute;bottom:2px;right:2px;background:rgba(0,0,0,0.6);color:white;font-size:8px;padding:1px 3px;border-radius:3px;">بکشید برای جابجایی</div></div>
<?php /* v4.120.0: تایمر داخل نوار چسبان بالای آزمون قرار گرفت */ ?>

<div class="max-w-5xl mx-auto space-y-4 anti-copy" id="examContainer">
    <div id="permissionStage" class="card space-y-4">
      <?php if (!empty($existingAttempt['is_timer_started']) && !empty($existingAttempt['first_started_at'])): ?>
      <div style="background:#fef3c7;border:1px solid #fbbf24;border-radius:10px;padding:10px 14px;color:#92400e;font-weight:700">
        ⏱ شما قبلاً این آزمون را شروع کرده‌اید — زمان شما از همان لحظه شروع در حال محاسبه است و با خروج/ورود ریست نمی‌شود.
        زمان باقیمانده: <?php echo gmdate('H:i:s', $remaining); ?>
      </div>
      <?php endif; ?>
        <h2 class="text-lg font-bold">بررسی دسترسی‌های آزمون: <?php echo clean($exam['title']); ?></h2>
        <p class="text-sm text-muted">برای شرکت در آزمون، باید دسترسی‌های زیر را تایید کنید. تایمر آزمون <b>بعد از تایید دسترسی‌ها</b> شروع می‌شود.</p>
        
        <div class="space-y-3" id="permissionChecks">
            <div class="flex justify-between items-center p-3 border rounded" id="check_camera"><span>دوربین (وب‌کم)</span><span class="status">در انتظار درخواست...</span><button class="btn btn-secondary text-xs" onclick="checkCamera()">بررسی</button></div>
            <div class="flex justify-between items-center p-3 border rounded" id="check_mic"><span>میکروفون</span><span class="status">در انتظار درخواست...</span><button class="btn btn-secondary text-xs" onclick="checkMic()">بررسی</button></div>
            <div class="flex justify-between items-center p-3 border rounded" id="check_location">
                <div><span>موقعیت مکانی</span><div class="text-[11px] text-muted" id="locationDetail"></div></div>
                <span class="status">در انتظار...</span>
            </div>
            <div class="flex justify-between items-center p-3 border rounded" id="check_internet"><span>کیفیت اینترنت</span><span class="status">در حال بررسی...</span></div>
        </div>

        <div class="flex gap-2">
            <button onclick="requestAllPermissions()" class="btn btn-primary">درخواست همه دسترسی‌ها</button>
            <button id="startExamBtn" onclick="startExamAfterChecks()" class="btn btn-success hidden">تایید و شروع آزمون (تایمر از حالا شروع می‌شود)</button>
        </div>
        <div id="permissionError" class="p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700 hidden"></div>
        
    </div>

    <div id="examStage" class="hidden space-y-4">
        <?php /* v4.120.0: نوار وضعیت چسبان — همیشه جلوی چشم دانش‌آموز */ ?>
        <div class="exam-topbar">
            <span class="ex-title"><?php echo clean($exam['title']); ?></span>
            <span class="ex-chip">📝 <?php echo tr_num(count($questions),'fa'); ?> سوال</span>
            <span class="ex-chip">🎯 <?php echo tr_num($maxScore,'fa'); ?> نمره</span>
            <span class="autosave-dot" id="autosaveDot"><i></i><span id="autosaveText">ذخیره خودکار فعال</span></span>
            <span class="timer-pill" id="timerDisplay">⏱ <?php echo gmdate('H:i:s', $remaining); ?></span>
        </div>
        <div>
            <div class="ex-progress"><div id="progressBar" style="width:0%"></div></div>
            <div class="ex-progress-label" id="progressLabel">پاسخ‌داده: ۰ از <?php echo tr_num(count($questions),'fa'); ?></div>
        </div>
        <?php if(!empty($exam['description'])): ?><p class="text-xs text-muted"><?php echo clean($exam['description']); ?></p><?php endif; ?>

        <?php /* v4.126.0: action صریح + شناسه‌های پنهان — اگر به هر دلیلی فرم
               submit شود (Enter، مرورگر قدیمی، بازگردانی فرم) همان آزمون با همان
               شناسه‌ها باز می‌شود و هرگز «آزمون نامعتبر» رخ نمی‌دهد. */ ?>
        <form id="examForm" method="post" action="online-exam-take.php?exam_id=<?php echo (int)$examId; ?>&amp;attempt_id=<?php echo (int)$attemptId; ?>" onsubmit="return false;">
        <input type="hidden" name="exam_id" value="<?php echo (int)$examId; ?>">
        <input type="hidden" name="attempt_id" value="<?php echo (int)$attemptId; ?>">
        <?php foreach($questions as $idx=>$q): $qd=json_decode($q['question_data'], true) ?: []; $qNum=$idx+1; ?>
            <div class="question-box" data-qid="<?php echo $q['id']; ?>" id="qbox_<?php echo $q['id']; ?>">
                <div class="q-head">
                    <div class="flex items-center gap-2">
                        <span class="q-num-badge"><?php echo tr_num($qNum,'fa'); ?></span>
                        <span class="q-points"><?php echo tr_num($q['points'],'fa'); ?> نمره</span>
                        <span class="q-type-tag"><?php echo online_question_types()[$q['question_type']]['label'] ?? $q['question_type']; ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-muted"><?php echo tr_num($qNum,'fa'); ?>/<?php echo tr_num(count($questions),'fa'); ?></span>
                        <span class="q-done-tick">✓</span>
                    </div>
                </div>
                <div class="q-body">
                <div class="prose max-w-none text-sm mb-3"><?php echo $q['question_text']; ?>
                    <?php if(!empty($qd['media']['image'])): ?><div class="mt-2"><img src="<?php echo clean($qd['media']['image']); ?>" class="max-w-full rounded border" style="max-height:400px;"></div><?php endif; ?>
                    <?php if(!empty($qd['media']['video'])): ?><div class="mt-2"><video controls src="<?php echo clean($qd['media']['video']); ?>" class="max-w-full rounded" style="max-height:400px;"></video></div><?php endif; ?>
                    <?php if(!empty($qd['media']['audio'])): ?><div class="mt-2"><audio controls src="<?php echo clean($qd['media']['audio']); ?>" class="w-full"></audio></div><?php endif; ?>
                </div>

                <div class="answer-area">
                <?php if(in_array($q['question_type'], ['radio'])): $opts = $qd['options'] ?? []; if(!empty($exam['randomize_answers'])) { mt_srand($attemptId * 31 + (int)$q['id']); shuffle($opts); mt_srand(); } ?>
                    <?php foreach($opts as $opt): ?>
                    <label class="flex gap-2 p-2 border rounded hover:bg-slate-50 cursor-pointer mb-1"><input type="radio" name="q_<?php echo $q['id']; ?>" value="<?php echo clean($opt['id']); ?>" onchange="saveAnswer(<?php echo $q['id']; ?>)"> <span class="text-sm"><?php echo clean($opt['text']); ?></span></label>
                    <?php endforeach; ?>
                <?php elseif($q['question_type']==='checkbox'): $opts=$qd['options']??[]; if(!empty($exam['randomize_answers'])) { mt_srand($attemptId * 37 + (int)$q['id']); shuffle($opts); mt_srand(); } ?>
                    <?php foreach($opts as $opt): ?>
                    <label class="flex gap-2 p-2 border rounded hover:bg-slate-50 cursor-pointer mb-1"><input type="checkbox" name="q_<?php echo $q['id']; ?>[]" value="<?php echo clean($opt['id']); ?>" onchange="saveAnswer(<?php echo $q['id']; ?>)"> <span class="text-sm"><?php echo clean($opt['text']); ?></span></label>
                    <?php endforeach; ?>
                <?php elseif($q['question_type']==='dropdown'): ?>
                    <select name="q_<?php echo $q['id']; ?>" class="form-select" onchange="saveAnswer(<?php echo $q['id']; ?>)"><option value="">انتخاب کنید</option><?php foreach(($qd['options']??[]) as $opt): ?><option value="<?php echo clean($opt['id']); ?>"><?php echo clean($opt['text']); ?></option><?php endforeach; ?></select>
                <?php elseif(in_array($q['question_type'], ['short_text','text'])): ?>
                    <?php if($q['question_type']==='short_text'): ?><input type="text" name="q_<?php echo $q['id']; ?>" class="form-input" placeholder="پاسخ کوتاه" oninput="saveAnswer(<?php echo $q['id']; ?>)"><?php else: ?><textarea name="q_<?php echo $q['id']; ?>" class="form-input" rows="4" placeholder="پاسخ تشریحی" oninput="saveAnswer(<?php echo $q['id']; ?>)"></textarea><?php endif; ?>
                <?php elseif($q['question_type']==='number'): ?>
                    <input type="number" step="any" name="q_<?php echo $q['id']; ?>" class="form-input" placeholder="عدد" oninput="saveAnswer(<?php echo $q['id']; ?>)">
                <?php elseif($q['question_type']==='info'): ?>
                    <div class="p-3 bg-blue-50 border border-blue-100 rounded text-sm">این بخش فقط اطلاع‌رسانی است و نمره ندارد.</div>
                <?php elseif($q['question_type']==='fill_blank'): ?>
                    <?php $blanks = $qd['blanks']??[]; ?>
                    <p class="text-xs text-muted mb-2">جاهای خالی را پر کنید:</p>
                    <?php foreach($blanks as $bIdx=>$b): ?><div class="flex gap-2 mb-2"><span class="text-xs">خالی <?php echo tr_num($bIdx+1,'fa'); ?>:</span><input type="text" data-blank="<?php echo $bIdx; ?>" class="form-input flex-1 text-sm blank-input" placeholder="پاسخ" oninput="saveAnswer(<?php echo $q['id']; ?>)"></div><?php endforeach; ?>
                <?php elseif($q['question_type']==='matching'): ?>
                    <div class="space-y-2">
                    <?php $pairs = $qd['pairs']??[]; $rights = array_column($qd['pairs']??[], 'right'); mt_srand($attemptId * 41 + (int)$q['id']); shuffle($rights); mt_srand(); ?>
                    <?php foreach($pairs as $pIdx=>$p): ?>
                    <div class="grid grid-cols-2 gap-2 items-center border p-2 rounded"><div class="text-sm font-bold"><?php echo clean($p['left']); ?></div><select class="form-select text-xs matching-select" data-index="<?php echo $pIdx; ?>" onchange="saveAnswer(<?php echo $q['id']; ?>)"><option value="">انتخاب تطبیق</option><?php foreach($rights as $rr): ?><option value="<?php echo clean($rr); ?>"><?php echo clean($rr); ?></option><?php endforeach; ?></select></div>
                    <?php endforeach; ?>
                    </div>
                <?php elseif($q['question_type']==='file_upload'): ?>
                    <input type="file" class="form-input text-xs file-input" data-qid="<?php echo $q['id']; ?>" onchange="uploadFileAnswer(this, <?php echo $q['id']; ?>)" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.zip"><div class="text-[11px] text-muted mt-1">فایل انتخابی خودکار ذخیره می‌شود</div><div class="file-status text-xs mt-1"></div>
                <?php elseif($q['question_type']==='voice_upload'): ?>
                    <div class="space-y-2">
                        <div class="flex gap-2"><button type="button" class="btn btn-primary text-xs record-btn" data-qid="<?php echo $q['id']; ?>" onclick="toggleVoiceRecord(this)">شروع ضبط صدا</button><span class="record-status text-xs text-muted">آماده</span></div>
                        <audio class="voice-preview hidden" controls></audio>
                    </div>
                <?php elseif($q['question_type']==='whiteboard'): ?>
                    <div class="space-y-2 whiteboard-wrapper" data-qid="<?php echo $q['id']; ?>">
                        <div class="flex flex-wrap gap-1 items-center p-2 bg-slate-50 rounded border text-xs">
                            <button type="button" class="btn btn-primary text-[10px] tool-btn" data-tool="pen" data-qid="<?php echo $q['id']; ?>" onclick="setWhiteboardTool(<?php echo $q['id']; ?>, 'pen')">قلم</button>
                            <button type="button" class="btn btn-secondary text-[10px] tool-btn" data-tool="eraser" data-qid="<?php echo $q['id']; ?>" onclick="setWhiteboardTool(<?php echo $q['id']; ?>, 'eraser')">پاک‌کن</button>
                            <label class="flex items-center gap-1">رنگ <input type="color" class="color-picker" data-qid="<?php echo $q['id']; ?>" value="#000000" onchange="setWhiteboardColor(<?php echo $q['id']; ?>, this.value)" style="width:24px;height:24px;"></label>
                            <label class="flex items-center gap-1">ضخامت <input type="range" min="1" max="10" value="2" class="width-picker" data-qid="<?php echo $q['id']; ?>" onchange="setWhiteboardWidth(<?php echo $q['id']; ?>, this.value)" style="width:60px;"></label>
                            <button type="button" class="btn btn-secondary text-[10px]" onclick="clearWhiteboard(<?php echo $q['id']; ?>)">پاک کردن کل</button>
                            <button type="button" class="btn btn-secondary text-[10px]" onclick="zoomWhiteboard(<?php echo $q['id']; ?>, 1.2)">+ بزرگنمایی</button>
                            <button type="button" class="btn btn-secondary text-[10px]" onclick="zoomWhiteboard(<?php echo $q['id']; ?>, 0.8)">- کوچکنمایی</button>
                            <button type="button" class="btn btn-secondary text-[10px]" onclick="resetZoomWhiteboard(<?php echo $q['id']; ?>)">اندازه اصلی</button>
                            <button type="button" class="btn btn-success text-[10px]" onclick="saveWhiteboard(<?php echo $q['id']; ?>)">ذخیره نقاشی</button>
                            <span class="text-[10px] text-muted">دقت بالا - لمس دقیق - قابل بزرگنمایی</span>
                        </div>
                        <div class="whiteboard-container" style="overflow:auto;border:1px solid #e2e8f0;border-radius:8px;background:#fff;max-height:500px;position:relative;">
                            <canvas class="whiteboard-canvas border-0 bg-white touch-none" width="800" height="400" data-qid="<?php echo $q['id']; ?>" style="display:block;touch-action:none;cursor:crosshair;"></canvas>
                        </div>
                    </div>
                <?php endif; ?>
                </div>
                </div><?php /* q-body */ ?>
            </div>
        <?php endforeach; ?>
        </form>

        <div class="text-center py-8">
            <button onclick="submitExam()" class="submit-hero">✓ ارسال نهایی آزمون</button>
            <div class="text-xs text-muted mt-3">پیش از ارسال، از نقشه سوالات پایین صفحه مطمئن شوید همه را پاسخ داده‌اید</div>
        </div>
        <?php /* v4.120.0: نقشه سوالات — پرش سریع + وضعیت پاسخ */ ?>
        <div class="q-map" id="qMap">
            <div class="q-map-title">نقشه سوالات <span id="qMapDone" style="color:#10b981"></span></div>
            <div class="q-map-grid" id="qMapGrid">
                <?php foreach($questions as $idx=>$q): if($q['question_type']==='info') continue; ?>
                <button type="button" class="q-map-btn" data-qref="<?php echo $q['id']; ?>" onclick="document.getElementById('qbox_<?php echo $q['id']; ?>').scrollIntoView({behavior:'smooth',block:'start'})"><?php echo tr_num($idx+1,'fa'); ?></button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
const examId = <?php echo $examId; ?>;
const attemptId = <?php echo $attemptId; ?>;
let remainingSeconds = <?php echo $remaining; ?>;
/* v4.123.0: رفع باگ «پرت شدن از آزمون» — قبلاً epoch سرور مستقیم با Date.now() دستگاه
   مقایسه می‌شد؛ اگر ساعت گوشی دانش‌آموز جلوتر از سرور بود، زمان «تمام‌شده» دیده می‌شد و
   بلافاصله alert + ارسال خودکار + پرت شدن به صفحه نتیجه رخ می‌داد (اغلب همزمان با اولین
   تعامل مثل انتخاب گزینه). حالا نقطه شروع از «باقیمانده سرور» مشتق می‌شود و کاملاً
   مستقل از ساعت دستگاه است. */
let firstStartedTimestamp = <?php echo (!empty($existingAttempt['is_timer_started']) && !empty($existingAttempt['first_started_at'])) ? 'Date.now() - ('.((int)$exam['duration_minutes']*60).' - '.(int)$remaining.')*1000' : 'null'; ?>;
let examDurationSeconds = <?php echo (int)$exam['duration_minutes']*60; ?>;
let timerStartedAtClient = Date.now(); // when JS timer started

let webcamStream = null;
let locationWatchId = null;
let permissionState = {camera:false, mic:false, location:false, internet:false};
let heartbeatInterval = null;
let timerInterval = null;
/* v4.119.0: تایمر قبلاً شروع شده؟ (بازگشت به آزمون) — بنر ادامه و ورود سریع */
const timerAlreadyStarted = <?php echo (!empty($existingAttempt['is_timer_started']) && !empty($existingAttempt['first_started_at'])) ? 'true' : 'false'; ?>;
let examStarted = false;

function initWatermark() {
    let overlay = document.getElementById('watermarkOverlay');
    if(!overlay) return;
    let text = <?php echo json_encode($watermarkText, JSON_UNESCAPED_UNICODE); ?>;
    for(let i=0;i<60;i++){
        let span = document.createElement('div');
        span.className='watermark-text';
        span.textContent = text + ' - '+ Math.random().toString(36).substring(2,6);
        span.style.left = (Math.random()*100)+'%';
        span.style.top = (Math.random()*100)+'%';
        span.style.fontSize = (12+Math.random()*6)+'px';
        span.style.opacity = 0.03 + Math.random()*0.04;
        overlay.appendChild(span);
    }
}

async function checkCamera(){
    try {
        let s = await navigator.mediaDevices.getUserMedia({video:true});
        permissionState.camera=true;
        document.querySelector('#check_camera .status').innerHTML='✅ تایید شد';
        document.getElementById('check_camera').classList.add('bg-green-50');
        s.getTracks().forEach(t=>t.stop());
        checkAllPermissionsDone();
    } catch(e){
        document.querySelector('#check_camera .status').innerHTML='❌ '+e.message;
        document.getElementById('check_camera').classList.add('bg-red-50');
        showPermError('دوربین: '+e.message);
    }
}
async function checkMic(){
    try {
        let s = await navigator.mediaDevices.getUserMedia({audio:true});
        permissionState.mic=true;
        document.querySelector('#check_mic .status').innerHTML='✅ تایید شد';
        document.getElementById('check_mic').classList.add('bg-green-50');
        s.getTracks().forEach(t=>t.stop());
        checkAllPermissionsDone();
    } catch(e){
        let msg = e.message || e.name;
        // اگر میکروفون پیدا نشد (دسکتاپ بدون میکروفون) - اجازه ادامه با هشدار
        if(e.name==='NotFoundError' || msg.includes('Requested device not found') || msg.includes('Not found')){
            document.querySelector('#check_mic .status').innerHTML='⚠️ میکروفون یافت نشد (دسکتاپ) - با IP ادامه می‌دهیم';
            document.getElementById('check_mic').classList.add('bg-amber-50');
            // اگر میکروفون الزامی نیست، اجازه ادامه
            let requireMic = <?php echo !empty($proctor['require_mic'])?'true':'false'; ?>;
            if(!requireMic){
                permissionState.mic=true; // treat as ok for non-required
                document.querySelector('#check_mic .status').innerHTML='⚠️ میکروفون ندارد ولی الزامی نیست - ✅ ادامه مجاز';
                checkAllPermissionsDone();
            } else {
                document.querySelector('#check_mic .status').innerHTML='❌ میکروفون الزامی ولی یافت نشد: '+msg+' <button onclick="continueWithoutMic()" class="btn btn-warning text-[10px]">ادامه بدون میکروفون (سازگار)</button>';
                showPermError('میکروفون یافت نشد (دسکتاپ بدون میکروفون). اگر دبیر اجازه دهد، با دکمه ادامه بدون میکروفون ادامه دهید یا در تنظیمات آزمون الزام میکروفون را بردارید');
            }
        } else {
            document.querySelector('#check_mic .status').innerHTML='❌ '+msg;
            showPermError('میکروفون: '+msg);
        }
    }
}
function continueWithoutMic(){
    permissionState.mic=true;
    document.querySelector('#check_mic .status').innerHTML='✅ ادامه بدون میکروفون (سازگار ایران - دسکتاپ)';
    document.getElementById('check_mic').classList.add('bg-amber-50');
    logProctor('mic_not_found_continue', 'ادامه بدون میکروفون - دستگاه دسکتاپ بدون میکروفون');
    checkAllPermissionsDone();
}

async function checkLocationRobust(){
    /* v4.127.0: این مرحله «هرگز» نمی‌تواند بی‌پایان بماند.

       علت باگ دسکتاپ: getCurrentPosition وقتی مجوز هنوز پاسخ داده نشده باشد هیچ
       callback ای صدا نمی‌زند و گزینهٔ timeout هم در آن حالت اعمال نمی‌شود
       (timeout فقط بعد از grant شدن مجوز شروع به شمارش می‌کند). مرورگر موبایل
       دیالوگ مودال نشان می‌دهد و دانش‌آموز پاسخ می‌دهد، ولی مرورگر کامپیوتر
       حباب کوچکی کنار نوار آدرس نشان می‌دهد که در صفحهٔ شلوغ آزمون دیده نمی‌شود.
       نتیجه: await برای همیشه می‌ماند → permissionState.location هیچ‌وقت true
       نمی‌شود → دکمهٔ «شروع آزمون» هرگز ظاهر نمی‌شود و «لطفا صبر کنید» می‌ماند.

       سه لایهٔ حفاظتی:
         ۱) هر تلاش با withTimeout مهار می‌شود (مستقل از رفتار مرورگر)
         ۲) مهلت کل برای مرحلهٔ موقعیت — بعد از آن بی‌صدا به IP می‌رویم
         ۳) واتچ‌داگ مطلق که در هر شرایطی دکمهٔ شروع را ظاهر می‌کند */
    const LOC_TRY_BUDGET = 6000;    // هر تلاش GPS
    const LOC_IP_BUDGET  = 8000;    // هر سرویس IP
    const LOC_TOTAL_MS   = 20000;   // کل مرحلهٔ GPS
    const WATCHDOG_MS    = LOC_TOTAL_MS + (2 * LOC_IP_BUDGET) + 4000;

    let statusEl = document.querySelector('#check_location .status');
    let detailEl = document.getElementById('locationDetail');
    if(statusEl) statusEl.textContent='لطفا صبر کنید…';
    if(detailEl) detailEl.textContent='';

    let finished = false;
    const finishOk = (mode) => {
        if(finished) return;                     // فقط یک‌بار اثر کند
        finished = true;
        clearTimeout(watchdog);
        permissionState.location = true;
        if(statusEl) statusEl.textContent = '✅ تایید شد';
        if(detailEl) detailEl.textContent = '';
        const box = document.getElementById('check_location');
        if(box){ box.classList.add('bg-green-50'); box.classList.remove('bg-red-50','bg-amber-50'); }
        try { logProctor('location_resolved', 'mode='+mode); } catch(e){}
        checkAllPermissionsDone();
    };

    /* مهار هر Promise با مهلت دیوار واقعی — حتی اگر مرورگر هرگز callback نزند */
    const withTimeout = (promise, ms, tag) => new Promise((resolve, reject) => {
        let done = false;
        const t = setTimeout(() => { if(!done){ done=true; reject(new Error('timeout:'+tag)); } }, ms);
        promise.then(
            v => { if(!done){ done=true; clearTimeout(t); resolve(v); } },
            e => { if(!done){ done=true; clearTimeout(t); reject(e); } }
        );
    });

    /* واتچ‌داگ مطلق — آخرین ضامن اینکه دانش‌آموز معطل نمی‌ماند */
    const watchdog = setTimeout(() => {
        try { logProctor('location_watchdog', 'مهلت کل تمام شد — ادامه بدون موقعیت'); } catch(e){}
        finishOk('watchdog');
    }, WATCHDOG_MS);

    const geoUsable = !!navigator.geolocation &&
        (location.protocol==='https:' || location.hostname==='localhost' || location.hostname==='127.0.0.1');

    const tryGetPos = (opts, ms) => withTimeout(new Promise((resolve, reject) => {
        try { navigator.geolocation.getCurrentPosition(resolve, reject, opts); }
        catch(e){ reject(e); }
    }), ms, 'geo');

    if(geoUsable){
        /* وضعیت مجوز را اول می‌پرسیم تا بدانیم چقدر بودجه بدهیم */
        let perm = 'unknown';
        try {
            if(navigator.permissions && navigator.permissions.query){
                const st = await withTimeout(navigator.permissions.query({name:'geolocation'}), 1500, 'perm');
                perm = st && st.state ? st.state : 'unknown';
            }
        } catch(e){ perm = 'unknown'; }

        if(perm === 'denied'){
            /* کاربر قبلاً رد کرده — GPS را کلاً رد می‌کنیم، وقت تلف نمی‌کنیم */
            try { logProctor('location_denied', 'مجوز موقعیت رد شده — موقعیت IP'); } catch(e){}
        } else {
            /* اگر مجوز grant شده: دو تلاش واقعی.
               اگر prompt یا نامعلوم: فقط «یک» تلاش کوتاه — چون ممکن است دانش‌آموز
               حباب مرورگر را نبیند، پس معطلش نمی‌مانیم. */
            const cascade = (perm === 'granted')
                ? [ {enableHighAccuracy:true,  timeout:15000, maximumAge:0},
                    {enableHighAccuracy:false, timeout:10000, maximumAge:60000} ]
                : [ {enableHighAccuracy:false, timeout:6000,  maximumAge:60000} ];

            for(const opts of cascade){
                if(finished) break;
                try {
                    const pos = await tryGetPos(opts, LOC_TRY_BUDGET);
                    window._lastPos = pos;
                    try { logProctor('location_acquired',
                        'acc='+Math.round(pos.coords.accuracy||0)+'m highAcc='+(opts.enableHighAccuracy?1:0)+' perm='+perm); } catch(e){}
                    finishOk(opts.enableHighAccuracy ? 'high' : 'low');
                    return;
                } catch(err){ /* بی‌صدا به مرحلهٔ بعد */ }
            }
        }
    }

    /* موقعیت تقریبی از IP — با مهلت و منبع جایگزین. در هر صورت تمام می‌شود. */
    if(window._lastPos === undefined) window._lastPos = null;
    let gotIp = false;
    const sources = [
        ['https://ipapi.co/json/', (j)=>({lat:j.latitude, lng:j.longitude})],
        ['https://ipwho.is/',      (j)=>({lat:j.latitude, lng:j.longitude})],
    ];
    for(const [url, pick] of sources){
        if(finished) break;
        const ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        const abortTimer = ctrl ? setTimeout(()=>ctrl.abort(), LOC_IP_BUDGET) : null;
        try {
            const r = await fetch(url, ctrl ? {signal: ctrl.signal} : undefined);
            const j = await r.json();
            const pt = pick(j);
            if(pt && pt.lat && pt.lng){
                window._lastPos = {coords:{latitude:parseFloat(pt.lat), longitude:parseFloat(pt.lng), accuracy:5000, isIp:true}};
                gotIp = true;
                break;
            }
        } catch(e){ /* منبع بعدی */ }
        finally { if(abortTimer) clearTimeout(abortTimer); }
    }
    try { logProctor('location_ip_fallback', gotIp ? 'IP-approx' : 'no location available'); } catch(e){}
    finishOk(gotIp ? 'ip' : 'none');
}


function checkInternet(){
    let start = Date.now();
    let statusEl = document.querySelector('#check_internet .status');
    statusEl.textContent='در حال تست...';
    fetch('online-exam-media-upload.php?check='+Date.now(), {method:'HEAD', cache:'no-store'}).then(()=>{
        let duration = Date.now()-start;
        let quality = duration<500?'عالی': duration<1000?'خوب': duration<2000?'متوسط':'ضعیف';
        permissionState.internet = duration<5000;
        statusEl.innerHTML='✅ '+duration+'ms - '+quality;
        document.getElementById('check_internet').classList.add('bg-green-50');
        checkAllPermissionsDone();
    }).catch(()=>{
        statusEl.innerHTML='❌ خطا در اتصال - ولی ادامه می‌دهیم';
        permissionState.internet=true; // allow continue even if head fails
        checkAllPermissionsDone();
    });
}

async function requestAllPermissions(){
    document.getElementById('permissionError').classList.add('hidden');
    // Camera & Mic together
    try {
        let stream = await navigator.mediaDevices.getUserMedia({video:true, audio:true});
        permissionState.camera=true; permissionState.mic=true;
        document.querySelector('#check_camera .status').innerHTML='✅ تایید شد';
        document.querySelector('#check_mic .status').innerHTML='✅ تایید شد';
        document.getElementById('check_camera').classList.add('bg-green-50');
        document.getElementById('check_mic').classList.add('bg-green-50');
        stream.getTracks().forEach(t=>t.stop());
    } catch(e){
        // try camera only
        try {
            let s = await navigator.mediaDevices.getUserMedia({video:true});
            permissionState.camera=true;
            document.querySelector('#check_camera .status').innerHTML='✅ تایید شد';
            document.getElementById('check_camera').classList.add('bg-green-50');
            s.getTracks().forEach(t=>t.stop());
        } catch(ee){
            document.querySelector('#check_camera .status').innerHTML='❌ '+ee.message;
        }
    }
    checkLocationRobust();
    checkInternet();
}

function showPermError(msg){ let el=document.getElementById('permissionError'); el.textContent=msg; el.classList.remove('hidden'); }

function checkAllPermissionsDone(){
    let required = <?php echo json_encode(['camera'=> !empty($proctor['require_camera']) && $exam['enable_webcam'], 'mic'=> !empty($proctor['require_mic']), 'location'=> !empty($proctor['require_location']) && $exam['enable_location']]); ?>;
    let ok = true;
    if(required.camera && !permissionState.camera) ok=false;
    if(required.mic && !permissionState.mic) ok=false;
    if(required.location && !permissionState.location) ok=false;
    // internet not blocking now
    if(ok){
        document.getElementById('startExamBtn').classList.remove('hidden');
    }
}

async function startExamAfterChecks(){
    // IMPORTANT: Reset timer on server - this fixes timer start after permission
    try {
        let fd = new FormData();
        fd.append('action','start_attempt');
        fd.append('attempt_id',attemptId);
        let res = await fetch('online-exam-api.php',{method:'POST',body:fd});
        let j = await res.json();
        if(j.ok && j.data && (j.data.remaining || j.data.remaining===0)){
            remainingSeconds = j.data.remaining;
            /* v4.123.0: نقطه شروع کلاینتی از باقیمانده سرور — مستقل از ساعت دستگاه */
            firstStartedTimestamp = Date.now() - (examDurationSeconds - remainingSeconds)*1000;
            console.log('Timer synced from server, remaining:', remainingSeconds);
        } else {
            // fallback to full duration
            remainingSeconds = <?php echo (int)$exam['duration_minutes']*60; ?>;
            firstStartedTimestamp = Date.now();
        }
    } catch(e){
        console.log('start_attempt failed',e);
        remainingSeconds = <?php echo (int)$exam['duration_minutes']*60; ?>;
    }

    /* v4.123.0: نقطه شروع در بلاک بالا از پاسخ سرور تنظیم شد */
    if(!firstStartedTimestamp) firstStartedTimestamp = Date.now();

    // Webcam preview
    try {
        let stream = await navigator.mediaDevices.getUserMedia({video:{width:320,height:240}, audio: !!permissionState.mic});
        webcamStream = stream;
        let video = document.getElementById('webcamVideo');
        video.srcObject = stream;
        document.getElementById('webcamPreview').classList.remove('hidden');
        permissionState.camera=true;
    } catch(e){
        if(<?php echo !empty($proctor['require_camera'])?'true':'false'; ?>){ alert('دوربین الزامی است: '+e.message); return; }
    }

    if(navigator.geolocation && permissionState.location){
        locationWatchId = navigator.geolocation.watchPosition(pos=>{
            window._lastPos = pos;
        }, err=>{
            logProctor('permission_revoked_location', err.message);
        }, {enableHighAccuracy:true, maximumAge:10000, timeout:30000});
    }

    document.getElementById('permissionStage').classList.add('hidden');
    document.getElementById('examStage').classList.remove('hidden');
    initWatermark();
    startTimer();
    startHeartbeat();
    setupProctoring();
    loadLocalAnswers();
    examStarted=true;
}

function startTimer(){
    // Timer that NEVER pauses - calculates from firstStartedTimestamp even if page hidden
    // If firstStartedTimestamp is from server, use it, otherwise use client start
    if(!firstStartedTimestamp) firstStartedTimestamp = Date.now() - (examDurationSeconds - remainingSeconds)*1000;
    
    let lastTickMs = Date.now();
    let clockJumpFixes = 0;
    function updateTimerDisplay(){
        /* v4.126.0: محافظ «پرش ساعت دستگاه».
           موبایل‌ها گاهی ساعت را با NTP همگام می‌کنند و Date.now() ناگهان چند
           دقیقه جلو می‌رود. قبلاً همین باعث می‌شد تایمر فوراً صفر شود و دانش‌آموز
           در میانه پاسخ‌دادن از آزمون بیرون بیفتد. حالا اگر یک تیک (~۱ ثانیه)
           بیش از ۶۰ ثانیه جهش داشته باشد، به‌جای پایان‌دادن آزمون، تایمر از
           سرور بازمی‌تنیم. */
        const nowMs = Date.now();
        const deltaMs = nowMs - lastTickMs;
        lastTickMs = nowMs;
        if (deltaMs > 60000 && clockJumpFixes < 3) {
            clockJumpFixes++;
            console.warn('ساعت دستگاه جهش کرد (ms=' + deltaMs + ') — همگام‌سازی با سرور');
            resyncTimerFromServer();
            return;
        }
        // Calculate elapsed from first start, not just decrement
        let elapsed = Math.floor((Date.now() - firstStartedTimestamp)/1000);
        remainingSeconds = Math.max(0, examDurationSeconds - elapsed);
        let h = Math.floor(remainingSeconds/3600), m=Math.floor((remainingSeconds%3600)/60), s=remainingSeconds%60;
        const td=document.getElementById('timerDisplay');
        td.textContent = '⏱ '+String(h).padStart(2,'0')+':'+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');
        td.classList.toggle('t-warn', remainingSeconds<600 && remainingSeconds>=180);
        td.classList.toggle('t-danger', remainingSeconds<180);
        if(remainingSeconds<=0){
            clearInterval(timerInterval);
            /* v4.126.0: قبل از هر اقدام قاطع، از سرور می‌پرسیم که واقعاً زمان
               تمام شده یا نه. اگر سرور بگوید وقت هست، تایمر اصلاح و آزمون ادامه
               می‌یابد؛ اگر تأیید کند تمام شده، امن ارسال می‌کنیم. */
            td.textContent='⏱ 00:00:00';
            confirmTimeThenFinish(td);
        }
    }
    /* v4.126.0: بنر سبک بالای صفحه (بدون alert مسدودکننده) */
    function showExamBanner(text, color){
        try{
            document.querySelectorAll('.v4126-banner').forEach(e=>e.remove());
            let bn=document.createElement('div');
            bn.className='v4126-banner';
            bn.style.cssText='position:fixed;top:12px;left:50%;transform:translateX(-50%);z-index:1300;background:'+(color||'linear-gradient(135deg,#dc2626,#ef4444)')+';color:#fff;padding:12px 24px;border-radius:999px;font-weight:800;font-size:14px;box-shadow:0 8px 28px rgba(0,0,0,.25)';
            bn.textContent=text;
            document.body.appendChild(bn);
            return bn;
        }catch(e){ return null; }
    }

    /* v4.126.0: بازمینیِ کامل تایمر از «زمان باقی‌ماندهٔ اعلامی سرور» */
    function applyServerRemaining(sec){
        remainingSeconds = Math.max(0, parseInt(sec, 10) || 0);
        firstStartedTimestamp = Date.now() - (examDurationSeconds - remainingSeconds) * 1000;
        lastTickMs = Date.now();
    }

    async function resyncTimerFromServer(){
        try{
            let fd=new FormData(); fd.append('action','check_time'); fd.append('attempt_id',attemptId);
            let r=await fetch('online-exam-api.php',{method:'POST',body:fd});
            let j=await r.json();
            if(j && j.ok && j.data){
                if(j.data.expired){ finishExamNow(document.getElementById('timerDisplay')); return; }
                applyServerRemaining(j.data.remaining);
                showExamBanner('⏱ زمان آزمون با سرور همگام شد — به آزمون ادامه دهید','linear-gradient(135deg,#0369a1,#0ea5e9)');
                setTimeout(()=>{ document.querySelectorAll('.v4126-banner').forEach(e=>e.remove()); }, 5000);
                if(!timerInterval) timerInterval = setInterval(updateTimerDisplay, 1000);
                updateTimerDisplay();
                return;
            }
        }catch(e){ console.warn('resync failed', e); }
        /* سرور در دسترس نبود: تایمر را همان‌جا نگه می‌داریم و دوباره تلاش می‌کنیم */
        if(!timerInterval) timerInterval = setInterval(updateTimerDisplay, 1000);
    }

    async function confirmTimeThenFinish(td){
        try{
            let fd=new FormData(); fd.append('action','check_time'); fd.append('attempt_id',attemptId);
            let r=await fetch('online-exam-api.php',{method:'POST',body:fd});
            let j=await r.json();
            if(j && j.ok && j.data && j.data.expired === false && j.data.remaining > 0){
                /* خطای ساعت دستگاه بود — آزمون ادامه دارد */
                applyServerRemaining(j.data.remaining);
                showExamBanner('⏱ زمان آزمون با سرور همگام شد — به آزمون ادامه دهید','linear-gradient(135deg,#0369a1,#0ea5e9)');
                setTimeout(()=>{ document.querySelectorAll('.v4126-banner').forEach(e=>e.remove()); }, 5000);
                timerInterval = setInterval(updateTimerDisplay, 1000);
                updateTimerDisplay();
                return;
            }
        }catch(e){ console.warn('check_time failed', e); }
        finishExamNow(td);
    }

    function finishExamNow(td){
        if(td) td.textContent='⏱ 00:00:00';
        showExamBanner('⏰ زمان آزمون تمام شد — پاسخ‌های ذخیره‌شده شما در حال ارسال است…');
        setTimeout(()=>submitExam(true), 1200);
    }

    updateTimerDisplay();
    timerInterval = setInterval(updateTimerDisplay, 1000);
    
    // Also update timer when page becomes visible again (phone unlock)
    document.addEventListener('visibilitychange', ()=>{
        if(!document.hidden){
            updateTimerDisplay();
        }
    });
}

/* ============ v4.121.0: پخش تذکر صوتی دبیر ============ */
let voiceQueue=[], voicePlaying=false, ackedVoices=new Set();
function playTeacherVoice(vn){
    if(ackedVoices.has(vn.id)) return;
    ackedVoices.add(vn.id);
    voiceQueue.push(vn);
    if(!voicePlaying) playNextVoice();
}
function playNextVoice(){
    const vn=voiceQueue.shift();
    if(!vn){voicePlaying=false; return;}
    voicePlaying=true;
    /* بنر اعلان */
    let bn=document.getElementById('voiceNoteBanner');
    if(!bn){
        bn=document.createElement('div');
        bn.id='voiceNoteBanner';
        bn.style.cssText='position:fixed;top:12px;left:50%;transform:translateX(-50%);z-index:1200;background:linear-gradient(135deg,#7c3aed,#8b5cf6);color:#fff;padding:11px 22px;border-radius:999px;font-weight:800;font-size:13.5px;box-shadow:0 8px 28px rgba(124,58,237,.45);display:flex;align-items:center;gap:9px';
        document.body.appendChild(bn);
    }
    bn.innerHTML='🔊 '+(vn.target?'تذکر صوتی دبیر (مخصوص شما)':'پیام صوتی دبیر')+' — در حال پخش…';
    bn.style.display='flex';
    const au=new Audio(vn.file_path+'?t='+Date.now());
    const done=()=>{
        bn.style.display='none';
        /* تأیید پخش به سرور */
        const fd=new FormData(); fd.append('action','ack_voice_note'); fd.append('voice_id',vn.id); fd.append('attempt_id',attemptId);
        fetch('online-exam-api.php',{method:'POST',body:fd}).catch(()=>{});
        logProctor('voice_note_played','voice_id='+vn.id);
        setTimeout(playNextVoice, 400);
    };
    au.onended=done;
    au.onerror=done;
    au.play().catch(()=>{
        /* اگر پخش خودکار مسدود بود: با اولین تعامل کاربر پخش شود */
        bn.innerHTML='🔊 پیام صوتی دبیر — برای پخش یک بار روی صفحه کلیک/لمس کنید';
        const once=()=>{au.play().catch(()=>{}); document.removeEventListener('pointerdown',once);};
        document.addEventListener('pointerdown',once);
    });
}
function startHeartbeat(){
    /* v4.121.0: تازه‌سازی دوره‌ای GPS هر ۵ دقیقه — کاهش فشار روی دستگاه و سرور */
    setInterval(()=>{
        if(navigator.geolocation && permissionState.location){
            navigator.geolocation.getCurrentPosition(p=>{window._lastPos=p;},()=>{},{enableHighAccuracy:false,timeout:15000,maximumAge:120000});
        }
    }, 300000);
    async function beat(){
        let pos = window._lastPos;
        let lat = pos ? pos.coords.latitude : '';
        let lng = pos ? pos.coords.longitude : '';
        let acc = pos ? pos.coords.accuracy : '';
        let src = pos ? (pos.coords.isIp ? 'ip' : 'gps') : 'none';
        let fd = new FormData();
        fd.append('action','heartbeat');
        fd.append('attempt_id',attemptId);
        fd.append('exam_id',examId);
        fd.append('lat',lat); fd.append('lng',lng); fd.append('accuracy',acc);
        fd.append('geo_source', src);
        fd.append('camera_ok', permissionState.camera?1:0);
        fd.append('mic_ok', permissionState.mic?1:0);
        fd.append('location_ok', permissionState.location?1:0);
        try{
            let res = await fetch('online-exam-api.php',{method:'POST',body:fd});
            let j = await res.json();
            if(j.ok && j.data.pending_webcam_requests && j.data.pending_webcam_requests.length>0){
                for(let req of j.data.pending_webcam_requests){
                    captureAndUploadWebcam(req.id);
                }
            }
            /* v4.121.0: پخش تذکر صوتی دبیر (عمومی یا هدفمند) */
            if(j.ok && j.data.pending_voice && j.data.pending_voice.length>0){
                for(let vn of j.data.pending_voice) playTeacherVoice(vn);
            }
        }catch(e){}
    }
    beat();
    heartbeatInterval = setInterval(beat, 15000);
}

/* v4.123.0: Enter داخل input تک‌خطی فرم آزمون → فقط ذخیره پاسخ (نه submit/reload) */
document.addEventListener('keydown',e=>{
    if(e.key==='Enter' && e.target && e.target.tagName==='INPUT' && e.target.closest('#examForm')){
        e.preventDefault();
        const box=e.target.closest('.question-box');
        if(box)saveAnswer(box.dataset.qid);
    }
});
function setupProctoring(){
    document.addEventListener('visibilitychange', async ()=>{
        if(document.hidden){
            logProctor('tab_hidden','تب عوض شد');
        } else {
            logProctor('tab_visible','بازگشت');
            // Fix webcam freeze on phone lock/unlock - restart webcam if needed
            try {
                let video = document.getElementById('webcamVideo');
                if(video && webcamStream){
                    // Check if tracks are still live
                    let liveTracks = webcamStream.getVideoTracks().filter(t=>t.readyState==='live');
                    if(liveTracks.length===0 || video.paused || video.srcObject===null){
                        console.log('Restarting webcam after visibility change');
                        let stream = await navigator.mediaDevices.getUserMedia({video:{width:320,height:240}, audio: !!permissionState.mic});
                        webcamStream = stream;
                        video.srcObject = stream;
                        await video.play().catch(()=>{});
                        logProctor('دوربین مجددا فعال شد بعد از بازگشت', 'Webcam restarted after visibility');
                    } else {
                        // Try to resume play
                        await video.play().catch(()=>{});
                    }
                }
            } catch(e){ console.log('Webcam restart failed', e); }
        }
    });
    window.addEventListener('blur', ()=>{ logProctor('window_blurred','Minimize'); });
    window.addEventListener('focus', async ()=>{
        logProctor('window_focused','فوکوس');
        // Also restart webcam on focus (phone unlock)
        try {
            let video = document.getElementById('webcamVideo');
            if(video && webcamStream){
                let liveTracks = webcamStream.getVideoTracks().filter(t=>t.readyState==='live');
                if(liveTracks.length===0){
                    let stream = await navigator.mediaDevices.getUserMedia({video:{width:320,height:240}, audio: !!permissionState.mic});
                    webcamStream = stream;
                    video.srcObject = stream;
                    await video.play().catch(()=>{});
                } else {
                    await video.play().catch(()=>{});
                }
            }
        } catch(e){}
    });
    document.addEventListener('copy', e=>{ e.preventDefault(); logProctor('copy_attempt','کپی'); });
    document.addEventListener('paste', e=>{ e.preventDefault(); logProctor('paste_attempt','پیست'); });
    document.addEventListener('contextmenu', e=>{ e.preventDefault(); logProctor('right_click','کلیک راست'); });
    document.addEventListener('keydown', e=>{
        if(e.key==='PrintScreen'){ e.preventDefault(); logProctor('printscreen','PrintScreen'); }
        if((e.ctrlKey||e.metaKey) && ['c','v','p','s','u'].includes(e.key.toLowerCase())){ e.preventDefault(); logProctor('copy_attempt','Ctrl+'+e.key); }
    });
}

function logProctor(type, data){
    let fd = new FormData(); fd.append('action','proctoring_log'); fd.append('attempt_id',attemptId); fd.append('exam_id',examId); fd.append('event_type',type); fd.append('event_data',data);
    fetch('online-exam-api.php',{method:'POST',body:fd});
}

function captureAndUploadWebcam(requestId){
    let video = document.getElementById('webcamVideo');
    let canvas = document.getElementById('webcamCanvas');
    if(!video || video.videoWidth===0) return;
    canvas.width=320; canvas.height=240;
    let ctx = canvas.getContext('2d'); ctx.drawImage(video,0,0,320,240);
    canvas.toBlob(blob=>{
        let fd = new FormData(); fd.append('action','upload_webcam_snapshot'); fd.append('attempt_id',attemptId); fd.append('request_id',requestId); fd.append('snapshot',blob,'snap.jpg');
        fetch('online-exam-api.php',{method:'POST',body:fd});
    }, 'image/jpeg', 0.4);
}

let isRecordingVideo = false;
async function recordAndUploadVideo10Sec(requestId){
    if(isRecordingVideo) return;
    if(!webcamStream){ console.log('No webcam stream for video'); return; }
    isRecordingVideo=true;
    logProctor('شروع ضبط ویدیو 10 ثانیه', 'درخواست ویدیو 10 ثانیه‌ای با کیفیت پایین 20fps - سازگار ایران');
    try {
        // Record 10 seconds at low resolution, 20fps target, low bitrate
        let options = {mimeType: 'video/webm;codecs=vp8'};
        if(!MediaRecorder.isTypeSupported(options.mimeType)){
            options = {mimeType: 'video/webm'};
        }
        let recordedChunks=[];
        let recorder = new MediaRecorder(webcamStream, {mimeType: options.mimeType, videoBitsPerSecond: 250000}); // 250kbps low quality
        recorder.ondataavailable = e=>{ if(e.data.size>0) recordedChunks.push(e.data); };
        recorder.onstop = async ()=>{
            let blob = new Blob(recordedChunks, {type: 'video/webm'});
            let fd = new FormData();
            fd.append('action','upload_webcam_video');
            fd.append('attempt_id',attemptId);
            fd.append('request_id',requestId);
            fd.append('video', blob, 'video10sec_'+attemptId+'.webm');
            try {
                let res = await fetch('online-exam-api.php',{method:'POST',body:fd});
                let j = await res.json();
                console.log('Video 10sec uploaded', j);
                logProctor('ویدیو 10 ثانیه ارسال شد', 'حجم: '+(blob.size/1024).toFixed(1)+'KB');
            } catch(e){ console.log('Video upload failed', e); }
            isRecordingVideo=false;
        };
        recorder.start(200); // collect every 200ms
        setTimeout(()=>{ if(recorder.state==='recording') recorder.stop(); }, 10000); // 10 seconds
    } catch(e){
        console.log('Video record error', e);
        isRecordingVideo=false;
        // Fallback to 10 snapshots
        for(let i=0;i<10;i++){
            setTimeout(()=>{ captureAndUploadWebcam(requestId); }, i*1000);
        }
    }
}

function saveAnswer(qid){
    let box = document.getElementById('qbox_'+qid);
    if(!box) return;
    let answer = {};
    if(box.querySelector('input[type=radio]:checked')) answer.selected = box.querySelector('input[type=radio]:checked').value;
    let checks = box.querySelectorAll('input[type=checkbox]:checked');
    if(checks.length>0) answer.selected = Array.from(checks).map(c=>c.value);
    let sel = box.querySelector('select');
    if(sel && sel.value) answer.selected = sel.value || answer.selected;
    let textInputs = box.querySelectorAll('input[type=text], textarea, input[type=number]');
    textInputs.forEach(inp=>{
        if(inp.classList.contains('blank-input')){
            if(!answer.blanks) answer.blanks={};
            answer.blanks[inp.dataset.blank]=inp.value;
        } else if(!inp.classList.contains('matching-select') && !inp.classList.contains('file-input')){
            if(inp.value) answer.value = inp.value;
        }
    });
    let matchSelects = box.querySelectorAll('.matching-select');
    if(matchSelects.length>0){
        answer.matches={};
        matchSelects.forEach((s,i)=>{ if(s.value) answer.matches[i]=s.value; });
    }
    let key = 'exam_'+examId+'_attempt_'+attemptId+'_q_'+qid;
    localStorage.setItem(key, JSON.stringify(answer));
    let fd = new FormData(); fd.append('action','save_answer'); fd.append('attempt_id',attemptId); fd.append('question_id',qid); fd.append('answer_data', JSON.stringify(answer));
    /* v4.126.0: نتیجهٔ ذخیره واقعاً بررسی می‌شود. قبلاً پاسخ «ذخیره شد ✓»
       نمایش داده می‌شد حتی وقتی سرور خطا داده بود و دانش‌آموز فکر می‌کرد پاسخش
       ثبت شده است. */
    setAutosaveState('saving');
    fetch('online-exam-api.php',{method:'POST',body:fd})
        .then(r=>r.json())
        .then(j=>{
            if(j && j.ok){ setAutosaveState('saved'); }
            else { setAutosaveState('error', (j && j.msg) ? j.msg : 'ذخیرهٔ پاسخ ناموفق بود'); }
            updateProgress();
        })
        .catch(()=>{ setAutosaveState('error', 'اتصال قطع است — پاسخ روی همین دستگاه نگه داشته شد'); updateProgress(); });
    updateProgress();
}

function loadLocalAnswers(){
    <?php foreach($questions as $q): ?>
    (function(){
        let qid = <?php echo $q['id']; ?>;
        let key = 'exam_'+examId+'_attempt_'+attemptId+'_q_'+qid;
        let stored = localStorage.getItem(key);
        if(stored){
            try{
                let ans = JSON.parse(stored);
                let box = document.getElementById('qbox_'+qid);
                if(!box) return;
                if(ans.selected){
                    if(Array.isArray(ans.selected)){
                        ans.selected.forEach(v=>{ let el=box.querySelector('input[value="'+CSS.escape(v)+'"]'); if(el) el.checked=true; });
                    } else {
                        let el=box.querySelector('input[value="'+CSS.escape(ans.selected)+'"]'); if(el) el.checked=true;
                        let sel=box.querySelector('select'); if(sel) sel.value=ans.selected;
                    }
                }
                if(ans.value!==undefined){
                    let inp=box.querySelector('input[type=text], textarea, input[type=number]'); if(inp) inp.value=ans.value;
                }
                if(ans.blanks) Object.keys(ans.blanks).forEach(k=>{ let inp=box.querySelector('.blank-input[data-blank="'+k+'"]'); if(inp) inp.value=ans.blanks[k]; });
                if(ans.matches) Object.keys(ans.matches).forEach(k=>{ let sel=box.querySelector('.matching-select[data-index="'+k+'"]'); if(sel) sel.value=ans.matches[k]; });
            }catch(e){}
        }
    })();
    <?php endforeach; ?>
    updateProgress();
}

function faNum(n){return String(n).replace(/[0-9]/g,d=>'۰۱۲۳۴۵۶۷۸۹'[+d]);}
function updateProgress(){
    let total = <?php echo count($questions); ?>;
    let answered = 0;
    document.querySelectorAll('.question-box').forEach(box=>{
        const has = box.querySelector('input:checked') || (box.querySelector('select') && box.querySelector('select').value) || (box.querySelector('input[type=text]') && box.querySelector('input[type=text]').value.trim()!=='') || (box.querySelector('textarea') && box.querySelector('textarea').value.trim()!=='');
        box.classList.toggle('q-answered', !!has);
        const mapBtn=document.querySelector('.q-map-btn[data-qref="'+box.dataset.qid+'"]');
        if(mapBtn)mapBtn.classList.toggle('answered', !!has);
        if(has) answered++;
    });
    let pct = total>0? Math.round(answered/total*100):0;
    let bar = document.getElementById('progressBar'); if(bar) bar.style.width=pct+'%';
    const lbl=document.getElementById('progressLabel'); if(lbl)lbl.textContent='پاسخ‌داده: '+faNum(answered)+' از '+faNum(total);
    const md=document.getElementById('qMapDone'); if(md)md.textContent='('+faNum(answered)+'/'+faNum(total)+')';
}
/* v4.120.0: نشانگر ذخیره خودکار */
function setAutosaveState(st, msg){
    const dot=document.getElementById('autosaveDot'); if(!dot)return;
    dot.classList.remove('saving','saved');
    const txt=document.getElementById('autosaveText');
    if(st==='saving'){dot.classList.add('saving'); dot.style.background=''; if(txt)txt.textContent='در حال ذخیره…';}
    else if(st==='saved'){dot.classList.add('saved'); dot.style.background=''; if(txt)txt.textContent='ذخیره شد ✓'; setTimeout(()=>{if(txt)txt.textContent='ذخیره خودکار فعال'; dot.classList.remove('saved');},2200);}
    else if(st==='error'){ /* v4.126.0 */ dot.style.background='#dc2626'; if(txt){txt.textContent=(msg||'ذخیره نشد')+' — دوباره تلاش کنید'; txt.style.color='#b91c1c'; setTimeout(()=>{txt.textContent='ذخیره خودکار فعال'; txt.style.color='';},8000);} }
}

function uploadFileAnswer(input, qid){
    let file = input.files[0]; if(!file) return;
    let fd = new FormData(); fd.append('action','save_answer'); fd.append('attempt_id',attemptId); fd.append('question_id',qid); fd.append('answer_data', JSON.stringify({file_name:file.name})); fd.append('answer_file', file);
    fetch('online-exam-api.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{
        let status = input.parentElement.querySelector('.file-status');
        if(status) status.textContent=j.ok?'✅ ذخیره شد: '+file.name:'❌ '+j.msg;
    });
}

let mediaRecorder=null; let audioChunks=[];
function toggleVoiceRecord(btn){
    let qid = btn.dataset.qid;
    let status = btn.parentElement.querySelector('.record-status');
    let preview = btn.parentElement.parentElement.querySelector('.voice-preview');
    if(mediaRecorder && mediaRecorder.state==='recording'){
        mediaRecorder.stop(); btn.textContent='شروع ضبط'; status.textContent='در حال ذخیره...';
    } else {
        navigator.mediaDevices.getUserMedia({audio:true}).then(stream=>{
            mediaRecorder = new MediaRecorder(stream);
            audioChunks=[];
            mediaRecorder.ondataavailable = e=>{ audioChunks.push(e.data); };
            mediaRecorder.onstop = ()=>{
                let blob = new Blob(audioChunks,{type:'audio/webm'});
                let url = URL.createObjectURL(blob);
                preview.src=url; preview.classList.remove('hidden');
                let fd = new FormData(); fd.append('action','save_answer'); fd.append('attempt_id',attemptId); fd.append('question_id',qid); fd.append('answer_data', JSON.stringify({voice:true})); fd.append('answer_file', blob, 'voice_'+qid+'.webm');
                fetch('online-exam-api.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{ status.textContent=j.ok?'✅ صدا ذخیره شد':'❌ خطا'; });
                stream.getTracks().forEach(t=>t.stop());
            };
            mediaRecorder.start(); btn.textContent='توقف ضبط'; status.textContent='🔴 در حال ضبط...';
        }).catch(err=>{ alert('میکروفون: '+err.message); });
    }
}

// Whiteboard with accurate coordinates, eraser, zoom, color, width - v4.28.7
let whiteboardState = {}; // per qid: {tool, color, width, scale, drawing, lastX, lastY}

function getWhiteboardState(qid){
    if(!whiteboardState[qid]) whiteboardState[qid] = {tool:'pen', color:'#000000', width:2, scale:1, drawing:false, lastX:0, lastY:0};
    return whiteboardState[qid];
}

function getCanvasCoords(canvas, clientX, clientY){
    let rect = canvas.getBoundingClientRect();
    let scaleX = canvas.width / rect.width;
    let scaleY = canvas.height / rect.height;
    return {
        x: (clientX - rect.left) * scaleX,
        y: (clientY - rect.top) * scaleY
    };
}

function setWhiteboardTool(qid, tool){
    let st = getWhiteboardState(qid);
    st.tool = tool;
    // update UI buttons
    document.querySelectorAll(`.tool-btn[data-qid="${qid}"]`).forEach(btn=>{
        btn.classList.remove('btn-primary'); btn.classList.add('btn-secondary');
        if(btn.dataset.tool===tool){ btn.classList.add('btn-primary'); btn.classList.remove('btn-secondary'); }
    });
}

function setWhiteboardColor(qid, color){
    let st = getWhiteboardState(qid);
    st.color = color;
}

function setWhiteboardWidth(qid, width){
    let st = getWhiteboardState(qid);
    st.width = parseInt(width);
}

function zoomWhiteboard(qid, factor){
    let st = getWhiteboardState(qid);
    st.scale *= factor;
    st.scale = Math.max(0.5, Math.min(3, st.scale));
    let canvas = document.querySelector(`.whiteboard-canvas[data-qid="${qid}"]`);
    if(canvas){
        let container = canvas.parentElement;
        canvas.style.transform = `scale(${st.scale})`;
        canvas.style.transformOrigin = 'top left';
        container.style.maxHeight = (500*st.scale)+'px';
    }
}

function resetZoomWhiteboard(qid){
    let st = getWhiteboardState(qid);
    st.scale=1;
    let canvas = document.querySelector(`.whiteboard-canvas[data-qid="${qid}"]`);
    if(canvas){
        canvas.style.transform='scale(1)';
        canvas.parentElement.style.maxHeight='500px';
    }
}

function clearWhiteboard(qid){
    let c=document.querySelector(`.whiteboard-canvas[data-qid="${qid}"]`);
    if(c){ 
        let ctx=c.getContext('2d');
        ctx.clearRect(0,0,c.width,c.height);
        // Save cleared state to localStorage for recovery?
        logProctor('whiteboard_cleared', 'پاک کردن تخته qid='+qid);
    }
}

document.querySelectorAll('.whiteboard-canvas').forEach(canvas=>{
    let qid = canvas.dataset.qid;
    let st = getWhiteboardState(qid);
    let ctx = canvas.getContext('2d');
    ctx.lineCap='round'; ctx.lineJoin='round';

    function startDraw(x,y){
        st.drawing=true;
        st.lastX=x; st.lastY=y;
        ctx.beginPath();
        ctx.moveTo(x,y);
    }
    function drawLine(x,y){
        if(!st.drawing) return;
        ctx.lineWidth = st.width;
        if(st.tool==='eraser'){
            ctx.globalCompositeOperation='destination-out';
            ctx.strokeStyle='rgba(0,0,0,1)';
        } else {
            ctx.globalCompositeOperation='source-over';
            ctx.strokeStyle=st.color;
        }
        ctx.lineTo(x,y);
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(x,y);
        st.lastX=x; st.lastY=y;
    }
    function endDraw(){
        if(st.drawing){
            st.drawing=false;
            ctx.beginPath();
            // Auto save to localStorage as base64 for recovery
            try { localStorage.setItem('whiteboard_'+qid, canvas.toDataURL()); } catch(e){}
        }
    }

    // Mouse
    canvas.addEventListener('mousedown', e=>{
        e.preventDefault();
        let coords = getCanvasCoords(canvas, e.clientX, e.clientY);
        startDraw(coords.x, coords.y);
    });
    canvas.addEventListener('mousemove', e=>{
        if(!st.drawing) return;
        e.preventDefault();
        let coords = getCanvasCoords(canvas, e.clientX, e.clientY);
        drawLine(coords.x, coords.y);
    });
    canvas.addEventListener('mouseup', e=>{ endDraw(); });
    canvas.addEventListener('mouseleave', e=>{ endDraw(); });

    // Touch - accurate
    canvas.addEventListener('touchstart', e=>{
        e.preventDefault();
        if(e.touches.length>0){
            let t=e.touches[0];
            let coords = getCanvasCoords(canvas, t.clientX, t.clientY);
            startDraw(coords.x, coords.y);
        }
    }, {passive:false});
    canvas.addEventListener('touchmove', e=>{
        e.preventDefault();
        if(!st.drawing || e.touches.length===0) return;
        let t=e.touches[0];
        let coords = getCanvasCoords(canvas, t.clientX, t.clientY);
        drawLine(coords.x, coords.y);
    }, {passive:false});
    canvas.addEventListener('touchend', e=>{
        e.preventDefault();
        endDraw();
    });

    // Restore from localStorage if exists
    try {
        let saved = localStorage.getItem('whiteboard_'+qid);
        if(saved){
            let img = new Image();
            img.onload = ()=>{ ctx.drawImage(img,0,0); };
            img.src = saved;
        }
    } catch(e){}
});

function saveWhiteboard(qid){
    let canvas=document.querySelector(`.whiteboard-canvas[data-qid="${qid}"]`);
    canvas.toBlob(blob=>{
        let fd=new FormData(); fd.append('action','save_answer'); fd.append('attempt_id',attemptId); fd.append('question_id',qid); fd.append('answer_data', JSON.stringify({whiteboard:true, color:getWhiteboardState(qid).color, width:getWhiteboardState(qid).width})); fd.append('answer_file', blob, 'whiteboard_'+qid+'.png');
        fetch('online-exam-api.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{ 
            if(j.ok) alert('✅ نقاشی ذخیره شد - دقیق و با بزرگنمایی');
            else alert('❌ '+j.msg);
        });
    });
}


function submitExam(isAuto=false){
    if(!isAuto && !confirm('ارسال نهایی؟')) return;
    let fd=new FormData(); fd.append('action','submit_exam'); fd.append('attempt_id',attemptId);
    fetch('online-exam-api.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{
        if(j.ok){ localStorage.clear(); alert('✅ ارسال شد - نمره: '+j.data.score); location.href='online-exam-result.php?attempt_id='+attemptId; } else alert(j.msg);
    });
}
window.addEventListener('beforeunload',()=>{ logProctor('page_unload','خروج'); });

// Draggable webcam preview - student can move it around
(function(){
    let el = document.getElementById('webcamPreview');
    if(!el) return;
    let isDragging=false, startX, startY, initialLeft, initialTop;
    function getPos(e){
        if(e.touches && e.touches[0]) return {x:e.touches[0].clientX, y:e.touches[0].clientY};
        return {x:e.clientX, y:e.clientY};
    }
    function onStart(e){
        if(e.target.tagName==='VIDEO') return;
        isDragging=true;
        let pos = getPos(e);
        startX=pos.x; startY=pos.y;
        let rect = el.getBoundingClientRect();
        initialLeft=rect.left; initialTop=rect.top;
        el.style.transition='none';
        e.preventDefault();
    }
    function onMove(e){
        if(!isDragging) return;
        let pos = getPos(e);
        let dx = pos.x - startX;
        let dy = pos.y - startY;
        el.style.left = (initialLeft + dx) + 'px';
        el.style.top = (initialTop + dy) + 'px';
        el.style.right='auto'; el.style.bottom='auto';
    }
    function onEnd(){ isDragging=false; el.style.transition=''; }
    el.addEventListener('mousedown', onStart);
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onEnd);
    el.addEventListener('touchstart', onStart, {passive:false});
    document.addEventListener('touchmove', onMove, {passive:false});
    document.addEventListener('touchend', onEnd);
})();

document.addEventListener('DOMContentLoaded',()=>{ initWatermark(); requestAllPermissions(); });

</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
