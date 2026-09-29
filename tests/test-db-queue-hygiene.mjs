// v4.172.0 — بهداشت صف و بهینه‌سازی پایگاه داده
//  ۱) نگهداشت خودکار صف ربات در worker (فقط رسیدهای ارسال‌شده و مسدودهای کهنه)
//  ۲) قواعد نگهداشت در صفحهٔ «سلامت پایگاه داده»
//  ۳) ایندکس‌های پیشنهادی برای سه جدولی که کاربر آزادسازی فضا را خواسته
//  ۴) توضیحِ «چرا فضا خودکار آزاد نمی‌شود» در همان صفحه
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { run, db, loginAdmin, req } from './harness/lib.mjs';

let n = 0;
const check = (v, m) => { assert(v, m); n++; };

/* ── fixture: صف ربات با رکوردهای کهنه و تازه ─────────────────────────── */
const fixture = await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
require_once '/www/includes/bot_outbox.php';
bot_outbox_schema();
DB::execute("DELETE FROM bot_outbox WHERE job_id LIKE 'ret%'");
$mk = function ($id, $state, $owner, $ageDays, $col) {
    DB::execute("INSERT INTO bot_outbox (job_id,platform,payload,payload_hash,owner,state,attempts,next_try,created_at,sent_at,last_error) VALUES (?,'bale',?,?,'local',?,0,0,?,?,'')",
        [$id, json_encode(['chat_id' => '778001', 'text' => 'x'], JSON_UNESCAPED_UNICODE), hash('sha256', $id), $state, time() - $ageDays * 86400, $col === 'sent_at' ? time() - $ageDays * 86400 : 0]);
};
$mk('ret0000000000000000000000000001', 'sent', 'local', 40, 'sent_at');     // کهنه → حذف
$mk('ret0000000000000000000000000002', 'sent', 'local', 2, 'sent_at');      // تازه → بماند
$mk('ret0000000000000000000000000003', 'blocked', 'local', 120, 'created_at'); // کهنه → حذف
$mk('ret0000000000000000000000000004', 'blocked', 'local', 5, 'created_at');   // تازه → بماند
$mk('ret0000000000000000000000000005', 'pending', 'local', 400, 'created_at'); // صف زنده → هرگز
DB::execute("INSERT INTO bot_outbox (job_id,platform,payload,payload_hash,owner,state,attempts,next_try,created_at,sent_at,last_error) VALUES ('ret0000000000000000000000000006','bale',?,?,'relay','sent',0,0,?,?,'')",
    [json_encode(['chat_id' => '778001', 'text' => 'r'], JSON_UNESCAPED_UNICODE), hash('sha256', 'relay'), time() - 400 * 86400, time() - 400 * 86400]);
set_setting('dbopt_outbox_retention_last', '0');
echo 'FIXTURE=OK';`);
check(fixture.out.includes('FIXTURE=OK'), 'the retention fixture ran: ' + fixture.out.slice(0, 200) + fixture.err.slice(0, 200));

/* ── ۱) worker: نگهداشت خودکار ─────────────────────────────────────────── */
const sweep = await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
$src = file_get_contents('/www/cron/bot-outbox-worker.php');
// تابع نگهداشت از داخل فایل worker استخراج و اجرا می‌شود (همان کدِ تولید).
preg_match('/function bot_outbox_retention_sweep\\(\\) \\{.*?\\n\\}/s', $src, $m);
echo 'fn=' . ($m ? 'yes' : 'no') . ';';
eval($m[0]);
$removed = bot_outbox_retention_sweep();
echo 'removed=' . $removed . ';';
echo 'sent_old=' . DB::fetch("SELECT COUNT(*) c FROM bot_outbox WHERE job_id='ret0000000000000000000000000001'")['c'] . ';';
echo 'sent_new=' . DB::fetch("SELECT COUNT(*) c FROM bot_outbox WHERE job_id='ret0000000000000000000000000002'")['c'] . ';';
echo 'blocked_old=' . DB::fetch("SELECT COUNT(*) c FROM bot_outbox WHERE job_id='ret0000000000000000000000000003'")['c'] . ';';
echo 'blocked_new=' . DB::fetch("SELECT COUNT(*) c FROM bot_outbox WHERE job_id='ret0000000000000000000000000004'")['c'] . ';';
echo 'pending_live=' . DB::fetch("SELECT COUNT(*) c FROM bot_outbox WHERE job_id='ret0000000000000000000000000005'")['c'] . ';';
echo 'relay_old=' . DB::fetch("SELECT COUNT(*) c FROM bot_outbox WHERE job_id='ret0000000000000000000000000006'")['c'] . ';';
$again = bot_outbox_retention_sweep();
echo 'second=' . $again . ';';`);
const s = Object.fromEntries((sweep.out.match(/([a-z_0-9]+)=([^;]*)/g) || []).map(kv => { const i = kv.indexOf('='); return [kv.slice(0, i), kv.slice(i + 1)]; }));
check(sweep.out.includes('removed='), 'the worker retention sweep ran: ' + sweep.out.slice(0, 200) + sweep.err.slice(0, 300));
check(s.fn === 'yes', 'the worker ships the retention helper');
check(s.removed === '2', 'exactly the two old rows are removed: ' + s.removed);
check(s.sent_old === '0', 'a 40-day-old delivery receipt is pruned');
check(s.sent_new === '1', 'a 2-day-old delivery receipt is kept');
check(s.blocked_old === '0', 'a 120-day-old parked message is pruned');
check(s.blocked_new === '1', 'a 5-day-old parked message is kept');
check(s.pending_live === '1', 'a live queued message is NEVER pruned, however old');
check(s.relay_old === '1', 'a relay (desktop) job is never pruned');
check(s.second === '0', 'the sweep runs at most once a day (no repeated work on an 8-minute cron)');

