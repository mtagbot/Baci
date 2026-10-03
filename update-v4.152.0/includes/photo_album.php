<?php
// File: includes/photo_album.php  (v4.170.0)
/**
 * گزارش «آلبوم عکس» — مثل لیست کلاسی، ولی با عکس.
 *
 * v4.176.0 — بازطراحی از «شبکه» به «لیست»:
 *   • هر کلاس روی **یک** صفحهٔ A4 است؛ هیچ کلاسی به برگهٔ دوم نمی‌رود.
 *   • چیدمان لیستی: ستون‌های ردیف | عکس | نام و نام خانوادگی | نام پدر |
 *     کد ملی | یادداشت — همان چیزی که دبیر برای شناختن کلاس لازم دارد.
 *   • ارتفاع ردیف‌ها متناسب با تعداد دانش‌آموز حساب می‌شود (کلاس ۲۰ نفره
 *     ردیف بلندتر و خوانا، کلاس ۴۵ نفره ردیف کوتاه‌تر) تا همیشه یک صفحه
 *     شود. سقف پایین برای خوانایی رعایت می‌شود و اگر کلاسی بسیار بزرگ
 *     باشد، هشدار روی برگه چاپ می‌شود.
 *
 * خروجی HTML چاپ‌محور است (مانند class_list_pdf) و کاربر با «Save as PDF»
 * مرورگر فایل PDF می‌گیرد؛ عکس‌ها به‌صورت data-URI داخل صفحه جاسازی
 * می‌شوند تا در چاپ/PDF بدون مسیر خارجی دیده شوند.
 */

if (!function_exists('pab_students_of_class')) {
    /**
     * دانش‌آموزان فعال یک کلاس (نام + عکس)، به ترتیب الفبای فارسی —
     * همان قاعدهٔ لیست کلاسی دبیر.
     */
    function pab_students_of_class($className, $year = '') {
        $where = ["s.status='active'", 's.class_name = ?'];
        $params = [$className];
        if ($year !== '') {
            $where[] = "(s.academic_year = ? OR s.academic_year IS NULL OR s.academic_year = '')";
            $params[] = $year;
        }
        $rows = DB::fetchAll(
            "SELECT s.id, s.first_name, s.last_name, s.father_name, s.national_id, s.photo_url FROM students s WHERE " . implode(' AND ', $where),
            $params
        );
        if (function_exists('persian_usort_students')) persian_usort_students($rows);
        return $rows;
    }
}

if (!function_exists('pab_photo_data_uri')) {
    /**
     * عکس دانش‌آموز را از uploads/photos خوانده و به data-URI تبدیل می‌کند.
     * فقط مسیر نسبیِ داخل پروژه پذیرفته می‌شود (بدون ..، بدون پروتکل) و
     * حجم هر عکس حداکثر ۲ مگابایت — برای سرورهای کم‌قدرت.
     * خروجی: رشتهٔ data:... یا '' وقتی عکس نیست/معتبر نیست.
     */
    function pab_photo_data_uri($photoUrl) {
        $rel = trim((string)$photoUrl);
        if ($rel === '' || strpos($rel, '..') !== false || strpos($rel, '://') !== false) return '';
        $rel = ltrim($rel, '/');
        if ($rel === '' || $rel[0] === '.') return '';
        $path = dirname(__DIR__) . '/' . $rel;
        if (!is_file($path) || @filesize($path) > 2 * 1024 * 1024) return '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        if (!isset($mimes[$ext])) return '';
        $data = @file_get_contents($path);
        if ($data === false || $data === '') return '';
        return 'data:' . $mimes[$ext] . ';base64,' . base64_encode($data);
    }
}

