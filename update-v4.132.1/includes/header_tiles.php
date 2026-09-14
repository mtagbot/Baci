<?php
/**
 * includes/header_tiles.php — v4.132.0
 *
 * منوی کاشی‌ای وسط هدر (فقط نقش مدیر) + موتور «کپچای تطبیقی».
 *
 * چرا یک فایل جدا و نه داخل header.php:
 * header.php روی هر صفحه اجرا می‌شود و ۲۱۶ خط است؛ اضافه‌کردن کاتالوگ
 * منو و منطق مجوزها داخلش آن را به فایلی می‌کرد که همه چیز را می‌داند.
 * اینجا فقط «داده + یک تابع رندر» است و header.php صدایش می‌زند.
 *
 * تنظیمات به‌صورت سراسری در جدول settings ذخیره می‌شود (کلید
 * header_tiles) چون کاربر خواست فقط مدیریت این منو را داشته باشد و
 * یک چیدمان برای کل مدرسه کافی است.
 */

if (!function_exists('header_tiles_catalog')) {
    /**
     * کاتالوگ کاشی‌های مجاز.
     *
     * عمداً یک allow-list بسته است: مقدار ذخیره‌شده در دیتابیس فقط
     * می‌تواند «کلید» باشد، نه URL. پس حتی اگر کسی مقدار setting را
     * دستکاری کند نمی‌تواند لینک دلخواه یا جاوااسکریپت تزریق کند.
     */
    function header_tiles_catalog() {
        return [
            'dashboard'   => ['t' => 'داشبورد',        'i' => '🏠', 'u' => 'index.php?view=dashboard', 'p' => null],
            'students'    => ['t' => 'دانش‌آموزان',    'i' => '🎓', 'u' => 'students.php',            'p' => 'manage_students'],
            'attendance'  => ['t' => 'حضور و غیاب',    'i' => '📋', 'u' => 'attendance.php',          'p' => 'manage_students'],
            'courses'     => ['t' => 'دروس',           'i' => '📚', 'u' => 'courses-management.php',  'p' => 'manage_classes'],
            'reports'     => ['t' => 'کارنامه‌ها',      'i' => '📊', 'u' => 'reports-management.php',  'p' => 'manage_reports'],
            'exams'       => ['t' => 'امتحانات حضوری', 'i' => '📝', 'u' => 'exams.php',               'p' => 'manage_reports'],
            'online'      => ['t' => 'آزمون مجازی',    'i' => '💻', 'u' => 'online-exams.php',        'p' => 'manage_reports'],
            'analytics'   => ['t' => 'تحلیل و آمار',   'i' => '📈', 'u' => 'analytics.php',           'p' => 'manage_reports'],
            'recovery'    => ['t' => 'بازیابی دانش‌آموز','i' => '♻️', 'u' => 'student-recovery.php',   'p' => 'import_data'],
            'messages'    => ['t' => 'پیامک و اعلان',  'i' => '✉️', 'u' => 'messages-management.php', 'p' => 'send_sms'],
            'bale'        => ['t' => 'بازوی بله',      'i' => '🤖', 'u' => 'bale-bot.php',            'p' => 'send_sms'],
            'telegram'    => ['t' => 'ربات تلگرام',    'i' => '✈️', 'u' => 'telegram-bot.php',        'p' => 'send_sms'],
            'botaccounts' => ['t' => 'اکانت‌های متصل', 'i' => '🔗', 'u' => 'bot-accounts.php',        'p' => 'send_sms'],
            'backups'     => ['t' => 'پشتیبان‌گیری',    'i' => '💾', 'u' => 'backups.php',             'p' => null],
            'logs'        => ['t' => 'گزارش فعالیت',   'i' => '🕓', 'u' => 'activity-logs.php',       'p' => null],
            'othersets'   => ['t' => 'تنظیمات دیگر',   'i' => '⚙️', 'u' => 'other-settings.php',      'p' => null],
            'sync'        => ['t' => 'همگام‌سازی',      'i' => '🔄', 'u' => 'desk-sync.php',           'p' => null],
            'dbhealth'    => ['t' => 'سلامت پایگاه',   'i' => '🩺', 'u' => 'db-optimizer.php',        'p' => 'system_settings'],
            'settings'    => ['t' => 'سفارشی‌سازی',    'i' => '🎨', 'u' => 'settings.php',            'p' => 'system_settings'],
            'admins'      => ['t' => 'مدیران',         'i' => '👥', 'u' => 'admins.php',              'p' => '__super__'],
        ];
    }
}

