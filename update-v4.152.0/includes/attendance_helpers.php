<?php
// File: includes/attendance_helpers.php  (v4.51.0)
/**
 * Smart QR attendance core:
 *  - per-student permanent QR tags (student_qr_tags)
 *  - scan processing with time rules:
 *      arrival <= att_present_until (default 08:40)  => present
 *      arrival  > att_present_until                  => late (minutes counted)
 *      no scan by att_absent_at (default 09:00)      => auto absent
 *  - auto-absent finalizer (idempotent per day)
 *  - parent notifications through Bale/Telegram bots
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/bot_helpers.php';

/* ------------------------------------------------------------------
 * v4.56.0: deferred notification queue.
 * When ATT_DEFER_NOTIFY is defined (scan API), parent notifications
 * (Bale/Telegram HTTP calls, up to ~20s each) are queued and executed
 * AFTER the JSON response has been flushed to the scanner, so the
 * phone never times out waiting ("خطای شبکه").
 * ------------------------------------------------------------------ */
if (!function_exists('att_defer')) {
    function att_defer($fn) {
        if (defined('ATT_DEFER_NOTIFY') && ATT_DEFER_NOTIFY) {
            $GLOBALS['att_deferred'][] = $fn;
            return true;   // queued
        }
        try { $fn(); } catch (Throwable $e) { error_log('att notify failed: ' . $e->getMessage()); }
        return false;      // executed inline
    }
    function att_run_deferred() {
        $q = $GLOBALS['att_deferred'] ?? [];
        $GLOBALS['att_deferred'] = [];
        foreach ($q as $fn) {
            try { $fn(); } catch (Throwable $e) { error_log('att deferred notify failed: ' . $e->getMessage()); }
        }
    }
}

if (!function_exists('att_norm')) {
    /**
     * v4.75.0 CRITICAL accuracy fix: dates/times must be stored in ONE digit
     * system. jdate() emits Persian digits by default while typed input and
     * some code paths used English digits — the same day could exist twice
     * («۱۴۰۵/۰۶/۱۱» and «1405/06/11»), breaking the daily-duplicate guard and
     * the auto-absent check (double absence alerts to parents!).
     * All writes/comparisons now normalize to English digits; display keeps
     * converting to Persian via tr_num(...,'fa') as before.
     */
    function att_norm($s) { return tr_num(trim((string)$s), 'en'); }
    // Both digit representations of the same date (for backward-compatible lookups).
    function att_day_forms($day) {
        $en = att_norm($day);
        $fa = tr_num($en, 'fa');
        return $en === $fa ? [$en] : [$en, $fa];
    }
}

if (!function_exists('att_year_sql')) {
    /**
     * v4.76.0: the WHOLE attendance mechanism operates ONLY on students of
     * the system's default academic year. Returns [sqlFragment, params] to
     * append to any query that selects from `students <alias>`.
     * Uses the exact same year-matching logic as students.php/deputy-panel
     * (direct year match, or legacy NULL-year students attached to the year
     * through their reports/classes/schedules).
     */
    function att_year_sql($alias = 's') {
        $y = get_setting('current_academic_year', '');
        if (function_exists('unify_academic_year')) { $u = unify_academic_year($y); if ($u !== '') $y = $u; }
        $y = trim((string)$y);
        if ($y === '') return ['1=1', []];
        $a = $alias;
        $sql = "($a.academic_year=? OR (($a.academic_year IS NULL OR $a.academic_year='') AND ("
             . "EXISTS(SELECT 1 FROM reports atyr WHERE atyr.student_id=$a.id AND atyr.academic_year=?)"
             . " OR EXISTS(SELECT 1 FROM class_schedules atycs WHERE atycs.academic_year=? AND atycs.class_name=$a.class_name)"
             . " OR EXISTS(SELECT 1 FROM classes atyc WHERE atyc.academic_year=? AND atyc.name=$a.class_name))))";
        return [$sql, [$y, $y, $y, $y]];
    }
}

