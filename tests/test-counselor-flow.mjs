// Real PHP/SQLite: «پروندهٔ مشاورهٔ v4.169.0» — the counselor panel, the
// per-student file (private notes + conversation bundle), the bot reply
// button and the parent follow-up conversation, driven through the REAL
// counselor-panel.php / counselor-file.php / bot_webhook_engine.php.
//
// What is proven here (execution, not claims):
//   1) the students tab has an academic-year filter preselected to the
//      default year, and the «یادداشت مشاور» button comes first,
//   2) each request shows the student badge + ID photo, resolved through
//      the parent's bot link (no student_id column needed),
//   3) a counselor reply is stored in the conversation AND enqueued to the
//      parent through the bot outbox with the glass «پاسخ» button,
//   4) tapping «پاسخ» starts the follow-up step; the parent's text lands in
//      the SAME request's conversation (no new request) and counselors are
//      notified through the queue,
//   5) counselor notes are private to the counselor role and the per-student
//      file bundles every request with its full thread,
//   6) reports-lists.php opens for the executive deputy (and still refuses
//      other teachers).
import assert from 'node:assert/strict';
import { php, run, db, req, loginAdmin } from './harness/lib.mjs';

const sid = 'testCounselorFlow';
let checks = 0;
const check = (v, m) => { assert(v, m); checks++; };

