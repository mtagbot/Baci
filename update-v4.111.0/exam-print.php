<?php
// File: exam-print.php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/exams_helper.php';
ensure_exams_schema();
$type = $_GET['type'] ?? 'schedule';
$school = get_setting('school_name','آموزشگاه');

function exam_pdf_pages_to_images($pdfRelPath, $examId, $declaredPages = 0) {
    $out = [];
    $pdfRelPath = (string)$pdfRelPath;
    if ($pdfRelPath === '') return $out;
    $full = realpath(__DIR__ . '/' . ltrim($pdfRelPath, '/')) ?: (__DIR__ . '/' . ltrim($pdfRelPath, '/'));
    $cacheDir = __DIR__ . '/uploads/exams/pdf-pages/exam_' . (int)$examId;
    /* v4.94.0: بعد از تبدیل موفق، فایل PDF از هاست حذف می‌شود؛ اگر PDF دیگر
       نیست ولی تصاویر صفحات در کش هستند، همان تصاویر منبع طراحی‌اند. */
    if (!is_file($full)) {
        foreach (glob($cacheDir . '/page_*.jpg') ?: [] as $img) $out[] = str_replace(__DIR__ . '/', '', $img);
        sort($out);
        return $out;
    }
    if (strtolower(pathinfo($full, PATHINFO_EXTENSION)) !== 'pdf') return $out;
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    foreach (glob($cacheDir . '/page_*.jpg') ?: [] as $img) {
        if (filemtime($img) < filemtime($full)) @unlink($img); else $out[] = str_replace(__DIR__ . '/', '', $img);
    }
    if ($out) return $out;
    $declaredPages = max(0, (int)$declaredPages);
    if ($declaredPages <= 0 && class_exists('Imagick')) {
        try { $probe = new Imagick(); $probe->pingImage($full); $declaredPages = max(1, (int)$probe->getNumberImages()); $probe->clear(); } catch (Exception $e) { $declaredPages = 1; }
    }
    if ($declaredPages <= 0) $declaredPages = 1;
    /* v4.101.0: اگر کاربر هنگام آپلود صفحات خاصی را انتخاب کرده (selected.txt)،
       فقط همان صفحات استخراج می‌شوند و خروجی متوالی شماره می‌خورد. */
    $selFile = $cacheDir . '/selected.txt';
    $sel = is_file($selFile) ? array_values(array_filter(array_map('intval', explode(',', (string)@file_get_contents($selFile))), function($x){ return $x >= 1; })) : [];
    sort($sel);
    $srcList = $sel ?: range(1, $declaredPages);
    // Render one source PDF page to one output image; never flatten the full PDF sequence.
    if (class_exists('Imagick')) {
        foreach ($srcList as $oi => $srcPage) {
            $i = $srcPage - 1;
            try {
                $page = new Imagick(); $page->setResolution(180,180); $page->readImage($full . '[' . $i . ']');
                $page->setIteratorIndex(0); $page->setImageBackgroundColor('white');
                if (defined('Imagick::ALPHACHANNEL_REMOVE')) $page->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $page->setImageFormat('jpeg'); $page->setImageCompressionQuality(90);
                $file = $cacheDir . '/page_' . str_pad((string)($oi+1), 3, '0', STR_PAD_LEFT) . '.jpg';
                $page->writeImage($file); $page->clear(); $out[] = str_replace(__DIR__ . '/', '', $file);
            } catch (Exception $e) { break; }
        }
    }
    if (count($out) < count($srcList) && function_exists('exec')) {
        foreach (glob($cacheDir . '/page_*.jpg') ?: [] as $old) @unlink($old);
        $out = [];
        foreach ($srcList as $oi => $srcPage) {
            $file = $cacheDir . '/page_' . str_pad((string)($oi+1), 3, '0', STR_PAD_LEFT) . '.jpg';
            $cmd = 'gs -q -dSAFER -dBATCH -dNOPAUSE -sDEVICE=jpeg -r180 -dJPEGQ=90 -dFirstPage='.(int)$srcPage.' -dLastPage='.(int)$srcPage.' -sOutputFile=' . escapeshellarg($file) . ' ' . escapeshellarg($full) . ' 2>&1';
            @exec($cmd, $gsOut, $code); if ($code===0 && is_file($file)) $out[] = str_replace(__DIR__ . '/', '', $file);
        }
    }
    /* v4.101.0: ImageMagick CLI — برای هاست‌هایی که افزونه PHP و gs ندارند */
    if (count($out) < count($srcList) && function_exists('exec')) {
        foreach (glob($cacheDir . '/page_*.jpg') ?: [] as $old) @unlink($old);
        $out = [];
        foreach (['magick', 'convert'] as $bin) {
            foreach ($srcList as $oi => $srcPage) {
                $file = $cacheDir . '/page_' . str_pad((string)($oi + 1), 3, '0', STR_PAD_LEFT) . '.jpg';
                @exec($bin . ' -density 180 ' . escapeshellarg($full . '[' . ($srcPage - 1) . ']') . ' -background white -alpha remove -quality 90 ' . escapeshellarg($file) . ' 2>&1', $o2, $c2);
                if ($c2 === 0 && is_file($file)) $out[] = str_replace(__DIR__ . '/', '', $file);
            }
            if (count($out) >= count($srcList)) break;
            $out = [];
        }
    }
    $out = array_slice($out, 0, count($srcList));
    /* v4.94.0: تبدیل کامل شد → PDF منبع دیگر لازم نیست؛ برای آزادسازی فضای هاست حذف می‌شود */
    if (count($out) >= count($srcList) && count($srcList) >= 1) @unlink($full);
    return $out;
}
// Students can print their own exam schedule; exam design is available to admins and assigned teachers.
// For mini-apps (Bale/Telegram) a signed dt token may authorize the design page even if it opens in another browser.
$earlyExamId = (int)($_GET['exam_id'] ?? 0);
$earlyTokenOk = $earlyExamId ? verify_exam_design_token($_GET['dt'] ?? '', $earlyExamId) : false;
if (!$earlyTokenOk && !is_admin_logged_in() && !is_student_logged_in() && empty($_SESSION['teacher_id'])) die('غیرمجاز');

