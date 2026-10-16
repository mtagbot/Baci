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

/* ═══════════ v4.181.0: گزینه‌های فرم — مقادیر ورودی کاربر + کلید فعال/غیرفعال ═══════════
   مشکل گزارش‌شده: متن دعوتنامه و رضایت‌نامه ثابت بود (اردو، تاریخ، محل، مبلغ، فهرست
   برنامه‌ها…) و مدرسه نمی‌توانست آن‌ها را پر کند؛ بخشی از متن هم خراب بود. حالا هر
   فیلد «مقدار» دارد و «کلید نمایش»؛ مقادیر آخرین ویرایش در settings می‌ماند تا برای
   جلسهٔ بعد فقط تیک‌ها را عوض کنند. */

if (!function_exists('sf_show')) {
    /** آیا فیلد در این چاپ نمایش داده شود؟ (پیش‌فرض: بله) */
    function sf_show(array $opts, $key) {
        $show = $opts['show'] ?? [];
        if (!is_array($show) || !array_key_exists($key, $show)) return true;
        return (bool)$show[$key];
    }
}
if (!function_exists('sf_val')) {
    /** مقدار یک فیلد برای چاپ: اگر «ندارد» باشد خط‌چین نقطه‌چین می‌ماند.
     *  فیلد خاموش (کلید نمایش برداشته‌شده) اصلاً چاپ نمی‌شود؛ چون اگر فقط با
     *  CSS پنهان شود، متن در HTML/PDF می‌ماند و جای برگه را هم می‌خورد. */
    function sf_val(array $opts, $key, $fallback = '......................') {
        if (!sf_show($opts, $key)) return '';
        $value = trim((string)($opts[$key] ?? ''));
        return $value === '' ? $fallback : $value;
    }
}
if (!function_exists('sf_today_with_time')) {
    function sf_today_with_time() {
        if (function_exists('jdate')) return jdate('Y/m/d H:i');
        if (function_exists('jalali_now')) return jalali_now();
        return date('Y/m/d H:i');
    }
}
if (!function_exists('sf_form_defaults')) {
    /**
     * پیش‌فرض فیلدهای هر فرم. اگر مدیر قبلاً «ذخیرهٔ پیش‌فرض» زده باشد، همان
     * مقادیر برمی‌گردد؛ وگرنه متن اصلی و تیک‌های پیش‌فرض.
     */
    function sf_form_defaults($form) {
        $saved = json_decode((string)get_setting('form_options_' . $form, ''), true);
        if (!is_array($saved)) $saved = [];
        if ($form === 'consent') {
            $base = [
                'year_label' => (string)get_setting('current_academic_year', ''),
                'trip' => '', 'date' => '', 'time' => '', 'from' => '', 'to' => '',
                'days' => '', 'amount' => '', 'notes' => '',
                'show' => ['year_label' => true, 'trip' => true, 'date' => true, 'time' => true,
                           'from' => true, 'to' => true, 'days' => true, 'amount' => true, 'notes' => true],
            ];
        } else {
            $base = [
                'intro' => "با سلام و احترام؛\nامید است که امسال نیز، همچون سال‌های گذشته، شاهد همراهی گرم شما در مسیر آموزش"
                    . " فرزندانمان باشیم. جلسهٔ عمومی انجمن اولیاء و مربیان مدرسه به منظور بررسی برنامه‌های سال تحصیلی"
                    . " جدید و انتخاب اعضای انجمن تشکیل می‌شود. حضور و نظر شما، هرچند کوتاه، در کیفیت آموزش مدرسه"
                    . " مؤثر خواهد بود.",
                'agenda' => "گزارش عملکرد انجمن در سال تحصیلی گذشته.\nبرنامهٔ کار انجمن برای سال تحصیلی جدید.\nمعرفی داوطلبان عضویت در انجمن و رأی‌گیری.",
                'place' => '', 'number' => '', 'date' => sf_today_with_time(),
                'show' => ['intro' => true, 'agenda' => true, 'place' => true, 'number' => true, 'date' => true,
                           'member_name' => true, 'member_phone' => true, 'member_education' => true, 'parent_sign' => true],
            ];
        }
        foreach ($saved as $k => $v) {
            if ($k === 'show') { if (is_array($v)) $base['show'] = array_merge($base['show'], $v); continue; }
            if (is_string($v) || is_numeric($v)) $base[$k] = (string)$v;
        }
        return $base;
    }
}
if (!function_exists('sf_agenda_lines')) {
    /** فهرست برنامه‌ها: هر خط یک بند. */
    function sf_agenda_lines($raw) {
        $lines = preg_split('/\r\n|\r|\n/', (string)$raw);
        $out = [];
        foreach ($lines as $line) {
            $line = trim(preg_replace('/^\s*[-•*\d]+[\).\-\s]*/u', '', (string)$line));
            if ($line !== '') $out[] = $line;
        }
        return $out;
    }
}
if (!function_exists('sf_form_options_from_post')) {
    /** مقادیر و تیک‌های ارسالی ویرایشگر را به ساختار opts تبدیل می‌کند. */
    function sf_form_options_from_post($form, array $src) {
        $base = sf_form_defaults($form);
        $fields = $form === 'consent'
            ? ['year_label', 'trip', 'date', 'time', 'from', 'to', 'days', 'amount', 'notes']
            : ['intro', 'agenda', 'place', 'number', 'date'];
        $out = [];
        foreach ($fields as $f) {
            if (array_key_exists('f_' . $f, $src)) $out[$f] = (string)$src['f_' . $f];
            elseif (array_key_exists($f, $base)) $out[$f] = $base[$f];
        }
        $show = [];
        foreach (array_keys($base['show']) as $key) $show[$key] = !empty($src['show_' . $key]);
        $out['show'] = $show;
        return $out;
    }
}
if (!function_exists('sf_form_options_save')) {
    function sf_form_options_save($form, array $opts) {
        set_setting('form_options_' . $form, json_encode($opts, JSON_UNESCAPED_UNICODE));
    }
}

