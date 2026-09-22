<?php
// File: includes/school_roles.php
/**
 * Deputy/counselor/discipline/counseling shared helpers.
 * تمام تاریخ‌های نمایشی در این ماژول شمسی هستند.
 */
require_once __DIR__ . '/functions.php';

if (!function_exists('ensure_school_roles_schema')) {
    function ensure_school_roles_schema() {
        try { DB::execute("ALTER TABLE teachers ADD COLUMN is_deputy tinyint(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE teachers ADD COLUMN is_counselor tinyint(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE teachers ADD COLUMN is_executive tinyint(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
        /* v4.90.0: فیلد نام و نام خانوادگی مجزا برای دبیران تا در پیام‌های ربات
           و همه‌جا نام خانوادگی به صورت قطعی (نه حدس از روی رشته) در دسترس باشد. */
        try { DB::execute("ALTER TABLE teachers ADD COLUMN first_name varchar(120) DEFAULT ''"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE teachers ADD COLUMN last_name varchar(150) DEFAULT ''"); } catch (Exception $e) {}
        /* backfill یک‌باره: در داده‌های ورودی از فایل پرسنلی، full_name به صورت
           «نام‌خانوادگی نام» است؛ پس توکن اول = نام خانوادگی، بقیه = نام.
           فقط رکوردهایی که last_name خالی دارند پر می‌شوند (idempotent) و مدیر
           می‌تواند در فرم دبیران هر مورد را اصلاح کند. */
        try {
            $needFill = DB::fetchAll("SELECT id, full_name FROM teachers WHERE COALESCE(last_name,'')=''");
            foreach ($needFill as $tRow) {
                $fn = trim((string)$tRow['full_name']);
                if ($fn === '') continue;
                $prefixes = ['استاد', 'آقای', 'خانم', 'دکتر', 'مهندس', 'حاج', 'سید', 'سیده'];
                $toks = preg_split('/\s+/u', $fn);
                while (!empty($toks) && in_array($toks[0], $prefixes, true)) array_shift($toks);
                if (empty($toks)) continue;
                $last = array_shift($toks);           // توکن اول = نام خانوادگی (قالب فایل پرسنلی)
                $first = trim(implode(' ', $toks));   // بقیه = نام
                DB::execute("UPDATE teachers SET last_name=?, first_name=? WHERE id=?", [$last, $first, (int)$tRow['id']]);
            }
        } catch (Exception $e) {}

        DB::execute("CREATE TABLE IF NOT EXISTS discipline_titles (
            id int(11) NOT NULL AUTO_INCREMENT,
            title varchar(200) NOT NULL,
            status tinyint(1) NOT NULL DEFAULT 1,
            created_at_jalali varchar(30) NOT NULL,
            PRIMARY KEY (id), KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        DB::execute("CREATE TABLE IF NOT EXISTS student_discipline_records (
            id int(11) NOT NULL AUTO_INCREMENT,
            student_id int(11) NOT NULL,
            title_id int(11) DEFAULT NULL,
            title_text varchar(250) NOT NULL,
            internal_note text DEFAULT NULL,
            occurred_at_jalali varchar(30) NOT NULL,
            notify_parents tinyint(1) NOT NULL DEFAULT 0,
            is_justified tinyint(1) NOT NULL DEFAULT 0,
            review_status varchar(50) DEFAULT 'pending',
            review_note text DEFAULT NULL,
            created_by_teacher_id int(11) DEFAULT NULL,
            created_at_jalali varchar(30) NOT NULL,
            updated_at_jalali varchar(30) DEFAULT NULL,
            PRIMARY KEY (id), KEY idx_student (student_id), KEY idx_title (title_id), KEY idx_justified (is_justified), KEY idx_review (review_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        try { DB::execute("ALTER TABLE student_discipline_records ADD COLUMN is_justified tinyint(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE student_discipline_records ADD COLUMN review_status varchar(50) DEFAULT 'pending'"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE student_discipline_records ADD COLUMN review_note text DEFAULT NULL"); } catch (Exception $e) {}

        DB::execute("CREATE TABLE IF NOT EXISTS counseling_requests (
            id int(11) NOT NULL AUTO_INCREMENT,
            platform enum('site','bale','telegram') NOT NULL DEFAULT 'site',
            chat_id varchar(80) DEFAULT NULL,
            student_id int(11) DEFAULT NULL,
            requester_name varchar(150) NOT NULL,
            requester_phone varchar(30) DEFAULT NULL,
            student_name varchar(150) DEFAULT NULL,
            class_name varchar(100) DEFAULT NULL,
            topic varchar(250) NOT NULL,
            description text NOT NULL,
            status enum('new','in_progress','replied','closed') NOT NULL DEFAULT 'new',
            counselor_id int(11) DEFAULT NULL,
            counselor_reply text DEFAULT NULL,
            created_at_jalali varchar(30) NOT NULL,
            replied_at_jalali varchar(30) DEFAULT NULL,
            PRIMARY KEY (id), KEY idx_status (status), KEY idx_student (student_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        DB::execute("CREATE TABLE IF NOT EXISTS bot_admin_sessions (
            id int(11) NOT NULL AUTO_INCREMENT,
            platform enum('bale','telegram') NOT NULL,
            chat_id varchar(80) NOT NULL,
            admin_id int(11) DEFAULT NULL,
            teacher_id int(11) DEFAULT NULL,
            role_type enum('admin','teacher') NOT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at_jalali varchar(30) NOT NULL,
            PRIMARY KEY (id), KEY idx_chat (platform, chat_id, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        DB::execute("CREATE TABLE IF NOT EXISTS bot_login_tokens (
            id int(11) NOT NULL AUTO_INCREMENT,
            token varchar(80) NOT NULL,
            target_type enum('admin','teacher','student') NOT NULL,
            target_id int(11) NOT NULL,
            expires_at int(11) NOT NULL,
            used_at_jalali varchar(30) DEFAULT NULL,
            created_at_jalali varchar(30) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uniq_token (token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('jalali_now')) {
    function jalali_now() { return jdate('Y/m/d H:i'); }
}

if (!function_exists('teacher_has_deputy')) {
    function teacher_has_deputy($teacherId) {
        ensure_school_roles_schema();
        $r = DB::fetch("SELECT is_deputy FROM teachers WHERE id=?", [(int)$teacherId]);
        return $r && (int)$r['is_deputy'] === 1;
    }
}

if (!function_exists('teacher_has_counselor')) {
    function teacher_has_counselor($teacherId) {
        ensure_school_roles_schema();
        $r = DB::fetch("SELECT is_counselor FROM teachers WHERE id=?", [(int)$teacherId]);
        return $r && (int)$r['is_counselor'] === 1;
    }
}

if (!function_exists('teacher_has_executive')) {
    function teacher_has_executive($teacherId) {
        ensure_school_roles_schema();
        $r = DB::fetch("SELECT is_executive FROM teachers WHERE id=?", [(int)$teacherId]);
        return $r && (int)$r['is_executive'] === 1;
    }
}

if (!function_exists('current_teacher_is_executive')) {
    function current_teacher_is_executive() {
        return !empty($_SESSION['teacher_id']) && teacher_has_executive((int)$_SESSION['teacher_id']);
    }
}

if (!function_exists('current_teacher_has_student_file_access')) {
    function current_teacher_has_student_file_access() {
        ensure_school_roles_schema();
        if (empty($_SESSION['teacher_id'])) return false;
        return teacher_has_deputy((int)$_SESSION['teacher_id']) || teacher_has_counselor((int)$_SESSION['teacher_id']) || teacher_has_executive((int)$_SESSION['teacher_id']);
    }
}

if (!function_exists('require_student_file_staff_access')) {
    function require_student_file_staff_access() {
        if (!current_teacher_has_student_file_access()) {
            set_flash_message('error', 'برای مشاهده پرونده دانش‌آموز باید نقش معاون/ناظم یا مشاور فعال داشته باشید.');
            redirect('teacher-panel.php');
        }
    }
}

if (!function_exists('bot_create_login_url')) {
    function bot_create_login_url($targetType, $targetId) {
        ensure_school_roles_schema();
        $token = bin2hex(random_bytes(24));
        DB::execute("INSERT INTO bot_login_tokens (token, target_type, target_id, expires_at, created_at_jalali) VALUES (?, ?, ?, ?, ?)", [$token, $targetType, (int)$targetId, time() + 900, jalali_now()]);
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '')), '/');
        if ($base === '.') $base = '';
        return $protocol . $host . $base . '/bot-login.php?token=' . urlencode($token);
    }
}

if (!function_exists('notify_student_discipline_bots')) {
    function notify_student_discipline_bots($studentId, $title, $occurredAt, $recordId = null) {
        require_once __DIR__ . '/bot_helpers.php';
        foreach (['bale','telegram'] as $platform) {
            try {
                ensure_bot_schema($platform);
                $table = bot_user_table($platform);
                $chatCol = $platform === 'telegram' ? 'telegram_chat_id' : 'bale_chat_id';
                $rows = DB::fetchAll("SELECT DISTINCT `$chatCol` AS chat_id FROM `$table` WHERE student_id=?", [(int)$studentId]);
                foreach ($rows as $r) {
                    $kb = $recordId ? ['inline_keyboard' => [[['text' => '✅ بررسی شد', 'callback_data' => 'ack_disc_' . (int)$recordId]]]] : null;
                    bot_send_message($platform, $r['chat_id'], "⚠️ مورد انضباطی جدید\nعنوان: {$title}\nزمان: " . tr_num($occurredAt, 'fa'), $kb);
                }
            } catch (Exception $e) { error_log('discipline notify failed: '.$e->getMessage()); }
        }
    }
}

if (!function_exists('notify_counselors_new_request')) {
    function notify_counselors_new_request($requestId, $summary) {
        require_once __DIR__ . '/bot_helpers.php';
        $counselors = DB::fetchAll("SELECT id, full_name FROM teachers WHERE is_counselor=1 AND status=1");
        foreach ($counselors as $c) {
            foreach (['bale','telegram'] as $platform) {
                try {
                    ensure_bot_schema($platform);
                    $sessions = DB::fetchAll("SELECT chat_id FROM bot_admin_sessions WHERE platform=? AND teacher_id=? AND role_type='teacher' AND is_active=1", [$platform, $c['id']]);
                    foreach ($sessions as $s) bot_send_message($platform, $s['chat_id'], "🧭 درخواست مشاوره جدید #{$requestId}\n{$summary}");
                } catch (Exception $e) {}
            }
        }
    }
}

if (!function_exists('discipline_titles_ranked')) {
    /**
     * v4.75.0: active discipline titles, most-used first.
     * Fully transparent to the user: the picker simply shows the titles the
     * school actually records most often at the top. Ranking blends overall
     * usage with recent usage (last 300 records count double), so seasonal
     * patterns float up automatically. Falls back to alphabetical order.
     */
    function discipline_titles_ranked() {
        $titles = DB::fetchAll("SELECT * FROM discipline_titles WHERE status=1");
        if (!$titles) return [];
        $score = [];   // by title_id
        $scoreTx = []; // by normalized title text (records saved without title_id)
        $norm = function ($t) { return preg_replace('/\s+/u', ' ', trim(mb_strtolower((string)$t))); };
        try {
            foreach (DB::fetchAll("SELECT title_id, title_text, COUNT(*) c FROM student_discipline_records GROUP BY title_id, title_text") as $u) {
                if (!empty($u['title_id'])) $score[(int)$u['title_id']] = ($score[(int)$u['title_id']] ?? 0) + (int)$u['c'];
                $k = $norm($u['title_text']); if ($k !== '') $scoreTx[$k] = ($scoreTx[$k] ?? 0) + (int)$u['c'];
            }
            foreach (DB::fetchAll("SELECT title_id, title_text, COUNT(*) c FROM (SELECT title_id, title_text FROM student_discipline_records ORDER BY id DESC LIMIT 300) t GROUP BY title_id, title_text") as $u) {
                if (!empty($u['title_id'])) $score[(int)$u['title_id']] = ($score[(int)$u['title_id']] ?? 0) + 2 * (int)$u['c'];
                $k = $norm($u['title_text']); if ($k !== '') $scoreTx[$k] = ($scoreTx[$k] ?? 0) + 2 * (int)$u['c'];
            }
        } catch (Exception $e) { /* ranking is best-effort; alphabetical fallback below */ }
        foreach ($titles as &$t) {
            $s = $score[(int)$t['id']] ?? 0;
            $k = $norm($t['title']);
            if ($s === 0 && $k !== '' && isset($scoreTx[$k])) $s = $scoreTx[$k];
            $t['_rank'] = $s;
        }
        unset($t);
        usort($titles, function ($a, $b) {
            if ($a['_rank'] !== $b['_rank']) return $b['_rank'] <=> $a['_rank'];
            return persian_compare((string)$a["title"], (string)$b["title"]);
        });
        return $titles;
    }
}
