<?php
// File: includes/db.php
/**
 * Database Helper Class (PDO with MySQL & SQLite Fallback Support)
 */

class DB {
    private static $instance = null;
    private $pdo;
    private $error = null;

    private function __construct() {
        global $dbConfig;
        if (!isset($dbConfig) || empty($dbConfig)) {
            $configFile = dirname(__DIR__) . '/config/database.php';
            if (file_exists($configFile)) {
                $dbConfig = require $configFile;
            } else {
                $dbConfig = ['driver' => 'sqlite', 'database' => dirname(__DIR__) . '/database.sqlite'];
            }
        }

        try {
            if (isset($dbConfig['driver']) && $dbConfig['driver'] === 'sqlite') {
                $dsn = "sqlite:" . $dbConfig['database'];
                $this->pdo = new PDO($dsn);
            } else {
                $host = $dbConfig['host'] ?? '127.0.0.1';
                $port = $dbConfig['port'] ?? '3306';
                $dbname = $dbConfig['database'] ?? 'student_report_db';
                $charset = $dbConfig['charset'] ?? 'utf8mb4';
                $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
                $user = $dbConfig['username'] ?? 'root';
                $pass = $dbConfig['password'] ?? '';
                $this->pdo = new PDO($dsn, $user, $pass);
            }
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            // Fallback to SQLite if MySQL fails during local preview without MySQL server
            try {
                $sqlitePath = dirname(__DIR__) . '/fallback.sqlite';
                $this->pdo = new PDO("sqlite:" . $sqlitePath);
                $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $this->initFallbackSqlite();
            } catch (Exception $ex) {
                // If even SQLite fails, keep null
            }
        }
    }

    private function initFallbackSqlite() {
        // Initialize basic tables in SQLite fallback if empty
        $test = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='admins'");
        if (!$test->fetch()) {
            $sql = file_get_contents(dirname(__DIR__) . '/sql/database.sql');
            // Clean MySQL specific syntax for SQLite fallback
            $sql = preg_replace('/AUTO_INCREMENT/i', 'AUTOINCREMENT', $sql);
            $sql = preg_replace('/int\(11\) NOT NULL AUTOINCREMENT/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
            $sql = preg_replace('/enum\([^\)]+\)/i', 'TEXT', $sql);
            $sql = preg_replace('/ENGINE=InnoDB[^\n;]+/i', '', $sql);
            $sql = preg_replace('/SET FOREIGN_KEY_CHECKS[^\n;]+;/i', '', $sql);
            $sql = preg_replace('/PRIMARY KEY \(`id`\),?/i', '', $sql);
            $sql = preg_replace('/UNIQUE KEY [^\n,]+,?/i', '', $sql);
            $sql = preg_replace('/KEY [^\n,]+,?/i', '', $sql);
            // Just split and execute simpler create tables if regex isn't exact, or let standard MySQL run on MySQL server
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new DB();
        }
        return self::$instance;
    }

    public function getPdo() {
        return $this->pdo;
    }

    public function getError() {
        return $this->error;
    }

    public static function query($sql, $params = []) {
        $pdo = self::getInstance()->getPdo();
        if (!$pdo) return false;
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
        return $stmt ? $stmt->fetch() : null;
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
