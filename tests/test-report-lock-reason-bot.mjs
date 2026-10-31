// Real PHP/SQLite: «علت مسدودسازی کارنامه در ربات» — v4.209.3.
//
// پیش از این، دانش‌آموز/اولیا وقتی کارنامهٔ یک ماه مسدود بود در ربات هیچ
// جوابی نمی‌گرفت (شاخهٔ rep_ بی‌صدا رد می‌شد و pdfrep_ هم چیزی نمی‌فرستاد)
// و دکمهٔ «دریافت کارنامه» ماه‌های مسدود را کامل حذف می‌کرد و اگر ماه باز
// دیگری نبود، پیام گمراه‌کنندهٔ «کارنامه‌ای صادر نشده» می‌داد.
//
// این سوئیت همان رفتار را با اجرای واقعی موتور وبهوک ثابت می‌کند:
//   ۱) کارنامهٔ مسدود ⇒ پیام «علت مسدودسازی» با همان متنِ ثبت‌شدهٔ مدیر،
//      و هیچ نمره‌ای در پیام‌ها نیست،
//   ۲) گفتگویی که به آن دانش‌آموز وصل نیست ⇒ «دسترسی تأیید نشد» و هیچ
//      متنی از علت (حریم خصوصی)،
//   ۳) کارنامهٔ آزاد مثل قبل نمره/معدل می‌دهد (رگرسیون)،
//   ۴) pdfrep_ روی کارنامهٔ مسدود ⇒ علت، بدون فایل،
//   ۵) دکمهٔ کارنامه‌ها: دکمه فقط برای ماه باز، و فهرست ماه‌های مسدود با علت،
//   ۶) دانش‌آموزی که تنها کارنامه‌اش مسدود است دیگر پیام «صادر نشده» نمی‌گیرد،
//   ۷) قفلِ ستونی (بدون قاعدهٔ report_locks) هم متن عمومی را نشان می‌دهد
//      و آزادسازی دوباره نمره را برمی‌گرداند.
import assert from 'node:assert/strict';
import { php, run } from './harness/lib.mjs';

const REASON = 'نقص پرونده یا عدم تسویه حساب مالی';
const CHAT_7301 = '556001';
const CHAT_7302 = '556002';
const CHAT_7303 = '556003';
const CHAT_7304 = '556004';

let checks = 0;
const check = (v, m) => { assert(v, m); checks++; };
const wireText = (wire) => wire.map(x => x.text || '').join('\n─────\n');

