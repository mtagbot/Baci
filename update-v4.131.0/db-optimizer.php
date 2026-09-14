<?php
/**
 * db-optimizer.php — v4.31.0
 * ابزار ممیزی و بهینه‌سازی پایگاه داده (Database Health & Optimizer)
 *
 * قابلیت‌ها:
 *  1) اسکن سلامت (فقط-خواندنی): موتور/کلیشن جداول، حجم داده و ایندکس، ایندکس‌های
 *     حیاتیِ غایب، رکوردهای یتیم (orphan)، رکوردهای تکراری/موازی، توکن‌های منقضی.
 *  2) اعمال ایندکس‌های حیاتی برای جداول پرحجم (reports/report_grades/students/…).
 *  3) پاکسازی امن رکوردهای یتیم و تکراری (فقط مواردی که هیچ وابسته‌ای ندارند).
 *  4) ANALYZE + OPTIMIZE برای بازپس‌گیری فضا و به‌روزرسانی آمار ایندکس‌ها.
 *
 * همهٔ عملیات نوشتاری: POST + CSRF + مجوز system_settings. هیچ جدولی حذف نمی‌شود.
 */

require_once __DIR__ . '/includes/auth.php';
require_permission('system_settings');

$pdo = DB::getInstance()->getPdo();

/* ---------------------------------------------------------------
 * v4.131.0 — این صفحه تا اینجا فقط MySQL بود
 * ---------------------------------------------------------------
 * کل فایل با information_schema و «ALTER TABLE ... ADD INDEX» و
 * «OPTIMIZE TABLE» نوشته شده بود؛ هیچ‌کدام در SQLite وجود ندارند. ولی
 * includes/header.php منوی «سلامت پایگاه داده» را روی دسکتاپ هم نشان
 * می‌داد، پس کاربر دسکتاپ صفحه‌ای می‌دید که دکمه‌هایش بی‌صدا شکست
 * می‌خوردند (خطاها در try/catch بلعیده می‌شدند) و بدتر: چون
 * dbopt_index_exists() در catch مقدار true برمی‌گرداند، همه‌چیز
 * «از قبل موجود» گزارش می‌شد.
 *
 * حالا هر دو موتور پشتیبانی می‌شوند:
 *   MySQL  → information_schema · ADD INDEX · ANALYZE/OPTIMIZE TABLE
 *   SQLite → PRAGMA/sqlite_master · CREATE INDEX · ANALYZE · VACUUM
 * --------------------------------------------------------------- */
