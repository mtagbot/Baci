<?php
/**
 * includes/docx_class_list.php — v4.145.0
 *
 * تولید «لیست کلاسی دبیر» به‌صورت فایل Word، از روی قالب خامی که
 * مدرسه داده است (assets/templates/teacher-class-list.docx).
 *
 * چرا قالب واقعی و نه ساختن docx از صفر:
 *   قالب مدرسه دو جدول دقیق، عرض ستون‌های تنظیم‌شده، حاشیه‌ها و فونت
 *   «B Titr» را از قبل دارد. ساختن دوبارهٔ این‌ها از صفر یعنی تفاوت
 *   ریز با چیزی که مدرسه سال‌هاست استفاده می‌کند. اینجا فقط دو چیز
 *   عوض می‌شود: شمارهٔ کلاس، و اسامی دانش‌آموزان.
 *
 * ساختار قالب (استخراج‌شده از خودِ فایل):
 *   جدول ۱ — صفحهٔ اول، ۳۵ ردیف:
 *       r0     سربرگ + سلول «کلاس : 3/9»
 *       r1..r4 سرستون‌ها (ردیف، جلسات، تاریخ، فعالیت درسی،
 *              «نام خانوادگی و نام»)
 *       r5..r34 سی ردیف دانش‌آموز؛ ستون ۰ شماره، ستون ۱ محل نام
 *   جدول ۲ — صفحهٔ دوم، «جدول ثبت میزان تدریس» (دست‌نخورده می‌ماند)
 *
 * docx یک فایل zip است؛ برای تغییرش فقط word/document.xml بازنویسی
 * می‌شود و بقیهٔ اجزا بیت‌به‌بیت کپی می‌شوند.
 */

if (!function_exists('dcl_template_path')) {
    function dcl_template_path() {
        return __DIR__ . '/../assets/templates/teacher-class-list.docx';
    }
}

if (!function_exists('dcl_grade_number')) {
    /**
     * شمارهٔ پایه از نام فارسی.
     *
     * چرا grade_sort_weight() بازاستفاده نشد: آن تابع روی نقشه حلقه
     * می‌زند و اولین تطابق را برمی‌گرداند، ولی «دهم» زیررشتهٔ
     * «دوازدهم» و «یازدهم» است. نتیجه: «دوازدهم4» به «10/4» تبدیل
     * می‌شد. آن تابع برای «مرتب‌سازی» نوشته شده و آنجا این خطا خود را
     * نشان نمی‌دهد، پس عوض‌کردنش ریسک داشت؛ اینجا نسخهٔ دقیق نوشته شد
     * که از بلندترین نام شروع می‌کند.
     */
    function dcl_grade_number($text) {
        $t = function_exists('norm_persian_str') ? norm_persian_str((string)$text) : (string)$text;
        /* از بلند به کوتاه، تا «دوازدهم» پیش از «دهم» بررسی شود */
        $map = [
            'دوازدهم' => 12, 'یازدهم' => 11, 'دهم' => 10,
            'نهم' => 9, 'هشتم' => 8, 'هفتم' => 7,
            'ششم' => 6, 'پنجم' => 5, 'چهارم' => 4, 'سوم' => 3, 'دوم' => 2, 'اول' => 1,
        ];
        foreach ($map as $k => $v) {
            $kk = function_exists('norm_persian_str') ? norm_persian_str($k) : $k;
            if (mb_strpos($t, $kk) !== false) return $v;
        }
        return 0;
    }
}

