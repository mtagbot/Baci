// Real PHP/SQLite: «ورود مدیر از داخل ربات» — the manager types his own username
// where the bot asks for the national id, then his password, and his account is
// connected to that messenger (Bale and Telegram).
//
// php-wasm has no HTTP layer, so the webhook script is driven through the real
// engine source with exactly ONE line replaced: the request body is read from
// /harness/update.json instead of php://input. That replaced line is asserted to
// be byte-identical to the shipped one, so the driver can never drift away from
// production; every other line — the whole dispatch, the database writes and the
// durable outbox — is the shipped code. Only the two bot providers are mocked
// (exactly like the outbox suite), so the assertions describe the wire the
// school would actually see.
import assert from 'node:assert/strict';
import {php,run,db,loginAdmin} from './harness/lib.mjs';

const sid='testBotAdminLogin';
await loginAdmin(sid);
let checks=0;
const check=(v,m)=>{assert(v,m);checks++};
const fa=n=>String(n).replace(/\d/g,d=>'۰۱۲۳۴۵۶۷۸۹'[d]);

const ADMIN_USER='modir.baci';          // مدیر فعال
const ADMIN_PASS='Modir-1404!';
const ADMIN_OFF='modir.ghair';         // مدیر غیرفعال
const ADMIN_NUM='9000000001';          // نام کاربری ده‌رقمی مدیر