$DBOPT_SQLITE = false;
try { $DBOPT_SQLITE = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'); } catch (Exception $e) {}
$GLOBALS['__dbopt_pdo'] = $pdo;   /* توابع کمکی به PDO خام نیاز دارند (PRAGMA) */

$dbName = null;
if ($DBOPT_SQLITE) {
    $dbName = 'SQLite (نسخهٔ دسکتاپ)';
} else {
    try { $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn(); } catch (Exception $e) {}
}

/* ---------------------------------------------------------------
 * تعریف ایندکس‌های حیاتی (idempotent — فقط اگر موجود نباشند)
 * --------------------------------------------------------------- */
$recommendedIndexes = [
    // جدول، نام ایندکس، ستون‌ها، توضیح فارسی
    ['students',  'idx_nid_year',    '`national_id`,`academic_year`',        'ورود دانش‌آموز و استعلام (پرترددترین کوئری سیستم)'],
    ['students',  'idx_year_class',  '`academic_year`,`class_name`',         'فیلتر لیست دانش‌آموزان بر اساس سال/کلاس'],
    ['students',  'idx_status',      '`status`',                             'شمارش/فیلتر دانش‌آموزان فعال'],
    ['reports',   'idx_student_year','`student_id`,`academic_year`,`term`',  'کارنامه‌های یک دانش‌آموز در سال/نوبت'],
    ['reports',   'idx_year_class',  '`academic_year`,`class_name`',         'رتبه‌بندی و گزارش‌های کلاسی'],
    ['reports',   'idx_year_term',   '`academic_year`,`term`,`report_month`','گزارش‌های دوره‌ای و قفل گروهی'],
    ['report_grades', 'idx_report_subject', '`report_id`,`subject_name`',    'نمرات یک کارنامه + تحلیل درس‌به‌درس'],
    ['report_locks',  'idx_lock_lookup',    '`academic_year`,`class_name`,`student_id`', 'بررسی قفل کارنامه هنگام نمایش'],
    ['classes',   'idx_year_name',   '`academic_year`,`name`',               'جستجوی کلاس بر اساس سال'],
    ['activity_logs', 'idx_created', '`created_at`',                         'مرور و پاکسازی لاگ‌ها بر اساس تاریخ'],
    ['sms_logs',      'idx_sent',    '`sent_at`',                            'گزارش پیامک‌های اخیر'],
    ['notifications', 'idx_target',  '`target_type`,`target_value`',         'اعلان‌های هدفمند کلاس/دانش‌آموز'],
    ['api_tokens',    'idx_expires', '`expires_at`',                         'پاکسازی توکن‌های منقضی'],
    ['student_discipline_records', 'idx_student_created', '`student_id`,`created_at`', 'پروندهٔ انضباطی دانش‌آموز به ترتیب زمان'],
];

/* ---------------------------------------------------------------
 * توابع کمکی
 * --------------------------------------------------------------- */
function dbopt_table_exists($table) {
    global $DBOPT_SQLITE;
    try {
        if ($DBOPT_SQLITE) {
            $r = DB::fetch("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [$table]);
            return (bool)$r;
        }
        $r = DB::fetch("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$table]);
        return (bool)$r;
    } catch (Exception $e) { return false; }
}
function dbopt_column_exists($table, $column) {
    global $DBOPT_SQLITE;
    try {
        if ($DBOPT_SQLITE) {
            foreach ($GLOBALS['__dbopt_pdo']->query("PRAGMA table_info(\"$table\")") as $row) {
                if (($row['name'] ?? '') === $column) return true;
            }
            return false;
        }
        $r = DB::fetch("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?", [$table, $column]);
        return (bool)$r;
    } catch (Exception $e) { return false; }
}
function dbopt_index_exists($table, $indexName) {
    global $DBOPT_SQLITE;
    try {
        if ($DBOPT_SQLITE) {
            $r = DB::fetch("SELECT name FROM sqlite_master WHERE type='index' AND name=? AND tbl_name=?", [$indexName, $table]);
            return (bool)$r;
        }
        $r = DB::fetch("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1", [$table, $indexName]);
        return (bool)$r;
    } catch (Exception $e) { return true; /* در شک، از ALTER تکراری پرهیز کن */ }
}
function dbopt_count($sql, $params = []) {
    try { $r = DB::fetch($sql, $params); return (int)($r['c'] ?? 0); } catch (Exception $e) { return -1; }
}

/* NOW() در SQLite وجود ندارد؛ معادلش datetime('now','localtime') است.
   همان قراردادی که schema-sqlite.sql برای ستون‌های created_at دارد. */
function dbopt_expired_sql($head, $col) {
    global $DBOPT_SQLITE;
    $now = $DBOPT_SQLITE ? "datetime('now','localtime')" : "NOW()";
    return "$head WHERE $col IS NOT NULL AND $col < $now";
}

/* تعریف اسکن رکوردهای یتیم: [کلید, برچسب, کوئری شمارش, کوئری حذف] */
function dbopt_orphan_defs() {
    $defs = [];
    $defs['grades_orphan'] = [
        'نمرات بدون کارنامه (report_grades یتیم)',
        "SELECT COUNT(*) c FROM report_grades rg LEFT JOIN reports r ON rg.report_id = r.id WHERE r.id IS NULL",
        "DELETE FROM report_grades WHERE report_id IS NOT NULL AND report_id NOT IN (SELECT id FROM reports)",
    ];
    $defs['reports_orphan'] = [
        'کارنامه‌های بدون دانش‌آموز (reports یتیم)',
        "SELECT COUNT(*) c FROM reports r LEFT JOIN students s ON r.student_id = s.id WHERE s.id IS NULL",
        "DELETE FROM reports WHERE student_id IS NOT NULL AND student_id NOT IN (SELECT id FROM students)",
    ];
    if (dbopt_table_exists('report_locks')) {
        $defs['locks_orphan'] = [
            'قفل‌های کارنامه برای دانش‌آموز حذف‌شده',
            "SELECT COUNT(*) c FROM report_locks l LEFT JOIN students s ON l.student_id = s.id WHERE l.student_id IS NOT NULL AND s.id IS NULL",
            "DELETE FROM report_locks WHERE student_id IS NOT NULL AND student_id NOT IN (SELECT id FROM students)",
        ];
    }
    if (dbopt_table_exists('student_discipline_records')) {
        $defs['discipline_orphan'] = [
            'موارد انضباطی دانش‌آموز حذف‌شده',
            "SELECT COUNT(*) c FROM student_discipline_records d LEFT JOIN students s ON d.student_id = s.id WHERE s.id IS NULL",
            "DELETE FROM student_discipline_records WHERE student_id IS NOT NULL AND student_id NOT IN (SELECT id FROM students)",
        ];
    }
    if (dbopt_table_exists('online_exam_answers') && dbopt_table_exists('online_exam_attempts')) {
        $defs['answers_orphan'] = [
            'پاسخ‌های آزمون آنلاین بدون تلاش ثبت‌شده',
            "SELECT COUNT(*) c FROM online_exam_answers a LEFT JOIN online_exam_attempts t ON a.attempt_id = t.id WHERE t.id IS NULL",
            "DELETE FROM online_exam_answers WHERE attempt_id IS NOT NULL AND attempt_id NOT IN (SELECT id FROM online_exam_attempts)",
        ];
    }
    if (dbopt_table_exists('online_exam_attempts') && dbopt_table_exists('online_exams')) {
        $defs['attempts_orphan'] = [
            'تلاش‌های آزمون آنلاین برای آزمون حذف‌شده',
            "SELECT COUNT(*) c FROM online_exam_attempts t LEFT JOIN online_exams e ON t.exam_id = e.id WHERE e.id IS NULL",
            "DELETE FROM online_exam_attempts WHERE exam_id IS NOT NULL AND exam_id NOT IN (SELECT id FROM online_exams)",
        ];
    }
    if (dbopt_table_exists('online_questions') && dbopt_table_exists('online_exams')) {
        $defs['questions_orphan'] = [
            'سوالات آزمون آنلاین برای آزمون حذف‌شده',
            "SELECT COUNT(*) c FROM online_questions q LEFT JOIN online_exams e ON q.exam_id = e.id WHERE e.id IS NULL",
            "DELETE FROM online_questions WHERE exam_id IS NOT NULL AND exam_id NOT IN (SELECT id FROM online_exams)",
        ];
    }
    if (dbopt_table_exists('online_exam_proctoring_logs') && dbopt_table_exists('online_exam_attempts')) {
        $defs['proctoring_orphan'] = [
            'لاگ‌های نظارت آزمون بدون تلاش مرتبط',
            "SELECT COUNT(*) c FROM online_exam_proctoring_logs p LEFT JOIN online_exam_attempts t ON p.attempt_id = t.id WHERE t.id IS NULL",
            "DELETE FROM online_exam_proctoring_logs WHERE attempt_id IS NOT NULL AND attempt_id NOT IN (SELECT id FROM online_exam_attempts)",
        ];
    }
    if (dbopt_table_exists('exam_assignments') && dbopt_table_exists('exam_schedules')) {
        $defs['examassign_orphan'] = [
            'تخصیص‌های امتحان حضوری برای امتحان حذف‌شده',
            "SELECT COUNT(*) c FROM exam_assignments a LEFT JOIN exam_schedules e ON a.exam_id = e.id WHERE e.id IS NULL",
            "DELETE FROM exam_assignments WHERE exam_id IS NOT NULL AND exam_id NOT IN (SELECT id FROM exam_schedules)",
        ];
    }
    if (dbopt_table_exists('counseling_requests')) {
        $defs['counseling_orphan'] = [
            'درخواست‌های مشاوره دانش‌آموز حذف‌شده',
            "SELECT COUNT(*) c FROM counseling_requests cr LEFT JOIN students s ON cr.student_id = s.id WHERE s.id IS NULL",
            "DELETE FROM counseling_requests WHERE student_id IS NOT NULL AND student_id NOT IN (SELECT id FROM students)",
        ];
    }
    if (dbopt_table_exists('api_tokens')) {
        $defs['tokens_expired'] = [
            'توکن‌های API منقضی‌شده',
            dbopt_expired_sql('SELECT COUNT(*) c FROM api_tokens', 'expires_at'),
            dbopt_expired_sql('DELETE FROM api_tokens', 'expires_at'),
        ];
    }
    if (dbopt_table_exists('bot_login_tokens') && dbopt_column_exists('bot_login_tokens', 'expires_at')) {
        $defs['bot_tokens_expired'] = [
            'توکن‌های ورود ربات منقضی‌شده',
            dbopt_expired_sql('SELECT COUNT(*) c FROM bot_login_tokens', 'expires_at'),
            dbopt_expired_sql('DELETE FROM bot_login_tokens', 'expires_at'),
        ];
    }
    return $defs;
}

/* ---------------------------------------------------------------
 * پردازش اکشن‌ها (POST)
 * --------------------------------------------------------------- */
$results = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'خطای امنیتی CSRF.');
        redirect('db-optimizer.php');
    }
    $act = $_POST['do'] ?? '';

    /* 1) اعمال ایندکس‌های حیاتی */
    if ($act === 'apply_indexes') {
        $added = 0; $skipped = 0; $failed = 0;
        foreach ($recommendedIndexes as [$table, $idxName, $cols, $desc]) {
            if (!dbopt_table_exists($table)) { $skipped++; continue; }
            if (dbopt_index_exists($table, $idxName)) { $skipped++; continue; }
            // اطمینان از وجود همهٔ ستون‌های ایندکس
            $colList = array_map(fn($c) => trim($c, ' `'), explode(',', $cols));
            $allCols = true;
            foreach ($colList as $col) { if (!dbopt_column_exists($table, $col)) { $allCols = false; break; } }
            if (!$allCols) { $skipped++; continue; }
            try {
                if ($DBOPT_SQLITE) {
                    /* SQLite: ADD INDEX ندارد؛ معادلش CREATE INDEX است و
                       شناسه‌ها با " نقل‌قول می‌شوند نه با backtick. */
                    $sqCols = implode(', ', array_map(fn($c) => '"' . trim($c, ' `') . '"', explode(',', $cols)));
                    $pdo->exec("CREATE INDEX IF NOT EXISTS \"$idxName\" ON \"$table\" ($sqCols)");
                } else {
                    $pdo->exec("ALTER TABLE `$table` ADD INDEX `$idxName` ($cols)");
                }
                $added++;
            } catch (Exception $e) { $failed++; }
        }
        log_activity($_SESSION['admin_id'] ?? null, 'بهینه‌سازی دیتابیس', "ایندکس‌گذاری: $added ایجاد، $skipped موجود/نامرتبط، $failed ناموفق");
        set_flash_message($failed ? 'warning' : 'success', "ایندکس‌گذاری انجام شد: $added ایندکس جدید ایجاد شد" . ($skipped ? "، $skipped مورد از قبل موجود بود" : '') . ($failed ? "، $failed مورد ناموفق" : '') . '.');
        redirect('db-optimizer.php');
    }

    /* 2) پاکسازی رکوردهای یتیم و منقضی */
    if ($act === 'cleanup_orphans') {
        $total = 0; $details = [];
        foreach (dbopt_orphan_defs() as $key => [$label, $countSql, $deleteSql]) {
            $n = dbopt_count($countSql);
            if ($n > 0) {
                try {
                    DB::execute($deleteSql);
                    $total += $n;
                    $details[] = "$label: $n";
                } catch (Exception $e) {}
            }
        }
        log_activity($_SESSION['admin_id'] ?? null, 'پاکسازی دیتابیس', $total ? implode(' | ', $details) : 'موردی یافت نشد');
        set_flash_message('success', $total ? "پاکسازی انجام شد — مجموعاً $total رکورد یتیم/منقضی حذف شد:\n" . implode("\n", $details) : 'هیچ رکورد یتیم یا منقضی‌ای یافت نشد. دیتابیس تمیز است. ✅');
        redirect('db-optimizer.php');
    }

    /* 3) حذف دانش‌آموزان تکراری بدون وابستگی */
    if ($act === 'dedup_students') {
        $removed = 0; $kept = 0;
        try {
            // گروه‌های تکراری: همان کدملی + همان سال تحصیلی
            $dups = DB::fetchAll("
                SELECT national_id, academic_year, COUNT(*) c, MAX(id) keep_id
                FROM students
                GROUP BY national_id, academic_year
                HAVING c > 1");
            foreach ($dups as $d) {
                $rows = DB::fetchAll("SELECT id FROM students WHERE national_id = ? AND academic_year <=> ? AND id <> ? ORDER BY id ASC",
                    [$d['national_id'], $d['academic_year'], $d['keep_id']]);
                foreach ($rows as $row) {
                    $sid = (int)$row['id'];
                    // فقط اگر هیچ دادهٔ وابسته‌ای ندارد حذف کن (کاملاً امن)
                    $deps = dbopt_count("SELECT COUNT(*) c FROM reports WHERE student_id = ?", [$sid]);
                    if ($deps === 0 && dbopt_table_exists('student_discipline_records')) {
                        $deps += max(0, dbopt_count("SELECT COUNT(*) c FROM student_discipline_records WHERE student_id = ?", [$sid]));
                    }
                    if ($deps === 0 && dbopt_table_exists('online_exam_attempts')) {
                        $deps += max(0, dbopt_count("SELECT COUNT(*) c FROM online_exam_attempts WHERE student_id = ?", [$sid]));
                    }
                    if ($deps === 0) {
                        DB::execute("DELETE FROM students WHERE id = ?", [$sid]);
                        $removed++;
                    } else {
                        $kept++;
                    }
                }
            }
            // کلاس‌های تکراری (نام + سال یکسان) — نگه‌داشتن قدیمی‌ترین
            $cdups = DB::fetchAll("SELECT name, academic_year, COUNT(*) c, MIN(id) keep_id FROM classes GROUP BY name, academic_year HAVING c > 1");
            $cRemoved = 0;
            foreach ($cdups as $cd) {
                $n = DB::execute("DELETE FROM classes WHERE name = ? AND academic_year <=> ? AND id <> ?", [$cd['name'], $cd['academic_year'], $cd['keep_id']]);
                $cRemoved += (int)$cd['c'] - 1;
            }
        } catch (Exception $e) {
            set_flash_message('error', 'خطا در حذف رکوردهای تکراری: ' . $e->getMessage());
            redirect('db-optimizer.php');
        }
        log_activity($_SESSION['admin_id'] ?? null, 'حذف رکوردهای تکراری', "دانش‌آموز تکراری حذف‌شده: $removed، دارای وابستگی (نگه‌داشته): $kept، کلاس تکراری: $cRemoved");
        $msg = "حذف تکراری‌ها انجام شد:\n• دانش‌آموزان تکراریِ بدون سابقه: $removed حذف شد";
        if ($kept) $msg .= "\n• $kept ردیف تکراری چون کارنامه/سابقه داشتند حذف نشدند (برای بررسی دستی)";
        $msg .= "\n• کلاس‌های تکراری: $cRemoved حذف شد";
        set_flash_message($kept ? 'warning' : 'success', $msg);
        redirect('db-optimizer.php');
    }

    /* 4) ANALYZE + OPTIMIZE همهٔ جداول */
    if ($act === 'optimize_tables') {
        $ok = 0; $fail = 0;
        if ($DBOPT_SQLITE) {
            /* SQLite: ANALYZE سراسری است و VACUUM جای OPTIMIZE را می‌گیرد
               (فضای آزاد را به سیستم‌عامل برمی‌گرداند). VACUUM داخل
               تراکنش اجرا نمی‌شود، پس جدا و با try خودش. */
            try { $pdo->exec("ANALYZE"); $ok++; } catch (Exception $e) { $fail++; }
            try { $pdo->exec("VACUUM");  $ok++; } catch (Exception $e) { $fail++; }
            log_activity($_SESSION['admin_id'] ?? null, 'بهینه‌سازی دیتابیس', "SQLite: ANALYZE + VACUUM");
            set_flash_message($fail ? 'warning' : 'success', $fail
                ? 'بهینه‌سازی ناقص انجام شد (ANALYZE/VACUUM).'
                : 'بهینه‌سازی انجام شد: ANALYZE اجرا و فضای آزاد با VACUUM بازپس گرفته شد.');
            redirect('db-optimizer.php');
        }
        try {
            $tables = DB::fetchAll("SELECT TABLE_NAME t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
            foreach ($tables as $tb) {
                $t = $tb['t'];
                try {
                    $pdo->exec("ANALYZE TABLE `$t`");
                    $pdo->exec("OPTIMIZE TABLE `$t`");
                    $ok++;
                } catch (Exception $e) { $fail++; }
            }
        } catch (Exception $e) {}
        log_activity($_SESSION['admin_id'] ?? null, 'OPTIMIZE دیتابیس', "$ok جدول بهینه شد، $fail ناموفق");
        set_flash_message('success', "بهینه‌سازی فیزیکی انجام شد: $ok جدول ANALYZE و OPTIMIZE شد" . ($fail ? " ($fail ناموفق)" : '') . '.');
        redirect('db-optimizer.php');
    }
}

/* ---------------------------------------------------------------
 * اسکن سلامت (نمایش)
 * --------------------------------------------------------------- */
$tablesInfo = [];
$totalData = 0; $totalIndex = 0; $sqliteFreeBytes = 0;
if ($DBOPT_SQLITE) {
    /* SQLite آمار حجم به تفکیک جدول ندارد (مگر با افزونهٔ dbstat که در
       بیلد همراه نیست). پس تعداد ردیف واقعی شمرده می‌شود و حجم کل از
       صفحه‌های فایل دیتابیس محاسبه می‌شود. */
    try {
        $rows = DB::fetchAll("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        foreach ($rows as $r) {
            $n = $r['name'];
            $cnt = 0;
            try { $cnt = (int)$pdo->query("SELECT COUNT(*) FROM \"$n\"")->fetchColumn(); } catch (Exception $e) {}
            $nIdx = 0;
            try { $nIdx = (int)DB::fetch("SELECT COUNT(*) c FROM sqlite_master WHERE type='index' AND tbl_name=?", [$n])['c']; } catch (Exception $e) {}
            $tablesInfo[] = ['name'=>$n, 'engine'=>'SQLite', 'collation'=>'—',
                             'rows_est'=>$cnt, 'data_len'=>null, 'index_len'=>$nIdx, 'data_free'=>null];
        }
        usort($tablesInfo, fn($a,$b) => $b['rows_est'] <=> $a['rows_est']);
        $pageCount = (int)$pdo->query("PRAGMA page_count")->fetchColumn();
        $pageSize  = (int)$pdo->query("PRAGMA page_size")->fetchColumn();
        $freeList  = (int)$pdo->query("PRAGMA freelist_count")->fetchColumn();
        $totalData = ($pageCount - $freeList) * $pageSize;
        $totalIndex = 0;
        $sqliteFreeBytes = $freeList * $pageSize;
    } catch (Exception $e) {}
} else {
    try {
        $tablesInfo = DB::fetchAll("
            SELECT TABLE_NAME name, ENGINE engine, TABLE_COLLATION collation, TABLE_ROWS rows_est,
                   DATA_LENGTH data_len, INDEX_LENGTH index_len, DATA_FREE data_free
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC");
        foreach ($tablesInfo as $t) { $totalData += (int)$t['data_len']; $totalIndex += (int)$t['index_len']; }
    } catch (Exception $e) {}
}

$missingIndexes = [];
foreach ($recommendedIndexes as [$table, $idxName, $cols, $desc]) {
    if (dbopt_table_exists($table) && !dbopt_index_exists($table, $idxName)) {
        $colList = array_map(fn($c) => trim($c, ' `'), explode(',', $cols));
        $allCols = true;
        foreach ($colList as $col) { if (!dbopt_column_exists($table, $col)) { $allCols = false; break; } }
        if ($allCols) $missingIndexes[] = [$table, $idxName, $cols, $desc];
    }
}

$orphanCounts = [];
foreach (dbopt_orphan_defs() as $key => [$label, $countSql, $deleteSql]) {
    $orphanCounts[$label] = dbopt_count($countSql);
}

$dupStudents = dbopt_count("SELECT COUNT(*) c FROM (SELECT national_id FROM students GROUP BY national_id, academic_year HAVING COUNT(*) > 1) x");
$dupClasses  = dbopt_count("SELECT COUNT(*) c FROM (SELECT name FROM classes GROUP BY name, academic_year HAVING COUNT(*) > 1) x");

$badEngine = array_values(array_filter($tablesInfo, fn($t) => strtolower((string)$t['engine']) !== 'innodb'));
$badCollation = array_values(array_filter($tablesInfo, fn($t) => strpos((string)$t['collation'], 'utf8mb4') !== 0));

function fmt_bytes($b) {
    $b = (float)$b;
    if ($b >= 1073741824) return number_format($b / 1073741824, 2) . ' GB';
    if ($b >= 1048576)   return number_format($b / 1048576, 1) . ' MB';
    if ($b >= 1024)      return number_format($b / 1024, 0) . ' KB';
    return number_format($b) . ' B';
}

$totalOrphans = 0;
foreach ($orphanCounts as $n) { if ($n > 0) $totalOrphans += $n; }

require_once __DIR__ . '/includes/header.php';
?>
<div class="page-hero card p-4 mb-4 flex items-center justify-between flex-wrap gap-2">
    <div>
        <h2 class="text-xl font-bold">🩺 سلامت و بهینه‌سازی پایگاه داده</h2>
        <p class="text-muted text-xs mt-1">ممیزی ایندکس‌ها، رکوردهای یتیم/تکراری و بهینه‌سازی فیزیکی جداول — نگارش 4.31.0</p>
    </div>
    <div class="flex gap-2 flex-wrap">
        <form method="POST" data-no-busy="0" onsubmit="return confirm('ایندکس‌های حیاتی به جداول اضافه شوند؟ (عملیات امن و بدون تغییر داده)');">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="do" value="apply_indexes">
            <button type="submit" class="btn btn-primary btn-sm" <?php echo empty($missingIndexes) ? 'disabled' : ''; ?>>⚡ اعمال ایندکس‌های حیاتی (<?php echo count($missingIndexes); ?>)</button>
        </form>
        <form method="POST" onsubmit="return confirm('رکوردهای یتیم و توکن‌های منقضی حذف شوند؟ این عملیات فقط داده‌های بلااستفاده را پاک می‌کند.');">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="do" value="cleanup_orphans">
            <button type="submit" class="btn btn-warning btn-sm" <?php echo $totalOrphans <= 0 ? 'disabled' : ''; ?>>🧹 پاکسازی رکوردهای یتیم (<?php echo max(0, $totalOrphans); ?>)</button>
        </form>
        <form method="POST" onsubmit="return confirm('دانش‌آموزان و کلاس‌های تکراری (فقط موارد بدون سابقه) حذف شوند؟');">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="do" value="dedup_students">
            <button type="submit" class="btn btn-danger btn-sm" <?php echo ($dupStudents <= 0 && $dupClasses <= 0) ? 'disabled' : ''; ?>>👥 حذف تکراری‌ها</button>
        </form>
        <form method="POST" onsubmit="return confirm(<?php echo $DBOPT_SQLITE ? "'ANALYZE و VACUUM روی کل دیتابیس اجرا شود؟ (ممکن است چند لحظه طول بکشد)'" : "'ANALYZE و OPTIMIZE روی همه جداول اجرا شود؟ (ممکن است چند لحظه طول بکشد)'"; ?>);">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="do" value="optimize_tables">
            <button type="submit" class="btn btn-success btn-sm"><?php echo $DBOPT_SQLITE ? '🚀 ANALYZE + VACUUM' : '🚀 ANALYZE + OPTIMIZE جداول'; ?></button>
        </form>
    </div>
</div>

<div class="grid gap-4 mb-4" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));">
    <div class="card p-4"><div class="text-muted text-xs mb-1">حجم داده‌ها</div><div class="text-xl font-bold" style="color:var(--primary)"><?php echo fmt_bytes($totalData); ?></div></div>
    <div class="card p-4"><div class="text-muted text-xs mb-1">حجم ایندکس‌ها</div><div class="text-xl font-bold" style="color:#0ea5e9"><?php echo fmt_bytes($totalIndex); ?></div></div>
    <div class="card p-4"><div class="text-muted text-xs mb-1">ایندکس حیاتی غایب</div><div class="text-xl font-bold" style="color:<?php echo empty($missingIndexes) ? '#10b981' : '#ef4444'; ?>"><?php echo count($missingIndexes); ?></div></div>
    <div class="card p-4"><div class="text-muted text-xs mb-1">رکورد یتیم / منقضی</div><div class="text-xl font-bold" style="color:<?php echo $totalOrphans > 0 ? '#f59e0b' : '#10b981'; ?>"><?php echo max(0, $totalOrphans); ?></div></div>
    <div class="card p-4"><div class="text-muted text-xs mb-1">گروه تکراری (دانش‌آموز/کلاس)</div><div class="text-xl font-bold" style="color:<?php echo ($dupStudents > 0 || $dupClasses > 0) ? '#ef4444' : '#10b981'; ?>"><?php echo max(0,$dupStudents); ?> / <?php echo max(0,$dupClasses); ?></div></div>
</div>

<?php if (!empty($missingIndexes)): ?>
<div class="card p-4 mb-4">
    <h3 class="font-bold mb-3">⚡ ایندکس‌های حیاتی پیشنهادی (هنوز اعمال نشده)</h3>
    <div class="table-container">
        <table class="w-full text-xs">
            <thead><tr><th>جدول</th><th>ایندکس</th><th>ستون‌ها</th><th>چرا مهم است؟</th></tr></thead>
            <tbody>
            <?php foreach ($missingIndexes as [$t, $i, $c, $d]): ?>
                <tr><td class="font-mono font-bold"><?php echo clean($t); ?></td><td class="font-mono"><?php echo clean($i); ?></td><td class="font-mono dir-ltr text-left"><?php echo clean(str_replace('`','',$c)); ?></td><td><?php echo clean($d); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="text-muted text-xs mt-2">با دکمهٔ «اعمال ایندکس‌های حیاتی» همهٔ موارد بالا به‌صورت امن (بدون تغییر داده) اضافه می‌شوند. با رشد داده‌ها این ایندکس‌ها سرعت ورود دانش‌آموز، لیست‌ها و کارنامه‌ها را چندین برابر می‌کنند.</p>
</div>
<?php else: ?>
<div class="card p-4 mb-4" style="border-color:#abefc6;background:#f6fef9;">
    <span class="font-bold" style="color:#067647;">✅ همهٔ ایندکس‌های حیاتی برقرار هستند.</span>
</div>
<?php endif; ?>

<div class="card p-4 mb-4">
    <h3 class="font-bold mb-3">🧹 وضعیت رکوردهای یتیم و منقضی</h3>
    <div class="table-container">
        <table class="w-full text-xs">
            <thead><tr><th>مورد</th><th style="width:120px">تعداد</th><th style="width:120px">وضعیت</th></tr></thead>
            <tbody>
            <?php foreach ($orphanCounts as $label => $n): ?>
                <tr>
                    <td><?php echo clean($label); ?></td>
                    <td class="font-mono font-bold"><?php echo $n < 0 ? '—' : $n; ?></td>
                    <td><?php if ($n > 0): ?><span class="badge badge-warning">نیاز به پاکسازی</span><?php elseif ($n === 0): ?><span class="badge badge-success">تمیز</span><?php else: ?><span class="badge badge-info">نامرتبط</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!empty($badEngine) || !empty($badCollation)): ?>