if (!function_exists('att_day_name')) {
    /**
     * v4.76.0: Persian weekday name («شنبه» … «جمعه») of a stored Jalali
     * date (Y/m/d, either digit system). Used when filing attendance into
     * the discipline dossier so the record carries both the day AND date.
     */
    function att_day_name($jalaliDate) {
        $p = explode('/', att_norm($jalaliDate));
        if (count($p) !== 3) return '';
        try {
            $g = jalali_to_gregorian((int)$p[0], (int)$p[1], (int)$p[2]);
            $ts = mktime(12, 0, 0, (int)$g[1], (int)$g[2], (int)$g[0]);
            return $ts ? jdate('l', $ts) : '';
        } catch (Exception $e) { return ''; }
    }
}

if (!function_exists('att_discipline_title_id')) {
    /**
     * v4.76.0: filing attendance into the discipline dossier must use the
     * school's OFFICIAL saved titles («غیبت» / «تأخیر در ورود به مدرسه») so
     * the filed records look exactly like ones registered by hand in
     * student management and the deputy panel. Finds the active title —
     * creating it once if the school never defined it — and returns its id.
     */
    function att_discipline_title_id($title) {
        try {
            $row = DB::fetch("SELECT id FROM discipline_titles WHERE title=? AND status=1", [$title]);
            if ($row) return (int)$row['id'];
            DB::execute("INSERT INTO discipline_titles (title, status, created_at_jalali) VALUES (?, 1, ?)", [$title, jalali_now()]);
            return (int)DB::lastInsertId();
        } catch (Exception $e) { return 0; }
    }
}

