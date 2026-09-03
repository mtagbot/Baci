<?php
// File: exam-source-api.php
/**
 * AJAX API for live exam source file upload/delete in exam designer.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/exams_helper.php';
// v2.13.0 (desktop): needed for site-side PDF→image conversion fallback
if (is_file(__DIR__ . '/includes/desk_sync.php')) require_once __DIR__ . '/includes/desk_sync.php';
ensure_exams_schema();
header('Content-Type: application/json; charset=utf-8');

function exam_source_json($ok, $data = []) { echo json_encode(array_merge(['ok'=>$ok], $data), JSON_UNESCAPED_UNICODE); exit; }
function exam_source_cache_dir($examId) { return __DIR__ . '/uploads/exams/pdf-pages/exam_' . (int)$examId; }
function exam_source_clear_cache($examId) {
    $dir = exam_source_cache_dir($examId);
    if (is_dir($dir)) foreach (glob($dir . '/*') ?: [] as $f) if (is_file($f)) @unlink($f);
}
function exam_source_pdf_pages_to_images($pdfRelPath, $examId, $declaredPages = 0) {
    $out = [];
    $full = realpath(__DIR__ . '/' . ltrim((string)$pdfRelPath, '/')) ?: (__DIR__ . '/' . ltrim((string)$pdfRelPath, '/'));
    if (!is_file($full) || strtolower(pathinfo($full, PATHINFO_EXTENSION)) !== 'pdf') return $out;
    $cacheDir = exam_source_cache_dir($examId);
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

    // Render exactly one PDF page into exactly one JPG file. Never read/flatten the whole PDF sequence,
    // because on some ImageMagick builds that stacks all pages into every output image.
    if (class_exists('Imagick')) {
        for ($i = 0; $i < $declaredPages; $i++) {
            try {
                $page = new Imagick();
                $page->setResolution(180, 180);
                $page->readImage($full . '[' . $i . ']');
                $page->setIteratorIndex(0);
                $page->setImageBackgroundColor('white');
                if (defined('Imagick::ALPHACHANNEL_REMOVE')) $page->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $page->setImageFormat('jpeg');
                $page->setImageCompressionQuality(90);
                $file = $cacheDir . '/page_' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT) . '.jpg';
                $page->writeImage($file);
                $page->clear();
                $out[] = str_replace(__DIR__ . '/', '', $file);
            } catch (Exception $e) { break; }
        }
    }
    if (count($out) < $declaredPages && function_exists('exec')) {
        foreach (glob($cacheDir . '/page_*.jpg') ?: [] as $old) @unlink($old);
        for ($i = 1; $i <= $declaredPages; $i++) {
            $file = $cacheDir . '/page_' . str_pad((string)$i, 3, '0', STR_PAD_LEFT) . '.jpg';
            $cmd = 'gs -q -dSAFER -dBATCH -dNOPAUSE -sDEVICE=jpeg -r180 -dJPEGQ=90 -dFirstPage=' . (int)$i . ' -dLastPage=' . (int)$i . ' -sOutputFile=' . escapeshellarg($file) . ' ' . escapeshellarg($full) . ' 2>&1';
            @exec($cmd, $gsOut, $code);
            if ($code === 0 && is_file($file)) $out[] = str_replace(__DIR__ . '/', '', $file);
        }
    }
    return array_slice($out, 0, $declaredPages);
}

$examId = (int)($_POST['exam_id'] ?? $_GET['exam_id'] ?? 0);
if (!$examId) exam_source_json(false, ['error'=>'شناسه آزمون نامعتبر است.']);
$tokenOk = verify_exam_design_token($_POST['dt'] ?? $_GET['dt'] ?? '', $examId);
if (!$tokenOk && !exam_can_design($examId)) exam_source_json(false, ['error'=>'دسترسی غیرمجاز']);
$exam = DB::fetch('SELECT * FROM exam_schedules WHERE id=?', [$examId]);
if (!$exam) exam_source_json(false, ['error'=>'آزمون یافت نشد.']);
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'delete') {
    if (!empty($exam['question_file'])) {
        $old = __DIR__ . '/' . ltrim($exam['question_file'], '/');
        if (is_file($old) && strpos(realpath($old) ?: '', realpath(__DIR__ . '/uploads/exams') ?: __DIR__) === 0) @unlink($old);
    }
    exam_source_clear_cache($examId);
    DB::execute('UPDATE exam_schedules SET question_file=NULL WHERE id=?', [$examId]);
    exam_source_json(true, ['file'=>'', 'ext'=>'', 'sourcePages'=>[], 'pdfNeedsServerConversion'=>false, 'message'=>'فایل منبع حذف شد.']);
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
    // Remove previous source after successful upload.
    if (!empty($exam['question_file'])) {
        $old = __DIR__ . '/' . ltrim($exam['question_file'], '/');
        if (is_file($old) && $old !== $dest) @unlink($old);
    }
    exam_source_clear_cache($examId);
    $rel = 'uploads/exams/' . $fn;
    DB::execute('UPDATE exam_schedules SET question_file=? WHERE id=?', [$rel, $examId]);
    $declaredPages = max(1, (int)($_POST['declared_pages'] ?? 1));
    /* v2.13.0: remember the declared page count next to the cache, so a
       later conversion (e.g. on the site after an offline upload) knows how
       many pages to render even without Imagick page probing. */
    if ($ext === 'pdf') {
        $cd = exam_source_cache_dir($examId);
        if (!is_dir($cd)) @mkdir($cd, 0755, true);
        @file_put_contents($cd . '/pages.txt', (string)$declaredPages);
    }
    $pages = ($ext === 'pdf') ? exam_source_pdf_pages_to_images($rel, $examId, $declaredPages) : [$rel];
    /* v2.13.0 (desktop): no local Imagick/Ghostscript → let the SITE convert.
       The PDF is pushed up, converted there with the site's own renderer and
       the page images are pulled back — the exam then looks identical on
       both sides and the site can print it too. */
    if ($ext === 'pdf' && count($pages) < $declaredPages && class_exists('DeskSync')) {
        $remote = DeskSync::remoteExamPdfPages($examId, $rel, $declaredPages);
        if ($remote) $pages = $remote;
    }
    exam_source_json(true, ['file'=>$rel, 'ext'=>$ext, 'sourcePages'=>$pages, 'pdfNeedsServerConversion'=>($ext==='pdf' && count($pages) < $declaredPages), 'declaredPages'=>$declaredPages, 'message'=>'فایل منبع بارگذاری شد.']);
}

exam_source_json(false, ['error'=>'عملیات نامعتبر است.']);
