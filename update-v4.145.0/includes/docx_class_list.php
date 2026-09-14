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

        return $gradeNum . '/' . $classNum;
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
        $tbl = $tm[0];
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

        /* --- ردیف‌های ۵ به بعد: اسامی --- *
         * ستون ۰ شمارهٔ ردیف است و دست نمی‌خورد؛ ستون ۱ محل نام. */
        $idx = 0;
        for ($i = 5; $i < count($rows); $i++) {
            $row = $rows[$i];
            if (!preg_match_all('/<w:tc>.*?<\/w:tc>/s', $row, $cm2)) continue;
            if (count($cm2[0]) < 2) continue;

            $name = isset($names[$idx]) ? $names[$idx] : '';
            $idx++;
            if ($name === '') continue;   /* بقیهٔ سلول‌ها خالی می‌مانند */

            $cell = $cm2[0][1];
            $newCell = dcl_set_cell_text($cell, $name, false);
            $newRow = str_replace($cell, $newCell, $row);
            $newTbl = str_replace($row, $newRow, $newTbl);
        }

        return str_replace($tbl, $newTbl, $xml);
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

if (!function_exists('dcl_students_of_class')) {
    /**
     * اسامی یک کلاس، به ترتیب الفبای فارسی («آ» پیش از «ا»).
     * خروجی: «نام خانوادگی نام» — همان چیزی که سرستون می‌گوید.
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

        $out = [];
        foreach ($rows as $r) {
            $n = trim(trim((string)($r['last_name'] ?? '')) . ' ' . trim((string)($r['first_name'] ?? '')));
            if ($n !== '') $out[] = $n;
        }
        return $out;
    }
}
