<?php
// File: includes/school_forms.php  (v4.176.0)
/**
 * فرم‌های اداری مدرسه — بازسازی چهار طرح گزارش که در پوشهٔ
 * school-reports-raw-files به‌صورت قالب Stimulsoft (.mrt) رسیده بود:
 *
 *   1) absentsForm.mrt  → «برگه حضور و غیاب» — چهار ستون زنگ، هر ستون ۶ ردیف
 *      خالی برای نوشتن نام، با جای امضای دبیر. طرح افقی ۱۹×۶٫۸ سانتی‌متر.
 *   2) amanat.mrt       → «فرم ثبت تحویل و دریافت کتاب» — جدول ۷ ستونی
 *      (ردیف، نام کتاب، کد کتاب و قفسه، تاریخ دریافت، تاریخ تحویل، مبلغ جریمه
 *      دیرکرد، ملاحظات) با سرستون برای هر دانش‌آموز.
 *   3) anjoman.mrt      → «دعوتنامه جلسهٔ عمومی انجمن اولیاء و مربیان» —
 *      یک برگه برای هر دانش‌آموز، با فهرست برنامه و بخش عضویت.
 *   4) campConsent.mrt  → «رضایت‌نامه اردو» — یک برگه برای هر دانش‌آموز، با
 *      جای خالی برای نام اردو، تاریخ، ساعت، مبدأ، مقصد، مدت، مبلغ و توضیح.
 *
 * تفاوت با قالب‌های اصلی: داده‌ها از بانک اطلاعاتی خودِ مدرسه خوانده می‌شود
 * (دانش‌آموزان، کلاس، نام مدرسه، سال تحصیلی) و خروجی HTML چاپ‌محور است که با
 * «Save as PDF» مرورگر به PDF تبدیل می‌شود — دقیقاً مثل آلبوم عکس و لیست کلاسی.
 */

