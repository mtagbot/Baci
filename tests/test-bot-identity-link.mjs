// Real PHP/SQLite: «اتصال ولی با شناسهٔ پرونده» — the v4.166.0 identity
// resolver, driven through the REAL bot_webhook_engine.php on php-wasm.
//
// What is proven here (execution, not claims):
//   1) a parent can link with ONLY the 6-digit identity of a mixed-format
//      profile ("26/ب/265486" → "265486"), and with the full typed form
//      ("26ب265486") — letters and the 2-digit prefix never matter,
//   2) standard 10-digit national ids keep working exactly as before,
//   3) two different students sharing one 6-digit identity are NOT linked
//      (ambiguity = safe "not found"),
//   4) inputs shorter than 6 digits are rejected by the format gate, and
//   5) multi-year students resolve to the latest academic-year row.
// Only the two bot providers are mocked (exactly like the other bot suites).
import assert from 'node:assert/strict';
import { php, run, db, loginAdmin } from './harness/lib.mjs';

const sid = 'testBotIdentityLink';
await loginAdmin(sid);
let checks = 0;
const check = (v, m) => { assert(v, m); checks++; };

/* ── fixture ─────────────────────────────────────────────────────────────── */
const setup = await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
ensure_bot_schema('bale');
set_setting('current_academic_year','1404/1405');
set_setting('school_name','آموزشگاه آزمون'); set_setting('school_name_short','آزمون');
set_setting('bale_bot_token','fixture-token');
DB::execute("DELETE FROM students WHERE id IN (7001,7002,7003,7004,7005)");
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year,serial_number) VALUES (7001,'1234567890','علی','استاندارد','101','دهم','active','1404/1405','111222')");
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year,serial_number) VALUES (7002,'26/ب/265486','مریم','شناسه‌دار','هفتم ۱','هفتم','active','1404/1405','654321')");
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year,serial_number) VALUES (7005,'26/ب/265486','مریم','شناسه‌دار','هشتم ۱','هشتم','active','1405/1406','654321')");
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year,serial_number) VALUES (7003,'99/الف/111111','ابهام','یک','101','دهم','active','1404/1405','999999')");
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year,serial_number) VALUES (7004,'45/ب/111111','ابهام','دو','101','دهم','active','1404/1405','888888')");
/* v4.167.0: exactly what student_serial_from_form() stores for a serial with
   letter + 2-digit prefix — the cohort that got «سریال یا رمز اشتباه». */
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year,serial_number) VALUES (7006,'9876543210','رضا','سریال‌مرکب','101','دهم','active','1404/1405','ب/26/654987')");
DB::execute("DELETE FROM bale_bot_users WHERE bale_chat_id IN ('555001','555002','555003')");
DB::execute("DELETE FROM bale_bot_state WHERE bale_chat_id IN ('555001','555002','555003')");
echo 'FIXTURE_OK';`);
check(setup.out.includes('FIXTURE_OK'), setup.out + setup.err);

/* ── resolver unit level ─────────────────────────────────────────────────── */
const unit = await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
$a=bot_find_student_by_identity('265486');
$b=bot_find_student_by_identity('26ب265486');
$c=bot_find_student_by_identity('1234567890');
$d=bot_find_student_by_identity('111111');
$e=bot_find_student_by_identity('12345');
echo 'A='.($a['id']??0).';B='.($b['id']??0).';C='.($c['id']??0).';D='.($d['id']??0).';E='.($e['id']??0);`);
const u = Object.fromEntries((unit.out.match(/([A-E])=(\d+)/g) || []).map(kv => kv.split('=')));
check(u.A === '7005', 'the 6-digit identity resolves to the LATEST-year row of the mixed-format profile: ' + unit.out);
check(u.B === '7005', 'the full typed form (letters included) resolves to the same student');
check(u.C === '7001', 'standard 10-digit national ids still resolve exactly as before');
check(u.D === '0', 'two different students sharing one 6-digit identity are never linked (ambiguity = not found)');
check(u.E === '0', 'inputs shorter than 6 digits cannot resolve');

