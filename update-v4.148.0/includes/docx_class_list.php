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

if (!function_exists('dcl_render_print_html')) {
    /**
     * نمای چاپی لیست کلاسی — بازسازی دقیق همان دو صفحهٔ قالب Word.
     *
     * v4.148.0 — بازنویسی شد. نسخهٔ قبل «شبیه» قالب بود نه یکی با آن:
     * عنوان‌هایی مثل «جمع غیبت» و «نمره مستمر» داشت که در قالب مدرسه
     * اصلاً وجود ندارند، و colspan های سربرگ با ساختار واقعی نمی‌خواند.
     * حالا از روی همان شبکهٔ عرض و همان ادغام‌های قالب ساخته می‌شود.
     *
     * چرا PDF با مرورگر: TCPDF در بسته نیست و برای فارسی به فونت
     * تبدیل‌شده نیاز دارد که هر خطا در آن، به‌جای فونت تیتر مربع خالی
     * چاپ می‌کند. مرورگر همان B-Titr.ttf را می‌خواند.
     */
    function dcl_render_print_html($classCode, array $names, $schoolName = '', $autoPrint = true) {
        $fontUrl = 'uploads/B-Titr/B-Titr.ttf';
        $grid    = dcl_print_grid();
        $total   = array_sum($grid);
        $rows    = 30;
        $nCols   = count($grid);          /* ۱۶ */
        $nSess   = $nCols - 1 - 2 - 5;    /* ۹ ستون جلسه */

        /* درصد هر ستون، تا نسبت‌ها با Word یکی بماند */
        $pc = [];
        foreach ($grid as $w) $pc[] = round($w * 100 / $total, 4);

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
body,table,td,th{font-family:'BTitr',Tahoma,sans-serif}
.sheet{width:277mm;min-height:190mm;background:#fff;margin:0 auto 8mm auto;padding:5mm 6mm;
       box-shadow:0 2px 12px rgba(15,23,42,.16);page-break-after:always;break-after:page}
.sheet:last-of-type{page-break-after:auto;break-after:auto;margin-bottom:0}
table{width:100%;border-collapse:collapse;table-layout:fixed}
td{border:0.35mm solid #000;text-align:center;vertical-align:middle;
   font-size:2.7mm;line-height:1.2;padding:0 0.5mm;overflow:hidden}
.t{font-size:3.3mm;font-weight:700;padding:1.3mm 1mm}
.h{font-size:2.5mm;font-weight:700}
.r{height:6.3mm}
.nm{font-size:2.6mm;white-space:nowrap;font-weight:700}
@page{size:A4 landscape;margin:5mm}
@media print{
  html,body{background:#fff}
  .sheet{box-shadow:none;margin:0 auto;padding:0}
  .noprint{display:none!important}
}
.noprint{max-width:277mm;margin:10px auto;font-family:Tahoma;display:flex;gap:8px;
         align-items:center;flex-wrap:wrap}
.noprint button{padding:9px 20px;border:0;border-radius:8px;background:#2563eb;color:#fff;
                font-size:13px;cursor:pointer;font-family:inherit}
.noprint .hint{font-size:12px;color:#334155;background:#fff;padding:8px 12px;border-radius:8px}
.noprint .ctl{display:flex;align-items:center;gap:6px;background:#fff;padding:7px 11px;
              border-radius:8px;font-size:12px;color:#0f172a}
.noprint .ctl input[type=range]{width:90px}
.noprint .ghost{background:#fff;color:#334155;border:1px solid #cbd5e1}
/* متغیرهای ویرایشگر — روی خودِ برگه اثر می‌کنند */
:root{--fs:1;--rh:1}
td{font-size:calc(2.7mm * var(--fs))}
.t{font-size:calc(3.3mm * var(--fs))}
.h{font-size:calc(2.5mm * var(--fs))}
.nm{font-size:calc(2.6mm * var(--fs))}
.r{height:calc(6.3mm * var(--rh))}
body.zebra tbody tr:nth-child(even) td{background:#f6f8fb}
body.nobold .nm{font-weight:400}
</style>
</head>
<body>
<?php /* v4.148.0 — ویرایشگر زندهٔ پیش از چاپ.
      کارفرما خواست پیش از چاپ همه‌چیز بررسی شود، پس چاپ خودکار
      برداشته شد و این نوار اضافه شد. هر تغییری همان لحظه روی خودِ
      برگه اعمال می‌شود — چیزی که می‌بینید همان است که چاپ می‌شود. */ ?>
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

    <span class="hint">
        در پنجرهٔ چاپ: مقصد «Save as PDF»، کاغذ A4 افقی، و گزینهٔ
        Background graphics روشن.
    </span>
</div>

<div class="sheet">
    <table>
        <colgroup>
            <?php foreach ($pc as $w): ?><col style="width:<?php echo $w; ?>%"><?php endforeach; ?>
        </colgroup>
        <?php /* r0 — سربرگ: عنوان بلند + سلول کلاس، مثل span13/span3 قالب */ ?>
        <tr>
            <td class="t" colspan="<?php echo $nCols - 3; ?>">جدول ثبت گزارش تدریس و نمرات ارزشیابی مستمر و حضور و غیاب دانش آموزان</td>
            <td class="t" colspan="3">کلاس : <?php echo htmlspecialchars($classCode, ENT_QUOTES, 'UTF-8'); ?></td>
        </tr>
        <?php /* r1..r4 — ستون «ردیف» روی چهار ردیف ادغام شده */ ?>
        <tr>
            <td class="h r" rowspan="4">ردیف</td>
            <td class="h">جلسات</td>
            <?php for ($i = 0; $i < $nCols - 2; $i++): ?><td class="h"></td><?php endfor; ?>
        </tr>
        <tr>
            <td class="h">تاریخ</td>
            <?php for ($i = 0; $i < $nCols - 2; $i++): ?><td class="h"></td><?php endfor; ?>
        </tr>
        <tr>
            <td class="h">فعالیت درسی</td>
            <?php /* ستون‌های بعدی در قالب از اینجا تا ردیف بعد ادغام عمودی‌اند */ ?>
            <?php for ($i = 0; $i < $nCols - 2; $i++): ?><td class="h" rowspan="2"></td><?php endfor; ?>
        </tr>
        <tr>
            <td class="h">نام خانوادگی</td>
            <td class="h">نام</td>
        </tr>
        <?php for ($r = 0; $r < $rows; $r++):
            $last  = '';
            $first = '';
            if (isset($names[$r])) {
                if (is_array($names[$r])) {
                    $last  = (string)($names[$r]['last'] ?? '');
                    $first = (string)($names[$r]['first'] ?? '');
                } else {
                    $last = (string)$names[$r];
                }
            }
        ?>
        <tr>
            <td class="r"><?php echo $r + 1; ?></td>
            <td class="r nm"><?php echo htmlspecialchars($last, ENT_QUOTES, 'UTF-8'); ?></td>
            <td class="r nm"><?php echo htmlspecialchars($first, ENT_QUOTES, 'UTF-8'); ?></td>
            <?php for ($i = 0; $i < $nCols - 3; $i++): ?><td class="r"></td><?php endfor; ?>
        </tr>
        <?php endfor; ?>
    </table>
</div>

<div class="sheet">
    <table>
        <colgroup>
            <col style="width:10%"><col style="width:22%"><col style="width:46%"><col style="width:22%">
        </colgroup>
        <tr><td class="t" colspan="4">جدول ثبت میزان تدریس</td></tr>
        <tr>
            <td class="h r">جلسه</td><td class="h r">تاریخ</td>
            <td class="h r">توضیحات</td><td class="h r">امضا</td>
        </tr>
        <?php for ($r = 1; $r <= 32; $r++): ?>
        <tr>
            <td class="r"><?php echo $r; ?></td>
            <td class="r"></td><td class="r"></td><td class="r"></td>
        </tr>
        <?php endfor; ?>
    </table>
</div>

<script>
/* v4.148.0 — ویرایشگر زنده. تنظیمات در همان مرورگر ذخیره می‌شود، پس
   دفعهٔ بعد یادش می‌ماند. همه‌چیز با متغیر CSS اعمال می‌شود تا هم
   روی صفحه و هم در خروجی چاپ یکسان باشد. */
(function(){
  var LS = 'mtag_classlist_editor_v1';
  var $ = function(id){ return document.getElementById(id); };
  function fa(n){ return String(n).replace(/[0-9]/g, function(d){ return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); }

  function get(){
    return {
      font:  +$('cFont').value,
      row:   +$('cRow').value,
      zebra: $('cZebra').checked,
      bold:  $('cBold').checked
    };
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
    var el = $(id);
    if (el) el.addEventListener('input', apply);
  });
  try {
    var sv = localStorage.getItem(LS);
    if (sv) {
      var d = JSON.parse(sv);
      if (d.font !== undefined) $('cFont').value = d.font;
      if (d.row  !== undefined) $('cRow').value  = d.row;
      if (d.zebra !== undefined) $('cZebra').checked = !!d.zebra;
      if (d.bold  !== undefined) $('cBold').checked  = !!d.bold;
    }
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
