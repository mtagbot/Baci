<?php
// File: online-exam-monitor.php - Live proctoring dashboard v4.28.6 - with 10 sec view, Persian logs, draggable
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/online_exam_helpers.php';
require_once __DIR__ . '/includes/school_roles.php';
ensure_school_roles_schema();
ensure_online_exams_schema();

if (!empty($_SESSION['admin_id'])) require_permission('manage_reports');
elseif (empty($_SESSION['teacher_id'])) redirect('admin-login.php');
$teacherId = $_SESSION['teacher_id'] ?? null;
$isExecutive = $teacherId ? (teacher_has_executive($teacherId) || teacher_has_deputy($teacherId) || teacher_has_counselor($teacherId)) : false;

$examId = (int)($_GET['exam_id'] ?? 0);
if ($examId<=0) { set_flash_message('error','آزمون نامعتبر'); redirect('online-exams.php'); }
$exam = DB::fetch("SELECT * FROM online_exams WHERE id=?", [$examId]);
if (!$exam) { set_flash_message('error','آزمون یافت نشد'); redirect('online-exams.php'); }
if ($teacherId && !$isExecutive && (int)$exam['teacher_id'] !== (int)$teacherId) { if (!$isExecutive) { set_flash_message('error','غیرمجاز'); redirect('online-exams.php'); } }

