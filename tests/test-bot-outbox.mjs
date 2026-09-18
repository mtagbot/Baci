// Actual application PHP + SQLite in MEMFS. Providers/network failures are deterministic mocks.
import assert from 'node:assert/strict';
import {readFileSync,writeFileSync,mkdirSync} from 'node:fs';
import {php,run,student,loginAdmin,req} from './harness/lib.mjs';
const desktop=process.env.BOT_DESKTOP==='1';let checks=0;
const check=(v,m)=>{assert(v,m);checks++;};
const mock=`function bot_api_request($platform,$method,$data=[],$multipart=false){
 unset($GLOBALS['bot_outbox_last']);
 $wire=json_decode(file_get_contents('/harness/wire.json'),true);$wire[]=['platform'=>$platform,'method'=>$method,'data'=>$data];file_put_contents('/harness/wire.json',json_encode($wire));
 $mode=file_get_contents('/harness/provider');
 if($mode==='offline')throw new RuntimeException('خطای ارتباط با API ربات: offline');
 if($mode==='limited')throw new RuntimeException('HTTP 429 {"parameters":{"retry_after":120}}');
 if($mode==='bad')return ['ok'=>false];
 if($mode==='invalid')throw new RuntimeException('HTTP 401 token-FIXTURE-SECRET');
 return ['ok'=>true,'result'=>['message_id'=>count($wire)]];
}`;
php.writeFile('/harness/provider','offline');php.writeFile('/harness/wire.json','[]');
async function code(c){const r=await run(`<?php ${mock} require '/www/includes/bot_helpers.php'; ${c}`);assert(!r.err,r.err);assert(!/Fatal error|Warning:|Parse error/.test(r.out),r.out);return r.out;}
async function json(c){return JSON.parse(await code(c));}
const jobs=()=>json("bot_outbox_schema();echo json_encode(bot_outbox_sql('SELECT * FROM bot_outbox ORDER BY created_at,job_id')->fetchAll(PDO::FETCH_ASSOC));");
const wire=()=>JSON.parse(php.readFileAsText('/harness/wire.json'));
await code(`ensure_bot_schema('bale');ensure_bot_schema('telegram');require '/www/includes/school_roles.php';ensure_school_roles_schema();set_setting('telegram_bot_token','token-FIXTURE-SECRET');set_setting('bale_bot_token','token-FIXTURE-SECRET');
set_setting('desk_sync_enabled','1');set_setting('desk_sync_url','https://school.test/reports/desk-sync-api.php');set_setting('desk_sync_key','fixture-sync-key-not-production');
foreach(['bale','telegram'] as $pf){$t=bot_user_table($pf);$col=$pf.'_chat_id';foreach(['101','102','103'] as $cid)DB::execute("INSERT INTO $t ($col,student_id) VALUES (?,?)",[$cid,${student.id}]);}
DB::execute("INSERT INTO teachers (id,national_id,full_name,password,status,academic_year,is_deputy,is_executive,is_counselor) VALUES (901,'teacher901','دبیر آزمون','hash',1,'1404/1405',1,1,1)");
foreach(['bale','telegram'] as $pf)DB::execute("INSERT INTO bot_admin_sessions (platform,chat_id,role_type,teacher_id,is_active,created_at_jalali) VALUES (?,?,'teacher',901,1,'1405/06/27')",[$pf,'201']);`);
// This record really exists before its original production notifier is called.
await code(`require '/www/includes/school_roles.php';DB::execute("INSERT INTO student_discipline_records (id,student_id,title_text,occurred_at_jalali,notify_parents,created_at_jalali) VALUES (5000001,?,'نظم','1405/06/27',1,'1405/06/27')",[${student.id}]);notify_student_discipline_bots(${student.id},'نظم','1405/06/27',5000001);`);
let rows=await jobs();check(rows.length===6,'all three parents on both platforms queued despite outage');check(rows.every(r=>r.state==='pending'),'no false delivery');check(rows.every(r=>JSON.parse(r.payload).reply_markup.inline_keyboard[0][0].callback_data==='ack_disc_5000001'),'callback retained');check(desktop?wire().length===0:(wire().length>0&&wire().length<=3),'desktop UI performs zero bot I/O');
await code(`require '/www/includes/bot_role_engine.php';bot_notify_teacher_chats(901,'اعلان دبیر',['inline_keyboard'=>[[['text'=>'پاسخ','callback_data'=>'objreply_1']]]]);bot_notify_role_chats('is_executive','اعلان معاون');require_once '/www/includes/school_roles.php';notify_counselors_new_request(1,'مشاوره');`);
rows=await jobs();check(rows.length===12,'teacher, deputy and counselor notifications queued on both platforms');check(rows.filter(r=>JSON.parse(r.payload).chat_id==='201').length===6,'all staff targets');
let broadcast=await json(`echo json_encode(bot_send_targeted_text('telegram','student',${student.id},'اطلاعیه'));`);check(broadcast.queued===3&&broadcast.sent===0&&broadcast.failed===0,'accepted vs sent counters');
check((await json("echo json_encode(DB::fetchAll(\"SELECT status,response FROM bot_message_logs WHERE message_type='targeted_text'\"));")).every(r=>r.status==='queued'&&r.response.startsWith('outbox:')),'truthful queued logs');
// Fresh PHP request/restart sees every durable item.
check((await jobs()).length===15,'persistent across requests');
// Durable accept/dedup and collision rejection, with no provider side effects.
const id='a'.repeat(32),payload={chat_id:'901',text:'idempotent'};
await code(`bot_outbox_accept([['id'=>'${id}','platform'=>'telegram','payload'=>['chat_id'=>'901','text'=>'idempotent']]]);bot_outbox_accept([['id'=>'${id}','platform'=>'telegram','payload'=>['chat_id'=>'901','text'=>'idempotent']]]);`);
check((await jobs()).filter(r=>r.job_id===id).length===1,'lost-ACK submission deduplicates');
check(await code(`try{bot_outbox_accept([['id'=>'${id}','platform'=>'telegram','payload'=>['chat_id'=>'902','text'=>'changed']]]);echo 'BAD';}catch(RuntimeException $e){echo 'CONFLICT';}`)==='CONFLICT','same ID different payload rejected');
// Live lease cannot be stolen. Crashed/stale lease resumes on a later worker.
await code(`bot_outbox_sql('UPDATE bot_outbox_limits SET next_try=0');bot_outbox_sql("UPDATE bot_outbox SET state='sending',lease_until=?,claim_token='other' WHERE job_id=?",[time()+600,'${id}']);`);const beforeLease=wire().length;
await code(`bot_outbox_deliver('${id}');`);check(wire().length===beforeLease,'live lease excluded');
php.writeFile('/harness/provider','online');await code(`bot_outbox_sql("UPDATE bot_outbox SET lease_until=0 WHERE job_id=?",['${id}']);bot_outbox_deliver('${id}');bot_outbox_deliver('${id}');`);
check(wire().length===beforeLease+1,'expired lease recovers once; confirmed sent never resent');
// Only local-owner work is drained; relay jobs cannot accidentally send from desktop.
const beforeDrain=wire().length;await code("bot_outbox_sql('UPDATE bot_outbox_limits SET next_try=0');bot_outbox_sql(\"UPDATE bot_outbox SET next_try=0 WHERE state='pending'\");bot_outbox_drain(100,40);");rows=await jobs();check(desktop?wire().length===beforeDrain:wire().length===beforeDrain+15,'one delivery authority');
if(!desktop)check(rows.every(r=>r.state==='sent'),'site reconnect drains entire backlog');
// Provider rate limit survives restarts and pauses the whole platform, not just one item.
const lim='b'.repeat(32),following='c'.repeat(32);await code(`bot_outbox_store('${lim}','bale',['chat_id'=>'901','text'=>'limit']);bot_outbox_store('${following}','bale',['chat_id'=>'902','text'=>'following']);`);php.writeFile('/harness/provider','limited');await code(`bot_outbox_deliver('${lim}');`);const limitCalls=wire().length;
await code(`bot_outbox_deliver('${following}');`);check(wire().length===limitCalls,'rate limit applies to later jobs across requests');
check((await jobs()).find(r=>r.job_id===lim).next_try>Date.now()/1000+90,'retry_after honored');
php.writeFile('/harness/provider','online');
await code("bot_outbox_store(str_repeat('e',32),'telegram',['chat_id'=>'701','text'=>'other platform']);bot_outbox_drain(100,40);");
check((await jobs()).find(r=>r.job_id==='e'.repeat(32)).state==='sent','Bale rate limit does not starve Telegram');
check((await jobs()).find(r=>r.job_id===lim).state==='pending','paused platform remains pending');
await code("bot_outbox_sql('UPDATE bot_outbox_limits SET next_try=0');bot_outbox_sql(\"UPDATE bot_outbox SET next_try=0 WHERE state='pending'\");");
php.writeFile('/harness/provider','bad');await code(`bot_outbox_deliver('${lim}');`);check((await jobs()).find(r=>r.job_id===lim).state==='pending','malformed/unconfirmed success is not sent');
php.writeFile('/harness/provider','invalid');await code(`bot_outbox_sql('UPDATE bot_outbox SET next_try=0 WHERE job_id=?',['${lim}']);bot_outbox_deliver('${lim}');`);check(!(await jobs()).find(r=>r.job_id===lim).last_error.includes('token-FIXTURE-SECRET'),'secrets redacted');
const beforeBad=wire().length;check(await code("try{bot_outbox_store('bad','telegram',['chat_id'=>'x','text'=>'x']);echo 'BAD';}catch(InvalidArgumentException $e){echo 'OK';}")==='OK','bad IDs rejected');check(wire().length===beforeBad,'validation never sends');
php.writeFile('/harness/provider','online');await code("bot_outbox_sql('UPDATE bot_outbox_limits SET next_try=0');bot_outbox_sql(\"UPDATE bot_outbox SET next_try=0 WHERE state='pending'\");bot_outbox_drain(100,40);");
const beforeBadReceipt=await jobs();
check(await code("try{bot_outbox_apply_receipts([], [['job_id'=>str_repeat('a',32),'state'=>'sent']]);echo 'BAD';}catch(RuntimeException $e){echo 'OK';}")==='OK','unsolicited acknowledgements rejected');
check(JSON.stringify(await jobs())===JSON.stringify(beforeBadReceipt),'unsolicited receipt cannot change state');
check(await code("try{bot_outbox_apply_receipts([['id'=>str_repeat('d',32)]],[]);echo 'BAD';}catch(RuntimeException $e){echo 'OK';}")==='OK','missing receipts are not delivery');
check(await code("try{bot_outbox_store(str_repeat('d',32),'telegram',['chat_id'=>'x','text'=>'x','url'=>'https://evil.test']);echo 'BAD';}catch(InvalidArgumentException $e){echo 'OK';}")==='OK','arbitrary transport URLs/methods rejected');
// Transactional enqueue cannot send a rolled-back business event on site.
const txWire=wire().length,txCount=(await jobs()).length;await code("$pdo=DB::getInstance()->getPdo();$pdo->beginTransaction();bot_send_message('telegram','303','rollback');$pdo->rollBack();");check(wire().length===txWire&&(await jobs()).length===txCount,'rollback causes no delivery');
if(desktop){
 const outgoing=await json('echo json_encode(bot_outbox_relay_jobs());');check(outgoing.length===15,'all relay-owned items survive failed direct drain');
 // Simulate a different DB on the website, run its actual authenticated endpoint.
 php.writeFile('/data/server.sqlite',php.readFileAsBuffer('/data/school.sqlite'));
 const originalConfig=php.readFileAsText('/www/config/database.php'),originalRelease=php.readFileAsText('/www/config/release.php');
 php.writeFile('/www/config/database.php',"<?php return ['driver'=>'sqlite','database'=>'/data/server.sqlite'];");php.writeFile('/www/config/release.php',"<?php return ['distribution'=>'site'];");
 php.writeFile('/www/class-exam-sync-api.php',readFileSync('update-v4.152.0/class-exam-sync-api.php'));
 await code('bot_outbox_sql("DELETE FROM bot_outbox");');
 const syncKey='ab'.repeat(32);php.writeFile('/www/config/desk-sync-key.php',`<?php return '${syncKey}';`);
 async function endpoint(key,jobs){return php.run({relativeUri:'/class-exam-sync-api.php',method:'POST',headers:{'content-type':'application/json'},body:new TextEncoder().encode(JSON.stringify({action:'bot_outbox',key,jobs})),code:`<?php ${mock} require '/www/class-exam-sync-api.php';`});}
 let denied=await endpoint('wrong',outgoing);check(JSON.parse(denied.text).ok===false&&(await jobs()).length===0,'endpoint authentication before writes');
 php.writeFile('/www/config/desk-sync-key.php',"<?php return 'invalid';");
 php.writeFile('/www/desk-sync-api.php',`<?php define('DESK_SYNC_KEY','${syncKey}');`);
 check(!JSON.parse((await endpoint(syncKey,outgoing)).text).ok&&(await jobs()).length===0,'invalid private key cannot fall back to legacy key');
 php.unlink('/www/config/desk-sync-key.php');check(JSON.parse((await endpoint(syncKey,[])).text).ok,'legacy configured key still supported when no private key exists');
 php.writeFile('/www/config/desk-sync-key.php',`<?php return '${syncKey}';`);
 let accepted=await endpoint(syncKey,outgoing);check(!accepted.errors,accepted.errors);let reply=JSON.parse(accepted.text);check(reply.ok&&reply.receipts.length===15,'actual endpoint accepts entire batch');
 const firstSent=(await jobs()).filter(r=>r.state==='sent').length;check(firstSent===1,'endpoint worker bounded to one send');
 // Lose this response, resend exactly the same envelope: no duplicate row / redelivery.
 accepted=await endpoint(syncKey,outgoing);check(JSON.parse(accepted.text).ok&&(await jobs()).length===15,'lost acknowledgement retry does not multiply jobs');
 await code('bot_outbox_drain(100,40);');let receipt=await json(`echo json_encode(bot_outbox_receipts(${JSON.stringify(outgoing.map(j=>j.id)).replaceAll('[','array(').replaceAll(']',')')}));`);check(receipt.every(r=>r.state==='sent'),'server drains all relayed notifications');
 const callsAfter=wire().length;await endpoint(syncKey,outgoing);check(wire().length===callsAfter,'repeated accepted/sent jobs never sent again');
 php.writeFile('/www/config/database.php',originalConfig);php.writeFile('/www/config/release.php',originalRelease);
 php.writeFile('/harness/jobs.json',JSON.stringify(outgoing));php.writeFile('/harness/receipts.json',JSON.stringify(receipt));
 await code("bot_outbox_apply_receipts(json_decode(file_get_contents('/harness/jobs.json'),true),json_decode(file_get_contents('/harness/receipts.json'),true));");check((await json('echo json_encode(bot_outbox_relay_jobs());')).length===0,'desktop acknowledges only delivered jobs');
 check((await json("echo json_encode(DB::fetchAll(\"SELECT status FROM bot_message_logs WHERE message_type='targeted_text'\"));")).every(r=>r.status==='sent'),'delivery receipts repair queued log status');
}
const storeBefore=wire().length;
check(await code("bot_outbox_schema();DB::getInstance()->getPdo()->rawScript(\"CREATE TRIGGER reject_outbox BEFORE INSERT ON bot_outbox BEGIN SELECT RAISE(ABORT,'storage failure'); END;\");try{bot_send_message('telegram','801','storage failure');echo 'BAD';}catch(PDOException $e){echo 'FAILED';}finally{bot_outbox_sql('DROP TRIGGER reject_outbox');}")==='FAILED','storage failure never reports queued success');
check(wire().length===storeBefore,'storage failure cannot send an untracked message');
// No generic sync of outbox tables (snapshot/reconcile must not delete or replay jobs).
for(const file of ['update-v4.152.0/includes/desk_sync.php','update-v4.152.0/class-exam-sync-api.php']){const text=readFileSync(file,'utf8');const list=text.match(/json_encode\(\[([\s\S]*?)\]\)/)?.[1]||text.match(/const SYNC_TABLES\s*=\s*\[([\s\S]*?)\];/)?.[1];check(list&&!list.includes('bot_outbox'),'outbox not generic sync tables');}
await loginAdmin('botAdmin');const page=await req('',{file:'telegram-bot.php',sid:'botAdmin'});check(!page.res.fatal&&!page.stderr,page.res.fatal||page.stderr);check(page.res.page.includes('صف ماندگار اعلان‌ها'),'queue status UI rendered');
mkdirSync('docs/bot-outbox',{recursive:true});writeFileSync(`docs/bot-outbox/${desktop?'desktop':'site'}.json`,JSON.stringify({checks,provider:'deterministic mock; no live messages',runtime:'PHP 8.3 WASM + real SQLite',finalJobs:(await jobs()).map(r=>({platform:r.platform,owner:r.owner,state:r.state,attempts:r.attempts}))},null,2));console.log('PASS',checks,desktop?'desktop + authenticated relay':'site','outbox checks');process.exit(0);