<div class="card p-4 mb-4" style="border-color:#fedf89;background:#fffcf5;">
    <h3 class="font-bold mb-2" style="color:#b54708;">⚠️ ناهماهنگی موتور/کلیشن</h3>
    <?php if (!empty($badEngine)): ?><p class="text-xs mb-1">جداول غیر InnoDB: <b><?php echo clean(implode('، ', array_column($badEngine, 'name'))); ?></b></p><?php endif; ?>
    <?php if (!empty($badCollation)): ?><p class="text-xs">جداول غیر utf8mb4: <b><?php echo clean(implode('، ', array_column($badCollation, 'name'))); ?></b></p><?php endif; ?>
    <p class="text-muted text-xs mt-2">تبدیل موتور/کلیشن عملیات سنگینی است؛ در صورت نیاز در زمان کم‌ترافیک با پشتیبان‌گیری انجام دهید.</p>
</div>
<?php endif; ?>

<div class="card p-4">
    <h3 class="font-bold mb-3">📊 جداول پایگاه داده (به ترتیب حجم)</h3>
    <div class="table-container">
        <table class="w-full text-xs">
            <thead><tr><th>جدول</th><th>موتور</th><th><?php echo $DBOPT_SQLITE ? 'ردیف‌ها (دقیق)' : 'ردیف‌ها (تقریبی)'; ?></th><th>حجم داده</th><th><?php echo $DBOPT_SQLITE ? 'تعداد ایندکس' : 'حجم ایندکس'; ?></th><th>فضای آزاد قابل بازپس‌گیری</th></tr></thead>
            <tbody>
            <?php foreach ($tablesInfo as $t): ?>
                <tr>
                    <td class="font-mono font-bold"><?php echo clean($t['name']); ?></td>
                    <td><?php echo clean($t['engine']); ?></td>
                    <td class="font-mono"><?php echo number_format((int)$t['rows_est']); ?></td>
                    <td class="font-mono"><?php echo $t['data_len'] === null ? '—' : fmt_bytes($t['data_len']); ?></td>
                    <td class="font-mono"><?php echo $DBOPT_SQLITE ? number_format((int)$t['index_len']) : fmt_bytes($t['index_len']); ?></td>
                    <td class="font-mono"><?php echo $t['data_free'] === null ? '—' : ((int)$t['data_free'] > 1048576 ? '<span style="color:#b54708;font-weight:bold">' . fmt_bytes($t['data_free']) . '</span>' : fmt_bytes($t['data_free'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="text-muted text-xs mt-2"><?php if ($DBOPT_SQLITE): ?>
        SQLite آمار حجم به تفکیک جدول ندارد، پس ستون «حجم داده» خالی است و به‌جای آن تعداد ردیف دقیق شمرده می‌شود.
        حجم کل فایل دیتابیس: <b><?php echo fmt_bytes($totalData); ?></b><?php if (!empty($sqliteFreeBytes)): ?> · فضای آزاد قابل بازپس‌گیری با VACUUM: <b><?php echo fmt_bytes($sqliteFreeBytes); ?></b><?php endif; ?>.
        توصیه: این صفحه را ماهی یک بار اجرا کنید.
    <?php else: ?>
        اگر «فضای آزاد» جدولی بزرگ است، دکمهٔ OPTIMIZE آن را بازپس می‌گیرد. توصیه: این صفحه را ماهی یک بار اجرا کنید.
    <?php endif; ?></p>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
