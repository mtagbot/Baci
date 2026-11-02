<?php
// File: includes/report_image.php
/**
 * Server-side report-card and analytical chart image generator.
 * تولید تصویر کارنامه و نمودارهای تحلیلی سمت سرور با GD.
 */

require_once __DIR__ . '/functions.php';

if (!function_exists('report_image_font_path')) {
    function report_image_font_path($bold = false) {
        /* v4.93.0: امکان تعیین فونت دلخواه برای یک بخش خاص (مثلا سربرگ آزمون با B-Titr) */
        if (!empty($GLOBALS['gd_font_override']) && is_file($GLOBALS['gd_font_override'])) return $GLOBALS['gd_font_override'];
        $spec=app_font_spec($bold);
        if($spec['path'] && preg_match('/\.(ttf|otf)$/i',$spec['path']))return $spec['path'];
        throw new RuntimeException('برای خروجی تصویری، فایل TTF همان قلم انتخابی را در سفارشی‌سازی بارگذاری کنید؛ قلم دیگری جایگزین نشد.');
    }
}

if (!function_exists('rtl_shape_persian_for_gd')) {
    function rtl_shape_persian_for_gd($text) {
        // Lightweight Arabic/Persian reshaper for GD FreeType (which does not shape RTL scripts).
        $text = str_replace(['ي','ك','ۀ'], ['ی','ک','ه'], (string)$text);
        $forms = [
            'ا'=>['ﺍ','ﺎ','ﺍ','ﺎ',0],'آ'=>['ﺁ','ﺂ','ﺁ','ﺂ',0],'أ'=>['ﺃ','ﺄ','ﺃ','ﺄ',0],'إ'=>['ﺇ','ﺈ','ﺇ','ﺈ',0],
            'ب'=>['ﺏ','ﺐ','ﺑ','ﺒ',1],'پ'=>['ﭖ','ﭗ','ﭘ','ﭙ',1],'ت'=>['ﺕ','ﺖ','ﺗ','ﺘ',1],'ث'=>['ﺙ','ﺚ','ﺛ','ﺜ',1],
            'ج'=>['ﺝ','ﺞ','ﺟ','ﺠ',1],'چ'=>['ﭺ','ﭻ','ﭼ','ﭽ',1],'ح'=>['ﺡ','ﺢ','ﺣ','ﺤ',1],'خ'=>['ﺥ','ﺦ','ﺧ','ﺨ',1],
            'د'=>['ﺩ','ﺪ','ﺩ','ﺪ',0],'ذ'=>['ﺫ','ﺬ','ﺫ','ﺬ',0],'ر'=>['ﺭ','ﺮ','ﺭ','ﺮ',0],'ز'=>['ﺯ','ﺰ','ﺯ','ﺰ',0],
            'ژ'=>['ﮊ','ﮋ','ﮊ','ﮋ',0],'س'=>['ﺱ','ﺲ','ﺳ','ﺴ',1],'ش'=>['ﺵ','ﺶ','ﺷ','ﺸ',1],'ص'=>['ﺹ','ﺺ','ﺻ','ﺼ',1],
            'ض'=>['ﺽ','ﺾ','ﺿ','ﻀ',1],'ط'=>['ﻁ','ﻂ','ﻃ','ﻄ',1],'ظ'=>['ﻅ','ﻆ','ﻇ','ﻈ',1],'ع'=>['ﻉ','ﻊ','ﻋ','ﻌ',1],
            'غ'=>['ﻍ','ﻎ','ﻏ','ﻐ',1],'ف'=>['ﻑ','ﻒ','ﻓ','ﻔ',1],'ق'=>['ﻕ','ﻖ','ﻗ','ﻘ',1],'ک'=>['ﮎ','ﮏ','ﮐ','ﮑ',1],
            'گ'=>['ﮒ','ﮓ','ﮔ','ﮕ',1],'ل'=>['ﻝ','ﻞ','ﻟ','ﻠ',1],'م'=>['ﻡ','ﻢ','ﻣ','ﻤ',1],'ن'=>['ﻥ','ﻦ','ﻧ','ﻨ',1],
            'و'=>['ﻭ','ﻮ','ﻭ','ﻮ',0],'ؤ'=>['ﺅ','ﺆ','ﺅ','ﺆ',0],'ه'=>['ﻩ','ﻪ','ﻫ','ﻬ',1],'ة'=>['ﺓ','ﺔ','ﺓ','ﺔ',0],
            'ی'=>['ﯼ','ﯽ','ﯾ','ﯿ',1],'ئ'=>['ﺉ','ﺊ','ﺋ','ﺌ',1],
        ];
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        $prevJoin = false;
        $count = count($chars);
        for ($i=0;$i<$count;$i++) {
            $ch = $chars[$i];
            // Keep number/date/time runs LTR as one token so 18.00 never becomes 00.81.
            if (preg_match('/[0-9۰-۹]/u', $ch)) {
                $num = $ch;
                while ($i + 1 < $count && preg_match('/[0-9۰-۹\.\/\:\-]/u', $chars[$i+1])) {
                    $num .= $chars[++$i];
                }
                $out[] = $num;
                $prevJoin = false;
                continue;
            }
            // v4.88.0: bidi mirroring — brackets must flip when the line is reversed,
            // otherwise «الف)» prints as «الف(» in shaped GD output.
            static $mirror = ['('=>')', ')'=>'(', '['=>']', ']'=>'[', '{'=>'}', '}'=>'{', '<'=>'>', '>'=>'<', '«'=>'»', '»'=>'«'];
            if (isset($mirror[$ch])) { $out[] = $mirror[$ch]; $prevJoin = false; continue; }
            if (!isset($forms[$ch])) { $out[] = $ch; $prevJoin = false; continue; }
            $next = $chars[$i+1] ?? '';
            $canPrev = $prevJoin;
            $canNext = isset($forms[$next]) && ($forms[$ch][4] === 1);
            $idx = ($canPrev && $canNext) ? 3 : (($canPrev) ? 1 : (($canNext) ? 2 : 0));
            $out[] = $forms[$ch][$idx];
            $prevJoin = ($forms[$ch][4] === 1);
        }
        return implode('', array_reverse($out));
    }
}

