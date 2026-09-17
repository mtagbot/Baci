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

// Printing multiplicity only: tokens and student queries stay unique.
$copiesRaw = $_GET['copies'] ?? '1';
$copiesText = is_scalar($copiesRaw) ? trim(tr_num((string)$copiesRaw, 'en')) : '';
$copiesText = strtr($copiesText, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
$copies = preg_match('/^(?:[1-9]|10)$/D', $copiesText) ? (int)$copiesText : 1;

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
$cardCustomLogo = get_setting('card_custom_logo', '') ?: $logoUrl;
// Plain display text, not a link/HTML: independent of the student's scan token.
$cardCustomText = isset($_GET['custom_text']) && is_string($_GET['custom_text'])
    ? mb_substr(trim($_GET['custom_text']), 0, 80)
    : mb_substr((string)get_setting('school_website', 'Bacirat.ir'), 0, 80);
$themeColor  = get_setting('theme_color', '#2563eb');
$accentColor = get_setting('accent_color', '#d97706');

/* ============================================================
 * نمای چاپ مستقل — بدون قالب سایت (مثل صفحهٔ تگ‌ها)
 * ============================================================ */
if (isset($_GET['print'])) {
    $D = [
        'side'   => in_array(($_GET['side'] ?? 'front'), ['front', 'both'], true) ? ($_GET['side'] ?? 'front') : 'front',
        /* v4.143.0 — سقف‌ها بالا رفت تا انتخاب دست کاربر باشد.
           کف صفر می‌ماند (حداکثر کارت در صفحه) و سقف فقط آن‌قدر است
           که مقدار بی‌معنی وارد نشود؛ اگر با تنظیمات انتخابی هیچ
           کارتی جا نشود، صفحه پیام روشن می‌دهد. */
        'gap'    => min(40, max(0, (float)($_GET['gap'] ?? 4))),
        'margin' => min(50, max(0, (float)($_GET['margin'] ?? 8))),
        'cut'    => ($_GET['cut'] ?? '1') === '1',
        'photo'  => ($_GET['photo'] ?? '1') === '1',
        'nid'    => ($_GET['nid'] ?? '1') === '1',
        'year'   => ($_GET['year'] ?? '1') === '1',
        'theme'  => in_array(($_GET['theme'] ?? 'classic'), ['classic', 'ribbon', 'minimal', 'titr', 'tile', 'sarv'], true) ? ($_GET['theme'] ?? 'classic') : 'classic',
        /* v4.139.0 — شکل کارت:
             full  = کارت کامل ۸۵٫۶×۵۴ با همهٔ اطلاعات (QR ۴۰mm)
             qrmax = همان اندازه ولی بزرگ‌ترین QR ممکن (۵۰mm، ۹۳٪ ارتفاع)
             sq    = مربع ۵۴×۵۴، QR ۴۸mm = ۷۹٪ مساحت کارت */
        'layout' => in_array(($_GET['layout'] ?? 'full'), ['full', 'qrmax', 'sq', 'custom'], true) ? ($_GET['layout'] ?? 'full') : 'full',
        /* v4.141.0 — کاغذ، جهت و مقیاس کارت */
        'paper'  => array_key_exists(($_GET['paper'] ?? 'A4'), card_paper_sizes()) ? ($_GET['paper'] ?? 'A4') : 'A4',
        'orient' => in_array(($_GET['orient'] ?? 'portrait'), ['portrait', 'landscape'], true) ? ($_GET['orient'] ?? 'portrait') : 'portrait',
        /* مقیاس — کف و سقف از card_scale_min/max می‌آیند تا فرم،
           اعتبارسنجی و محاسبهٔ ظرفیت پیش‌نمایش یک منبع داشته باشند. */
        'scale'  => min(card_scale_max(), max(card_scale_min(), (float)($_GET['scale'] ?? 1))),
    ];

    /* v4.141.0 — چیدمان واقعی روی کاغذ انتخابی.
       اگر با این تنظیمات حتی یک کارت جا نشود، به‌جای تولید صفحهٔ
       خالی، پیام روشن می‌دهیم. */
    $grid = card_grid_info($D['paper'], $D['orient'], $D['layout'], $D['scale'], $D['margin'], $D['gap']);
    $perPage = max(1, (int)$grid['per_page']);
    $gridFits = $grid['per_page'] > 0;

    /* کارت‌ها به ترتیب چیده می‌شوند؛ در حالت دورو، پشت هر کارت
       بلافاصله بعد از روی آن می‌آید (چاپ دورو دستی). */
    $printItems = [];
    foreach ($students as $st) {
        // Repeat a complete front/back pair before moving to the next student.
        for ($copyNo = 0; $copyNo < $copies; $copyNo++) {
            $printItems[] = ['s' => $st, 'back' => false];
            if ($D['side'] === 'both') $printItems[] = ['s' => $st, 'back' => true];
        }
    }
    $pages = $gridFits ? array_chunk($printItems, $perPage) : [$printItems];

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
<?php include __DIR__ . '/includes/card_sheet_layout.php'; ?>
<?php include __DIR__ . '/includes/card_custom_styles.php'; ?>
.cut{outline:0.25mm dashed #cbd5e1;outline-offset:0}
.toolbar{position:fixed;top:10px;left:10px;display:flex;gap:8px;font-family:<?php echo $fontStack; ?>;z-index:9}
.toolbar button{padding:8px 18px;border:0;border-radius:8px;background:var(--cp);color:#fff;font-family:inherit;font-size:13px;cursor:pointer}
.toolbar span{background:#f1f5f9;border-radius:8px;padding:8px 14px;font-size:12px;color:#334155}

/* v4.141.0 — هر صفحهٔ چاپ یک بلوک با ابعاد دقیق کاغذ.
   روی صفحه خاکستری پشت آن دیده می‌شود تا کاربر مرز کاغذ را ببیند؛
   در چاپ، سایه و پس‌زمینه حذف می‌شوند. */
.page{
  position:relative;
  width:<?php echo $grid['paper_w']; ?>mm;
  height:<?php echo $grid['paper_h']; ?>mm;
  box-sizing:border-box;overflow:hidden;
  padding:<?php echo $D['margin']; ?>mm;
  margin:0 auto 8mm auto;
  background:#fff;
  box-shadow:0 2px 12px rgba(15,23,42,.16);
  page-break-after:always; break-after:page;
  font-size:0; line-height:0;
}
.page:last-child{page-break-after:auto;break-after:auto;margin-bottom:0}
.page-scale{
  position:absolute;
  top:<?php echo $D['margin']; ?>mm;right:<?php echo $D['margin']; ?>mm;
  width:<?php echo max(0,$grid['paper_w']-2*$D['margin']); ?>mm;
  height:<?php echo max(0,$grid['paper_h']-2*$D['margin']); ?>mm;
}
.sheet{--card-scale:<?php echo $D['scale']; ?>}
<?php for($slot=0;$slot<$grid['per_page'];$slot++): ?>
.sheet > :nth-child(<?php echo $slot+1; ?>){right:<?php echo sprintf('%.8F',($slot%$grid['cols'])*($grid['card_w']+$D['gap'])); ?>mm;top:<?php echo sprintf('%.8F',floor($slot/$grid['cols'])*($grid['card_h']+$D['gap'])); ?>mm}
<?php endfor; ?>
.nofit{
  font-family:<?php echo $fontStack; ?>;font-size:3.4mm;line-height:1.8;
  color:#b91c1c;padding:6mm;border:0.4mm dashed #fca5a5;border-radius:2mm;
}
@media print{
  .toolbar{display:none!important}
  @page{size:<?php echo $grid['paper_w']; ?>mm <?php echo $grid['paper_h']; ?>mm;margin:0}
  html,body{background:#fff}
  .page{box-shadow:none;margin:0;page-break-inside:avoid;break-inside:avoid}
}
</style>
<?php echo app_appearance_head(); ?>
</head>
<body>
<?php card_ornament_defs($themeColor, $accentColor); ?>
<div class="toolbar no-print">
    <button onclick="appPrint()">چاپ</button>
    <span>
        <?php echo tr_num(count($students), 'fa'); ?> دانش‌آموز ·
        <?php echo tr_num($copies, 'fa'); ?> نسخه برای هر نفر = <?php echo tr_num(count($students) * $copies, 'fa'); ?> نسخه کارت ·
        <?php echo clean($D['paper']); ?><?php echo $D['orient'] === 'landscape' ? ' افقی' : ''; ?> ·
        <?php echo tr_num($grid['cols'], 'fa'); ?>×<?php echo tr_num($grid['rows'], 'fa'); ?>
        = <?php echo tr_num($grid['per_page'], 'fa'); ?> کارت در هر صفحه ·
        <?php echo tr_num(count($pages), 'fa'); ?> صفحه ·
        مقیاس کارت <?php echo tr_num(round($D['scale'] * 100), 'fa'); ?>٪ · تنظیم چاپگر: مقیاس ۱۰۰٪، یک صفحه در هر برگ، بدون سربرگ/پابرگ
    </span>
</div>
<?php if (!$gridFits): ?>
    <div class="page"><div class="nofit">
        با این تنظیمات حتی یک کارت در صفحه جا نمی‌شود.<br>
        مقیاس را کم کنید، حاشیهٔ صفحه را کاهش دهید، یا کاغذ بزرگ‌تری انتخاب کنید.
    </div></div>
<?php else: ?>
    <?php foreach ($pages as $pageItems): ?>
    <div class="page">
        <div class="page-scale">
            <div class="sheet" data-card-layout="<?php echo clean($D['layout']); ?>">
            <?php foreach ($pageItems as $it):
                $s = $it['s'];
                $fullName = trim($s['first_name'] . ' ' . $s['last_name']);
                if ($it['back']) include __DIR__ . '/includes/card_back.php';
                else            include __DIR__ . '/includes/card_face.php';
            endforeach; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
<script src="assets/js/card-custom.js"></script>
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
    setTimeout(function(){ fitCustomCards(document); appPrint(); }, 250);
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
                <label class="text-xs">شکل کارت</label>
                <select id="cLayout" class="form-select" data-no-search="1" onchange="applyCardDesign()">
                    <option value="full">کامل — ۸۵٫۶×۵۴ با همهٔ اطلاعات (QR ۴۰mm)</option>
                    <option value="custom">سفارشی</option>
                    <option value="qrmax">تگ‌محور — همان اندازه، بزرگ‌ترین QR (۵۰mm)</option>
                    <option value="sq">مربع تگ‌محور — ۵۴×۵۴، QR ۴۸mm (۷۹٪ کارت)</option>
                </select>
            </div>
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
                <input type="range" id="cGap" min="0" max="40" step="1" value="4" style="width:100%" oninput="applyCardDesign()">
            </div>
            <div>
                <label class="text-xs">حاشیه صفحه چاپ: <b><span id="vCMargin">۸</span> میلی‌متر</b></label>
                <input type="range" id="cMargin" min="0" max="50" step="1" value="8" style="width:100%" oninput="applyCardDesign()">
            </div>
            <div>
                <label class="text-xs">کاغذ چاپ</label>
                <select id="cPaper" class="form-select" data-no-search="1" onchange="applyCardDesign()">
                    <?php foreach (card_paper_sizes() as $pk => $pv): ?>
                        <option value="<?php echo clean($pk); ?>" <?php echo $pk === 'A4' ? 'selected' : ''; ?>><?php echo clean($pv['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-xs">جهت کاغذ</label>
                <select id="cOrient" class="form-select" data-no-search="1" onchange="applyCardDesign()">
                    <option value="portrait">عمودی</option>
                    <option value="landscape">افقی</option>
                </select>
            </div>
            <div>
                <label class="text-xs">اندازهٔ کارت: <b><span id="vCScale">۱۰۰</span>٪</b></label>
                <input type="range" id="cScale" min="<?php echo (int)round(card_scale_min()*100); ?>" max="<?php echo (int)round(card_scale_max()*100); ?>" step="5" value="100" style="width:100%" oninput="applyCardDesign()">
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
            <div>
                <label class="text-xs" for="cCopies">تعداد نسخه برای هر دانش‌آموز</label>
                <select id="cCopies" class="form-select" data-no-search="1" onchange="applyCardDesign()">
                    <?php for ($copyOption = 1; $copyOption <= 10; $copyOption++): ?>
                    <option value="<?php echo $copyOption; ?>" <?php echo $copies === $copyOption ? 'selected' : ''; ?>><?php echo tr_num($copyOption, 'fa'); ?></option>
                    <?php endfor; ?>
                </select>
                <div class="text-xs text-muted">نسخه‌های هر دانش‌آموز پشت سر هم چاپ می‌شوند.</div>
            </div>

            <label class="flex items-center gap-2 text-xs font-semibold"><input type="checkbox" id="cPhoto" checked onchange="applyCardDesign()"> نمایش عکس دانش‌آموز</label>
            <label class="flex items-center gap-2 text-xs font-semibold"><input type="checkbox" id="cNid" checked onchange="applyCardDesign()"> نمایش کد ملی</label>
            <label class="flex items-center gap-2 text-xs font-semibold"><input type="checkbox" id="cYear" checked onchange="applyCardDesign()"> نمایش سال تحصیلی</label>
            <label class="flex items-center gap-2 text-xs font-semibold"><input type="checkbox" id="cCut" checked onchange="applyCardDesign()"> خط برش دور کارت</label>
        </div>
    </div>

    <div class="card no-print">
        <div id="customCardOptions" class="no-print" style="display:none;margin-bottom:12px">
            <div style="margin-bottom:12px">
                <label for="cCustomLogo" class="text-xs font-bold">تعویض لوگوی کارت سفارشی</label>
                <input id="cCustomLogo" type="file" accept="image/png,image/jpeg,image/webp" onchange="uploadCustomCardLogo(this)" data-csrf="<?php echo clean(csrf_token()); ?>">
                <button id="cLogoReset" type="button" class="btn btn-outline text-xs" onclick="resetCustomCardLogo()">بازگشت به لوگوی پیش‌فرض</button>
                <p class="text-xs text-muted">PNG، JPG یا WebP تا ۲ مگابایت؛ فقط لوگوی کارت سفارشی در همین نصب تغییر می‌کند، نه لوگوی عمومی مدرسه.</p>
                <p id="cLogoStatus" class="text-xs" role="status" aria-live="polite"></p>
            </div>
            <label for="cCustomText" class="text-xs font-bold">متن یا نشانی پایین کارت — تنظیم دستی</label>
            <input id="cCustomText" style="max-width:360px" type="text" dir="ltr" maxlength="80" class="form-input" value="<?php echo clean($cardCustomText); ?>" oninput="applyCardDesign()">
            <p class="text-xs text-muted">نشانی مانند bacirat.ir یا هر متن دلخواه را بنویسید؛ تغییر بلافاصله در پیش‌نمایش و چاپ اعمال و در همین مرورگر ذخیره می‌شود. برای حذف نوشته، کادر را خالی کنید.</p>
        </div>
        <div class="flex justify-between items-center" style="margin-bottom:10px;flex-wrap:wrap;gap:8px">
            <h3 class="font-bold text-sm">پیش‌نمایش زندهٔ صفحهٔ چاپ</h3>
            <div class="text-xs text-muted" id="pvInfo"></div>
        </div>

        <?php card_ornament_defs($themeColor, $accentColor); ?>
        <?php /* v4.142.0 — پیش‌نمایش «یک صفحهٔ واقعی».
              فقط صفحهٔ اول رندر می‌شود (خواستهٔ کارفرما برای بهینه ماندن)،
              ولی دیگر سقف ثابتی ندارد: هر چند کارت که در فضای قابل چاپِ
              کاغذ جا شود.

              چرا card_preview_cap(): بیشترین حالت ممکن، A2 عمودی با
              کارت مربع، مقیاس ۶۰٪ و حاشیهٔ صفر است که ۲۱۶ کارت می‌شود.
              این عدد از خودِ هندسه حساب می‌شود، نه حدس — پس اگر روزی
              کاغذ بزرگ‌تری اضافه شود، خودکار بالا می‌رود.

              چاپ نهایی همهٔ دانش‌آموزان را در همهٔ صفحات می‌آورد. */ ?>
        <?php
        $D = ['photo' => true, 'nid' => true, 'year' => true, 'theme' => 'classic',
              'cut' => true, 'layout' => 'full'];
        $previewList = array_slice($students, 0, card_preview_cap());
        ?>
        <!-- Source faces are never multiplied here. Only one live page is cloned. -->
        <div id="cardPreviewSources" style="display:none" aria-hidden="true">
            <?php foreach ($previewList as $s):
                $fullName = trim($s['first_name'] . ' ' . $s['last_name']); ?>
                <div class="preview-source">
                    <?php include __DIR__ . '/includes/card_face.php';
                          include __DIR__ . '/includes/card_back.php'; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="pv-wrap">
            <div class="pv-paper" id="pvPaper">
                <div class="pv-scale" id="pvScale">
                    <div class="sheet" id="cardPreview" style="--gap:4mm">

                    </div>
                </div>
                <?php if (!$students): ?>
                    <div class="text-center text-muted" style="font-size:13px;line-height:1.6;padding:20px">دانش‌آموزی یافت نشد.</div>
                <?php endif; ?>
            </div>
        </div>
        <p class="text-xs text-muted" id="pvNote" style="margin-top:10px"></p>
    </div>
</div>

<style>
<?php /* v4.137.0: همان فونت‌ها و متغیرهای نمای چاپ، تا پیش‌نمایش با
      خروجی چاپ یکی باشد. */ ?>
<?php echo card_font_faces(); ?>
:root{--cp:<?php echo clean($themeColor); ?>;--ca:<?php echo clean($accentColor); ?>;<?php echo card_font_vars(); ?>}
<?php include __DIR__ . '/includes/card_styles.php'; ?>
<?php include __DIR__ . '/includes/card_sheet_layout.php'; ?>
<?php include __DIR__ . '/includes/card_custom_styles.php'; ?>
.cut{outline:0.25mm dashed #cbd5e1}

/* v4.141.0 — کاغذ پیش‌نمایش.
   کاغذ با ابعاد میلی‌متری واقعی ساخته می‌شود، بعد کلِ آن با یک
   ضریب دوم کوچک می‌شود تا داخل عرض کارت صفحه جا شود. یعنی آنچه
   می‌بینید از نظر نسبت‌ها دقیقاً همان چیزی است که چاپ می‌شود. */
.pv-wrap{overflow:auto;background:#e9eef5;border:1px solid var(--border-color);
  border-radius:.6rem;padding:14px;text-align:center}
.pv-paper{
  position:relative;display:inline-block;vertical-align:top;box-sizing:border-box;overflow:hidden;
  background:#fff;box-shadow:0 3px 14px rgba(15,23,42,.18);
  -webkit-transform-origin:top center;transform-origin:top center;
  font-size:0;line-height:0;
}
.pv-scale{position:absolute}
.pv-over{outline:2px dashed #dc2626;outline-offset:-2px}
@media print{ .no-print{display:none!important} }
</style>

<script src="assets/js/card-custom.js"></script>
<script src="assets/js/qrcode-generator.js"></script>
<script>
var CARD_LS = 'mtag_card_design_v1';
/* از همان منبع PHP می‌آید تا اگر طرحی اضافه شد، اینجا جا نماند */
var THEME_KEYS = <?php echo json_encode(array_keys(card_themes())); ?>;
var THEME_ART = <?php
    $previewArt = [];
    foreach (array_keys(card_themes()) as $themeKey) $previewArt[$themeKey] = card_theme_art($themeKey);
    echo json_encode($previewArt);
?>;
/* اندازهٔ کاغذها و ابعاد پایهٔ کارت از همان منبع PHP می‌آیند، تا
   پیش‌نمایش و چاپ هرگز دو حساب جدا نداشته باشند. */
var PAPERS = <?php echo json_encode(card_paper_sizes()); ?>;
var CARD_BASE = <?php echo json_encode([
  'full'  => card_base_size('full'),
  'qrmax' => card_base_size('qrmax'),
  'sq'    => card_base_size('sq'),
  'custom'=> card_base_size('custom'),
]); ?>;
var TOTAL_STUDENTS = <?php echo (int)count($students); ?>;
var PREVIEW_CARDS  = <?php echo (int)count($previewList); ?>;

function printCopies(value){
  if (typeof value !== 'string' && typeof value !== 'number') return 1;
  var text = String(value).replace(/[۰-۹]/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d);})
      .replace(/[٠-٩]/g,function(d){return '٠١٢٣٤٥٦٧٨٩'.indexOf(d);}).replace(/^\s+|\s+$/g,'');
  return /^(?:[1-9]|10)$/.test(text) ? Number(text) : 1;
}
var previewCopiesKey = '';
function buildCopyPreview(d, capacity){
  var key = d.copies + ':' + d.side + ':' + capacity;
  if (key === previewCopiesKey) return;
  previewCopiesKey = key;
  var pv = document.getElementById('cardPreview');
  pv.innerHTML = '';
  var sources = document.querySelectorAll('#cardPreviewSources .preview-source');
  var fragment = document.createDocumentFragment(), count = 0;
  for (var i = 0; i < sources.length && count < capacity; i++) {
    for (var copy = 0; copy < d.copies && count < capacity; copy++) {
      var faces = sources[i].querySelectorAll(d.side === 'both' ? '.card-id,.card-back' : '.card-id');
      for (var side = 0; side < faces.length && count < capacity; side++) {
        fragment.appendChild(faces[side].cloneNode(true)); count++;
      }
    }
  }
  pv.appendChild(fragment);
  pv.querySelectorAll('canvas[data-qr]').forEach(drawCardQR);
}

function getCardDesign(){
  return {
    copies: printCopies(document.getElementById('cCopies').value),
    paper:  document.getElementById('cPaper').value,
    orient: document.getElementById('cOrient').value,
    scale:  +document.getElementById('cScale').value / 100,
    layout: document.getElementById('cLayout').value,
    customText: document.getElementById('cCustomText').value.replace(/^\s+|\s+$/g,'').slice(0,80),
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
  document.getElementById('cCopies').value = printCopies(d.copies);
  if(d.paper)  document.getElementById('cPaper').value  = d.paper;
  if(d.orient) document.getElementById('cOrient').value = d.orient;
  if(d.scale  !== undefined) document.getElementById('cScale').value = Math.round(d.scale * 100);
  if(d.layout) document.getElementById('cLayout').value = d.layout;
  if(typeof d.customText === 'string') document.getElementById('cCustomText').value = d.customText.slice(0,80);
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

/* v4.141.0 — چیدمان صفحه: چند ستون/سطر با این تنظیمات جا می‌شود.
   دقیقاً همان فرمول card_grid_info() در PHP؛ اگر یکی عوض شود آن یکی
   هم باید عوض شود، و تست این هم‌خوانی را می‌سنجد. */
function gridInfo(d){
  var p = PAPERS[d.paper] || PAPERS.A4;
  var pw = (d.orient === 'landscape') ? p.h : p.w;
  var ph = (d.orient === 'landscape') ? p.w : p.h;
  var cb = CARD_BASE[d.layout] || CARD_BASE.full;
  var cw = cb.w * d.scale, ch = cb.h * d.scale;
  var uw = Math.max(0, pw - 2 * d.margin), uh = Math.max(0, ph - 2 * d.margin);
  /* همان فرمول PHP (card_grid_info): جای ثابت میلی‌متری، فاصله فقط بین کارت‌ها. */
  var cols = cw > 0 ? Math.floor((uw + d.gap) / (cw + d.gap)) : 0;
  var rows = ch > 0 ? Math.floor((uh + d.gap) / (ch + d.gap)) : 0;
  cols = Math.max(0, cols); rows = Math.max(0, rows);
  return { pw: pw, ph: ph, cw: cw, ch: ch, cols: cols, rows: rows, perPage: cols * rows };
}

function applyCardDesign(){
  var d = getCardDesign(), g = gridInfo(d);
  buildCopyPreview(d, g.perPage);
  document.getElementById('vCGap').textContent    = faNum(d.gap);
  document.getElementById('vCMargin').textContent = faNum(d.margin);
  document.getElementById('vCScale').textContent  = faNum(Math.round(d.scale * 100));

  var pv = document.getElementById('cardPreview');
  pv.setAttribute('data-card-layout',d.layout);
  // The card, not its flow container, scales. Both renderers use the same physical slots.
  pv.style.setProperty('--card-scale', d.scale);
  pv.querySelectorAll('.card-id,.card-back').forEach(function(c, slot){
    c.style.right = ((slot % Math.max(1,g.cols)) * (g.cw+d.gap)) + 'mm';
    c.style.top = (Math.floor(slot / Math.max(1,g.cols)) * (g.ch+d.gap)) + 'mm';
    if (c.classList.contains('card-id')) {
      THEME_KEYS.forEach(function(k){ c.classList.remove('th-' + k); });
      c.classList.add('th-' + d.theme);
    }
    ['.card-hero use', '.card-head-orn use', '.card-back-orn use'].forEach(function(selector, i){
      var use = c.querySelector(selector);
      if (use) {
        var href = '#orn-' + THEME_ART[d.theme][i];
        use.setAttribute('href', href);
        use.setAttributeNS('http://www.w3.org/1999/xlink', 'href', href);
      }
    });
    c.classList.remove('sq','qrmax','custom');
    if (d.layout === 'custom' && c.classList.contains('card-id')) c.classList.add('custom');
    var customSite = c.querySelector('.card-custom-site');
    if(customSite) customSite.textContent=d.customText;
    c.querySelectorAll('[data-custom-fit]').forEach(function(el){el.style.fontSize='';el.removeAttribute('data-custom-fit');});
    if (d.layout === 'sq') c.classList.add('sq');
    else if (d.layout === 'qrmax') c.classList.add('qrmax');
    c.classList.toggle('cut', d.cut);
    var fr = c.querySelector('.card-frame');
    if (d.theme === 'tile' && c.classList.contains('card-id') && !fr) { fr = document.createElement('div'); fr.className='card-frame'; c.insertBefore(fr, c.firstChild.nextSibling); }
    if (fr) fr.style.display = (d.theme === 'tile') ? '' : 'none';
    var ph = c.querySelector('.card-photo');   if(ph) ph.style.display = d.photo ? '' : 'none';
    var pp = c.querySelector('.card-photo-ph'); if(pp) pp.style.display = d.photo ? '' : 'none';
    var nd = c.querySelector('.card-nid');     if(nd) nd.style.display = d.nid   ? '' : 'none';
    var yr = c.querySelector('.card-year');    if(yr) yr.style.display = d.year  ? '' : 'none';
  });

  /* ── کاغذ زنده ── */
  var paper = document.getElementById('pvPaper');
  var scaleBox = document.getElementById('pvScale');
  paper.style.width     = g.pw + 'mm';
  paper.style.height    = g.ph + 'mm';
  paper.style.padding   = d.margin + 'mm';
  scaleBox.style.top = scaleBox.style.right = d.margin + 'mm';
  scaleBox.style.width = Math.max(0,g.pw-2*d.margin) + 'mm';
  scaleBox.style.height = Math.max(0,g.ph-2*d.margin) + 'mm';

  /* فقط کارت‌هایی که در صفحهٔ اول جا می‌شوند نمایش داده شوند —
     پیش‌نمایش باید «یک صفحه» باشد، نه همهٔ کارت‌ها. */
  var cards = pv.querySelectorAll('.card-id,.card-back');
  var shown = 0;
  for (var i = 0; i < cards.length; i++) {
    var vis = (g.perPage > 0 && i < g.perPage);
    cards[i].style.display = vis ? '' : 'none';
    if (vis) shown++;
  }

  /* کاغذ بزرگ (A2/A3) از عرض صفحه بیرون می‌زند؛ کل کاغذ را کوچک
     می‌کنیم تا کامل دیده شود. این فقط بزرگ‌نماییِ نمایش است و روی
     خروجی چاپ اثری ندارد. */
  var wrap = paper.parentNode;
  var avail = wrap.clientWidth - 28;
  var pxPerMm = 96 / 25.4;
  var fit = Math.min(1, avail / (g.pw * pxPerMm));
  paper.style.transform = 'scale(' + fit + ')';
  paper.style.marginBottom = (-(1 - fit) * g.ph * pxPerMm) + 'px';

  var perPage = g.perPage;
  var pagesNeeded = perPage > 0
      ? Math.ceil(TOTAL_STUDENTS * d.copies * (d.side === 'both' ? 2 : 1) / perPage) : 0;

  var info = document.getElementById('pvInfo');
  if (perPage === 0) {
    info.innerHTML = '<span style="color:#b91c1c;font-weight:700">با این تنظیمات هیچ کارتی جا نمی‌شود</span>';
    paper.classList.add('pv-over');
  } else {
    paper.classList.remove('pv-over');
    info.textContent = d.paper + (d.orient === 'landscape' ? ' افقی' : ' عمودی') +
      ' · ' + faNum(g.cols) + '×' + faNum(g.rows) + ' = ' + faNum(perPage) + ' کارت در هر صفحه' +
      ' · نمایش ' + faNum(Math.round(fit * 100)) + '٪';
  }

  var note = document.getElementById('pvNote');
  if (perPage === 0) {
    note.textContent = 'مقیاس را کم کنید، حاشیه را کاهش دهید، یا کاغذ بزرگ‌تری انتخاب کنید.';
  } else {
    var t = 'این فقط صفحهٔ اول است. در چاپ، ' + faNum(TOTAL_STUDENTS) +
            ' دانش‌آموز × ' + faNum(d.copies) + ' نسخه = ' + faNum(TOTAL_STUDENTS * d.copies) + ' نسخه کارت' + (d.side === 'both' ? ' (روی و پشت)' : '') +
            ' در ' + faNum(pagesNeeded) + ' صفحه چاپ می‌شوند.';
    if (shown < perPage) {
      /* دو علت متفاوت، دو پیام متفاوت — وگرنه کاربر فکر می‌کند
         چیزی خراب است. */
      if (TOTAL_STUDENTS * d.copies * (d.side === 'both' ? 2 : 1) < perPage) {
        t += ' (این صفحه ' + faNum(perPage) + ' جا دارد ولی فقط ' +
             faNum(TOTAL_STUDENTS * d.copies * (d.side === 'both' ? 2 : 1)) + ' قطعه برای فیلتر فعلی لازم است.)';
      } else {
        t += ' (پیش‌نمایش تا ' + faNum(PREVIEW_CARDS) + ' کارت را نشان می‌دهد؛ چاپ کامل است.)';
      }
    }
    note.textContent = t;
  }

  document.getElementById('customCardOptions').style.display = d.layout === 'custom' ? '' : 'none';
  fitCustomCards(pv);
  try { localStorage.setItem(CARD_LS, JSON.stringify(d)); } catch(e){}
}
document.addEventListener('keydown',function(e){if((e.ctrlKey||e.metaKey)&&String(e.key||'').toLowerCase()==='p'){e.preventDefault();openCardPrint();}});
function resetCardDesign(){
  document.getElementById('cCustomText').value = <?php echo json_encode($cardCustomText, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  setCardDesign({copies:1,paper:'A4',orient:'portrait',scale:1,layout:'full',theme:'classic',gap:4,margin:8,side:'front',photo:true,nid:true,year:true,cut:true});
  applyCardDesign();
}
function openCardPrint(oneId){
  var d = getCardDesign();
  var params = new URLSearchParams(window.location.search);
  params.set('print','1');
  params.set('copies', d.copies);
  if (oneId) params.set('student_id', String(oneId));
  params.set('layout', d.layout);
  params.set('custom_text',d.customText);
  params.set('paper', d.paper); params.set('orient', d.orient);
  params.set('scale', d.scale);
  params.set('theme', d.theme); params.set('gap', d.gap); params.set('margin', d.margin);
  params.set('side', d.side);
  params.set('photo', d.photo ? '1':'0'); params.set('nid', d.nid ? '1':'0');
  params.set('year', d.year ? '1':'0'); params.set('cut', d.cut ? '1':'0');
  window.open('entry-cards.php?' + params.toString(), '_blank');
}
function printOneCard(id){ openCardPrint(id); }

function drawCardQR(cv){
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
(function(){
  document.addEventListener('DOMContentLoaded', function(){
    try { var sv = localStorage.getItem(CARD_LS); if (sv) setCardDesign(JSON.parse(sv)); } catch(e){}
    <?php if (isset($_GET['copies'])): ?>document.getElementById('cCopies').value = <?php echo $copies; ?>;<?php endif; ?>
    applyCardDesign();
    if(document.fonts && document.fonts.ready) document.fonts.ready.then(function(){fitCustomCards(document.getElementById('cardPreview'));});
  });
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
