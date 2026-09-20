// Real PHP/SQLite: the printed «تگ آزمایشی» sheet and the whole
// scan → attendance core → management test-message path (Bale + Telegram).
//
// The scan endpoint is executed for real (auth, JSON emit, deferred queue and the
// durable outbox). Only the two bot providers are mocked, exactly like the outbox
// suite, so the assertions describe the wire the school would see.
import assert from 'node:assert/strict';
import {php,run,req,db,loginAdmin} from './harness/lib.mjs';

const sid='testTagAdmin';
await loginAdmin(sid);
let checks=0;
const check=(v,m)=>{assert(v,m);checks++};
const fa=n=>String(n).replace(/\d/g,d=>'۰۱۲۳۴۵۶۷۸۹'[d]);

/* ── fixture ────────────────────────────────────────────────────────────────
   Two active students of the default year, parent chats for both messengers and
   manager accounts connected through the bot (one per messenger) — plus parents,
   a deputy, a plain teacher, an INACTIVE session and a disabled manager account
   that must never receive anything.
   att_auto_finalize is paused and today's finalizer guard is set: the scan
   endpoint's opportunistic auto-absent run must not blur "the test tag changed
   nothing" (that run has its own suite). */
const setup=await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/attendance_helpers.php';
set_setting('current_academic_year','1404/1405');
set_setting('school_name','آموزشگاه آزمون');
set_setting('school_name_short','آزمون');
set_setting('attendance_scanner_key','fixture-scanner-key');
set_setting('att_present_until','08:40'); set_setting('att_absent_at','09:00');
set_setting('att_notify_present','1'); set_setting('att_notify_late','1');
set_setting('att_auto_finalize','0');
set_setting('att_finalized_day',att_today());
set_setting('bale_bot_token','fixture-token'); set_setting('telegram_bot_token','fixture-token');
DB::execute("UPDATE students SET status='inactive'");
foreach ([[7201,'9200000001','زهرا','احمدی','101','دهم','active','1404/1405'],
          [7202,'9200000002','مهدی','کریمی','102','یازدهم','active','1404/1405']] as $s)
    DB::execute('INSERT OR REPLACE INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year) VALUES (?,?,?,?,?,?,?,?)',$s);
ensure_bot_schema('bale'); ensure_bot_schema('telegram');
foreach ([['bale','8101','bale_chat_id'],['telegram','8102','telegram_chat_id']] as $p) {
    $table=bot_user_table($p[0]);
    DB::execute("INSERT INTO \`$table\` (\`{$p[2]}\`,student_id) VALUES (?,?)",[$p[1],7201]);
}
$adminRow=DB::fetch('SELECT id FROM admins ORDER BY id LIMIT 1'); $adminId=(int)($adminRow['id']??1);
DB::execute("INSERT OR REPLACE INTO admins (id,username,password,name,role,status) VALUES (9701,'fixture-manager-two','x','مدیر دوم','super_admin',1)");
DB::execute("INSERT OR REPLACE INTO admins (id,username,password,name,role,status) VALUES (9702,'fixture-manager-off','x','مدیر غیرفعال','super_admin',0)");
DB::execute("INSERT OR REPLACE INTO teachers (id,national_id,full_name,password,status,academic_year,is_deputy,is_executive,is_counselor) VALUES (9501,'teacher9501','معاون آزمون','hash',1,'1404/1405',1,0,0)");
DB::execute("INSERT OR REPLACE INTO teachers (id,national_id,full_name,password,status,academic_year,is_deputy,is_executive,is_counselor) VALUES (9502,'teacher9502','دبیر ساده','hash',1,'1404/1405',0,0,0)");
/* Manager accounts registered through the bot (username+password) — the ONLY recipients. */
DB::execute("INSERT INTO bot_admin_sessions (platform,chat_id,admin_id,role_type,is_active,created_at_jalali) VALUES ('bale','9001',?,'admin',1,'1404/06/29')",[$adminId]);
DB::execute("INSERT INTO bot_admin_sessions (platform,chat_id,admin_id,role_type,is_active,created_at_jalali) VALUES ('telegram','9002',9701,'admin',1,'1404/06/29')");
/* Everything else must stay silent: a deputy on Bale, a plain teacher on Telegram,
   an inactive session and a disabled manager account. */
