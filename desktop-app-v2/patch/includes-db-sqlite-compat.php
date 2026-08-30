<?php
// File: includes/db_sqlite_compat.php  (SchoolDesk Pro desktop runtime)
/**
 * MySQL -> SQLite query translation layer.
 *
 * The web system is written against MySQL. When running inside the
 * portable desktop build (driver = sqlite) this shim rewrites the small
 * set of MySQL-specific constructs used by the codebase so every page
 * (reports, exams, designer, prints, filters ...) works unmodified.
 */

class SQLiteCompat {

    /** rewrite a MySQL-flavoured SQL string into SQLite dialect */
    public static function rewrite($sql) {
        $s = $sql;

        // backticks -> double quotes
        $s = str_replace('`', '"', $s);

        // NOW() -> local datetime
        $s = preg_replace('/\bNOW\(\)/i', "datetime('now','localtime')", $s);
        $s = preg_replace('/\bCURDATE\(\)/i', "date('now','localtime')", $s);
        $s = preg_replace('/\bUNIX_TIMESTAMP\(\)/i', "strftime('%s','now')", $s);
        $s = preg_replace('/\bCURRENT_TIMESTAMP\(\)/i', "datetime('now','localtime')", $s);

        // IFNULL is native in SQLite; RAND() -> RANDOM()
        $s = preg_replace('/\bRAND\(\)/i', 'RANDOM()', $s);

        // DATE_SUB(NOW(), INTERVAL n UNIT) / DATE_ADD
        $s = preg_replace_callback(
            '/DATE_(SUB|ADD)\(\s*(.+?)\s*,\s*INTERVAL\s+(\d+)\s+(SECOND|MINUTE|HOUR|DAY|MONTH|YEAR)\s*\)/i',
            function ($m) {
                $sign = strtoupper($m[1]) === 'SUB' ? '-' : '+';
                $unit = strtolower($m[4]);
                return "datetime(" . $m[2] . ", '" . $sign . $m[3] . " " . $unit . "')";
            }, $s);

        // DATE_FORMAT(x, '%Y-%m-%d') -> strftime
        $s = preg_replace_callback(
            "/DATE_FORMAT\(\s*(.+?)\s*,\s*'([^']*)'\s*\)/i",
            function ($m) {
                $fmt = str_replace(['%i'], ['%M'], $m[2]); // mysql %i = minutes
                return "strftime('" . $fmt . "', " . $m[1] . ")";
            }, $s);

        // GROUP_CONCAT([DISTINCT] x [ORDER BY ...] [SEPARATOR 'y'])
        //   Parsed manually (paren counting) because ORDER BY may itself
        //   contain function calls like FIELD(...). SQLite: group_concat.
        $s = self::rewriteGroupConcat($s);

        // FIELD(col, a, b, c) — MySQL ordering helper; emulate with CASE
        $s = self::rewriteField($s);

        // INSERT IGNORE INTO -> INSERT OR IGNORE INTO
        $s = preg_replace('/\bINSERT\s+IGNORE\s+INTO\b/i', 'INSERT OR IGNORE INTO', $s);
        // REPLACE INTO is supported natively by SQLite

        // INSERT ... ON DUPLICATE KEY UPDATE a=VALUES(a), b=VALUES(b)
        //   -> INSERT ... ON CONFLICT DO UPDATE SET a=excluded.a, ...
        if (preg_match('/ON DUPLICATE KEY UPDATE/i', $s)) {
            $parts = preg_split('/ON DUPLICATE KEY UPDATE/i', $s, 2);
            $ins = rtrim($parts[0]);
            $upd = trim($parts[1]);
            // a = VALUES(a)  ->  a = excluded.a
            $upd = preg_replace('/VALUES\(\s*"?([A-Za-z0-9_]+)"?\s*\)/i', 'excluded.$1', $upd);
            // find conflict target: first UNIQUE-ish column list is unknown here;
            // SQLite allows bare ON CONFLICT DO UPDATE only with a target, so
            // fall back to the table's primary/unique detection via UPSERT on
            // rowid is not possible -> use INSERT OR REPLACE-like emulation:
            // We rewrite to: INSERT INTO ... ON CONFLICT DO UPDATE SET ...
            // SQLite >= 3.35 supports a target-less DO UPDATE.
            $s = $ins . ' ON CONFLICT DO UPDATE SET ' . $upd;
        }

        // SHOW TABLES -> sqlite_master
        if (preg_match('/^\s*SHOW\s+TABLES/i', $s)) {
            $s = "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name";
        }
        // SHOW CREATE TABLE t -> sqlite_master lookup (two columns like MySQL)
        if (preg_match('/^\s*SHOW\s+CREATE\s+TABLE\s+"?([A-Za-z0-9_]+)"?/i', $s, $m)) {
            return "SELECT name, sql FROM sqlite_master WHERE type='table' AND name='" . $m[1] . "'";
        }
        // SHOW COLUMNS FROM t [LIKE 'x'] / DESCRIBE t  -> PRAGMA-like emulation
        if (preg_match('/^\s*(SHOW\s+COLUMNS\s+FROM|DESCRIBE)\s+"?([A-Za-z0-9_]+)"?(?:\s+LIKE\s+\'([^\']*)\')?/i', $s, $m)) {
            $s = "SELECT name AS Field, type AS Type, CASE WHEN \"notnull\"=0 THEN 'YES' ELSE 'NO' END AS \"Null\", '' AS Key, dflt_value AS \"Default\", '' AS Extra FROM pragma_table_info('" . $m[2] . "')";
            if (isset($m[3]) && $m[3] !== '') {
                $s .= " WHERE name LIKE '" . $m[3] . "'";
            }
        }
        // SHOW INDEX FROM t
        if (preg_match('/^\s*SHOW\s+INDEX\s+FROM\s+"?([A-Za-z0-9_]+)"?/i', $s, $m)) {
            $s = "SELECT name AS Key_name, '' AS Column_name, CASE WHEN \"unique\"=1 THEN 0 ELSE 1 END AS Non_unique FROM pragma_index_list('" . $m[1] . "')";
        }

        // ALTER TABLE t ADD COLUMN x type AFTER y  (SQLite has no AFTER)
        $s = preg_replace('/\s+AFTER\s+"?[A-Za-z0-9_]+"?\s*$/i', '', $s);
        $s = preg_replace('/\s+AFTER\s+"?[A-Za-z0-9_]+"?\s*(,)/i', '$1', $s);
        // ALTER TABLE t MODIFY/CHANGE ... -> no-op (SQLite is dynamically typed)
        if (preg_match('/^\s*ALTER\s+TABLE\s+\S+\s+(MODIFY|CHANGE)\b/i', $s)) {
            $s = 'SELECT 1';
        }
        // column type cleanup inside ALTER ADD COLUMN
        if (preg_match('/^\s*ALTER\s+TABLE/i', $s)) {
            $s = preg_replace('/\benum\([^)]*\)/i', 'TEXT', $s);
            $s = preg_replace('/\b(var)?char\(\d+\)/i', 'TEXT', $s);
            $s = preg_replace('/\b(big|medium|small|tiny)?int\(\d+\)(\s+unsigned)?/i', 'INTEGER', $s);
            $s = preg_replace('/\bdecimal\(\d+,\s*\d+\)/i', 'REAL', $s);
            $s = preg_replace('/\b(datetime|timestamp)\b/i', 'TEXT', $s);
            $s = preg_replace('/\b(long|medium|tiny)text\b/i', 'TEXT', $s);
            $s = preg_replace('/\bDEFAULT CURRENT_TIMESTAMP\b/i', "DEFAULT (datetime('now','localtime'))", $s);
            $s = preg_replace('/\bCOLLATE\s+\S+/i', '', $s);
            // ADD UNIQUE KEY / ADD KEY -> separate index (best effort: ignore)
            if (preg_match('/ADD\s+(UNIQUE\s+)?(KEY|INDEX)/i', $s)) $s = 'SELECT 1';
            if (preg_match('/DROP\s+(KEY|INDEX|FOREIGN)/i', $s)) $s = 'SELECT 1';
        }

        // CREATE TABLE ... ENGINE=InnoDB ... -> strip engine/charset tail
        if (preg_match('/^\s*CREATE\s+TABLE/i', $s)) {
            $s = preg_replace('/\bint\(\d+\)\s+NOT\s+NULL\s+AUTO_INCREMENT\b/i', 'INTEGER', $s);
            $s = preg_replace('/\b(big|medium|small|tiny)?int\(\d+\)(\s+unsigned)?/i', 'INTEGER', $s);
            $s = preg_replace('/\bAUTO_INCREMENT\b/i', '', $s);
            $s = preg_replace('/\benum\([^)]*\)/i', 'TEXT', $s);
            $s = preg_replace('/\b(var)?char\(\d+\)/i', 'TEXT', $s);
            $s = preg_replace('/\b(long|medium|tiny)text\b/i', 'TEXT', $s);
            $s = preg_replace('/\bdecimal\(\d+,\s*\d+\)/i', 'REAL', $s);
            $s = preg_replace('/\b(datetime|timestamp)\b/i', 'TEXT', $s);
            $s = preg_replace('/\bDEFAULT CURRENT_TIMESTAMP\s+ON UPDATE CURRENT_TIMESTAMP/i', "DEFAULT (datetime('now','localtime'))", $s);
            $s = preg_replace('/\bDEFAULT CURRENT_TIMESTAMP\b/i', "DEFAULT (datetime('now','localtime'))", $s);
            $s = preg_replace('/\bCOLLATE[= ]\S+/i', '', $s);
            $s = preg_replace('/\bCHARACTER SET \w+/i', '', $s);
            $s = preg_replace('/\)\s*ENGINE\s*=.*$/is', ')', $s);
            // PRIMARY KEY ("id") + "id" INTEGER  -> AUTOINCREMENT pk
            if (preg_match('/PRIMARY KEY \("(\w+)"\)/', $s, $m)) {
                $pk = $m[1];
                $s = preg_replace('/,?\s*PRIMARY KEY \("' . $pk . '"\)/', '', $s);
                $s = preg_replace('/"' . $pk . '"\s+INTEGER/', '"' . $pk . '" INTEGER PRIMARY KEY AUTOINCREMENT', $s, 1);
            }
            $s = preg_replace('/UNIQUE KEY\s+"?\w+"?\s*\(([^)]*)\)/i', 'UNIQUE ($1)', $s);
            $s = preg_replace('/,\s*KEY\s+"?\w+"?\s*\([^)]*\)/i', '', $s);
            $s = preg_replace('/,\s*(CONSTRAINT\s+\S+\s+)?FOREIGN KEY\s*\([^)]*\)\s*REFERENCES\s*\S+\s*\([^)]*\)(\s+ON\s+\w+\s+(CASCADE|SET NULL|RESTRICT|NO ACTION))*/i', '', $s);
            $s = preg_replace('/,\s*\)/', ')', $s);
        }

        // TRUNCATE t -> DELETE FROM t
        $s = preg_replace('/^\s*TRUNCATE\s+(TABLE\s+)?/i', 'DELETE FROM ', $s);

        // OPTIMIZE/ANALYZE TABLE -> no-op
        if (preg_match('/^\s*(OPTIMIZE|ANALYZE|REPAIR)\s+TABLE/i', $s)) $s = 'SELECT 1';

        // LIMIT x, y (MySQL) -> LIMIT y OFFSET x
        $s = preg_replace('/\bLIMIT\s+(\d+)\s*,\s*(\d+)/i', 'LIMIT $2 OFFSET $1', $s);

        return $s;
    }

