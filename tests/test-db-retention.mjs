// db-retention: قواعد نگهداشت جدول‌های پرشونده — v4.176.0
import { run, req, loginAdmin } from './harness/lib.mjs';
let n = 0;
const check = (v, m) => { if (!v) throw new Error('FAIL: ' + m); n++; };

/* دادهٔ آزمایشی: ردیف‌های کهنه در چهار جدول هدف */
const seed = await run(String.raw`<?php require_once '/www/includes/functions.php';
$old = time() - 400*86400; $recent = time() - 3600;
/* ۱) لاگ پیام ربات */
DB::execute("CREATE TABLE IF NOT EXISTS bot_message_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, platform TEXT, chat_id TEXT, student_id INTEGER, message_type TEXT, message TEXT, status TEXT, response TEXT, created_at TEXT)");
DB::execute("DELETE FROM bot_message_logs");
DB::execute("INSERT INTO bot_message_logs (platform,chat_id,student_id,message_type,message,status,response,created_at) VALUES ('bale','1',1,'text','سلام','sent','ok',datetime($old,'unixepoch','localtime'))");
DB::execute("INSERT INTO bot_message_logs (platform,chat_id,student_id,message_type,message,status,response,created_at) VALUES ('bale','1',1,'text','سلام','sent','ok',datetime($recent,'unixepoch','localtime'))");
/* ۲) تغییرهای دسکتاپ */
DB::execute("CREATE TABLE IF NOT EXISTS desk_change_log (id INTEGER PRIMARY KEY AUTOINCREMENT, tbl TEXT, rid TEXT, op TEXT, ts INTEGER)");
DB::execute("DELETE FROM desk_change_log");
DB::execute("INSERT INTO desk_change_log (tbl,rid,op,ts) VALUES ('students','1','i',$old)");
DB::execute("INSERT INTO desk_change_log (tbl,rid,op,ts) VALUES ('students','2','i',$recent)");
/* ۳) حضور و غیاب — دو سال قدیمی و یک سال جاری */
DB::execute("CREATE TABLE IF NOT EXISTS student_attendance (id INTEGER PRIMARY KEY AUTOINCREMENT, student_id INTEGER, academic_year TEXT, date_jalali TEXT, status TEXT, minutes_late INTEGER DEFAULT 0, note TEXT, notified_chats INTEGER DEFAULT 0, review_status TEXT DEFAULT 'pending', created_by_admin_id INTEGER, created_by_teacher_id INTEGER, created_at_jalali TEXT, updated_at_jalali TEXT)");
DB::execute("DELETE FROM student_attendance");
foreach (['1400/1401','1401/1402','1402/1403','1403/1404'] as $y) DB::execute("INSERT INTO student_attendance (student_id,academic_year,date_jalali,status,created_at_jalali) VALUES (1,?,'".$y."-01-01','absent','".$y."-01-01')",[$y]);
/* ۴) نسخه‌های طراحی آزمون */
DB::execute("CREATE TABLE IF NOT EXISTS exam_design_archive (id INTEGER PRIMARY KEY AUTOINCREMENT, exam_id INTEGER, design_json TEXT, designer_name TEXT, subject_name TEXT, exam_month TEXT, academic_year TEXT, grade_level TEXT, class_name TEXT, src_fingerprint TEXT, created_at_jalali TEXT)");
DB::execute("DELETE FROM exam_design_archive");
for ($i=1;$i<=8;$i++) DB::execute("INSERT INTO exam_design_archive (exam_id,design_json,created_at_jalali) VALUES (?,?,'1403/1404')",[77, str_repeat('x',$i)]);
DB::execute("INSERT INTO exam_design_archive (exam_id,design_json,created_at_jalali) VALUES (88,'y','1403/1404')",[]);
/* ۵) صف ربات: blocked کهنه و pending تازه */
DB::execute("CREATE TABLE IF NOT EXISTS bot_outbox (job_id TEXT PRIMARY KEY, platform TEXT, payload TEXT, payload_hash TEXT, owner TEXT, state TEXT, attempts INTEGER DEFAULT 0, next_try INTEGER DEFAULT 0, lease_until INTEGER DEFAULT 0, claim_token TEXT DEFAULT '', created_at INTEGER, sent_at INTEGER DEFAULT 0, message_id TEXT DEFAULT '', last_error TEXT DEFAULT '')");
DB::execute("DELETE FROM bot_outbox");
DB::execute("INSERT INTO bot_outbox (job_id,platform,payload,payload_hash,owner,state,created_at,last_error) VALUES ('a1','bale','{}','h','local','blocked',$old,'x')");
DB::execute("INSERT INTO bot_outbox (job_id,platform,payload,payload_hash,owner,state,created_at,last_error) VALUES ('a2','bale','{}','h','local','pending',$recent,'')");
DB::execute("INSERT INTO bot_outbox (job_id,platform,payload,payload_hash,owner,state,created_at,last_error) VALUES ('a3','bale','{}','h','relay','blocked',$old,'x')");
echo 'SEEDED';`);
check(seed.out.includes('SEEDED'), 'fixtures seeded');

