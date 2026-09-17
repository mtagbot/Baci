import assert from 'node:assert/strict';
import {mkdirSync,writeFileSync} from 'node:fs';
import {run,req,db,loginAdmin,php} from './harness/lib.mjs';
import {REPO} from './harness/site.mjs';
const dir=REPO+'/.cache/student-workflow/fixtures';mkdirSync(dir,{recursive:true});let n=0;
async function code(s){const r=await run(s);assert(!r.err,r.err);return r.out;}
const sid='studentWorkflow';await loginAdmin(sid);
async function request(file,options={}){const r=await req('',{file,sid,method:options.post?'POST':'GET',...options});assert(!r.res.fatal,r.res.fatal);return r.res;}
const first=await request('students.php',{query:'action=add'});writeFileSync(dir+'/add.html',first.page);
const csrf=first.page.match(/name="csrf_token" value="([^"]+)"/)[1];
assert(first.page.includes('student-profile-form'));assert(first.page.includes('serial_letter'));n++;
for(const raw of ['ب/35/356750','35/356750/ب','356750/ب/35','ب/۳۵/۳۵۶۷۵۰','۳۵۶۷۵۰']){
 const p=JSON.parse(await code(`<?php require '/www/includes/student_profile_fields.php';echo json_encode(student_serial_parts('${raw}'));`));assert(p.recognized);assert.equal(p.number,'356750');n++;
}
const base={save_student:'1',csrf_token:csrf,national_id:'0012345678',first_name:'سید محمد طاها',last_name:'آزمایشی',serial_number:'356750',serial_letter:'ب',serial_suffix:'35',academic_year:'1404/1405',grade_level:'هفتم',class_name:'هفتم1',father_phone:'۰۹۱۲۳۴۵۶۷۸۹',birth_year:'۱۳۹۰',birth_month:'۰۷',birth_day:'۱۵',gender:'male',sport_limitation_desc:'شرح آزمایشی',mother_name:'مینا',mother_last_name:'روشن',status:'active',student_code:'کد مستقل'};
await request('students.php',{post:base});
let rows=await db("SELECT * FROM students WHERE national_id='0012345678'");assert.equal(rows.length,1);let s=rows[0];assert.equal(s.serial_number,'ب/35/356750');assert.equal(s.birth_date,'1390/07/15');assert.equal(s.student_code,'کد مستقل');assert.equal(s.gender,'male');assert.equal(s.sport_limitation_desc,'شرح آزمایشی');assert.equal(s.mother_first_name,'مینا');n++;
const id=s.id;
const edit=await request('students.php',{query:'action=edit&id='+id});writeFileSync(dir+'/edit.html',edit.page);assert(edit.page.includes('value="356750"'));n++;
await code(`<?php require '/www/includes/functions.php';DB::execute("UPDATE students SET serial_number='35/356750/ب' WHERE id=${id}");`);
await request('students.php',{query:'id='+id,post:base});assert.equal((await db('SELECT serial_number FROM students WHERE id='+id))[0].serial_number,'35/356750/ب');n++;
await code(`<?php require '/www/includes/functions.php';DB::execute("UPDATE students SET serial_number=NULL,student_code='ب/03/000001' WHERE id=${id}");`);
const oldCode=await request('students.php',{query:'action=edit&id='+id});assert(oldCode.page.includes('value="000001"'));assert(oldCode.page.includes('value="03"'));n++;
await request('students.php',{query:'id='+id,post:{...base,serial_number:'000001',serial_suffix:'03',student_code:'ب/03/000001'}});assert.equal((await db('SELECT serial_number FROM students WHERE id='+id))[0].serial_number,'ب/03/000001');n++;
await request('students.php',{query:'id='+id,post:base});
const badDate=await request('students.php',{query:'id='+id,post:{...base,birth_month:'1x'}});assert(badDate.page.includes('باید فقط عدد باشند'));assert.equal((await db('SELECT birth_date FROM students WHERE id='+id))[0].birth_date,'1390/07/15');n++;
const bad=await request('students.php',{query:'id='+id,post:{...base,serial_suffix:'333',last_name:'نباید ذخیره شود'}});assert(bad.page.includes('student-errors'));assert(bad.page.includes('نباید ذخیره شود'));assert.equal((await db('SELECT last_name FROM students WHERE id='+id))[0].last_name,'آزمایشی');n++;
await code(`<?php require '/www/includes/functions.php';DB::execute("UPDATE students SET serial_number='legacy-unknown' WHERE id=${id}");`);
await request('students.php',{query:'id='+id,post:{...base,serial_number:'',serial_letter:'',serial_suffix:''}});assert.equal((await db('SELECT serial_number FROM students WHERE id='+id))[0].serial_number,'legacy-unknown');n++;
await request('students.php',{query:'id='+id,post:base});
const list=await request('students.php');writeFileSync(dir+'/list.html',list.page);
const fields=JSON.parse(await code("<?php require '/www/includes/student_profile_fields.php';echo json_encode(array_keys(student_report_columns(array_keys(student_profile_groups()))));"));
assert(fields.length>=50);assert(!fields.includes('password'));n++;
const post={csrf_token:csrf,student_ids:[id],fields_version:'2',fields,report_type:'info',format:'html'};
for(const font of ['Vazirmatn','Sahel','Yekan','CustomUploadedFont']){
 await code(`<?php require '/www/includes/functions.php';set_setting('font_family','${font}');set_setting('custom_font_url','uploads/Sahel/Sahel.ttf');`);
 const report=await request('student-bulk-report.php',{post});writeFileSync(dir+'/report-'+font+'.html',report.page);assert(report.page.includes('356750'));assert(report.page.includes('student-report-print.js'));assert(!report.page.includes('<script>window.print()'));n++;
}
for(const format of ['xls','doc']){const report=await request('student-bulk-report.php',{post:{...post,format}});assert(!report.page.includes('<script'));assert(!report.page.includes('school-ui'));n++;}
const subset=await request('student-bulk-report.php',{post:{...post,fields:['national_id','password','serial_letter','home_address']}});assert(subset.page.includes('<th>کد ملی</th>'));assert(!subset.page.includes('<th>موبایل پدر</th>'));assert(!subset.page.includes('password'));n++;
const empty=await request('student-bulk-report.php',{post:{...post,fields:[]}});assert(empty.page.includes('حداقل یک ستون'));assert(empty.page.includes('href="students.php"'));n++;
const unauth=await req('',{file:'student-bulk-report.php',sid:'studentWorkflowGuest',post});assert(!unauth.res.page.includes('0012345678'));n++;
for(const file of ['import.php','import-photos.php','grade-entry-management.php']){const r=await request(file);assert(r.page.includes('در نسخهٔ مبنا موجود نیست'));assert(r.page.includes('بازگشت به بخش مربوط'));n++;}
for(const file of ['report-print.php','exam-print.php','discipline-bulk-report.php']){const r=await request(file,{query:'id=999999&exam_id=999999'});assert(r.page.includes('امکان نمایش صفحه نیست'));assert(r.page.includes('href="index.php"'));n++;}
writeFileSync(dir+'/fields.json',JSON.stringify(fields));console.log('PASS',n,'student profile/serial/report PHP cases');process.exit(0);
