<?php
// File: includes/db.php  (SchoolDesk Pro desktop runtime edition)
/**
 * Database Helper Class.
 *
 * Desktop build: runs the FULL site on an embedded SQLite database.
 * A translation shim (db_sqlite_compat.php) rewrites the MySQL-specific
 * SQL used across the codebase, so every feature — filters, prints,
 * exams, exam designer, report cards — behaves exactly like the
 * MySQL-backed web system.
 */

/* V4.209.2 — این دو فایل «اسکلت نصب Release_V1.0» هستند و روی نصب‌های قدیمی‌تر
   (که فقط با بسته‌های اصلاحی به‌روز شده‌اند) وجود ندارند. پیش‌تر require بی‌قید
   بود و روی چنین نصبی صفحه با «Failed opening required install_guard.php»
   کامل ۵۰۰ می‌شد؛ دقیقاً همان خطایی که روی هاست cPanel دیده شد. حالا اگر
   نباشند، نادیده گرفته می‌شوند: رفتار همان نسخهٔ قبل از Release_V1.0 است. */
if (is_file(__DIR__ . '/install_guard.php')) require_once __DIR__ . '/install_guard.php';
if (is_file(__DIR__ . '/db_sqlite_compat.php')) require_once __DIR__ . '/db_sqlite_compat.php';

/**
 * PDO subclass that transparently rewrites MySQL SQL into SQLite dialect.
 * Covers code that calls $pdo->query()/exec()/prepare() directly
 * (backups, migration-updater, db-optimizer, installer).
 */
class SQLitePDO extends PDO {
    /** بازنویسی SQL فقط اگر شیم SQLite نصب باشد (روی MySQL هرگز لازم نیست). */
    private static function rw($sql) {
        return class_exists('SQLiteCompat') ? SQLiteCompat::rewrite($sql) : $sql;
    }
    /** run a trusted multi-statement script with NO rewriting */
    public function rawScript($sql) {
        return parent::exec($sql);
    }
    #[\ReturnTypeWillChange]
    public function exec($sql) {
        return parent::exec(self::rw($sql));
    }
    #[\ReturnTypeWillChange]
    public function query($sql, $fetchMode = null, ...$args) {
        $sql = self::rw($sql);
        if ($fetchMode === null) return parent::query($sql);
        return parent::query($sql, $fetchMode, ...$args);
    }
    #[\ReturnTypeWillChange]
    public function prepare($sql, $options = []) {
        return parent::prepare(self::rw($sql), $options);
    }
}

class DB {
    private static $instance = null;
    private $pdo;
    private $error = null;
    private $isSqlite = false;