if (!function_exists('header_tiles_default')) {
    function header_tiles_default() {
        return ['dashboard', 'students', 'attendance', 'reports', 'online', 'messages', 'backups', 'settings'];
    }
}

if (!function_exists('header_tiles_max')) {
    /** حداکثر کاشی در یک ردیف — خواستهٔ کاربر: حداکثر ۸ */
    function header_tiles_max() { return 8; }
}

if (!function_exists('header_tiles_allowed')) {
    /** آیا کاربر فعلی اجازهٔ دیدن این کاشی را دارد؟ */
    function header_tiles_allowed($key) {
        $cat = header_tiles_catalog();
        if (!isset($cat[$key])) return false;
        $perm = $cat[$key]['p'];
        if ($perm === null) return true;
        if ($perm === '__super__') return (($_SESSION['admin_role'] ?? '') === 'super_admin');
        return function_exists('has_permission') ? has_permission($perm) : true;
    }
}

if (!function_exists('header_tiles_selected')) {
    /**
     * کاشی‌های انتخاب‌شده، پاک‌سازی‌شده.
     *
     * هر مقدار ناشناخته دور ریخته می‌شود، تکراری‌ها حذف، و سقف ۸ اعمال
     * می‌شود — چه از دیتابیس بیاید چه از فرم. یعنی حتی اگر ردیف settings
     * دستکاری شود، رندر همچنان امن و در یک ردیف می‌ماند.
     */
    function header_tiles_selected() {
        $cat = header_tiles_catalog();
        $raw = function_exists('get_setting') ? get_setting('header_tiles', '') : '';
        $keys = ($raw === '') ? header_tiles_default() : array_filter(array_map('trim', explode(',', $raw)));

        $out = [];
        foreach ($keys as $k) {
            if (isset($cat[$k]) && !in_array($k, $out, true)) $out[] = $k;
            if (count($out) >= header_tiles_max()) break;
        }
        return $out;
    }
}

if (!function_exists('header_tiles_sanitize_post')) {
    /** همان پاک‌سازی، ولی روی ورودی فرم. خروجی: رشتهٔ آمادهٔ ذخیره. */
    function header_tiles_sanitize_post($posted) {
        $cat = header_tiles_catalog();
        if (!is_array($posted)) $posted = [];
        $out = [];
        foreach ($posted as $k) {
            $k = trim((string)$k);
            if (isset($cat[$k]) && !in_array($k, $out, true)) $out[] = $k;
            if (count($out) >= header_tiles_max()) break;
        }
        return implode(',', $out);
    }
}

if (!function_exists('header_tiles_enabled')) {
    function header_tiles_enabled() {
        return get_setting('header_tiles_enabled', '1') === '1';
    }
}

if (!function_exists('render_header_tiles')) {
    /**
     * رندر نوار کاشی‌ها. فقط برای مدیرِ واردشده.
     * یک ردیف، Grid، بدون شکستن به ردیف دوم (خواستهٔ کاربر).
     */
    function render_header_tiles() {
        if (!function_exists('is_admin_logged_in') || !is_admin_logged_in()) return;
        if (!header_tiles_enabled()) return;

        $keys = header_tiles_selected();
        $cat  = header_tiles_catalog();
        $self = basename($_SERVER['PHP_SELF'] ?? '');

        $visible = [];
        foreach ($keys as $k) {
            if (header_tiles_allowed($k)) $visible[] = $k;
        }
        if (!$visible) return;

        echo '<nav class="hdr-tiles" role="navigation" aria-label="دسترسی سریع" style="--hdr-tiles-count:' . count($visible) . '">';
        foreach ($visible as $k) {
            $tile = $cat[$k];
            $file = strtok($tile['u'], '?');
            $isActive = ($file === $self);
            /* داشبورد فقط وقتی فعال است که واقعاً روی نمای داشبورد باشیم */
            if ($k === 'dashboard') {
                $isActive = ($self === 'index.php')
                    && (!isset($_GET['view']) || ($_GET['view'] ?? '') === 'dashboard');
            } elseif ($file === 'index.php') {
                $isActive = false;
            }
            printf(
                '<a class="hdr-tile%s" href="%s" title="%s"><span class="hdr-tile-i" aria-hidden="true">%s</span><span class="hdr-tile-t">%s</span></a>',
                $isActive ? ' is-active' : '',
                clean($tile['u']),
                clean($tile['t']),
                $tile['i'],
                clean($tile['t'])
            );
        }
        echo '</nav>';
    }
}

