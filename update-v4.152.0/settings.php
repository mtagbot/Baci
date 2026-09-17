<?php
// File: settings.php
/**
 * Advanced Visual & System Settings (settings.php)
 */

require_once __DIR__ . '/includes/auth.php';
require_permission('system_settings');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'خطای امنیتی CSRF');
        redirect('settings.php');
    }

    $uploadedFont=null;
    if(isset($_FILES['custom_font_file']) && $_FILES['custom_font_file']['error']!==UPLOAD_ERR_NO_FILE){
        $upload=$_FILES['custom_font_file'];$ext=strtolower(pathinfo($upload['name'],PATHINFO_EXTENSION));
        $signatures=['ttf'=>"\0\1\0\0",'otf'=>'OTTO','woff'=>'wOFF','woff2'=>'wOF2'];
        if($upload['error']!==UPLOAD_ERR_OK || !isset($signatures[$ext]) || $upload['size']>8*1024*1024 || $upload['size']<12 || file_get_contents($upload['tmp_name'],false,null,0,4)!==$signatures[$ext]){
            set_flash_message('error','فایل قلم معتبر نیست یا از ۸ مگابایت بزرگ‌تر است؛ تنظیمات قبلی حفظ شد.');redirect('settings.php');
        }
        $fontDir=__DIR__.'/uploads/fonts';if(!is_dir($fontDir))@mkdir($fontDir,0755,true);
        $uploadedFont='uploads/fonts/custom_font_'.bin2hex(random_bytes(16)).'.'.$ext;
        if(!move_uploaded_file($upload['tmp_name'],__DIR__.'/'.$uploadedFont)){
            set_flash_message('error','بارگذاری قلم انجام نشد؛ تنظیمات قبلی حفظ شد.');redirect('settings.php');
        }
    }

    set_setting('school_name', trim($_POST['school_name'] ?? ''));
    set_setting('school_phone', trim($_POST['school_phone'] ?? ''));
    set_setting('school_address', trim($_POST['school_address'] ?? ''));
    set_setting('school_province', trim($_POST['school_province'] ?? 'تهران'));
    set_setting('school_region', trim($_POST['school_region'] ?? 'منطقه ۳'));
    set_setting('school_unit_type', trim($_POST['school_unit_type'] ?? 'متوسطه اول پسرانه'));
    set_setting('report_header_line1', trim($_POST['report_header_line1'] ?? 'بسمه تعالی'));
    set_setting('report_header_line2', trim($_POST['report_header_line2'] ?? 'دبیرستان غیردولتی بصیرت'));
    set_setting('report_image_footer_text', trim($_POST['report_image_footer_text'] ?? 'تولید شده توسط سامانه مدیریت کارنامه - {date}'));
    set_setting('exam_header_title', trim($_POST['exam_header_title'] ?? 'سربرگ رسمی آزمون'));
    set_setting('exam_header_subtitle', trim($_POST['exam_header_subtitle'] ?? ''));
    
    // Handle stamp & signature upload
    foreach (['principal_signature_file' => 'principal_signature_url', 'school_stamp_file' => 'school_stamp_url', 'exam_stamp_file' => 'exam_stamp_url'] as $fInput => $fKey) {
        if (isset($_FILES[$fInput]) && $_FILES[$fInput]['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES[$fInput]['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp'])) {
                $dir = __DIR__ . '/uploads/stamps';
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                $fn = $fKey . '_' . time() . '.' . $ext;
                move_uploaded_file($_FILES[$fInput]['tmp_name'], $dir . '/' . $fn);
                set_setting($fKey, 'uploads/stamps/' . $fn);
            }
        }
    }
    
    set_setting('theme_color', app_color($_POST['theme_color'] ?? '', '#2563eb'));
    set_setting('success_color', app_color($_POST['success_color'] ?? '', '#15803d'));
    set_setting('danger_color', app_color($_POST['danger_color'] ?? '', '#b91c1c'));
    set_setting('accent_color', app_color($_POST['accent_color'] ?? '', '#d97706'));
    set_setting('theme_mode', trim($_POST['theme_mode'] ?? 'light'));
    $chosenFont=in_array($_POST['font_family']??'', ['Vazirmatn','Sahel','Yekan','Tahoma','CustomUploadedFont'],true)?$_POST['font_family']:'Vazirmatn';
    set_setting('font_family', $chosenFont);
    set_setting('body_font_size', trim($_POST['body_font_size'] ?? '14px'));
    set_setting('body_text_color', trim($_POST['body_text_color'] ?? '#0f172a'));
    
    set_setting('heading_font_family', $chosenFont);
    set_setting('heading_font_size', trim($_POST['heading_font_size'] ?? '22px'));
    set_setting('heading_font_weight', trim($_POST['heading_font_weight'] ?? '700'));
    set_setting('heading_text_color', trim($_POST['heading_text_color'] ?? '#1e293b'));
    
    set_setting('table_font_size', trim($_POST['table_font_size'] ?? '13px'));
    set_setting('table_header_bg', trim($_POST['table_header_bg'] ?? '#f1f5f9'));

    /* v4.132.0 — منوی کاشی‌ای هدر.
       پاک‌سازی در header_tiles_sanitize_post انجام می‌شود: فقط کلیدهای
       شناخته‌شده، بدون تکرار، حداکثر ۸ تا. */
    require_once __DIR__ . '/includes/header_tiles.php';
    set_setting('header_tiles', header_tiles_sanitize_post($_POST['header_tiles'] ?? []));
    set_setting('header_tiles_enabled', isset($_POST['header_tiles_enabled']) ? '1' : '0');
    $logoInput = trim($_POST['logo_url'] ?? '');
    $faviconInput = trim($_POST['favicon_url'] ?? '');
    // Production rule: only self-hosted/local asset paths are accepted; external CDN/URL values are discarded.
    if (preg_match('/^https?:\/\//i', $logoInput)) $logoInput = '';
    if (preg_match('/^https?:\/\//i', $faviconInput)) $faviconInput = '';
    set_setting('logo_url', $logoInput);
    set_setting('favicon_url', $faviconInput);

    if($uploadedFont!==null){set_setting('custom_font_url',$uploadedFont);set_setting('font_family','CustomUploadedFont');set_setting('heading_font_family','CustomUploadedFont');}

    set_setting('sms_provider', trim($_POST['sms_provider'] ?? 'kavenegar'));
    set_setting('sms_api_key', trim($_POST['sms_api_key'] ?? ''));
    set_setting('sms_username', trim($_POST['sms_username'] ?? ''));
    set_setting('sms_password', trim($_POST['sms_password'] ?? ''));
    set_setting('sms_sender_number', trim($_POST['sms_sender_number'] ?? ''));

    log_activity($_SESSION['admin_id'] ?? null, 'تغییر تنظیمات سیستم', 'تنظیمات ظاهری، سربرگ کارنامه، فونت و پیامک بروزرسانی شد.');
    set_flash_message('success', 'تنظیمات ظاهری و سفارشی‌سازی سایت با موفقیت اعمال شد.');
    redirect('settings.php');
}

