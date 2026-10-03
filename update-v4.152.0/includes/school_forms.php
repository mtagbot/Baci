<?php
// File: includes/school_forms.php  (v4.177.0)
/**
 * فرم‌های اداری مدرسه — بازسازی چهار طرح گزارش که در پوشهٔ
 * school-reports-raw-files به‌صورت قالب Stimulsoft (.mrt) رسیده بود:
 *
 *   1) absentsForm.mrt  → «برگه حضور و غیاب» — یک هفته کامل: شنبه تا
 *      پنجشنبه (۶ روز)، هر روز ۳ زنگ. ردیف‌ها یا نام دانش‌آموزان‌اند یا خالی.
 *   2) amanat.mrt       → «فرم ثبت تحویل و دریافت کتاب» — یک برگهٔ خالی با
 *      ستون «نام دریافت کننده».
 *   3) anjoman.mrt      → «دعوتنامه جلسهٔ عمومی انجمن اولیاء و مربیان» —
 *      دو دعوتنامه در هر برگه، با تایپوگرافی نامهٔ رسمی فارسی.
 *   4) campConsent.mrt  → «رضایت‌نامه اردو» — چهار برگه در هر صفحه، با مهر
 *      مدیر به‌صورت روی‌هم (overlay) روی متن.
 *
 * تفاوت با قالب‌های اصلی: داده‌ها از بانک اطلاعاتی خودِ مدرسه خوانده می‌شود
 * (دانش‌آموزان، کلاس، نام مدرسه، سال تحصیلی) و خروجی HTML چاپ‌محور است که با
 * «Save as PDF» مرورگر به PDF تبدیل می‌شود.
 *
 * v4.177.0: هر فرم دو حالت دارد —
 *   $blank = true  → «چاپ پایه»: برگهٔ خالی بدون نام (برای نوشتن با دست)
 *   $blank = false → «چاپ کل دانش‌آموزان»: نام دانش‌آموزان روی برگه چاپ می‌شود
 */

if (!function_exists('sf_students_of_class')) {
    /** دانش‌آموزان فعال یک کلاس، به ترتیب الفبای فارسی. */
    function sf_students_of_class($className, $year = '') {
        $where = ["s.status='active'", 's.class_name = ?'];
        $params = [$className];
        if ($year !== '') {
            $where[] = "(s.academic_year = ? OR s.academic_year IS NULL OR s.academic_year = '')";
            $params[] = $year;
        }
        $rows = DB::fetchAll(
            "SELECT s.id, s.first_name, s.last_name, s.father_name, s.national_id, s.class_name, s.grade_level, s.photo_url FROM students s WHERE " . implode(' AND ', $where),
            $params
        );
        if (function_exists('persian_usort_students')) persian_usort_students($rows);
        return $rows;
    }
}

if (!function_exists('sf_students_of_grade')) {
    /** همهٔ دانش‌آموزان فعال یک پایه (برای چاپ پایه‌ای). */
    function sf_students_of_grade($grade, $year = '') {
        $where = ["s.status='active'", 's.grade_level = ?'];
        $params = [$grade];
        if ($year !== '') {
            $where[] = "(s.academic_year = ? OR s.academic_year IS NULL OR s.academic_year = '')";
            $params[] = $year;
        }
        $rows = DB::fetchAll(
            "SELECT s.id, s.first_name, s.last_name, s.father_name, s.national_id, s.class_name, s.grade_level, s.photo_url FROM students s WHERE " . implode(' AND ', $where),
            $params
        );
        if (function_exists('persian_usort_students')) persian_usort_students($rows);
        return $rows;
    }
}

if (!function_exists('sf_all_students')) {
    /** همهٔ دانش‌آموزان فعال مدرسه. */
    function sf_all_students($year = '') {
        $where = ["s.status='active'"];
        $params = [];
        if ($year !== '') {
            $where[] = "(s.academic_year = ? OR s.academic_year IS NULL OR s.academic_year = '')";
            $params[] = $year;
        }
        $rows = DB::fetchAll(
            "SELECT s.id, s.first_name, s.last_name, s.father_name, s.national_id, s.class_name, s.grade_level, s.photo_url FROM students s WHERE " . implode(' AND ', $where),
            $params
        );
        if (function_exists('persian_usort_students')) persian_usort_students($rows);
        return $rows;
    }
}

