<?php
// File: includes/db_retention.php  (v4.176.0)
/**
 * نگهداشت خودکار جدول‌های پرشونده — «بانک اطلاعاتی چاق نشود».
 *
 * چرا: در یک مدرسهٔ واقعی، چهار جدول زیر بدون هیچ قاعده‌ای بی‌نهایت رشد
 * می‌کنند و کل پایگاه داده را سنگین می‌سازند:
 *   • bot_message_logs  → هر پیام ربات یک ردیف ماندگار (متن پیام + پاسخ)
 *   • bot_outbox        → رسید هر اعلان ارسال‌شده (از v4.172.0 پاکسازی دارد)
 *   • desk_change_log   → هر تغییر جدول همگام‌سازی دسکتاپ یک ردیف
 *   • student_attendance→ هر روزِ هر دانش‌آموز یک ردیف (سال‌به‌سال انباشت)
 *   • exam_design_archive → هر بار که طراحی آزمون عوض شود یک نسخهٔ کامل
 *
 * قواعد این فایل «محافظه‌کار» هستند:
 *   • هیچ رکورد در جریانی حذف نمی‌شود (صف pending/sending، رسیدهای رله).
 *   • حذف تکه‌تکه (LIMIT) و با سقف زمانی انجام می‌شود تا روی هاست کم‌قدرت
 *     بار ایجاد نکند.
 *   • هر قاعده یک بار در روز اجرا می‌شود (زمان آخرین اجرا در settings).
 *   • همهٔ روزهای نگهداشت از تنظیمات خوانده می‌شود؛ صفر = قاعده خاموش.
 *
 * اجرای خودکار: cron/bot-outbox-worker.php (که از قبل هر دقیقه اجرا می‌شود)
 * یک بار در روز dbm_sweep_all() را صدا می‌زند. اجرای دستی هم از صفحهٔ
 * «سلامت و بهینه‌سازی پایگاه داده» ممکن است.
 */

if (!function_exists('dbm_driver')) {
    /** 'sqlite' یا 'mysql' — برای تفاوت‌های نحوی تاریخ/یونیکس. */
    function dbm_driver() {
        static $d = null;
        if ($d !== null) return $d;
        $d = 'sqlite';
        try { $d = DB::getInstance()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME); } catch (Throwable $e) {}
        return $d === 'mysql' ? 'mysql' : 'sqlite';
    }
}