require_once __DIR__ . '/includes/header.php';

$s_name    = get_setting('school_name', 'دبیرستان نمونه دولتی نخبگان');
$s_prov    = get_setting('school_province', 'تهران');
$s_reg     = get_setting('school_region', 'منطقه ۳ آموزش و پرورش');
$s_unit    = get_setting('school_unit_type', 'متوسطه اول پسرانه');
$s_phone   = get_setting('school_phone', '021-88888888');
$s_addr    = get_setting('school_address', 'تهران، خیابان ولیعصر');
$r_line1   = get_setting('report_header_line1', 'بسمه تعالی');
$r_line2   = get_setting('report_header_line2', 'دبیرستان غیردولتی بصیرت');
$report_img_footer = get_setting('report_image_footer_text', 'تولید شده توسط سامانه مدیریت کارنامه - {date}');
$exam_title = get_setting('exam_header_title', 'سربرگ رسمی آزمون');
$exam_subtitle = get_setting('exam_header_subtitle', '');
$p_sig     = get_setting('principal_signature_url', '');
$s_stamp   = get_setting('school_stamp_url', '');
$e_stamp   = get_setting('exam_stamp_url', '');

$s_color   = get_setting('theme_color', '#2563eb');
$s_accent  = get_setting('accent_color', '#d97706');
$s_mode    = get_setting('theme_mode', 'light');
$s_font    = get_setting('font_family', 'Vazirmatn');
$b_size    = get_setting('body_font_size', '14px');
$b_color   = get_setting('body_text_color', '#0f172a');

