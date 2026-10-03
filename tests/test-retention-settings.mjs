// v4.177.0 — ذخیرهٔ قواعد نگهداشت (فرم بیرون‌از‌فرم) + صندوق ورودی ربات
import assert from 'node:assert/strict';
import { run, req, loginAdmin } from './harness/lib.mjs';

let n = 0;
const check = (v, m) => { assert(v, m); n++; };

/* ═══════ ۱) قواعد نگهداشت: مقدار تازه ذخیره می‌شود ═══════ */
await run(String.raw`<?php require_once '/www/includes/functions.php';
set_setting('dbopt_logs_days','90'); set_setting('dbopt_exam_versions','5'); set_setting('dbopt_attendance_years','0');
echo 'seeded';`);

await loginAdmin('dboptSave01');
const optPage = await req('صفحهٔ بهینه‌ساز', { file: 'db-optimizer.php', sid: 'dboptSave01', query: 'scan=1' });
const csrf = (optPage.res.page.match(/name="csrf_token" value="([^"]+)"/) || [])[1] || '';
check(csrf !== '', 'a CSRF token is available on the optimizer page');
check(/name="retention_bot_message_logs" value="90"/.test(optPage.res.page), 'the logs rule shows the current value 90');

/* ورودی‌ها باید داخل فرم باشند — وگرنه مرورگر آن‌ها را نمی‌فرستد */
const formOpen = optPage.res.page.indexOf('id="retentionSettingsForm"');
const inputPos = optPage.res.page.indexOf('name="retention_bot_message_logs"');
const formClose = optPage.res.page.indexOf('</form>', formOpen);
check(formOpen !== -1 && inputPos > formOpen && inputPos < formClose,
  'the number inputs are INSIDE the settings form (this was the bug: ' + formOpen + ' < ' + inputPos + ' < ' + formClose + ')');

const saved = await req('ذخیرهٔ قواعد نگهداشت', {
  file: 'db-optimizer.php', sid: 'dboptSave01', method: 'POST',
  post: {
    csrf_token: csrf, do: 'retention_settings',
    retention_bot_message_logs: '30',
    retention_desk_change_log: '45',
    retention_exam_design_archive: '7',
    retention_student_attendance: '2',
    retention_bot_outbox_blocked: '120',
    retention_bot_inbox: '15',
  },
});
check(/قواعد نگهداشت ذخیره شد/.test(saved.res.flash?.message || ''), 'the settings form reports success: ' + (saved.res.flash?.message || ''));

const after = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/db_retention.php';
echo 'logs=' . dbm_rule_days('bot_message_logs') . ';';
echo 'desk=' . dbm_rule_days('desk_change_log') . ';';
echo 'exam=' . dbm_rule_days('exam_design_archive') . ';';
echo 'att=' . dbm_rule_days('student_attendance') . ';';
echo 'blocked=' . dbm_rule_days('bot_outbox_blocked') . ';';
echo 'inbox=' . dbm_rule_days('bot_inbox') . ';';`);
check(after.out.includes('logs=30'), 'the new logs value is really stored (30): ' + after.out.match(/logs=\d+/)[0]);
check(after.out.includes('desk=45'), 'the desk-log value is stored (45)');
check(after.out.includes('exam=7'), 'the exam-archive value is stored (7)');
check(after.out.includes('att=2'), 'the attendance value is stored (2 years)');
check(after.out.includes('blocked=120'), 'the blocked-queue value is stored (120)');
check(after.out.includes('inbox=15'), 'the bot-inbox value is stored (15)');

/* صفحهٔ بازآمده باید مقدار تازه را نشان بدهد، نه مقدار قبلی */
const reload = await req('بازآمیزی صفحهٔ بهینه‌ساز', { file: 'db-optimizer.php', sid: 'dboptSave01', query: 'scan=1' });
check(/name="retention_bot_message_logs" value="30"/.test(reload.res.page),
  'the reloaded page shows the NEW value 30, not the old 90');
check(/name="retention_bot_inbox" value="15"/.test(reload.res.page), 'the bot-inbox rule is on the page with its value');

/* مقدار ۰ = خاموش */
const off = await req('خاموش کردن قاعده', {
  file: 'db-optimizer.php', sid: 'dboptSave01', method: 'POST',
  post: { csrf_token: csrf, do: 'retention_settings', retention_bot_message_logs: '0' },
});
const offChk = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/db_retention.php';
echo 'logs=' . dbm_rule_days('bot_message_logs') . ';';`);
check(offChk.out.includes('logs=0'), 'setting 0 turns the rule off: ' + offChk.out);
const offSweep = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/db_retention.php';
echo 'swept=' . dbm_sweep_rule('bot_message_logs', microtime(true)+3) . ';';`);
check(offSweep.out.includes('swept=0'), 'an off rule never deletes anything');
await run(String.raw`<?php require_once '/www/includes/functions.php'; set_setting('dbopt_logs_days','90');`);

/* ═══════ ۲) بانک سوالات: طراحی آزمونِ موجود هرگز پاک نمی‌شود ═══════ */
const bank = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/db_retention.php';
DB::execute("CREATE TABLE IF NOT EXISTS exam_designs (id INTEGER PRIMARY KEY AUTOINCREMENT, exam_id INTEGER, design_json TEXT, updated_at_jalali TEXT)");
DB::execute("CREATE TABLE IF NOT EXISTS exam_design_archive (id INTEGER PRIMARY KEY AUTOINCREMENT, exam_id INTEGER, design_json TEXT, created_at_jalali TEXT)");
DB::execute("DELETE FROM exam_designs"); DB::execute("DELETE FROM exam_design_archive");
DB::execute("INSERT INTO exam_designs (exam_id,design_json,updated_at_jalali) VALUES (500,'{}','1403/01/01')");
for ($i=1;$i<=12;$i++) DB::execute("INSERT INTO exam_design_archive (exam_id,design_json,created_at_jalali) VALUES (500,?,?)", ['v'.$i, '1403/01/' . sprintf('%02d', $i)]);
for ($i=1;$i<=12;$i++) DB::execute("INSERT INTO exam_design_archive (exam_id,design_json,created_at_jalali) VALUES (501,?,?)", ['w'.$i, '1403/01/' . sprintf('%02d', $i)]);
set_setting('dbopt_exam_versions','5');
echo 'removed=' . dbm_sweep_rule('exam_design_archive', microtime(true)+5) . ';';
echo 'live=' . DB::fetch("SELECT COUNT(*) c FROM exam_design_archive WHERE exam_id=500")['c'] . ';';
echo 'gone=' . DB::fetch("SELECT COUNT(*) c FROM exam_design_archive WHERE exam_id=501")['c'] . ';';`);
check(bank.out.includes('removed=7'), 'only the versions of the deleted exam are pruned (12 → 5): ' + bank.out.slice(0, 120));
check(bank.out.includes('live=12'), 'a live exam keeps its WHOLE design history (12/12): ' + bank.out.match(/live=\d+/)[0]);
check(bank.out.includes('gone=5'), 'a deleted exam keeps only the newest 5: ' + bank.out.match(/gone=\d+/)[0]);

