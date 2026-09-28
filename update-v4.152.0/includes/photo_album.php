<?php
// File: includes/photo_album.php  (v4.170.0)
/**
 * گزارش «آلبوم عکس» — مثل لیست کلاسی، ولی با عکس.
 *
 * هر کلاس روی صفحهٔ A4: دانش‌آموزان در کادرهای شبکه‌ای (۵ ستون × ۶ ردیف =
 * ۳۰ کادر در هر برگه)، هر کادر شامل عکس شناسنامه‌ای، نام دانش‌آموز و یک
 * فضای خالی خط‌کشی‌شده برای نوشتن یک متن کوتاه. کلاس‌های بزرگ‌تر از ۳۰
 * نفر به برگهٔ بعدی سرریز می‌شوند.
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
            "SELECT s.id, s.first_name, s.last_name, s.photo_url FROM students s WHERE " . implode(' AND ', $where),
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
        $perPage = 30;                       /* ۵ ستون × ۶ ردیف */
        $pages   = $students ? array_chunk($students, $perPage) : [[]];
        $pageN   = count($pages);

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
.grid{display:grid;grid-template-columns:repeat(5,1fr);gap:2.6mm}
.stu{border:0.35mm solid #000;border-radius:1.6mm;padding:1.4mm;position:relative;
     display:flex;flex-direction:column;align-items:center;gap:1mm;height:40.4mm}
.stu .seq{position:absolute;top:.6mm;right:1.2mm;font-size:6.5pt;color:#64748b}
.ph{width:17mm;height:22mm;border:0.25mm solid #94a3b8;border-radius:1mm;overflow:hidden;
    background:#f1f5f9;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.ph img{width:100%;height:100%;object-fit:cover}
.ph .no{font-size:6.5pt;color:#94a3b8}
.nm{font-size:7.5pt;font-weight:700;text-align:center;line-height:1.35;max-height:6.6mm;
    overflow:hidden;word-break:break-word}
.note{width:100%;flex:1;border:0.25mm dashed #94a3b8;border-radius:1mm;min-height:7mm}
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
<?php foreach ($pages as $pi => $chunk): ?>
<div class="sheet">
  <div class="hdr">
    <div class="side"><?php echo htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8'); ?><?php if ($year !== ''): ?><br>سال تحصیلی <?php echo htmlspecialchars(function_exists('tr_num') ? tr_num($year, 'fa') : $year, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></div>
    <div class="ttl">آلبوم عکس<?php if ($pageN > 1): ?> — برگهٔ <?php echo function_exists('tr_num') ? tr_num((string)($pi + 1), 'fa') : ($pi + 1); ?> از <?php echo function_exists('tr_num') ? tr_num((string)$pageN, 'fa') : $pageN; ?><?php endif; ?></div>
    <div class="side">کلاس <?php echo htmlspecialchars($className, ENT_QUOTES, 'UTF-8'); ?><?php if ($grade !== ''): ?> — پایهٔ <?php echo htmlspecialchars($grade, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?><br>تاریخ تهیه: <?php echo htmlspecialchars(function_exists('tr_num') ? tr_num($today, 'fa') : $today, ENT_QUOTES, 'UTF-8'); ?></div>
  </div>
  <div class="grid">
    <?php foreach ($chunk as $idx => $s):
        $seq  = $pi * $perPage + $idx + 1;
        $name = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
        $uri  = pab_photo_data_uri($s['photo_url'] ?? '');
    ?>
    <div class="stu">
      <span class="seq"><?php echo function_exists('tr_num') ? tr_num((string)$seq, 'fa') : $seq; ?></span>
      <div class="ph"><?php if ($uri !== ''): ?><img src="<?php echo $uri; ?>" alt=""><?php else: ?><span class="no">جای عکس</span><?php endif; ?></div>
      <div class="nm"><?php echo htmlspecialchars($name !== '' ? $name : '—', ENT_QUOTES, 'UTF-8'); ?></div>
      <div class="note"></div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="ftr"><span>تعداد دانش‌آموزان این کلاس: <?php echo function_exists('tr_num') ? tr_num((string)count($students), 'fa') : count($students); ?></span><span>فضای خالی زیر هر نام، برای یادداشت کوتاه است.</span></div>
</div>
<?php endforeach; ?>
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
