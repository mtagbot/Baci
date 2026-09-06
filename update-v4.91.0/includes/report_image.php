<?php
// File: includes/report_image.php
/**
 * Server-side report-card and analytical chart image generator.
 * تولید تصویر کارنامه و نمودارهای تحلیلی سمت سرور با GD.
 */

require_once __DIR__ . '/functions.php';

if (!function_exists('report_image_font_path')) {
    function report_image_font_path($bold = false) {
        $root = dirname(__DIR__);
        $candidates = $bold ? [
            $root . '/uploads/Vazirmatn/Vazirmatn-Bold.ttf',
            $root . '/uploads/Vazirmatn/Vazirmatn-Bold.woff2',
            $root . '/uploads/Sahel/Sahel-Bold.ttf',
            $root . '/uploads/Yekan/Yekan.ttf',
        ] : [
            $root . '/uploads/Vazirmatn/Vazirmatn-Regular.ttf',
            $root . '/uploads/Vazirmatn/Vazirmatn[wght].ttf',
            $root . '/uploads/Sahel/Sahel.ttf',
            $root . '/uploads/Yekan/Yekan.ttf',
        ];
        foreach ($candidates as $p) if (is_file($p)) return $p;
        return null;
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
            $w = $box ? abs($box[2] - $box[0]) : 0;
            if ($align === 'right') $x -= $w;
            if ($align === 'center') $x -= (int)($w / 2);
            @imagettftext($im, $size, 0, (int)$x, (int)$y, $color, $font, $text);
        } else {
            $fontId = $bold ? 5 : 3; $w = imagefontwidth($fontId) * mb_strlen($text, 'UTF-8');
            if ($align === 'right') $x -= $w; if ($align === 'center') $x -= (int)($w / 2);
            imagestring($im, $fontId, (int)$x, (int)$y - 14, $text, $color);
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
        $classAvg = DB::fetch("SELECT AVG(gpa) AS avg_gpa, COUNT(*) AS cnt FROM reports WHERE class_name = ? AND academic_year = ? AND term = ? AND report_month = ? AND is_locked = 0", [$className, $report['academic_year'], $report['term'], $report['report_month']]);
        $rankRow = DB::fetch("SELECT COUNT(*) + 1 AS rank_calc FROM reports WHERE class_name = ? AND academic_year = ? AND term = ? AND report_month = ? AND is_locked = 0 AND gpa > ?", [$className, $report['academic_year'], $report['term'], $report['report_month'], $report['gpa']]);
        $progress = DB::fetchAll("SELECT report_month, term, gpa FROM reports WHERE student_id = ? AND is_locked = 0 ORDER BY id ASC LIMIT 12", [$report['student_id']]);
        $subjectAvgs = []; $subjectRanks = [];
        foreach ($parsed['regular_grades'] as $g) {
            $row = DB::fetch("SELECT AVG(rg.score) AS avg_score FROM report_grades rg JOIN reports r ON r.id = rg.report_id WHERE r.class_name = ? AND r.academic_year = ? AND r.term = ? AND r.report_month = ? AND rg.subject_name = ? AND r.is_locked = 0", [$className, $report['academic_year'], $report['term'], $report['report_month'], $g['subject_name']]);
            $subjectAvgs[$g['subject_name']] = $row && $row['avg_score'] !== null ? round((float)$row['avg_score'], 2) : null;
            $classRank = DB::fetch("SELECT COUNT(*)+1 rnk FROM report_grades rg JOIN reports r ON r.id=rg.report_id WHERE r.class_name=? AND r.academic_year=? AND r.term=? AND r.report_month=? AND rg.subject_name=? AND rg.score > ?", [$className,$report['academic_year'],$report['term'],$report['report_month'],$g['subject_name'],(float)$g['score']]);
            $gradeRank = DB::fetch("SELECT COUNT(*)+1 rnk FROM report_grades rg JOIN reports r ON r.id=rg.report_id JOIN students s ON s.id=r.student_id WHERE s.grade_level=? AND r.academic_year=? AND r.term=? AND r.report_month=? AND rg.subject_name=? AND rg.score > ?", [$report['grade_level'],$report['academic_year'],$report['term'],$report['report_month'],$g['subject_name'],(float)$g['score']]);
            $subjectRanks[$g['subject_name']] = ['class'=>(int)($classRank['rnk']??0), 'grade'=>(int)($gradeRank['rnk']??0)];
        }
        return ['report'=>$report,'grades'=>$parsed['regular_grades'],'class_avg'=>$classAvg && $classAvg['avg_gpa']!==null?round((float)$classAvg['avg_gpa'],2):null,'class_count'=>(int)($classAvg['cnt']??0),'rank'=>$report['rank_in_class'] ?: (int)($rankRow['rank_calc']??0),'progress'=>$progress,'subject_avgs'=>$subjectAvgs,'subject_ranks'=>$subjectRanks];
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

if (!function_exists('generate_report_card_image')) {
    function generate_report_card_image($reportId) {
        if (!extension_loaded('gd')) throw new RuntimeException('افزونه GD روی سرور فعال نیست.');
        $data = get_report_analysis_data($reportId); if (!$data) throw new RuntimeException('کارنامه یافت نشد.');
        $r=$data['report']; $grades=$data['grades']; $w=1600; $h=max(2250, 900+count($grades)*78+560);
        $im=imagecreatetruecolor($w,$h); imagealphablending($im,true); imagesavealpha($im,true);
        $white=imagecolorallocate($im,255,255,255); $ink=imagecolorallocate($im,15,23,42); $muted=imagecolorallocate($im,100,116,139); $primary=imagecolorallocate($im,37,99,235); $gold=imagecolorallocate($im,217,119,6); $green=imagecolorallocate($im,16,185,129); $red=imagecolorallocate($im,220,38,38); $line=imagecolorallocate($im,226,232,240); $soft=imagecolorallocate($im,248,250,252); $blueSoft=imagecolorallocate($im,239,246,255);
        imagefilledrectangle($im,0,0,$w,$h,$soft); imagefilledrectangle($im,45,45,$w-45,$h-45,$white); imagerectangle($im,45,45,$w-45,$h-45,$line);
        imagefilledrectangle($im,45,45,$w-45,185,$blueSoft);
        gd_text($im,get_setting('report_header_line1','بسمه تعالی'),$w/2,92,24,$primary,true,'center');
        gd_text($im,get_setting('report_header_line2',get_setting('school_name','آموزشگاه')),$w/2,135,20,$ink,true,'center');
        gd_text($im,'کارنامه تحصیلی و تحلیل عملکرد',$w-80,170,15,$gold,true,'right');
        $photoX=82; $photoY=205; /* v4.91.0: نسبت ۳×۴ */ imagefilledrectangle($im,$photoX,$photoY,$photoX+120,$photoY+160,$soft); imagerectangle($im,$photoX,$photoY,$photoX+120,$photoY+160,$primary); if(!report_image_place_photo($im,$r['photo_url']??'',$photoX+2,$photoY+2,116,156)) gd_text($im,'عکس',$photoX+60,$photoY+82,15,$muted,false,'center');
        $y=225; gd_text($im,'نام دانش‌آموز: '.$r['first_name'].' '.$r['last_name'],$w-90,$y,20,$ink,true); gd_text($im,'کد ملی: '.$r['national_id'],$w-90,$y+38,17,$muted); gd_text($im,'پایه: '.($r['grade_level']?:'---'),$w-90,$y+76,17,$muted); gd_text($im,'کلاس: '.($r['class_name']?:$r['student_class']),$w-90,$y+114,17,$muted); gd_text($im,'سال تحصیلی: '.$r['academic_year'],720,$y+38,17,$muted); gd_text($im,'نوبت/ماه: '.$r['term'].' - '.$r['report_month'],720,$y+76,17,$muted);
        $cardY=380; imagefilledrectangle($im,80,$cardY,260,$cardY+92,imagecolorallocate($im,240,253,244)); imagerectangle($im,80,$cardY,260,$cardY+92,$green); gd_text($im,'معدل کل',170,$cardY+30,17,$green,true,'center'); gd_text($im,format_score($r['gpa']),170,$cardY+70,28,$green,true,'center');
        imagefilledrectangle($im,280,$cardY,460,$cardY+92,imagecolorallocate($im,255,251,235)); imagerectangle($im,280,$cardY,460,$cardY+92,$gold); gd_text($im,'انضباط',370,$cardY+30,17,$gold,true,'center'); gd_text($im,($r['discipline_score']!==null && $r['discipline_score']!=='')?format_score($r['discipline_score']):'---',370,$cardY+70,28,$gold,true,'center');
        $y=505; imagefilledrectangle($im,80,$y,$w-80,$y+64,$primary); gd_text($im,'درس',$w-135,$y+42,20,$white,true); gd_text($im,'نمره',690,$y+42,20,$white,true,'center'); gd_text($im,'میانگین کلاس',520,$y+42,18,$white,true,'center'); gd_text($im,'رتبه کلاس',365,$y+42,18,$white,true,'center'); gd_text($im,'رتبه پایه',240,$y+42,18,$white,true,'center'); gd_text($im,'وضعیت',125,$y+42,18,$white,true,'center'); $y+=64;
        foreach($grades as $i=>$g){$bg=$i%2?$soft:$white; imagefilledrectangle($im,80,$y,$w-80,$y+74,$bg); imageline($im,80,$y+74,$w-80,$y+74,$line); $score=(float)($g['score']??0); $avg=$data['subject_avgs'][$g['subject_name']]??null; $rank=$data['subject_ranks'][$g['subject_name']]??['class'=>0,'grade'=>0]; $scoreColor = ($score == 21.0) ? $muted : ($score < 10 ? $red : ($score < 15 ? $gold : $green)); $statusText = ($score == 21.0) ? 'غیبت' : ($score < 10 ? 'نیازمند تلاش' : ($score < 15 ? 'قابل قبول' : 'قبول'));
            gd_text($im,$g['subject_name'],$w-135,$y+48,19,$ink,true); gd_text($im,display_score($score),690,$y+48,20,$scoreColor,true,'center'); gd_text($im,$avg!==null?format_score($avg):'---',520,$y+48,18,$muted,false,'center'); gd_text($im,$rank['class']?:'---',365,$y+48,18,$ink,false,'center'); gd_text($im,$rank['grade']?:'---',240,$y+48,18,$ink,false,'center'); gd_text($im,$statusText,125,$y+48,17,$scoreColor,true,'center'); $y+=74; }
        $y+=35; imagefilledrectangle($im,80,$y,$w-80,$y+340,imagecolorallocate($im,249,250,251)); imagerectangle($im,80,$y,$w-80,$y+340,$line); gd_text($im,'تحلیل نموداری عملکرد',$w-115,$y+46,24,$primary,true); gd_text($im,'رتبه در کلاس: '.($data['rank']?:'---').' از '.($data['class_count']?:'---'),$w-115,$y+98,21,$ink,true); gd_text($im,'میانگین کلاس: '.($data['class_avg']!==null?format_score($data['class_avg']):'---'),$w-115,$y+145,21,$muted);
        $barX=650; $barY=$y+88; $barW=300; $barH=28; gd_text($im,'مقایسه معدل',800,$y+52,20,$ink,true,'center'); imagefilledrectangle($im,$barX,$barY,$barX+$barW,$barY+$barH,$line); imagefilledrectangle($im,$barX,$barY,$barX+(int)($barW*min(20,max(0,$r['gpa']))/20),$barY+$barH,$green); gd_text($im,'دانش‌آموز',$barX-15,$barY+22,15,$muted,false,'right'); $barY+=50; imagefilledrectangle($im,$barX,$barY,$barX+$barW,$barY+$barH,$line); imagefilledrectangle($im,$barX,$barY,$barX+(int)($barW*min(20,max(0,(float)$data['class_avg']))/20),$barY+$barH,$gold); gd_text($im,'کلاس',$barX-15,$barY+22,15,$muted,false,'right');
        $chartX=130; $chartY=$y+78; $chartW=410; $chartH=180; gd_text($im,'روند پیشرفت معدل',$chartX+$chartW/2,$y+52,20,$ink,true,'center'); imagerectangle($im,$chartX,$chartY,$chartX+$chartW,$chartY+$chartH,$line); for($i=1;$i<4;$i++) imageline($im,$chartX,$chartY+$i*45,$chartX+$chartW,$chartY+$i*45,$line); $prog=$data['progress']; $prev=null; $n=max(1,count($prog)-1); foreach($prog as $i=>$p){$px=$chartX+(int)(($chartW-30)*$i/$n)+15; $py=$chartY+$chartH-(int)(min(20,max(0,(float)$p['gpa']))/20*($chartH-20))-10; imagefilledellipse($im,$px,$py,10,10,$primary); if($prev) imageline($im,$prev[0],$prev[1],$px,$py,$primary); $prev=[$px,$py]; gd_text($im,$p['report_month']?:$p['term'],$px,$chartY+$chartH+32,13,$muted,false,'center');}
        if(!empty($r['teacher_comments'])){ $y+=375; gd_text($im,'نظر مشاور / پشتیبان: '.$r['teacher_comments'],$w-90,$y,20,$ink,false); }
        gd_text($im,str_replace('{date}',jdate('Y/m/d H:i'),get_setting('report_image_footer_text','تولید شده توسط سامانه مدیریت کارنامه - {date}')),$w/2,$h-75,16,$muted,false,'center');
        $file=report_image_safe_filename('report_card',$reportId); imagepng($im,$file,2); imagedestroy($im); return $file;
    }
}
