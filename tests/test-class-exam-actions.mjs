import assert from 'node:assert/strict';
process.env.CLASS_ACTIONS_TEST='1';
const {request,api,db,code,group,canonical,members,fixture,sid,other,admin,exec,csrf,fork,php}=await import('./test-class-exam-groups.mjs');
let checks=0;
const table=(html,id)=>html.match(new RegExp(`<table id="${id}"[\\s\\S]*?</table>`))[0];
const exams=()=>db('SELECT * FROM exam_schedules ORDER BY id');
const designs=()=>db('SELECT * FROM exam_designs ORDER BY id');
const beforeSchedules=await db('SELECT * FROM class_schedules ORDER BY id');
await code(`<?php require '/www/includes/db.php';foreach([['1404/1405','پنج شنبه','زنگ ۱','THURSDAY_ONE'],['1404/1405','پنجشنبه','زنگ 1','THURSDAY_TWO'],['1404/1405','یکشنبه','زنگ 2','SUNDAY_ALIAS'],['1405/1406','پنجشنبه','زنگ 1','OTHER_YEAR']] as $a)DB::execute('INSERT INTO class_schedules(teacher_id,academic_year,day_of_week,period_num,class_name,subject_name) VALUES (?,?,?,?,?,?)',[801,$a[0],$a[1],$a[2],'هفتم1',$a[3]]);`);
const scheduled=await db('SELECT * FROM class_schedules ORDER BY id');
for(const [year,present,absent] of [['1404/1405','THURSDAY_ONE','OTHER_YEAR'],['1405/1406','OTHER_YEAR','THURSDAY_ONE']]){
 const page=await request('teacher-panel.php',{query:'year='+encodeURIComponent(year)});const t=table(page.page,'teacherWeeklySchedule');assert(t.includes('data-day="پنجشنبه"'));assert.equal((t.match(/data-day=/g)||[]).length,6);assert(t.includes(present));assert(!t.includes(absent));if(year==='1404/1405')assert(t.includes('THURSDAY_TWO')&&t.includes('SUNDAY_ALIAS'));checks++;
}
assert.deepEqual(await db('SELECT * FROM class_schedules ORDER BY id'),scheduled);checks++;
const independent=await request('exam-print.php',{query:'type=questions&exam_id='+fork});assert(independent.page.includes('independent-design-notice'));assert(!independent.page.includes('class="group-design-notice"'));checks++;
// A normalized class alias in an older group must not override a persisted fork identity.
const conflicting=JSON.parse(await code(`<?php require '/www/includes/class_exam_groups.php';$id=ceg_insert_exam(801,'1404/1405','دیگر','ریاضی','هفتم2');$fakeDesign=ceg_insert_exam(801,'1404/1405','دیگر','ریاضی','');DB::execute('INSERT INTO class_exam_groups(id,identity_key,teacher_id,academic_year,grade_level,subject_name,design_exam_id) VALUES (?,?,?,?,?,?,?)',[10,'fixture-alias',801,'1404/1405','دیگر','ریاضی',$fakeDesign]);DB::execute('INSERT INTO class_exam_group_members(group_id,exam_id,class_name) VALUES (?,?,?)',[10,$id,'هفتم2']);$g=ceg_for_exam(DB::fetch('SELECT * FROM exam_schedules WHERE id=?',[${fork}]));echo json_encode(['id'=>$id,'canonical'=>$fakeDesign,'group'=>$g]);`));assert.equal(conflicting.group.id,group.id);assert.equal(conflicting.group.excluded,1);checks++;
await code(`<?php require '/www/includes/db.php';DB::execute('DELETE FROM class_exam_group_members WHERE group_id=10');DB::execute('DELETE FROM class_exam_groups WHERE id=10');DB::execute('DELETE FROM exam_schedules WHERE id=?',[${conflicting.id}]);DB::execute('DELETE FROM exam_schedules WHERE id=?',[${conflicting.canonical}]);`);
for(const session of [admin,exec]){
 const r=await request('exams.php',{session,query:'tab=class&year=1404%2F1405'});const t=table(r.page,'adminClassExams');assert(t.includes('admin-class-grade-print'));assert(t.includes('exam_id='+canonical+'&amp;grade_all=1'));assert.equal((t.match(new RegExp('exam_id='+canonical+'&amp;grade_all=1','g'))||[]).length,1);assert(t.includes('class-exam-delete.php'));assert(!t.includes('MONTHLY_SENTINEL'));checks++;
}
// Delete endpoint: GET, bad CSRF, foreign teacher, student, monthly and canonical rejected.
const remove=(id,session=sid,extra={})=>request('class-exam-delete.php',{session,post:{csrf_token:csrf,exam_id:id,year:'1404/1405',...extra}});
let oldExams=await exams(),oldDesigns=await designs();
await request('class-exam-delete.php',{query:'exam_id='+fixture.src});assert.deepEqual(await exams(),oldExams);checks++;
for(const [id,session,extra] of [[fixture.src,sid,{csrf_token:'bad'}],[fixture.foreign,sid,{}],[fixture.src,other,{}],[fixture.monthly,admin,{}],[canonical,sid,{}],[fixture.src,'harnessStu0001',{}]]){await remove(id,session,extra);assert.deepEqual(await exams(),oldExams);assert.deepEqual(await designs(),oldDesigns);checks++;}
// Inject failure after the membership change: the class remains a full member and no record is deleted.
await code(`<?php require '/www/includes/db.php';DB::getInstance()->getPdo()->rawScript("CREATE TRIGGER fail_delete BEFORE UPDATE ON exam_schedules WHEN NEW.exam_kind='class_deleted' BEGIN SELECT RAISE(ABORT,'fixture'); END;");`);
await remove(members[2].exam_id);assert.deepEqual(await exams(),oldExams);assert.equal((await db('SELECT excluded FROM class_exam_group_members WHERE exam_id='+members[2].exam_id))[0].excluded,0);checks++;
await code("<?php require '/www/includes/db.php';DB::execute('DROP TRIGGER fail_delete');");
// Deleting an active member excludes only that class; canonical and all original designs remain.
const removed=await remove(members[2].exam_id);assert(removed.redirect.includes('teacher-panel.php?tab=exams'));assert.equal(removed.flash.type,'success');
assert.equal((await exams()).find(e=>e.id===members[2].exam_id).exam_kind,'class_deleted');assert.deepEqual(await designs(),oldDesigns);assert((await api(canonical,'load')).ok);checks++;
const print=await request('exam-print.php',{query:'type=questions&exam_id='+canonical+'&grade_all=1'});assert(!print.page.includes('GroupStudent2'));assert(print.page.includes('GroupStudent0'));checks++;
let after=await exams();await remove(members[2].exam_id);assert.deepEqual(await exams(),after);checks++;
assert(!(await api(members[2].exam_id,'save',{design:{questions:[]}})).ok);const denied=await request('exam-print.php',{query:'type=questions&exam_id='+members[2].exam_id});assert(denied.page.includes('حذف شده'));checks++;
const create=await request('class-exam-create.php',{post:{csrf_token:csrf,class:'هفتم3',subject:'ریاضی',year:'1404/1405'}});assert(create.redirect.includes('exam-print.php'));checks++;
// Executive and administrator can remove other teachers' class exams, but their data/schedule is untouched.
await remove(fixture.foreign,exec,{return_to:'management'});assert.equal((await exams()).find(e=>e.id===fixture.foreign).exam_kind,'class_deleted');checks++;
await remove(fixture.future,admin,{return_to:'management',year:'1405/1406'});assert.equal((await exams()).find(e=>e.id===fixture.future).exam_kind,'class_deleted');checks++;
// Removing a detached fork must not resurrect the original hidden member.
await remove(fork);after=await exams();assert.equal(after.find(e=>e.id===fork).exam_kind,'class_deleted');assert.equal(after.find(e=>e.id===members[1].exam_id).exam_kind,'class_deleted');assert.deepEqual(await designs(),oldDesigns);checks++;
assert(!(await api(fork,'group_scope')).ok);assert(!(await api(canonical,'save',{design:{questions:[]}},'&group_member='+members[1].exam_id)).ok);checks++;
const monthly=await request('exams.php',{session:admin,query:'tab=print&year=1404%2F1405'});const mt=table(monthly.page,'adminMonthlyExams');assert(mt.includes('exam_id='+fixture.monthly+'&'));assert(!mt.includes('exam_id='+fork+'&'));assert(!table(monthly.page,'adminClassExams').includes('exam_id='+fork+'&'));checks++;
assert.deepEqual(await db('SELECT * FROM class_schedules ORDER BY id'),scheduled);assert.deepEqual((await exams()).find(e=>e.id===fixture.monthly),oldExams.find(e=>e.id===fixture.monthly));checks++;
// A general deputy has class-only access, not monthly scheduling privileges.
const deputy='classDeputy';await code(`<?php ini_set('session.save_path','/tmp/sess');session_name('BACI_TEST');session_id('${deputy}');session_start();$_SESSION=['teacher_id'=>804,'csrf_token'=>'${csrf}'];require '/www/includes/db.php';DB::execute('INSERT INTO teachers(id,national_id,full_name,password,status,academic_year,is_deputy) VALUES (?,?,?,?,?,?,?)',[804,'fixtureDeputy','معاون','hash',1,'1404/1405',1]);`);
const depPanel=await request('exams.php',{session:deputy,query:'tab=class'});assert(depPanel.page.includes('id="adminClassExams"'));assert(!depPanel.page.includes('name="create_grade_exams"'));checks++;
const depBefore=await exams();await request('exams.php',{session:deputy,post:{csrf_token:csrf,save_exam:1,exam_id:fixture.monthly}});assert.deepEqual(await exams(),depBefore);checks++;
await remove(fixture.legacy,deputy,{return_to:'management'});assert.equal((await exams()).find(e=>e.id===fixture.legacy).exam_kind,'class_deleted');assert(!(await api(canonical,'load')).ok);assert((await designs()).some(d=>d.exam_id===canonical));checks++;
const restarted=await request('class-exam-group.php',{post:{csrf_token:csrf,mode:'fresh',year:'1404/1405',grade:'هفتم',subject:'ریاضی'}});assert(restarted.redirect.includes('grade_all=1'));assert(!restarted.redirect.includes('exam_id='+canonical+'&'));checks++;

