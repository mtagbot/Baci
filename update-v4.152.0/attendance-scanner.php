<?php
// Reversible scanner hotfix; legacy implementation is preserved byte-for-byte.
if (isset($_GET['scanner']) && $_GET['scanner'] === 'legacy') {
    require __DIR__ . '/attendance-scanner-legacy.php';
    exit;
}
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
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>اسکنر حضور و غیاب — <?php echo clean($schoolName); ?></title>
<style>
/* v4.94.0: حداقل استایل — بدون انیمیشن، سایه، گرادیان یا فیلتر (هیچ بار اضافه‌ای روی CPU/GPU) */
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Tahoma,sans-serif;background:#111;color:#eee;min-height:100vh;display:flex;flex-direction:column}
.hdr{display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:#1c1c1c;border-bottom:1px solid #333}
.hdr b{font-size:.9rem}
.hdr .clock{font-size:1.3rem;font-weight:800;color:#4cc2ff;font-variant-numeric:tabular-nums}
.hdr .rules{font-size:.65rem;color:#999;line-height:1.7;text-align:left}
.main{flex:1;display:block;display:grid;grid-template-columns:1fr 300px;gap:8px;padding:8px}
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
.camswitch:disabled{opacity:.5;cursor:wait}
.camswitch{position:absolute;top:8px;left:8px;z-index:5;background:#1c1c1c;border:1px solid #333;color:#eee;padding:8px 12px;font-family:inherit;font-size:.72rem;font-weight:700;cursor:pointer;display:none}
.camlabel{position:absolute;top:8px;right:8px;z-index:5;background:rgba(0,0,0,.6);padding:5px 9px;font-size:.6rem;color:#999;max-width:46%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.optics{background:#1c1c1c;padding:8px;font-size:.72rem;line-height:1.8}
.optics button{font:inherit;padding:5px;margin:3px;border:1px solid #555;background:#252525;color:#eee;cursor:pointer}
.optics button:disabled{opacity:.5;cursor:default}
.optics small{display:block;color:#bbb}
</style>
<?php echo app_appearance_head(); ?>
<link rel=stylesheet href=assets/css/school-ui.css?v20260917d><script defer src=assets/js/school-icons.js?v20260917d></script><script defer src=assets/js/school-ui.js?v20260917d></script></head>
<body class="ui-scanner">
<div class="hdr">
    <div><b><svg data-ui-icon="student" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m2 7 10-4 10 4-10 4Zm3 2v5c3 3 11 3 14 0V9M22 7v7M5 21c1-5 13-5 14 0"/></svg> <?php echo clean($schoolName); ?></b><div style="font-size:.68rem;color:#94a3b8">اسکنر هوشمند حضور و غیاب — سریع / فوکوس نزدیک</div><a style="color:#9cd8ff;font-size:.7rem" href="<?php echo clean('attendance-scanner.php?scanner=legacy' . ($byKey ? '&key=' . rawurlencode($key) : '')); ?>">بازگشت به اسکنر قبلی</a></div>
    <div class="clock" id="clock">--:--:--</div>
    <div class="rules">
        حضور تا ساعت <b style="color:#34d399"><?php echo tr_num($times['present_until'], 'fa'); ?></b><br>
        تأخیر تا ساعت <b style="color:#fbbf24"><?php echo tr_num($times['absent_at'], 'fa'); ?></b> — بعد از آن: غیبت خودکار
    </div>
</div>
<div class="main">
    <div class="camwrap">
        <video id="cam" playsinline webkit-playsinline muted></video>
        <button type="button" class="camswitch" id="camSwitch">تعویض دوربین</button>
        <button type="button" class="camswitch" id="camRetry" style="top:auto;bottom:55px">تلاش مجدد همین دوربین</button>
        <div class="camlabel" id="camLabel" style="display:none"></div>
        <div class="cammsg" id="camMsg">در حال راه‌اندازی دوربین...</div>
    </div>
    <div class="side">
        <div class="result" id="resBox">
            <div class="icon" id="resIcon"><svg data-ui-icon="camera" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M8 6 9 3h6l1 3h5v15H3V6Z"/><circle cx="12" cy="13" r="4"/></svg></div>
            <div class="rname" id="resName">آماده اسکن</div>
            <div class="rstat" id="resStat">تگ QR خود را مقابل دوربین بگیرید</div>
            <div class="rsub" id="resSub"></div>
        </div>
        <div class="optics">
            <label><input type="checkbox" id="opticsMode" checked disabled> فوکوس نزدیک ثابت / حالت حرکت</label><br>
            <button type="button" id="opticsFocus" disabled>تنظیم دوباره فوکوس</button>
            <button type="button" id="opticsTorch" disabled>روشن‌کردن چراغ</button>
            <div id="opticsStatus">بررسی قابلیت‌های دوربین پس از بازشدن تصویر…</div>
            <small id="opticsDetails">قفل بی‌نهایت برای فاصله ۱۰ تا ۱۵ سانتی‌متر استفاده نمی‌شود.</small>
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
<script>
window.ATT_SCANNER_CONFIG = {
  key: <?php echo json_encode($byKey ? $key : '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
  api: 'attendance-scan-api.php',
  worker: 'assets/js/attendance-decoder-worker.js?v=4.152.0-camera3',
  decoder: 'assets/js/jsqr.min.js'
};
</script>
<script src="assets/js/attendance-scanner-light.js?v=4.152.0-camera3"></script>
</body>
</html>
