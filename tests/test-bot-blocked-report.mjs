// Real PHP/SQLite: «گزارش مسدودکنندگان ربات» — the v4.164.0 blocked-parent
// report, driven through the REAL bot_outbox.php helper on php-wasm.
//
// What is proven here (execution, not claims):
//   1) chats whose unsent jobs carry an HTTP 403 error are collected with
//      job counts and max attempts,
//   2) every chat is mapped to its linked students through the platform link
//      table (bale_bot_users / telegram_bot_users),
//   3) chats without a link row still appear (as anonymous) instead of
//      silently disappearing,
//   4) sent jobs and non-403 failures are excluded, and
//   5) the bot admin page actually renders the report section.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { php, run, db, loginAdmin, req } from './harness/lib.mjs';

const sid = 'testBotBlockedReport';
await loginAdmin(sid);
let checks = 0;
const check = (v, m) => { assert(v, m); checks++; };

/* ── fixture: two linked students on one blocked chat, one unlinked chat,
      plus a sent-403 job and a non-403 failure that must stay out ───────── */
const setup = await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
require_once '/www/includes/bot_outbox.php';
ensure_bot_schema('bale');
DB::execute("DELETE FROM students WHERE id IN (6601,6602)");
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year) VALUES (6601,'0000006601','علی','رضایی','هفتم ۱','هفتم','active','1404/1405')");
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year) VALUES (6602,'0000006602','مریم','کریمی','هشتم ۲','هشتم','active','1404/1405')");
DB::execute("DELETE FROM bale_bot_users WHERE bale_chat_id IN ('777001','777002','777003')");
DB::execute("INSERT INTO bale_bot_users (bale_chat_id,student_id) VALUES ('777001',6601)");
DB::execute("INSERT INTO bale_bot_users (bale_chat_id,student_id) VALUES ('777001',6602)");
bot_outbox_schema();
DB::execute("DELETE FROM bot_outbox WHERE job_id LIKE 'blk%'");
$mk = function ($id, $chat, $state, $err, $attempts) {
    DB::execute("INSERT INTO bot_outbox (job_id,platform,payload,payload_hash,owner,state,attempts,created_at,last_error) VALUES (?,'bale',?,?,'local',?,?,?,?)",
        [$id, json_encode(['chat_id' => $chat, 'text' => 'اعلان حضور و غیاب'], JSON_UNESCAPED_UNICODE), hash('sha256', 'fixture' . $id), $state, $attempts, time(), $err]);
};
$mk('blk00000000000000000000000000a1', '777001', 'pending', 'پاسخ ناموفق API: HTTP 403 - {"ok":false,"error_code":403,"description":"Forbidden: permission_denied"}', 3);
$mk('blk00000000000000000000000000a2', '777001', 'pending', 'پاسخ ناموفق API: HTTP 403 - Forbidden', 1);
$mk('blk00000000000000000000000000a3', '777002', 'pending', 'پاسخ ناموفق API: HTTP 403 - Forbidden', 2);
$mk('blk00000000000000000000000000a4', '777003', 'sent', '', 9);
$mk('blk00000000000000000000000000a5', '777003', 'pending', 'خطای ارتباط با API ربات: timeout', 1);
echo 'REPORT=' . json_encode(bot_outbox_blocked_chats('bale'), JSON_UNESCAPED_UNICODE);`);
check(setup.out.includes('REPORT='), 'the fixture ran: ' + setup.out.slice(0, 200) + setup.err.slice(0, 200));

const report = JSON.parse(setup.out.slice(setup.out.indexOf('REPORT=') + 7).trim());
check(Array.isArray(report) && report.length === 2, 'only the two unsent 403 chats are reported: ' + JSON.stringify(report));
check(report[0].chat_id === '777001', 'the chat with more attempts is listed first');
check(report[0].jobs === 2 && report[0].attempts === 3, 'pending 403 jobs are counted with their max attempts');
check(report[0].students.length === 2
      && report[0].students.some(s => s.includes('علی رضایی') && s.includes('هفتم ۱'))
      && report[0].students.some(s => s.includes('مریم کریمی') && s.includes('هشتم ۲')),
      'both linked students of the blocking parent are named with their classes: ' + JSON.stringify(report[0].students));
check(report[1].chat_id === '777002' && report[1].students.length === 0, 'a blocked chat without a link row stays visible as anonymous');
check(!report.some(c => c.chat_id === '777003'), 'sent jobs and non-403 failures are excluded from the report');

const empty = await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
require_once '/www/includes/bot_outbox.php';
var_dump(bot_outbox_blocked_chats('sms') === []);`);
check(empty.out.includes('bool(true)'), 'an unknown platform yields an empty report');