if (!function_exists('dcl_class_code')) {
    /**
     * «هفتم1» → «7/1»
     *
     * خواستهٔ مدرسه: عدد پایه، اسلش، شمارهٔ کلاس. اگر پایه یا شماره
     * قابل تشخیص نباشد، خودِ نام کلاس برگردانده می‌شود تا سلول هرگز
     * خالی یا غلط نماند.
     */
    function dcl_class_code($className, $gradeLevel = '') {
        $name = trim((string)$className);
        if ($name === '') return '';

        $grade = trim((string)$gradeLevel);
        if ($grade === '' && function_exists('infer_grade_from_class_name')) {
            $grade = (string)infer_grade_from_class_name($name);
        }
        $gradeNum = dcl_grade_number($grade !== '' ? $grade : $name);
        if ($gradeNum <= 0 || $gradeNum > 12) return $name;

        /* شمارهٔ کلاس = عددی که در نام کلاس آمده (فارسی یا انگلیسی) */
        $en = function_exists('tr_num') ? tr_num($name, 'en') : $name;
        if (!preg_match('/(\d+)\s*$/u', $en, $m)) {
            if (!preg_match('/(\d+)/u', $en, $m)) return $name;
        }
        $classNum = (int)$m[1];
        if ($classNum <= 0) return $name;

        /* v4.146.0 — ترتیب عمدی: «شمارهٔ کلاس / پایه».
           Word این سلول را راست‌به‌چپ می‌چیند، پس رشتهٔ «9/1» روی کاغذ
           «1/9» دیده می‌شود. کارفرما «هفتم1» را به‌شکل «7/1» می‌خواهد،
           یعنی آنچه چشم می‌بیند باید پایه سمت راست باشد. برای همین
           اینجا عکسِ آن تولید می‌شود تا رندر RTL نتیجه را درست کند. */
        return $classNum . '/' . $gradeNum;
    }
}

