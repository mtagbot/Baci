import assert from 'node:assert/strict';
import {mkdirSync,writeFileSync} from 'node:fs';
import {join} from 'node:path';
import {REPO} from './harness/site.mjs';
process.env.CLASS_ACTIONS_TEST='1';
const {code,admin}=await import('./test-class-exam-groups.mjs');
const {req}=await import('./harness/lib.mjs');
const dir=join(REPO,'.cache/mobile-hubs',process.env.HUB_BEFORE?'before-fixtures':process.env.HUB_DESKTOP?'desktop-fixtures':'fixtures');mkdirSync(dir,{recursive:true});
const groups=[
 ['courses-management.php','مدیریت دروس',[['classes','classes.php?embedded=1'],['teachers','import-teachers.php?tab=list&embedded=1'],['schedule','import-schedule.php?embedded=1'],['years','academic-years.php?embedded=1']]],
 ['reports-management.php','مدیریت کارنامه‌ها',[['reports','reports.php?embedded=1'],['wizard','import.php?embedded=1'],['analytics','analytics.php?embedded=1'],['grades','grade-entry-management.php?embedded=1'],['broadcast','report-broadcast.php?embedded=1']]],
 ['student-recovery.php','بازیابی دانش‌آموز',[['students','import-students.php?embedded=1'],['photos','import-photos.php?embedded=1']]],
 ['messages-management.php','پیامک و اعلان‌ها',[['notifications','notifications.php?embedded=1'],['sms','sms-panel.php?embedded=1']]],
 ['other-settings.php','تنظیمات دیگر',[['logs','activity-logs.php?embedded=1'],['migration','migration-updater.php?embedded=1'],['backups','backups.php?embedded=1'],['api','api/docs.php?embedded=1']]]
];
await code(`<?php require '/www/includes/functions.php';set_setting('school_name','دبیرستان بصیرت');set_setting('font_family','Vazirmatn');DB::execute("INSERT INTO reports(student_id,academic_year,term,report_month,class_name,gpa) VALUES(1,'1404/1405','نوبت اول','آبان','هفتم1',18)");`);
const cases=[];
async function capture(url,key,extra={}){
 const [file,query='']=url.split('?');const r=(await req('',{file,query,sid:admin})).res;
 assert(!r.fatal,r.fatal);assert(r.page.includes('<html'),url+': no HTML');
 const snapshot=key+'.html';writeFileSync(join(dir,snapshot),r.page);
 const unavailable=r.page.includes('در نسخهٔ مبنا موجود نیست');cases.push({url,file,query,key,snapshot,unavailable,...extra});return r;
}
for(const [file,title,tabs] of groups){
 await capture(file,file,{parent:true,title,tabs});
 for(const [key,url] of tabs){
  await capture(url,file+'-'+key,{parentFile:file,tab:key});
  if(!process.env.HUB_BEFORE){
   const r=await capture(file+'?hub_tab='+key,file+'-selected-'+key,{parent:true,selected:key});
   assert(r.page.includes('src="'+url.replaceAll('&','&amp;')+'"'),'Server selected tab');
  }
 }
}
for(const [key,url] of [['class-matrix','classes.php?tab=schedule&embedded=1'],['teacher-import','import-teachers.php?tab=import&embedded=1'],['teacher-edit','import-teachers.php?tab=edit&id=803&embedded=1'],['report-create','reports.php?action=new&embedded=1']])await capture(url,key,{extra:true});
await capture('import-teachers.php?tab=list','teacher-redirect',{redirect:true});
if(!process.env.HUB_BEFORE){
 for(const [file,title,tabs] of groups){
  for(const bad of ['unknown','%3Cscript%3E','%5B%5D']){
   const r=(await req('',{file,query:'hub_tab='+bad,sid:admin})).res;
   assert(!r.fatal,r.fatal);assert(r.page.includes('src="'+tabs[0][1].replaceAll('&','&amp;')+'"'),'Invalid tab uses safe default');
   assert((r.page.match(/aria-selected="true"/g)||[]).length===1,'Only one selected tab');
  }
  const guest=(await req('',{file,sid:'HubGuest'})).res;assert(!guest.page.includes('id="hubFrame"'),'Guest cannot bypass hub permission guard');
 }
 const arrayTab=(await req('',{file:'courses-management.php',query:'hub_tab[]=teachers',sid:admin})).res;
 assert(!arrayTab.fatal&&arrayTab.page.includes('src="classes.php?embedded=1"'),'Array query does not crash');
}
writeFileSync(join(dir,'cases.json'),JSON.stringify({groups,cases},null,2));
console.log('PASS hub fixtures:',groups.length,'parents, 17 tabs, 4 inner views. Missing modules:',cases.filter(c=>c.unavailable).map(c=>c.file));
process.exit(0);
