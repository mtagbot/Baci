import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { run, req, db, loginAdmin } from './harness/lib.mjs';
import { REPO } from './harness/site.mjs';

const sid='disciplineTransferAdmin', csrf='discipline-transfer-test';
let checks=0;const check=(v,m)=>{assert(v,m);checks++;};
async function code(s){const r=await run(s);assert(!r.err,r.err);assert(!r.out.includes('Fatal error'),r.out);return r.out.trim();}
await loginAdmin(sid);
await code(`<?php ini_set('session.save_path','/tmp/sess');session_name('BACI_TEST');session_id('${sid}');session_start();$_SESSION['csrf_token']='${csrf}';`);
await code(`<?php require '/www/includes/school_roles.php';ensure_school_roles_schema();
set_setting('current_academic_year','1404/1405');
DB::execute("INSERT OR IGNORE INTO academic_years (year_name,status,is_default) VALUES ('1404/1405',1,1),('1405/1406',1,0)");
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year) VALUES (9911,'9911000001','آزمایشی','یک','هفتم1','هفتم','active','1404/1405'),(9912,'9912000001','آزمایشی','دو','هفتم1','هفتم','active','1404/1405')");
DB::execute("INSERT INTO class_schedules (academic_year,class_name,subject_name,teacher_id,day_of_week,period_num) VALUES ('1404/1405','هفتم1','ریاضی',1,'شنبه','۱'),('1405/1406','هفتم1','ریاضی',1,'شنبه','۱')");
DB::execute("INSERT INTO reports (student_id,class_name,academic_year,term,report_month,total_score,gpa,discipline_score) VALUES (9911,'هفتم1','1404/1405','نوبت اول','آبان',16,16,18),(9912,'هفتم1','1404/1405','نوبت اول','آبان',14,14,17),(9911,'هفتم1','1405/1406','نوبت اول','آبان',0,0,NULL)");
DB::execute("INSERT INTO report_grades (report_id,subject_name,score,max_score,coefficient,status) SELECT id,'ریاضی',16,20,1,'passed' FROM reports WHERE student_id=9911 AND academic_year='1404/1405'");
DB::execute("INSERT INTO report_grades (report_id,subject_name,score,max_score,coefficient,status) SELECT id,'ریاضی',14,20,1,'passed' FROM reports WHERE student_id=9912 AND academic_year='1404/1405'");
DB::execute("INSERT INTO student_discipline_records (student_id,title_text,occurred_at_jalali) VALUES (9911,'تاخیر آزمایشی','1404/07/01')");`);

async function request(file,{method='GET',query='',post={}}={}){
 const {res,stderr}=await req('',{file,query,sid,method,post});
 assert(!res.fatal,`${file}: ${res.fatal||''}`);assert(!stderr,stderr);return res;
}
const discipline=await request('discipline-report-management.php',{query:'embedded=1&year=1404%2F1405&month=%D8%A2%D8%A8%D8%A7%D9%86&grade=%D9%87%D9%81%D8%AA%D9%85&class=%D9%87%D9%81%D8%AA%D9%851'});
check(discipline.page.includes('تاخیر آزمایشی')&&discipline.page.includes('سال‌های گذشته'),'Discipline list shows item and relative age');
check(discipline.page.includes('name="scores[9911]"')&&discipline.page.includes('مدیریت انضباطی کارنامه'),'Discipline score is editable in roster');
const disciplineCsrf=(discipline.page.match(/name="csrf_token" value="([^"]+)"/)||[])[1];
check(!!disciplineCsrf,'Discipline form has CSRF token');
await request('discipline-report-management.php',{method:'POST',post:{csrf_token:disciplineCsrf,save_discipline_scores:'1',embedded:'1',year:'1404/1405',month:'آبان',grade:'هفتم',class:'هفتم1',scores:{9911:'19.5'}}});
check((await db("SELECT discipline_score FROM reports WHERE student_id=9911 AND academic_year='1404/1405' AND report_month='آبان'"))[0].discipline_score===19.5,'Group save writes discipline score to report');

const transfer=await request('report-grade-transfer.php',{query:'embedded=1&src_year=1404%2F1405&src_month=all&src_class=&src_student=all&src_subject=all&dst_year=1405%2F1406&dst_month=all&dst_class=&dst_student=all&dst_subject=all'});
check(transfer.page.includes('رونوشت نمرات')&&transfer.page.includes('انتقال نمرات'),'Transfer UI has both safe actions');
check(!transfer.page.includes('همه سال‌ها'),'Year selectors have no all-years option');
const fields={src_year:'1404/1405',src_month:'all',src_class:'',src_student:'all',src_subject:'all',dst_year:'1405/1406',dst_month:'all',dst_class:'',dst_student:'all',dst_subject:'all',embedded:'1',csrf_token:disciplineCsrf};
await request('report-grade-transfer.php',{method:'POST',post:{...fields,run_grade_transfer:'copy'}});
const copied=await db("SELECT g.score,r.student_id FROM report_grades g JOIN reports r ON r.id=g.report_id WHERE r.academic_year='1405/1406'");
check(copied.length===1&&copied[0].student_id===9911&&copied[0].score===16,'Copy maps matching student and subject; absent destination student is skipped');
check((await db("SELECT COUNT(*) n FROM report_grades g JOIN reports r ON r.id=g.report_id WHERE r.academic_year='1404/1405'"))[0].n===2,'Copy preserves all source grades');
await request('report-grade-transfer.php',{method:'POST',post:{...fields,run_grade_transfer:'move'}});
const moved=await db("SELECT g.score,r.student_id FROM report_grades g JOIN reports r ON r.id=g.report_id WHERE r.academic_year='1405/1406'");
check(moved.length===1&&moved[0].student_id===9911&&moved[0].score===16,'Move keeps destination grade');
const remaining=await db("SELECT g.score,r.student_id FROM report_grades g JOIN reports r ON r.id=g.report_id WHERE r.academic_year='1404/1405'");
check(remaining.length===1&&remaining[0].student_id===9912,'Move removes source only after matching target save; unmatched source remains');
const hub=readFileSync(`${REPO}/update-v4.152.0/includes/management_hub.php`,'utf8');
const columns=hub.indexOf('مدیریت ستونی'),disc=hub.indexOf('مدیریت انضباطی'),gradeTransfer=hub.indexOf('انتقال نمرات');
check(columns>=0&&disc>columns&&gradeTransfer>disc,'Management tabs appear after columns in requested order');
console.log('PASS',checks,'discipline and grade-transfer checks');
