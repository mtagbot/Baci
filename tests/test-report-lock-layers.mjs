// Real PHP/SQLite: «لایه‌های مسدودسازی کارنامه» — v4.209.4.
//
// سناریوی کاربر: کل کلاس برای یک ماه (مثلاً آبان) بسته می‌شود تا نمرات تکمیل
// شود و همان‌موقع چند دانش‌آموز به دلیل دیگری (بدهی مالی) جدا مسدود می‌شوند.
// با تکمیل نمرات، مسدودیت گروهی برداشته می‌شود ولی مسدودیت دومِ همان
// دانش‌آموزان باید سر جایش بماند.
//
// این سوئیت با اجرای واقعی کد ثابت می‌کند:
//   ۱) آزادسازی گروهی، مسدودیت فردی (قاعده و قفل ردیف) را باز نمی‌کند،
//   ۲) دانش‌آموزان بدون مسدودیت فردی واقعاً آزاد می‌شوند،
//   ۳) ثبت نمره توسط دبیر در ماهِ قفل‌شده «قفل فردی» نمی‌سازد (قبلاً می‌ساخت
//      و همان باعث می‌شد آزادسازی گروهی نتواند کارنامه را باز کند)،
//   ۴) دادهٔ نسخه‌های قبل (ستون ۱ + قاعدهٔ گروهی) یک‌بار بازسازی می‌شود و
//      تصمیم نمایش همان می‌مانَد،
//   ۵) «آزادسازی کامل» با تیک صریح مدیر همه‌چیز همین محدوده را پاک می‌کند،
//   ۶) قفل/رفع مسدودیت فردی از دکمهٔ ردیف کارنامه مستقل عمل می‌کند،
//   ۷) ربات بعد از آزادسازی گروهی، علتِ مسدودیت دوم را نشان می‌دهد و نمره نمی‌دهد.
import assert from 'node:assert/strict';
import { php, run } from './harness/lib.mjs';

let checks = 0;
const check = (v, m) => { assert(v, m); checks++; };

const YEAR = '1404/1405';
const MONTH = 'آبان';
const DEBT_REASON = 'بدهی مالی شهریه';

/* ── fixture ─────────────────────────────────────────────────────────────── */
const setup = await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
ensure_bot_schema('bale');
set_setting('current_academic_year','${YEAR}');
set_setting('school_name','آموزشگاه آزمون');
set_setting('bale_bot_token','fixture-token');
DB::execute("DELETE FROM reports WHERE student_id IN (7401,7402,7403,7404,7405)");
DB::execute("DELETE FROM students WHERE id IN (7401,7402,7403,7404,7405)");
DB::execute("DELETE FROM report_locks");
DB::execute("DELETE FROM bale_bot_users WHERE bale_chat_id IN ('557001','557004')");
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year,serial_number) VALUES (7401,'0000007401','سارا','عادی','هفتم ۱','هفتم','active','${YEAR}','740111')");
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year,serial_number) VALUES (7402,'0000007402','رضا','بدهکار','هفتم ۱','هفتم','active','${YEAR}','740222')");
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year,serial_number) VALUES (7403,'0000007403','مریم','کلاس‌دیگر','هشتم ۲','هشتم','active','${YEAR}','740333')");
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year,serial_number) VALUES (7404,'0000007404','علی','قدیمی‌داده','هفتم ۱','هفتم','active','${YEAR}','740444')");
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year,serial_number) VALUES (7405,'0000007405','نگار','دستکاری','هفتم ۱','هفتم','active','${YEAR}','740555')");
DB::execute("INSERT INTO bale_bot_users (bale_chat_id,student_id) VALUES ('557001',7401)");
DB::execute("INSERT INTO bale_bot_users (bale_chat_id,student_id) VALUES ('557004',7404)");
foreach ([7401,7402,7403,7404,7405] as $i => $sid) {
    $class = $sid === 7403 ? 'هشتم ۲' : 'هفتم ۱';
    DB::execute("INSERT INTO reports (student_id,class_name,academic_year,term,report_month,total_score,gpa,is_locked) VALUES (?,?,?,'نوبت اول','${MONTH}',18,18,0)", [$sid,$class,'${YEAR}']);
}
echo 'SEEDED';`);
check(setup.out.includes('SEEDED'), 'fixture seeded: ' + setup.out + setup.err);

const state = async (label) => {
  const r = await run(`<?php require_once '/www/includes/functions.php';