// v4.189: admin-controlled class design availability is enforced at the page and API boundaries.
const toggleExam=Number(await code(`<?php require '/www/includes/class_exam_groups.php';$id=ceg_insert_exam(801,'1404/1405','هشتم','ریاضی','هشتم1');echo $id;`));
await code(`<?php require '/www/includes/db.php';DB::execute('UPDATE exam_schedules SET teacher_design_enabled=0 WHERE id=?',[${toggleExam}]);`);
assert(!(await api(toggleExam,'save',{design:{questions:[]}})).ok);checks++;
const blockedClassEditor=await request('exam-print.php',{session:sid,query:'type=questions&exam_id='+toggleExam});
assert(blockedClassEditor.page.includes('طراحی آزمون‌های کلاسی در حال حاضر توسط مدیریت غیرفعال شده است'),blockedClassEditor.page.slice(-700));checks++;
const teacherClassPage=await request('teacher-panel.php',{session:sid,query:'tab=exams&year=1404%2F1405'});
const teacherClassTable=table(teacherClassPage.page,'teacherClassExams');
const disabledRow=teacherClassTable.match(/<tr class="class-exam-row" data-class="هشتم1" data-subject="ریاضی">[\s\S]*?<\/tr>/)?.[0]??'';
assert(disabledRow.includes('طراحی آزمون کلاسی توسط مدیریت غیرفعال است'));assert(!disabledRow.includes('class-exam-create.php?'));checks++;
const adminTogglePage=await request('exams.php',{session:admin,query:'tab=class&year=1404%2F1405'});
assert(table(adminTogglePage.page,'adminClassExams').includes('name="set_class_design_enabled"'));
const executiveTogglePage=await request('exams.php',{session:exec,query:'tab=class&year=1404%2F1405'});
assert(!table(executiveTogglePage.page,'adminClassExams').includes('name="set_class_design_enabled"'));checks++;
await request('exams.php',{session:admin,post:{csrf_token:csrf,set_class_design_enabled:1,exam_id:toggleExam,teacher_design_enabled:1}});
assert.equal((await db('SELECT teacher_design_enabled FROM exam_schedules WHERE id='+toggleExam))[0].teacher_design_enabled,1);checks++;
await request('exams.php',{session:exec,post:{csrf_token:csrf,set_class_design_enabled:1,exam_id:toggleExam,teacher_design_enabled:0}});
assert.equal((await db('SELECT teacher_design_enabled FROM exam_schedules WHERE id='+toggleExam))[0].teacher_design_enabled,1);checks++;

