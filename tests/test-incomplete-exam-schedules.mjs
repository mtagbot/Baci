import assert from 'node:assert/strict';
import { php, run, req, db, loginAdmin } from './harness/lib.mjs';

const admin = 'incompleteAdmin', teacher = 'incompleteTeacher', csrf = 'incomplete-schedule';
await loginAdmin(admin);
await run(`<?php ini_set('session.save_path','/tmp/sess');session_name('BACI_TEST');session_id('${admin}');session_start();$_SESSION['csrf_token']='${csrf}';session_write_close();
ini_set('session.save_path','/tmp/sess');session_name('BACI_TEST');session_id('${teacher}');session_start();$_SESSION=['teacher_id'=>9918,'csrf_token'=>'${csrf}'];`);

const setup = await run(`<?php require_once '/www/includes/class_exam_groups.php';ensure_exams_schema();ceg_schema();
DB::execute("INSERT INTO teachers (id,national_id,full_name,password,status,academic_year) VALUES (9918,'Incomplete9918','دبیر آزمون','hash',1,'1405/1406')");
function schedule_row($subject,$date,$time,$kind='official',$duration=90,$file='') {
 DB::execute('INSERT INTO exam_schedules (academic_year,exam_month,grade_level,class_name,subject_name,teacher_id,teacher_name,exam_date_jalali,exam_day_name,start_time,duration_minutes,exam_room_default,question_file,header_config,is_active,exam_kind,created_at_jalali) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',['1405/1406','آبان','هفتم','هفتم۱',$subject,9918,'دبیر آزمون',$date,'شنبه',$time,$duration,'',$file,'{}',1,$kind,'1405/01/01']);
 return (int)DB::lastInsertId();
}
$valid=schedule_row('آزمون کامل','1405/08/02','08:00');
$noDate=schedule_row('ناقص بدون تاریخ','','08:00');
$noTime=schedule_row('ناقص بدون ساعت','1405/08/03','');
$hasDesign=schedule_row('ناقص با طراحی','','08:00');
$hasAssignment=schedule_row('ناقص با تخصیص','1405/08/04','');
$hasFile=schedule_row('ناقص با فایل','','08:00','official',90,'uploads/exams/keep.pdf');
$class=schedule_row('آزمون کلاسی','','','class');
DB::execute('INSERT INTO exam_designs (exam_id,design_json,updated_at_jalali) VALUES (?,?,?)',[$hasDesign,'{}','1405/01/01']);
DB::execute('INSERT INTO exam_assignments (exam_id,student_id,created_at_jalali) VALUES (?,?,?)',[$hasAssignment,1,'1405/01/01']);
echo json_encode(compact('valid','noDate','noTime','hasDesign','hasAssignment','hasFile','class'));`);
assert.equal(setup.err, '', setup.err);
const ids = JSON.parse(setup.out.trim());

const readiness = await run(`<?php require_once '/www/includes/exams_helper.php';$ids=json_decode(${JSON.stringify(JSON.stringify(ids))},true);$out=[];foreach($ids as $k=>$id){$out[$k]=exam_schedule_design_ready(DB::fetch('SELECT * FROM exam_schedules WHERE id=?',[$id]));}echo json_encode($out);`);
if (!readiness.out.trim().startsWith('{')) throw new Error('readiness: '+readiness.out+' ERR: '+readiness.err);
assert.deepEqual(JSON.parse(readiness.out.trim()), {
  valid:true, noDate:false, noTime:false, hasDesign:false, hasAssignment:false, hasFile:false, class:true,
});

// The teacher's monthly-design list contains complete official exams only.
const panel = await req('فهرست طراحی دبیر',{file:'teacher-panel.php',query:'tab=exams',sid:teacher});
assert(!panel.res.fatal,panel.res.fatal);
assert(panel.res.page.includes('آزمون کامل'));
for (const hidden of ['ناقص بدون تاریخ','ناقص بدون ساعت','ناقص با طراحی','ناقص با تخصیص','ناقص با فایل']) assert(!panel.res.page.includes(hidden),hidden+' must not be offered for design');