/* ═══════════ ۱) برگهٔ حضور و غیاب هفتگی ═══════════ */

if (!function_exists('sf_render_absents_form')) {
    /**
     * برگهٔ «لیست ثبت حضور و غیاب کلاس» — v4.181.0، بازسازی‌شده طبق تصویر ارسالی
     * کارفرما (جای چیدمان هفتگیِ ماه-محور قبلی):
     *
     *   یک برگهٔ A4 عمودی؛ شش کادر روز در سه ردیف و دو ستون
     *   (شنبه، یکشنبه / دوشنبه، سه‌شنبه / چهارشنبه، پنجشنبه).
     *   هر کادر: سرصفحهٔ «روز …» و «تاریخ: …/…/…»، سپس جدولی سه‌ستونی
     *   «زنگ | اسامی غایبین | امضای دبیر» با سه ردیف زنگ اول/دوم/سوم —
     *   یعنی هر روز سه ظرفیت ثبت غایب و سه امضای دبیر.
     *
     * $students/$blank برای سازگاری امضا نگه داشته شده‌اند؛ این برگه جای نام
     * دانش‌آموز، خودِ «غایبین» را ثبت می‌کند، پس در هر دو حالت یکی است.
     */
    function sf_render_absents_form($className, $grade, array $students, $ctx, $autoPrint = false, $blank = false, array $opts = []) {
        $pairs = [['شنبه', 'یکشنبه'], ['دوشنبه', 'سه‌شنبه'], ['چهارشنبه', 'پنجشنبه']];
        $periods = ['اول', 'دوم', 'سوم'];
        $title = trim((string)($opts['title'] ?? '')) !== '' ? (string)$opts['title'] : 'لیست ثبت حضور و غیاب کلاس';
        $classLabel = trim((string)$className) !== '' ? trim((string)$className) : '..............................';
        $esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
        $th = 'border:0.4mm solid #000;font-size:10pt;font-weight:700;padding:1.2mm;background:#f1f5f9';
        $td = 'border:0.4mm solid #000;font-size:10pt;font-weight:700;padding:1mm;text-align:center';
        $empty = 'border:0.4mm solid #000;font-size:10pt;padding:1mm';
        ob_start();
        ?>
<div class="sheet">
  <div style="text-align:center;font-size:13pt;font-weight:700;margin-bottom:3mm">
    <?php echo $esc($title); ?> <?php echo $esc(sf_fa($classLabel)); ?>
  </div>
  <table style="width:100%;border-collapse:separate;border-spacing:2.5mm;table-layout:fixed">
    <colgroup><col style="width:50%"><col style="width:50%"></colgroup>
    <?php foreach ($pairs as $pair): ?>
    <tr>
      <?php foreach ($pair as $day): ?>
      <td style="vertical-align:top;border:0.5mm solid #000;border-radius:1.5mm;height:82mm;padding:1.5mm 2mm">
        <table style="width:100%;border-collapse:collapse;table-layout:fixed;margin-bottom:1.5mm">
          <tr>
            <td style="font-size:11.5pt;font-weight:700;text-align:right;padding:0">روز <?php echo $esc($day); ?></td>
            <td style="font-size:10.5pt;font-weight:700;text-align:left;padding:0;white-space:nowrap">تاریخ:&nbsp;&nbsp;<?php echo $esc(sf_fa('/')); ?>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<?php echo $esc(sf_fa('/')); ?></td>
          </tr>
        </table>
        <table style="width:100%;border-collapse:collapse;table-layout:fixed">
          <colgroup><col style="width:15mm"><col><col style="width:31mm"></colgroup>
          <thead>
            <tr>
              <th style="<?php echo $th; ?>">زنگ</th>
              <th style="<?php echo $th; ?>">اسامی غایبین</th>
              <th style="<?php echo $th; ?>">امضای دبیر</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($periods as $period): ?>
            <tr style="height:19mm">
              <td style="<?php echo $td; ?>"><?php echo $esc($period); ?></td>
              <td style="<?php echo $empty; ?>"></td>
              <td style="<?php echo $empty; ?>"></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </td>
      <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
        <?php
        $out = sf_head('لیست ثبت حضور و غیاب کلاس ' . $classLabel);
        $out .= '<style>.sheet{padding:7mm 6mm}</style>';
        return $out . ob_get_clean() . sf_tail($autoPrint);
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

if (!function_exists('sf_anjoman_blocks')) {
    /**
     * بلوک‌های یک دعوتنامه برای هر دانش‌آموز (خروجی: آرایهٔ HTML).
     * جدا شده از پوسته تا ویرایشگر زنده بتواند همان بلوک‌ها را پیش‌نمایش کند.
     */
    function sf_anjoman_blocks(array $students, $ctx, $blank, array $opts) {
        $esc  = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
        $vis = function ($key) use ($opts) { return sf_show($opts, $key) ? '' : 'display:none;'; };
        $opt = function ($key, $style = '') use ($vis) { return ' class="sfe-opt" data-sfe-opt="' . $key . '" style="' . $vis($key) . $style . '"'; };
        $node = function ($key, $text, $style = '') use ($esc, $vis) {
            if ($vis($key) !== '') $text = '';   /* فیلد خاموش حتی متنش هم چاپ نمی‌شود */
            return '<span data-sfe="' . $esc($key) . '" style="' . $style . '">' . $esc($text) . '</span>';
        };
        $agenda = sf_show($opts, 'agenda') ? sf_agenda_lines((string)($opts['agenda'] ?? '')) : [];
        $introLines = preg_split('/\r\n|\r|\n/', (string)($opts['intro'] ?? ''));
        $blocks = [];
        $items = $blank ? [null] : $students;
        if (!$items) $items = [null];
        foreach ($items as $st) {
            $name = $st ? trim((string)($st['first_name'] ?? '') . ' ' . (string)($st['last_name'] ?? '')) : '';
            $cls  = $st ? trim((string)($st['class_name'] ?? '')) : '';
            ob_start();
            ?>
<div class="blk" style="height:124mm;border:0.35mm solid #334155;border-radius:2mm;padding:5mm 6mm;position:relative;overflow:hidden">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;font-size:8pt;color:#334155">
    <span<?php echo $opt('date'); ?>><?php if (sf_show($opts, 'date')): ?>تاریخ: <?php echo $node('date', (string)($opts['date'] ?? '')); ?><?php endif; ?></span>
    <span<?php echo $opt('number'); ?>><?php if (sf_show($opts, 'number')): ?>شمارهٔ نامه: <?php echo $node('number', sf_val($opts, 'number', '................')); ?><?php endif; ?></span>
  </div>
  <div style="text-align:center;margin:3mm 0 1mm">
    <div style="font-size:11.5pt;font-weight:700">دعوتنامهٔ جلسهٔ عمومی انجمن اولیاء و مربیان</div>
    <div style="font-size:8.5pt;margin-top:1mm"><?php echo $esc($ctx['school']); ?>
      <?php if ($ctx['year'] !== ''): ?> — سال تحصیلی <?php echo $esc(sf_fa($ctx['year'])); ?><?php endif; ?></div>
  </div>
  <div style="font-size:9pt;margin:2mm 0">ولی محترم دانش‌آموز:
    <b><?php echo $esc($name !== '' ? $name : '................................'); ?></b>
    <?php if ($cls !== ''): ?><span style="color:#334155">(کلاس <?php echo $esc($cls); ?>)</span><?php endif; ?>
  </div>
  <div<?php echo $opt('intro', 'font-size:8.6pt;line-height:2;text-align:justify;text-justify:inter-word'); ?>>
    <?php echo $node('intro', implode("\n", $introLines), 'white-space:pre-line'); ?>
  </div>
  <div<?php echo $opt('agenda', 'font-size:8.6pt;line-height:2;margin-top:2mm'); ?>>
    <div style="font-weight:700">فهرست برنامه‌ها:</div>
    <ol class="sfe-agenda" style="margin-right:8mm">
      <?php foreach ($agenda as $item): ?><li><?php echo $esc($item); ?></li><?php endforeach; ?>
    </ol>
  </div>
  <table class="sfe-member" style="width:100%;border-collapse:collapse;margin-top:2mm;font-size:8.2pt">
    <tr>
      <td<?php echo $opt('member_name', 'width:50%;border:0.25mm solid #475569;padding:1.6mm;height:9mm'); ?>><?php if (sf_show($opts, 'member_name')): ?>نام و نام خانوادگی:<?php endif; ?></td>
      <td<?php echo $opt('member_phone', 'border:0.25mm solid #475569;padding:1.6mm'); ?>><?php if (sf_show($opts, 'member_phone')): ?>شمارهٔ تماس:<?php endif; ?></td>
    </tr>
    <tr>
      <td<?php echo $opt('member_education', 'border:0.25mm solid #475569;padding:1.6mm;height:9mm'); ?>><?php if (sf_show($opts, 'member_education')): ?>رشته و میزان تحصیلات:<?php endif; ?></td>
      <td<?php echo $opt('place', 'border:0.25mm solid #475569;padding:1.6mm'); ?>><?php if (sf_show($opts, 'place')): ?>زمان و مکان جلسه: <?php echo $node('place', sf_val($opts, 'place', '..............................')); ?><?php endif; ?></td>
    </tr>
  </table>
  <div style="display:flex;justify-content:space-between;font-size:8pt;margin-top:2.5mm;color:#334155">
    <span>با احترام — مدیریت <?php echo $esc($ctx['school']); ?></span>
    <span<?php echo $opt('parent_sign'); ?>><?php if (sf_show($opts, 'parent_sign')): ?>امضای ولی دانش‌آموز<?php endif; ?></span>
  </div>
</div>
            <?php
            $blocks[] = ob_get_clean();
        }
        return $blocks;
    }
}

if (!function_exists('sf_render_anjoman_letter')) {
    /**
     * دعوتنامهٔ جلسهٔ عمومی انجمن اولیاء و مربیان — دو دعوتنامه در هر برگه A4.
     *
     * v4.181.0: متن، فهرست برنامه‌ها، زمان و مکان جلسه، تاریخ و شمارهٔ نامه از
     * «گزینه‌های فرم» می‌آید (ویرایشگر زنده در همین صفحه ذخیره‌شان می‌کند) و هر
     * فیلد یک کلید «نمایش» دارد؛ در جلسه‌ای که لازم نیست، تیکش برداشته می‌شود.
     * متن خراب «هرچند крат» هم به «هرچند کوتاه» اصلاح شد.
     */
    function sf_render_anjoman_letter(array $students, $ctx, $autoPrint = false, $blank = false, array $opts = []) {
        if (!$opts) $opts = sf_form_defaults('anjoman');
        $blocks = sf_anjoman_blocks($students, $ctx, $blank, $opts);
        /* دو دعوتنامه در هر برگه */
        $out = sf_head('دعوتنامهٔ انجمن اولیاء و مربیان');
        $out .= '<style>.sheet{padding:8mm 9mm}.blk+.blk{margin-top:10mm}</style>';
        foreach (array_chunk($blocks, 2) as $page) {
            $out .= '<div class="sheet">' . implode('', $page) . '</div>';
        }
        return $out . sf_tail($autoPrint);
    }
}

/* ═══════════ ۴) رضایت‌نامهٔ اردو — چهار برگه در هر صفحه ═══════════ */

if (!function_exists('sf_camp_blocks')) {
    /**
     * بلوک‌های یک رضایت‌نامهٔ اردو برای هر دانش‌آموز (خروجی: آرایهٔ HTML).
     * v4.181.0: مقادیر اردو (عنوان، تاریخ، ساعت، مبدأ، مقصد، مدت، مبلغ و
     * توضیحات) از گزینه‌های فرم می‌آید و هر کدام کلید نمایش جدا دارد؛ جمله
     * فقط از بخش‌های فعال ساخته می‌شود تا با خاموش‌کردن یک فیلد، متن ناقص نشود.
     * جای «تاریخ» در سرصفحه، «سال تحصیلی» نشسته است و امضای مدیر (overlay)
     * دقیقاً روی «امضای مدیر / مهر مدرسه» می‌افتد، نه وسط کادر توضیحات.
     */
    function sf_camp_blocks(array $students, $ctx, $blank, array $opts) {
        $esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
        $stamp = sf_data_uri($ctx['signature']);
        $val   = function ($key, $style = '') use ($opts, $esc) {
            return '<b data-sfe="' . $esc($key) . '" style="' . $style . '">' . $esc(sf_val($opts, $key, '......................')) . '</b>';
        };
        $optC  = function ($key, $style = '') use ($opts) {
            return ' class="sfe-opt" data-sfe-opt="' . $key . '" style="' . (sf_show($opts, $key) ? '' : 'display:none;') . $style . '"';
        };
        /* جملهٔ اصلی فقط از بخش‌های فعال ساخته می‌شود؛ با خاموش‌کردن هر فیلد
           متن به‌هم‌ریخته نمی‌شود (نه «که در تاریخ» بی‌دنباله می‌ماند، نه «به»). */
        $clauses = ['که در تاریخ ' . $val('date'), 'راس ساعت ' . $val('time'), 'از محل ' . $val('from'),
                    'به مقصد ' . $val('to'), 'به مدت ' . $val('days') . ' روز', 'به مبلغ ' . $val('amount') . ' تومان'];
        $keys = ['date', 'time', 'from', 'to', 'days', 'amount'];
        $live = [];
        foreach ($keys as $i => $k) if (sf_show($opts, $k)) $live[] = $clauses[$i];
        $sentence = 'رضایت خود را جهت عزیمت فرزندم به ' . (sf_show($opts, 'trip') ? 'اردوی ' . $val('trip') : 'اردو');
        if ($live) {
            $lastOne = array_pop($live);
            $sentence .= ' ' . implode('، ', $live) . ($live ? ' و ' : '') . $lastOne;
        }
        $sentence .= ' برگزار می‌شود، اعلام می‌دارم.';
        $blocks = [];
        $items = $blank ? [null] : $students;
        if (!$items) $items = [null];
        foreach ($items as $st) {
            $name = $st ? trim((string)($st['first_name'] ?? '') . ' ' . (string)($st['last_name'] ?? '')) : '';
            $cls  = $st ? trim((string)($st['class_name'] ?? '')) : '';
            ob_start();
            ?>
<div class="blk" style="height:62mm;border:0.35mm solid #334155;border-radius:2mm;padding:3mm 4mm;position:relative;overflow:hidden">
  <div style="display:flex;justify-content:space-between;font-size:7.6pt;color:#334155">
    <span><?php echo $esc($ctx['school']); ?><?php if ($cls !== ''): ?> — کلاس <?php echo $esc($cls); ?><?php endif; ?></span>
    <span<?php echo $optC('year_label'); ?>><?php if (sf_show($opts, 'year_label')): ?>سال تحصیلی: <?php echo $val('year_label', 'font-weight:700'); ?><?php endif; ?></span>
  </div>
  <div style="text-align:center;font-size:10.5pt;font-weight:700;margin:1.5mm 0">رضایت‌نامهٔ اردو</div>
  <div style="font-size:8.2pt;line-height:1.9">
    اینجانب ولی دانش‌آموز <b><?php echo $esc($name !== '' ? $name : '................................'); ?></b>
    <?php echo $sentence; ?>
  </div>
  <div<?php echo $optC('notes', 'margin-top:1.5mm'); ?>>
    <div style="border:0.25mm solid #475569;height:20mm;padding:1.5mm;font-size:7.4pt;color:#334155;overflow:hidden">
      <?php if (sf_show($opts, 'notes')): ?><span style="font-weight:700">توضیحات:</span><?php endif; ?>
      <span data-sfe="notes" style="white-space:pre-line"><?php if (sf_show($opts, 'notes')) echo $esc(trim((string)($opts['notes'] ?? ''))); ?></span>
    </div>
  </div>
  <div style="display:flex;justify-content:space-between;align-items:flex-end;font-size:7.6pt;margin-top:1.5mm;color:#334155">
    <span>محل امضا و اثر انگشت ولی دانش‌آموز</span>
    <span style="position:relative;display:inline-block;padding:0 2mm">
      امضای مدیر / مهر مدرسه
      <?php if ($stamp !== ''): ?>
        <img src="<?php echo $esc($stamp); ?>" alt="" class="sfe-stamp"
             style="position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:26mm;max-height:12mm;object-fit:contain;opacity:.9;pointer-events:none;mix-blend-mode:multiply">
      <?php endif; ?>
    </span>
    <span aria-hidden="true" style="width:1px"></span>
  </div>
</div>
            <?php
            $blocks[] = ob_get_clean();
        }
        return $blocks;
    }
}

if (!function_exists('sf_render_camp_consent')) {
    /**
     * رضایت‌نامهٔ اردو — چهار برگه در هر صفحه A4.
     *
     * v4.181.0: بر پایهٔ بازخورد کارفرما —
     *   • سرصفحه به‌جای «تاریخ» عنوان «سال تحصیلی» را نشان می‌دهد.
     *   • عنوان اردو، تاریخ، ساعت، مبدأ، مقصد، مدت، مبلغ و توضیحات از ورودی
     *     کاربر می‌آید و هر کدام کلید نمایش مستقل دارد.
     *   • امضای مدیر به‌صورت پوشش (overlay) روی «امضای مدیر / مهر مدرسه»
     *     می‌افتد، نه وسط کادر توضیحات.
     */
    function sf_render_camp_consent(array $students, $ctx, $autoPrint = false, $blank = false, array $opts = []) {
        if (!$opts) $opts = sf_form_defaults('consent');
        $blocks = sf_camp_blocks($students, $ctx, $blank, $opts);
        /* چهار برگه در هر صفحه */
        $out = sf_head('رضایت‌نامهٔ اردو');
        $out .= '<style>.sheet{padding:7mm 8mm}.blk+.blk{margin-top:4mm}</style>';  /* ۶۲×۴ + ۳×۴ = ۲۶۰ میلی‌متر */
        foreach (array_chunk($blocks, 4) as $page) {
            $out .= '<div class="sheet">' . implode('', $page) . '</div>';
        }
        return $out . sf_tail($autoPrint);
    }
}