/* ── fixture ─────────────────────────────────────────────────────────────── */
const setup = await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
ensure_bot_schema('bale');
set_setting('current_academic_year','1404/1405');
set_setting('school_name','آموزشگاه آزمون');
set_setting('school_name_short','آزمون');
set_setting('bale_bot_token','fixture-token');
DB::execute("DELETE FROM report_grades WHERE report_id IN (SELECT id FROM reports WHERE student_id IN (7301,7302,7303,7304))");
DB::execute("DELETE FROM reports WHERE student_id IN (7301,7302,7303,7304)");
DB::execute("DELETE FROM students WHERE id IN (7301,7302,7303,7304)");
DB::execute("DELETE FROM report_locks");
DB::execute("DELETE FROM bale_bot_users WHERE bale_chat_id IN ('${CHAT_7301}','${CHAT_7302}','${CHAT_7303}','${CHAT_7304}')");
DB::execute("DELETE FROM bale_bot_state WHERE bale_chat_id IN ('${CHAT_7301}','${CHAT_7302}','${CHAT_7303}','${CHAT_7304}')");
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year,serial_number) VALUES (7301,'0000007301','علی','آزادگی','هفتم ۱','هفتم','active','1404/1405','730111')");
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year,serial_number) VALUES (7302,'0000007302','مریم','دیگری','هشتم ۲','هشتم','active','1404/1405','730222')");
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year,serial_number) VALUES (7303,'0000007303','سارا','قفل‌کامل','نهم ۳','نهم','active','1404/1405','730333')");
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year,serial_number) VALUES (7304,'0000007304','رضا','قفل‌ستونی','دهم ۴','دهم','active','1404/1405','730444')");
DB::execute("INSERT INTO bale_bot_users (bale_chat_id,student_id) VALUES ('${CHAT_7301}',7301)");
DB::execute("INSERT INTO bale_bot_users (bale_chat_id,student_id) VALUES ('${CHAT_7302}',7302)");
DB::execute("INSERT INTO bale_bot_users (bale_chat_id,student_id) VALUES ('${CHAT_7303}',7303)");
DB::execute("INSERT INTO bale_bot_users (bale_chat_id,student_id) VALUES ('${CHAT_7304}',7304)");
DB::execute("INSERT INTO reports (student_id,class_name,academic_year,term,report_month,total_score,gpa,discipline_score) VALUES (7301,'هفتم ۱','1404/1405','نوبت اول','آبان',19.5,17.25,20)");
$aban = (int)DB::lastInsertId();
DB::execute("INSERT INTO report_grades (report_id,subject_name,score,max_score,coefficient,status) VALUES (?, 'ریاضی', 19.5, 20, 1, 'passed')", [$aban]);
DB::execute("INSERT INTO reports (student_id,class_name,academic_year,term,report_month,total_score,gpa) VALUES (7301,'هفتم ۱','1404/1405','نوبت اول','مهر',18,16)");
$mehr = (int)DB::lastInsertId();
DB::execute("INSERT INTO reports (student_id,class_name,academic_year,term,report_month,total_score,gpa) VALUES (7302,'هشتم ۲','1404/1405','نوبت اول','آبان',15,15)");
DB::execute("INSERT INTO reports (student_id,class_name,academic_year,term,report_month,total_score,gpa) VALUES (7303,'نهم ۳','1404/1405','نوبت اول','آبان',14,14)");
$onlyLocked = (int)DB::lastInsertId();
DB::execute("INSERT INTO reports (student_id,class_name,academic_year,term,report_month,total_score,gpa) VALUES (7304,'دهم ۴','1404/1405','نوبت اول','آبان',13,13)");
$rowLocked = (int)DB::lastInsertId();
/* قفل گروهی با علتِ خوانا — همان کاری که مدیر از پنجرهٔ «مسدودسازی» می‌کند */
set_report_bulk_lock('1404/1405', 'آبان', 'هفتم ۱', 0, 1, '${REASON}');
set_report_bulk_lock('1404/1405', 'آبان', 'نهم ۳', 0, 1, 'تا پایان بررسی پروندهٔ انضباطی');
/* قفلِ ستونی بدون هیچ قاعده‌ای — مسیر پنل دبیر/انضباط/قفل تک‌تک */
DB::execute("UPDATE reports SET is_locked = 1 WHERE id = ?", [$rowLocked]);
echo 'IDS=' . json_encode(['aban' => $aban, 'mehr' => $mehr, 'onlyLocked' => $onlyLocked, 'rowLocked' => $rowLocked]);`);
const ids = JSON.parse((setup.out.match(/IDS=(\{.*\})/) || [])[1] || '{}');
check(ids.aban > 0 && ids.mehr > 0 && ids.onlyLocked > 0 && ids.rowLocked > 0,
  'fixture built both reports and all four students: ' + setup.out + setup.err);

/* ── unit level: the reason helpers ──────────────────────────────────────── */
const unit = await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
$a = DB::fetch("SELECT * FROM reports WHERE id = ?", [${ids.aban}]);
$m = DB::fetch("SELECT * FROM reports WHERE id = ?", [${ids.mehr}]);
$o = DB::fetch("SELECT * FROM reports WHERE id = ?", [${ids.onlyLocked}]);
$r = DB::fetch("SELECT * FROM reports WHERE id = ?", [${ids.rowLocked}]);
$out = [
  'locked_aban' => report_lock_reason_for($a),
  'free_mehr' => report_lock_reason_for($m),
  'locked_only' => report_lock_reason_for($o),
  'row_locked' => report_lock_reason_for($r),
  'lookup' => report_lock_reason_lookup('1404/1405', 'آبان', 'هفتم ۱', 7301),
  'lookup_none' => report_lock_reason_lookup('1404/1405', 'مهر', 'هفتم ۱', 7301),
  'generic' => report_lock_generic_reason(),
  'tpl' => bot_text('bale', 'report_locked', ['student_name' => 'علی آزادگی', 'term' => 'نوبت اول', 'month' => 'آبان', 'reason' => '${REASON}']),
  'msg' => bot_report_lock_message('bale', $a),
  'list_msg' => bot_report_lock_list_message('bale', 'علی آزادگی', [['term' => 'نوبت اول', 'report_month' => 'آبان', 'reason' => '${REASON}']]),
];
echo 'UNIT=' . json_encode($out, JSON_UNESCAPED_UNICODE);`);
const unitOut = JSON.parse((unit.out.match(/UNIT=(\{.*\})/) || [])[1] || '{}');
check(unitOut.locked_aban === REASON, 'a group lock exposes the exact admin reason: ' + JSON.stringify(unitOut));
check(unitOut.free_mehr === '', 'an unlocked report has no reason (empty string)');
check(unitOut.locked_only === 'تا پایان بررسی پروندهٔ انضباطی', 'a second class keeps its own reason');
check(unitOut.row_locked === unitOut.generic, 'a column-only lock falls back to the shared generic sentence');
check(unitOut.lookup === REASON, 'report_lock_reason_lookup finds the rule text');
check(unitOut.lookup_none === '', 'report_lock_reason_lookup returns nothing for a month without a rule');
check(unitOut.tpl.includes(REASON) && unitOut.tpl.includes('علی آزادگی') && !unitOut.tpl.includes('{reason}'),
  'the report_locked template replaces every placeholder: ' + unitOut.tpl);
