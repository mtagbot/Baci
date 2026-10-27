<?php
// File: class-exam-sync-api.php   (روی سرور/سایت اصلی آپلود شود — کنار index.php)
/**
 * SchoolDesk Pro — server-side sync endpoint (MySQL).
 *
 * Install next to the original desk-sync-api.php in the live site root. On the first
 * handshake it automatically creates its change-log table and installs
 * AFTER INSERT/UPDATE/DELETE triggers on all synced tables, so every
 * change made on the website is recorded and can be pulled by the
 * desktop app. Changes pushed by the desktop are applied with the
 * triggers temporarily disabled (via @desk_sync_suppress session var).
 *
 * Authentication is inherited from the original endpoint; never replace its key.
 */

if (!defined('SDP_SYNC_ACTIVE')) {
    define('SDP_SYNC_ACTIVE', true);
}
if (!defined('SDP_SYNC_ROOT')) {
    define('SDP_SYNC_ROOT', is_file(__DIR__ . '/config/config.php') ? __DIR__ : dirname(__DIR__));
}
foreach (['desk-sync-api.php', 'class-exam-sync-api.php'] as $__sdpEpName) {
    $__sdpEpFile = SDP_SYNC_ROOT . '/' . $__sdpEpName;
    if (is_file($__sdpEpFile) && ((@fileperms($__sdpEpFile) & 0777) < 0600)) @chmod($__sdpEpFile, 0644);
}

// Reuse the installation's existing key; no key/configuration is shipped or overwritten.
function ceg_existing_sync_key() {
    // Release_V1.0 installs use this private key file. An invalid existing key fails closed.
    $private=__DIR__.'/config/desk-sync-key.php';
    if(!is_file($private))$private=SDP_SYNC_ROOT.'/config/desk-sync-key.php';
    if(is_file($private)) {
        $key=require $private;
        if(is_string($key) && preg_match('/^[a-f0-9]{64}$/D',$key)) return $key;
        if(is_string($key) && strlen($key)>=16 && strpos($key,'CHANGE-ME')===false) return $key;
    }
    // Compatibility only for pre-release installations without a private key file.
    $path=__DIR__.'/desk-sync-api.php';
    if(!is_file($path))$path=SDP_SYNC_ROOT.'/desk-sync-api.php';
    if(!is_file($path))return '';
    $tokens=array_values(array_filter(token_get_all((string)@file_get_contents($path)),function($t){return !is_array($t)||!in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true);}));
    for($i=0;$i<count($tokens)-5;$i++) {
        if(!is_array($tokens[$i]) || $tokens[$i][0]!==T_STRING || strtolower($tokens[$i][1])!=='define' || $tokens[$i+1]!=='(' || !is_array($tokens[$i+2]) || $tokens[$i+2][0]!==T_CONSTANT_ENCAPSED_STRING || substr($tokens[$i+2][1],1,-1)!=='DESK_SYNC_KEY' || $tokens[$i+3]!==',' || !is_array($tokens[$i+4]) || $tokens[$i+4][0]!==T_CONSTANT_ENCAPSED_STRING)continue;
        $literal=$tokens[$i+4][1];
        return $literal[0]==='"'?stripcslashes(substr($literal,1,-1)):str_replace(["\\\\","\\'"],["\\","'"],substr($literal,1,-1));
    }
    return '';
}

function ceg_all_sync_keys(): array {
    $keys = [];
    $primary = ceg_existing_sync_key();
    if ($primary !== '' && strlen($primary) >= 16 && strpos($primary, 'CHANGE-ME') === false) $keys[] = $primary;
    $private = SDP_SYNC_ROOT . '/config/desk-sync-key.php';
    if (is_file($private)) {
        $v = require $private;
        if (is_string($v) && strlen($v) >= 16 && strpos($v, 'CHANGE-ME') === false) $keys[] = $v;
    }
    $legacy = SDP_SYNC_ROOT . '/desk-sync-api.php';
    if (is_file($legacy)) {
        $src = (string)@file_get_contents($legacy, false, null, 0, 131072);
        if (preg_match("~define\\s*\\(\\s*['\"]DESK_SYNC_KEY['\"]\\s*,\\s*['\"]((?:[^'\"\\\\]|\\\\.){16,200})['\"]~", $src, $m)) {
            $lit = str_replace(["\\\\", "\\'", '\\"'], ["\\", "'", '"'], $m[1]);
            if (strlen($lit) >= 16 && strpos($lit, 'CHANGE-ME') === false) $keys[] = $lit;
        }
    }
    return array_values(array_unique($keys));
}

