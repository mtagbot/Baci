import assert from 'node:assert/strict';
import {writeFileSync,mkdirSync} from 'node:fs';
import {REPO} from './harness/site.mjs';
const fixtureDir=REPO+'/.cache/quality/fixtures/';
process.env.CLASS_ACTIONS_TEST='1';
const {request,code,db,canonical,members,fork,admin,exec,php}=await import('./test-class-exam-groups.mjs');
// Exercise real HTTP-style headers, especially session rotation and binary PDF responses.
php.writeFile('/harness/run_request.php',php.readFileAsText('/harness/run_request.php').replace('echo " ";',"define('TCPDF_SILENCE_DEPRECATION',true);"));
const reportId=await code(`<?php require '/www/includes/functions.php';DB::execute('INSERT INTO reports(student_id,academic_year,term,report_month,class_name) VALUES(?,?,?,?,?)',[9801,'1404/1405','ماهانه','مهر','هفتم1']);echo DB::lastInsertId();`);
let n=0;const check=(v,m)=>{assert(v,m);n++};
for(const who of [admin,exec]){
 const r=await request('exams.php',{session:who,query:'tab=class&year=1404%2F1405'});
 const table=r.page.match(/<table id="adminClassExams"[\s\S]*?<\/table>/)[0];
 check(!table.includes('exam_id='+members[1].exam_id+'&'),'Excluded shadow row absent');
 check((table.match(new RegExp('exam_id='+fork+'&dt=','g'))||[]).length===1,'Exactly one detached exam row');
}
for(const [id,klass] of [[canonical,'هفتم1'],[fork,'هفتم2']]){
 const r=await request('exam-print.php',{query:'type=questions&exam_id='+id+'&grade_all=1'});
 const text=r.page.match(/<b class="(?:group|independent)-design-notice">([\s\S]*?)<\/b>/)?.[1];
 check(text?.includes(klass),'Scope notice names the actual class');
 if(id===canonical)check(!text.includes('هفتم2'),'Detached class excluded from shared notice');
}
mkdirSync(fixtureDir+'',{recursive:true});
for(const family of ['Vazirmatn','Sahel','Yekan']){
 const r=JSON.parse(await code(`<?php require '/www/includes/report_image.php';set_setting('font_family','${family}');echo json_encode(['font'=>app_font_spec(),'raster'=>report_image_font_path(),'html'=>'<!doctype html><html><head>'.app_appearance_head().'</head><body><h1>نام مدرسه</h1><input value="نام دانش‌آموز"><button class="btn-success">ذخیره</button><button class="btn-danger">حذف</button><table><tr><td>کارنامه فارسی</td></tr></table></body></html>']);`));
 check(r.font.path===r.raster,'Preview/raster share '+family);writeFileSync(fixtureDir+'/font-'+family+'.html',r.html);

 const exported=await request('export-pdf.php',{session:admin,query:'id='+reportId});
 const bytes=Buffer.from(php.readFileAsBuffer('/harness/page.html'));check(bytes.subarray(0,5).toString()==='%PDF-'&&bytes.toString('latin1').toLowerCase().includes(family.toLowerCase()),'Actual report PDF embeds '+family);
 const excel=await request('export-excel.php',{session:admin,query:'id='+reportId});check(excel.page.includes('ss:FontName="'+family+'"'),'Spreadsheet declares '+family);
 const pdf=JSON.parse(await code(`<?php require '/www/includes/functions.php';require '/www/vendor/tcpdf/tcpdf.php';set_setting('font_family','${family}');$f=app_pdf_font();$p=new TCPDF();$p->AddFont($f['name'],'',$f['file']);$p->SetFont($f['name'],'',12);$p->AddPage();$p->Write(0,'گزارش دانش آموز');$b=$p->Output('report.pdf','S');echo json_encode(['prefix'=>substr($b,0,5),'font'=>$f['name'],'size'=>strlen($b)]);`));
 check(pdf.prefix==='%PDF-'&&pdf.size>10000&&pdf.font.toLowerCase().includes(family.toLowerCase()),'Real PDF embeds '+family);
}
const custom=JSON.parse(await code(`<?php require '/www/includes/functions.php';set_setting('font_family','CustomUploadedFont');set_setting('custom_font_url','uploads/Sahel/Sahel.ttf');echo json_encode(['family'=>app_export_font_family(),'html'=>'<!doctype html><html><head>'.app_appearance_head().'</head><body><h1>قلم اختصاصی</h1><input value="فارسی"><button>ذخیره</button><table><tr><td>کارنامه</td></tr></table></body></html>']);`));check(custom.family==='Sahel','Custom TTF uses real font name in Excel');writeFileSync(fixtureDir+'font-CustomUploadedFont.html',custom.html);
const palette=JSON.parse(await code(`<?php require '/www/includes/functions.php';set_setting('success_color','#123456');set_setting('danger_color','#abcdef');echo json_encode(app_palette());`));check(palette.success==='#123456'&&palette.danger==='#abcdef','Four saved palette colors');
check((await code(`<?php require '/www/includes/functions.php';echo app_color('red;</style>','#123456');`))==='#123456','CSS injection rejected');
check((await code(`<?php require '/www/includes/functions.php';set_setting('font_family','Tahoma');echo app_pdf_font()===null?'BROWSER':'BAD';`))==='BROWSER','System font uses browser, not substitute'); // Does not silently substitute DejaVu.
const html=await code(`<?php require '/www/includes/docx_class_list.php';echo dcl_render_print_html('7/1',array_fill(0,30,['first'=>'سید محمد طاها','last'=>'حسینی نژاد']), 'مدرسه',false);`);
writeFileSync(fixtureDir+'/teacher.html',html);
const sq=await request('entry-cards.php',{session:admin,query:'print=1&layout=sq&auto=0'});writeFileSync(fixtureDir+'/square.html',sq.page);
// Unlike the legacy harness, login needs headers unsent to rotate the real session ID.
php.writeFile('/harness/run_request.php',php.readFileAsText('/harness/run_request.php').replace('echo " ";',"define('TCPDF_SILENCE_DEPRECATION',true);"));
const login=await request('admin-login.php',{session:'freshQualityLogin'});check(login.page.includes('app-appearance'),'Login receives shared font/hidden fix');writeFileSync(fixtureDir+'/login.html',login.page);
await code(`<?php require '/www/includes/functions.php';DB::execute("INSERT INTO admins(username,password,name,role,status) VALUES(?,?,?,?,1)",['QualityAdmin',password_hash('QualityPassword9',PASSWORD_DEFAULT),'Quality','super_admin']);`);
const firstPost={login_type:'admin',username:'QualityAdmin',password:'wrong',csrf_token:login.page.match(/name="csrf_token" value="([^"]+)/)[1],captcha:'999'};
const bad=await request('admin-login.php',{session:'freshQualityLogin',post:firstPost});check(bad.flash?.message.includes('کلمه عبور مدیریت نادرست'),'First attempt ignores wrong captcha and checks password');
const retry=await request('admin-login.php',{session:'freshQualityLogin'});check(/data-cap-role="admin"\s*>/.test(retry.page),'Failed password makes retry visible in initial HTML');writeFileSync(fixtureDir+'retry.html',retry.page);
const blocked=await request('admin-login.php',{session:'freshQualityLogin',post:{...firstPost,password:'QualityPassword9',captcha:''}});check(blocked.flash?.message.includes('کد امنیتی نادرست'),'Correct password without second-attempt captcha is rejected');
const answer=await code(`<?php ini_set('session.save_path','/tmp/sess');session_name('BACI_TEST');session_id('freshQualityLogin');session_start();echo $_SESSION['captcha_ans'];`);
const success=await request('admin-login.php',{session:'freshQualityLogin',post:{...firstPost,password:'QualityPassword9',captcha:answer}});check(success.flash?.type==='success','Correct retry captcha/password logs in');
const fresh=await request('admin-login.php',{session:'newQualityLogin'});check(/data-cap-role="admin" hidden/.test(fresh.page),'A new session begins without captcha');
check((await code(`<?php require '/www/includes/functions.php';require '/www/includes/header_tiles.php';$_SESSION=['login_challenge'=>['admin'=>true]];echo login_captcha_gate('admin','QualityAdmin','')?'BAD':'BLOCKED';`))==='BLOCKED','Direct POST cannot skip a never-generated second-attempt challenge');
check((await code(`<?php require '/www/includes/functions.php';$_SESSION=[];$_SERVER['REMOTE_ADDR']='192.0.2.99';for($i=0;$i<30;$i++)record_failed_login();$_SESSION=[];echo check_login_throttle('192.0.2.99')?'BAD':'LIMITED';`))==='LIMITED','Clearing cookies cannot bypass persistent IP rate limit');
console.log('PASS',n,'quality integration checks');process.exit(0);
