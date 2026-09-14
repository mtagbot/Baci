<?php
// File: online-exam-take.php - Student exam taking with anti-cheat (v4.28.1 fix)
// Fixes: timer starts after permission, location timeout robust
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/online_exam_helpers.php';
require_student();
ensure_online_exams_schema();

$examId = (int)($_GET['exam_id'] ?? 0);
$attemptId = (int)($_GET['attempt_id'] ?? 0);

if ($examId<=0 && $attemptId>0) {
    $att = DB::fetch("SELECT exam_id FROM online_exam_attempts WHERE id=? AND student_id=?", [$attemptId, $_SESSION['student_id']]);
    if ($att) $examId = (int)$att['exam_id'];
}
if ($examId<=0) { set_flash_message('error','آزمون نامعتبر'); redirect('student-panel.php'); }

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
    // If attempt already has timer_started flag? We use start_time as real start after permission. If attempt exists but timer not yet started (start_time is recent creation), we will reset on frontend after permission.
    $canTake = true;
    $start = strtotime($existingAttempt['start_time']);
    $duration = (int)$exam['duration_minutes'] * 60;
    if (time() - $start > $duration && $existingAttempt['status']=='in_progress') {
        // Check if timer was actually started? If student never started exam stage, we should not expire immediately. We check if there is any answer or heartbeat? For now, allow continuation if no heartbeat yet? Simpler: if attempt older than duration AND has live session, expire.
        $hasLive = DB::fetch("SELECT id FROM online_exam_live_sessions WHERE attempt_id=?", [$attemptId]);
        if ($hasLive) {
            DB::execute("UPDATE online_exam_attempts SET status='expired', end_time=NOW() WHERE id=?", [$attemptId]);
            set_flash_message('error','زمان آزمون به پایان رسیده'); redirect('online-exam-result.php?attempt_id='.$attemptId);
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
if (!empty($exam['randomize_questions'])) shuffle($questions);

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

require_once __DIR__ . '/includes/header.php';
?>
<style>
.anti-copy { user-select:none; -webkit-user-select:none; -moz-user-select:none; -ms-user-select:none; }
.watermark-overlay { position:fixed; top:0; left:0; width:100%; height:100%; pointer-events:none; z-index:999; opacity:0.03; background-repeat:repeat; overflow:hidden; }
.watermark-text { position:absolute; font-size:14px; color:#000; transform:rotate(-30deg); white-space:nowrap; opacity:0.8; }
#webcamPreview { position:fixed; bottom:15px; left:15px; width:160px; height:120px; background:#000; border:2px solid #ef4444; border-radius:12px; overflow:hidden; z-index:1000; box-shadow:0 4px 12px rgba(0,0,0,0.4); }
#webcamPreview video { width:100%; height:100%; object-fit:cover; }
#webcamPreview .label { position:absolute; top:2px; left:2px; background:rgba(239,68,68,0.9); color:white; font-size:9px; padding:2px 4px; border-radius:4px; }
.question-box { border:1px solid #e2e8f0; border-radius:12px; padding:16px; background:white; margin-bottom:16px; }
.timer-fixed { position:fixed; top:70px; left:15px; background:#dc2626; color:white; padding:8px 14px; border-radius:999px; font-weight:bold; font-size:14px; z-index:999; box-shadow:0 4px 12px rgba(220,38,38,0.4); }
@media print { body { display:none; } }
</style>

<div class="watermark-overlay" id="watermarkOverlay"></div>
<div id="webcamPreview" class="hidden"><span class="label" style="cursor:move;">🔴 وب‌کم فعال - قابل جابجایی</span><video id="webcamVideo" autoplay muted playsinline></video><canvas id="webcamCanvas" class="hidden"></canvas><div style="position:absolute;bottom:2px;right:2px;background:rgba(0,0,0,0.6);color:white;font-size:8px;padding:1px 3px;border-radius:3px;">بکشید برای جابجایی</div></div>
<div class="timer-fixed" id="timerDisplay">⏱️ <?php echo gmdate('H:i:s', $remaining); ?></div>

<div class="max-w-5xl mx-auto space-y-4 anti-copy" id="examContainer">
    <div id="permissionStage" class="card space-y-4">
        <h2 class="text-lg font-bold">🔐 بررسی دسترسی‌های آزمون: <?php echo clean($exam['title']); ?></h2>
        <p class="text-sm text-muted">برای شرکت در آزمون، باید دسترسی‌های زیر را تایید کنید. تایمر آزمون <b>بعد از تایید دسترسی‌ها</b> شروع می‌شود.</p>
        
        <div class="space-y-3" id="permissionChecks">
            <div class="flex justify-between items-center p-3 border rounded" id="check_camera"><span>📷 دوربین (وب‌کم)</span><span class="status">در انتظار درخواست...</span><button class="btn btn-secondary text-xs" onclick="checkCamera()">بررسی</button></div>
            <div class="flex justify-between items-center p-3 border rounded" id="check_mic"><span>🎤 میکروفون</span><span class="status">در انتظار درخواست...</span><button class="btn btn-secondary text-xs" onclick="checkMic()">بررسی</button></div>
            <div class="flex justify-between items-center p-3 border rounded" id="check_location">
                <div><span>📍 موقعیت مکانی</span><div class="text-[11px] text-muted" id="locationDetail">GPS باید روشن باشد و مرورگر اجازه داشته باشد - HTTPS الزامی است</div><div id="ipContinueWrap" class="hidden mt-2"><button onclick="continueWithIpFallback()" class="btn btn-warning text-xs">✅ ادامه با موقعیت IP (سازگار با ایران - بدون نیاز ماهواره)</button><a href="online-exam-location-test.php" target="_blank" class="btn btn-secondary text-xs">🔍 تست GPS</a></div></div>
                <div class="flex gap-1 items-center flex-col"><span class="status">در انتظار...</span><div class="flex gap-1"><button class="btn btn-secondary text-xs" onclick="checkLocationRobust()">بررسی مجدد</button><button class="btn btn-outline text-xs" onclick="continueWithIpFallback()" title="اگر GPS نمی‌گیرد، با IP ادامه دهید">ادامه با IP</button></div></div>
            </div>
            <div class="flex justify-between items-center p-3 border rounded" id="check_internet"><span>🌐 کیفیت اینترنت</span><span class="status">در حال بررسی...</span></div>
        </div>

        <div class="flex gap-2">
            <button onclick="requestAllPermissions()" class="btn btn-primary">🔄 درخواست همه دسترسی‌ها</button>
            <button id="startExamBtn" onclick="startExamAfterChecks()" class="btn btn-success hidden">✅ تایید و شروع آزمون (تایمر از حالا شروع می‌شود)</button>
        </div>
        <div id="permissionError" class="p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700 hidden"></div>
        <div class="p-3 bg-blue-50 border border-blue-200 rounded text-xs">
            <b>راهنمای رفع خطای موقعیت مکانی Timeout expired:</b>
            <ul class="list-disc pr-4 mt-1 space-y-1">
                <li>سایت باید با <b>HTTPS</b> باشد (http کار نمی‌کند)</li>
                <li>در اندروید: تنظیمات → Location → روشن + حالت High Accuracy</li>
                <li>در مرورگر: روی آیکون قفل کنار آدرس → Site settings → Location → Allow</li>
                <li>اگر داخل ساختمان هستید، نزدیک پنجره بروید تا GPS آنتن دهد</li>
                <li>دکمه بررسی مجدد را بزنید - سیستم با timeout 30 ثانیه مجدد تلاش می‌کند</li>
            </ul>
        </div>
    </div>

    <div id="examStage" class="hidden space-y-4">
        <div class="card bg-blue-50 border-blue-200">
            <h3 class="font-bold"><?php echo clean($exam['title']); ?></h3>
            <p class="text-xs"><?php echo clean($exam['description']); ?></p>
            <div class="flex gap-3 text-xs mt-1"><span>⏱️ مدت: <?php echo tr_num($exam['duration_minutes'],'fa'); ?> دقیقه</span><span>📝 سوالات: <?php echo tr_num(count($questions),'fa'); ?></span><span>⭐ نمره کل: <?php echo tr_num($maxScore,'fa'); ?></span></div>
        </div>

        <div class="flex justify-between items-center">
            <div class="text-xs text-muted">پاسخ‌ها خودکار ذخیره می‌شوند (زمان حتی اگر صفحه را ترک کنید ادامه دارد)</div>
            <button onclick="submitExam()" class="btn btn-danger">📤 ارسال نهایی آزمون</button>
        </div>

        <?php if(!empty($display['show_progress_bar'])): ?>
        <div class="w-full bg-gray-200 rounded-full h-2"><div id="progressBar" class="bg-blue-600 h-2 rounded-full transition-all" style="width:0%"></div></div>
        <?php endif; ?>

        <form id="examForm">
        <?php foreach($questions as $idx=>$q): $qd=json_decode($q['question_data'], true) ?: []; $qNum=$idx+1; ?>
            <div class="question-box" data-qid="<?php echo $q['id']; ?>" id="qbox_<?php echo $q['id']; ?>">
                <div class="flex justify-between items-start mb-2">
                    <div class="font-bold">سوال <?php echo tr_num($qNum,'fa'); ?> (<?php echo tr_num($q['points'],'fa'); ?> نمره) - <?php echo online_question_types()[$q['question_type']]['label'] ?? $q['question_type']; ?></div>
                    <div class="text-xs text-muted"><?php echo $qNum; ?>/<?php echo count($questions); ?></div>
                </div>
                <div class="prose max-w-none text-sm mb-3"><?php echo $q['question_text']; ?>
                    <?php if(!empty($qd['media']['image'])): ?><div class="mt-2"><img src="<?php echo clean($qd['media']['image']); ?>" class="max-w-full rounded border" style="max-height:400px;"></div><?php endif; ?>
                    <?php if(!empty($qd['media']['video'])): ?><div class="mt-2"><video controls src="<?php echo clean($qd['media']['video']); ?>" class="max-w-full rounded" style="max-height:400px;"></video></div><?php endif; ?>
                    <?php if(!empty($qd['media']['audio'])): ?><div class="mt-2"><audio controls src="<?php echo clean($qd['media']['audio']); ?>" class="w-full"></audio></div><?php endif; ?>
                </div>

                <div class="answer-area">
                <?php if(in_array($q['question_type'], ['radio'])): $opts = $qd['options'] ?? []; if(!empty($exam['randomize_answers'])) shuffle($opts); ?>
                    <?php foreach($opts as $opt): ?>
                    <label class="flex gap-2 p-2 border rounded hover:bg-slate-50 cursor-pointer mb-1"><input type="radio" name="q_<?php echo $q['id']; ?>" value="<?php echo clean($opt['id']); ?>" onchange="saveAnswer(<?php echo $q['id']; ?>)"> <span class="text-sm"><?php echo clean($opt['text']); ?></span></label>
                    <?php endforeach; ?>
                <?php elseif($q['question_type']==='checkbox'): $opts=$qd['options']??[]; if(!empty($exam['randomize_answers'])) shuffle($opts); ?>
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
                    <?php $pairs = $qd['pairs']??[]; $rights = array_column($qd['pairs']??[], 'right'); shuffle($rights); ?>
                    <?php foreach($pairs as $pIdx=>$p): ?>
                    <div class="grid grid-cols-2 gap-2 items-center border p-2 rounded"><div class="text-sm font-bold"><?php echo clean($p['left']); ?></div><select class="form-select text-xs matching-select" data-index="<?php echo $pIdx; ?>" onchange="saveAnswer(<?php echo $q['id']; ?>)"><option value="">انتخاب تطبیق</option><?php foreach($rights as $rr): ?><option value="<?php echo clean($rr); ?>"><?php echo clean($rr); ?></option><?php endforeach; ?></select></div>
                    <?php endforeach; ?>
                    </div>
                <?php elseif($q['question_type']==='file_upload'): ?>
                    <input type="file" class="form-input text-xs file-input" data-qid="<?php echo $q['id']; ?>" onchange="uploadFileAnswer(this, <?php echo $q['id']; ?>)" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.zip"><div class="text-[11px] text-muted mt-1">فایل انتخابی خودکار ذخیره می‌شود</div><div class="file-status text-xs mt-1"></div>
                <?php elseif($q['question_type']==='voice_upload'): ?>
                    <div class="space-y-2">
                        <div class="flex gap-2"><button type="button" class="btn btn-primary text-xs record-btn" data-qid="<?php echo $q['id']; ?>" onclick="toggleVoiceRecord(this)">🎤 شروع ضبط صدا</button><span class="record-status text-xs text-muted">آماده</span></div>
                        <audio class="voice-preview hidden" controls></audio>
                    </div>
                <?php elseif($q['question_type']==='whiteboard'): ?>
                    <div class="space-y-2 whiteboard-wrapper" data-qid="<?php echo $q['id']; ?>">
                        <div class="flex flex-wrap gap-1 items-center p-2 bg-slate-50 rounded border text-xs">
                            <button type="button" class="btn btn-primary text-[10px] tool-btn" data-tool="pen" data-qid="<?php echo $q['id']; ?>" onclick="setWhiteboardTool(<?php echo $q['id']; ?>, 'pen')">✏️ قلم</button>
                            <button type="button" class="btn btn-secondary text-[10px] tool-btn" data-tool="eraser" data-qid="<?php echo $q['id']; ?>" onclick="setWhiteboardTool(<?php echo $q['id']; ?>, 'eraser')">🧽 پاک‌کن</button>
                            <label class="flex items-center gap-1">رنگ <input type="color" class="color-picker" data-qid="<?php echo $q['id']; ?>" value="#000000" onchange="setWhiteboardColor(<?php echo $q['id']; ?>, this.value)" style="width:24px;height:24px;"></label>
                            <label class="flex items-center gap-1">ضخامت <input type="range" min="1" max="10" value="2" class="width-picker" data-qid="<?php echo $q['id']; ?>" onchange="setWhiteboardWidth(<?php echo $q['id']; ?>, this.value)" style="width:60px;"></label>
                            <button type="button" class="btn btn-secondary text-[10px]" onclick="clearWhiteboard(<?php echo $q['id']; ?>)">🧹 پاک کردن کل</button>
                            <button type="button" class="btn btn-secondary text-[10px]" onclick="zoomWhiteboard(<?php echo $q['id']; ?>, 1.2)">🔍+ بزرگنمایی</button>
                            <button type="button" class="btn btn-secondary text-[10px]" onclick="zoomWhiteboard(<?php echo $q['id']; ?>, 0.8)">🔍- کوچکنمایی</button>
                            <button type="button" class="btn btn-secondary text-[10px]" onclick="resetZoomWhiteboard(<?php echo $q['id']; ?>)">↩️ اندازه اصلی</button>
                            <button type="button" class="btn btn-success text-[10px]" onclick="saveWhiteboard(<?php echo $q['id']; ?>)">💾 ذخیره نقاشی</button>
                            <span class="text-[10px] text-muted">دقت بالا - لمس دقیق - قابل بزرگنمایی</span>
                        </div>
                        <div class="whiteboard-container" style="overflow:auto;border:1px solid #e2e8f0;border-radius:8px;background:#fff;max-height:500px;position:relative;">
                            <canvas class="whiteboard-canvas border-0 bg-white touch-none" width="800" height="400" data-qid="<?php echo $q['id']; ?>" style="display:block;touch-action:none;cursor:crosshair;"></canvas>
                        </div>
                    </div>
                <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </form>

        <div class="text-center py-6"><button onclick="submitExam()" class="btn btn-danger px-12 py-3 font-bold text-base">📤 ارسال نهایی آزمون</button></div>
    </div>
</div>

<script>
const examId = <?php echo $examId; ?>;
const attemptId = <?php echo $attemptId; ?>;
let remainingSeconds = <?php echo $remaining; ?>;
let firstStartedTimestamp = <?php echo !empty($existingAttempt['first_started_at']) ? strtotime($existingAttempt['first_started_at'])*1000 : (!empty($existingAttempt['start_time']) ? strtotime($existingAttempt['start_time'])*1000 : 'Date.now()'); ?>;
let examDurationSeconds = <?php echo (int)$exam['duration_minutes']*60; ?>;
let timerStartedAtClient = Date.now(); // when JS timer started

let webcamStream = null;
let locationWatchId = null;
let permissionState = {camera:false, mic:false, location:false, internet:false};
let heartbeatInterval = null;
let timerInterval = null;
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
    let statusEl = document.querySelector('#check_location .status');
    let detailEl = document.getElementById('locationDetail');
    let locationMode = <?php echo json_encode($proctor['location_mode']??'balanced'); ?>;
    let allowIpFallback = <?php echo !empty($proctor['allow_ip_fallback'])?'true':'false'; ?>;

    statusEl.textContent='در حال دریافت موقعیت... (تا 30 ثانیه)';
    detailEl.textContent='حالت: '+locationMode+' - لطفا صبر کنید...';

    if(locationMode==='disabled'){
        permissionState.location=true;
        statusEl.textContent='✅ غیرفعال - فقط IP چک می‌شود';
        detailEl.textContent='موقعیت مکانی در تنظیمات آزمون غیرفعال است - از IP استفاده می‌شود';
        document.getElementById('check_location').classList.add('bg-green-50');
        checkAllPermissionsDone();
        return;
    }

    if(!navigator.geolocation){
        if(allowIpFallback){
            permissionState.location=true;
            statusEl.textContent='⚠️ مرورگر پشتیبانی نمی‌کند ولی با IP ادامه می‌دهیم';
            detailEl.textContent='مرورگر شما geolocation ندارد - از IP برای موقعیت تقریبی استفاده می‌شود (سازگار با ایران)';
            document.getElementById('check_location').classList.add('bg-amber-50');
            checkAllPermissionsDone();
            return;
        } else {
            statusEl.textContent='❌ مرورگر پشتیبانی نمی‌کند';
            return;
        }
    }
    if(location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1'){
        if(allowIpFallback){
            permissionState.location=true;
            statusEl.textContent='⚠️ http است ولی با IP ادامه می‌دهیم (سازگار با ایران)';
            detailEl.textContent='سایت http است - برای دقت بیشتر https کنید ولی الان با IP ادامه می‌دهیم';
            document.getElementById('check_location').classList.add('bg-amber-50');
            checkAllPermissionsDone();
            return;
        } else {
            statusEl.textContent='❌ باید HTTPS باشد';
            detailEl.textContent='سایت شما http است، موقعیت دقیق در http کار نمی‌کند. هاست را https کنید یا حالت کم‌دقت را انتخاب کنید';
            showPermError('سایت باید https باشد تا موقعیت دقیق کار کند - حالت کم‌دقت یا IP Fallback را فعال کنید');
            return;
        }
    }

    const tryGetPos = (opts) => new Promise((resolve,reject)=>{
        navigator.geolocation.getCurrentPosition(resolve, reject, opts);
    });

    // Strategies based on location_mode - Iran compatible
    let strategies = [];
    if(locationMode==='low'){
        // Low accuracy - uses network/wifi, not satellite - most compatible with Iran, no need foreign satellite
        strategies = [
            {enableHighAccuracy:false, timeout:20000, maximumAge:60000},
            {enableHighAccuracy:false, timeout:30000, maximumAge:0},
        ];
        detailEl.textContent='حالت کم‌دقت - از شبکه/وای‌فای استفاده می‌شود (بدون ماهواره خارجی - سازگار با ایران)';
    } else if(locationMode==='balanced'){
        // Balanced - tries low first then high - recommended for Iran
        strategies = [
            {enableHighAccuracy:false, timeout:15000, maximumAge:30000},
            {enableHighAccuracy:false, timeout:20000, maximumAge:0},
            {enableHighAccuracy:true, timeout:30000, maximumAge:10000},
        ];
        detailEl.textContent='حالت متعادل - ابتدا شبکه، سپس GPS (پیشنهادی برای ایران)';
    } else if(locationMode==='high'){
        // High accuracy - requires satellite - may fail in Iran buildings
        strategies = [
            {enableHighAccuracy:false, timeout:15000, maximumAge:0},
            {enableHighAccuracy:true, timeout:30000, maximumAge:0},
            {enableHighAccuracy:true, timeout:40000, maximumAge:10000}
        ];
        detailEl.textContent='حالت دقیق - نیاز به GPS ماهواره‌ای و آسمان باز (ممکن است در ایران با تاخیر باشد)';
    }

    for(let i=0;i<strategies.length;i++){
        try {
            statusEl.textContent=`تلاش ${i+1}/${strategies.length} - حالت ${strategies[i].enableHighAccuracy?'GPS ماهواره‌ای':'شبکه'} ...`;
            let pos = await tryGetPos(strategies[i]);
            permissionState.location=true;
            window._lastPos = pos;
            let acc = pos.coords.accuracy ? Math.round(pos.coords.accuracy)+'m' : 'نامشخص';
            let method = strategies[i].enableHighAccuracy ? 'GPS ماهواره‌ای' : 'شبکه/وای‌فای (سازگار ایران)';
            statusEl.innerHTML=`✅ تایید شد - دقت: ${acc} - روش: ${method}`;
            detailEl.textContent=`Lat: ${pos.coords.latitude.toFixed(5)}, Lng: ${pos.coords.longitude.toFixed(5)} - روش: ${method}`;
            document.getElementById('check_location').classList.add('bg-green-50');
            document.getElementById('check_location').classList.remove('bg-red-50');
            document.getElementById('permissionError').classList.add('hidden');
            document.getElementById('ipContinueWrap').classList.add('hidden');
            checkAllPermissionsDone();
            return;
        } catch(err){
            console.log('location attempt',i,'failed',err);
            if(i===strategies.length-1){
                document.getElementById('ipContinueWrap').classList.remove('hidden');
                // همیشه با IP ادامه می‌دهیم - سازگار با ایران، حتی اگر تنظیمات IP Fallback خاموش باشد، برای جلوگیری از گیر کردن دانش‌آموز
                permissionState.location=true;
                // Try to get IP-based location via ipapi.co (no satellite needed)
                try {
                    let ipRes = await fetch('https://ipapi.co/json/').then(r=>r.json());
                    if(ipRes && ipRes.latitude && ipRes.longitude){
                        window._lastPos = {coords:{latitude:parseFloat(ipRes.latitude), longitude:parseFloat(ipRes.longitude), accuracy:5000, isIp:true}};
                        detailEl.textContent=`موقعیت تقریبی از IP: ${ipRes.city||''} ${ipRes.region||''} - Lat:${ipRes.latitude} Lng:${ipRes.longitude} - بدون ماهواره`;
                    } else {
                        window._lastPos = {coords:{latitude:35.6892, longitude:51.3890, accuracy:5000, isIp:true}};
                    }
                } catch(e){
                    window._lastPos = {coords:{latitude:35.6892, longitude:51.3890, accuracy:5000, isIp:true}};
                }
                statusEl.innerHTML='⚠️ GPS ماهواره‌ای در دسترس نیست - ✅ ادامه با موقعیت IP (سازگار ایران - بدون ماهواره)';
                detailEl.innerHTML+='<br>✅ موقعیت IP جایگزین شد - برای ادامه روی "شروع آزمون" کلیک کنید - کد خطای GPS: '+err.code+' '+err.message;
                document.getElementById('check_location').classList.add('bg-amber-50');
                document.getElementById('check_location').classList.remove('bg-red-50');
                logProctor('موقعیت از طریق IP (GPS ناموفق - سازگار ایران)', 'GPS ناموفق: '+err.message+' - IP: <?php echo $_SERVER['REMOTE_ADDR']; ?> - حالت: '+locationMode);
                checkAllPermissionsDone();
                document.getElementById('startExamBtn').classList.remove('hidden');
                return;
            } else {
                statusEl.textContent=`تلاش ${i+1} ناموفق (${err.code}), تلاش بعدی با روش دیگر...`;
            }
        }
    }
}

function continueWithIpFallback(){
    // Manual override - Iran compatible - allows exam to continue with IP location
    permissionState.location=true;
    window._lastPos = {coords:{latitude:35.6892, longitude:51.3890, accuracy:5000}}; // Tehran as fallback, will be overwritten by IP
    document.querySelector('#check_location .status').innerHTML='✅ ادامه با IP (سازگار ایران)';
    document.getElementById('locationDetail').textContent='موقعیت با IP سرور ثبت می‌شود - بدون نیاز به ماهواره خارجی - تایید شد';
    document.getElementById('check_location').classList.add('bg-amber-50');
    document.getElementById('check_location').classList.remove('bg-red-50');
    document.getElementById('ipContinueWrap').classList.add('hidden');
    logProctor('location_ip_manual_override', 'کاربر با IP ادامه داد - GPS در دسترس نبود - ایران سازگار');
    checkAllPermissionsDone();
    // Show message
    alert('✅ با موقعیت IP ادامه می‌دهید (سازگار با ایران - بدون ماهواره)');
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
        if(j.ok && j.data && j.data.remaining){
            remainingSeconds = j.data.remaining;
            console.log('Timer reset, remaining:', remainingSeconds);
        } else {
            // fallback to full duration
            remainingSeconds = <?php echo (int)$exam['duration_minutes']*60; ?>;
        }
    } catch(e){
        console.log('start_attempt failed',e);
        remainingSeconds = <?php echo (int)$exam['duration_minutes']*60; ?>;
    }

    // Update firstStartedTimestamp to now when exam actually starts (after permission) - only if not already started
    if(!firstStartedTimestamp || <?php echo !empty($existingAttempt['is_timer_started']) ? 'false' : 'true'; ?>){
        firstStartedTimestamp = Date.now();
    }

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
    
    function updateTimerDisplay(){
        // Calculate elapsed from first start, not just decrement
        let elapsed = Math.floor((Date.now() - firstStartedTimestamp)/1000);
        remainingSeconds = Math.max(0, examDurationSeconds - elapsed);
        let h = Math.floor(remainingSeconds/3600), m=Math.floor((remainingSeconds%3600)/60), s=remainingSeconds%60;
        document.getElementById('timerDisplay').textContent = '⏱️ '+String(h).padStart(2,'0')+':'+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0') + (remainingSeconds<300 ? ' ⚠️' : '');
        if(remainingSeconds<=0){
            clearInterval(timerInterval);
            alert('⏰ زمان آزمون به پایان رسید - آزمون خودکار ارسال می‌شود (زمان حتی خارج از صفحه محاسبه شد)');
            submitExam(true);
        }
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

function startHeartbeat(){
    async function beat(){
        let pos = window._lastPos;
        let lat = pos ? pos.coords.latitude : '';
        let lng = pos ? pos.coords.longitude : '';
        let acc = pos ? pos.coords.accuracy : '';
        let fd = new FormData();
        fd.append('action','heartbeat');
        fd.append('attempt_id',attemptId);
        fd.append('exam_id',examId);
        fd.append('lat',lat); fd.append('lng',lng); fd.append('accuracy',acc);
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
        }catch(e){}
    }
    beat();
    heartbeatInterval = setInterval(beat, 15000);
}

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
    fetch('online-exam-api.php',{method:'POST',body:fd}).then(()=>updateProgress());
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

function updateProgress(){
    let total = <?php echo count($questions); ?>;
    let answered = 0;
    document.querySelectorAll('.question-box').forEach(box=>{
        if(box.querySelector('input:checked') || (box.querySelector('select') && box.querySelector('select').value) || (box.querySelector('input[type=text]') && box.querySelector('input[type=text]').value.trim()!=='') || (box.querySelector('textarea') && box.querySelector('textarea').value.trim()!=='')) answered++;
    });
    let pct = total>0? Math.round(answered/total*100):0;
    let bar = document.getElementById('progressBar'); if(bar) bar.style.width=pct+'%';
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
        mediaRecorder.stop(); btn.textContent='🎤 شروع ضبط'; status.textContent='در حال ذخیره...';
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
            mediaRecorder.start(); btn.textContent='⏹️ توقف'; status.textContent='🔴 در حال ضبط...';
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
