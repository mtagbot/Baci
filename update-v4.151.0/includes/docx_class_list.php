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

if (!function_exists('dcl_tighten_cell')) {
    /**
     * حاشیهٔ داخلی (padding) سلول را کم می‌کند.
     *
     * Word وقتی <w:tcMar> نداشته باشد، پیش‌فرضِ ۱۰۸ توییپ در چپ و
     * راست می‌گذارد؛ یعنی ۲۱۶ توییپ (۳٫۸ میلی‌متر) از عرض هر سلول
     * صرف فضای خالی می‌شود. روی ستون نام که باریک است، همین مقدار
     * باعث می‌شد نام‌های بلند بشکنند.
     *
     * با ۲۸ توییپ در هر طرف، حدود ۲٫۸ میلی‌متر فضای نوشتاری آزاد
     * می‌شود بدون اینکه متن به خط جدول بچسبد.
     */
    function dcl_tighten_cell($cellXml) {
        $mar = '<w:tcMar>'
             . '<w:left w:w="28" w:type="dxa"/>'
             . '<w:right w:w="28" w:type="dxa"/>'
             . '</w:tcMar>';

        /* اگر از قبل tcMar دارد، جایگزینش کن */
        if (strpos($cellXml, '<w:tcMar>') !== false) {
            return preg_replace('/<w:tcMar>.*?<\/w:tcMar>/s', $mar, $cellXml, 1);
        }
        /* وگرنه داخل tcPr اضافه کن — ترتیب عناصر در tcPr مهم است و
           tcMar باید بعد از tcW و پیش از vAlign بیاید. */
        if (preg_match('/<w:tcPr>.*?<\/w:tcPr>/s', $cellXml, $m)) {
            $pr = $m[0];
            if (strpos($pr, '<w:vAlign') !== false) {
                $newPr = preg_replace('/(<w:vAlign)/', $mar . '$1', $pr, 1);
            } else {
                $newPr = str_replace('</w:tcPr>', $mar . '</w:tcPr>', $pr);
            }
            return str_replace($pr, $newPr, $cellXml);
        }
        return $cellXml;
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
        $NAME_W  = 2256;     /* عرض ستون فعلی در قالب */
        /* v4.147.0 — یک ستون جلسات حذف و عرضش (۵۵۹) به این دو اضافه شد.
           دلیل: نام‌های چندکلمه‌ای در ستون باریک به خط دوم می‌رفتند و
           ارتفاع ردیف را می‌شکستند، که کل صفحه‌بندی دو صفحه‌ای را
           به‌هم می‌ریخت. مجموع عرض جدول ثابت می‌ماند (۱۰۶۵۲)، پس
           جدول از کاغذ بیرون نمی‌زند. */
        $W_LAST  = 1563;     /* نام خانوادگی — سهم بیشتر، چون بلندتر است */
        $W_FIRST = 1252;     /* نام */
        $DROP_W  = 559;      /* ستون جلسه‌ای که حذف می‌شود */

        /* ۱) شبکهٔ جدول: ستون نام دو تا می‌شود و یک ستون جلسه می‌رود،
              پس تعداد ستون‌ها همان ۱۶ باقی می‌ماند. */
        if (preg_match('/<w:tblGrid>.*?<\/w:tblGrid>/s', $tblXml, $gm)) {
            $grid = $gm[0];
            $newGrid = preg_replace(
                '/<w:gridCol w:w="' . $NAME_W . '"\s*\/>/',
                '<w:gridCol w:w="' . $W_LAST . '"/><w:gridCol w:w="' . $W_FIRST . '"/>',
                $grid, 1
            );
            /* اولین ستون جلسه (بعد از ستون نام) حذف می‌شود */
            $newGrid = preg_replace(
                '/<w:gridCol w:w="' . $DROP_W . '"\s*\/>/', '', $newGrid, 1
            );
            $tblXml = str_replace($grid, $newGrid, $tblXml);
        }

        /* ۲) و ۳) ردیف‌ها */
        if (!preg_match_all('/<w:tr(?:\s[^>]*)?>.*?<\/w:tr>/s', $tblXml, $rm)) return $tblXml;

        foreach ($rm[0] as $ri => $row) {
            if (!preg_match_all('/<w:tc>.*?<\/w:tc>/s', $row, $cm)) continue;
            $cells = $cm[0];

            /* ردیف سربرگ بالا (دو سلول با gridSpan): تعداد کل ستون‌ها
               عوض نشده (یکی اضافه، یکی حذف)، پس gridSpan دست‌نخورده
               می‌ماند. این عمدی است — در v4.146.0 چون فقط اضافه
               می‌کردیم، لازم بود یکی زیاد شود. */
            if (count($cells) === 2) continue;

            if (count($cells) < 3) continue;
            $nameCell = $cells[1];

                /* سلول نام خانوادگی = همان سلول با عرض تازه */
            $lastCell = preg_replace('/w:w="' . $NAME_W . '"/', 'w:w="' . $W_LAST . '"', $nameCell, 1);
            $lastCell = dcl_tighten_cell($lastCell);
            /* سلول نام = کپی، با عرض خودش و بدون متن */
            $firstCell = preg_replace('/w:w="' . $NAME_W . '"/', 'w:w="' . $W_FIRST . '"', $nameCell, 1);
            $firstCell = preg_replace('/<w:r(?:\s[^>]*)?>.*?<\/w:r>/s', '', $firstCell);
            $firstCell = dcl_tighten_cell($firstCell);

            $newRow = str_replace($nameCell, $lastCell . $firstCell, $row);

            /* یک ستون جلسه حذف می‌شود تا عرض جدول ثابت بماند.
               سلول‌ها را از نسخهٔ تازه می‌گیریم چون ایندکس‌ها جابه‌جا
               شده‌اند؛ اولین سلولِ بعد از دو ستون نام حذف می‌شود. */
            if (preg_match_all('/<w:tc>.*?<\/w:tc>/s', $newRow, $cm4) && count($cm4[0]) >= 4) {
                $victim = $cm4[0][3];
                /* سلولی که vMerge ادامه‌دار دارد را حذف نکن — ساختار
                   ادغام عمودی را می‌شکند. به‌جایش بعدی را بردار. */
                $k = 3;
                while ($k < count($cm4[0]) && strpos($cm4[0][$k], '<w:vMerge') !== false
                       && strpos($cm4[0][$k], 'w:val="restart"') === false) {
                    $k++;
                }
                if ($k < count($cm4[0])) $victim = $cm4[0][$k];
                $pos = strpos($newRow, $victim);
                if ($pos !== false) {
                    $newRow = substr_replace($newRow, '', $pos, strlen($victim));
                }
            }

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

if (!function_exists('dcl_print_grid')) {
    /**
     * عرض ستون‌های جدول اول، به توییپ — دقیقاً همان اعدادی که در
     * قالب Word هستند (پس از تقسیم ستون نام و حذف یک ستون جلسه).
     * PDF از همین‌ها درصد می‌سازد تا نسبت ستون‌ها مو به مو یکی باشد.
     */
    function dcl_print_grid() {
        return [
            /* دقیقاً همان ترتیبی که پس از حذف یک ستون در فایل Word
               می‌ماند — با آن مقایسه و تأیید شده است. */
            567,          /* ردیف */
            1563, 1252,   /* نام خانوادگی، نام */
            559, 559, 559, 560, 559, 559, 559, 559,  /* ۹ ستون جلسه */
            560, 515, 567, 567, 588,                  /* ۵ ستون انتهایی */
        ];
    }
}

if (!function_exists('dcl_table_indent')) {
    /**
     * فاصلهٔ هر جدول از لبهٔ راست کاغذ — <w:tblInd> قالب (توییپ).
     *
     * v4.151.0 — پیش‌تر هر دو جدول با «margin:0 auto» وسط‌چین شده
     * بودند، پس هیچ‌کدام سر جای واقعی‌اش نبود: جدول صفحهٔ اول از راست
     * فاصله می‌گرفت و تا لبهٔ چپ می‌رفت، و جدول صفحهٔ دوم برعکس.
     * در جدول RTL، tblInd فاصله از لبهٔ راست است — و برای جدول دوم
     * منفی است، یعنی کمی بیرون‌تر از حاشیه شروع می‌شود.
     */
    function dcl_table_indent() {
        return ['t1' => 418, 't2' => -173];
    }
}

if (!function_exists('dcl_print_grid2')) {
    /** عرض ستون‌های جدول صفحهٔ دوم، عیناً از قالب. */
    function dcl_print_grid2() {
        return [773, 1409, 559, 1129, 3770, 1415, 1600];
    }
}

if (!function_exists('dcl_print_page')) {
    /** ابعاد و حاشیهٔ صفحه، عیناً از <w:sectPr> قالب (به میلی‌متر). */
    function dcl_print_page() {
        return [
            'w' => 11906 / 56.7, 'h' => 16838 / 56.7,   /* A4 عمودی */
            'top' => 142 / 56.7, 'right' => 566 / 56.7,
            'bottom' => 4.0,                             /* قالب ۰ دارد؛ چاپگر نمی‌تواند */
            'left' => 540 / 56.7,
        ];
    }
}

/* ارتفاع یکنواخت ردیف‌های دانش‌آموز (میلی‌متر).
   از تقسیم فضای باقی‌مانده صفحه بر ۳۰ ردیف به‌دست آمده، طوری که
   جدول کامل در یک صفحه جا شود. */
if (!defined('DCL_BODY_ROW_MM')) define('DCL_BODY_ROW_MM', 6.35);

if (!function_exists('dcl_row_heights')) {
    /**
     * ارتفاع ردیف‌های جدول اول، عیناً از <w:trHeight> قالب (توییپ).
     *
     * v4.150.0 — پیش‌تر همهٔ ردیف‌ها ارتفاع یکسان داشتند و به همین
     * دلیل PDF با Word یکی نمی‌شد. در قالب، ردیف «فعالیت درسی»
     * ۶۶۹ توییپ است (بلندترین) و چند ردیف دیگر هم ارتفاع ویژه
     * دارند. صفر یعنی auto (به‌اندازهٔ محتوا).
     */
    function dcl_row_heights() {
        return [
            0, 160, 130, 669, 361,          /* سربرگ‌ها */
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,   /* ردیف ۱..۱۰ */
            135, 195, 0, 300, 0,            /* ۱۱..۱۵ */
            0, 240, 280, 0, 0,              /* ۱۶..۲۰ */
            0, 0, 0, 0, 0,                  /* ۲۱..۲۵ */
            0, 0, 0, 321, 0,                /* ۲۶..۳۰ */
        ];
    }
}

if (!function_exists('dcl_header_font')) {
    /**
     * اندازهٔ فونت سلول‌های سربرگ، از <w:sz> قالب (نصف‌پوینت).
     * قالب برای هر سربرگ اندازهٔ متفاوتی دارد؛ یکسان‌کردنشان یکی از
     * دلایل تفاوت ظاهری PDF با Word بود.
     */
    function dcl_header_font() {
        return [
            'title'   => 24,   /* عنوان بالای جدول و سلول کلاس */
            'radif'   => 14,   /* «ردیف» — عمودی نوشته می‌شود */
            'jalasat' => 20,
            'tarikh'  => 22,
            'faaliat' => 16,
            'name'    => 24,
            'body'    => 24,
        ];
    }
}

if (!function_exists('dcl_render_print_html')) {
    /**
     * نمای چاپی لیست کلاسی — بازسازی دقیق قالب Word.
     *
     * v4.149.0 — سه ایراد گزارش‌شده رفع شد:
     *   ۱) کاغذ A4 «عمودی» است، نه افقی. قالب در <w:sectPr> صریحاً
     *      11906×16838 توییپ دارد و من افقی گذاشته بودم.
     *   ۲) حاشیه و عرض ستون‌ها از همان sectPr و tblGrid خوانده
     *      می‌شوند، نه اعداد تقریبی.
     *   ۳) جدول «دعوت از اولیا» جا افتاده بود. صفحهٔ دوم قالب در
     *      واقع یک جدول ۷ستونی است با دو بخش: «ثبت میزان تدریس»
     *      (۱۵ ردیف) و «دعوت از اولیا» (۱۵ ردیف).
     *
     * چرا PDF با مرورگر: TCPDF در بسته نیست و برای فارسی به فونت
     * تبدیل‌شده نیاز دارد که هر خطا در آن، به‌جای فونت تیتر مربع خالی
     * چاپ می‌کند. مرورگر همان B-Titr.ttf را می‌خواند.
     */
    function dcl_render_print_html($classCode, array $names, $schoolName = '', $autoPrint = true) {
        $fontUrl = 'uploads/B-Titr/B-Titr.ttf';
        $pg      = dcl_print_page();
        $grid    = dcl_print_grid();
        $grid2   = dcl_print_grid2();
        $total   = array_sum($grid);
        $total2  = array_sum($grid2);
        $rows    = 30;
        $nCols   = count($grid);          /* ۱۶ */
        $rh      = dcl_row_heights();     /* ارتفاع واقعی ردیف‌ها */
        $fs      = dcl_header_font();     /* اندازهٔ فونت سربرگ‌ها */
        /* توییپ → میلی‌متر؛ صفر یعنی auto که با min-height بیان می‌شود. */
        $mm = function ($tw) { return round($tw / 56.7, 2); };
        /* نصف‌پوینت → میلی‌متر (۱pt = ۰٫۳۵۲۸mm) */
        $pt = function ($half) { return round($half / 2 * 0.3528, 2); };
        /* ارتفاع مؤثر یک سربرگ: بزرگ‌ترِ «عدد قالب» و «جای لازم متن».
           همان کاری که Word با trHeight بدون hRule=exact می‌کند. */
        $need = function ($half) use ($pt) { return round($pt($half) * 1.1 + 1.0, 2); };
        $tin    = dcl_table_indent();
        $ind1   = $mm($tin['t1']);
        $ind2   = $mm($tin['t2']);
        $hdrJal = max($mm($rh[1]), $need($fs['jalasat']));
        $hdrTar = max($mm($rh[2]), $need($fs['tarikh']));

        /* درصد هر ستون، تا نسبت‌ها با Word یکی بماند */
        $pc = [];  foreach ($grid  as $w) $pc[]  = round($w * 100 / $total,  4);
        $pc2 = []; foreach ($grid2 as $w) $pc2[] = round($w * 100 / $total2, 4);

        /* عرض جدول نسبت به فضای قابل چاپ — مثل Word که جدول ۱۰۶۵۲ از
           ۱۰۸۰۰ را می‌گیرد، نه تمام عرض. */
        $usable  = 10800;
        $tblPc   = round($total  * 100 / $usable, 3);
        $tblPc2  = round($total2 * 100 / $usable, 3);

        ob_start();
        ?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title>لیست کلاسی <?php echo htmlspecialchars($classCode, ENT_QUOTES, 'UTF-8'); ?></title>
<style>
@font-face{font-family:'BTitr';src:url('<?php echo $fontUrl; ?>') format('truetype');font-display:block}
*{box-sizing:border-box;margin:0;padding:0}
html,body{background:#eef2f7}
body,table,td{font-family:'BTitr',Tahoma,sans-serif}
.sheet{
  width:<?php echo round($pg['w'], 1); ?>mm;
  /* v4.151.0 — ارتفاع «محتوا» = کاغذ منهای حاشیه‌ها.
     پیش‌تر min-height برابر کل ارتفاع کاغذ بود و padding رویش جمع
     می‌شد؛ برگه ۶٫۵mm بلندتر از A4 می‌شد و مرورگر یک صفحهٔ سوم خالی
     می‌ساخت. (box-sizing روی min-height اثر ندارد.) */
  min-height:<?php echo round($pg['h'] - $pg['top'] - $pg['bottom'], 1); ?>mm;
  background:#fff;margin:0 auto 8mm auto;
  padding:<?php echo round($pg['top'],1); ?>mm <?php echo round($pg['right'],1); ?>mm <?php echo round($pg['bottom'],1); ?>mm <?php echo round($pg['left'],1); ?>mm;
  box-shadow:0 2px 12px rgba(15,23,42,.16);
  page-break-after:always;break-after:page;
  overflow:hidden;
}
/* آخرین برگه نباید صفحه‌شکن بگذارد، وگرنه یک صفحهٔ خالی اضافه می‌شود */
.sheet:last-of-type{page-break-after:auto;break-after:auto;margin-bottom:0}
/* جدول‌ها با tblInd قالب تراز می‌شوند، نه وسط‌چین. صفحه RTL است پس
   margin-right همان فاصله از لبهٔ راست است. */
table{border-collapse:collapse;table-layout:fixed;margin:0}
.tbl1{margin-right:<?php echo $ind1; ?>mm}
.tbl2{margin-right:<?php echo $ind2; ?>mm}
/* عنوان بخش‌های صفحهٔ دوم: در قالب حاشیهٔ چپ/راست/بالا ندارند (nil) */
.sec{border-left:0;border-right:0;border-top:0}
td{border:0.35mm solid #000;text-align:center;vertical-align:middle;
   font-size:calc(<?php echo $pt($fs['body']); ?>mm * var(--fs));line-height:1.1;
   padding:0 0.2mm;overflow:hidden;word-break:break-word}
/* اندازهٔ هر سربرگ از <w:sz> قالب می‌آید، نه یک عدد یکسان */
.t  {font-size:calc(<?php echo $pt($fs['title']); ?>mm * var(--fs));font-weight:700}
.h  {font-weight:700}
.h-jal{font-size:calc(<?php echo $pt($fs['jalasat']); ?>mm * var(--fs))}
.h-tar{font-size:calc(<?php echo $pt($fs['tarikh']); ?>mm * var(--fs))}
.h-faa{font-size:calc(<?php echo $pt($fs['faaliat']); ?>mm * var(--fs))}
.h-nam{font-size:calc(<?php echo $pt($fs['name']); ?>mm * var(--fs))}
/* «ردیف» در قالب عمودی نوشته شده (textDirection=btLr) */
.h-rad{font-size:calc(<?php echo $pt($fs['radif']); ?>mm * var(--fs));
       writing-mode:vertical-rl;transform:rotate(180deg);white-space:nowrap}
.nm{white-space:nowrap;font-weight:700}
.r2{height:calc(6.4mm * var(--rh))}
:root{--fs:1;--rh:1}
body.zebra tbody tr:nth-child(even) td{background:#f6f8fb}
body.nobold .nm{font-weight:400}
@page{size:A4 portrait;margin:0}
@media print{
  html,body{background:#fff}
  .sheet{box-shadow:none;margin:0 auto}
  .noprint{display:none!important}
}
.noprint{max-width:<?php echo round($pg['w'], 1); ?>mm;margin:10px auto;font-family:Tahoma;
         display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.noprint button{padding:9px 20px;border:0;border-radius:8px;background:#2563eb;color:#fff;
                font-size:13px;cursor:pointer;font-family:inherit}
.noprint .ctl{display:flex;align-items:center;gap:6px;background:#fff;padding:7px 11px;
              border-radius:8px;font-size:12px;color:#0f172a}
.noprint .ctl input[type=range]{width:80px}
.noprint .ghost{background:#fff;color:#334155;border:1px solid #cbd5e1}
.noprint .hint{font-size:12px;color:#334155;background:#fff;padding:8px 12px;border-radius:8px}
</style>
</head>
<body>
<?php /* ویرایشگر زندهٔ پیش از چاپ — در خروجی چاپ دیده نمی‌شود. */ ?>
<div class="noprint" id="editor">
    <button onclick="window.print()">چاپ / ذخیره به‌صورت PDF</button>
    <label class="ctl">اندازهٔ متن
        <input type="range" id="cFont" min="80" max="130" step="5" value="100">
        <b id="vFont">۱۰۰٪</b>
    </label>
    <label class="ctl">ارتفاع ردیف
        <input type="range" id="cRow" min="80" max="140" step="5" value="100">
        <b id="vRow">۱۰۰٪</b>
    </label>
    <label class="ctl"><input type="checkbox" id="cZebra"> ردیف‌های یک‌درمیان</label>
    <label class="ctl"><input type="checkbox" id="cBold" checked> نام‌ها پررنگ</label>
    <button type="button" class="ghost" onclick="resetEditor()">بازنشانی</button>
    <span class="hint">کاغذ A4 عمودی · مقصد «Save as PDF» · Background graphics روشن</span>
</div>

<div class="sheet">
    <table class="tbl1" style="width:<?php echo $tblPc; ?>%">
        <colgroup>
            <?php foreach ($pc as $w): ?><col style="width:<?php echo $w; ?>%"><?php endforeach; ?>
        </colgroup>
        <?php /* r0 — عنوان + سلول کلاس (در قالب span13 و span3) */ ?>
        <tr<?php echo $rh[0] ? ' style="height:' . $mm($rh[0]) . 'mm"' : ''; ?>>
            <td class="t" colspan="<?php echo $nCols - 3; ?>">جدول ثبت گزارش تدریس و نمرات ارزشیابی مستمر و حضور و غیاب دانش آموزان</td>
            <td class="t" colspan="3">کلاس : <?php echo htmlspecialchars($classCode, ENT_QUOTES, 'UTF-8'); ?></td>
        </tr>
        <?php /* r1..r4 — «ردیف» عمودی و روی چهار ردیف ادغام شده */ ?>
        <?php /* v4.151.0 — «جلسات» و «تاریخ»:
              trHeight قالب برای این دو ۱۶۰ و ۱۳۰ توییپ (۲٫۸ و ۲٫۳mm)
              است، ولی خودِ متن با فونت ۱۰pt و ۱۱pt بلندتر از آن است.
              چون قالب hRule="exact" ندارد، Word این عدد را «حداقل»
              می‌گیرد و ردیف را تا اندازهٔ متن باز می‌کند. من آن را
              ارتفاع قطعی گرفته بودم و متن له می‌شد. */ ?>
        <tr style="height:<?php echo $hdrJal; ?>mm">
            <td class="h h-rad" rowspan="4">ردیف</td>
            <td class="h h-jal">جلسات</td>
            <?php for ($i = 0; $i < $nCols - 2; $i++): ?><td class="h"></td><?php endfor; ?>
        </tr>
        <tr style="height:<?php echo $hdrTar; ?>mm">
            <td class="h h-tar">تاریخ</td>
            <?php for ($i = 0; $i < $nCols - 2; $i++): ?><td class="h"></td><?php endfor; ?>
        </tr>
        <tr style="height:<?php echo $mm($rh[3]); ?>mm">
            <td class="h h-faa">فعالیت درسی</td>
            <?php for ($i = 0; $i < $nCols - 2; $i++): ?><td class="h" rowspan="2"></td><?php endfor; ?>
        </tr>
        <tr style="height:<?php echo $mm($rh[4]); ?>mm">
            <td class="h h-nam">نام خانوادگی</td>
            <td class="h h-nam">نام</td>
        </tr>
        <?php for ($r = 0; $r < $rows; $r++):
            $last = ''; $first = '';
            if (isset($names[$r])) {
                if (is_array($names[$r])) {
                    $last  = (string)($names[$r]['last'] ?? '');
                    $first = (string)($names[$r]['first'] ?? '');
                } else { $last = (string)$names[$r]; }
            }
            /* v4.151.0 — همهٔ ردیف‌های دانش‌آموز یک ارتفاع دارند.
               در قالب چند ردیف عدد trHeight دارند (۱۳۵، ۱۹۵، ۳۲۱…)
               ولی چون hRule="exact" ندارند، Word آن‌ها را فقط
               «حداقل ارتفاع» می‌گیرد و چون محتوا بلندتر است، همه در
               عمل یک‌اندازه رندر می‌شوند. من آن اعداد را ارتفاع قطعی
               گرفته بودم و ردیف‌ها ناهم‌اندازه می‌شدند. */
            $hs = 'height:calc(' . DCL_BODY_ROW_MM . 'mm * var(--rh))';
        ?>
        <tr>
            <td style="<?php echo $hs; ?>"><?php echo $r + 1; ?></td>
            <td class="nm" style="<?php echo $hs; ?>"><?php echo htmlspecialchars($last, ENT_QUOTES, 'UTF-8'); ?></td>
            <td class="nm" style="<?php echo $hs; ?>"><?php echo htmlspecialchars($first, ENT_QUOTES, 'UTF-8'); ?></td>
            <?php for ($i = 0; $i < $nCols - 3; $i++): ?><td style="<?php echo $hs; ?>"></td><?php endfor; ?>
        </tr>
        <?php endfor; ?>
    </table>
</div>

<div class="sheet">
    <?php /* صفحهٔ دوم قالب یک جدول ۷ستونی با دو بخش است:
          «ثبت میزان تدریس» و «دعوت از اولیا». */ ?>
    <table class="tbl2" style="width:<?php echo $tblPc2; ?>%">
        <colgroup>
            <?php foreach ($pc2 as $w): ?><col style="width:<?php echo $w; ?>%"><?php endforeach; ?>
        </colgroup>
        <tr><td class="t sec" colspan="7">جدول ثبت میزان تدریس</td></tr>
        <tr>
            <td class="h r2">جلسه</td>
            <td class="h r2">تاریخ</td>
            <td class="h r2" colspan="4">توضیحات</td>
            <td class="h r2">امضا</td>
        </tr>
        <?php for ($r = 1; $r <= 15; $r++): ?>
        <tr>
            <td class="r2"><?php echo $r; ?></td>
            <td class="r2"></td>
            <td class="r2" colspan="4"></td>
            <td class="r2"></td>
        </tr>
        <?php endfor; ?>

        <tr><td class="t sec" colspan="7">دعوت از اولیا</td></tr>
        <tr>
            <td class="h r2">ردیف</td>
            <td class="h r2" colspan="2">نام دانش آموز</td>
            <td class="h r2">تاریخ</td>
            <td class="h r2">علت دعوت</td>
            <td class="h r2" colspan="2">نتیجه</td>
        </tr>
        <?php for ($r = 1; $r <= 15; $r++): ?>
        <tr>
            <td class="r2"><?php echo $r; ?></td>
            <td class="r2" colspan="2"></td>
            <td class="r2"></td>
            <td class="r2"></td>
            <td class="r2" colspan="2"></td>
        </tr>
        <?php endfor; ?>
    </table>
</div>

<script>
/* ویرایشگر زنده. تنظیمات در همان مرورگر ذخیره می‌شود. همه‌چیز با
   متغیر CSS اعمال می‌شود تا روی صفحه و در چاپ یکسان باشد. */
(function(){
  var LS = 'mtag_classlist_editor_v1';
  var $ = function(id){ return document.getElementById(id); };
  function fa(n){ return String(n).replace(/[0-9]/g, function(d){ return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); }
  function get(){
    return { font:+$('cFont').value, row:+$('cRow').value,
             zebra:$('cZebra').checked, bold:$('cBold').checked };
  }
  function apply(){
    var d = get();
    document.documentElement.style.setProperty('--fs', d.font / 100);
    document.documentElement.style.setProperty('--rh', d.row / 100);
    document.body.classList.toggle('zebra', d.zebra);
    document.body.classList.toggle('nobold', !d.bold);
    $('vFont').textContent = fa(d.font) + '٪';
    $('vRow').textContent  = fa(d.row) + '٪';
    try { localStorage.setItem(LS, JSON.stringify(d)); } catch(e){}
  }
  window.resetEditor = function(){
    $('cFont').value = 100; $('cRow').value = 100;
    $('cZebra').checked = false; $('cBold').checked = true;
    apply();
  };
  ['cFont','cRow','cZebra','cBold'].forEach(function(id){
    var el = $(id); if (el) el.addEventListener('input', apply);
  });
  try {
    var sv = localStorage.getItem(LS);
    if (sv) { var d = JSON.parse(sv);
      if (d.font !== undefined) $('cFont').value = d.font;
      if (d.row  !== undefined) $('cRow').value  = d.row;
      if (d.zebra !== undefined) $('cZebra').checked = !!d.zebra;
      if (d.bold  !== undefined) $('cBold').checked  = !!d.bold; }
  } catch(e){}
  apply();
})();
</script>

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
