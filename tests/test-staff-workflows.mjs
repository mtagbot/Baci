// Isolated real PHP + SQLite; optional browser regression for lazy schedule pickers.
import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {readFileSync,writeFileSync,mkdirSync,existsSync} from 'node:fs';
import {createRequire} from 'node:module';
import {run,req,db,php,loginAdmin} from './harness/lib.mjs';
import {REPO,resolveFile} from './harness/site.mjs';
async function checkedRun(code){const r=await run(code);assert(!r.err,r.err);assert(!/Fatal error/.test(r.out),r.out);return r;}
const admin='staffAdmin',teacher='staffTeacher',csrf='staff-fixture-csrf';
await loginAdmin(admin);
for(const [sid,who] of [[admin,'admin'],[teacher,'teacher']]) await checkedRun(`<?php ini_set('session.save_path','/tmp/sess'); session_name('BACI_TEST'); session_id('${sid}'); session_start(); $_SESSION['csrf_token']='${csrf}'; ${who==='teacher'?"$_SESSION['teacher_id']=901; unset($_SESSION['admin_id']);":''}`);
const seeded=await checkedRun(`<?php require '/www/includes/class_exam_helpers.php'; ensure_exams_schema();
set_setting('current_academic_year','1404/1405');
DB::execute("INSERT OR IGNORE INTO academic_years (year_name,status,is_default) VALUES ('1405/1406',1,0),('1404/1405',1,1),('1403/1404',1,0)");
for($i=901;$i<=912;$i++) DB::execute('INSERT INTO teachers (id,national_id,full_name,password,status,academic_year) VALUES (?,?,?,?,?,?)',[$i,'Teacher'.$i,'دبیر '.$i,'hash',1,'1404/1405']);
foreach (['هفتم1','هفتم2','هفتم3','هشتم1'] as $c) DB::execute('INSERT INTO classes (name,grade,academic_year) VALUES (?,?,?)',[$c,infer_grade_from_class_name($c),'1404/1405']);
foreach ([
[9001,'1404/1405','هفتم1','شنبه','زنگ 1','ریاضی',901],
[9002,'1404/1405','هفتم2','شنبه','زنگ 1','ریاضی',901],
[9003,'1404/1405','هفتم3','شنبه','زنگ 1','ریاضی',902],
[9004,'1404/1405','هفتم1','یک‌شنبه','زنگ 2','علوم',901],
[9005,'1404/1405','هشتم1','شنبه','زنگ 2','ریاضی',901],
[9006,'1403/1404','هفتم1','شنبه','زنگ 1','ریاضی',901],
[9007,'1405/1406','هفتم1','شنبه','زنگ 1','ریاضی',901],
[9008,'1404/1405','هفتم1','چهارشنبه','زنگ 3','ورزش',null]
] as $s) { DB::execute('INSERT INTO class_schedules (id,academic_year,class_name,day_of_week,period_num,subject_name,teacher_id,teacher_name) VALUES (?,?,?,?,?,?,?,?)',array_merge($s,[$s[6]?'دبیر '.$s[6]:'نام واردشده از فایل'])); }
DB::execute('ALTER TABLE class_schedules ADD COLUMN keep_metadata TEXT');
DB::execute("UPDATE class_schedules SET keep_metadata='must survive'");
foreach ([['هفتم1','1404/1405'],['هفتم2','1404/1405'],['هفتم3','1404/1405'],['هفتم1','1403/1404'],['هشتم1','1404/1405']] as $i=>$s) DB::execute('INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year) VALUES (?,?,?,?,?,?,?,?)',[9901+$i,'Student'.$i,'Student'.$i,'Scope',$s[0],infer_grade_from_class_name($s[0]),'active',$s[1]]);
`);
assert(!seeded.err,seeded.err);assert(!/Fatal|Exception|Error:/.test(seeded.out),seeded.out);
let count=0;
async function request(file,{sid=admin,query='',post,issues=false}={}){
  const r=await req('',{file,sid,query,method:post?'POST':'GET',post:post||{}});
  assert(!r.res.fatal,r.res.fatal);assert(!r.stderr,r.stderr);
  if(!issues)assert.equal((r.res.php_issues||'').split('\n').filter(x=>x&&!x.includes('headers already sent by (output started at /harness/run_request.php:33)')).join('\n'),'',r.res.php_issues);
  return r.res;
}
const schedules=()=>db('SELECT * FROM class_schedules ORDER BY id');
const before=await schedules();
const page=await request('classes.php',{query:'tab=schedule&year=1404%2F1405'});
assert(page.page.includes('wsOpenPicker'));assert.deepEqual(await schedules(),before);count++;
const base={ajax_cell_save:'1',csrf_token:csrf,class_name:'هفتم1',day_of_week:'شنبه',period_num:'زنگ 1',subject_name:'ریاضی',teacher_id:'901',is_half:'0',subject_name2:'',teacher_id2:'0'};
async function save(over={},sid=admin){const data={...base,...over};const slot=(await schedules()).filter(r=>r.academic_year==='1404/1405'&&r.class_name===data.class_name&&r.day_of_week===data.day_of_week&&r.period_num===data.period_num);data.revision=over.revision||createHash('sha256').update(JSON.stringify(slot.map(r=>['id','subject_name','teacher_id','teacher_name'].map(k=>String(r[k]??''))))).digest('hex');const r=await request('classes.php',{sid,query:'tab=schedule&year=1404%2F1405',post:data});const j=JSON.parse(r.page);if(!j.ok)console.log("SAVE:",j);return j;}
assert((await save({teacher_id:'902'})).ok);
let rows=await schedules();assert.equal(rows.find(x=>x.id===9001).teacher_id,902);assert.equal(rows.find(x=>x.id===9001).keep_metadata,'must survive');
assert.deepEqual(rows.filter(x=>x.id!==9001),before.filter(x=>x.id!==9001));count++;
assert((await save()).ok);count++;
const stable=await schedules();
for(const bad of [{csrf_token:'bad'},{teacher_id:'999999'},{day_of_week:'bad'},{period_num:'bad'},{class_name:'unassigned'},{subject_name:''},{revision:'stale'}]){assert(!(await save(bad)).ok);assert.deepEqual(await schedules(),stable);count++;}
const denied=await request('classes.php',{sid:teacher,query:'tab=schedule&year=1404%2F1405',post:base});assert(denied.redirect || (denied.page && !JSON.parse(denied.page).ok));assert.deepEqual(await schedules(),stable);count++;
const badYear=await request('classes.php',{query:'tab=schedule&year=1399%2F1400',post:{...base,revision:'unused'}});
assert(!JSON.parse(badYear.page).ok);assert.deepEqual(await schedules(),stable);count++;
const missing={...base};delete missing.subject_name;
assert(!JSON.parse((await request('classes.php',{query:'tab=schedule&year=1404%2F1405',post:missing})).page).ok);assert.deepEqual(await schedules(),stable);count++;
// Failure after the first UPDATE must roll that UPDATE back too.
await checkedRun(`<?php require '/www/includes/db.php'; DB::getInstance()->getPdo()->rawScript("CREATE TRIGGER fail_schedule BEFORE INSERT ON class_schedules WHEN NEW.subject_name='FAIL' BEGIN SELECT RAISE(ABORT,'injected'); END;");`);
assert(!(await save({subject_name:'تغییر نباید بماند',is_half:'1',subject_name2:'FAIL',teacher_id2:'902'})).ok);
assert.deepEqual(await schedules(),stable);count++;
await checkedRun("<?php require '/www/includes/db.php'; DB::execute('DROP TRIGGER fail_schedule');");
assert((await save({is_half:'1',subject_name2:'علوم',teacher_id2:'902'})).ok);
rows=await schedules();const second=rows.find(x=>x.class_name==='هفتم1'&&x.day_of_week==='شنبه'&&x.id!==9001&&x.academic_year==='1404/1405');assert(second);count++;
assert((await save({is_half:'1',subject_name2:'علوم',teacher_id2:'903'})).ok);assert.equal((await schedules()).find(x=>x.id===second.id).teacher_id,903);count++;
await checkedRun(`<?php require '/www/includes/db.php'; DB::execute("INSERT INTO class_schedules (academic_year,class_name,day_of_week,period_num,subject_name) VALUES ('1404/1405','هفتم1','شنبه','زنگ 1','legacy third')");`);
const threeRows=await schedules();assert(!(await save()).ok);assert.deepEqual(await schedules(),threeRows);count++;
await checkedRun(`<?php require '/www/includes/db.php'; DB::execute("DELETE FROM class_schedules WHERE subject_name='legacy third'");`);
assert((await save()).ok);assert.deepEqual(await schedules(),stable);count++;
assert((await save({day_of_week:'چهارشنبه',period_num:'زنگ 3',subject_name:'تربیت بدنی',teacher_id:'0'})).ok);
assert.equal((await schedules()).find(x=>x.id===9008).teacher_name,'نام واردشده از فایل');count++;
assert((await save({day_of_week:'چهارشنبه',period_num:'زنگ 3',subject_name:'تربیت بدنی',teacher_id:'0',teacher_edited1:'1'})).ok);
assert.equal((await schedules()).find(x=>x.id===9008).teacher_name,'');count++;
// Restore this fixture without touching production databases (all tests are MEMFS).
await checkedRun("<?php require '/www/includes/db.php'; DB::execute(\"UPDATE class_schedules SET subject_name='ورزش',teacher_name='نام واردشده از فایل' WHERE id=9008\");");