if (!function_exists('gd_text')) {
    function gd_text($im, $text, $x, $y, $size, $color, $bold = false, $align = 'right') {
        $text = tr_num((string)$text, 'fa');
        // Shape/reverse only real Persian/Arabic letters. Persian digits are also in the 06xx block,
        // but they must remain left-to-right inside numbers (18.00 must not become 00.81).
        if (preg_match('/[آاأإبپتثجچحخدذرزژسشصضطظعغفقکگلمنوهیئؤة]/u', $text)) {
            $text = rtl_shape_persian_for_gd($text);
        }
        $font = report_image_font_path($bold);
        if ($font && function_exists('imagettftext')) {
            $box = @imagettfbbox($size, 0, $font, $text);
            if(!$box)throw new RuntimeException('خواندن قلم انتخابی برای تصویر ممکن نیست.');
            $w = abs($box[2] - $box[0]);
            if ($align === 'right') $x -= $w;
            if ($align === 'center') $x -= (int)($w / 2);
            if(@imagettftext($im, $size, 0, (int)$x, (int)$y, $color, $font, $text)===false)throw new RuntimeException('رسم متن با قلم انتخابی انجام نشد.');
        } else {
            throw new RuntimeException('پشتیبانی FreeType در افزونه GD لازم است؛ قلم دیگری جایگزین نشد.');
        }
    }
}

if (!function_exists('report_image_safe_filename')) {
    function report_image_safe_filename($prefix, $id) {
        $dir = dirname(__DIR__) . '/uploads/generated-reports';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        // Auto-clean generated report images older than 24 hours to save disk space.
        foreach (glob($dir . '/*.png') ?: [] as $old) {
            if (is_file($old) && filemtime($old) < time() - 300) @unlink($old);
        }
        return $dir . '/' . $prefix . '_' . (int)$id . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.png';
    }
}