/* ── fixture ─────────────────────────────────────────────────────────────── */
const setup=await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/school_roles.php';
require_once '/www/includes/bot_helpers.php';
require_once '/www/includes/attendance_helpers.php';
foreach (['bale','telegram'] as $pf) ensure_bot_schema($pf);
ensure_school_roles_schema();
set_setting('current_academic_year','1404/1405');
set_setting('school_name','آموزشگاه آزمون'); set_setting('school_name_short','آزمون');
set_setting('bale_bot_token','fixture-token'); set_setting('telegram_bot_token','fixture-token');
set_setting('attendance_scanner_key','fixture-scanner-key');
set_setting('att_present_until','08:40'); set_setting('att_absent_at','09:00');
set_setting('att_notify_present','1'); set_setting('att_notify_late','1');
set_setting('att_auto_finalize','0'); set_setting('att_finalized_day', att_today());
DB::execute("DELETE FROM bot_admin_sessions"); DB::execute("DELETE FROM bot_login_tokens");
DB::execute("INSERT OR REPLACE INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year,serial_number) VALUES (7301,'9200000031','زهرا','احمدی','101','دهم','active','1404/1405','123456')");
DB::execute("INSERT OR REPLACE INTO teachers (id,national_id,full_name,password,status,academic_year,is_deputy,is_executive,is_counselor,personnel_code) VALUES (9601,'9200000041','معاون آزمون','hash',1,'1404/1405',1,0,0,'424242')");
DB::execute("INSERT OR REPLACE INTO admins (id,username,password,name,role,status) VALUES (9801,?,?,'مدیر آزمون','super_admin',1)",['${ADMIN_USER}','x']);
DB::execute("INSERT OR REPLACE INTO admins (id,username,password,name,role,status) VALUES (9802,?,?,'مدیر غیرفعال','super_admin',0)",['${ADMIN_OFF}','x']);
DB::execute("INSERT OR REPLACE INTO admins (id,username,password,name,role,status) VALUES (9803,?,?,'مدیر با نام کاربری عددی','super_admin',1)",['${ADMIN_NUM}','x']);
password_store_hash('admins', 9801, '${ADMIN_PASS}');
password_store_hash('admins', 9803, '${ADMIN_PASS}');
/* پیش از اصلاح: نام کاربری مدیر در مرحلهٔ کد ملی با پیام «۱۰ رقم» رد می‌شد. */
echo 'FIXTURE_OK';`);
check(setup.out.includes('FIXTURE_OK'),setup.out+setup.err);

/* ── driving the real webhook engine ─────────────────────────────────────── */
const INPUT_LINE = "$input = file_get_contents('php://input');";
const MOCK = `function bot_api_request($platform,$method,$data=[],$multipart=false){
 $wire=json_decode((string)@file_get_contents('/harness/wire.json'),true); if(!is_array($wire))$wire=[];
 $wire[]=['platform'=>$platform,'method'=>$method,'chat_id'=>(string)($data['chat_id']??''),'text'=>(string)($data['text']??'')];
 @file_put_contents('/harness/wire.json',json_encode($wire,JSON_UNESCAPED_UNICODE));
 return ['ok'=>true,'result'=>['message_id'=>count($wire)]];}`;

function buildDriver(platform){
  const src=php.readFileAsText('/www/includes/bot_webhook_engine.php');
  check(src.includes(INPUT_LINE),'the shipped engine still reads the request body from php://input');
  const body=src.replace(/^<\?php\r?\n/,'').replace(INPUT_LINE,"$input = @file_get_contents('/harness/update.json');");
  php.writeFile(`/www/includes/__harness_webhook_${platform}.php`,
    `<?php\ndefine('BOT_PLATFORM', '${platform}');\n${MOCK}\n${body}`);
}
buildDriver('bale'); buildDriver('telegram');

async function webhook(platform,chatId,text){
  php.writeFile('/harness/update.json',JSON.stringify({message:{chat:{id:chatId},text,from:{username:'harness-user'}}}));
  php.writeFile('/harness/wire.json','[]');
  const r=await run(`<?php require '/www/includes/__harness_webhook_${platform}.php';`);
  return {wire:JSON.parse(php.readFileAsText('/harness/wire.json')),out:r.out,err:r.err};
}
const texts=res=>res.wire.map(x=>x.text).join('\n---\n');
const said=(res,needle)=>res.wire.some(x=>x.text.includes(needle));
const state=async(platform,chatId)=>{
  const col=platform==='telegram'?'telegram_chat_id':'bale_chat_id';
  const table=platform==='telegram'?'telegram_bot_state':'bale_bot_state';
  return (await db(`SELECT * FROM ${table} WHERE ${col}='${chatId}'`))[0]||null;
};
const sessions=async()=>await db("SELECT platform,chat_id,admin_id,teacher_id,role_type,is_active FROM bot_admin_sessions ORDER BY id");
const teacherButton=await run(`<?php require_once '/www/includes/functions.php'; require_once '/www/includes/bot_helpers.php';
echo 'BTN=' . bot_buttons_map('bale')['main_teacher_login'];`);
const btnTeacher=teacherButton.out.split('BTN=')[1].trim();

/* ── ۱) بله: نام کاربری مدیر در مرحلهٔ کد ملی ────────────────────────────── */
const chat='700001';
let w=await webhook('bale',chat,ADMIN_USER);
check(said(w,'به عنوان مدیر آموزشگاه شناسایی شد'),'the manager username is recognised at the national-id step: '+texts(w).slice(0,160));
check(said(w,'رمز عبور مدیر را ارسال کنید'),'the bot asks for the manager password instead of the «10 digit» error');
check(!said(w,'۱۰ رقم'),'no national-id error is sent for a manager username');
check(w.wire.every(x=>x.chat_id===chat),'the answer goes to the same chat');
let st=await state('bale',chat);
check(st&&st.step==='admin_password','the conversation is waiting for the manager password');
check(st.temp_nid===ADMIN_USER,'only the username is kept in the state (never the password)');
check((await sessions()).length===0,'nothing is connected before the password is verified');

/* رمز اشتباه: سه تلاش، بعد بسته می‌شود */
for(const n of [1,2]){
  w=await webhook('bale',chat,'ramz-eshtebah-'+n);
  check(said(w,'نادرست'),'the wrong password is refused (attempt '+n+')');
  check(w.wire.some(x=>x.text.includes(fa(n))),'the refusal counts the attempt: '+fa(n));
  check((await state('bale',chat))?.temp_payload===String(n),'the attempt counter is stored');
  check((await sessions()).length===0,'a wrong password connects nothing');
}
w=await webhook('bale',chat,'ramz-eshtebah-3');
check(said(w,'بسته شد'),'after three wrong passwords the step is closed');
check(await state('bale',chat)===null,'the conversation state is cleared after the lockout');

/* رمز درست */
w=await webhook('bale',chat,ADMIN_USER);
check(said(w,'رمز عبور مدیر را ارسال کنید'),'the manager can start again after the lockout');
w=await webhook('bale',chat,ADMIN_PASS);
check(said(w,'ورود مدیر تأیید شد'),'the correct password is accepted: '+texts(w).slice(0,160));
check(said(w,'تگ آزمایشی'),'the confirmation tells the manager test-tag messages arrive here');
check(/bot-login\.php\?token=/.test(texts(w)),'the secure admin panel link is issued');
check(await state('bale',chat)===null,'the conversation state is cleared after the login');
let s=await sessions();
check(s.length===1,'exactly one bot session was created');
check(s[0].platform==='bale'&&s[0].chat_id===chat&&s[0].role_type==='admin'&&s[0].is_active===1,
      'the manager account is connected to Bale with role_type=admin');
check(Number(s[0].admin_id)===9801,'the session points at the manager row');
check((await db("SELECT COUNT(*) n FROM bot_login_tokens WHERE target_type='admin' AND target_id=9801"))[0].n===1,
      'the login token belongs to the manager account');

/* /start دست‌نخورده است */
w=await webhook('bale','700009','/start');
check(said(w,'به ربات رسمی آموزشگاه خوش آمدید'),'the parent welcome still works');
check((await state('bale','700009')).step==='awaiting_nid','/start still begins with the national-id step');

/* ── ۲) تلگرام: همان ورود، حساب مستقل روی تلگرام ─────────────────────────── */
w=await webhook('telegram','700002',ADMIN_USER);
check(said(w,'به عنوان مدیر آموزشگاه شناسایی شد'),'Telegram recognises the manager username too');
w=await webhook('telegram','700002',ADMIN_PASS);
check(said(w,'ورود مدیر تأیید شد'),'Telegram accepts the manager password');
check(said(w,'تلگرام'),'the Telegram confirmation names its own messenger');
s=await sessions();
check(s.length===2&&s[1].platform==='telegram'&&s[1].chat_id==='700002'&&s[1].role_type==='admin',
      'the Telegram chat gets its own admin session');

/* ورود دوباره: یک حساب، یک ردیف فعال */
w=await webhook('bale',chat,ADMIN_USER);
w=await webhook('bale',chat,ADMIN_PASS);
s=await sessions();
check(s.filter(r=>r.platform==='bale'&&r.chat_id===chat&&r.is_active===1).length===1,
      'logging in again leaves exactly one active session for that chat');
check(s.some(r=>r.platform==='bale'&&r.chat_id===chat&&r.is_active===0),
      'the previous session of the same chat was deactivated, not duplicated');

/* ── ۳) نقش‌های دیگر دست‌نخورده ──────────────────────────────────────────── */
w=await webhook('bale','700003','9200000031');
check(said(w,'سریال'),'a student national id still asks for the serial');
check((await state('bale','700003')).step==='awaiting_serial','the student step is unchanged');
w=await webhook('bale','700004','9200000041');
check(said(w,'کد پرسنلی'),'a teacher national id still asks for the personnel code');
check((await state('bale','700004')).step==='staff_personnel_code','the staff step is unchanged');
w=await webhook('bale','700005','salam');
check(said(w,'کد ملی ۱۰ رقمی (یا شناسهٔ ۶ رقمی پرونده)'),'a random text still gets the national-id error');
check(said(w,'نام کاربری مدیریت خود را ارسال کنید'),'the error now guides the manager to his username');
w=await webhook('bale','700006',ADMIN_OFF);
check(!said(w,'رمز عبور مدیر را ارسال کنید'),'a disabled manager account can never start a login');
check(said(w,'کد ملی ۱۰ رقمی (یا شناسهٔ ۶ رقمی پرونده)'),'the disabled username gets the normal error');
w=await webhook('bale','700007',ADMIN_NUM);
check(said(w,'رمز عبور مدیر را ارسال کنید'),'a ten-digit manager username is recognised after the student lookup');
check(!said(w,'دانش‌آموزی با این کد ملی'),'the student-not-found message is not sent for a manager username');
w=await webhook('bale','700007',ADMIN_PASS);
check(said(w,'ورود مدیر تأیید شد'),'the ten-digit manager username logs in as well');

/* نام کاربری مدیر در مرحلهٔ دبیر و در مرحلهٔ سریال */
w=await webhook('bale','700008',btnTeacher);
check(said(w,'کد ملی/نام کاربری دبیر'),'the teacher button still asks for the teacher id');
w=await webhook('bale','700008',ADMIN_USER);
check(said(w,'رمز عبور مدیر را ارسال کنید'),'the teacher entry accepts the manager username');
w=await webhook('bale','700010','9200000031');
check(said(w,'سریال'),'student identified, serial asked');
w=await webhook('bale','700010',ADMIN_USER);
check(said(w,'رمز عبور مدیر را ارسال کنید'),'the serial step falls back to the manager login');
check(said(w,'مدیر آموزشگاه شناسایی شد'),'the fallback announces the manager role');

/* ── ۴) ورود مخفی پیشین دست‌نخورده ───────────────────────────────────────── */
const secret=(await run(`<?php require_once '/www/includes/functions.php'; set_setting('admin_bot_login_secret','harnesssecret1234'); echo 'S=harnesssecret1234';`)).out.includes('harnesssecret1234');
check(secret,'the hidden admin secret is set for this test');
w=await webhook('bale','700011','/start admin_harnesssecret1234');
check(said(w,'نام کاربری مدیر را ارسال کنید'),'the hidden /start admin_SECRET entry still works');
w=await webhook('bale','700011',ADMIN_USER);
check(said(w,'رمز عبور مدیر را ارسال کنید'),'the hidden entry asks for the password after a valid username');
w=await webhook('bale','700011',ADMIN_PASS);
check(said(w,'ورود مدیر تأیید شد'),'the hidden entry still completes the manager login');
w=await webhook('bale','700012','/start admin_harnesssecret1234');
w=await webhook('bale','700012',ADMIN_OFF);
check(said(w,'مدیر فعالی با این نام کاربری پیدا نشد'),'the hidden entry rejects a disabled manager username');
w=await webhook('bale','700013','/start admin_badsecret9999');
check(said(w,'لینک ورود مدیریتی نامعتبر است'),'a wrong secret is refused');

/* ── ۵) نتیجه: پیام «تگ آزمایشی» به همین حساب می‌رسد ─────────────────────── */
await run(`<?php require_once '/www/includes/functions.php'; require_once '/www/includes/school_roles.php';
DB::execute("INSERT INTO bot_admin_sessions (platform,chat_id,teacher_id,role_type,is_active,created_at_jalali) VALUES ('bale','700020',9601,'teacher',1,'1404/06/29')");
echo 'DEPUTY_LINKED';`);
php.writeFile('/harness/wire.json','[]');
const scan=await run(`<?php
@mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess'); session_name('BACI_TEST'); session_id('${sid}'); session_start();
$_SERVER['REQUEST_METHOD']='POST'; $_SERVER['REQUEST_URI']='/attendance-scan-api.php'; $_SERVER['HTTP_HOST']='localhost';
$_POST=['action'=>'scan','payload'=>'MTAG-ATT-TEST:present','key'=>'fixture-scanner-key'];
${MOCK}
require '/www/attendance-scan-api.php';`);
/* صف ماندگار در پایان هر درخواست فقط سه پیام می‌فرستد؛ برای دیدن کل نتیجه
   صف را کامل تخلیه می‌کنیم (همان مسیر تولیدی). */
const drain=await run(`<?php ${MOCK}
require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
echo 'DRAINED=' . bot_outbox_drain(50, 20);`);
check(drain.out.includes('DRAINED='),drain.out+drain.err);
const scanWire=JSON.parse(php.readFileAsText('/harness/wire.json'));
const recipients=scanWire.filter(x=>x.text.includes('آزمون سامانه')).map(x=>x.platform+':'+x.chat_id).sort();
const activeManagerChats=(await sessions()).filter(r=>r.role_type==='admin'&&r.is_active===1)
                          .map(r=>r.platform+':'+r.chat_id).sort();
check(recipients.length>0&&recipients.join(',')===activeManagerChats.join(','),
      'the test-tag message reaches exactly the manager accounts connected through the bot: '+recipients.join(', '));
check(recipients.includes('bale:'+chat),'the manager chat that logged in through the bot receives it');
check(!recipients.includes('bale:700020'),
      'the connected deputy chat receives nothing (manager-only rule)');
check(scan.out.includes('"ok":true')||scan.out.includes('"ok": true'),'the scan endpoint answered the test tag');

console.log(`PASS ${checks} bot admin-login cases (manager username → password → connected account)`);
process.exit(0);
