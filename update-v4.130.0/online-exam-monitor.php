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

require_once __DIR__ . '/includes/em_icons.php';
$pageCss = 'assets/css/exam-ui.css';          /* v4.129.0: لایهٔ رابط کاربری آزمون */
require_once __DIR__ . '/includes/header.php';
?>
<style>
/* v4.129.0 — .webcam-float و .stream-timer حذف شدند.
   پنل شناور position:fixed با width:360px ثابت، روی گوشی از قاب بیرون می‌زد
   و چون اسکرول هم نداشت، سرصفحه‌اش (همان‌جا که دکمهٔ بستن است) می‌توانست
   بالای viewport بیفتد. جای آن مودال تمام‌صفحه آمده؛ لایهٔ بصری در
   assets/css/exam-ui.css است. */
</style>
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <h2 class="text-lg sm:text-xl font-bold">مانیتورینگ زنده: <?php echo clean($exam['title']); ?></h2>
            <p class="em-hint">نظارت بر دانش‌آموزان با تصویر وب‌کم، وضعیت دستگاه، IP و موقعیت</p>
        </div>
        <div class="flex flex-wrap gap-2 w-full sm:w-auto">
            <a href="online-exams.php" class="em-btn em-btn--ghost em-btn--sm"><?php em_icon('list'); ?> آزمون‌ها</a>
            <a href="online-exam-questions.php?exam_id=<?php echo $examId; ?>" class="em-btn em-btn--brand em-btn--sm"><?php em_icon('doc'); ?> سوالات</a>
            <a href="online-exam-results.php?exam_id=<?php echo $examId; ?>" class="em-btn em-btn--ghost em-btn--sm"><?php em_icon('grid'); ?> نتایج</a>
        </div>
    </div>

    <div class="em-stats">
        <div class="em-stat"><span class="em-stat__i"><?php em_icon('user'); ?></span><span><span class="em-stat__n em-num" id="statLive">0</span><div class="em-stat__l">در حال آزمون</div></span></div>
        <div class="em-stat"><span class="em-stat__i" style="color:#b91c1c;background:rgba(239,68,68,.12)"><?php em_icon('wifi'); ?></span><span><span class="em-stat__n em-num" id="statSameIp">0</span><div class="em-stat__l">IP تکراری</div></span></div>
        <div class="em-stat"><span class="em-stat__i" style="color:#b45309;background:rgba(245,158,11,.14)"><?php em_icon('pin'); ?></span><span><span class="em-stat__n em-num" id="statProx">0</span><div class="em-stat__l">نزدیکی زیر ۱۵۰ متر</div></span></div>
        <div class="em-stat"><span class="em-stat__i"><?php em_icon('clock'); ?></span><span><span class="em-stat__n em-num" id="statTime" style="font-size:.95rem">--:--:--</span><div class="em-stat__l">آخرین بروزرسانی</div></span></div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="card lg:col-span-2">
            <h3 class="font-bold mb-3 flex items-center justify-between gap-2 flex-wrap">
                <span>دانش‌آموزان در حال آزمون</span>
                <button type="button" onclick="refreshLive()" class="em-btn em-btn--ghost em-btn--sm"><?php em_icon('refresh'); ?> بروزرسانی</button>
            </h3>
            <div class="table-container em-scroll-x">
                <table class="em-table">
                    <thead><tr><th>دانش‌آموز</th><th>IP</th><th>موقعیت</th><th>دستگاه</th><th>رویدادها</th><th>اقدام</th></tr></thead>
                    <tbody id="liveTbody"><tr><td colspan="6" class="text-center text-muted py-6">در حال بارگذاری…</td></tr></tbody>
                </table>
            </div>
        </div>
        <div class="space-y-4">
            <div class="card">
                <h3 class="font-bold text-sm mb-2 flex items-center gap-1.5"><?php em_icon('mic'); ?> تذکر صوتی به دانش‌آموزان</h3>
                <div class="em-hint mb-2">دکمه را نگه دارید و صحبت کنید؛ با رها کردن، صدا ارسال و در دستگاه دانش‌آموز(ها) پخش می‌شود. حداکثر ۶۰ ثانیه.</div>
                <div class="mb-2">
                    <label class="em-hint font-bold">گیرنده:</label>
                    <select id="pttTarget" class="form-select text-xs" style="margin-top:3px">
                        <option value="0">همهٔ دانش‌آموزان حاضر در آزمون</option>
                    </select>
                </div>
                <button id="pttBtn" type="button" class="em-btn em-btn--brand em-btn--block" style="touch-action:none;user-select:none;-webkit-user-select:none">
                    <?php em_icon('mic'); ?> نگه دارید و صحبت کنید
                </button>
                <div id="pttStatus" class="em-hint mt-2 text-center">آماده</div>
            </div>
            <div class="card">
                <h3 class="font-bold text-sm mb-2">هشدار IP تکراری (تقلب احتمالی)</h3>
                <div id="sameIpBox" class="space-y-2 text-xs text-muted">-</div>
            </div>
            <div class="card">
                <h3 class="font-bold text-sm mb-2">هشدار نزدیکی مکانی (&lt;150 متر - از GPS گوشی بدون ماهواره)</h3>
                <div id="proximityBox" class="space-y-2 text-xs text-muted">-</div>
                <p class="em-hint mt-1">موقعیت از GPS گوشی دانش‌آموز گرفته می‌شود (حتی بدون ماهواره، با شبکه) و فاصلهٔ زیر ۱۵۰ متر هشدار می‌گیرد.</p>
            </div>
            <div class="card">
                <h3 class="font-bold text-sm mb-2 flex items-center gap-1.5"><?php em_icon('shield'); ?> رویدادهای اخیر پایش</h3>
                <div id="logsBox" class="max-h-[400px] overflow-auto text-[11px]">-</div>
            </div>
        </div>
    </div>
