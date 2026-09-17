<?php
// File: exam-source-api.php
/**
 * AJAX API for live exam source file upload/delete in exam designer.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/class_exam_groups.php';
// v2.13.0 (desktop): needed for site-side PDF→image conversion fallback
if (is_file(__DIR__ . '/includes/desk_sync.php')) require_once __DIR__ . '/includes/desk_sync.php';
ensure_exams_schema(); ceg_schema();
header('Content-Type: application/json; charset=utf-8');

function exam_source_json($ok, $data = []) { ceg_write_end($ok); echo json_encode(array_merge(['ok'=>$ok], $data), JSON_UNESCAPED_UNICODE); exit; }
function exam_source_cache_dir($examId) { return __DIR__ . '/uploads/exams/pdf-pages/exam_' . (int)$examId; }
/* v4.99.0: وقتی منبع عوض/حذف می‌شود، بخش‌های وابسته به صفحات منبع در طراحیِ
   ذخیره‌شده (چینش، برش‌ها، نقاشی‌ها) ریست می‌شوند تا طراحی قدیمی هرگز روی
   صفحات منبع جدید سوار نشود؛ سوالات تایپی و سربرگ دست نمی‌خورند. */
function exam_source_reset_design_layout($examId) {
    $design = DB::fetch("SELECT * FROM exam_designs WHERE exam_id=?", [(int)$examId]);
    if (!$design) return;
    $dj = json_decode((string)$design['design_json'], true) ?: [];
    unset($dj['sourceOrder'], $dj['sourceCrops'], $dj['drawings']);
    DB::execute("UPDATE exam_designs SET design_json=?, updated_at_jalali=? WHERE exam_id=?",
        [json_encode($dj, JSON_UNESCAPED_UNICODE), jalali_now(), (int)$examId]);
}

