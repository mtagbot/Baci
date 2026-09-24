<?php
// File: attendance-scanner.php  (v4.51.0)
/**
 * Full-screen QR attendance scanner kiosk.
 * Open on the gate device: attendance-scanner.php?key=SCANNER_KEY
 * (the key is shown in attendance.php). A logged-in admin/deputy can open
 * it without the key. Camera runs locally; decoding via bundled jsQR.
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
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Tahoma,'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh;display:flex;flex-direction:column}
.hdr{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;background:#1e293b;border-bottom:1px solid #334155}
.hdr b{font-size:1rem}
.hdr .clock{font-size:1.5rem;font-weight:800;color:#38bdf8;font-variant-numeric:tabular-nums}
.hdr .rules{font-size:.68rem;color:#94a3b8;line-height:1.8;text-align:left}
.main{flex:1;display:grid;grid-template-columns:1fr 340px;gap:14px;padding:14px}
@media(max-width:860px){.main{grid-template-columns:1fr}}
.camwrap{position:relative;background:#000;border-radius:16px;overflow:hidden;display:flex;align-items:center;justify-content:center;min-height:380px}
video{width:100%;height:100%;object-fit:cover;display:block}
.reticle{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none}
.reticle .box{width:min(58vw,300px);height:min(58vw,300px);border-radius:24px;position:relative}
.reticle .box::before,.reticle .box::after,.reticle .box i::before,.reticle .box i::after{content:'';position:absolute;width:44px;height:44px;border:5px solid #38bdf8;border-radius:6px}
.reticle .box::before{top:0;right:0;border-left:0;border-bottom:0}
.reticle .box::after{top:0;left:0;border-right:0;border-bottom:0}
.reticle .box i::before{bottom:0;right:0;border-left:0;border-top:0}
.reticle .box i::after{bottom:0;left:0;border-right:0;border-top:0}
.scanline{position:absolute;left:8%;right:8%;height:3px;background:linear-gradient(90deg,transparent,#38bdf8,transparent);animation:scan 2.2s ease-in-out infinite;border-radius:3px}
@keyframes scan{0%,100%{top:12%}50%{top:84%}}
.flash{position:absolute;inset:0;opacity:0;pointer-events:none;transition:opacity .18s}
.flash.ok{background:rgba(16,185,129,.42);opacity:1}
.flash.warn{background:rgba(245,158,11,.45);opacity:1}
.flash.err{background:rgba(239,68,68,.45);opacity:1}
.cammsg{position:absolute;bottom:0;left:0;right:0;padding:12px;text-align:center;font-size:.8rem;background:linear-gradient(transparent,rgba(0,0,0,.75))}
.side{display:flex;flex-direction:column;gap:12px}
.result{background:#1e293b;border-radius:16px;padding:18px;text-align:center;border:2px solid #334155;transition:border-color .2s;min-height:172px;display:flex;flex-direction:column;justify-content:center;gap:6px}
.result.ok{border-color:#10b981}.result.warn{border-color:#f59e0b}.result.err{border-color:#ef4444}
.result .icon{font-size:44px;line-height:1}
.result .rname{font-size:1.25rem;font-weight:800}
.result .rstat{font-size:.95rem;font-weight:700}
.result.ok .rstat{color:#34d399}.result.warn .rstat{color:#fbbf24}.result.err .rstat{color:#f87171}
.result .rsub{font-size:.72rem;color:#94a3b8}
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.stat{background:#1e293b;border-radius:12px;padding:10px;text-align:center}
.stat b{display:block;font-size:1.4rem;font-variant-numeric:tabular-nums}
.stat span{font-size:.65rem;color:#94a3b8}
.stat.p b{color:#34d399}.stat.l b{color:#fbbf24}.stat.a b{color:#f87171}
.recent{background:#1e293b;border-radius:16px;padding:12px;flex:1;overflow:auto}
.recent h4{font-size:.75rem;color:#94a3b8;margin-bottom:8px}
.recent .row{display:flex;justify-content:space-between;align-items:center;padding:7px 4px;border-bottom:1px solid #273449;font-size:.78rem}
.recent .row:last-child{border-bottom:0}
.recent .who b{display:block}
.recent .who span{font-size:.64rem;color:#64748b}
.tagstat{font-size:.68rem;font-weight:700;padding:2px 10px;border-radius:999px}
.tagstat.p{background:rgba(16,185,129,.15);color:#34d399}
.tagstat.l{background:rgba(245,158,11,.15);color:#fbbf24}
.manual{background:#1e293b;border-radius:12px;padding:10px;display:flex;gap:6px}
.manual input{flex:1;background:#0f172a;border:1px solid #334155;color:#e2e8f0;border-radius:8px;padding:9px;font-family:inherit;font-size:.75rem;direction:ltr;text-align:left}
.manual button{background:#38bdf8;border:0;color:#082f49;font-weight:800;border-radius:8px;padding:0 16px;cursor:pointer;font-family:inherit}
.beepnote{font-size:.62rem;color:#64748b;text-align:center}
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
        <div class="reticle"><div class="box"><i></i><div class="scanline"></div></div></div>
        <div class="flash" id="flash"></div>
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
  var flash = document.getElementById('flash');
  var resBox = document.getElementById('resBox'), resIcon = document.getElementById('resIcon'),
      resName = document.getElementById('resName'), resStat = document.getElementById('resStat'), resSub = document.getElementById('resSub');
  var lastPayload = '', lastAt = 0, busy = false;

  function faDigits(s){ return String(s).replace(/\d/g, function(d){ return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
  setInterval(function(){
    var d = new Date();
    document.getElementById('clock').textContent = faDigits(
      ('0'+d.getHours()).slice(-2)+':'+('0'+d.getMinutes()).slice(-2)+':'+('0'+d.getSeconds()).slice(-2));
  }, 500);

  function beep(freq, dur){
    try {
      var actx = beep.ctx || (beep.ctx = new (window.AudioContext || window.webkitAudioContext)());
      var o = actx.createOscillator(), g = actx.createGain();
      o.frequency.value = freq; o.type = 'sine';
      g.gain.setValueAtTime(.28, actx.currentTime);
      g.gain.exponentialRampToValueAtTime(.001, actx.currentTime + dur/1000);
      o.connect(g); g.connect(actx.destination); o.start(); o.stop(actx.currentTime + dur/1000);
    } catch(e){}
  }

  function showResult(kind, icon, name, stat, sub){
    resBox.className = 'result ' + kind;
    resIcon.textContent = icon; resName.textContent = name; resStat.textContent = stat; resSub.textContent = sub || '';
    flash.className = 'flash ' + (kind === 'ok' ? 'ok' : kind === 'warn' ? 'warn' : 'err');
    setTimeout(function(){ flash.className = 'flash'; }, 450);
    if (kind === 'ok') { beep(880, 140); }
    else if (kind === 'warn') { beep(600, 160); setTimeout(function(){ beep(600, 160); }, 200); }
    else { beep(240, 350); }
  }

  function sendScan(payload){
    if (busy) return;
    busy = true;
    fetch(API, { method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ action: 'scan', payload: payload, key: KEY }) })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (j.ok && j.code === 'present') showResult('ok', '✅', j.student, 'حضور ثبت شد — ' + j.time, j['class'] + ' — ' + j.message);
        else if (j.ok && j.code === 'late') showResult('warn', '⏰', j.student, j.status + ' — ' + j.time, j['class'] + ' — ' + j.message);
        else if (j.code === 'duplicate') showResult('warn', '🔁', j.student || 'تکراری', 'قبلاً ثبت شده: ' + (j.status || ''), j.message);
        else showResult('err', '❌', 'ناموفق', j.message || 'کد نامعتبر', '');
        refreshStatus();
      })
      .catch(function(){ showResult('err', '📡', 'خطای شبکه', 'اتصال به سرور برقرار نشد', ''); })
      .finally(function(){ setTimeout(function(){ busy = false; }, 900); });
  }

  window.manualSubmit = function(){
    var v = document.getElementById('manualInp').value.trim();
    if (v) { sendScan(v); document.getElementById('manualInp').value = ''; }
  };
  document.getElementById('manualInp').addEventListener('keydown', function(e){ if (e.key === 'Enter') window.manualSubmit(); });

  function tick(){
    if (video.readyState === video.HAVE_ENOUGH_DATA && !busy) {
      var w = video.videoWidth, h = video.videoHeight;
      if (w && h) {
        var scale = Math.min(1, 640 / w);
        canvas.width = Math.round(w * scale); canvas.height = Math.round(h * scale);
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
        var code = null;
        try { code = jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' }); } catch(e){}
        if (code && code.data) {
          var now = Date.now();
          if (code.data !== lastPayload || now - lastAt > 5000) {
            lastPayload = code.data; lastAt = now;
            sendScan(code.data);
          }
        }
      }
    }
    requestAnimationFrame(tick);
  }

  function startCam(){
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      camMsg.textContent = 'مرورگر از دوربین پشتیبانی نمی‌کند. از ورود دستی استفاده کنید.';
      return;
    }
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment', width: {ideal: 1280}, height: {ideal: 720} }, audio: false })
      .then(function(stream){
        video.srcObject = stream;
        video.setAttribute('playsinline', 'true');
        video.play();
        camMsg.textContent = 'تگ QR را داخل کادر بگیرید';
        requestAnimationFrame(tick);
      })
      .catch(function(err){
        camMsg.textContent = 'دسترسی به دوربین رد شد (' + err.name + '). از ورود دستی یا بارکدخوان USB استفاده کنید.';
      });
  }

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

  startCam();
  refreshStatus();
  setInterval(refreshStatus, 30000);
})();
</script>
</body>
</html>