</div>

<!-- v4.129.0: تصویر وب‌کم در مودال تمام‌صفحه.
     چرا مودال و نه پنل شناور: روی گوشی، touchstart هدر با preventDefault
     کلیک مصنوعی دکمه‌های داخل همان هدر را لغو می‌کرد — یعنی «× بستن» و
     «ذخیره» روی موبایل هیچ کاری نمی‌کردند (روی دسکتاپ mousedown کلیک را
     لغو نمی‌کند، برای همین آنجا درست کار می‌کرد). -->
<div id="webcamModal" class="em-modal" role="dialog" aria-modal="true" aria-labelledby="webcamTitle">
    <div class="em-modal__bd" data-close></div>
    <div class="em-modal__panel">
        <div class="em-modal__head" id="webcamHeader">
            <span class="em-modal__title" id="webcamTitle">وب‌کم دانش‌آموز</span>
            <button type="button" class="em-x" data-close aria-label="بستن"><?php em_icon('x', 'ic ic-lg'); ?></button>
        </div>
        <div class="em-modal__body">
            <img id="webcamImg" class="em-shot" src="" alt="تصویر وب‌کم دانش‌آموز">
            <div class="em-hint mt-2" id="webcamMeta"></div>
        </div>
        <div class="em-modal__foot">
            <button type="button" id="saveWebcamBtn" class="em-btn em-btn--brand em-btn--block"><?php em_icon('save'); ?> ذخیرهٔ دائمی</button>
            <button type="button" class="em-btn em-btn--ghost em-btn--block" data-close>بستن</button>
        </div>
    </div>
</div>

<!-- v4.129.0: تخلفات هم در مودال. پیش از این window.open(...,'width=600,height=600')
     بود که مرورگر گوشی آن را می‌بندد (popup blocker) و w برابر null می‌شد،
     پس w.document.write خطا می‌داد و هیچ چیزی باز نمی‌شد. -->
<div id="logsModal" class="em-modal" role="dialog" aria-modal="true">
    <div class="em-modal__bd" data-close></div>
    <div class="em-modal__panel">
        <div class="em-modal__head">
            <span class="em-modal__title" id="logsTitle">رویدادهای پایش</span>
            <button type="button" class="em-x" data-close aria-label="بستن"><?php em_icon('x', 'ic ic-lg'); ?></button>
        </div>
        <div class="em-modal__body" id="logsModalBody"></div>
    </div>
</div>

<script>
let examId = <?php echo $examId; ?>;
let currentSnapshotId = null;
let currentSnapshotPath = null;
let streamInterval = null;

/* v4.129.0 — برچسب فارسی + شدتِ هر رویداد.
   پیش از این هر نوع رویدادی که در این جدول نبود (مثل voice_note_played یا
   location_acquired) عیناً به‌صورت کد خام لاتین نشان داده می‌شد. حالا اگر
   نوع ناشناخته باشد، یک برچسب فارسی عمومی برمی‌گردد، نه کد خام. */
