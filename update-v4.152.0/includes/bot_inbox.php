<?php
// File: includes/bot_inbox.php  (v4.177.0)
/**
 * پیام‌های دریافتی از ربات — «صندوق ورودی».
 *
 * تا این نسخه فقط پیام‌های خروجی (bot_message_logs) نگه داشته می‌شدند و متن‌هایی
 * که کاربران به ربات می‌فرستادند — مثلاً پیام‌های بی‌هدف یا اشتباهی — هیچ‌جا
 * دیده نمی‌شدند. این ماژول هر پیام ورودی را با مشخصات فرستنده نگه می‌دارد تا
 * مدیر بتواند در «تنظیمات دیگر ← پیام‌های دریافتی ربات» آن‌ها را ببیند.
 *
 * اصول:
 *   • هیچ‌وقت جریان ورود/پیوند ولی را خراب نمی‌کند (هر خطا فقط لاگ می‌شود).
 *   • نوشتن بسیار ارزان است: یک INSERT بدون ایندکس اضافه روی مسیر داغ.
 *   • نگهداشت خودکار با قاعدهٔ bot_inbox (پیش‌فرض ۶۰ روز) در db_retention.php.
 */

if (!function_exists('bot_inbox_is_mysql')) {
    /**
     * درایور جاری — MySQL و SQLite از نظر نحوی تاریخ/ایندکس تفاوت دارند و
     * سایت زندهٔ مدرسه MySQL است، پس هر SQL اینجا باید روی هر دو کار کند.
     */
    function bot_inbox_is_mysql() {
        static $m = null;
        if ($m !== null) return $m;
        $m = false;
        try {
            $m = (DB::getInstance()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql');
        } catch (Throwable $e) { $m = false; }
        return $m;
    }
}

if (!function_exists('bot_inbox_ensure_index')) {
    /** ساخت ایندکس به‌صورت idempotent و مستقل از درایور (MySQL lacking IF NOT EXISTS). */
    function bot_inbox_ensure_index($name, $cols) {
        try {
            if (!bot_inbox_is_mysql()) {
                DB::execute('CREATE INDEX IF NOT EXISTS "' . $name . '" ON bot_inbox (' . $cols . ')');
                return true;
            }
            $rows = DB::fetchAll('SHOW INDEX FROM bot_inbox');
            foreach ($rows as $r) {
                if ((string)($r['Key_name'] ?? '') === $name) return true;
            }
            DB::execute('ALTER TABLE bot_inbox ADD INDEX `' . $name . '` (' . $cols . ')');
            return true;
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('bot_inbox_schema')) {
    /** جدول صندوق ورودی — idempotent و سازگار با MySQL و SQLite. */
    function bot_inbox_schema() {
        static $done = false;
        if ($done) return true;
        try {
            if (bot_inbox_is_mysql()) {
                DB::execute(
                    "CREATE TABLE IF NOT EXISTS bot_inbox (
                        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        platform VARCHAR(20) NOT NULL,
                        chat_id VARCHAR(64) NOT NULL,
                        username VARCHAR(190) DEFAULT NULL,
                        student_id INT DEFAULT NULL,
                        message TEXT,
                        kind VARCHAR(40) DEFAULT 'text',
                        handled VARCHAR(40) DEFAULT NULL,
                        created_at DATETIME NULL,
                        KEY bot_inbox_platform_chat (platform, chat_id),
                        KEY bot_inbox_created (created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                );
            } else {
                DB::execute(
                    "CREATE TABLE IF NOT EXISTS bot_inbox (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        platform VARCHAR(20) NOT NULL,
                        chat_id VARCHAR(64) NOT NULL,
                        username VARCHAR(190) DEFAULT NULL,
                        student_id INT DEFAULT NULL,
                        message TEXT,
                        kind VARCHAR(40) DEFAULT 'text',
                        handled VARCHAR(40) DEFAULT NULL,
                        created_at TEXT
                    )"
                );
            }
        } catch (Throwable $e) {
            return false;
        }
        // ایندکس‌های لازم برای دیدن/پاک‌سازی ارزان (idempotent و مستقل از درایور)
        bot_inbox_ensure_index('bot_inbox_platform_chat', 'platform, chat_id');
        bot_inbox_ensure_index('bot_inbox_created', 'created_at');
        $done = true;
        return true;
    }
}

if (!function_exists('bot_inbox_log')) {
    /**
     * ثبت یک پیام ورودی. هرگز استثنا پرتاب نمی‌کند.
     *
     * @param string      $platform  بله/تلگرام
     * @param string      $chatId    شناسهٔ گفتگو
     * @param string      $username  نام کاربری (اگر باشد)
     * @param string      $text      متن پیام
     * @param string      $kind      نوع پیام (text/photo/document/…)
     * @param string|null $studentId شناسهٔ دانش‌آموز اگر شناخته شده باشد
     * @param string|null $handled   کد مسیری که پیام را پردازش کرد (مثل start/panel/unknown)
     */
    function bot_inbox_log($platform, $chatId, $username, $text, $kind = 'text', $studentId = null, $handled = null) {
        try {
            if (!bot_inbox_schema()) return false;
            $platform = bot_valid_platform($platform);
            $chatId = (string)$chatId;
            if ($chatId === '') return false;
            $text = (string)$text;
            if ($text !== '' && function_exists('mb_substr')) $text = mb_substr($text, 0, 4000, 'UTF-8');
            DB::execute(
                "INSERT INTO bot_inbox (platform, chat_id, username, student_id, message, kind, handled, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$platform, $chatId, ($username !== '' ? (string)$username : null),
                 ($studentId !== null && (int)$studentId > 0 ? (int)$studentId : null),
                 $text, ($kind !== '' ? (string)$kind : 'text'),
                 ($handled !== null ? (string)$handled : null),
                 /* v4.177.1: زمان از PHP می‌آید، نه از تابع تاریخِ درون‌خطیِ
                    SQLite؛ آن شکل فقط روی SQLite کار می‌کند و روی سایت زندهٔ
                    MySQL این INSERT را بی‌صدا می‌شکست. قالب
                    YYYY-MM-DD HH:MM:SS همان قالبی است که قاعدهٔ نگهداشت
                    با آن مقایسه می‌کند. */
                 date('Y-m-d H:i:s')]
            );
            return true;
        } catch (Throwable $e) {
            error_log('bot_inbox_log: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('bot_inbox_student_id')) {
    /** شناسهٔ دانش‌آموزِ متصل به این گفتگو (اگر پیوند خورده باشد). */
    function bot_inbox_student_id($platform, $chatId) {
        try {
            $table = function_exists('bot_user_table') ? bot_user_table($platform) : '';
            if ($table === '') return null;
            $col = $platform === 'telegram' ? 'telegram_chat_id' : 'bale_chat_id';
            $row = DB::fetch("SELECT student_id FROM `$table` WHERE `$col` = ? LIMIT 1", [(string)$chatId]);
            $sid = (int)($row['student_id'] ?? 0);
            return $sid > 0 ? $sid : null;
        } catch (Throwable $e) { return null; }
    }
}

if (!function_exists('bot_inbox_filters')) {
    /** فیلترهای صفحهٔ صندوق ورودی از روی آدرس ساخته می‌شود. */
    function bot_inbox_filters() {
        $f = [
            'platform' => bot_valid_platform($_GET['platform'] ?? 'bale'),
            'q'        => trim((string)($_GET['q'] ?? '')),
            'kind'     => trim((string)($_GET['kind'] ?? '')),
            'student'  => trim((string)($_GET['student'] ?? '')),
        ];
        if (!in_array($f['kind'], ['text', 'photo', 'document', 'voice', 'other'], true)) $f['kind'] = '';
        return $f;
    }
}

if (!function_exists('bot_inbox_where')) {
    /** بخش WHERE و پارامترها برای فیلترهای داده‌شده. */
    function bot_inbox_where(array $f) {
        $where = ['platform = ?'];
        $params = [$f['platform']];
        if ($f['q'] !== '') {
            $where[] = '(message LIKE ? OR username LIKE ? OR chat_id LIKE ?)';
            for ($i = 0; $i < 3; $i++) $params[] = '%' . $f['q'] . '%';
        }
        if ($f['kind'] !== '') { $where[] = 'kind = ?'; $params[] = $f['kind']; }
        if ($f['student'] !== '') {
            if ($f['student'] === 'linked')      $where[] = 'student_id IS NOT NULL';
            elseif ($f['student'] === 'unknown') $where[] = 'student_id IS NULL';
        }
        return [implode(' AND ', $where), $params];
    }
}

if (!function_exists('bot_inbox_rows')) {
    /** یک صفحه از پیام‌های ورودی (جدیدترین اول) با نام دانش‌آموز. */
    function bot_inbox_rows(array $f, $page = 1, $perPage = 50) {
        if (!bot_inbox_schema()) return [];
        list($where, $params) = bot_inbox_where($f);
        $perPage = max(10, min(200, (int)$perPage));
        $page = max(1, (int)$page);
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT i.*, s.first_name, s.last_name, s.class_name
                FROM bot_inbox i
                LEFT JOIN students s ON s.id = i.student_id
                WHERE $where
                ORDER BY i.id DESC
                LIMIT $perPage OFFSET $offset";
        try { return DB::fetchAll($sql, $params); } catch (Throwable $e) { return []; }
    }
}

if (!function_exists('bot_inbox_total')) {
    /** تعداد کل پیام‌های منطبق با فیلتر. */
    function bot_inbox_total(array $f) {
        if (!bot_inbox_schema()) return 0;
        list($where, $params) = bot_inbox_where($f);
        try { return (int)(DB::fetch("SELECT COUNT(*) c FROM bot_inbox WHERE $where", $params)['c'] ?? 0); }
        catch (Throwable $e) { return 0; }
    }
}

if (!function_exists('bot_inbox_kind_counts')) {
    /** آمار نوع پیام‌ها برای فیلترهای بالای صفحه. */
    function bot_inbox_kind_counts($platform) {
        $out = ['text' => 0, 'photo' => 0, 'document' => 0, 'voice' => 0, 'other' => 0];
        if (!bot_inbox_schema()) return $out;
        try {
            $rows = DB::fetchAll("SELECT kind, COUNT(*) c FROM bot_inbox WHERE platform = ? GROUP BY kind", [bot_valid_platform($platform)]);
            foreach ($rows as $r) {
                $k = (string)($r['kind'] ?? '');
                if (!isset($out[$k])) $k = 'other';
                $out[$k] = (int)$r['c'];
            }
        } catch (Throwable $e) {}
        return $out;
    }
}