/* ── v4.165.0 cycle: 3rd 403 parks the job, later messages are born parked,
      any user message wakes them, and relay jobs are never touched ───────── */
const cycle = await run(`<?php
/* mock the provider before bot_helpers' function_exists guard, exactly like
   the other bot suites: fail403 mode throws the real Bale error shape. */
function bot_api_request($platform,$method,$data=[],$multipart=false){
    $mode=trim((string)@file_get_contents('/harness/botmode.txt'));
    if($mode==='ok')return ['ok'=>true,'result'=>['message_id'=>9001]];
    throw new RuntimeException('پاسخ ناموفق API: HTTP 403 - {"ok":false,"error_code":403,"description":"Forbidden: permission_denied"}');
}
require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
require_once '/www/includes/bot_outbox.php';
@mkdir('/harness'); file_put_contents('/harness/botmode.txt','fail403');
DB::execute("DELETE FROM bot_outbox WHERE job_id LIKE 'cyc%'");
DB::execute("DELETE FROM bot_outbox_limits WHERE platform='bale'");
DB::execute("INSERT INTO bot_outbox (job_id,platform,payload,payload_hash,owner,state,attempts,next_try,created_at,last_error) VALUES ('cyc00000000000000000000000000b1','bale',?,?,'local','pending',2,0,?,?)",
  [json_encode(['chat_id'=>'888001','text'=>'اعلان ۱'],JSON_UNESCAPED_UNICODE), 'hashb1', time(), 'پاسخ ناموفق API: HTTP 403 - Forbidden']);
bot_outbox_drain(10,5);
$s1=DB::fetch("SELECT state,attempts FROM bot_outbox WHERE job_id='cyc00000000000000000000000000b1'");
echo 'AFTER3='.$s1['state'].'/'.$s1['attempts'].';';
bot_outbox_drain(10,5);
$s2=DB::fetch("SELECT attempts FROM bot_outbox WHERE job_id='cyc00000000000000000000000000b1'");
echo 'STABLE='.$s2['attempts'].';';
bot_outbox_enqueue('bale',['chat_id'=>'888001','text'=>'اعلان ۲']);
$nb=DB::fetch("SELECT job_id,state FROM bot_outbox WHERE platform='bale' AND payload LIKE '%اعلان ۲%' ORDER BY created_at DESC LIMIT 1");
echo 'BORN='.$nb['state'].';';
DB::execute("INSERT INTO bot_outbox (job_id,platform,payload,payload_hash,owner,state,attempts,next_try,created_at,last_error) VALUES ('cyc00000000000000000000000000b9','bale',?,?,'relay','blocked',1,0,?,'')",
  [json_encode(['chat_id'=>'888001','text'=>'رله'],JSON_UNESCAPED_UNICODE),'hashb9',time()]);
$n=bot_outbox_unblock_chat('bale','888001');
echo 'WOKE='.$n.';';
$rl=DB::fetch("SELECT state FROM bot_outbox WHERE job_id='cyc00000000000000000000000000b9'");
echo 'RELAY='.$rl['state'].';';
file_put_contents('/harness/botmode.txt','ok');
$sent=bot_outbox_drain(10,5);
$s3=DB::fetch("SELECT state FROM bot_outbox WHERE job_id='cyc00000000000000000000000000b1'");
$s4=DB::fetch("SELECT state FROM bot_outbox WHERE job_id='".$nb['job_id']."'");
echo 'DELIV='.$s3['state'].'/'.$s4['state'].'/'.$sent;`);
const c = Object.fromEntries((cycle.out.match(/([A-Z0-9]+)=([^;]*)/g) || []).map(kv => { const i = kv.indexOf('='); return [kv.slice(0, i), kv.slice(i + 1)]; }));
check(c.AFTER3 === 'blocked/3', 'the third consecutive 403 parks the job instead of re-queueing it: ' + cycle.out.slice(0, 200) + cycle.err.slice(0, 200));
check(c.STABLE === '3', 'a parked job is never claimed again (no wasted attempts)');
check(c.BORN === 'blocked', 'a NEW message to an already-blocked chat is born parked (zero attempts)');
check(c.WOKE === '2', 'unblocking wakes exactly the local parked jobs of that chat');
check(c.RELAY === 'blocked', 'relay (desktop) jobs are never touched by the unblock flush');
check(c.DELIV === 'sent/sent/2', 'woken jobs deliver normally once the provider accepts again: ' + c.DELIV);