// Monthly print rows are nearest-upcoming first, show saved-design dates/countdowns, and mark unsaved rows.
const futureMonthly=Number(await code(`<?php require '/www/includes/class_exam_groups.php';$id=ceg_insert_exam(801,'1404/1405','هفتم','FUTURE_SENTINEL','هفتم1');DB::execute("UPDATE exam_schedules SET exam_kind='official',exam_month='FUTURE_SENTINEL',exam_date_jalali='1406/01/01',start_time='08:00',duration_minutes=90 WHERE id=?",[$id]);DB::execute('INSERT INTO exam_designs (exam_id,design_json,designer_name,updated_at_jalali) VALUES (?,?,?,?)',[$id,'{}','دبیر آزمون','1405/02/03']);echo $id;`));
const monthlyPrint=await request('exams.php',{session:admin,query:'tab=print&year=1404%2F1405'});
const monthlyTable=table(monthlyPrint.page,'adminMonthlyExams');
assert(monthlyTable.indexOf('FUTURE_SENTINEL')<monthlyTable.indexOf('1404/05/10'), monthlyTable.slice(0,5000));
assert(monthlyTable.includes('بدون طراحی')&&monthlyTable.includes('تا آزمون')&&monthlyTable.includes('۱۴۰۵/۰۲/۰۳'));
assert(!monthlyTable.includes('چاپ سربرگ سوالات'));
assert(/<tr class="exam-no-design">/.test(monthlyTable));
const dayCheck=JSON.parse(await code(`<?php require '/www/includes/exams_helper.php';echo json_encode(['sort'=>exam_jalali_date_sort_key('۱۴۰۶/۰۱/۰۱'),'days'=>exam_jalali_days_until('۱۴۰۶/۰۱/۰۱')]);`));
assert.equal(dayCheck.sort,14060101);assert(dayCheck.days>0);checks++;
console.log(`PASS ${checks} weekly/exclusion/grade-print/deletion regression scenarios`);
process.exit(0);
