<?php
// File: includes/bot_webhook_engine.php
/**
 * Shared webhook workflow for Bale and Telegram.
 * The including file must define BOT_PLATFORM as 'bale' or 'telegram'.
 */

require_once __DIR__ . '/bot_helpers.php';
require_once __DIR__ . '/school_roles.php';
require_once __DIR__ . '/exams_helper.php';
require_once __DIR__ . '/online_exam_helpers.php';
require_once __DIR__ . '/bot_role_engine.php';   // v4.87.0: multi-role staff panels
ensure_school_roles_schema();
ensure_exams_schema();

$platform = defined('BOT_PLATFORM') ? bot_valid_platform(BOT_PLATFORM) : 'bale';
try {
    ensure_bot_schema($platform);
    // Migrate existing bot links to latest academic year (for students in multiple grades 7,8,9)
    if (function_exists('bot_migrate_to_latest_year')) {
        bot_migrate_to_latest_year($platform);
    }
} catch (Exception $e) {
    error_log('Bot schema error: ' . $e->getMessage());
}

if (get_setting(bot_token_key($platform), '') === '') {
    http_response_code(200);
    exit;
}

// v4.46.0: reject forged updates when a webhook secret is configured.
// The secret is set automatically when the admin re-registers the webhook
// from the bot panel (Telegram only; Bale does not send this header).
if ($platform === 'telegram') {
    $expectedSecret = get_setting('telegram_webhook_secret', '');
    if ($expectedSecret !== '') {
        $gotSecret = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
        if (!hash_equals($expectedSecret, $gotSecret)) {
            http_response_code(403);
            exit;
        }
    }
}

$input = file_get_contents('php://input');
$update = json_decode($input, true);
if (!is_array($update)) {
    http_response_code(200);
    exit;
}

$userTable = bot_user_table($platform);
$stateTable = bot_state_table($platform);
$chatCol = $platform === 'telegram' ? 'telegram_chat_id' : 'bale_chat_id';
$usernameCol = $platform === 'telegram' ? 'telegram_username' : 'bale_username';
$buttons = bot_buttons_map($platform);
$btnReports = $buttons['main_reports'] ?? '📊 دریافت کارنامه تحصیلی';
$btnChildren = $buttons['main_children'] ?? '👤 وضعیت فرزندان من';
$btnAdd = $buttons['main_add_child'] ?? '➕ افزودن فرزند جدید';
$btnContact = $buttons['main_contact'] ?? '📞 ارتباط با آموزشگاه';
$btnTeacher = $buttons['main_teacher_login'] ?? '👨‍🏫 ورود دبیران';
$btnCounselor = $buttons['main_counselor'] ?? '🧭 ارتباط با مشاور';
$btnDiscipline = $buttons['main_discipline'] ?? '⚠️ موارد انضباطی';
$btnExamSchedule = $buttons['main_exam_schedule'] ?? '📝 برنامه امتحانی';
$btnRemoveChild = $buttons['main_remove_child'] ?? '🗑️ حذف دانش‌آموز از حساب';

function bot_webhook_safe_send($platform, $chatId, $text, $keyboard = null) {
    try {
        return bot_send_message($platform, $chatId, $text, $keyboard);
    } catch (Exception $e) {
        error_log('Bot sendMessage failed: ' . $e->getMessage());
        return null;
    }
}

function bot_webhook_answer_callback($platform, $callbackId) {
    if (!$callbackId) return;
    try {
        bot_api_request($platform, 'answerCallbackQuery', ['callback_query_id' => $callbackId]);
    } catch (Exception $e) {
        error_log('answerCallbackQuery failed: ' . $e->getMessage());
    }
}

$callbackQuery = $update['callback_query'] ?? null;
$message = $update['message'] ?? null;