if (!function_exists('ensure_attendance_schema_v2')) {
    function ensure_attendance_schema_v2() {
        ensure_attendance_schema();
        try { DB::execute("ALTER TABLE student_attendance MODIFY status enum('present','absent','late') NOT NULL DEFAULT 'present'"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE student_attendance ADD COLUMN scan_time varchar(10) DEFAULT NULL"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE student_attendance ADD COLUMN source enum('manual','qr','auto') NOT NULL DEFAULT 'manual'"); } catch (Exception $e) {}
        DB::execute("CREATE TABLE IF NOT EXISTS student_qr_tags (
            id int(11) NOT NULL AUTO_INCREMENT,
            student_id int(11) NOT NULL,
            token varchar(64) NOT NULL,
            created_at_jalali varchar(30) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_student (student_id),
            UNIQUE KEY uniq_token (token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('att_setting_times')) {
    function att_setting_times() {
        return [
            'present_until' => get_setting('att_present_until', '08:40'),
            'absent_at' => get_setting('att_absent_at', '09:00'),
        ];
    }
}

if (!function_exists('att_scanner_key')) {
    function att_scanner_key($regenerate = false) {
        $key = get_setting('attendance_scanner_key', '');
        if ($key === '' || $regenerate) { $key = bin2hex(random_bytes(10)); set_setting('attendance_scanner_key', $key); }
        return $key;
    }
}

if (!function_exists('att_time_to_minutes')) {
    function att_time_to_minutes($hhmm) {
        $p = explode(':', tr_num(trim((string)$hhmm), 'en'));
        return ((int)($p[0] ?? 0)) * 60 + (int)($p[1] ?? 0);
    }
}

if (!function_exists('jalali_now')) {
    // v4.57.0 fix: jalali_now() is defined in includes/exams_helper.php,
    // which the scan API never loads — scanning then crashed with
    // "Call to undefined function jalali_now()". Same format as the original.
    function jalali_now() { return jdate('Y/m/d H:i'); }
}

if (!function_exists('att_now_time')) {
    // Tehran local wall-clock (jdf uses Asia/Tehran).
    // v4.75.0: second-level precision — parents get the exact arrival time.
    // att_time_to_minutes() ignores the seconds part, so all time-rule
    // comparisons (present/late cutoffs) behave exactly as before.
    // English digits (att_norm) so stored values compare consistently.
    function att_now_time() { return att_norm(jdate('H:i:s')); }
}

if (!function_exists('att_today')) {
    // Canonical (English-digit) Jalali date used for ALL attendance storage.
    function att_today() { return att_norm(jdate('Y/m/d')); }
}

if (!function_exists('att_get_or_create_tag')) {
    function att_get_or_create_tag($studentId) {
        ensure_attendance_schema_v2();
        $row = DB::fetch("SELECT * FROM student_qr_tags WHERE student_id=?", [(int)$studentId]);
        if ($row) return $row;
        $token = bin2hex(random_bytes(12));
        DB::execute("INSERT INTO student_qr_tags (student_id, token, created_at_jalali) VALUES (?, ?, ?)", [(int)$studentId, $token, jalali_now()]);
        return DB::fetch("SELECT * FROM student_qr_tags WHERE student_id=?", [(int)$studentId]);
    }
}

if (!function_exists('att_regenerate_tag')) {
    function att_regenerate_tag($studentId) {
        ensure_attendance_schema_v2();
        $token = bin2hex(random_bytes(12));
        DB::execute("INSERT INTO student_qr_tags (student_id, token, created_at_jalali) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE token=VALUES(token), created_at_jalali=VALUES(created_at_jalali)", [(int)$studentId, $token, jalali_now()]);
        return $token;
    }
}

if (!function_exists('att_qr_payload')) {
    // The exact string encoded inside each student's QR tag.
    function att_qr_payload($studentId, $token) { return 'MTAG-ATT:' . (int)$studentId . ':' . $token; }
}

/* ------------------------------------------------------------------
 * v4.152.0 — «تگ آزمایشی» (برگهٔ آزمون سامانه)
 *
 * دو تگ آزمایشی («حضور به موقع» و «تأخیر») چاپ می‌شوند تا مدرسه بتواند
 * کل زنجیره را با یک اسکن واقعی بسنجد: اسکنر → API اسکن → پردازش →
 * اطلاع‌رسانی. هیچ رکوردی در student_attendance نوشته نمی‌شود و پیام
 * آزمایشی فقط به حساب‌های مدیریتی متصل به ربات (بله و تلگرام) می‌رود.
 * ------------------------------------------------------------------ */

if (!function_exists('att_test_tag_kinds')) {
    /** دو وضعیت شبیه‌سازی‌شدهٔ برگهٔ آزمایشی: کلید → برچسب و وضعیت واقعی */
    function att_test_tag_kinds() {
        return [
            'present' => ['label' => 'حضور به موقع', 'status' => 'present'],
            'late'    => ['label' => 'تأخیر',        'status' => 'late'],
        ];
    }
}

if (!function_exists('att_test_tag_payload')) {
    /** رشتهٔ داخل QR تگ آزمایشی. این پیش‌شماره هیچ‌گاه با تگ واقعی
        دانش‌آموز (MTAG-ATT:{id}:{token}) اشتباه نمی‌شود و قالب تگ واقعی
        را هم تغییر نمی‌دهد. */
    function att_test_tag_payload($kind) { return 'MTAG-ATT-TEST:' . strtolower((string)$kind); }
}

if (!function_exists('att_test_tag_print_rows')) {
    /** سطرهای برگهٔ چاپی آزمایشی — دقیقاً در همان شکل سطرهای دانش‌آموز
        (شناسه/نام/کلاس/کد ملی/qr) تا قالب چاپ بدون تغییر از آن استفاده کند. */
    function att_test_tag_print_rows() {
        $rows = [];
        foreach (att_test_tag_kinds() as $kind => $meta) {
            $rows[] = [
                'id'          => 0,
                'first_name'  => $meta['label'],
                'last_name'   => '',
                'class_name'  => 'تگ آزمایشی',
                'national_id' => 'بدون ثبت حضور و غیاب',
                'qr'          => att_test_tag_payload($kind),
            ];
        }
        return $rows;
    }
}

if (!function_exists('att_unique_chats')) {
    /** حذف چت تکراری (یک نفر می‌تواند هم معاون باشد هم معاون اجرایی). */
    function att_unique_chats($list) {
        $seen = []; $out = [];
        foreach ((array)$list as $row) {
            $chatId = (string)($row['chat_id'] ?? '');
            if ($chatId === '' || isset($seen[$chatId])) continue;
            $seen[$chatId] = true;
            $out[] = $row;
        }
        return $out;
    }
}

if (!function_exists('att_management_chats')) {
    /**
     * چت حساب‌های «مدیریت» روی یک پیام‌رسان.
     *
     * فقط حسابی که خودِ مدیر از طریق ربات و با نام کاربری و رمز پنل، حسابش را
     * به ربات اضافه کرده است (`bot_admin_sessions.role_type = 'admin'`) پیام
     * می‌گیرد. هیچ نقش دیگری — دبیر، معاون/ناظم، معاون اجرایی، مشاور — پیام
     * آزمایشی نمی‌گیرد، حتی اگر به ربات متصل باشد. اگر روی یک پیام‌رسان هیچ
     * حساب مدیریتی متصل نباشد، فهرست خالی برمی‌گردد و چیزی فرستاده نمی‌شود؛
     * هیچ جانشین و هیچ نقش دومی وجود ندارد.
     */
    function att_management_chats($platform) {
        $platform = bot_valid_platform($platform);
        ensure_bot_schema($platform);
        if (!function_exists('ensure_school_roles_schema')) require_once __DIR__ . '/school_roles.php';
        ensure_school_roles_schema();
        /* بدون DISTINCT و بدون مرتب‌سازی روی ستون بیرون از SELECT: این شکل روی
           هم SQLite و هم MySQL معتبر است؛ تکراری‌ها با att_unique_chats حذف می‌شوند. */
        $rows = DB::fetchAll("SELECT bs.chat_id, a.name AS admin_name, a.status AS admin_status
                                FROM bot_admin_sessions bs
                                LEFT JOIN admins a ON a.id = bs.admin_id
                               WHERE bs.platform = ? AND bs.is_active = 1 AND bs.role_type = 'admin'
                               ORDER BY bs.id DESC", [$platform]);
        $chats = [];
        foreach ($rows as $r) {
            if (isset($r['admin_status']) && (int)$r['admin_status'] !== 1) continue;
            $chats[] = ['chat_id' => (string)$r['chat_id'], 'name' => trim((string)$r['admin_name']), 'role' => 'مدیر'];
        }
        return att_unique_chats($chats);
    }
}

if (!function_exists('att_test_scan_message')) {
    /**
     * متن پیام آزمایشی — همان متنی که در حالت واقعی برای والدین می‌رود، با
     * سرلوحه و پانویس «آزمایشی» تا کسی آن را با اعلان واقعی اشتباه نگیرد.
     * نام پیام‌رسان در متن می‌آید تا معلوم باشد پیام از کدام ربات رسیده است.
     */
    function att_test_scan_message($platform, $kind, $time, $minutesLate = 0) {
        $kinds  = att_test_tag_kinds();
        $meta   = isset($kinds[$kind]) ? $kinds[$kind] : $kinds['present'];
        $isLate = ($meta['status'] === 'late');
        $messenger = ($platform === 'telegram') ? 'تلگرام' : 'بله';
        $msg = '🧪 آزمون سامانهٔ حضور و غیاب — پیام نمونه روی ' . $messenger . "\n"
             . ($isLate ? '⏰ اطلاع‌رسانی تأخیر (نمونهٔ آزمایشی)' : '🔔 اطلاع ورود به مدرسه (نمونهٔ آزمایشی)') . "\n"
             . 'وضعیت آزمایشی: ' . $meta['label'] . "\n";
        if ($isLate) {
            $msg .= 'فرزند شما امروز با ' . tr_num(max(1, (int)$minutesLate), 'fa') . " دقیقه تأخیر در مدرسه حاضر شده است.\n"
                  . 'ساعت دقیق ورود به مدرسه: ' . tr_num($time, 'fa') . "\n";
        } else {
            $msg .= 'ساعت ورود: ' . tr_num($time, 'fa') . "\n";
        }
        $msg .= 'تاریخ: ' . tr_num(att_today(), 'fa') . "\n"
              . 'دانش‌آموز: — (تگ آزمایشی، بدون دانش‌آموز واقعی)' . "\n"
              . 'گیرنده: حساب مدیریت متصل به ربات' . "\n\n"
              . 'این پیام نمونهٔ آزمایشی است و فقط برای حساب مدیریتِ متصل به ربات فرستاده شده؛ هیچ حضور یا غیابی ثبت نشده و هیچ پیامی برای والدین، دبیر یا معاون نرفته است.';
        return $msg;
    }
}

if (!function_exists('att_test_notify_management')) {
    /** ارسال پیام آزمایشی به حساب‌های مدیریتی — هم روی بله و هم روی تلگرام. */
    function att_test_notify_management($kind, $time = '', $minutesLate = 0) {
        $result = ['bale' => 0, 'telegram' => 0, 'missing' => []];
        foreach (['bale', 'telegram'] as $platform) {
            try {
                $chats = att_management_chats($platform);
                if (!$chats) { $result['missing'][] = $platform; continue; }
                $msg = att_test_scan_message($platform, $kind, $time, $minutesLate);
                foreach ($chats as $c) {
                    try {
                        bot_send_message($platform, $c['chat_id'], $msg);
                        bot_log_send($platform, $c['chat_id'], null, 'attendance_test', $msg, 'sent', 'ok');
                        $result[$platform]++;
                    } catch (Exception $e) {
                        bot_log_send($platform, $c['chat_id'], null, 'attendance_test', $msg, 'failed', $e->getMessage());
                    }
                }
            } catch (Exception $e) { error_log('test attendance notify failed (' . $platform . '): ' . $e->getMessage()); }
        }
        /* نتیجهٔ آخرین آزمون ذخیره می‌شود تا صفحهٔ چاپ تگ بتواند نشان دهد پیام
           آزمایشی به چند حساب مدیریتی رسیده است. */
        try {
            set_setting('att_test_last_result', json_encode([
                'kind'     => (string)$kind,
                'time'     => (string)$time,
                'at'       => jalali_now(),
                'bale'     => $result['bale'],
                'telegram' => $result['telegram'],
                'missing'  => $result['missing'],
            ], JSON_UNESCAPED_UNICODE));
        } catch (Exception $e) {}
        return $result;
    }
}

if (!function_exists('att_process_test_scan')) {
    /**
     * یک اسکن «تگ آزمایشی»: همان پاسخ استاندارد اسکنر برمی‌گردد تا کل مسیر
     * (خواندن QR، پاسخ API، نمایش نتیجه روی دستگاه) آزموده شود، ولی هیچ
     * رکورد حضوری نوشته نمی‌شود؛ در عوض پیام آزمایشی مربوط به همان وضعیت
     * برای حساب مدیریت روی بله و تلگرام فرستاده می‌شود.
     */
    function att_process_test_scan($kind) {
        $kinds = att_test_tag_kinds();
        $kind  = strtolower(trim((string)$kind));
        if (!isset($kinds[$kind])) {
            return ['ok' => false, 'code' => 'invalid', 'message' => 'تگ آزمایشی ناشناخته است.'];
        }
        $meta   = $kinds[$kind];
        $isLate = ($meta['status'] === 'late');
        $now    = att_now_time();
        $times  = att_setting_times();
        $minutesLate = $isLate ? max(1, att_time_to_minutes($now) - att_time_to_minutes($times['present_until'])) : 0;
        att_defer(function () use ($kind, $now, $minutesLate) {
            att_test_notify_management($kind, $now, $minutesLate);
        });
        return [
            'ok'      => true,
            'test'    => true,
            'code'    => ($isLate ? 'late' : 'present'),
            'status'  => ($isLate ? ('تأخیر ' . tr_num($minutesLate, 'fa') . ' دقیقه') : 'حضور'),
            'student' => $meta['label'] . ' — تگ آزمایشی',
            'class'   => 'آزمون سامانه — بدون ثبت حضور و غیاب',
            'time'    => tr_num($now, 'fa'),
            'message' => 'تگ آزمایشی خوانده شد؛ پیام آزمایشی برای حساب مدیریت ارسال می‌شود.',
        ];
    }
}

if (!function_exists('att_notify_scan')) {
    // Optional "arrived at school" info push to parents (both platforms).
    function att_notify_scan($studentId, $statusFa, $time) {
        foreach (['bale', 'telegram'] as $platform) {
            try {
                ensure_bot_schema($platform);
                $table = bot_user_table($platform);
                $chatCol = $platform === 'telegram' ? 'telegram_chat_id' : 'bale_chat_id';
                $rows = DB::fetchAll("SELECT DISTINCT b.`$chatCol` AS chat_id, s.first_name, s.last_name FROM `$table` b JOIN students s ON s.id=b.student_id WHERE b.student_id=?", [(int)$studentId]);
                foreach ($rows as $r) {
                    $msg = "🏫 اطلاع ورود به مدرسه\n"
                         . 'دانش‌آموز: ' . trim($r['first_name'] . ' ' . $r['last_name']) . "\n"
                         . 'وضعیت: ' . $statusFa . "\n"
                         . 'ساعت ورود: ' . tr_num($time, 'fa') . "\n"
                         . 'تاریخ: ' . tr_num(jdate('Y/m/d'), 'fa');
                    try {
                        bot_send_message($platform, $r['chat_id'], $msg);
                        bot_log_send($platform, $r['chat_id'], (int)$studentId, 'attendance_scan', $msg, 'sent', 'ok');
                    } catch (Exception $e) {
                        bot_log_send($platform, $r['chat_id'], (int)$studentId, 'attendance_scan', $msg, 'failed', $e->getMessage());
                    }
                }
            } catch (Exception $e) { error_log('scan notify failed: ' . $e->getMessage()); }
        }
    }
}

if (!function_exists('att_process_scan')) {
    /**
     * Handles one QR scan. Returns an array ready for JSON output:
     * [ok, code, status, student, class, time, message]
     * codes: present | late | duplicate | invalid
     */
    function att_process_scan($payload) {
        ensure_attendance_schema_v2();
        $payload = trim((string)$payload);
        /* v4.152.0: «تگ آزمایشی» همان مسیر اسکن را طی می‌کند ولی هیچ
           حضوری ثبت نمی‌کند؛ فقط پیام آزمایشی مدیریت را می‌فرستد. */
        if (preg_match('/^MTAG-ATT-TEST:([a-z]+)$/i', $payload, $tm)) {
            return att_process_test_scan($tm[1]);
        }
        if (!preg_match('/^MTAG-ATT:(\d+):([a-f0-9]{16,64})$/i', $payload, $m)) {
            return ['ok' => false, 'code' => 'invalid', 'message' => 'کد QR نامعتبر است.'];
        }
        $sid = (int)$m[1]; $token = strtolower($m[2]);
        $tag = DB::fetch("SELECT t.*, s.first_name, s.last_name, s.class_name, s.status AS st_status FROM student_qr_tags t JOIN students s ON s.id=t.student_id WHERE t.student_id=?", [$sid]);
        if (!$tag || !hash_equals(strtolower($tag['token']), $token) || $tag['st_status'] !== 'active') {
            return ['ok' => false, 'code' => 'invalid', 'message' => 'تگ شناسایی نشد یا غیرفعال است.'];
        }
        // v4.76.0: attendance is limited to students of the DEFAULT academic
        // year — an old tag from a previous year must not register anything.
        list($yearSql, $yearParams) = att_year_sql('s');
        $inYear = DB::fetch("SELECT s.id FROM students s WHERE s.id=? AND $yearSql", array_merge([$sid], $yearParams));
        if (!$inYear) {
            return ['ok' => false, 'code' => 'invalid', 'message' => 'دانش‌آموز در سال تحصیلی جاری فعال نیست.'];
        }
        $name = trim($tag['first_name'] . ' ' . $tag['last_name']);
        $today = att_today();
        $now = att_now_time();
        $times = att_setting_times();
        $nowMin = att_time_to_minutes($now);
        $presentUntil = att_time_to_minutes($times['present_until']);

        // match today's record in either digit system (older rows may be Persian-digit)
        $df = att_day_forms($today);
        $existing = DB::fetch("SELECT * FROM student_attendance WHERE student_id=? AND date_jalali IN (" . implode(',', array_fill(0, count($df), '?')) . ")", array_merge([$sid], $df));
        if ($existing) {
            // Auto-absent row gets upgraded when the student finally arrives.
            if ($existing['status'] === 'absent' && $existing['source'] === 'auto') {
                $minutes = max(1, $nowMin - $presentUntil);
                DB::execute("UPDATE student_attendance SET status='late', minutes_late=?, scan_time=?, source='qr', updated_at_jalali=? WHERE id=?", [$minutes, $now, jalali_now(), $existing['id']]);
                if (get_setting('att_notify_late', '1') === '1') {
                    $exId = (int)$existing['id'];
                    att_defer(function () use ($sid, $today, $minutes, $exId, $now) {
                        $n = notify_student_attendance_bots($sid, 'late', $today, $minutes, 'ورود با تأخیر پس از ثبت غیبت خودکار', $exId, $now);
                        DB::execute("UPDATE student_attendance SET notified_chats=? WHERE id=?", [$n, $exId]);
                    });
                }
                return ['ok' => true, 'code' => 'late', 'status' => 'تأخیر ' . tr_num($minutes, 'fa') . ' دقیقه', 'student' => $name, 'class' => $tag['class_name'], 'time' => tr_num($now, 'fa'), 'message' => 'ورود ثبت شد (غیبت قبلی به تأخیر تبدیل شد).'];
            }
            $prev = $existing['status'] === 'present' ? 'حضور' : ($existing['status'] === 'late' ? 'تأخیر' : 'غیبت');
            return ['ok' => false, 'code' => 'duplicate', 'status' => $prev, 'student' => $name, 'class' => $tag['class_name'], 'time' => tr_num((string)($existing['scan_time'] ?: $existing['created_at_jalali']), 'fa'), 'message' => 'برای امروز قبلاً ثبت شده است.'];
        }

        if ($nowMin <= $presentUntil) {
            // v4.75.0: UNIQUE(student_id,date_jalali) guards against the same
            // tag being read twice in the same instant (double camera frame,
            // two kiosks). The second insert fails cleanly → report duplicate
            // instead of a scary server error.
            try {
                DB::execute("INSERT INTO student_attendance (student_id, academic_year, date_jalali, status, minutes_late, scan_time, source, created_at_jalali) VALUES (?, ?, ?, 'present', 0, ?, 'qr', ?)",
                    [$sid, get_setting('current_academic_year', ''), $today, $now, jalali_now()]);
            } catch (Exception $e) {
                return ['ok' => false, 'code' => 'duplicate', 'status' => 'حضور', 'student' => $name, 'class' => $tag['class_name'], 'time' => tr_num($now, 'fa'), 'message' => 'برای امروز قبلاً ثبت شده است.'];
            }
            if (get_setting('att_notify_present', '0') === '1') {
                att_defer(function () use ($sid, $now) { att_notify_scan($sid, 'حضور به‌موقع ✅', $now); });
            }
            return ['ok' => true, 'code' => 'present', 'status' => 'حضور', 'student' => $name, 'class' => $tag['class_name'], 'time' => tr_num($now, 'fa'), 'message' => 'خوش آمدی! حضور ثبت شد.'];
        }

        $minutes = max(1, $nowMin - $presentUntil);
        try {
            DB::execute("INSERT INTO student_attendance (student_id, academic_year, date_jalali, status, minutes_late, scan_time, source, created_at_jalali) VALUES (?, ?, ?, 'late', ?, ?, 'qr', ?)",
                [$sid, get_setting('current_academic_year', ''), $today, $minutes, $now, jalali_now()]);
        } catch (Exception $e) {
            return ['ok' => false, 'code' => 'duplicate', 'status' => 'تأخیر', 'student' => $name, 'class' => $tag['class_name'], 'time' => tr_num($now, 'fa'), 'message' => 'برای امروز قبلاً ثبت شده است.'];
        }
        $rid = (int)DB::lastInsertId();
        if (get_setting('att_notify_late', '1') === '1') {
            att_defer(function () use ($sid, $today, $minutes, $rid, $now) {
                $n = notify_student_attendance_bots($sid, 'late', $today, $minutes, '', $rid, $now);
                DB::execute("UPDATE student_attendance SET notified_chats=? WHERE id=?", [$n, $rid]);
            });
        }
        return ['ok' => true, 'code' => 'late', 'status' => 'تأخیر ' . tr_num($minutes, 'fa') . ' دقیقه', 'student' => $name, 'class' => $tag['class_name'], 'time' => tr_num($now, 'fa'), 'message' => 'ورود با تأخیر ثبت شد.'];
    }
}

if (!function_exists('att_finalize_absents')) {
    /**
     * At/after att_absent_at, every active student without a record today is
     * marked absent (source=auto) and parents are notified. Runs once per day.
     * Returns [ran, marked, notified] .
     */
    function att_finalize_absents($force = false) {
        ensure_attendance_schema_v2();
        $today = att_today();
        $times = att_setting_times();
        // v4.75.0 CRITICAL: never auto-mark absences on school holidays.
        // Before this fix, opening any attendance page on a Friday marked
        // every student absent and alarmed all parents. Off days are
        // configurable (default: Friday; Thursday optional).
        // v4.77.0: master switch — the school can pause/resume the automatic
        // absent-marking at the configured cutoff time. Manual run (force)
        // from the admin panel keeps working even while paused.
        if (!$force && get_setting('att_auto_finalize', '1') !== '1') {
            return ['ran' => false, 'marked' => 0, 'notified' => 0, 'reason' => 'paused'];
        }
        if (!$force) {
            $offDays = array_map('trim', explode(',', get_setting('att_off_days', 'جمعه')));
            if (in_array(jdate('l'), $offDays, true)) {
                return ['ran' => false, 'marked' => 0, 'notified' => 0, 'reason' => 'holiday'];
            }
        }
        if (!$force && att_time_to_minutes(att_now_time()) < att_time_to_minutes($times['absent_at'])) {
            return ['ran' => false, 'marked' => 0, 'notified' => 0, 'reason' => 'not_yet'];
        }
        $guardKey = 'att_finalized_day';
        if (!$force && get_setting($guardKey, '') === $today) {
            /* v4.87.0: the absents list was finalized earlier; the deputy push
               (08:02 by default) may still be pending — attempt it now. The
               push has its own time/once-per-day guards, so this is cheap.
               IMPORTANT: this does NOT touch parent notifications or any
               existing time-rule behaviour. */
            try {
                require_once __DIR__ . '/bot_role_engine.php';
                att_defer(function () { bot_deputy_absents_push(); });
            } catch (Exception $e) {}
            return ['ran' => false, 'marked' => 0, 'notified' => 0, 'reason' => 'already'];
        }
        // v4.75.0: mark the guard only AFTER the work finishes — if PHP dies
        // halfway through the list, the next page view finishes the rest
        // (idempotent thanks to NOT EXISTS + the unique day index).
        $df = att_day_forms($today);
        // v4.76.0: auto-absent runs ONLY over students of the default
        // academic year — students of previous years must never receive
        // an automatic absence (or a bot alert to their parents).
        list($yearSql, $yearParams) = att_year_sql('s');
        $rows = DB::fetchAll("SELECT s.id FROM students s WHERE s.status='active' AND $yearSql AND NOT EXISTS (SELECT 1 FROM student_attendance a WHERE a.student_id=s.id AND a.date_jalali IN (" . implode(',', array_fill(0, count($df), '?')) . "))", array_merge($yearParams, $df));
        $marked = 0; $notified = 0;
        $doNotify = get_setting('att_notify_absent', '1') === '1';
        foreach ($rows as $r) {
            try {
                DB::execute("INSERT INTO student_attendance (student_id, academic_year, date_jalali, status, minutes_late, source, created_at_jalali) VALUES (?, ?, ?, 'absent', 0, 'auto', ?)",
                    [(int)$r['id'], get_setting('current_academic_year', ''), $today, jalali_now()]);
                $rid = (int)DB::lastInsertId();
                $marked++;
                if ($doNotify) {
                    $stuId = (int)$r['id'];
                    $absentAt = $times['absent_at'];
                    att_defer(function () use ($stuId, $today, $absentAt, $rid) {
                        $n = notify_student_attendance_bots($stuId, 'absent', $today, 0, 'عدم ثبت ورود تا ساعت ' . $absentAt, $rid);
                        if ($n) { DB::execute("UPDATE student_attendance SET notified_chats=? WHERE id=?", [$n, $rid]); }
                    });
                    $notified++;
                }
            } catch (Exception $e) { error_log('finalize absent failed: ' . $e->getMessage()); }
        }
        set_setting($guardKey, $today);   // done — block re-runs for today
        log_activity($_SESSION['admin_id'] ?? null, 'ثبت غیبت خودکار', "تاریخ $today تعداد $marked دانش‌آموز، اطلاع‌رسانی $notified");
        /* v4.87.0: right after today's absents are finalized, push the full
           absents list to every deputy chat (both bots). Own guards inside. */
        try {
            require_once __DIR__ . '/bot_role_engine.php';
            att_defer(function () { bot_deputy_absents_push(); });
        } catch (Exception $e) {}
        return ['ran' => true, 'marked' => $marked, 'notified' => $notified, 'reason' => 'ok'];
    }
}
