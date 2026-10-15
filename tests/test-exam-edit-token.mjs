// v4.180.0 — «ویرایش آزمون» هم مثل «طراحی آزمون جدید» با توکن امضاشده از پنجرهٔ
// تازه باز شود (مرورگر داخلی بله یا پنجرهٔ جدای اپ دسکتاپ که نشست پنل ندارد).
//
// چه چیزی واقعاً اجرا می‌شود، نه ادعا:
//   ۱) پنل دبیر با PHP واقعی رندر می‌شود و توکنِ داخل دکمه‌های ویرایش کلاسی،
//      ساخت آزمون جدید و طراحی پایه‌ای از خودِ HTML بیرون کشیده می‌شود.
//   ۲) همان URL با یک نشستِ **خالی** (بدون teacher_id و بدون ورود مدیر) درخواست
//      می‌شود؛ نتیجه باید به exam-print.php برسد، نه admin-login.php.
//   ۳) صفحهٔ طراحی با آن توکن بدون هیچ نشستی رندر می‌شود (کاربر واقعاً می‌بیند).
//   ۴) کنترل منفی: بدون توکن، با توکن دست‌کاری‌شده، با توکن منقضی و با توکنِ
//      کلاس دیگر همان درخواست باید به صفحهٔ ورود برگردد یا رد شود.
//   ۵) دکمهٔ «طراحی آزمون جدید» (POST بدون نشست، فقط با توکن) آزمون می‌سازد و به
//      طراحی می‌رود؛ GET بدون توکن همچنان چیزی نمی‌سازد (پیش‌بارگذاری امن است).
import assert from 'node:assert/strict';
import {run,req,php,db,loginAdmin} from './harness/lib.mjs';

const teacher='editTokTeacher', anon='editTokAnon', admin='editTokAdmin', csrf='edit-token-fixture';
async function code(s){const r=await run(s);assert(!r.err,r.err);assert(!r.out.includes('Fatal error'),r.out);return r.out.trim();}

await loginAdmin(admin);
await code(`<?php ini_set('session.save_path','/tmp/sess');session_name('BACI_TEST');session_id('${teacher}');session_start();$_SESSION=['teacher_id'=>901,'csrf_token'=>'${csrf}'];`);
// نشست بی‌اعتبار: وجود دارد ولی نه دبیر است نه مدیر — همان چیزی که در پنجرهٔ تازه رخ می‌دهد.
await code(`<?php ini_set('session.save_path','/tmp/sess');session_name('BACI_TEST');session_id('${anon}');session_start();$_SESSION=['csrf_token'=>'${csrf}'];`);

const fixture=JSON.parse(await code(`<?php require '/www/includes/class_exam_groups.php';ensure_exams_schema();ceg_schema();
set_setting('current_academic_year','1404/1405');
DB::execute("INSERT OR IGNORE INTO academic_years (year_name,status,is_default) VALUES ('1404/1405',1,1)");
DB::execute('INSERT INTO teachers (id,national_id,full_name,password,status,academic_year) VALUES (?,?,?,?,?,?)',[901,'EditTok901','دبیر ۹۰۱','hash',1,'1404/1405']);
DB::execute('INSERT INTO teachers (id,national_id,full_name,password,status,academic_year) VALUES (?,?,?,?,?,?)',[902,'EditTok902','دبیر ۹۰۲','hash',1,'1404/1405']);
foreach(['هفتم1','هفتم2'] as $c){DB::execute('INSERT INTO classes (name,grade,academic_year) VALUES (?,?,?)',[$c,'هفتم','1404/1405']);}
foreach([['هفتم1','ریاضی'],['هفتم2','ریاضی']] as $a)DB::execute('INSERT INTO class_schedules (academic_year,class_name,subject_name,teacher_id,day_of_week,period_num) VALUES (?,?,?,?,?,?)',['1404/1405',$a[0],$a[1],901,'شنبه','زنگ 1']);
$existing=ceg_insert_exam(901,'1404/1405','هفتم','ریاضی','هفتم1');
$freshClass='هفتم2';
/* آزمون ماهانه (official) تا جدول «آزمون‌های ماهانه مدرسه» هم پر باشد */
DB::execute("INSERT INTO exam_schedules (academic_year,exam_month,grade_level,class_name,subject_name,teacher_id,teacher_name,exam_date_jalali,exam_day_name,start_time,duration_minutes,exam_room_default,question_file,header_config,is_active,exam_kind,created_at_jalali) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",['1404/1405','MONTHLY_SENTINEL_TOKEN','هفتم','هفتم1','علوم',901,'دبیر ۹۰۱','1404/05/10','شنبه','08:00',90,'','','{}',1,'official','1404/05/01 08:00']);
$monthly=(int)DB::lastInsertId();
echo json_encode(['existing'=>(int)$existing,'freshClass'=>$freshClass,'monthly'=>$monthly]);`));

