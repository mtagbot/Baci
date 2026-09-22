<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/online_exam_helpers.php';
ensure_online_exams_schema();

if (empty($_SESSION['admin_id']) && empty($_SESSION['teacher_id'])) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'msg'=>'غیرمجاز']);
    exit;
}

if ($_SERVER['REQUEST_METHOD']!=='POST' || empty($_FILES['file'])) {
    echo json_encode(['ok'=>false,'msg'=>'فایل نرسید']);
    exit;
}

$allowed = ['jpg','jpeg','png','webp','gif','mp4','webm','mp3','wav','ogg','pdf'];
$ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowed)) {
    echo json_encode(['ok'=>false,'msg'=>'فرمت مجاز نیست']);
    exit;
}

$dir = __DIR__.'/uploads/online-exams/media';
if (!is_dir($dir)) mkdir($dir,0755,true);
$fn = time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
$dest = $dir.'/'.$fn;
if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
    echo json_encode(['ok'=>false,'msg'=>'خطا در آپلود']);
    exit;
}

$rel = 'uploads/online-exams/media/'.$fn;
echo json_encode(['ok'=>true,'path'=>$rel,'url'=>$rel], JSON_UNESCAPED_UNICODE);
?>