const engine = readFileSync(new URL('../update-v4.152.0/includes/bot_webhook_engine.php', import.meta.url), 'utf8');
check(engine.includes("try { bot_outbox_unblock_chat($platform, $chatId); }"), 'the webhook flush is wrapped in its own try/catch before the /start flow');

/* ── the shipped admin page must render the section ──────────────────────── */
/* v4.172.0: بخش صف از bot_admin_ui.php به فایل مستقل includes/bot_queue_ui.php رفت */
const ui = readFileSync(new URL('../update-v4.152.0/includes/bot_queue_ui.php', import.meta.url), 'utf8');
check(ui.includes('$blockedChats = bot_outbox_blocked_chats($platform);'), 'the shared queue section calls the report helper');
check(ui.includes("function bot_admin_render_queue_section"), 'the queue section lives in its own include');
check(!readFileSync(new URL('../update-v4.152.0/includes/bot_admin_ui.php', import.meta.url), 'utf8').includes('bot_admin_render_queue_section'), 'the bot admin page no longer renders the queue itself');
check(ui.includes('ولی‌هایی که ربات را مسدود کرده‌اند'), 'the report has a parent-facing Persian heading');
check(ui.includes("implode('، ', $bc['students'])") && ui.includes('چت ناشناس'), 'named students and anonymous chats are both rendered');

/* ── v4.168.0: compact queue list — repeated errors collapse into one
      grouped line, blocked jobs stay out of it, raw API JSON is gone ────── */
