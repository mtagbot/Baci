<?php
// File: attendance-tags.php  (v4.51.0)
/**
 * Printable QR attendance tags for students (card layout, per class or all).
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
if ($fClass !== '') { $where[] = 's.class_name=?'; $params[] = $fClass; }
$students = DB::fetchAll("SELECT s.id, s.first_name, s.last_name, s.national_id, s.class_name, s.grade_level FROM students s WHERE " . implode(' AND ', $where), $params);
persian_usort_students($students);
foreach ($students as &$s) { $tag = att_get_or_create_tag((int)$s['id']); $s['qr'] = att_qr_payload((int)$s['id'], $tag['token']); }
unset($s);
$schoolName = get_setting('school_name', 'آموزشگاه');
$schoolLabel = get_setting('school_name_short', '') !== '' ? get_setting('school_name_short', '') : $schoolName;

/* ============================================================
 * v4.54.0: standalone print view — NO site template at all.
 * attendance-tags.php?print=1&class=...&size=30|40
 * Fixes: (1) site header printed, (2) only first page printed
 * (the admin layout is a flex/overflow container that browsers
 * clip to one page). This view is plain block-flow HTML, so all
 * pages paginate naturally.
 * ============================================================ */
if (isset($_GET['print'])) {
    $sizeMm = ((int)($_GET['size'] ?? 40) === 30) ? 30 : 40;
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
.qr-tag{width:<?php echo $sizeMm; ?>mm;height:<?php echo $sizeMm; ?>mm;display:inline-block;margin:0 0 4mm 4mm;page-break-inside:avoid;break-inside:avoid}
.toolbar{position:fixed;top:10px;left:10px;display:flex;gap:8px;font-family:Tahoma,sans-serif;z-index:9}
.toolbar button{padding:8px 18px;border:0;border-radius:8px;background:#2563eb;color:#fff;font-family:inherit;font-size:13px;cursor:pointer}
.toolbar span{background:#f1f5f9;border-radius:8px;padding:8px 14px;font-size:12px;color:#334155}
@media print{ .toolbar{display:none !important} @page{margin:8mm} }
</style>
</head>
<body>
<div class="toolbar no-print">
    <button onclick="window.print()">چاپ</button>
    <span>تعداد تگ: <?php echo tr_num(count($students), 'fa'); ?> — اندازه: <?php echo tr_num($sizeMm, 'fa'); ?> میلی‌متر</span>
</div>
<div class="sheet">
<?php foreach ($students as $s): ?>
    <canvas class="qr-tag" data-qr="<?php echo clean($s['qr']); ?>"></canvas>
<?php endforeach; ?>
</div>
<script src="assets/js/qrcode-generator.js"></script>
<script>
(function(){
  var SCHOOL = <?php echo json_encode($schoolLabel, JSON_UNESCAPED_UNICODE); ?>;
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
    if (SCHOOL) {
      var label = SCHOOL.length > 18 ? SCHOOL.slice(0, 18) : SCHOOL;
      var boxW = Math.round(size * 0.40), boxH = Math.round(size * 0.13);
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
            <p class="text-sm text-muted">فقط QR خالص برای چاپ — نام مدرسه در مرکز هر تگ</p>
        </div>
        <div class="flex gap-2">
            <a href="attendance.php" class="btn btn-outline text-xs">بازگشت به حضور و غیاب</a>
            <button type="button" onclick="openPrintView()" class="btn btn-primary text-xs">چاپ تگ‌ها</button>
        </div>
    </div>

    <div class="card no-print">
        <form method="GET" class="grid grid-cols-5 gap-3 items-end">
            <div>
                <label class="text-xs">کلاس</label>
                <select name="class" class="form-select">
                    <option value="">همه کلاس‌ها</option>
                    <?php foreach ($classes as $c): ?><option value="<?php echo clean($c['class_name']); ?>" <?php echo $fClass === $c['class_name'] ? 'selected' : ''; ?>><?php echo clean($c['class_name']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-secondary">نمایش</button>
            <div>
                <label class="text-xs">اندازه چاپ تگ</label>
                <select id="tagSize" class="form-select" onchange="applyTagSize()">
                    <option value="30">۳۰ × ۳۰ میلی‌متر</option>
                    <option value="40" selected>۴۰ × ۴۰ میلی‌متر</option>
                </select>
            </div>
            <label class="flex items-center gap-2 text-xs font-semibold" style="padding-bottom:10px">
                <input type="checkbox" id="showIdent" onchange="toggleIdent()">
                نمایش نام برای شناسایی (فقط روی صفحه)
            </label>
            <div class="text-xs text-muted">تعداد: <?php echo tr_num(count($students), 'fa'); ?> دانش‌آموز</div>
        </form>
    </div>

    <div class="qr-grid" id="qrGrid">
        <?php foreach ($students as $s): ?>
        <div class="qr-item">
            <canvas class="qr-tag" data-qr="<?php echo clean($s['qr']); ?>"></canvas>
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
:root{--tagmm:40mm}
.qr-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px}
.qr-item{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:10px;text-align:center;page-break-inside:avoid;break-inside:avoid}
.qr-tag{width:130px;height:130px;display:block;margin:0 auto}
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
  .qr-item{display:inline-block !important;border:0 !important;padding:0 !important;background:none !important;border-radius:0 !important;box-shadow:none !important;margin:0 0 4mm 4mm !important}
  .qr-tag{width:var(--tagmm);height:var(--tagmm);margin:0}
  @page{margin:8mm}
}
</style>
<script src="assets/js/qrcode-generator.js"></script>
<script>
function applyTagSize(){
  var v = document.getElementById('tagSize').value;
  document.documentElement.style.setProperty('--tagmm', v + 'mm');
}
/* v4.54.0: چاپ در نمای مستقل — بدون هدر سایت و بدون محدودیت یک‌صفحه‌ای قالب */
function openPrintView(){
  var size = document.getElementById('tagSize').value;
  var params = new URLSearchParams(window.location.search);
  params.set('print', '1');
  params.set('size', size);
  window.open('attendance-tags.php?' + params.toString(), '_blank');
}
function toggleIdent(){
  var on = document.getElementById('showIdent').checked;
  document.querySelectorAll('.qr-ident').forEach(function(el){
    el.style.display = on ? 'flex' : 'none';
  });
}
document.addEventListener('DOMContentLoaded', function(){
  applyTagSize();
  var SCHOOL = <?php echo json_encode(get_setting('school_name_short', '') !== '' ? get_setting('school_name_short', '') : $schoolName, JSON_UNESCAPED_UNICODE); ?>;
  document.querySelectorAll('canvas.qr-tag[data-qr]').forEach(function(cv){
    try {
      /* سطح تصحیح خطای H: تا ۳۰٪ آسیب قابل بازیابی است —
         بنابراین نوشتن نام مدرسه در مرکز، اسکن را خراب نمی‌کند */
      var qr = qrcode(0, 'H');
      qr.addData(cv.getAttribute('data-qr'));
      qr.make();
      var count = qr.getModuleCount();
      var quiet = 4;                       /* حاشیه امن استاندارد */
      var scale = 16;                      /* رزولوشن بالا برای چاپ تمیز ۳۰-۴۰mm */
      var size = (count + quiet * 2) * scale;
      cv.width = size; cv.height = size;
      var ctx = cv.getContext('2d');
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, size, size);
      ctx.fillStyle = '#000';
      for (var r = 0; r < count; r++)
        for (var c = 0; c < count; c++)
          if (qr.isDark(r, c)) ctx.fillRect((c + quiet) * scale, (r + quiet) * scale, scale, scale);
      /* نام مدرسه در مرکز — پلاک سفید کوچک (~۵٪ سطح، بسیار کمتر از تحمل ۳۰٪) */
      if (SCHOOL) {
        var label = SCHOOL.length > 18 ? SCHOOL.slice(0, 18) : SCHOOL;
        var boxW = Math.round(size * 0.40), boxH = Math.round(size * 0.13);
        var bx = Math.round((size - boxW) / 2), by = Math.round((size - boxH) / 2);
        ctx.fillStyle = '#fff';
        var rad = Math.round(boxH * 0.3);
        ctx.beginPath();
        ctx.moveTo(bx + rad, by);
        ctx.arcTo(bx + boxW, by, bx + boxW, by + boxH, rad);
        ctx.arcTo(bx + boxW, by + boxH, bx, by + boxH, rad);
        ctx.arcTo(bx, by + boxH, bx, by, rad);
        ctx.arcTo(bx, by, bx + boxW, by, rad);
        ctx.closePath();
        ctx.fill();
        ctx.strokeStyle = '#000';
        ctx.lineWidth = Math.max(2, Math.round(scale / 6));
        ctx.stroke();
        ctx.fillStyle = '#000';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        var fs = Math.round(boxH * 0.52);
        ctx.font = '700 ' + fs + 'px Tahoma, sans-serif';
        while (ctx.measureText(label).width > boxW * 0.88 && fs > 8) {
          fs--; ctx.font = '700 ' + fs + 'px Tahoma, sans-serif';
        }
        ctx.fillText(label, size / 2, by + boxH / 2 + fs * 0.06);
      }
    } catch(e) {
      var d = document.createElement('div'); d.textContent = 'خطا در ساخت QR'; cv.replaceWith(d);
    }
  });
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