function exam_source_clear_cache($examId) {
    $dir = exam_source_cache_dir($examId);
    if (is_dir($dir)) foreach (glob($dir . '/*') ?: [] as $f) if (is_file($f)) @unlink($f);
}
function exam_source_pdf_pages_to_images($pdfRelPath, $examId, $declaredPages = 0, $selectedPages = []) {
    $out = [];
    $full = realpath(__DIR__ . '/' . ltrim((string)$pdfRelPath, '/')) ?: (__DIR__ . '/' . ltrim((string)$pdfRelPath, '/'));
    $cacheDir = exam_source_cache_dir($examId);
    /* v4.94.0: اگر PDF قبلا تبدیل و حذف شده، تصاویر کش منبع طراحی‌اند */
    if (!is_file($full)) {
        foreach (glob($cacheDir . '/page_*.jpg') ?: [] as $img) $out[] = str_replace(__DIR__ . '/', '', $img);
        sort($out);
        return $out;
    }
    if (strtolower(pathinfo($full, PATHINFO_EXTENSION)) !== 'pdf') return $out;
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    foreach (glob($cacheDir . '/page_*.jpg') ?: [] as $old) @unlink($old);

    $declaredPages = max(0, (int)$declaredPages);
    if ($declaredPages <= 0 && class_exists('Imagick')) {
        try {
            $probe = new Imagick();
            $probe->pingImage($full);
            $declaredPages = max(1, (int)$probe->getNumberImages());
            $probe->clear();
        } catch (Exception $e) { $declaredPages = 1; }
    }
    if ($declaredPages <= 0) $declaredPages = 1;
    /* v4.101.0: انتخاب صفحات — فقط صفحات خواسته‌شده PDF استخراج می‌شوند و خروجی
       به‌صورت متوالی page_001.jpg و… شماره می‌خورد (مثلا انتخاب ۵و۶ → دو صفحه). */
    $selectedPages = array_values(array_unique(array_filter(array_map('intval', (array)$selectedPages), function($x){ return $x >= 1; })));
    sort($selectedPages);
    $srcList = $selectedPages ?: range(1, $declaredPages);

    // Render exactly one PDF page into exactly one JPG file. Never read/flatten the whole PDF sequence,
    // because on some ImageMagick builds that stacks all pages into every output image.
    if (class_exists('Imagick')) {
        foreach ($srcList as $oi => $srcPage) {
            $i = $srcPage - 1;
            try {
                $page = new Imagick();
                $page->setResolution(180, 180);
                $page->readImage($full . '[' . $i . ']');
                $page->setIteratorIndex(0);
                $page->setImageBackgroundColor('white');
                if (defined('Imagick::ALPHACHANNEL_REMOVE')) $page->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $page->setImageFormat('jpeg');
                $page->setImageCompressionQuality(90);
                $file = $cacheDir . '/page_' . str_pad((string)($oi + 1), 3, '0', STR_PAD_LEFT) . '.jpg';
                $page->writeImage($file);
                $page->clear();
                $out[] = str_replace(__DIR__ . '/', '', $file);
            } catch (Exception $e) { break; }
        }
    }
    if (count($out) < count($srcList) && function_exists('exec')) {
        foreach (glob($cacheDir . '/page_*.jpg') ?: [] as $old) @unlink($old);
        $out = [];
        foreach ($srcList as $oi => $srcPage) {
            $file = $cacheDir . '/page_' . str_pad((string)($oi + 1), 3, '0', STR_PAD_LEFT) . '.jpg';
            $cmd = 'gs -q -dSAFER -dBATCH -dNOPAUSE -sDEVICE=jpeg -r180 -dJPEGQ=90 -dFirstPage=' . (int)$srcPage . ' -dLastPage=' . (int)$srcPage . ' -sOutputFile=' . escapeshellarg($file) . ' ' . escapeshellarg($full) . ' 2>&1';
            @exec($cmd, $gsOut, $code);
            if ($code === 0 && is_file($file)) $out[] = str_replace(__DIR__ . '/', '', $file);
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
    /* v4.94.0: تبدیل کامل شد → PDF منبع برای آزادسازی فضای هاست حذف می‌شود */
    if (count($out) >= count($srcList) && count($srcList) >= 1) @unlink($full);
    return $out;
}

$examId = (int)($_POST['exam_id'] ?? $_GET['exam_id'] ?? 0);
if (!$examId) exam_source_json(false, ['error'=>'شناسه آزمون نامعتبر است.']);
$tokenOk = verify_exam_design_token($_POST['dt'] ?? $_GET['dt'] ?? '', $examId);
if (!$tokenOk && !exam_can_design($examId)) exam_source_json(false, ['error'=>'دسترسی غیرمجاز']);
$exam = DB::fetch('SELECT * FROM exam_schedules WHERE id=?', [$examId]);
if (!$exam) exam_source_json(false, ['error'=>'آزمون یافت نشد.']);
if(($exam['exam_kind']??'')==='class_deleted')exam_source_json(false,['error'=>'این آزمون کلاسی حذف شده است.']);
ceg_write_begin($exam);
$exam = DB::fetch('SELECT * FROM exam_schedules WHERE id=?', [$examId]);
if(($exam['exam_kind']??'')==='class_deleted')exam_source_json(false,['error'=>'این آزمون کلاسی حذف شده است.']);
$group=ceg_for_exam($exam);
if(ceg_pending_sync($exam,$group))exam_source_json(false,['error'=>'اطلاعات گروه هنوز همگام نشده است.']);
if($group && !$group['excluded'] && (int)$group['design_exam_id']!==$examId)exam_source_json(false,['error'=>'این کلاس عضو آزمون پایه شده است؛ صفحه را تازه‌سازی کنید.']);
if($group && $group['excluded'] && (int)$group['member_exam_id']===$examId)exam_source_json(false,['error'=>'این کلاس مستثنی شده است؛ طراحی مستقل را باز کنید.']);
if($group && !$group['excluded'] && !ceg_validate_member($group,(int)($_GET['group_member']??0)))exam_source_json(false,['error'=>'این کلاس دیگر عضو فعال آزمون پایه نیست.']);
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'delete') {
    /* v4.99.0: قبل از حذف، طراحی فعلی + صفحاتش به بایگانی بانک منتقل می‌شود
       (به‌جز آزمون‌هایی که خودشان از بانک درج شده‌اند — فایل تکراری ذخیره نمی‌شود). */
    if (function_exists('exam_archive_design_before_source_change')) {
        try { exam_archive_design_before_source_change($examId); } catch (Exception $e) {}
    }
    if (!empty($exam['question_file'])) {
        $old = __DIR__ . '/' . ltrim($exam['question_file'], '/');
        if (is_file($old) && strpos(realpath($old) ?: '', realpath(__DIR__ . '/uploads/exams') ?: __DIR__) === 0) @unlink($old);
    }
    exam_source_clear_cache($examId);
    exam_source_reset_design_layout($examId);   /* v4.99.0: چینش/برش/نقاشی قدیمی با منبع جدید قاطی نشود */
    DB::execute('UPDATE exam_schedules SET question_file=NULL WHERE id=?', [$examId]);
    exam_source_json(true, ['file'=>'', 'ext'=>'', 'sourcePages'=>[], 'pdfNeedsServerConversion'=>false, 'message'=>'فایل منبع حذف شد. طراحی قبلی با صفحاتش در بانک آزمون‌های ذخیره‌شده بایگانی شد.']);
}

if ($action === 'upload') {
    if (empty($_FILES['question_source']) || $_FILES['question_source']['error'] !== UPLOAD_ERR_OK) exam_source_json(false, ['error'=>'فایلی دریافت نشد.']);
    $ext = strtolower(pathinfo($_FILES['question_source']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf','jpg','jpeg','png','webp'], true)) exam_source_json(false, ['error'=>'فرمت فایل مجاز نیست.']);
    if ($_FILES['question_source']['size'] > 25 * 1024 * 1024) exam_source_json(false, ['error'=>'حجم فایل بیش از حد مجاز است.']);
    $dir = __DIR__ . '/uploads/exams'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fn = 'source_exam_' . $examId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $dir . '/' . $fn;
    if (!move_uploaded_file($_FILES['question_source']['tmp_name'], $dest)) exam_source_json(false, ['error'=>'ذخیره فایل ناموفق بود.']);
    /* v4.99.0: منبع در حال تعویض است — طراحی قبلی و صفحاتش اول بایگانی می‌شوند */
    if (function_exists('exam_archive_design_before_source_change')) {
        try { exam_archive_design_before_source_change($examId); } catch (Exception $e) {}
    }
    // Remove previous source after successful upload.
    if (!empty($exam['question_file'])) {
        $old = __DIR__ . '/' . ltrim($exam['question_file'], '/');
        if (is_file($old) && $old !== $dest) @unlink($old);
    }
    exam_source_clear_cache($examId);
    exam_source_reset_design_layout($examId);   /* v4.99.0: چینش/برش/نقاشی منبع قبلی برای منبع جدید معتبر نیست */
    $rel = 'uploads/exams/' . $fn;
    DB::execute('UPDATE exam_schedules SET question_file=? WHERE id=?', [$rel, $examId]);
    $declaredPages = max(1, (int)($_POST['declared_pages'] ?? 1));
    /* v4.101.0: صفحات انتخاب‌شده کاربر (مثلا «5,6») — فقط همین‌ها استخراج می‌شوند */
    $selectedPages = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['selected_pages'] ?? ''))), function($x){ return $x >= 1; }));
    $wantCount = $selectedPages ? count($selectedPages) : $declaredPages;
    /* v2.13.0: remember the declared page count next to the cache, so a
       later conversion (e.g. on the site after an offline upload) knows how
       many pages to render even without Imagick page probing. */
    if ($ext === 'pdf') {
        $cd = exam_source_cache_dir($examId);
        if (!is_dir($cd)) @mkdir($cd, 0755, true);
        @file_put_contents($cd . '/pages.txt', (string)$declaredPages);
        if ($selectedPages) @file_put_contents($cd . '/selected.txt', implode(',', $selectedPages)); else @unlink($cd . '/selected.txt');
    }
    $pages = ($ext === 'pdf') ? exam_source_pdf_pages_to_images($rel, $examId, $declaredPages, $selectedPages) : [$rel];
    /* v2.13.0 (desktop): no local Imagick/Ghostscript → let the SITE convert.
       The PDF is pushed up, converted there with the site's own renderer and
       the page images are pulled back — the exam then looks identical on
       both sides and the site can print it too. */
    if ($ext === 'pdf' && count($pages) < $wantCount && class_exists('DeskSync')) {
        ceg_write_end(true);
        $remote = DeskSync::remoteExamPdfPages($examId, $rel, $declaredPages, $selectedPages);
        if ($remote) $pages = $remote;
    }
    /* v4.100.0: ضد کش مرورگر — نام صفحات (page_001.jpg…) بعد از تعویض منبع ثابت
       می‌ماند و مرورگر تصویر قدیمی را نشان می‌داد؛ ?v=زمان تغییر فایل حل می‌کند. */
    $pages = array_map(function($sp){ $full = __DIR__ . '/' . ltrim((string)$sp, '/'); return is_file($full) ? ($sp . '?v=' . filemtime($full)) : $sp; }, $pages);
    exam_source_json(true, ['file'=>$rel, 'ext'=>$ext, 'sourcePages'=>$pages, 'pdfNeedsServerConversion'=>($ext==='pdf' && count($pages) < $wantCount), 'declaredPages'=>$declaredPages, 'selectedPages'=>$selectedPages, 'message'=>'فایل منبع بارگذاری شد.']);
}

exam_source_json(false, ['error'=>'عملیات نامعتبر است.']);