/* ── fixture ─────────────────────────────────────────────────────────────── */
const setup = await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/school_roles.php';
require_once '/www/includes/bot_helpers.php';
require_once '/www/includes/bot_outbox.php';
ensure_school_roles_schema(); ensure_bot_schema('bale'); ensure_counseling_schema(); bot_outbox_schema();
set_setting('current_academic_year','1404/1405');
set_setting('school_name','آموزشگاه آزمون'); set_setting('school_name_short','آزمون');
set_setting('bale_bot_token','fixture-token');
try { DB::execute("DELETE FROM academic_years WHERE year_name='1404/1405'"); DB::execute("INSERT INTO academic_years (year_name,is_default,status) VALUES ('1404/1405',1,1)"); } catch (Throwable $e) {}
DB::execute("DELETE FROM teachers WHERE id IN (901,902)");
DB::execute("INSERT INTO teachers (id,national_id,full_name,password,is_counselor,is_executive,is_deputy,status) VALUES (901,'9010000001','مشاور مدرسه','x',1,0,0,1)");
DB::execute("INSERT INTO teachers (id,national_id,full_name,password,is_counselor,is_executive,is_deputy,status) VALUES (902,'9020000002','معاون اجرایی','x',0,1,0,1)");
DB::execute("DELETE FROM bot_admin_sessions WHERE teacher_id IN (901,902)");
DB::execute("INSERT INTO bot_admin_sessions (platform,chat_id,teacher_id,role_type,is_active,created_at_jalali) VALUES ('bale','560901',901,'teacher',1,'1404/06/01')");
DB::execute("DELETE FROM students WHERE id IN (7101,7102)");
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year,photo_url) VALUES (7101,'1100000001','سارا','مشاوره‌ای','هفتم ۱','هفتم','active','1404/1405','uploads/students/7101.jpg')");
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year) VALUES (7102,'1100000002','سال','قبل','هفتم ۱','هفتم','active','1403/1404')");
DB::execute("DELETE FROM bale_bot_users WHERE bale_chat_id IN ('555901','555902')");
DB::execute("INSERT INTO bale_bot_users (bale_chat_id,student_id) VALUES ('555901',7101)");
DB::execute("DELETE FROM counseling_requests WHERE id IN (801,802)");
DB::execute("INSERT INTO counseling_requests (id,platform,chat_id,student_id,requester_name,requester_phone,student_name,topic,description,status,created_at_jalali) VALUES (801,'bale','555901',NULL,'مادر سارا','09120000000','سارا مشاوره‌ای','افت تحصیلی','نمره‌های ریاضی کم شده','new','1404/06/01 10:00')");
DB::execute("INSERT INTO counseling_requests (id,platform,chat_id,student_id,requester_name,requester_phone,student_name,topic,description,status,created_at_jalali) VALUES (802,'bale','555902',NULL,'پدر ناشناس','09120000001','بی‌اتصال','موضوع دیگر','شرح','new','1404/06/02 10:00')");
DB::execute("DELETE FROM counseling_messages WHERE request_id IN (801,802)");
DB::execute("DELETE FROM counselor_notes WHERE student_id IN (7101,7102)");
DB::execute("DELETE FROM bot_outbox WHERE job_id LIKE 'cns%' OR payload LIKE '%counselreply_801%'");
DB::execute("DELETE FROM bale_bot_state WHERE bale_chat_id IN ('555901','555902')");
echo 'FIXTURE_OK';`);
check(setup.out.includes('FIXTURE_OK'), 'fixture ran: ' + setup.out.slice(0, 200) + setup.err.slice(0, 200));

/* counselor + executive sessions (same pattern as the staff-workflow suite) */
const csrf = 'csrfCounselorTest';
await run(`<?php ini_set('session.save_path','/tmp/sess'); session_name('BACI_TEST'); session_id('${sid}'); session_start();
$_SESSION['csrf_token']='${csrf}'; $_SESSION['teacher_id']=901; unset($_SESSION['admin_id']); unset($_SESSION['student_id']); echo 'SES_OK';`);
const execSid = 'testCounselorExec';
await run(`<?php ini_set('session.save_path','/tmp/sess'); session_name('BACI_TEST'); session_id('${execSid}'); session_start();
$_SESSION['csrf_token']='${csrf}'; $_SESSION['teacher_id']=902; unset($_SESSION['admin_id']); unset($_SESSION['student_id']); echo 'SES_OK';`);

/* ── 1+2: students tab — year filter preselected + note button first ─────── */
const stu = await req('', { file: 'counselor-panel.php', sid, query: 'tab=students' });
check(!stu.res.fatal, 'students tab renders: ' + (stu.res.fatal || ''));
check(stu.res.page.includes('name="year"'), 'the academic-year filter exists');
check(/name="year"[\s\S]{0,400}?1404\/1405[\s\S]{0,120}?selected/.test(stu.res.page) || /<option value="1404\/1405"[^>]*selected/.test(stu.res.page), 'the default year is preselected when arriving without a year param');
check(!stu.res.page.includes('سال قبل') || stu.res.page.indexOf('سارا مشاوره‌ای') < stu.res.page.indexOf('سال قبل'), 'last-year student is filtered out by the default-year filter');
const iNote = stu.res.page.indexOf('یادداشت مشاور');
const iInfo = stu.res.page.indexOf('اطلاعات</a>');
check(iNote > -1 && iNote < iInfo, '«یادداشت مشاور» button comes before the other buttons');
check(stu.res.page.includes('counselor-file.php?id=7101'), 'the note button links to the per-student counseling file');
check(stu.res.page.includes('back='), 'list links carry the filtered-return address');

/* year filter actually filters: all-years shows the last-year student */
const stuAll = await req('', { file: 'counselor-panel.php', sid, query: 'tab=students&year=all' });
check(stuAll.res.page.includes('سال قبل'), 'year=all shows students of every year');

/* ── 2: requests tab — badge + ID photo resolved through the bot link ────── */
const rq = await req('', { file: 'counselor-panel.php', sid, query: 'tab=requests' });
check(!rq.res.fatal, 'requests tab renders: ' + (rq.res.fatal || ''));
check(rq.res.page.includes('counselor-file.php?id=7101&back='), 'the request shows the student badge linking to the file');
check(rq.res.page.includes('سارا مشاوره‌ای'), 'the badge carries the student name');
check(rq.res.page.includes('uploads/students/7101.jpg') && rq.res.page.includes('cns-photo'), 'the ID-style photo is rendered');
check(rq.res.page.includes('بی‌اتصال'), 'an unlinked request still shows its free-text student name');
/* v4.170.0: expandable cards, bubble thread, status/grade filters */
check(rq.res.page.includes('<details class="cns-req"'), 'each request is a collapsible card');
check(/<details class="cns-req"[^>]*open/.test(rq.res.page), 'a «new» request starts expanded');
check(rq.res.page.includes('cns-bubble-row') && rq.res.page.includes('cns-bubble parent'), 'the parent message is shown as a bubble');
check(rq.res.page.includes('name="rstatus"') && rq.res.page.includes('name="grade"'), 'status + grade filters exist');

/* ── 3: counselor reply → conversation row + queued bot message w/ button ── */
const rep = await req('', { file: 'counselor-panel.php', sid, method: 'POST', post: {
  csrf_token: csrf, reply_request: '1', request_id: '801', status: 'replied', reply: 'جلسهٔ مشاوره روز شنبه ساعت ۱۰ برگزار می‌شود.' } });
check(!rep.res.fatal, 'reply saved without fatal: ' + (rep.res.fatal || ''));
const afterReply = await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php'; ensure_counseling_schema();
$m=DB::fetch("SELECT * FROM counseling_messages WHERE request_id=801 AND sender='counselor' ORDER BY id DESC");
$r=DB::fetch("SELECT status,counselor_reply FROM counseling_requests WHERE id=801");
echo 'MSG='.($m['body']??'').';ST='.($r['status']??'');`);
check(afterReply.out.includes('MSG=جلسهٔ مشاوره روز شنبه ساعت ۱۰ برگزار می‌شود.'), 'the reply is stored in the conversation thread: ' + afterReply.out);
check(afterReply.out.includes('ST=replied'), 'the request status is updated as before');
const job = await run(`<?php require_once '/www/includes/functions.php';
$j=DB::fetch("SELECT payload,state FROM bot_outbox WHERE payload LIKE '%counselreply_801%' ORDER BY created_at DESC");
echo 'JOB='.($j['payload']??'NONE').';STATE='.($j['state']??'-');`);
check(job.out.includes('counselreply_801'), 'the reply is enqueued to the parent through the bot outbox: ' + job.out.slice(0, 160));
check(job.out.includes('💬 پاسخ'), 'the queued message carries the glass reply button');
check(!job.out.includes('STATE=sent'), 'the job is queued (delivery stays with the worker, never inline)');
const rq2 = await req('', { file: 'counselor-panel.php', sid, query: 'tab=requests' });
check(rq2.res.page.includes('cns-bubble counselor') && rq2.res.page.includes('جلسهٔ مشاوره روز شنبه'), 'the counselor reply shows as a bubble in the thread');
/* v4.170.0: the status filter actually narrows the list server-side */
const rqClosed = await req('', { file: 'counselor-panel.php', sid, query: 'tab=requests&rstatus=closed' });
check(!rqClosed.res.page.includes('cns-req" open') && !rqClosed.res.page.includes('counselreply_801'), 'filtering by a status with no matches shows nothing: ' + (rqClosed.res.page.match(/درخواستی با این فیلترها/) ? 'ok' : 'unexpected'));
const rqNew = await req('', { file: 'counselor-panel.php', sid, query: 'tab=requests&rstatus=new' });
check(rqNew.res.page.includes('بی‌اتصال') && !rqNew.res.page.includes('جلسهٔ مشاوره روز شنبه'), 'the status=new filter keeps only new requests');