const EM_EVENTS = {
    'tab_hidden':                   ['خروج از صفحهٔ آزمون / جابجایی تب', 'bad'],
    'tab_visible':                  ['بازگشت به صفحهٔ آزمون', 'ok'],
    'window_blurred':               ['خارج شدن از فوکوس پنجره', 'warn'],
    'window_focused':               ['بازگشت فوکوس به پنجره', 'ok'],
    'fullscreen_exit':              ['خروج از حالت تمام‌صفحه', 'warn'],
    'fullscreen_enter':             ['ورود به حالت تمام‌صفحه', 'ok'],
    'copy_attempt':                 ['تلاش برای کپی متن', 'bad'],
    'paste_attempt':                ['تلاش برای چسباندن متن', 'warn'],
    'right_click':                  ['کلیک راست (مسدود شد)', 'warn'],
    'contextmenu_blocked':          ['منوی کلیک راست مسدود شد', 'warn'],
    'printscreen':                  ['فشردن کلید چاپ صفحه', 'bad'],
    'permission_revoked_camera':    ['دوربین قطع شد', 'bad'],
    'permission_revoked_mic':       ['میکروفون قطع شد', 'warn'],
    'permission_revoked_location':  ['موقعیت مکانی قطع شد', 'warn'],
    'permission_granted_camera':    ['دوربین دوباره وصل شد', 'ok'],
    'permission_granted_mic':       ['میکروفون وصل شد', 'ok'],
    'permission_granted_location':  ['موقعیت مکانی وصل شد', 'ok'],
    'ip_changed':                   ['آدرس IP عوض شد', 'warn'],
    'location_changed':             ['موقعیت تغییر کرد', 'warn'],
    'page_unload':                  ['بستن / ترک صفحهٔ آزمون', 'warn'],
    'minimize':                     ['کوچک کردن پنجره', 'warn'],
    'location_acquired':            ['موقعیت با دقت بالا ثبت شد', 'ok'],
    'location_denied':              ['دسترسی موقعیت رد شد', 'warn'],
    'location_ip_fallback':         ['موقعیت از روی IP ثبت شد', 'ok'],
    'location_ip_manual_override':  ['ادامه با موقعیت IP', 'ok'],
    'location_watchdog':            ['موقعیت پس از مهلت تعیین شد', 'ok'],
    'location_resolved':            ['موقعیت نهایی ثبت شد', 'ok'],
    'mic_not_found_continue':       ['ادامه بدون میکروفون', 'ok'],
    'devtools_attempt':             ['تلاش برای باز کردن ابزار توسعه', 'bad'],
    'camera_snapshot_requested':    ['درخواست تصویر وب‌کم توسط مراقب', 'ok'],
    'voice_note_played':            ['پیام صوتی مراقب پخش شد', 'ok'],
    'voice_note_received':          ['پیام صوتی مراقب رسید', 'ok'],
};
function emEvent(type){
    return EM_EVENTS[type] || ['رویداد ثبت‌شده در آزمون', 'idle'];
}
function persianEventLabel(type){ return emEvent(type)[0]; }
function emEventLevel(type){ return emEvent(type)[1]; }
function esc(t){ return String(t==null?'':t).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function ic(name, cls){ return '<svg class="'+(cls||'ic')+'" aria-hidden="true"><use href="#i-'+name+'"></use></svg>'; }

/* ── مودال (v4.129.0) ────────────────────────────────────────────────
   یک پیاده‌سازی مشترک برای هر دو مودال: پس‌زمینه، ESC، دکمه‌های data-close،
   و قفل اسکرول صفحهٔ پشت. جای window.open و پنل شناور را گرفته. */
function emOpen(id){
    const el = document.getElementById(id); if(!el) return;
    el.classList.add('is-open');
    document.body.classList.add('em-lock');
    const x = el.querySelector('.em-x'); if(x) setTimeout(()=>x.focus(), 30);
}
function emClose(id){
    const el = document.getElementById(id); if(!el) return;
    el.classList.remove('is-open');
    document.body.classList.remove('em-lock');
    if(id==='webcamModal') clearWebcam();
}
document.addEventListener('click', function(e){
    const t = e.target.closest ? e.target.closest('[data-close]') : null;
    if(!t) return;
    const m = t.closest('.em-modal'); if(m) emClose(m.id);
});
document.addEventListener('keydown', function(e){
    if(e.key !== 'Escape') return;
    document.querySelectorAll('.em-modal.is-open').forEach(m=>emClose(m.id));
});

function refreshLive(){
    // Show loading only first time
    let tbodyEl = document.getElementById('liveTbody');
    if(tbodyEl.innerHTML.includes('در حال بارگذاری')) tbodyEl.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-6">در حال دریافت فهرست زنده…<div class="em-hint mt-1">اگر بیش از ۱۰ ثانیه طول کشید، «بروزرسانی» را بزنید یا <a href="online-exam-monitor-debug.php?exam_id='+examId+'" target="_blank">صفحهٔ تشخیص</a> را باز کنید.</div></td></tr>';
    
    fetch('online-exam-api.php?action=get_live_sessions&exam_id='+examId)
    .then(r=>{
        if(!r.ok) throw new Error('HTTP '+r.status);
        return r.json();
    })
    .then(j=>{
        if(!j.ok){ 
            document.getElementById('liveTbody').innerHTML='<tr><td colspan="6" class="text-center py-6"><span class="em-state is-bad">'+ic('alert')+' خطا: '+esc(j.msg)+'</span><div class="em-hint mt-1">جدول live_sessions خالی است یا خطای سرور — <a href="online-exam-monitor-debug.php?exam_id='+examId+'" target="_blank">صفحهٔ تشخیص</a></div></td></tr>';
            // Try fallback: get attempts in_progress directly
            return fetch('online-exam-api.php?action=get_attempts_in_progress&exam_id='+examId).then(r=>r.json()).then(j2=>{
                if(j2.ok && j2.data.attempts && j2.data.attempts.length>0){
                    let fallbackTbody='';
                    j2.data.attempts.forEach(a=>{
                        /* v4.129.0: ردیف جایگزین هم همان چیدمان ۶ ستونهٔ جدید را
                           می‌گیرد. پیش از این ۸ ستون داشت و نام خام را داخل
                           onclick می‌گذاشت. */
                        let who2 = esc(a.first_name+' '+a.last_name);
                        fallbackTbody+= `<tr class="is-stale">`
                          + `<td class="is-head">${who2}`
                            + `<div class="em-hint">${esc(a.class_name||'—')}</div>`
                            + `<div class="mt-1"><span class="em-state is-warn">`+ic('alert','ic ic-sm')+` بدون ضربان قلب</span></div>`
                          + `</td>`
                          + `<td data-label="IP"><span class="em-num">${esc(a.ip_address||'—')}</span></td>`
                          + `<td data-label="موقعیت">از روی IP (بدون GPS)</td>`
                          + `<td data-label="تلاش"><span class="em-num">${a.attempt_number||1}</span></td>`
                          + `<td data-label="رویدادها"><span class="em-num">خروج ${a.exit_count||0}</span></td>`
                          + `<td class="is-actions">`
                            + `<button type="button" data-cam="${a.id}" data-name="${who2}" class="em-btn em-btn--ghost em-btn--sm">`+ic('camera')+` وب‌کم</button>`
                            + `<a href="online-exam-result.php?attempt_id=${a.id}" class="em-btn em-btn--soft em-btn--sm">`+ic('doc')+` نتیجهٔ آزمون</a>`
                          + `</td></tr>`;
                    });
                    document.getElementById('liveTbody').innerHTML=fallbackTbody;
                    document.getElementById('statLive').textContent = j2.data.attempts.length + ' (جایگزین)';
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
            let stale = s.is_stale ? 'class="is-stale"' : '';
            /* وضعیت دستگاه به‌جای ✅/❌ — ایموجی بین پلتفرم‌ها متفاوت رندر
               می‌شود و هم‌ترازی‌اش قابل کنترل نیست؛ اینجا آیکون svg است. */
            let dev = '<span class="em-state '+(s.camera_ok==1?'is-ok':'is-bad')+'">'+ic('camera','ic ic-sm')+' دوربین</span> '
                    + '<span class="em-state '+(s.mic_ok==1?'is-ok':'is-bad')+'">'+ic('mic','ic ic-sm')+' میکروفون</span> '
                    + '<span class="em-state '+(s.location_ok==1?'is-ok':'is-bad')+'">'+ic('pin','ic ic-sm')+' موقعیت</span>'
                    + '<div class="em-hint mt-1">آخرین ضربان: '+esc(s.last_heartbeat_fa)+'</div>';
            let locText = (s.lat && s.lng) ? (parseFloat(s.lat).toFixed(5)+', '+parseFloat(s.lng).toFixed(5)) : 'از روی IP (بدون GPS)';
            let acc = s.accuracy ? s.accuracy+' متر' : '—';
            let who = esc(s.first_name+' '+s.last_name);
            tbody+= `<tr ${stale}>`
              + `<td class="is-head">${who}`
                + `<div class="em-hint">${esc(s.class_name||'—')} · ${esc(s.national_id)}</div>`
                + `<div class="mt-1">${s.is_stale
                    ? '<span class="em-state is-bad">'+ic('alert','ic ic-sm')+' بی‌ارتباط</span>'
                    : '<span class="em-state is-ok is-live">آنلاین</span>'}</div>`
              + `</td>`
              + `<td data-label="IP"><span class="em-num">${esc(s.ip_address||'—')}</span><div class="em-hint">${esc(s.attempt_status||'')}</div></td>`
              + `<td data-label="موقعیت"><span class="em-num">${esc(locText)}</span><div class="em-hint">دقت: ${esc(acc)}</div></td>`
              + `<td data-label="دستگاه">${dev}</td>`
              + `<td data-label="رویدادها"><span class="em-num">خروج ${s.exit_count||0} · تب ${s.tab_switch_count||0} · کپی ${s.copy_attempts||0}</span></td>`
              /* v4.129.0: دکمهٔ «تخلفات فارسی» حذف شد — همان محتوا در صفحهٔ
                 نتیجه هست و دو دکمهٔ هم‌معنی فقط جا می‌گرفت. آن یکی هم که
                 می‌ماند، نامش «نتیجهٔ آزمون» شد. */
              + `<td class="is-actions">`
                + `<button type="button" data-cam="${s.attempt_id}" data-name="${who}" class="em-btn em-btn--ghost em-btn--sm">${ic('camera')} وب‌کم</button>`
                + `<a href="online-exam-results.php?attempt_id=${s.attempt_id}" class="em-btn em-btn--soft em-btn--sm">${ic('doc')} نتیجهٔ آزمون</a>`
              + `</td></tr>`;
        });
        if(!tbody) tbody='<tr><td colspan="6" class="text-center text-muted py-6">هیچ دانش‌آموزی در حال آزمون نیست</td></tr>';
        document.getElementById('liveTbody').innerHTML=tbody;

        let sameHtml=''; sameIp.forEach(g=>{ sameHtml+=`<div class="p-2 border rounded" style="background:rgba(239,68,68,.06);border-color:rgba(239,68,68,.25)"><b class="em-num">IP ${esc(g.ip)}</b><div class="em-hint">${esc(g.students.join('، '))}</div></div>`; });
        document.getElementById('sameIpBox').innerHTML = sameHtml||'<span class="em-state is-ok">موردی یافت نشد</span>';
        let proxHtml=''; proximity.forEach(p=>{ const bg=p.level==='critical'?'bg-red-50 border-red-300':(p.level==='warning'?'bg-orange-50 border-orange-300':'bg-amber-50');         proxHtml+=`<div class="p-2 border rounded ${bg}"><div class="font-bold">${esc(p.level_fa||'')}</div><b>${esc(p.student1)}</b> و <b>${esc(p.student2)}</b><div class="em-hint">فاصله: ${esc(p.distance_text)}</div></div>`; }); document.getElementById('proximityBox').innerHTML = proxHtml||('<span class="em-state is-ok">موردی یافت نشد</span><div class="em-hint mt-1">فقط موقعیت‌های GPS واقعی مقایسه می‌شوند؛ دانش‌آموزان با موقعیت IP در بخش «IP یکسان» بررسی می‌شوند.</div>');
    });

    fetch('online-exam-api.php?action=get_proctoring_logs&exam_id='+examId).then(r=>r.json()).then(j=>{
        if(!j.ok) return;
        let logs = j.data.logs||[]; let html='';
        logs.slice(0,50).forEach(l=>{
            const ev = emEvent(l.event_type);
            /* v4.129.0: کد خامِ لاتین و IP از ردیف حذف شد — IP ستون خودش را
               در جدول بالا دارد و تکرارش اینجا فقط شلوغی بود. */
            html += `<div class="em-log is-${ev[1]}">`
                 + `<span class="em-log__t">${esc(l.created_at||'')}</span>`
                 + `<span class="em-log__x"><span class="em-log__k">${esc(ev[0])}</span>`
                 + `<span class="em-log__d">${esc(l.first_name||'')} ${esc(l.last_name||'')}</span></span>`
                 + `</div>`;
        });
        document.getElementById('logsBox').innerHTML = html||'<span class="em-state is-ok">رویدادی ثبت نشده</span>';
    }).catch(e=>{
        document.getElementById('logsBox').innerHTML = '<span class="text-red-600">خطا در دریافت لاگ: '+e.message+'</span>';
    });
}
function refreshLiveWithErrorHandling(){
    try { refreshLive(); } catch(e){
        document.getElementById('liveTbody').innerHTML = '<tr><td colspan="6" class="py-4"><span class="em-state is-bad">'+ic('alert')+' خطای اسکریپت: '+esc(e.message)+'</span></td></tr>';
    }
}


/* v4.129.0: نام دانش‌آموز از data-* خوانده می‌شود. پیش از این داخل
   onclick درون‌خطی با ' ' بسته می‌شد و نامِ دارای نویسهٔ خاص اسکریپت را
   می‌شکست (و یک تزریق HTML هم بود). */
document.addEventListener('click', function(e){
    const b = e.target.closest ? e.target.closest('[data-cam]') : null;
    if(!b) return;
    requestWebcam(b.getAttribute('data-cam'), b.getAttribute('data-name')||'دانش‌آموز');
});

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
                    showWebcam(jj.data.request.snapshot_path, studentName, jj.data.request);
                }
                if(tries>20){ clearInterval(interval); alert('پاسخی دریافت نشد — دانش‌آموز آفلاین است یا دوربینش بسته است.'); }
            });
        },1500);
    });
}