$h_font    = get_setting('heading_font_family', 'Vazirmatn');
$h_size    = get_setting('heading_font_size', '22px');
$h_weight  = get_setting('heading_font_weight', '700');
$h_color   = get_setting('heading_text_color', '#1e293b');

$t_size    = get_setting('table_font_size', '13px');
$t_bg      = get_setting('table_header_bg', '#f1f5f9');
$s_cfont   = get_setting('custom_font_url', '');
$s_logo    = get_setting('logo_url', '');
$s_fav     = get_setting('favicon_url', '');

$sms_prov  = get_setting('sms_provider', 'kavenegar');
$sms_key   = get_setting('sms_api_key', '');
$sms_user  = get_setting('sms_username', '');
$sms_pass  = get_setting('sms_password', '');
$sms_send  = get_setting('sms_sender_number', '');
?>
<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold">🎨 سفارشی‌سازی کامل ظاهری، سربرگ کارنامه و تنظیمات</h2>
            <p class="text-sm text-muted">مدیریت اطلاعات مدرسه، سربرگ چاپ کارنامه‌ها، تغییر رنگ‌بندی، آپلود فونت‌های محلی، لوگو و درگاه پیامک</p>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="save_settings" value="1">

        <div class="grid grid-cols-2 gap-6">
            <!-- General Settings -->
            <div class="card shadow-lg space-y-4">
                <h3 class="font-bold text-primary border-b pb-2">اطلاعات آموزشگاه و سربرگ کارنامه‌ها</h3>
                <div>
                    <label class="block text-xs font-semibold mb-1">نام رسمی آموزشگاه در سیستم</label>
                    <input type="text" name="school_name" class="form-input" value="<?php echo clean($s_name); ?>" required>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold mb-1">خط اول سربرگ چاپ کارنامه</label>
                        <input type="text" name="report_header_line1" class="form-input font-bold text-blue-600" value="<?php echo clean($r_line1); ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">خط دوم سربرگ چاپ کارنامه</label>
                        <input type="text" name="report_header_line2" class="form-input font-bold text-blue-600" value="<?php echo clean($r_line2); ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">متن فوتر تصویر کارنامه ربات ({date})</label>
                        <input type="text" name="report_image_footer_text" class="form-input" value="<?php echo clean($report_img_footer); ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">خط اول هدر سربرگ آزمون</label>
                        <input type="text" name="exam_header_title" class="form-input font-bold text-blue-600" value="<?php echo clean($exam_title); ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">خط دوم هدر سربرگ آزمون</label>
                        <input type="text" name="exam_header_subtitle" class="form-input font-bold text-blue-600" value="<?php echo clean($exam_subtitle); ?>">
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-semibold mb-1">استان</label>
                        <input type="text" name="school_province" class="form-input text-xs" value="<?php echo clean($s_prov); ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">منطقه / ناحیه</label>
                        <input type="text" name="school_region" class="form-input text-xs" value="<?php echo clean($s_reg); ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">نوع واحد آموزشی</label>
                        <input type="text" name="school_unit_type" class="form-input text-xs" value="<?php echo clean($s_unit); ?>">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold mb-1">آپلود تصویر مهر مدرسه (PNG / JPG)</label>
                        <input type="file" name="school_stamp_file" class="form-input p-1 text-xs" accept=".png,.jpg,.jpeg,.webp">
                        <?php if ($s_stamp): ?><small class="text-[11px] text-green-600 block mt-1">مهر فعال: <code><?php echo clean($s_stamp); ?></code></small><?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">آپلود امضای مدیر مدرسه (PNG شفاف)</label>
                        <input type="file" name="principal_signature_file" class="form-input p-1 text-xs" accept=".png,.jpg,.jpeg,.webp">
                        <?php if ($p_sig): ?><small class="text-[11px] text-green-600 block mt-1">امضای فعال: <code><?php echo clean($p_sig); ?></code></small><?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">آپلود مهر امتحانات</label>
                        <input type="file" name="exam_stamp_file" class="form-input p-1 text-xs" accept=".png,.jpg,.jpeg,.webp">
                        <?php if ($e_stamp): ?><small class="text-[11px] text-green-600 block mt-1">مهر امتحانات: <code><?php echo clean($e_stamp); ?></code></small><?php endif; ?>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1">تلفن تماس مدرسه</label>
                    <input type="text" name="school_phone" class="form-input dir-ltr text-left" value="<?php echo clean($s_phone); ?>">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1">آدرس پستی</label>
                    <textarea name="school_address" rows="2" class="form-textarea"><?php echo clean($s_addr); ?></textarea>
                </div>
            </div>

            <!-- Visual & Font Settings -->
            <div class="card shadow-lg space-y-4">
                <h3 class="font-bold text-primary border-b pb-2">🎨 سفارشی‌سازی جلوه‌های بصری و فونت‌ها</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold mb-1">رنگ اصلی سایت (Primary Color)</label>
                        <div class="flex items-center gap-2">
                            <input type="color" name="theme_color" class="h-10 w-16 p-1 border rounded cursor-pointer" value="<?php echo clean($s_color); ?>">
                            <input type="text" class="form-input text-xs dir-ltr text-center font-mono" value="<?php echo clean($s_color); ?>" disabled>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">رنگ ثانویه / طلایی (Accent Color)</label>
                        <div class="flex items-center gap-2">
                            <input type="color" name="accent_color" class="h-10 w-16 p-1 border rounded cursor-pointer" value="<?php echo clean($s_accent); ?>">
                            <input type="text" class="form-input text-xs dir-ltr text-center font-mono" value="<?php echo clean($s_accent); ?>" disabled>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold mb-1">حالت پیش‌فرض پوسته</label>
                        <select name="theme_mode" class="form-select">
                            <option value="light" <?php echo $s_mode === 'light' ? 'selected' : ''; ?>>روشن (Light Theme)</option>
                            <option value="dark" <?php echo $s_mode === 'dark' ? 'selected' : ''; ?>>تاریک (Dark Theme)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">انتخاب قلم (فونت متن‌ها)</label>
                        <select name="font_family" class="form-select">
                            <option value="Vazirmatn" <?php echo $s_font === 'Vazirmatn' ? 'selected' : ''; ?>>وزیرمتن (Vazirmatn)</option>
                            <option value="Tahoma" <?php echo $s_font === 'Tahoma' ? 'selected' : ''; ?>>تاهوما سیستم (Fallback)</option>
                            <option value="Sahel" <?php echo $s_font === 'Sahel' ? 'selected' : ''; ?>>ساحل محلی (uploads/Sahel)</option>
                            <option value="Yekan" <?php echo $s_font === 'Yekan' ? 'selected' : ''; ?>>یکان محلی (uploads/Yekan)</option>
                            <?php if ($s_cfont): ?>
                            <option value="CustomUploadedFont" <?php echo $s_font === 'CustomUploadedFont' ? 'selected' : ''; ?>>فونت بارگذاری‌شده محلی اختصاصی</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <label>رنگ سوم — تأیید و موفقیت<input type="color" name="success_color" value="<?php echo clean(app_palette()['success']); ?>"></label>
                    <label>رنگ چهارم — خطا و حذف<input type="color" name="danger_color" value="<?php echo clean(app_palette()['danger']); ?>"></label>
                </div>
                <p class="text-xs">قلم متن‌ها روی عنوان‌ها، فرم‌ها و گزارش‌ها نیز اعمال می‌شود. برای یکسان بودن خروجی PDF و تصویر با پیش‌نمایش، قلم محلی TTF انتخاب کنید. قلم سیستمی Tahoma یا WOFF از مسیر چاپ مرورگر PDF می‌شود؛ برای تصویر سرور، فایل TTF همان قلم لازم است. قالب‌های ثابت لیست دبیر و مدرسه قلم تیتر خود را حفظ می‌کنند.</p>
                <div>
                    <label class="block text-xs font-semibold mb-1">آپلود فونت اختصاصی روی هاست (WOFF2 / TTF)</label>
                    <input type="file" name="custom_font_file" class="form-input p-1.5 text-xs" accept=".woff2,.woff,.ttf,.otf">
                    <?php if ($s_cfont): ?>
                    <small class="text-[11px] text-green-600 block mt-1">فونت فعال محلی: <code><?php echo clean($s_cfont); ?></code></small>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs font-semibold mb-1">مسیر محلی لوگو در هاست</label>
                        <input type="text" name="logo_url" class="form-input dir-ltr text-left text-xs" placeholder="uploads/logo.png" value="<?php echo clean($s_logo); ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">مسیر محلی آیکون مرورگر</label>
                        <input type="text" name="favicon_url" class="form-input dir-ltr text-left text-xs" placeholder="uploads/favicon.ico" value="<?php echo clean($s_fav); ?>">
                    </div>
                </div>
            </div>

            <!-- SMS Provider Settings -->
            <div class="card col-span-2 shadow-lg space-y-4">
                <h3 class="font-bold text-primary border-b pb-2">تنظیمات درگاه پیامک (SMS Provider Configuration)</h3>
                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-semibold mb-1">انتخاب ارائه‌دهنده سرویس</label>
                        <select name="sms_provider" class="form-select">
                            <option value="kavenegar" <?php echo $sms_prov === 'kavenegar' ? 'selected' : ''; ?>>کاوه نگار (Kavenegar)</option>
                            <option value="smsir" <?php echo $sms_prov === 'smsir' ? 'selected' : ''; ?>>اس ام اس دات آی آر (Sms.ir)</option>
                            <option value="melipayamak" <?php echo $sms_prov === 'melipayamak' ? 'selected' : ''; ?>>ملی پیامک (Melipayamak)</option>
                            <option value="farazsms" <?php echo $sms_prov === 'farazsms' ? 'selected' : ''; ?>>فراز اس ام اس (FarazSMS)</option>
                            <option value="mock" <?php echo $sms_prov === 'mock' ? 'selected' : ''; ?>>شبیه‌ساز محلی (صرفاً ثبت در دیتابیس)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">کلید/نام کاربری وب‌سرویس</label>
                        <input type="password" name="sms_api_key" class="form-input dir-ltr text-left" value="<?php echo clean($sms_key); ?>" placeholder="برای ملی پیامک می‌تواند username:password باشد">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">نام کاربری ملی پیامک</label>
                        <input type="text" name="sms_username" class="form-input dir-ltr text-left" value="<?php echo clean($sms_user); ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">رمز عبور ملی پیامک</label>
                        <input type="password" name="sms_password" class="form-input dir-ltr text-left" value="<?php echo clean($sms_pass); ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">شماره خط اختصاصی ارسال</label>
                        <input type="text" name="sms_sender_number" class="form-input dir-ltr text-left" value="<?php echo clean($sms_send); ?>">
                    </div>
                </div>
            </div>
        </div>

        <?php /* ═══ v4.132.0 — سکشن جداگانهٔ «منوی سریع هدر» ═══ */ ?>
        <?php
            require_once __DIR__ . '/includes/header_tiles.php';
            $tileCat      = header_tiles_catalog();
            $tileSelected = header_tiles_selected();
            $tileMax      = header_tiles_max();
            $tilesOn      = header_tiles_enabled();
            /* انتخاب‌شده‌ها اول و به ترتیب، بقیه بعد از آن‌ها */
            $tileOrdered = $tileSelected;
            foreach ($tileCat as $k => $_v) if (!in_array($k, $tileOrdered, true)) $tileOrdered[] = $k;
        ?>
        <div class="card shadow-lg space-y-4 mt-6" id="headerTilesSection">
            <div class="flex justify-between items-center border-b pb-2 flex-wrap gap-2">
                <div>
                    <h3 class="font-bold text-primary">🧩 منوی سریع هدر (کاشی‌ها)</h3>
                    <p class="text-xs text-muted">
                        کاشی‌های میانبر وسط نوار بالا — فقط برای مدیران نمایش داده می‌شود.
                        حداکثر <b><?php echo $tileMax; ?></b> کاشی در یک ردیف.
                        ترتیب کاشی‌ها همان ترتیب انتخاب شماست.
                    </p>
                </div>
                <label class="tile-opt <?php echo $tilesOn ? 'is-on' : 'is-off'; ?>" style="cursor:pointer" id="tilesEnableWrap">
                    <input type="checkbox" name="header_tiles_enabled" id="tilesEnable" value="1" <?php echo $tilesOn ? 'checked' : ''; ?>>
                    <span class="tn">نمایش منوی کاشی‌ای</span>
                </label>
            </div>

            <div>
                <div class="flex justify-between items-center mb-2 flex-wrap gap-2">
                    <label class="block text-xs font-semibold">پیش‌نمایش زنده</label>
                    <span class="text-xs text-muted">
                        انتخاب‌شده: <b id="tileCount">0</b> از <?php echo $tileMax; ?>
                        <span id="tileFull" style="color:#b45309;display:none">— به سقف رسیدید؛ برای افزودن مورد جدید، یکی را بردارید.</span>
                    </span>
                </div>
                <div class="tile-preview"><div class="hdr-tiles" id="tilePreview" style="max-width:100%;margin:0"></div></div>
            </div>

            <div>
                <label class="block text-xs font-semibold mb-2">کاشی‌های قابل انتخاب</label>
                <div class="tile-picker">
                    <?php foreach ($tileOrdered as $k):
                        $tile = $tileCat[$k];
                        $on   = in_array($k, $tileSelected, true);
                        /* موردی که مدیر فعلی مجوزش را ندارد نمایش داده می‌شود ولی
                           با توضیح — تا چیدمان مدرسه بدون اطلاع تغییر نکند. */
                        $can  = header_tiles_allowed($k);
                    ?>
                    <label class="tile-opt <?php echo $on ? 'is-on' : 'is-off'; ?>" data-key="<?php echo clean($k); ?>"
                           data-ic="<?php echo clean($tile['ic'] ?? ''); ?>" data-title="<?php echo clean($tile['t']); ?>"
                           <?php if (!$can): ?>title="شما به این بخش دسترسی ندارید؛ برای مدیرانی که دسترسی دارند نمایش داده می‌شود."<?php endif; ?>>
                        <input type="checkbox" name="header_tiles[]" value="<?php echo clean($k); ?>" <?php echo $on ? 'checked' : ''; ?>>
                        <span class="ti"><?php
                            if (!empty($tile['ic'])) tile_icon($tile['ic'], 'tile-opt-ic');
                            else echo $tile['i'];
                        ?></span>
                        <span class="tn"><?php echo clean($tile['t']); ?><?php if (!$can): ?> <span class="text-muted" style="font-weight:400">(بدون دسترسی)</span><?php endif; ?></span>
                        <span class="tile-order"></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="flex justify-end mt-6">
            <button type="submit" class="btn btn-success px-8 py-3 font-bold shadow-lg">
                <span>💾 ذخیره و اعمال تغییرات تنظیمات ظاهری</span>
            </button>
        </div>
    </form>

    <script>
    /* v4.132.0 — پیش‌نمایش زندهٔ کاشی‌ها + اعمال سقف ۸ تایی.
       سقف در سرور هم اعمال می‌شود؛ این فقط برای این است که کاربر
       بلافاصله بفهمد، نه اینکه بعد از ذخیره تعجب کند. */
    (function () {
        var MAX     = <?php echo (int)$tileMax; ?>;
        var section = document.getElementById('headerTilesSection');
        if (!section) return;
        var boxes   = Array.prototype.slice.call(section.querySelectorAll('input[name="header_tiles[]"]'));
        var preview = document.getElementById('tilePreview');
        var counter = document.getElementById('tileCount');
        var fullMsg = document.getElementById('tileFull');
        var enable  = document.getElementById('tilesEnable');
        var enWrap  = document.getElementById('tilesEnableWrap');
        var order   = [];

        boxes.forEach(function (b) { if (b.checked) order.push(b.value); });

        function render() {
            var n = order.length;
            counter.textContent = n;
            fullMsg.style.display = (n >= MAX) ? '' : 'none';

            preview.style.setProperty('--hdr-tiles-count', Math.max(n, 1));
            preview.innerHTML = '';
            if (!n) {
                var e = document.createElement('span');
                e.className = 'text-xs text-muted';
                e.textContent = 'هیچ کاشی‌ای انتخاب نشده — نوار کاشی نمایش داده نمی‌شود.';
                preview.appendChild(e);
            }
            order.forEach(function (key) {
                var lab = section.querySelector('.tile-opt[data-key="' + key + '"]');
                if (!lab) return;
                var a = document.createElement('span');
                a.className = 'hdr-tile';
                /* پیش‌نمایش باید دقیقاً همان چیزی باشد که در هدر رندر
                   می‌شود، پس همان <use> از sprite ساخته می‌شود.
                   createElementNS لازم است: SVG فضای‌نام خودش را دارد و
                   با createElement ساخته نمی‌شود. */
                var NS = 'http://www.w3.org/2000/svg';
                var wrap = document.createElement('span');
                wrap.className = 'hdr-tile-i';
                var ic = lab.getAttribute('data-ic');
                if (ic) {
                    var svg = document.createElementNS(NS, 'svg');
                    svg.setAttribute('class', 'hdr-tile-ic');
                    svg.setAttribute('xmlns:xlink', 'http://www.w3.org/1999/xlink');
                    svg.setAttribute('viewBox', '0 0 24 24');
                    svg.setAttribute('aria-hidden', 'true');
                    var use = document.createElementNS(NS, 'use');
                    use.setAttribute('href', '#t-' + ic);
                    use.setAttributeNS('http://www.w3.org/1999/xlink', 'xlink:href', '#t-' + ic);
                    svg.appendChild(use);
                    wrap.appendChild(svg);
                }
                var txt = document.createElement('span');
                txt.className = 'hdr-tile-t';
                txt.textContent = lab.dataset.title;
                a.appendChild(wrap); a.appendChild(txt);
                preview.appendChild(a);
            });

            boxes.forEach(function (b) {
                var lab = b.closest('.tile-opt');
                var pos = order.indexOf(b.value);
                lab.classList.toggle('is-on', pos !== -1);
                lab.classList.toggle('is-off', pos === -1);
                lab.querySelector('.tile-order').textContent = pos === -1 ? '' : (pos + 1);
                /* وقتی پر است، بقیه غیرفعال می‌شوند تا انتخاب بی‌اثر نماند */
                b.disabled = (pos === -1 && order.length >= MAX);
                if (b.disabled) lab.style.cursor = 'not-allowed';
                else lab.style.cursor = 'pointer';
            });

            var on = enable.checked;
            preview.style.opacity = on ? '1' : '.4';
            enWrap.classList.toggle('is-on', on);
            enWrap.classList.toggle('is-off', !on);
        }

        boxes.forEach(function (b) {
            b.addEventListener('change', function () {
                var i = order.indexOf(b.value);
                if (b.checked && i === -1) {
                    if (order.length >= MAX) { b.checked = false; return; }
                    order.push(b.value);          /* ترتیب = ترتیب انتخاب */
                } else if (!b.checked && i !== -1) {
                    order.splice(i, 1);
                }
                render();
            });
        });
        enable.addEventListener('change', render);
        render();
    })();
    </script>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
