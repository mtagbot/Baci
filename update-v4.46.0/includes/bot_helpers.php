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
            'invalid_nid' => '⚠️ کد ملی باید دقیقاً ۱۰ رقم عددی باشد. لطفاً دوباره ارسال کنید:',
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
            return "https://api.telegram.org/bot{$token}/{$method}";
        }
        return "https://tapi.bale.ai/bot{$token}/{$method}";
    }
}

if (!function_exists('bot_api_request')) {
    function bot_api_request($platform, $method, $data = [], $multipart = false) {
        $url = bot_api_endpoint($platform, $method);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
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
            throw new RuntimeException('خطای ارتباط با API ربات: ' . $err);
        }
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
    function bot_send_photo($platform, $chatId, $filePath, $caption = '', $keyboard = null) {
        if (!is_file($filePath)) throw new RuntimeException('فایل تصویر برای ارسال یافت نشد.');
        $payload = ['chat_id' => (string)$chatId, 'caption' => (string)$caption];
        if ($keyboard) $payload['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        $payload['photo'] = new CURLFile($filePath, 'image/png', basename($filePath));
        return bot_api_request($platform, 'sendPhoto', $payload, true);
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
        if ($scope === 'grade') {
            return DB::fetchAll($base . " AND s.grade_level = ? ORDER BY s.class_name, s.last_name", [$value]);
        }
        if ($scope === 'class') {
            return DB::fetchAll($base . " AND s.class_name = ? ORDER BY s.last_name", [$value]);
        }
        if ($scope === 'student') {
            return DB::fetchAll($base . " AND s.id = ?", [(int)$value]);
        }
        return DB::fetchAll($base . " ORDER BY s.class_name, s.last_name");
    }
}

if (!function_exists('bot_send_targeted_text')) {
    function bot_send_targeted_text($platform, $scope, $value, $message) {
        $recipients = bot_target_recipients($platform, $scope, $value);
        $sent = 0; $failed = 0;
        foreach ($recipients as $r) {
            $text = bot_text($platform, 'broadcast_prefix', ['message' => $message, 'student_name' => trim($r['first_name'] . ' ' . $r['last_name'])]);
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
            $keyboard = ['inline_keyboard' => [[['text' => '✅ بررسی شد', 'callback_data' => 'ack_rep_' . (int)$reportId]]]];
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