if (!function_exists('get_report_analysis_data')) {
    function get_report_analysis_data($reportId) {
        $report = DB::fetch("SELECT r.*, s.first_name, s.last_name, s.national_id, s.grade_level, s.class_name AS student_class, s.photo_url FROM reports r JOIN students s ON s.id = r.student_id WHERE r.id = ?", [$reportId]);
        if (!$report) return null;
        $grades = DB::fetchAll("SELECT subject_name, score, max_score FROM report_grades WHERE report_id = ? ORDER BY id ASC", [$reportId]);
        $parsed = extract_clean_grades_and_discipline($grades, $report['discipline_score']);
        $report['gpa'] = $parsed['count'] ? $parsed['calculated_gpa'] : (float)$report['gpa'];
        $report['discipline_score'] = $parsed['discipline_score'];
        $className = $report['class_name'] ?: $report['student_class'];
        $report['class_name'] = $className;
        $report['grade_level'] = report_student_grade_level($report);
        $years = report_year_variants($report['academic_year'] ?? '');
        $yMarks = implode(',', array_fill(0, count($years), '?'));

        $classReports = fetch_report_rank_scope($report, 'class');
        $classRankVal = 1;
        $gpaSum = 0.0;
        foreach ($classReports as $ci => $cr) {
            $gpaSum += (float)$cr['gpa'];
            if ((int)$cr['student_id'] === (int)$report['student_id']) $classRankVal = $ci + 1;
        }
        $classCount = count($classReports);
        $classAvgVal = $classCount > 0 ? round($gpaSum / $classCount, 2) : null;

        $gradeReports = fetch_report_rank_scope($report, 'grade');
        $gradeRankVal = 1;
        foreach ($gradeReports as $gi => $gr) {
            if ((int)$gr['student_id'] === (int)$report['student_id']) { $gradeRankVal = $gi + 1; break; }
        }

        /* V4.209.4 — نمودار روند فقط ماه‌های آزاد را نشان می‌دهد؛ قفل گروهی هم مثل
           قفل فردی ماه را از نمودار بیرون می‌گذارد (قبلاً فقط ستون is_locked دیده می‌شد). */
        $progress = [];
        foreach (DB::fetchAll("SELECT id, student_id, class_name, report_month, term, gpa, academic_year FROM reports WHERE student_id = ? AND academic_year IN ($yMarks) ORDER BY id ASC LIMIT 12", array_merge([(int)$report['student_id']], $years)) ?: [] as $pr) {
            if (is_report_locked_for_student($pr)) continue;
            $progress[] = $pr;
        }
        $subjectAvgs = []; $subjectRanks = [];
        foreach ($parsed['regular_grades'] as $g) {
            $sClassList = fetch_subject_rank_scope($report, (string)$g['subject_name'], 'class');
            $sGradeList = fetch_subject_rank_scope($report, (string)$g['subject_name'], 'grade');
            $sSum = 0.0;
            $sClsRnk = 1;
            foreach ($sClassList as $si => $scr) {
                $sSum += (float)$scr['score'];
                if ((float)$g['score'] != 21.0 && ((int)$scr['student_id'] === (int)$report['student_id'] || (float)$scr['score'] <= (float)$g['score'])) {
                    if ($sClsRnk === 1 && $si > 0) $sClsRnk = $si + 1;
                }
            }
            if ((float)$g['score'] != 21.0) {
                $sClsRnk = 1;
                foreach ($sClassList as $si => $scr) {
                    if ((int)$scr['student_id'] === (int)$report['student_id'] || (float)$scr['score'] <= (float)$g['score']) { $sClsRnk = $si + 1; break; }
                }
            }
            $sGrdRnk = 1;
            if ((float)$g['score'] != 21.0) {
                foreach ($sGradeList as $si => $sgr) {
                    if ((int)$sgr['student_id'] === (int)$report['student_id'] || (float)$sgr['score'] <= (float)$g['score']) { $sGrdRnk = $si + 1; break; }
                }
            }
            $subjectAvgs[$g['subject_name']] = count($sClassList) > 0 ? round($sSum / count($sClassList), 2) : null;
            $subjectRanks[$g['subject_name']] = ['class' => $sClsRnk, 'grade' => $sGrdRnk];
        }
        return [
            'report' => $report,
            'grades' => $parsed['regular_grades'],
            'class_avg' => $classAvgVal,
            'class_count' => $classCount,
            'rank' => $classRankVal,
            'grade_rank' => $gradeRankVal,
            'progress' => $progress,
            'subject_avgs' => $subjectAvgs,
            'subject_ranks' => $subjectRanks
        ];
    }
}

