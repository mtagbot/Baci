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

require_once __DIR__.'/includes/db_health.php';
$pdo = DB::getInstance()->getPdo();
$dboptErrors=[]; $dboptIndexCache=[];
$detailedScan=(($_GET['scan']??'')==='1');

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
try { $driver=$pdo?$pdo->getAttribute(PDO::ATTR_DRIVER_NAME):''; if (!in_array($driver,['sqlite','mysql'],true)) throw new RuntimeException('driver'); $DBOPT_SQLITE=($driver==='sqlite'); }
catch (Throwable $e) { http_response_code(503); exit('نوع پایگاه داده قابل تشخیص نیست؛ هیچ عملیات بهینه‌سازی انجام نشد.'); }
$GLOBALS['__dbopt_pdo'] = $pdo;   /* توابع کمکی به PDO خام نیاز دارند (PRAGMA) */

$dbName = null;
if ($DBOPT_SQLITE) {
    $dbName = 'SQLite';
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
    } catch (Exception $e) { $GLOBALS['dboptErrors']['tables']='فهرست جدول‌ها کامل خوانده نشد.'; return false; }
}
function dbopt_column_exists($table, $column) {
    global $DBOPT_SQLITE,$pdo,$dboptErrors;
    static $cache=[];
    try {
        if (!isset($cache[$table])) {
            if ($DBOPT_SQLITE) $cache[$table]=array_column($pdo->query('PRAGMA table_info('.dbh_quote($table,true).')')->fetchAll(PDO::FETCH_ASSOC),'name');
            else $cache[$table]=array_column(DB::fetchAll('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]),'COLUMN_NAME');
        }
        return in_array($column,$cache[$table],true);
    } catch (Throwable $e) { $dboptErrors['columns']='اطلاعات ستون‌ها کامل خوانده نشد.'; return false; }
}
function dbopt_index_exists($table, $indexName) {
    global $DBOPT_SQLITE,$pdo,$recommendedIndexes,$dboptIndexCache,$dboptErrors;
    try {
        if (!isset($dboptIndexCache[$table])) $dboptIndexCache[$table]=dbh_index_metadata($pdo,$DBOPT_SQLITE,$table);
        foreach ($recommendedIndexes as [$t,$i,$cols]) if ($t===$table && $i===$indexName) {
            $columns=array_map(function($c){return trim($c,' `');},explode(',',$cols));
            return dbh_index_covers($dboptIndexCache[$table],$columns);
        }
        return isset($dboptIndexCache[$table][$indexName]);
    } catch (Throwable $e) { $dboptErrors['indexes']='خواندن اطلاعات ایندکس‌ها ناموفق بود؛ وضعیت نامشخص است.'; return null; }
}
function dbopt_count($sql, $params = []) {
    try { $r = DB::fetch($sql, $params); return (int)($r['c'] ?? 0); } catch (Exception $e) { $GLOBALS['dboptErrors']['counts']='برخی شمارش‌ها ناموفق بودند؛ نتیجهٔ آن‌ها سالم فرض نمی‌شود.'; return -1; }
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
    if (!is_string($_POST['csrf_token']??null) || !verify_csrf($_POST['csrf_token'])) {
        set_flash_message('error', 'خطای امنیتی CSRF.');
        redirect('db-optimizer.php?'.(!empty($_GET['embedded'])?'embedded=1&':'').'scan=1');
    }
    $act = $_POST['do'] ?? '';

    /* 1) اعمال ایندکس‌های حیاتی */
    if ($act === 'apply_indexes') {
        $added = 0; $skipped = 0; $failed = 0;
        foreach ($recommendedIndexes as [$table, $idxName, $cols, $desc]) {
            if (!dbopt_table_exists($table)) { $skipped++; continue; }
            $coverage=dbopt_index_exists($table,$idxName);
            if ($coverage===null) { $failed++; continue; }
            if ($coverage) { $skipped++; continue; }
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
                    $pdo->exec("CREATE INDEX IF NOT EXISTS \"dbopt_{$table}_{$idxName}\" ON \"$table\" ($sqCols)");
                } else {
                    $pdo->exec("ALTER TABLE `$table` ADD INDEX `$idxName` ($cols)");
                }
                unset($dboptIndexCache[$table]);
                if (dbopt_index_exists($table,$idxName)!==true) throw new RuntimeException('Index creation not verified');
                $added++;
            } catch (Exception $e) { $failed++; }
        }
        log_activity($_SESSION['admin_id'] ?? null, 'بهینه‌سازی دیتابیس', "ایندکس‌گذاری: $added ایجاد، $skipped موجود/نامرتبط، $failed ناموفق");
        set_flash_message($failed ? 'warning' : 'success', "ایندکس‌گذاری انجام شد: $added ایندکس جدید ایجاد شد" . ($skipped ? "، $skipped مورد از قبل موجود بود" : '') . ($failed ? "، $failed مورد ناموفق" : '') . '.');
        redirect('db-optimizer.php?'.(!empty($_GET['embedded'])?'embedded=1&':'').'scan=1');
    }

    /* 2) پاکسازی رکوردهای یتیم و منقضی */
    if ($act === 'cleanup_orphans') {
        $total = 0; $details = []; $failed=0;
        foreach (dbopt_orphan_defs() as $key => [$label, $countSql, $deleteSql]) {
            $n = dbopt_count($countSql);
            if ($n > 0) {
                try {
                    $stmt=DB::query($deleteSql);
                    $n=$stmt?$stmt->rowCount():0; $total += $n;
                    $details[] = "$label: $n";
                } catch (Exception $e) { $failed++; }
            } elseif ($n<0) $failed++;
        }
        log_activity($_SESSION['admin_id'] ?? null, 'پاکسازی دیتابیس', $total ? implode(' | ', $details) : 'موردی یافت نشد');
        set_flash_message($failed?'warning':'success', $failed?'پاکسازی کامل نشد؛ برخی بررسی‌ها یا حذف‌ها ناموفق بودند. تعداد حذف‌شده: '.$total : ($total ? "پاکسازی انجام شد — مجموعاً $total رکورد یتیم/منقضی حذف شد:\n" . implode("\n", $details) : 'هیچ رکورد یتیم یا منقضی‌ای یافت نشد. دیتابیس در بررسی‌های انجام‌شده تمیز است.'));
        redirect('db-optimizer.php?'.(!empty($_GET['embedded'])?'embedded=1&':'').'scan=1');
    }

    /* 3) حذف دانش‌آموزان تکراری بدون وابستگی */
    if ($act === 'dedup_students') {
        $removed = 0; $kept = 0;
        try {
            // گروه‌های تکراری: همان کدملی + همان سال تحصیلی
            $dups = DB::fetchAll("
                SELECT national_id, academic_year, COUNT(*) c, MAX(id) keep_id
                FROM students
                WHERE national_id IS NOT NULL AND national_id<>''
                GROUP BY national_id, academic_year
                HAVING c > 1");
            foreach ($dups as $d) {
                $rows = DB::fetchAll("SELECT id FROM students WHERE national_id = ? AND academic_year <=> ? AND id <> ? ORDER BY id ASC",
                    [$d['national_id'], $d['academic_year'], $d['keep_id']]);
                foreach ($rows as $row) {
                    $sid = (int)$row['id'];
                    $deps=dbh_has_references($pdo,$DBOPT_SQLITE,'students',$sid)?1:0;
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
                $original=DB::fetch('SELECT * FROM classes WHERE id=?',[$cd['keep_id']]); unset($original['id']);
                foreach (DB::fetchAll('SELECT * FROM classes WHERE name=? AND academic_year <=> ? AND id<>?',[$cd['name'],$cd['academic_year'],$cd['keep_id']]) as $duplicate) {
                    $cid=$duplicate['id']; unset($duplicate['id']);
                    if ($original!=$duplicate || dbh_has_references($pdo,$DBOPT_SQLITE,'classes',$cid)) continue;
                    $stmt=DB::query('DELETE FROM classes WHERE id=?',[$cid]); $cRemoved+=$stmt->rowCount();
                }
            }
        } catch (Exception $e) {
            set_flash_message('error', 'بررسی یا حذف تکراری‌ها کامل نشد؛ برای حفاظت از داده، ادامهٔ عملیات متوقف شد.');
            redirect('db-optimizer.php?'.(!empty($_GET['embedded'])?'embedded=1&':'').'scan=1');
        }
        log_activity($_SESSION['admin_id'] ?? null, 'حذف رکوردهای تکراری', "دانش‌آموز تکراری حذف‌شده: $removed، دارای وابستگی (نگه‌داشته): $kept، کلاس تکراری: $cRemoved");
        $msg = "حذف تکراری‌ها انجام شد:\n• دانش‌آموزان تکراریِ بدون سابقه: $removed حذف شد";
        if ($kept) $msg .= "\n• $kept ردیف تکراری چون کارنامه/سابقه داشتند حذف نشدند (برای بررسی دستی)";
        $msg .= "\n• کلاس‌های تکراری: $cRemoved حذف شد";
        set_flash_message($kept ? 'warning' : 'success', $msg);
        redirect('db-optimizer.php?'.(!empty($_GET['embedded'])?'embedded=1&':'').'scan=1');
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
            redirect('db-optimizer.php?'.(!empty($_GET['embedded'])?'embedded=1&':'').'scan=1');
        }
        try {
            $tables = DB::fetchAll("SELECT TABLE_NAME t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
            foreach ($tables as $tb) {
                $t = $tb['t'];
                try {
                    foreach (['ANALYZE','OPTIMIZE'] as $op) {
                        $messages=$pdo->query($op.' TABLE '.dbh_quote($t,false))->fetchAll(PDO::FETCH_ASSOC);
                        if (!dbh_maintenance_ok($messages)) throw new RuntimeException('Maintenance not confirmed');
                    }
                    $ok++;
                } catch (Exception $e) { $fail++; }
            }
        } catch (Exception $e) { $fail++; }
        log_activity($_SESSION['admin_id'] ?? null, 'OPTIMIZE دیتابیس', "$ok جدول بهینه شد، $fail ناموفق");
        set_flash_message($fail?'warning':'success', "بهینه‌سازی فیزیکی انجام شد: $ok جدول ANALYZE و OPTIMIZE شد" . ($fail ? " ($fail ناموفق)" : '') . '.');
        redirect('db-optimizer.php?'.(!empty($_GET['embedded'])?'embedded=1&':'').'scan=1');
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
            $cnt = null;
            if ($detailedScan) { try { $cnt=(int)$pdo->query('SELECT COUNT(*) FROM '.dbh_quote($n,true))->fetchColumn(); } catch (Exception $e) { $dboptErrors['counts']='شمارش بعضی جدول‌ها ناموفق بود.'; } }
            $nIdx = 0;
            try { $nIdx = (int)DB::fetch("SELECT COUNT(*) c FROM sqlite_master WHERE type='index' AND tbl_name=?", [$n])['c']; } catch (Exception $e) {}
            $tablesInfo[] = ['name'=>$n, 'engine'=>'SQLite', 'collation'=>'—',
                             'rows_est'=>$cnt, 'data_len'=>null, 'index_len'=>$nIdx, 'data_free'=>null];
        }
        if ($detailedScan) usort($tablesInfo, fn($a,$b) => $b['rows_est'] <=> $a['rows_est']);
        $pageCount = (int)$pdo->query("PRAGMA page_count")->fetchColumn();
        $pageSize  = (int)$pdo->query("PRAGMA page_size")->fetchColumn();
        $freeList  = (int)$pdo->query("PRAGMA freelist_count")->fetchColumn();
        $totalData = $pageCount * $pageSize;
        $totalIndex = 0;
        $sqliteFreeBytes = $freeList * $pageSize;
    } catch (Exception $e) { $dboptErrors['tables']='خواندن فرادادهٔ SQLite کامل نشد.'; }
} else {
    try {
        $tablesInfo = DB::fetchAll("
            SELECT TABLE_NAME name, ENGINE engine, TABLE_COLLATION collation, TABLE_ROWS rows_est,
                   DATA_LENGTH data_len, INDEX_LENGTH index_len, DATA_FREE data_free
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC");
        foreach ($tablesInfo as $t) { $totalData += (int)$t['data_len']; $totalIndex += (int)$t['index_len']; }
    } catch (Exception $e) { $dboptErrors['tables']='خواندن فرادادهٔ MySQL کامل نشد.'; }
}

$missingIndexes = [];
foreach ($recommendedIndexes as [$table, $idxName, $cols, $desc]) {
    if (dbopt_table_exists($table) && dbopt_index_exists($table, $idxName)===false) {
        $colList = array_map(fn($c) => trim($c, ' `'), explode(',', $cols));
        $allCols = true;
        foreach ($colList as $col) { if (!dbopt_column_exists($table, $col)) { $allCols = false; break; } }
        if ($allCols) $missingIndexes[] = [$table, $idxName, $cols, $desc];
    }
}

$orphanCounts = [];
if ($detailedScan) foreach (dbopt_orphan_defs() as $key => [$label, $countSql, $deleteSql]) {
    $orphanCounts[$label] = dbopt_count($countSql);
}

$dupStudents = $detailedScan ? dbopt_count("SELECT COUNT(*) c FROM (SELECT national_id FROM students GROUP BY national_id, academic_year HAVING COUNT(*) > 1) x") : -1;
$dupClasses  = $detailedScan ? dbopt_count("SELECT COUNT(*) c FROM (SELECT name FROM classes GROUP BY name, academic_year HAVING COUNT(*) > 1) x") : -1;

$engineWarnings=dbh_engine_warnings($driver,$tablesInfo);
$badEngine=$engineWarnings['engine']; $badCollation=$engineWarnings['collation'];
if ($engineWarnings['unknown']) $dboptErrors['engine']='اطلاعات موتور/کلیشن برخی جدول‌ها نامشخص است.';
$integrity=null;
if ($DBOPT_SQLITE && $detailedScan) {
    try { $integrity=$pdo->query('PRAGMA quick_check')->fetchAll(PDO::FETCH_COLUMN); }
    catch (Throwable $e) { $dboptErrors['integrity']='بررسی یکپارچگی SQLite انجام نشد.'; }
}

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
<section class="card p-4 mb-4" aria-label="نوع پایگاه داده">
<h2>سلامت پایگاه داده — <?php echo clean($DBOPT_SQLITE?'SQLite':'MySQL / MariaDB'); ?></h2>
<?php if ($DBOPT_SQLITE): ?><p>این اتصال از SQLite استفاده می‌کند. InnoDB و کلیشن utf8mb4 مخصوص MySQL هستند و برای این پایگاه داده قابل اعمال نیستند؛ نیازی به تبدیل موتور یا کلیشن نیست.</p><?php endif; ?>
<p>نمای اولیه فقط فراداده و ایندکس‌ها را بررسی می‌کند. اسکن کامل شامل شمارش رکوردها، بررسی تکراری‌ها و در SQLite بررسی یکپارچگی است؛ روی پایگاه بزرگ ممکن است زمان‌بر باشد. بازکردن صفحه هیچ بهینه‌سازی یا حذف خودکاری اجرا نمی‌کند.</p>
<a class="btn btn-outline" href="db-optimizer.php?scan=1<?php echo !empty($_GET['embedded'])?'&amp;embedded=1':''; ?>">اجرای اسکن کامل (فقط خواندنی)</a>
<p>پیش از عملیات نوشتاری پشتیبان بگیرید. ایجاد ایندکس و بازسازی فیزیکی را در زمان کم‌ترافیک انجام دهید؛ بهبود سرعت وابسته به کوئری و حجم داده است.</p>
<?php if ($integrity!==null): ?><p role="status"><?php echo $integrity===['ok']?'بررسی یکپارچگی SQLite: سالم در این بررسی.':'بررسی یکپارچگی SQLite خطا گزارش کرد؛ نوشتن را متوقف و نسخهٔ پشتیبان را با متخصص بررسی کنید. تبدیل موتور راه‌حل این خطا نیست.'; ?></p><?php endif; ?>
<?php foreach (array_unique($dboptErrors) as $error): ?><p role="alert"><?php echo clean($error); ?></p><?php endforeach; ?>
</section>
<div class="page-hero card p-4 mb-4 flex items-center justify-between flex-wrap gap-2">
    <div>
        <h2 class="text-xl font-bold"><svg data-ui-icon="health" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 21S-1 13 3 5c3-5 9 0 9 0s6-5 9 0c4 8-9 16-9 16Z"/><path d="M8 12h8m-4-4v8"/></svg> سلامت و بهینه‌سازی پایگاه داده</h2>
        <p class="text-muted text-xs mt-1">ممیزی ایندکس‌ها، رکوردهای یتیم/تکراری و بهینه‌سازی فیزیکی جداول — نگارش 4.31.0</p>
    </div>
    <div class="flex gap-2 flex-wrap">
        <form method="POST" data-no-busy="0" onsubmit="return confirm('ایندکس‌های حیاتی به جداول اضافه شوند؟ (بدون تغییر رکوردها؛ نیازمند زمان و فضای دیسک)');">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="do" value="apply_indexes">
            <button type="submit" class="btn btn-primary btn-sm" <?php echo (empty($missingIndexes)||$dboptErrors) ? 'disabled' : ''; ?>><svg data-ui-icon="bolt" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m14 2-11 12h8l-1 8 11-12h-8Z"/></svg> اعمال ایندکس‌های حیاتی (<?php echo count($missingIndexes); ?>)</button>
        </form>
        <form method="POST" onsubmit="return confirm('رکوردهای یتیم و توکن‌های منقضی حذف شوند؟ این عملیات فقط داده‌های بلااستفاده را پاک می‌کند.');">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="do" value="cleanup_orphans">
            <button type="submit" class="btn btn-warning btn-sm" <?php echo $totalOrphans <= 0 ? 'disabled' : ''; ?>><svg data-ui-icon="delete" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 6h18M9 6V3h6v3M5 6l1 16h12l1-16M10 10v8m4-8v8"/></svg> پاکسازی رکوردهای یتیم (<?php echo $detailedScan?max(0,$totalOrphans):'اسکن نشده'; ?>)</button>
        </form>
        <form method="POST" onsubmit="return confirm('دانش‌آموزان و کلاس‌های تکراری (فقط موارد بدون سابقه) حذف شوند؟');">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="do" value="dedup_students">
            <button type="submit" class="btn btn-danger btn-sm" <?php echo ($dupStudents <= 0 && $dupClasses <= 0) ? 'disabled' : ''; ?>><svg data-ui-icon="users" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="9" cy="7" r="3"/><path d="M2 21v-3c0-6 14-6 14 0v3M16 4c5 0 5 6 1 6M19 14c3 1 3 4 3 7"/></svg> حذف تکراری‌ها</button>
        </form>
        <form method="POST" onsubmit="return confirm(<?php echo $DBOPT_SQLITE ? "'ANALYZE و VACUUM روی کل دیتابیس اجرا شود؟ (ممکن است چند لحظه طول بکشد)'" : "'ANALYZE و OPTIMIZE روی همه جداول اجرا شود؟ (ممکن است چند لحظه طول بکشد)'"; ?>);">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="do" value="optimize_tables">
            <button type="submit" class="btn btn-success btn-sm"><?php echo $DBOPT_SQLITE ? 'ANALYZE + VACUUM' : 'ANALYZE + OPTIMIZE جداول'; ?></button>
        </form>
    </div>
</div>

<div class="grid gap-4 mb-4" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));">
    <div class="card p-4"><div class="text-muted text-xs mb-1">حجم داده‌ها</div><div class="text-xl font-bold" style="color:var(--primary)"><?php echo fmt_bytes($totalData); ?></div></div>
    <div class="card p-4"><div class="text-muted text-xs mb-1">حجم ایندکس‌ها</div><div class="text-xl font-bold" style="color:#0ea5e9"><?php echo $DBOPT_SQLITE?'جداگانه در دسترس نیست':fmt_bytes($totalIndex); ?></div></div>
    <div class="card p-4"><div class="text-muted text-xs mb-1">ایندکس حیاتی غایب</div><div class="text-xl font-bold" style="color:<?php echo empty($missingIndexes) ? '#10b981' : '#ef4444'; ?>"><?php echo count($missingIndexes); ?></div></div>
    <div class="card p-4"><div class="text-muted text-xs mb-1">رکورد یتیم / منقضی</div><div class="text-xl font-bold" style="color:<?php echo $totalOrphans > 0 ? '#f59e0b' : '#10b981'; ?>"><?php echo $detailedScan?max(0,$totalOrphans):'اسکن نشده'; ?></div></div>
    <div class="card p-4"><div class="text-muted text-xs mb-1">گروه تکراری (دانش‌آموز/کلاس)</div><div class="text-xl font-bold" style="color:<?php echo ($dupStudents > 0 || $dupClasses > 0) ? '#ef4444' : '#10b981'; ?>"><?php echo $detailedScan?($dupStudents<0?'نامشخص':$dupStudents).' / '.($dupClasses<0?'نامشخص':$dupClasses):'اسکن نشده'; ?></div></div>