DB::execute("INSERT INTO bot_admin_sessions (platform,chat_id,teacher_id,role_type,is_active,created_at_jalali) VALUES ('bale','9003',9501,'teacher',1,'1404/06/29')");
DB::execute("INSERT INTO bot_admin_sessions (platform,chat_id,teacher_id,role_type,is_active,created_at_jalali) VALUES ('bale','9004',9501,'teacher',0,'1404/06/29')");
DB::execute("INSERT INTO bot_admin_sessions (platform,chat_id,teacher_id,role_type,is_active,created_at_jalali) VALUES ('telegram','9005',9502,'teacher',1,'1404/06/29')");
DB::execute("INSERT INTO bot_admin_sessions (platform,chat_id,admin_id,role_type,is_active,created_at_jalali) VALUES ('telegram','9006',9702,'admin',1,'1404/06/29')");
echo 'FIXTURE_OK';`);
assert(setup.out.includes('FIXTURE_OK'),setup.out+setup.err);

/* ── provider mock + scan endpoint execution ─────────────────────────────── */
const mock=`function bot_api_request($platform,$method,$data=[],$multipart=false){
 $wire=json_decode((string)@file_get_contents('/harness/wire.json'),true); if(!is_array($wire))$wire=[];
 $wire[]=['platform'=>$platform,'method'=>$method,'chat_id'=>(string)($data['chat_id']??''),'text'=>(string)($data['text']??'')];
 @file_put_contents('/harness/wire.json',json_encode($wire,JSON_UNESCAPED_UNICODE));
 return ['ok'=>true,'result'=>['message_id'=>count($wire)]];
}`;
php.writeFile('/harness/wire.json','[]');
const wire=()=>JSON.parse(php.readFileAsText('/harness/wire.json'));
const clearWire=()=>php.writeFile('/harness/wire.json','[]');

async function scan(payload,key='fixture-scanner-key'){
  const r=await run(`<?php
@mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess'); session_name('BACI_TEST'); session_id('${sid}'); session_start();
$_SERVER['REQUEST_METHOD']='POST'; $_SERVER['REQUEST_URI']='/attendance-scan-api.php'; $_SERVER['HTTP_HOST']='localhost';
$_POST=['action'=>'scan','payload'=>${JSON.stringify(payload)},'key'=>${JSON.stringify(key)}];
${mock}
require '/www/attendance-scan-api.php';`);
  const m=r.out.match(/\{[\s\S]*\}/);
  let json=null; try{json=m?JSON.parse(m[0]):null}catch(e){}
  return {json,raw:r.out,err:r.err};
}

/* ── 1) the printed test sheet ───────────────────────────────────────────── */
async function render(file,query=''){
  const {res,stderr}=await req('',{file,query,sid});
  assert(!res.fatal,res.fatal);assert(!stderr,stderr);assert(!res.php_issues,res.php_issues);
  return res.page;
}
const qrList=html=>[...html.matchAll(/<canvas\b[^>]*data-qr="([^"]+)"/g)].map(m=>m[1]);

const sheet=await render('attendance-tags.php','print=1&test=1');
check(JSON.stringify(qrList(sheet))===JSON.stringify(['MTAG-ATT-TEST:present','MTAG-ATT-TEST:late']),
      'the test sheet prints exactly the two test payloads: '+qrList(sheet).join(','));
check(sheet.includes('حضور به موقع')&&sheet.includes('تأخیر'),'both tag names are printed');
check(sheet.includes('تگ آزمایشی'),'the tag caption marks it as a test tag');
check(sheet.includes('بدون ثبت حضور و غیاب'),'the printed tag says nothing is recorded');
check(!sheet.includes('احمدی')&&!sheet.includes('کریمی')&&!sheet.includes('MTAG-ATT:7201'),
      'no student and no real token appears on the test sheet');
check(sheet.includes('برگهٔ آزمایشی'),'the print toolbar names the test sheet');

const many=await render('attendance-tags.php','print=1&test=1&copies=3');
check(JSON.stringify(qrList(many))===JSON.stringify(['MTAG-ATT-TEST:present','MTAG-ATT-TEST:present','MTAG-ATT-TEST:present',
      'MTAG-ATT-TEST:late','MTAG-ATT-TEST:late','MTAG-ATT-TEST:late']),
      'copies repeat the two test tags (each tag copies consecutively): '+qrList(many).join(','));
check(many.includes('تعداد تگ: '+fa(6)),'the test toolbar counts the test tags');

const normal=await render('attendance-tags.php','print=1');
check(qrList(normal).length===2&&!qrList(normal).some(q=>q.includes('TEST')),
      'the normal student sheet is unchanged (2 real tags, no test payload)');

const page=await render('attendance-tags.php');
check(/تگ آزمایشی/.test(page)&&/openTestPrint\(\)/.test(page),'the tag page offers the «تگ آزمایشی» option');
check(page.includes('حساب مدیریت:')&&page.includes('حساب مدیریت متصل'),'the page shows which messengers have a manager account connected');
check(!/آخرین آزمون/.test(page),'no test has run yet, so no result line is shown');

/* ── 2) scanning the test tags: real endpoint, no attendance, both bots ──── */
const attendanceBefore=(await db('SELECT COUNT(*) n FROM student_attendance'))[0].n;
const tagsBefore=(await db('SELECT COUNT(*) n FROM student_qr_tags'))[0].n;
clearWire();

const present=await scan('MTAG-ATT-TEST:present');
check(present.json&&present.json.ok===true,'the real scan endpoint accepts the present test tag: '+present.raw.slice(0,200));
check(present.json.code==='present'&&present.json.test===true,'the kiosk gets the present test result');
check(String(present.json.student).includes('حضور به موقع'),'the kiosk shows «حضور به موقع» as the name');
check(String(present.json.class).includes('بدون ثبت حضور و غیاب'),'the kiosk states that nothing is recorded');
check(String(present.json.time).length>0&&/^[۰-۹0-9:]+$/.test(String(present.json.time)),'a real clock time is reported');

let w=wire();
check(w.length===2,'one message per messenger after the first test scan');
check(w.filter(x=>x.platform==='bale').length===1&&w.filter(x=>x.platform==='telegram').length===1,
      'Bale AND Telegram both received it');
check(w.filter(x=>x.platform==='bale')[0].chat_id==='9001','Bale goes to the manager account connected through the bot');
check(w.filter(x=>x.platform==='telegram')[0].chat_id==='9002','Telegram goes to the manager account connected through the bot');
check(w.every(x=>!['8101','8102','9003','9004','9005','9006'].includes(x.chat_id)),
      'no parent, teacher, deputy, inactive session or disabled manager receives the test message');
check(w.every(x=>x.text.includes('آزمایشی')&&x.text.includes('آزمون سامانه')),
      'every message is the attendance test message');
check(w.every(x=>x.text.includes('حساب مدیریت')),
      'every message states it is for the connected manager account only');
check(w.every(x=>x.text.includes('حضور به موقع')),'the message carries the simulated status');
check(w.filter(x=>x.platform==='bale')[0].text.includes('بله')&&
      w.filter(x=>x.platform==='telegram')[0].text.includes('تلگرام'),
      'each message names the messenger it was sent through');

const late=await scan('MTAG-ATT-TEST:late');
check(late.json&&late.json.ok===true&&late.json.code==='late','the late test tag is accepted as a late result');
check(String(late.json.status).includes('تأخیر'),'the late result reports tardiness');
w=wire();
check(w.length===4,'two scans produced two messages per messenger');
const lateTexts=w.filter(x=>x.text.includes('تأخیر'));
check(lateTexts.length===2,'the late message reaches both messengers');
check(lateTexts.every(x=>/فرزند شما امروز با [۰-۹0-9]+ دقیقه تأخیر/.test(x.text)),
      'the late message is the same wording parents receive, minutes included');

check((await db('SELECT COUNT(*) n FROM student_attendance'))[0].n===attendanceBefore,
      'the test tags wrote NO attendance row');
check((await db('SELECT COUNT(*) n FROM student_qr_tags'))[0].n===tagsBefore,
      'the test sheet created no tag/token');
check((await db("SELECT COUNT(*) n FROM student_attendance WHERE student_id IN (7201,7202)"))[0].n===0,
      'no attendance for the fixture students either');
const jobs=await db('SELECT platform,state,payload FROM bot_outbox ORDER BY created_at');
check(jobs.length===4&&jobs.every(j=>j.state==='sent'),'all four test messages were delivered by the durable outbox');
check(jobs.every(j=>['9001','9002'].includes(JSON.parse(j.payload).chat_id)),
      'the outbox only ever targets the two manager chats');
const logs=await db("SELECT status,chat_id FROM bot_message_logs WHERE message_type='attendance_test'");
check(logs.length===4&&logs.every(l=>l.status==='sent'),'the test messages are logged truthfully');

/* ── 3) the page reports the outcome, so the school can verify ───────────── */
const after=await render('attendance-tags.php');
check(/آخرین آزمون/.test(after),'the tag page shows the last test result');
check(after.includes('بله: '+fa(1)+' پیام')&&after.includes('تلگرام: '+fa(1)+' پیام'),
      'the reported counts match the two platforms');

/* ── 4) invalid test payloads send nothing ──────────────────────────────── */
clearWire();
for(const bad of ['MTAG-ATT-TEST:','MTAG-ATT-TEST:bogus','MTAG-ATT-TEST:late:1','MTAG-ATT-TEST:7201','MTAG-ATT-TEST:present!']){
  const r=await scan(bad);
  check(r.json&&r.json.ok===false&&r.json.code==='invalid','rejected: '+JSON.stringify(bad));
}
check(wire().length===0,'an invalid test payload notifies nobody');
check((await db('SELECT COUNT(*) n FROM student_attendance'))[0].n===attendanceBefore,
      'an invalid test payload writes nothing');

/* ── 5) the real tag path is untouched ──────────────────────────────────── */
clearWire();
const real=await run(`<?php ${mock}
require_once '/www/includes/functions.php';
require_once '/www/includes/attendance_helpers.php';
$tag=DB::fetch("SELECT token FROM student_qr_tags WHERE student_id=7201");
if(!$tag){ $tag=att_get_or_create_tag(7201); }
$payload=att_qr_payload(7201,$tag['token']);
$r=att_process_scan($payload);
att_run_deferred();
echo 'RESULT'.json_encode($r,JSON_UNESCAPED_UNICODE);`);
check(real.out.includes('RESULT'),real.out+real.err);
const realJson=JSON.parse(real.out.slice(real.out.indexOf('RESULT')+6));
check(realJson.ok===true&&['present','late'].includes(realJson.code),'a real student tag still registers: '+realJson.code);
check((await db('SELECT COUNT(*) n FROM student_attendance WHERE student_id=7201'))[0].n===1,
      'the real scan still writes exactly one attendance row');
check(realJson.student!==undefined&&!/آزمایشی/.test(String(realJson.student)),'the real result is not a test result');
const realWire=wire();
check(realWire.length>0&&realWire.every(x=>!x.text.includes('آزمون سامانه')),
      'the real scan sends parent notifications, never the test message');
check(realWire.some(x=>['8101','8102'].includes(x.chat_id)),'the parent of the scanned student was notified as before');
check((await db("SELECT COUNT(*) n FROM bot_message_logs WHERE message_type='attendance_test'"))[0].n===4,
      'the real scan logged no test message');

/* ── 6) no manager account connected → nobody gets anything (no fallback) ─ */
clearWire();
const off=await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/attendance_helpers.php';
DB::execute("UPDATE bot_admin_sessions SET is_active=0 WHERE role_type='admin'");
echo 'ADMINS_OFF';`);
check(off.out.includes('ADMINS_OFF'),off.out+off.err);
const outboxBefore=(await db('SELECT COUNT(*) n FROM bot_outbox'))[0].n;
const orphan=await scan('MTAG-ATT-TEST:present');
check(orphan.json&&orphan.json.ok===true,'the test tag still answers the scanner when no manager is connected');
check(wire().length===0,'without a connected manager account nothing is sent to anyone — no fallback to teachers, deputies or parents');
check((await db("SELECT COUNT(*) n FROM bot_message_logs WHERE message_type='attendance_test'"))[0].n===4,
      'no extra test message was logged');
check((await db('SELECT COUNT(*) n FROM bot_outbox'))[0].n===outboxBefore,'no extra outbox job was queued');
const noMgr=await render('attendance-tags.php');
check(noMgr.includes('بدون حساب مدیریت متصل'),'the page says no manager account is connected');
check(/آخرین آزمون[^<]*بدون حساب مدیریت متصل/.test(noMgr),'the last-test line reports that nothing was delivered');

console.log(`PASS ${checks} attendance test-tag cases (sheet, scan path, manager-only messages, no recording)`);
process.exit(0);