require_once SDP_SYNC_ROOT . '/config/config.php';
require_once SDP_SYNC_ROOT . '/includes/db.php';
if(!defined('DESK_SYNC_KEY'))define('DESK_SYNC_KEY',ceg_existing_sync_key());

$__sdpOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!headers_sent()) {
    header('Access-Control-Allow-Origin: ' . ($__sdpOrigin !== '' ? $__sdpOrigin : '*'));
    if ($__sdpOrigin !== '') header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With, X-SDP-Z');
}
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (!defined('SDP_SYNC_LIB_ONLY') && isset($_GET['sdp_bridge']) && (string)$_GET['sdp_bridge'] === '1') {
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>پل ارتباطی همگام‌سازی</title></head>'
       . '<body style="font-family:Tahoma,sans-serif;background:#f0fdf4;color:#166534;padding:16px;text-align:center;font-size:13px">'
       . '<div id="st">پل همگام‌سازی مرورگر آماده است...</div><script>'
       . '(function(){'
       . 'var tgt=window.opener||window.parent;'
       . 'function post(m){try{if(tgt&&tgt!==window)tgt.postMessage(m,"*");}catch(e){}}'
       . 'async function callSameOrigin(msg){'
       . '  var eps=[location.pathname,"index.php","desk-connection-status.php","csrf-refresh.php","class-exam-sync-api.php","desk-sync-api.php"];'
       . '  var seen={};'
       . '  for(var i=0;i<eps.length;i++){'
       . '    var ep=eps[i]; if(!ep||seen[ep])continue; seen[ep]=1;'
       . '    try{'
       . '      var r=await fetch(ep,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8"},body:"sdp_sync=1&sdp_z="+encodeURIComponent(msg.sdp_z||"")});'
       . '      if(r.status===200){var j=await r.json();if(j&&typeof j.ok==="boolean"&&!(j.ok===false&&j.error==="bad request"))return {ok:true,data:j,ep:ep,mode:"post_z"};}'
       . '    }catch(e){}'
       . '    if(msg.payload){try{'
       . '      var r2=await fetch(ep,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8"},body:"payload="+encodeURIComponent(msg.payload)});'
       . '      if(r2.status===200){var j2=await r2.json();if(j2&&typeof j2.ok==="boolean")return {ok:true,data:j2,ep:ep,mode:"form"};}'
       . '    }catch(e){}}'
       . '    if(msg.sdp_z){try{'
       . '      var z=String(msg.sdp_z);'
       . '      if(z.length<=1800){'
       . '        var sep=ep.indexOf("?")===-1?"?":"&";'
       . '        var r3=await fetch(ep+sep+"sdp_sync=1&sdp_z="+encodeURIComponent(z)+"&_t="+Date.now(),{method:"GET",credentials:"same-origin"});'
       . '        if(r3.status===200){var j3=await r3.json();if(j3&&typeof j3.ok==="boolean")return {ok:true,data:j3,ep:ep,mode:"get_z"};}'
       . '      }else{'
       . '        var parts=[];for(var k=0;k<z.length;k+=1800)parts.push(z.slice(k,k+1800));'
       . '        var cid=Math.random().toString(16).slice(2,10)+Math.random().toString(16).slice(2,10);'
       . '        var sep2=ep.indexOf("?")===-1?"?":"&";'
       . '        var okAll=true,lastJ=null;'
       . '        for(var idx=0;idx<parts.length;idx++){'
       . '          var rc=await fetch(ep+sep2+"sdp_sync=1&sdp_cid="+cid+"&sdp_ci="+idx+"&sdp_cn="+parts.length+"&sdp_cp="+encodeURIComponent(parts[idx])+"&_t="+Date.now(),{method:"GET",credentials:"same-origin"});'
       . '          if(rc.status!==200){okAll=false;break;}'
       . '          lastJ=await rc.json();if(!lastJ||!lastJ.ok){okAll=false;break;}'
       . '        }'
       . '        if(okAll&&lastJ&&typeof lastJ.ok==="boolean")return {ok:true,data:lastJ,ep:ep,mode:"get_z_chunked"};'
       . '      }'
       . '    }catch(e){}}'
       . '  }'
       . '  return {ok:false,error:"same-origin bridge failed across all endpoints"};'
       . '}'
       . 'window.addEventListener("message",async function(ev){'
       . '  var d=ev.data; if(!d||!d.sdp_bridge_req)return;'
       . '  var res=await callSameOrigin(d);'
       . '  post({sdp_bridge_reply:true,id:d.id,res:res});'
       . '});'
       . 'post({sdp_bridge_ready:true,href:location.href});'
       . 'setInterval(function(){post({sdp_bridge_ready:true,href:location.href});},1500);'
       . '})();</script></body></html>';
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const SYNC_TABLES = [
    'admins',
    'academic_years','teachers','discipline_titles','classes','subjects',
    'class_schedules','students','student_discipline_records','reports',
    'report_grades','report_locks','exam_schedules','exam_designs',
    'class_exam_groups','class_exam_group_members',
    'exam_question_bank','exam_design_archive','exam_assignments','exam_student_seating',
    'online_exam_categories','online_question_categories','online_question_bank',
    'online_exams','online_questions','online_exam_attempts','online_exam_answers',
    'grade_messages','counseling_requests','student_attendance','student_qr_tags',
    'bale_bot_users','telegram_bot_users','rubika_bot_users','bot_admin_sessions',
    'bot_message_templates','bot_button_templates','bot_login_tokens','bot_message_logs',
    'activity_logs','notifications','sms_logs','report_parent_reviews',
    'settings',
];

/* settings uses a string primary key; everything else uses `id` */
function pk_col($t) { return $t === 'settings' ? 'key_name' : 'id'; }

function jout($data) { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function jfail($msg) { jout(['ok' => false, 'error' => $msg]); }

function desk_sync_unpack_token(string $token): ?array {
    $token = trim($token);
    if (strlen($token) < 4) return null;
    $prefix = substr($token, 0, 2);
    $b64 = strtr(substr($token, 2), '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad > 0) $b64 .= str_repeat('=', 4 - $pad);
    $bin = base64_decode($b64, true);
    if (!is_string($bin) || $bin === '') return null;
    if ($prefix === 'z1') {
        $json = function_exists('gzinflate') ? @gzinflate($bin) : false;
        if (!is_string($json) || $json === '') return null;
    } elseif ($prefix === 'b1') {
        $json = $bin;
    } else {
        return null;
    }
    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : null;
}

function desk_sync_receive_chunk(): ?string {
    $cid = isset($_REQUEST['sdp_cid']) ? preg_replace('/[^a-f0-9]/', '', strtolower((string)$_REQUEST['sdp_cid'])) : '';
    if (strlen($cid) < 8 || strlen($cid) > 32) return null;
    $ci = isset($_REQUEST['sdp_ci']) ? (int)$_REQUEST['sdp_ci'] : -1;
    $cn = isset($_REQUEST['sdp_cn']) ? (int)$_REQUEST['sdp_cn'] : 0;
    $cp = isset($_REQUEST['sdp_cp']) ? (string)$_REQUEST['sdp_cp'] : '';
    if ($ci < 0 || $cn < 1 || $cn > 2000 || $ci >= $cn || $cp === '' || strlen($cp) > 65536) jfail('bad chunk');
    $dir = rtrim(sys_get_temp_dir(), '/\\') . '/sdp_sync_chunks';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if ($ci === 0) {
        foreach (glob($dir . '/chk_*') ?: [] as $old) {
            if (@filemtime($old) < time() - 300) @unlink($old);
        }
    }
    $partFile = $dir . '/chk_' . $cid . '_' . $ci;
    if (@file_put_contents($partFile, $cp) === false) jfail('chunk storage unavailable');
    if ($ci < $cn - 1) jout(['ok' => true, 'chunk' => $ci]);
    $full = '';
    for ($i = 0; $i < $cn; $i++) {
        $pf = $dir . '/chk_' . $cid . '_' . $i;
        if (!is_file($pf)) jfail('missing chunk ' . $i);
        $full .= (string)@file_get_contents($pf);
        @unlink($pf);
    }
    return $full;
}
if (defined('SDP_SYNC_LIB_ONLY')) return;

$in = null;
if (!empty($_REQUEST['sdp_cid'])) {
    $assembledToken = desk_sync_receive_chunk();
    if (is_string($assembledToken)) $in = desk_sync_unpack_token($assembledToken);
}
$rawRequest = file_get_contents('php://input');
if (!is_array($in)) {
    $sdpZ = $_POST['sdp_z'] ?? $_GET['sdp_z'] ?? $_SERVER['HTTP_X_SDP_Z'] ?? null;
    if (!is_string($sdpZ) && $rawRequest !== '') {
        $formZ = [];
        parse_str($rawRequest, $formZ);
        if (isset($formZ['sdp_z']) && is_string($formZ['sdp_z'])) $sdpZ = $formZ['sdp_z'];
    }
    if (is_string($sdpZ) && $sdpZ !== '') $in = desk_sync_unpack_token($sdpZ);
}
if (!is_array($in)) $in = json_decode($rawRequest, true); // Keep backward compatibility with existing JSON clients.
if (!is_array($in)) {
    // WAF/hosting compatibility: some hosts reject application/json POSTs before PHP.
    // The desktop client can tunnel the exact same JSON document as one form field,
    // avoiding max_input_vars truncation of large sync batches.
    $formPayload = $_POST['payload'] ?? $_GET['payload'] ?? null;
    if (!is_string($formPayload) && $rawRequest !== '') {
        $form = [];
        parse_str($rawRequest, $form); // Covers hosts that do not populate $_POST.
        $formPayload = $form['payload'] ?? null;
    }
    if (is_string($formPayload)) $in = json_decode($formPayload, true);
}
if (!is_array($in) && !empty($_REQUEST['action']) && !empty($_REQUEST['key'])) {
    $in = $_REQUEST;
}
if (!is_array($in)) jfail('bad request');
$__allSyncKeys = ceg_all_sync_keys();
if ((strpos(DESK_SYNC_KEY, 'CHANGE-ME') === 0 || strlen(DESK_SYNC_KEY) < 16) && !$__allSyncKeys) jfail('sync key unavailable — keep the original desk-sync-api.php with its configured DESK_SYNC_KEY');
$__givenKey = (string)($in['key'] ?? '');
$__keyAuthorized = (strlen(DESK_SYNC_KEY) >= 16 && strpos(DESK_SYNC_KEY, 'CHANGE-ME') === false && hash_equals(DESK_SYNC_KEY, $__givenKey));
if (!$__keyAuthorized) {
    foreach ($__allSyncKeys as $__candKey) {
        if (hash_equals($__candKey, $__givenKey)) { $__keyAuthorized = true; break; }
    }
}
if (!$__keyAuthorized) { http_response_code(200); jfail('invalid key'); }

$pdo = DB::getInstance()->getPdo();
if (!$pdo) jfail('database unavailable');
$action = $in['action'] ?? '';
if ($action === 'bot_outbox') {
    // Authentication above is mandatory; no tokens, arbitrary methods or URLs from clients.
    require_once SDP_SYNC_ROOT.'/includes/bot_helpers.php';
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    try {
        $ids=bot_outbox_accept($in['jobs']??[]);
        bot_outbox_drain(1,8); // bounded; cron handles backlogs even after desktop closure
        jout(['ok'=>true,'receipts'=>bot_outbox_receipts($ids)]);
    } catch(Throwable $e) { jfail('notification queue rejected request or storage unavailable'); }
}

require_once SDP_SYNC_ROOT.'/includes/class_exam_groups.php';
ensure_exams_schema();ceg_schema();

/* ------------------------------------------------------------------ */
/* helpers                                                             */
/* ------------------------------------------------------------------ */

function table_exists($pdo, $t) {
    try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); return true; }
    catch (Exception $e) { return false; }
}

function suppress_triggers($pdo, $on) {
    // MySQL: session variable checked inside the triggers
    try { $pdo->exec($on ? "SET @desk_sync_suppress = 1" : "SET @desk_sync_suppress = NULL"); } catch (Exception $e) {}
    // SQLite fallback (when this API runs on a SQLite-based mirror)
    try {
        if ($on) $pdo->exec("INSERT INTO desk_sync_suppress (flag) VALUES (1)");
        else     $pdo->exec("DELETE FROM desk_sync_suppress");
    } catch (Exception $e) {}
}

function table_columns($pdo, $t) {
    static $cache = [];
    if (!isset($cache[$t])) {
        $cols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM `$t`") as $r) $cols[] = $r['Field'];
        $cache[$t] = $cols;
    }
    return $cache[$t];
}

function is_sqlite($pdo) {
    try { return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'; }
    catch (Exception $e) { return false; }
}

function ensure_infra($pdo) {
    if (is_sqlite($pdo)) {
        // SQLite mirror: schema file already provides desk_change_log + triggers
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS desk_change_log (
        id BIGINT NOT NULL AUTO_INCREMENT,
        tbl VARCHAR(64) NOT NULL,
        rid VARCHAR(128) NOT NULL,
        op CHAR(1) NOT NULL,
        ts INT NOT NULL,
        PRIMARY KEY (id),
        KEY idx_tbl (tbl, rid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach (SYNC_TABLES as $t) {
        if (!table_exists($pdo, $t)) continue;
        $pk = pk_col($t);
        foreach (['i' => 'INSERT', 'u' => 'UPDATE', 'd' => 'DELETE'] as $suf => $evt) {
            $trg = "desk_sync_{$t}_{$suf}";
            $ref = ($evt === 'DELETE') ? 'OLD' : 'NEW';
            $cond = "@desk_sync_suppress IS NULL";
            if ($t === 'settings') $cond .= " AND $ref.key_name NOT LIKE 'desk!_%' ESCAPE '!'";
            try {
                $exists = $pdo->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
                    WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = " . $pdo->quote($trg))->fetchColumn();
                if ($exists) continue;
                $pdo->exec("CREATE TRIGGER `$trg` AFTER $evt ON `$t` FOR EACH ROW
                    BEGIN
                        IF $cond THEN
                            INSERT INTO desk_change_log (tbl, rid, op, ts)
                            VALUES ('$t', $ref.`$pk`, '" . substr($evt, 0, 1) . "', UNIX_TIMESTAMP());
                        END IF;
                    END");
            } catch (Exception $e) { /* no TRIGGER privilege → poll-only mode still works for push */ }
        }
    }
}

/* ------------------------------------------------------------------ */
/* actions                                                             */
/* ------------------------------------------------------------------ */

/* lightweight poll: lets the desktop know instantly (every few seconds)
   whether the site has produced new changes — no trigger install, no data */
if ($action === 'ping') {
    $max = 0;
    try { $max = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM desk_change_log")->fetchColumn(); } catch (Exception $e) {}
    jout(['ok' => true, 'log_max' => $max]);
}

if ($action === 'handshake') {
    ensure_infra($pdo);
    $trg = 0;
    try {
        $trg = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'desk_sync_%'")->fetchColumn();
    } catch (Exception $e) {}
    jout(['ok' => true, 'server' => 'desk-sync-api v2.13', 'triggers' => $trg, 'tables' => SYNC_TABLES]);
}

/* v2.11.0: cheap per-table fingerprint (row count + sum of ids) so the desktop
   can verify it is a TRUE MIRROR of the site and re-import any table that
   drifted (demo rows, pre-sync edits, missed trigger, crash mid-import). */
if ($action === 'recon') {
    $out = [];
    foreach (SYNC_TABLES as $t) {
        if (!table_exists($pdo, $t)) continue;
        try {
            if ($t === 'settings') {
                $r = $pdo->query("SELECT COUNT(*) n FROM `settings` WHERE key_name NOT LIKE 'desk!_%' ESCAPE '!'")->fetch(PDO::FETCH_ASSOC);
                $out[$t] = ['n' => (int)$r['n'], 's' => 0];
            } else {
                $r = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(`id`),0) s FROM `$t`")->fetch(PDO::FETCH_ASSOC);
                $out[$t] = ['n' => (int)$r['n'], 's' => (string)(int)$r['s']];
            }
        } catch (Exception $e) { /* skip */ }
    }
    jout(['ok' => true, 'tables' => $out]);
}

if ($action === 'snapshot') {
    $tbl = (string)($in['tbl'] ?? '');
    if (!in_array($tbl, SYNC_TABLES, true)) jfail('bad table');
    if (!table_exists($pdo, $tbl)) jout(['ok' => true, 'rows' => [], 'log_max' => 0]);
    $pk = pk_col($tbl);
    $after = $in['after'] ?? 0;
    $limit = min(1000, max(1, (int)($in['limit'] ?? 400)));
    $extra = ($tbl === 'settings') ? " AND key_name NOT LIKE 'desk!_%' ESCAPE '!'" : '';
    $st = $pdo->prepare("SELECT * FROM `$tbl` WHERE `$pk` > ?$extra ORDER BY `$pk` ASC LIMIT $limit");
    $st->execute([$after]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $logMax = 0;
    try { $logMax = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM desk_change_log")->fetchColumn(); } catch (Exception $e) {}
    jout(['ok' => true, 'rows' => $rows, 'log_max' => $logMax]);
}

if ($action === 'pull') {
    ensure_infra($pdo);
    $cursor = (int)($in['cursor'] ?? 0);
    $limit  = min(1000, max(1, (int)($in['limit'] ?? 400)));
    $st = $pdo->prepare("SELECT id, tbl, rid, op, ts FROM desk_change_log WHERE id > ? ORDER BY id ASC LIMIT $limit");
    $st->execute([$cursor]);
    $log = $st->fetchAll(PDO::FETCH_ASSOC);
    $next = $cursor;
    $latest = [];
    foreach ($log as $r) {
        $latest[$r['tbl'] . '|' . $r['rid']] = $r;
        $next = max($next, (int)$r['id']);
    }
    $changes = [];
    foreach ($latest as $r) {
        $c = ['tbl' => $r['tbl'], 'rid' => $r['rid'], 'op' => $r['op'], 'ts' => (int)$r['ts']];
        if ($r['op'] !== 'D' && table_exists($pdo, $r['tbl'])) {
            $pk = pk_col($r['tbl']);
            $st2 = $pdo->prepare("SELECT * FROM `{$r['tbl']}` WHERE `$pk` = ?");
            $st2->execute([$r['rid']]);
            $row = $st2->fetch(PDO::FETCH_ASSOC);
            if ($row) { $c['op'] = 'U'; $c['row'] = $row; } else { $c['op'] = 'D'; }
        }
        $changes[] = $c;
    }
    jout(['ok' => true, 'changes' => $changes, 'next_cursor' => $next, 'more' => count($log) >= $limit]);
}

if ($action === 'push') {
    ensure_infra($pdo);
    $changes = $in['changes'] ?? [];
    if (!is_array($changes)) jfail('bad changes');
    foreach($changes as $change)if(!empty($change['row']) && ceg_sync_conflict($change['tbl']??'',$change['row']))jfail('class exam group conflict: simultaneous groups require review; no automatic merge');
    $applied = 0;
    suppress_triggers($pdo, true);
    foreach ($changes as $c) {
        $tbl = (string)($c['tbl'] ?? '');
        if (!in_array($tbl, SYNC_TABLES, true) || !table_exists($pdo, $tbl)) continue;
        $rid = $c['rid'] ?? null;
        if ($rid === null) continue;
        $pk = pk_col($tbl);
        if ($tbl === 'settings' && strpos((string)$rid, 'desk_') === 0) continue;
        try {
            if (($c['op'] ?? '') === 'D' || empty($c['row'])) {
                $st = $pdo->prepare("DELETE FROM `$tbl` WHERE `$pk` = ?");
                $st->execute([$rid]);
            } else {
                $row  = $c['row'];
                $cols = array_values(array_intersect(table_columns($pdo, $tbl), array_keys($row)));
                if (!$cols) continue;
                $cl = '`' . implode('`,`', $cols) . '`';
                $ph = implode(',', array_fill(0, count($cols), '?'));
                $up = [];
                foreach ($cols as $col) if ($col !== $pk) $up[] = "`$col` = VALUES(`$col`)";
                $sql = "INSERT INTO `$tbl` ($cl) VALUES ($ph)"
                     . ($up ? " ON DUPLICATE KEY UPDATE " . implode(',', $up) : "");
                $st = $pdo->prepare($sql);
                $vals = [];
                foreach ($cols as $col) $vals[] = $row[$col];
                $st->execute($vals);
            }
            $applied++;
        } catch (Exception $e) { /* skip broken row, keep going */ }
    }
    suppress_triggers($pdo, false);
    jout(['ok' => true, 'applied' => $applied]);
}

/* v2.12.0: file-sync actions — the desktop must mirror not only rows but the
   FILES they reference (exam source PDFs, converted page images, student
   photos, stamps). All paths are locked inside uploads/. */
function desk_safe_rel($p) {
    $p = str_replace('\\', '/', trim((string)$p));
    $p = ltrim($p, '/');
    if ($p === '' || strpos($p, '..') !== false || strpos($p, "\0") !== false) return '';
    if (strpos($p, 'uploads/') !== 0) return '';
    return $p;
}

if ($action === 'files_list') {
    $dirs = $in['dirs'] ?? [];
    if (!is_array($dirs)) $dirs = [];
    $out = [];
    foreach (array_slice($dirs, 0, 200) as $d) {
        $rel = desk_safe_rel($d);
        if ($rel === '') continue;
        $full = SDP_SYNC_ROOT . '/' . $rel;
        $files = [];
        if (is_dir($full)) {
            foreach (scandir($full) ?: [] as $fn) {
                if ($fn === '' || $fn[0] === '.') continue;
                $fp = $full . '/' . $fn;
                if (is_file($fp)) $files[] = ['p' => $rel . '/' . $fn, 's' => (int)filesize($fp), 'm' => (int)filemtime($fp)];
            }
        }
        $out[$rel] = $files;
    }
    jout(['ok' => true, 'dirs' => $out]);
}

if ($action === 'files_stat') {
    $paths = $in['paths'] ?? [];
    if (!is_array($paths)) $paths = [];
    $out = [];
    foreach (array_slice($paths, 0, 2000) as $p) {
        $rel = desk_safe_rel($p);
        if ($rel === '') continue;
        $full = SDP_SYNC_ROOT . '/' . $rel;
        $out[$rel] = is_file($full) ? ['s' => (int)filesize($full), 'm' => (int)filemtime($full)] : ['s' => -1, 'm' => 0];
    }
    jout(['ok' => true, 'files' => $out]);
}

if ($action === 'file_get') {
    $rel = desk_safe_rel($in['path'] ?? '');
    if ($rel === '') jfail('bad path');
    $full = SDP_SYNC_ROOT . '/' . $rel;
    if (!is_file($full)) jfail('not found');
    $size = (int)filesize($full);
    if ($size > 25 * 1024 * 1024) jfail('file too large');
    jout(['ok' => true, 'b64' => base64_encode((string)file_get_contents($full)), 's' => $size, 'm' => (int)filemtime($full)]);
}

/* v2.13.0: reverse exam-design path — an exam DESIGNED ON THE DESKTOP with a
   PDF source cannot be rendered there (the portable app has no Imagick).
   The desktop pushes the PDF via file_put and then calls this action: the
   SITE converts the PDF to page images via Imagick and returns the list, so
   the desktop pulls them back and both sides show the exam identically. */
if ($action === 'exam_pages') {
    $examId = (int)($in['exam_id'] ?? 0);
    if ($examId <= 0) jfail('bad exam id');
    $qf = '';
    try {
        $st = $pdo->prepare('SELECT question_file FROM exam_schedules WHERE id = ?');
        $st->execute([$examId]);
        $qf = (string)($st->fetchColumn() ?: '');
    } catch (Exception $e) { /* row may not have synced yet */ }
    if ($qf === '' && !empty($in['path'])) $qf = (string)$in['path'];
    $rel = desk_safe_rel($qf);
    if ($rel === '') jout(['ok' => true, 'pages' => []]);
    $full = SDP_SYNC_ROOT . '/' . $rel;
    if (!is_file($full)) {
        $cached = [];
        foreach (glob(SDP_SYNC_ROOT . '/uploads/exams/pdf-pages/exam_' . $examId . '/page_*.jpg') ?: [] as $img) $cached[] = 'uploads/exams/pdf-pages/exam_' . $examId . '/' . basename($img);
        sort($cached);
        jout(['ok' => true, 'pages' => $cached]);
    }
    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') jout(['ok' => true, 'pages' => [$rel]]);  // image source needs no conversion

    $cacheDir = SDP_SYNC_ROOT . '/uploads/exams/pdf-pages/exam_' . $examId;
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    $declaredPages = max(0, (int)($in['declared_pages'] ?? 0));
    $selPages = array_values(array_filter(array_map('intval', explode(',', (string)($in['selected_pages'] ?? ''))), function($x){ return $x >= 1; }));
    sort($selPages);
    $pages = [];
    foreach (glob($cacheDir . '/page_*.jpg') ?: [] as $img) {
        if (filemtime($img) < filemtime($full)) @unlink($img); else $pages[] = 'uploads/exams/pdf-pages/exam_' . $examId . '/' . basename($img);
    }
    $wantN = $selPages ? count($selPages) : $declaredPages;
    if ($pages && ($wantN <= 0 || count($pages) >= $wantN)) { sort($pages); jout(['ok' => true, 'pages' => $pages]); }
    $pages = [];
    if ($declaredPages <= 0 && class_exists('Imagick')) {
        try { $probe = new Imagick(); $probe->pingImage($full); $declaredPages = max(1, (int)$probe->getNumberImages()); $probe->clear(); } catch (Exception $e) { $declaredPages = 1; }
    }
    if ($declaredPages <= 0) $declaredPages = 1;
    $srcList = $selPages ?: range(1, $declaredPages);
    if (class_exists('Imagick')) {
        foreach ($srcList as $oi => $sp) {
            $i = $sp - 1;
            try {
                $page = new Imagick(); $page->setResolution(180, 180); $page->readImage($full . '[' . $i . ']');
                $page->setIteratorIndex(0); $page->setImageBackgroundColor('white');
                if (defined('Imagick::ALPHACHANNEL_REMOVE')) $page->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $page->setImageFormat('jpeg'); $page->setImageCompressionQuality(90);
                $file = $cacheDir . '/page_' . str_pad((string)($oi + 1), 3, '0', STR_PAD_LEFT) . '.jpg';
                $page->writeImage($file); $page->clear();
                $pages[] = 'uploads/exams/pdf-pages/exam_' . $examId . '/' . basename($file);
            } catch (Exception $e) { break; }
        }
    }
    sort($pages);
    if (count($pages) >= count($srcList) && count($srcList) >= 1) @unlink($full);
    jout(['ok' => true, 'pages' => array_slice($pages, 0, count($srcList)), 'converted' => count($pages) > 0]);
}

if ($action === 'file_put') {
    $rel = desk_safe_rel($in['path'] ?? '');
    if ($rel === '') jfail('bad path');
    $b64 = (string)($in['b64'] ?? '');
    $data = base64_decode($b64, true);
    if ($data === false || strlen($data) > 25 * 1024 * 1024) jfail('bad data');
    $full = SDP_SYNC_ROOT . '/' . $rel;
    $dir = dirname($full);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (@file_put_contents($full, $data) === false) jfail('write failed');
    $m = (int)($in['m'] ?? 0);
    if ($m > 0) @touch($full, $m);
    jout(['ok' => true, 's' => strlen($data)]);
}

jfail('unknown action');