// A direct designer URL is blocked too; class exams remain designable despite having no date/time.
const badDesigner = await req('طراحی ناقص',{file:'exam-print.php',query:`type=questions&exam_id=${ids.noDate}`,sid:teacher});
assert(!badDesigner.res.fatal,badDesigner.res.fatal);
assert(badDesigner.res.page.includes('برنامهٔ امتحانی ناقص'));
php.writeFile('/harness/req.json',JSON.stringify({file:'exam-design-api.php',query:`action=save&exam_id=${ids.noDate}`,method:'POST',session_id:admin,post:{}}));
const apiResponse=await php.runStream({code:"<?php require '/harness/run_request.php';",method:'POST',body:new TextEncoder().encode(JSON.stringify({design:{questions:[]}})),headers:{'Content-Type':'application/json'}});
const apiOutput=php.readFileAsText('/harness/page.html'),apiError=await apiResponse.stderrText;
assert.equal(apiError,'',apiError);
assert(apiOutput.includes('برنامهٔ امتحانی ناقص'),apiOutput);
const classDesigner = await req('طراحی آزمون کلاسی',{file:'exam-print.php',query:`type=questions&exam_id=${ids.class}`,sid:teacher});
assert(!classDesigner.res.fatal,classDesigner.res.fatal);
assert(classDesigner.res.page.includes('exam-design-api.php'));

// Admin's save endpoint must reject a missing date before inserting any schedule row.
const countBefore = (await db('SELECT COUNT(*) AS c FROM exam_schedules'))[0].c;
const invalidSave = await req('ثبت ناقص',{file:'exams.php',method:'POST',sid:admin,post:{
  csrf_token:csrf,save_exam:'1',academic_year:'1405/1406',grade_level:'هفتم',class_name:'هفتم۱',
  subject_name:'نباید ثبت شود',exam_date_jalali:'',start_time:'08:00',duration_minutes:'90',teacher_id:'0',
}});
assert(invalidSave.res.flash?.message?.includes('ناقص'));
assert.equal((await db('SELECT COUNT(*) AS c FROM exam_schedules'))[0].c,countBefore);

// The optimizer recognizes only dependency-free malformed official rows, not class exams
// or incomplete rows still carrying a design, assignments, or a source file.
await loginAdmin(admin);
const optimizer = await req('بخش داده‌های یتیم',{file:'db-optimizer.php',query:'scan=1',sid:admin});
assert(!optimizer.res.fatal,optimizer.res.fatal);
assert(optimizer.res.page.includes('برنامه‌های امتحانی رسمی ناقص و بدون وابستگی'));
const cleanup = await req('پاکسازی هدفمند برنامه ناقص',{file:'db-optimizer.php',method:'POST',query:'scan=1',sid:admin,post:{csrf_token:csrf,do:'cleanup_incomplete_exam_schedules'}});
assert(cleanup.res.flash?.message?.includes('سایر داده‌های یتیم پاک نشدند'),cleanup.res.flash?.message);
const remaining = await db(`SELECT COUNT(*) AS c FROM exam_schedules WHERE id IN (${ids.noDate},${ids.noTime},${ids.hasDesign},${ids.hasAssignment},${ids.hasFile},${ids.class})`);
assert.equal(+remaining[0].c,4,'only the two unattached incomplete official schedules are removed');

// Bot formatter is tested as PHP output, including separate lines and accurate class/room labels.
const formatter = await run(`<?php require_once '/www/includes/bot_helpers.php';echo bot_format_student_exam_schedule('امیرمحمد حدادی',[
 ['subject_name'=>'ریاضی','exam_date_jalali'=>'1405/08/02','start_time'=>'08:00','seat_number'=>'48','class_name'=>'هفتم ۱','exam_room'=>'سالن ۲'],
 ['subject_name'=>'علوم تجربی','exam_date_jalali'=>'1405/08/04','start_time'=>'10:00','seat_number'=>'48','class_name'=>'هفتم ۱','exam_room'=>'']
]);`);
assert(formatter.out.includes('📝 برنامهٔ امتحانی امیرمحمد حدادی'));
assert(formatter.out.includes('📚 ۱. ریاضی\n'));
assert(formatter.out.includes('📅 تاریخ: ۱۴۰۵/۰۸/۰۲\n'));
assert(formatter.out.includes('🏫 کلاس: هفتم ۱'));
assert(formatter.out.includes('📍 حوزه/سالن: سالن ۲'));
assert(!formatter.out.includes('ریاضی |'));
assert(formatter.out.split('\n').length > 12);

console.log('✓ ثبت و طراحی آزمون ناقص مسدود، بخش یتیم امن، آزمون کلاسی سالم، و قالب خوانای ربات');
process.exit(0);