/* ══════════════════════════════════════════════════════════════════
   کپچای تطبیقی — «بار اول بدون کد امنیتی، بعد از اشتباه همیشه با کد»

   تصمیم کاربر: وضعیت به «نام کاربری مدیر» گره بخورد و در دیتابیس
   بماند، نه در نشست. دلیلش مهم است: اگر در session نگه داشته شود،
   پاک‌کردن کوکی یا آمدن با مرورگر/دستگاه دیگر آن را صفر می‌کند و
   محافظت عملاً هیچ است. با ذخیره در دیتابیس، سابقهٔ خطا به خودِ حساب
   می‌چسبد و همهٔ IPها و دستگاه‌ها از آن تبعیت می‌کنند.
   ══════════════════════════════════════════════════════════════════ */

if (!function_exists('login_guard_ensure_schema')) {
    function login_guard_ensure_schema() {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            DB::execute("CREATE TABLE IF NOT EXISTS login_guard (
                identity    VARCHAR(190) NOT NULL,
                fail_count  INT NOT NULL DEFAULT 0,
                last_fail   INT NOT NULL DEFAULT 0,
                PRIMARY KEY (identity)
            )");
        } catch (Exception $e) { /* نبودش نباید ورود را بشکند */ }
    }
}

if (!function_exists('login_guard_key')) {
    /** کلید یکتا برای هر حساب. نقش را هم می‌آورد تا نام‌های همسان قاطی نشوند. */
    function login_guard_key($role, $identity) {
        $identity = function_exists('tr_num') ? tr_num(trim((string)$identity), 'en') : trim((string)$identity);
        return strtolower($role . ':' . $identity);
    }
}

if (!function_exists('login_needs_captcha')) {
    /**
     * آیا این حساب باید کد امنیتی ببیند؟
     *
     * بار اول (هیچ خطایی ثبت نشده) → false، یعنی فرم بدون کپچا.
     * بعد از اولین خطا → true و تا وقتی ورود موفق نشود همین‌طور می‌ماند.
     */
    function login_needs_captcha($role, $identity) {
        if (trim((string)$identity) === '') return false;
        login_guard_ensure_schema();
        try {
            $row = DB::fetch("SELECT fail_count FROM login_guard WHERE identity = ?", [login_guard_key($role, $identity)]);
            return $row && (int)$row['fail_count'] > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('login_guard_fail')) {
    function login_guard_fail($role, $identity) {
        if (trim((string)$identity) === '') return;
        login_guard_ensure_schema();
        $key = login_guard_key($role, $identity);
        try {
            $row = DB::fetch("SELECT fail_count FROM login_guard WHERE identity = ?", [$key]);
            if ($row) {
                DB::execute("UPDATE login_guard SET fail_count = fail_count + 1, last_fail = ? WHERE identity = ?", [time(), $key]);
            } else {
                DB::execute("INSERT INTO login_guard (identity, fail_count, last_fail) VALUES (?, 1, ?)", [$key, time()]);
            }
        } catch (Exception $e) { /* ثبت‌نشدن نباید ورود را بشکند */ }
    }
}

if (!function_exists('login_guard_success')) {
    /** ورود موفق → پاک‌سازی، پس دفعهٔ بعد دوباره بدون کد امنیتی است. */
    function login_guard_success($role, $identity) {
        if (trim((string)$identity) === '') return;
        login_guard_ensure_schema();
        try {
            DB::execute("DELETE FROM login_guard WHERE identity = ?", [login_guard_key($role, $identity)]);
        } catch (Exception $e) { /* بی‌اهمیت */ }
    }
}

if (!function_exists('login_captcha_gate')) {
    /**
     * دروازهٔ واحد کپچا برای همهٔ فرم‌های ورود.
     *
     * نکتهٔ ظریف امنیتی: وقتی کپچا لازم است و پاسخ غلط است، خطا را
     * *قبل از* بررسی رمز برمی‌گردانیم، ولی شمارنده را هم بالا می‌بریم —
     * وگرنه مهاجم می‌توانست با فرستادن کپچای غلط، شمارنده را دور بزند.
     *
     * @return bool true یعنی اجازهٔ ادامه
     */
    function login_captcha_gate($role, $identity, $answer) {
        if (!login_needs_captcha($role, $identity)) return true;
        if (verify_captcha($answer)) return true;
        login_guard_fail($role, $identity);
        if (function_exists('record_failed_login')) record_failed_login();
        return false;
    }
}
