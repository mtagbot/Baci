<?php
// File: includes/bot_helpers.php
/**
 * Shared Bale/Telegram/Rubika bot helpers: schema migration, API wrappers,
 * templates, targeting, and report-image delivery.
 */

require_once __DIR__ . '/functions.php';
/* V4.209.2 — این دو فایل روی نصب‌های قدیمی می‌توانند نباشند (از بسته‌های
   اصلاحی جدا آمده‌اند). require بی‌قید یعنی صفحهٔ سفید؛ پس فقط اگر هستند
   بارگذاری می‌شوند و کدِ مربوط به ربات خودش با function_exists محافظت دارد. */
if (is_file(__DIR__ . '/report_image.php')) require_once __DIR__ . '/report_image.php';
if (is_file(__DIR__ . '/bot_outbox.php')) require_once __DIR__ . '/bot_outbox.php';

if (!function_exists('bot_platforms')) {
    /**
     * v4.186.0: فهرست پیام‌رسان‌های پشتیبانی‌شده.
     * افزودن پیام‌رسان تازه فقط از همین‌جا + یک فایل وب‌هوک/صفحه انجام می‌شود؛
     * هیچ‌کدام از توابع پایین «اگر تلگرام نبود یعنی بله» فرض نمی‌کنند.
     */
    function bot_platforms() {
        return ['bale', 'telegram', 'rubika'];
    }
}

if (!function_exists('bot_platform_labels')) {
    function bot_platform_labels() {
        return ['bale' => 'بله', 'telegram' => 'تلگرام', 'rubika' => 'روبیکا'];
    }
}

if (!function_exists('bot_platform_label')) {
    function bot_platform_label($platform) {
        $labels = bot_platform_labels();
        return $labels[bot_valid_platform($platform)] ?? 'بله';
    }
}

if (!function_exists('bot_valid_platform')) {
    function bot_valid_platform($platform) {
        return in_array($platform, bot_platforms(), true) ? $platform : 'bale';
    }
}

if (!function_exists('bot_user_table')) {
    function bot_user_table($platform) {
        return bot_valid_platform($platform) . '_bot_users';
    }
}

if (!function_exists('bot_state_table')) {
    function bot_state_table($platform) {
        return bot_valid_platform($platform) . '_bot_state';
    }
}

if (!function_exists('bot_chat_column')) {
    /** نام ستون چت در جدول‌های هر پیام‌رسان (bale_chat_id / telegram_chat_id / rubika_chat_id). */
    function bot_chat_column($platform) {
        return bot_valid_platform($platform) . '_chat_id';
    }
}

if (!function_exists('bot_username_column')) {
    function bot_username_column($platform) {
        return bot_valid_platform($platform) . '_username';
    }
}

if (!function_exists('bot_migrate_platform_enum')) {
    /**
     * v4.186.0: نصب‌های قدیمی، ستون platform را با enum('bale','telegram')
     * ساخته‌اند و MySQL مقدار تازهٔ «rubika» را در آن رد می‌کند.
     * این تابع فقط روی MySQL و فقط وقتی ستون هنوز روبیکا را ندارد ALTER می‌زند.
     * SQLite اساساً enum ندارد، پس بی‌خطر رد می‌شود.
     *
     * @param string $definition تعریف کامل ستون بعد از MODIFY، مثل
     *               "enum('bale','telegram','soroush','rubika') NOT NULL"
     */
    function bot_migrate_platform_enum($table, $column, $definition) {
        try {
            $pdo = DB::getInstance()->getPdo();
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
            if (!preg_match('/^[a-z_]+$/', (string)$table) || !preg_match('/^[a-z_]+$/', (string)$column)) return;
            $col = DB::fetch("SHOW COLUMNS FROM `$table` LIKE ?", [(string)$column]);
            $type = strtolower((string)($col['Type'] ?? ''));
            if ($type === '' || strpos($type, "'rubika'") !== false) return;
            DB::execute("ALTER TABLE `$table` MODIFY `$column` " . $definition);
        } catch (Throwable $e) { error_log('bot platform enum migration: ' . $e->getMessage()); }
    }
}

if (!function_exists('bot_token_key')) {
    function bot_token_key($platform) {
        return bot_valid_platform($platform) . '_bot_token';
    }
}

if (!function_exists('ensure_bot_schema')) {
    function ensure_bot_schema($platform = 'bale') {
        $platform = bot_valid_platform($platform);
        /* V4.209.0 — کل این تابع (۵ CREATE + ۱ ALTER + ۳ بررسی enum + seed)
           پیش از این در هر بازدیدِ هر صفحهٔ ربات اجرا می‌شد. حالا یک‌بار در هر
           نسخه برای هر پیام‌رسان؛ نتیجه همان است و فقط کوئری‌های تکراری حذف
           می‌شوند. */
        if (function_exists('perf_schema_once')) {
            static $ran = [];
            $key = 'bot_schema_' . $platform . '_v1';
            if (isset($ran[$key])) return;
            $ran[$key] = true;
            perf_schema_once($key, function () use ($platform) { ensure_bot_schema_work($platform); });
            return;
        }
        ensure_bot_schema_work($platform);
    }
}