check(unitOut.msg.includes(REASON) && unitOut.msg.includes('آبان'), 'bot_report_lock_message builds the full lock message');
check(unitOut.list_msg.includes(REASON) && unitOut.list_msg.includes('آبان'), 'bot_report_lock_list_message builds the locked-months list');

/* ── driving the real webhook engine ─────────────────────────────────────── */
const INPUT_LINE = "$input = file_get_contents('php://input');";
const MOCK = `function bot_api_request($platform,$method,$data=[],$multipart=false){
 $wire=json_decode((string)@file_get_contents('/harness/wire.json'),true); if(!is_array($wire))$wire=[];
 $wire[]=['platform'=>$platform,'method'=>$method,'chat_id'=>(string)($data['chat_id']??''),'text'=>(string)($data['text']??''),'caption'=>(string)($data['caption']??''),'markup'=>$data['reply_markup']??null];
 @file_put_contents('/harness/wire.json',json_encode($wire,JSON_UNESCAPED_UNICODE));
 return ['ok'=>true,'result'=>['message_id'=>count($wire)]];}`;
const src = php.readFileAsText('/www/includes/bot_webhook_engine.php');
check(src.includes(INPUT_LINE), 'the shipped engine still reads the request body from php://input');
const body = src.replace(/^<\?php\r?\n/, '').replace(INPUT_LINE, "$input = @file_get_contents('/harness/update.json');");
php.writeFile('/www/includes/__harness_webhook_bale.php',
  `<?php\ndefine('BOT_PLATFORM', 'bale');\n${MOCK}\n${body}`);

async function respond(update) {
  php.writeFile('/harness/update.json', JSON.stringify(update));
  php.writeFile('/harness/wire.json', '[]');
  const r = await run(`<?php require '/www/includes/__harness_webhook_bale.php';`);
  let wire = [];
  try { wire = JSON.parse(php.readFileAsText('/harness/wire.json')); } catch (e) { wire = []; }
  return { wire, out: r.out, err: r.err };
}
const callback = (chatId, data) => respond({
  callback_query: { id: 'cb1', data, message: { chat: { id: chatId } }, from: { username: 'harness-user' } },
});
const say = (chatId, text) => respond({
  message: { chat: { id: chatId }, text, from: { username: 'harness-user' } },
});
const NO_GRADES = (t) => !t.includes('19.5') && !t.includes('۱۹.۵') && !t.includes('17.25') && !t.includes('۱۷.۲۵');

