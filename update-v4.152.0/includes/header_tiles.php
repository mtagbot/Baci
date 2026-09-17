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

require_once __DIR__ . '/tile_icons.php';

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
            'dashboard'   => ['t' => 'داشبورد',        'i' => '🏠', 'ic' => 'dashboard', 'u' => 'index.php?view=dashboard', 'p' => null],
            'students'    => ['t' => 'دانش‌آموزان',    'i' => '🎓', 'ic' => 'students', 'u' => 'students.php',            'p' => 'manage_students'],
            'attendance'  => ['t' => 'حضور و غیاب',    'i' => '📋', 'ic' => 'attendance', 'u' => 'attendance.php',          'p' => 'manage_students'],
            'courses'     => ['t' => 'دروس',           'i' => '📚', 'ic' => 'courses', 'u' => 'courses-management.php',  'p' => 'manage_classes'],
            'reports'     => ['t' => 'کارنامه‌ها',      'i' => '📊', 'ic' => 'reports', 'u' => 'reports-management.php',  'p' => 'manage_reports'],
            'exams'       => ['t' => 'امتحانات حضوری', 'i' => '📝', 'ic' => 'exams', 'u' => 'exams.php',               'p' => 'manage_reports'],
            'online'      => ['t' => 'آزمون مجازی',    'i' => '💻', 'ic' => 'online', 'u' => 'online-exams.php',        'p' => 'manage_reports'],
            'analytics'   => ['t' => 'تحلیل و آمار',   'i' => '📈', 'ic' => 'analytics', 'u' => 'analytics.php',           'p' => 'manage_reports'],
            'recovery'    => ['t' => 'بازیابی دانش‌آموز','i' => '♻️', 'ic' => 'recovery', 'u' => 'student-recovery.php',   'p' => 'import_data'],
            'messages'    => ['t' => 'پیامک و اعلان',  'i' => '✉️', 'ic' => 'messages', 'u' => 'messages-management.php', 'p' => 'send_sms'],
            'bale'        => ['t' => 'بازوی بله',      'i' => '🤖', 'ic' => 'bale', 'u' => 'bale-bot.php',            'p' => 'send_sms'],
            'telegram'    => ['t' => 'ربات تلگرام',    'i' => '✈️', 'ic' => 'telegram', 'u' => 'telegram-bot.php',        'p' => 'send_sms'],
            'botaccounts' => ['t' => 'اکانت‌های متصل', 'i' => '🔗', 'ic' => 'botaccounts', 'u' => 'bot-accounts.php',        'p' => 'send_sms'],
            'backups'     => ['t' => 'پشتیبان‌گیری',    'i' => '💾', 'ic' => 'backups', 'u' => 'backups.php',             'p' => null],
            'logs'        => ['t' => 'گزارش فعالیت',   'i' => '🕓', 'ic' => 'logs', 'u' => 'activity-logs.php',       'p' => null],
            'othersets'   => ['t' => 'تنظیمات دیگر',   'i' => '⚙️', 'ic' => 'othersets', 'u' => 'other-settings.php',      'p' => null],
            'sync'        => ['t' => 'همگام‌سازی',      'i' => '🔄', 'ic' => 'sync', 'u' => 'desk-sync.php',           'p' => null],
            'dbhealth'    => ['t' => 'سلامت پایگاه',   'i' => '🩺', 'ic' => 'dbhealth', 'u' => 'db-optimizer.php',        'p' => 'system_settings'],
            'settings'    => ['t' => 'سفارشی‌سازی',    'i' => '🎨', 'ic' => 'settings', 'u' => 'settings.php',            'p' => 'system_settings'],
            'admins'      => ['t' => 'مدیران',         'i' => '👥', 'ic' => 'admins', 'u' => 'admins.php',              'p' => '__super__'],
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

        /* v4.144.0 — sprite باید *بیرون* از nav چاپ شود.
           باگ گزارش‌شده: در موبایل .hdr-tiles با display:none پنهان
           است و چون sprite داخل همان nav ساخته می‌شد، کروم اندروید
           به‌جای نادیده‌گرفتنش، آن را با ابعاد ذاتی ۳۰۰×۱۵۰ وسط صفحه
           سیاه رندر می‌کرد و روی کل رابط می‌افتاد.
           با چاپ sprite پیش از nav، دیگر والدِ پنهان ندارد. */
        if (function_exists('tile_icon_sprite')) tile_icon_sprite();

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
            /* v4.133.0: آیکون خطی به‌جای ایموجی.
               اگر کاشی به هر دلیل 'ic' نداشت، به ایموجی برمی‌گردیم تا
               کاشی بدون نشانه نماند. */
            printf(
                '<a class="hdr-tile%s" href="%s" title="%s"%s>',
                $isActive ? ' is-active' : '',
                clean($tile['u']),
                clean($tile['t']),
                $isActive ? ' aria-current="page"' : ''
            );
            echo '<span class="hdr-tile-i" aria-hidden="true">';
            if (!empty($tile['ic']) && function_exists('tile_icon')) {
                tile_icon($tile['ic']);
            } else {
                echo $tile['i'];
            }
            echo '</span><span class="hdr-tile-t">' . clean($tile['t']) . '</span></a>';
        }
        echo '</nav>';
    }
}

/* ══════════════════════════════════════════════════════════════════
   کپچای تطبیقی — «بار اول بدون کد امنیتی، بعد از اشتباه همیشه با کد»

   وضعیت نمایش اکنون به نشست و نقش وابسته است؛ خطاهای گذشتهٔ یک
   مرورگر دیگر، کپچای تلاش اول را فعال نمی‌کنند. شمارش پایدار خطاها
   برای محدودسازی مستقل IP نگه داشته می‌شود، نه نمایش کپچا.
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
        return !empty($_SESSION['login_challenge'][$role]);
    }
}

if (!function_exists('login_guard_fail')) {
    function login_guard_fail($role, $identity) {
        $_SESSION['login_challenge'][$role] = true;
        if (trim((string)$identity) === '') return;
        login_guard_ensure_schema();
        $key = login_guard_key($role, $identity);
        try {
            $row = DB::fetch("SELECT fail_count FROM login_guard WHERE identity = ?", [$key]);
            if ($row) {
                DB::execute("UPDATE login_guard SET fail_count = CASE WHEN last_fail < ? THEN 1 ELSE fail_count + 1 END, last_fail = ? WHERE identity = ?", [time()-600, time(), $key]);
            } else {
                DB::execute("INSERT INTO login_guard (identity, fail_count, last_fail) VALUES (?, 1, ?)", [$key, time()]);
            }
        } catch (Exception $e) { /* ثبت‌نشدن نباید ورود را بشکند */ }
    }
}

if (!function_exists('login_guard_success')) {
    /** ورود موفق → پاک‌سازی، پس دفعهٔ بعد دوباره بدون کد امنیتی است. */
    function login_guard_success($role, $identity) {
        unset($_SESSION['login_challenge'][$role]);
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
        if (isset($_SESSION['captcha_ans']) && trim((string)$answer)!=='' && verify_captcha($answer)) return true;
        login_guard_fail($role, $identity);
        if (function_exists('record_failed_login')) record_failed_login();
        return false;
    }
}
