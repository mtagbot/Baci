import assert from 'node:assert/strict';
import {readFileSync,writeFileSync,mkdirSync} from 'node:fs';
import {join} from 'node:path';
import {run,req,php,db,loginAdmin} from './harness/lib.mjs';
import {REPO} from './harness/site.mjs';
const sid='reportToolsAdmin',csrf='report-tools-test';let checks=0;
const outdir=join(REPO,process.env.REPORT_TOOLS_DESKTOP?'.cache/restore/desktop-fixtures':'.cache/restore/fixtures');mkdirSync(outdir,{recursive:true});
const check=(v,m)=>{assert(v,m);checks++;};
async function code(s){const r=await run(s);assert(!r.err,r.err);assert(!r.out.includes('Fatal error'),r.out);return r.out.trim();}
await loginAdmin(sid);
await code(`<?php ini_set('session.save_path','/tmp/sess');session_name('BACI_TEST');session_id('${sid}');session_start();$_SESSION['csrf_token']='${csrf}';`);
await code(`<?php require '/www/includes/school_roles.php';require '/www/includes/grade_permissions.php';ensure_school_roles_schema();ensure_grade_permissions_schema();
set_setting('current_academic_year','1404/1405');DB::execute("INSERT OR IGNORE INTO academic_years (year_name,status,is_default) VALUES ('1404/1405',1,1),('1405/1406',1,0)");
DB::execute("INSERT INTO teachers (id,national_id,full_name,password,status,academic_year) VALUES (801,'RESTORE801','دبیر آزمایشی','hash',1,'1404/1405')");
DB::execute("INSERT INTO class_schedules (academic_year,class_name,subject_name,teacher_id,day_of_week,period_num) VALUES ('1404/1405','هفتم1','ریاضی',801,'شنبه','زنگ 1')");
DB::execute("INSERT INTO classes (name,grade,academic_year) VALUES ('هفتم1','هفتم','1404/1405')");
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year) VALUES (9901,'0012345678','دانش‌آموز','آزمایشی','هفتم1','هفتم','active','1404/1405')");`);
for(const [s,values] of [['reportToolsOther',{admin_id:2,admin_role:'super_admin',csrf_token:csrf}],['reportToolsTeacher',{teacher_id:801,csrf_token:csrf}],['reportToolsDenied',{admin_id:3,admin_role:'staff',csrf_token:csrf}]])await code(`<?php ini_set('session.save_path','/tmp/sess');session_name('BACI_TEST');session_id('${s}');session_start();$_SESSION=json_decode('${JSON.stringify(values)}',true);`);
async function request(file,{post,query='',session=sid}={}){const {res,stderr}=await req('',{file,query,sid:session,method:post?'POST':'GET',post:post||{}});check(!res.fatal,res.fatal);assert(!stderr || /^Report import failed: .*fixture failure\s*$/.test(stderr),stderr);return res;}
for(const file of ['import.php','grade-entry-management.php']){
 const r=await request(file,{query:'embedded=1'});check(!r.page.includes('در نسخهٔ مبنا موجود نیست')&&r.page.includes('<form'),file+' restored');writeFileSync(join(outdir,file+'.html'),r.page);
 for(const session of ['reportToolsGuest','reportToolsTeacher','reportToolsDenied'])check((await request(file,{session})).redirect,'Permission gate '+file);
}
const rule={csrf_token:csrf,save_rule:1,academic_year:'۱۴۰۴/۱۴۰۵',report_month:'آبان',grade_level:'',class_name:'هفتم1',subject_name:'ریاضی',teacher_id:'801',note:'قانون آزمایشی'};
await request('grade-entry-management.php',{post:{...rule,csrf_token:'bad'}});check(!(await db('SELECT * FROM grade_entry_permissions')).length,'CSRF no rule');
await request('grade-entry-management.php',{post:{...rule,teacher_id:'999999'}});check(!(await db('SELECT * FROM grade_entry_permissions')).length,'Invalid teacher rejected');
await request('grade-entry-management.php',{post:rule});const id=(await db('SELECT * FROM grade_entry_permissions'))[0].id;
const allowed=async(month='آبان',year='1404/1405')=>code(`<?php require '/www/includes/school_sort.php';require '/www/includes/grade_permissions.php';echo can_teacher_enter_grade(801,'${year}','${month}','هفتم1','ریاضی')?'YES':'NO';`);
check(await allowed()==='NO','Rule enforced');check(await allowed('آذر')==='YES','Month scope');check(await allowed('آبان','1405/1406')==='YES','Year scope');
const grades=()=>db('SELECT * FROM report_grades ORDER BY id');const before=await grades();
await request('teacher-panel.php',{session:'reportToolsTeacher',post:{csrf_token:csrf,save_grades:1,class_name:'هفتم1',subject_name:'ریاضی',academic_year:'1404/1405',report_month:'آبان',student_id:[9901],score:['19']}});assert.deepEqual(await grades(),before);checks++;
await request('grade-entry-management.php',{post:{csrf_token:'wrong',delete_rule:1,id}});check(await allowed()==='NO','Bad CSRF cannot delete');
const rulepage=await request('grade-entry-management.php',{query:'embedded=1'});writeFileSync(join(outdir,'rules.html'),rulepage.page);
await request('grade-entry-management.php',{post:{csrf_token:csrf,delete_rule:1,id}});check(await allowed()==='YES','Delete reopens scope');
await request('teacher-panel.php',{session:'reportToolsTeacher',post:{csrf_token:csrf,save_grades:1,class_name:'هفتم1',subject_name:'ریاضی',academic_year:'1404/1405',report_month:'آبان',student_id:[9901],score:['17']}});check((await grades()).some(g=>g.score===17),'Real teacher save works after delete');
// Genuine multipart upload: PHP creates an uploaded temp file. No bypass of is_uploaded_file().
let harness=readFileSync(join(REPO,'tests/harness/run_request.php'),'utf8').replace("$post    = $spec['post'] ?? [];","$post    = $spec['post'] ?? $_POST;").replace("$_FILES = $spec['files'] ?? [];","$_FILES = $spec['files'] ?? $_FILES;");
php.writeFile('/harness/upload_request.php',harness);
async function upload(data,name='grades.csv',token=csrf){
 php.writeFile('/harness/req.json',JSON.stringify({file:'import.php',query:'embedded=1',method:'POST',session_id:sid}));
 const fd=new FormData();fd.append('csrf_token',token);fd.append('action','upload_file');fd.append('import_file',new File([data],name));const response=new Response(fd);
 const r=await php.run({code:"<?php require '/harness/upload_request.php';",method:'POST',body:new Uint8Array(await response.arrayBuffer()),headers:{'Content-Type':response.headers.get('content-type')}});
 const res=JSON.parse(php.readFileAsText('/harness/result.json'));check(!res.fatal,res.fatal);assert(!r.errors,r.errors);return res;
}
const header='national_id,first_name,last_name,class_name,academic_year,term,report_month,subject_name,score,max_score,coefficient\n';
const line=(subject,score='۱۸/۵۰',year='1404/1405',nid='0012345678')=>`${nid},دانش‌آموز,آزمایشی,هفتم1,${year},نوبت اول,آبان,${subject},${score},20,2\n`;
const sessions=()=>db('SELECT * FROM import_sessions ORDER BY id');
await upload(header+line('ریاضی'),'grades.csv','wrong');check(!(await sessions()).length,'Bad upload CSRF');
await upload(header+line('ریاضی'),'grades.xls');check(!(await sessions()).length,'Legacy XLS fails honestly');
await upload(header+line('ریاضی')+'too,few,columns\n');check(!(await sessions()).length,'Malformed CSV rejected');
await upload(header+line('ریاضی')+line('علوم','21')+line('انضباط','20'));
let session=(await sessions()).at(-1);check(session.total_rows===3&&session.processed_rows===0,'Upload preview only');
const preview=await request('import.php',{query:'step=2&session_id='+session.id+'&embedded=1'});writeFileSync(join(outdir,'preview.html'),preview.page);
check(preview.page.includes('تایید و ثبت'),'Preview form');const prior=await grades();
await request('import.php',{query:'step=4&session_id='+session.id});check(!(await request('import.php',{query:'step=4&session_id='+session.id})).page.includes('ایمپورت با موفقیت انجام شد!'),'Cannot forge success screen');
const execute=(id,opts={})=>request('import.php',{session:opts.session||sid,post:{action:'execute_import',session_id:id,target_academic_year:opts.year||'',csrf_token:opts.csrf||csrf}});
await execute(session.id,{csrf:'wrong'});assert.deepEqual(await grades(),prior);checks++;
await execute(session.id,{session:'reportToolsOther'});assert.deepEqual(await grades(),prior);checks++;
const foreign=await request('import.php',{query:'step=2&session_id='+session.id,session:'reportToolsOther'});check(!foreign.page.includes('0012345678'),'No foreign preview data');
// A subject absent from the file must survive the merge; no student reclassification.
await code(`<?php require '/www/includes/functions.php';DB::execute("INSERT INTO reports (student_id,class_name,academic_year,term,report_month,total_score,gpa) VALUES (9901,'هفتم1','1404/1405','نوبت اول','آبان',10,10)");$id=DB::lastInsertId();DB::execute("INSERT INTO report_grades (report_id,subject_name,score,max_score,coefficient,status) VALUES (?,'زبان',10,20,1,'passed')",[$id]);`);
const studentBefore=await db('SELECT * FROM students WHERE id=9901');
await execute(session.id);check((await sessions()).at(-1).processed_rows===3,'Three actual writes counted');
const target=(await db("SELECT * FROM reports WHERE student_id=9901 AND term='نوبت اول'"))[0];
const tg=await db('SELECT * FROM report_grades WHERE report_id='+target.id);
check(tg.length===4&&tg.some(g=>g.subject_name==='زبان'),'Unrelated subject preserved');check(tg.find(g=>g.subject_name==='ریاضی').score===18.5,'Persian decimal');check(tg.find(g=>g.subject_name==='علوم').status==='none','Absence status');check(target.gpa===14.25&&target.discipline_score===20,'Existing GPA and discipline semantics');assert.deepEqual(await db('SELECT * FROM students WHERE id=9901'),studentBefore);checks++;
const committed=await grades();await execute(session.id);assert.deepEqual(await grades(),committed);checks++;
const result=await request('import.php',{query:'step=4&session_id='+session.id+'&embedded=1'});writeFileSync(join(outdir,'result.html'),result.page);check(result.page.includes('ایمپورت با موفقیت انجام شد!'),'Actual success only');
// Whole transaction rolls back, including earlier subjects and the session claim.
await upload(header+line('اول','12')+line('خرابی','13'));const fail=(await sessions()).at(-1);
await code(`<?php require '/www/includes/db.php';DB::getInstance()->getPdo()->rawScript("CREATE TRIGGER fail_import BEFORE INSERT ON report_grades WHEN NEW.subject_name='خرابی' BEGIN SELECT RAISE(ABORT,'fixture failure'); END;");`);
await execute(fail.id);assert.deepEqual(await grades(),committed);check((await sessions()).at(-1).status==='preview','Failed transaction retryable');
await code("<?php require '/www/includes/db.php';DB::execute('DROP TRIGGER fail_import');");
for(const contents of [header+line('ریاضی','bad'),header+line('ریاضی','22'),header+line('ریاضی',''),header+line('ریاضی','18','1404/1405','UNKNOWN'),header+line('ریاضی')+line('ریاضی')]){
 await upload(contents);const bad=(await sessions()).at(-1);check(JSON.parse(bad.ambiguities_data).length>0,'Ambiguity shown');await execute(bad.id);assert.deepEqual(await grades(),committed);checks++;
}
await upload(header+line('ریاضی','16'));const locked=(await sessions()).at(-1);
await code(`<?php require '/www/includes/db.php';DB::execute('UPDATE reports SET is_locked=1 WHERE id=?',[${target.id}]);`);await execute(locked.id);assert.deepEqual(await grades(),committed);checks++;
await code(`<?php require '/www/includes/db.php';DB::execute('UPDATE reports SET is_locked=0 WHERE id=?',[${target.id}]);`);
await execute(locked.id,{year:'1405/1406'});check((await db("SELECT * FROM reports WHERE student_id=9901 AND academic_year='1405/1406'")).length===1,'Year override');
// More than 50 rows are retained and actually imported, not merely reported completed.
await upload(header+Array.from({length:60},(_,i)=>line('درس '+i,'0')).join(''));const sixty=(await sessions()).at(-1);check(JSON.parse(sixty.preview_data).length===60,'Full preview storage');await execute(sixty.id);check((await sessions()).at(-1).processed_rows===60,'All 60 rows committed');
// XLSX fixtures built with ZipArchive in the isolated PHP filesystem.
const xlsx=await code(`<?php $z=new ZipArchive();$z->open('/tmp/report.xlsx',ZipArchive::CREATE|ZipArchive::OVERWRITE);$z->addFromString('xl/workbook.xml','<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Grades" sheetId="1" r:id="rId1"/></sheets></workbook>');$z->addFromString('xl/_rels/workbook.xml.rels','<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');$lines=explode("\\n",trim(base64_decode('${Buffer.from(header+line('اکسل','19')).toString('base64')}')));$xml='<worksheet><sheetData>';foreach($lines as $i=>$l){$xml.='<row r="'.($i+1).'">';foreach(str_getcsv($l) as $j=>$v)$xml.='<c r="'.chr(65+$j).($i+1).'" t="inlineStr"><is><t>'.htmlspecialchars($v,ENT_XML1).'</t></is></c>';$xml.='</row>';}$z->addFromString('xl/worksheets/sheet1.xml',$xml.'</sheetData></worksheet>');$z->close();echo base64_encode(file_get_contents('/tmp/report.xlsx'));`);
await upload(Buffer.from(xlsx,'base64'),'grades.xlsx');const excel=(await sessions()).at(-1);check(excel.file_type==='xlsx'&&excel.total_rows===1,'Actual XLSX upload parsed');await execute(excel.id);check((await grades()).some(g=>g.subject_name==='اکسل'&&g.score===19),'Actual XLSX saved');
// Original two-column Report.csv parser, validated before numeric casts.
const blockRows=[Array(26).fill(''),Array(26).fill(''),Array(26).fill(''),Array(26).fill(''),['مهر و امضاء']];
blockRows[0][0]='سال تحصیلی:';blockRows[0][1]='1404/1405';
blockRows[1][6]='دانش‌آموز آزمایشی';blockRows[1][10]='نام و نام خانوادگی:';
blockRows[2][6]='هفتم1';blockRows[2][10]='کلاس:';
blockRows[3][6]='2';blockRows[3][9]='۱۸/۷۵';blockRows[3][10]='بلوک';
await upload(blockRows.map(r=>r.join(',')).join('\n'));const block=(await sessions()).at(-1);
check(block.total_rows===1&&!JSON.parse(block.ambiguities_data).length,'Original Report.csv block parsed');await execute(block.id);check((await grades()).some(g=>g.subject_name==='بلوک'&&g.score===18.75),'Original block data committed');
// Forged paths, formulas and external XML entities cannot become imports.
const safety=await code(`<?php require '/www/includes/report_import_wizard.php';$ok=0;
foreach(['<!DOCTYPE x [<!ENTITY y SYSTEM "file:///etc/passwd">]><x>&y;</x>','<bad'] as $xml){try{ri_xml($xml);}catch(InvalidArgumentException $e){$ok++;}}
try{ri_parse_file('/tmp/report.xlsx','xls');}catch(InvalidArgumentException $e){$ok++;}
$z=new ZipArchive();$z->open('/tmp/report.xlsx');$sheet=$z->getFromName('xl/worksheets/sheet1.xml');$sheet=str_replace('<is>','<f>1+1</f><is>',$sheet);$z->addFromString('xl/worksheets/sheet1.xml',$sheet);$z->close();try{ri_xlsx_rows('/tmp/report.xlsx');}catch(InvalidArgumentException $e){$ok++;}
echo $ok;`);check(safety==='4','Unsafe XML/formula/format rejected');
// Failed/missing upload never creates a session; permission checks precede any parsing.
const countBefore=(await sessions()).length;await request('import.php',{post:{csrf_token:csrf,action:'upload_file'}});check((await sessions()).length===countBefore,'Missing file rejected');
for(const file of ['import.php','grade-entry-management.php']){
 const dataBefore=JSON.stringify([await sessions(),await grades(),await db('SELECT * FROM grade_entry_permissions')]);
 await request(file,{session:'reportToolsTeacher',post:{...rule,action:'execute_import',session_id:session.id}});
 check(JSON.stringify([await sessions(),await grades(),await db('SELECT * FROM grade_entry_permissions')])===dataBefore,'Unauthorized POST cannot write '+file);
}
// Legacy API must not bypass confirmation, ownership or real writes.
async function api(resource,post,session=sid){const r=await request('api/admin-import.php',{query:'resource='+resource,post,session});return JSON.parse(r.page);}
await upload(header+line('رابط برنامه','15'));const apis=(await sessions()).at(-1);const apiBefore=await grades();
check((await api('preview-execute',{session_id:apis.id})).status==='error','API requires CSRF');
check((await api('preview-execute&session_id='+apis.id)).status==='error','GET cannot execute');
check((await api('preview-state&session_id='+apis.id,undefined,'reportToolsOther')).status==='error','API ownership');
check((await api('sessions',undefined,'reportToolsTeacher')).status==='error','API teacher denied');
check((await api('queue-process',{csrf_token:csrf})).status==='error','No pretend queue completion');
check((await api('resolve-student-edit',{csrf_token:csrf,session_id:apis.id,national_id:'123'})).status==='error','No fake ambiguity resolution');
assert.deepEqual(await grades(),apiBefore);checks++;
check((await api('preview-execute',{csrf_token:csrf,session_id:apis.id})).processed_rows===1,'API uses real transaction');
check((await grades()).some(g=>g.subject_name==='رابط برنامه'&&g.score===15),'API actually wrote grade');
await code(`<?php require '/www/includes/db.php';DB::execute("INSERT INTO api_tokens (user_type,user_id,access_token,refresh_token,expires_at) VALUES ('admin',1,'fixture-bearer','fixture-refresh','2099-01-01 00:00:00')");`);
const tokenRead=JSON.parse(await code(`<?php ini_set('session.save_path','/tmp/sess');session_name('BACI_TEST');session_id('ApiBearerOnly');session_start();$_SESSION=[];$_SERVER['HTTP_AUTHORIZATION']='Bearer fixture-bearer';$_SERVER['REQUEST_METHOD']='GET';$_GET=['resource'=>'sessions'];require '/www/api/admin-import.php';`));
check(tokenRead.status==='success'&&tokenRead.data.some(s=>s.id===apis.id),'Bearer API retained');
console.log('PASS',checks,'report restoration checks (real multipart, CSV/XLSX, writes, rollback, permission rules)');
writeFileSync(join(outdir,'checks.json'),JSON.stringify({checks},null,2));process.exit(0);