    /** Find the matching close-paren for the paren at $open. */
    private static function matchParen($s, $open) {
        $depth = 0; $n = strlen($s); $inStr = false; $q = '';
        for ($i = $open; $i < $n; $i++) {
            $c = $s[$i];
            if ($inStr) {
                if ($c === $q) { $inStr = false; }
                continue;
            }
            if ($c === "'" || $c === '"') { $inStr = true; $q = $c; continue; }
            if ($c === '(') $depth++;
            elseif ($c === ')') { $depth--; if ($depth === 0) return $i; }
        }
        return -1;
    }

    /** GROUP_CONCAT([DISTINCT] expr [ORDER BY ...] [SEPARATOR 'x']) -> group_concat */
    private static function rewriteGroupConcat($s) {
        $offset = 0;
        while (($pos = stripos($s, 'GROUP_CONCAT(', $offset)) !== false) {
            $open = $pos + 12; // index of '('
            $close = self::matchParen($s, $open);
            if ($close < 0) break;
            $inner = substr($s, $open + 1, $close - $open - 1);

            $distinct = false;
            if (preg_match('/^\s*DISTINCT\s+/i', $inner, $dm)) {
                $distinct = true;
                $inner = substr($inner, strlen($dm[0]));
            }
            $sep = ',';
            if (preg_match("/\s+SEPARATOR\s+'((?:[^'\\\\]|\\\\.)*)'\s*$/i", $inner, $sm)) {
                $sep = $sm[1];
                $inner = substr($inner, 0, -strlen($sm[0]));
            }
            // strip trailing ORDER BY ... (may contain nested parens; it is the tail)
            if (preg_match('/\s+ORDER\s+BY\s+/i', $inner, $om, PREG_OFFSET_CAPTURE)) {
                $inner = substr($inner, 0, $om[0][1]);
            }
            $inner = trim($inner);

            if ($distinct) {
                $new = ($sep !== ',')
                    ? "replace(group_concat(DISTINCT " . $inner . "), ',', '" . $sep . "')"
                    : "group_concat(DISTINCT " . $inner . ")";
            } else {
                $new = "group_concat(" . $inner . ", '" . $sep . "')";
            }
            $s = substr($s, 0, $pos) . $new . substr($s, $close + 1);
            $offset = $pos + strlen($new);
        }
        return $s;
    }

