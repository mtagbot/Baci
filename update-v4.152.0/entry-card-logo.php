<?php
// Dedicated custom-card logo; never overwrite the school's logo or student photos.
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/school_roles.php';
function card_logo_reply($ok, $message, $extra=[], $status=200) {
    if(!headers_sent()){http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');}
    echo json_encode(array_merge(['ok'=>$ok,'message'=>$message],$extra),JSON_UNESCAPED_UNICODE);exit;
}
if (!(is_admin_logged_in() && has_permission('manage_students')) && !(is_teacher_logged_in() && teacher_has_deputy($_SESSION['teacher_id']??0))) card_logo_reply(false,'دسترسی مجاز نیست.',[],403);
if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') card_logo_reply(false,'درخواست باید POST باشد.',[],405);
if (!is_string($_POST['csrf_token']??null) || !verify_csrf($_POST['csrf_token'])) card_logo_reply(false,'خطای امنیتی؛ صفحه را تازه‌سازی کنید.',[],403);
$action=$_POST['action']??'';
if($action==='reset') {
    if(!set_setting('card_custom_logo',''))card_logo_reply(false,'ذخیره تنظیم ناموفق بود.',[],500);
    card_logo_reply(true,'لوگوی پیش‌فرض برگشت.', ['url'=>(string)get_setting('logo_url','')]);
}
if($action!=='upload')card_logo_reply(false,'عملیات نامعتبر است.',[],400);
$data=$_POST['image_data']??null;
if(!is_string($data) || strlen($data)>2800000 || !preg_match('~^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$~D',$data,$m))card_logo_reply(false,'فقط تصویر PNG، JPG یا WebP تا ۲ مگابایت مجاز است.',[],400);
$bytes=base64_decode($m[2],true);
if($bytes===false || strlen($bytes)>2*1024*1024)card_logo_reply(false,'حجم یا محتوای تصویر معتبر نیست.',[],400);
$info=@getimagesizefromstring($bytes);
if(!$info || !in_array($info[2],[IMAGETYPE_PNG,IMAGETYPE_JPEG,IMAGETYPE_WEBP],true) || $info[0]<1 || $info[1]<1 || $info[0]>4096 || $info[1]>4096 || $info[0]*$info[1]>8000000)card_logo_reply(false,'تصویر نامعتبر یا بیش از حد بزرگ است (حداکثر ۴۰۹۶ پیکسل و ۸ مگاپیکسل).',[],400);
if(!function_exists('imagecreatefromstring') || !function_exists('imagepng'))card_logo_reply(false,'افزونه GD برای آپلود لوگو باید روی سرور فعال باشد.',[],503);
// Decode/re-encode: do not save client bytes, extension, metadata or embedded scripts.
$source=@imagecreatefromstring($bytes);
if(!$source)card_logo_reply(false,'خواندن تصویر ممکن نیست.',[],400);
$ratio=min(1,512/max($info[0],$info[1]));$w=max(1,(int)round($info[0]*$ratio));$h=max(1,(int)round($info[1]*$ratio));
$image=imagecreatetruecolor($w,$h);imagealphablending($image,false);imagesavealpha($image,true);
imagefilledrectangle($image,0,0,$w,$h,imagecolorallocatealpha($image,0,0,0,127));
imagecopyresampled($image,$source,0,0,0,0,$w,$h,$info[0],$info[1]);imagedestroy($source);
$dir=__DIR__.'/uploads/card-logos';
if(!is_dir($dir) && !@mkdir($dir,0755,true)){imagedestroy($image);card_logo_reply(false,'ساخت پوشه آپلود ممکن نیست.',[],500);}
$url='uploads/card-logos/'.bin2hex(random_bytes(16)).'.png';$path=__DIR__.'/'.$url;
$written=imagepng($image,$path,6);imagedestroy($image);
if(!$written)card_logo_reply(false,'ذخیره تصویر ممکن نیست.',[],500);
if(!set_setting('card_custom_logo',$url)){@unlink($path);card_logo_reply(false,'ذخیره تنظیم ناموفق بود؛ لوگوی قبلی حفظ شد.',[],500);}
// Old images are retained for recovery; resetting/replacing must not delete shared assets.
card_logo_reply(true,'لوگوی کارت سفارشی ذخیره شد.', ['url'=>$url]);