const grading=await request('teacher-panel.php',{sid:teacher,query:'tab=grading'});
assert(!grading.page.includes('class-exam-create.php'));assert(grading.page.includes('ورود به لیست نمرات'));count++;
let panel=await request('teacher-panel.php',{sid:teacher,query:'tab=exams'});
assert(panel.page.includes('➕ طراحی آزمون جدید'));assert(panel.page.includes('طراحی پایه‌ای کلاسی'));count++;
const exbase={class:'هفتم1',subject:'ریاضی',year:'1404/1405',csrf_token:csrf,new:'1'};
const exams=()=>db("SELECT * FROM exam_schedules WHERE teacher_id>=901 ORDER BY id");
const initialExams=await exams();
await request('class-exam-create.php',{sid:teacher,query:new URLSearchParams(exbase).toString()});assert.deepEqual(await exams(),initialExams);count++;
await request('class-exam-create.php',{sid:teacher,post:{...exbase,csrf_token:'bad'}});assert.deepEqual(await exams(),initialExams);count++;
let created=await request('class-exam-create.php',{sid:teacher,post:exbase});
assert(created.redirect?.includes('exam-print.php'));let exrows=await exams();assert.equal(exrows.length,initialExams.length+1);const exam=exrows.at(-1);count++;
const direct=await checkedRun(`<?php require '/www/includes/class_exam_helpers.php'; echo json_encode([class_exam_get_or_create(901,'1404/1405','هفتم1','ریاضی'),class_exam_get_or_create(901,'1404/1405','هفتم1','ریاضی')]);`);
assert.deepEqual(JSON.parse(direct.out),[exam.id,exam.id]);assert.deepEqual(await exams(),exrows);count++;
for(let i=0;i<4;i++){await request('class-exam-create.php',{sid:teacher,post:exbase});assert.deepEqual(await exams(),exrows);count++;}
await request('class-exam-create.php',{sid:teacher,query:new URLSearchParams(exbase).toString()});assert.deepEqual(await exams(),exrows);count++;
for(const bad of [{class:'هفتم3'},{subject:'درس غیرتخصیصی'},{class:'ناشناخته'}]){await request('class-exam-create.php',{sid:teacher,post:{...exbase,...bad}});assert.deepEqual(await exams(),exrows);count++;}
// Another subject in the same class, and another academic year, are independent.
await request('class-exam-create.php',{sid:teacher,post:{...exbase,subject:'علوم'}});assert.equal((await exams()).length,exrows.length+1);count++;
await request('class-exam-create.php',{sid:teacher,post:{...exbase,year:'1405/1406'}});assert.equal((await exams()).length,exrows.length+2);count++;
const science=(await exams()).find(x=>x.subject_name==='علوم');
await checkedRun(`<?php require '/www/includes/db.php'; DB::execute('UPDATE exam_schedules SET is_active=0 WHERE id=?',[${science.id}]);`);
const inactiveSnapshot=await exams();await request('class-exam-create.php',{sid:teacher,post:{...exbase,subject:'علوم'}});assert.deepEqual(await exams(),inactiveSnapshot);count++;
await request('class-exam-create.php',{sid:teacher,post:{...exbase,year:'1399/1400'}});assert.deepEqual(await exams(),inactiveSnapshot);count++;
// Seed a legacy duplicate and preserve its design and inactive state.
await checkedRun(`<?php require '/www/includes/db.php'; DB::execute("INSERT INTO exam_schedules (academic_year,exam_month,grade_level,class_name,subject_name,teacher_id,exam_kind,is_active,header_config,exam_date_jalali,start_time,created_at_jalali) VALUES ('1404/1405','legacy','هفتم','هفتم1','ریاضی',901,'class',0,'keep legacy','','','1404/01/01')"); $id=DB::lastInsertId(); DB::execute("INSERT INTO exam_designs (exam_id,design_json,designer_name,updated_at_jalali) VALUES (?,?,'legacy','1404/01/01')",[$id,'{"keep":"untouched"}']);`);
const legacy=(await exams()).at(-1);const legacySnapshot=await exams();const designsBefore=await db('SELECT * FROM exam_designs ORDER BY id');
await request('class-exam-create.php',{sid:teacher,post:exbase});assert.deepEqual(await exams(),legacySnapshot);assert.deepEqual(await db('SELECT * FROM exam_designs ORDER BY id'),designsBefore);count++;
panel=await request('teacher-panel.php',{sid:teacher,query:'tab=exams'});
const classRow=[...panel.page.matchAll(/<tr class="class-exam-row"[\s\S]*?<\/tr>/g)].map(m=>m[0]).find(x=>x.includes('data-class="هفتم1"')&&x.includes('data-subject="ریاضی"'));
assert(!classRow.includes('➕ طراحی آزمون جدید'));assert(classRow.includes(`value="${exam.id}"`));assert(classRow.includes(`value="${legacy.id}"`));assert(classRow.includes('ویرایش آزمون کلاسی'));count++;
const owned=await request('class-exam-create.php',{sid:teacher,query:new URLSearchParams({...exbase,exam_id:String(exam.id),grade_all:'1'}).toString()});assert(owned.redirect.includes('class-exam-group.php'));
const choice=await request('class-exam-group.php',{sid:teacher,query:owned.redirect.split('?')[1]});assert(choice.page.includes('value="copy"')&&choice.page.includes('value="fresh"'));
const target='type=questions&exam_id='+exam.id+'&grade_all=1';
const printed=await request('exam-print.php',{sid:teacher,query:target,issues:true});
assert(printed.page.includes('Student0'));assert(printed.page.includes('Student1'));assert(!printed.page.includes('Student2'));assert(!printed.page.includes('Student3'));assert(!printed.page.includes('Student4'));count++;
const wrong=await request('class-exam-create.php',{sid:teacher,query:new URLSearchParams({...exbase,subject:'علوم',exam_id:String(exam.id)}).toString()});assert(wrong.redirect.includes('teacher-panel.php'));count++;
assert.deepEqual(await db('SELECT * FROM exam_designs ORDER BY id'),designsBefore);count++;
const pdf=await request('reports-lists.php',{query:'action=class_list_pdf&class='+encodeURIComponent('هفتم1')});assert(pdf.page.includes('class="class-code">7/1</bdi>'));count++;
console.log(`PASS ${count} staff workflow PHP cases (isolated SQLite; no live data)`);