if (!function_exists('pab_render_print_html')) {
    /**
     * HTML چاپی آلبوم یک کلاس.
     *
     * @param string $className  نام کلاس (مثل «۷/۱»)
     * @param string $grade      پایه
     * @param array  $students   خروجی pab_students_of_class
     * @param string $schoolName نام مدرسه برای سربرگ
     * @param bool   $autoPrint  true → پنجرهٔ چاپ خودکار باز می‌شود
     */
    function pab_render_print_html($className, $grade, array $students, $schoolName = '', $autoPrint = false) {
        $fontUrl = 'uploads/B-Titr/B-Titr.ttf';
        $year    = function_exists('get_setting') ? get_setting('current_academic_year', '') : '';
        $today   = function_exists('jalali_now') ? jalali_now() : '';
        /* v4.177.0: چیدمان دوستونه — صفحه به دو نیمه تقسیم می‌شود، پس هر ردیف
           دو برابر بلندتر از چیدمان تک‌ستونه می‌شود و عکس‌ها بزرگتر می‌شوند.
           همچنان هر کلاس روی یک برگهٔ A4 است. */
        $total   = count($students);
        $rows    = $total > 0 ? (int)ceil($total / 2) : 1;   /* ردیف در هر نیمه */
        $sheetH  = 281;   /* میلی‌متر */
        $pad     = 8;     /* حاشیهٔ صفحه در CSS */
        $headerH = 16;    /* سربرگ */
        $thH     = 6;     /* ردیف عنوان جدول */
        $avail   = max(40, $sheetH - 2 * $pad - $headerH - $thH);
        $minRow  = 4.6;   /* کمتر از این خوانا نیست */
        $maxRow  = 26;    /* بیش از این عکس بی‌نهایت بزرگ می‌شود */
        $rowH    = $total > 0 ? min($maxRow, max($minRow, $avail / $rows)) : $maxRow;
        /* عکس نسبت ۳ به ۴ (عکس شناسنامه‌ای) و همیشه کوچکتر از ارتفاع ردیف */
        $photoH  = min(30, max(9.5, $rowH - 2.4));
        $photoW  = round($photoH * 0.75, 2);
        $over    = $total > 0 && ($rowH <= $minRow + 0.001) && ($rows * $minRow > $avail);
        $fontSm  = $total > 68 ? 7 : ($total > 52 ? 7.6 : ($total > 36 ? 8.2 : 9));
        $fontMeta = $total > 68 ? 6.8 : ($total > 52 ? 7.4 : 8);
        $pageN   = 1;     /* یک برگه برای هر کلاس */

        ob_start();
        ?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head><meta name=viewport content="width=device-width, initial-scale=1">
<meta charset="UTF-8">
<title>آلبوم عکس کلاس <?php echo htmlspecialchars($className, ENT_QUOTES, 'UTF-8'); ?></title>
<style>
@font-face{font-family:'BTitr';src:url('<?php echo $fontUrl; ?>') format('truetype');font-display:block}
*{box-sizing:border-box;margin:0;padding:0}
html,body{background:#eef2f7}
body{font-family:'BTitr',Tahoma,sans-serif}
.sheet{
  width:210mm;min-height:281mm;background:#fff;margin:0 auto 8mm auto;
  padding:8mm;box-shadow:0 2px 12px rgba(15,23,42,.16);
  page-break-after:always;break-after:page;overflow:hidden;
}
.sheet:last-of-type{page-break-after:auto;break-after:auto;margin-bottom:0}
@page{size:A4 portrait;margin:0}
.hdr{display:flex;justify-content:space-between;align-items:center;
     border:0.4mm solid #000;border-radius:2mm;padding:2mm 3mm;margin-bottom:3mm}
.hdr .side{font-size:9pt;line-height:1.8}
.hdr .ttl{font-size:12pt;font-weight:700;text-align:center}
.cols{display:flex;gap:4mm;align-items:flex-start}
.half{flex:1;min-width:0}
.list{width:100%;border-collapse:collapse;table-layout:fixed}
.list th,.list td{border:0.3mm solid #334155;padding:0 1mm;vertical-align:middle;overflow:hidden}
.list th{background:#eef2f7;font-size:8pt;font-weight:700;text-align:center;height:6mm}
.list td{text-align:center}
.list td.nm{text-align:right;font-weight:700;padding-right:2mm}
.list td.note{background:
  repeating-linear-gradient(to bottom, transparent 0, transparent calc(var(--row) - 0.4mm), #cbd5e1 calc(var(--row) - 0.4mm), #cbd5e1 var(--row))}
.list tr{break-inside:avoid;page-break-inside:avoid}
.list .seq{font-size:7.5pt;color:#475569}
.list .nm-txt{font-size:9pt;line-height:1.3}
.list .meta{font-size:8pt;color:#334155}
.half .list th{font-size:7.6pt}
.ph{width:11mm;height:14.6mm;border:0.25mm solid #94a3b8;border-radius:.8mm;overflow:hidden;
    background:#f1f5f9;margin:0 auto;display:flex;align-items:center;justify-content:center}
.ph img{width:100%;height:100%;object-fit:cover}
.ph .no{font-size:6pt;color:#94a3b8}
.warn{font-size:7.5pt;color:#b45309;background:#fffbeb;border:0.3mm solid #f59e0b;
      border-radius:1mm;padding:1mm 2mm;margin-top:2mm}
.ftr{margin-top:2.5mm;display:flex;justify-content:space-between;font-size:8pt;color:#334155}
.noprint{max-width:210mm;margin:10px auto;padding:0 8px;font-family:Tahoma;
         display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.noprint button{padding:9px 20px;border:0;border-radius:8px;background:#2563eb;color:#fff;
                font-size:13px;cursor:pointer;font-family:inherit}
.noprint .hint{font-size:12px;color:#0f172a;background:#fff;padding:7px 11px;border-radius:8px}
@media print{
  html,body{background:#fff}
  .sheet{box-shadow:none;margin:0 auto}
  .noprint{display:none!important}
}
</style>
</head>
<body>
<div class="noprint">
  <button type="button" onclick="printAlbum()">چاپ / ذخیرهٔ PDF</button>
  <span class="hint">در پنجرهٔ چاپ، مقصد را «Save as PDF» و اندازهٔ کاغذ را A4 انتخاب کنید.</span>
</div>
<div class="sheet" style="--row:<?php echo number_format($rowH, 2, '.', ''); ?>mm">
  <div class="hdr">
    <div class="side"><?php echo htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8'); ?><?php if ($year !== ''): ?><br>سال تحصیلی <?php echo htmlspecialchars(function_exists('tr_num') ? tr_num($year, 'fa') : $year, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></div>
    <div class="ttl">آلبوم عکس — کلاس <?php echo htmlspecialchars($className, ENT_QUOTES, 'UTF-8'); ?></div>
    <div class="side"><?php if ($grade !== ''): ?>پایهٔ <?php echo htmlspecialchars($grade, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?><br>تاریخ تهیه: <?php echo htmlspecialchars(function_exists('tr_num') ? tr_num($today, 'fa') : $today, ENT_QUOTES, 'UTF-8'); ?></div>
  </div>
  <div class="cols">
  <?php
  /* v4.177.0: دانش‌آموزان به دو نیمه تقسیم می‌شوند (نیمهٔ راست و نیمهٔ چپ صفحه). */
  $perHalf = max(1, (int)ceil(count($students) / 2));
  $halves  = $students ? array_chunk($students, $perHalf) : [[]];
  if ($students && count($halves) === 1) $halves[] = [];
  foreach ($halves as $hi => $half):
      $start = $hi * (int)ceil(count($students) / 2);
  ?>
    <div class="half">
      <table class="list">
        <colgroup>
          <col style="width:7mm"><col style="width:<?php echo number_format($photoW + 3, 2, '.', ''); ?>mm">
          <col style="width:26mm"><col>
        </colgroup>
        <thead><tr>
          <th>ردیف</th><th>عکس</th><th>نام و نام خانوادگی</th><th>یادداشت</th>
        </tr></thead>
        <tbody>
        <?php foreach ($half as $j => $s):
            $seq  = $start + $j + 1;
            $name = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
            $uri  = pab_photo_data_uri($s['photo_url'] ?? '');
        ?>
          <tr style="height:<?php echo number_format($rowH, 2, '.', ''); ?>mm">
            <td class="seq"><?php echo function_exists('tr_num') ? tr_num((string)$seq, 'fa') : $seq; ?></td>
            <td><div class="ph" style="height:<?php echo number_format($photoH, 2, '.', ''); ?>mm;width:<?php echo number_format($photoW, 2, '.', ''); ?>mm"><?php if ($uri !== ''): ?><img src="<?php echo $uri; ?>" alt=""><?php else: ?><span class="no">جای عکس</span><?php endif; ?></div></td>
            <td class="nm"><span class="nm-txt" style="font-size:<?php echo number_format($fontSm, 1, '.', ''); ?>pt"><?php echo htmlspecialchars($name !== '' ? $name : '—', ENT_QUOTES, 'UTF-8'); ?></span></td>
            <td class="note"></td>
          </tr>
        <?php endforeach; if (!$half): ?>
          <tr><td colspan="4" style="height:<?php echo number_format($rowH, 2, '.', ''); ?>mm">دانش‌آموزی در این کلاس ثبت نشده است.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php endforeach; ?>
  </div>
  <?php if ($over): ?><div class="warn">این کلاس بیش از حد پر است؛ برای خوانایی بهتر، عکس‌ها را در «مدیریت دانش‌آموزان» بررسی و در صورت امکان کلاس را تقسیم کنید.</div><?php endif; ?>
</div>
<script>
/* اندازهٔ برگه‌ها روی نمایشگر کوچک‌تر از عرض A4 است؛ مثل لیست کلاسی،
   برگه را به نسبت عرض موجود کوچک می‌کنیم (فقط روی صفحه، نه در چاپ). */
function fitAlbumSheets(){
  var A4W=210*96/25.4;
  var sheets=document.querySelectorAll('.sheet');
  for(var i=0;i<sheets.length;i++){
    var el=sheets[i];
    var avail=(el.parentElement?el.parentElement.clientWidth:window.innerWidth)-24;
    var f=Math.min(1,avail/A4W);
    el.style.transform='scale('+f+')';
    el.style.transformOrigin='top center';
    el.style.marginBottom=(f<1?(-el.scrollHeight*(1-f)+30):30)+'px';
  }
}
window.printAlbum=function(){
  var ready=document.fonts?document.fonts.ready:Promise.resolve();
  return ready.then(function(){window.print();});
};
window.addEventListener('load',fitAlbumSheets);
window.addEventListener('resize',fitAlbumSheets);
fitAlbumSheets();
</script>
<?php if ($autoPrint): ?>
<script>
(function(){
  var done=false;
  function go(){ if(done) return; done=true; setTimeout(function(){ printAlbum(); },250); }
  if(document.fonts&&document.fonts.ready&&typeof document.fonts.ready.then==='function'){ document.fonts.ready.then(go); }
  else { setTimeout(go,800); }
})();
</script>
<?php endif; ?>
</body>
</html><?php
        return ob_get_clean();
    }
}
