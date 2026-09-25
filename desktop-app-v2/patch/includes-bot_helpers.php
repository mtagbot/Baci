<?php
// File: includes/bot_helpers.php
/**
 * Shared Bale/Telegram bot helpers: schema migration, API wrappers, templates,
 * targeting, and report-image delivery.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/report_image.php';

if (!function_exists('bot_valid_platform')) {
    function bot_valid_platform($platform) {
        return in_array($platform, ['bale', 'telegram'], true) ? $platform : 'bale';
    }
}

if (!function_exists('bot_user_table')) {
    function bot_user_table($platform) {
        return bot_valid_platform($platform) === 'telegram' ? 'telegram_bot_users' : 'bale_bot_users';
    }
}

if (!function_exists('bot_state_table')) {
    function bot_state_table($platform) {
        return bot_valid_platform($platform) === 'telegram' ? 'telegram_bot_state' : 'bale_bot_state';
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
        $userTable = bot_user_table($platform);
        $stateTable = bot_state_table($platform);
        $chatCol = $platform === 'telegram' ? 'telegram_chat_id' : 'bale_chat_id';
        $usernameCol = $platform === 'telegram' ? 'telegram_username' : 'bale_username';

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
            `platform` enum('bale','telegram') NOT NULL,
            `template_key` varchar(80) NOT NULL,
            `template_text` text NOT NULL,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_platform_key` (`platform`, `template_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        DB::execute("CREATE TABLE IF NOT EXISTS `bot_button_templates` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `platform` enum('bale','telegram') NOT NULL,
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
            `platform` enum('bale','telegram') NOT NULL,
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
        foreach (bot_default_messages() as $key => $text) {
            $exists = DB::fetch("SELECT id FROM bot_message_templates WHERE platform = ? AND template_key = ?", [$platform, $key]);
            if (!$exists) {
                DB::execute("INSERT INTO bot_message_templates (platform, template_key, template_text) VALUES (?, ?, ?)", [$platform, $key, $text]);
            }
        }
        foreach (bot_default_buttons() as $b) {
            $exists = DB::fetch("SELECT id FROM bot_button_templates WHERE platform = ? AND button_key = ?", [$platform, $b[0]]);
            if (!$exists) {
                DB::execute("INSERT INTO bot_button_templates (platform, button_key, button_text, sort_order, row_no, is_active) VALUES (?, ?, ?, ?, ?, 1)", [$platform, $b[0], $b[1], $b[2], $b[3]]);
            }
        }
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

if (!function_exists('bot_buttons_map')) {
    function bot_buttons_map($platform) {
        ensure_bot_schema($platform);
        $rows = DB::fetchAll("SELECT * FROM bot_button_templates WHERE platform = ? AND is_active = 1 ORDER BY row_no ASC, sort_order ASC, id ASC", [bot_valid_platform($platform)]);
        $map = [];
        foreach ($rows as $r) $map[$r['button_key']] = $r['button_text'];
        return $map;
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
        return "https://tapi.bale.ai/bot{$token}/{$method}";
    }
}

if (!function_exists('bot_api_request')) {
    function bot_api_request($platform, $method, $data = [], $multipart = false) {
        $url = bot_api_endpoint($platform, $method);
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
        if ($code >= 400 || !is_array($json) || (array_key_exists('ok', $json) && !$json['ok'])) {
            throw new RuntimeException('پاسخ ناموفق API: HTTP ' . $code . ' - ' . $res);
        }
        return $json;
    }
}

if (!function_exists('bot_send_message')) {
    function bot_send_message($platform, $chatId, $text, $keyboard = null) {
        $payload = ['chat_id' => (string)$chatId, 'text' => (string)$text];
        if ($keyboard) $payload['reply_markup'] = $keyboard;
        return bot_api_request($platform, 'sendMessage', $payload, false);
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
        $chatCol = $platform === 'telegram' ? 'telegram_chat_id' : 'bale_chat_id';
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
        $sent = 0; $failed = 0;
        foreach ($recipients as $r) {
            $rcptName = !empty($r['is_teacher']) ? (string)($r['display_name'] ?? '') : trim($r['first_name'] . ' ' . $r['last_name']);
            $text = bot_text($platform, 'broadcast_prefix', ['message' => $message, 'student_name' => $rcptName]);
            try {
                bot_send_message($platform, $r['chat_id'], $text);
                bot_log_send($platform, $r['chat_id'], $r['student_id'], 'targeted_text', $message, 'sent', 'ok');
                $sent++;
            } catch (Exception $e) {
                bot_log_send($platform, $r['chat_id'], $r['student_id'], 'targeted_text', $message, 'failed', $e->getMessage());
                $failed++;
            }
        }
        return ['total' => count($recipients), 'sent' => $sent, 'failed' => $failed];
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
        foreach (['bale', 'telegram'] as $platform) {
            try {
                ensure_bot_schema($platform);
                $table = bot_user_table($platform);
                $chatCol = $platform === 'telegram' ? 'telegram_chat_id' : 'bale_chat_id';
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
            $chatCol = $platform === 'telegram' ? 'telegram_chat_id' : 'bale_chat_id';
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
        $chatCol = $platform === 'telegram' ? 'telegram_chat_id' : 'bale_chat_id';
        $usernameCol = $platform === 'telegram' ? 'telegram_username' : 'bale_username';
        $exists = DB::fetch("SELECT id FROM `$table` WHERE `$chatCol` = ? AND student_id = ?", [(string)$chatId, (int)$studentId]);
        if ($exists) {
            DB::execute("UPDATE `$table` SET `$usernameCol` = ? WHERE id = ?", [(string)$username, (int)$exists['id']]);
        } else {
            DB::execute("INSERT INTO `$table` (`$chatCol`, `$usernameCol`, student_id, created_at) VALUES (?, ?, ?, NOW())", [(string)$chatId, (string)$username, (int)$studentId]);
        }
    }
}