$out = [];
foreach ([7401,7402,7403,7404,7405] as $sid) {
    $rep = DB::fetch("SELECT * FROM reports WHERE student_id = ? AND report_month = '${MONTH}' ORDER BY id DESC LIMIT 1", [$sid]);
    $why = '';
    $locked = $rep ? is_report_locked_for_student($rep, $why) : null;
    $out[$sid] = ['locked' => $locked, 'column' => $rep ? (int)$rep['is_locked'] : null, 'reason' => $locked ? report_lock_reason_for($rep) : ''];
}
echo 'ST=' . json_encode($out, JSON_UNESCAPED_UNICODE);`);
  const parsed = JSON.parse((r.out.match(/ST=(\{.*\})/) || [])[1] || '{}');
  return parsed;
};

/* ── ۱) گروه قفل + دو مسدودیت فردی (یکی با قاعده، یکی با قفل ردیف) ──────── */
await run(`<?php require_once '/www/includes/functions.php';
/* مسدودیت گروهی کلاس «هفتم ۱» برای آبان — همان کاری که مدیر از پنجرهٔ مسدودسازی می‌کند */
set_report_bulk_lock('${YEAR}', '${MONTH}', 'هفتم ۱', 0, 1, 'در حال تکمیل نمرات توسط دبیران');
/* مسدودیت فردی رضا با علت مشخص (بدهی مالی) — از همان پنجره با انتخاب دانش‌آموز */
set_report_bulk_lock('${YEAR}', '${MONTH}', 'هفتم ۱', 7402, 1, '${DEBT_REASON}');
/* مسدودیت فردی نگار فقط از دکمهٔ ردیف کارنامه (قفل ستون، بدون قاعده) */
DB::execute("UPDATE reports SET is_locked = 1 WHERE student_id = 7405 AND report_month = '${MONTH}'");
echo 'LOCKED';`);
let st = await state();
check(st['7401'].locked === true, 'the whole class is locked by the group rule');
check(st['7402'].locked === true && st['7402'].reason === DEBT_REASON, 'the debt student is locked with his own reason: ' + JSON.stringify(st['7402']));
check(st['7403'].locked === false, 'a student in another class is untouched by the class rule');
check(st['7405'].locked === true && st['7405'].column === 1, 'the row-level individual lock is a real lock');

/* ── ۲) آزادسازی گروهی ← مسدودیت فردی باید بماند ───────────────────────── */
await run(`<?php require_once '/www/includes/functions.php';
set_report_bulk_lock('${YEAR}', '${MONTH}', 'هفتم ۱', 0, 0, '');
echo 'RELEASED';`);
st = await state();
check(st['7401'].locked === false, 'the ordinary student is released by the group unlock (the original goal)');
check(st['7402'].locked === true, 'THE FIX: the debt student STAYS locked after the group unlock');
check(st['7402'].reason === DEBT_REASON, 'and his own reason is still shown: ' + st['7402'].reason);
check(st['7405'].locked === true, 'the row-level individual lock also survives the group unlock');
check(st['7403'].locked === false, 'other classes stay untouched');

/* ── ۳) آزادسازی فردی رضا ← باید باز شود ─────────────────────────────────── */
await run(`<?php require_once '/www/includes/functions.php';
set_report_bulk_lock('${YEAR}', '${MONTH}', 'هفتم ۱', 7402, 0, '');
echo 'ONE';`);
st = await state();
check(st['7402'].locked === false, 'releasing the individual lock opens that student');

/* ── ۴) ثبت نمره توسط دبیر در ماهِ قفل‌شده قفل فردی نمی‌سازد ──────────── */
const teacher = await run(`<?php require_once '/www/includes/functions.php';
set_report_bulk_lock('${YEAR}', '${MONTH}', 'هفتم ۱', 0, 1, 'در حال تکمیل نمرات توسط دبیران');
/* همان کاری که پنل دبیر در ثبت نمره انجام می‌دهد: ساختن/به‌روزرسانی کارنامه */
DB::execute("DELETE FROM reports WHERE student_id = 7403 AND report_month = '${MONTH}'");
DB::execute("INSERT INTO reports (student_id,class_name,academic_year,term,report_month,total_score,gpa,is_locked) VALUES (7403,'هشتم ۲','${YEAR}','نوبت اول','${MONTH}',0,0,0)");
$col = (int)DB::fetch("SELECT is_locked FROM reports WHERE student_id = 7403 AND report_month = '${MONTH}'")['is_locked'];
echo 'COL=' . $col;
set_report_bulk_lock('${YEAR}', '${MONTH}', 'هفتم ۱', 0, 0, '');
$after = DB::fetch("SELECT * FROM reports WHERE student_id = 7401 AND report_month = '${MONTH}'");
echo ';AFTER=' . (is_report_locked_for_student($after) ? 'locked' : 'open');`);
check(teacher.out.includes('COL=0'), 'grade entry never writes the group lock into the reports row');
check(teacher.out.includes('AFTER=open'), 'so the class unlock really opens the class');
st = await state();
check(st['7405'].locked === true, 'the row-level lock survives that too (nobody silently re-locks or releases)');

/* ── ۵) دادهٔ نسخهٔ قبل: ستون ۱ + قاعدهٔ گروهی ← بازسازی یک‌باره ────────── */
const legacy = await run(`<?php require_once '/www/includes/functions.php';
/* شبیه‌سازی دادهٔ V4.209.3: قاعدهٔ گروهی + ستون ۱ برای همان ردیف */
DB::execute("DELETE FROM report_locks");
set_report_bulk_lock('${YEAR}', '${MONTH}', 'هفتم ۱', 0, 1, 'در حال تکمیل نمرات توسط دبیران');
DB::execute("UPDATE reports SET is_locked = 1 WHERE student_id = 7401 AND report_month = '${MONTH}'");
set_setting('report_lock_node_holds_v2094', '0');
unset($GLOBALS['__sdp_report_lock_norm_done']);
unset($GLOBALS['__sdp_report_locks_cache']);
report_lock_normalize_node_holds();
$rep = DB::fetch("SELECT * FROM reports WHERE student_id = 7401 AND report_month = '${MONTH}'");
echo 'BEFORE_UNLOCK=' . (is_report_locked_for_student($rep) ? 'locked' : 'open') . ';';
echo 'COLUMN=' . (int)$rep['is_locked'] . ';';
set_report_bulk_lock('${YEAR}', '${MONTH}', 'هفتم ۱', 0, 0, '');
$rep2 = DB::fetch("SELECT * FROM reports WHERE student_id = 7401 AND report_month = '${MONTH}'");
echo 'AFTER_UNLOCK=' . (is_report_locked_for_student($rep2) ? 'locked' : 'open');`);
check(legacy.out.includes('BEFORE_UNLOCK=locked'), 'legacy data stays locked before anything changes: ' + legacy.out);
check(legacy.out.includes('COLUMN=0'), 'the one-time pass moves the lock from the column to the rule it belongs to');
check(legacy.out.includes('AFTER_UNLOCK=open'), 'and after that the group unlock finally works on legacy rows too');

/* ── ۶) «آزادسازی کامل» با تیک صریح مدیر ───────────────────────────────── */
const full = await run(`<?php require_once '/www/includes/functions.php';
set_report_bulk_lock('${YEAR}', '${MONTH}', 'هفتم ۱', 0, 1, 'در حال تکمیل نمرات توسط دبیران');
set_report_bulk_lock('${YEAR}', '${MONTH}', 'هفتم ۱', 7402, 1, '${DEBT_REASON}');
DB::execute("UPDATE reports SET is_locked = 1 WHERE student_id = 7405 AND report_month = '${MONTH}'");
/* حالت امن (پیش‌فرض): فقط گروه */
set_report_bulk_lock('${YEAR}', '${MONTH}', 'هفتم ۱', 0, 0, '');
$safe = [];
foreach ([7401,7402,7405] as $sid) { $r = DB::fetch("SELECT * FROM reports WHERE student_id=? AND report_month='${MONTH}'", [$sid]); $safe[$sid] = is_report_locked_for_student($r) ? 'L' : 'O'; }
echo 'SAFE=' . implode('', $safe) . ';';
/* حالت کامل با تیک صریح */
set_report_bulk_lock('${YEAR}', '${MONTH}', 'هفتم ۱', 0, 0, '', true);
$all = [];
foreach ([7401,7402,7405] as $sid) { $r = DB::fetch("SELECT * FROM reports WHERE student_id=? AND report_month='${MONTH}'", [$sid]); $all[$sid] = is_report_locked_for_student($r) ? 'L' : 'O'; }
echo 'ALL=' . implode('', $all) . ';';
echo 'RULES=' . (int)DB::fetch("SELECT COUNT(*) AS c FROM report_locks")['c'];`);
check(full.out.includes('SAFE=OLL'), 'safe release leaves the individual locks in place: ' + full.out);
check(full.out.includes('ALL=OOO'), 'the explicit "full release" clears individual locks as well');
check(full.out.includes('RULES=0'), 'and leaves no stale rule behind');

/* ── ۷) ربات: بعد از آزادسازی گروهی، علتِ مسدودیت دوم ──────────────────── */
await run(`<?php require_once '/www/includes/functions.php';
DB::execute("DELETE FROM report_locks");
set_report_bulk_lock('${YEAR}', '${MONTH}', 'هفتم ۱', 0, 1, 'در حال تکمیل نمرات توسط دبیران');
set_report_bulk_lock('${YEAR}', '${MONTH}', 'هفتم ۱', 7404, 1, '${DEBT_REASON}');
DB::execute("DELETE FROM report_grades WHERE report_id IN (SELECT id FROM reports WHERE student_id = 7404)");
DB::execute("INSERT INTO report_grades (report_id,subject_name,score,max_score,coefficient,status) SELECT id,'ریاضی',12.5,20,1,'passed' FROM reports WHERE student_id = 7404 AND report_month = '${MONTH}'");
set_report_bulk_lock('${YEAR}', '${MONTH}', 'هفتم ۱', 0, 0, '');
echo 'BOT_READY';`);

const INPUT_LINE = "$input = file_get_contents('php://input');";
const MOCK = `function bot_api_request($platform,$method,$data=[],$multipart=false){
 $wire=json_decode((string)@file_get_contents('/harness/wire.json'),true); if(!is_array($wire))$wire=[];
 $wire[]=['method'=>$method,'text'=>(string)($data['text']??''),'caption'=>(string)($data['caption']??'')];
 @file_put_contents('/harness/wire.json',json_encode($wire,JSON_UNESCAPED_UNICODE));
 return ['ok'=>true,'result'=>['message_id'=>count($wire)]];}`;
const src = php.readFileAsText('/www/includes/bot_webhook_engine.php');
check(src.includes(INPUT_LINE), 'the shipped engine still reads the request body from php://input');
php.writeFile('/www/includes/__harness_webhook_bale.php', `<?php\ndefine('BOT_PLATFORM', 'bale');\n${MOCK}\n` +
  src.replace(/^<\?php\r?\n/, '').replace(INPUT_LINE, "$input = @file_get_contents('/harness/update.json');"));

const repId = await run(`<?php require_once '/www/includes/functions.php';
echo DB::fetch("SELECT id FROM reports WHERE student_id = 7404 AND report_month = '${MONTH}'")['id'];`);
php.writeFile('/harness/update.json', JSON.stringify({
  callback_query: { id: 'cb1', data: 'rep_' + repId.out.trim(), message: { chat: { id: '557004' } }, from: { username: 'harness' } },
}));
php.writeFile('/harness/wire.json', '[]');
const bot = await run(`<?php require '/www/includes/__harness_webhook_bale.php';`);
const sent = (() => { try { return JSON.parse(php.readFileAsText('/harness/wire.json')); } catch (e) { return []; } })();
const botText = sent.map(x => (x.text || '') + (x.caption || '')).join('\n');
check(botText.includes(DEBT_REASON), 'the bot still reports the debt reason after the class was unlocked: ' + botText.slice(0, 300));
check(!botText.includes('12.5') && !botText.includes('۱۲.۵'), 'and still delivers no grade while that individual lock is active');

console.log(`PASS ${checks} layered-lock cases (group vs individual, teacher writes, legacy data, full release, bot reason)`);
process.exit(0);
