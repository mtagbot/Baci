<?php
// File: entry-cards.php  (v4.136.0)
/**
 * کارت ورود دانش‌آموز — اندازهٔ استاندارد کارت ویزیت / کارت شناسایی.
 *
 * ابعاد ۸۵٫۶ × ۵۴ میلی‌متر است (ISO/IEC 7810 ID-1) — همان اندازهٔ کارت
 * بانکی و کارت ملی، پس داخل هر هولدر و جاکارتی استانداردی جا می‌شود.
 *
 * همان فیلترها و ترتیب‌های صفحهٔ «چاپ تگ‌ها» را دارد (پایه، کلاس،
 * دانش‌آموز تکی، پنج ترتیب چیدمان) و از همان توکن QR حضور و غیاب
 * استفاده می‌کند — یعنی کارت ورود با اسکنر حضور و غیاب هم کار می‌کند
 * و نیازی به توکن دوم نیست.
 *
 * طراحی: نقش‌مایه‌های ایرانی–اسلامی برداری از includes/card_ornaments.php
 * (شمسهٔ هشت‌پر، بته‌جقه، طاق، گره‌چینی). هیچ تصویر رستری‌ای در کار نیست
 * تا چاپ در هر دقتی تیز بماند و بستهٔ آفلاین سنگین نشود.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/attendance_helpers.php';
require_once __DIR__ . '/includes/school_roles.php';
require_once __DIR__ . '/includes/school_sort.php';
require_once __DIR__ . '/includes/card_ornaments.php';

$isAdminAtt  = is_admin_logged_in() && has_permission('manage_students');
$isDeputyAtt = is_teacher_logged_in() && teacher_has_deputy($_SESSION['teacher_id'] ?? 0);
if (!$isAdminAtt && !$isDeputyAtt) redirect('admin-login.php?tab=teacher');
ensure_attendance_schema_v2();

/* ---- فیلترها: عیناً همان قرارداد صفحهٔ تگ‌ها ---- */
$fClass = trim($_GET['class'] ?? '');
$fGrade = trim($_GET['grade'] ?? '');
$fOne   = (int)($_GET['student_id'] ?? 0);
$sortKey = $_GET['sort'] ?? 'class';
if (!in_array($sortKey, ['class', 'name', 'first', 'grade', 'nid'], true)) $sortKey = 'class';

$year    = get_setting('current_academic_year', '1404/1405');
$classes = get_unified_class_options($year);

$where = ["s.status='active'"]; $params = [];
list($cardYearSql, $cardYearParams) = att_year_sql('s');
$where[] = $cardYearSql; foreach ($cardYearParams as $typ) $params[] = $typ;
if ($fClass !== '') { $where[] = 's.class_name=?'; $params[] = $fClass; }
if ($fGrade !== '') { $where[] = 's.grade_level=?'; $params[] = $fGrade; }
if ($fOne > 0)      { $where[] = 's.id=?';          $params[] = $fOne; }