/* ── ۱) هر قاعده کوئری سالم می‌سازد ───────────────────────────────────── */
const rules = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/db_retention.php';
foreach (dbm_retention_rules() as $k => $r) {
  $sql = dbm_rule_count_sql($k);
  echo $k . '=' . ($sql === null ? 'null' : 'ok') . ';';
}
echo 'days=' . dbm_rule_days('bot_message_logs') . ',' . dbm_rule_days('desk_change_log') . ',' . dbm_rule_days('student_attendance') . ',' . dbm_rule_days('exam_design_archive') . ',' . dbm_rule_days('bot_outbox_blocked') . ';';`);
check(/bot_message_logs=ok/.test(rules.out) && /desk_change_log=ok/.test(rules.out) && /student_attendance=ok/.test(rules.out) && /exam_design_archive=ok/.test(rules.out) && /bot_outbox_blocked=ok/.test(rules.out),
  'every rule builds a count query: ' + rules.out.slice(0, 200));
check(/days=90,60,0,5,90/.test(rules.out), 'defaults: logs 90d, desk log 60d, attendance off, exam versions 5, blocked 90d: ' + rules.out.match(/days=[^;]*/)[0]);

/* ── ۲) شمارش قبل از پاکسازی ─────────────────────────────────────────── */
const before = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/db_retention.php';
echo json_encode(dbm_rule_counts(), JSON_UNESCAPED_UNICODE);`);
const bc = JSON.parse(before.out.match(/\{.*\}/s)[0]);
check(bc.bot_message_logs === 1, 'one old bot log counted: ' + JSON.stringify(bc));
check(bc.desk_change_log === 1, 'one old desk change counted');
check(bc.exam_design_archive === 3, '3 surplus exam design versions counted (8 kept 5 → 3, exam 88 has 1): ' + bc.exam_design_archive);
check(bc.bot_outbox_blocked === 1, 'only the LOCAL blocked row is counted (relay/desktop jobs are never pruned): ' + bc.bot_outbox_blocked);

/* ── ۳) اجرای پاکسازی ────────────────────────────────────────────────── */
const swept = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/db_retention.php';
echo 'logs=' . dbm_sweep_rule('bot_message_logs', microtime(true)+5) . ';';
echo 'desk=' . dbm_sweep_rule('desk_change_log', microtime(true)+5) . ';';
echo 'versions=' . dbm_sweep_rule('exam_design_archive', microtime(true)+5) . ';';
echo 'blocked=' . dbm_sweep_rule('bot_outbox_blocked', microtime(true)+5) . ';';`);
check(swept.out.includes('logs=1'), 'old bot log removed: ' + swept.out);
check(swept.out.includes('desk=1'), 'old desk change removed');
check(swept.out.includes('versions=3'), '3 surplus versions removed: ' + swept.out);
check(swept.out.includes('blocked=1'), 'the local blocked row is removed: ' + swept.out);

/* ── ۴) آنچه باید بماند، مانده است ───────────────────────────────────── */
const after = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/db_retention.php';
echo 'logs=' . DB::fetch("SELECT COUNT(*) c FROM bot_message_logs")['c'] . ';';
echo 'desk=' . DB::fetch("SELECT COUNT(*) c FROM desk_change_log")['c'] . ';';
echo 'arch=' . DB::fetch("SELECT COUNT(*) c FROM exam_design_archive")['c'] . ';';
echo 'arch77=' . DB::fetch("SELECT COUNT(*) c FROM exam_design_archive WHERE exam_id=77")['c'] . ';';
echo 'arch88=' . DB::fetch("SELECT COUNT(*) c FROM exam_design_archive WHERE exam_id=88")['c'] . ';';
echo 'pending=' . DB::fetch("SELECT COUNT(*) c FROM bot_outbox WHERE state='pending'")['c'] . ';';
echo 'arch_newest_kept=' . (DB::fetch("SELECT id FROM exam_design_archive WHERE exam_id=77 ORDER BY id DESC")['id'] ?? 0) . ';';`);
check(after.out.includes('logs=1'), 'the fresh bot log stays');
check(after.out.includes('desk=1'), 'the fresh desk change stays');
check(after.out.includes('arch=6') && after.out.includes('arch77=5') && after.out.includes('arch88=1'),
  'only the newest 5 versions per exam survive: ' + after.out.slice(0, 120));
check(after.out.includes('pending=1'), 'a pending queue row is never touched');
const relay = await run(String.raw`<?php require_once '/www/includes/functions.php';
echo 'relay=' . DB::fetch("SELECT COUNT(*) c FROM bot_outbox WHERE owner='relay'")['c'] . ';';`);
check(relay.out.includes('relay=1'), 'a relay (desktop) blocked row survives the sweep');
const newest = after.out.match(/arch_newest_kept=(\d+)/)[1];
check(newest === '8', 'the newest version (id 8) is the one kept: ' + newest);