    private function __construct() {
        global $dbConfig;
        if (!isset($dbConfig) || empty($dbConfig)) {
            $configFile = dirname(__DIR__) . '/config/database.php';
            if (file_exists($configFile)) {
                $dbConfig = require $configFile;
            } else {
                throw new RuntimeException('Database configuration is missing; automatic fallback is disabled.');
            }
        }

        /* V4.209.2 — تحمل «شکل قدیمی» config/database.php
           ------------------------------------------------------------------
           نصب‌هایی که از نسخه‌های پیش از Release_V1.0 آمده‌اند، همین فایل را
           با کلیدهای دیگری نوشته‌اند (db_name/db_user/… یا بدون driver و
           port/charset). پیش‌تر این‌ها با «Invalid database configuration» یا
           «Incomplete database configuration» رد می‌شدند؛ یعنی بعد از رفع
           خطای install_guard، خطای بعدی همین بود.
           حالا شکل‌های قدیمی به همان شکل استاندارد نگاشت می‌شوند (بدون حدس
           رمز؛ فقط نام کلیدها و مقادیر پیش‌فرضِ بی‌خطر). */
        if (!is_array($dbConfig)) {
            throw new RuntimeException('Invalid database configuration; no implicit driver or credentials are allowed.');
        }
        $alias = [
            'database' => ['database', 'dbname', 'db_name', 'name'],
            'host'     => ['host', 'db_host', 'hostname', 'server'],
            'username' => ['username', 'user', 'db_user', 'db_username'],
            'password' => ['password', 'pass', 'db_pass', 'db_password'],
            'port'     => ['port', 'db_port'],
            'charset'  => ['charset', 'db_charset'],
        ];
        foreach ($alias as $canonical => $names) {
            if (array_key_exists($canonical, $dbConfig)) continue;
            foreach ($names as $name) {
                if (array_key_exists($name, $dbConfig)) { $dbConfig[$canonical] = $dbConfig[$name]; break; }
            }
        }
        if (!isset($dbConfig['driver']) || !in_array($dbConfig['driver'], ['sqlite','mysql'], true)) {
            /* استنتاج محافظ: مسیر فایل SQLite ⇒ sqlite؛ در غیر این صورت اگر
               شناسهٔ دیتابیس و میزبان هست ⇒ mysql؛ وگرنه خطا (مثل قبل). */
            $candidate = strtolower((string)($dbConfig['driver'] ?? ''));
            $pathLike = (string)($dbConfig['database'] ?? '');
            if ($candidate === 'sqlite3' || $candidate === 'sqlite' ||
                ($candidate === '' && preg_match('/\.(sqlite3?|db)$/i', $pathLike) && !isset($dbConfig['host']))) {
                $dbConfig['driver'] = 'sqlite';
            } elseif ($candidate === 'mysqli' || $candidate === 'mariadb') {
                $dbConfig['driver'] = 'mysql';
            } elseif ($candidate === '' && isset($dbConfig['host']) && (string)($dbConfig['database'] ?? '') !== '') {
                $dbConfig['driver'] = 'mysql';
            } else {
                throw new RuntimeException('Invalid database configuration; no implicit driver or credentials are allowed.');
            }
        }
        if ($dbConfig['driver'] === 'mysql') {
            /* مقادیر پیش‌فرض فقط برای کلیدهای «بی‌اثر»؛ نام کاربری و رمز
               هیچ‌گاه حدس زده نمی‌شوند. */
            if (!isset($dbConfig['host']) || (string)$dbConfig['host'] === '') $dbConfig['host'] = '127.0.0.1';
            if (!isset($dbConfig['port']) || (string)$dbConfig['port'] === '') $dbConfig['port'] = '3306';
            if (!isset($dbConfig['charset']) || (string)$dbConfig['charset'] === '') $dbConfig['charset'] = 'utf8mb4';
            foreach (['database','username','password'] as $field) {
                if (!array_key_exists($field, $dbConfig)) throw new RuntimeException('Incomplete database configuration.');
            }
        }
        try {
            if ($dbConfig['driver'] === 'sqlite') {
                $path = (string)($dbConfig['database'] ?? '');
                if ($path !== '' && !preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path)) {
                    $path = dirname(__DIR__) . '/' . ltrim($path, './');
                }
                if ($path === '' || !is_file($path) || filesize($path) === 0) throw new RuntimeException('The installed database is missing or empty. Restore a backup; it will not be recreated.');
                $fresh = !file_exists($path) || filesize($path) === 0;
                $this->pdo = new SQLitePDO('sqlite:' . $path);
                $this->isSqlite = true;
                $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $this->pdo->exec('PRAGMA journal_mode=WAL');
                $this->pdo->exec('PRAGMA synchronous=NORMAL');
                $this->pdo->exec('PRAGMA foreign_keys=OFF');
                $this->pdo->exec('PRAGMA busy_timeout=8000');
                if ($fresh) $this->initSqliteSchema();
                else $this->ensureSchemaCurrent();
            } else {
                $host = $dbConfig['host'] ?? '127.0.0.1';
                $port = $dbConfig['port'] ?? '3306';
                $dbname = $dbConfig['database'] ?? 'student_report_db';
                $charset = $dbConfig['charset'] ?? 'utf8mb4';
                $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
                $user = $dbConfig['username'] ?? 'root';
                $pass = $dbConfig['password'] ?? '';
                $this->pdo = new PDO($dsn, $user, $pass);
                $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            error_log('Release database error, SQLSTATE: '.$e->getCode());
            throw new RuntimeException('Database connection failed; no fallback database was created.');
        }
    }

    /** Historical method retained for API compatibility; release schema is structure-only. */
    private function initSqliteSchema() {
        $schema = dirname(__DIR__) . '/sql/schema-sqlite.sql';
        if (file_exists($schema)) {
            $this->pdo->rawScript(file_get_contents($schema));
        }
        $this->markSchemaVersion();
    }