</div>

<?php if (!empty($missingIndexes)): ?>
<div class="card p-4 mb-4">
    <h3 class="font-bold mb-3"><svg data-ui-icon="bolt" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m14 2-11 12h8l-1 8 11-12h-8Z"/></svg> ایندکس‌های حیاتی پیشنهادی (هنوز اعمال نشده)</h3>
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
    <p class="text-muted text-xs mt-2">با دکمهٔ «اعمال ایندکس‌های حیاتی» همهٔ موارد بالا بدون تغییر رکوردهای داده (با هزینهٔ ساخت ایندکس) اضافه می‌شوند. ایندکس هم‌ارز با نام دیگر نیز تشخیص داده می‌شود؛ افزایش سرعت تضمین‌شده نیست.</p>
</div>
<?php elseif (!$dboptErrors): ?>
<div class="card p-4 mb-4" style="border-color:#abefc6;background:#f6fef9;">
    <span class="font-bold" style="color:#067647;"><svg data-ui-icon="check" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="m7 12 3 3 7-7"/></svg> همهٔ ایندکس‌های حیاتی برقرار هستند.</span>
</div>
<?php endif; ?>

<div class="card p-4 mb-4">
    <h3 class="font-bold mb-3"><svg data-ui-icon="delete" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 6h18M9 6V3h6v3M5 6l1 16h12l1-16M10 10v8m4-8v8"/></svg> وضعیت رکوردهای یتیم و منقضی</h3>
    <div class="table-container">
        <table class="w-full text-xs">
            <thead><tr><th>مورد</th><th style="width:120px">تعداد</th><th style="width:120px">وضعیت</th></tr></thead>
            <tbody>
            <?php foreach ($orphanCounts as $label => $n): ?>
                <tr>
                    <td><?php echo clean($label); ?></td>
                    <td class="font-mono font-bold"><?php echo $n < 0 ? '—' : $n; ?></td>
                    <td><?php if ($n > 0): ?><span class="badge badge-warning">نیاز به پاکسازی</span><?php elseif ($n === 0): ?><span class="badge badge-success">تمیز</span><?php else: ?><span class="badge badge-info">بررسی ناموفق</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!empty($badEngine) || !empty($badCollation)): ?>
