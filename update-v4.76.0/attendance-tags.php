<?php
// File: attendance-tags.php  (v4.55.0)
/**
 * Printable QR attendance tags for students — with a LIVE tag designer.
 * v4.54.0: standalone print view (no site header, correct multi-page pagination).
 * v4.55.0: free size + live customization of all tag elements:
 *          size (20-100mm), gap, page margin, cut frame, center school plate
 *          (custom text + scale), optional student name / class / national id.
 * QR codes are rendered client-side with the bundled qrcode-generator lib.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/attendance_helpers.php';
require_once __DIR__ . '/includes/school_roles.php';
require_once __DIR__ . '/includes/school_sort.php';

$isAdminAtt = is_admin_logged_in() && has_permission('manage_students');
$isDeputyAtt = is_teacher_logged_in() && teacher_has_deputy($_SESSION['teacher_id'] ?? 0);
if (!$isAdminAtt && !$isDeputyAtt) redirect('admin-login.php?tab=teacher');
ensure_attendance_schema_v2();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['regen_tag'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { set_flash_message('error', 'خطای CSRF'); redirect('attendance-tags.php'); }
    att_regenerate_tag((int)$_POST['student_id']);
    set_flash_message('success', 'تگ جدید برای دانش‌آموز ساخته شد (تگ قبلی باطل شد).');
    redirect('attendance-tags.php?class=' . urlencode($_POST['back_class'] ?? ''));
}

$fClass = trim($_GET['class'] ?? '');
$year = get_setting('current_academic_year', '1404/1405');
$classes = get_unified_class_options($year);
$where = ["s.status='active'"]; $params = [];
// v4.76.0: QR tags are printed ONLY for students of the default academic year
list($tagYearSql, $tagYearParams) = att_year_sql('s');
$where[] = $tagYearSql; foreach ($tagYearParams as $typ) $params[] = $typ;
if ($fClass !== '') { $where[] = 's.class_name=?'; $params[] = $fClass; }
$students = DB::fetchAll("SELECT s.id, s.first_name, s.last_name, s.national_id, s.class_name, s.grade_level FROM students s WHERE " . implode(' AND ', $where), $params);
persian_usort_students($students);
foreach ($students as &$s) { $tag = att_get_or_create_tag((int)$s['id']); $s['qr'] = att_qr_payload((int)$s['id'], $tag['token']); }
unset($s);
$schoolName = get_setting('school_name', 'آموزشگاه');
$schoolLabel = get_setting('school_name_short', '') !== '' ? get_setting('school_name_short', '') : $schoolName;

/* ============================================================
 * Standalone print view — NO site template at all (v4.54.0),
 * now fully customizable via query params (v4.55.0):
 * attendance-tags.php?print=1&class=...&size=42&gap=4&margin=8
 *   &frame=none|dashed|solid&plate=1&ptext=...&pscale=40
 *   &sname=1&scls=1&snid=1
 * Plain block-flow HTML => all pages paginate naturally.
 * ============================================================ */
