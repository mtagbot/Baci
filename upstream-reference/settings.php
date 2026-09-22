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
    
    set_setting('theme_color', trim($_POST['theme_color'] ?? '#2563eb'));
    set_setting('accent_color', trim($_POST['accent_color'] ?? '#d97706'));
    set_setting('theme_mode', trim($_POST['theme_mode'] ?? 'light'));
    set_setting('font_family', trim($_POST['font_family'] ?? 'Vazirmatn'));
    set_setting('body_font_size', trim($_POST['body_font_size'] ?? '14px'));
    set_setting('body_text_color', trim($_POST['body_text_color'] ?? '#0f172a'));
    
    set_setting('heading_font_family', trim($_POST['heading_font_family'] ?? 'Vazirmatn'));
    set_setting('heading_font_size', trim($_POST['heading_font_size'] ?? '22px'));
    set_setting('heading_font_weight', trim($_POST['heading_font_weight'] ?? '700'));
    set_setting('heading_text_color', trim($_POST['heading_text_color'] ?? '#1e293b'));
    
    set_setting('table_font_size', trim($_POST['table_font_size'] ?? '13px'));
    set_setting('table_header_bg', trim($_POST['table_header_bg'] ?? '#f1f5f9'));
    $logoInput = trim($_POST['logo_url'] ?? '');
    $faviconInput = trim($_POST['favicon_url'] ?? '');
    // Production rule: only self-hosted/local asset paths are accepted; external CDN/URL values are discarded.
    if (preg_match('/^https?:\/\//i', $logoInput)) $logoInput = '';
    if (preg_match('/^https?:\/\//i', $faviconInput)) $faviconInput = '';
    set_setting('logo_url', $logoInput);
    set_setting('favicon_url', $faviconInput);

    // Handle Font File Upload if provided
    if (isset($_FILES['custom_font_file']) && $_FILES['custom_font_file']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['custom_font_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['woff2', 'woff', 'ttf', 'otf'])) {
            $fontDir = __DIR__ . '/uploads/fonts';
            if (!is_dir($fontDir)) mkdir($fontDir, 0777, true);
            $fontFilename = 'custom_font_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['custom_font_file']['tmp_name'], $fontDir . '/' . $fontFilename);
            set_setting('custom_font_url', 'uploads/fonts/' . $fontFilename);
            set_setting('font_family', 'CustomUploadedFont');
        }
    }

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

        <div class="flex justify-end mt-6">
            <button type="submit" class="btn btn-success px-8 py-3 font-bold shadow-lg">
                <span>💾 ذخیره و اعمال تغییرات تنظیمات ظاهری</span>
            </button>
        </div>
    </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