require_once __DIR__ . '/includes/header.php';
?>
<style>
.webcam-float { position:fixed; bottom:20px; right:20px; width:360px; background:white; border:2px solid #e11d48; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,0.3); z-index:9999; padding:8px; cursor:move; }
.webcam-float img { width:100%; border-radius:8px; cursor:default; }
.webcam-float video { width:100%; border-radius:8px; }
.live-dot { width:8px; height:8px; background:#22c55e; border-radius:50%; display:inline-block; animation:pulse 1.5s infinite; }
@keyframes pulse { 0%{opacity:1} 50%{opacity:0.3} 100%{opacity:1} }
.stream-timer { position:absolute; top:8px; right:8px; background:rgba(0,0,0,0.7); color:white; font-size:10px; padding:2px 6px; border-radius:999px; }
</style>
<div class="space-y-4">
    <div class="flex justify-between items-center">
        <div>
            <h2 class="text-xl font-bold">مانیتورینگ زنده: <?php echo clean($exam['title']); ?></h2>
            <p class="text-xs text-muted">نظارت بر دانش‌آموزان با وب‌کم لحظه‌ای، نمایش 10 ثانیه، IP، موقعیت، تخلفات فارسی</p>
        </div>
        <div class="flex gap-2">
            <a href="online-exams.php" class="btn btn-secondary text-xs">لیست آزمون‌ها</a>
            <a href="online-exam-questions.php?exam_id=<?php echo $examId; ?>" class="btn btn-primary text-xs">سوالات</a>
            <a href="online-exam-results.php?exam_id=<?php echo $examId; ?>" class="btn btn-outline text-xs">نتایج + تخلفات</a>
        </div>
    </div>

    <div class="grid grid-cols-4 gap-3">
        <div class="card text-center"><div class="text-xs text-muted">در حال آزمون</div><div id="statLive" class="text-2xl font-bold">0</div></div>
        <div class="card text-center"><div class="text-xs text-muted">IP تکراری</div><div id="statSameIp" class="text-2xl font-bold text-red-600">0</div></div>
        <div class="card text-center"><div class="text-xs text-muted">نزدیکی مکانی &lt;150متر</div><div id="statProx" class="text-2xl font-bold text-orange-600">0</div></div>
        <div class="card text-center"><div class="text-xs text-muted">آخرین بروزرسانی</div><div id="statTime" class="text-xs font-mono">--:--:--</div></div>
    </div>

    <div class="grid grid-cols-3 gap-4">
        <div class="col-span-2 card">
            <h3 class="font-bold mb-2 flex justify-between"><span>دانش‌آموزان در حال آزمون</span><button onclick="refreshLive()" class="btn btn-secondary text-xs">بروزرسانی</button></h3>
            <div class="table-container">
                <table class="text-xs">
                    <thead><tr><th>دانش‌آموز</th><th>کلاس</th><th>IP</th><th>موقعیت GPS (بدون ماهواره هم محاسبه می‌شود)</th><th>وضعیت</th><th>خروج/تب/کپی</th><th>وب‌کم</th><th>عملیات</th></tr></thead>
                    <tbody id="liveTbody"><tr><td colspan="8" class="text-center text-muted py-6">در حال بارگذاری...</td></tr></tbody>
                </table>
            </div>
        </div>
        <div class="space-y-4">
            <div class="card" style="border:1.5px solid #ddd6fe;background:linear-gradient(135deg,#faf5ff,#f5f3ff)">
                <h3 class="font-bold text-sm mb-2">🎙 تذکر صوتی به دانش‌آموزان</h3>
                <div class="text-[11px] text-muted mb-2">دکمه را نگه دارید و صحبت کنید؛ با رها کردن، صدا ارسال و در دستگاه دانش‌آموز(ان) پخش می‌شود. حداکثر ۶۰ ثانیه — کیفیت گفتار کم‌حجم (هر دقیقه ≈ ۸۰ کیلوبایت).</div>
                <div class="mb-2">
                    <label class="text-[11px] font-bold">گیرنده:</label>
                    <select id="pttTarget" class="form-select text-xs" style="margin-top:3px">
                        <option value="0">📢 همه دانش‌آموزان حاضر در آزمون</option>
                    </select>
                </div>
                <button id="pttBtn" class="w-full" style="background:linear-gradient(135deg,#7c3aed,#8b5cf6);color:#fff;border:0;border-radius:12px;padding:13px;font-weight:800;font-size:14px;cursor:pointer;user-select:none;-webkit-user-select:none;touch-action:none">🎙 نگه دارید و صحبت کنید</button>
                <div id="pttStatus" class="text-[11px] text-muted mt-2 text-center">آماده</div>
            </div>
            <div class="card">
                <h3 class="font-bold text-sm mb-2">هشدار IP تکراری (تقلب احتمالی)</h3>
                <div id="sameIpBox" class="space-y-2 text-xs text-muted">-</div>
            </div>
            <div class="card">
                <h3 class="font-bold text-sm mb-2">هشدار نزدیکی مکانی (&lt;150 متر - از GPS گوشی بدون ماهواره)</h3>
                <div id="proximityBox" class="space-y-2 text-xs text-muted">-</div>
                <p class="text-[10px] text-muted mt-1">موقعیت از GPS گوشی دانش‌آموز گرفته می‌شود (حتی بدون ماهواره با شبکه) و فاصله تا 150 متر محاسبه می‌شود</p>
            </div>
            <div class="card">
                <h3 class="font-bold text-sm mb-2">پیام‌های تخلفات اخیر (فارسی)</h3>
                <div id="logsBox" class="max-h-[400px] overflow-auto text-[11px] space-y-1">-</div>
            </div>
        </div>
    </div>
</div>

<!-- Floating webcam viewer - draggable -->
<div id="webcamFloat" class="webcam-float hidden">
    <div class="flex justify-between items-center mb-1 cursor-move" id="webcamHeader"><span class="font-bold text-xs" id="webcamTitle">وب‌کم دانش‌آموز</span><div class="flex gap-1"><button id="saveWebcamBtn" class="btn btn-success text-[10px]">ذخیره</button><button onclick="closeWebcamFloat()" class="btn btn-danger text-[10px]">× بستن</button></div></div>
    <div class="relative">
        <img id="webcamImg" src="" alt="snapshot" class="">
        
    </div>
    <div class="text-[10px] text-muted mt-1" id="webcamMeta"></div>
    <div class="text-[10px] text-muted">کادر قابل جابجایی است - بکشید / کلیک بیرون برای بستن</div>
</div>

<script>
let examId = <?php echo $examId; ?>;
let currentSnapshotId = null;
let currentSnapshotPath = null;
let streamInterval = null;

// Persian translation for event types
function persianEventLabel(type){
    const map = {
        'tab_hidden': 'تب مرورگر مخفی شد / جابجایی تب (تقلب احتمالی)',
        'tab_visible': 'بازگشت به تب آزمون',
        'window_blurred': 'پنجره کوچک شد / خارج شدن از فوکوس (Minimize)',
        'window_focused': 'بازگشت فوکوس به پنجره',
        'fullscreen_exit': 'خروج از حالت تمام صفحه',
        'copy_attempt': 'تلاش برای کپی متن (تقلب)',
        'paste_attempt': 'تلاش برای چسباندن (Paste)',
        'right_click': 'کلیک راست (مسدود شد)',
        'printscreen': 'کلید PrintScreen (تلاش اسکرین‌شات)',
        'permission_revoked_camera': 'دوربین قطع شد',
        'permission_revoked_mic': 'میکروفون قطع شد',
        'permission_revoked_location': 'موقعیت مکانی قطع شد / Timeout',
        'page_unload': 'خروج / بستن صفحه آزمون',
        'minimize': 'حداقل کردن پنجره',
        'contextmenu_blocked': 'منوی کلیک راست مسدود شد',
        'location_ip_fallback': 'موقعیت از طریق IP (GPS ناموفق - سازگار ایران)',
        'location_ip_manual_override': 'ادامه با IP دستی (GPS در دسترس نبود - ایران)',
        'mic_not_found_continue': 'ادامه بدون میکروفون (دسکتاپ)',
        'devtools_attempt': 'تلاش برای باز کردن ابزار توسعه',
    };
    return map[type] || type;
}

function refreshLive(){
    // Show loading only first time
    let tbodyEl = document.getElementById('liveTbody');
    if(tbodyEl.innerHTML.includes('در حال بارگذاری')) tbodyEl.innerHTML = '<tr><td colspan=8 class="text-center text-muted py-6">⏳ در حال دریافت لیست زنده...<br><small>اگر بیش از 10 ثانیه طول کشید، دکمه بروزرسانی را بزنید یا <a href="online-exam-monitor-debug.php?exam_id='+examId+'" target="_blank">صفحه دیباگ</a> را باز کنید</small></td></tr>';
    
    fetch('online-exam-api.php?action=get_live_sessions&exam_id='+examId)
    .then(r=>{
        if(!r.ok) throw new Error('HTTP '+r.status);
        return r.json();
    })
    .then(j=>{
        if(!j.ok){ 
            document.getElementById('liveTbody').innerHTML='<tr><td colspan=8 class="text-center text-red-600 py-6">❌ خطا: '+j.msg+'<br><small>جدول live_sessions خالی است یا خطای سرور - <a href="online-exam-monitor-debug.php?exam_id='+examId+'" target="_blank">دیباگ</a></small></td></tr>'; 
            // Try fallback: get attempts in_progress directly
            return fetch('online-exam-api.php?action=get_attempts_in_progress&exam_id='+examId).then(r=>r.json()).then(j2=>{
                if(j2.ok && j2.data.attempts && j2.data.attempts.length>0){
                    let fallbackTbody='';
                    j2.data.attempts.forEach(a=>{
                        fallbackTbody+= `<tr style="background:#fffbeb"><td class="font-bold">${a.first_name} ${a.last_name}<div class="text-[10px]">بدون heartbeat (Fallback)</div></td><td>${a.class_name}</td><td>${a.ip_address||'-'}</td><td>نامشخص (IP Fallback)</td><td>تلاش: ${a.attempt_number}</td><td>خروج:${a.exit_count||0}</td><td><button onclick="requestWebcam(${a.id},'${a.first_name}')" class="btn btn-danger text-[10px]">عکس</button></td><td><a href="online-exam-result.php?attempt_id=${a.id}" class="btn btn-secondary text-[10px]">نتیجه</a></td></tr>`;
                    });
                    document.getElementById('liveTbody').innerHTML=fallbackTbody;
                    document.getElementById('statLive').textContent = j2.data.attempts.length + ' (Fallback)';
                }
            }).catch(()=>{});
            return; 
        }
        let sessions = j.data.sessions||[]; pttFillTargets(sessions);
        let sameIp = j.data.same_ip||[];
        let proximity = j.data.proximity||[];

        document.getElementById('statLive').textContent = sessions.length;
        document.getElementById('statSameIp').textContent = sameIp.length;
        document.getElementById('statProx').textContent = proximity.length;
        document.getElementById('statTime').textContent = new Date().toLocaleTimeString('fa-IR');

        let tbody=''; 
        sessions.forEach(s=>{
            let stale = s.is_stale ? 'style="opacity:0.6;background:#fef2f2"' : '';
            let cam = s.camera_ok==1 ? '✅' : '❌';
            let mic = s.mic_ok==1 ? '✅' : '❌';
            let loc = s.location_ok==1 ? '✅' : '❌';
            let locText = (s.lat && s.lng) ? (parseFloat(s.lat).toFixed(5)+','+parseFloat(s.lng).toFixed(5)) : 'نامشخص (IP: '+(s.ip_address||'-')+')';
            let acc = s.accuracy ? s.accuracy+'m' : '-';
            tbody+= `<tr ${stale}><td class="font-bold">${s.first_name} ${s.last_name}<div class="text-[10px] text-muted">${s.national_id}</div><div>${s.is_stale?'<span class="text-red-500">⚠️ آفلاین</span>':'<span class="live-dot"></span> آنلاین'}</div></td><td>${s.class_name}</td><td class="font-mono">${s.ip_address||'-'}<div class="text-[10px]">${s.attempt_status}</div></td><td><div class="text-[10px]">${locText}</div><div>دقت: ${acc}</div></td><td><div>دوربین:${cam} میک:${mic} مکان:${loc}</div><div class="text-[10px]">آخرین: ${s.last_heartbeat_fa}</div></td><td><div>خروج: ${s.exit_count||0}</div><div>تب: ${s.tab_switch_count||0}</div><div>کپی: ${s.copy_attempts||0}</div></td><td>
                <button onclick="requestWebcam(${s.attempt_id},'${s.first_name} ${s.last_name}')" class="btn btn-danger text-[10px] w-full">مشاهده وب‌کم دانش‌آموز</button>
                <div class="mt-1"><button onclick="viewLogs(${s.attempt_id})" class="btn btn-outline text-[10px] w-full">تخلفات فارسی</button></div>
            </td><td><a href="online-exam-results.php?attempt_id=${s.attempt_id}" class="btn btn-secondary text-[10px]">نتیجه + تخلفات</a></td></tr>`;
        });
        if(!tbody) tbody='<tr><td colspan=8 class="text-center text-muted py-6">هیچ دانش‌آموزی در حال آزمون نیست</td></tr>';
        document.getElementById('liveTbody').innerHTML=tbody;

        let sameHtml=''; sameIp.forEach(g=>{ sameHtml+=`<div class="p-1 border rounded bg-red-50"><b>IP ${g.ip}</b>: ${g.students.join('، ')}</div>`; }); document.getElementById('sameIpBox').innerHTML = sameHtml||'موردی یافت نشد ✅';
        let proxHtml=''; proximity.forEach(p=>{ const bg=p.level==='critical'?'bg-red-50 border-red-300':(p.level==='warning'?'bg-orange-50 border-orange-300':'bg-amber-50'); proxHtml+=`<div class="p-2 border rounded ${bg}"><div class="font-bold">${p.level_fa||''}</div><b>${p.student1}</b> و <b>${p.student2}</b><br>فاصله: ${p.distance_text}</div>`; }); document.getElementById('proximityBox').innerHTML = proxHtml||'موردی یافت نشد ✅ (فقط موقعیت‌های GPS واقعی مقایسه می‌شوند؛ دانش‌آموزان با موقعیت IP در بخش «IP یکسان» بررسی می‌شوند)';
    });

    fetch('online-exam-api.php?action=get_proctoring_logs&exam_id='+examId).then(r=>r.json()).then(j=>{
        if(!j.ok) return;
        let logs = j.data.logs||[]; let html='';
        logs.slice(0,50).forEach(l=>{
            let persianType = persianEventLabel(l.event_type);
            html+= `<div class="border-b pb-1"><b>${l.first_name} ${l.last_name}</b> - <span class="text-red-600">${persianType}</span><div class="text-muted">${l.event_data||''} - IP: ${l.ip_address||''} - ${l.created_at||''}</div></div>`;
        });
        document.getElementById('logsBox').innerHTML = html||'لاگی نیست';
    }).catch(e=>{
        document.getElementById('logsBox').innerHTML = '<span class="text-red-600">خطا در دریافت لاگ: '+e.message+'</span>';
    });
}
function refreshLiveWithErrorHandling(){
    try { refreshLive(); } catch(e){
        document.getElementById('liveTbody').innerHTML = '<tr><td colspan=8 class="text-red-600">❌ خطای JS: '+e.message+'</td></tr>';
    }
}


function requestWebcam(attemptId, studentName){
    if(!confirm('درخواست تصویر وب‌کم فوری از '+studentName+' ؟')) return;
    let fd = new FormData(); fd.append('action','request_webcam'); fd.append('attempt_id',attemptId); fd.append('exam_id',examId);
    fetch('online-exam-api.php', {method:'POST', body:fd}).then(r=>r.json()).then(j=>{
        if(!j.ok){ alert(j.msg); return; }
        let reqId = j.data.request_id;
        let tries=0;
        let interval = setInterval(()=>{
            tries++;
            fetch('online-exam-api.php?action=get_webcam_request_status&request_id='+reqId).then(r=>r.json()).then(jj=>{
                if(jj.data && jj.data.request && jj.data.request.status==='completed'){
                    clearInterval(interval);
                    showWebcamFloat(jj.data.request.snapshot_path, studentName, jj.data.request);
                }
                if(tries>20){ clearInterval(interval); alert('پاسخی دریافت نشد - دانش‌آموز آفلاین یا دوربین بسته'); }
            });
        },1500);
    });
}

function showWebcamFloat(path, studentName, meta){
    fetch('online-exam-api.php?action=get_webcam_snapshots&attempt_id='+meta.attempt_id).then(r=>r.json()).then(j=>{
        if(j.data.snapshots && j.data.snapshots.length>0){
            let latest = j.data.snapshots[0];
            currentSnapshotId = latest.id;
            document.getElementById('webcamImg').src = latest.file_path + '?t='+Date.now();
            document.getElementById('webcamTitle').textContent = 'وب‌کم: '+studentName+' - '+latest.created_at;
            document.getElementById('webcamMeta').textContent = 'حجم: '+latest.file_size+' بایت - کیفیت پایین - موقت (10 دقیقه) مگر ذخیره شود';
            document.getElementById('webcamFloat').classList.remove('hidden');
        } else {
            document.getElementById('webcamImg').src = path;
            document.getElementById('webcamTitle').textContent = 'وب‌کم: '+studentName;
            document.getElementById('webcamFloat').classList.remove('hidden');
        }
    });
}

function closeWebcamFloat(){ 
    document.getElementById('webcamFloat').classList.add('hidden'); 
    document.getElementById('webcamImg').src=''; 
    if(streamInterval) clearInterval(streamInterval);
    currentSnapshotId=null; 
}

document.getElementById('saveWebcamBtn').onclick = function(){
    if(!currentSnapshotId) return alert('تصویری انتخاب نشده');
    let fd = new FormData(); fd.append('action','save_webcam_snapshot'); fd.append('snapshot_id',currentSnapshotId);
    fetch('online-exam-api.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{ if(j.ok) alert('تصویر ذخیره دائمی شد'); else alert(j.msg); });
};

function viewLogs(attemptId){
    fetch('online-exam-api.php?action=get_proctoring_logs&attempt_id='+attemptId).then(r=>r.json()).then(j=>{
        let logs = j.data.logs||[]; 
        let html = '<div style="max-height:400px;overflow:auto;text-align:right;direction:rtl;">';
        logs.forEach(l=>{
            let persian = persianEventLabel(l.event_type);
            html+= `<div style="border-bottom:1px solid #eee;padding:6px;"><b>${l.created_at}</b> - <span style="color:red">${persian}</span><br><small>${l.event_data||''} - IP: ${l.ip_address||''}</small></div>`;
        });
        html+='</div>';
        let w = window.open('', '_blank', 'width=600,height=600');
        w.document.write('<html><head><title>تخلفات دانش‌آموز</title><meta charset="UTF-8"></head><body style="font-family:Tahoma;direction:rtl;">'+html+'</body></html>');
    });
}

// Draggable webcam float
(function(){
    let el = document.getElementById('webcamFloat');
    let header = document.getElementById('webcamHeader');
    let isDragging=false, startX, startY, initLeft, initTop;
    function getPos(e){ if(e.touches&&e.touches[0]) return {x:e.touches[0].clientX, y:e.touches[0].clientY}; return {x:e.clientX, y:e.clientY}; }
    function onStart(e){ isDragging=true; let p=getPos(e); startX=p.x; startY=p.y; let r=el.getBoundingClientRect(); initLeft=r.left; initTop=r.top; e.preventDefault(); }
    function onMove(e){ if(!isDragging) return; let p=getPos(e); el.style.left=(initLeft+p.x-startX)+'px'; el.style.top=(initTop+p.y-startY)+'px'; el.style.right='auto'; el.style.bottom='auto'; }
    function onEnd(){ isDragging=false; }
    header.addEventListener('mousedown', onStart);
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onEnd);
    header.addEventListener('touchstart', onStart, {passive:false});
    document.addEventListener('touchmove', onMove, {passive:false});
    document.addEventListener('touchend', onEnd);
})();

setInterval(refreshLive, 10000);

/* ============ v4.121.0: Push-To-Talk دبیر ============ */
let pttRecorder=null, pttChunks=[], pttStream=null, pttTimer=null, pttActive=false;
const pttBtn=document.getElementById('pttBtn');
const pttStatus=document.getElementById('pttStatus');

function pttFillTargets(sessions){
    const sel=document.getElementById('pttTarget'); if(!sel)return;
    const cur=sel.value;
    while(sel.options.length>1)sel.remove(1);
    (sessions||[]).forEach(s=>{
        const o=document.createElement('option');
        o.value=s.attempt_id;
        o.textContent='👤 '+s.first_name+' '+s.last_name+' ('+(s.class_name||'')+')';
        sel.appendChild(o);
    });
    sel.value=cur;
    if(sel.value!==cur)sel.value='0';
}

async function pttStart(e){
    e.preventDefault();
    if(pttActive)return;
    try{
        /* v4.122.0: صدای کم‌حجم — مونو، نرخ نمونه پایین، حذف نویز (کیفیت گفتار، نه موسیقی) */
        pttStream=await navigator.mediaDevices.getUserMedia({audio:{
            channelCount:1,
            sampleRate:16000,
            echoCancellation:true,
            noiseSuppression:true,
            autoGainControl:true
        }});
    }catch(err){
        try{ pttStream=await navigator.mediaDevices.getUserMedia({audio:true}); }
        catch(err2){ pttStatus.textContent='❌ دسترسی میکروفون رد شد: '+err2.message; return; }
    }
    pttChunks=[];
    if(!window.MediaRecorder){pttStatus.textContent='❌ مرورگر از ضبط صدا پشتیبانی نمی‌کند';return;}
    /* اولویت با کدک Opus (فشرده‌ترین برای گفتار)؛ بیت‌ریت ~۱۰kbps → یک دقیقه ≈ ۸۰KB */
    let mime='';
    for(const cand of ['audio/webm;codecs=opus','audio/ogg;codecs=opus','audio/webm','audio/mp4']){
        if(!MediaRecorder.isTypeSupported || MediaRecorder.isTypeSupported(cand)){ mime=cand; break; }
    }
    const recOpts={audioBitsPerSecond:10000};
    if(mime)recOpts.mimeType=mime;
    try{ pttRecorder=new MediaRecorder(pttStream,recOpts); }
    catch(e2){ pttRecorder=mime?new MediaRecorder(pttStream,{mimeType:mime}):new MediaRecorder(pttStream); }
    pttRecorder.ondataavailable=ev=>{if(ev.data&&ev.data.size>0)pttChunks.push(ev.data);};
    pttRecorder.onstop=pttUpload;
    pttRecorder.start();
    pttActive=true;
    pttBtn.style.background='linear-gradient(135deg,#dc2626,#ef4444)';
    pttBtn.textContent='🔴 در حال ضبط… (رها کنید تا ارسال شود)';
    pttStatus.textContent='در حال ضبط…';
    /* سقف ۶۰ ثانیه */
    pttTimer=setTimeout(()=>{if(pttActive)pttStop();},60000);
}
function pttStop(){
    if(!pttActive)return;
    pttActive=false;
    clearTimeout(pttTimer);
    try{pttRecorder.stop();}catch(e){}
    if(pttStream){pttStream.getTracks().forEach(t=>t.stop()); pttStream=null;}
    pttBtn.style.background='linear-gradient(135deg,#7c3aed,#8b5cf6)';
    pttBtn.textContent='🎙 نگه دارید و صحبت کنید';
}
async function pttUpload(){
    const blob=new Blob(pttChunks,{type:pttRecorder.mimeType||'audio/webm'});
    if(blob.size<800){pttStatus.textContent='ضبط خیلی کوتاه بود — دوباره امتحان کنید';return;}
    pttStatus.textContent='در حال ارسال… ('+Math.round(blob.size/1024)+'KB)';
    const fd=new FormData();
    fd.append('action','send_voice_note');
    fd.append('exam_id',examId);
    fd.append('attempt_id',document.getElementById('pttTarget').value||'0');
    fd.append('voice',blob,'note.webm');
    try{
        const j=await fetch('online-exam-api.php',{method:'POST',body:fd}).then(r=>r.json());
        pttStatus.textContent=j.ok?('✅ '+(j.msg||j.message||'ارسال شد')+' — در ضربان بعدی (حداکثر ۱۵ ثانیه) پخش می‌شود'):('❌ '+(j.msg||j.message||'خطا'));
    }catch(e){
        pttStatus.textContent='❌ خطا در ارسال: '+e.message;
    }
}
if(pttBtn){
    pttBtn.addEventListener('pointerdown',pttStart);
    pttBtn.addEventListener('pointerup',pttStop);
    pttBtn.addEventListener('pointerleave',()=>{if(pttActive)pttStop();});
    pttBtn.addEventListener('contextmenu',e=>e.preventDefault());
}
refreshLive();

document.addEventListener('click', function(e){
    let float = document.getElementById('webcamFloat');
    if(float.classList.contains('hidden')) return;
    if(!float.contains(e.target) && !e.target.textContent.includes('مشاهده')){
        // close only if clicking on empty body
        if(e.target.tagName==='BODY' || e.target.id==='liveTbody' || e.target.classList.contains('space-y-4')){
            // closeWebcamFloat();
        }
    }
});
// Per user request: click on empty part closes
document.addEventListener('click', function(e){
    let float = document.getElementById('webcamFloat');
    if(float.classList.contains('hidden')) return;
    if(!float.contains(e.target) && !e.target.closest('button')){
        if(e.target.textContent.includes('مشاهده')) return;
        // If click outside float and not on button, close
        if(!e.target.closest('table') && !e.target.closest('.card')){
            closeWebcamFloat();
        }
    }
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