if (!function_exists('ensure_bot_schema_work')) {
    function ensure_bot_schema_work($platform) {
        $platform = bot_valid_platform($platform);
        $userTable = bot_user_table($platform);
        $stateTable = bot_state_table($platform);
        $chatCol = bot_chat_column($platform);
        $usernameCol = bot_username_column($platform);

        DB::execute("CREATE TABLE IF NOT EXISTS `$userTable` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `$chatCol` varchar(80) NOT NULL,
            `$usernameCol` varchar(150) DEFAULT NULL,
            `student_id` int(11) NOT NULL,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_chat_student` (`$chatCol`, `student_id`),
            KEY `idx_chat` (`$chatCol`),
            KEY `idx_student` (`student_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        DB::execute("CREATE TABLE IF NOT EXISTS `$stateTable` (
            `$chatCol` varchar(80) NOT NULL,
            `step` varchar(60) NOT NULL,
            `temp_nid` varchar(50) DEFAULT NULL,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`$chatCol`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        DB::execute("CREATE TABLE IF NOT EXISTS `bot_message_templates` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `platform` enum('bale','telegram','soroush','rubika') NOT NULL,
            `template_key` varchar(80) NOT NULL,
            `template_text` text NOT NULL,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_platform_key` (`platform`, `template_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        DB::execute("CREATE TABLE IF NOT EXISTS `bot_button_templates` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `platform` enum('bale','telegram','soroush','rubika') NOT NULL,
            `button_key` varchar(80) NOT NULL,
            `button_text` varchar(150) NOT NULL,
            `sort_order` int(11) NOT NULL DEFAULT 0,
            `row_no` int(11) NOT NULL DEFAULT 1,
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_platform_button` (`platform`, `button_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        DB::execute("CREATE TABLE IF NOT EXISTS `bot_message_logs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `platform` enum('bale','telegram','soroush','rubika') NOT NULL,
            `chat_id` varchar(80) NOT NULL,
            `student_id` int(11) DEFAULT NULL,
            `message_type` varchar(50) DEFAULT 'text',
            `message` text DEFAULT NULL,
            `status` varchar(50) DEFAULT 'pending',
            `response` text DEFAULT NULL,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_platform_chat` (`platform`, `chat_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        try { DB::execute("ALTER TABLE `$stateTable` ADD COLUMN temp_payload text DEFAULT NULL"); } catch (Exception $e) {}

        /* v4.186.0: enumهای قدیمی ('bale','telegram') روبیکا را نمی‌پذیرند. */
        static $enumMigrated = false;
        if (!$enumMigrated) {
            $enumMigrated = true;
            foreach (['bot_message_templates', 'bot_button_templates', 'bot_message_logs'] as $tbl) {
                bot_migrate_platform_enum($tbl, 'platform', "enum('bale','telegram','soroush','rubika') NOT NULL");
            }
        }

        bot_seed_templates($platform);
    }
}

if (!function_exists('bot_default_messages')) {
    function bot_default_messages() {
        return [
            'welcome' => "🌺 سلام! به ربات رسمی آموزشگاه خوش آمدید.\n\nبرای احراز هویت، لطفاً کد ملی ۱۰ رقمی دانش‌آموز را ارسال کنید.",
            'invalid_nid' => '⚠️ کد ملی ۱۰ رقمی (یا شناسهٔ ۶ رقمی پرونده) را فقط با عدد ارسال کنید. لطفاً دوباره ارسال کنید:',
            'student_not_found' => '❌ دانش‌آموزی با این کد ملی در سیستم یافت نشد. لطفاً کد ملی صحیح را ارسال کنید:',
            'ask_serial' => '✔️ دانش‌آموز شناسایی شد. اکنون سریال ۶ رقمی شناسنامه/رمز ورود را ارسال کنید:',
            'invalid_serial' => '❌ سریال یا رمز وارد شده اشتباه است. لطفاً دوباره تلاش کنید:',
            'link_success' => "🎉 پرونده دانش‌آموز «{student_name}» با موفقیت به حساب شما متصل شد.\nاکنون از منوی زیر می‌توانید کارنامه‌ها را دریافت کنید.",
            'no_link' => 'شما هنوز هیچ دانش‌آموزی را متصل نکرده‌اید. لطفاً کد ملی دانش‌آموز را ارسال کنید.',
            'choose_report' => '📊 کارنامه‌های صادرشده برای {student_name} را انتخاب کنید:',
            'no_report' => 'برای دانش‌آموز «{student_name}» کارنامه فعالی صادر نشده است.',
            'children_title' => "👥 دانش‌آموزان متصل به حساب شما:\n\n",
            'contact' => "🏫 {school_name}\n📞 تلفن تماس: {school_phone}",
            'fallback' => 'لطفاً از منوی زیر گزینه مورد نظر را انتخاب کنید:',
            'exam_schedule_empty' => 'برای {student_name} برنامه امتحانی ثبت نشده است.',
            'exam_schedule_title' => "📝 برنامه امتحانی {student_name}:\n",
            'report_caption' => "🎓 کارنامه تصویری و تحلیل نموداری {student_name}\n⭐ معدل: {gpa}\n✨ انضباط: {discipline}\n🏅 رتبه کلاس: {rank}",
            /* V4.209.3 — متن «چرا کارنامه مسدود است»؛ مدیر می‌تواند از صفحهٔ
               الگوهای ربات همین متن را ویرایش کند. */
            'report_locked' => "🔒 کارنامهٔ «{student_name}»\n📅 {term} — {month}\n\n📝 علت مسدودسازی: {reason}\n\nبرای پیگیری با آموزشگاه در تماس باشید.",
            'report_locked_list' => "🔒 کارنامه‌های مسدودشده برای {student_name}:\n\n{locks}\n\nبرای پیگیری با آموزشگاه در تماس باشید.",
            'report_not_found' => '❌ این کارنامه یافت نشد یا حذف شده است.',
            'broadcast_prefix' => "📢 اطلاعیه آموزشگاه:\n\n{message}",
        ];
    }
}

if (!function_exists('bot_default_buttons')) {
    function bot_default_buttons() {
        return [
            ['main_reports', '📊 دریافت کارنامه تحصیلی', 1, 1],
            ['main_children', '👤 وضعیت فرزندان من', 2, 2],
            ['main_add_child', '➕ افزودن فرزند جدید', 3, 2],
            ['main_contact', '📞 ارتباط با آموزشگاه', 4, 3],
            ['main_teacher_login', '👨‍🏫 ورود دبیران', 5, 4],
            ['main_counselor', '🧭 ارتباط با مشاور', 6, 4],
            ['main_discipline', '⚠️ موارد انضباطی', 7, 5],
            ['main_exam_schedule', '📝 برنامه امتحانی', 8, 5],
            ['main_remove_child', '🗑️ حذف دانش‌آموز از حساب', 9, 6],
        ];
    }
}

if (!function_exists('bot_seed_templates')) {
    function bot_seed_templates($platform) {
        $platform = bot_valid_platform($platform);
        /* V4.209.0 — پیش از این برای هر پیام و هر دکمه یک SELECT جدا اجرا می‌شد
           (حدود ۲۵ کوئری برای هر پیام‌رسان در هر بازدید صفحهٔ ربات‌ها). حالا
           کلیدهای موجود با یک کوئری خوانده می‌شوند و فقط کلیدهای تازه درج
           می‌شوند؛ نتیجهٔ نهایی دقیقاً همان قبلی است. */
        $haveMsg = []; $haveBtn = [];
        try { foreach (DB::fetchAll("SELECT template_key FROM bot_message_templates WHERE platform = ?", [$platform]) as $r) $haveMsg[(string)$r['template_key']] = true; } catch (Throwable $e) {}
        try { foreach (DB::fetchAll("SELECT button_key FROM bot_button_templates WHERE platform = ?", [$platform]) as $r) $haveBtn[(string)$r['button_key']] = true; } catch (Throwable $e) {}
        foreach (bot_default_messages() as $key => $text) {
            if (isset($haveMsg[(string)$key])) continue;
            try { DB::execute("INSERT INTO bot_message_templates (platform, template_key, template_text) VALUES (?, ?, ?)", [$platform, $key, $text]); } catch (Throwable $e) {}
        }
        foreach (bot_default_buttons() as $b) {
            if (isset($haveBtn[(string)$b[0]])) continue;
            try { DB::execute("INSERT INTO bot_button_templates (platform, button_key, button_text, sort_order, row_no, is_active) VALUES (?, ?, ?, ?, ?, 1)", [$platform, $b[0], $b[1], $b[2], $b[3]]); } catch (Throwable $e) {}
        }
    }
}

if (!function_exists('bot_format_student_exam_schedule')) {
    function bot_format_student_exam_schedule($studentName, array $items, $title = '') {
        $title = trim((string)$title);
        $msg = ($title !== '' ? $title : ("📝 برنامهٔ امتحانی " . trim((string)$studentName))) . "\n";
        $msg .= "────────────────────\n";
        foreach ($items as $index => $ex) {
            $room = trim((string)($ex['exam_room'] ?? ''));
            if ($room === '') $room = trim((string)($ex['exam_room_default'] ?? ''));
            $className = trim((string)($ex['class_name'] ?? ''));
            $subject = trim((string)($ex['subject_name'] ?? '')) ?: 'درس نامشخص';
            $msg .= "\n📚 " . tr_num((string)($index + 1), 'fa') . '. ' . $subject . "\n";
            $msg .= "   📅 تاریخ: " . tr_num((string)($ex['exam_date_jalali'] ?? ''), 'fa') . "\n";
            $msg .= "   🕗 ساعت: " . tr_num((string)($ex['start_time'] ?? ''), 'fa') . "\n";
            if ($className !== '') $msg .= "   🏫 کلاس: {$className}\n";
            $msg .= "   💺 صندلی: " . tr_num((string)($ex['seat_number'] ?? '') ?: '---', 'fa') . "\n";
            $msg .= "   📍 حوزه/سالن: " . ($room !== '' ? $room : '---') . "\n";
            $msg .= "────────────────────\n";
        }
        return $msg;
    }
}

if (!function_exists('bot_text')) {
    function bot_text($platform, $key, $vars = []) {
        $platform = bot_valid_platform($platform);
        $defaults = bot_default_messages();
        $row = DB::fetch("SELECT template_text FROM bot_message_templates WHERE platform = ? AND template_key = ?", [$platform, $key]);
        $text = $row ? $row['template_text'] : ($defaults[$key] ?? '');
        $vars = array_merge([
            'school_name' => get_setting('school_name', 'آموزشگاه'),
            'school_phone' => get_setting('school_phone', '---'),
            'date' => jdate('Y/m/d'),
        ], $vars);
        foreach ($vars as $k => $v) {
            $text = str_replace('{' . $k . '}', (string)$v, $text);
        }
        return $text;
    }
}

if (!function_exists('bot_report_student_name')) {
    /** V4.209.3 — نام دانش‌آموز از ردیف کارنامه (اگر join شده باشد) یا از فیلد مستقیم. */
    function bot_report_student_name(array $report): string {
        $name = trim(((string)($report['first_name'] ?? '')) . ' ' . ((string)($report['last_name'] ?? '')));
        if ($name === '') $name = trim((string)($report['student_name'] ?? ''));
        if ($name === '') $name = trim((string)($report['student_full_name'] ?? ''));
        return $name;
    }
}

if (!function_exists('bot_report_lock_message')) {
    /**
     * V4.209.3 — پیامِ «کارنامه مسدود است» همراه با علتی که مدیر ثبت کرده.
     *
     * پیش از این، درخواست کارنامهٔ مسدود در ربات کاملاً بی‌صدا رد می‌شد و
     * والدین فقط «هیچ اتفاقی نیفتاد» را می‌دیدند. اکنون همان قفل سر جایش
     * است (هیچ نمره‌ای نمایش داده نمی‌شود) اما دلیلش هم گفته می‌شود.
     */
    function bot_report_lock_message($platform, array $report, $reason = ''): string {
        $reason = trim((string)$reason);
        if ($reason === '' && function_exists('report_lock_reason_for')) {
            $reason = report_lock_reason_for($report);
        }
        if ($reason === '') {
            $reason = function_exists('report_lock_generic_reason')
                ? report_lock_generic_reason()
                : 'به دلیل امور اداری/مالی یا تکمیل نمرات مسدود است.';
        }
        $term = trim((string)($report['term'] ?? ''));
        $month = trim((string)($report['report_month'] ?? ''));
        return bot_text($platform, 'report_locked', [
            'student_name' => bot_report_student_name($report),
            'term' => $term !== '' ? $term : '---',
            'month' => $month !== '' ? $month : '---',
            'reason' => $reason,
        ]);
    }
}

if (!function_exists('bot_report_lock_list_message')) {
    /**
     * V4.209.3 — فهرستِ ماه‌های مسدودشده همراه علت هرکدام.
     *
     * ورودی: آرایه‌ای از ['term','report_month','reason'].
     * «تکراری‌ها یکی می‌شوند» تا برای هر ماه/علت یک خط بیاید.
     */
    function bot_report_lock_list_message($platform, $studentName, array $items): string {
        $lines = [];
        $seen = [];
        foreach ($items as $it) {
            $term = trim((string)($it['term'] ?? ''));
            $month = trim((string)($it['report_month'] ?? ''));
            $reason = trim((string)($it['reason'] ?? ''));
            if ($reason === '') {
                $reason = function_exists('report_lock_generic_reason')
                    ? report_lock_generic_reason()
                    : 'به دلیل امور اداری/مالی یا تکمیل نمرات مسدود است.';
            }
            $key = $term . '|' . $month . '|' . $reason;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $label = trim($term . ($term !== '' && $month !== '' ? ' — ' : '') . $month);
            if ($label === '') $label = 'کارنامه';
            $lines[] = '• ' . $label . ":\n   " . $reason;
        }
        $count = count($lines);
        if ($count > 8) {
            $lines = array_slice($lines, 0, 8);
            $lines[] = '… و ' . tr_num((string)($count - 8), 'fa') . ' مورد دیگر.';
        }
        return bot_text($platform, 'report_locked_list', [
            'student_name' => trim((string)$studentName),
            'locks' => implode("\n", $lines),
        ]);
    }
}

if (!function_exists('bot_buttons_map')) {
    function bot_buttons_map($platform) {
        ensure_bot_schema($platform);
        $rows = DB::fetchAll("SELECT * FROM bot_button_templates WHERE platform = ? AND is_active = 1 ORDER BY row_no ASC, sort_order ASC, id ASC", [bot_valid_platform($platform)]);
        $map = [];
        foreach ($rows as $r) $map[$r['button_key']] = $r['button_text'];
        return $map;
    }
}

if (!function_exists('bot_site_base_url')) {
    /**
     * v4.174.0: آدرس پایهٔ سایت — همان منطقی که school_roles.php در
     * bot_create_login_url() دارد. برای فرستادن لینک صفحهٔ ورود پنل از ربات.
     */
    function bot_site_base_url() {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '')), '/');
        if ($base === '.') $base = '';
        return $protocol . $host . $base;
    }
}

if (!function_exists('bot_panel_login_url')) {
    /**
     * v4.174.0: لینک مستقیم صفحهٔ ورود پنل برای هر نقش. دبیری که در گفتگوی ربات
     * این لینک را بگیرد، مستقیم روی «تب دبیران» می‌افتد و با «کد ملی + کد پرسنلی»
     * وارد می‌شود — بدون گشتن در صفحه.
     */
    function bot_panel_login_url($role = 'student') {
        $role = in_array($role, ['student', 'teacher', 'inquiry'], true) ? $role : 'student';
        return bot_site_base_url() . '/index.php?view=login&tab=' . $role;
    }
}

if (!function_exists('bot_main_keyboard')) {
    function bot_main_keyboard($platform) {
        ensure_bot_schema($platform);
        $rows = DB::fetchAll("SELECT * FROM bot_button_templates WHERE platform = ? AND is_active = 1 ORDER BY row_no ASC, sort_order ASC, id ASC", [bot_valid_platform($platform)]);
        $keyboardRows = [];
        foreach ($rows as $r) {
            $keyboardRows[(int)$r['row_no']][] = ['text' => $r['button_text']];
        }
        ksort($keyboardRows);
        return ['keyboard' => array_values($keyboardRows), 'resize_keyboard' => true];
    }
}

if (!function_exists('bot_api_endpoint')) {
    function bot_api_endpoint($platform, $method) {
        $platform = bot_valid_platform($platform);
        $token = get_setting(bot_token_key($platform), '');
        if ($token === '') throw new RuntimeException('توکن ربات تنظیم نشده است.');
        if ($platform === 'telegram') {
            /* v4.84.0: hosts inside Iran often firewall all foreign traffic, so
               api.telegram.org times out. If the admin configures a relay/mirror
               base URL (e.g. a Cloudflare Worker or any reverse proxy that
               forwards to api.telegram.org), every Telegram API call goes
               through it. Empty = direct connection as before. */
            $base = trim((string)get_setting('telegram_api_base', ''));
            $base = rtrim($base, '/');
            if ($base !== '' && !preg_match('#^https?://#i', $base)) $base = 'https://' . $base;
            if ($base === '') $base = 'https://api.telegram.org';
            return $base . "/bot{$token}/{$method}";
        }
        if ($platform === 'rubika') {
            /* Rubika Bot API v3: https://botapi.rubika.ir/v3/{token}/{method}. */
            return "https://botapi.rubika.ir/v3/{$token}/{$method}";
        }
        return "https://tapi.bale.ai/bot{$token}/{$method}";
    }
}

if (!function_exists('bot_rubika_normalize_payload')) {
    /**
     * Rubika uses Bot API v3 field/keypad names, not Telegram reply_markup.
     * Convert the shared internal keyboard contract at the transport boundary.
     */
    function bot_rubika_normalize_payload($method, array $data) {
        if (!in_array($method, ['sendMessage', 'sendFile'], true) || !isset($data['reply_markup'])) return $data;
        $markup = $data['reply_markup'];
        unset($data['reply_markup']);
        if (is_string($markup)) $markup = json_decode($markup, true);
        if (!is_array($markup)) return $data;
        if (isset($markup['keyboard']) && is_array($markup['keyboard'])) {
            $rows = [];
            foreach ($markup['keyboard'] as $ri => $row) {
                $buttons = [];
                foreach ((array)$row as $bi => $button) {
                    $text = (string)($button['text'] ?? '');
                    if ($text === '') continue;
                    $buttons[] = ['id' => 'k' . $ri . '_' . $bi, 'type' => 'Simple', 'button_text' => $text];
                }
                if ($buttons) $rows[] = ['buttons' => $buttons];
            }
            if ($rows) {
                $data['chat_keypad_type'] = 'New';
                $data['chat_keypad'] = ['rows' => $rows, 'resize_keyboard' => !empty($markup['resize_keyboard']), 'one_time_keyboard' => !empty($markup['one_time_keyboard'])];
            }
        } elseif (isset($markup['inline_keyboard']) && is_array($markup['inline_keyboard'])) {
            $rows = [];
            foreach ($markup['inline_keyboard'] as $ri => $row) {
                $buttons = [];
                foreach ((array)$row as $bi => $button) {
                    $text = (string)($button['text'] ?? '');
                    if ($text === '') continue;
                    $id = (string)($button['callback_data'] ?? ('i' . $ri . '_' . $bi));
                    $entry = ['id' => $id, 'type' => isset($button['url']) ? 'Link' : 'Simple', 'button_text' => $text];
                    if (isset($button['url'])) $entry['url'] = (string)$button['url'];
                    $buttons[] = $entry;
                }
                if ($buttons) $rows[] = ['buttons' => $buttons];
            }
            if ($rows) $data['inline_keypad'] = ['rows' => $rows];
        }
        return $data;
    }
}

if (!function_exists('bot_rubika_normalize_update')) {
    /** Normalize documented Rubika Update/InlineMessage envelopes for the shared bot engine. */
    function bot_rubika_normalize_update(array $payload) {
        if (is_array($payload['update'] ?? null)) {
            $ru = $payload['update'];
            $nm = is_array($ru['new_message'] ?? null) ? $ru['new_message'] : (is_array($ru['updated_message'] ?? null) ? $ru['updated_message'] : []);
            $type = strtolower((string)($ru['type'] ?? ''));
            if (in_array($type, ['newmessage','updatedmessage','startedbot'], true)) {
                $txt = (string)($nm['text'] ?? '');
                if ($txt === '' && $type === 'startedbot') $txt = '/start';
                $ct = strtolower((string)($ru['chat_type'] ?? 'user'));
                $ct = ['user'=>'private','bot'=>'private','group'=>'group','channel'=>'channel'][$ct] ?? 'unknown';
                $payload['message'] = [
                    'message_id' => (string)($nm['message_id'] ?? ''),
                    'chat' => ['id' => (string)($ru['chat_id'] ?? $nm['chat_id'] ?? ''), 'type' => $ct],
                    'from' => ['id' => (string)($nm['sender_id'] ?? $ru['sender_id'] ?? ''), 'username' => (string)($nm['username'] ?? ''), 'first_name' => (string)($nm['first_name'] ?? '')],
                    'text' => $txt,
                ];
            }
        }
        if (is_array($payload['inline_message'] ?? null)) {
            $im = $payload['inline_message'];
            $buttonId = (string)($im['aux_data']['button_id'] ?? '');
            $payload['callback_query'] = [
                'id' => (string)($im['message_id'] ?? 'rubika-inline'),
                'from' => ['id' => (string)($im['sender_id'] ?? '')],
                'data' => $buttonId !== '' ? $buttonId : (string)($im['text'] ?? ''),
                'message' => ['message_id' => (string)($im['message_id'] ?? ''), 'chat' => ['id' => (string)($im['chat_id'] ?? ''), 'type' => 'private']],
            ];
        }
        return $payload;
    }
}

if (!function_exists('bot_rubika_upload_and_send')) {
    /** Rubika file delivery is a two-step requestSendFile → multipart upload → sendFile flow. */
    function bot_rubika_upload_and_send(array $data) {
        $fileField = '';
        foreach (['photo','document','video','audio','voice','animation'] as $field) {
            if (isset($data[$field]) && $data[$field] instanceof CURLFile) { $fileField = $field; break; }
        }
        if ($fileField === '') throw new RuntimeException('فایل ارسالی روبیکا شناسایی نشد.');
        $file = $data[$fileField];
        $type = $fileField === 'photo' ? 'Image' : ($fileField === 'video' ? 'Video' : (in_array($fileField, ['audio','voice'], true) ? 'Voice' : 'File'));
        $requested = bot_api_request('rubika', 'requestSendFile', ['type' => $type]);
        $requestData = $requested['result'] ?? $requested['data'] ?? [];
        $uploadUrl = (string)($requestData['upload_url'] ?? '');
        if ($uploadUrl === '') throw new RuntimeException('روبیکا نشانی بارگذاری فایل را برنگرداند.');
        $ch = curl_init();
        curl_setopt_array($ch, [CURLOPT_URL=>$uploadUrl,CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_POSTFIELDS=>['file'=>$file],CURLOPT_TIMEOUT=>180,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true]);
        $body = curl_exec($ch); $err = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($body === false || $code >= 400) throw new RuntimeException('بارگذاری فایل در روبیکا ناموفق بود: ' . ($err ?: ('HTTP ' . $code . ' ' . (string)$body)));
        $uploaded = json_decode($body, true);
        if (!is_array($uploaded) || (isset($uploaded['status']) && strtoupper((string)$uploaded['status']) !== 'OK')) throw new RuntimeException('پاسخ بارگذاری فایل روبیکا قابل قبول نیست.');
        $uploadedData = $uploaded['data'] ?? $uploaded;
        $fileId = (string)($uploadedData['file_id'] ?? $uploadedData['fileId'] ?? '');
        if ($fileId === '') throw new RuntimeException('روبیکا شناسهٔ فایل بارگذاری‌شده را برنگرداند.');
        $send = ['chat_id'=>(string)($data['chat_id']??''),'file_id'=>$fileId,'text'=>(string)($data['caption']??'')];
        if (isset($data['reply_markup'])) $send['reply_markup']=$data['reply_markup'];
        return bot_api_request('rubika', 'sendFile', bot_rubika_normalize_payload('sendFile', $send));
    }
}

if (!function_exists('bot_api_request')) {
    function bot_api_request($platform, $method, $data = [], $multipart = false) {
        if ($platform === 'rubika' && $multipart) return bot_rubika_upload_and_send($data);
        unset($GLOBALS['bot_outbox_last']);
        $url = bot_api_endpoint($platform, $method);
        if ($platform === 'rubika' && !$multipart && is_array($data)) {
            $data = bot_rubika_normalize_payload($method, $data);
        }
        /* v2.11.0 (desktop only): offline circuit-breaker.
           The desktop runs the whole app on ONE PHP worker — a bot call with
           8s connect + 20s total timeout while the internet is down froze
           every page (each QR scan waited the full timeout). Desktop rules:
           shorter timeouts, and after a connection-level failure all bot
           calls fail instantly for 45 seconds instead of hanging the app. */
        $isDesk   = (PHP_SAPI === 'cli-server');
        $cbFile   = $isDesk ? rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'sdp-bot-offline.flag' : '';
        if ($isDesk && is_file($cbFile) && time() - (int)@filemtime($cbFile) < 45) {
            throw new RuntimeException('خطای ارتباط با API ربات: اینترنت در دسترس نیست (تلاش مجدد خودکار تا ۴۵ ثانیه دیگر)');
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            /* v4.88.0: multipart = file uploads (exam PDFs can be a few MB);
               they need far more than 20s on slow hosts/relays. */
            CURLOPT_TIMEOUT => $multipart ? 180 : ($isDesk ? 12 : 20),
            CURLOPT_CONNECTTIMEOUT => $isDesk ? 4 : 8,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($multipart) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=utf-8']);
        }
        $res = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res === false) {
            if ($isDesk && $cbFile !== '') @touch($cbFile);   // open the breaker: next calls fail fast
            throw new RuntimeException('خطای ارتباط با API ربات: ' . $err);
        }
        if ($isDesk && $cbFile !== '' && is_file($cbFile)) @unlink($cbFile); // back online
        $json = json_decode($res, true);
        $rubikaFailed = $platform === 'rubika' && (!isset($json['status']) || strtoupper((string)$json['status']) !== 'OK');
        if ($code >= 400 || !is_array($json) || (array_key_exists('ok', $json) && !$json['ok']) || $rubikaFailed) {
            throw new RuntimeException('پاسخ ناموفق API: HTTP ' . $code . ' - ' . $res);
        }
        if ($platform === 'rubika' && strtoupper((string)($json['status'] ?? '')) === 'OK') {
            $json['ok'] = true;
            $rubikaData = $json['data'] ?? [];
            if (is_array($rubikaData) && isset($rubikaData['chat']) && is_array($rubikaData['chat'])) $rubikaData = $rubikaData['chat'];
            $json['result'] = $rubikaData;
        }
        return $json;
    }
}

if (!function_exists('bot_send_message')) {
    function bot_send_message($platform, $chatId, $text, $keyboard = null) {
        $payload = ['chat_id' => (string)$chatId, 'text' => (string)$text];
        if ($keyboard) $payload['reply_markup'] = $keyboard;
        return bot_outbox_enqueue(bot_valid_platform($platform), $payload);
    }
}

if (!function_exists('bot_send_photo')) {
    function bot_send_photo($platform, $chatId, $filePath, $caption = '', $keyboard = null, $mime = 'image/png', $origName = '') {
        if (!is_file($filePath)) throw new RuntimeException('فایل تصویر برای ارسال یافت نشد.');
        $payload = ['chat_id' => (string)$chatId, 'caption' => (string)$caption];
        if ($keyboard) $payload['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        $payload['photo'] = new CURLFile($filePath, $mime ?: 'image/png', $origName !== '' ? $origName : basename($filePath));
        return bot_api_request($platform, 'sendPhoto', $payload, true);
    }
}

if (!function_exists('bot_send_document')) {
    // v4.47.0: generic file/document delivery (PDF, Word, Excel, ZIP, voice, video, ...).
    function bot_send_document($platform, $chatId, $filePath, $caption = '', $keyboard = null, $mime = '', $origName = '') {
        if (!is_file($filePath)) throw new RuntimeException('فایل برای ارسال یافت نشد.');
        $payload = ['chat_id' => (string)$chatId, 'caption' => (string)$caption];
        if ($keyboard) $payload['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        $payload['document'] = new CURLFile($filePath, $mime !== '' ? $mime : 'application/octet-stream', $origName !== '' ? $origName : basename($filePath));
        return bot_api_request($platform, 'sendDocument', $payload, true);
    }
}

if (!function_exists('bot_send_video')) {
    // v4.50.0: playable video message (falls back to document by the caller on failure).
    function bot_send_video($platform, $chatId, $filePath, $caption = '', $keyboard = null, $mime = 'video/mp4', $origName = '') {
        if (!is_file($filePath)) throw new RuntimeException('فایل ویدیو برای ارسال یافت نشد.');
        $payload = ['chat_id' => (string)$chatId, 'caption' => (string)$caption];
        if ($keyboard) $payload['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        $payload['video'] = new CURLFile($filePath, $mime ?: 'video/mp4', $origName !== '' ? $origName : basename($filePath));
        return bot_api_request($platform, 'sendVideo', $payload, true);
    }
}

if (!function_exists('bot_send_audio')) {
    // v4.50.0: playable audio/music message.
    function bot_send_audio($platform, $chatId, $filePath, $caption = '', $keyboard = null, $mime = 'audio/mpeg', $origName = '') {
        if (!is_file($filePath)) throw new RuntimeException('فایل صوتی برای ارسال یافت نشد.');
        $payload = ['chat_id' => (string)$chatId, 'caption' => (string)$caption];
        if ($keyboard) $payload['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        $payload['audio'] = new CURLFile($filePath, $mime ?: 'audio/mpeg', $origName !== '' ? $origName : basename($filePath));
        return bot_api_request($platform, 'sendAudio', $payload, true);
    }
}

if (!function_exists('bot_send_voice')) {
    // v4.50.0: voice-note style message (OGG).
    function bot_send_voice($platform, $chatId, $filePath, $caption = '', $keyboard = null, $mime = 'audio/ogg', $origName = '') {
        if (!is_file($filePath)) throw new RuntimeException('فایل صوتی برای ارسال یافت نشد.');
        $payload = ['chat_id' => (string)$chatId, 'caption' => (string)$caption];
        if ($keyboard) $payload['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        $payload['voice'] = new CURLFile($filePath, $mime ?: 'audio/ogg', $origName !== '' ? $origName : basename($filePath));
        return bot_api_request($platform, 'sendVoice', $payload, true);
    }
}

if (!function_exists('bot_log_send')) {
    function bot_log_send($platform, $chatId, $studentId, $type, $message, $status, $response = '') {
        $last=$GLOBALS['bot_outbox_last']??null;
        if($last && $last['platform']===bot_valid_platform($platform) && $last['chat_id']===(string)$chatId) {
            $status=$last['state']==='sent'?'sent':'queued';$response='outbox:'.$last['id'];
            unset($GLOBALS['bot_outbox_last']);
        }
        try {
            DB::execute("INSERT INTO bot_message_logs (platform, chat_id, student_id, message_type, message, status, response) VALUES (?, ?, ?, ?, ?, ?, ?)", [bot_valid_platform($platform), (string)$chatId, $studentId ?: null, $type, $message, $status, mb_substr((string)$response, 0, 4000, 'UTF-8')]);
        } catch (Exception $e) {}
    }
}

if (!function_exists('bot_target_recipients')) {
    function bot_target_recipients($platform, $scope, $value = '') {
        ensure_bot_schema($platform);
        $platform = bot_valid_platform($platform);
        $table = bot_user_table($platform);
        $chatCol = bot_chat_column($platform);
        $base = "SELECT DISTINCT b.`$chatCol` AS chat_id, s.id AS student_id, s.first_name, s.last_name, s.national_id, s.grade_level, s.class_name FROM `$table` b JOIN students s ON s.id = b.student_id WHERE s.status = 'active'";
        // v4.77.0: true Persian collation (آ before ا) applied after fetch
        /* v4.89.0: دو مخاطب جدید — «همه دبیران» و «یک دبیر خاص».
           گیرنده = چت‌های فعال کارکنان (bot_admin_sessions) روی همین پیام‌رسان. */
        if ($scope === 'teachers' || $scope === 'teacher') {
            if (function_exists('ensure_school_roles_schema')) ensure_school_roles_schema();
            $tSql = "SELECT DISTINCT bs.chat_id, t.id AS teacher_id, t.full_name FROM bot_admin_sessions bs JOIN teachers t ON t.id = bs.teacher_id WHERE bs.platform=? AND bs.role_type='teacher' AND bs.is_active=1 AND t.status=1";
            $tParams = [$platform];
            if ($scope === 'teacher') { $tSql .= " AND t.id=?"; $tParams[] = (int)$value; }
            $tRows = DB::fetchAll($tSql, $tParams);
            $out = [];
            foreach ($tRows as $tr) {
                $out[] = [
                    'chat_id' => $tr['chat_id'],
                    'student_id' => null,
                    'first_name' => '', 'last_name' => '',
                    'national_id' => '', 'grade_level' => '', 'class_name' => '',
                    'is_teacher' => 1,
                    'display_name' => function_exists('teacher_respectful_name') ? teacher_respectful_name($tr['full_name']) : $tr['full_name'],
                ];
            }
            if (function_exists('persian_usort_by')) persian_usort_by($out, ['display_name']);
            return $out;
        }
        if ($scope === 'grade') {
            $rows = DB::fetchAll($base . " AND s.grade_level = ?", [$value]);
        } elseif ($scope === 'class') {
            $rows = DB::fetchAll($base . " AND s.class_name = ?", [$value]);
        } elseif ($scope === 'student') {
            return DB::fetchAll($base . " AND s.id = ?", [(int)$value]);
        } else {
            $rows = DB::fetchAll($base);
        }
        if (function_exists('persian_usort_by')) persian_usort_by($rows, ['class_name','last_name','first_name']);
        return $rows;
    }
}

if (!function_exists('bot_send_targeted_text')) {
    function bot_send_targeted_text($platform, $scope, $value, $message) {
        $recipients = bot_target_recipients($platform, $scope, $value);
        $sent = 0; $failed = 0; $queued = 0;
        foreach ($recipients as $r) {
            $rcptName = !empty($r['is_teacher']) ? (string)($r['display_name'] ?? '') : trim($r['first_name'] . ' ' . $r['last_name']);
            $text = bot_text($platform, 'broadcast_prefix', ['message' => $message, 'student_name' => $rcptName]);
            try {
                $delivery=bot_send_message($platform, $r['chat_id'], $text);
                bot_log_send($platform, $r['chat_id'], $r['student_id'], 'targeted_text', $message, 'sent', 'ok');
                if(!empty($delivery['queued']))$queued++;else $sent++;
            } catch (Exception $e) {
                bot_log_send($platform, $r['chat_id'], $r['student_id'], 'targeted_text', $message, 'failed', $e->getMessage());
                $failed++;
            }
        }
        return ['total' => count($recipients), 'sent' => $sent, 'failed' => $failed, 'queued' => $queued];
    }
}

if (!function_exists('bot_send_targeted_file')) {
    /**
     * v4.47.0: targeted broadcast of an attached file (photo or document)
     * with an optional caption, to the same audiences as text broadcast.
     */
    function bot_send_targeted_file($platform, $scope, $value, $caption, $filePath, $mime = '', $origName = '') {
        $recipients = bot_target_recipients($platform, $scope, $value);
        $mimeL = strtolower($mime);
        // v4.50.0: route by media kind so recipients get playable video/audio
        // (like a real messenger) instead of a plain document.
        $kind = 'document';
        if (in_array($mimeL, ['image/jpeg', 'image/png', 'image/webp'], true)) $kind = 'photo';
        elseif ($mimeL === 'video/mp4') $kind = 'video';
        elseif (in_array($mimeL, ['audio/mpeg', 'audio/wav'], true)) $kind = 'audio';
        elseif ($mimeL === 'audio/ogg') $kind = 'voice';
        $sent = 0; $failed = 0;
        foreach ($recipients as $r) {
            $rcptName = !empty($r['is_teacher']) ? (string)($r['display_name'] ?? '') : trim($r['first_name'] . ' ' . $r['last_name']);
            $text = $caption !== ''
                ? bot_text($platform, 'broadcast_prefix', ['message' => $caption, 'student_name' => $rcptName])
                : '';
            $logMsg = '[فایل: ' . ($origName !== '' ? $origName : basename($filePath)) . '] ' . $caption;
            try {
                try {
                    if ($kind === 'photo')      bot_send_photo($platform, $r['chat_id'], $filePath, $text, null, $mime, $origName);
                    elseif ($kind === 'video')  bot_send_video($platform, $r['chat_id'], $filePath, $text, null, $mime, $origName);
                    elseif ($kind === 'audio')  bot_send_audio($platform, $r['chat_id'], $filePath, $text, null, $mime, $origName);
                    elseif ($kind === 'voice')  bot_send_voice($platform, $r['chat_id'], $filePath, $text, null, $mime, $origName);
                    else                        bot_send_document($platform, $r['chat_id'], $filePath, $text, null, $mime, $origName);
                } catch (Exception $inner) {
                    // Media-specific method unsupported/failed → fall back to document.
                    if ($kind === 'document') throw $inner;
                    bot_send_document($platform, $r['chat_id'], $filePath, $text, null, $mime, $origName);
                }
                bot_log_send($platform, $r['chat_id'], $r['student_id'], 'targeted_file', $logMsg, 'sent', 'ok');
                $sent++;
            } catch (Exception $e) {
                bot_log_send($platform, $r['chat_id'], $r['student_id'], 'targeted_file', $logMsg, 'failed', $e->getMessage());
                $failed++;
            }
        }
        return ['total' => count($recipients), 'sent' => $sent, 'failed' => $failed];
    }
}

if (!function_exists('bot_generate_report_pdf')) {
    /**
     * v4.50.0: dependency-free PDF report card.
     * Renders the existing GD report image and embeds it (as JPEG) into a
     * minimal valid single-page PDF — works on any host, no TCPDF needed.
     * Returns the generated PDF file path.
     */
    function bot_generate_report_pdf($reportId) {
        $png = generate_report_card_image($reportId);
        $im = @imagecreatefrompng($png);
        if (!$im) throw new RuntimeException('بازکردن تصویر کارنامه برای ساخت PDF ناموفق بود.');
        $w = imagesx($im); $h = imagesy($im);
        // Flatten alpha over white, then JPEG-encode for compact DCTDecode embedding.
        $flat = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($flat, 255, 255, 255);
        imagefilledrectangle($flat, 0, 0, $w, $h, $white);
        imagecopy($flat, $im, 0, 0, 0, 0, $w, $h);
        imagedestroy($im);
        ob_start();
        imagejpeg($flat, null, 88);
        $jpeg = ob_get_clean();
        imagedestroy($flat);
        if ($jpeg === false || $jpeg === '') throw new RuntimeException('تبدیل تصویر کارنامه به JPEG ناموفق بود.');
        // Page sized to A4 width, proportional height (single tall page).
        $pw = 595.28; $ph = round($pw * $h / $w, 2);
        $content = "q\n{$pw} 0 0 {$ph} 0 0 cm\n/Im1 Do\nQ";
        $objs = [];
        $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objs[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $objs[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pw} {$ph}] /Resources << /XObject << /Im1 5 0 R >> /ProcSet [/PDF /ImageC] >> /Contents 4 0 R >>";
        $objs[4] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        $objs[5] = "<< /Type /XObject /Subtype /Image /Width {$w} /Height {$h} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($jpeg) . " >>\nstream\n" . $jpeg . "\nendstream";
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xrefPos . "\n%%EOF";
        $file = report_image_safe_filename('report_card_pdf', $reportId);
        $file = preg_replace('/\.png$/i', '', $file) . '.pdf';
        // Auto-clean stale generated PDFs (the shared cleaner only handles *.png).
        foreach (glob(dirname($file) . '/*.pdf') ?: [] as $old) {
            if (is_file($old) && filemtime($old) < time() - 300) @unlink($old);
        }
        if (@file_put_contents($file, $pdf) === false) throw new RuntimeException('ذخیره فایل PDF کارنامه ناموفق بود.');
        return $file;
    }
}

if (!function_exists('bot_send_report_pdf_to_chat')) {
    // v4.50.0: deliver the report card as a downloadable/printable PDF document.
    function bot_send_report_pdf_to_chat($platform, $chatId, $reportId) {
        $platform = bot_valid_platform($platform);
        $data = get_report_analysis_data($reportId);
        if (!$data) throw new RuntimeException('کارنامه یافت نشد.');
        $r = $data['report'];
        $pdf = bot_generate_report_pdf($reportId);
        $caption = '📄 نسخه PDF کارنامه ' . trim($r['first_name'] . ' ' . $r['last_name']) . ' — ' . $r['term'] . ' (' . $r['report_month'] . ')';
        $niceName = 'Karname-' . preg_replace('/\D+/', '', (string)$r['national_id']) . '-' . (int)$reportId . '.pdf';
        try {
            $res = bot_send_document($platform, $chatId, $pdf, $caption, null, 'application/pdf', $niceName);
            bot_log_send($platform, $chatId, $r['student_id'], 'report_pdf', $caption, 'sent', 'ok');
            return $res;
        } catch (Exception $e) {
            bot_log_send($platform, $chatId, $r['student_id'], 'report_pdf', $caption, 'failed', $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('notify_student_attendance_bots')) {
    /**
     * v4.50.0: push absence/tardiness alerts to all parent chats linked to a
     * student, on both Bale and Telegram, with an acknowledge button.
     * Returns the number of chats notified.
     */
    function notify_student_attendance_bots($studentId, $status, $dateJalali, $minutesLate = 0, $note = '', $recordId = null, $scanTime = '') {
        $sentCount = 0;
        $isLate = ($status === 'late');
        $title = $isLate ? '⏰ اطلاع‌رسانی تأخیر' : '🔴 اطلاع‌رسانی غیبت';
        $line = $isLate
            ? ('فرزند شما امروز با ' . tr_num((string)max(1, (int)$minutesLate), 'fa') . ' دقیقه تأخیر در مدرسه حاضر شده است.')
            : 'فرزند شما امروز در مدرسه حاضر نشده است (غیبت).';
        // v4.75.0: exact arrival time so parents know precisely when the
        // student entered the school (critical accuracy requirement).
        if ($isLate && trim((string)$scanTime) !== '') {
            $line .= "\n" . 'ساعت دقیق ورود به مدرسه: ' . tr_num(trim((string)$scanTime), 'fa');
        }
        foreach (bot_platforms() as $platform) {
            try {
                ensure_bot_schema($platform);
                $table = bot_user_table($platform);
                $chatCol = bot_chat_column($platform);
                $rows = DB::fetchAll("SELECT DISTINCT b.`$chatCol` AS chat_id, s.first_name, s.last_name FROM `$table` b JOIN students s ON s.id=b.student_id WHERE b.student_id=?", [(int)$studentId]);
                foreach ($rows as $r) {
                    $msg = $title . "\n"
                         . 'دانش‌آموز: ' . trim($r['first_name'] . ' ' . $r['last_name']) . "\n"
                         . 'تاریخ: ' . tr_num($dateJalali, 'fa') . "\n"
                         . $line;
                    if (trim((string)$note) !== '') $msg .= "\nتوضیحات مدرسه: " . trim($note);
                    $msg .= "\n\nدر صورت موجه بودن، لطفاً با مدرسه تماس بگیرید: " . tr_num(get_setting('school_phone', '---'), 'fa');
                    $kb = $recordId ? ['inline_keyboard' => [[['text' => '✅ اطلاع یافتم', 'callback_data' => 'ack_att_' . (int)$recordId]]]] : null;
                    try {
                        bot_send_message($platform, $r['chat_id'], $msg, $kb);
                        bot_log_send($platform, $r['chat_id'], (int)$studentId, 'attendance_alert', $msg, 'sent', 'ok');
                        $sentCount++;
                    } catch (Exception $e) {
                        bot_log_send($platform, $r['chat_id'], (int)$studentId, 'attendance_alert', $msg, 'failed', $e->getMessage());
                    }
                }
            } catch (Exception $e) { error_log('attendance notify failed: ' . $e->getMessage()); }
        }
        return $sentCount;
    }
}

if (!function_exists('ensure_attendance_schema')) {
    // v4.50.0: attendance records (absence / tardiness) with bot-notification state.
    function ensure_attendance_schema() {
        DB::execute("CREATE TABLE IF NOT EXISTS student_attendance (
            id int(11) NOT NULL AUTO_INCREMENT,
            student_id int(11) NOT NULL,
            academic_year varchar(20) DEFAULT NULL,
            date_jalali varchar(30) NOT NULL,
            status enum('absent','late') NOT NULL DEFAULT 'absent',
            minutes_late int(11) NOT NULL DEFAULT 0,
            note text DEFAULT NULL,
            notified_chats int(11) NOT NULL DEFAULT 0,
            review_status varchar(50) NOT NULL DEFAULT 'pending',
            created_by_admin_id int(11) DEFAULT NULL,
            created_by_teacher_id int(11) DEFAULT NULL,
            created_at_jalali varchar(30) NOT NULL,
            updated_at_jalali varchar(30) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_day (student_id, date_jalali),
            KEY idx_date (date_jalali), KEY idx_status (status), KEY idx_review (review_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('bot_send_report_image_to_chat')) {
    function bot_send_report_image_to_chat($platform, $chatId, $reportId) {
        $platform = bot_valid_platform($platform);
        $data = get_report_analysis_data($reportId);
        if (!$data) throw new RuntimeException('کارنامه یافت نشد.');
        $r = $data['report'];
        $image = generate_report_card_image($reportId);
        $discipline = ($r['discipline_score'] !== null && $r['discipline_score'] !== '') ? format_score($r['discipline_score']) : '---';
        $caption = bot_text($platform, 'report_caption', [
            'student_name' => trim($r['first_name'] . ' ' . $r['last_name']),
            'gpa' => format_score($r['gpa']),
            'discipline' => $discipline,
            'rank' => $data['rank'] ?: '---',
        ]);
        if (mb_strpos($caption, 'انضباط') === false) {
            $caption .= "\n✨ انضباط: " . $discipline;
        }
        try {
            $keyboard = ['inline_keyboard' => [
                [['text' => '✅ بررسی شد', 'callback_data' => 'ack_rep_' . (int)$reportId]],
                // v4.50.0: downloadable/printable PDF version of the same report.
                [['text' => '📄 دریافت نسخه PDF', 'callback_data' => 'pdfrep_' . (int)$reportId]],
            ]];
            $res = bot_send_photo($platform, $chatId, $image, $caption, $keyboard);
            bot_log_send($platform, $chatId, $r['student_id'], 'report_image', $caption, 'sent', json_encode($res, JSON_UNESCAPED_UNICODE));
            return $res;
        } catch (Exception $e) {
            bot_log_send($platform, $chatId, $r['student_id'], 'report_image', $caption, 'failed', $e->getMessage());
            throw $e;
        }
    }
}


if (!function_exists('get_latest_student_by_national_id')) {
    function get_latest_student_by_national_id($nationalId) {
        try {
            // Try to get student with latest academic_year
            $row = DB::fetch("SELECT * FROM students WHERE national_id=? AND status='active' ORDER BY academic_year DESC, id DESC LIMIT 1", [$nationalId]);
            if ($row) return $row;
        } catch (Exception $e) {}
        // Fallback to any
        return DB::fetch("SELECT * FROM students WHERE national_id=? AND status='active' LIMIT 1", [$nationalId]);
    }
}

if (!function_exists('bot_find_student_by_identity')) {
    /* v4.166.0: شناسهٔ مؤثر دانش‌آموز = بلندترین بخش رقمی پرونده. در پرونده‌هایی
       مثل «26/ب/265486» حرف و دو رقم پیشرو هیچ نقشی در اتصال ندارند؛ همان
       «265486» (یا کل رقم‌ها «26265486») برای اتصال کافی است. پرونده‌های کاملاً
       رقمی (کد ملی ۱۰ رقمی استاندارد) فقط از همان مسیر تطبیق دقیق قبلی
       می‌گذرند، پس رفتار آن‌ها ذره‌ای تغییر نمی‌کند. اگر چند دانش‌آموز متفاوت
       یک شناسهٔ ۶ رقمی داشته باشند، عمداً «یافت نشد» برمی‌گردد (ابهام = عدم
       اتصال) تا هیچ‌وقت دانش‌آموز اشتباهی به ولی وصل نشود. */
    function bot_find_student_by_identity($input) {
        $digits = preg_replace('/\D+/', '', tr_num((string)$input, 'en'));
        if ($digits === '') return null;
        $exact = get_latest_student_by_national_id($digits);
        if ($exact) return $exact;
        if (!preg_match('/^\d{6,10}$/', $digits)) return null;
        try {
            $rows = DB::fetchAll("SELECT national_id FROM students WHERE status='active' AND national_id<>'' LIMIT 20000");
        } catch (Exception $e) { return null; }
        $hits = [];
        foreach ($rows as $r) {
            $stored = (string)($r['national_id'] ?? '');
            if ($stored === '' || preg_match('/^\d+$/', $stored)) continue;
            $identity = '';
            foreach (preg_split('/\D+/', $stored, -1, PREG_SPLIT_NO_EMPTY) as $run) {
                if (strlen($run) > strlen($identity)) $identity = $run;
            }
            if ($identity === $digits || preg_replace('/\D+/', '', $stored) === $digits) $hits[$stored] = true;
        }
        if (count($hits) !== 1) return null;
        return get_latest_student_by_national_id((string)array_key_first($hits));
    }
}

if (!function_exists('get_latest_student_by_id')) {
    function get_latest_student_by_id($studentId) {
        try {
            $orig = DB::fetch("SELECT national_id FROM students WHERE id=?", [(int)$studentId]);
            if ($orig && !empty($orig['national_id'])) {
                $latest = get_latest_student_by_national_id($orig['national_id']);
                if ($latest) return $latest;
            }
        } catch (Exception $e) {}
        return DB::fetch("SELECT * FROM students WHERE id=?", [(int)$studentId]);
    }
}

if (!function_exists('normalize_academic_year_bot')) {
    function normalize_academic_year_bot($year) {
        if (!$year) return '';
        $year = trim($year);
        $year = str_replace(['-', '–', '—', ' '], ['/', '/', '/', ''], $year);
        $year = preg_replace('#/+#', '/', $year);
        return trim($year, '/');
    }
}


if (!function_exists('bot_migrate_to_latest_year')) {
    function bot_migrate_to_latest_year($platform = 'bale') {
        try {
            $platform = bot_valid_platform($platform);
            $userTable = bot_user_table($platform);
            // v4.46.0: fetch link_id, current student_id and chat_id in a single
            // query and compare student ids correctly (the old code compared the
            // student id against the link id and re-queried the table per row).
            $chatCol = bot_chat_column($platform);
            $rows = DB::fetchAll("SELECT b.id AS link_id, b.student_id AS current_student_id, b.`$chatCol` AS chat_id, s.national_id FROM `$userTable` b JOIN students s ON s.id=b.student_id WHERE s.national_id<>''");
            foreach ($rows as $r) {
                $latest = get_latest_student_by_national_id($r['national_id']);
                if ($latest && (int)$latest['id'] !== (int)$r['current_student_id']) {
                    // Same national_id points to a newer academic-year record.
                    // If the chat is already linked to the latest record, drop the stale duplicate.
                    $exists = DB::fetch("SELECT id FROM `$userTable` WHERE `$chatCol`=? AND student_id=?", [$r['chat_id'], $latest['id']]);
                    if ($exists) {
                        DB::execute("DELETE FROM `$userTable` WHERE id=?", [$r['link_id']]);
                    } else {
                        DB::execute("UPDATE `$userTable` SET student_id=? WHERE id=?", [$latest['id'], $r['link_id']]);
                    }
                }
            }
        } catch (Exception $e) {
            error_log('bot_migrate_to_latest_year failed: '.$e->getMessage());
        }
    }
}

if (!function_exists('bot_link_student')) {

    function bot_link_student($platform, $chatId, $username, $studentId) {
        ensure_bot_schema($platform);
        $platform = bot_valid_platform($platform);
        $table = bot_user_table($platform);
        $chatCol = bot_chat_column($platform);
        $usernameCol = bot_username_column($platform);
        $exists = DB::fetch("SELECT id FROM `$table` WHERE `$chatCol` = ? AND student_id = ?", [(string)$chatId, (int)$studentId]);
        if ($exists) {
            DB::execute("UPDATE `$table` SET `$usernameCol` = ? WHERE id = ?", [(string)$username, (int)$exists['id']]);
        } else {
            DB::execute("INSERT INTO `$table` (`$chatCol`, `$usernameCol`, student_id, created_at) VALUES (?, ?, ?, NOW())", [(string)$chatId, (string)$username, (int)$studentId]);
        }
    }
}

/* ═════════════ v4.169.0 — پروندهٔ مشاورهٔ هر دانش‌آموز ═════════════
   دو جدول سبک و ایندکس‌دار برای مکانیزم مشاوره:
     • counseling_messages — مکالمهٔ هر درخواست (ولی ↔ مشاور)، تاریخچه‌وار
     • counselor_notes    — یادداشت‌های خصوصی مشاور برای هر دانش‌آموز
   ساخت جدول idempotent و ارزان است و هیچ تغییری در جدول‌های موجود
   یا در مکانیزم ارسال/صف ربات ایجاد نمی‌کند. */
if (!function_exists('ensure_counseling_schema')) {
    function ensure_counseling_schema() {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            DB::execute("CREATE TABLE IF NOT EXISTS counseling_messages (
                id int(11) NOT NULL AUTO_INCREMENT,
                request_id int(11) NOT NULL,
                sender enum('parent','counselor') NOT NULL,
                sender_id int(11) DEFAULT NULL,
                body text NOT NULL,
                created_at_jalali varchar(30) NOT NULL,
                PRIMARY KEY (id), KEY idx_cmsg_request (request_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            DB::execute("CREATE TABLE IF NOT EXISTS counselor_notes (
                id int(11) NOT NULL AUTO_INCREMENT,
                student_id int(11) NOT NULL,
                teacher_id int(11) NOT NULL,
                body text NOT NULL,
                created_at_jalali varchar(30) NOT NULL,
                PRIMARY KEY (id), KEY idx_cnote_student (student_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) { error_log('counseling schema: ' . $e->getMessage()); }
    }
}

if (!function_exists('counseling_resolve_students')) {
    /**
     * دانش‌آموز(های) مرتبط با هر درخواست مشاوره را برمی‌گرداند.
     * ترتیب اولویت: student_id ثبت‌شده → اتصال چت ولی در جدول همان
     * پلتفرم → تطبیق نام آزادِ student_name با اسامی دانش‌آموزان فعال.
     * ورودی: فهرست درخواست‌ها؛ خروجی: [request_id => student_row|null].
     * کوئری‌ها دسته‌ای هستند (برای کل فهرست حداکثر سه کوئری) تا صفحه
     * مشاور روی سرور ضعیف هم سریع بماند.
     */
    function counseling_resolve_students(array $reqs) {
        $out = [];
        if (!$reqs) return $out;
        ensure_counseling_schema();
        $ids = []; $chats = array_fill_keys(bot_platforms(), []);
        foreach ($reqs as $r) {
            $rid = (int)$r['id'];
            $out[$rid] = null;
            $ids[] = $rid;
            if (!empty($r['student_id'])) continue;
            $p = $r['platform'] ?? '';
            if (isset($chats[$p]) && trim((string)($r['chat_id'] ?? '')) !== '') $chats[$p][trim((string)$r['chat_id'])][] = $rid;
        }
        /* ۱) student_id مستقیم */
        $directIds = [];
        foreach ($reqs as $r) if (!empty($r['student_id'])) $directIds[(int)$r['student_id']][] = (int)$r['id'];
        if ($directIds) {
            $marks = implode(',', array_fill(0, count($directIds), '?'));
            try {
                $srows = DB::fetchAll("SELECT * FROM students WHERE id IN ($marks)", array_keys($directIds));
                foreach ($srows as $s) foreach ($directIds[(int)$s['id']] as $rid) if ($out[$rid] === null) $out[$rid] = $s;
            } catch (Throwable $e) {}
        }
        /* ۲) اتصال چت ولی در جدول لینک همان پلتفرم */
        foreach (bot_platforms() as $p) {
            if (!$chats[$p]) continue;
            $table = bot_user_table($p);
            $chatCol = bot_chat_column($p);
            $marks = implode(',', array_fill(0, count($chats[$p]), '?'));
            try {
                ensure_bot_schema($p);
                $lrows = DB::fetchAll("SELECT b.`$chatCol` AS chat_id, s.* FROM `$table` b JOIN students s ON s.id=b.student_id WHERE b.`$chatCol` IN ($marks)", array_keys($chats[$p]));
                foreach ($lrows as $lr) {
                    $c = (string)$lr['chat_id'];
                    foreach (($chats[$p][$c] ?? []) as $rid) if ($out[$rid] === null) $out[$rid] = $lr;
                }
            } catch (Throwable $e) {}
        }
        /* ۳) تطبیق نام آزاد (فقط وقتی نام و نام خانوادگی کامل نوشته شده) */
        foreach ($reqs as $r) {
            $rid = (int)$r['id'];
            if ($out[$rid] !== null) continue;
            $nm = trim((string)($r['student_name'] ?? ''));
            if ($nm === '' || mb_strlen($nm, 'UTF-8') < 5) continue;
            $parts = preg_split('/\s+/u', $nm);
            if (count($parts) < 2) continue;
            $first = $parts[0]; $last = $parts[count($parts) - 1];
            try {
                $s = DB::fetch("SELECT * FROM students WHERE status='active' AND first_name=? AND last_name=? ORDER BY id DESC LIMIT 1", [$first, $last]);
                if ($s) $out[$rid] = $s;
            } catch (Throwable $e) {}
        }
        return $out;
    }
}

if (!function_exists('counseling_notify_counselors')) {
    /** پیام کوتاه برای مشاوران فعال در ربات — از همان مسیر صف (bot_send_message). */
    function counseling_notify_counselors($requestId, $text) {
        try {
            foreach (bot_platforms() as $pf) ensure_bot_schema($pf);
            $counselors = DB::fetchAll("SELECT id FROM teachers WHERE is_counselor=1 AND status=1");
            foreach ($counselors as $c) {
                foreach (bot_platforms() as $platform) {
                    try {
                        ensure_bot_schema($platform);
                        $sessions = DB::fetchAll("SELECT chat_id FROM bot_admin_sessions WHERE platform=? AND teacher_id=? AND role_type='teacher' AND is_active=1", [$platform, (int)$c['id']]);
                        foreach ($sessions as $s) bot_send_message($platform, $s['chat_id'], $text);
                    } catch (Throwable $e) {}
                }
            }
        } catch (Throwable $e) {}
    }
}
