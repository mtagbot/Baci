import assert from 'node:assert/strict';
import {mkdirSync} from 'node:fs';
import {createRequire} from 'node:module';
import {run,req,php,db,loginAdmin} from './harness/lib.mjs';
import {REPO} from './harness/site.mjs';
const sid='groupTeacher',other='groupOther',admin='groupAdmin',exec='groupExec',csrf='group-fixture';
async function code(s){const r=await run(s);assert(!r.err,r.err);assert(!r.out.includes('Fatal error'),r.out);return r.out.trim();}
await loginAdmin(admin);
for(const [session,id] of [[sid,801],[other,802],[exec,803]])await code(`<?php ini_set('session.save_path','/tmp/sess');session_name('BACI_TEST');session_id('${session}');session_start();$_SESSION=['teacher_id'=>${id},'csrf_token'=>'${csrf}'];`);
await code(`<?php ini_set('session.save_path','/tmp/sess');session_name('BACI_TEST');session_id('${admin}');session_start();$_SESSION['csrf_token']='${csrf}';`);
const fixture=JSON.parse(await code(`<?php require '/www/includes/class_exam_groups.php';ensure_exams_schema();ceg_schema();
set_setting('current_academic_year','1404/1405');DB::execute("INSERT OR IGNORE INTO academic_years (year_name,status,is_default) VALUES ('1404/1405',1,1),('1405/1406',1,0)");
foreach([801,802,803] as $id)DB::execute('INSERT INTO teachers (id,national_id,full_name,password,status,academic_year,is_executive) VALUES (?,?,?,?,?,?,?)',[$id,'Group'.$id,'دبیر '.$id,'hash',1,'1404/1405',$id===803?1:0]);
foreach(['هفتم1','هفتم2','هفتم3','هفتم4','هشتم1'] as $i=>$c){DB::execute('INSERT INTO classes (name,grade,academic_year) VALUES (?,?,?)',[$c,infer_grade_from_class_name($c),'1404/1405']);DB::execute('INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year) VALUES (?,?,?,?,?,?,?,?)',[9801+$i,'GroupStudent'.$i,'GroupStudent'.$i,'Scope',$c,infer_grade_from_class_name($c),'active','1404/1405']);}
foreach([['هفتم1','ریاضی',801],['هفتم2','ریاضی',801],['هفتم3','ریاضی',801],['هفتم4','ریاضی',802],['هفتم1','علوم',801],['هشتم1','ریاضی',801]] as $a)DB::execute('INSERT INTO class_schedules (academic_year,class_name,subject_name,teacher_id,day_of_week,period_num) VALUES (?,?,?,?,?,?)',['1404/1405',$a[0],$a[1],$a[2],'شنبه','زنگ 1']);
$src=ceg_insert_exam(801,'1404/1405','هفتم','ریاضی','هفتم1');
$legacy=ceg_insert_exam(801,'1404/1405','هفتم','ریاضی','هفتم1');
$future=ceg_insert_exam(801,'1405/1406','هفتم','ریاضی','هفتم1');
$science=ceg_insert_exam(801,'1404/1405','هفتم','علوم','هفتم1');
$foreign=ceg_insert_exam(802,'1404/1405','هفتم','ریاضی','هفتم4');
$monthly=ceg_insert_exam(801,'1404/1405','هفتم','ریاضی','هفتم1');DB::execute("UPDATE exam_schedules SET exam_kind='official',exam_month='MONTHLY_SENTINEL' WHERE id=?",[$monthly]);
DB::execute('UPDATE exam_schedules SET question_file=? WHERE id=?',['uploads/exams/converted.pdf',$src]);
$path='uploads/exams/pdf-pages/exam_'.$src.'/page_001.jpg';
DB::execute('UPDATE exam_schedules SET header_config=?,duration_minutes=64 WHERE id=?',[json_encode(['logo'=>$path,'paths'=>[$path=>'preserved']]),$src]);
foreach([$src,$science,$legacy] as $id)DB::execute('INSERT INTO exam_designs (exam_id,design_json,designer_teacher_id,designer_name,updated_at_jalali) VALUES (?,?,?,?,?)',[$id,json_encode(['questions'=>[['html'=>'ORIGINAL_DESIGN','score'=>'1']],'asset'=>$path,'sourceCrops'=>[$path=>['src'=>$path]],'style'=>[]]),801,'دبیر 801','1404/01/01']);
echo json_encode(compact('src','science','foreign','monthly','path','legacy','future'));`));
php.mkdirTree('/www/uploads/exams/pdf-pages/exam_'+fixture.src);
const image=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a5lsAAAAASUVORK5CYII=','base64');
php.writeFile('/www/'+fixture.path,image);php.writeFile('/www/uploads/exams/pdf-pages/exam_'+fixture.src+'/pages.txt','1');
let checks=0;
async function request(file,{query='',post,session=sid,raw}={}){
 let res,err;
 if(raw!==undefined){
  php.writeFile('/harness/req.json',JSON.stringify({file,query,post:post||{},method:'POST',session_id:session}));
  const r=await php.runStream({code:"<?php require '/harness/run_request.php';",method:'POST',body:new TextEncoder().encode(JSON.stringify(raw)),headers:{'Content-Type':'application/json'}});await r.stdoutText;err=await r.stderrText;
  res=JSON.parse(php.readFileAsText('/harness/result.json'));res.page=php.readFileAsText('/harness/page.html');
 }else {const r=await req('',{file,query,post:post||{},method:post?'POST':'GET',sid:session});res=r.res;err=r.stderr;}
 assert(!err,err);assert(!res.fatal,res.fatal);
 const issues=(res.php_issues||'').split('\n').filter(x=>x&&!/headers already sent (?:by )?\(output started at \/harness\/run_request.php:33\)/.test(x));
 assert.deepEqual(issues,[],issues.join('\n'));return res;
}
const all=()=>db('SELECT * FROM exam_schedules ORDER BY id');
const designs=()=>db('SELECT * FROM exam_designs ORDER BY id');
const sourceBefore=await all(),designBefore=await designs();
const input={year:'1404/1405',grade:'هفتم',subject:'ریاضی',csrf_token:csrf};
const groupPost=p=>request('class-exam-group.php',{post:{...input,...p}});
let panel=await request('teacher-panel.php',{query:'tab=exams'});
const table=(html,id)=>html.match(new RegExp(`<table id="${id}"[\\s\\S]*?</table>`))[0];
let classTable=table(panel.page,'teacherClassExams'),monthlyTable=table(panel.page,'teacherMonthlyExams');
assert.equal((classTable.match(/ceg-start/g)||[]).length,3); // math/seventh, science/seventh, math/eighth
assert(classTable.includes('rowspan="3"'));assert(!classTable.includes('MONTHLY_SENTINEL'));assert(monthlyTable.includes('MONTHLY_SENTINEL'));assert(!monthlyTable.includes('class-exam-row'));checks++;
const choice=await request('class-exam-group.php',{query:new URLSearchParams(input).toString()});
assert(choice.page.includes('value="copy"')&&choice.page.includes('value="fresh"'));assert.deepEqual(await all(),sourceBefore);checks++;
for(const bad of [{mode:'copy',source_exam_id:fixture.foreign},{mode:'fresh',csrf_token:'bad'},{mode:'copy',source_exam_id:99999},{mode:'copy',source_exam_id:fixture.future},{mode:'copy',source_exam_id:fixture.science},{mode:'bad'}]){await groupPost(bad);assert.deepEqual(await all(),sourceBefore);assert.equal((await db('SELECT * FROM class_exam_groups')).length,0);checks++;}
await request('class-exam-group.php',{session:other,post:{...input,mode:'copy',source_exam_id:fixture.src}});assert.deepEqual(await all(),sourceBefore);checks++;
await code(`<?php unlink('/www/${fixture.path}');`);await groupPost({mode:'copy',source_exam_id:fixture.src});assert.deepEqual(await all(),sourceBefore);php.writeFile('/www/'+fixture.path,image);checks++;
// Failure in the last membership insert rolls back DB and removes only newly copied files.
await code(`<?php require '/www/includes/db.php';DB::getInstance()->getPdo()->rawScript("CREATE TRIGGER fail_group BEFORE INSERT ON class_exam_group_members WHEN NEW.class_name='هفتم3' BEGIN SELECT RAISE(ABORT,'injected'); END;");`);
await groupPost({mode:'copy',source_exam_id:fixture.src});assert.deepEqual(await all(),sourceBefore);assert.deepEqual(await designs(),designBefore);assert.equal((await db('SELECT * FROM class_exam_groups')).length,0);checks++;
assert.deepEqual(php.listFiles('/www/uploads/exams/pdf-pages'),['exam_'+fixture.src]);checks++;
await code("<?php require '/www/includes/db.php';DB::execute('DROP TRIGGER fail_group');");
const created=await groupPost({mode:'copy',source_exam_id:fixture.src});assert(created.redirect.includes('grade_all=1'),JSON.stringify(created));
let group=(await db('SELECT * FROM class_exam_groups'))[0],members=await db('SELECT * FROM class_exam_group_members ORDER BY exam_id');
assert.equal(members.length,3);const canonical=group.design_exam_id;
assert.deepEqual((await all()).filter(e=>sourceBefore.some(s=>s.id===e.id)),sourceBefore);assert.deepEqual((await designs()).filter(e=>designBefore.some(s=>s.id===e.id)),designBefore);checks++;
const copied=(await designs()).find(d=>d.exam_id===canonical),copiedJson=JSON.parse(copied.design_json);
assert(copiedJson.asset.includes('exam_'+canonical+'/'));assert.equal(Object.keys(copiedJson.sourceCrops)[0],copiedJson.asset);const scheduleCopy=(await all()).find(e=>e.id===canonical);assert.equal(scheduleCopy.duration_minutes,64);assert.equal(JSON.parse(scheduleCopy.header_config).logo,copiedJson.asset);assert.equal(Object.keys(JSON.parse(scheduleCopy.header_config).paths)[0],copiedJson.asset);assert(copiedJson.questions[0].html==='ORIGINAL_DESIGN');
assert.deepEqual(Buffer.from(php.readFileAsBuffer('/www/uploads/exams/pdf-pages/exam_'+canonical+'/page_001.jpg')),image);checks++;
const snapshot=await all();await groupPost({mode:'fresh'});assert.deepEqual(await all(),snapshot);assert.deepEqual((await designs()).find(d=>d.exam_id===canonical),copied);checks++;
// Real API JSON body, not a replacement/mock of the save implementation.
async function api(id,action,raw,extra=''){const r=await request('exam-design-api.php',{query:'exam_id='+id+'&action='+action+extra,raw});return JSON.parse(r.page);}
assert((await api(canonical,'load')).ok);assert(!(await api(fixture.src,'save',{design:{questions:[]}})).ok);checks++;
const sharedDesign={questions:[],printNote:'SHARED_LATEST',style:{}};
assert((await api(canonical,'save',{design:sharedDesign})).ok);assert.equal((await api(canonical,'load')).design.printNote,'SHARED_LATEST');checks++;
assert(!(await api(fixture.legacy,'save',{design:{questions:[]}})).ok);const legacyRoute=await request('exam-print.php',{query:'type=questions&exam_id='+fixture.legacy});assert(legacyRoute.redirect.includes('exam_id='+canonical+'&'));checks++;
const memberRoute=await request('exam-print.php',{query:'type=questions&exam_id='+members[1].exam_id});assert(memberRoute.redirect.includes('class_only='));checks++;
let sharedPrint=await request('exam-print.php',{query:'type=questions&exam_id='+canonical+'&grade_all=1'});
for(let i=0;i<3;i++)assert(sharedPrint.page.includes('GroupStudent'+i));assert(!sharedPrint.page.includes('GroupStudent3'));assert(!sharedPrint.page.includes('GroupStudent4'));checks++;
panel=await request('teacher-panel.php',{query:'tab=exams'});classTable=table(panel.page,'teacherClassExams');
assert.equal((classTable.match(/مستثنی کردن از آزمون پایه/g)||[]).length,3);assert(!classTable.includes('data-class=""'));checks++;
// Both manager roles: monthly table excludes class records; class table includes unsaved/group members.
for(const session of [admin,exec]){
 const management=await request('exams.php',{session,query:'tab=print&year=1404%2F1405'});
 assert(table(management.page,'adminMonthlyExams').includes('exam_id='+fixture.monthly+'&'));assert(!table(management.page,'adminMonthlyExams').includes('exam_id='+fixture.src+'&'));
 const ct=table(management.page,'adminClassExams');assert(ct.includes('هفتم2'));assert(!ct.includes('MONTHLY_SENTINEL'));
 const separated=await request('exams.php',{session,query:'tab=class&year=1404%2F1405'});assert(separated.page.includes('id="adminClassExams"'));assert(!separated.page.includes('name="create_grade_exams"'));checks++;
}
const otherYear=await request('exams.php',{session:admin,query:'tab=class&year=1405%2F1406'});assert(table(otherYear.page,'adminClassExams').includes('exam_id='+fixture.future+'&'));assert(!table(otherYear.page,'adminClassExams').includes('exam_id='+fixture.src+'&'));checks++;
// Protected monthly editor/delete and direct admin copy must not overwrite grouped originals.
for(const params of [{save_exam:1,exam_id:fixture.src},{copy_class_design:1,source_exam_id:fixture.src,target_assignment:'1404/1405|هفتم2|ریاضی'},{copy_class_design:1,source_exam_id:fixture.science,target_assignment:'1404/1405|هفتم2|ریاضی'}]){const before=await all();await request('exams.php',{session:admin,post:{csrf_token:csrf,...params}});assert.deepEqual(await all(),before);checks++;}
await request('exams.php',{session:admin,query:'delete='+fixture.src});assert((await all()).some(e=>e.id===fixture.src));checks++;
const bankDelete=await request('exam-design-api.php',{session:admin,post:{action:'delete_design',exam_id:fixture.science,source_ref:'e'+canonical}});assert(!JSON.parse(bankDelete.page).ok);assert.equal((await api(canonical,'load')).design.printNote,'SHARED_LATEST');checks++;
const scopeBefore=(await api(canonical,'group_scope')).scope;
const detachInput={mode:'detach',group_id:group.id,member_id:members[1].exam_id};
await request('class-exam-group.php',{session:other,post:{...input,...detachInput}});assert.equal((await db('SELECT * FROM class_exam_group_members WHERE excluded=1')).length,0);checks++;
await groupPost(detachInput);members=await db('SELECT * FROM class_exam_group_members ORDER BY exam_id');const detached=members.find(m=>m.excluded),fork=detached.detached_exam_id;
assert(fork);assert.equal((await api(fork,'load')).design.printNote,'SHARED_LATEST');assert.notEqual((await api(canonical,'group_scope')).scope,scopeBefore);checks++;
assert.deepEqual(Buffer.from(php.readFileAsBuffer('/www/uploads/exams/pdf-pages/exam_'+fork+'/page_001.jpg')),image);php.writeFile('/www/uploads/exams/pdf-pages/exam_'+canonical+'/page_001.jpg','changed canonical only');assert.deepEqual(Buffer.from(php.readFileAsBuffer('/www/uploads/exams/pdf-pages/exam_'+fork+'/page_001.jpg')),image);php.writeFile('/www/uploads/exams/pdf-pages/exam_'+canonical+'/page_001.jpg',image);checks++;
assert(!(await api(detached.exam_id,'save',{design:{questions:[]}})).ok);const excludedRoute=await request('exam-print.php',{query:'type=questions&exam_id='+detached.exam_id});assert(excludedRoute.redirect.includes('exam_id='+fork+'&'));checks++;
const staleUpload=await request('exam-print.php',{query:'type=questions&exam_id='+canonical+'&class_only='+detached.exam_id,post:{upload_question_source:1}});assert(staleUpload.page.includes('دیگر عضو فعال'));checks++;
for(const id of [fixture.src,detached.exam_id,canonical]){const originalFile=(await all()).find(e=>e.id===id).question_file;const staleSource=await request('exam-source-api.php',{query:'group_member='+detached.exam_id,post:{action:'delete',exam_id:id}});assert(!JSON.parse(staleSource.page).ok);assert.equal((await all()).find(e=>e.id===id).question_file,originalFile);checks++;}
const forkSource=await request('exam-source-api.php',{post:{action:'delete',exam_id:fork}});assert(JSON.parse(forkSource.page).ok);assert(php.fileExists('/www/uploads/exams/pdf-pages/exam_'+canonical+'/page_001.jpg'));assert(php.fileExists('/www/'+fixture.path));checks++;
const afterDetach=await all();await groupPost(detachInput);assert.deepEqual(await all(),afterDetach);checks++;
assert((await api(fork,'save',{design:{questions:[],printNote:'INDEPENDENT'}})).ok);
assert.equal((await api(canonical,'load')).design.printNote,'SHARED_LATEST');
assert((await api(canonical,'save',{design:{questions:[],printNote:'GROUP_UPDATED'}})).ok);
assert.equal((await api(fork,'load')).design.printNote,'INDEPENDENT');checks++;
assert(!(await api(canonical,'save',{design:{questions:[]}},'&group_member='+detached.exam_id)).ok);checks++;
sharedPrint=await request('exam-print.php',{query:'type=questions&exam_id='+canonical+'&grade_all=1'});
assert(!sharedPrint.page.includes('GroupStudent1'));assert(sharedPrint.page.includes('GroupStudent0')&&sharedPrint.page.includes('GroupStudent2'));checks++;
const individual=await request('exam-print.php',{query:'type=questions&exam_id='+fork+'&grade_all=1'});assert(individual.page.includes('GroupStudent1'));assert(!individual.page.includes('GroupStudent0'));checks++;
assert.deepEqual((await all()).filter(e=>sourceBefore.some(s=>s.id===e.id)),sourceBefore);assert.deepEqual((await designs()).filter(d=>designBefore.some(s=>s.id===d.id)),designBefore);checks++;
await code(`<?php require '/www/includes/db.php';DB::execute("UPDATE class_schedules SET teacher_id=802 WHERE teacher_id=801 AND class_name='هفتم3' AND subject_name='ریاضی'");`);
const reassigned=await request('exam-print.php',{query:'type=questions&exam_id='+canonical+'&grade_all=1'});assert(!reassigned.page.includes('GroupStudent2'));assert(!(await api(canonical,'save',{design:{questions:[]}},'&group_member='+members[2].exam_id)).ok);checks++;
await code(`<?php require '/www/includes/db.php';DB::execute("UPDATE class_schedules SET teacher_id=801 WHERE teacher_id=802 AND class_name='هفتم3'");`);
// Start fresh in a different subject: retain old design but canonical starts empty.
await request('class-exam-group.php',{post:{...input,subject:'علوم',mode:'fresh'}});
const fresh=(await db("SELECT * FROM class_exam_groups WHERE subject_name='علوم'"))[0];assert(!(await api(fresh.design_exam_id,'load')).design);assert.equal(JSON.parse((await designs()).find(d=>d.exam_id===fixture.science).design_json).questions[0].html,'ORIGINAL_DESIGN');checks++;
// New metadata participates in the existing ID/change-log/snapshot/push protocol.
php.writeFile('/www/desk-sync-api.php',"<?php define('DESK_SYNC_KEY','fixture-sync-key-not-real');");
const legacyEndpoint=php.readFileAsText('/www/desk-sync-api.php');
const sync=async payload=>JSON.parse((await request('class-exam-sync-api.php',{raw:{key:'fixture-sync-key-not-real',...payload}})).page);
assert(!(await sync({key:'wrong',action:'handshake'})).ok);checks++;
const handshake=await sync({action:'handshake'});assert(handshake.ok);for(const t of ['class_exam_groups','class_exam_group_members'])assert(handshake.tables.includes(t));checks++;
for(const t of ['class_exam_groups','class_exam_group_members']){const snap=await sync({action:'snapshot',tbl:t});assert(snap.ok);assert.deepEqual(snap.rows,await db('SELECT * FROM '+t+' ORDER BY id'));assert(snap.rows.every(r=>r.id>=5000000));assert((await db("SELECT * FROM desk_change_log WHERE tbl='"+t+"'")).length>0);const pushed=await sync({action:'push',changes:snap.rows.map(row=>({tbl:t,rid:row.id,op:'U',row}))});assert.equal(pushed.applied,snap.rows.length);checks++;}
assert.equal(php.readFileAsText('/www/desk-sync-api.php'),legacyEndpoint);checks++;
const conflict=await sync({action:'push',changes:[{tbl:'class_exam_groups',rid:42,op:'U',row:{...group,id:42}}]});assert(!conflict.ok);assert.equal((await db('SELECT * FROM class_exam_groups WHERE identity_key='+JSON.stringify(group.identity_key)))[0].id,group.id);checks++;
const orphan=Number(await code(`<?php require '/www/includes/class_exam_groups.php';echo ceg_insert_exam(801,'1404/1405','هفتم','ریاضی','');`));assert(!(await api(orphan,'load')).ok);const orphanPrint=await request('exam-print.php',{query:'type=questions&exam_id='+orphan});assert(orphanPrint.page.includes('هنوز همگام نشده'));checks++;
await code(`<?php require '/www/includes/db.php';DB::execute('DELETE FROM exam_schedules WHERE id=?',[${orphan}]);`);
console.log(`PASS ${checks} class-group PHP/API/sync scenarios`);

