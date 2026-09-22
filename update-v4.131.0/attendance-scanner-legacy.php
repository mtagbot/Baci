<?php
// File: attendance-scanner.php  (v4.59.0)
/**
 * Full-screen QR attendance scanner kiosk.
 * Open on the gate device: attendance-scanner.php?key=SCANNER_KEY
 * (the key is shown in attendance.php). A logged-in admin/deputy can open
 * it without the key. Camera runs locally; decoding via bundled jsQR.
 * v4.56.0: robust send (text/plain simple request, timeout+retry, exact errors).
 * v4.58.0: camera switching (multi-camera devices, remembered choice) +
 *          full-frame high-sensitivity detection: whole frame + center-zoom +
 *          rotating quadrant passes, attemptBoth (inverted tags), any rotation,
 *          1080p capture, continuous autofocus when supported.
 * v4.59.0: real buzzer sounds — rising accept chime, mid warn beeps,
 *          harsh low reject buzzer + vibration; audio unlocked on first touch.
 * v4.94.0: ULTRA-LIGHT mode — all decorative visuals removed (scanline
 *          animation, reticle, flash overlay, blur, shadows, gradients);
 *          nothing runs on the GPU/CPU except the camera preview and the
 *          decoder. Faster scan cadence. Function over form.
 * v4.92.0: lightweight adaptive scan loop for weak devices (low-RAM/CPU
 *          phones): decode work is throttled to the device speed, low-end
 *          mode lowers analysis/capture resolution, canvas is reused, and
 *          status polling pauses while hidden.
 *          Robust resume: camera + scanning self-heal after Home button /
 *          tab switch / screen off via visibilitychange, pageshow, focus,
 *          track-ended handlers and a frozen-frame watchdog.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/attendance_helpers.php';

$key = trim((string)($_GET['key'] ?? ''));
$storedKey = get_setting('attendance_scanner_key', '');
$byKey = ($storedKey !== '' && $key !== '' && hash_equals($storedKey, $key));
$bySession = !empty($_SESSION['admin_id']) || !empty($_SESSION['teacher_id']);
if (!$byKey && !$bySession) { http_response_code(403); die('دسترسی به اسکنر مجاز نیست. آدرس را با کلید اسکنر باز کنید.'); }
ensure_attendance_schema_v2();
$times = att_setting_times();
$schoolName = get_setting('school_name', 'آموزشگاه');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>اسکنر حضور و غیاب — <?php echo clean($schoolName); ?></title>
<style>
/* v4.94.0: حداقل استایل — بدون انیمیشن، سایه، گرادیان یا فیلتر (هیچ بار اضافه‌ای روی CPU/GPU) */
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Tahoma,sans-serif;background:#111;color:#eee;min-height:100vh;display:flex;flex-direction:column}
.hdr{display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:#1c1c1c;border-bottom:1px solid #333}
.hdr b{font-size:.9rem}
.hdr .clock{font-size:1.3rem;font-weight:800;color:#4cc2ff;font-variant-numeric:tabular-nums}
.hdr .rules{font-size:.65rem;color:#999;line-height:1.7;text-align:left}
.main{flex:1;display:grid;grid-template-columns:1fr 300px;gap:8px;padding:8px}
@media(max-width:860px){.main{grid-template-columns:1fr}}
.camwrap{position:relative;background:#000;overflow:hidden;min-height:320px}
video{width:100%;height:100%;object-fit:cover;display:block}
.cammsg{position:absolute;bottom:0;left:0;right:0;padding:8px;text-align:center;font-size:.75rem;background:rgba(0,0,0,.65)}
.side{display:flex;flex-direction:column;gap:8px}
.result{background:#1c1c1c;padding:14px;text-align:center;border:3px solid #333;min-height:150px;display:flex;flex-direction:column;justify-content:center;gap:4px}
.result.ok{border-color:#10b981;background:#0d2a1f}
.result.warn{border-color:#f59e0b;background:#2a230d}
.result.err{border-color:#ef4444;background:#2a0d0d}
.result .icon{font-size:38px;line-height:1}
.result .rname{font-size:1.2rem;font-weight:800}
.result .rstat{font-size:.9rem;font-weight:700}
.result.ok .rstat{color:#34d399}.result.warn .rstat{color:#fbbf24}.result.err .rstat{color:#f87171}
.result .rsub{font-size:.7rem;color:#999}
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}
.stat{background:#1c1c1c;padding:8px;text-align:center}
.stat b{display:block;font-size:1.3rem;font-variant-numeric:tabular-nums}
.stat span{font-size:.62rem;color:#999}
.stat.p b{color:#34d399}.stat.l b{color:#fbbf24}.stat.a b{color:#f87171}
.recent{background:#1c1c1c;padding:10px;flex:1;overflow:auto}
.recent h4{font-size:.72rem;color:#999;margin-bottom:6px}
.recent .row{display:flex;justify-content:space-between;align-items:center;padding:6px 3px;border-bottom:1px solid #2c2c2c;font-size:.75rem}
.recent .row:last-child{border-bottom:0}
.recent .who b{display:block}
.recent .who span{font-size:.62rem;color:#777}
.tagstat{font-size:.65rem;font-weight:700;padding:2px 8px}
.tagstat.p{color:#34d399}
.tagstat.l{color:#fbbf24}
.manual{background:#1c1c1c;padding:8px;display:flex;gap:6px}
.manual input{flex:1;background:#111;border:1px solid #333;color:#eee;padding:8px;font-family:inherit;font-size:.72rem;direction:ltr;text-align:left}
.manual button{background:#4cc2ff;border:0;color:#04263a;font-weight:800;padding:0 14px;cursor:pointer;font-family:inherit}
.beepnote{font-size:.6rem;color:#777;text-align:center}
.camswitch{position:absolute;top:8px;left:8px;z-index:5;background:#1c1c1c;border:1px solid #333;color:#eee;padding:8px 12px;font-family:inherit;font-size:.72rem;font-weight:700;cursor:pointer;display:none}
.camlabel{position:absolute;top:8px;right:8px;z-index:5;background:rgba(0,0,0,.6);padding:5px 9px;font-size:.6rem;color:#999;max-width:46%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
</style>
</head>
<body>
<div class="hdr">
    <div><b>🎓 <?php echo clean($schoolName); ?></b><div style="font-size:.68rem;color:#94a3b8">اسکنر هوشمند حضور و غیاب</div></div>
    <div class="clock" id="clock">--:--:--</div>
    <div class="rules">
        حضور تا ساعت <b style="color:#34d399"><?php echo tr_num($times['present_until'], 'fa'); ?></b><br>
        تأخیر تا ساعت <b style="color:#fbbf24"><?php echo tr_num($times['absent_at'], 'fa'); ?></b> — بعد از آن: غیبت خودکار
    </div>
</div>
<div class="main">
    <div class="camwrap">
        <video id="cam" playsinline muted></video>
        <button type="button" class="camswitch" id="camSwitch">تعویض دوربین</button>
        <div class="camlabel" id="camLabel" style="display:none"></div>
        <div class="cammsg" id="camMsg">در حال راه‌اندازی دوربین...</div>
    </div>
    <div class="side">
        <div class="result" id="resBox">
            <div class="icon" id="resIcon">📷</div>
            <div class="rname" id="resName">آماده اسکن</div>
            <div class="rstat" id="resStat">تگ QR خود را مقابل دوربین بگیرید</div>
            <div class="rsub" id="resSub"></div>
        </div>
        <div class="stats">
            <div class="stat p"><b id="stP">۰</b><span>حضور</span></div>
            <div class="stat l"><b id="stL">۰</b><span>تأخیر</span></div>
            <div class="stat a"><b id="stA">۰</b><span>غیبت</span></div>
        </div>
        <div class="recent"><h4>آخرین ترددهای امروز</h4><div id="recentList"><div style="color:#475569;font-size:.72rem">هنوز ترددی ثبت نشده است.</div></div></div>
        <div class="manual">
            <input id="manualInp" placeholder="ورود دستی کد تگ (MTAG-ATT:...)">
            <button type="button" onclick="manualSubmit()">ثبت</button>
        </div>
        <div class="beepnote" id="sndHint">برای فعال شدن صدای بازر، یک بار صفحه را لمس کنید.</div>
        <div class="beepnote">این دستگاه را می‌توانید تمام روز روشن بگذارید — رأس ساعت <?php echo tr_num($times['absent_at'], 'fa'); ?> غیبت‌ها به‌صورت خودکار ثبت و به اولیا اطلاع داده می‌شود.</div>
    </div>
</div>
<canvas id="qrCanvas" style="display:none"></canvas>
<script src="assets/js/jsqr.min.js"></script>
<script>
(function(){
  var API = 'attendance-scan-api.php';
  var KEY = <?php echo json_encode($byKey ? $key : '', JSON_UNESCAPED_UNICODE); ?>;
  var video = document.getElementById('cam');
  var canvas = document.getElementById('qrCanvas');
  var ctx = canvas.getContext('2d', { willReadFrequently: true });
  var camMsg = document.getElementById('camMsg');
  var resBox = document.getElementById('resBox'), resIcon = document.getElementById('resIcon'),
      resName = document.getElementById('resName'), resStat = document.getElementById('resStat'), resSub = document.getElementById('resSub');
  var lastPayload = '', lastAt = 0, busy = false;

  function faDigits(s){ return String(s).replace(/\d/g, function(d){ return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
  setInterval(function(){
    var d = new Date();
    document.getElementById('clock').textContent = faDigits(
      ('0'+d.getHours()).slice(-2)+':'+('0'+d.getMinutes()).slice(-2)+':'+('0'+d.getSeconds()).slice(-2));
  }, 1000);

  /* ============================================================
     v4.59.0: real buzzer sounds (like POS/turnstile devices).
     - ACCEPT  : bright rising two-tone chime (E6→B6) — unmistakable "OK"
     - WARN    : two mid square beeps (late / duplicate)
     - REJECT  : harsh low double "BZZT" sawtooth buzzer — clearly negative
     - Mobile browsers block audio until a user gesture: we unlock the
       AudioContext on the first touch/click, and show a hint until then.
     ============================================================ */
  var actx = null;
  function audioCtx(){
    if (!actx) {
      try { actx = new (window.AudioContext || window.webkitAudioContext)(); } catch(e){ return null; }
    }
    if (actx && actx.state === 'suspended') { actx.resume().catch(function(){}); }
    return actx;
  }
  function unlockAudio(){
    var c = audioCtx();
    if (c) {
      /* play a silent blip so iOS/Android fully unlock the output */
      try {
        var o = c.createOscillator(), g = c.createGain();
        g.gain.value = 0.0001; o.connect(g); g.connect(c.destination);
        o.start(); o.stop(c.currentTime + 0.03);
      } catch(e){}
    }
    var hint = document.getElementById('sndHint');
    if (hint) hint.style.display = 'none';
    document.removeEventListener('touchstart', unlockAudio);
    document.removeEventListener('click', unlockAudio);
  }
  document.addEventListener('touchstart', unlockAudio, { once: true });
  document.addEventListener('click', unlockAudio, { once: true });

  /* one tone with attack/decay envelope */
  function tone(freq, startAt, dur, type, vol, slideTo){
    var c = audioCtx(); if (!c) return;
    try {
      var t0 = c.currentTime + startAt;
      var o = c.createOscillator(), g = c.createGain();
      o.type = type || 'sine';
      o.frequency.setValueAtTime(freq, t0);
      if (slideTo) o.frequency.exponentialRampToValueAtTime(slideTo, t0 + dur);
      g.gain.setValueAtTime(0.0001, t0);
      g.gain.exponentialRampToValueAtTime(vol || 0.5, t0 + 0.012);   // fast attack
      g.gain.setValueAtTime(vol || 0.5, t0 + dur * 0.7);
      g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);          // release
      o.connect(g); g.connect(c.destination);
      o.start(t0); o.stop(t0 + dur + 0.02);
    } catch(e){}
  }

  /* ✅ ACCEPT: rising two-tone chime — E6 then B6 (positive, bright) */
  function buzzOk(){
    tone(1318.5, 0,    0.09, 'sine', 0.55);
    tone(1975.5, 0.09, 0.22, 'sine', 0.55);
  }
  /* ⏰ WARN (late/duplicate): two mid beeps — attention, not failure */
  function buzzWarn(){
    tone(740, 0,    0.14, 'square', 0.30);
    tone(740, 0.22, 0.14, 'square', 0.30);
  }
  /* ❌ REJECT/ERROR: harsh low double BZZT — sawtooth + downward slide */
  function buzzErr(){
    tone(160, 0,    0.28, 'sawtooth', 0.60, 110);
    tone(160, 0.34, 0.34, 'sawtooth', 0.60, 90);
  }

  function showResult(kind, icon, name, stat, sub){
    resBox.className = 'result ' + kind;
    resIcon.textContent = icon; resName.textContent = name; resStat.textContent = stat; resSub.textContent = sub || '';
    if (kind === 'ok') buzzOk();
    else if (kind === 'warn') buzzWarn();
    else buzzErr();
    if (navigator.vibrate) {
      try { navigator.vibrate(kind === 'ok' ? 80 : kind === 'warn' ? [80,60,80] : [180,80,180]); } catch(e){}
    }
  }

  /* v4.56.0: robust send —
     - text/plain body (a "simple request": no CORS preflight, and some
       mobile networks/hosts block application/json POSTs)
     - 20s timeout + one automatic retry on network failure
     - distinguishes real network failure from server errors (HTTP 500,
       login redirect, non-JSON output) so the message tells the truth */
  function postScan(payload, timeoutMs){
    var ac = ('AbortController' in window) ? new AbortController() : null;
    var tm = ac ? setTimeout(function(){ ac.abort(); }, timeoutMs) : null;
    return fetch(API, {
      method: 'POST',
      headers: {'Content-Type': 'text/plain;charset=UTF-8'},
      body: JSON.stringify({ action: 'scan', payload: payload, key: KEY }),
      cache: 'no-store',
      signal: ac ? ac.signal : undefined
    }).then(function(r){
      if (tm) clearTimeout(tm);
      return r.text().then(function(t){
        var j = null;
        try { j = JSON.parse(t); } catch(e) {}
        if (!j) {
          var msg = r.ok ? 'پاسخ سرور قابل خواندن نیست (خروجی غیر JSON)' : ('خطای سرور HTTP ' + r.status);
          throw { server: true, message: msg };
        }
        return j;
      });
    }).catch(function(err){
      if (tm) clearTimeout(tm);
      throw err;
    });
  }

  function sendScan(payload){
    if (busy) return;
    busy = true;
    postScan(payload, 20000)
      .catch(function(err){
        if (err && err.server) throw err;
        /* network hiccup — one automatic retry */
        return postScan(payload, 20000);
      })
      .then(function(j){
        if (j.ok && j.code === 'present') showResult('ok', '✅', j.student, 'حضور ثبت شد — ' + j.time, j['class'] + ' — ' + j.message);
        else if (j.ok && j.code === 'late') showResult('warn', '⏰', j.student, j.status + ' — ' + j.time, j['class'] + ' — ' + j.message);
        else if (j.code === 'duplicate') showResult('warn', '🔁', j.student || 'تکراری', 'قبلاً ثبت شده: ' + (j.status || ''), j.message);
        else showResult('err', '❌', 'ناموفق', j.message || 'کد نامعتبر', '');
        refreshStatus();
      })
      .catch(function(err){
        if (err && err.server) showResult('err', '⚠️', 'خطای سرور', err.message, 'اسکن دوباره را امتحان کنید');
        else if (err && err.name === 'AbortError') showResult('err', '📡', 'پاسخ سرور دیر شد', 'سرور در ۲۰ ثانیه پاسخ نداد — دوباره اسکن کنید', 'ممکن است ثبت انجام شده باشد؛ اسکن مجدد «تکراری» نشان می‌دهد');
        else showResult('err', '📡', 'خطای شبکه', 'اتصال به سرور برقرار نشد — اینترنت/آنتن را بررسی کنید', 'دوباره اسکن کنید');
      })
      .finally(function(){ setTimeout(function(){ busy = false; }, 600); });
  }

  window.manualSubmit = function(){
    var v = document.getElementById('manualInp').value.trim();
    if (v) { sendScan(v); document.getElementById('manualInp').value = ''; }
  };
  document.getElementById('manualInp').addEventListener('keydown', function(e){ if (e.key === 'Enter') window.manualSubmit(); });

  /* ============================================================
     v4.58.0: full-frame, high-sensitivity detection.
     - The WHOLE camera frame is analysed — the square is only a
       visual guide; the tag can be anywhere in the picture.
     - inversionAttempts:'attemptBoth' => inverted (negative) tags
       are read too; jsQR is rotation-invariant by design, so any
       orientation (upside-down / sideways / tilted) works.
     - Multi-pass pyramid per frame: full frame at high resolution,
       then center crop (2x zoom, far/small tags), then 4 overlapping
       quadrant crops on rotating frames (tiny tags near edges).
     ============================================================ */
  /* ============================================================
     v4.92.0: adaptive lightweight scan loop.
     - Old loop ran up to 3 heavy jsQR decodes per animation frame —
       on weak phones (little RAM / slow CPU) that froze the page.
     - New loop runs exactly ONE decode per tick, and rotates the
       crop pattern across ticks (full → center-zoom → full →
       quadrant), so sensitivity stays but per-tick cost drops ~3x.
     - Low-end devices (RAM ≤ 3GB or ≤ 4 cores, or slow measured
       decode) get a lower analysis resolution and a longer pause
       between decodes; fast devices keep the full quality.
     - The interval self-tunes: after every decode we measure how
       long it took and rest ~1.5x that time, so the CPU is never
       saturated and the UI/camera preview stays smooth everywhere.
     ============================================================ */
  var LOW_END = (function(){
    try {
      var mem = navigator.deviceMemory || 0;           // GB (Chrome/Android)
      var cores = navigator.hardwareConcurrency || 0;
      if (mem && mem <= 3) return true;
      if (cores && cores <= 4) return true;
    } catch(e){}
    return false;
  })();
  var FULL_DIM = LOW_END ? 560 : 900;   // analysis resolution
  var BASE_REST = LOW_END ? 150 : 55;   // v4.94.0: faster cadence — page has no visual load anymore
  var passCounter = 0;
  var scanTimer = null;
  var scanningActive = false;

  function tryDecode(sx, sy, sw, sh, outDim){
    var ratio = sw / sh;
    var cw = outDim, ch = Math.round(outDim / ratio);
    if (ch > outDim) { ch = outDim; cw = Math.round(outDim * ratio); }
    if (canvas.width !== cw) canvas.width = cw;        // resize only when needed
    if (canvas.height !== ch) canvas.height = ch;
    ctx.imageSmoothingEnabled = (sw > cw);             // smooth only when downscaling
    ctx.drawImage(video, sx, sy, sw, sh, 0, 0, cw, ch);
    var img = ctx.getImageData(0, 0, cw, ch);
    try { return jsQR(img.data, img.width, img.height, { inversionAttempts: 'attemptBoth' }); }
    catch(e){ return null; }
  }

  /* ONE decode per call; the crop pattern rotates across calls:
     even ticks = whole frame (tag can be anywhere),
     odd ticks alternate center-zoom (far/small tags) and a rotating
     overlapping quadrant (tiny tags near edges/corners). */
  function scanFrameOnce(){
    var w = video.videoWidth, h = video.videoHeight;
    if (!w || !h) return null;
    var p = passCounter++;
    if (p % 2 === 0) return tryDecode(0, 0, w, h, FULL_DIM);
    if (p % 4 === 1) {
      var cw = Math.round(w * 0.55), chh = Math.round(h * 0.55);
      return tryDecode(Math.round((w - cw) / 2), Math.round((h - chh) / 2), cw, chh, FULL_DIM);
    }
    var qw = Math.round(w * 0.62), qh = Math.round(h * 0.62);
    var q = (p >> 2) % 4;
    var qx = (q % 2) ? (w - qw) : 0;
    var qy = (q > 1) ? (h - qh) : 0;
    return tryDecode(qx, qy, qw, qh, FULL_DIM);
  }

  function scanTick(){
    scanTimer = null;
    if (!scanningActive) return;
    var rest = BASE_REST;
    if (!document.hidden && video.readyState === video.HAVE_ENOUGH_DATA && !busy) {
      var t0 = (window.performance && performance.now) ? performance.now() : Date.now();
      var code = scanFrameOnce();
      var t1 = (window.performance && performance.now) ? performance.now() : Date.now();
      /* adaptive rest: never hog the CPU — rest at least 1.5x the time
         the decode itself took (slow device => automatically calmer) */
      rest = Math.max(BASE_REST, Math.round((t1 - t0) * 1.5));
      if (code && code.data) {
        var now = Date.now();
        if (code.data !== lastPayload || now - lastAt > 5000) {
          lastPayload = code.data; lastAt = now;
          sendScan(code.data);
        }
      }
    }
    scanTimer = setTimeout(scanTick, rest);
  }

  function startScanLoop(){
    scanningActive = true;
    if (!scanTimer) scanTimer = setTimeout(scanTick, BASE_REST);
  }
  function stopScanLoop(){
    scanningActive = false;
    if (scanTimer) { clearTimeout(scanTimer); scanTimer = null; }
  }

  /* ============================================================
     v4.58.0: camera switching.
     - Lists all cameras after permission is granted.
     - «تعویض دوربین» cycles through them; choice is remembered.
     ============================================================ */
  var camList = [];
  var camIndex = -1;
  var currentStream = null;
  var camBtn = document.getElementById('camSwitch');
  var camLabel = document.getElementById('camLabel');

  function stopStream(){
    if (currentStream) {
      currentStream.getTracks().forEach(function(t){ try { t.stop(); } catch(e){} });
      currentStream = null;
    }
  }

  function listCams(){
    return navigator.mediaDevices.enumerateDevices().then(function(devs){
      camList = devs.filter(function(d){ return d.kind === 'videoinput'; });
      camBtn.style.display = camList.length > 1 ? 'block' : 'none';
      return camList;
    });
  }

  function camName(i){
    var d = camList[i];
    if (!d) return '';
    if (d.label) return d.label;
    return 'دوربین ' + faDigits(i + 1);
  }

  function startCam(deviceId){
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      camMsg.textContent = 'مرورگر از دوربین پشتیبانی نمی‌کند. از ورود دستی استفاده کنید.';
      return;
    }
    stopStream();
    camMsg.textContent = 'در حال راه‌اندازی دوربین...';
    /* v4.92.0: weak devices capture at 720p — less RAM, less CPU, faster decode */
    var capW = LOW_END ? 1280 : 1920, capH = LOW_END ? 720 : 1080;
    var videoC = deviceId
      ? { deviceId: { exact: deviceId }, width: {ideal: capW}, height: {ideal: capH} }
      : { facingMode: 'environment', width: {ideal: capW}, height: {ideal: capH} };
    navigator.mediaDevices.getUserMedia({ video: videoC, audio: false })
      .then(function(stream){
        currentStream = stream;
        video.srcObject = stream;
        video.setAttribute('playsinline', 'true');
        video.play().catch(function(){});
        camMsg.textContent = 'تگ را هر جای تصویر بگیرید — لازم نیست داخل کادر باشد';
        /* v4.92.0: if the OS kills the camera track (Home button, screen
           off, another app takes the camera), restart automatically the
           moment the page is visible again. */
        try {
          stream.getVideoTracks().forEach(function(t){
            t.onended = function(){ scheduleCamRecovery('دوربین توسط سیستم قطع شد — راه‌اندازی مجدد...'); };
          });
        } catch(e){}
        // continuous autofocus if the camera supports it (sharper = better decode)
        try {
          var track = stream.getVideoTracks()[0];
          var caps = track.getCapabilities ? track.getCapabilities() : {};
          if (caps.focusMode && caps.focusMode.indexOf('continuous') !== -1) {
            track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }).catch(function(){});
          }
          var st = track.getSettings ? track.getSettings() : {};
          if (st.deviceId) {
            try { localStorage.setItem('mtag_scanner_cam', st.deviceId); } catch(e){}
          }
        } catch(e){}
        // labels only become available after permission — refresh the list now
        listCams().then(function(){
          try {
            var track = stream.getVideoTracks()[0];
            var st = track.getSettings ? track.getSettings() : {};
            for (var i = 0; i < camList.length; i++) {
              if (camList[i].deviceId === st.deviceId) { camIndex = i; break; }
            }
            if (camList.length > 1) {
              camLabel.style.display = 'block';
              camLabel.textContent = camName(camIndex >= 0 ? camIndex : 0);
            }
          } catch(e){}
        });
        startScanLoop();
      })
      .catch(function(err){
        if (deviceId) { startCam(null); return; }   // saved camera unplugged — fall back
        camMsg.textContent = 'دسترسی به دوربین رد شد (' + err.name + '). از ورود دستی یا بارکدخوان USB استفاده کنید.';
      });
  }

  camBtn.addEventListener('click', function(){
    if (camList.length < 2) return;
    camIndex = (camIndex + 1) % camList.length;
    camLabel.style.display = 'block';
    camLabel.textContent = camName(camIndex);
    startCam(camList[camIndex].deviceId);
  });

  function refreshStatus(){
    fetch(API + '?action=status&key=' + encodeURIComponent(KEY))
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) return;
        document.getElementById('stP').textContent = faDigits(j.present);
        document.getElementById('stL').textContent = faDigits(j.late);
        document.getElementById('stA').textContent = faDigits(j.absent);
        var list = document.getElementById('recentList');
        if (j.recent && j.recent.length) {
          list.innerHTML = '';
          j.recent.forEach(function(r){
            var row = document.createElement('div'); row.className = 'row';
            var who = document.createElement('div'); who.className = 'who';
            var b = document.createElement('b'); b.textContent = r.name;
            var s = document.createElement('span'); s.textContent = r['class'] + ' — ' + r.time;
            who.appendChild(b); who.appendChild(s);
            var tag = document.createElement('span'); tag.className = 'tagstat ' + (r.late ? 'l' : 'p'); tag.textContent = r.status;
            row.appendChild(who); row.appendChild(tag);
            list.appendChild(row);
          });
        }
      }).catch(function(){});
  }

  /* ============================================================
     v4.92.0: bullet-proof resume after Home button / app switch /
     screen off / browser minimise.
     Mobile browsers freeze JS timers and often release the camera
     when the tab loses focus; when the user comes back the old
     <video> keeps showing a frozen frame and nothing scans.
     Strategy:
       1. on hide  → stop the scan loop + status polling (saves
          battery/CPU and releases pressure on weak devices).
       2. on show  → always restart the scan loop AND verify the
          camera track is really alive; if it is dead/muted/frozen,
          reopen the camera from scratch.
       3. listen to every \"came back\" signal browsers emit:
          visibilitychange, pageshow (bfcache restores), focus,
          and orientationchange.
       4. watchdog: every 4s while visible, if the video element
          has no fresh data (readyState < 2, or currentTime frozen
          while the track claims to be live), force a camera restart.
     ============================================================ */
  var savedCam = null;
  try { savedCam = localStorage.getItem('mtag_scanner_cam'); } catch(e){}

  var recoveryPending = false;
  function currentDeviceId(){
    if (camIndex >= 0 && camList[camIndex]) return camList[camIndex].deviceId;
    return savedCam;
  }
  function scheduleCamRecovery(msg){
    if (recoveryPending) return;
    recoveryPending = true;
    if (msg) camMsg.textContent = msg;
    setTimeout(function(){
      recoveryPending = false;
      if (document.hidden) return;              // wait for the next \"show\" signal
      startCam(currentDeviceId());
    }, 350);
  }
  function trackAlive(){
    if (!currentStream) return false;
    var ts = currentStream.getVideoTracks();
    if (!ts.length) return false;
    var t = ts[0];
    return t.readyState === 'live' && !t.muted;
  }
  function resumeAll(){
    if (document.hidden) return;
    var c = audioCtx(); if (c && c.state === 'suspended') { try { c.resume(); } catch(e){} }
    refreshStatus();
    startScanLoop();
    if (!trackAlive()) { scheduleCamRecovery('بازگشت به برنامه — راه‌اندازی مجدد دوربین...'); return; }
    /* track claims live: nudge the video element (iOS often needs it) */
    try { if (video.paused) video.play().catch(function(){}); } catch(e){}
  }
  function pauseAll(){
    stopScanLoop();
  }
  document.addEventListener('visibilitychange', function(){
    if (document.hidden) pauseAll(); else resumeAll();
  });
  window.addEventListener('pageshow', function(){ resumeAll(); });   // incl. bfcache restores
  window.addEventListener('focus', function(){ resumeAll(); });
  window.addEventListener('orientationchange', function(){ setTimeout(resumeAll, 400); });
  window.addEventListener('online', function(){ refreshStatus(); });

  /* frozen-frame watchdog */
  var lastVideoTime = -1, frozenCount = 0;
  setInterval(function(){
    if (document.hidden || recoveryPending) return;
    if (!currentStream) return;                  // camera never started / denied
    if (!trackAlive()) { scheduleCamRecovery('دوربین قطع شد — راه‌اندازی مجدد...'); return; }
    if (video.readyState < 2) { frozenCount++; }
    else if (video.currentTime === lastVideoTime) { frozenCount++; }
    else { frozenCount = 0; }
    lastVideoTime = video.currentTime;
    if (frozenCount >= 2) {                      // ~8s frozen → hard restart
      frozenCount = 0;
      scheduleCamRecovery('تصویر دوربین ثابت مانده — راه‌اندازی مجدد...');
    }
  }, 4000);

  startCam(savedCam);
  refreshStatus();
  /* v4.92.0: poll only while the page is visible (battery + data saver) */
  setInterval(function(){ if (!document.hidden) refreshStatus(); }, 30000);
})();
</script>
</body>
</html>