if (!function_exists('report_image_place_photo')) {
    function report_image_place_photo($im, $path, $x, $y, $w, $h) {
        $full = dirname(__DIR__) . '/' . ltrim((string)$path, '/');
        if (!is_file($full)) return false;
        $info = @getimagesize($full); if (!$info) return false;
        $src = null;
        if ($info[2] === IMAGETYPE_JPEG) $src=@imagecreatefromjpeg($full);
        elseif ($info[2] === IMAGETYPE_PNG) $src=@imagecreatefrompng($full);
        elseif ($info[2] === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) $src=@imagecreatefromwebp($full);
        if (!$src) return false;
        imagecopyresampled($im,$src,$x,$y,0,0,$w,$h,imagesx($src),imagesy($src)); imagedestroy($src); return true;
    }
}

/* ══════════════════════════════════════════════════════════════════════
 * V4.214.0 — رنگ وضعیت نمره در کارنامهٔ تصویری ربات (و PDF همان تصویر)
 * ----------------------------------------------------------------------
 * مدیر خواست کارنامه‌ای که ربات می‌فرستد هم مثل سایت وضعیت هر نمره را
 * نشان دهد: خودِ نمره مشکی، و برچسبِ وضعیت با طیف رنگ از «بحرانی» تا
 * «عالی». پله‌ها و رنگ‌ها از report_grade_status_bands() در functions.php
 * خوانده می‌شوند — همان منبعی که کارنامهٔ سایت و نسخهٔ چاپی استفاده
 * می‌کنند؛ پس سه جا سه رنگ و سه متن نمی‌سازیم.
 *
 * نسخهٔ PDF ربات از همین تصویر ساخته می‌شود
 * (bot_generate_report_pdf → generate_report_card_image)، بنابراین هر
 * تغییری اینجا خودبه‌خود در PDF هم دیده می‌شود.
 * ══════════════════════════════════════════════════════════════════════ */

if (!function_exists('report_image_grade_style')) {
    /**
     * @return array{label:string,score_color:string,dot:string,bg:string,border:string,text:string}
     */
    function report_image_grade_style($score, $inkHex = '#0f172a') {
        if (report_grade_is_absence($score)) {
            return ['label' => 'غیبت', 'score_color' => $inkHex,
                    'dot' => '#64748b', 'bg' => '#f8fafc', 'border' => '#cbd5e1', 'text' => '#334155'];
        }
        $band = report_grade_status($score);
        if (!$band) {
            return ['label' => 'ثبت نشده', 'score_color' => $inkHex,
                    'dot' => '#94a3b8', 'bg' => '#f8fafc', 'border' => '#e2e8f0', 'text' => '#64748b'];
        }
        return ['label' => $band['label'], 'score_color' => $inkHex,
                'dot' => $band['dot'], 'bg' => $band['bg'], 'border' => $band['border'], 'text' => $band['text']];
    }
}