if (!function_exists('dcl_xml_escape')) {
    function dcl_xml_escape($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

if (!function_exists('dcl_make_run')) {
    /**
     * یک <w:r> با فونت B Titr.
     *
     * همان rPr قالب تکرار می‌شود (B Titr، بولد، راست‌به‌چپ) تا متن
     * تزریق‌شده با بقیهٔ فایل یکدست باشد — خواستهٔ کارفرما این بود که
     * «تمام فونت‌های این لیست فونت تیتر باشد».
     */
    function dcl_make_run($text, $bold = true) {
        $b = $bold ? '<w:b/><w:bCs/>' : '';
        return '<w:r><w:rPr><w:rFonts w:cs="B Titr" w:hint="cs"/>' . $b
             . '<w:szCs w:val="24"/><w:rtl/><w:lang w:bidi="fa-IR"/></w:rPr>'
             . '<w:t xml:space="preserve">' . dcl_xml_escape($text) . '</w:t></w:r>';
    }
}

if (!function_exists('dcl_set_cell_text')) {
    /**
     * متن یک <w:tc> را جایگزین می‌کند و پاراگراف/قالب‌بندی را نگه می‌دارد.
     *
     * روش: همهٔ <w:r> های داخل اولین <w:p> حذف و یک run تازه گذاشته
     * می‌شود. چرا نه جست‌وجوی متن: مقدار «3/9» در قالب به سه run جدا
     * شکسته شده («3»، «/»، «9») و جایگزینی رشته‌ای آن را پیدا نمی‌کرد.
     */
    function dcl_set_cell_text($cellXml, $text, $bold = true) {
        /* اولین پاراگراف سلول */
        if (!preg_match('/<w:p(?:\s[^>]*)?>.*?<\/w:p>/s', $cellXml, $pm)) return $cellXml;
        $p = $pm[0];

        /* run های موجود را بردار */
        $newP = preg_replace('/<w:r(?:\s[^>]*)?>.*?<\/w:r>/s', '', $p);

        /* run تازه را درست قبل از بسته‌شدن پاراگراف بگذار */
        $run = dcl_make_run($text, $bold);
        $newP = preg_replace('/<\/w:p>$/', $run . '</w:p>', $newP, 1);

        return str_replace($p, $newP, $cellXml);
    }
}

if (!function_exists('dcl_split_name_column')) {
    /**
     * ستون «نام خانوادگی و نام» را به دو ستون جدا می‌کند.
     *
     * قالب اصلی یک ستون ۲۲۵۶ توییپی دارد. برای دو ستون شدن سه چیز
     * باید هم‌زمان درست شود، وگرنه Word فایل را خراب می‌داند:
     *   ۱) <w:tblGrid> باید یک <w:gridCol> بیشتر داشته باشد.
     *   ۲) ردیف سربرگِ بالا (r0) که gridSpan=13 دارد باید ۱۴ شود،
     *      چون یک ستون به پهنای جدول اضافه شده.
     *   ۳) هر ردیف باید یک <w:tc> تازه کنار سلول نام بگیرد.
     *
     * تقسیم عمدی نامتقارن است: نام خانوادگی معمولاً بلندتر از نام
     * است، پس ۱۲۵۶ به آن و ۱۰۰۰ به نام می‌رسد.
     *
     * ترتیب ستون‌ها در XML: چون جدول RTL است (<w:bidiVisual/>)، اولین
     * <w:tc> سمت راست دیده می‌شود. پس سلول «نام خانوادگی» اول می‌آید
     * و «نام» بعد از آن — دقیقاً خواستهٔ کارفرما.
     */
    function dcl_split_name_column($tblXml) {
        $NAME_W = 2256;      /* عرض ستون فعلی در قالب */
        $W_LAST = 1256;      /* نام خانوادگی */
        $W_FIRST = 1000;     /* نام */

        /* ۱) شبکهٔ جدول */
        if (preg_match('/<w:tblGrid>.*?<\/w:tblGrid>/s', $tblXml, $gm)) {
            $grid = $gm[0];
            $newGrid = preg_replace(
                '/<w:gridCol w:w="' . $NAME_W . '"\s*\/>/',
                '<w:gridCol w:w="' . $W_LAST . '"/><w:gridCol w:w="' . $W_FIRST . '"/>',
                $grid, 1
            );
            $tblXml = str_replace($grid, $newGrid, $tblXml);
        }

        /* ۲) و ۳) ردیف‌ها */
        if (!preg_match_all('/<w:tr(?:\s[^>]*)?>.*?<\/w:tr>/s', $tblXml, $rm)) return $tblXml;

        foreach ($rm[0] as $ri => $row) {
            if (!preg_match_all('/<w:tc>.*?<\/w:tc>/s', $row, $cm)) continue;
            $cells = $cm[0];

            /* ردیف سربرگ بالا: فقط gridSpan بزرگ‌تر شود */
            if (count($cells) === 2) {
                $newRow = preg_replace_callback(
                    '/<w:gridSpan w:val="(\d+)"\/>/',
                    function ($m) { return '<w:gridSpan w:val="' . ((int)$m[1] + 1) . '"/>'; },
                    $row, 1
                );
                $tblXml = str_replace($row, $newRow, $tblXml);
                continue;
            }

            if (count($cells) < 2) continue;
            $nameCell = $cells[1];

            /* سلول نام خانوادگی = همان سلول با عرض کمتر */
            $lastCell = preg_replace('/w:w="' . $NAME_W . '"/', 'w:w="' . $W_LAST . '"', $nameCell, 1);
            /* سلول نام = کپی، با عرض خودش و بدون متن */
            $firstCell = preg_replace('/w:w="' . $NAME_W . '"/', 'w:w="' . $W_FIRST . '"', $nameCell, 1);
            $firstCell = preg_replace('/<w:r(?:\s[^>]*)?>.*?<\/w:r>/s', '', $firstCell);

            $newRow = str_replace($nameCell, $lastCell . $firstCell, $row);
            $tblXml = str_replace($row, $newRow, $tblXml);
        }

        return $tblXml;
    }
}

if (!function_exists('dcl_build_document_xml')) {
    /**
     * document.xml قالب را می‌گیرد و نسخهٔ پرشده برمی‌گرداند.
     *
     * @param string $xml      محتوای word/document.xml قالب
     * @param string $classCode  مثل «7/1»
     * @param array  $names     نام دانش‌آموزان، از پیش مرتب‌شده
     */
    function dcl_build_document_xml($xml, $classCode, array $names) {
        /* --- جدول اول را جدا کن --- */
        if (!preg_match('/<w:tbl>.*?<\/w:tbl>/s', $xml, $tm)) return $xml;
        $origTbl = $tm[0];
        /* v4.146.0: ستون نام به دو ستون تبدیل می‌شود، پیش از هر کار دیگر */
        $tbl = dcl_split_name_column($origTbl);
        $newTbl = $tbl;

        $rows = [];
        if (preg_match_all('/<w:tr(?:\s[^>]*)?>.*?<\/w:tr>/s', $tbl, $rm)) {
            $rows = $rm[0];
        }
        if (count($rows) < 6) return $xml;

        /* --- ردیف ۰: شمارهٔ کلاس --- */
        $r0 = $rows[0];
        if (preg_match_all('/<w:tc>.*?<\/w:tc>/s', $r0, $cm) && count($cm[0]) >= 2) {
            $cell = $cm[0][1];
            $newCell = dcl_set_cell_text($cell, 'کلاس : ' . $classCode, true);
            $newR0 = str_replace($cell, $newCell, $r0);
            $newTbl = str_replace($r0, $newR0, $newTbl);
            $rows[0] = $newR0;
        }

        /* --- ردیف ۴: سرستون‌های دو ستون تازه --- */
        if (isset($rows[4]) && preg_match_all('/<w:tc>.*?<\/w:tc>/s', $rows[4], $hm) && count($hm[0]) >= 3) {
            $r4 = $rows[4];
            $newR4 = $r4;
            /* سلول ۱ = نام خانوادگی (سمت راست)، سلول ۲ = نام */
            $c1 = $hm[0][1];
            $newR4 = str_replace($c1, dcl_set_cell_text($c1, 'نام خانوادگی', true), $newR4);
            /* دوباره سلول‌ها را از نسخهٔ جدید بگیر تا جابه‌جایی درست باشد */
            if (preg_match_all('/<w:tc>.*?<\/w:tc>/s', $newR4, $hm2) && count($hm2[0]) >= 3) {
                $c2 = $hm2[0][2];
                $newR4 = str_replace($c2, dcl_set_cell_text($c2, 'نام', true), $newR4);
            }
            $newTbl = str_replace($r4, $newR4, $newTbl);
            $rows[4] = $newR4;
        }

        /* --- ردیف‌های ۵ به بعد: اسامی در دو ستون --- *
         * ستون ۰ شمارهٔ ردیف (دست‌نخورده)، ستون ۱ نام خانوادگی، ستون ۲ نام. */
        $idx = 0;
        for ($i = 5; $i < count($rows); $i++) {
            $row = $rows[$i];
            if (!preg_match_all('/<w:tc>.*?<\/w:tc>/s', $row, $cm2)) continue;
            if (count($cm2[0]) < 3) continue;

            $pair = isset($names[$idx]) ? $names[$idx] : null;
            $idx++;
            if ($pair === null) continue;   /* بقیهٔ سلول‌ها خالی می‌مانند */

            $last  = is_array($pair) ? (string)($pair['last'] ?? '')  : (string)$pair;
            $first = is_array($pair) ? (string)($pair['first'] ?? '') : '';
            if ($last === '' && $first === '') continue;

            $newRow = $row;
            $cLast = $cm2[0][1];
            $newRow = str_replace($cLast, dcl_set_cell_text($cLast, $last, false), $newRow);
            if (preg_match_all('/<w:tc>.*?<\/w:tc>/s', $newRow, $cm3) && count($cm3[0]) >= 3) {
                $cFirst = $cm3[0][2];
                $newRow = str_replace($cFirst, dcl_set_cell_text($cFirst, $first, false), $newRow);
            }
            $newTbl = str_replace($row, $newRow, $newTbl);
        }

        return str_replace($origTbl, $newTbl, $xml);
    }
}

if (!function_exists('dcl_generate')) {
    /**
     * فایل docx پرشده را به‌صورت رشته برمی‌گرداند (یا null در خطا).
     *
     * docx = zip. با ZipArchive فایل قالب کپی و فقط document.xml
     * بازنویسی می‌شود، پس هر چیز دیگری (استایل، فونت، تنظیم صفحه)
     * دقیقاً مثل قالب مدرسه می‌ماند.
     */
    function dcl_generate($classCode, array $names) {
        $tpl = dcl_template_path();
        if (!is_file($tpl) || !class_exists('ZipArchive')) return null;

        $tmp = tempnam(sys_get_temp_dir(), 'dcl');
        if ($tmp === false) return null;
        if (!@copy($tpl, $tmp)) { @unlink($tmp); return null; }

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) { @unlink($tmp); return null; }

        $xml = $zip->getFromName('word/document.xml');
        if ($xml === false) { $zip->close(); @unlink($tmp); return null; }

        $zip->addFromString('word/document.xml', dcl_build_document_xml($xml, $classCode, $names));
        $zip->close();

        $out = @file_get_contents($tmp);
        @unlink($tmp);
        return $out === false ? null : $out;
    }
}

if (!function_exists('dcl_render_print_html')) {
    /**
     * نمای چاپی لیست کلاسی — همان دو صفحهٔ قالب Word، با HTML.
     *
     * چرا PDF با مرورگر و نه با کتابخانهٔ PHP:
     *   TCPDF در این بسته موجود نیست (export-pdf.php هم وقتی پیدایش
     *   نکند به صفحهٔ چاپ برمی‌گردد). مهم‌تر اینکه کارفرما تأکید کرد
     *   فونت باید «تیتر» باشد؛ TCPDF برای فونت فارسی به تبدیل دستی
     *   فونت و افزودن فایل‌های .z/.php نیاز دارد و هر خطایی در آن
     *   مسیر به‌جای فونت تیتر، مربع خالی چاپ می‌کند.
     *   مرورگر همان B-Titr.ttf موجود در uploads را با @font-face
     *   می‌خواند و «ذخیره به‌صورت PDF» خروجی برداری و دقیق می‌دهد.
     *
     * چیدمان عمداً با جدول HTML بازسازی شده تا با قالب Word یکی
     * باشد: ۳۰ ردیف، همان سرستون‌ها، همان دو صفحه.
     */
    function dcl_render_print_html($classCode, array $names, $schoolName = '', $autoPrint = true) {
        $fontUrl = 'uploads/B-Titr/B-Titr.ttf';
        $rows = 30;
        $sessions = 10;   /* ستون‌های جلسات در قالب */

        ob_start();
        ?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title>لیست کلاسی <?php echo htmlspecialchars($classCode, ENT_QUOTES, 'UTF-8'); ?></title>
<style>
/* فونت تیتر، از همان فایلی که در بستهٔ سامانه هست */
@font-face{font-family:'BTitr';src:url('<?php echo $fontUrl; ?>') format('truetype');font-display:block}
*{box-sizing:border-box;margin:0;padding:0}
html,body{background:#fff}
body,table,td,th{font-family:'BTitr',Tahoma,sans-serif}
.page{width:277mm;min-height:190mm;padding:6mm;page-break-after:always;break-after:page}
.page:last-child{page-break-after:auto;break-after:auto}
table{width:100%;border-collapse:collapse;table-layout:fixed}
td,th{border:0.3mm solid #000;text-align:center;vertical-align:middle;
      font-size:2.7mm;line-height:1.25;padding:0.4mm;overflow:hidden}
.title{font-size:3.4mm;font-weight:700;padding:1.4mm}
.hdr{font-size:2.5mm;font-weight:700;height:7mm}
.rownum{width:8mm}
.cl{width:26mm}
.cf{width:21mm}
.cell{height:6.2mm}
.vert{writing-mode:vertical-rl;transform:rotate(180deg);white-space:nowrap}
@media print{ @page{size:A4 landscape;margin:6mm} .noprint{display:none!important} }
.noprint{margin:8px;font-family:Tahoma}
.noprint button{padding:8px 18px;border:0;border-radius:8px;background:#2563eb;color:#fff;font-size:13px;cursor:pointer}
</style>
</head>
<body>
<div class="noprint">
    <button onclick="window.print()">چاپ / ذخیره به‌صورت PDF</button>
    <span style="font-size:12px;color:#334155;margin-right:8px">
        در پنجرهٔ چاپ، مقصد را روی «Save as PDF» بگذارید.
    </span>
</div>

<div class="page">
    <table>
        <tr>
            <td class="title" colspan="<?php echo 2 + $sessions + 3; ?>">جدول ثبت گزارش تدریس و نمرات ارزشیابی مستمر و حضور و غیاب دانش آموزان</td>
            <td class="title" colspan="2">کلاس : <?php echo htmlspecialchars($classCode, ENT_QUOTES, 'UTF-8'); ?></td>
        </tr>
        <tr>
            <td class="hdr rownum" rowspan="4">ردیف</td>
            <td class="hdr" colspan="2">جلسات</td>
            <?php for ($i = 0; $i < $sessions; $i++): ?><td class="hdr"></td><?php endfor; ?>
            <td class="hdr" rowspan="4">جمع غیبت</td>
            <td class="hdr" rowspan="4">نمره مستمر</td>
            <td class="hdr" rowspan="4">ملاحظات</td>
        </tr>
        <tr>
            <td class="hdr" colspan="2">تاریخ</td>
            <?php for ($i = 0; $i < $sessions; $i++): ?><td class="hdr"></td><?php endfor; ?>
        </tr>
        <tr>
            <td class="hdr" colspan="2">فعالیت درسی</td>
            <?php for ($i = 0; $i < $sessions; $i++): ?><td class="hdr"></td><?php endfor; ?>
        </tr>
        <tr>
            <td class="hdr cl">نام خانوادگی</td>
            <td class="hdr cf">نام</td>
            <?php for ($i = 0; $i < $sessions; $i++): ?><td class="hdr"></td><?php endfor; ?>
        </tr>
        <?php for ($r = 0; $r < $rows; $r++):
            $last  = isset($names[$r]) ? (is_array($names[$r]) ? ($names[$r]['last'] ?? '') : $names[$r]) : '';
            $first = isset($names[$r]) && is_array($names[$r]) ? ($names[$r]['first'] ?? '') : '';
        ?>
        <tr>
            <td class="cell rownum"><?php echo $r + 1; ?></td>
            <td class="cell cl"><?php echo htmlspecialchars($last, ENT_QUOTES, 'UTF-8'); ?></td>
            <td class="cell cf"><?php echo htmlspecialchars($first, ENT_QUOTES, 'UTF-8'); ?></td>
            <?php for ($i = 0; $i < $sessions + 3; $i++): ?><td class="cell"></td><?php endfor; ?>
        </tr>
        <?php endfor; ?>
    </table>
</div>

<div class="page">
    <table>
        <tr><td class="title" colspan="4">جدول ثبت میزان تدریس</td></tr>
        <tr>
            <td class="hdr" style="width:14mm">جلسه</td>
            <td class="hdr" style="width:32mm">تاریخ</td>
            <td class="hdr">توضیحات</td>
            <td class="hdr" style="width:40mm">امضا</td>
        </tr>
        <?php for ($r = 1; $r <= 32; $r++): ?>
        <tr>
            <td class="cell"><?php echo $r; ?></td>
            <td class="cell"></td><td class="cell"></td><td class="cell"></td>
        </tr>
        <?php endfor; ?>
    </table>
</div>

<?php if ($autoPrint): ?>
<script>
/* چاپ باید تا لود شدن فونت صبر کند — همان درسی که در چاپ تگ‌ها و
   کارت ورود گرفتیم: اگر زودتر باز شود، خروجی با فونت جایگزین می‌رود. */
(function(){
  var done = false;
  function go(){ if (done) return; done = true; setTimeout(function(){ window.print(); }, 250); }
  if (document.fonts && document.fonts.ready && typeof document.fonts.ready.then === 'function') {
    document.fonts.ready.then(go);
    setTimeout(go, 3000);
  } else { setTimeout(go, 800); }
})();
</script>
<?php endif; ?>
</body>
</html><?php
        return ob_get_clean();
    }
}

if (!function_exists('dcl_students_of_class')) {
    /**
     * اسامی یک کلاس، به ترتیب الفبای فارسی («آ» پیش از «ا»).
     * خروجی: آرایه‌ای از ['last'=>..., 'first'=>...] — چون لیست از
     * v4.146.0 دو ستون جدا دارد.
     */
    function dcl_students_of_class($className, $year = '') {
        $where = ["s.status='active'", 's.class_name = ?'];
        $params = [$className];
        if ($year !== '') {
            $where[] = '(s.academic_year = ? OR s.academic_year IS NULL OR s.academic_year = \'\')';
            $params[] = $year;
        }
        $rows = DB::fetchAll(
            "SELECT s.first_name, s.last_name FROM students s WHERE " . implode(' AND ', $where),
            $params
        );
        if (function_exists('persian_usort_students')) persian_usort_students($rows);

        /* v4.146.0: نام و نام خانوادگی جدا برمی‌گردند، چون لیست حالا
           دو ستون مجزا دارد. */
        $out = [];
        foreach ($rows as $r) {
            $last  = trim((string)($r['last_name'] ?? ''));
            $first = trim((string)($r['first_name'] ?? ''));
            if ($last === '' && $first === '') continue;
            $out[] = ['last' => $last, 'first' => $first];
        }
        return $out;
    }
}
