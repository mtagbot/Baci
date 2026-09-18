<?php
/** Database-health metadata helpers. Identifiers never come from request data. */
function dbh_quote($name, $sqlite) {
    $q=$sqlite?'"':'`'; return $q.str_replace($q,$q.$q,(string)$name).$q;
}
function dbh_engine_warnings($driver, $tables) {
    $out=['engine'=>[],'collation'=>[],'unknown'=>[]];
    // SQLite has neither MySQL table engines nor MySQL collations.
    if ($driver==='sqlite') return $out;
    if ($driver!=='mysql') throw new RuntimeException('Unsupported database driver');
    foreach ($tables as $t) {
        $engine=strtolower(trim((string)($t['engine']??'')));
        $collation=strtolower(trim((string)($t['collation']??'')));
        if ($engine==='' || $collation==='') $out['unknown'][]=$t;
        if ($engine!=='' && $engine!=='innodb') $out['engine'][]=$t;
        if ($collation!=='' && strpos($collation,'utf8mb4_')!==0) $out['collation'][]=$t;
    }
    return $out;
}
function dbh_index_metadata($pdo, $sqlite, $table) {
    $out=[];
    if ($sqlite) {
        foreach ($pdo->query('PRAGMA index_list('.dbh_quote($table,true).')')->fetchAll(PDO::FETCH_ASSOC) as $idx) {
            if (!empty($idx['partial'])) continue;
            $columns=[];
            foreach ($pdo->query('PRAGMA index_info('.dbh_quote($idx['name'],true).')')->fetchAll(PDO::FETCH_ASSOC) as $c) $columns[]=$c['name'];
            $out[$idx['name']]=$columns;
        }
    } else {
        $q=$pdo->prepare('SELECT INDEX_NAME,COLUMN_NAME,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX');
        $q->execute([$table]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['INDEX_NAME']][]=empty($r['SUB_PART'])?$r['COLUMN_NAME']:null;
    }
    return $out;
}
function dbh_index_covers($indexes, $columns) {
    foreach ($indexes as $existing) {
        // Leading columns in order, complete fields only; partial/expression/prefix indexes do not count.
        if (array_slice($existing,0,count($columns))===$columns) return true;
    }
    return false;
}
function dbh_maintenance_ok($rows) {
    $ok=false;
    foreach ($rows as $r) {
        if (in_array(strtolower($r['Msg_type']??''),['error','warning'],true)) return false;
        if (strtolower($r['Msg_type']??'')==='status' && strtolower($r['Msg_text']??'')==='ok') $ok=true;
    }
    return $ok;
}
/** Conservative duplicate protection, including attendance, tags, seating and polymorphic user IDs. */
function dbh_has_references($pdo,$sqlite,$entity,$id) {
    $tables=$sqlite?$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN):$pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        if ($table===$entity) continue;
        $fields=$entity==='students'?['student_id','user_id']:['class_id'];
        if ($sqlite) $columns=array_column($pdo->query('PRAGMA table_info('.dbh_quote($table,true).')')->fetchAll(PDO::FETCH_ASSOC),'name');
        else { $q=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);$columns=$q->fetchAll(PDO::FETCH_COLUMN); }
        foreach (array_intersect($fields,$columns) as $field) {
            $q=$pdo->prepare('SELECT 1 FROM '.dbh_quote($table,$sqlite).' WHERE '.dbh_quote($field,$sqlite).'=? LIMIT 1');$q->execute([$id]);
            if ($q->fetchColumn()!==false) return true;
        }
    }
    return false; // Any SQL failure propagates; the caller must NOT delete on uncertainty.
}