const fx = await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
require_once '/www/includes/bot_outbox.php';
bot_outbox_schema();
DB::execute("DELETE FROM bot_outbox WHERE job_id LIKE 'blk%'");
$mk = function ($id, $chat, $state, $err, $attempts) {
    DB::execute("INSERT INTO bot_outbox (job_id,platform,payload,payload_hash,owner,state,attempts,created_at,last_error) VALUES (?,'bale',?,?,'local',?,?,?,?)",
        [$id, json_encode(['chat_id' => $chat, 'text' => 'اعلان حضور و غیاب'], JSON_UNESCAPED_UNICODE), hash('sha256', 'fx' . $id), $state, $attempts, time(), $err]);
};
$e403 = 'پاسخ ناموفق API: HTTP 403 - {"ok":false,"error_code":403,"description":"Forbidden: permission_denied"}';
$mk('blk00000000000000000000000000b1','777001','pending',$e403,3);
$mk('blk00000000000000000000000000b2','777001','pending',$e403,5);
$mk('blk00000000000000000000000000b3','777002','pending',$e403,8);
$mk('blk00000000000000000000000000b4','777003','pending','خطای ارتباط با API ربات: timeout',1);
$mk('blk00000000000000000000000000b5','777001','blocked',$e403,10);
$mk('blk00000000000000000000000000b6','777003','sent','',4);
echo 'FX=OK';`);
check(fx.out.includes('FX=OK'), 'the v4.168 fixture ran: ' + fx.out.slice(0, 200) + fx.err.slice(0, 200));
/* v4.172.0: صف از صفحهٔ ربات به «تنظیمات دیگر ← صف اعلان‌های ربات» رفت */
const qpage = await req('صف اعلان‌ها', { file: 'bot-queue.php', sid, query: 'platform=bale' });
const qhtml = qpage.res.page || '';
check(!qpage.res.fatal, 'the bot admin page renders without fatals: ' + (qpage.res.fatal || ''));
check(qhtml.includes('صف ماندگار اعلان‌ها'), 'the queue card is rendered on its own settings page');
check(qhtml.includes('صف اعلان‌های ربات'), 'the page has its own queue heading');
check(qhtml.includes('bot-queue.php?platform=telegram'), 'the other platform is reachable from the queue page');
const botPage = await req('صفحهٔ ربات', { file: 'bale-bot.php', sid });
check(!botPage.res.fatal, 'the bot page still renders without fatals: ' + (botPage.res.fatal || ''));
check(!(botPage.res.page || '').includes('صف ماندگار اعلان‌ها'), 'the queue card is no longer on the bot page');
check((botPage.res.page || '').includes('تنظیمات توکن و Webhook'), 'the bot page keeps its own token/webhook card');
const hub = readFileSync(new URL('../update-v4.152.0/includes/management_hub.php', import.meta.url), 'utf8');
check(hub.includes("'queue'=>['صف اعلان‌های ربات','bot-queue.php?embedded=1']"), 'the queue page is a tab of «تنظیمات دیگر»');
check(hub.includes("'queue'=>'send_sms'"), 'the new tab honours the send_sms permission');
const queuePage = readFileSync(new URL('../update-v4.152.0/bot-queue.php', import.meta.url), 'utf8');
check(queuePage.includes('bot_admin_render_queue_section($queuePlatform)'), 'the queue page renders the shared section');
check(queuePage.includes("bot_outbox_sql(\"UPDATE bot_outbox SET next_try=0 WHERE platform=? AND state IN ('pending','relayed')\"") && queuePage.includes('verify_csrf'), 'the retry button is CSRF-protected and only resets due times');
check((qhtml.match(/کاربر ربات را مسدود کرده است/g) || []).length === 1, 'the three identical 403 rows collapse into ONE grouped line');
check(qhtml.includes('۳ پیام'), 'the grouped line states how many messages it covers');
check(!qhtml.includes('"error_code":403'), 'raw API JSON is never dumped into the page');
check(!(qhtml.match(/مسدود \(بدون تلاش\)<\/b> —/g) || []).length, 'blocked jobs no longer appear as repeated rows in the compact list');
check(qhtml.includes('ولی‌هایی که ربات را مسدود کرده‌اند'), 'the blocked-parent section still renders with names');

/* ── v4.171.0: سقف بهداشت صف مسدود — بیدارباش محدود + سقف انباشت ───────── */
const hygiene = await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
require_once '/www/includes/bot_outbox.php';
bot_outbox_schema();
DB::execute("DELETE FROM bot_outbox WHERE job_id LIKE 'hyg%' OR job_id LIKE 'cap%' OR job_id LIKE 'rlh%'");
$mk = function ($pfx, $n, $chat, $owner) {
    DB::execute("INSERT INTO bot_outbox (job_id,platform,payload,payload_hash,owner,state,attempts,created_at,last_error) VALUES (?,'bale',?,?,?,'blocked',3,?,'HTTP 403')",
        [$pfx . str_pad((string)$n, 29, '0', STR_PAD_LEFT), json_encode(['chat_id' => $chat, 'text' => 'اعلان ' . $n], JSON_UNESCAPED_UNICODE), hash('sha256', $pfx . $n), $owner, 1700000000 + $n]);
};
for ($i = 1; $i <= 12; $i++) $mk('hyg', $i, '777010', 'local');
$woke = bot_outbox_unblock_chat('bale', '777010');
$still = DB::fetch("SELECT COUNT(*) AS c FROM bot_outbox WHERE job_id LIKE 'hyg%' AND state='blocked'")['c'];
$pend  = DB::fetch("SELECT COUNT(*) AS c FROM bot_outbox WHERE job_id LIKE 'hyg%' AND state='pending'")['c'];
$oldest = DB::fetch("SELECT state FROM bot_outbox WHERE job_id='hyg" . str_pad('1', 29, '0', STR_PAD_LEFT) . "'")['state'];
$newest = DB::fetch("SELECT state FROM bot_outbox WHERE job_id='hyg" . str_pad('12', 29, '0', STR_PAD_LEFT) . "'")['state'];
echo "WOKE=$woke;STILL=$still;PEND=$pend;OLDEST=$oldest;NEWEST=$newest;";
for ($i = 1; $i <= 60; $i++) $mk('cap', $i, '777011', 'local');
$mk('rlh', 1, '777011', 'relay');
$del = bot_outbox_prune_blocked_chat('bale', '777011', 50);
$left = DB::fetch("SELECT COUNT(*) AS c FROM bot_outbox WHERE job_id LIKE 'cap%'")['c'];
$keptNewest = DB::fetch("SELECT state FROM bot_outbox WHERE job_id='cap" . str_pad('60', 29, '0', STR_PAD_LEFT) . "'")['state'];
$droppedOldest = DB::fetch("SELECT COUNT(*) AS c FROM bot_outbox WHERE job_id='cap" . str_pad('1', 29, '0', STR_PAD_LEFT) . "'")['c'];
bot_outbox_unblock_chat('bale', '777011');
$relayStill = DB::fetch("SELECT state FROM bot_outbox WHERE job_id LIKE 'rlh%'")['state'];
echo "DEL=$del;LEFT=$left;KEPT=$keptNewest;OLDGONE=$droppedOldest;RELAY=$relayStill;";
file_put_contents('/harness/hygiene.txt', 'ok');`);
const h = Object.fromEntries((hygiene.out.match(/([A-Z0-9]+)=([^;]*)/g) || []).map(kv => { const i = kv.indexOf('='); return [kv.slice(0, i), kv.slice(i + 1)]; }));
check(hygiene.out.includes('WOKE='), 'the v4.171 hygiene fixture ran: ' + hygiene.out.slice(0, 200) + hygiene.err.slice(0, 200));
check(h.WOKE === '10', 'unblocking wakes only the last N parked messages, not the whole backlog: ' + h.WOKE);
check(h.STILL === '2', 'the older parked messages stay parked instead of flooding the parent: ' + h.STILL);
check(h.PEND === '10', 'exactly the woken messages re-enter the retry cycle: ' + h.PEND);
check(h.OLDEST === 'blocked' && h.NEWEST === 'pending', 'the NEWEST messages are woken and the oldest wait their turn');
check(h.DEL === '10', 'pruning drops only the surplus beyond the cap: ' + h.DEL);
check(h.LEFT === '50' && h.KEPT === 'blocked', 'the cap keeps the newest messages and parks them: ' + h.LEFT + '/' + h.KEPT);
check(h.OLDGONE === '0', 'the oldest parked messages are the ones removed');
check(h.RELAY === 'blocked', 'a relay (desktop) parked job is never woken or pruned');

const ob = readFileSync(new URL('../update-v4.152.0/includes/bot_outbox.php', import.meta.url), 'utf8');
check(ob.includes("define('BOT_OUTBOX_WAKE_LIMIT',10)"), 'the wake limit is a named constant, not a magic number');
check(ob.includes("define('BOT_OUTBOX_BLOCKED_CAP',50)"), 'the blocked-queue cap is a named constant');
check(ob.includes('function bot_outbox_prune_blocked_chat'), 'the prune helper exists');
check(ob.includes("bot_outbox_prune_blocked_chat($platform,(string)($payload['chat_id']??''));"), 'a new parked message keeps its chat within the cap immediately');
check(engine.includes("try { bot_outbox_unblock_chat($platform, $chatId); }"), 'the webhook flush is still wrapped in its own try/catch before the /start flow');

console.log(`PASS ${checks} bot blocked-parent report cases (403 mapping, students, exclusions, admin UI)`);
process.exit(0);