if (!function_exists('dbm_table_exists')) {
    function dbm_table_exists($table) {
        try {
            if (dbm_driver() === 'sqlite') return (bool)DB::fetch("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [$table]);
            return (bool)DB::fetch("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$table]);
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('dbm_column_exists')) {
    function dbm_column_exists($table, $column) {
        try {
            if (dbm_driver() === 'sqlite') {
                $cols = array_column(DB::getInstance()->getPdo()->query('PRAGMA table_info(' . dbm_qi($table) . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
            } else {
                $cols = array_column(DB::fetchAll("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$table]), 'COLUMN_NAME');
            }
            return in_array($column, $cols, true);
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('dbm_qi')) {
    /** نقل‌قول نام جدول/ستون برای هر دو موتور. */
    function dbm_qi($name) {
        $n = (string)$name;
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $n)) throw new RuntimeException('bad identifier');
        return dbm_driver() === 'sqlite' ? '"' . $n . '"' : '`' . $n . '`';
    }
}

if (!function_exists('dbm_pk_column')) {
    /** ستون کلید اصلی جدول (برای محدودکردن تکهٔ حذف). پیش‌فرض id. */
    function dbm_pk_column($table) {
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];
        $pk = 'id';
        try {
            if (dbm_driver() === 'sqlite') {
                foreach (DB::getInstance()->getPdo()->query('PRAGMA table_info(' . dbm_qi($table) . ')')->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    if ((int)($c['pk'] ?? 0) === 1) { $pk = (string)$c['name']; break; }
                }
            } else {
                $r = DB::fetch("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_KEY = 'PRI' LIMIT 1", [$table]);
                if (!empty($r['COLUMN_NAME'])) $pk = (string)$r['COLUMN_NAME'];
            }
        } catch (Throwable $e) {}
        return $cache[$table] = $pk;
    }
}

if (!function_exists('dbm_epoch_cut')) {
    /** برش زمانیِ یونیکس برای «قدیمی‌تر از N روز» در SQLite یا MySQL. */
    function dbm_epoch_cut($days) {
        $days = max(1, (int)$days);
        return dbm_driver() === 'sqlite'
            ? "strftime('%s','now','-{$days} days')"
            : "UNIX_TIMESTAMP(NOW() - INTERVAL {$days} DAY)";
    }
}

if (!function_exists('dbm_datetime_cut')) {
    /** برش زمانی برای ستون‌های datetime (YYYY-MM-DD HH:MM:SS). */
    function dbm_datetime_cut($days) {
        $days = max(1, (int)$days);
        return dbm_driver() === 'sqlite'
            ? "datetime('now','localtime','-{$days} days')"
            : "(NOW() - INTERVAL {$days} DAY)";
    }
}

if (!function_exists('dbm_days_setting')) {
    /** مقدار روزهای نگهداشت از تنظیمات؛ صفر یا منفی یعنی «خاموش». */
    function dbm_days_setting($key, $default) {
        $v = (int)get_setting($key, (string)$default);
        return $v > 0 ? $v : 0;
    }
}

if (!function_exists('dbm_retention_rules')) {
    /**
     * فهرست قواعد نگهداشت.
     *
     * هر قاعده:
     *   kind      => 'epoch'  : حذف با ستون زمانِ یونیکس قدیمی‌تر از cutoff
     *               'years'  : حذف ردیف‌هایی که سال تحصیلی‌شان در فهرست
     *                          سال‌های نگه‌داشته‌شده نیست (student_attendance)
     *               'versions': نگهداشت N نسخهٔ آخر هرExam (exam_design_archive)
     *   table     => جدول هدف
     *   column    => ستون زمان (kind=epoch)
     *   where     => شرط اضافهٔ اجباری (مثلاً فقط رسیدهای ارسال‌شده)
     *   days      => کلید تنظیم + پیش‌فرض
     *   why       => توضیح فارسی برای صفحهٔ بهینه‌ساز
     *   index     => ایندکس لازم برای اجرای ارزان (اگر نبود، ساخته می‌شود)
     */
    function dbm_retention_rules() {
        $rules = [];

        $rules['bot_message_logs'] = [
            'kind' => 'datetime', 'table' => 'bot_message_logs', 'column' => 'created_at',
            'where' => "1=1",
            'days' => ['dbopt_logs_days', 90],
            'label' => 'لاگ پیام‌های ربات',
            'why' => 'هر پیام دریافتی/ارسالیِ ربات یک ردیف با متن پیام و پاسخ می‌سازد. برای رفع اشکال، ۹۰ روز گذشته کافی است؛',
            'index' => ['bot_message_logs', 'dbopt_logs_created', 'created_at'],
        ];

        $rules['desk_change_log'] = [
            'kind' => 'epoch', 'table' => 'desk_change_log', 'column' => 'ts',
            'where' => "1=1",
            'days' => ['dbopt_desklog_days', 60],
            'label' => 'تغییرهای همگام‌سازی دسکتاپ',
            'why' => 'هر تغییر جدول‌های مدرسه برای همگام‌سازی نرم‌افزار دسکتاپ یک ردیف می‌سازد. دسکتاپ شما مرتب همگام می‌شود، پس ۶۰ روز تاریخچه برای بازیابی کافی است؛',
            'index' => ['desk_change_log', 'dbopt_desklog_ts', 'ts'],
        ];

        $rules['student_attendance'] = [
            'kind' => 'years', 'table' => 'student_attendance', 'column' => 'academic_year',
            'where' => "academic_year IS NOT NULL AND academic_year <> ''",
            'days' => ['dbopt_attendance_years', 0],
            'label' => 'حضور و غیاب سال‌های قدیمی (پیش‌فرض خاموش)',
            'why' => 'هر روزِ هر دانش‌آموز یک ردیف است و سال‌به‌سال انباشت می‌شود. این قاعده فقط با انتخاب شما فعال می‌شود و سال‌های تحصیلیِ قدیمی‌تر از مقدار تعیین‌شده را حذف می‌کند (پیشنهاد: پیش از فعال‌سازی پشتیبان بگیرید).',
            'index' => ['student_attendance', 'dbopt_attendance_year', 'academic_year'],
        ];

        $rules['exam_design_archive'] = [
            'kind' => 'versions', 'table' => 'exam_design_archive', 'column' => 'exam_id',
            'days' => ['dbopt_exam_versions', 5],
            'label' => 'نسخه‌های قدیمی طراحی آزمون',
            'why' => 'هر بار که طراحی یک آزمون عوض شود، نسخهٔ کامل قبلی در آرشیو می‌ماند. نگهداشت ۵ نسخهٔ آخر هر آزمون کافی است؛ طراحی جاری در جدول exam_designs دست‌نخورده می‌ماند.',
            'index' => null,
        ];

        $rules['bot_outbox_blocked'] = [
            'kind' => 'epoch', 'table' => 'bot_outbox', 'column' => 'created_at',
            'where' => "state='blocked' AND owner='local'",
            'days' => ['dbopt_outbox_blocked_days', 90],
            'label' => 'پیام‌های مسدودِ صف ربات',
            'why' => 'پیامی که پیام‌رسان رد کرده هرگز ارسال نشود؛ پس فقط به اندازهٔ بازبینی لازم نگه داشته می‌شود. (رسیدهای ارسال‌شده از v4.172.0 پاکسازی می‌شوند.)',
            'index' => null,
        ];

        return $rules;
    }
}

if (!function_exists('dbm_kept_years')) {
    /** سال‌های تحصیلیِ «داغ» = سال پیش‌فرض سیستم + N سال قبل از آن. */
    function dbm_kept_years($keepCount) {
        $keepCount = max(1, (int)$keepCount);
        $years = [];
        $current = (string)get_setting('current_academic_year', '');
        if ($current === '') {
            try { $r = DB::fetch("SELECT DISTINCT academic_year FROM student_attendance WHERE academic_year IS NOT NULL AND academic_year<>'' ORDER BY academic_year DESC LIMIT 1"); $current = (string)($r['academic_year'] ?? ''); } catch (Throwable $e) {}
        }
        if ($current === '' || !preg_match('~^(\d{4})/(\d{4})$~D', $current, $m)) return [];
        $start = (int)$m[1];
        for ($i = 0; $i < $keepCount; $i++) $years[] = ($start - $i) . '/' . ($start - $i + 1);
        return $years;
    }
}

if (!function_exists('dbm_ensure_retention_indexes')) {
    /** ایندکس‌های لازم برای اجرای ارزان قواعد — idempotent و فقط اگر لازم باشد. */
    function dbm_ensure_retention_indexes() {
        $made = 0;
        foreach (dbm_retention_rules() as $rule) {
            if (empty($rule['index'])) continue;
            [$table, $idxName, $column] = $rule['index'];
            if (!dbm_table_exists($table) || !dbm_column_exists($table, $column)) continue;
            if (dbm_retention_index_exists($table, $idxName, $column)) continue;
            try {
                if (dbm_driver() === 'sqlite') {
                    DB::execute('CREATE INDEX IF NOT EXISTS "' . $idxName . '" ON ' . dbm_qi($table) . ' (' . dbm_qi($column) . ')');
                } else {
                    DB::execute('ALTER TABLE ' . dbm_qi($table) . ' ADD INDEX `' . $idxName . '` (`' . $column . '`)');
                }
                $made++;
            } catch (Throwable $e) { /* ایندکس از قبل هست یا ستون نامناسب؛ بی‌صدا رد می‌شویم */ }
        }
        return $made;
    }
}

if (!function_exists('dbm_retention_index_exists')) {
    /** آیا ایندکس روی همان ستون (با هر نامی) وجود دارد؟ */
    function dbm_retention_index_exists($table, $idxName, $column) {
        try {
            if (dbm_driver() === 'sqlite') {
                $rows = DB::fetchAll("SELECT name, sql FROM sqlite_master WHERE type='index' AND tbl_name=?", [$table]);
                foreach ($rows as $r) {
                    if ($r['name'] === $idxName) return true;
                    $sql = (string)($r['sql'] ?? '');
                    if ($sql !== '' && preg_match('~\(\s*"?' . preg_quote($column, '~') . '"?\s*\)~', $sql)) return true;
                }
                return false;
            }
            $rows = DB::fetchAll("SHOW INDEX FROM `" . $table . "`");
            foreach ($rows as $r) if ((string)($r['Column_name'] ?? '') === $column) return true;
            return false;
        } catch (Throwable $e) { return true; /* نامشخص = دست نزن */ }
    }
}

if (!function_exists('dbm_rule_days')) {
    /** روز/مقدار نگهداشت یک قاعده (۰ = خاموش). */
    function dbm_rule_days($key) {
        $rules = dbm_retention_rules();
        if (!isset($rules[$key]['days'])) return 0;
        return dbm_days_setting($rules[$key]['days'][0], $rules[$key]['days'][1]);
    }
}

if (!function_exists('dbm_rule_count_sql')) {
    /** کوئری شمارش رکوردهای قابل حذف یک قاعده. */
    function dbm_rule_count_sql($key) {
        $rules = dbm_retention_rules();
        if (!isset($rules[$key])) return null;
        $r = $rules[$key];
        if (!dbm_table_exists($r['table'])) return null;
        $t = dbm_qi($r['table']);

        if ($r['kind'] === 'epoch') {
            if (!dbm_column_exists($r['table'], $r['column'])) return null;
            $cut = dbm_epoch_cut(dbm_rule_days($key));
            return "SELECT COUNT(*) c FROM $t WHERE {$r['column']} > 0 AND {$r['column']} < $cut AND ({$r['where']})";
        }
        if ($r['kind'] === 'datetime') {
            if (!dbm_column_exists($r['table'], $r['column'])) return null;
            $cut = dbm_datetime_cut(dbm_rule_days($key));
            return "SELECT COUNT(*) c FROM $t WHERE {$r['column']} IS NOT NULL AND {$r['column']} < $cut AND ({$r['where']})";
        }
        if ($r['kind'] === 'years') {
            if (!dbm_column_exists($r['table'], $r['column'])) return null;
            $years = dbm_kept_years(dbm_rule_days($key));
            if (!$years) return null;
            $in = implode(',', array_fill(0, count($years), '?'));
            return "SELECT COUNT(*) c FROM $t WHERE ({$r['where']}) AND {$r['column']} NOT IN ($in)";
        }
        if ($r['kind'] === 'versions') {
            if (!dbm_column_exists($r['table'], $r['column'])) return null;
            $keep = max(1, dbm_rule_days($key));
            // برای پایداری روی MySQL 5.7 و SQLite قدیمی، از «جمعِ اضافی» شمرده
            // می‌شود: مجموع ردیف‌ها منهای سهم هر آزمون که نگه داشته می‌شود.
            return "SELECT COALESCE(SUM(extra),0) c FROM (SELECT MAX(COUNT(*) - $keep, 0) extra FROM $t GROUP BY {$r['column']}) y";
        }
        return null;
    }
}

if (!function_exists('dbm_rule_delete_sql')) {
    /**
     * کوئری حذف یک تکه (chunk) از رکوردهای قابل حذف.
     * SQLite و MySQL هر دو DELETE ... WHERE ... LIMIT را می‌پذیرند (MySQL
     * بدون ORDER BY). سقف تکه کوچک است تا قفل جدول طولانی نشود.
     */
    function dbm_rule_delete_sql($key, $chunk = 500) {
        $rules = dbm_retention_rules();
        if (!isset($rules[$key])) return null;
        $r = $rules[$key];
        if (!dbm_table_exists($r['table'])) return null;
        $t = dbm_qi($r['table']);
        $chunk = max(50, min(2000, (int)$chunk));

        /* شرطِ «کدام ردیف‌ها» برای هر گونهٔ قاعده */
        $where = null; $params = [];
        if ($r['kind'] === 'epoch') {
            if (!dbm_column_exists($r['table'], $r['column'])) return null;
            $cut = dbm_epoch_cut(dbm_rule_days($key));
            $where = "{$r['column']} > 0 AND {$r['column']} < $cut AND ({$r['where']})";
        } elseif ($r['kind'] === 'datetime') {
            if (!dbm_column_exists($r['table'], $r['column'])) return null;
            $cut = dbm_datetime_cut(dbm_rule_days($key));
            $where = "{$r['column']} IS NOT NULL AND {$r['column']} < $cut AND ({$r['where']})";
        } elseif ($r['kind'] === 'years') {
            if (!dbm_column_exists($r['table'], $r['column'])) return null;
            $years = dbm_kept_years(dbm_rule_days($key));
            if (!$years) return null;
            $in = implode(',', array_fill(0, count($years), '?'));
            $where = "({$r['where']}) AND {$r['column']} NOT IN ($in)";
            $params = $years;
        } elseif ($r['kind'] === 'versions') {
            // شناسه‌ها را dbm_sweep_rule حساب می‌کند (سازگار با هر دو موتور)
            return ['sql' => "DELETE FROM $t WHERE " . dbm_qi(dbm_pk_column($r['table'])) . " IN (__DBM_IDS__)", 'params' => []];
        }
        if ($where === null) return null;

        /* SQLite عمداً DELETE ... LIMIT ندارد (مگر با بیلد خاص)، پس تکه را
           با زیرکوئری شناسه محدود می‌کنیم؛ MySQL خودش LIMIT را می‌فهمد. */
        $pk = dbm_qi(dbm_pk_column($r['table']));
        if (dbm_driver() === 'sqlite') {
            return ['sql' => "DELETE FROM $t WHERE $pk IN (SELECT $pk FROM $t WHERE $where LIMIT $chunk)", 'params' => $params];
        }
        return ['sql' => "DELETE FROM $t WHERE $where LIMIT $chunk", 'params' => $params];
    }
}

if (!function_exists('dbm_version_ids_to_drop')) {
    /**
     * شناسهٔ نسخه‌های قدیمیِ هر رکورد مادر: برای هر مادر، شناسهٔ نسخهٔ Nام
     * (مرز) پیدا می‌شود و همهٔ شناسه‌های کوچک‌تر از آن قابل حذف‌اند.
     * بدون پنجرهٔ (window function) — سازگار با MySQL 5.7 و SQLite قدیمی.
     *
     * @return int[] حداکثر $chunk شناسه
     */
    function dbm_version_ids_to_drop($table, $motherColumn, $keep, $chunk = 500) {
        $keep = max(1, (int)$keep);
        $t = dbm_qi($table);
        $pk = dbm_qi(dbm_pk_column($table));
        /* مقدار keep همیشه عدد صحیحِ کنترل‌شده است، پس مستقیم در متن کوئری
           می‌آید (پارامتر در HAVING در SQLite درست بسته نمی‌شود). */
        $mothers = DB::fetchAll(
            "SELECT $motherColumn AS mid FROM $t GROUP BY $motherColumn HAVING COUNT(*) > $keep"
        );
        $out = [];
        foreach ($mothers as $m) {
            if (count($out) >= $chunk) break;
            $mid = $m['mid'];
            // مرز: نسخهٔ Nام از جدید به قدیم
            $pkName = dbm_pk_column($table);
            $border = DB::fetch("SELECT $pkName AS b FROM $t WHERE $motherColumn = ? ORDER BY $pkName DESC LIMIT 1 OFFSET " . ($keep - 1), [$mid]);
            if (empty($border['b'])) continue;
            $rows = DB::fetchAll("SELECT $pkName AS v FROM $t WHERE $motherColumn = ? AND $pkName < ? ORDER BY $pkName ASC LIMIT ?", [$mid, (int)$border['b'], $chunk]);
            foreach ($rows as $r) $out[] = (int)$r['v'];
            if (count($out) >= $chunk) break;
        }
        return array_slice($out, 0, $chunk);
    }
}

if (!function_exists('dbm_sweep_rule')) {
    /**
     * اجرای یک قاعده تا خالی‌شدن یا رسیدن به سقف زمانی.
     * خروجی: تعداد رکورد حذف‌شده (۰ اگر قاعده خاموش/بی‌مورد باشد).
     */
    function dbm_sweep_rule($key, $deadline = 0, $maxRows = 20000) {
        $days = dbm_rule_days($key);
        if ($days <= 0) return 0;
        $rules = dbm_retention_rules();
        if (!isset($rules[$key])) return 0;
        if (!dbm_table_exists($rules[$key]['table'])) return 0;

        $removed = 0;
        $chunk = 500;
        while ($removed < $maxRows) {
            if ($deadline && microtime(true) > $deadline) break;
            $job = dbm_rule_delete_sql($key, $chunk);
            if ($job === null) break;
            try {
                $sql = $job['sql']; $params = $job['params'];
                if ($rules[$key]['kind'] === 'versions') {
                    $ids = dbm_version_ids_to_drop($rules[$key]['table'], $rules[$key]['column'], $days, $chunk);
                    if (!$ids) break;
                    $sql = str_replace('__DBM_IDS__', implode(',', array_map('intval', $ids)), $sql);
                }
                $st = DB::query($sql, $params);
                $n = $st ? (int)$st->rowCount() : 0;
            } catch (Throwable $e) { break; }
            if ($n <= 0) break;
            $removed += $n;
            if ($n < $chunk) break;
        }
        return $removed;
    }
}

if (!function_exists('dbm_sweep_all')) {
    /**
     * اجرای همهٔ قواعد فعال، هر کدام حداکثر یک بار در روز.
     * سقف زمانی پیش‌فرض ۳ ثانیه تا هاست کم‌قدرت زیر بار نرود.
     */
    function dbm_sweep_all($budgetSeconds = 3.0) {
        $deadline = microtime(true) + max(0.5, (float)$budgetSeconds);
        $report = [];
        try { dbm_ensure_retention_indexes(); } catch (Throwable $e) {}
        foreach (array_keys(dbm_retention_rules()) as $key) {
            if (microtime(true) > $deadline) { $report[$key] = 'زمان تمام شد'; continue; }
            $days = dbm_rule_days($key);
            if ($days <= 0) { $report[$key] = 0; continue; }
            $lastKey = 'dbopt_retention_last_' . $key;
            $last = (int)get_setting($lastKey, '0');
            if ($last > time() - 82800) { $report[$key] = 'امروز اجرا شده'; continue; }
            try {
                $n = dbm_sweep_rule($key, $deadline);
                set_setting($lastKey, (string)time());
                $report[$key] = $n;
                if ($n > 0) error_log("db retention [$key]: $n رکورد قدیمی حذف شد");
            } catch (Throwable $e) {
                $report[$key] = 'خطا';
                error_log("db retention [$key] skipped: " . $e->getMessage());
            }
        }
        return $report;
    }
}

if (!function_exists('dbm_rule_counts')) {
    /** شمارش رکوردهای قابل حذف برای همهٔ قواعد (برای نمایش در صفحهٔ بهینه‌ساز). */
    function dbm_rule_counts() {
        $out = [];
        foreach (array_keys(dbm_retention_rules()) as $key) {
            $sql = dbm_rule_days($key) > 0 ? dbm_rule_count_sql($key) : null;
            $n = -1;
            if ($sql !== null) {
                try {
                    if ($key === 'student_attendance') {
                        $row = DB::fetch($sql, dbm_kept_years(dbm_rule_days($key)));
                    } else {
                        $row = DB::fetch($sql);
                    }
                    $n = (int)($row['c'] ?? 0);
                } catch (Throwable $e) { $n = -1; }
            }
            $out[$key] = $n;
        }
        return $out;
    }
}
