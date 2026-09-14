<?php
/**
 * online-exam-media-upload.php
 *
 * v4.131.0 — سخت‌سازی امنیتی
 * ---------------------------------------------------------------------
 * پیش از این، تنها کنترل‌های این endpoint «لاگین بودن» و «پسوند فایل»
 * بود. نه CSRF داشت، نه سقف حجم، نه بررسی محتوای واقعی فایل. یعنی هر
 * سایتی می‌توانست از مرورگرِ دبیرِ لاگین‌کرده، فایل روی سرور بنویسد.
 *
 * آنچه اضافه شد:
 *   ۱) توکن CSRF (هم‌راستا با بقیهٔ فرم‌های سامانه)
 *   ۲) سقف حجم ۱۰ مگابایت + بررسی خطاهای خود PHP هنگام آپلود
 *   ۳) بررسی MIME واقعی با finfo — نه صرفاً پسوند
 *   ۴) تطابق اجباری «پسوند ↔ MIME» تا فایل .jpg حاوی PHP رد شود
 *   ۵) نام فایل کاملاً تصادفی (پیش‌تر با time() قابل حدس بود)
 *   ۶) نوشتن .htaccess در پوشهٔ آپلود تا اجرای PHP آنجا خاموش شود
 *
 * درخواست HEAD عمداً باز مانده است: صفحهٔ آزمون دانش‌آموز
 * (online-exam-take.php) از آن برای سنجش سرعت اینترنت استفاده می‌کند و
 * هیچ داده‌ای نمی‌نویسد.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/online_exam_helpers.php';
ensure_online_exams_schema();

/* تست سرعت اینترنت در مرحلهٔ بررسی دسترسی‌ها — فقط پاسخ خالی */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

function meu_fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['admin_id']) && empty($_SESSION['teacher_id'])) {
    meu_fail('غیرمجاز', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    meu_fail('فقط POST');
}

/* ۱) CSRF */
$token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!verify_csrf($token)) {
    meu_fail('خطای امنیتی CSRF — صفحه را تازه کنید و دوباره تلاش کنید.', 403);
}

if (empty($_FILES['file'])) {
    meu_fail('فایل نرسید');
}

/* ۲) خطاهای خود PHP */
$err = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
if ($err !== UPLOAD_ERR_OK) {
    $map = [
        UPLOAD_ERR_INI_SIZE   => 'حجم فایل از حد مجاز سرور بیشتر است.',
        UPLOAD_ERR_FORM_SIZE  => 'حجم فایل از حد مجاز فرم بیشتر است.',
        UPLOAD_ERR_PARTIAL    => 'فایل ناقص ارسال شد.',
        UPLOAD_ERR_NO_FILE    => 'فایلی انتخاب نشده است.',
        UPLOAD_ERR_NO_TMP_DIR => 'پوشهٔ موقت سرور در دسترس نیست.',
        UPLOAD_ERR_CANT_WRITE => 'نوشتن روی دیسک ممکن نشد.',
    ];
    meu_fail($map[$err] ?? 'خطا در آپلود');
}

/* سقف حجم */
const MEU_MAX_BYTES = 10 * 1024 * 1024;   // ۱۰ مگابایت
$size = (int)($_FILES['file']['size'] ?? 0);
if ($size <= 0)               meu_fail('فایل خالی است.');
if ($size > MEU_MAX_BYTES)    meu_fail('حجم فایل بیش از ۱۰ مگابایت است.');

if (!is_uploaded_file($_FILES['file']['tmp_name'])) {
    meu_fail('منبع فایل معتبر نیست.');
}

/* ۳+۴) پسوند باید مجاز باشد و MIME واقعی با آن بخواند */
$allowed = [
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'webp' => ['image/webp'],
    'gif'  => ['image/gif'],
    'mp4'  => ['video/mp4'],
    'webm' => ['video/webm', 'audio/webm'],
    'mp3'  => ['audio/mpeg', 'audio/mp3'],
    'wav'  => ['audio/wav', 'audio/x-wav', 'audio/wave'],
    'ogg'  => ['audio/ogg', 'video/ogg', 'application/ogg'],
    'pdf'  => ['application/pdf'],
];

$ext = strtolower(pathinfo((string)$_FILES['file']['name'], PATHINFO_EXTENSION));
if (!isset($allowed[$ext])) {
    meu_fail('فرمت مجاز نیست.');
}

$mime = '';
if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) { $mime = (string)finfo_file($fi, $_FILES['file']['tmp_name']); finfo_close($fi); }
}
/* اگر finfo در دسترس نبود، برای تصاویر از getimagesize استفاده کن */
if ($mime === '' && in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) {
    $gi = @getimagesize($_FILES['file']['tmp_name']);
    $mime = $gi['mime'] ?? '';
}
if ($mime !== '' && !in_array($mime, $allowed[$ext], true)) {
    meu_fail('محتوای فایل با پسوند آن هم‌خوان نیست.');
}

/* ۶) پوشهٔ مقصد + خاموش‌کردن اجرای اسکریپت داخل آن */
$dir = __DIR__ . '/uploads/online-exams/media';
if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
    meu_fail('ساخت پوشهٔ مقصد ممکن نشد.', 500);
}
$ht = $dir . '/.htaccess';
if (!file_exists($ht)) {
    @file_put_contents($ht, "php_flag engine off\nOptions -ExecCGI -Indexes\n<FilesMatch \"\\.(php[0-9]?|phtml|phar|pl|py|cgi|asp|aspx|sh)$\">\n  Require all denied\n</FilesMatch>\n");
}

/* ۵) نام کاملاً تصادفی */
$fn   = bin2hex(random_bytes(16)) . '.' . $ext;
$dest = $dir . '/' . $fn;
if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
    meu_fail('خطا در ذخیرهٔ فایل', 500);
}
@chmod($dest, 0644);

$rel = 'uploads/online-exams/media/' . $fn;
echo json_encode(['ok' => true, 'path' => $rel, 'url' => $rel], JSON_UNESCAPED_UNICODE);