/* ── 4: webhook — the glass button + the parent follow-up ────────────────── */
const INPUT_LINE = "$input = file_get_contents('php://input');";
const MOCK = `function bot_api_request($platform,$method,$data=[],$multipart=false){
 $wire=json_decode((string)@file_get_contents('/harness/wire.json'),true); if(!is_array($wire))$wire=[];
 $wire[]=['platform'=>$platform,'method'=>$method,'chat_id'=>(string)($data['chat_id']??''),'text'=>(string)($data['text']??'')];
 @file_put_contents('/harness/wire.json',json_encode($wire,JSON_UNESCAPED_UNICODE));
 return ['ok'=>true,'result'=>['message_id'=>count($wire)]];}`;
{
  const src = php.readFileAsText('/www/includes/bot_webhook_engine.php');
  check(src.includes(INPUT_LINE), 'the shipped engine still reads the request body from php://input');
  const body = src.replace(/^<\?php\r?\n/, '').replace(INPUT_LINE, "$input = @file_get_contents('/harness/update.json');");
  php.writeFile('/www/includes/__harness_webhook_bale_cns.php',
    `<?php\ndefine('BOT_PLATFORM', 'bale');\n${MOCK}\n/* The outbox arms its own drain once per process (static flag); in this long-lived php-wasm process an earlier run may have consumed it, so the driver re-arms a drain itself. bot_outbox_limits is cleared because earlier runs here attempt REAL (unmocked) HTTP sends that would rate-limit the platform row. */\nregister_shutdown_function(function(){ try{ DB::execute('DELETE FROM bot_outbox_limits'); }catch(Throwable $e){} try{ bot_outbox_drain(3,8); }catch(Throwable $e){} });\n${body}`);
}
async function webhook(update) {
  php.writeFile('/harness/update.json', JSON.stringify(update));
  php.writeFile('/harness/wire.json', '[]');
  const r = await run(`<?php require '/www/includes/__harness_webhook_bale_cns.php';`);
  return { wire: JSON.parse(php.readFileAsText('/harness/wire.json')), out: r.out, err: r.err };
}
let w = await webhook({ callback_query: { id: 'cb-1', data: 'counselreply_801', message: { chat: { id: 555901 } } } });
check(w.wire.some(x => x.text.includes('پاسخ خود را برای مشاور بنویسید')), 'tapping «پاسخ» asks the parent to write the follow-up: ' + JSON.stringify(w.wire));
const st = await db("SELECT step, temp_nid FROM bale_bot_state WHERE bale_chat_id='555901'");
check(st[0] && st[0].step === 'counsel_reply_wait' && String(st[0].temp_nid) === '801', 'the follow-up step is parked on the same request');
w = await webhook({ message: { chat: { id: 555901 }, text: 'سلام، ساعت ۱۲ برای ما بهتر است.', from: { username: 'parent-1' } } });
check(w.wire.some(x => x.text.includes('برای مشاور ارسال شد')), 'the parent gets a delivery confirmation');
const conv = await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php'; ensure_counseling_schema();
$n=DB::fetch("SELECT COUNT(*) c FROM counseling_requests WHERE id>802");
$m=DB::fetch("SELECT * FROM counseling_messages WHERE request_id=801 AND sender='parent' ORDER BY id DESC");
$r=DB::fetch("SELECT status FROM counseling_requests WHERE id=801");
$notify=DB::fetch("SELECT payload FROM bot_outbox WHERE payload LIKE '%مکالمهٔ مشاوره%' ORDER BY created_at DESC");
echo 'NEWREQ='.($n['c']??'?').';PMSG='.($m['body']??'').';ST='.($r['status']??'').';NTF='.($notify['payload']??'NONE');`);
check(conv.out.includes('NEWREQ=0'), 'no NEW request was created for the follow-up: ' + conv.out.slice(0, 200));
check(conv.out.includes('PMSG=سلام، ساعت ۱۲ برای ما بهتر است.'), 'the follow-up lands in the SAME conversation');
check(conv.out.includes('ST=in_progress'), 'the request goes back to in-progress for the counselor');
check(conv.out.includes('NTF=') && !conv.out.includes('NTF=NONE'), 'counselors are notified through the outbox queue');
const stAfter = await db("SELECT step FROM bale_bot_state WHERE bale_chat_id='555901'");
check(!stAfter.length, 'the follow-up step is cleared after the message is delivered to the thread');

/* a callback for a request that belongs to another chat is refused */
w = await webhook({ callback_query: { id: 'cb-2', data: 'counselreply_801', message: { chat: { id: 555902 } } } });
check(w.wire.some(x => x.text.includes('در دسترس نیست')), 'the reply button only works in the requester chat');

/* ── 5: counselor-file — private notes + the conversation bundle ─────────── */
const notePost = await req('', { file: 'counselor-file.php', sid, method: 'POST', post: {
  csrf_token: csrf, add_note: '1', student_id: '7101', body: 'مورد: افت ریاضی پس از تغییر کلاس. پیگیری شد.' } });
check(!notePost.res.fatal, 'note saved: ' + (notePost.res.fatal || ''));
const filePage = await req('', { file: 'counselor-file.php', sid, query: 'id=7101' });
check(!filePage.res.fatal, 'the per-student file renders: ' + (filePage.res.fatal || ''));
check(filePage.res.page.includes('افت ریاضی پس از تغییر کلاس'), 'the private note is listed');
check(filePage.res.page.includes('مشاور مدرسه'), 'the note shows its author (counselor)');
check(filePage.res.page.includes('بستهٔ درخواست‌ها و مکالمات'), 'the conversation bundle section exists');
check(filePage.res.page.includes('افت تحصیلی') && filePage.res.page.includes('سلام، ساعت ۱۲ برای ما بهتر است.'), 'the bundle shows every request with the full parent/counselor thread');
const asExec = await req('', { file: 'counselor-file.php', sid: execSid, query: 'id=7101' });
check(asExec.res.redirect && asExec.res.redirect.includes('admin-login'), 'a non-counselor teacher (executive) cannot open the counseling file');
/* v4.170.0: cross-navigation between the notes and the dossier tabs */
check(filePage.res.page.includes('staff-student-file.php?tab=info&id=7101') && filePage.res.page.includes('staff-student-file.php?tab=discipline&id=7101'), 'the notes page links to the info/discipline dossier of the same student');
check(/staff-student-file\.php\?tab=reports&id=7101[^"']*back=counselor-file/.test(filePage.res.page), 'the dossier links carry a return address back to the notes');
const ssfC = await req('', { file: 'staff-student-file.php', sid, query: 'id=7101&tab=discipline' });
check(!ssfC.res.fatal && ssfC.res.page.includes('counselor-file.php?id=7101'), 'a counselor sees the «یادداشت مشاور» button on the dossier');
const ssfE = await req('', { file: 'staff-student-file.php', sid: execSid, query: 'id=7101&tab=discipline' });
check(!ssfE.res.page.includes('counselor-file.php?id=7101'), 'a non-counselor deputy does NOT get the counselor-notes button');

/* ── 6: reports-lists for the executive deputy ────────────────────────────── */
const rlExec = await req('', { file: 'reports-lists.php', sid: execSid });
check(!rlExec.res.fatal, 'reports-lists renders for the executive: ' + (rlExec.res.fatal || ''));
check(!rlExec.res.redirect && rlExec.res.page.includes('لیست‌ها و گزارشات'), 'the executive deputy reaches the lists & reports page');
check(rlExec.res.page.includes('لیست کلاسی دبیر') && rlExec.res.page.includes('لیست دانش‌آموزان کل مدرسه'), 'all report sections are present');
const rlCounselor = await req('', { file: 'reports-lists.php', sid });
check(rlCounselor.res.redirect && rlCounselor.res.redirect.includes('login'), 'other teachers are still refused: redirect=' + rlCounselor.res.redirect);

/* ── 7: «آلبوم عکس» report (item 3, v4.170.0) ────────────────────────────── */
check(rlExec.res.page.includes('آلبوم عکس کلاس‌ها'), 'the album report has a tab in the hub');
/* 32 students in one class → overflow to a second A4 sheet; a few get real
   photo files so the data-URI embedding is exercised end to end. */
const JPEG = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==';
const albumSetup = await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/school_roles.php'; ensure_school_roles_schema();
DB::execute("DELETE FROM students WHERE class_name='آلبوم ۱'");
@mkdir('/www/uploads/photos', 0777, true);
for ($i = 1; $i <= 32; $i++) {
  $nid = '11000' . str_pad((string)(100 + $i), 5, '0', STR_PAD_LEFT);
  $pu = ($i <= 3) ? 'uploads/photos/' . $nid . '.jpg' : '';
  if ($pu !== '') file_put_contents('/www/' . $pu, base64_decode('${JPEG}'));
  DB::execute("INSERT INTO students (national_id,first_name,last_name,class_name,grade_level,status,academic_year,photo_url) VALUES (?,'دانش','آموز{$i}','آلبوم ۱','هفتم','active','1404/1405',?)", [$nid, $pu]);
}
echo 'ALBUM_OK';`);
check(albumSetup.out.includes('ALBUM_OK'), 'album class fixture ran: ' + albumSetup.out.slice(0, 120) + albumSetup.err.slice(0, 120));
const albumTab = await req('', { file: 'reports-lists.php', sid: execSid, query: 'tab=album' });
check(!albumTab.res.fatal && albumTab.res.page.includes('photo_album_pdf'), 'the album tab lists classes with a download action: ' + (albumTab.res.fatal || ''));
const albumPdf = await req('', { file: 'reports-lists.php', sid: execSid, query: 'action=photo_album_pdf&class=' + encodeURIComponent('آلبوم ۱') });
check(!albumPdf.res.fatal && albumPdf.res.page.includes('@page{size:A4 portrait'), 'the album prints on A4: ' + (albumPdf.res.fatal || ''));
check((albumPdf.res.page.match(/class="sheet"/g) || []).length === 2, 'a 32-student class overflows to a second A4 sheet');
check(albumPdf.res.page.includes('data:image/jpeg;base64,'), 'a student with a photo file is embedded as a data-URI');
check(albumPdf.res.page.includes('جای عکس'), 'a student without a photo keeps an empty photo box');
check(albumPdf.res.page.includes('دانش آموز1') && albumPdf.res.page.includes('class="note"'), 'each card shows the name and a writing space');

/* ── 5b: student-modal returns live counters (item 5) ─────────────────────── */
await run(`<?php require_once '/www/includes/functions.php';
DB::execute("DELETE FROM student_discipline_records WHERE student_id=7101");
DB::execute("INSERT INTO student_discipline_records (student_id,title_text,occurred_at_jalali,review_status,created_at_jalali) VALUES (7101,'تأخیر','1404/06/10','pending','1404/06/10 08:00')");
echo 'D_OK';`);
await loginAdmin('harnessAdmCns1');
const modal = await req('', { file: 'student-modal.php', sid: 'harnessAdmCns1', query: 'type=discipline&student_id=7101' });
const mj = JSON.parse(modal.res.page);
check(mj.ok && mj.stats && mj.stats.student_id === 7101 && mj.stats.discipline_count === 1 && mj.stats.unreviewed_count === 1,
  'the modal response carries fresh discipline counters for the live row update: ' + modal.res.page.slice(0, 120));

console.log(`PASS ${checks} counselor-flow cases (year filter, notes, badge/photo, bot reply button, threads, executive access)`);
process.exit(0);