$students = DB::fetchAll(
    "SELECT s.id, s.first_name, s.last_name, s.father_name, s.national_id, s.student_code,
            s.class_name, s.grade_level, s.photo_url
     FROM students s WHERE " . implode(' AND ', $where), $params);

$gradeRows = DB::fetchAll("SELECT DISTINCT s.grade_level FROM students s
    WHERE s.status='active' AND $cardYearSql AND s.grade_level<>''", $cardYearParams);
persian_usort_grades($gradeRows);

$allForPick = DB::fetchAll("SELECT s.id, s.first_name, s.last_name, s.class_name
    FROM students s WHERE s.status='active' AND $cardYearSql", $cardYearParams);
persian_usort_students($allForPick);

/* ---- چیدمان (همان منطق صفحهٔ تگ‌ها) ---- */
switch ($sortKey) {
    case 'name':
        persian_usort_students($students);
        break;
    case 'first':
        usort($students, function ($a, $b) {
            $c = persian_compare($a['first_name'] ?? '', $b['first_name'] ?? '');
            return $c !== 0 ? $c : persian_compare($a['last_name'] ?? '', $b['last_name'] ?? '');
        });
        break;
    case 'grade':
        usort($students, function ($a, $b) {
            $wa = grade_sort_weight($a['grade_level'] ?? ''); $wb = grade_sort_weight($b['grade_level'] ?? '');
            if ($wa !== $wb) return $wa <=> $wb;
            $c = persian_compare($a['class_name'] ?? '', $b['class_name'] ?? '');
            return $c !== 0 ? $c : persian_compare($a['last_name'] ?? '', $b['last_name'] ?? '');
        });
        break;
    case 'nid':
        usort($students, function ($a, $b) {
            return strcmp((string)($a['national_id'] ?? ''), (string)($b['national_id'] ?? ''));
        });
        break;
    case 'class':
    default:
        usort($students, function ($a, $b) {
            $c = persian_compare($a['class_name'] ?? '', $b['class_name'] ?? '');
            if ($c !== 0) return $c;
            $c = persian_compare($a['last_name'] ?? '', $b['last_name'] ?? '');
            return $c !== 0 ? $c : persian_compare($a['first_name'] ?? '', $b['first_name'] ?? '');
        });
        break;
}

/* همان توکن حضور و غیاب — کارت ورود با اسکنر موجود کار می‌کند */
foreach ($students as &$s) {
    $tag = att_get_or_create_tag((int)$s['id']);
    $s['qr'] = att_qr_payload((int)$s['id'], $tag['token']);
}
unset($s);

/* ---- هویت مدرسه ---- */
$schoolName  = get_setting('school_name', 'آموزشگاه');
$schoolShort = get_setting('school_name_short', '') !== '' ? get_setting('school_name_short', '') : $schoolName;
$province    = get_setting('school_province', '');
$region      = get_setting('school_region', '');
$unitType    = get_setting('school_unit_type', '');
$logoUrl     = get_setting('logo_url', '');
$themeColor  = get_setting('theme_color', '#2563eb');
$accentColor = get_setting('accent_color', '#d97706');

/* ============================================================
 * نمای چاپ مستقل — بدون قالب سایت (مثل صفحهٔ تگ‌ها)
 * ============================================================ */
if (isset($_GET['print'])) {
    $D = [
        'side'   => in_array(($_GET['side'] ?? 'front'), ['front', 'both'], true) ? $_GET['side'] : 'front',
        'gap'    => min(14, max(0, (float)($_GET['gap'] ?? 4))),
        'margin' => min(25, max(0, (float)($_GET['margin'] ?? 8))),
        'cut'    => ($_GET['cut'] ?? '1') === '1',
        'photo'  => ($_GET['photo'] ?? '1') === '1',
        'nid'    => ($_GET['nid'] ?? '1') === '1',
        'year'   => ($_GET['year'] ?? '1') === '1',
        'theme'  => in_array(($_GET['theme'] ?? 'classic'), ['classic', 'ribbon', 'minimal', 'titr', 'tile', 'sarv'], true) ? $_GET['theme'] : 'classic',
    ];

    /* v4.137.0: همهٔ فونت‌های ایرانی بسته، برای طرح‌های مختلف کارت.
       طرح‌ها با متغیرهای --f-* فونت خود را انتخاب می‌کنند. */
    $fontFaces = card_font_faces();
    $fontVars  = card_font_vars();

    /* فونت واقعی سامانه در چاپ — همان درسی که در صفحهٔ تگ‌ها گرفتیم:
       این صفحه قالب سایت را ندارد، پس باید خودش @font-face بدهد. */
    $printFont = get_setting('font_family', 'Vazirmatn');
    $customFontUrl = get_setting('custom_font_url', '');
    $fontStack = "Tahoma, sans-serif";
    $fontDirs = [
        'Vazirmatn' => [['Vazirmatn/Vazirmatn-Regular', 400], ['Vazirmatn/Vazirmatn-Bold', 700]],
        'Sahel'     => [['Sahel/Sahel', 400]],
        'Yekan'     => [['Yekan/Yekan', 400]],
    ];
    if ($printFont === 'CustomUploadedFont' && $customFontUrl !== '') {
        $fontFaces .= "@font-face{font-family:'CustomUploadedFont';src:url('" . clean($customFontUrl) . "');font-display:block}\n";
        $fontStack = "'CustomUploadedFont', Tahoma, sans-serif";
    } elseif (isset($fontDirs[$printFont]) && card_font_exists($printFont)) {
        $fontStack = "'{$printFont}', Tahoma, sans-serif";
    }
    ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title>چاپ کارت ورود دانش‌آموزان</title>
<style>
<?php echo $fontFaces; ?>
*{box-sizing:border-box;margin:0;padding:0}
html,body{background:#fff;font-family:<?php echo $fontStack; ?>}
:root{--cp:<?php echo clean($themeColor); ?>;--ca:<?php echo clean($accentColor); ?>;<?php echo $fontVars; ?>}
.sheet{font-size:0;line-height:0}
<?php include __DIR__ . '/includes/card_styles.php'; ?>
.cut{outline:0.25mm dashed #cbd5e1;outline-offset:0}
.toolbar{position:fixed;top:10px;left:10px;display:flex;gap:8px;font-family:<?php echo $fontStack; ?>;z-index:9}
.toolbar button{padding:8px 18px;border:0;border-radius:8px;background:var(--cp);color:#fff;font-family:inherit;font-size:13px;cursor:pointer}
.toolbar span{background:#f1f5f9;border-radius:8px;padding:8px 14px;font-size:12px;color:#334155}
@media print{ .toolbar{display:none!important} @page{margin:<?php echo $D['margin']; ?>mm} }
</style>
</head>
<body>
<?php card_ornament_defs($themeColor, $accentColor); ?>
<div class="toolbar no-print">
    <button onclick="window.print()">چاپ</button>
    <span>تعداد کارت: <?php echo tr_num(count($students), 'fa'); ?> — اندازه: ۸۵٫۶ × ۵۴ میلی‌متر</span>
</div>
<div class="sheet" style="--gap:<?php echo $D['gap']; ?>mm">
<?php foreach ($students as $s):
    $fullName = trim($s['first_name'] . ' ' . $s['last_name']);
    include __DIR__ . '/includes/card_face.php';
    if ($D['side'] === 'both') include __DIR__ . '/includes/card_back.php';
endforeach; ?>
</div>
<script src="assets/js/qrcode-generator.js"></script>
<script>
(function(){
  /* v4.137.0 — کیفیت اسکن در اولویت است.
     · تصحیح خطا از M به Q: تا ۲۵٪ آسیب (خط‌خوردگی، انگشت روی کارت،
       تاشدگی) باز هم خوانده می‌شود. روی کارتی که هر روز در جیب و
       هولدر جابه‌جا می‌شود این تفاوت واقعی می‌سازد.
     · scale=22 یعنی هر ماژول در بوم بزرگ است؛ چون canvas با عرض
       میلی‌متری کوچک می‌شود، خروجی چاپ عملاً بسیار پرجزئیات است.
     · quiet=4 حاشیهٔ سفید استانداردِ QR است. کمتر از ۴ ماژول، بعضی
       اسکنرها اصلاً قفل نمی‌کنند — قبلاً ۲ بود. */
  function draw(cv){
    try {
      var qr = qrcode(0, 'Q');
      qr.addData(cv.getAttribute('data-qr'));
      qr.make();
      var count = qr.getModuleCount(), quiet = 4, scale = 22;
      var size = (count + quiet * 2) * scale;
      cv.width = size; cv.height = size;
      var ctx = cv.getContext('2d');
      ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, size, size);
      ctx.fillStyle = '#000';
      for (var r = 0; r < count; r++)
        for (var c = 0; c < count; c++)
          if (qr.isDark(r, c)) ctx.fillRect((c + quiet) * scale, (r + quiet) * scale, scale, scale);
    } catch(e) {}
  }
  function drawAll(){
    document.querySelectorAll('canvas.card-qr[data-qr],canvas.card-back-qr[data-qr]').forEach(draw);
  }

  /* چاپ باید تا لود شدن فونت صبر کند — همان درس صفحهٔ تگ‌ها */
  function whenFontsReady(cb){
    if (document.fonts && document.fonts.ready && typeof document.fonts.ready.then === 'function') {
      var done = false;
      var go = function(){ if (!done) { done = true; cb(); } };
      document.fonts.ready.then(go);
      setTimeout(go, 3000);
    } else { setTimeout(cb, 600); }
  }
  var printed = false;
  function printOnce(){
    if (printed) return;
    printed = true;
    setTimeout(function(){ window.print(); }, 250);
  }
  whenFontsReady(function(){
    drawAll();
    if (document.readyState === 'complete') printOnce();
    else window.addEventListener('load', printOnce);
  });
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
            <h2 class="text-2xl font-bold">کارت ورود دانش‌آموزان</h2>
            <p class="text-sm text-muted">اندازهٔ استاندارد کارت شناسایی (۸۵٫۶ × ۵۴ میلی‌متر) با نقش‌مایه‌های ایرانی — پیش‌نمایش زنده</p>
        </div>
        <div class="flex gap-2">
            <a href="attendance-tags.php" class="btn btn-outline text-xs">چاپ تگ‌های QR</a>
            <a href="attendance.php" class="btn btn-outline text-xs">حضور و غیاب</a>
            <button type="button" onclick="openCardPrint()" class="btn btn-primary text-xs">چاپ کارت‌ها</button>
        </div>
    </div>

    <div class="card no-print">
        <form method="GET" class="grid grid-cols-4 gap-3 items-end">
            <div>
                <label class="text-xs">پایه</label>
                <select name="grade" class="form-select" data-no-search="1">
                    <option value="">همه پایه‌ها</option>
                    <?php foreach ($gradeRows as $g): ?><option value="<?php echo clean($g['grade_level']); ?>" <?php echo $fGrade === $g['grade_level'] ? 'selected' : ''; ?>><?php echo clean($g['grade_level']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-xs">کلاس</label>
                <select name="class" class="form-select" data-no-search="1">
                    <option value="">همه کلاس‌ها</option>
                    <?php foreach ($classes as $c): ?><option value="<?php echo clean($c['class_name']); ?>" <?php echo $fClass === $c['class_name'] ? 'selected' : ''; ?>><?php echo clean($c['class_name']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-xs">ترتیب چیدمان کارت‌ها</label>
                <select name="sort" class="form-select" data-no-search="1">
                    <option value="class" <?php echo $sortKey === 'class' ? 'selected' : ''; ?>>کلاس، سپس نام خانوادگی</option>
                    <option value="name"  <?php echo $sortKey === 'name'  ? 'selected' : ''; ?>>نام خانوادگی</option>
                    <option value="first" <?php echo $sortKey === 'first' ? 'selected' : ''; ?>>نام</option>
                    <option value="grade" <?php echo $sortKey === 'grade' ? 'selected' : ''; ?>>پایه، سپس کلاس</option>
                    <option value="nid"   <?php echo $sortKey === 'nid'   ? 'selected' : ''; ?>>کد ملی</option>
                </select>
            </div>
            <div>
                <label class="text-xs">فقط یک دانش‌آموز (چاپ تکی)</label>
                <select name="student_id" id="oneStudentCard" class="form-select">
                    <option value="">— همهٔ دانش‌آموزان فیلتر شده —</option>
                    <?php foreach ($allForPick as $sp): ?><option value="<?php echo (int)$sp['id']; ?>" <?php echo $fOne === (int)$sp['id'] ? 'selected' : ''; ?>><?php echo clean($sp['last_name'] . '، ' . $sp['first_name'] . ' — ' . ($sp['class_name'] ?: '---')); ?></option><?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-secondary">نمایش</button>
            <?php if ($fClass !== '' || $fGrade !== '' || $fOne > 0 || $sortKey !== 'class'): ?>
                <a href="entry-cards.php" class="btn btn-outline text-xs">حذف فیلترها</a>
            <?php endif; ?>
            <div class="text-xs text-muted">تعداد: <?php echo tr_num(count($students), 'fa'); ?> دانش‌آموز</div>
        </form>
    </div>

    <div class="card no-print" id="cardDesigner">
        <div class="flex justify-between items-center" style="margin-bottom:12px">
            <h3 class="font-bold text-sm">ویرایشگر زندهٔ کارت</h3>
            <button type="button" class="btn btn-outline text-xs" onclick="resetCardDesign()">بازنشانی به پیش‌فرض</button>
        </div>
        <div class="grid grid-cols-4 gap-4">
            <div>
                <label class="text-xs">طرح کارت</label>
                <select id="cTheme" class="form-select" data-no-search="1" onchange="applyCardDesign()">
                    <?php foreach (card_themes() as $tk => $tl): ?>
                        <option value="<?php echo clean($tk); ?>"><?php echo clean($tl); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-xs">فاصله بین کارت‌ها: <b><span id="vCGap">۴</span> میلی‌متر</b></label>
                <input type="range" id="cGap" min="0" max="14" step="1" value="4" style="width:100%" oninput="applyCardDesign()">
            </div>
            <div>
                <label class="text-xs">حاشیه صفحه چاپ: <b><span id="vCMargin">۸</span> میلی‌متر</b></label>
                <input type="range" id="cMargin" min="0" max="25" step="1" value="8" style="width:100%" oninput="applyCardDesign()">
            </div>
            <div>
                <label class="text-xs">پشت کارت</label>
                <select id="cSide" class="form-select" data-no-search="1" onchange="applyCardDesign()">
                    <option value="front">فقط روی کارت</option>
                    <option value="both">روی و پشت (قوانین و تماس)</option>
                </select>
            </div>
        </div>
        <div class="grid grid-cols-4 gap-4" style="margin-top:12px">
            <label class="flex items-center gap-2 text-xs font-semibold"><input type="checkbox" id="cPhoto" checked onchange="applyCardDesign()"> نمایش عکس دانش‌آموز</label>
            <label class="flex items-center gap-2 text-xs font-semibold"><input type="checkbox" id="cNid" checked onchange="applyCardDesign()"> نمایش کد ملی</label>
            <label class="flex items-center gap-2 text-xs font-semibold"><input type="checkbox" id="cYear" checked onchange="applyCardDesign()"> نمایش سال تحصیلی</label>
            <label class="flex items-center gap-2 text-xs font-semibold"><input type="checkbox" id="cCut" checked onchange="applyCardDesign()"> خط برش دور کارت</label>
        </div>
    </div>

    <div class="card no-print">
        <h3 class="font-bold text-sm" style="margin-bottom:10px">پیش‌نمایش (ابعاد واقعی)</h3>
        <?php card_ornament_defs($themeColor, $accentColor); ?>
        <div class="sheet" id="cardPreview" style="--gap:4mm">
        <?php
        $D = ['photo' => true, 'nid' => true, 'year' => true, 'theme' => 'classic', 'cut' => true];
        $previewList = array_slice($students, 0, 6);
        foreach ($previewList as $s):
            $fullName = trim($s['first_name'] . ' ' . $s['last_name']);
            include __DIR__ . '/includes/card_face.php';
        endforeach;
        if (!$students): ?>
            <div class="text-center text-muted" style="font-size:13px;line-height:1.6">دانش‌آموزی یافت نشد.</div>
        <?php endif; ?>
        </div>
        <?php if (count($students) > 6): ?>
            <p class="text-xs text-muted" style="margin-top:10px">در پیش‌نمایش فقط ۶ کارت اول نشان داده می‌شود؛ در چاپ هر <?php echo tr_num(count($students), 'fa'); ?> کارت می‌آید.</p>
        <?php endif; ?>
    </div>
</div>

<style>
<?php /* v4.137.0: همان فونت‌ها و متغیرهای نمای چاپ، تا پیش‌نمایش با
      خروجی چاپ یکی باشد. */ ?>
<?php echo card_font_faces(); ?>
:root{--cp:<?php echo clean($themeColor); ?>;--ca:<?php echo clean($accentColor); ?>;<?php echo card_font_vars(); ?>}
<?php include __DIR__ . '/includes/card_styles.php'; ?>
.cut{outline:0.25mm dashed #cbd5e1}
@media print{ .no-print{display:none!important} }
</style>

<script src="assets/js/qrcode-generator.js"></script>
<script>
var CARD_LS = 'mtag_card_design_v1';
/* از همان منبع PHP می‌آید تا اگر طرحی اضافه شد، اینجا جا نماند */
var THEME_KEYS = <?php echo json_encode(array_keys(card_themes())); ?>;

function getCardDesign(){
  return {
    theme:  document.getElementById('cTheme').value,
    gap:    +document.getElementById('cGap').value,
    margin: +document.getElementById('cMargin').value,
    side:   document.getElementById('cSide').value,
    photo:  document.getElementById('cPhoto').checked,
    nid:    document.getElementById('cNid').checked,
    year:   document.getElementById('cYear').checked,
    cut:    document.getElementById('cCut').checked
  };
}
function setCardDesign(d){
  if(!d) return;
  if(d.theme)  document.getElementById('cTheme').value  = d.theme;
  if(d.side)   document.getElementById('cSide').value   = d.side;
  if(d.gap    !== undefined) document.getElementById('cGap').value    = d.gap;
  if(d.margin !== undefined) document.getElementById('cMargin').value = d.margin;
  if(d.photo  !== undefined) document.getElementById('cPhoto').checked = !!d.photo;
  if(d.nid    !== undefined) document.getElementById('cNid').checked   = !!d.nid;
  if(d.year   !== undefined) document.getElementById('cYear').checked  = !!d.year;
  if(d.cut    !== undefined) document.getElementById('cCut').checked   = !!d.cut;
}
function faNum(n){ return String(n).replace(/[0-9]/g, function(d){ return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); }

function applyCardDesign(){
  var d = getCardDesign();
  document.getElementById('vCGap').textContent    = faNum(d.gap);
  document.getElementById('vCMargin').textContent = faNum(d.margin);

  var pv = document.getElementById('cardPreview');
  pv.style.setProperty('--gap', d.gap + 'mm');
  pv.querySelectorAll('.card-id').forEach(function(c){
    THEME_KEYS.forEach(function(k){ c.classList.remove('th-' + k); });
    c.classList.add('th-' + d.theme);
    c.classList.toggle('cut', d.cut);
    var fr = c.querySelector('.card-frame');
    if (d.theme === 'tile' && !fr) { fr = document.createElement('div'); fr.className='card-frame'; c.insertBefore(fr, c.firstChild.nextSibling); }
    if (fr) fr.style.display = (d.theme === 'tile') ? '' : 'none';
    var ph = c.querySelector('.card-photo');   if(ph) ph.style.display = d.photo ? '' : 'none';
    var pp = c.querySelector('.card-photo-ph'); if(pp) pp.style.display = d.photo ? '' : 'none';
    var nd = c.querySelector('.card-nid');     if(nd) nd.style.display = d.nid   ? '' : 'none';
    var yr = c.querySelector('.card-year');    if(yr) yr.style.display = d.year  ? '' : 'none';
  });
  try { localStorage.setItem(CARD_LS, JSON.stringify(d)); } catch(e){}
}
function resetCardDesign(){
  setCardDesign({theme:'classic',gap:4,margin:8,side:'front',photo:true,nid:true,year:true,cut:true});
  applyCardDesign();
}
function openCardPrint(oneId){
  var d = getCardDesign();
  var params = new URLSearchParams(window.location.search);
  params.set('print','1');
  if (oneId) params.set('student_id', String(oneId));
  params.set('theme', d.theme); params.set('gap', d.gap); params.set('margin', d.margin);
  params.set('side', d.side);
  params.set('photo', d.photo ? '1':'0'); params.set('nid', d.nid ? '1':'0');
  params.set('year', d.year ? '1':'0'); params.set('cut', d.cut ? '1':'0');
  window.open('entry-cards.php?' + params.toString(), '_blank');
}
function printOneCard(id){ openCardPrint(id); }

(function(){
  function draw(cv){
    try {
      var qr = qrcode(0, 'Q');
      qr.addData(cv.getAttribute('data-qr'));
      qr.make();
      var count = qr.getModuleCount(), quiet = 4, scale = 14;
      var size = (count + quiet*2) * scale;
      cv.width = size; cv.height = size;
      var ctx = cv.getContext('2d');
      ctx.fillStyle = '#fff'; ctx.fillRect(0,0,size,size);
      ctx.fillStyle = '#000';
      for (var r=0;r<count;r++) for (var c=0;c<count;c++)
        if (qr.isDark(r,c)) ctx.fillRect((c+quiet)*scale,(r+quiet)*scale,scale,scale);
    } catch(e){}
  }
  document.addEventListener('DOMContentLoaded', function(){
    try { var sv = localStorage.getItem(CARD_LS); if (sv) setCardDesign(JSON.parse(sv)); } catch(e){}
    applyCardDesign();
    document.querySelectorAll('canvas.card-qr[data-qr],canvas.card-back-qr[data-qr]').forEach(draw);
  });
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