function showWebcam(path, studentName, meta){
    fetch('online-exam-api.php?action=get_webcam_snapshots&attempt_id='+meta.attempt_id).then(r=>r.json()).then(j=>{
        let d = (j && j.data) || {};
        if(d.snapshots && d.snapshots.length>0){
            let latest = d.snapshots[0];
            currentSnapshotId = latest.id;
            document.getElementById('webcamImg').src = latest.file_path + '?t='+Date.now();
            document.getElementById('webcamTitle').textContent = studentName;
            document.getElementById('webcamMeta').textContent =
                'زمان تصویر: '+latest.created_at+' · کیفیت پایین · تا ۱۰ دقیقه می‌ماند مگر ذخیره شود';
        } else {
            currentSnapshotId = null;
            document.getElementById('webcamImg').src = path;
            document.getElementById('webcamTitle').textContent = studentName;
            document.getElementById('webcamMeta').textContent = '';
        }
        /* v4.129.0: مودال باز می‌شود، نه پنل شناور. تا وقتی بسته نشود،
           اسکرول صفحهٔ پشت قفل است (body.em-lock). */
        emOpen('webcamModal');
    }).catch(function(err){
        document.getElementById('webcamMeta').textContent = 'خطا در دریافت تصویر: '+err.message;
        emOpen('webcamModal');
    });
}