let checks=0;
const tokens=html=>(html.match(/name="ct" value="([^"]+)"/g)||[]).map(m=>m.replace(/^name="ct" value="|"$/g,''));
/* «subject=» با ct= تمام می‌شود؛ پس فقط ct= واقعی (بعد از & یا ?) شمرده می‌شود. */
const hrefTokens=html=>(html.match(/[?&](amp;)?ct=([^&"]+)/g)||[]).map(m=>decodeURIComponent(m.replace(/^[?&](amp;)?ct=/,'')));

/* ═══════ ۱) دکمه‌های پنل دبیر توکن دارند ═══════ */
const panel=await req('پنل دبیر',{file:'teacher-panel.php',query:'tab=exams',sid:teacher});
assert(!panel.res.fatal,panel.res.fatal);
const classTable=panel.res.page.match(/<table id="teacherClassExams"[\s\S]*?<\/table>/)[0];
const formTokens=tokens(classTable), linkTokens=hrefTokens(classTable);
assert(!classTable.includes('name="ct" value=""'),'no empty ct field is rendered');
assert.equal(formTokens.length,2,'both class rows carry a signed ct token (edit + new): '+formTokens.length);checks++;
assert(linkTokens.length>=1,'the grade design link carries ct');checks++;
const gradeLink=classTable.match(/href="class-exam-group\.php\?([^"]+)"/)[1];
assert(gradeLink.includes('grade=%D9%87%D9%81%D8%AA%D9%85'),'the grade link keeps its grade: '+gradeLink);checks++;
assert(classTable.includes('ویرایش آزمون کلاسی')&&classTable.includes('طراحی آزمون جدید'),'the two labels are unchanged');checks++;

/* ═══════ ۲) ویرایش کلاسی در پنجرهٔ تازه = فضای بدون نشست ═══════ */
const editToken=formTokens[0];
const noSession=await req('ویرایش کلاسی بدون نشست',{
  file:'class-exam-create.php',
  query:'class='+encodeURIComponent('هفتم1')+'&subject='+encodeURIComponent('ریاضی')+'&year='+encodeURIComponent('1404/1405')+'&exam_id='+fixture.existing+'&ct='+encodeURIComponent(editToken),
  sid:anon});
assert(!/admin-login\.php/.test(noSession.res.redirect||''),'a signed token must never land on the login page: '+(noSession.res.redirect||''));checks++;
assert(noSession.res.redirect.startsWith('exam-print.php'),'the edit token redirects straight into the live designer: '+noSession.res.redirect);checks++;
const dt=new URLSearchParams(noSession.res.redirect.split('?')[1]||'').get('dt');
assert(dt&&dt.length>20,'the redirect carries a signed dt design token');checks++;

/* ═══════ ۳) صفحهٔ طراحی با توکن، بدون هیچ نشستی رندر می‌شود ═══════ */
const designer=await req('طراحی بدون نشست',{file:'exam-print.php',query:'type=questions&exam_id='+fixture.existing+'&dt='+encodeURIComponent(dt),sid:anon});
assert(!designer.res.fatal,designer.res.fatal);
assert(designer.res.page.includes('exam-design-api.php'),'the real designer UI is rendered');checks++;
assert(!/admin-login\.php|غیرمجاز/.test(designer.res.page),'no login page and no «غیرمجاز» for a valid token');checks++;

/* ═══════ ۴) کنترل‌های منفی ═══════ */
const bare=await req('ویرایش بدون توکن',{file:'class-exam-create.php',query:'class='+encodeURIComponent('هفتم1')+'&subject='+encodeURIComponent('ریاضی')+'&year='+encodeURIComponent('1404/1405')+'&exam_id='+fixture.existing,sid:anon});
assert(/admin-login\.php/.test(bare.res.redirect||''),'without a token the session guard still redirects to login');checks++;
const flipped=(editToken.slice(0,-2)+(editToken.slice(-2,1)==='A'?'B':'A'));
const forged=await req('توکن دست‌کاری‌شده',{file:'class-exam-create.php',query:'class='+encodeURIComponent('هفتم1')+'&subject='+encodeURIComponent('ریاضی')+'&year='+encodeURIComponent('1404/1405')+'&exam_id='+fixture.existing+'&ct='+encodeURIComponent(flipped),sid:anon});
assert(/admin-login\.php/.test(forged.res.redirect||''),'a tampered token is rejected');checks++;
const expired=await code(`<?php require '/www/includes/auth.php';echo make_class_exam_entry_token(901,'1404/1405','هفتم1','ریاضی','هفتم',0,-10);`);
const stale=await req('توکن منقضی',{file:'class-exam-create.php',query:'class='+encodeURIComponent('هفتم1')+'&subject='+encodeURIComponent('ریاضی')+'&year='+encodeURIComponent('1404/1405')+'&exam_id='+fixture.existing+'&ct='+encodeURIComponent(expired),sid:anon});
assert(/admin-login\.php/.test(stale.res.redirect||''),'an expired token is rejected');checks++;
const otherClass=await code(`<?php require '/www/includes/auth.php';echo make_class_exam_entry_token(901,'1404/1405','هشتم9','ریاضی','هشتم',0,86400);`);
const mismatch=await req('توکن کلاس دیگر',{file:'class-exam-create.php',query:'class='+encodeURIComponent('هفتم1')+'&subject='+encodeURIComponent('ریاضی')+'&year='+encodeURIComponent('1404/1405')+'&exam_id='+fixture.existing+'&ct='+encodeURIComponent(otherClass),sid:anon});
assert(!/exam-print\.php/.test(mismatch.res.redirect||''),'a token minted for another class cannot open this exam: '+(mismatch.res.redirect||''));checks++;
const foreignExam=await code(`<?php require '/www/includes/class_exam_groups.php';$id=ceg_insert_exam(902,'1404/1405','هفتم','ریاضی','هفتم1');echo $id;`);
const stolen=await req('آزمون دبیر دیگر',{file:'class-exam-create.php',query:'class='+encodeURIComponent('هفتم1')+'&subject='+encodeURIComponent('ریاضی')+'&year='+encodeURIComponent('1404/1405')+'&exam_id='+foreignExam+'&ct='+encodeURIComponent(editToken),sid:anon});
assert(!/exam-print\.php/.test(stolen.res.redirect||''),'a token cannot open another teacher\'s exam: '+(stolen.res.redirect||''));checks++;
await code(`<?php require '/www/includes/db.php';DB::execute('DELETE FROM exam_schedules WHERE id=?',[${foreignExam}]);`);

/* ═══════ ۵) ساخت آزمون جدید با توکنِ «طراحی آزمون جدید» ═══════ */
const createToken=formTokens.filter(t=>{
  let raw='';try{raw=Buffer.from(t.replace(/-/g,'+').replace(/_/g,'/'),'base64').toString('utf8');}catch(e){return false;}
  return raw.includes('"n":1');
})[0];
assert(createToken,'the new-exam form mints a token with the create flag');checks++;
const before=await db('SELECT COUNT(*) c FROM exam_schedules');
const created=await req('ساخت با توکن بدون نشست',{file:'class-exam-create.php',method:'POST',
  post:{class:fixture.freshClass,subject:'ریاضی',year:'1404/1405',ct:createToken},sid:anon});
assert(created.res.redirect.startsWith('exam-print.php'),'the create token builds the exam and lands on the designer: '+created.res.redirect);checks++;
const after=await db('SELECT COUNT(*) c FROM exam_schedules');
assert.equal(Number(after[0].c),Number(before[0].c)+1,'exactly one exam row was created');checks++;
const made=await db(`SELECT id FROM exam_schedules WHERE class_name='${fixture.freshClass}' AND subject_name='ریاضی'`);
assert((await db('SELECT design_exam_id FROM class_exam_groups')).length===0,'no grade group is invented by the class path');checks++;
const crossClass=await req('توکن کلاس دیگر ویرایش نکند',{file:'class-exam-create.php',
  query:'class='+encodeURIComponent('هفتم1')+'&subject='+encodeURIComponent('ریاضی')+'&year='+encodeURIComponent('1404/1405')+'&exam_id='+fixture.existing+'&ct='+encodeURIComponent(createToken),sid:anon});
assert(!/exam-print\.php/.test(crossClass.res.redirect||''),'a token minted for one class cannot edit another class: '+(crossClass.res.redirect||''));checks++;
// GET بدون توکن نباید بسازد (پیش‌بارگذاری مرورگر امن می‌ماند)
const prefetchBefore=await db('SELECT COUNT(*) c FROM exam_schedules');
const plainGet=await req('GET بی‌توکن',{file:'class-exam-create.php',query:'class='+encodeURIComponent('هفتم9')+'&subject='+encodeURIComponent('ریاضی')+'&year='+encodeURIComponent('1404/1405'),sid:teacher});
assert(!/exam-print\.php/.test(plainGet.res.redirect||''),'a plain GET still does not reach the designer');checks++;
assert.equal(Number((await db('SELECT COUNT(*) c FROM exam_schedules'))[0].c),Number(prefetchBefore[0].c),'and creates nothing');checks++;

/* ═══════ ۶) طراحی پایه‌ای با توکن، در پنجرهٔ تازه ═══════ */
const gradeToken=decodeURIComponent(gradeLink.match(/(?:^|&(?:amp;)?)ct=([^&]+)/)[1]);
const gradePage=await req('صفحهٔ پایه‌ای بدون نشست',{file:'class-exam-group.php',
  query:'year=1404%2F1405&grade='+encodeURIComponent('هفتم')+'&subject='+encodeURIComponent('ریاضی')+'&ct='+encodeURIComponent(gradeToken),sid:anon});
assert(!/admin-login\.php/.test(gradePage.res.redirect||''),'the grade token never lands on the login page');checks++;
if(gradePage.res.redirect){
  /* گروه از قبل هست: باید مستقیم به طراحی مشترک برود */
  assert(gradePage.res.redirect.startsWith('exam-print.php'),'the grade token opens the shared design: '+gradePage.res.redirect);checks++;
  assert(gradePage.res.redirect.includes('grade_all=1'),'the grade design keeps grade_all=1');checks++;
}else{
  /* بار اول: پنجرهٔ انتخاب روش طراحی، و همان فرم باید در پنجرهٔ تازه هم کار کند */
  assert(gradePage.res.page.includes('طراحی مشترک'),'the fresh-window choice page is rendered');checks++;
  const choiceToken=tokens(gradePage.res.page)[0];
  assert(choiceToken,'the choice form carries the signed token');checks++;
  const chosen=await req('انتخاب روش در پنجرهٔ تازه',{file:'class-exam-group.php',method:'POST',
    post:{year:'1404/1405',grade:'هفتم',subject:'ریاضی',mode:'fresh',ct:choiceToken},sid:anon});
  assert(chosen.res.redirect.startsWith('exam-print.php'),'the choice POST reaches the designer without a session: '+(chosen.res.redirect||''));checks++;
  assert(chosen.res.redirect.includes('grade_all=1'),'the shared design keeps grade_all=1');checks++;
  const again=await req('گروه موجود، بدون نشست',{file:'class-exam-group.php',
    query:'year=1404%2F1405&grade='+encodeURIComponent('هفتم')+'&subject='+encodeURIComponent('ریاضی')+'&ct='+encodeURIComponent(gradeToken),sid:anon});
  assert((again.res.redirect||'').startsWith('exam-print.php'),'with the group created the same token opens it directly');checks++;
}
const gradeBare=await req('پایه‌ای بدون توکن',{file:'class-exam-group.php',query:'year=1404%2F1405&grade='+encodeURIComponent('هفتم')+'&subject='+encodeURIComponent('ریاضی'),sid:anon});
assert(/admin-login\.php/.test(gradeBare.res.redirect||''),'without a token class-exam-group.php still requires the session');checks++;

/* ═══════ ۷) آزمون ماهانه: دکمهٔ ویرایش با توکن ═══════ */
const monthly=await req('جدول ماهانه',{file:'exams.php',query:'tab=print&year=1404%2F1405',sid:admin});
const monthlyTable=monthly.res.page.match(/<table id="adminMonthlyExams"[\s\S]*?<\/table>/)[0];
assert(monthlyTable.includes('ویرایش آزمون ماهانه'),'the monthly table shows a «ویرایش آزمون ماهانه» button');checks++;
const monthlyRow=(monthlyTable.split('<tr>').find(r=>r.includes('exam_id='+fixture.monthly+'&'))||'');
assert(monthlyRow.includes('ویرایش آزمون ماهانه'),'the monthly row of the fixture exam carries the edit button');checks++;
const monthlyHref=monthlyTable.match(/href="exam-print\.php\?type=questions&(?:amp;)?exam_id=(\d+)&(?:amp;)?dt=([^"]+)"/);
assert(monthlyHref,'the monthly edit button is a signed design link');checks++;
const monthlyDesigner=await req('ویرایش ماهانه بدون نشست',{file:'exam-print.php',
  query:'type=questions&exam_id='+monthlyHref[1]+'&dt='+encodeURIComponent(monthlyHref[2]),sid:anon});
assert(!/غیرمجاز|admin-login\.php/.test(monthlyDesigner.res.page),'the monthly design link renders without a session');checks++;
const sched=await req('جدول برنامه',{file:'exams.php',query:'tab=schedule&year=1404%2F1405',sid:admin});
assert(sched.res.page.includes('ویرایش آزمون ماهانه'),'the schedule table also offers the token edit button');checks++;

console.log(`PASS ${checks} class-exam edit-token scenarios (fresh window, no session)`);
process.exit(0);