    /** upgrades: run once per app version (cheap check on every boot) */
    private function ensureSchemaCurrent() {
        try {
            $cur = $this->pdo->query("SELECT key_value FROM settings WHERE key_name='desk_schema_version'")->fetchColumn();
        } catch (Exception $e) { $cur = ''; }
        if ($cur === $this->schemaVersion()) return;
        // apply idempotent schema file again (CREATE TABLE IF NOT EXISTS / INSERT OR IGNORE)
        $schema = dirname(__DIR__) . '/sql/schema-sqlite.sql';
        if (file_exists($schema)) {
            $sql = file_get_contents($schema);
            $this->pdo->exec("INSERT INTO desk_sync_suppress (flag) VALUES (1)");
            try { $this->pdo->rawScript($sql); }
            finally { $this->pdo->exec("DELETE FROM desk_sync_suppress"); }
        }
        $this->markSchemaVersion();
    }

    private function schemaVersion() {
        $schema = dirname(__DIR__) . '/sql/schema-sqlite.sql';
        return file_exists($schema) ? (string) filemtime($schema) . ':' . (string) filesize($schema) : '0';
    }

    private function markSchemaVersion() {
        try {
            $st = $this->pdo->prepare("INSERT INTO settings(key_name, key_value) VALUES('desk_schema_version', ?)
                                        ON CONFLICT(key_name) DO UPDATE SET key_value=excluded.key_value");
            $st->execute([$this->schemaVersion()]);
        } catch (Exception $e) {}
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new DB();
        }
        return self::$instance;
    }

    public function getPdo() { return $this->pdo; }
    public function getError() { return $this->error; }
    public static function isSqliteDriver() {
        return self::getInstance()->isSqlite;
    }

    /* ═══════════════════════════════════════════════════════════════════
       V4.208.0 — سنجه‌ها و حافظهٔ درون‌درخواستی
       ───────────────────────────────────────────────────────────────────
       هدف: ۱) شمردن کوئری‌ها و زمان SQL برای صفحهٔ «کارایی»
             ۲) حذف رفت‌وبرگشت‌های تکراریِ فقط-خواندنی در یک درخواست
             ۳) باطل‌کردن خودکار کش صفحه هنگام هر نوشتن
       هیچ‌کدام رفتار دادهٔ دیده‌شده را تغییر نمی‌دهد: حافظه در پایان درخواست
       می‌میرد و با هر INSERT/UPDATE/DELETE (حتی از مسیر execute) پاک می‌شود.
       ═══════════════════════════════════════════════════════════════════ */

    /** @var array آمار درخواست جاری */
    public static $stats = ['queries' => 0, 'selects' => 0, 'writes' => 0, 'ddl' => 0, 'sql_ms' => 0.0, 'memo_hits' => 0, 'slow' => []];
    /** @var array حافظهٔ نتیجهٔ SELECT در همین درخواست */
    private static $memo = [];

    /** آمار برای صفحهٔ کارایی / تست‌ها */
    public static function stats() { return self::$stats; }

    /** صفر کردن شمارنده‌ها (تست‌ها) */
    public static function resetStats() {
        self::$stats = ['queries' => 0, 'selects' => 0, 'writes' => 0, 'ddl' => 0, 'sql_ms' => 0.0, 'memo_hits' => 0, 'slow' => []];
    }

    /** پاک‌کردن حافظهٔ خواندن؛ با هر نوشتن خودکار صدا زده می‌شود. */
    public static function clearCache() { self::$memo = []; }

    /** آیا این SQL نوشتنی است؟ (همه‌چیز جز SELECT/PRAGMA/SHOW/EXPLAIN/DESCRIBE) */
    private static function isWrite($sql) {
        return !preg_match('/^\s*(?:SELECT|PRAGMA|SHOW|EXPLAIN|DESCRIBE|WITH)\b/i', (string)$sql);
    }

    private static function memoEnabled() {
        if (count(self::$memo) > 400) return false;         // سقف حافظه
        if (function_exists('perf_config')) return (bool)perf_config('query_cache', true);
        return true;
    }

    private static function memoKey($sql, $params) {
        return md5($sql . "\x00" . serialize($params));
    }

