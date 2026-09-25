<?php
/** Durable text-notification transport. Private to each installation: NEVER add these tables
 * to DeskSync's generic snapshot/reconcile tables. Delivery is at-least-once; a provider
 * accepting a message immediately before a process/network failure can cause a retry. */
function bot_outbox_desktop() {
    static $desktop;
    if ($desktop === null) {
        $file=dirname(__DIR__).'/config/release.php';
        $cfg=is_file($file)?require $file:[];
        $desktop=($cfg['distribution']??'')==='desktop' || PHP_SAPI==='cli-server';
    }
    return $desktop;
}
function bot_outbox_schema() {
    static $ready=false;if($ready)return;
    $pdo=DB::getInstance()->getPdo();
    try {
        $pdo->query('SELECT job_id FROM bot_outbox LIMIT 0');
        $pdo->query('SELECT platform FROM bot_outbox_limits LIMIT 0');
        $ready=true;return;
    } catch(PDOException $missing) {
        // MySQL DDL implicitly commits: never migrate inside a business transaction.
        if($pdo->inTransaction())throw new RuntimeException('Initialize notification queue before opening a transaction');
    }
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_outbox (
        job_id VARCHAR(32) PRIMARY KEY, platform VARCHAR(16) NOT NULL,
        payload TEXT NOT NULL, payload_hash VARCHAR(64) NOT NULL,
        owner VARCHAR(8) NOT NULL, state VARCHAR(16) NOT NULL DEFAULT 'pending',
        attempts INTEGER NOT NULL DEFAULT 0, next_try BIGINT NOT NULL DEFAULT 0,
        lease_until BIGINT NOT NULL DEFAULT 0, claim_token VARCHAR(32) NOT NULL DEFAULT '',
        created_at BIGINT NOT NULL, sent_at BIGINT NOT NULL DEFAULT 0,
        message_id VARCHAR(80) NOT NULL DEFAULT '', last_error TEXT NOT NULL
        ".($mysql?", KEY bot_outbox_due (owner,state,next_try)":"").")".($mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':''));
    if(!$mysql)$pdo->exec('CREATE INDEX IF NOT EXISTS bot_outbox_due ON bot_outbox(owner,state,next_try)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_outbox_limits (platform VARCHAR(16) PRIMARY KEY, next_try BIGINT NOT NULL DEFAULT 0)".($mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':''));
    $ready=true;
}
function bot_outbox_sql($sql,$params=[]) {
    $st=DB::getInstance()->getPdo()->prepare($sql);$st->execute($params);return $st;
}
function bot_outbox_payload($platform,$payload) {
    if(!in_array($platform,['telegram','bale'],true)||!is_array($payload))throw new InvalidArgumentException('Invalid notification platform/payload');
    if(array_diff(array_keys($payload),['chat_id','text','reply_markup']))throw new InvalidArgumentException('Invalid notification fields');
    if(!isset($payload['chat_id'],$payload['text'])||!is_string($payload['chat_id'])||!is_string($payload['text'])||$payload['chat_id']===''||strlen($payload['chat_id'])>80||$payload['text']==='')throw new InvalidArgumentException('Invalid notification recipient/text');
    if(isset($payload['reply_markup'])&&!is_array($payload['reply_markup']))throw new InvalidArgumentException('Invalid keyboard');
    // Stable field order on both sides; reject oversize input rather than truncating a message.
    $p=['chat_id'=>$payload['chat_id'],'text'=>$payload['text']];if(isset($payload['reply_markup']))$p['reply_markup']=$payload['reply_markup'];
    $json=json_encode($p,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    if(strlen($json)>60000)throw new InvalidArgumentException('Notification payload too large');
    return $json;
}
function bot_outbox_store($id,$platform,$payload,$owner='local') {
    if(!preg_match('/^[a-f0-9]{32}$/D',(string)$id)||!in_array($owner,['local','relay'],true))throw new InvalidArgumentException('Invalid notification identity');
    $json=bot_outbox_payload($platform,$payload);$hash=hash('sha256',$platform."\n".$json);
    bot_outbox_schema();
    try {
        bot_outbox_sql("INSERT INTO bot_outbox (job_id,platform,payload,payload_hash,owner,created_at,last_error) VALUES (?,?,?,?,?,?,?)",[$id,$platform,$json,$hash,$owner,time(),'']);
    } catch(PDOException $e) {
        // Only an IDENTICAL existing event is an acknowledgement. Never hide disk/SQL errors.
        $old=bot_outbox_sql('SELECT payload_hash,owner FROM bot_outbox WHERE job_id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if(!$old)throw $e;
        if(!hash_equals($old['payload_hash'],$hash)||$old['owner']!==$owner)throw new RuntimeException('Notification identity conflict');
    }
    return $id;
}
function bot_outbox_log_state($id,$state) {
    // Existing log table is optional; the outbox itself is the authoritative receipt.
    try { bot_outbox_sql('UPDATE bot_message_logs SET status=? WHERE response=?',[$state==='sent'?'sent':'queued','outbox:'.$id]); }catch(PDOException $e){}
}
function bot_outbox_enqueue($platform,$payload) {
    $owner='local';
    if(bot_outbox_desktop()) {
        require_once __DIR__.'/desk_sync.php';
        if(DeskSync::enabled())$owner='relay';
    }
    $id=bot_outbox_store(bin2hex(random_bytes(16)),$platform,$payload,$owner);
    // Enqueue the ENTIRE recipient loop before any site network I/O. A single offline
    // recipient must never prevent later recipients from even entering the queue.
    static $scheduled=false;
    if(!bot_outbox_desktop()&&!$scheduled) {
        $scheduled=true;
        register_shutdown_function(function(){
            try {
                if(DB::getInstance()->getPdo()->inTransaction())return;
                if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
                if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();
                bot_outbox_drain(3,8);
            }catch(Throwable $e){error_log('Notification queue retained; worker will retry.');}
        });
    }
    $row=bot_outbox_sql('SELECT state FROM bot_outbox WHERE job_id=?',[$id])->fetch(PDO::FETCH_ASSOC);
    $GLOBALS['bot_outbox_last']=['id'=>$id,'platform'=>$platform,'chat_id'=>$payload['chat_id'],'state'=>$row['state']];
    return ['ok'=>true,'queued'=>true,'outbox_id'=>$id];
}
function bot_outbox_clean_error($e,$platform) {
    $message=$e->getMessage();$token=(string)get_setting(bot_token_key($platform),'');
    if($token!=='')$message=str_replace($token,'[redacted]',$message);
    return mb_substr($message,0,700,'UTF-8');
}
function bot_outbox_deliver($id) {
    bot_outbox_schema();$now=time();
    $row=bot_outbox_sql('SELECT * FROM bot_outbox WHERE job_id=?',[$id])->fetch(PDO::FETCH_ASSOC);
    if(!$row||$row['owner']!=='local')return null;
    $limit=bot_outbox_sql('SELECT next_try FROM bot_outbox_limits WHERE platform=?',[$row['platform']])->fetch(PDO::FETCH_ASSOC);
    if((int)($limit['next_try']??0)>$now)return null;
    $claim=bin2hex(random_bytes(16));
    $n=bot_outbox_sql("UPDATE bot_outbox SET state='sending',claim_token=?,lease_until=?,attempts=attempts+1 WHERE job_id=? AND owner='local' AND next_try<=? AND (state='pending' OR (state='sending' AND lease_until<?))",[$claim,$now+300,$id,$now,$now])->rowCount();
    if(!$n)return null;
    try {
        $payload=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
        $result=bot_api_request($row['platform'],'sendMessage',$payload,false);
        // Success must be explicit; an HTML page, malformed JSON or HTTP 200 error isn't delivery.
        if(!is_array($result)||($result['ok']??null)!==true)throw new RuntimeException('Bot did not confirm delivery');
        bot_outbox_sql("UPDATE bot_outbox SET state='sent',sent_at=?,lease_until=0,claim_token='',last_error='',message_id=? WHERE job_id=? AND claim_token=?",[time(),(string)($result['result']['message_id']??''),$id,$claim]);
        bot_outbox_log_state($id,'sent');return $result;
    } catch(Throwable $e) {
        $retry=min(60,10*(2**min(3,(int)$row['attempts'])));
        if(preg_match('/HTTP (?:400|401|403)\b/',$e->getMessage()))$retry=min(3600,10*(2**min(9,(int)$row['attempts'])));
        $pausePlatform=(bool)preg_match('/خطای ارتباط|توکن ربات تنظیم نشده|HTTP (?:5\d\d|401)\b/u',$e->getMessage());
        if(preg_match('/"retry_after"\s*:\s*(\d+)/',$e->getMessage(),$m)) {
            $retry=max($retry,min(86400,(int)$m[1]));$pausePlatform=true;
        }
        if($pausePlatform) {
            try { bot_outbox_sql('INSERT INTO bot_outbox_limits (platform,next_try) VALUES (?,0)',[$row['platform']]); }catch(PDOException $duplicate){}
            bot_outbox_sql('UPDATE bot_outbox_limits SET next_try=CASE WHEN next_try>? THEN next_try ELSE ? END WHERE platform=?',[$now+$retry,$now+$retry,$row['platform']]);
        }
        bot_outbox_sql("UPDATE bot_outbox SET state='pending',next_try=?,lease_until=0,claim_token='',last_error=? WHERE job_id=? AND claim_token=?",[$now+$retry,bot_outbox_clean_error($e,$row['platform']),$id,$claim]);
        bot_outbox_log_state($id,'pending');return null;
    }
}
function bot_outbox_drain($limit=10,$seconds=20) {
    bot_outbox_schema();$start=microtime(true);$sent=0;$now=time();
    // Long-lived desktop workers must see token/relay corrections without restarting.
    unset($GLOBALS['__settings_cache_loaded'],$GLOBALS['__settings_cache']);
    $limit=max(1,min(100,(int)$limit));
    $rows=bot_outbox_sql("SELECT job_id FROM bot_outbox WHERE owner='local' AND next_try<=? AND (state='pending' OR (state='sending' AND lease_until<?)) AND NOT EXISTS (SELECT 1 FROM bot_outbox_limits l WHERE l.platform=bot_outbox.platform AND l.next_try>?) ORDER BY next_try,created_at,job_id LIMIT $limit",[$now,$now,$now])->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r) {
        if(microtime(true)-$start>=$seconds)break;
        if(bot_outbox_deliver($r['job_id']))$sent++;
    }
    return $sent;
}
/** Authenticated sync endpoint calls this only AFTER validating the installation key. */
function bot_outbox_accept($jobs) {
    if(!is_array($jobs)||count($jobs)>20)throw new InvalidArgumentException('Invalid notification batch');
    $ids=[];
    foreach($jobs as $j) {
        if(!is_array($j))throw new InvalidArgumentException('Invalid notification');
        $ids[]=bot_outbox_store($j['id']??'', $j['platform']??'', $j['payload']??null, 'local');
    }
    return $ids;
}
function bot_outbox_receipts($ids) {
    $result=[];
    foreach($ids as $id) {
        $r=bot_outbox_sql('SELECT job_id,state,last_error,message_id,sent_at,attempts FROM bot_outbox WHERE job_id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if($r)$result[]=$r;
    }
    return $result;
}
/** A response loss never changes IDs or causes a fallback to local sending. */
function bot_outbox_relay_jobs($limit=20) {
    bot_outbox_schema();$limit=max(1,min(20,(int)$limit));
    $rows=bot_outbox_sql("SELECT job_id,platform,payload FROM bot_outbox WHERE owner='relay' AND state IN ('pending','relayed') AND next_try<=? ORDER BY next_try,created_at,job_id LIMIT $limit",[time()])->fetchAll(PDO::FETCH_ASSOC);
    return array_map(function($r){return ['id'=>$r['job_id'],'platform'=>$r['platform'],'payload'=>json_decode($r['payload'],true)];},$rows);
}
function bot_outbox_apply_receipts($jobs,$receipts) {
    if(!is_array($receipts))throw new RuntimeException('Invalid notification acknowledgements');
    $expected=array_fill_keys(array_column($jobs,'id'),true);$seen=[];
    foreach($receipts as $r) {
        $id=$r['job_id']??'';
        if(!isset($expected[$id])||isset($seen[$id])||!in_array($r['state']??'', ['pending','sending','sent'],true))throw new RuntimeException('Invalid notification acknowledgement');
        $seen[$id]=true;
        $state=$r['state']==='sent'?'sent':'relayed';
        bot_outbox_sql("UPDATE bot_outbox SET state=?,next_try=?,sent_at=?,message_id=?,last_error=?,attempts=? WHERE job_id=? AND owner='relay' AND state<>'sent'",[$state,time()+30,(int)($r['sent_at']??0),mb_substr((string)($r['message_id']??''),0,80),mb_substr((string)($r['last_error']??''),0,700),max(0,(int)($r['attempts']??0)),$id]);
        bot_outbox_log_state($id,$state);
    }
    if(count($seen)!==count($expected))throw new RuntimeException('Incomplete notification acknowledgements');
}

/* v4.164.0: گزارش «چه کسی ربات را مسدود کرده؟» — چت‌هایی که پاسخ HTTP 403
   (permission_denied) گرفته‌اند از صف ارسال‌نشده بیرون کشیده می‌شوند و
   دانش‌آموزان متصلِ هر چت از جدول اتصال همان پلتفرم نشان داده می‌شوند.
   این تابع فقط گزارش می‌دهد؛ صف و وضعیت jobها را تغییر نمی‌دهد. */
function bot_outbox_blocked_chats($platform) {
    if (!in_array($platform, ['telegram', 'bale'], true)) return [];
    bot_outbox_schema();
    $rows = bot_outbox_sql("SELECT payload, attempts FROM bot_outbox WHERE platform=? AND state<>'sent' AND last_error LIKE '%HTTP 403%'", [$platform])->fetchAll(PDO::FETCH_ASSOC);
    $chats = [];
    foreach ($rows as $r) {
        $p = json_decode((string)$r['payload'], true);
        $chat = is_array($p) ? trim((string)($p['chat_id'] ?? '')) : '';
        if ($chat === '') continue;
        if (!isset($chats[$chat])) $chats[$chat] = ['chat_id' => $chat, 'jobs' => 0, 'attempts' => 0, 'students' => []];
        $chats[$chat]['jobs']++;
        $chats[$chat]['attempts'] = max($chats[$chat]['attempts'], (int)$r['attempts']);
    }
    if (!$chats) return [];
    /* همان قرارداد نام‌گذاری bot_user_table() — عمداً محلی نگه داشته شده تا این
       گزارش بدون بارگذاری کامل هلپرهای ربات هم کار کند. */
    $table   = $platform === 'telegram' ? 'telegram_bot_users' : 'bale_bot_users';
    $chatCol = $platform === 'telegram' ? 'telegram_chat_id' : 'bale_chat_id';
    $marks = implode(',', array_fill(0, count($chats), '?'));
    try {
        $srows = DB::fetchAll("SELECT b.`$chatCol` AS chat_id, s.first_name, s.last_name, s.class_name FROM `$table` b JOIN students s ON s.id=b.student_id WHERE b.`$chatCol` IN ($marks)", array_keys($chats));
    } catch (Throwable $e) { $srows = []; }
    foreach ($srows as $sr) {
        $chat = (string)$sr['chat_id'];
        if (!isset($chats[$chat])) continue;
        $name = trim((string)$sr['first_name'] . ' ' . (string)$sr['last_name']);
        $label = $name !== '' ? $name : 'بدون نام';
        if (trim((string)($sr['class_name'] ?? '')) !== '') $label .= ' — ' . trim((string)$sr['class_name']);
        if (!in_array($label, $chats[$chat]['students'], true)) $chats[$chat]['students'][] = $label;
    }
    $out = array_values($chats);
    usort($out, function ($a, $b) { return $b['attempts'] <=> $a['attempts']; });
    return $out;
}
