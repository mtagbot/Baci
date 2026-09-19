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
/* حداقل استایل: بدون انیمیشن، سایه، گرادیان یا فیلتر (هیچ بار اضافه‌ای روی CPU/GPU).
   صفحه فقط سه چیز دارد: ساعت بالا، تصویر دوربین، و نتیجهٔ آخرین اسکن زیر دوربین. */
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Tahoma,sans-serif;background:#111;color:#eee;height:100vh;display:flex;flex-direction:column;overflow:hidden}
.hdr{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:6px 12px;background:#1c1c1c;border-bottom:1px solid #333}
.hdr .sch{font-size:.85rem;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hdr a{color:#64748b;font-size:.62rem;text-decoration:none;white-space:nowrap}
.hdr .clock{font-size:2rem;font-weight:800;color:#4cc2ff;font-variant-numeric:tabular-nums;line-height:1}
.main{flex:1;display:flex;flex-direction:column;min-height:0}
.camwrap{position:relative;background:#000;flex:1;min-height:240px;overflow:hidden}
video{width:100%;height:100%;object-fit:cover;display:block}
.cammsg{position:absolute;bottom:0;left:0;right:0;padding:6px;text-align:center;font-size:.75rem;background:rgba(0,0,0,.62)}
.corner{position:absolute;top:8px;z-index:5;background:rgba(28,28,28,.9);border:1px solid #333;color:#eee;padding:7px 11px;font-family:inherit;font-size:.72rem;font-weight:700;cursor:pointer;display:none}
#camSwitch{left:8px}
#camRetry{left:8px;top:auto;bottom:44px}
#opticsTorch{right:8px}
#opticsTorch:disabled{opacity:.45}
.camlabel{position:absolute;top:8px;right:8px;z-index:4;background:rgba(0,0,0,.55);padding:4px 8px;font-size:.6rem;color:#94a3b8;max-width:42%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:none}
.result{background:#1c1c1c;padding:12px 16px;text-align:center;border-top:4px solid #333;display:flex;align-items:center;justify-content:center;gap:14px;min-height:96px}
.result.ok{border-top-color:#10b981;background:#0d2a1f}
.result.warn{border-top-color:#f59e0b;background:#2a230d}
.result.err{border-top-color:#ef4444;background:#2a0d0d}
.result .icon{font-size:40px;line-height:1}
.result .rname{font-size:1.7rem;font-weight:800;line-height:1.25}
.result .rstat{font-size:1.05rem;font-weight:700}
.result.ok .rstat{color:#34d399}.result.warn .rstat{color:#fbbf24}.result.err .rstat{color:#f87171}
.result .rsub{font-size:.75rem;color:#94a3b8}
.rtext{text-align:right;min-width:0}
#resSub b{display:block;font-weight:800}
.sndhint{position:absolute;bottom:34px;left:0;right:0;text-align:center;font-size:.6rem;color:#94a3b8;pointer-events:none}
</style>
<?php echo app_appearance_head(); ?>
<link rel=stylesheet href=assets/css/school-ui.css?v20260917d><script defer src=assets/js/school-icons.js?v20260917d></script><script defer src=assets/js/school-ui.js?v20260917d></script></head>
<body class="ui-scanner">
<div class="hdr">
    <div class="sch"><?php echo clean($schoolName); ?></div>
    <div class="clock" id="clock">--:--:--</div>
    <a href="<?php echo clean('attendance-scanner.php?scanner=legacy' . ($byKey ? '&key=' . rawurlencode($key) : '')); ?>">اسکنر قبلی</a>
</div>
<div class="main">
    <div class="camwrap">
        <video id="cam" playsinline webkit-playsinline muted autoplay></video>
        <button type="button" class="corner" id="camSwitch">تعویض دوربین</button>
        <button type="button" class="corner" id="camRetry">تلاش مجدد</button>
        <button type="button" class="corner" id="opticsTorch" disabled>چراغ</button>
        <div class="camlabel" id="camLabel"></div>
        <div class="cammsg" id="camMsg">در حال راه‌اندازی دوربین…</div>
        <div class="sndhint" id="sndHint">برای صدای تأیید، یک بار صفحه را لمس کنید</div>
        <div id="opticsStatus" style="display:none" aria-hidden="true"></div>
    </div>
    <div class="result" id="resBox">
        <div class="icon" id="resIcon"><svg data-ui-icon="camera" class="school-icon" viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M8 6 9 3h6l1 3h5v15H3V6Z"/><circle cx="12" cy="13" r="4"/></svg></div>
        <div class="rtext">
            <div class="rname" id="resName">آمادهٔ اسکن</div>
            <div class="rstat" id="resStat">تگ را وسط تصویر، حدود ۵ تا ۲۰ سانتی‌متر بگیرید</div>
            <div class="rsub" id="resSub"></div>
        </div>
    </div>
</div>
<canvas id="qrCanvas" style="display:none"></canvas>
<script>
window.ATT_SCANNER_CONFIG = {
  key: <?php echo json_encode($byKey ? $key : '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
  api: 'attendance-scan-api.php',
  worker: 'assets/js/attendance-decoder-worker.js?v=4.152.0-camera5',
  decoder: 'assets/js/jsqr.min.js'
};
</script>
<script src="assets/js/attendance-scanner-light.js?v=4.152.0-camera5"></script>
</body>
</html>