if(process.env.STAFF_BROWSER){
 const require=createRequire(REPO+'/.cache/browser/node_modules/_resolver.cjs');const {chromium:pw}=require('playwright'),mod=require('@sparticuz/chromium'),chromium=mod.default||mod;
 const browser=await pw.launch({executablePath:await chromium.executablePath(),args:chromium.args,headless:true});
 try{
  const ctx=await browser.newContext({viewport:{width:1440,height:1100}}),errors=[];let pending=0,maxPending=0,posts=0,delay=0,failNext=false;
  // Prevent native OS picker UI from stealing automation focus; keep real select/blur/change events.
  await ctx.addInitScript(()=>{HTMLSelectElement.prototype.showPicker=undefined;});
  await ctx.route('**/*',async route=>{
   const u=new URL(route.request().url()),f=decodeURIComponent(u.pathname).slice(1);
   if(f==='classes.php'){
    const post=route.request().method()==='POST'?Object.fromEntries(new URLSearchParams(route.request().postData())):null;
    if(post){posts++;pending++;maxPending=Math.max(maxPending,pending);if(failNext){failNext=false;pending--;return route.abort();}}
    const r=await request(f,{query:u.search.slice(1),post});
    if(post&&delay)await new Promise(r=>setTimeout(r,delay));
    if(post)pending--;
    return route.fulfill({contentType:post?'application/json':'text/html',body:r.page});
   }
   if(f==='teacher-panel.php'||f==='class-exam-create.php'){
    const post=route.request().method()==='POST'?Object.fromEntries(new URLSearchParams(route.request().postData())):null;
    const r=await request(f,{sid:teacher,query:u.search.slice(1),post});
    if(r.redirect)return route.fulfill({contentType:'text/html',body:'<!doctype html><script>location.replace('+JSON.stringify(new URL(r.redirect,u).href)+');</script>'});
    return route.fulfill({contentType:'text/html',body:r.page});
   }
   if(f==='exam-print.php')return route.fulfill({contentType:'text/html',body:'<!doctype html><title>Designer navigation fixture</title>'});
   if(f.endsWith('.php'))return route.fulfill({contentType:'application/json',body:'{}'});
   const p=resolveFile(f);if(!existsSync(p))return route.fulfill({status:404,body:''});
   return route.fulfill({contentType:f.endsWith('.js')?'application/javascript':f.endsWith('.css')?'text/css':'application/octet-stream',body:readFileSync(p)});
  });
  const page=await ctx.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.goto('https://staff.test/classes.php?tab=schedule&year=1404%2F1405');
  const cell=await page.locator('td[data-class="هفتم1"][data-day="شنبه"][data-period="زنگ 1"]').getAttribute('id');const tcell=cell.slice(0,-1)+'t';
  for(const id of ['902','903','901']){
   await page.locator('#'+tcell+' .ws-teach').click();
   await page.waitForTimeout(180); // Allow the real searchable-select MutationObserver + blur timer.
   assert.equal(await page.locator('#'+tcell+' .ss-wrap').count(),0);
   assert.equal(await page.locator('#'+tcell+' select').count(),1);
   await page.locator('#'+tcell+' select').selectOption(id);
   await page.waitForFunction(()=>document.getElementById('wsStatus').textContent==='ذخیره شد ✓');
   assert.equal((await db('SELECT teacher_id FROM class_schedules WHERE id=9001'))[0].teacher_id,+id);
   assert.equal(await page.locator('#'+tcell+' select').count(),0);
  }
  // Course selection and alternating-week slots use the same protected native lifecycle.
  await page.locator('#'+cell+' .ws-subj').click();await page.waitForTimeout(180);
  assert.equal(await page.locator('#'+cell+' .ss-wrap').count(),0);
  await page.locator('#'+cell+' select').selectOption('علوم');
  await page.waitForFunction(()=>document.getElementById('wsStatus').textContent==='ذخیره شد ✓');
  assert.equal((await db('SELECT subject_name FROM class_schedules WHERE id=9001'))[0].subject_name,'علوم');
  await page.locator('#'+cell+' .ws-subj').click();await page.locator('#'+cell+' select').selectOption('ریاضی');
  await page.waitForFunction(()=>document.getElementById('wsStatus').textContent==='ذخیره شد ✓');
  await page.locator('#'+cell+' .ws-halfchk').check();
  await page.locator('#'+cell+' .ws-subj2').click();await page.locator('#'+cell+' select').selectOption('علوم');
  await page.waitForFunction(()=>document.getElementById('wsStatus').textContent==='ذخیره شد ✓');
  await page.locator('#'+tcell+' .ws-teach2').click();await page.locator('#'+tcell+' select').selectOption('902');
  await page.waitForFunction(()=>document.getElementById('wsStatus').textContent==='ذخیره شد ✓');
  const halfSnapshot=await schedules();assert(halfSnapshot.some(r=>r.class_name==='هفتم1'&&r.subject_name==='علوم'&&r.teacher_id===902));
  page.once('dialog',d=>d.dismiss());await page.locator('#'+cell+' .ws-halfchk').uncheck({force:true}).catch(()=>{});
  assert(await page.locator('#'+cell+' .ws-halfchk').isChecked());assert.deepEqual(await schedules(),halfSnapshot);
  page.once('dialog',d=>d.accept());await page.locator('#'+cell+' .ws-halfchk').uncheck();
  await page.waitForFunction(()=>document.getElementById('wsStatus').textContent==='ذخیره شد ✓');
  assert.equal((await db("SELECT COUNT(*) n FROM class_schedules WHERE academic_year='1404/1405' AND class_name='هفتم1' AND day_of_week='شنبه' AND period_num='زنگ 1'"))[0].n,1);
  // Rapid edits while a save is in flight must serialize and persist the latest state.
  delay=1000;
  await page.locator('#'+tcell+' .ws-teach').click();await page.locator('#'+tcell+' select').selectOption('902');
  await page.waitForFunction(id=>document.getElementById(id).classList.contains('saving'),cell);
  await page.locator('#'+tcell+' .ws-teach').click();await page.locator('#'+tcell+' select').selectOption('903');
  await page.waitForFunction(()=>document.getElementById('wsStatus').textContent==='ذخیره شد ✓');
  assert.equal(maxPending,1);assert.equal((await db('SELECT teacher_id FROM class_schedules WHERE id=9001'))[0].teacher_id,903);delay=0;
  // Network failure stays visibly dirty; explicit retry succeeds without losing selection.
  failNext=true;await page.locator('#'+tcell+' .ws-teach').click();await page.locator('#'+tcell+' select').selectOption('901');
  await page.waitForFunction(id=>document.getElementById(id).classList.contains('err'),cell);
  await page.getByRole('button',{name:'تلاش دوباره برای ذخیره'}).click();
  await page.waitForFunction(()=>document.getElementById('wsStatus').textContent==='ذخیره شد ✓');
  await page.reload();assert((await page.locator('#'+tcell+' .ws-teach').textContent()).includes('901'));
  assert.deepEqual(errors,[]);assert(posts>=7);
  mkdirSync(REPO+'/.cache/staff',{recursive:true});await page.screenshot({path:REPO+'/.cache/staff/schedule.png',fullPage:true});
  // Real create form opens a designer window; returning refreshes the original table.
  await page.goto('https://staff.test/teacher-panel.php?tab=exams');
  const row=page.locator('.class-exam-row[data-class="هشتم1"][data-subject="ریاضی"]');
  assert.equal(await row.getByRole('button',{name:'➕ طراحی آزمون جدید',exact:true}).count(),1);
  const popupReady=page.waitForEvent('popup');await row.getByRole('button',{name:'➕ طراحی آزمون جدید',exact:true}).click();
  const popup=await popupReady;await popup.waitForURL('**/exam-print.php?**');
  const nav=page.waitForNavigation();await page.evaluate(()=>window.dispatchEvent(new Event('focus')));await nav;
  assert.equal(await row.getByRole('button',{name:'➕ طراحی آزمون جدید',exact:true}).count(),0);
  assert.equal(await row.locator('select[name="exam_id"] option').count(),1);
  assert.equal(await row.getByRole('button',{name:'🧪 ویرایش آزمون کلاسی',exact:true}).count(),1);
  await page.screenshot({path:REPO+'/.cache/staff/exam-panel.png',fullPage:true});
  await popup.close();assert.deepEqual(errors,[]);
  await page.setContent(pdf.page);await page.evaluate(()=>document.fonts.ready);
  assert.equal(await page.locator('.class-code').textContent(),'7/1');
  assert(await page.locator('.class-code').evaluate(el=>{
    const r=document.createRange();r.setStart(el.firstChild,0);r.setEnd(el.firstChild,1);const left=r.getBoundingClientRect().left;
    r.setStart(el.firstChild,2);r.setEnd(el.firstChild,3);return left<r.getBoundingClientRect().left;
  }));
  await page.pdf({path:REPO+'/.cache/staff/teacher-list.pdf',preferCSSPageSize:true,printBackground:true});
  assert.deepEqual(errors,[]);
  console.log('PASS browser: real global select enhancer + repeated picks + serialized saves + failure/retry/reload');
 }finally{await browser.close();}
}
process.exit(0);