<div class="card p-4 mb-4" style="border-color:#fedf89;background:#fffcf5;">
    <h3 class="font-bold mb-2" style="color:#b54708;"><svg data-ui-icon="warning" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3 2 21h20Z"/><path d="M12 9v5m0 3v.1"/></svg> ناهماهنگی موتور/کلیشن</h3>
    <?php if (!empty($badEngine)): ?><p class="text-xs mb-1">جداول غیر InnoDB: <b><?php echo clean(implode('، ', array_column($badEngine, 'name'))); ?></b></p><?php endif; ?>
    <?php if (!empty($badCollation)): ?><p class="text-xs">جداول غیر utf8mb4: <b><?php echo clean(implode('، ', array_column($badCollation, 'name'))); ?></b></p><?php endif; ?>
    <p class="text-muted text-xs mt-2">تبدیل موتور/کلیشن عملیات سنگینی است؛ در صورت نیاز در زمان کم‌ترافیک با پشتیبان‌گیری انجام دهید.</p>
</div>
<?php endif; ?>

<div class="card p-4">
    <h3 class="font-bold mb-3"><svg data-ui-icon="chart" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 3v18h18M7 17v-4m5 4V8m5 9V4"/></svg> جداول پایگاه داده (به ترتیب حجم)</h3>
    <div class="table-container">
        <table class="w-full text-xs">
            <thead><tr><th>جدول</th><th>موتور</th><th><?php echo $DBOPT_SQLITE ? 'ردیف‌ها (دقیق)' : 'ردیف‌ها (تقریبی)'; ?></th><th>حجم داده</th><th><?php echo $DBOPT_SQLITE ? 'تعداد ایندکس' : 'حجم ایندکس'; ?></th><th>فضای آزاد قابل بازپس‌گیری</th></tr></thead>
            <tbody>
            <?php foreach ($tablesInfo as $t): ?>
                <tr>
                    <td class="font-mono font-bold"><?php echo clean($t['name']); ?></td>
                    <td><?php echo clean($t['engine']); ?></td>
                    <td class="font-mono"><?php echo $t['rows_est']===null?'اسکن نشده':number_format((int)$t['rows_est']); ?></td>
                    <td class="font-mono"><?php echo $t['data_len'] === null ? '—' : fmt_bytes($t['data_len']); ?></td>
                    <td class="font-mono"><?php echo $DBOPT_SQLITE ? number_format((int)$t['index_len']) : fmt_bytes($t['index_len']); ?></td>
                    <td class="font-mono"><?php echo $t['data_free'] === null ? '—' : ((int)$t['data_free'] > 1048576 ? '<span style="color:#b54708;font-weight:bold">' . fmt_bytes($t['data_free']) . '</span>' : fmt_bytes($t['data_free'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="text-muted text-xs mt-2"><?php if ($DBOPT_SQLITE): ?>
        SQLite آمار حجم به تفکیک جدول ندارد، پس ستون «حجم داده» خالی است؛ تعداد دقیق ردیف‌ها فقط در اسکن کامل شمرده می‌شود.
        حجم کل فایل دیتابیس: <b><?php echo fmt_bytes($totalData); ?></b><?php if (!empty($sqliteFreeBytes)): ?> · فضای آزاد قابل بازپس‌گیری با VACUUM: <b><?php echo fmt_bytes($sqliteFreeBytes); ?></b><?php endif; ?>.
        بازسازی فیزیکی را فقط در صورت نیاز، با پشتیبان و فضای دیسک کافی اجرا کنید.
    <?php else: ?>
        اگر «فضای آزاد» جدولی بزرگ است، دکمهٔ OPTIMIZE آن را بازپس می‌گیرد. بازسازی فیزیکی را فقط در صورت نیاز، با پشتیبان و فضای دیسک کافی اجرا کنید.
    <?php endif; ?></p>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
