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

require_once __DIR__ . '/install_guard.php';
require_once __DIR__ . '/db_sqlite_compat.php';

/**
 * PDO subclass that transparently rewrites MySQL SQL into SQLite dialect.
 * Covers code that calls $pdo->query()/exec()/prepare() directly
 * (backups, migration-updater, db-optimizer, installer).
 */
class SQLitePDO extends PDO {
    /** run a trusted multi-statement script with NO rewriting */
    public function rawScript($sql) {
        return parent::exec($sql);
    }
    #[\ReturnTypeWillChange]
    public function exec($sql) {
        return parent::exec(SQLiteCompat::rewrite($sql));
    }
    #[\ReturnTypeWillChange]
    public function query($sql, $fetchMode = null, ...$args) {
        $sql = SQLiteCompat::rewrite($sql);
        if ($fetchMode === null) return parent::query($sql);
        return parent::query($sql, $fetchMode, ...$args);
    }
    #[\ReturnTypeWillChange]
    public function prepare($sql, $options = []) {
        return parent::prepare(SQLiteCompat::rewrite($sql), $options);
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

        if (!is_array($dbConfig) || !in_array($dbConfig['driver'] ?? '', ['sqlite','mysql'], true)) {
            throw new RuntimeException('Invalid database configuration; no implicit driver or credentials are allowed.');
        }
        if ($dbConfig['driver'] === 'mysql') {
            foreach (['host','port','database','username','password','charset'] as $field) {
                if (!array_key_exists($field, $dbConfig)) throw new RuntimeException('Incomplete database configuration.');
            }
        }
        try {
            if ($dbConfig['driver'] === 'sqlite') {
                $path = $dbConfig['database'] ?? '';
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

    public static function query($sql, $params = []) {
        $pdo = self::getInstance()->getPdo();
        if (!$pdo) return false;
        // NOTE: rewrite happens inside SQLitePDO::prepare — no double pass here
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetchAll($sql, $params = []) {
        $stmt = self::query($sql, $params);
        return $stmt ? $stmt->fetchAll() : [];
    }

    public static function fetch($sql, $params = []) {
        $stmt = self::query($sql, $params);
        if (!$stmt) return null;
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function execute($sql, $params = []) {
        $stmt = self::query($sql, $params);
        return $stmt !== false;
    }

    public static function lastInsertId() {
        $pdo = self::getInstance()->getPdo();
        return $pdo ? $pdo->lastInsertId() : 0;
    }
}