if (!function_exists('sf_students_of_class')) {
    /** دانش‌آموزان فعال یک کلاس، به ترتیب الفبای فارسی (همان لیست کلاسی). */
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
    /** تصویر امضای مدرسه به‌صورت data-URI (فایل محلی، سقف ۲ مگابایت). */
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

if (!function_exists('sf_shell')) {
    /**
     * پوستهٔ مشترک صفحهٔ چاپ: فونت تیتر، قالب A4، دکمهٔ چاپ و جاوااسکریپت
     * کوچک برای کوچک‌کردن برگه روی نمایشگر.
     */
    function sf_shell($title, $bodyHtml, $autoPrint = false) {
        $fontUrl = 'uploads/B-Titr/B-Titr.ttf';
        ob_start();
        ?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head><meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
<style>
@font-face{font-family:'BTitr';src:url('<?php echo $fontUrl; ?>') format('truetype');font-display:block}
*{box-sizing:border-box;margin:0;padding:0}
html,body{background:#eef2f7}
body{font-family:'BTitr',Tahoma,sans-serif;color:#0f172a}
.sheet{width:210mm;min-height:297mm;background:#fff;margin:0 auto 8mm auto;padding:8mm;
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
.noprint{max-width:210mm;margin:10px auto;padding:0 8px;font-family:Tahoma;
         display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.noprint button{padding:9px 20px;border:0;border-radius:8px;background:#2563eb;color:#fff;
                font-size:13px;cursor:pointer;font-family:inherit}
.noprint .hint{font-size:12px;color:#0f172a;background:#fff;padding:7px 11px;border-radius:8px}
.stamp{position:absolute;left:14mm;bottom:16mm;width:30mm;opacity:.85}
@media print{html,body{background:#fff}.sheet{box-shadow:none;margin:0 auto}.noprint{display:none!important}}
</style>
</head>
<body>
<div class="noprint">
  <button type="button" onclick="printForms()">چاپ / ذخیرهٔ PDF</button>
  <span class="hint">در پنجرهٔ چاپ، کاغذ A4 و مقصد «Save as PDF» را انتخاب کنید.</span>
</div>
<?php echo $bodyHtml; ?>
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

if (!function_exists('sf_render_absents_form')) {
    /**
     * برگه حضور و غیاب — بازشناسی absentsForm.mrt
     * چیدمان: سربرگ با نام مدرسه/کلاس/تاریخ، سپس چهار ستون «زنگ» که هر ستون
     * ۶ ردیف خالی برای نوشتن نام دارد، و پایین هر ستون جای امضای دبیر.
     */
    function sf_render_absents_form($className, $grade, array $students, $ctx, $autoPrint = false) {
        $periods = ['زنگ اول', 'زنگ دوم', 'زنگ سوم', 'زنگ چهارم'];
        $rows = 6;
        ob_start();
        ?>
<div class="sheet">
  <div class="hdr">
    <div class="side"><?php echo htmlspecialchars($ctx['school'], ENT_QUOTES, 'UTF-8'); ?>
      <?php if ($ctx['year'] !== ''): ?><br>سال تحصیلی <?php echo htmlspecialchars(sf_fa($ctx['year']), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
      <br>تعداد دانش‌آموز: <?php echo htmlspecialchars(sf_fa(count($students)), ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <div class="ttl">برگه حضور و غیاب</div>
    <div class="side">کلاس <?php echo htmlspecialchars($className, ENT_QUOTES, 'UTF-8'); ?>
      <?php if ($grade !== ''): ?> — پایهٔ <?php echo htmlspecialchars($grade, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
      <br>تاریخ: <?php echo htmlspecialchars(sf_fa($ctx['today']), ENT_QUOTES, 'UTF-8'); ?>
    </div>
  </div>
  <table style="width:100%;border-collapse:collapse;table-layout:fixed">
    <tr>
      <?php foreach ($periods as $pi => $p): ?>
      <td style="border:0.4mm solid #000;padding:2mm;vertical-align:top;height:190mm">
        <div style="text-align:center;font-size:10pt;font-weight:700;border-bottom:0.3mm solid #000;
                    padding-bottom:1.5mm;margin-bottom:2mm"><?php echo htmlspecialchars($p, ENT_QUOTES, 'UTF-8'); ?></div>
        <table style="width:100%;border-collapse:collapse">
          <?php for ($r = 1; $r <= $rows; $r++): ?>
          <tr>
            <td style="width:7mm;font-size:8pt;text-align:center;border:0.25mm solid #94a3b8;
                       padding:1mm"><?php echo htmlspecialchars(sf_fa($r), ENT_QUOTES, 'UTF-8'); ?>-</td>
            <td style="border:0.25mm solid #94a3b8;height:22mm;padding:1mm"></td>
          </tr>
          <?php endfor; ?>
        </table>
        <div style="margin-top:6mm;font-size:8.5pt;text-align:center">امضای دبیر: ........................</div>
      </td>
      <?php if ($pi < count($periods) - 1): ?><td style="width:2mm"></td><?php endif; ?>
      <?php endforeach; ?>
    </tr>
  </table>
  <div class="ftr">
    <span>هر ستون یک زنگ است؛ نام دانش‌آموز غایب یا تأخیردار را در ردیف خالی همان ستون بنویسید.</span>
    <span>برگهٔ <?php echo htmlspecialchars($className, ENT_QUOTES, 'UTF-8'); ?> — <?php echo htmlspecialchars(sf_fa($ctx['today']), ENT_QUOTES, 'UTF-8'); ?></span>
  </div>
</div>
        <?php
        return sf_shell('برگه حضور و غیاب کلاس ' . $className, ob_get_clean(), $autoPrint);
    }
}

if (!function_exists('sf_render_amanat_form')) {
    /**
     * فرم ثبت تحویل و دریافت کتاب — بازشناسی amanat.mrt
     * جدول ۷ ستونی با ۱۲ ردیف خالی؛ برای هر دانش‌آموز یک برگه.
     */
    function sf_render_amanat_form(array $students, $ctx, $autoPrint = false) {
        $cols = ['ردیف', 'نام کتاب', 'کد کتاب و قفسه', 'تاریخ دریافت', 'تاریخ تحویل', 'مبلغ جریمه دیرکرد', 'ملاحظات'];
        $widths = ['10mm', 'auto', '26mm', '24mm', '24mm', '26mm', '30mm'];
        $rows = 12;
        $sheets = '';
        foreach ($students as $s) {
            $name = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
            ob_start();
            ?>
<div class="sheet">
  <div class="hdr">
    <div class="side"><?php echo htmlspecialchars($ctx['school'], ENT_QUOTES, 'UTF-8'); ?>
      <?php if ($ctx['year'] !== ''): ?><br>سال تحصیلی: <?php echo htmlspecialchars(sf_fa($ctx['year']), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
    </div>
    <div class="ttl">فرم ثبت تحویل و دریافت کتاب</div>
    <div class="side">دانش‌آموز: <?php echo htmlspecialchars($name !== '' ? $name : '—', ENT_QUOTES, 'UTF-8'); ?>
      <br>کلاس: <?php echo htmlspecialchars((string)($s['class_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
    </div>
  </div>
  <table style="width:100%;border-collapse:collapse;table-layout:fixed">
    <colgroup><?php foreach ($widths as $w): ?><col style="width:<?php echo $w === 'auto' ? 'auto' : htmlspecialchars($w, ENT_QUOTES, 'UTF-8'); ?>"><?php endforeach; ?></colgroup>
    <thead><tr><?php foreach ($cols as $c): ?><th style="border:0.3mm solid #000;background:#eef2f7;font-size:8.5pt;padding:1.6mm;text-align:center"><?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?></th><?php endforeach; ?></tr></thead>
    <tbody>
      <?php for ($r = 1; $r <= $rows; $r++): ?>
      <tr>
        <td style="border:0.25mm solid #64748b;text-align:center;font-size:8pt;height:16mm"><?php echo htmlspecialchars(sf_fa($r), ENT_QUOTES, 'UTF-8'); ?></td>
        <?php for ($c = 1; $c < count($cols); $c++): ?><td style="border:0.25mm solid #64748b"></td><?php endfor; ?>
      </tr>
      <?php endfor; ?>
    </tbody>
  </table>
  <div class="note" style="margin-top:4mm">
    توضیح: این برگه هنگام دریافت کتاب از کتابخانه‌آموز پر می‌شود؛ ستون «تاریخ تحویل» پس از بازگرداندن کتاب کامل گردد.
    مبلغ جریمه دیرکرد طبق آیین‌نامهٔ کتابخانه محاسبه و درج شود.
  </div>
  <div class="ftr"><span>مسئول کتابخانه: ........................</span><span>امضای دانش‌آموز: ........................</span></div>
</div>
            <?php
            $sheets .= ob_get_clean();
        }
        return sf_shell('فرم ثبت تحویل و دریافت کتاب', $sheets, $autoPrint);
    }
}

if (!function_exists('sf_render_anjoman_letter')) {
    /**
     * دعوتنامه جلسهٔ عمومی انجمن اولیاء و مربیان — بازشناسی anjoman.mrt
     * برای هر دانش‌آموز یک برگه، با متن دعوت، فهرست برنامه و بخش عضویت.
     */
    function sf_render_anjoman_letter(array $students, $ctx, $autoPrint = false) {
        $program = [
            'ارائه گزارش از عملکرد انجمن در سال گذشته.',
            'اعلام برنامه‌های در دست اقدام برای سال تحصیلی جدید.',
            'معرفی داوطلبان عضویت در انجمن و رأی‌گیری.',
        ];
        $sheets = '';
        foreach ($students as $s) {
            $name = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
            ob_start();
            ?>
<div class="sheet">
  <div class="hdr">
    <div class="side"><?php echo htmlspecialchars($ctx['school'], ENT_QUOTES, 'UTF-8'); ?>
      <?php if ($ctx['region'] !== ''): ?><br><?php echo htmlspecialchars($ctx['region'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
    </div>
    <div class="ttl">دعوتنامه جلسهٔ عمومی انجمن اولیاء و مربیان</div>
    <div class="side">کلاس: <?php echo htmlspecialchars((string)($s['class_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
      <br>تاریخ: <?php echo htmlspecialchars(sf_fa($ctx['today']), ENT_QUOTES, 'UTF-8'); ?>
    </div>
  </div>

  <div style="font-size:10pt;line-height:2.4;margin-bottom:3mm">
    ولی محترم دانش‌آموز: <b><?php echo htmlspecialchars($name !== '' ? $name : '—', ENT_QUOTES, 'UTF-8'); ?></b>
    <span style="float:left">سلام علیکم:</span>
  </div>

  <div style="font-size:9.5pt;line-height:2.3;text-align:justify">
    بسیار خرسندیم که در ماه مهر، ماه آغاز علم‌آموزی و معرفت، حضور پرشور و ثمربخش شما را در کنار خود احساس کنیم
    و رهنمودهای شما را در تحقق آرمان‌های تعلیم و تربیت فرزندانمان پذیرا باشیم.
    شما هم می‌توانید در این تعامل و همبستگی بین خانه و مدرسه، خدمتگزاران خودتان را یاری رسانید تا آن‌ها
    با تلاش و برنامه‌ریزی، آینده‌سازانی عالم و عامل پرورش دهند.
    خواهشمند است با عنایتی که نسبت به سعادت و خوشبختی فرزندتان دارید، ضمن حضور به‌موقع و مشارکت فعال
    در این جلسه، ما را از نظرات و پیشنهادهای سازنده و مفید خود بهره‌مند سازید.
  </div>

  <div style="font-size:10pt;font-weight:700;margin:4mm 0 2mm">فهرست برنامه‌ها:</div>
  <ol style="font-size:9.5pt;line-height:2.2;margin-right:8mm">
    <?php foreach ($program as $p): ?><li><?php echo htmlspecialchars($p, ENT_QUOTES, 'UTF-8'); ?></li><?php endforeach; ?>
  </ol>

  <div style="font-size:10pt;font-weight:700;margin:4mm 0 2mm">زمان و مکان تشکیل جلسه:</div>
  <div style="font-size:9.5pt;line-height:2.2">
    روز ........................ تاریخ ........................ ساعت ........................
    <br>مکان: ............................................................................................
  </div>

  <div style="font-size:10pt;font-weight:700;margin:4mm 0 2mm">لطفاً در صورت تمایل به عضویت در انجمن مدرسه، اطلاعات زیر را تکمیل نمایید:</div>
  <table style="width:100%;border-collapse:collapse;margin-top:1mm">
    <tr>
      <td style="width:50%;border:0.3mm solid #334155;padding:2mm;font-size:9pt;height:14mm">نام و نام خانوادگی:</td>
      <td style="border:0.3mm solid #334155;padding:2mm;font-size:9pt">شماره تماس:</td>
    </tr>
    <tr>
      <td style="border:0.3mm solid #334155;padding:2mm;font-size:9pt;height:14mm">رشته و میزان تحصیلات / تخصص و مهارت:</td>
      <td style="border:0.3mm solid #334155;padding:2mm;font-size:9pt">امضای ولی:</td>
    </tr>
  </table>

  <div class="ftr">
    <span>مدیر <?php echo htmlspecialchars($ctx['school'], ENT_QUOTES, 'UTF-8'); ?></span>
    <span><?php echo htmlspecialchars($name !== '' ? $name : '—', ENT_QUOTES, 'UTF-8'); ?></span>
  </div>
</div>
            <?php
            $sheets .= ob_get_clean();
        }
        return sf_shell('دعوتنامه انجمن اولیاء و مربیان', $sheets, $autoPrint);
    }
}

if (!function_exists('sf_render_camp_consent')) {
    /**
     * رضایت‌نامه اردو — بازشناسی campConsent.mrt
     * برای هر دانش‌آموز یک برگه با جای خالی برای مشخصات اردو.
     */
    function sf_render_camp_consent(array $students, $ctx, $autoPrint = false) {
        $sheets = '';
        foreach ($students as $s) {
            $name = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
            ob_start();
            ?>
<div class="sheet">
  <div class="hdr">
    <div class="side"><?php echo htmlspecialchars($ctx['school'], ENT_QUOTES, 'UTF-8'); ?>
      <?php if ($ctx['region'] !== ''): ?><br><?php echo htmlspecialchars($ctx['region'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
    </div>
    <div class="ttl">رضایت‌نامه اردو</div>
    <div class="side">کلاس: <?php echo htmlspecialchars((string)($s['class_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
      <br>تاریخ: <?php echo htmlspecialchars(sf_fa($ctx['today']), ENT_QUOTES, 'UTF-8'); ?>
    </div>
  </div>

  <div style="font-size:10pt;line-height:2.4">
    اینجانب ولی دانش‌آموز: <b><?php echo htmlspecialchars($name !== '' ? $name : '—', ENT_QUOTES, 'UTF-8'); ?></b>
  </div>

  <div style="font-size:9.5pt;line-height:2.4;margin-top:2mm;text-align:justify">
    بدین‌وسیله رضایت خود را جهت شرکت و عزیمت فرزندم به اردوی
    <b>......................................</b> که در تاریخ
    <b>....................</b> و راس ساعت <b>............</b> از محل
    <b>................................</b> به مقصد <b>................................</b>
    و به مدت <b>............</b> روز و به مبلغ <b>....................</b> تومان انجام خواهد شد، اعلام می‌دارم.
  </div>

  <div style="font-size:10pt;font-weight:700;margin:4mm 0 2mm">نکات مهم:</div>
  <ul style="font-size:9pt;line-height:2.2;margin-right:8mm;list-style:disc">
    <li>مسئولیت حفظ سلامت و اموال فرزندم در طول اردو بر عهدهٔ خودم می‌باشد.</li>
    <li>از فرزندم خواسته‌ام نظم و برنامهٔ کاروان را رعایت کند.</li>
    <li>در صورت بروز هر حادثه، مراتب به اطلاع اینجانب خواهد رسید.</li>
  </ul>

  <div style="font-size:10pt;font-weight:700;margin:4mm 0 2mm">توضیحات:</div>
  <div style="border:0.3mm solid #334155;height:34mm;padding:2mm;font-size:9pt"></div>

  <div class="ftr">
    <span>محل امضا و اثر انگشت ولی دانش‌آموز</span>
    <span>امضای مدیر / مهر مدرسه</span>
  </div>
  <?php if ($ctx['signature'] !== ''): ?>
    <img class="stamp" src="<?php echo htmlspecialchars(sf_data_uri($ctx['signature']), ENT_QUOTES, 'UTF-8'); ?>" alt="">
  <?php endif; ?>
</div>
            <?php
            $sheets .= ob_get_clean();
        }
        return sf_shell('رضایت‌نامه اردو', $sheets, $autoPrint);
    }
}