/* ═══════ ۳) صندوق ورودی ربات ═══════ */
const inbox = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
require_once '/www/includes/bot_inbox.php';
bot_inbox_log('bale','881','ali','سلام، نمرهٔ من چند است؟');
bot_inbox_log('bale','882','','این پیام بی‌هدف است');
bot_inbox_log('bale','883','reza','','photo');
bot_inbox_log('telegram','884','sara','hello from telegram');
$f = ['platform'=>'bale','q'=>'','kind'=>'','student'=>''];
echo 'total=' . bot_inbox_total($f) . ';';
echo 'rows=' . count(bot_inbox_rows($f,1,50)) . ';';
echo 'kinds=' . json_encode(bot_inbox_kind_counts('bale'), JSON_UNESCAPED_UNICODE) . ';';
echo 'search=' . count(bot_inbox_rows(['platform'=>'bale','q'=>'بی‌هدف','kind'=>'','student'=>''],1,50)) . ';';
echo 'photo_only=' . count(bot_inbox_rows(['platform'=>'bale','q'=>'','kind'=>'photo','student'=>''],1,50)) . ';';
echo 'telegram=' . bot_inbox_total(['platform'=>'telegram','q'=>'','kind'=>'','student'=>'']) . ';';`);
check(inbox.out.includes('total=3'), 'three bale messages are logged: ' + inbox.out.match(/total=\d+/)[0]);
check(inbox.out.includes('rows=3'), 'the page can read them back');
check(inbox.out.includes('"text":2') && inbox.out.includes('"photo":1'), 'message kinds are counted: ' + inbox.out.match(/kinds=\{[^}]*\}/)[0]);
check(inbox.out.includes('search=1'), 'free-text search finds the aimless message');
check(inbox.out.includes('photo_only=1'), 'the kind filter works');
check(inbox.out.includes('telegram=1'), 'telegram messages are kept separately');

/* صفحهٔ صندوق ورودی */
await loginAdmin('inbox01');
const inboxPage = await req('صفحهٔ پیام‌های دریافتی', { file: 'bot-inbox.php', sid: 'inbox01', query: 'platform=bale' });
check(!inboxPage.res.fatal, 'the inbox page renders: ' + (inboxPage.res.fatal || ''));
check((inboxPage.res.page || '').includes('این پیام بی‌هدف است'), 'the aimless message is visible on the page');
check((inboxPage.res.page || '').includes('پیام‌های دریافتی ربات'), 'the page has its title');
const inboxSearch = await req('جستجو در صندوق', { file: 'bot-inbox.php', sid: 'inbox01', query: 'platform=bale&q=' + encodeURIComponent('بی‌هدف') });
check((inboxSearch.res.page || '').includes('این پیام بی‌هدف است'), 'searching from the page works');

/* تب تازه در «تنظیمات دیگر» */
const hub = await req('تنظیمات دیگر', { file: 'other-settings.php', sid: 'inbox01', query: 'hub_tab=inbox' });
check((hub.res.page || '').includes('bot-inbox.php?embedded=1'), 'the new tab is registered in the settings hub: ' + ((hub.res.page || '').match(/bot-inbox[^"']*/) || [''])[0]);

console.log('PASS ' + n + ' checks (retention settings now persist, question bank is safe, bot inbox works)');
process.exit(0);