function clearWebcam(){
    document.getElementById('webcamImg').src='';
    document.getElementById('webcamMeta').textContent='';
    if(streamInterval) clearInterval(streamInterval);
    currentSnapshotId=null;
}
/* نام قدیمی هم نگه داشته می‌شود تا اگر جای دیگری صدا زده می‌شد نشکند */
function closeWebcamFloat(){ emClose('webcamModal'); }
function showWebcamFloat(path, studentName, meta){ return showWebcam(path, studentName, meta); }

document.getElementById('saveWebcamBtn').onclick = function(){
    if(!currentSnapshotId) return alert('تصویری انتخاب نشده');
    let fd = new FormData(); fd.append('action','save_webcam_snapshot'); fd.append('snapshot_id',currentSnapshotId);
    fetch('online-exam-api.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{ if(j.ok) alert('تصویر ذخیره دائمی شد'); else alert(j.msg); });
};

/* v4.129.0 — تخلفات در مودال درون‌صفحه.
   پیش از این window.open('','_blank','width=600,height=600') بود. مرورگر
   گوشی هم پنجرهٔ با ابعاد را پشتیبانی نمی‌کند و هم مسدودکنندهٔ بازشو
   آن را می‌بندد، پس w برابر null می‌شد و w.document.write خطا می‌داد؛
   برای همین روی گوشی هیچ اتفاقی نمی‌افتاد. */
function viewLogs(attemptId, studentName){
    const body = document.getElementById('logsModalBody');
    document.getElementById('logsTitle').textContent = studentName
        ? 'رویدادهای پایش — '+studentName : 'رویدادهای پایش';
    body.innerHTML = '<div class="em-hint">در حال دریافت…</div>';
    emOpen('logsModal');
    fetch('online-exam-api.php?action=get_proctoring_logs&attempt_id='+attemptId)
    .then(r=>r.json()).then(j=>{
        const logs = (j && j.data && j.data.logs) || [];
        if(!logs.length){ body.innerHTML = '<span class="em-state is-ok">رویدادی ثبت نشده</span>'; return; }
        /* فقط متن فارسی رویداد + زمان. کد خامِ لاتین، IP و مختصات نشان
           داده نمی‌شود. */
        body.innerHTML = logs.map(function(l){
            const ev = emEvent(l.event_type);
            return '<div class="em-log is-'+ev[1]+'">'
                 + '<span class="em-log__t">'+esc(l.created_at||'')+'</span>'
                 + '<span class="em-log__x"><span class="em-log__k">'+esc(ev[0])+'</span></span>'
                 + '</div>';
        }).join('');
    }).catch(function(err){
        body.innerHTML = '<div class="em-state is-bad">خطا در دریافت رویدادها</div><div class="em-hint mt-1">'+esc(err.message)+'</div>';
    });
}

/* v4.129.0 — کد درگِ پنل شناور حذف شد؛ همان علت بسته‌نشدن وب‌کم روی گوشی بود.

   چرا: onStart روی touchstart با {passive:false} ثبت شده بود و e.preventDefault()
   صدا می‌زد. preventDefault روی touchstart، «کلیک مصنوعی» بعدی را لغو می‌کند —
   و چون دکمه‌های «× بستن» و «ذخیره» *داخل* همان هدر بودند، لمسشان روی گوشی
   هرگز onclick را اجرا نمی‌کرد. روی دسکتاپ mousedown کلیک را لغو نمی‌کند،
   به همین دلیل آنجا سالم کار می‌کرد و تفاوت فقط روی موبایل دیده می‌شد.

   حالا تصویر در مودال است که خودش جابه‌جا نمی‌شود، پس درگ معنا ندارد؛
   در عوض با پس‌زمینه، ESC، ضربدر و دکمهٔ «بستن» بسته می‌شود. */

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
        o.textContent=s.first_name+' '+s.last_name+(s.class_name?' ('+s.class_name+')':'');
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
        catch(err2){ pttStatus.textContent='دسترسی میکروفون رد شد: '+err2.message; return; }
    }
    pttChunks=[];
    if(!window.MediaRecorder){pttStatus.textContent='مرورگر از ضبط صدا پشتیبانی نمی‌کند';return;}
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
    pttBtn.textContent='در حال ضبط… (رها کنید تا ارسال شود)';
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
    pttBtn.textContent='نگه دارید و صحبت کنید';
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
        pttStatus.textContent=j.ok?((j.msg||j.message||'ارسال شد')+' — در ضربان بعدی (حداکثر ۱۵ ثانیه) پخش می‌شود'):('خطا در ارسال: '+(j.msg||j.message||'نامشخص'));
    }catch(e){
        pttStatus.textContent='خطا در ارسال: '+e.message;
    }
}
if(pttBtn){
    pttBtn.addEventListener('pointerdown',pttStart);
    pttBtn.addEventListener('pointerup',pttStop);
    pttBtn.addEventListener('pointerleave',()=>{if(pttActive)pttStop();});
    pttBtn.addEventListener('contextmenu',e=>e.preventDefault());
}
refreshLive();

/* v4.129.0 — دو هندلر «کلیک بیرون برای بستن» حذف شدند.
   هندلر دوم شرط‌های !closest('table') && !closest('.card') داشت؛ روی گوشی
   تقریباً هر نقطه‌ای از صفحه داخل یک .card است، پس عملاً هیچ‌وقت بسته
   نمی‌شد. کارِ بستن حالا به سامانهٔ مودال واگذار شده: لایهٔ پس‌زمینه
   (.em-modal__bd با data-close)، ESC، ضربدر و دکمهٔ «بستن». */
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