/* a) locked report + linked parent ⇒ the reason, no grades */
let w = await callback(CHAT_7301, `rep_${ids.aban}`);
let t = wireText(w.wire);
check(t.includes('علت مسدودسازی'), 'tapping a locked report now answers with a reason instead of silence: ' + t.slice(0, 300) + w.err.slice(0, 200));
check(t.includes(REASON), 'the reason shown is exactly what the admin typed');
check(NO_GRADES(t), 'no grade or GPA leaks from a locked report: ' + t.slice(0, 300));

/* b) another parent guessing the same report id ⇒ access denied, never the reason */
w = await callback(CHAT_7302, `rep_${ids.aban}`);
t = wireText(w.wire);
check(t.includes('دسترسی شما به این کارنامه تأیید نشد'), 'an unlinked chat is refused: ' + t.slice(0, 300));
check(!t.includes(REASON), 'the lock reason is never leaked to a chat that is not linked to the student');

/* c) unlocked report ⇒ unchanged behaviour (grade text still delivered) */
w = await callback(CHAT_7301, `rep_${ids.mehr}`);
t = wireText(w.wire);
check(t.includes('🎓 کارنامه') && t.includes('معدل'), 'an unlocked report still sends the report text exactly as before: ' + t.slice(0, 300));
check(!t.includes('علت مسدودسازی'), 'an unlocked report never shows a lock reason');

/* d) PDF of a locked report ⇒ reason, and no document */
w = await callback(CHAT_7301, `pdfrep_${ids.aban}`);
t = wireText(w.wire);
check(t.includes(REASON), 'the PDF button on a locked report explains the lock: ' + t.slice(0, 300));
check(!w.wire.some(x => x.method === 'sendDocument'), 'no PDF document is sent for a locked report');
check(NO_GRADES(t), 'the PDF refusal does not leak grades either');

/* e) the reports button: buttons only for open months, reasons for locked ones */
w = await say(CHAT_7301, '📊 دریافت کارنامه تحصیلی');
t = wireText(w.wire);
const buttons = JSON.stringify(w.wire.map(x => x.markup || {}));
check(buttons.includes(`rep_${ids.mehr}`), 'the OPEN month still has its inline button: ' + buttons.slice(0, 300));
check(!buttons.includes(`rep_${ids.aban}`), 'the LOCKED month gets no button (the lock still holds)');
check(t.includes(REASON), 'the locked month is listed with its reason: ' + t.slice(0, 400));
check(!t.includes('کارنامه فعالی صادر نشده'), 'the misleading «no report» text is not shown when only some months are locked');

/* f) a student whose ONLY report is locked ⇒ reason, not «no report» */
w = await say(CHAT_7303, '📊 دریافت کارنامه تحصیلی');
t = wireText(w.wire);
check(t.includes('تا پایان بررسی پروندهٔ انضباطی'), 'the only-month-locked student sees the reason: ' + t.slice(0, 400));
check(!t.includes('کارنامه فعالی صادر نشده'), 'and never the wrong «nothing issued» message');

/* g) column-only lock (teacher/discipline/single toggle) ⇒ generic reason */
w = await callback(CHAT_7304, `rep_${ids.rowLocked}`);
t = wireText(w.wire);
check(t.includes('علت مسدودسازی') && t.includes('امور اداری/مالی'), 'a column-only lock still explains itself with the generic sentence: ' + t.slice(0, 300));
check(NO_GRADES(t), 'and still leaks no score');

/* h) unlocking restores the grades — the reason disappears with the lock */
const unlock = await run(`<?php require_once '/www/includes/functions.php';
set_report_bulk_lock('1404/1405', 'آبان', 'هفتم ۱', 0, 0, '');
echo 'UNLOCKED';`);
check(unlock.out.includes('UNLOCKED'), 'the class month can be unlocked again');
w = await callback(CHAT_7301, `rep_${ids.aban}`);
t = wireText(w.wire);
check(!t.includes('علت مسدودسازی'), 'after unlocking, no lock reason is sent');
check(t.includes('🎓 کارنامه'), 'and the report text comes back exactly as before: ' + t.slice(0, 300));

console.log(`PASS ${checks} locked-report-reason cases (reason shown, privacy kept, grades delivered only when open, list + PDF + generic fallback + unlock)`);
process.exit(0);