/* ── ۵) حضور و غیاب: پیش‌فرض خاموش، با مقدار ۲ سال فقط دو سال آخر می‌ماند ── */
const attOff = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/db_retention.php';
echo 'off_count=' . dbm_rule_counts()['student_attendance'] . ';';`);
check(attOff.out.includes('off_count=-1') || attOff.out.includes('off_count=0'),
  'attendance retention is OFF by default (nothing counted): ' + attOff.out.match(/off_count=-?\d+/)[0]);

const attOn = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/db_retention.php';
set_setting('current_academic_year','1403/1404');
set_setting('dbopt_attendance_years','2');
echo 'kept=' . implode(',', dbm_kept_years(2)) . ';';
echo 'count=' . dbm_rule_counts()['student_attendance'] . ';';
echo 'removed=' . dbm_sweep_rule('student_attendance', microtime(true)+5) . ';';
echo 'left=' . DB::fetch("SELECT GROUP_CONCAT(academic_year) g FROM student_attendance ORDER BY academic_year")['g'] . ';';`);
check(attOn.out.includes('kept=1403/1404,1402/1403'), 'the two newest years are the hot set: ' + attOn.out.match(/kept=[^;]*/)[0]);
check(attOn.out.includes('count=2'), 'two old years counted for removal');
check(attOn.out.includes('removed=2') && attOn.out.includes('left=1402/1403,1403/1404'),
  'after the sweep only the kept years remain: ' + attOn.out.match(/left=[^;]*/)[0]);

/* ── ۶) ایندکس‌های لازم ساخته می‌شوند و بار دوم تکراری نمی‌شوند ───────── */
const idx = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/db_retention.php';
$a = dbm_ensure_retention_indexes();
$b = dbm_ensure_retention_indexes();
$names = array_column(DB::fetchAll("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name IN ('bot_message_logs','desk_change_log','student_attendance')"),'name');
echo 'first=' . $a . ';second=' . $b . ';idx=' . implode(',', $names) . ';';`);
check(/\bfirst=[1-9]/.test(idx.out), 'missing retention indexes were created: ' + idx.out.slice(0, 160));
check(idx.out.includes('second=0'), 'the second run creates nothing (idempotent)');
check(/idx=.*dbopt_logs_created/.test(idx.out) && /dbopt_desklog_ts/.test(idx.out) && /dbopt_attendance_year/.test(idx.out),
  'indexes exist on the sweep columns: ' + idx.out.match(/idx=[^;]*/)[0]);

/* ── ۷) sweep_all روزانه است و زمان‌دار ──────────────────────────────── */
const all = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/db_retention.php';
set_setting('dbopt_retention_last_bot_message_logs','0');
set_setting('dbopt_retention_last_desk_change_log','0');
set_setting('dbopt_retention_last_exam_design_archive','0');
set_setting('dbopt_retention_last_bot_outbox_blocked','0');
set_setting('dbopt_retention_last_student_attendance','0');
$r = dbm_sweep_all(2.0);
echo json_encode($r, JSON_UNESCAPED_UNICODE) . ';';
echo 'marked=' . (get_setting('dbopt_retention_last_bot_message_logs','0') > time()-60 ? 'yes' : 'no') . ';';`);
check(/bot_message_logs":0/.test(all.out) && /desk_change_log":0/.test(all.out), 'the daily sweep reports what it did: ' + all.out.slice(0, 200));
check(all.out.includes('marked=yes'), 'each rule records its last run so it runs once a day');

/* ── ۸) صفحهٔ بهینه‌ساز، بخش نگهداشت را نشان می‌دهد ──────────────────── */
await loginAdmin('dbopt01');
const page = await req('صفحهٔ سلامت پایگاه داده', { file: 'db-optimizer.php', sid: 'dbopt01', query: 'scan=1' });
check(/قواعد نگهداشت|نگهداشت خودکار/.test(page.res.page), 'the optimizer page has the retention section');
check(/bot_message_logs/.test(page.res.page) && /exam_design_archive/.test(page.res.page), 'the retention table lists the heavy tables');

/* ── ۹) کرون ورکر، پاکسازی را صدا می‌زند ─────────────────────────────── */
const worker = await run(String.raw`<?php
$src = file_get_contents('/www/cron/bot-outbox-worker.php');
echo 'has_sweep=' . (strpos($src, 'dbm_sweep_all') !== false ? 'yes' : 'no') . ';';
echo 'has_include=' . (strpos($src, 'db_retention.php') !== false ? 'yes' : 'no') . ';';`);
check(worker.out.includes('has_sweep=yes') && worker.out.includes('has_include=yes'),
  'the once-a-minute worker also runs the retention sweep: ' + worker.out.slice(0, 80));

console.log('PASS ' + n + ' db-retention checks (safe rules, chunked deletes, hot data untouched, once-a-day, indexed)');
process.exit(0);