if (!function_exists('sf_context')) {
    /** اطلاعات سربرگ: نام مدرسه، سال تحصیلی، تاریخ روز، منطقه/اداره. */
    function sf_context() {
        return [
            'school'  => (string)get_setting('school_name', 'آموزشگاه'),
            'year'    => (string)get_setting('current_academic_year', ''),
            'today'   => function_exists('jalali_now') ? jalali_now() : (function_exists('jdate') ? jdate('Y/m/d') : ''),
            'region'  => (string)(get_setting('school_region', '') !== '' ? get_setting('school_region', '') : get_setting('school_province', '')),
            'stamp'   => (string)get_setting('school_stamp_url', ''),
            'signature' => (string)get_setting('principal_signature_url', ''),
        ];
    }
}

if (!function_exists('sf_fa')) {
    /** عدد فارسی با نگهبانِ نبودن تابع. */
    function sf_fa($value) {
        return function_exists('tr_num') ? tr_num((string)$value, 'fa') : (string)$value;
    }
}

if (!function_exists('sf_data_uri')) {
    /** تصویر مهر/امضا به‌صورت data-URI (فایل محلی، سقف ۲ مگابایت). */
    function sf_data_uri($rel) {
        $rel = trim((string)$rel);
        if ($rel === '' || strpos($rel, '..') !== false || strpos($rel, '://') !== false) return '';
        $rel = ltrim($rel, '/');
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

if (!function_exists('sf_head')) {
    /** سرصفحهٔ مشترک همهٔ برگه‌ها (فونت تیتر، قالب A4، دکمهٔ چاپ). */
    function sf_head($title) {
        ob_start();
        ?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head><meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
<style>
@font-face{font-family:'BTitr';src:url('uploads/B-Titr/B-Titr.ttf') format('truetype');font-display:block}
*{box-sizing:border-box;margin:0;padding:0}
html,body{background:#eef2f7}
body{font-family:'BTitr',Tahoma,sans-serif;color:#0f172a}
.sheet{width:210mm;min-height:281mm;background:#fff;margin:0 auto 8mm auto;padding:8mm;
       box-shadow:0 2px 12px rgba(15,23,42,.16);page-break-after:always;break-after:page;
       position:relative;overflow:hidden}
.sheet:last-of-type{page-break-after:auto;break-after:auto;margin-bottom:0}
@page{size:A4 portrait;margin:0}
.hdr{display:flex;justify-content:space-between;align-items:center;border:0.4mm solid #000;
     border-radius:2mm;padding:2mm 3mm;margin-bottom:3mm}
.hdr .side{font-size:8.5pt;line-height:1.9}
.hdr .ttl{font-size:12pt;font-weight:700;text-align:center}
.ftr{margin-top:3mm;display:flex;justify-content:space-between;font-size:8pt;color:#334155}
.note{font-size:8pt;line-height:2}
.stamp{position:absolute;left:14mm;bottom:16mm;width:30mm;opacity:.85}
.blk ol{list-style:persian decimal;margin-right:9mm;line-height:1.9}
.blk b{font-weight:700}
.noprint{max-width:210mm;margin:10px auto;padding:0 8px;font-family:Tahoma;
         display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.noprint button{padding:9px 20px;border:0;border-radius:8px;background:#2563eb;color:#fff;
                font-size:13px;cursor:pointer;font-family:inherit}
.noprint .hint{font-size:12px;color:#0f172a;background:#fff;padding:7px 11px;border-radius:8px}
@media print{html,body{background:#fff}.sheet{box-shadow:none;margin:0 auto}.noprint{display:none!important}}
</style>
</head>
<body>
<div class="noprint">
  <button type="button" onclick="printForms()">چاپ / ذخیرهٔ PDF</button>
  <span class="hint">در پنجرهٔ چاپ، کاغذ A4 و مقصد «Save as PDF» را انتخاب کنید.</span>
</div>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('sf_tail')) {
    /** پاصفحهٔ مشترک: کوچک‌کردن برگه روی نمایشگر + دکمهٔ چاپ. */
    function sf_tail($autoPrint = false) {
        ob_start();
        ?>
<script>
function fitFormSheets(){
  var A4W=210*96/25.4, sheets=document.querySelectorAll('.sheet');
  for(var i=0;i<sheets.length;i++){
    var el=sheets[i], avail=(el.parentElement?el.parentElement.clientWidth:window.innerWidth)-24;
    var f=Math.min(1,avail/A4W);
    el.style.transform='scale('+f+')'; el.style.transformOrigin='top center';
    el.style.marginBottom=(f<1?(-el.scrollHeight*(1-f)+30):30)+'px';
  }
}
window.printForms=function(){
  var ready=document.fonts?document.fonts.ready:Promise.resolve();
  return ready.then(function(){window.print();});
};
window.addEventListener('load',fitFormSheets); window.addEventListener('resize',fitFormSheets);
fitFormSheets();
</script>
<?php if ($autoPrint): ?>
<script>(function(){var done=false;function go(){if(done)return;done=true;setTimeout(function(){printForms();},250);}
if(document.fonts&&document.fonts.ready&&typeof document.fonts.ready.then==='function'){document.fonts.ready.then(go);}else{setTimeout(go,800);}})();</script>
<?php endif; ?>
</body>
</html><?php
        return ob_get_clean();
    }
}

if (!function_exists('sf_shell')) {
    /** پوستهٔ برگهٔ کامل (یک برگه در هر صفحه). */
    function sf_shell($title, $bodyHtml, $autoPrint = false) {
        return sf_head($title) . $bodyHtml . sf_tail($autoPrint);
    }
}

if (!function_exists('sf_shell_blocks')) {
    /**
     * پوستهٔ چندبرگه‌ای: $blocks به $perPage برگه در هر صفحه تقسیم می‌شود.
     * برای دعوتنامه (۲ در هر برگه) و رضایت‌نامه (۴ در هر برگه).
     */
    function sf_shell_blocks($title, array $blocks, $perPage = 2, $autoPrint = false) {
        $out = sf_head($title);
        if (!$blocks) $blocks = ['<div class="blk">برگی برای چاپ نیست.</div>'];
        foreach (array_chunk($blocks, $perPage) as $page) {
            $out .= '<div class="sheet">' . implode('', $page) . '</div>';
        }
        return $out . sf_tail($autoPrint);
    }
}

/* ═══════════ ۱) برگهٔ حضور و غیاب هفتگی ═══════════ */

if (!function_exists('sf_render_absents_form')) {
    /**
     * برگهٔ حضور و غیاب — بازشناسی absentsForm.mrt (نسخهٔ هفتگی v4.177.0)
     * چیدمان: فقط یک خط با نام کلاس (مثل «۷-۱») و جدول یک هفته:
     * ۶ روز (شنبه تا پنجشنبه) × ۳ زنگ. ردیف‌ها یا نام دانش‌آموزان‌اند
     * ($blank=false) یا خالی ($blank=true).
     */
    function sf_render_absents_form($className, $grade, array $students, $ctx, $autoPrint = false, $blank = false) {
        $days    = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه'];
        $periods = 3;
        $blankRows = 30;
        $rows = $blank ? $blankRows : count($students);
        if ($rows < 1) $rows = 1;
        /* ارتفاع ردیف: صفحه ۲۸۱ میلی‌متر منهای حاشیه، خط کلاس و دو ردیف عنوان */
        $avail = 281 - 16 - 12 - 14;
        $rowH  = max(5.4, min(11, $avail / $rows));
        ob_start();
        ?>
<div class="sheet">
  <div style="font-size:13pt;font-weight:700;margin-bottom:2mm">کلاس <?php echo htmlspecialchars($className, ENT_QUOTES, 'UTF-8'); ?></div>
  <table style="width:100%;border-collapse:collapse;table-layout:fixed">
    <colgroup><col style="width:9mm"><col style="width:46mm"><?php for ($i = 0; $i < count($days) * $periods; $i++): ?><col><?php endfor; ?></colgroup>
    <thead>
      <tr>
        <th colspan="2" style="border:0.35mm solid #000;background:#e2e8f0;font-size:8pt;padding:1mm"></th>
        <?php foreach ($days as $d): ?>
        <th colspan="<?php echo $periods; ?>" style="border:0.35mm solid #000;background:#e2e8f0;font-size:7.6pt;padding:1mm"><?php echo htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?></th>
        <?php endforeach; ?>
      </tr>
      <tr>
        <th style="border:0.35mm solid #000;background:#f1f5f9;font-size:7.2pt;padding:1mm">ردیف</th>
        <th style="border:0.35mm solid #000;background:#f1f5f9;font-size:7.6pt;padding:1mm">نام و نام خانوادگی</th>
        <?php for ($d = 0; $d < count($days); $d++): for ($p = 1; $p <= $periods; $p++): ?>
        <th style="border:0.35mm solid #000;background:#f1f5f9;font-size:6.4pt;padding:0.6mm"><?php echo htmlspecialchars(sf_fa($p), ENT_QUOTES, 'UTF-8'); ?></th>
        <?php endfor; endfor; ?>
      </tr>
    </thead>
    <tbody>
      <?php for ($r = 1; $r <= $rows; $r++):
        $s = $blank ? null : ($students[$r - 1] ?? null);
        $name = $s ? trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')) : '';
      ?>
      <tr style="height:<?php echo number_format($rowH, 2, '.', ''); ?>mm">
        <td style="border:0.25mm solid #64748b;text-align:center;font-size:7pt"><?php echo htmlspecialchars(sf_fa($r), ENT_QUOTES, 'UTF-8'); ?></td>
        <td style="border:0.25mm solid #64748b;font-size:8pt;padding:0 1.5mm"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></td>
        <?php for ($c = 0; $c < count($days) * $periods; $c++): ?><td style="border:0.25mm solid #94a3b8"></td><?php endfor; ?>
      </tr>
      <?php endfor; ?>
    </tbody>
  </table>
</div>
        <?php
        return sf_shell('برگهٔ حضور و غیاب کلاس ' . $className, ob_get_clean(), $autoPrint);
    }
}

/* ═══════════ ۲) فرم ثبت تحویل و دریافت کتاب ═══════════ */

if (!function_exists('sf_render_amanat_form')) {
    /**
     * فرم ثبت تحویل و دریافت کتاب — بازشناسی amanat.mrt (v4.177.0)
     * حالت پیش‌فرض: یک برگهٔ خالی (بدون نام دانش‌آموز) با ستون
     * «نام دریافت کننده». با $blank=false برای هر دانش‌آموز یک برگه.
     */
    function sf_render_amanat_form(array $students, $ctx, $autoPrint = false, $blank = true, $className = '') {
        $cols = ['ردیف', 'نام کتاب', 'کد کتاب و قفسه', 'نام دریافت کننده', 'تاریخ دریافت', 'تاریخ تحویل', 'مبلغ جریمه دیرکرد', 'ملاحظات'];
        $widths = ['9mm', 'auto', '24mm', '30mm', '20mm', '20mm', '22mm', 'auto'];
        $rows = 14;
        $sheets = '';
        foreach ($blank ? [null] : $students as $s) {
            $name = $s ? trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')) : '';
            ob_start();
            ?>
<div class="sheet">
  <div class="hdr">
    <div class="side"><?php echo htmlspecialchars($ctx['school'], ENT_QUOTES, 'UTF-8'); ?>
      <?php if ($ctx['year'] !== ''): ?><br>سال تحصیلی: <?php echo htmlspecialchars(sf_fa($ctx['year']), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
    </div>
    <div class="ttl">فرم ثبت تحویل و دریافت کتاب</div>
    <div class="side"><?php if ($className !== ''): ?>کلاس: <?php echo htmlspecialchars($className, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
      <?php if ($name !== ''): ?><br>دانش‌آموز: <?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
    </div>
  </div>
  <table style="width:100%;border-collapse:collapse;table-layout:fixed">
    <colgroup><?php foreach ($widths as $w): ?><col style="width:<?php echo $w === 'auto' ? 'auto' : htmlspecialchars($w, ENT_QUOTES, 'UTF-8'); ?>"><?php endforeach; ?></colgroup>
    <thead><tr><?php foreach ($cols as $c): ?><th style="border:0.3mm solid #000;background:#eef2f7;font-size:8pt;padding:1.6mm;text-align:center"><?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?></th><?php endforeach; ?></tr></thead>
    <tbody>
      <?php for ($r = 1; $r <= $rows; $r++): ?>
      <tr>
        <td style="border:0.25mm solid #64748b;text-align:center;font-size:8pt;height:15mm"><?php echo htmlspecialchars(sf_fa($r), ENT_QUOTES, 'UTF-8'); ?></td>
        <?php for ($c = 1; $c < count($cols); $c++): ?><td style="border:0.25mm solid #64748b"></td><?php endfor; ?>
      </tr>
      <?php endfor; ?>
    </tbody>
  </table>
  <div class="ftr"><span>مسئول کتابخانه: ........................</span><span>امضای دریافت‌کننده: ........................</span></div>
</div>
            <?php
            $sheets .= ob_get_clean();
        }
        return sf_shell('فرم ثبت تحویل و دریافت کتاب', $sheets, $autoPrint);
    }
}

/* ═══════════ ۳) دعوتنامهٔ انجمن — دو برگه در هر صفحه ═══════════ */

if (!function_exists('sf_render_anjoman_letter')) {
    /**
     * دعوتنامهٔ جلسهٔ عمومی انجمن اولیاء و مربیان — بازشناسی anjoman.mrt (v4.177.0)
     * دو دعوتنامه در هر برگه. چیدمان بر پایهٔ اصول نامهٔ نگاری فارسی:
     * تاریخ بالا-چپ، موضوع در middle، سلام و بدنهٔ راست‌چین و هم‌تراز،
     * امضا پایین-چپ. با $blank=true نام دانش‌آموز خالی می‌ماند.
     */
    function sf_render_anjoman_letter(array $students, $ctx, $autoPrint = false, $blank = false) {
        $program = [
            'گزارش عملکرد انجمن در سال تحصیلی گذشته.',
            'برنامهٔ کار انجمن برای سال تحصیلی جدید.',
            'معرفی داوطلبان عضویت در انجمن و رأی‌گیری.',
        ];
        $blocks = [];
        $items = $blank ? [null] : $students;
        if (!$items) $items = [null];
        foreach ($items as $s) {
            $name = $s ? trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')) : '';
            $cls  = $s ? trim((string)($s['class_name'] ?? '')) : '';
            ob_start();
            ?>
<div class="blk" style="height:124mm;border:0.35mm solid #334155;border-radius:2mm;padding:5mm 6mm;position:relative;overflow:hidden">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;font-size:8pt;color:#334155">
    <span>تاریخ: <?php echo htmlspecialchars(sf_fa($ctx['today']), ENT_QUOTES, 'UTF-8'); ?></span>
    <span>شمارهٔ نامه: ................</span>
  </div>
  <div style="text-align:center;margin:3mm 0 1mm">
    <div style="font-size:11.5pt;font-weight:700">دعوتنامهٔ جلسهٔ عمومی انجمن اولیاء و مربیان</div>
    <div style="font-size:8.5pt;margin-top:1mm"><?php echo htmlspecialchars($ctx['school'], ENT_QUOTES, 'UTF-8'); ?>
      <?php if ($ctx['year'] !== ''): ?> — سال تحصیلی <?php echo htmlspecialchars(sf_fa($ctx['year']), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></div>
  </div>
  <div style="font-size:9pt;margin:2mm 0">ولی محترم دانش‌آموز:
    <b><?php echo htmlspecialchars($name !== '' ? $name : '................................', ENT_QUOTES, 'UTF-8'); ?></b>
    <?php if ($cls !== ''): ?><span style="color:#334155">(کلاس <?php echo htmlspecialchars($cls, ENT_QUOTES, 'UTF-8'); ?>)</span><?php endif; ?>
  </div>
  <div style="font-size:8.6pt;line-height:2;text-align:justify;text-justify:inter-word">
    با سلام و احترام؛<br>
    امید است که امسال نیز، همچون سال‌های گذشته، شاهد همراهی گرم شما در مسیر آموزش
    فرزندانمان باشیم. جلسهٔ عمومی انجمن اولیاء و مربیان مدرسه به منظور بررسی
    برنامه‌های سال تحصیلی جدید و انتخاب اعضای انجمن تشکیل می‌شود. حضور و نظر
    شما، هرچند крат، در کیفیت آموزش مدرسه مؤثر خواهد بود.
  </div>
  <div style="font-size:8.6pt;line-height:2;margin-top:2mm">
    <div style="font-weight:700">فهرست برنامه‌ها:</div>
    <ol style="margin-right:8mm">
      <?php foreach ($program as $p): ?><li><?php echo htmlspecialchars($p, ENT_QUOTES, 'UTF-8'); ?></li><?php endforeach; ?>
    </ol>
  </div>
  <table style="width:100%;border-collapse:collapse;margin-top:2mm;font-size:8.2pt">
    <tr>
      <td style="width:50%;border:0.25mm solid #475569;padding:1.6mm;height:9mm">نام و نام خانوادگی:</td>
      <td style="border:0.25mm solid #475569;padding:1.6mm">شمارهٔ تماس:</td>
    </tr>
    <tr>
      <td style="border:0.25mm solid #475569;padding:1.6mm;height:9mm">رشته و میزان تحصیلات:</td>
      <td style="border:0.25mm solid #475569;padding:1.6mm">زمان و مکان جلسه: ..............................</td>
    </tr>
  </table>
  <div style="display:flex;justify-content:space-between;font-size:8pt;margin-top:2.5mm;color:#334155">
    <span>با احترام — مدیریت <?php echo htmlspecialchars($ctx['school'], ENT_QUOTES, 'UTF-8'); ?></span>
    <span>امضای ولی دانش‌آموز</span>
  </div>
</div>
            <?php
            $blocks[] = ob_get_clean();
        }
        /* دو دعوتنامه در هر برگه */
        $out = sf_head('دعوتنامهٔ انجمن اولیاء و مربیان');
        $out .= '<style>.sheet{padding:8mm 9mm}.blk+.blk{margin-top:10mm}</style>';  /* ۱۲۴+۱۲۴+۱۰ = ۲۵۸ میلی‌متر */
        foreach (array_chunk($blocks, 2) as $page) {
            $out .= '<div class="sheet">' . implode('', $page) . '</div>';
        }
        return $out . sf_tail($autoPrint);
    }
}

/* ═══════════ ۴) رضایت‌نامهٔ اردو — چهار برگه در هر صفحه ═══════════ */

if (!function_exists('sf_render_camp_consent')) {
    /**
     * رضایت‌نامهٔ اردو — بازشناسی campConsent.mrt (v4.177.0)
     * چهار برگه در هر صفحه. مهر مدیر مدرسه به‌صورت روی‌هم (overlay) روی
     * کادر توضیحات قرار می‌گیرد. با $blank=true نام خالی می‌ماند.
     */
    function sf_render_camp_consent(array $students, $ctx, $autoPrint = false, $blank = false) {
        $stamp = sf_data_uri($ctx['signature']);
        $blocks = [];
        $items = $blank ? [null] : $students;
        if (!$items) $items = [null];
        foreach ($items as $s) {
            $name = $s ? trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')) : '';
            $cls  = $s ? trim((string)($s['class_name'] ?? '')) : '';
            ob_start();
            ?>
<div class="blk" style="height:62mm;border:0.35mm solid #334155;border-radius:2mm;padding:3mm 4mm;position:relative;overflow:hidden">
  <div style="display:flex;justify-content:space-between;font-size:7.6pt;color:#334155">
    <span><?php echo htmlspecialchars($ctx['school'], ENT_QUOTES, 'UTF-8'); ?><?php if ($cls !== ''): ?> — کلاس <?php echo htmlspecialchars($cls, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></span>
    <span>تاریخ: <?php echo htmlspecialchars(sf_fa($ctx['today']), ENT_QUOTES, 'UTF-8'); ?></span>
  </div>
  <div style="text-align:center;font-size:10.5pt;font-weight:700;margin:1.5mm 0">رضایت‌نامهٔ اردو</div>
  <div style="font-size:8.2pt;line-height:1.9">
    اینجانب ولی دانش‌آموز <b><?php echo htmlspecialchars($name !== '' ? $name : '................................', ENT_QUOTES, 'UTF-8'); ?></b>
    رضایت خود را جهت عزیمت فرزندم به اردوی <b>......................</b> که در تاریخ
    <b>................</b> و راس ساعت <b>..........</b> از محل <b>......................</b>
    به مقصد <b>......................</b> و به مدت <b>..........</b> روز و به مبلغ
    <b>................</b> تومان برگزار می‌شود، اعلام می‌دارم.
  </div>
  <div style="position:relative;margin-top:1.5mm">
    <div style="border:0.25mm solid #475569;height:22mm;padding:1.5mm;font-size:7.4pt;color:#334155">توضیحات:</div>
    <?php if ($stamp !== ''): ?>
      <img src="<?php echo htmlspecialchars($stamp, ENT_QUOTES, 'UTF-8'); ?>" alt=""
           style="position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:30mm;opacity:.3;pointer-events:none">
    <?php endif; ?>
  </div>
  <div style="display:flex;justify-content:space-between;font-size:7.6pt;margin-top:1.5mm;color:#334155">
    <span>محل امضا و اثر انگشت ولی دانش‌آموز</span>
    <span>امضای مدیر / مهر مدرسه</span>
  </div>
</div>
            <?php
            $blocks[] = ob_get_clean();
        }
        /* چهار برگه در هر صفحه */
        $out = sf_head('رضایت‌نامهٔ اردو');
        $out .= '<style>.sheet{padding:7mm 8mm}.blk+.blk{margin-top:4mm}</style>';  /* ۶۲×۴ + ۳×۴ = ۲۶۰ میلی‌متر */
        foreach (array_chunk($blocks, 4) as $page) {
            $out .= '<div class="sheet">' . implode('', $page) . '</div>';
        }
        return $out . sf_tail($autoPrint);
    }
}