if (!function_exists('report_image_hex')) {
    /** #rrggbb → شناسهٔ رنگ GD. تصویر truecolor است، پس عدد رنگ بین تصویرها قابل استفاده است. */
    function report_image_hex($im, $hex) {
        static $cache = [];
        $hex = strtolower(preg_replace('/[^0-9a-f]/i', '', (string)$hex));
        if (strlen($hex) !== 6) $hex = '64748b';
        if (isset($cache[$hex])) return $cache[$hex];
        return $cache[$hex] = imagecolorallocate($im,
            hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }
}

if (!function_exists('gd_text_measure')) {
    /** پهنای متن با همان تبدیل‌های gd_text (فارسی‌سازی ارقام + شکل‌دهی) — برای اندازه‌گیری قرص. */
    function gd_text_measure($text, $size, $bold = true) {
        $text = tr_num((string)$text, 'fa');
        if (preg_match('/[آاأإبپتثجچحخدذرزژسشصضطظعغفقکگلمنوهیئؤة]/u', $text)) {
            $text = rtl_shape_persian_for_gd($text);
        }
        $font = report_image_font_path($bold);
        $box = @imagettfbbox($size, 0, $font, $text);
        return $box ? abs($box[2] - $box[0]) : (mb_strlen($text) * (int)($size * 0.6));
    }
}

if (!function_exists('gd_round_rect')) {
    function gd_round_rect($im, $x1, $y1, $x2, $y2, $r, $color) {
        $r = (int)max(0, min($r, (int)(($x2 - $x1) / 2), (int)(($y2 - $y1) / 2)));
        imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        if ($r > 0) {
            imagefilledellipse($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
            imagefilledellipse($im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
            imagefilledellipse($im, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
            imagefilledellipse($im, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
        }
    }
}

if (!function_exists('gd_status_pill')) {
    /**
     * قرص رنگیِ وضعیت: نقطهٔ رنگی + برچسب. اگر برچسب در ستون جا نشود،
     * قلم کوچک‌تر می‌شود، نه اینکه از ستون بزند بیرون.
     */
    function gd_status_pill($im, $cx, $cy, $text, $style, $maxW = 170) {
        $size = 17;
        foreach ([17, 15, 13] as $try) {
            $size = $try;
            if (gd_text_measure($text, $try, true) + 28 <= $maxW) break;
        }
        $tw = gd_text_measure($text, $size, true);
        $pw = (int)min($maxW, $tw + 28);
        $ph = 44;
        $x1 = (int)round($cx - $pw / 2); $x2 = $x1 + $pw;
        $y1 = (int)round($cy - $ph / 2); $y2 = $y1 + $ph;
        gd_round_rect($im, $x1, $y1, $x2, $y2, 14, report_image_hex($im, $style['border']));
        gd_round_rect($im, $x1 + 2, $y1 + 2, $x2 - 2, $y2 - 2, 12, report_image_hex($im, $style['bg']));
        /* V4.214.1 — بدون نقطهٔ دایره‌ای: فقط متن وضعیت، وسط قرص. */
        gd_text($im, $text, (int)round($cx), (int)round($cy) + 7, $size, report_image_hex($im, $style['text']), true, 'center');
        return ['x1' => $x1, 'x2' => $x2, 'w' => $pw, 'size' => $size];
    }
}

if (!function_exists('generate_report_card_image')) {
    function generate_report_card_image($reportId) {
        if (!extension_loaded('gd')) throw new RuntimeException('افزونه GD روی سرور فعال نیست.');
        $data = get_report_analysis_data($reportId); if (!$data) throw new RuntimeException('کارنامه یافت نشد.');
        $r=$data['report']; $grades=$data['grades']; $w=1600; $h=max(2250, 900+count($grades)*78+560);
        $im=imagecreatetruecolor($w,$h); imagealphablending($im,true); imagesavealpha($im,true);
        $white=imagecolorallocate($im,255,255,255); $ink=imagecolorallocate($im,15,23,42); $muted=imagecolorallocate($im,100,116,139); $primary=imagecolorallocate($im,37,99,235); $gold=imagecolorallocate($im,217,119,6); $green=imagecolorallocate($im,16,185,129); $red=imagecolorallocate($im,220,38,38); $line=imagecolorallocate($im,226,232,240); $soft=imagecolorallocate($im,248,250,252); $blueSoft=imagecolorallocate($im,239,246,255);
        $palette=app_palette();$alloc=function($hex)use($im){return imagecolorallocate($im,hexdec(substr($hex,1,2)),hexdec(substr($hex,3,2)),hexdec(substr($hex,5,2)));};
        $primary=$alloc($palette['primary']);$gold=$alloc($palette['accent']);$green=$alloc($palette['success']);$red=$alloc($palette['danger']);
        imagefilledrectangle($im,0,0,$w,$h,$soft); imagefilledrectangle($im,45,45,$w-45,$h-45,$white); imagerectangle($im,45,45,$w-45,$h-45,$line);
        imagefilledrectangle($im,45,45,$w-45,185,$blueSoft);
        gd_text($im,get_setting('report_header_line1','بسمه تعالی'),$w/2,92,24,$primary,true,'center');
        gd_text($im,get_setting('report_header_line2',get_setting('school_name','آموزشگاه')),$w/2,135,20,$ink,true,'center');
        gd_text($im,'کارنامه تحصیلی و تحلیل عملکرد',$w-80,170,15,$gold,true,'right');
        $photoX=82; $photoY=205; /* v4.91.0: نسبت ۳×۴ */ imagefilledrectangle($im,$photoX,$photoY,$photoX+120,$photoY+160,$soft); imagerectangle($im,$photoX,$photoY,$photoX+120,$photoY+160,$primary); if(!report_image_place_photo($im,$r['photo_url']??'',$photoX+2,$photoY+2,116,156)) gd_text($im,'عکس',$photoX+60,$photoY+82,15,$muted,false,'center');
        $y=225; gd_text($im,'نام دانش‌آموز: '.$r['first_name'].' '.$r['last_name'],$w-90,$y,20,$ink,true); gd_text($im,'کد ملی: '.$r['national_id'],$w-90,$y+38,17,$muted); gd_text($im,'پایه: '.($r['grade_level']?:'---'),$w-90,$y+76,17,$muted); gd_text($im,'کلاس: '.($r['class_name']?:$r['student_class']),$w-90,$y+114,17,$muted); gd_text($im,'سال تحصیلی: '.$r['academic_year'],720,$y+38,17,$muted); gd_text($im,'نوبت/ماه: '.$r['term'].' - '.$r['report_month'],720,$y+76,17,$muted);
        /* V4.214.0 — کارت معدل هم وضعیت کل دانش‌آموز را با همان طیف رنگ نشان می‌دهد. */
        $gpaStyle = report_image_grade_style($r['gpa'] ?? null);
        $cardY=380; imagefilledrectangle($im,80,$cardY,260,$cardY+92,report_image_hex($im,$gpaStyle['bg'])); imagerectangle($im,80,$cardY,260,$cardY+92,report_image_hex($im,$gpaStyle['dot'])); gd_text($im,'معدل کل',170,$cardY+24,15,$muted,true,'center'); gd_text($im,format_score($r['gpa']),170,$cardY+56,26,report_image_hex($im,$gpaStyle['text']),true,'center'); gd_text($im,$gpaStyle['label'],170,$cardY+80,13,report_image_hex($im,$gpaStyle['text']),true,'center');
        imagefilledrectangle($im,280,$cardY,460,$cardY+92,imagecolorallocate($im,255,251,235)); imagerectangle($im,280,$cardY,460,$cardY+92,$gold); gd_text($im,'انضباط',370,$cardY+30,17,$gold,true,'center'); gd_text($im,($r['discipline_score']!==null && $r['discipline_score']!=='')?format_score($r['discipline_score']):'---',370,$cardY+70,28,$gold,true,'center');
        $y=505; imagefilledrectangle($im,80,$y,$w-80,$y+64,$primary); gd_text($im,'درس',$w-95,$y+42,20,$white,true); gd_text($im,'نمره',700,$y+42,20,$white,true,'center'); gd_text($im,'میانگین کلاس',560,$y+42,18,$white,true,'center'); gd_text($im,'رتبه کلاس',430,$y+42,18,$white,true,'center'); gd_text($im,'رتبه پایه',320,$y+42,18,$white,true,'center'); gd_text($im,'وضعیت',165,$y+42,18,$white,true,'center'); $y+=64;
        foreach($grades as $i=>$g){
            $bg=$i%2?$soft:$white; imagefilledrectangle($im,80,$y,$w-80,$y+74,$bg); imageline($im,80,$y+74,$w-80,$y+74,$line);
            $avg=$data['subject_avgs'][$g['subject_name']]??null; $rank=$data['subject_ranks'][$g['subject_name']]??['class'=>0,'grade'=>0];
            /* V4.214.0 — نمره همیشه مشکی است؛ تنها برچسبِ وضعیت رنگ طیف را می‌گیرد. */
            $gStyle = report_image_grade_style($g['score'] ?? null);
            gd_text($im,$g['subject_name'],$w-95,$y+48,19,$ink,true);
            gd_text($im,display_score($g['score'] ?? null),700,$y+48,22,$ink,true,'center');
            gd_text($im,$avg!==null?format_score($avg):'---',560,$y+48,18,$muted,false,'center');
            gd_text($im,$rank['class']?:'---',430,$y+48,18,$ink,false,'center');
            gd_text($im,$rank['grade']?:'---',320,$y+48,18,$ink,false,'center');
            gd_status_pill($im,165,$y+37,$gStyle['label'],$gStyle,170);
            $y+=74;
        }
        $y+=35; imagefilledrectangle($im,80,$y,$w-80,$y+340,imagecolorallocate($im,249,250,251)); imagerectangle($im,80,$y,$w-80,$y+340,$line); gd_text($im,'تحلیل نموداری عملکرد',$w-115,$y+46,24,$primary,true); gd_text($im,'رتبه در کلاس: '.($data['rank']?:'---').' از '.($data['class_count']?:'---'),$w-115,$y+98,21,$ink,true); gd_text($im,'رتبه در پایه: '.(($data['grade_rank']??0)?:'---'),$w-115,$y+142,20,$ink,true); gd_text($im,'میانگین کلاس: '.($data['class_avg']!==null?format_score($data['class_avg']):'---'),$w-115,$y+186,21,$muted);
        $barX=650; $barY=$y+88; $barW=300; $barH=28; gd_text($im,'مقایسه معدل',800,$y+52,20,$ink,true,'center'); imagefilledrectangle($im,$barX,$barY,$barX+$barW,$barY+$barH,$line); imagefilledrectangle($im,$barX,$barY,$barX+(int)($barW*min(20,max(0,$r['gpa']))/20),$barY+$barH,$green); gd_text($im,'دانش‌آموز',$barX-15,$barY+22,15,$muted,false,'right'); $barY+=50; imagefilledrectangle($im,$barX,$barY,$barX+$barW,$barY+$barH,$line); imagefilledrectangle($im,$barX,$barY,$barX+(int)($barW*min(20,max(0,(float)$data['class_avg']))/20),$barY+$barH,$gold); gd_text($im,'کلاس',$barX-15,$barY+22,15,$muted,false,'right');
        $chartX=130; $chartY=$y+78; $chartW=410; $chartH=180; gd_text($im,'روند پیشرفت معدل',$chartX+$chartW/2,$y+52,20,$ink,true,'center'); imagerectangle($im,$chartX,$chartY,$chartX+$chartW,$chartY+$chartH,$line); for($i=1;$i<4;$i++) imageline($im,$chartX,$chartY+$i*45,$chartX+$chartW,$chartY+$i*45,$line); $prog=$data['progress']; $prev=null; $n=max(1,count($prog)-1); foreach($prog as $i=>$p){$px=$chartX+(int)(($chartW-30)*$i/$n)+15; $py=$chartY+$chartH-(int)(min(20,max(0,(float)$p['gpa']))/20*($chartH-20))-10; imagefilledellipse($im,$px,$py,10,10,$primary); if($prev) imageline($im,$prev[0],$prev[1],$px,$py,$primary); $prev=[$px,$py]; gd_text($im,$p['report_month']?:$p['term'],$px,$chartY+$chartH+32,13,$muted,false,'center');}
        if(!empty($r['teacher_comments'])){ $y+=375; gd_text($im,'نظر مشاور / پشتیبان: '.$r['teacher_comments'],$w-90,$y,20,$ink,false); }
        gd_text($im,str_replace('{date}',jdate('Y/m/d H:i'),get_setting('report_image_footer_text','تولید شده توسط سامانه مدیریت کارنامه - {date}')),$w/2,$h-75,16,$muted,false,'center');
        $file=report_image_safe_filename('report_card',$reportId); imagepng($im,$file,2); imagedestroy($im); return $file;
    }
}