    public static function query($sql, $params = []) {
        $pdo = self::getInstance()->getPdo();
        if (!$pdo) return false;
        $t = microtime(true);
        // NOTE: rewrite happens inside SQLitePDO::prepare — no double pass here
        $stmt = $pdo->prepare($sql);
        try {
            $stmt->execute($params);
        } catch (PDOException $e) {
            /* V4.208.0 — «از قبل هست» یعنی هدفِ DDL محقق شده است.
               قبلاً این خطاها بی‌صدا بلعیده می‌شدند اما هیچ نشانه‌ای ثبت نمی‌شد،
               پس همان ALTER در هر درخواست دوباره تلاش می‌شد (و روی MySQL هر بار
               قفل متادیتا می‌گرفت). حالا یک‌بار که فهمیدیم ستون/جدول/کلید هست،
               برای همیشه رد می‌شود. */
            if (function_exists('perf_ddl_mark') && preg_match('/^(ALTER|CREATE)\s/i', trim((string)$sql))
                && preg_match('/already exists|Duplicate column|Duplicate key|duplicate entry|exists$/i', $e->getMessage())) {
                perf_ddl_mark($sql);
            }
            throw $e;
        }
        $ms = (microtime(true) - $t) * 1000;
        $isWrite = self::isWrite($sql);
        self::$stats['queries']++;
        self::$stats['sql_ms'] += $ms;
        if ($isWrite) {
            self::$stats['writes']++;
            if (preg_match('/^\s*(?:ALTER|CREATE|DROP|TRUNCATE|RENAME)\b/i', (string)$sql)) self::$stats['ddl']++;
            // هر نوشتن، کش داده را برای درخواست‌های بعدی باطل می‌کند —
            // مگر نوشتن‌های «گذرا» (user_sessions/activity_logs/…) که هیچ‌کدام از
            // اعداد کش‌شده را عوض نمی‌کنند و هر دقیقه تکرار می‌شوند.
            self::clearCache();
            if (function_exists('perf_cache_bump') && !(function_exists('perf_write_is_ephemeral') && perf_write_is_ephemeral($sql))) perf_cache_bump();
        } else {
            self::$stats['selects']++;
        }
        if ($ms > 120) self::$stats['slow'][] = ['ms' => round($ms, 1), 'sql' => mb_substr(preg_replace('/\s+/', ' ', (string)$sql), 0, 200)];
        /* V4.208.1 — ثبت اختیاری دستورها برای عیب‌یابی (فقط وقتی صریحاً روشن شود). */
        if (isset($GLOBALS['__perf_sql_log']) && is_array($GLOBALS['__perf_sql_log']) && count($GLOBALS['__perf_sql_log']) < 500) {
            $GLOBALS['__perf_sql_log'][] = ($isWrite ? 'W ' : 'R ') . round($ms, 2) . 'ms  ' . mb_substr(preg_replace('/\s+/', ' ', (string)$sql), 0, 180);
        }
        return $stmt;
    }

    public static function fetchAll($sql, $params = []) {
        $memo = self::memoEnabled() && !self::isWrite($sql);
        $key = $memo ? self::memoKey($sql, $params) : null;
        if ($memo && array_key_exists($key, self::$memo)) { self::$stats['memo_hits']++; return self::$memo[$key]; }
        $stmt = self::query($sql, $params);
        $rows = $stmt ? $stmt->fetchAll() : [];
        if ($memo) self::$memo[$key] = $rows;
        return $rows;
    }

    public static function fetch($sql, $params = []) {
        $memo = self::memoEnabled() && !self::isWrite($sql);
        $key = $memo ? self::memoKey($sql, $params) : null;
        if ($memo && array_key_exists($key, self::$memo)) { self::$stats['memo_hits']++; return self::$memo[$key]; }
        $stmt = self::query($sql, $params);
        if (!$stmt) return null;
        $row = $stmt->fetch();
        $row = $row === false ? null : $row;
        if ($memo) self::$memo[$key] = $row;
        return $row;
    }

    public static function execute($sql, $params = []) {
        /* V4.208.0 — DDL تکراری اجرا نمی‌شود.
           در سراسر کد، ALTER TABLE / CREATE TABLE «برای اطمینان» در هر درخواست
           تکرار می‌شود و خطای «از قبل هست» بی‌صدا بلعیده می‌شود. روی MySQL هر
           ALTER حتی در همین حالت هزینهٔ قفل متادیتا دارد. اجرای موفق هر DDL
           برای نسخهٔ جاری ثبت می‌شود و تکرار آن فقط یک is_file() است.
           DROP/TRUNCATE هرگز نادیده گرفته نمی‌شوند. */
        if (function_exists('perf_ddl_skip') && perf_ddl_skip($sql)) {
            if (!isset(self::$stats['ddl_skipped'])) self::$stats['ddl_skipped'] = 0;
            self::$stats['ddl_skipped']++;
            return true;
        }
        $stmt = self::query($sql, $params);
        if ($stmt !== false && function_exists('perf_ddl_mark')) perf_ddl_mark($sql);
        return $stmt !== false;
    }


    public static function lastInsertId() {
        $pdo = self::getInstance()->getPdo();
        return $pdo ? $pdo->lastInsertId() : 0;
    }
}
