// Render every discovered UI route with explicit safe fixtures; never crawl destructive GET links.
import {readFileSync,readdirSync,writeFileSync,mkdirSync} from 'node:fs';
import {join} from 'node:path';
import {REPO,resolveSite} from './harness/site.mjs';
process.env.CLASS_ACTIONS_TEST='1';
const {code,admin,sid,exec,canonical,php}=await import('./test-class-exam-groups.mjs');
const {req}=await import('./harness/lib.mjs');
const catalogRoot=join(REPO,process.env.UI_CATALOG_ROOT||'.cache/ui-audit');
const dir=join(catalogRoot,'pages');mkdirSync(dir,{recursive:true});
await code(`<?php require '/www/includes/functions.php';DB::execute("INSERT OR IGNORE INTO admins(id,username,name,password,role,status) VALUES(1,'audit','مدیر مدرسه','disabled','super_admin',1)");set_setting('school_name','دبیرستان بصیرت');set_setting('font_family','Vazirmatn');set_setting('current_academic_year','1404/1405');DB::execute("INSERT INTO reports(student_id,academic_year,term,report_month,class_name,gpa) VALUES(1,'1404/1405','نوبت اول','آبان','101',18)");`);
const reportId=await code(`<?php require '/www/includes/functions.php';echo DB::fetch('SELECT MAX(id) n FROM reports')['n'];`);
await code(`<?php require '/www/includes/functions.php';DB::execute("UPDATE teachers SET is_counselor=1,is_deputy=1 WHERE id=803");DB::execute("UPDATE online_exams SET max_attempts=10");DB::execute("INSERT INTO online_exam_attempts(id,exam_id,student_id,attempt_number,status,start_time,score,max_score,submitted_at,end_time) VALUES(10,1,1,1,'submitted',?,8,12,datetime('now'),datetime('now'))",[date('Y-m-d H:i:s')]);`);
const routes=[],inventory=[];
for(const file of [...readdirSync(resolveSite()).filter(n=>n.endsWith('.php')),'api/docs.php']){
 const s=readFileSync(join(resolveSite(),file),'utf8');
 const view=/includes\/header.php|<html|<!doctype html/i.test(s)||s.includes("includes/unavailable_route.php");
 const excluded=/^(installer|attendance-scanner-legacy|migration-|download-|export-|logout|telegram-poll)/.test(file)&&file!=='migration-updater.php';
 inventory.push({file,kind:excluded?'protected/maintenance/export':view?'page':'API/action/fragment'});
 if(!view||excluded)continue;
 let role=/^student-|^online-exam-(take|result)\.php/.test(file)?'student':/^teacher-panel/.test(file)?'teacher':/^(executive|counselor|deputy)-panel/.test(file)?'executive':'admin';
 if(file==='admin-login.php')role='guest';if(['student-recovery.php','student-bulk-report.php'].includes(file))role='admin';if(file==='class-exam-group.php')role='teacher';
 const query=new URLSearchParams({id:'1',exam_id:file==='exam-print.php'?String(canonical):'1',student_id:'1',report_id:reportId,attempt_id:'10',ignore_install:'1'});
 if(file==='report-view.php'||file==='report-print.php')query.set('id',reportId);
 if(file==='exam-print.php')query.set('type','questions');if(file==='online-exam-results.php'||file==='online-exam-take.php')query.delete('attempt_id');if(file==='class-exam-group.php'){query.set('year','1404/1405');query.set('grade','هشتم');query.set('subject','ریاضی');}
 routes.push({file,query:query.toString(),role});
}
routes.push({file:'index.php',query:'view=login&ignore_install=1',role:'guest'},{file:'teacher-panel.php',query:'tab=exams',role:'teacher'},{file:'exams.php',query:'tab=class',role:'executive'},{file:'student-panel.php',query:'tab=reports',role:'student'},{file:'entry-cards.php',query:'print=1&layout=custom&auto=0',role:'admin'},{file:'attendance-tags.php',query:'print=1&auto=0',role:'admin'},{file:'reports-lists.php',query:'action=class_list_pdf&class=هفتم1&year=1404%2F1405',role:'admin'},{file:'bulk-print.php',query:'print=1&year=1404%2F1405&month=آبان',role:'admin'});
for(const file of ['student-bulk-report.php','discipline-bulk-report.php'])routes.push({file,role:'admin',post:{csrf_token:'group-fixture',student_ids:[1,2],format:'html',report_type:file==='student-bulk-report.php'?'info':'full'}});
const result=[];
for(const [i,r] of routes.entries()){
 let response;
 try{
  response=await req('',{file:r.file,query:r.query||'',post:r.post||{},method:r.post?'POST':'GET',sid:{admin,teacher:sid,executive:exec,student:'harnessStu0001',guest:'uiGuest'}[r.role]});
  const h=response.res.page||'',hasHTML=/<html/i.test(h),snapshot=`${String(i).padStart(3,'0')}-${r.file.replaceAll('/','-')}-${r.role}.html`;
  const item={...r,snapshot,rendered:hasHTML&&!response.res.fatal,ui:hasHTML&&h.includes('school-ui.js'),bytes:Buffer.byteLength(h),fatal:response.res.fatal,issues:response.res.php_issues,redirect:response.res.redirect};
  result.push(item);if(hasHTML)writeFileSync(join(dir,snapshot),h);console.log(hasHTML?'HTML':'SKIP',r.file,r.role,item.fatal||item.redirect||'');
 }catch(e){result.push({...r,error:e.message});console.log('ERROR',r.file,e.message);}
}
writeFileSync(join(catalogRoot,'routes.json'),JSON.stringify({inventory,routes:result},null,2));
console.log('Rendered:',result.filter(r=>r.rendered).length,'/',result.length,'requests; root catalog:',inventory.length);const failures=result.filter(r=>r.error||r.fatal||(!r.rendered&&!(r.file.includes('bulk-report.php')&&!r.post)));if(failures.length)console.error('Catalog failures',failures);process.exit(failures.length?1:0);