if ($type === 'seatcards') {
    $year = trim($_GET['year'] ?? get_setting('current_academic_year','1404/1405'));
    $month = trim($_GET['exam_month'] ?? 'خرداد');
    /* v4.72.0: شماره صندلی سراسری است — ماه در کار نیست؛ جدیدترین رکورد هر دانش‌آموز در سال */
    $rows = DB::fetchAll("SELECT seat.*, s.first_name, s.last_name, s.photo_url, s.class_name, s.grade_level
        FROM exam_student_seating seat
        JOIN (SELECT MAX(id) mid FROM exam_student_seating WHERE academic_year=? GROUP BY student_id) x ON x.mid=seat.id
        JOIN students s ON s.id=seat.student_id
        ORDER BY seat.exam_room, CAST(seat.seat_number AS UNSIGNED), s.class_name, s.last_name", [$year]);
?><!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title>ویرایشگر کارت صندلی</title>
<style>
@page{size:A4;margin:var(--pageMargin,7mm)} @font-face{font-family:Vazirmatn;src:url('uploads/Vazirmatn/Vazirmatn-Regular.woff2')}*{box-sizing:border-box}body{font-family:Vazirmatn,Tahoma,sans-serif;margin:0;background:#e5e7eb}.seat-toolbar{position:fixed;top:0;inset-inline:0;background:#111827;color:#fff;z-index:20;display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:8px;font-size:12px}.seat-toolbar input,.seat-toolbar select{width:80px}.sheet{--cols:2;--gap:3mm;--cardH:53mm;--photo:27mm;--font:11px;--seatFont:25px;display:grid;grid-template-columns:repeat(var(--cols),1fr);gap:var(--gap);padding:var(--pageMargin,7mm);margin:52px auto 0;background:#fff;width:210mm;min-height:297mm}.card{border:1.8px solid #000;border-radius:5px;padding:3mm;display:grid;grid-template-columns:1fr var(--photo);gap:3mm;min-height:var(--cardH);page-break-inside:avoid;font-size:var(--font);cursor:move}.info{line-height:2}.seatbox{border:2px solid #000;display:flex;align-items:center;justify-content:center;font-size:var(--seatFont);font-weight:900;min-height:18mm;margin-top:2mm}.photo{width:var(--photo);height:calc(var(--photo) * 4 / 3);border:1px solid #333;display:flex;align-items:center;justify-content:center;overflow:hidden;font-size:9px}.photo img{width:100%;height:100%;object-fit:cover}.empty-seat{border-style:dashed;opacity:.6}@media print{.seat-toolbar{display:none}.sheet{margin:0;break-after:page}.card{cursor:default}}
</style></head><body>
<div class="seat-toolbar">تعداد کارت <input type="range" min="4" max="12" value="10" oninput="setCardsPerPage(this.value)"> ستون <input type="range" min="1" max="3" value="2" oninput="setSeatVar('--cols',this.value)"> فاصله <input type="range" min="1" max="8" value="3" oninput="setSeatVar('--gap',this.value+'mm')"> ارتفاع کارت <input type="range" min="35" max="80" value="53" oninput="setSeatVar('--cardH',this.value+'mm')"> عکس <input type="range" min="16" max="38" value="27" oninput="setSeatVar('--photo',this.value+'mm')"> فونت <input type="range" min="8" max="16" value="11" oninput="setSeatVar('--font',this.value+'px')"> شماره <input type="range" min="18" max="42" value="25" oninput="setSeatVar('--seatFont',this.value+'px')"> حاشیه <input type="range" min="0" max="15" value="7" oninput="setSeatVar('--pageMargin',this.value+'mm')"><select id="gradeMix" onchange="filterSeatCards()"><option value="all">همه پایه‌ها</option><option value="هفتم">هفتم</option><option value="هشتم">هشتم</option><option value="نهم">نهم</option><option value="هفتم,هشتم">هفتم+هشتم</option><option value="هشتم,نهم">هشتم+نهم</option><option value="نهم,هفتم">نهم+هفتم</option></select><button onclick="addEmptySeat()">صندلی خالی</button><button onclick="window.print()">چاپ نهایی</button></div>
<div class="sheet" id="seatSheet">
<?php foreach($rows as $r): ?>
  <div class="card" draggable="true" data-grade="<?php echo clean($r['grade_level']); ?>"><div class="info"><b>نام دانش‌آموز:</b> <?php echo clean($r['first_name'].' '.$r['last_name']); ?><br><b>کلاس دانش‌آموز:</b> <?php echo clean($r['class_name']); ?><br><b>کلاس امتحانی:</b> <?php echo clean($r['exam_room']); ?><div class="seatbox"><?php echo tr_num($r['seat_number'],'fa'); ?></div></div><div><div class="photo"><?php if(!empty($r['photo_url'])): ?><img src="<?php echo clean($r['photo_url']); ?>"><?php else: ?>عکس<?php endif; ?></div></div></div>
<?php endforeach; ?>
</div><script>
function setSeatVar(k,v){document.getElementById('seatSheet').style.setProperty(k,v)}
function setCardsPerPage(n){let cols=Math.ceil(Math.sqrt(n/1.4)); setSeatVar('--cols',cols)}
function filterSeatCards(){const v=document.getElementById('gradeMix').value.split(',');document.querySelectorAll('.card').forEach(c=>{c.style.display=(v[0]==='all'||v.includes(c.dataset.grade))?'grid':'none'})}
function addEmptySeat(){document.getElementById('seatSheet').insertAdjacentHTML('beforeend','<div class="card empty-seat" draggable="true"><div class="info"><b>صندلی خالی</b><div class="seatbox">—</div></div><div class="photo">خالی</div></div>'); enableDrag();}
function enableDrag(){document.querySelectorAll('.card').forEach(card=>{card.ondragstart=e=>{card.classList.add('dragging')};card.ondragend=()=>card.classList.remove('dragging');card.ondragover=e=>e.preventDefault();card.ondrop=e=>{e.preventDefault();const d=document.querySelector('.dragging');if(d&&d!==card)card.parentNode.insertBefore(d,card.nextSibling)}})} enableDrag();
</script></body></html><?php exit; }

$examId = (int)($_GET['exam_id'] ?? 0);
$exam = DB::fetch('SELECT es.*, COALESCE(t.full_name, es.teacher_name) t_name, t.last_name t_last FROM exam_schedules es LEFT JOIN teachers t ON t.id=es.teacher_id WHERE es.id=?',[$examId]);
if (!$exam) die('امتحان یافت نشد');
$designToken = $_GET['dt'] ?? '';
$designTokenOk = verify_exam_design_token($designToken, $examId);
if (($type === 'questions' || $type === 'questions_editor') && !$designTokenOk && !exam_can_design($examId)) die('شما مجاز به طراحی سوالات این آزمون نیستید.');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_question_source']) && ($designTokenOk || exam_can_design($examId))) {
    if (isset($_FILES['question_source']) && $_FILES['question_source']['error'] === UPLOAD_ERR_OK) {
        $extUp = strtolower(pathinfo($_FILES['question_source']['name'], PATHINFO_EXTENSION));
        if (in_array($extUp, ['pdf','jpg','jpeg','png','webp'], true)) {
            $dir = __DIR__ . '/uploads/exams'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $fn = 'live_source_' . $examId . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $extUp;
            move_uploaded_file($_FILES['question_source']['tmp_name'], $dir . '/' . $fn);
            DB::execute('UPDATE exam_schedules SET question_file=? WHERE id=?', ['uploads/exams/' . $fn, $examId]);
            // Remove old converted cache so new PDF pages are generated.
            $cacheDir = __DIR__ . '/uploads/exams/pdf-pages/exam_' . (int)$examId;
            foreach (glob($cacheDir . '/page_*.jpg') ?: [] as $oldp) @unlink($oldp);
            $qs = $_GET; $qs['type']='questions'; $qs['exam_id']=$examId; if($designToken) $qs['dt']=$designToken;
            header('Location: exam-print.php?' . http_build_query($qs)); exit;
        }
    }
}
if (is_student_logged_in()) {
    $students = DB::fetchAll('SELECT * FROM students WHERE id=?',[$_SESSION['student_id']]);
} else {
    $gradeAll = isset($_GET['grade_all']) && $_GET['grade_all'] === '1';
    $targetGrade = $exam['grade_level'] ?: infer_grade_from_class_name($exam['class_name'] ?? '');
    // v4.78.0: print sheets ONLY for students active in the exam's OWN
    // academic year — a 1405/1406 exam must never produce sheets/headers
    // for students of any other year.
    list($exySql, $exyParams) = exam_year_students_sql($exam['academic_year'] ?? '');
    if ($gradeAll && $targetGrade !== '') {
        // Grade-wide printing: use the saved design for all students in the same grade,
        // independent of class teacher or original class of the design.
        $students = DB::fetchAll("SELECT s.* FROM students s WHERE s.status='active' AND (s.grade_level=? OR s.class_name LIKE ?) AND $exySql", array_merge([$targetGrade, $targetGrade . '%'], $exyParams));
    } else {
        $students = DB::fetchAll("SELECT s.* FROM students s WHERE s.status='active' AND (?='' OR s.class_name=?) AND (?='' OR s.grade_level=?) AND $exySql", array_merge([$exam['class_name'],$exam['class_name'],$exam['grade_level'],$exam['grade_level']], $exyParams));
    }
    persian_usort_by($students, ['class_name','last_name','first_name']);   // v4.77.0: آ قبل از ا
}
if (!$students) $students=[['id'=>0,'first_name'=>'نمونه','last_name'=>'دانش‌آموز','class_name'=>$exam['class_name'],'grade_level'=>$exam['grade_level'],'national_id'=>'','photo_url'=>'']];

if ($type === 'schedule') {
?><!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title>برنامه امتحانی</title><style>@page{size:A4;margin:10mm}@font-face{font-family:Vazirmatn;src:url('uploads/Vazirmatn/Vazirmatn-Regular.woff2')}body{font-family:Vazirmatn,Tahoma,sans-serif;margin:0}table{width:100%;border-collapse:collapse}th,td{border:1px solid #000;padding:7px;text-align:center;font-size:12px}th{background:#eee}.head{text-align:center;margin-bottom:10px}</style></head><body><div class="head"><b><?php echo clean($school); ?></b><br>جدول برنامه امتحانی</div><table><thead><tr><th>نام دانش‌آموز</th><th>کلاس</th><th>درس</th><th>تاریخ</th><th>روز</th><th>ساعت</th><th>مدت</th><th>کلاس امتحانی</th><th>صندلی</th></tr></thead><tbody>
<?php foreach($students as $s): $seat=DB::fetch('SELECT * FROM exam_student_seating WHERE academic_year=? AND student_id=? ORDER BY id DESC LIMIT 1',[$exam['academic_year'],$s['id']]); ?><tr><td><?php echo clean($s['first_name'].' '.$s['last_name']); ?></td><td><?php echo clean($s['class_name']); ?></td><td><?php echo clean($exam['subject_name']); ?></td><td><?php echo tr_num($exam['exam_date_jalali'],'fa'); ?></td><td><?php echo clean($exam['exam_day_name']); ?></td><td><?php echo tr_num($exam['start_time'],'fa'); ?></td><td><?php echo tr_num($exam['duration_minutes'],'fa'); ?></td><td><?php echo clean(($seat['exam_room']??'') ?: $exam['exam_room_default']); ?></td><td><?php echo tr_num($seat['seat_number']??'---','fa'); ?></td></tr><?php endforeach; ?>
</tbody></table><script>window.print()</script></body></html><?php exit; }

if ($type === 'questions') { $type = 'questions_editor'; }
if ($type === 'questions_editor') {
    $f = (string)($exam['question_file'] ?? '');
    $ext = $f !== '' ? strtolower(pathinfo($f, PATHINFO_EXTENSION)) : '';
    $sourcePages = ($ext === 'pdf') ? exam_pdf_pages_to_images($f, $examId) : (($f !== '' && in_array($ext, ['png','jpg','jpeg','webp'])) ? [$f] : []);
    /* v2.13.0 (desktop): PDF uploaded while offline → pages were never
       converted (no local Imagick/Ghostscript). Ask the SITE to convert and
       pull the images back, right when the designer/print page opens. */
    if ($ext === 'pdf' && empty($sourcePages) && is_file(__DIR__ . '/includes/desk_sync.php')) {
        require_once __DIR__ . '/includes/desk_sync.php';
        if (class_exists('DeskSync')) {
            // declared page count was stored at upload time (pages.txt)
            $pcFile = __DIR__ . '/uploads/exams/pdf-pages/exam_' . (int)$examId . '/pages.txt';
            $declared = is_file($pcFile) ? max(0, (int)@file_get_contents($pcFile)) : 0;
            /* v4.101.0: صفحات انتخابی کاربر هم به سرور تبدیل‌کننده می‌رود */
            $selFile2 = __DIR__ . '/uploads/exams/pdf-pages/exam_' . (int)$examId . '/selected.txt';
            $sel2 = is_file($selFile2) ? array_filter(array_map('intval', explode(',', (string)@file_get_contents($selFile2)))) : [];
            $remotePages = DeskSync::remoteExamPdfPages($examId, $f, $declared, $sel2);
            if ($remotePages) $sourcePages = $remotePages;
        }
    }
    $pdfNeedsServerConversion = ($ext === 'pdf' && empty($sourcePages));
    /* v4.100.0: ضد کش مرورگر — بعد از تعویض فایل منبع، نام صفحات (page_001.jpg…)
       ثابت می‌ماند و مرورگر تصویر قدیمی را از کش نشان می‌داد؛ افزودن ?v=زمانِ
       تغییر فایل باعث می‌شود همیشه نسخه جدید بارگذاری شود. */
    $sourcePages = array_map(function($sp){
        $full = __DIR__ . '/' . ltrim((string)$sp, '/');
        return is_file($full) ? ($sp . '?v=' . filemtime($full)) : $sp;
    }, $sourcePages);
    $studentsData = [];
    foreach ($students as $s0) {
        $seat0 = DB::fetch('SELECT * FROM exam_student_seating WHERE academic_year=? AND student_id=? ORDER BY id DESC LIMIT 1', [$exam['academic_year'], $s0['id']]);
        $studentsData[] = [
            'id' => (int)$s0['id'],
            'name' => trim(($s0['first_name'] ?? '') . ' ' . ($s0['last_name'] ?? '')),
            'class_name' => $s0['class_name'] ?? '',
            'seat' => tr_num($seat0['seat_number'] ?? '---', 'fa'),
            'photo' => $s0['photo_url'] ?? '',
        ];
    }
    $examData = [
        'title' => get_setting('exam_header_title', $school . ' - سربرگ رسمی آزمون'),
        'subtitle' => get_setting('exam_header_subtitle', ''),
        'subject' => $exam['subject_name'] ?? '',
        'teacher' => teacher_respectful_name(['last_name' => $exam['t_last'] ?? '', 'full_name' => $exam['t_name'] ?? '']), /* v4.94.0: فقط آقای + نام خانوادگی */
        'date' => tr_num($exam['exam_date_jalali'] ?? '', 'fa'),
        'time' => tr_num($exam['start_time'] ?? '', 'fa'),
        'stamp' => get_setting('exam_stamp_url',''),
        'file' => $f,
        'ext' => $ext,
        'sourcePages' => $sourcePages,
        'pdfNeedsServerConversion' => $pdfNeedsServerConversion,
    ];
?><!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title>ویرایشگر چاپ سوالات</title>
<style>
@page{size:A4;margin:0}@font-face{font-family:Vazirmatn;src:url('uploads/Vazirmatn/Vazirmatn-Regular.woff2')}@font-face{font-family:BTitr;src:url('uploads/B-Titr/B-Titr.ttf')}@font-face{font-family:Yekan;src:url('uploads/Yekan/Yekan.ttf')}@font-face{font-family:Sahel;src:url('uploads/Sahel/Sahel.ttf')}*{box-sizing:border-box}
:root{--ink:#0f172a;--muted:#64748b;--line:#e2e8f0;--line2:#cbd5e1;--bg:#eef1f6;--card:#ffffff;--brand:#2563eb;--brand2:#1d4ed8;--ok:#16a34a;--ok2:#15803d;--warn:#f59e0b;--danger:#ef4444;--radius:12px;--shadow:0 10px 30px rgba(15,23,42,.12);--shadow-lg:0 24px 70px rgba(15,23,42,.28);--focus:0 0 0 3px rgba(37,99,235,.35)}
body{font-family:Vazirmatn,Tahoma,sans-serif;margin:0;background:var(--bg);color:var(--ink)}
button{font-family:inherit}
button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible,[contenteditable]:focus-visible{outline:none;box-shadow:var(--focus);border-color:var(--brand)!important}
::selection{background:rgba(37,99,235,.18)}
/* ---------- نوار ابزار ---------- */
.toolbar{position:fixed;inset-inline:0;top:0;background:linear-gradient(180deg,#1e293b,#0f172a);color:#e2e8f0;padding:8px 12px;z-index:30;display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-size:12px;box-shadow:0 4px 18px rgba(2,6,23,.35)}
.toolbar button{cursor:pointer;border:1px solid rgba(148,163,184,.25);border-radius:8px;padding:6px 11px;background:rgba(148,163,184,.12);color:#e2e8f0;font-size:12px;transition:background .15s,border-color .15s,transform .05s}
.toolbar button:hover{background:rgba(148,163,184,.24);border-color:rgba(148,163,184,.45)}
.toolbar button:active{transform:translateY(1px)}
.tb-group{display:inline-flex;gap:5px;align-items:center;padding:3px 6px;border-radius:10px}
.tb-title{font-size:10px;color:#94a3b8;margin-inline-end:2px;white-space:nowrap}
.tb-sep{width:1px;height:26px;background:rgba(148,163,184,.25)}
.tb-spacer{flex:1}
.tb-primary{background:var(--brand)!important;border-color:var(--brand2)!important;color:#fff!important;font-weight:700}
.tb-primary:hover{background:var(--brand2)!important}
.tb-actions{gap:8px}
.tb-actions .btn-save{background:var(--ok)!important;border:1px solid var(--ok2)!important;color:#fff!important;font-weight:700;padding:7px 16px}
.tb-actions .btn-save:hover{background:var(--ok2)!important}
.tb-actions .btn-print{background:var(--warn)!important;border:1px solid #d97706!important;color:#fff!important;font-weight:700;padding:7px 16px}
.tb-actions .btn-print:hover{background:#d97706!important}
.tb-color{display:inline-flex;align-items:center;border:1px solid rgba(148,163,184,.25);border-radius:8px;padding:2px;background:rgba(148,163,184,.12)}
.tb-color input{width:30px;height:24px;padding:0;border:0;background:transparent;cursor:pointer}
.toolbar button.tool-active{background:var(--brand)!important;border-color:var(--brand2)!important;color:#fff!important}
/* ---------- پنل‌های کناری ---------- */
.editor-panel{position:fixed;top:calc(var(--toolbarH,58px) + 4px);right:12px;width:400px;max-height:calc(100vh - var(--toolbarH,58px) - 20px);overflow:auto;background:var(--card);border:1px solid var(--line2);border-radius:var(--radius);box-shadow:var(--shadow);z-index:25;padding:12px;font-size:12px}
.editor-panel.collapsed{display:none}
.bank-panel{position:fixed;top:calc(var(--toolbarH,58px) + 4px);left:12px;width:360px;max-height:calc(100vh - var(--toolbarH,58px) - 20px);overflow:auto;background:var(--card);border:1px solid var(--line2);border-radius:var(--radius);box-shadow:var(--shadow);z-index:25;padding:12px;font-size:12px}
.bank-panel.collapsed{display:none}
.panel-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid var(--line)}
.panel-close{border:0;background:#f1f5f9;color:#475569;border-radius:8px;width:28px;height:28px;font-size:16px;line-height:1;cursor:pointer;transition:background .15s}
.panel-close:hover{background:#fee2e2;color:#b91c1c}
.editor-panel details,.bank-panel details{border:1px solid var(--line);border-radius:10px;margin:8px 0;background:#fbfcfe;overflow:hidden}
.editor-panel summary,.bank-panel summary{cursor:pointer;padding:9px 12px;font-weight:700;font-size:12px;color:#1e293b;background:#f8fafc;user-select:none;list-style:none;position:relative}
.editor-panel summary::-webkit-details-marker{display:none}
.editor-panel summary:after{content:'‹';position:absolute;left:12px;top:50%;transform:translateY(-50%) rotate(0deg);transition:transform .18s;color:var(--muted);font-size:15px}
.editor-panel details[open] summary:after{transform:translateY(-50%) rotate(-90deg)}
.editor-panel details[open] summary{border-bottom:1px solid var(--line)}
.editor-panel details>*:not(summary){margin-inline:10px}
.editor-panel details>label:first-of-type{margin-top:8px}
.editor-panel details>*:last-child{margin-bottom:10px}
.bank-item{border:1px solid var(--line);border-radius:10px;padding:8px;margin-bottom:7px;background:#fbfcfe;transition:border-color .15s,box-shadow .15s}
.bank-item:hover{border-color:var(--brand);box-shadow:0 2px 10px rgba(37,99,235,.08)}
.bank-q{max-height:80px;overflow:hidden;font-size:11px}
.editor-panel input,.editor-panel select,.bank-panel input,.bank-panel select{width:100%;padding:7px 9px;margin:4px 0;border:1.5px solid var(--line2);border-radius:8px;font-family:inherit;font-size:12px;background:#fff;transition:border-color .15s}
.editor-panel input:hover,.editor-panel select:hover{border-color:#94a3b8}
.editor-panel label,.bank-panel label{font-weight:700;font-size:11px;color:#334155;display:block;margin-top:6px}
.editor-panel button,.bank-panel button{cursor:pointer;border:1px solid var(--line2);background:#f8fafc;border-radius:8px;padding:6px 10px;font-size:12px;transition:background .15s,border-color .15s}
.editor-panel button:hover,.bank-panel button:hover{background:#eef2f7;border-color:#94a3b8}
.btn-soft{border:1.5px solid var(--line2)!important;background:#fff!important;color:#334155!important;border-radius:8px;padding:8px 14px;cursor:pointer;font-size:12px;transition:background .15s,border-color .15s}
.btn-soft:hover{background:#f1f5f9!important;border-color:#94a3b8!important}
/* ---------- اسلایدرها ---------- */
input[type=range]{-webkit-appearance:none;appearance:none;height:22px;background:transparent;cursor:pointer;padding:0!important;border:0!important}
input[type=range]::-webkit-slider-runnable-track{height:5px;border-radius:999px;background:linear-gradient(90deg,var(--brand) 0%,var(--brand) 50%,#dbe3ee 50%)}
input[type=range]::-webkit-slider-thumb{-webkit-appearance:none;width:16px;height:16px;border-radius:50%;background:#fff;border:2.5px solid var(--brand);margin-top:-5.5px;box-shadow:0 1px 5px rgba(15,23,42,.25);transition:transform .12s}
input[type=range]::-webkit-slider-thumb:hover{transform:scale(1.15)}
input[type=range]::-moz-range-track{height:5px;border-radius:999px;background:#dbe3ee}
input[type=range]::-moz-range-progress{height:5px;border-radius:999px;background:var(--brand)}
input[type=range]::-moz-range-thumb{width:13px;height:13px;border-radius:50%;background:#fff;border:2.5px solid var(--brand);box-shadow:0 1px 5px rgba(15,23,42,.25)}
.range-val{display:inline-block;min-width:22px;text-align:center;background:#e0e9ff;color:var(--brand2);border-radius:6px;padding:1px 6px;font-size:10px;font-weight:800;margin-inline-start:4px}
/* ---------- ویرایشگر متن ---------- */
.rich-tools{display:flex;gap:4px;flex-wrap:wrap;margin:8px 0}
.rich-tools button{padding:6px 10px;border:1px solid var(--line2);background:#f8fafc;border-radius:7px;cursor:pointer;font-size:12px;transition:background .15s,border-color .15s}
.rich-tools button:hover{background:#e0e9ff;border-color:var(--brand)}.rich-editor{min-height:105px;border:1px solid #94a3b8;border-radius:8px;padding:0 8px 8px;background:#fff;outline:none;position:relative;overflow:auto}.rich-editor p{margin:2px 0}.rich-editor p:first-child{margin-top:0}.rich-editor img{max-width:100%;height:auto;position:relative;display:inline-block}.page{--headerH:39mm;--photo:20mm;--stamp:18mm;/* v4.95.0: مهر ۱۸ + شماره صندلی ۸.۷ = ارتفاع عکس ۳×۴ *//* v4.100.0: کادر همیشه 1px، عرض حداکثر، فاصله 0، ارتفاع = کل فضای باقیمانده صفحه */--qW:210mm;--qTop:0mm;--qBorder:1px;background:white;width:210mm;min-height:297mm;margin:var(--toolbarH,58px) auto 0;padding:0;overflow:hidden}.exam-header{height:var(--headerH);border:1px solid #000;padding:2mm 4mm;display:grid;grid-template-columns:var(--photo) 1fr var(--stamp);gap:3mm;align-items:center;font-family:BTitr,Vazirmatn,Tahoma,sans-serif/* v4.93.0 */}.page.no-header .exam-header{display:none}.photo,.stamp{width:100%;border:1px solid #000;display:flex;align-items:center;justify-content:center;font-size:9px;overflow:hidden}.photo{height:calc(var(--photo) * 4 / 3)}.stamp{height:var(--stamp)}.photo img{width:100%;height:100%;object-fit:cover}.stamp img{width:100%;height:100%;object-fit:contain}/* v4.95.0: کادر شماره صندلی چسبیده به زیر مهر — مجموع = ارتفاع عکس ۳×۴ */.stampcol{width:100%;display:flex;flex-direction:column}.seatno{width:100%;height:calc(var(--photo) * 4 / 3 - var(--stamp));border:1px solid #000;border-top:0;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;font-variant-numeric:tabular-nums;overflow:hidden}.hcenter{text-align:center;font-size:15px;font-weight:normal}.hcenter b{font-weight:normal}.meta{display:grid;grid-template-columns:repeat(4,1fr);gap:1.2mm;margin-top:1.5mm}.box{border:1px solid #333;padding:1mm;font-size:13px;font-weight:normal}.qwrap{position:relative;height:calc(296mm - var(--headerH) - var(--qTop));width:var(--qW);margin:var(--qTop) auto 0;border:var(--qBorder) solid #000;overflow:hidden;background:#fff}.page.no-header .qwrap{height:294mm;margin-top:1mm}.page.blank-sep{background:#fff}.page.blank-sep:after{content:'';display:block}.source-file{display:flex;align-items:flex-start;justify-content:center;min-height:0}.source-file:empty{display:none}.source-file{position:relative;overflow:hidden;width:100%;height:auto;background:#fff}.source-file img{width:var(--srcW,100%);height:auto;max-height:none;object-fit:contain;clip-path:inset(var(--cropT,0%) var(--cropR,0%) var(--cropB,0%) var(--cropL,0%));transform:translate(var(--srcX,0mm), var(--srcY,0mm));transform-origin:top right;filter:contrast(var(--srcContrast,100%)) brightness(var(--srcBrightness,100%));mix-blend-mode:var(--srcBlend,normal)}.source-file iframe{width:100%;height:80mm;border:0}.questions-table{width:100%;border-collapse:collapse;direction:rtl}.questions-table th,.questions-table td{border:1px solid #000;padding:4px;vertical-align:top;font-size:11px}.questions-table th{background:#f1f5f9;text-align:center}.q-no{width:13mm;text-align:center;font-weight:bold}.q-score{width:18mm;text-align:center;font-weight:bold}.q-content{min-height:12mm;position:relative;overflow:hidden;padding-top:0!important}/* v4.100.0: سوال چسبیده به بالای فضای متن */.q-content p{margin:2px 0}.q-content p:first-child{margin-top:0}.q-content .q-options{margin-top:2px}.q-content img{position:absolute!important;z-index:3;max-width:none;cursor:move}.questions-body .q-content img{display:block}.bank-panel img{max-width:100%;height:auto;position:static!important}.q-options{margin-top:4px;display:grid;grid-template-columns:1fr 1fr;gap:3px}.blank-line{display:inline-block;border-bottom:1px dotted #000;min-width:35mm;height:10px}.tf-square{display:inline-block;width:12px;height:12px;border:1px solid #000;margin:0 5px;vertical-align:middle}.q-actions{display:flex;gap:4px;margin-top:4px}.q-actions button{font-size:9px;padding:2px 5px}.question-row[draggable="true"]{cursor:move}.question-row.dragging{opacity:.45}.drawCanvas{position:absolute;inset:0;z-index:5;pointer-events:none}.drawOverlay{position:absolute!important;inset:0!important;width:100%!important;height:100%!important;z-index:50!important;pointer-events:none!important;display:block!important;max-width:none!important}.draw-on .drawCanvas{pointer-events:auto}body.drawing-mode .drawOverlay{display:none!important}.header-field-row{display:grid;grid-template-columns:24px 1fr 1fr 28px;gap:4px;align-items:center;margin-bottom:4px}.selected-img{outline:2px solid #ef4444;outline-offset:2px}.upload-modal{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:60;display:flex;align-items:center;justify-content:center}.upload-card{width:min(560px,94vw);background:white;border-radius:14px;padding:16px;box-shadow:0 20px 60px rgba(0,0,0,.25);position:relative}.upload-close{position:absolute;left:12px;top:10px;border:0;background:#ef4444;color:white;border-radius:8px;padding:4px 10px}.upload-steps{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin:12px 0}.upload-steps span{background:#e5e7eb;border-radius:8px;padding:8px;text-align:center;font-size:11px}.upload-steps span.active{background:#2563eb;color:white}.upload-step-body{border:1px solid #e2e8f0;border-radius:10px;padding:12px;margin:10px 0}.upload-step-body input{width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:8px}.progressbar{height:12px;background:#e5e7eb;border-radius:999px;overflow:hidden}.progressbar div{height:100%;width:0;background:#16a34a;transition:.2s}.upload-actions{display:flex;gap:8px;justify-content:flex-end}.questions-hidden .questions-table{display:none!important}.btn-save{background:#16a34a!important;color:white!important}.btn-print{background:#f59e0b!important;color:white!important}.toolbar button{border:0;border-radius:6px;padding:5px 8px;font-family:inherit}.editor-panel,.bank-panel{font-family:Vazirmatn,Tahoma,sans-serif}.image-modal{position:fixed;inset:0;background:rgba(15,23,42,.58);z-index:75;display:none;align-items:center;justify-content:center}.image-card{width:min(980px,96vw);max-height:94vh;overflow:auto;background:white;border-radius:14px;padding:14px;box-shadow:0 20px 60px rgba(0,0,0,.3)}.image-editor-grid{display:grid;grid-template-columns:1.5fr 1fr;gap:12px}.image-canvas-wrap{background:#f1f5f9;border:1px solid #cbd5e1;border-radius:10px;padding:10px;text-align:center;overflow:auto}.image-canvas-wrap canvas{max-width:100%;height:auto;background:white}.image-controls label{font-size:11px;font-weight:700;display:block;margin-top:6px}.image-controls input[type=range],.image-controls input[type=file]{width:100%}.math-modal{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:70;display:none;align-items:center;justify-content:center}.math-card{width:min(760px,96vw);max-height:92vh;overflow:auto;background:white;border-radius:14px;padding:14px;box-shadow:0 20px 60px rgba(0,0,0,.28)}.math-grid{display:grid;grid-template-columns:repeat(8,1fr);gap:5px;margin:8px 0}.math-grid button{padding:7px;border:1px solid #cbd5e1;background:#f8fafc;border-radius:7px;font-size:16px}.math-preview{border:1px dashed #94a3b8;border-radius:8px;padding:10px;min-height:42px;background:#f8fafc;font-size:18px;direction:ltr;text-align:left}.math-token{direction:ltr;unicode-bidi:embed;font-family:Cambria Math,Times New Roman,serif;font-size:1.12em;display:inline-block;padding:0 2px}.math-frac{display:inline-flex;flex-direction:column;vertical-align:middle;text-align:center;line-height:1.05;margin:0 3px}.math-frac .top{border-bottom:1px solid #111;padding:0 4px}.math-frac .bottom{padding:0 4px}.math-frac .math-frac{font-size:.92em}.math-sqrt{display:inline-flex;align-items:baseline}.math-sqrt-sym{font-size:1.12em}.math-sqrt-body{border-top:1.2px solid #111;padding:0 3px}.math-matrix{display:inline-table;border-collapse:collapse;vertical-align:middle;margin:0 4px;direction:ltr}.math-matrix td{border:0!important;padding:1px 6px!important;text-align:center}.math-bracket{display:inline-flex;align-items:center;gap:2px;direction:ltr}.math-big{font-size:1.8em;line-height:1}.math-vector{position:relative;display:inline-block}.math-vector:before{content:"→";position:absolute;top:-.85em;left:0;right:0;text-align:center;font-size:.8em}/* v4.95.0: بخش آزمون‌های ذخیره‌شده در بانک */.bank-exam{border:1px solid #cbd5e1;border-radius:8px;padding:7px;margin-bottom:7px;background:#fff}.bank-exam-thumbs{display:flex;gap:4px;overflow-x:auto;margin:5px 0;cursor:zoom-in}.bank-exam-thumbs img{height:86px;width:auto;border:1px solid #e2e8f0;border-radius:4px;background:#fff;position:static!important}.bank-exam small{color:#475569}.exam-zoom-modal{position:fixed;inset:0;background:rgba(15,23,42,.72);z-index:80;display:none;align-items:center;justify-content:center}.exam-zoom-card{width:min(880px,96vw);max-height:94vh;overflow:auto;background:#fff;border-radius:14px;padding:14px;box-shadow:0 20px 60px rgba(0,0,0,.35);position:relative}.exam-zoom-card img{width:100%;height:auto;border:1px solid #e2e8f0;border-radius:6px;margin-bottom:8px;position:static!important}.exam-zoom-close{position:sticky;top:0;float:left;border:0;background:#ef4444;color:#fff;border-radius:8px;padding:5px 12px;cursor:pointer;z-index:2}.print-note-box{border:1px solid #e2e8f0;background:#f8fafc;border-radius:8px;padding:8px}.print-note-box textarea{width:100%;min-height:70px;border:1px solid #cbd5e1;border-radius:8px;padding:8px}/* ---------- v4.102.0: مودال ثبت سوال جدید ---------- */
.q-modal{position:fixed;inset:0;background:rgba(15,23,42,.6);backdrop-filter:blur(3px);z-index:65;display:flex;align-items:center;justify-content:center;animation:fadeIn .18s ease}
.q-modal-card{width:min(1060px,96vw);max-height:94vh;overflow:auto;background:var(--card);border-radius:16px;padding:18px;box-shadow:var(--shadow-lg);animation:popIn .2s ease}
.q-modal-head{display:flex;justify-content:space-between;align-items:center;padding-bottom:10px;border-bottom:1px solid var(--line);margin-bottom:12px}
.q-modal-head b{font-size:15px}
.q-modal-grid{display:grid;grid-template-columns:1.15fr 1fr;gap:16px}
@media (max-width:860px){.q-modal-grid{grid-template-columns:1fr}}
.q-form-col label,.q-preview-col label{font-weight:700;font-size:11px;color:#334155;display:block;margin-top:8px}
.q-form-col input,.q-form-col select{width:100%;padding:7px 9px;margin:4px 0;border:1.5px solid var(--line2);border-radius:8px;font-family:inherit;font-size:12px;background:#fff}
.q-field-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
.q-field{min-width:0}
.q-preview-col{position:sticky;top:0}
.q-preview-sheet{border:1.5px solid var(--line2);border-radius:10px;background:repeating-linear-gradient(45deg,#f8fafc,#f8fafc 12px,#f3f6fa 12px,#f3f6fa 24px);padding:12px;margin-top:6px;overflow:auto;max-height:52vh}
.q-preview-sheet .questions-table{background:#fff}
.q-preview-sheet .q-content{overflow:hidden;position:relative}
.q-preview-hint{color:var(--muted);display:block;margin-top:6px;font-size:10.5px}
.q-modal-actions{display:flex;gap:10px;align-items:center;margin-top:14px;padding-top:12px;border-top:1px solid var(--line)}
.q-modal-actions .btn-save{background:var(--ok);color:#fff;border:0;border-radius:9px;padding:10px 22px;font-weight:800;font-size:13px;cursor:pointer;transition:background .15s}
.q-modal-actions .btn-save:hover{background:var(--ok2)}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes popIn{from{opacity:.4;transform:scale(.97) translateY(8px)}to{opacity:1;transform:none}}
.upload-card,.math-card,.image-card,.exam-zoom-card{animation:popIn .2s ease}
.upload-modal,.math-modal,.image-modal,.exam-zoom-modal{backdrop-filter:blur(3px)}
body.modal-open{overflow:hidden}
/* ---------- توست ---------- */
#uiToast{position:fixed;bottom:22px;inset-inline:0;margin-inline:auto;width:max-content;max-width:88vw;background:#0f172a;color:#f1f5f9;border-radius:999px;padding:10px 22px;font-size:13px;font-weight:700;z-index:120;box-shadow:0 10px 34px rgba(2,6,23,.45);opacity:0;transform:translateY(14px);transition:opacity .22s,transform .22s;pointer-events:none}
#uiToast.show{opacity:1;transform:none}
/* ---------- بهبود ریزتعامل‌ها ---------- */
.rich-editor{min-height:130px;border:1.5px solid var(--line2);border-radius:10px;padding:0 10px 10px;background:#fff;outline:none;position:relative;overflow:auto;transition:border-color .15s}
.rich-editor:focus{border-color:var(--brand);box-shadow:var(--focus)}
.q-actions button{font-size:10px;padding:3px 8px;border:1px solid var(--line2);background:#fff;border-radius:6px;cursor:pointer;transition:background .15s}
.q-actions button:hover{background:#eef2f7}
.q-actions button:last-child:hover{background:#fee2e2;border-color:#fca5a5;color:#b91c1c}
.upload-steps span{transition:background .2s,color .2s}
.upload-steps span.active{box-shadow:0 3px 12px rgba(37,99,235,.35)}
.progressbar div{background:linear-gradient(90deg,#16a34a,#22c55e)}
.pagepick-chip{display:inline-flex;align-items:center;gap:5px;border:1.5px solid var(--line2);border-radius:9px;padding:6px 10px;background:#fff;cursor:pointer;font-size:12px;user-select:none;transition:border-color .15s,background .15s}
.pagepick-chip:hover{border-color:var(--brand)}
.pagepick-chip input{accent-color:var(--brand)}
.pagepick-chip:has(input:checked){background:#e0e9ff;border-color:var(--brand);font-weight:700}
@media print{.toolbar,.editor-panel,.bank-panel,.q-actions,.exam-zoom-modal,.q-modal,#uiToast{display:none!important}.page{margin:0;width:210mm;height:297mm;break-after:page}body{background:white}.qwrap{overflow:hidden}.source-file iframe{height:65mm!important}.drawCanvas{pointer-events:none}}
/* v4.77.0: render pages lazily on screen — old/weak systems keep scrolling smooth
   even with hundreds of print pages; ignored harmlessly by very old browsers.
   Print media is unaffected: all pages always render fully on paper. */
@media screen{.page{content-visibility:auto;contain-intrinsic-size:210mm 297mm}}
</style></head><body>
<div class="toolbar" id="mainToolbar">
  <div class="tb-group no-ajax"><span class="tb-title">فایل منبع</span><button type="button" onclick="openSourceUploadModal()">بارگذاری PDF/تصویر</button><button type="button" onclick="deleteLiveSource()">حذف/تغییر</button></div>
  <span class="tb-sep"></span>
  <div class="tb-group"><span class="tb-title">سوالات</span><button class="tb-primary" onclick="openQuestionModal()">ثبت سوال جدید</button><button onclick="toggleBank()">بانک سوالات</button></div>
  <span class="tb-sep"></span>
  <div class="tb-group"><span class="tb-title">صفحه</span><button onclick="addBlankPage()">صفحه جدید</button><button onclick="deleteLastEmptyPage()">حذف صفحه اضافی</button><button onclick="toggleEditor()">تنظیمات برگه</button></div>
  <span class="tb-sep"></span>
  <div class="tb-group"><span class="tb-title">دست‌نویس</span><label class="tb-color" title="رنگ قلم"><input type="color" id="penColor" value="#111111"></label><button id="toolBtnPen" onclick="setTool('pen')">قلم</button><button id="toolBtnEraser" onclick="setTool('eraser')">پاک‌کن</button><button id="toolBtnOff" class="tool-active" onclick="setTool('off')">خاموش</button><button onclick="clearDrawings()">پاک‌کردن</button></div>
  <span class="tb-spacer"></span>
  <div class="tb-group tb-actions"><button class="btn-save" onclick="saveDesign()">ذخیره طراحی</button><button class="btn-print" onclick="prepareAllAndPrint()">چاپ نهایی</button></div>
</div>
<div class="editor-panel collapsed" id="questionEditorPanel">
  <div class="panel-head"><b>تنظیمات برگه</b><button type="button" class="panel-close" onclick="toggleEditor()" aria-label="بستن">×</button></div>
  <details open><summary>یادداشت برای چاپ</summary><div class="print-note-box"><textarea id="printNote" placeholder="یادداشت داخلی طراح برای چاپ/آماده‌سازی آزمون؛ برای همه کاربران مجاز قابل مشاهده است." oninput="autosaveLocal()"></textarea><small>این یادداشت داخل طراحی آزمون ذخیره می‌شود و در برگه چاپی دانش‌آموز نمایش داده نمی‌شود.</small></div></details>
  <details><summary>تصویر انتخاب‌شده در برگه</summary><label>ابعاد تصویر انتخاب‌شده</label><input id="imgSize" type="range" min="40" max="700" value="220" oninput="resizeSelectedImage(this.value)"><small>ابتدا روی تصویر داخل برگه کلیک کنید، سپس اندازه را تغییر دهید.</small></details>
  <details><summary>چینش و برش صفحات منبع</summary><div id="pdfConvertWarn" style="display:none;color:#b91c1c;font-weight:bold;margin:6px 0">برای تبدیل صفحه‌به‌صفحه PDF به تصویر، افزونه Imagick روی سرور لازم است. در حال حاضر از نمایش PDF کامل در صفحه جلوگیری شده تا عملیات سنگین و تکراری نشود.</div><label>چینش صفحات منبع PDF/تصویر</label><input id="sourceOrderInput" class="form-input" placeholder="مثلاً 1,2,3 یا 2,1" oninput="setSourceOrderFromInput()"><button type="button" class="btn-soft" onclick="resetSourceOrder()">چینش پیش‌فرض</button><label>شماره صفحه منبع</label><select id="cropPage" onchange="loadCropControls()"></select><label>برش بالا</label><input type="range" min="0" max="45" value="0" id="cropT" oninput="setCrop()"><label>برش راست</label><input type="range" min="0" max="45" value="0" id="cropR" oninput="setCrop()"><label>برش پایین</label><input type="range" min="0" max="45" value="0" id="cropB" oninput="setCrop()"><label>برش چپ</label><input type="range" min="0" max="45" value="0" id="cropL" oninput="setCrop()"><label>جابجایی افقی تصویر</label><input type="range" min="-80" max="80" value="0" id="srcX" oninput="setCrop()"><label>جابجایی عمودی تصویر</label><input type="range" min="-80" max="80" value="0" id="srcY" oninput="setCrop()"><label>کنتراست تصویر صفحه</label><input type="range" min="50" max="220" value="100" id="srcContrast" oninput="setCrop()"><label>روشنایی تصویر صفحه</label><input type="range" min="60" max="140" value="100" id="srcBrightness" oninput="setCrop()"><label>حذف پس‌زمینه سفید</label><input type="range" min="0" max="100" value="0" id="srcBgRemove" oninput="setCrop()"><label>اندازه تصویر صفحه</label><input type="range" min="50" max="180" value="100" id="srcW" oninput="setCrop()"></details>
  <details><summary>فیلدهای سربرگ</summary><div id="headerFieldsBox"></div><div style="display:grid;grid-template-columns:1fr 1fr auto;gap:4px"><input id="newHeaderLabel" placeholder="عنوان فیلد"><input id="newHeaderValue" placeholder="مقدار ثابت"><button onclick="addHeaderField()">+</button></div><small>تغییرات سربرگ روی تمام برگه‌های دانش‌آموزان اعمال می‌شود.</small></details>
</div>
<div class="bank-panel collapsed" id="bankPanel">
  <div class="panel-head"><span><b>بانک سوالات</b> <small id="bankSubjectBadge" style="display:none;background:#dbeafe;color:#1e40af;border-radius:6px;padding:2px 7px;font-weight:700"></small></span><button type="button" class="panel-close" onclick="toggleBank()" aria-label="بستن">×</button></div>
  <?php /* v4.97.0: بانک فقط سوالات و آزمون‌های هم‌درس با آزمون در حال طراحی را نشان می‌دهد */ ?>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;margin:6px 0" class="no-ajax">
    <input id="bankSearch" placeholder="جستجوی متن/درس">
    <?php /* v4.96.0: فیلترهای سال/ماه/طراح انتخابی — مشترک بین سوالات بانک و آزمون‌های ذخیره‌شده */ ?>
    <select id="bankYear"><option value="">همه سال‌ها</option></select>
    <select id="bankMonth"><option value="">همه ماه‌ها</option></select>
    <select id="bankType"><option value="">همه انواع</option><option value="text">تشریحی</option><option value="mcq">چندگزینه‌ای</option><option value="blank">جای خالی</option><option value="tf">صحیح/غلط</option></select>
    <select id="bankDesigner"><option value="">همه طراحان</option></select>
    <button class="tb-primary" style="border-radius:8px" onclick="loadBank()">اعمال فیلتر</button>
  </div>
  <small>دبیران فقط می‌توانند سوالات بانک را درج کنند؛ ویرایش بانک فقط برای مدیر است.</small>
  <?php /* v4.95.0: آزمون‌های کامل ذخیره‌شده (با فایل منبع تبدیل‌شده به تصویر) */ ?>
  <div style="margin-top:10px;border-top:2px solid #e2e8f0;padding-top:8px">
    <b>آزمون‌های ذخیره‌شده</b>
    <small style="display:block;margin:3px 0">برای فیلتر از فیلدهای سال، ماه و طراح بالای پنل استفاده کنید؛ با کلیک روی تصویر، صفحات بزرگ می‌شوند.</small>
    <div id="designBankList" style="margin-top:6px"></div>
  </div>
  <div id="bankList" style="margin-top:8px"></div>
</div>
<div id="questionModal" class="q-modal" style="display:none" onclick="if(event.target===this)closeQuestionModal()">
  <div class="q-modal-card" role="dialog" aria-modal="true" aria-labelledby="qModalTitle">
    <div class="q-modal-head"><b id="qModalTitle">آماده‌سازی سوال</b><button type="button" class="panel-close" onclick="closeQuestionModal()" aria-label="بستن">×</button></div>
    <input type="hidden" id="editingQid" value="">
    <div class="q-modal-grid">
      <div class="q-form-col">
        <div class="q-field-row">
          <div class="q-field"><label>نوع سوال</label><select id="qType" onchange="applyQuestionTemplate()"><option value="text">متنی / تشریحی</option><option value="mcq">چند گزینه‌ای</option><option value="blank">جای خالی</option><option value="tf">صحیح و غلط</option></select></div>
          <div class="q-field"><label>شماره ردیف</label><input id="qNumber" type="number" min="1" value="1" oninput="updateQuestionPreview()"></div>
          <div class="q-field"><label>بارم / نمره</label><input id="qScore" type="text" value="1" oninput="updateQuestionPreview()"></div>
        </div>
        <div class="q-field-row">
          <div class="q-field"><label>فونت متن</label><select id="qFont" onchange="applyFontToEditor()"><option value="Vazirmatn">وزیرمتن (Vazirmatn)</option><option value="Yekan">یکان (Yekan)</option><option value="Sahel">ساحل (Sahel)</option><option value="BTitr">تیتر (B-Titr)</option><option value="Tahoma">Tahoma</option><option value="serif">Serif</option><option value="sans-serif">Sans</option></select></div>
          <div class="q-field"><label>اندازه فونت <span class="range-val" id="qFontSizeVal">۱۱</span></label><input id="qFontSize" type="range" min="8" max="24" value="11" oninput="applyFontToEditor()"></div>
          <div class="q-field"><label>ارتفاع سوال <span class="range-val" id="qHeightVal">۱۸</span> mm</label><input id="qHeight" type="range" min="10" max="90" value="18" oninput="previewQuestionHeight(this.value)"></div>
        </div>
        <div class="q-field"><label>افزودن فونت دلخواه</label><input type="file" accept=".ttf,.otf,.woff,.woff2" onchange="loadCustomFont(this)"></div>
        <div class="rich-tools"><button type="button" title="درشت" onclick="cmd('bold')"><b>B</b></button><button type="button" title="مورب" onclick="cmd('italic')"><i>I</i></button><button type="button" title="زیرخط" onclick="cmd('underline')"><u>U</u></button><button type="button" onclick="cmd('insertUnorderedList')">لیست</button><button type="button" onclick="cmd('justifyRight')">راست</button><button type="button" onclick="cmd('justifyCenter')">وسط</button><button type="button" onclick="cmd('justifyLeft')">چپ</button><button type="button" onclick="cmd('justifyFull')">تراز</button><button type="button" onclick="openMathModal()">∑ ریاضی</button><button type="button" onclick="openImageEditor()">تصویر</button></div>
        <label>متن، گزینه‌ها و تصویر سوال</label>
        <div id="qText" class="rich-editor" contenteditable="true" oninput="updateQuestionPreview()"></div>
      </div>
      <div class="q-preview-col">
        <label>پیش‌نمایش سوال در برگه</label>
        <div class="q-preview-sheet">
          <table class="questions-table"><thead><tr><th class="q-no">شماره</th><th>سوال</th><th class="q-score">بارم</th></tr></thead>
          <tbody><tr><td class="q-no" id="qPrevNo">1</td><td class="q-content" id="qPrevContent"></td><td class="q-score" id="qPrevScore">1</td></tr></tbody></table>
        </div>
        <small class="q-preview-hint">پیش‌نمایش با همان فونت، اندازه و ارتفاع واقعی برگه به‌روز می‌شود.</small>
      </div>
    </div>
    <div class="q-modal-actions">
      <button type="button" class="btn-soft" onclick="clearQuestionForm()">فرم جدید</button>
      <span style="flex:1"></span>
      <button type="button" class="btn-soft" onclick="closeQuestionModal()">انصراف</button>
      <button type="button" class="btn-save" id="qInsertBtn" onclick="saveQuestion()">درج در آزمون</button>
    </div>
  </div>
</div>
<div id="sourceUploadModal" class="upload-modal" style="display:none">
  <div class="upload-card">
    <button type="button" class="upload-close" onclick="closeSourceUploadModal()">×</button>
    <h3>بارگذاری فایل منبع آزمون</h3>
    <div class="upload-steps"><span id="upStep1" class="active">۱ انتخاب فایل</span><span id="upStep2">۲ انتخاب صفحات</span><span id="upStep3">۳ بارگذاری</span><span id="upStep4">۴ آماده‌سازی</span></div>
    <div id="uploadStepSelect" class="upload-step-body">
      <label>فایل PDF یا تصویر سوالات</label>
      <input type="file" id="modalSourceFile" accept=".pdf,.jpg,.jpeg,.png,.webp" onchange="sourceFileSelected()">
      <small>نام فارسی یا کاراکترهای خاص مشکلی ایجاد نمی‌کند؛ فایل با نام امن روی سرور ذخیره می‌شود.</small>
    </div>
    <div id="uploadStepPages" class="upload-step-body" style="display:none">
      <div id="pdfPagePickWrap">
        <label>انتخاب صفحات فایل منبع</label>
        <div id="pdfPageInfo" style="margin:4px 0;font-weight:700"></div>
        <div id="pdfPageChecks" style="display:grid;grid-template-columns:repeat(5,1fr);gap:5px;max-height:190px;overflow:auto"></div>
        <div style="display:flex;gap:6px;margin-top:8px"><button type="button" class="btn btn-secondary" onclick="pdfPagesAll(true)">انتخاب همه</button><button type="button" class="btn btn-secondary" onclick="pdfPagesAll(false)">هیچ‌کدام</button></div>
        <div id="pdfCountManual" style="display:none;margin-top:8px"><label>تعداد کل صفحات PDF (شناسایی خودکار نشد — دستی وارد کنید)</label><input type="number" id="sourcePageCountInput" min="1" value="1" oninput="buildPdfPageChecks(Math.max(1,+this.value||1))"></div>
        <small>فقط صفحات تیک‌خورده استخراج و در آزمون قرار می‌گیرند؛ مثلاً اگر فقط صفحه ۵ و ۶ را لازم دارید همان دو را تیک بزنید.</small>
      </div>
      <div id="imgNoPick" style="display:none">فایل تصویری است (یک صفحه) — نیازی به انتخاب صفحه نیست؛ روی «شروع بارگذاری» بزنید.</div>
    </div>
    <div id="uploadStepProgress" class="upload-step-body" style="display:none">
      <div class="progress-label" id="uploadProgressText">در حال بارگذاری...</div>
      <div class="progressbar"><div id="uploadProgressBar"></div></div>
      <div class="progress-label" id="renderProgressText" style="margin-top:10px"></div>
    </div>
    <div id="uploadStepDone" class="upload-step-body" style="display:none"><b>فایل آماده شد و بدون رفرش صفحه روی برگه آزمون قرار گرفت.</b></div>
    <div class="upload-actions"><button type="button" class="btn btn-secondary" onclick="closeSourceUploadModal()">انصراف</button><button type="button" class="btn btn-primary" id="uploadNextBtn" onclick="sourceUploadNext()">ادامه</button></div>
  </div>
</div>
<div class="exam-zoom-modal" id="examZoomModal" onclick="if(event.target===this)closeExamZoom()">
  <div class="exam-zoom-card">
    <button class="exam-zoom-close" onclick="closeExamZoom()">بستن</button>
    <div id="examZoomPages"></div>
  </div>
</div>
<div id="imageEditorModal" class="image-modal"><div class="image-card"><div style="display:flex;justify-content:space-between;align-items:center"><b>ویرایشگر حرفه‌ای تصویر سوال</b><button onclick="closeImageEditor()" style="background:#ef4444;color:white;border:0;border-radius:8px;padding:5px 12px">×</button></div><div class="image-editor-grid"><div class="image-canvas-wrap"><canvas id="imageEditCanvas" width="800" height="500"></canvas></div><div class="image-controls"><label>انتخاب تصویر</label><input type="file" id="imageEditFile" accept="image/*" onchange="loadImageForEdit(this)"><label>برش از چپ</label><input type="range" id="imgCropL" min="0" max="40" value="0" oninput="renderImageEdit()"><label>برش از راست</label><input type="range" id="imgCropR" min="0" max="40" value="0" oninput="renderImageEdit()"><label>برش از بالا</label><input type="range" id="imgCropT" min="0" max="40" value="0" oninput="renderImageEdit()"><label>برش از پایین</label><input type="range" id="imgCropB" min="0" max="40" value="0" oninput="renderImageEdit()"><label>عرض خروجی</label><input type="range" id="imgOutW" min="80" max="800" value="420" oninput="renderImageEdit()"><label>روشنایی</label><input type="range" id="imgBright" min="-80" max="80" value="0" oninput="renderImageEdit()"><label>کنتراست</label><input type="range" id="imgContrast" min="-80" max="80" value="0" oninput="renderImageEdit()"><label><input type="checkbox" id="imgRemoveBg" onchange="renderImageEdit()"> حذف پس‌زمینه روشن/سفید</label><label>شدت حذف پس‌زمینه</label><input type="range" id="imgBgThreshold" min="180" max="255" value="238" oninput="renderImageEdit()"><div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px"><button class="btn-save" onclick="insertEditedImageToQuestion()">درج تصویر در سوال</button><button onclick="closeImageEditor()">انصراف</button></div><small>همه پردازش‌ها داخل مرورگر و بدون CDN انجام می‌شود. خروجی WebP فشرده با حداکثر ۸۰۰px است.</small></div></div></div></div><div id="mathModal" class="math-modal"><div class="math-card"><div style="display:flex;justify-content:space-between;align-items:center"><b>ویرایشگر سریع ریاضی / LaTeX ساده</b><button onclick="closeMathModal()" style="background:#ef4444;color:white;border:0;border-radius:8px;padding:5px 12px">×</button></div><p style="font-size:12px;color:#64748b">فرمول را با دکمه‌ها بسازید یا LaTeX ساده بنویسید؛ پیش‌نمایش به یونیکد/فرمت چاپی تبدیل می‌شود.</p><textarea id="mathInput" style="width:100%;min-height:70px;direction:ltr;text-align:left;border:1px solid #cbd5e1;border-radius:8px;padding:8px" oninput="updateMathPreview()" placeholder="مثلاً: \frac{2}{3}+\sqrt{x^2}+\alpha"></textarea><div class="math-grid" id="mathButtons"></div><div class="math-preview" id="mathPreview"></div><div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px"><button onclick="insertMathToQuestion()" class="btn-save">درج در نوشته</button><button onclick="closeMathModal()">انصراف</button></div></div></div>
<!-- v4.78.0: پنجره انتخاب کیفیت چاپ -->
<div id="printQualityModal" class="upload-modal" style="display:none">
  <div class="upload-card">
    <button type="button" class="upload-close" onclick="closePrintQualityModal()">×</button>
    <h3>کیفیت چاپ آزمون</h3>
    <p style="font-size:12px;color:#64748b" id="pqInfo"></p>
    <div class="upload-step-body" style="display:grid;gap:8px">
      <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer"><input type="radio" name="printQuality" value="high" checked style="margin-top:3px">
        <span><b>کیفیت بالا (پیش‌فرض)</b><br><small style="color:#64748b">تصاویر با همان کیفیت کامل چاپ می‌شوند — مناسب سیستم‌های جدید و آزمون‌های با جزئیات ریز.</small></span></label>
      <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer"><input type="radio" name="printQuality" value="medium" style="margin-top:3px">
        <span><b>کیفیت متوسط (سریع‌تر)</b><br><small style="color:#64748b">تصاویر تا عرض ۱۴۰۰ پیکسل سبک می‌شوند — برای متن و اشکال معمولی تفاوت محسوسی در چاپ ندارد و آماده‌سازی بسیار سریع‌تر است.</small></span></label>
      <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer"><input type="radio" name="printQuality" value="low" style="margin-top:3px">
        <span><b>کیفیت اقتصادی (سریع‌ترین)</b><br><small style="color:#64748b">تصاویر تا عرض ۹۰۰ پیکسل — مخصوص سیستم‌ها و پرینترهای قدیمی یا آزمون‌های چندصفحه‌ای پرتصویر که چاپ سنگین می‌شود.</small></span></label>
    </div>
    <p style="font-size:11px;color:#64748b;margin:4px 0">کیفیت انتخابی فقط روی «چاپ» اثر می‌گذارد؛ طراحی ذخیره‌شده آزمون همیشه با کیفیت کامل باقی می‌ماند و انتخاب شما برای دفعات بعد به خاطر سپرده می‌شود.</p>
    <div id="pqProgressWrap" style="display:none;margin:8px 0">
      <div id="pqProgressText" style="font-size:11px;margin-bottom:4px;color:#334155">در حال آماده‌سازی...</div>
      <div class="progressbar"><div id="pqProgressBar"></div></div>
    </div>
    <div class="upload-actions"><button type="button" class="btn btn-secondary" onclick="closePrintQualityModal()">انصراف</button><button type="button" class="btn btn-primary" id="pqGoBtn" onclick="startQualityPrint()">آماده‌سازی و چاپ</button></div>
  </div>
</div>
<div id="pagesRoot"></div>
<script>
/* v4.69.0: برگه نباید زیر نوار ابزار برود — ارتفاع واقعی نوار (حتی چندخطی) اندازه‌گیری می‌شود */
function syncToolbarOffset(){
  var tb=document.querySelector('.toolbar');
  if(tb){document.documentElement.style.setProperty('--toolbarH',(tb.offsetHeight+14)+'px');}
}
window.addEventListener('load',syncToolbarOffset);
window.addEventListener('resize',syncToolbarOffset);
setTimeout(syncToolbarOffset,300);
setTimeout(syncToolbarOffset,1200);
const studentsData=<?php echo json_encode($studentsData, JSON_UNESCAPED_UNICODE); ?>;
const examData=<?php echo json_encode($examData, JSON_UNESCAPED_UNICODE); ?>;
const examId=<?php echo (int)$examId; ?>;
const designToken=<?php echo json_encode($designToken, JSON_UNESCAPED_UNICODE); ?>;
const canEditBank=<?php echo is_admin_logged_in() ? 'true' : 'false'; ?>;
let previewOnly=true;
let qItems=[], bankItems=[], drawingsData={}, sourceCrops={}, sourceOrder=[], pageStyle={}, printNote='', selectedImageId=null, tool='off', drawing=false, last=null, booting=true, printSavePromptPending=false, canvasTouched={};
let headerFields=[{key:'studentName',label:'نام',source:'name',on:true},{key:'class',label:'کلاس',source:'class_name',on:true},{key:'teacher',label:'دبیر',value:examData.teacher,on:true},{key:'subject',label:'درس',value:examData.subject,on:true},{key:'seat',label:'صندلی',source:'seat',on:true},{key:'date',label:'تاریخ',value:examData.date,on:true},{key:'time',label:'ساعت',value:examData.time,on:true},{key:'score',label:'نمره',value:'',on:true}];
/* v4.92.0: در طرح‌های ذخیره‌شده قدیمی هم جای «دبیر» و «صندلی» عوض می‌شود */
/* v4.93.0: در طرح‌های ذخیره‌شده قدیمی، سربرگ کوتاه‌تر از ۳۹mm به ۳۹ ارتقا می‌یابد
   (فونت‌های جدید جای بیشتری می‌خواهند)؛ اسلایدر همچنان آزاد است. */
function fixLoadedHeaderH(st){if(st&&st['--headerH']){const n=parseFloat(st['--headerH']); if(!isNaN(n)&&n<39) st['--headerH']='39mm';} return st;}
function fixHeaderFieldOrder(list){if(!Array.isArray(list))return list; const si=list.findIndex(f=>f&&f.key==='seat'), ti=list.findIndex(f=>f&&f.key==='teacher'); if(si>-1&&ti>-1&&si<ti){const t=list[si]; list[si]=list[ti]; list[ti]=t;} 
/* v4.95.0: نام دبیر همیشه از سرور می‌آید (فقط «آقای + نام خانوادگی») —
   مقدار ذخیره‌شده قدیمی که نام کوچک داشت هرگز نمایش داده نمی‌شود. */
list.forEach(f=>{if(f&&f.key==='teacher') f.value=examData.teacher;});
return list;}
function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
function headerHtml(st,pageNo){if(pageNo%2===0)return ''; let fields=headerFields.filter(f=>f.on).map(f=>`<div class="box">${esc(f.label)}: ${esc(f.value!==undefined&&f.value!==''?f.value:st[f.source])}</div>`).join(''); return `<div class="exam-header"><div class="photo">${st.photo?`<img src="${esc(st.photo)}">`:'عکس'}</div><div><div class="hcenter"><b>${esc(examData.title)}</b>${examData.subtitle?`<br><span>${esc(examData.subtitle)}</span>`:''}</div><div class="meta">${fields}</div></div><div class="stampcol"><div class="stamp">${examData.stamp?`<img src="${esc(examData.stamp)}">`:'مهر'}</div><div class="seatno">${esc(st.seat!==undefined&&st.seat!==null?st.seat:'')}</div></div></div>`;}
function sourceHtml(pageNo){const order=sourceOrder.length?sourceOrder:defaultSourceOrder(); const srcIndex=order[pageNo-1]; const src=srcIndex?((examData.sourcePages||[])[srcIndex-1]):''; const c=sourceCrops[pageNo]||{t:0,r:0,b:0,l:0,w:100,x:0,y:0,contrast:100,brightness:100,bg:0}; const blend=(+c.bg>0)?'multiply':'normal'; if(src)return `<div class="source-file" data-source-page="${pageNo}" data-cropt="${c.t||0}" data-cropb="${c.b||0}" style="--cropT:${c.t}%;--cropR:${c.r}%;--cropB:${c.b}%;--cropL:${c.l}%;--srcW:${c.w}%;--srcX:${c.x||0}mm;--srcY:${c.y||0}mm;--srcContrast:${c.contrast||100}%;--srcBrightness:${c.brightness||100}%;--srcBlend:${blend}"><img src="${esc(src)}" onload="applySourceCropFits()"></div>`; return '';}
/* v4.101.0: ارتفاع layout بلوک منبع = فقط بخش دیده‌شده (برش بالا/پایین حذف) —
   جدول سوالات دقیقا چسبیده به زیر قسمت برش‌خورده شروع می‌شود. */
let sourceFitSigs={};
function applySourceCropFits(){let changed=false; document.querySelectorAll('.source-file').forEach(sf=>{const img=sf.querySelector('img'); if(!img||!img.complete||!img.naturalWidth)return; const w=img.getBoundingClientRect().width||sf.clientWidth; if(!w)return; const fullH=w*img.naturalHeight/img.naturalWidth; const t=+sf.dataset.cropt||0,b=+sf.dataset.cropb||0; const visH=Math.max(0,fullH*(100-t-b)/100); img.style.marginTop=(-fullH*t/100)+'px'; sf.style.height=visH+'px'; const key=(sf.closest('.page')?.dataset.studentId||'')+'_'+(sf.dataset.sourcePage||''); const sig=Math.round(visH)+'_'+t+'_'+b; if(sourceFitSigs[key]!==sig){sourceFitSigs[key]=sig; changed=true;}}); if(changed) scheduleQuestionReflow(); return changed;}
let reflowTimer=null;
function scheduleQuestionReflow(){clearTimeout(reflowTimer); reflowTimer=setTimeout(()=>{renderQuestions();},120);}
function pageStyleAttr(){/* v4.101.0: عکس/مهر/کادر/عرض/ارتفاع/فاصله/سربرگ همه ثابت‌اند — مقادیر ذخیره‌شده قدیمی نادیده گرفته می‌شود */ return Object.entries(pageStyle).filter(([k])=>k!=='--photo'&&k!=='--stamp'&&k!=='--qH'&&k!=='--qW'&&k!=='--qTop'&&k!=='--qBorder'&&k!=='--headerH').map(([k,v])=>`${k}:${v}`).join(';');}
function pageHtml(st,pageNo){return `<div class="page ${pageNo%2===0?'no-header':''}" style="${pageStyleAttr()}" data-student-id="${st.id}" data-page-index="${pageNo}">${headerHtml(st,pageNo)}<div class="qwrap"><canvas class="drawCanvas"></canvas>${sourceHtml(pageNo)}<table class="questions-table"><thead><tr><th class="q-no">شماره</th><th>سوال</th><th class="q-score">بارم</th></tr></thead><tbody class="questions-body"></tbody></table></div></div>`;}
function ensurePages(n){const activeStudents=previewOnly?[studentsData[0]]:studentsData; activeStudents.forEach(st=>{let count=document.querySelectorAll(`.page[data-student-id="${st.id}"]`).length; const root=document.getElementById('pagesRoot'); while(count<n){const html=pageHtml(st,count+1); const pages=[...document.querySelectorAll(`.page[data-student-id="${st.id}"]`)]; const last=pages[pages.length-1]; if(last) last.insertAdjacentHTML('afterend',html); else root.insertAdjacentHTML('beforeend',html); count++;}}); renderHeaders(); resizeCanvases();}
function sourcePageCount(){return Math.max(1,(sourceOrder.length?sourceOrder.length:(examData.sourcePages||[]).length));}
function rerenderPages(){document.getElementById('pagesRoot').innerHTML=''; ensurePages(sourcePageCount()); renderQuestions();}
function questionRow(it){return `<tr class="question-row" draggable="true" data-qid="${it.id}"><td class="q-no">${it.no}</td><td class="q-content" style="height:${it.height}mm;font-family:${it.font};font-size:${it.fontSize}px">${it.html}<div class="q-actions"><button onclick="editQuestion('${it.id}')">ویرایش</button><button onclick="deleteQuestion('${it.id}')">حذف</button></div></td><td class="q-score">${it.score}</td></tr>`;}
function renumberQuestions(){/* v4.101.0: شماره دستی کاربر دیگر بازنویسی نمی‌شود — فقط شماره بعدی پیشنهاد می‌شود */ let mx=0; qItems.forEach(it=>{if(it.type!=='q')return; const v=parseInt(String(it.no??'').replace(/[۰-۹]/g,d=>String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d))),10); if(!isNaN(v)&&v>mx)mx=v;}); const inp=document.getElementById('qNumber'); if(inp && document.getElementById('editingQid').value==='') inp.value=mx+1;}
function pageHasSource(page){const order=sourceOrder.length?sourceOrder:defaultSourceOrder(); return !!(order[page-1]&&(examData.sourcePages||[])[order[page-1]-1]);}
function renderQuestions(){collectDrawings();renumberQuestions();document.getElementById('pagesRoot').innerHTML=''; /* v4.102.0: سوالات فرم فقط بعد از صفحات فایل منبع قرار می‌گیرند */ const srcN=(examData.sourcePages||[]).length?sourcePageCount():0; let page=srcN>0?srcN+1:1; ensurePages(Math.max(1,srcN)); applySourceCropFits(); qItems.forEach(it=>{ if(it.type==='page'){page++; ensurePages(page); return;} ensurePages(page); appendItemToPage(it,page); if(isPageOverflow(page) && questionCountOnPage(page)>1){removeItemFromPage(it.id,page); page++; ensurePages(page); appendItemToPage(it,page);} }); toggleEmptyTables(); enableDrag(); resizeCanvases(); restoreDrawings(); autosaveLocal(); }
function toggleEmptyTables(){/* v4.102.0: روی صفحات منبع که سوالی ندارند، جدول خالی (سرستون شماره/سوال/بارم) نمایش داده نمی‌شود */ document.querySelectorAll('.questions-table').forEach(t=>{const b=t.querySelector('.questions-body'); t.style.display=(b&&b.children.length)?'':'none';});}
function questionCountOnPage(page){const first=studentsData[0]; return document.querySelectorAll(`.page[data-student-id="${first.id}"][data-page-index="${page}"] .question-row`).length;}
function isPageOverflow(page){const first=studentsData[0]; const q=document.querySelector(`.page[data-student-id="${first.id}"][data-page-index="${page}"] .qwrap`); if(!q)return false; return q.scrollHeight > q.clientHeight + 4;}
function appendItemToPage(it,page){document.querySelectorAll(`.page[data-page-index="${page}"] .questions-body`).forEach(tb=>tb.insertAdjacentHTML('beforeend',questionRow(it)));}
function removeItemFromPage(id,page){document.querySelectorAll(`.page[data-page-index="${page}"] [data-qid="${id}"]`).forEach(r=>r.remove());}
function setv(k,v){pageStyle[k]=v;document.querySelectorAll('.page').forEach(p=>p.style.setProperty(k,v)); resizeCanvases(); autosaveLocal();}
function toggleEditor(){document.getElementById('questionEditorPanel').classList.toggle('collapsed');}
/* v4.102.0: مودال «ثبت سوال جدید» — آماده‌سازی سوال با پیش‌نمایش زنده و درج در آزمون */
function openQuestionModal(editId){const m=document.getElementById('questionModal'); m.style.display='flex'; document.body.classList.add('modal-open'); if(!editId){clearQuestionForm(); renumberQuestions(); document.getElementById('qModalTitle').textContent='آماده‌سازی سوال جدید'; document.getElementById('qInsertBtn').textContent='درج در آزمون';} updateQuestionPreview(); setTimeout(()=>{const ed=document.getElementById('qText'); ed.focus(); const r=document.createRange(); r.selectNodeContents(ed); r.collapse(false); const sel=getSelection(); sel.removeAllRanges(); sel.addRange(r);},60);}
function closeQuestionModal(){document.getElementById('questionModal').style.display='none'; document.body.classList.remove('modal-open'); document.getElementById('editingQid').value='';}
function showToast(msg){let t=document.getElementById('uiToast'); if(!t){t=document.createElement('div'); t.id='uiToast'; document.body.appendChild(t);} t.textContent=msg; requestAnimationFrame(()=>{t.classList.add('show');}); clearTimeout(t._h); t._h=setTimeout(()=>t.classList.remove('show'),2600);}
/* v4.102.0: ESC هر مودال باز را می‌بندد؛ Ctrl+S ذخیره طراحی؛ Ctrl+Enter در مودال سوال = درج */
document.addEventListener('keydown',e=>{
  if(e.key==='Escape'){
    const qm=document.getElementById('questionModal'); if(qm&&qm.style.display!=='none'&&qm.style.display!==''){closeQuestionModal();return;}
    const mm=document.getElementById('mathModal'); if(mm&&mm.style.display==='flex'){closeMathModal();return;}
    const im=document.getElementById('imageEditorModal'); if(im&&im.style.display==='flex'){closeImageEditor();return;}
    const zm=document.getElementById('examZoomModal'); if(zm&&zm.style.display==='flex'){closeExamZoom();return;}
    const um=document.getElementById('sourceUploadModal'); if(um&&um.style.display==='flex'){closeSourceUploadModal();return;}
    const pm=document.getElementById('printQualityModal'); if(pm&&pm.style.display==='flex'){closePrintQualityModal();return;}
  }
  if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='s'){e.preventDefault(); saveDesign(true).then(j=>showToast(j&&j.ok?'طراحی ذخیره شد':'خطا در ذخیره طراحی'));}
  if((e.ctrlKey||e.metaKey)&&e.key==='Enter'){const qm=document.getElementById('questionModal'); if(qm&&qm.style.display==='flex'){e.preventDefault(); saveQuestion();}}
});
function updateQuestionPreview(){const c=document.getElementById('qPrevContent'); if(!c)return; c.innerHTML=document.getElementById('qText').innerHTML; c.style.fontFamily=document.getElementById('qFont').value; c.style.fontSize=document.getElementById('qFontSize').value+'px'; c.style.height=document.getElementById('qHeight').value+'mm'; document.getElementById('qPrevNo').textContent=document.getElementById('qNumber').value||'۱'; document.getElementById('qPrevScore').textContent=document.getElementById('qScore').value||''; const fv=document.getElementById('qFontSizeVal'); if(fv)fv.textContent=(+document.getElementById('qFontSize').value).toLocaleString('fa-IR'); const hv=document.getElementById('qHeightVal'); if(hv)hv.textContent=(+document.getElementById('qHeight').value).toLocaleString('fa-IR');}
function toggleBank(){document.getElementById('bankPanel').classList.toggle('collapsed');}
function cmd(c){document.execCommand(c,false,null);document.getElementById('qText').focus();}
function clearQuestionForm(){document.getElementById('editingQid').value='';document.getElementById('qText').innerHTML='';document.getElementById('qScore').value='1';document.getElementById('qHeight').value='18';document.getElementById('qModalTitle').textContent='آماده‌سازی سوال جدید';document.getElementById('qInsertBtn').textContent='درج در آزمون';applyQuestionTemplate();renumberQuestions();updateQuestionPreview();}
function applyQuestionTemplate(){const t=document.getElementById('qType').value, ed=document.getElementById('qText'); if(t==='text')ed.innerHTML='<p>صورت سوال تشریحی را اینجا بنویسید...</p>'; if(t==='mcq')ed.innerHTML='<p>صورت سوال چندگزینه‌ای را اینجا بنویسید.</p><div class="q-options"><span>الف) گزینه اول</span><span>ب) گزینه دوم</span><span>ج) گزینه سوم</span><span>د) گزینه چهارم</span></div>'; if(t==='blank')ed.innerHTML='<p>عبارت زیر را کامل کنید: <span class="blank-line"></span></p>'; if(t==='tf')ed.innerHTML='<p>متن عبارت... <span class="tf-square"></span> صحیح <span class="tf-square"></span> غلط</p>'; applyFontToEditor(); updateQuestionPreview();}
function applyFontToEditor(){const ed=document.getElementById('qText'); ed.style.fontFamily=document.getElementById('qFont').value; ed.style.fontSize=document.getElementById('qFontSize').value+'px'; updateQuestionPreview();}
function loadCustomFont(input){const file=input.files&&input.files[0]; if(!file)return; const name='CustomFont_'+Date.now(); const r=new FileReader(); r.onload=e=>{const st=document.createElement('style'); st.textContent=`@font-face{font-family:${name};src:url(${e.target.result})}`; document.head.appendChild(st); document.getElementById('qFont').appendChild(new Option(file.name,name,true,true)); applyFontToEditor();}; r.readAsDataURL(file);}
function imageQualityBySize(bytes){ if(bytes>10*1024*1024) return 0; if(bytes>5*1024*1024) return .30; if(bytes>1*1024*1024) return .50; return .80; }
function compressImageFile(file){return new Promise((resolve,reject)=>{const q=imageQualityBySize(file.size); if(!q){reject('حجم تصویر بیشتر از ۱۰ مگابایت است.'); return;} const img=new Image(); const r=new FileReader(); r.onload=e=>{img.onload=()=>{const max=800, scale=Math.min(1,max/Math.max(img.width,img.height)); const c=document.createElement('canvas'); c.width=Math.round(img.width*scale); c.height=Math.round(img.height*scale); c.getContext('2d').drawImage(img,0,0,c.width,c.height); resolve(c.toDataURL('image/webp',q));}; img.src=e.target.result;}; r.readAsDataURL(file);});}
function insertQuestionImage(input){[...(input.files||[])].forEach(file=>{compressImageFile(file).then(src=>{const id='img_'+Date.now()+'_'+Math.random().toString(16).slice(2); document.getElementById('qText').focus(); document.execCommand('insertHTML',false,`<img src="${src}" data-imgid="${id}" style="width:220px;height:auto;position:absolute;left:0px;top:0px" onclick="selectQuestionImage('${id}',this)">`);}).catch(msg=>alert(msg));}); input.value='';}
function selectQuestionImage(id,img){selectedImageId=id; document.querySelectorAll('.selected-img').forEach(i=>i.classList.remove('selected-img')); document.querySelectorAll(`[data-imgid="${id}"]`).forEach(i=>i.classList.add('selected-img')); document.getElementById('imgSize').value=parseInt(img.style.width)||220;}
function resizeSelectedImage(w){if(!selectedImageId)return; document.querySelectorAll(`[data-imgid="${selectedImageId}"]`).forEach(img=>{img.style.width=w+'px';img.style.height='auto';}); updateModelFromDomImage(); syncRenderedImagesToModel();}
function updateModelFromDomImage(){const edit=document.getElementById('editingQid').value; if(edit){const it=qItems.find(x=>x.id===edit); if(it)it.html=document.getElementById('qText').innerHTML;}}
function previewQuestionHeight(v){updateQuestionPreview(); const id=document.getElementById('editingQid').value; if(id){const it=qItems.find(x=>x.id===id); if(it){it.height=v;renderQuestions();}}}
function saveQuestion(){const edit=document.getElementById('editingQid').value, no=document.getElementById('qNumber').value||'1', score=document.getElementById('qScore').value||'', html=document.getElementById('qText').innerHTML.trim(), height=document.getElementById('qHeight').value||18, font=document.getElementById('qFont').value, fontSize=document.getElementById('qFontSize').value||11, qtype=document.getElementById('qType').value; if(!html){alert('متن سوال را وارد کنید.');return;} if(edit){const it=qItems.find(x=>x.id===edit); if(!it){document.getElementById('editingQid').value=''; qItems.push({type:'q',id:'q_'+Date.now(),qtype,no,score,html,height,font,fontSize});} else Object.assign(it,{no,score,html,height,font,fontSize,qtype});} else qItems.push({type:'q',id:'q_'+Date.now(),qtype,no,score,html,height,font,fontSize}); renderQuestions(); clearQuestionForm(); autosaveLocal(); closeQuestionModal(); showToast(edit?'سوال ویرایش و در برگه به‌روزرسانی شد':'سوال در آزمون درج شد');}
function editQuestion(id){const it=qItems.find(x=>x.id===id); if(!it)return; document.getElementById('editingQid').value=id; document.getElementById('qNumber').value=it.no; document.getElementById('qScore').value=it.score; document.getElementById('qText').innerHTML=it.html; document.getElementById('qHeight').value=it.height; document.getElementById('qFont').value=it.font; document.getElementById('qFontSize').value=it.fontSize; document.getElementById('qModalTitle').textContent='ویرایش سوال'; document.getElementById('qInsertBtn').textContent='ذخیره تغییرات'; applyFontToEditor(); openQuestionModal(id);}
function deleteQuestion(id){if(confirm('سوال حذف شود؟')){qItems=qItems.filter(x=>x.id!==id); if(document.getElementById('editingQid').value===id) clearQuestionForm(); renderQuestions(); autosaveLocal();}}
function addBlankPage(){qItems.push({type:'page',id:'pg_'+Date.now()}); renderQuestions(); autosaveLocal();}
function deleteLastEmptyPage(){
  const first=studentsData[0]; const pages=[...document.querySelectorAll(`.page[data-student-id="${first.id}"]`)];
  if(pages.length<=1){alert('صفحه اول قابل حذف نیست.');return;}
  const last=pages[pages.length-1], pno=last.dataset.pageIndex;
  const hasQ=last.querySelector('.question-row'); const hasSrc=last.querySelector('.source-file img');
  if(hasQ||hasSrc){alert('آخرین صفحه خالی نیست. ابتدا محتوای آن را حذف کنید.');return;}
  const lastPageBreak=[...qItems].reverse().find(it=>it.type==='page');
  if(lastPageBreak) qItems=qItems.filter(it=>it.id!==lastPageBreak.id);
  else if(sourceOrder.length>=pno) sourceOrder.pop();
  renderQuestions(); autosaveLocal();
}
function enableDrag(){document.querySelectorAll('.question-row').forEach(row=>{row.ondragstart=e=>{row.classList.add('dragging');e.dataTransfer.setData('text/plain',row.dataset.qid)};row.ondragend=()=>{row.classList.remove('dragging');syncOrderFromFirst();};row.ondragover=e=>e.preventDefault();row.ondrop=e=>{e.preventDefault();const dragged=document.querySelector('.dragging');if(dragged&&dragged!==row)row.parentNode.insertBefore(dragged,row.nextSibling);};}); enableImageDrag();}
function syncOrderFromFirst(){const first=studentsData[0]; const ids=[...document.querySelectorAll(`.page[data-student-id="${first.id}"] .question-row`)].map(r=>r.dataset.qid); const map=Object.fromEntries(qItems.map(x=>[x.id,x])); qItems=ids.map(id=>map[id]).filter(Boolean); renderQuestions();}
function enableImageDrag(){document.querySelectorAll('.q-content img').forEach(img=>{img.onpointerdown=e=>{selectQuestionImage(img.dataset.imgid,img); img.dataset.drag='1'; img.dataset.sx=e.clientX; img.dataset.sy=e.clientY; img.dataset.l=parseFloat(img.style.left||0); img.dataset.t=parseFloat(img.style.top||0); e.preventDefault();};});}
document.addEventListener('pointermove',e=>{const img=document.querySelector('.selected-img[data-drag="1"]'); if(!img)return; const dx=e.clientX-img.dataset.sx, dy=e.clientY-img.dataset.sy, l=(+img.dataset.l)+dx, t=(+img.dataset.t)+dy; document.querySelectorAll(`[data-imgid="${img.dataset.imgid}"]`).forEach(i=>{i.style.left=l+'px';i.style.top=t+'px';});});document.addEventListener('pointerup',()=>{document.querySelectorAll('[data-drag="1"]').forEach(i=>i.dataset.drag='0'); syncRenderedImagesToModel();});
function syncRenderedImagesToModel(){const first=studentsData[0]; qItems.forEach(it=>{if(it.type!=='q')return; const row=document.querySelector(`.page[data-student-id="${first.id}"] [data-qid="${it.id}"] .q-content`); if(row){const clone=row.cloneNode(true); clone.querySelector('.q-actions')?.remove(); it.html=clone.innerHTML;}}); autosaveLocal();}
function renderHeaderEditor(){const box=document.getElementById('headerFieldsBox');box.innerHTML='';headerFields.forEach((f,i)=>box.insertAdjacentHTML('beforeend',`<div class="header-field-row"><input type="checkbox" ${f.on?'checked':''} onchange="headerFields[${i}].on=this.checked;rerenderPages()"><input value="${esc(f.label)}" oninput="headerFields[${i}].label=this.value;rerenderPages()"><input value="${esc(f.value||'')}" placeholder="مقدار ثابت/خالی" oninput="headerFields[${i}].value=this.value;rerenderPages()"><button onclick="headerFields.splice(${i},1);renderHeaderEditor();rerenderPages()">×</button></div>`));}
function addHeaderField(){const l=document.getElementById('newHeaderLabel').value||'فیلد';const v=document.getElementById('newHeaderValue').value||'';headerFields.push({key:'custom_'+Date.now(),label:l,value:v,on:true});document.getElementById('newHeaderLabel').value='';document.getElementById('newHeaderValue').value='';renderHeaderEditor();rerenderPages();}
function renderHeaders(){document.querySelectorAll('.page').forEach(p=>{const st=studentsData.find(s=>String(s.id)===String(p.dataset.studentId));const header=p.querySelector('.exam-header'); if(header&&st){const meta=header.querySelector('.meta'); meta.innerHTML=headerFields.filter(f=>f.on).map(f=>`<div class="box">${esc(f.label)}: ${esc(f.value!==undefined&&f.value!==''?f.value:st[f.source])}</div>`).join('');}});}
/* v4.84.0: paint every draw-canvas from drawingsData (the saved handwriting —
   including strokes made by OTHER users on OTHER devices/platforms), then run
   cb once all page images have actually been drawn. This is what makes the
   eraser selective everywhere: the live canvas gets the full saved artwork
   before the static overlay is hidden, so the mouse eraser removes only the
   pixels it touches instead of "everything vanishing". */
function syncCanvasesFromData(cb){
  const canv=[...document.querySelectorAll('.drawCanvas')]; let pending=0, fired=false;
  const fin=()=>{ if(!fired && pending===0){ fired=true; if(cb)cb(); } };
  canv.forEach(c=>{const page=c.closest('.page').dataset.pageIndex, data=drawingsData[page]; const ctx=c.getContext('2d'); ctx.globalCompositeOperation='source-over'; /* v4.103.0: بعد از پاک‌کن، حالت ترکیب بوم destination-out می‌ماند و بازنقاشی دست‌نوشته را «پاک» می‌کرد — ریست به source-over */ ctx.clearRect(0,0,c.width,c.height);
    if(data){ pending++; const img=new Image();
      img.onload=()=>{ try{ctx.globalCompositeOperation='source-over'; ctx.drawImage(img,0,0,c.width,c.height);}catch(e){} pending--; fin(); };
      img.onerror=()=>{ pending--; fin(); };
      img.src=data; }
  });
  fin();
}
let drawModeSeq=0;
function setTool(t){tool=t; const seq=++drawModeSeq;
/* v4.102.0: هایلایت ابزار فعال در نوار ابزار */
[['toolBtnPen','pen'],['toolBtnEraser','eraser'],['toolBtnOff','off']].forEach(([id,v])=>{const b=document.getElementById(id); if(b)b.classList.toggle('tool-active',t===v);});
/* v4.84.0: entering a drawing tool first REPAINTS the canvases from the saved
   handwriting and only then hides the overlay images — strokes saved by any
   user on any device stay visible and individually erasable. */
if(t!=='off'){
  syncCanvasesFromData(()=>{ if(seq===drawModeSeq && tool!=='off') document.body.classList.add('drawing-mode'); });
} else {
  document.body.classList.remove('drawing-mode');
  collectDrawings(); applyDrawingOverlays(); document.querySelectorAll('.page').forEach(p=>p.classList.toggle('questions-hidden'));
}
document.querySelectorAll('.qwrap').forEach(w=>{w.classList.toggle('draw-on',t!=='off'); if(t==='pen'){const color=(document.getElementById('penColor')?.value||'#111111').replace('#','%23'); w.style.cursor=`url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='24' height='24'><circle cx='12' cy='12' r='7' fill='none' stroke='${color}' stroke-width='3'/></svg>") 12 12, crosshair`; } else if(t==='eraser'){w.style.cursor=`url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='24' height='24'><rect x='5' y='5' width='14' height='14' fill='white' stroke='black' stroke-width='2'/></svg>") 12 12, cell`; } else {w.style.cursor='default';}});}
function resizeCanvases(){document.querySelectorAll('.drawCanvas').forEach(c=>{const r=c.parentElement.getBoundingClientRect();c.width=r.width;c.height=r.height;});
/* v4.84.0: resizing a canvas WIPES its bitmap (this was the second root cause
   of "everything disappears with the eraser on another device") — repaint the
   saved handwriting right away whenever a drawing tool is active. */
if(document.body.classList.contains('drawing-mode')||tool!=='off') syncCanvasesFromData();}
function canvasPos(e,c){const r=c.getBoundingClientRect();return{x:e.clientX-r.left,y:e.clientY-r.top};}
document.addEventListener('pointerdown',e=>{if(!e.target.classList.contains('drawCanvas')||tool==='off')return;drawing=true;last=canvasPos(e,e.target);});
document.addEventListener('pointermove',e=>{if(!drawing||!e.target.classList.contains('drawCanvas'))return;const c=e.target,p=canvasPos(e,c),page=c.closest('.page').dataset.pageIndex;drawStrokeOnPage(page,last,p,tool);last=p;});document.addEventListener('pointerup',()=>drawing=false);
function drawStrokeOnPage(page,a,b,t){canvasTouched[page]=true; document.querySelectorAll(`.page[data-page-index="${page}"] .drawCanvas`).forEach(c=>{const ctx=c.getContext('2d');ctx.lineCap='round';ctx.lineJoin='round';if(t==='eraser'){ctx.globalCompositeOperation='destination-out';ctx.lineWidth=18;}else{ctx.globalCompositeOperation='source-over';ctx.strokeStyle=(document.getElementById('penColor')?.value||'#111111');ctx.lineWidth=2;}ctx.beginPath();ctx.moveTo(a.x,a.y);ctx.lineTo(b.x,b.y);ctx.stroke();ctx.globalCompositeOperation='source-over';/* v4.103.0 */}); collectDrawings(); autosaveLocal();}
function clearDrawings(){document.querySelectorAll('.drawCanvas').forEach(c=>{c.getContext('2d').clearRect(0,0,c.width,c.height); const pg=c.closest('.page'); if(pg) canvasTouched[pg.dataset.pageIndex]=true;}); drawingsData={}; applyDrawingOverlays(); autosaveLocal();}
function designPayload(){collectDrawings();printNote=document.getElementById('printNote')?.value||printNote||'';return {questions:qItems,headerFields,drawings:drawingsData,sourceCrops,sourceOrder,style:pageStyle,printNote,academicYear:'<?php echo addslashes($exam['academic_year'] ?? ''); ?>',examMonth:'<?php echo addslashes($exam['exam_month'] ?? ''); ?>',updatedAt:new Date().toISOString()};}
function saveDesign(silent=false){const payload=designPayload(); autosaveLocal(); return fetch('exam-design-api.php?action=save&exam_id='+examId+'&dt='+encodeURIComponent(designToken),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({design:payload})}).then(r=>r.json()).then(j=>{if(!silent){if(j.ok)showToast(j.message||'طراحی ذخیره شد'); else alert(j.error||'خطا در ذخیره');} return j;});}
function loadDesignAndBank(){fetch('exam-design-api.php?action=load&exam_id='+examId+'&dt='+encodeURIComponent(designToken)).then(r=>r.json()).then(j=>{if(j.ok){let loaded=false; if(j.design){qItems=j.design.questions||[]; if(j.design.headerFields) headerFields=fixHeaderFieldOrder(j.design.headerFields); drawingsData=j.design.drawings||{}; sourceCrops=j.design.sourceCrops||{}; sourceOrder=j.design.sourceOrder||[]; pageStyle=fixLoadedHeaderH(j.design.style||{}); printNote=j.design.printNote||''; const pn=document.getElementById('printNote'); if(pn)pn.value=printNote; loaded=true; try{localStorage.removeItem(draftKey());}catch(e){} } const local=(!loaded)?loadLocalDraft():null; if(local){qItems=local.questions||[]; if(local.headerFields) headerFields=fixHeaderFieldOrder(local.headerFields); drawingsData=local.drawings||{}; sourceCrops=local.sourceCrops||{}; sourceOrder=local.sourceOrder||[]; pageStyle=fixLoadedHeaderH(local.style||{}); printNote=local.printNote||''; const pn=document.getElementById('printNote'); if(pn)pn.value=printNote; loaded=true;} renderHeaderEditor(); rerenderPages(); showBankSubject(j.subject,j.grade); populateBankFilters(j.filters); renderBank(j.bank||[]); renderDesignBank(j.designBank||[]); booting=false; autosaveLocal();}});}
function loadBank(){const q=document.getElementById('bankSearch').value||'', y=document.getElementById('bankYear').value||'', m=document.getElementById('bankMonth').value||'', t=document.getElementById('bankType').value||'', d=document.getElementById('bankDesigner').value||''; fetch('exam-design-api.php?action=load&exam_id='+examId+'&dt='+encodeURIComponent(designToken)+'&subject='+encodeURIComponent(q)+'&year='+encodeURIComponent(y)+'&month='+encodeURIComponent(m)+'&type='+encodeURIComponent(t)+'&designer='+encodeURIComponent(d)).then(r=>r.json()).then(j=>{if(j.ok){showBankSubject(j.subject,j.grade); populateBankFilters(j.filters); renderBank(j.bank||[]); renderDesignBank(j.designBank||[]);}});}
function renderBank(items){bankItems=items;const box=document.getElementById('bankList'); box.innerHTML=''; items.forEach(b=>{const gr=b.grade_level?` <span style="background:#e0e9ff;color:#1d4ed8;border-radius:5px;padding:0 6px;font-size:10px">${esc(b.grade_level)}</span>`:''; box.insertAdjacentHTML('beforeend',`<div class="bank-item"><b>${esc(b.subject_name)}</b>${gr} <small>${esc(b.academic_year||'')} ${esc(b.exam_month||'')}</small><div class="bank-q">${b.question_html}</div><div style="display:flex;gap:4px;margin-top:5px"><button onclick="insertBankQuestion(${b.id})">درج در برگه</button>${canEditBank?`<button onclick="deleteBankQuestion(${b.id})">حذف از بانک</button>`:''}</div></div>`);});}
/* v4.97.0: نشان درس جاری روی پنل بانک — یادآوری اینکه فقط محتوای هم‌درس نمایش داده می‌شود */
function showBankSubject(subj,grade){const el=document.getElementById('bankSubjectBadge'); if(!el)return; const g=grade?(' — پایه '+grade):((typeof examData!=='undefined'&&examData.gradeLevel)?(' — پایه '+examData.gradeLevel):''); el.textContent=subj?('درس: '+subj+g):''; el.style.display=subj?'inline-block':'none';} else el.style.display='none';}
/* v4.96.0: پرکردن لیست‌های بازشونده فیلتر بانک (سال/ماه/طراح) با حفظ انتخاب فعلی */
function populateBankFilters(f){if(!f)return; const fill=(id,items,valKey,labKey)=>{const el=document.getElementById(id); if(!el)return; const cur=el.value; while(el.options.length>1)el.remove(1); (items||[]).forEach(it=>{const v=valKey?it[valKey]:it, lab=labKey?it[labKey]:it; const o=document.createElement('option'); o.value=v; o.textContent=lab; el.appendChild(o);}); el.value=cur; if(el.value!==cur)el.value='';}; fill('bankYear',f.years); fill('bankMonth',f.months); fill('bankDesigner',f.designers,'v','label');}
/* v4.96.0: نمای صفحات آزمون ذخیره‌شده با تنظیمات اولین طراحی (چینش، برش، روشنایی، کنتراست، حذف پس‌زمینه، اندازه) — فایل اصلی دست نمی‌خورد، فقط نمایش/درج با تنظیمات است */
function bankPageViews(x){const pages=x.pages||[]; const order=(x.order&&x.order.length)?x.order:pages.map((_,i)=>i+1); const out=[]; order.forEach((srcIdx,i)=>{const src=pages[srcIdx-1]; if(!src)return; const c=(x.crops&&x.crops[i+1])||{}; const t=+c.t||0,r=+c.r||0,b=+c.b||0,l=+c.l||0,w=+c.w||100,ct=+c.contrast||100,br=+c.brightness||100; const blend=(+ (c.bg||0)>0)?'multiply':'normal'; out.push({src,style:`width:${w}%;clip-path:inset(${t}% ${r}% ${b}% ${l}%);filter:contrast(${ct}%) brightness(${br}%);mix-blend-mode:${blend}`});}); return out;}
/* v4.95.0: آزمون‌های کامل ذخیره‌شده در بانک — بندانگشتی صفحات + نام طراح + ماه/سال، زوم شناور، درج در ویرایشگر، حذف */
let designBankItems=[];
function renderDesignBank(items){designBankItems=items||[];const box=document.getElementById('designBankList'); if(!box)return; box.innerHTML=''; if(!designBankItems.length){box.innerHTML='<small>آزمون ذخیره‌شده‌ای با فایل منبع یافت نشد.</small>'; return;} designBankItems.forEach(x=>{const ref=x.ref||('e'+x.exam_id); const thumbs=bankPageViews(x).slice(0,6).map(p=>`<span style="display:inline-block;background:#fff;overflow:hidden;flex:none"><img src="${esc(p.src)}" loading="lazy" style="${p.style};height:86px;width:auto"></span>`).join(''); const archBadge=x.archived?` <small style="background:#fef3c7;color:#92400e;border-radius:5px;padding:1px 6px">نسخه قبلی (بایگانی)</small>`:''; box.insertAdjacentHTML('beforeend',`<div class="bank-exam"><b>${esc(x.subject||'')}</b> <small>${esc(x.grade||'')} ${esc(x.class||'')}</small>${archBadge}<br><small>طراح: ${esc(x.designer||'—')} | ماه: ${esc(x.month||'—')} | سال: ${esc(x.year||'—')}</small><div class="bank-exam-thumbs" onclick="openExamZoom('${ref}')" title="بزرگ‌نمایی صفحات">${thumbs}</div><div style="display:flex;gap:4px"><button onclick="importSavedExam('${ref}')">درج این آزمون در ویرایشگر</button>${canEditBank?`<button onclick="deleteSavedExam('${ref}')" style="background:#fee2e2">حذف از بانک</button>`:''}</div></div>`);});}
function openExamZoom(ref){const x=designBankItems.find(e=>(e.ref||('e'+e.exam_id))===String(ref)); if(!x)return; /* v4.100.0: وسط‌چینی فلکس مثل صفحه اصلی — تصویر با عرض بیش از ۱۰۰٪ دیگر از یک طرف بریده نمی‌شود */ document.getElementById('examZoomPages').innerHTML=`<div style="margin-bottom:8px"><b>${esc(x.subject||'')}</b> — طراح: ${esc(x.designer||'—')} | ماه: ${esc(x.month||'—')} | سال: ${esc(x.year||'—')}</div>`+bankPageViews(x).map(p=>`<div style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;margin-bottom:8px;overflow:hidden;display:flex;justify-content:center;align-items:flex-start"><img src="${esc(p.src)}" style="${p.style};border:0;margin:0;flex:none"></div>`).join(''); document.getElementById('examZoomModal').style.display='flex';}
function closeExamZoom(){document.getElementById('examZoomModal').style.display='none';}
function importSavedExam(ref){if(!confirm('آزمون انتخابی از بانک در ویرایشگر فعلی درج شود؟ طراحی فعلی جایگزین می‌شود.'))return; const fd=new FormData(); fd.append('action','import_design'); fd.append('exam_id',examId); fd.append('dt',designToken); fd.append('source_ref',ref); fetch('exam-design-api.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{if(!j.ok){alert(j.error||'خطا در درج آزمون'); return;} examData.sourcePages=j.sourcePages||[]; const d=j.design||{}; qItems=d.questions||[]; if(d.headerFields) headerFields=fixHeaderFieldOrder(d.headerFields); drawingsData=d.drawings||{}; sourceCrops=d.sourceCrops||{}; sourceOrder=d.sourceOrder||[]; pageStyle=fixLoadedHeaderH(d.style||{}); printNote=d.printNote||''; const pn=document.getElementById('printNote'); if(pn)pn.value=printNote; renderHeaderEditor(); rerenderPages(); autosaveLocal(); alert(j.message||'آزمون درج شد.');});}
function deleteSavedExam(ref){if(!confirm('این آزمون ذخیره‌شده به‌طور کامل از بانک حذف شود؟'))return; const fd=new FormData(); fd.append('action','delete_design'); fd.append('exam_id',examId); fd.append('dt',designToken); fd.append('source_ref',ref); fetch('exam-design-api.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{if(j.ok)loadBank(); else alert(j.error||'خطا');});}
function insertBankQuestion(id){const b=(bankItems||[]).find(x=>+x.id===+id); if(!b)return; /* v4.102.0: شماره ردیف بعدی به‌صورت خودکار */ let mx=0; qItems.forEach(it=>{if(it.type!=='q')return; const v=parseInt(String(it.no??'').replace(/[۰-۹]/g,d=>String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d))),10); if(!isNaN(v)&&v>mx)mx=v;}); qItems.push({type:'q',id:'q_'+Date.now(),bank_id:b.id,qtype:b.question_type||'text',no:mx+1,score:b.score||'',html:b.question_html,height:18,font:'Vazirmatn',fontSize:11}); renderQuestions(); showToast('سوال از بانک درج شد');}
function deleteBankQuestion(id){if(!confirm('سوال از بانک حذف شود؟'))return; const fd=new FormData(); fd.append('action','delete_bank'); fd.append('exam_id',examId); fd.append('question_id',id); fetch('exam-design-api.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{if(j.ok)loadBank();else alert(j.error||'خطا');});}
/* v4.79.0: خط لوله چاپ برای «بدترین سناریو» بازطراحی شد — ۳ کلاس × ۱۰۰
   دانش‌آموز × ۴ صفحه = ۴۰۰ صفحه چاپی باید بدون فریز و بدون کمبود حافظه
   آماده شود:
   1) سبک‌سازی تصاویر «قبل از» ساخت DOM انجام می‌شود (نه بعد از آن) تا اوج
      مصرف حافظه هرگز اتفاق نیفتد.
   2) هر تصویر یکتا (منبع/سوال/دست‌نویس) به یک Blob URL کوتاه تبدیل می‌شود؛
      قبلا data-URL چندصدکیلوبایتی دست‌نویس در HTML هر ۱۰۰ کپی تکرار می‌شد.
   3) صفحات به‌صورت تکه‌تکه (حدود ۲۴ صفحه در هر برش) خارج از DOM ساخته
      می‌شوند — بین برش‌ها کنترل به مرورگر برمی‌گردد و نوار پیشرفت به‌روز
      می‌شود؛ درج نهایی با یک عملیات واحد است (فقط یک reflow).
   4) canvas های خالی از کپی‌ها حذف می‌شوند؛ دست‌نویس به‌صورت تصویر ثابت
      باقی می‌ماند (دستاورد v4.77.0 حفظ شده). */
function designedWrapClones(assetMap){
  const first=studentsData[0];
  let firstPages=[...document.querySelectorAll(`.page[data-student-id="${first.id}"]`)];
  if(!firstPages.length){rerenderPages(); applyDrawingOverlays(); firstPages=[...document.querySelectorAll(`.page[data-student-id="${first.id}"]`)];}
  return firstPages.map(p=>{
    const w=p.querySelector('.qwrap'); if(!w) return '';
    const c=w.cloneNode(true);
    c.querySelectorAll('canvas.drawCanvas').forEach(x=>x.remove());   // canvases are useless in clones — huge memory win
    c.querySelectorAll('img').forEach(x=>{
      x.setAttribute('decoding','async');
      if(assetMap){const s=x.getAttribute('src')||''; if(assetMap[s]) x.setAttribute('src',assetMap[s]);}
    });
    return c.outerHTML;
  });
}
function studentPagesHtml(st,designedWraps){
  const styleAttr=pageStyleAttr();
  let h='';
  designedWraps.forEach((wrap,idx)=>{const pageNo=idx+1; h+=`<div class="page ${pageNo%2===0?'no-header':''}" style="${styleAttr}" data-student-id="${st.id}" data-page-index="${pageNo}">${headerHtml(st,pageNo)}${wrap}</div>`;});
  /* v4.103.0: چاپ دورو — آزمون با تعداد صفحات فردِ بزرگ‌تر از ۱، بعد از هر دانش‌آموز
     یک صفحه سفید می‌گیرد تا برگه نفر بعد پشت آخرین برگه نفر قبل چاپ نشود.
     آزمون تک‌صفحه‌ای یک‌رو چاپ می‌شود و صفحه سفید لازم ندارد. */
  if(designedWraps.length>1 && designedWraps.length%2===1) h+=`<div class="page no-header blank-sep" style="${styleAttr}" data-student-id="${st.id}" data-page-index="${designedWraps.length+1}"></div>`;
  return h;
}
function prepareAllStudents(){
  // مسیر همگام (فقط برای Ctrl+P مستقیم / beforeprint). مسیر اصلی چاپ،
  // prepareAllStudentsAsync است که تکه‌تکه و با نوار پیشرفت کار می‌کند.
  collectDrawings();
  applyDrawingOverlays();
  const designedWraps=designedWrapClones(null);
  previewOnly=false;
  const parts=[];
  studentsData.forEach(st=>parts.push(studentPagesHtml(st,designedWraps)));
  document.getElementById('pagesRoot').innerHTML=parts.join('');
}
function prepareAllStudentsAsync(assetMap,onProgress,cb){
  collectDrawings();
  applyDrawingOverlays();
  const designedWraps=designedWrapClones(assetMap);
  previewOnly=false;
  const holder=document.createElement('div');
  const total=studentsData.length; let i=0;
  const CHUNK=Math.max(1,Math.ceil(24/Math.max(1,designedWraps.length))); // ~۲۴ صفحه در هر برش
  const step=()=>{
    let html='';
    const end=Math.min(total,i+CHUNK);
    for(;i<end;i++) html+=studentPagesHtml(studentsData[i],designedWraps);
    holder.insertAdjacentHTML('beforeend',html);   // خارج از DOM — بدون reflow
    if(onProgress)onProgress(i,total);
    if(i<total){setTimeout(step,0);return;}        // بازگرداندن کنترل به مرورگر
    const root=document.getElementById('pagesRoot');
    const frag=document.createDocumentFragment();
    while(holder.firstChild)frag.appendChild(holder.firstChild);
    root.innerHTML='';
    root.appendChild(frag);                        // درج همه صفحات با یک عملیات
    cb();
  };
  step();
}
function waitForPrintReady(cb,maxMs){
  // v4.77.0: never fire window.print() on a half-rendered DOM (the old fixed
  // 550ms timer produced blank/garbled pages and printer-spooler overload on
  // slow systems). Wait until every image is decoded — capped so a broken
  // image can never hang the print button.
  const t0=Date.now(); maxMs=maxMs||15000;
  const imgs=[...document.querySelectorAll('#pagesRoot img')];
  const pending=imgs.filter(im=>!(im.complete&&im.naturalWidth>0));
  const done=()=>{requestAnimationFrame(()=>requestAnimationFrame(cb));};
  if(!pending.length){done();return;}
  let left=pending.length;
  const tick=()=>{if(--left<=0||Date.now()-t0>maxMs)done();};
  pending.forEach(im=>{
    if(im.decode){im.decode().then(tick,tick);}
    else{im.addEventListener('load',tick,{once:true});im.addEventListener('error',tick,{once:true});}
  });
  setTimeout(()=>{if(left>0){left=0;done();}},maxMs);
}
/* v4.92.0: دکمه «تکثیر برای همه و چاپ» حذف شد (عملکردش با «چاپ نهایی» یکسان بود).
   v4.78.0: دکمه «چاپ نهایی» ابتدا پنجره
   انتخاب کیفیت چاپ را باز می‌کنند؛ آماده‌سازی بر اساس کیفیت انتخابی انجام
   می‌شود. کیفیت پایین‌تر = تصاویر سبک‌تر = آماده‌سازی و چاپ بسیار سریع‌تر
   روی سیستم‌ها و پرینترهای قدیمی. */
function prepareAllAndPrint(){openPrintQualityModal();}
function openPrintQualityModal(){
  let saved='high'; try{saved=localStorage.getItem('examPrintQuality')||'high';}catch(e){}
  const r=document.querySelector(`input[name="printQuality"][value="${saved}"]`); if(r)r.checked=true;
  const pages=designedPageCount(); const total=pages*studentsData.length;
  const info=document.getElementById('pqInfo');
  if(info)info.textContent='این آزمون برای '+studentsData.length+' دانش‌آموز × '+pages+' صفحه = '+total+' صفحه چاپی آماده می‌شود.';
  const w=document.getElementById('pqProgressWrap'); if(w)w.style.display='none';
  document.getElementById('printQualityModal').style.display='flex';
}
function closePrintQualityModal(){if(printPrepBusy)return; document.getElementById('printQualityModal').style.display='none';}
function pqProgress(txt,pct){
  const t=document.getElementById('pqProgressText'), b=document.getElementById('pqProgressBar'), w=document.getElementById('pqProgressWrap');
  if(w)w.style.display='block';
  if(t)t.textContent=txt;
  if(b)b.style.width=Math.max(0,Math.min(100,pct))+'%';
}
function pqSetBusy(busy){
  const go=document.getElementById('pqGoBtn');
  if(go){go.disabled=busy; go.textContent=busy?'در حال آماده‌سازی...':'آماده‌سازی و چاپ';}
  document.querySelectorAll('#printQualityModal input[name="printQuality"]').forEach(r=>r.disabled=busy);
}
let printPrepBusy=false;
/* شمارش صفحات «واقعی» برگه طراحی‌شده (سوالات ممکن است بیش از صفحات منبع، صفحه بسازند) */
function designedPageCount(){
  const first=studentsData[0];
  const n=first?document.querySelectorAll(`.page[data-student-id="${first.id}"]`).length:0;
  return Math.max(1,n,sourcePageCount());
}
function startQualityPrint(){
  if(printPrepBusy)return;
  const q=document.querySelector('input[name="printQuality"]:checked')?.value||'high';
  try{localStorage.setItem('examPrintQuality',q);}catch(e){}
  printPrepBusy=true; printSavePromptPending=true; pqSetBusy(true);
  const btns=[...document.querySelectorAll('.btn-print')]; btns.forEach(b=>{b.disabled=true;b.dataset.oldTxt=b.textContent;b.textContent='در حال آماده‌سازی...';});
  const restore=()=>{printPrepBusy=false; pqSetBusy(false); btns.forEach(b=>{b.disabled=false;if(b.dataset.oldTxt)b.textContent=b.dataset.oldTxt;}); const w=document.getElementById('pqProgressWrap'); if(w)w.style.display='none';};
  const perStudent=designedPageCount();
  const totalPages=perStudent*studentsData.length;
  // مرحله ۱: سبک‌سازی تصاویر یکتا «قبل از» ساخت صدها صفحه — اوج مصرف حافظه حذف می‌شود.
  buildPrintAssets(q,(d,t)=>pqProgress('سبک‌سازی تصاویر... ('+d+' از '+t+')',t?Math.round(d/t*40):40),assetMap=>{
    // مرحله ۲: ساخت صفحات به‌صورت تکه‌تکه؛ مرورگر بین برش‌ها آزاد می‌ماند.
    prepareAllStudentsAsync(assetMap,(d,t)=>pqProgress('ساخت صفحات چاپ... ('+Math.min(d*perStudent,totalPages)+' از '+totalPages+' صفحه)',40+Math.round(d/Math.max(1,t)*45)),()=>{
      // مرحله ۳: انتظار برای decode شدن تصاویر (سقف زمان متناسب با حجم کار).
      pqProgress('آماده‌سازی نهایی برای چاپ...',88);
      waitForPrintReady(()=>{pqProgress('ارسال به چاپگر...',100); restore(); closePrintQualityModal(); window.print();}, Math.min(120000, 15000+totalPages*150));
    });
  });
}
/* v4.79.0: buildPrintAssets — جایگزین degradePrintImages.
   - فقط روی تصاویر «یکتا»ی صفحات دانش‌آموز اولِ طراحی کار می‌کند (نه ۴۰۰ کپی).
   - خروجی هر تصویر یک Blob URL کوتاه است؛ Blob URL در HTML همه کپی‌ها فقط
     چند ده بایت جا می‌گیرد و مرورگر آن را یک بار decode می‌کند — قبلا
     data-URL دست‌نویس (گاهی چندصد کیلوبایت) در هر ۱۰۰ کپی تکرار می‌شد.
   - حتی در «کیفیت بالا» data-URL ها به Blob URL تبدیل می‌شوند (بدون افت کیفیت).
   - نتایج در حافظه نگه داشته می‌شوند تا چاپ دوم با همان کیفیت فوری باشد. */
const printAssetCache={high:{},medium:{},low:{}};
function dataUrlToBlobUrl(u){
  try{
    const i=u.indexOf(','); if(i<0)return null;
    const meta=u.slice(5,i), b64=meta.indexOf(';base64')>=0;
    const mime=meta.split(';')[0]||'application/octet-stream';
    let bytes;
    if(b64){const bin=atob(u.slice(i+1)); bytes=new Uint8Array(bin.length); for(let k=0;k<bin.length;k++)bytes[k]=bin.charCodeAt(k);}
    else{const s=decodeURIComponent(u.slice(i+1)); bytes=new Uint8Array(s.length); for(let k=0;k<s.length;k++)bytes[k]=s.charCodeAt(k);}
    return URL.createObjectURL(new Blob([bytes],{type:mime}));
  }catch(e){return null;}
}
function canvasToBlobUrl(c,type,quality,cb){
  if(c.toBlob){c.toBlob(b=>cb(b?URL.createObjectURL(b):null),type,quality);return;}
  try{const u=c.toDataURL(type,quality); cb(u.indexOf('data:'+type)===0?(dataUrlToBlobUrl(u)||u):null);}catch(e){cb(null);}
}
function buildPrintAssets(q,onProgress,cb){
  const conf={high:null, medium:{w:1400,q:0.8}, low:{w:900,q:0.62}}[q]||null;
  const cache=printAssetCache[q]||(printAssetCache[q]={});
  const first=studentsData[0];
  collectDrawings(); applyDrawingOverlays();
  const imgs=[...document.querySelectorAll(`.page[data-student-id="${first.id}"] .qwrap img`)];
  const bySrc={};
  imgs.forEach(im=>{const s=im.getAttribute('src')||''; if(!s)return; (bySrc[s]=bySrc[s]||[]).push(im);});
  const srcs=Object.keys(bySrc);
  const map={};
  if(!srcs.length){cb(map);return;}
  let done=0; const total=srcs.length;
  const finish=(src,out)=>{ if(out&&out!==src){cache[src]=out; map[src]=out;} done++; if(onProgress)onProgress(done,total); if(done>=total)cb(map); };
  srcs.forEach(src=>{
    if(cache[src]){finish(src,cache[src]);return;}
    const isData=src.indexOf('data:')===0;
    if(!conf){ // کیفیت بالا: فقط data-URL های حجیم Blob می‌شوند؛ فایل‌های سرور دست‌نخورده.
      finish(src, isData?dataUrlToBlobUrl(src):null);
      return;
    }
    const needsAlpha=bySrc[src].some(im=>im.classList.contains('drawOverlay'));
    const probe=new Image();
    probe.onload=()=>{
      try{
        const scale=Math.min(1, conf.w/Math.max(1,probe.naturalWidth));
        if(scale>=1){finish(src, isData?dataUrlToBlobUrl(src):null); return;}  // به‌قدر کافی سبک است
        const c=document.createElement('canvas');
        c.width=Math.max(1,Math.round(probe.naturalWidth*scale));
        c.height=Math.max(1,Math.round(probe.naturalHeight*scale));
        c.getContext('2d').drawImage(probe,0,0,c.width,c.height);
        canvasToBlobUrl(c,'image/webp',conf.q,out=>{
          if(out){finish(src,out);return;}
          // webp پشتیبانی نشد: دست‌نویس‌ها (نیازمند شفافیت) با PNG سبک شوند، بقیه JPEG روی زمینه سفید.
          if(needsAlpha){canvasToBlobUrl(c,'image/png',undefined,o2=>finish(src,o2||(isData?dataUrlToBlobUrl(src):null)));return;}
          const c2=document.createElement('canvas'); c2.width=c.width; c2.height=c.height;
          const x2=c2.getContext('2d'); x2.fillStyle='#fff'; x2.fillRect(0,0,c2.width,c2.height); x2.drawImage(c,0,0);
          canvasToBlobUrl(c2,'image/jpeg',conf.q,o3=>finish(src,o3||(isData?dataUrlToBlobUrl(src):null)));
        });
      }catch(e){finish(src, isData?dataUrlToBlobUrl(src):null);}
    };
    probe.onerror=()=>finish(src,null);
    probe.src=src;
  });
}
window.addEventListener('beforeprint',()=>{if(previewOnly){prepareAllStudents();}});
window.addEventListener('afterprint',()=>{if(printSavePromptPending||confirm('آیا طراحی آزمون و سوالات در بانک اطلاعاتی ذخیره شود؟')){saveDesign(true).then(j=>alert(j.ok?'طراحی و سوالات ذخیره شد.':(j.error||'خطا در ذخیره')));} printSavePromptPending=false; previewOnly=true; rerenderPages();});

function draftKey(){return 'exam_live_design_'+examId;}
function autosaveLocal(){if(booting)return; try{localStorage.setItem(draftKey(),JSON.stringify({expires:Date.now()+3*24*3600*1000,design:designPayload()}));}catch(e){}}
function loadLocalDraft(){try{const raw=localStorage.getItem(draftKey()); if(!raw)return null; const o=JSON.parse(raw); if(!o.expires||Date.now()>o.expires){localStorage.removeItem(draftKey()); return null;} return o.design||null;}catch(e){return null;}}
function isCanvasBlank(c){const ctx=c.getContext('2d'), data=ctx.getImageData(0,0,c.width,c.height).data; for(let i=3;i<data.length;i+=4){if(data[i]!==0)return false;} return true;}
function collectDrawings(){const first=studentsData[0]; if(!first)return; document.querySelectorAll(`.page[data-student-id="${first.id}"] .drawCanvas`).forEach(c=>{const page=c.closest('.page').dataset.pageIndex;
  /* v4.83.0: canvasTouched = the user drew or ERASED on this page in this
     session. When touched, the canvas is the single source of truth: a fully
     erased canvas DELETES the stored handwriting (previously old strokes
     could resurrect because blank canvases were ignored). */
  if(canvasTouched[page]){ if(isCanvasBlank(c)) delete drawingsData[page]; else drawingsData[page]=c.toDataURL('image/png'); }
  else if(!isCanvasBlank(c) && !drawingsData[page]) drawingsData[page]=c.toDataURL('image/png');
});}
function restoreDrawings(){setTimeout(()=>{document.querySelectorAll('.drawCanvas').forEach(c=>{const page=c.closest('.page').dataset.pageIndex, data=drawingsData[page]; const ctx=c.getContext('2d'); ctx.globalCompositeOperation='source-over'; ctx.clearRect(0,0,c.width,c.height); if(data){const img=new Image(); img.onload=()=>{ctx.globalCompositeOperation='source-over'; ctx.drawImage(img,0,0,c.width,c.height);}; img.src=data;}}); applyDrawingOverlays();},60);}
function applyDrawingOverlays(){document.querySelectorAll('.drawOverlay').forEach(x=>x.remove()); document.querySelectorAll('.qwrap').forEach(w=>{const page=w.closest('.page').dataset.pageIndex, data=drawingsData[page]; if(data) w.insertAdjacentHTML('beforeend',`<img class="drawOverlay" alt="handwriting" src="${data}" style="position:absolute;inset:0;width:100%;height:100%;z-index:50;pointer-events:none;">`);});}

function defaultSourceOrder(){return (examData.sourcePages||[]).map((_,i)=>i+1);}
function resetSourceOrder(){sourceOrder=defaultSourceOrder(); sourceCrops={}; const inp=document.getElementById('sourceOrderInput'); if(inp)inp.value=sourceOrder.join(','); initCropPages(); rerenderPages(); autosaveLocal();}
function setSourceOrderFromInput(){const max=(examData.sourcePages||[]).length; const arr=(document.getElementById('sourceOrderInput')?.value||'').split(',').map(x=>parseInt(x.trim(),10)).filter(n=>n>=1&&n<=max); sourceOrder=arr; initCropPages(false); rerenderPages(); autosaveLocal();}
function initCropPages(updateInput=true){if(!sourceOrder.length)sourceOrder=defaultSourceOrder(); const warn=document.getElementById('pdfConvertWarn'); if(warn)warn.style.display=(examData.pdfNeedsServerConversion?'block':'none'); const inp=document.getElementById('sourceOrderInput'); if(inp&&updateInput)inp.value=sourceOrder.join(','); const sel=document.getElementById('cropPage'); if(!sel)return; sel.innerHTML=''; const n=sourcePageCount(); for(let i=1;i<=n;i++)sel.add(new Option('صفحه آزمون '+i+' ← منبع '+(sourceOrder[i-1]||'-'),i)); loadCropControls();}
function loadCropControls(){const p=+(document.getElementById('cropPage')?.value||1); const c=sourceCrops[p]||{t:0,r:0,b:0,l:0,w:100,x:0,y:0,contrast:100,brightness:100,bg:0}; ['T','R','B','L'].forEach(k=>{const el=document.getElementById('crop'+k); if(el)el.value=c[k.toLowerCase()]||0;}); const sw=document.getElementById('srcW'); if(sw)sw.value=c.w||100; const sx=document.getElementById('srcX'); if(sx)sx.value=c.x||0; const sy=document.getElementById('srcY'); if(sy)sy.value=c.y||0; const sc=document.getElementById('srcContrast'); if(sc)sc.value=c.contrast||100; const sb=document.getElementById('srcBrightness'); if(sb)sb.value=c.brightness||100; const bg=document.getElementById('srcBgRemove'); if(bg)bg.value=c.bg||0;}
function setCrop(){const p=+(document.getElementById('cropPage')?.value||1); sourceCrops[p]={t:+document.getElementById('cropT').value,r:+document.getElementById('cropR').value,b:+document.getElementById('cropB').value,l:+document.getElementById('cropL').value,w:+document.getElementById('srcW').value,x:+document.getElementById('srcX').value,y:+document.getElementById('srcY').value,contrast:+document.getElementById('srcContrast').value,brightness:+document.getElementById('srcBrightness').value,bg:+document.getElementById('srcBgRemove').value}; rerenderPages(); autosaveLocal();}
let sourceUploadStep=1;
function setUploadStep(n){
  sourceUploadStep=n;
  ['upStep1','upStep2','upStep3','upStep4'].forEach((id,i)=>document.getElementById(id)?.classList.toggle('active',i+1<=n));
  document.getElementById('uploadStepSelect').style.display=n===1?'block':'none';
  document.getElementById('uploadStepPages').style.display=n===2?'block':'none';
  document.getElementById('uploadStepProgress').style.display=n===3?'block':'none';
  document.getElementById('uploadStepDone').style.display=n===4?'block':'none';
  const btn=document.getElementById('uploadNextBtn'); if(btn) btn.textContent=n===1?'ادامه':(n===2?'شروع بارگذاری':'بستن');
}
function openSourceUploadModal(){document.getElementById('sourceUploadModal').style.display='flex'; setUploadStep(1);}
function closeSourceUploadModal(){document.getElementById('sourceUploadModal').style.display='none';}
/* v4.101.0: شمارش صفحات PDF در خود مرورگر (خواندن ساختار فایل — بدون کتابخانه) */
let pdfDetectedPages=0, pdfSelectedPages=[];
function countPdfPagesLocal(file){return file.arrayBuffer().then(buf=>{const bytes=new Uint8Array(buf); let txt=''; const CH=65536; for(let i=0;i<bytes.length;i+=CH){txt+=String.fromCharCode.apply(null,bytes.subarray(i,Math.min(i+CH,bytes.length)));} let m=txt.match(/\/Type\s*\/Pages[^>]*?\/Count\s+(\d+)/g); let best=0; if(m){m.forEach(x=>{const c=parseInt(x.match(/\/Count\s+(\d+)/)[1],10); if(c>best)best=c;});} if(!best){const pm=txt.match(/\/Type\s*\/Page[^s]/g); best=pm?pm.length:0;} return best;});}
function isPdfFile(f){return f && (f.type==='application/pdf'||f.name.toLowerCase().endsWith('.pdf'));}
function buildPdfPageChecks(n){pdfDetectedPages=n; const box=document.getElementById('pdfPageChecks'); if(!box)return; box.innerHTML=''; for(let i=1;i<=n;i++){box.insertAdjacentHTML('beforeend',`<label class="pagepick-chip"><input type="checkbox" class="pdfPageChk" value="${i}" checked style="width:auto;margin:0"> صفحه ${(+i).toLocaleString('fa-IR')}</label>`);} }
function pdfPagesAll(v){document.querySelectorAll('.pdfPageChk').forEach(c=>c.checked=v);}
function selectedPdfPages(){return [...document.querySelectorAll('.pdfPageChk:checked')].map(c=>+c.value);}
function sourceFileSelected(){const f=document.getElementById('modalSourceFile')?.files?.[0]; if(!f)return; pdfDetectedPages=0; if(isPdfFile(f)){countPdfPagesLocal(f).then(n=>{pdfDetectedPages=n;}).catch(()=>{pdfDetectedPages=0;});}}
function sourceUploadNext(){ if(sourceUploadStep===1){const f=document.getElementById('modalSourceFile')?.files?.[0]; if(!f){alert('ابتدا فایل را انتخاب کنید.');return;} setUploadStep(2); preparePagePickUI(f); return;} if(sourceUploadStep===2){const f=document.getElementById('modalSourceFile')?.files?.[0]; if(isPdfFile(f)&&document.getElementById('pdfPagePickWrap').style.display!=='none'&&!selectedPdfPages().length){alert('حداقل یک صفحه را انتخاب کنید.');return;} uploadLiveSource();return;} if(sourceUploadStep===4){closeSourceUploadModal();return;} }
/* v4.101.0: آماده‌سازی مرحله انتخاب صفحات — شمارش خودکار؛ اگر نشد ورودی دستی تعداد */
function preparePagePickUI(f){const wrap=document.getElementById('pdfPagePickWrap'), noPick=document.getElementById('imgNoPick'), manual=document.getElementById('pdfCountManual'), info=document.getElementById('pdfPageInfo');
  if(!isPdfFile(f)){wrap.style.display='none'; noPick.style.display='block'; return;}
  wrap.style.display='block'; noPick.style.display='none';
  const applyN=n=>{if(n>0){info.textContent='این فایل PDF دارای '+n+' صفحه است — صفحات موردنیاز را انتخاب کنید:'; manual.style.display='none'; buildPdfPageChecks(n);}else{info.textContent='تعداد صفحات به‌صورت خودکار شناسایی نشد.'; manual.style.display='block'; buildPdfPageChecks(Math.max(1,+document.getElementById('sourcePageCountInput')?.value||1));}};
  if(pdfDetectedPages>0){applyN(pdfDetectedPages);} else {info.textContent='در حال بررسی صفحات فایل...'; countPdfPagesLocal(f).then(applyN).catch(()=>applyN(0));}}
function applySourceResponse(j){
  if(!j.ok){alert(j.error||'خطا در فایل منبع');setUploadStep(1);return;}
  examData.file=j.file||''; examData.ext=j.ext||''; examData.sourcePages=j.sourcePages||[]; examData.pdfNeedsServerConversion=!!j.pdfNeedsServerConversion;
  /* v4.99.0: با تعویض/حذف منبع، همه بخش‌های وابسته به صفحات قبلی (چینش، برش، نقاشی) صفر می‌شوند تا صفحات قدیم و جدید هرگز قاطی نشوند */
  sourceOrder=defaultSourceOrder(); sourceCrops={}; drawingsData={}; initCropPages(true); rerenderPages(); autosaveLocal(); loadBank();
  document.getElementById('renderProgressText').textContent = j.pdfNeedsServerConversion ? 'فایل ذخیره شد اما تبدیل صفحه‌ای PDF روی سرور انجام نشد. Imagick/Ghostscript را بررسی کنید.' : 'رندر و آماده‌سازی صفحات انجام شد.';
  setUploadStep(4);
}
function uploadLiveSource(){
  const inp=document.getElementById('modalSourceFile'); const file=inp&&inp.files?inp.files[0]:null;
  if(!file){alert('ابتدا فایل PDF یا تصویر را انتخاب کنید.');return;}
  const fd=new FormData(); fd.append('action','upload'); fd.append('exam_id',examId); fd.append('dt',designToken);
  /* v4.101.0: صفحات انتخاب‌شده کاربر — فقط همین‌ها استخراج و در آزمون قرار می‌گیرند */
  const selPages=isPdfFile(file)?selectedPdfPages():[];
  fd.append('selected_pages',selPages.join(','));
  fd.append('declared_pages',String(pdfDetectedPages||document.getElementById('sourcePageCountInput')?.value||''));
  fd.append('question_source',file,file.name);
  setUploadStep(3);
  document.getElementById('uploadProgressText').textContent='در حال بارگذاری فایل...'; document.getElementById('uploadProgressBar').style.width='0%'; document.getElementById('renderProgressText').textContent='';
  const xhr=new XMLHttpRequest();
  xhr.open('POST','exam-source-api.php',true);
  xhr.upload.onprogress=function(e){if(e.lengthComputable){const pct=Math.round(e.loaded/e.total*100);document.getElementById('uploadProgressBar').style.width=pct+'%';document.getElementById('uploadProgressText').textContent='بارگذاری: '+pct+'٪'; if(pct===100) document.getElementById('renderProgressText').textContent='فایل دریافت شد؛ در حال تبدیل و آماده‌سازی صفحات...';}};
  xhr.onload=function(){try{applySourceResponse(JSON.parse(xhr.responseText));}catch(e){alert('پاسخ سرور نامعتبر بود.');setUploadStep(1);}};
  xhr.onerror=function(){alert('خطا در ارتباط با سرور.');setUploadStep(1);};
  xhr.send(fd);
}
function deleteLiveSource(){
  if(!confirm('فایل منبع این آزمون حذف شود؟')) return;
  const fd=new FormData(); fd.append('action','delete'); fd.append('exam_id',examId); fd.append('dt',designToken);
  fetch('exam-source-api.php',{method:'POST',body:fd}).then(r=>r.json()).then(applySourceResponse).catch(e=>alert('خطا در حذف: '+e));
}


let imageEditOriginal=null;
function openImageEditor(){document.getElementById('imageEditorModal').style.display='flex';}
function closeImageEditor(){document.getElementById('imageEditorModal').style.display='none';}
function loadImageForEdit(input){
  const file=input.files&&input.files[0]; if(!file)return;
  if(file.size>10*1024*1024){alert('حجم تصویر بیش از ۱۰ مگابایت است.'); input.value=''; return;}
  const r=new FileReader(); r.onload=e=>{const img=new Image(); img.onload=()=>{imageEditOriginal=img; renderImageEdit();}; img.src=e.target.result;}; r.readAsDataURL(file);
}
function renderImageEdit(){
  const c=document.getElementById('imageEditCanvas'), ctx=c.getContext('2d');
  ctx.clearRect(0,0,c.width,c.height); ctx.fillStyle='#fff'; ctx.fillRect(0,0,c.width,c.height);
  if(!imageEditOriginal){ctx.fillStyle='#64748b';ctx.font='20px Tahoma';ctx.textAlign='center';ctx.fillText('ابتدا تصویر را انتخاب کنید',c.width/2,c.height/2);return;}
  const img=imageEditOriginal, l=+imgCropL.value/100, rr=+imgCropR.value/100, t=+imgCropT.value/100, b=+imgCropB.value/100;
  const sx=Math.round(img.width*l), sy=Math.round(img.height*t), sw=Math.max(1,Math.round(img.width*(1-l-rr))), sh=Math.max(1,Math.round(img.height*(1-t-b)));
  const outW=Math.min(800,+imgOutW.value||420), outH=Math.round(outW*sh/sw); c.width=Math.max(120,outW); c.height=Math.max(80,outH);
  ctx.drawImage(img,sx,sy,sw,sh,0,0,outW,outH);
  let data=ctx.getImageData(0,0,outW,outH), d=data.data, br=+imgBright.value, ct=+imgContrast.value;
  const factor=(259*(ct+255))/(255*(259-ct)); const rm=imgRemoveBg.checked, th=+imgBgThreshold.value;
  for(let i=0;i<d.length;i+=4){
    d[i]=Math.max(0,Math.min(255,factor*(d[i]-128)+128+br));
    d[i+1]=Math.max(0,Math.min(255,factor*(d[i+1]-128)+128+br));
    d[i+2]=Math.max(0,Math.min(255,factor*(d[i+2]-128)+128+br));
    if(rm && d[i]>th && d[i+1]>th && d[i+2]>th) d[i+3]=0;
  }
  ctx.putImageData(data,0,0);
}
function insertEditedImageToQuestion(){
  if(!imageEditOriginal){alert('ابتدا تصویر را انتخاب کنید.');return;}
  renderImageEdit(); const c=document.getElementById('imageEditCanvas');
  const id='img_'+Date.now()+'_'+Math.random().toString(16).slice(2); const src=c.toDataURL('image/webp',0.82);
  document.getElementById('qText').focus(); document.execCommand('insertHTML',false,`<img src="${src}" data-imgid="${id}" style="width:${Math.min(420,c.width)}px;height:auto;position:absolute;left:0px;top:0px" onclick="selectQuestionImage('${id}',this)">`);
  closeImageEditor();
}
const mathSymbols=['+','−','×','÷','=','≠','≈','≤','≥','∞','π','θ','α','β','γ','Δ','√','∛','∑','∫','∠','⊥','∥','≅','∼','∈','∉','⊂','∪','∩','→','↔','°','²','³','⁴','₁','₂','₃','₄','±','∓','∧','∨','¬','∀','∃','∴','∵','٪'];
const mathTemplates={
 'کسر ساده':'\\frac{□}{□}',
 'کسر فارسی':'\\frac{صورت}{مخرج}',
 'عدد مخلوط':'□ \\frac{□}{□}',
 'توان':'x^{□}',
 'اندیس':'x_{□}',
 'توان و اندیس':'x_{□}^{□}',
 'رادیکال':'\\sqrt{□}',
 'ریشه nام':'\\root{n}{□}',
 'ماتریس ۲×۲':'\\matrix{a,b;c,d}',
 'ماتریس ۳×۳':'\\matrix{a,b,c;d,e,f;g,h,i}',
 'دترمینان ۲×۲':'\\det{a,b;c,d}',
 'دستگاه معادلات':'\\cases{x+y=1;2x-y=3}',
 'بردار':'\\vec{AB}',
 'پاره‌خط':'\\overline{AB}',
 'زاویه':'\\angle ABC',
 'مثلث':'△ABC',
 'حد':'\\lim{x\\to a} f(x)',
 'مشتق':'\\frac{dy}{dx}',
 'انتگرال معین':'\\int_{a}^{b} f(x) dx',
 'مجموع':'\\sum_{i=1}^{n} a_i',
 'احتمال':'P(A)=\\frac{n(A)}{n(S)}',
 'میانگین':'\\bar{x}=\\frac{\\sum x_i}{n}',
 'درصد':'٪ = \\frac{بخش}{کل} × 100'
};
function openMathModal(){document.getElementById('mathModal').style.display='flex'; renderMathButtons(); updateMathPreview();}
function closeMathModal(){document.getElementById('mathModal').style.display='none';}
function renderMathButtons(){const box=document.getElementById('mathButtons'); if(box.dataset.ready==='1')return; Object.entries(mathTemplates).forEach(([label,t])=>box.insertAdjacentHTML('beforeend',`<button title="${label}" onclick="appendMath('${t.replace(/\\/g,'\\\\').replace(/'/g,"\\'")}')">${label}</button>`)); mathSymbols.forEach(sym=>box.insertAdjacentHTML('beforeend',`<button onclick="appendMath('${sym.replace(/'/g,"\\'")}')">${sym}</button>`)); box.dataset.ready='1';}
function appendMath(t){const inp=document.getElementById('mathInput'); const st=inp.selectionStart||inp.value.length; inp.value=inp.value.slice(0,st)+t+inp.value.slice(inp.selectionEnd||st); inp.focus(); inp.setSelectionRange(st+t.length,st+t.length); updateMathPreview();}
function latexBasic(s){s=String(s||''); return s.replace(/\\alpha/g,'α').replace(/\\beta/g,'β').replace(/\\gamma/g,'γ').replace(/\\theta/g,'θ').replace(/\\pi/g,'π').replace(/\\Delta/g,'Δ').replace(/\\infty/g,'∞').replace(/\\times/g,'×').replace(/\\div/g,'÷').replace(/\\leq/g,'≤').replace(/\\geq/g,'≥').replace(/\\neq/g,'≠').replace(/\\approx/g,'≈').replace(/\\to/g,'→').replace(/\\angle/g,'∠').replace(/\\sum/g,'∑').replace(/\\int/g,'∫').replace(/\\lim/g,'lim');}
function escMath(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
function latexToHtml(x){
 /* v4.100.0: پشتیبانی از عبارات تو در تو — تبدیل به‌صورت حلقه‌ای از داخلی‌ترین
    دستور به بیرون انجام می‌شود؛ مثلا \frac{\sqrt{2}}{3^2} یا کسر داخل کسر. */
 let s=latexBasic(x), prev;
 const sup={'0':'⁰','1':'¹','2':'²','3':'³','4':'⁴','5':'⁵','6':'⁶','7':'⁷','8':'⁸','9':'⁹','+':'⁺','-':'⁻','n':'ⁿ','□':'□'};
 const sub={'0':'₀','1':'₁','2':'₂','3':'₃','4':'₄','5':'₅','6':'₆','7':'₇','8':'₈','9':'₉','+':'₊','-':'₋','n':'ₙ','□':'□'};
 let guard=0;
 do{ prev=s; guard++;
  s=s.replace(/\\frac\{([^{}]*)\}\{([^{}]*)\}/g,(m,a,b)=>`<span class="math-frac"><span class="top">${a}</span><span class="bottom">${b}</span></span>`);
  s=s.replace(/\\root\{([^{}]*)\}\{([^{}]*)\}/g,(m,n,a)=>`<span class="math-sqrt"><sup>${n}</sup><span class="math-sqrt-sym">√</span><span class="math-sqrt-body">${a}</span></span>`);
  s=s.replace(/\\sqrt\{([^{}]*)\}/g,(m,a)=>`<span class="math-sqrt"><span class="math-sqrt-sym">√</span><span class="math-sqrt-body">${a}</span></span>`);
  s=s.replace(/\\vec\{([^{}]*)\}/g,(m,a)=>`<span class="math-vector">${a}</span>`);
  s=s.replace(/\\overline\{([^{}]*)\}/g,(m,a)=>`<span style="text-decoration:overline">${a}</span>`);
  s=s.replace(/\\matrix\{([^{}]*)\}/g,(m,body)=>matrixHtml(body,'[',']'));
  s=s.replace(/\\det\{([^{}]*)\}/g,(m,body)=>matrixHtml(body,'|','|'));
  s=s.replace(/\\cases\{([^{}]*)\}/g,(m,body)=>matrixHtml(body,'{',''));
  s=s.replace(/\^\{([0-9+n□-]+)\}/g,(m,a)=>[...a].map(c=>sup[c]||c).join(''));
  s=s.replace(/\^([0-9]+|[+n□-])/g,(m,a)=>[...a].map(c=>sup[c]||c).join(''));
  s=s.replace(/_\{([0-9+n□-]+)\}/g,(m,a)=>[...a].map(c=>sub[c]||c).join(''));
  s=s.replace(/_([0-9]+|[+n□-])/g,(m,a)=>[...a].map(c=>sub[c]||c).join(''));
  s=s.replace(/\^\{([^{}]*)\}/g,'<sup>$1</sup>');
  s=s.replace(/_\{([^{}]*)\}/g,'<sub>$1</sub>');
  s=s.replace(/\^([A-Za-z\u0600-\u06FF])/g,'<sup>$1</sup>');
 }while(s!==prev && guard<40);
 return s;
}
function matrixHtml(body,left='[',right=']'){
 const rows=String(body).split(';').map(r=>r.split(','));
 let html='<span class="math-bracket"><span class="math-big">'+escMath(left)+'</span><table class="math-matrix">';
 rows.forEach(r=>{html+='<tr>'+r.map(c=>'<td>'+latexToHtml(c.trim())+'</td>').join('')+'</tr>';});
 html+='</table><span class="math-big">'+escMath(right)+'</span></span>'; return html;
}
function updateMathPreview(){document.getElementById('mathPreview').innerHTML=latexToHtml(document.getElementById('mathInput').value);}
function insertMathToQuestion(){const html=`<span class="math-token">${latexToHtml(document.getElementById('mathInput').value)}</span>&nbsp;`; document.getElementById('qText').focus(); document.execCommand('insertHTML',false,html); closeMathModal();}
window.addEventListener('resize',resizeCanvases);renderHeaderEditor();initCropPages();rerenderPages();applyQuestionTemplate();loadDesignAndBank();setTimeout(resizeCanvases,300);
</script></body></html><?php exit; }
