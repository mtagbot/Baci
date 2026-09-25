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
import { php, run, db, loginAdmin } from './harness/lib.mjs';

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

/* ── the shipped admin page must render the section ──────────────────────── */
const ui = readFileSync(new URL('../update-v4.152.0/includes/bot_admin_ui.php', import.meta.url), 'utf8');
check(ui.includes('$blockedChats = bot_outbox_blocked_chats($platform);'), 'the bot admin page calls the report helper');
check(ui.includes('ولی‌هایی که ربات را مسدود کرده‌اند'), 'the report has a parent-facing Persian heading');
check(ui.includes("implode('، ', $bc['students'])") && ui.includes('چت ناشناس'), 'named students and anonymous chats are both rendered');

console.log(`PASS ${checks} bot blocked-parent report cases (403 mapping, students, exclusions, admin UI)`);
process.exit(0);