if ($callbackQuery) {
    $chatId = (string)($callbackQuery['message']['chat']['id'] ?? '');
    $data = (string)($callbackQuery['data'] ?? '');
    bot_webhook_answer_callback($platform, $callbackQuery['id'] ?? '');

    // v4.87.0: staff-role buttons (exam-design PDF, objection reply flow)
    if ($chatId !== '' && bot_role_handle_callback($platform, $chatId, $data, $stateTable, $chatCol)) {
        http_response_code(200);
        exit;
    }

    if ($chatId !== '' && strpos($data, 'ack_rep_') === 0) {
        $repId = (int)substr($data, 8);
        DB::execute("CREATE TABLE IF NOT EXISTS report_parent_reviews (id int(11) NOT NULL AUTO_INCREMENT, report_id int(11) NOT NULL, platform enum('bale','telegram') NOT NULL, chat_id varchar(80) NOT NULL, reviewed_at_jalali varchar(30) NOT NULL, PRIMARY KEY(id), UNIQUE KEY uniq_review (report_id, platform, chat_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        DB::execute("INSERT INTO report_parent_reviews (report_id, platform, chat_id, reviewed_at_jalali) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE reviewed_at_jalali=VALUES(reviewed_at_jalali)", [$repId, $platform, $chatId, jalali_now()]);
        bot_webhook_safe_send($platform, $chatId, '✅ بررسی کارنامه ثبت شد.');
    }
    if ($chatId !== '' && strpos($data, 'ack_disc_') === 0) {
        $rid = (int)substr($data, 9);
        DB::execute("UPDATE student_discipline_records SET review_status='reviewed', updated_at_jalali=? WHERE id=?", [jalali_now(), $rid]);
        bot_webhook_safe_send($platform, $chatId, '✅ بررسی مورد انضباطی ثبت شد.');
    }
    // v4.50.0: parent acknowledges an absence/tardiness alert.
    if ($chatId !== '' && strpos($data, 'ack_att_') === 0) {
        $rid = (int)substr($data, 8);
        try {
            if (function_exists('ensure_attendance_schema')) ensure_attendance_schema();
            DB::execute("UPDATE student_attendance SET review_status='acknowledged', updated_at_jalali=? WHERE id=?", [jalali_now(), $rid]);
            bot_webhook_safe_send($platform, $chatId, '✅ اطلاع شما از این مورد حضور و غیاب ثبت شد. متشکریم.');
        } catch (Exception $e) {
            error_log('ack_att failed: ' . $e->getMessage());
        }
    }
    // v4.50.0: parent requests the PDF version of a report card.
    if ($chatId !== '' && strpos($data, 'pdfrep_') === 0) {
        $repId = (int)substr($data, 7);
        $report = DB::fetch("SELECT r.*, s.first_name, s.last_name FROM reports r JOIN students s ON r.student_id = s.id WHERE r.id = ? AND r.is_locked = 0", [$repId]);
        if ($report) {
            $linked = DB::fetch("SELECT id FROM `$userTable` WHERE `$chatCol` = ? AND student_id = ?", [$chatId, $report['student_id']]);
            if ($linked) {
                try {
                    bot_send_report_pdf_to_chat($platform, $chatId, $repId);
                } catch (Exception $e) {
                    bot_webhook_safe_send($platform, $chatId, 'ساخت نسخه PDF کارنامه با خطا روبه‌رو شد: ' . $e->getMessage(), bot_main_keyboard($platform));
                }
            } else {
                bot_webhook_safe_send($platform, $chatId, 'دسترسی شما به این کارنامه تأیید نشد.', bot_main_keyboard($platform));
            }
        }
    }

    if ($chatId !== '' && strpos($data, 'unlink_') === 0) {
        $sid = (int)substr($data, 7);
        DB::execute("DELETE FROM `$userTable` WHERE `$chatCol` = ? AND student_id = ?", [$chatId, $sid]);
        bot_webhook_safe_send($platform, $chatId, 'دانش‌آموز انتخاب‌شده از حساب ربات شما حذف شد.', bot_main_keyboard($platform));
    }

    if ($chatId !== '' && strpos($data, 'rep_') === 0) {
        $repId = (int)substr($data, 4);
        $report = DB::fetch("SELECT r.*, s.first_name, s.last_name FROM reports r JOIN students s ON r.student_id = s.id WHERE r.id = ? AND r.is_locked = 0", [$repId]);
        if ($report) {
            $linked = DB::fetch("SELECT id FROM `$userTable` WHERE `$chatCol` = ? AND student_id = ?", [$chatId, $report['student_id']]);
            if ($linked) {
                try {
                    $grades = DB::fetchAll("SELECT subject_name, score FROM report_grades WHERE report_id=?", [$repId]);
                    $txt = "🎓 کارنامه " . $report['first_name'] . ' ' . $report['last_name'] . "\n";
                    $txt .= "📅 " . $report['term'] . ' - ' . $report['report_month'] . "\n⭐ معدل: " . format_score($report['gpa']) . "\n";
                    if ($report['discipline_score'] !== null && $report['discipline_score'] !== '') {
                        $txt .= "✨ انضباط: " . format_score($report['discipline_score']) . "\n";
                    }
                    foreach ($grades as $gg) $txt .= "\n▪️ " . $gg['subject_name'] . ': ' . display_score($gg['score']);
                    bot_webhook_safe_send($platform, $chatId, $txt);
                    bot_send_report_image_to_chat($platform, $chatId, $repId);
                } catch (Exception $e) {
                    bot_webhook_safe_send($platform, $chatId, 'ارسال تصویر کارنامه با خطا روبه‌رو شد: ' . $e->getMessage(), bot_main_keyboard($platform));
                }
            } else {
                bot_webhook_safe_send($platform, $chatId, 'دسترسی شما به این کارنامه تأیید نشد.', bot_main_keyboard($platform));
            }
        }
    }
    http_response_code(200);
    exit;
}

if (!$message) {
    http_response_code(200);
    exit;
}

$chatId = (string)($message['chat']['id'] ?? '');
$text = trim((string)($message['text'] ?? ''));
$username = (string)($message['from']['username'] ?? '');
if ($chatId === '') {
    http_response_code(200);
    exit;
}

if ($text === '/start') {
    DB::execute("REPLACE INTO `$stateTable` (`$chatCol`, step, temp_nid) VALUES (?, 'awaiting_nid', NULL)", [$chatId]);
    // v4.87.0: an already-signed-in staff member gets the staff panel back
    $staffOnStart = bot_staff_session($platform, $chatId);
    if ($staffOnStart) {
        DB::execute("DELETE FROM `$stateTable` WHERE `$chatCol`=?", [$chatId]);
        $tFamS = function_exists('teacher_respectful_name') ? teacher_respectful_name($staffOnStart['full_name']) : $staffOnStart['full_name'];
        $roleNames = [];
        foreach (bot_teacher_roles($staffOnStart) as $rl) $roleNames[] = bot_role_labels()[$rl] ?? $rl;
        bot_webhook_safe_send($platform, $chatId, "🌺 خوش آمدید {$tFamS}\nنقش‌های فعال شما: " . implode('، ', $roleNames) . "\nاز دکمه‌های پایین استفاده کنید.", bot_staff_keyboard($staffOnStart));
        http_response_code(200);
        exit;
    }
    bot_webhook_safe_send($platform, $chatId, bot_text($platform, 'welcome'));
    http_response_code(200);
    exit;
}

/* ─────────── v4.87.0: staff glass buttons (teacher/deputy/executive) ─────────── */
$staffSession = bot_staff_session($platform, $chatId);

if ($staffSession && $text === '🗓 برنامه هفتگی تدریس من') {
    bot_webhook_safe_send($platform, $chatId, bot_teacher_weekly_text($staffSession['teacher_id'], $staffSession['full_name']), bot_staff_keyboard($staffSession));
    http_response_code(200); exit;
}
if ($staffSession && $text === '📝 برنامه امتحانی من') {
    bot_webhook_safe_send($platform, $chatId, bot_teacher_exams_text($staffSession['teacher_id'], $staffSession['full_name']), bot_staff_keyboard($staffSession));
    http_response_code(200); exit;
}
if ($staffSession && $text === '💬 اعتراضات نمرات') {
    $pend = DB::fetchAll("SELECT gm.id, gm.subject_name, gm.message, s.first_name, s.last_name, s.class_name FROM grade_messages gm JOIN students s ON s.id=gm.student_id WHERE gm.teacher_id=? AND gm.status='pending' ORDER BY gm.id DESC LIMIT 15", [(int)$staffSession['teacher_id']]);
    if (!$pend) {
        bot_webhook_safe_send($platform, $chatId, '💬 اعتراض بی‌پاسخی برای دروس شما وجود ندارد. ✅', bot_staff_keyboard($staffSession));
    } else {
        foreach ($pend as $p) {
            $kbO = ['inline_keyboard' => [[['text' => '✍️ پاسخ به اعتراض', 'callback_data' => 'objreply_' . (int)$p['id']]]]];
            bot_webhook_safe_send($platform, $chatId, "💬 اعتراض بی‌پاسخ\n👤 " . trim($p['first_name'].' '.$p['last_name']) . ' (' . ($p['class_name'] ?: '—') . ")\n📚 درس: " . $p['subject_name'] . "\n\n📝 " . mb_substr((string)$p['message'], 0, 500, 'UTF-8'), $kbO);
        }
    }
    http_response_code(200); exit;
}
if ($staffSession && $text === '🚫 غایبین امروز') {
    if (empty($staffSession['is_deputy'])) {
        bot_webhook_safe_send($platform, $chatId, 'این گزینه مخصوص معاون/ناظم است.', bot_staff_keyboard($staffSession));
    } else {
        bot_webhook_safe_send($platform, $chatId, bot_deputy_absents_text(), bot_staff_keyboard($staffSession));
    }
    http_response_code(200); exit;
}
if ($staffSession && $text === '🖨 آزمون‌های طراحی‌شده') {
    if (empty($staffSession['is_executive'])) {
        bot_webhook_safe_send($platform, $chatId, 'این گزینه مخصوص معاون اجرایی است.', bot_staff_keyboard($staffSession));
    } else {
        list($dTxt, $dKb) = bot_exec_designs_text_and_kb();
        bot_webhook_safe_send($platform, $chatId, $dTxt, $dKb ?: bot_staff_keyboard($staffSession));
    }
    http_response_code(200); exit;
}
if ($staffSession && $text === '🚪 خروج از حساب کارکنان') {
    DB::execute("UPDATE bot_admin_sessions SET is_active=0 WHERE platform=? AND chat_id=? AND role_type='teacher'", [$platform, $chatId]);
    DB::execute("DELETE FROM `$stateTable` WHERE `$chatCol`=?", [$chatId]);
    bot_webhook_safe_send($platform, $chatId, '🚪 از حساب کارکنان خارج شدید. برای شروع دوباره /start را بزنید.', bot_main_keyboard($platform));
    http_response_code(200); exit;
}

// Hidden admin entry: the bot never shows an admin-login button. Admin sends /start admin_SECRET or /admin SECRET.
if (preg_match('/^\/(?:start|admin)(?:\s+|_)?admin[_\-]?([A-Za-z0-9]{8,})$/u', $text, $m)) {
    $secret = get_setting('admin_bot_login_secret', '');
    if ($secret === '') { $secret = bin2hex(random_bytes(8)); set_setting('admin_bot_login_secret', $secret); }
    if (hash_equals($secret, $m[1])) {
        DB::execute("REPLACE INTO `$stateTable` (`$chatCol`, step, temp_nid, temp_payload) VALUES (?, 'admin_username', NULL, NULL)", [$chatId]);
        bot_webhook_safe_send($platform, $chatId, 'نام کاربری مدیر را ارسال کنید:');
    } else {
        bot_webhook_safe_send($platform, $chatId, 'لینک ورود مدیریتی نامعتبر است.');
    }
    http_response_code(200); exit;
}

if ($text === $btnTeacher) {
    DB::execute("REPLACE INTO `$stateTable` (`$chatCol`, step, temp_nid, temp_payload) VALUES (?, 'teacher_username', NULL, NULL)", [$chatId]);
    bot_webhook_safe_send($platform, $chatId, 'کد ملی/نام کاربری دبیر را ارسال کنید:');
    http_response_code(200); exit;
}

if ($text === $btnCounselor) {
    DB::execute("REPLACE INTO `$stateTable` (`$chatCol`, step, temp_nid, temp_payload) VALUES (?, 'counsel_name', NULL, ?)", [$chatId, json_encode([], JSON_UNESCAPED_UNICODE)]);
    bot_webhook_safe_send($platform, $chatId, 'برای ثبت درخواست مشاوره، نام و نام خانوادگی خود را ارسال کنید:');
    http_response_code(200); exit;
}

if ($text === $btnDiscipline) {
    $links = DB::fetchAll("SELECT s.id, s.first_name, s.last_name, s.national_id FROM `$userTable` b JOIN students s ON b.student_id = s.id WHERE b.`$chatCol` = ? ORDER BY s.last_name", [$chatId]);
    if (function_exists('persian_usort_by')) persian_usort_by($links, ['last_name','first_name']);
    if (!$links) bot_webhook_safe_send($platform, $chatId, 'ابتدا یک دانش‌آموز را به حساب خود متصل کنید.', bot_main_keyboard($platform));
    foreach ($links as $st) {
        // Always use latest academic year for student who is in multiple grades (7,8,9) - use latest student id
        $latestSt = get_latest_student_by_id($st['id']);
        $effectiveId = $latestSt ? $latestSt['id'] : $st['id'];
        // Also get all student ids with same national_id to show all discipline across years? But per request, show only latest year
        // For discipline, we will show only latest year's records (per user request)
        DB::execute("UPDATE student_discipline_records SET review_status='reviewed', updated_at_jalali=? WHERE student_id=? AND COALESCE(review_status,'pending')='pending'", [jalali_now(), $effectiveId]);
        $items = DB::fetchAll("SELECT title_text, occurred_at_jalali FROM student_discipline_records WHERE student_id=? ORDER BY id DESC", [$effectiveId]);
        $msg = '⚠️ موارد انضباطی ' . $st['first_name'].' '.$st['last_name'] . "\n";
        if (!$items) $msg .= 'موردی ثبت نشده است.';
        foreach ($items as $it) $msg .= "\n▪️ {$it['title_text']} - " . tr_num($it['occurred_at_jalali'], 'fa');
        bot_webhook_safe_send($platform, $chatId, $msg, bot_main_keyboard($platform));
    }
    http_response_code(200); exit;
}

if ($text === $btnExamSchedule) {
    $links = DB::fetchAll("SELECT s.id, s.first_name, s.last_name, s.national_id FROM `$userTable` b JOIN students s ON b.student_id = s.id WHERE b.`$chatCol` = ? ORDER BY s.last_name", [$chatId]);
    if (function_exists('persian_usort_by')) persian_usort_by($links, ['last_name','first_name']);
    if (!$links) bot_webhook_safe_send($platform, $chatId, 'ابتدا یک دانش‌آموز را به حساب خود متصل کنید.', bot_main_keyboard($platform));
    foreach ($links as $st) {
        $latestSt = get_latest_student_by_id($st['id']);
        $effectiveId = $latestSt ? $latestSt['id'] : $st['id'];
        $items = exam_student_schedule($effectiveId);
        $studentName = trim($st['first_name'] . ' ' . $st['last_name']);
        if (!$items) { bot_webhook_safe_send($platform, $chatId, bot_text($platform, 'exam_schedule_empty', ['student_name'=>$studentName]), bot_main_keyboard($platform)); continue; }
        $msg = bot_text($platform, 'exam_schedule_title', ['student_name'=>$studentName]);
        foreach ($items as $ex) {
            $msg .= "\n▪️ " . $ex['subject_name'] . " | " . tr_num($ex['exam_date_jalali'], 'fa') . " ساعت " . tr_num($ex['start_time'], 'fa') . " | صندلی: " . tr_num($ex['seat_number'] ?: '---', 'fa') . " | کلاس: " . ($ex['exam_room'] ?: $ex['exam_room_default'] ?: '---');
        }
        // Also include online exams for latest year (virtual exams)
        try {
            ensure_online_exams_schema();
            $latestForOnline = get_latest_student_by_id($st['id']);
            $onlineStudent = $latestForOnline ?: $st;
            $onlineExams = [];
            try {
                $currentYearBot = get_setting('current_academic_year','1404/1405');
                $onlineExams = DB::fetchAll("SELECT * FROM online_exams WHERE status='published' AND is_active=1 AND (academic_year=? OR academic_year='' OR academic_year IS NULL) AND (class_name='' OR class_name=? OR class_name IS NULL) ORDER BY start_datetime DESC LIMIT 10", [$currentYearBot, $onlineStudent['class_name'] ?? '']);
            } catch (Exception $e) {
                $onlineExams = DB::fetchAll("SELECT * FROM online_exams WHERE status='published' AND is_active=1 ORDER BY id DESC LIMIT 10");
            }
            if (!empty($onlineExams)) {
                $msg .= "\n\n🧪 آزمون‌های مجازی (آنلاین) - سال جدید:\n";
                foreach ($onlineExams as $oe) {
                    $startFa = $oe['start_datetime'] ? tr_num(jdate('Y/m/d H:i', strtotime($oe['start_datetime'])), 'fa') : 'نامشخص';
                    $msg .= "\n▪️ ". $oe['title'] . " | مدت: " . tr_num($oe['duration_minutes'], 'fa') . " دقیقه | شروع: " . $startFa;
                }
            }
        } catch (Exception $e) {}
        bot_webhook_safe_send($platform, $chatId, $msg, bot_main_keyboard($platform));
    }
    http_response_code(200); exit;
}

if ($text === $btnRemoveChild) {
    $links = DB::fetchAll("SELECT s.id, s.first_name, s.last_name FROM `$userTable` b JOIN students s ON b.student_id = s.id WHERE b.`$chatCol` = ? ORDER BY s.last_name", [$chatId]);
    if (function_exists('persian_usort_by')) persian_usort_by($links, ['last_name','first_name']);
    $inline=[]; foreach($links as $st) $inline[]=[[ 'text'=>'حذف '.$st['first_name'].' '.$st['last_name'], 'callback_data'=>'unlink_'.$st['id'] ]];
    bot_webhook_safe_send($platform, $chatId, $inline ? 'دانش‌آموز موردنظر برای حذف از حساب ربات را انتخاب کنید:' : 'دانش‌آموزی متصل نیست.', $inline?['inline_keyboard'=>$inline]:bot_main_keyboard($platform));
    http_response_code(200); exit;
}

if ($text === $btnReports) {
    $links = DB::fetchAll("SELECT s.id, s.first_name, s.last_name, s.national_id FROM `$userTable` b JOIN students s ON b.student_id = s.id WHERE b.`$chatCol` = ? ORDER BY s.last_name", [$chatId]);
    if (function_exists('persian_usort_by')) persian_usort_by($links, ['last_name','first_name']);
    if (empty($links)) {
        DB::execute("REPLACE INTO `$stateTable` (`$chatCol`, step, temp_nid) VALUES (?, 'awaiting_nid', NULL)", [$chatId]);
        bot_webhook_safe_send($platform, $chatId, bot_text($platform, 'no_link'));
    } else {
        foreach ($links as $st) {
            $latestSt = get_latest_student_by_id($st['id']);
            $effectiveId = $latestSt ? $latestSt['id'] : $st['id'];
            $reps = DB::fetchAll("SELECT id, term, report_month, academic_year FROM reports WHERE student_id = ? AND is_locked = 0 ORDER BY id DESC", [$effectiveId]);
            if (!$reps) {
                bot_webhook_safe_send($platform, $chatId, bot_text($platform, 'no_report', ['student_name' => trim($st['first_name'] . ' ' . $st['last_name'])]), bot_main_keyboard($platform));
                continue;
            }
            $inline = [];
            foreach ($reps as $r) {
                $inline[] = [['text' => 'کارنامه ' . $r['term'] . ' (' . $r['report_month'] . ')', 'callback_data' => 'rep_' . $r['id']]];
            }
            bot_webhook_safe_send($platform, $chatId, bot_text($platform, 'choose_report', ['student_name' => trim($st['first_name'] . ' ' . $st['last_name'])]), ['inline_keyboard' => $inline]);
        }
    }
    http_response_code(200);
    exit;
}

if ($text === $btnChildren) {
    $links = DB::fetchAll("SELECT s.* FROM `$userTable` b JOIN students s ON b.student_id = s.id WHERE b.`$chatCol` = ? ORDER BY s.last_name", [$chatId]);
    if (function_exists('persian_usort_by')) persian_usort_by($links, ['last_name','first_name']);
    // Convert each to latest year
    $latestLinks = [];
    foreach ($links as $l) {
        $latest = get_latest_student_by_id($l['id']);
        if ($latest) $latestLinks[] = $latest;
        else $latestLinks[] = $l;
    }
    $links = $latestLinks;
    if (!$links) {
        bot_webhook_safe_send($platform, $chatId, 'رکوردی متصل نیست.', bot_main_keyboard($platform));
    } else {
        $msg = bot_text($platform, 'children_title');
        foreach ($links as $st) {
            $msg .= '▪️ نام: ' . $st['first_name'] . ' ' . $st['last_name'] . ' | کلاس: ' . $st['class_name'] . "\n";
        }
        bot_webhook_safe_send($platform, $chatId, $msg, bot_main_keyboard($platform));
    }
    http_response_code(200);
    exit;
}

if ($text === $btnAdd) {
    DB::execute("REPLACE INTO `$stateTable` (`$chatCol`, step, temp_nid) VALUES (?, 'awaiting_nid', NULL)", [$chatId]);
    bot_webhook_safe_send($platform, $chatId, 'لطفاً کد ملی ۱۰ رقمی دانش‌آموز جدید را ارسال کنید:');
    http_response_code(200);
    exit;
}

if ($text === $btnContact) {
    bot_webhook_safe_send($platform, $chatId, bot_text($platform, 'contact'), bot_main_keyboard($platform));
    http_response_code(200);
    exit;
}

$state = DB::fetch("SELECT * FROM `$stateTable` WHERE `$chatCol` = ?", [$chatId]);
$step = $state['step'] ?? 'awaiting_nid';

if ($step === 'admin_username') {
    DB::execute("REPLACE INTO `$stateTable` (`$chatCol`, step, temp_nid, temp_payload) VALUES (?, 'admin_password', ?, NULL)", [$chatId, trim($text)]);
    bot_webhook_safe_send($platform, $chatId, 'رمز عبور مدیر را ارسال کنید:');
    http_response_code(200); exit;
}
if ($step === 'admin_password') {
    $username = $state['temp_nid'] ?? '';
    $admin = DB::fetch("SELECT * FROM admins WHERE username=? AND status=1", [$username]);
    if ($admin && (password_verify($text, $admin['password']) || hash_equals((string)$admin['password'], $text) || md5($text)===$admin['password'])) {
        DB::execute("INSERT INTO bot_admin_sessions (platform, chat_id, admin_id, role_type, created_at_jalali) VALUES (?, ?, ?, 'admin', ?)", [$platform, $chatId, $admin['id'], jalali_now()]);
        DB::execute("DELETE FROM `$stateTable` WHERE `$chatCol`=?", [$chatId]);
        $url = bot_create_login_url('admin', $admin['id']);
        $msg = "✅ ورود مدیر تأیید شد.\nبرای دسترسی کامل به تمام امکانات پنل مدیریت از لینک امن زیر استفاده کنید (اعتبار ۱۵ دقیقه):\n{$url}\n\nمیانبرها: دانش‌آموزان، دبیران، کلاس‌ها، برنامه هفتگی، اعلان‌ها، ربات‌ها و تنظیمات همگی از همین پنل در دسترس‌اند.";
        bot_webhook_safe_send($platform, $chatId, $msg, bot_main_keyboard($platform));
    } else bot_webhook_safe_send($platform, $chatId, 'نام کاربری یا رمز مدیر نادرست است.');
    http_response_code(200); exit;
}
if ($step === 'teacher_username') {
    DB::execute("REPLACE INTO `$stateTable` (`$chatCol`, step, temp_nid, temp_payload) VALUES (?, 'teacher_password', ?, NULL)", [$chatId, preg_replace('/\D+/', '', tr_num($text, 'en'))]);
    bot_webhook_safe_send($platform, $chatId, 'رمز عبور دبیر را ارسال کنید:');
    http_response_code(200); exit;
}
if ($step === 'teacher_password') {
    $nid = $state['temp_nid'] ?? '';
    $teacher = DB::fetch("SELECT * FROM teachers WHERE national_id=? AND status=1", [$nid]);
    if ($teacher && (password_verify($text, $teacher['password']) || hash_equals((string)$teacher['password'], $text) || hash_equals((string)$teacher['personnel_code'], $text))) {
        DB::execute("INSERT INTO bot_admin_sessions (platform, chat_id, teacher_id, role_type, created_at_jalali) VALUES (?, ?, ?, 'teacher', ?)", [$platform, $chatId, $teacher['id'], jalali_now()]);
        DB::execute("DELETE FROM `$stateTable` WHERE `$chatCol`=?", [$chatId]);
        $url = bot_create_login_url('teacher', $teacher['id']);
        $tFam = function_exists('teacher_respectful_name') ? teacher_respectful_name($teacher['full_name']) : $teacher['full_name'];
        $msg = "✅ ورود دبیر تأیید شد: {$tFam}\nلینک امن پنل کامل دبیران (ثبت/ویرایش نمرات و اعتراضات):\n{$url}";
        if (!empty($teacher['is_deputy'])) $msg .= "\n🛡️ نقش معاونت شما فعال است؛ پس از ورود، پنل معاونت نیز در دسترس است.";
        if (!empty($teacher['is_counselor'])) $msg .= "\n🧭 نقش مشاور شما فعال است؛ پنل مشاوره نیز در دسترس است.";
        bot_webhook_safe_send($platform, $chatId, $msg, bot_main_keyboard($platform));
    } else bot_webhook_safe_send($platform, $chatId, 'کد ملی یا رمز دبیر نادرست است.');
    http_response_code(200); exit;
}

if (strpos($step, 'counsel_') === 0) {
    $payload = json_decode($state['temp_payload'] ?? '[]', true) ?: [];
    $next = null; $ask = '';
    if ($step === 'counsel_name') { $payload['requester_name']=$text; $next='counsel_phone'; $ask='شماره تماس خود را ارسال کنید:'; }
    elseif ($step === 'counsel_phone') { $payload['requester_phone']=tr_num($text,'en'); $next='counsel_student'; $ask='نام دانش‌آموز و کلاس (در صورت وجود) را ارسال کنید:'; }
    elseif ($step === 'counsel_student') { $payload['student_name']=$text; $next='counsel_topic'; $ask='موضوع درخواست مشاوره را کوتاه بنویسید:'; }
    elseif ($step === 'counsel_topic') { $payload['topic']=$text; $next='counsel_desc'; $ask='شرح کامل درخواست را ارسال کنید:'; }
    elseif ($step === 'counsel_desc') {
        $payload['description']=$text;
        DB::execute("INSERT INTO counseling_requests (platform, chat_id, requester_name, requester_phone, student_name, topic, description, created_at_jalali) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", [$platform,$chatId,$payload['requester_name']??'', $payload['requester_phone']??'', $payload['student_name']??'', $payload['topic']??'مشاوره', $payload['description']??'', jalali_now()]);
        $rid=DB::lastInsertId();
        notify_counselors_new_request($rid, ($payload['topic']??'') . ' - ' . ($payload['requester_name']??''));
        DB::execute("DELETE FROM `$stateTable` WHERE `$chatCol`=?", [$chatId]);
        bot_webhook_safe_send($platform,$chatId,'✅ درخواست مشاوره شما ثبت شد و به مشاور مدرسه اطلاع داده شد.',bot_main_keyboard($platform));
        http_response_code(200); exit;
    }
    DB::execute("REPLACE INTO `$stateTable` (`$chatCol`, step, temp_nid, temp_payload) VALUES (?, ?, NULL, ?)", [$chatId, $next, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    bot_webhook_safe_send($platform, $chatId, $ask);
    http_response_code(200); exit;
}

if ($step === 'awaiting_nid') {
    $nid = preg_replace('/\D+/', '', tr_num($text, 'en'));
    if (!preg_match('/^\d{10}$/', $nid)) {
        bot_webhook_safe_send($platform, $chatId, bot_text($platform, 'invalid_nid'));
        http_response_code(200);
        exit;
    }
    /* v4.87.0: role detection by national id. When the id belongs to a
       TEACHER (any staff role), switch to the staff flow: ask for the
       personnel code instead of the student serial. Teacher id wins over a
       student with the same id (rare data-entry collision). */
    $staffMatch = DB::fetch("SELECT * FROM teachers WHERE national_id=? AND status=1", [$nid]);
    if ($staffMatch) {
        DB::execute("REPLACE INTO `$stateTable` (`$chatCol`, step, temp_nid) VALUES (?, 'staff_personnel_code', ?)", [$chatId, $nid]);
        $roleNames = [];
        foreach (bot_teacher_roles($staffMatch) as $rl) $roleNames[] = bot_role_labels()[$rl] ?? $rl;
        $tFamD = function_exists('teacher_respectful_name') ? teacher_respectful_name($staffMatch['full_name']) : $staffMatch['full_name'];
        bot_webhook_safe_send($platform, $chatId, "👨‍🏫 {$tFamD} عزیز، شما به عنوان «" . implode('، ', $roleNames) . "» شناسایی شدید.\n\n🔑 برای ورود، کد پرسنلی خود را ارسال کنید:");
        http_response_code(200);
        exit;
    }
    // Use latest academic year for student who exists in multiple years (7th,8th,9th)
    $student = get_latest_student_by_national_id($nid);
    if (!$student) {
        bot_webhook_safe_send($platform, $chatId, bot_text($platform, 'student_not_found'));
        http_response_code(200);
        exit;
    }
    DB::execute("REPLACE INTO `$stateTable` (`$chatCol`, step, temp_nid) VALUES (?, 'awaiting_serial', ?)", [$chatId, $nid]);
    bot_webhook_safe_send($platform, $chatId, bot_text($platform, 'ask_serial', ['student_name' => trim($student['first_name'] . ' ' . $student['last_name'])]));
    http_response_code(200);
    exit;
}

/* v4.87.0: staff login — personnel code check, then the role panel. */
if ($step === 'staff_personnel_code') {
    $code = trim(tr_num($text, 'en'));
    $nid = $state['temp_nid'] ?? '';
    $teacher = DB::fetch("SELECT * FROM teachers WHERE national_id=? AND status=1", [$nid]);
    $okStaff = false;
    if ($teacher) {
        $okStaff = hash_equals((string)$teacher['personnel_code'], $code)
            || (!empty($teacher['password']) && password_verify($code, $teacher['password']))
            || hash_equals((string)$teacher['password'], $code);
    }
    if ($okStaff) {
        DB::execute("UPDATE bot_admin_sessions SET is_active=0 WHERE platform=? AND chat_id=? AND role_type='teacher'", [$platform, $chatId]);
        DB::execute("INSERT INTO bot_admin_sessions (platform, chat_id, teacher_id, role_type, created_at_jalali) VALUES (?, ?, ?, 'teacher', ?)", [$platform, $chatId, $teacher['id'], jalali_now()]);
        DB::execute("DELETE FROM `$stateTable` WHERE `$chatCol`=?", [$chatId]);
        $roleNames = [];
        foreach (bot_teacher_roles($teacher) as $rl) $roleNames[] = bot_role_labels()[$rl] ?? $rl;
        $tFamL = function_exists('teacher_respectful_name') ? teacher_respectful_name($teacher['full_name']) : $teacher['full_name'];
        $msgL = "✅ ورود تأیید شد: {$tFamL}\n"
              . "🎖 نقش‌های فعال: " . implode('، ', $roleNames) . "\n\n"
              . "از دکمه‌های پایین استفاده کنید. اعلان‌های مربوط به نقش‌های شما از این پس در همین گفتگو ارسال می‌شود.";
        bot_webhook_safe_send($platform, $chatId, $msgL, bot_staff_keyboard($teacher));
    } else {
        bot_webhook_safe_send($platform, $chatId, '❌ کد پرسنلی نادرست است. دوباره ارسال کنید یا /start را بزنید.');
    }
    http_response_code(200);
    exit;
}

/* v4.87.0: objection reply — text step. */
if ($step === 'objection_reply_text') {
    $msgId = (int)($state['temp_nid'] ?? 0);
    $staffR = bot_staff_session($platform, $chatId);
    $gmR = DB::fetch("SELECT * FROM grade_messages WHERE id=?", [$msgId]);
    if (!$staffR || !$gmR || (int)$gmR['teacher_id'] !== (int)$staffR['teacher_id']) {
        DB::execute("DELETE FROM `$stateTable` WHERE `$chatCol`=?", [$chatId]);
        bot_webhook_safe_send($platform, $chatId, 'جلسه پاسخ‌دهی معتبر نیست. دوباره از دکمه «پاسخ به اعتراض» شروع کنید.');
        http_response_code(200); exit;
    }
    DB::execute("UPDATE grade_messages SET reply=? WHERE id=?", [trim($text), $msgId]);
    DB::execute("REPLACE INTO `$stateTable` (`$chatCol`, step, temp_nid, temp_payload) VALUES (?, 'objection_grade_choice', ?, NULL)", [$chatId, (string)$msgId]);
    $kbG = ['inline_keyboard' => [[
        ['text' => '🔢 تغییر نمره', 'callback_data' => 'objgrade_edit_' . $msgId],
        ['text' => '✅ نمره بدون تغییر', 'callback_data' => 'objgrade_keep_' . $msgId],
    ]]];
    bot_webhook_safe_send($platform, $chatId, "متن پاسخ ثبت شد. ✅\n\nآیا نمره این درس هم تغییر کند؟", $kbG);
    http_response_code(200); exit;
}

/* v4.87.0: objection reply — new grade step (respects admin grade-edit rules). */
if ($step === 'objection_new_grade') {
    $msgId = (int)($state['temp_nid'] ?? 0);
    $staffG = bot_staff_session($platform, $chatId);
    $gmG = DB::fetch("SELECT gm.*, r.academic_year, r.report_month, r.class_name FROM grade_messages gm JOIN reports r ON r.id=gm.report_id WHERE gm.id=?", [$msgId]);
    if (!$staffG || !$gmG || (int)$gmG['teacher_id'] !== (int)$staffG['teacher_id']) {
        DB::execute("DELETE FROM `$stateTable` WHERE `$chatCol`=?", [$chatId]);
        bot_webhook_safe_send($platform, $chatId, 'جلسه پاسخ‌دهی معتبر نیست. دوباره شروع کنید.');
        http_response_code(200); exit;
    }
    $scoreTxt = str_replace(['/', '٫'], '.', tr_num(trim($text), 'en'));
    if (!is_numeric($scoreTxt) || (float)$scoreTxt < 0 || (float)$scoreTxt > 21) {
        bot_webhook_safe_send($platform, $chatId, '⚠️ نمره نامعتبر است. عددی بین 0 تا 20 (یا 21 برای بدون‌نمره) ارسال کنید:');
        http_response_code(200); exit;
    }
    // The change must honour the SAME permission rules the admin sets for the
    // teacher panel (month/class/subject grade-edit locks).
    require_once __DIR__ . '/grade_permissions.php';
    if (!can_teacher_enter_grade((int)$staffG['teacher_id'], $gmG['academic_year'], $gmG['report_month'], $gmG['class_name'], $gmG['subject_name'])) {
        DB::execute("DELETE FROM `$stateTable` WHERE `$chatCol`=?", [$chatId]);
        bot_webhook_safe_send($platform, $chatId, "⛔ مدیریت، ویرایش نمره این درس/کلاس را در این ماه برای شما غیرفعال کرده است.\nپاسخ متنی شما ثبت و برای دانش‌آموز ارسال می‌شود؛ نمره بدون تغییر ماند.", bot_staff_keyboard($staffG));
        bot_role_finish_objection($platform, $chatId, $msgId, null, $staffG);
        http_response_code(200); exit;
    }
    DB::execute("DELETE FROM `$stateTable` WHERE `$chatCol`=?", [$chatId]);
    bot_role_finish_objection($platform, $chatId, $msgId, (float)$scoreTxt, $staffG);
    http_response_code(200); exit;
}

if ($step === 'awaiting_serial') {
    $serial = preg_replace('/\D+/', '', tr_num($text, 'en'));
    $nid = $state['temp_nid'] ?? '';
    // Always use latest year for student in multiple academic years
    $student = get_latest_student_by_national_id($nid);
    $valid = false;
    if ($student) {
        $plainStored = (string)($student['serial_number'] ?: $student['password']);
        $valid = hash_equals((string)$student['serial_number'], $serial)
            || (!empty($student['password']) && password_verify($serial, $student['password']))
            || hash_equals($plainStored, $serial);
    }
    if ($valid) {
        // Link to latest student id for this national_id (newest academic year)
        $latestForLink = get_latest_student_by_national_id($nid);
        $linkId = $latestForLink ? $latestForLink['id'] : $student['id'];
        bot_link_student($platform, $chatId, $username, $linkId);
        DB::execute("DELETE FROM `$stateTable` WHERE `$chatCol` = ?", [$chatId]);
        bot_webhook_safe_send($platform, $chatId, bot_text($platform, 'link_success', ['student_name' => trim($student['first_name'] . ' ' . $student['last_name'])]), bot_main_keyboard($platform));
        http_response_code(200);
        exit;
    }
    bot_webhook_safe_send($platform, $chatId, bot_text($platform, 'invalid_serial'));
    http_response_code(200);
    exit;
}

bot_webhook_safe_send($platform, $chatId, bot_text($platform, 'fallback'), bot_main_keyboard($platform));
http_response_code(200);