/* ── driving the real webhook engine ─────────────────────────────────────── */
const INPUT_LINE = "$input = file_get_contents('php://input');";
const MOCK = `function bot_api_request($platform,$method,$data=[],$multipart=false){
 $wire=json_decode((string)@file_get_contents('/harness/wire.json'),true); if(!is_array($wire))$wire=[];
 $wire[]=['platform'=>$platform,'method'=>$method,'chat_id'=>(string)($data['chat_id']??''),'text'=>(string)($data['text']??'')];
 @file_put_contents('/harness/wire.json',json_encode($wire,JSON_UNESCAPED_UNICODE));
 return ['ok'=>true,'result'=>['message_id'=>count($wire)]];}`;
function buildDriver() {
  const src = php.readFileAsText('/www/includes/bot_webhook_engine.php');
  check(src.includes(INPUT_LINE), 'the shipped engine still reads the request body from php://input');
  const body = src.replace(/^<\?php\r?\n/, '').replace(INPUT_LINE, "$input = @file_get_contents('/harness/update.json');");
  php.writeFile('/www/includes/__harness_webhook_bale.php',
    `<?php\ndefine('BOT_PLATFORM', 'bale');\n${MOCK}\n${body}`);
}
buildDriver();

async function webhook(chatId, text) {
  php.writeFile('/harness/update.json', JSON.stringify({ message: { chat: { id: chatId }, text, from: { username: 'harness-user' } } }));
  php.writeFile('/harness/wire.json', '[]');
  const r = await run(`<?php require '/www/includes/__harness_webhook_bale.php';`);
  return { wire: JSON.parse(php.readFileAsText('/harness/wire.json')), out: r.out, err: r.err };
}
const linkedStudent = async (chatId) => {
  const r = await run(`<?php require_once '/www/includes/functions.php';
$row=DB::fetch("SELECT student_id FROM bale_bot_users WHERE bale_chat_id='${chatId}'");
echo 'LINK='.($row['student_id']??0);`);
  return r.out.trim();
};

/* a) only the 6-digit identity → asked for serial → linked (latest year) */
let w = await webhook('555001', '265486');
check(w.wire.some(x => x.text.includes('سریال')), 'the 6-digit identity passes step 1 and the serial is asked: ' + JSON.stringify(w.wire));
w = await webhook('555001', '654321');
check(await linkedStudent('555001') === 'LINK=7005', 'the mixed-format student is linked to the LATEST-year row');

/* b) the full typed form with the letter and the prefix */
w = await webhook('555002', '26ب265486');
check(w.wire.some(x => x.text.includes('سریال')), 'typing the full "26ب265486" form is accepted too');
w = await webhook('555002', '654321');
check(await linkedStudent('555002') === 'LINK=7005', 'the full-form parent is linked to the same student');

/* c) standard 10-digit regression */
w = await webhook('555003', '1234567890');
check(w.wire.some(x => x.text.includes('سریال')), 'standard 10-digit login still reaches the serial step');
w = await webhook('555003', '111222');
check(await linkedStudent('555003') === 'LINK=7001', 'the standard student links exactly as before');

/* d) v4.167.0: composite serial («ب/26/654987») — the 6-digit part is the password */
w = await webhook('555006', '9876543210');
check(w.wire.some(x => x.text.includes('سریال')), 'a clean 10-digit national id with a composite serial reaches the serial step');
w = await webhook('555006', '111111');
check(w.wire.some(x => x.text.includes('اشتباه است')), 'a wrong serial for the composite-serial student is still rejected');
w = await webhook('555006', '654987');
check(await linkedStudent('555006') === 'LINK=7006', 'the 6-digit part of the composite serial links the student (letters/prefix never matter)');

/* e) ambiguity and too-short inputs are rejected with the right messages */
w = await webhook('555004', '111111');
check(w.wire.some(x => x.text.includes('یافت نشد')), 'an ambiguous 6-digit identity answers "not found", never a wrong link');
w = await webhook('555005', '12345');
check(w.wire.some(x => x.text.includes('۱۰ رقمی')), 'a 5-digit input is rejected by the format gate: ' + JSON.stringify(w.wire));

console.log(`PASS ${checks} parent-identity link cases (6-digit identity, full form, 10-digit regression, ambiguity, gate)`);
process.exit(0);