/* ── ۲) قواعد نگهداشت در صفحهٔ سلامت پایگاه داده ───────────────────────── */
const opt = readFileSync(new URL('../update-v4.152.0/db-optimizer.php', import.meta.url), 'utf8');
check(opt.includes('function dbopt_age_sql'), 'the optimizer has a portable "older than N days" builder');
check(opt.includes("dbopt_age_sql('DELETE FROM bot_outbox', 'sent_at', $sentDays"), 'sent receipts older than the retention window are cleaned');
check(opt.includes("dbopt_age_sql('DELETE FROM bot_outbox', 'created_at', $blockedDays"), 'parked messages older than the retention window are cleaned');
check(opt.includes("state='sent' AND owner='local'") && opt.includes("state='blocked' AND owner='local'"), 'retention only ever touches local, unsent-or-delivered rows');
check(!/DELETE FROM bot_outbox[^;]*(pending|relayed|sending)/.test(opt), 'no retention rule can delete a live/relay job');
check(opt.includes("dbopt_outbox_retention_days('sent')") && opt.includes("dbopt_outbox_retention_days('blocked')"), 'retention windows are settings, not magic numbers');

/* ── ۳) ایندکس‌های پیشنهادی برای سه جدول گزارش‌شده ────────────────────── */
for (const [table, idx] of [['bot_outbox', 'idx_platform_state'], ['desk_change_log', 'idx_ts'], ['student_attendance', 'idx_student_date']]) {
  check(opt.includes(`['${table}', '${idx}'`), `${table} gets the recommended ${idx} index`);
}
check(opt.includes("'`platform`,`state`,`sent_at`'"), 'the retention query itself is index-backed');

/* ── ۴) توضیحِ «چرا فضا خودکار آزاد نمی‌شود» ──────────────────────────── */
check(opt.includes('دکمهٔ OPTIMIZE آن را بازپس می‌گیرد'), 'the page explains that OPTIMIZE is what returns the space');
check(opt.includes('بازسازی فیزیکی را فقط در صورت نیاز'), 'the page keeps the backup/disk-space warning');

/* ── صفحه واقعاً رندر می‌شود و ردیف‌های نگهداشت را نشان می‌دهد ─────────── */
const sid = 'testDbQueueHygiene';
await loginAdmin(sid);
const page = await req('سلامت پایگاه داده', { file: 'db-optimizer.php', sid, query: 'scan=1' });
check(!page.res.fatal, 'the database-health page renders without fatals: ' + (page.res.fatal || ''));
check((page.res.page || '').includes('رسیدهای ارسال‌شدهٔ صف ربات'), 'the retention rows appear in the orphan/expired table');
check((page.res.page || '').includes('فضای آزاد قابل بازپس‌گیری'), 'the reclaimable-space column is shown');

console.log(`PASS ${n} queue-retention / database-health cases (auto sweep, index backing, live & relay safety, UI)`);
process.exit(0);