    /** FIELD(col, v1, v2, ...) -> CASE col WHEN v1 THEN 1 ... ELSE 999 END */
    private static function rewriteField($s) {
        $offset = 0;
        while (preg_match('/\bFIELD\s*\(/i', $s, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $pos = $m[0][1];
            $open = $pos + strlen($m[0][0]) - 1;
            $close = self::matchParen($s, $open);
            if ($close < 0) break;
            $inner = substr($s, $open + 1, $close - $open - 1);
            // split on commas not inside quotes/parens
            $args = []; $cur = ''; $depth = 0; $inStr = false; $q = '';
            for ($i = 0, $n = strlen($inner); $i < $n; $i++) {
                $c = $inner[$i];
                if ($inStr) { $cur .= $c; if ($c === $q) $inStr = false; continue; }
                if ($c === "'" || $c === '"') { $inStr = true; $q = $c; $cur .= $c; continue; }
                if ($c === '(') $depth++;
                if ($c === ')') $depth--;
                if ($c === ',' && $depth === 0) { $args[] = trim($cur); $cur = ''; continue; }
                $cur .= $c;
            }
            if (trim($cur) !== '') $args[] = trim($cur);
            if (count($args) < 2) { $offset = $close + 1; continue; }
            $col = array_shift($args);
            $case = '(CASE ' . $col;
            foreach ($args as $i2 => $a) {
                $case .= ' WHEN ' . $a . ' THEN ' . ($i2 + 1);
            }
            $case .= ' ELSE 999 END)';
            $s = substr($s, 0, $pos) . $case . substr($s, $close + 1);
            $offset = $pos + strlen($case);
        }
        return $s;
    }
}
