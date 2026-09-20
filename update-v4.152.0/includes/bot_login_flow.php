<?php
// File: includes/bot_login_flow.php
/**
 * v4.152.0 — ورود مدیر از داخل ربات (بله و تلگرام)
 *
 * مدیر آموزشگاه در همان مرحله‌ای که ربات «کد ملی ۱۰ رقمی» می‌پرسد، نام کاربری
 * خودش را می‌فرستد. پیش از این، نام کاربری مدیر با پیام «کد ملی باید دقیقاً
 * ۱۰ رقم عددی باشد» رد می‌شد و تنها راه ورود مدیر، لینک مخفی
 * `/start admin_SECRET` بود.
 *
 * حالا:
 *   ۱) هر جا ربات یک شناسه یا رمز می‌پرسد (کد ملی دانش‌آموز/دبیر، سریال
 *      دانش‌آموز، کد پرسنلی دبیر)، اگر متن با نام کاربری یک مدیر **فعال**
 *      بخواند، ربات نقش مدیریت را تشخیص می‌دهد و به‌جای پیام خطا، رمز عبور
 *      مدیر را می‌پرسد.
 *   ۲) در مرحلهٔ بعد با تأیید رمز (همان `verify_user_password` سامانه)، حساب
 *      مدیریت روی همان پیام‌رسان به ربات اضافه می‌شود
 *      (`bot_admin_sessions` با `role_type='admin'` و `is_active=1`).
 *   ۳) سه تلاش برای رمز شمرده می‌شود؛ پس از آن گفتگو بسته می‌شود تا کسی
 *      نتواند رمز مدیر را حدس بزند.
 *
 * این فایل هیچ خروجی و هیچ کد اجرایی ندارد؛ فقط تابع. موتور وبهوک
 * (`includes/bot_webhook_engine.php`) در مرحلهٔ مربوطه آن را صدا می‌زند.
 */

if (!function_exists('bot_login_platform_fa')) {
    /** نام فارسی پیام‌رسان — در متن پیام‌ها استفاده می‌شود. */
    function bot_login_platform_fa($platform) {
        return bot_valid_platform($platform) === 'telegram' ? 'تلگرام' : 'بله';
    }
}

if (!function_exists('bot_login_send')) {
    /**
     * ارسال امن پیام در این فایل.
     *
     * موتور وبهوک تابع خودش (`bot_webhook_safe_send`) را دارد، ولی آن تابع
     * داخل خودِ موتور تعریف شده است؛ این فایل نباید به آن وابسته باشد تا
     * مستقل قابل آزمون باشد. تنها مسیر ارسال، همان `bot_send_message`
     * تولیدی است (صف ماندگار + لاگ).
     */
    function bot_login_send($platform, $chatId, $text, $keyboard = null) {
        try {
            return bot_send_message($platform, $chatId, $text, $keyboard);
        } catch (Exception $e) {
            error_log('bot login send failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('bot_login_admin_by_username')) {
    /**
     * مدیر فعالی که نام کاربری‌اش دقیقاً با این متن می‌خواند — وگرنه null.
     *
     * مقایسه دقیق و حساس به بزرگی/کوچکی حروف است (نام کاربری همان چیزی است
     * که مدیر در پنل ساخته است) و فقط حساب‌های فعال (`status=1`) پذیرفته
     * می‌شوند؛ حساب غیرفعال هرگز نمی‌تواند از ربات وارد شود.
     */
    function bot_login_admin_by_username($text) {
        $username = trim(tr_num((string)$text, 'en'));
        if ($username === '' || mb_strlen($username) > 190) return null;
        try {
            $row = DB::fetch("SELECT * FROM admins WHERE username=? AND status=1 LIMIT 1", [$username]);
        } catch (Exception $e) {
            return null;
        }
        return $row ?: null;
    }
}

if (!function_exists('bot_login_ask_admin_password')) {
    /**
     * مرحلهٔ دوم ورود مدیر: ذخیرهٔ نام کاربری در حالت گفتگو و پرسیدن رمز.
     *
     * رمز هرگز در حالت گفتگو ذخیره نمی‌شود؛ تنها نام کاربری می‌ماند.
     */
    function bot_login_ask_admin_password($platform, $chatId, array $admin, $username) {
        $platform = bot_valid_platform($platform);
        $stateTable = bot_state_table($platform);
        $chatCol = $platform === 'telegram' ? 'telegram_chat_id' : 'bale_chat_id';
        $username = (string)$username;
        DB::execute("REPLACE INTO `$stateTable` (`$chatCol`, step, temp_nid, temp_payload) VALUES (?, 'admin_password', ?, NULL)",
                    [$chatId, $username]);
        $msg = '👤 «' . $username . '» به عنوان مدیر آموزشگاه شناسایی شد.' . "\n\n"
             . '🔑 رمز عبور مدیر را ارسال کنید تا حساب مدیریت روی ربات «' . bot_login_platform_fa($platform) . '» به شما وصل شود.';
        bot_login_send($platform, $chatId, $msg);
        return true;
    }
}

if (!function_exists('bot_login_manager_entry')) {
    /**
     * اگر متن با نام کاربری یک مدیر فعال بخواند، ورود مدیر را شروع می‌کند.
     *
     * @return bool true یعنی «همین متن ورود مدیر بود» و موتور وبهوک باید
     *              همانجا تمام کند؛ false یعنی جریان عادی ادامه پیدا کند.
     */
    function bot_login_manager_entry($platform, $chatId, $text) {
        $admin = bot_login_admin_by_username($text);
        if (!$admin) return false;
        return bot_login_ask_admin_password($platform, $chatId, $admin, trim(tr_num((string)$text, 'en')));
    }
}

if (!function_exists('bot_login_admin_profile')) {
    /**
     * حساب مدیریت روی یک پیام‌رسان — همان چیزی که «تگ آزمایشی» و پیام‌های
     * مدیریتی به آن فرستاده می‌شود. برای آزمون و پنل: نام و تاریخ اتصال.
     */
    function bot_login_admin_profile($platform, $chatId) {
        $platform = bot_valid_platform($platform);
        try {
            return DB::fetch("SELECT bs.*, a.name AS admin_name, a.username AS admin_username
                                FROM bot_admin_sessions bs
                                LEFT JOIN admins a ON a.id = bs.admin_id
                               WHERE bs.platform=? AND bs.chat_id=? AND bs.role_type='admin' AND bs.is_active=1
                               ORDER BY bs.id DESC LIMIT 1", [$platform, (string)$chatId]) ?: null;
        } catch (Exception $e) {
            return null;
        }
    }
}