if (isset($_GET['print'])) {
    $D = [
        'size'   => min(100, max(20, (float)($_GET['size'] ?? 40))),
        'gap'    => min(20, max(0, (float)($_GET['gap'] ?? 4))),
        'margin' => min(25, max(0, (float)($_GET['margin'] ?? 8))),
        'frame'  => in_array(($_GET['frame'] ?? 'none'), ['none', 'dashed', 'solid'], true) ? $_GET['frame'] : 'none',
        'plate'  => ($_GET['plate'] ?? '1') === '1',
        'ptext'  => trim((string)($_GET['ptext'] ?? '')),
        'pscale' => min(55, max(25, (int)($_GET['pscale'] ?? 40))),
        'sname'  => ($_GET['sname'] ?? '0') === '1',
        'scls'   => ($_GET['scls'] ?? '0') === '1',
        'snid'   => ($_GET['snid'] ?? '0') === '1',
    ];
    if ($D['ptext'] === '') $D['ptext'] = $schoolLabel;
    $fsName = max(2.4, $D['size'] * 0.085);
    $fsSub  = max(2.1, $D['size'] * 0.068);
    ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title>چاپ تگ‌های QR</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
html,body{background:#fff}
/* Block flow + inline-block = reliable multi-page pagination in every browser
   (flex/grid containers are clipped to one page by some engines). */
.sheet{font-size:0;line-height:0}
.tag{width:<?php echo $D['size']; ?>mm;display:inline-block;vertical-align:top;text-align:center;
     margin:0 0 <?php echo $D['gap']; ?>mm <?php echo $D['gap']; ?>mm;
     page-break-inside:avoid;break-inside:avoid;
     <?php if ($D['frame'] !== 'none'): ?>border:0.3mm <?php echo $D['frame']; ?> #94a3b8;padding:1mm;<?php endif; ?>}
.qr-tag{width:100%;display:block}
.t-name{font-family:Tahoma,sans-serif;font-size:<?php echo round($fsName, 2); ?>mm;font-weight:700;color:#000;line-height:1.3}
.t-sub{font-family:Tahoma,sans-serif;font-size:<?php echo round($fsSub, 2); ?>mm;color:#000;line-height:1.3}
.toolbar{position:fixed;top:10px;left:10px;display:flex;gap:8px;font-family:Tahoma,sans-serif;z-index:9}
.toolbar button{padding:8px 18px;border:0;border-radius:8px;background:#2563eb;color:#fff;font-family:inherit;font-size:13px;cursor:pointer}
.toolbar span{background:#f1f5f9;border-radius:8px;padding:8px 14px;font-size:12px;color:#334155}
@media print{ .toolbar{display:none !important} @page{margin:<?php echo $D['margin']; ?>mm} }
</style>
</head>
<body>
<div class="toolbar no-print">
    <button onclick="window.print()">چاپ</button>
    <span>تعداد تگ: <?php echo tr_num(count($students), 'fa'); ?> — اندازه: <?php echo tr_num($D['size'], 'fa'); ?> میلی‌متر</span>
</div>
<div class="sheet">
<?php foreach ($students as $s): ?>
    <div class="tag">
        <canvas class="qr-tag" data-qr="<?php echo clean($s['qr']); ?>"></canvas>
        <?php if ($D['sname']): ?><div class="t-name"><?php echo clean($s['first_name'] . ' ' . $s['last_name']); ?></div><?php endif; ?>
        <?php if ($D['scls']): ?><div class="t-sub"><?php echo clean($s['class_name'] ?: '---'); ?></div><?php endif; ?>
        <?php if ($D['snid']): ?><div class="t-sub"><?php echo clean(tr_num($s['national_id'], 'fa')); ?></div><?php endif; ?>
    </div>
<?php endforeach; ?>
</div>
<script src="assets/js/qrcode-generator.js"></script>
<script>
(function(){
  var PLATE  = <?php echo $D['plate'] ? 'true' : 'false'; ?>;
  var PSCALE = <?php echo $D['pscale']; ?> / 100;
  var LABEL  = <?php echo json_encode($D['ptext'], JSON_UNESCAPED_UNICODE); ?>;
  function draw(cv){
    var qr = qrcode(0, 'H');
    qr.addData(cv.getAttribute('data-qr'));
    qr.make();
    var count = qr.getModuleCount(), quiet = 4, scale = 16;
    var size = (count + quiet * 2) * scale;
    cv.width = size; cv.height = size;
    var ctx = cv.getContext('2d');
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, size, size);
    ctx.fillStyle = '#000';
    for (var r = 0; r < count; r++)
      for (var c = 0; c < count; c++)
        if (qr.isDark(r, c)) ctx.fillRect((c + quiet) * scale, (r + quiet) * scale, scale, scale);
    if (PLATE && LABEL) {
      var label = LABEL.length > 18 ? LABEL.slice(0, 18) : LABEL;
      var boxW = Math.round(size * PSCALE), boxH = Math.round(boxW * 0.325);
      var bx = Math.round((size - boxW) / 2), by = Math.round((size - boxH) / 2);
      var rad = Math.round(boxH * 0.3);
      ctx.fillStyle = '#fff';
      ctx.beginPath();
      ctx.moveTo(bx + rad, by);
      ctx.arcTo(bx + boxW, by, bx + boxW, by + boxH, rad);
      ctx.arcTo(bx + boxW, by + boxH, bx, by + boxH, rad);
      ctx.arcTo(bx, by + boxH, bx, by, rad);
      ctx.arcTo(bx, by, bx + boxW, by, rad);
      ctx.closePath(); ctx.fill();
      ctx.strokeStyle = '#000'; ctx.lineWidth = Math.max(2, Math.round(scale / 6)); ctx.stroke();
      ctx.fillStyle = '#000'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      var fs = Math.round(boxH * 0.52);
      ctx.font = '700 ' + fs + 'px Tahoma, sans-serif';
      while (ctx.measureText(label).width > boxW * 0.88 && fs > 8) { fs--; ctx.font = '700 ' + fs + 'px Tahoma, sans-serif'; }
      ctx.fillText(label, size / 2, by + boxH / 2 + fs * 0.06);
    }
  }
  document.querySelectorAll('canvas.qr-tag[data-qr]').forEach(draw);
  /* auto-open print dialog once everything is rendered */
  window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 400); });
})();
</script>
</body>
</html>
    <?php
    exit;
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-6">
    <div class="page-hero flex justify-between items-center no-print">
        <div>
            <h2 class="text-2xl font-bold">تگ‌های QR حضور و غیاب</h2>
            <p class="text-sm text-muted">ویرایشگر زنده — اندازه و عناصر تگ را سفارشی کنید، پیش‌نمایش همان لحظه به‌روز می‌شود</p>
        </div>
        <div class="flex gap-2">
            <a href="attendance.php" class="btn btn-outline text-xs">بازگشت به حضور و غیاب</a>
            <button type="button" onclick="openPrintView()" class="btn btn-primary text-xs">چاپ تگ‌ها</button>
        </div>
    </div>

    <div class="card no-print">
        <form method="GET" class="grid grid-cols-4 gap-3 items-end">
            <div>
                <label class="text-xs">کلاس</label>
                <select name="class" class="form-select">
                    <option value="">همه کلاس‌ها</option>
                    <?php foreach ($classes as $c): ?><option value="<?php echo clean($c['class_name']); ?>" <?php echo $fClass === $c['class_name'] ? 'selected' : ''; ?>><?php echo clean($c['class_name']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-secondary">نمایش</button>
            <label class="flex items-center gap-2 text-xs font-semibold" style="padding-bottom:10px">
                <input type="checkbox" id="showIdent" onchange="toggleIdent()">
                نمایش اطلاعات شناسایی و دکمه «تگ جدید» (فقط روی صفحه)
            </label>
            <div class="text-xs text-muted">تعداد: <?php echo tr_num(count($students), 'fa'); ?> دانش‌آموز</div>
        </form>
    </div>

    <!-- ======== v4.55.0: ویرایشگر زنده تگ ======== -->
    <div class="card no-print" id="tagEditor">
        <div class="flex justify-between items-center" style="margin-bottom:12px">
            <h3 class="font-bold text-sm">ویرایشگر زنده تگ</h3>
            <button type="button" class="btn btn-outline text-xs" onclick="resetDesign()">بازنشانی به پیش‌فرض</button>
        </div>
        <div class="grid grid-cols-4 gap-4">
            <div>
                <label class="text-xs">اندازه تگ: <b><span id="vSize">۴۰</span> میلی‌متر</b></label>
                <input type="range" id="dSize" min="20" max="100" step="1" value="40" style="width:100%" oninput="applyDesign()">
            </div>
            <div>
                <label class="text-xs">فاصله بین تگ‌ها: <b><span id="vGap">۴</span> میلی‌متر</b></label>
                <input type="range" id="dGap" min="0" max="20" step="1" value="4" style="width:100%" oninput="applyDesign()">
            </div>
            <div>
                <label class="text-xs">حاشیه صفحه چاپ: <b><span id="vMargin">۸</span> میلی‌متر</b></label>
                <input type="range" id="dMargin" min="0" max="25" step="1" value="8" style="width:100%" oninput="applyDesign()">
            </div>
            <div>
                <label class="text-xs">کادر دور تگ (راهنمای برش)</label>
                <select id="dFrame" class="form-select" onchange="applyDesign()">
                    <option value="none">بدون کادر</option>
                    <option value="dashed">خط‌چین</option>
                    <option value="solid">خط ممتد</option>
                </select>
            </div>
            <label class="flex items-center gap-2 text-xs font-semibold">
                <input type="checkbox" id="dPlate" checked onchange="applyDesign()">
                پلاک نام مدرسه در مرکز QR
            </label>
            <div>
                <label class="text-xs">متن پلاک (خالی = نام مدرسه)</label>
                <input type="text" id="dPtext" class="form-input" maxlength="18" placeholder="<?php echo clean(mb_substr($schoolLabel, 0, 18)); ?>" oninput="applyDesign()">
            </div>
            <div>
                <label class="text-xs">اندازه پلاک: <b><span id="vPscale">۴۰</span>٪</b></label>
                <input type="range" id="dPscale" min="25" max="55" step="1" value="40" style="width:100%" oninput="applyDesign()">
            </div>
            <div class="text-xs text-muted" style="align-self:center">پلاک تا ۵۵٪ هم امن است (تصحیح خطای H)</div>
            <label class="flex items-center gap-2 text-xs font-semibold">
                <input type="checkbox" id="dName" onchange="applyDesign()">
                نام دانش‌آموز زیر QR
            </label>
            <label class="flex items-center gap-2 text-xs font-semibold">
                <input type="checkbox" id="dCls" onchange="applyDesign()">
                نام کلاس زیر QR
            </label>
            <label class="flex items-center gap-2 text-xs font-semibold">
                <input type="checkbox" id="dNid" onchange="applyDesign()">
                کد ملی زیر QR
            </label>
            <div class="text-xs text-muted" style="align-self:center">تنظیمات به‌طور خودکار ذخیره می‌شود</div>
        </div>
    </div>

    <div class="qr-grid" id="qrGrid">
        <?php foreach ($students as $s): ?>
        <div class="qr-item">
            <div class="tag">
                <canvas class="qr-tag" data-qr="<?php echo clean($s['qr']); ?>"></canvas>
                <div class="t-name"><?php echo clean($s['first_name'] . ' ' . $s['last_name']); ?></div>
                <div class="t-cls t-sub"><?php echo clean($s['class_name'] ?: '---'); ?></div>
                <div class="t-nid t-sub"><?php echo clean(tr_num($s['national_id'], 'fa')); ?></div>
            </div>
            <div class="qr-ident no-print" style="display:none">
                <b><?php echo clean($s['first_name'] . ' ' . $s['last_name']); ?></b>
                <span><?php echo clean($s['class_name'] ?: '---'); ?></span>
                <form method="POST" onsubmit="return confirm('تگ فعلی باطل و تگ جدید ساخته شود؟ (تگ چاپ‌شده قبلی دیگر کار نمی‌کند)')">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="regen_tag" value="1">
                    <input type="hidden" name="student_id" value="<?php echo (int)$s['id']; ?>">
                    <input type="hidden" name="back_class" value="<?php echo clean($fClass); ?>">
                    <button class="btn btn-outline text-xs">تگ جدید</button>
                </form>
            </div>
        </div>
        <?php endforeach; if (!$students): ?>
        <div class="card text-center text-muted">دانش‌آموزی یافت نشد.</div>
        <?php endif; ?>
    </div>
</div>

<style>
:root{--tagmm:40mm;--gapmm:4mm}
/* پیش‌نمایش زنده: تگ‌ها روی صفحه با ابعاد واقعی میلی‌متری نمایش داده می‌شوند */
.qr-grid{display:flex;flex-wrap:wrap;gap:var(--gapmm);align-items:flex-start}
.qr-item{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:8px;text-align:center;page-break-inside:avoid;break-inside:avoid}
.tag{width:var(--tagmm);display:inline-block;vertical-align:top;text-align:center;margin:0 auto}
#qrGrid.f-dashed .tag{border:0.3mm dashed #94a3b8;padding:1mm}
#qrGrid.f-solid .tag{border:0.3mm solid #94a3b8;padding:1mm}
.qr-tag{width:100%;display:block}
.t-name{display:none;font-weight:700;color:#000;line-height:1.3;font-size:max(9px, calc(var(--tagmm) * 0.085))}
.t-sub{display:none;color:#000;line-height:1.3;font-size:max(8px, calc(var(--tagmm) * 0.068))}
#qrGrid.show-name .t-name{display:block}
#qrGrid.show-cls .t-cls{display:block}
#qrGrid.show-nid .t-nid{display:block}
.qr-ident{margin-top:6px;font-size:.68rem;color:#64748b;flex-direction:column;gap:4px;align-items:center}
.qr-ident b{color:#0f172a;font-size:.74rem}
@media print{
  /* v4.54.0: fallback if someone presses Ctrl+P on the admin view —
     hide the site chrome AND unlock the template's flex/overflow chain
     so every page paginates (the layout normally clips to one page). */
  .no-print,.sidebar,.navbar,header,nav,.page-hero,footer,.card,.qr-ident{display:none !important}
  body,html{background:#fff !important;margin:0 !important;padding:0 !important;height:auto !important;min-height:0 !important;overflow:visible !important}
  body{display:block !important}
  body > .flex{display:block !important;height:auto !important;overflow:visible !important}
  main.flex-1{display:block !important;height:auto !important;max-height:none !important;overflow:visible !important;padding:0 !important;flex:none !important;width:auto !important}
  .space-y-6{display:block !important}
  .space-y-6>*+*{margin:0 !important}
  .qr-grid{display:block !important;font-size:0;line-height:0}
  .qr-item{display:inline-block !important;border:0 !important;padding:0 !important;background:none !important;border-radius:0 !important;box-shadow:none !important;margin:0 0 var(--gapmm) var(--gapmm) !important}
  @page{margin:8mm}
}
</style>
<script src="assets/js/qrcode-generator.js"></script>
<script>
var SCHOOL = <?php echo json_encode(mb_substr($schoolLabel, 0, 18), JSON_UNESCAPED_UNICODE); ?>;
var LS_KEY = 'mtag_tag_design_v1';
var QRCACHE = {};
var _redrawT = null;

function $id(i){ return document.getElementById(i); }
function faNum(n){ return String(n).replace(/[0-9]/g, function(d){ return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); }

function getDesign(){
  return {
    size:   parseInt($id('dSize').value, 10),
    gap:    parseInt($id('dGap').value, 10),
    margin: parseInt($id('dMargin').value, 10),
    frame:  $id('dFrame').value,
    plate:  $id('dPlate').checked,
    ptext:  $id('dPtext').value.trim(),
    pscale: parseInt($id('dPscale').value, 10),
    sname:  $id('dName').checked,
    scls:   $id('dCls').checked,
    snid:   $id('dNid').checked
  };
}

function setDesign(d){
  $id('dSize').value = d.size; $id('dGap').value = d.gap; $id('dMargin').value = d.margin;
  $id('dFrame').value = d.frame; $id('dPlate').checked = !!d.plate;
  $id('dPtext').value = d.ptext || ''; $id('dPscale').value = d.pscale;
  $id('dName').checked = !!d.sname; $id('dCls').checked = !!d.scls; $id('dNid').checked = !!d.snid;
}

function applyDesign(){
  var d = getDesign();
  document.documentElement.style.setProperty('--tagmm', d.size + 'mm');
  document.documentElement.style.setProperty('--gapmm', d.gap + 'mm');
  var g = $id('qrGrid');
  g.classList.toggle('show-name', d.sname);
  g.classList.toggle('show-cls', d.scls);
  g.classList.toggle('show-nid', d.snid);
  g.classList.toggle('f-dashed', d.frame === 'dashed');
  g.classList.toggle('f-solid', d.frame === 'solid');
  $id('vSize').textContent = faNum(d.size);
  $id('vGap').textContent = faNum(d.gap);
  $id('vMargin').textContent = faNum(d.margin);
  $id('vPscale').textContent = faNum(d.pscale);
  try { localStorage.setItem(LS_KEY, JSON.stringify(d)); } catch(e) {}
  /* بازترسیم QRها (پلاک) با تاخیر کوتاه تا اسلایدر روان بماند */
  clearTimeout(_redrawT);
  _redrawT = setTimeout(renderAll, 120);
}

function resetDesign(){
  setDesign({size:40, gap:4, margin:8, frame:'none', plate:true, ptext:'', pscale:40, sname:false, scls:false, snid:false});
  applyDesign();
}

function renderAll(){
  var d = getDesign();
  var label = d.ptext !== '' ? d.ptext : SCHOOL;
  document.querySelectorAll('canvas.qr-tag[data-qr]').forEach(function(cv){
    drawTag(cv, d.plate, d.pscale / 100, label);
  });
}

function drawTag(cv, plate, pscale, label){
  try {
    var payload = cv.getAttribute('data-qr');
    var qr = QRCACHE[payload];
    if (!qr) {
      /* سطح تصحیح خطای H: تا ۳۰٪ آسیب قابل بازیابی است */
      qr = qrcode(0, 'H');
      qr.addData(payload);
      qr.make();
      QRCACHE[payload] = qr;
    }
    var count = qr.getModuleCount(), quiet = 4, scale = 16;
    var size = (count + quiet * 2) * scale;
    cv.width = size; cv.height = size;
    var ctx = cv.getContext('2d');
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, size, size);
    ctx.fillStyle = '#000';
    for (var r = 0; r < count; r++)
      for (var c = 0; c < count; c++)
        if (qr.isDark(r, c)) ctx.fillRect((c + quiet) * scale, (r + quiet) * scale, scale, scale);
    if (plate && label) {
      var lb = label.length > 18 ? label.slice(0, 18) : label;
      var boxW = Math.round(size * pscale), boxH = Math.round(boxW * 0.325);
      var bx = Math.round((size - boxW) / 2), by = Math.round((size - boxH) / 2);
      var rad = Math.round(boxH * 0.3);
      ctx.fillStyle = '#fff';
      ctx.beginPath();
      ctx.moveTo(bx + rad, by);
      ctx.arcTo(bx + boxW, by, bx + boxW, by + boxH, rad);
      ctx.arcTo(bx + boxW, by + boxH, bx, by + boxH, rad);
      ctx.arcTo(bx, by + boxH, bx, by, rad);
      ctx.arcTo(bx, by, bx + boxW, by, rad);
      ctx.closePath(); ctx.fill();
      ctx.strokeStyle = '#000'; ctx.lineWidth = Math.max(2, Math.round(scale / 6)); ctx.stroke();
      ctx.fillStyle = '#000'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      var fs = Math.round(boxH * 0.52);
      ctx.font = '700 ' + fs + 'px Tahoma, sans-serif';
      while (ctx.measureText(lb).width > boxW * 0.88 && fs > 8) { fs--; ctx.font = '700 ' + fs + 'px Tahoma, sans-serif'; }
      ctx.fillText(lb, size / 2, by + boxH / 2 + fs * 0.06);
    }
  } catch(e) {
    var dv = document.createElement('div'); dv.textContent = 'خطا در ساخت QR'; cv.replaceWith(dv);
  }
}

/* چاپ در نمای مستقل — بدون هدر سایت و بدون محدودیت یک‌صفحه‌ای قالب (v4.54.0)
   همه تنظیمات ویرایشگر عیناً به نمای چاپ منتقل می‌شود (v4.55.0) */
function openPrintView(){
  var d = getDesign();
  var params = new URLSearchParams(window.location.search);
  params.set('print', '1');
  params.set('size', d.size); params.set('gap', d.gap); params.set('margin', d.margin);
  params.set('frame', d.frame); params.set('plate', d.plate ? '1' : '0');
  params.set('ptext', d.ptext); params.set('pscale', d.pscale);
  params.set('sname', d.sname ? '1' : '0'); params.set('scls', d.scls ? '1' : '0'); params.set('snid', d.snid ? '1' : '0');
  window.open('attendance-tags.php?' + params.toString(), '_blank');
}

function toggleIdent(){
  var on = document.getElementById('showIdent').checked;
  document.querySelectorAll('.qr-ident').forEach(function(el){
    el.style.display = on ? 'flex' : 'none';
  });
}

document.addEventListener('DOMContentLoaded', function(){
  try {
    var saved = localStorage.getItem(LS_KEY);
    if (saved) setDesign(JSON.parse(saved));
  } catch(e) {}
  applyDesign();
  renderAll();
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