if(process.env.GROUP_BROWSER){
 const require=createRequire(REPO+'/.cache/browser/node_modules/_resolver.cjs');const {chromium:pw}=require('playwright'),mod=require('@sparticuz/chromium'),chromium=mod.default||mod;
 const browser=await pw.launch({executablePath:await chromium.executablePath(),args:chromium.args,headless:true});
 try{
  const context=await browser.newContext({viewport:{width:1400,height:1100}}),errors=[];
  // Every app page/API is rendered by real PHP. Routes stay entirely inside this isolated fixture.
  let queue=Promise.resolve();
  await context.route('**/*',route=>{const task=async()=>{
   const u=new URL(route.request().url()),file=decodeURIComponent(u.pathname).slice(1);
   if(['teacher-panel.php','class-exam-group.php','class-exam-create.php','exam-print.php','exam-design-api.php','exam-source-api.php','exams.php'].includes(file)){
    const body=route.request().postData(),json=route.request().headers()['content-type']?.includes('application/json');
    const r=await request(file,{query:u.search.slice(1),post:body&&!json?Object.fromEntries(new URLSearchParams(body)):undefined,raw:body&&json?JSON.parse(body):undefined});
    if(r.redirect)return route.fulfill({contentType:'text/html',body:'<!doctype html><script>location.replace('+JSON.stringify(new URL(r.redirect,u).href)+');</script>'});
    return route.fulfill({contentType:file.endsWith('api.php')?'application/json':'text/html',body:r.page});
   }
   if(file.endsWith('.php'))return route.fulfill({contentType:'application/json',body:'{}'});
   const virtual='/www/'+file;
   if(php.fileExists(virtual))return route.fulfill({contentType:file.endsWith('.js')?'application/javascript':file.endsWith('.css')?'text/css':'application/octet-stream',body:Buffer.from(php.readFileAsBuffer(virtual))});
   return route.fulfill({status:404,body:''});
  };queue=queue.then(task);return queue;});
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.goto('https://groups.test/teacher-panel.php?tab=exams');
  assert.equal(await page.locator('#teacherClassExams .ceg-start').count(),3);
  assert.equal(await page.locator('#teacherClassExams button').filter({hasText:'مستثنی کردن از آزمون پایه'}).count(),3); // two math + one science
  // Ungrouped eighth grade: popup + cancel writes nothing, then fresh submits exactly once.
  const link=page.locator('.ceg-start[data-dialog]');assert.equal(await link.count(),1);
  const beforeBrowser=await all();await link.click();const modal=page.locator('.ceg-modal:not([hidden])');assert.equal(await modal.count(),1);
  await modal.getByRole('button',{name:'انصراف',exact:true}).click();assert.deepEqual(await all(),beforeBrowser);
  await link.click();const pop=page.waitForEvent('popup');await modal.getByRole('button',{name:'شروع از نو برای همهٔ کلاس‌های گروه'}).click();
  const editor=await pop;editor.on('pageerror',e=>errors.push(e.message));await editor.waitForURL('**/exam-print.php?**');await editor.waitForFunction(()=>typeof booting!=='undefined'&&!booting);
  assert.equal(await editor.locator('.group-design-notice').count(),1);
  const nav=page.waitForNavigation();await page.evaluate(()=>{window.classExamPending=true;window.dispatchEvent(new Event('focus'));});await nav;
  assert.equal(await page.locator('.ceg-start[data-dialog]').count(),0);
  // Existing source -> copy choice -> shared save -> per-class exception, all through real windows.
  const ninth=Number(await code(`<?php require '/www/includes/class_exam_groups.php';foreach(['نهم1','نهم2'] as $c){DB::execute('INSERT INTO classes(name,grade,academic_year) VALUES (?,?,?)',[$c,'نهم','1404/1405']);DB::execute('INSERT INTO class_schedules(academic_year,class_name,subject_name,teacher_id,day_of_week,period_num) VALUES (?,?,?,?,?,?)',['1404/1405',$c,'ریاضی',801,'شنبه','زنگ 1']);}$id=ceg_insert_exam(801,'1404/1405','نهم','ریاضی','نهم1');DB::execute('INSERT INTO exam_designs(exam_id,design_json,updated_at_jalali) VALUES (?,?,?)',[$id,json_encode(['questions'=>[],'printNote'=>'COPY_FROM_CLASS']),jalali_now()]);echo $id;`));
  php.writeFile('/www/uploads/exams/browser-source.png',image);await code(`<?php require '/www/includes/db.php';DB::execute('UPDATE exam_schedules SET question_file=? WHERE id=?',['uploads/exams/browser-source.png',${ninth}]);`);
  await page.reload();await page.locator('.ceg-start[data-dialog]').click();
  assert.equal(await modal.locator('select[name="source_exam_id"]').inputValue(),String(ninth));
  const copyPop=page.waitForEvent('popup');await modal.getByRole('button',{name:'کپی آزمون انتخابی برای گروه'}).click();const sharedEditor=await copyPop;sharedEditor.on('pageerror',e=>errors.push(e.message));
  await sharedEditor.waitForURL('**/exam-print.php?**');await sharedEditor.waitForFunction(()=>!booting);assert.equal(await sharedEditor.locator('#printNote').inputValue(),'COPY_FROM_CLASS');
  await sharedEditor.getByRole('button',{name:'تنظیمات برگه',exact:true}).click();await sharedEditor.locator('#printNote').fill('LATEST_IN_BROWSER');const saving=sharedEditor.waitForResponse('**/exam-design-api.php?**action=save**');await sharedEditor.getByRole('button',{name:'ذخیره طراحی',exact:true}).click();assert((await (await saving).json()).ok);
  await page.reload();const ninthRow=page.locator('.class-exam-row[data-class="نهم1"]');const classPop=page.waitForEvent('popup');await ninthRow.getByRole('link',{name:'ویرایش / چاپ این کلاس'}).click();const oldClass=await classPop;await oldClass.waitForURL('**/exam-print.php?**class_only=**');await oldClass.waitForFunction(()=>!booting);
  page.once('dialog',d=>d.accept());const forkPop=page.waitForEvent('popup');await ninthRow.getByRole('button',{name:'مستثنی کردن از آزمون پایه'}).click();const forkEditor=await forkPop;await forkEditor.waitForURL('**/exam-print.php?**');await forkEditor.waitForFunction(()=>!booting);assert.equal(await forkEditor.locator('#printNote').inputValue(),'LATEST_IN_BROWSER');
  const ninthGroup=(await db("SELECT * FROM class_exam_groups WHERE grade_level='نهم'"))[0];const fileSchedules=await all();const canonicalFile=fileSchedules.find(e=>e.id===ninthGroup.design_exam_id).question_file;const forkId=Number(new URL(forkEditor.url()).searchParams.get('exam_id'));const forkFile=fileSchedules.find(e=>e.id===forkId).question_file;assert.notEqual(forkFile,canonicalFile);assert.notEqual(canonicalFile,'uploads/exams/browser-source.png');assert.deepEqual(Buffer.from(php.readFileAsBuffer('/www/'+forkFile)),image);
  assert.equal((await oldClass.evaluate(()=>saveDesign(true))).ok,false);
  sharedEditor.once('dialog',d=>d.accept());assert.equal(await sharedEditor.evaluate(()=>checkClassGroupScope()),false);
  await forkEditor.getByRole('button',{name:'تنظیمات برگه',exact:true}).click();await forkEditor.locator('#printNote').fill('FORK_BROWSER');assert((await forkEditor.evaluate(()=>saveDesign(true))).ok);await sharedEditor.reload();await sharedEditor.waitForFunction(()=>!booting);assert.equal(await sharedEditor.locator('#printNote').inputValue(),'LATEST_IN_BROWSER');
  await page.reload();assert.equal(await page.locator('.ceg-start').count(),4);assert.equal(await ninthRow.getByRole('button',{name:'مستثنی کردن از آزمون پایه'}).count(),0);
  mkdirSync(REPO+'/.cache/groups',{recursive:true});await page.screenshot({path:REPO+'/.cache/groups/teacher.png',fullPage:true});
  assert.deepEqual(errors,[]);
  console.log('PASS Chromium: separate tables, one button, cancel/copy/fresh windows, real shared save, exception snapshot, independent save, stale-editor and print-scope rejection');
 }finally{await browser.close();}
}
process.exit(0);
