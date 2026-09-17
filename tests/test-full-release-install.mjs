// Fresh-install integration against the ACTUAL staged release, not historical seeded fixtures.
// Optional real MySQL: disposable local instance on 127.0.0.1:3307 only; password file
// .cache/release-v1/mysql-test-password. The reserved release_install_test DB is recreated.
import assert from 'node:assert/strict';
import {PHP} from '@php-wasm/universal';
import {loadNodeRuntime,withNetworking} from '@php-wasm/node';
import {readFileSync,readdirSync} from 'node:fs';
import {join,relative} from 'node:path';
import {randomBytes} from 'node:crypto';
const root=new URL('../',import.meta.url).pathname;
const encode=x=>Buffer.from(JSON.stringify(x)).toString('base64');
const input={school_name:'مدرسهٔ آزمون نصب',admin_name:'مدیر آزمون نصب',admin_username:'release_admin',admin_password:'Install-Only-Test-2026',admin_password_confirm:'Install-Only-Test-2026',academic_year:'1405/1406',db_host:'127.0.0.1',db_port:'3307',db_name:'release_install_test',db_user:'root'};
let assertions=0;
const check=(condition,msg)=>{assert(condition,msg);assertions++};
async function environment(platform){
 const php=new PHP(await loadNodeRuntime('8.3',{emscriptenOptions:process.env.RELEASE_TEST_MYSQL?await withNetworking({processId:1}):{processId:1}}));
 php.mkdirTree('/www');php.mkdirTree('/data');php.mkdirTree('/tmp/sess');
 const folder=join(root,'.cache/release-v1/package',platform==='site'?'site':'SchoolDeskPro/www');
 function copy(dir){for(const e of readdirSync(dir,{withFileTypes:true})){const p=join(dir,e.name),dest='/www/'+relative(folder,p);if(e.isDirectory()){php.mkdirTree(dest);copy(p)}else php.writeFile(dest,new Uint8Array(readFileSync(p)))}}
 copy(folder);
 const owner=randomBytes(24).toString('hex');php.writeFile('/www/config/install-access.php',`<?php return '${owner}';`);
 async function code(s){const r=await php.run({code:s});if(r.errors)console.error(r.errors);return r;}
 let sid='releaseFreshInstall';
 async function request(post=null,query='',path='installer.php'){
  const response=await code(`<?php ini_set('session.save_path','/tmp/sess');session_id('${sid}');$_SERVER['REQUEST_METHOD']='${post===null?'GET':'POST'}';$_SERVER['HTTPS']='on';$_SERVER['SCRIPT_NAME']='/school/${path}';$_SERVER['SCRIPT_FILENAME']='/www/${path}';$_SERVER['REQUEST_URI']='/school/${path}${query?'?'+query:''}';$_SERVER['REMOTE_ADDR']='127.0.0.1';$_POST=json_decode(base64_decode('${encode(post||{})}'),true);parse_str('${query}',$_GET);require '/www/${path}';`);
  for(const cookie of response.headers['set-cookie']||[]){const match=cookie.match(/^PHPSESSID=([^;]+)/);if(match)sid=match[1]}
  return response;
 }
 if(platform==='site'&&process.env.RELEASE_TEST_MYSQL){
  input.db_pass=readFileSync(join(root,'.cache/release-v1/mysql-test-password'),'utf8');
  const r=await code(`<?php $p=new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4','root',base64_decode('${Buffer.from(input.db_pass).toString('base64')}'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$p->exec('DROP DATABASE IF EXISTS release_install_test');$p->exec('CREATE DATABASE release_install_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');echo $p->getAttribute(PDO::ATTR_SERVER_VERSION);`);
  check(r.text.includes('5.7')||r.text.includes('8.'),'Real MySQL connection');console.log('Real MySQL:',r.text);
 }
 return {php,owner,code,request};
}
for(const platform of ['desktop',...(process.env.RELEASE_TEST_MYSQL?['site']:[])]){
 console.log('Fresh installation:',platform);
 const {php,owner,code,request}=await environment(platform);
 check(!php.fileExists('/www/config/database.php'),'No shipped configuration');
 check(!php.fileExists('/data/school.sqlite'),'No shipped database');
 const pre=await request(null,'','index.php');check(pre.headers.location?.[0]==='/school/installer.php','Subfolder first-run redirect');
 const page=await request();check(page.text.includes('Release_V1.0'),'First-run form');
 const csrf=page.text.match(/name="csrf" value="([a-f0-9]{64})"/)?.[1];check(!!csrf,'CSRF generated');
 check(!page.text.includes('class="bad"'),'All real prerequisites passed');
 const bad=await request({...input,install_access:owner,csrf:'wrong'});check(bad.httpStatusCode===403,'Reject CSRF');
 const weak=await request({...input,install_access:owner,csrf,admin_password:'short',admin_password_confirm:'short'});check(weak.httpStatusCode===400,'Reject weak password');
 check(!php.fileExists('/www/config/database.php'),'Rejected request writes no configuration');
 if(platform==='site'){
  const wrong=await request({...input,csrf,install_access:'wrong'});check(wrong.httpStatusCode===400,'Reject invalid deployment owner key');
  const wrongdb=await request({...input,csrf,install_access:owner,db_pass:'definitely-incorrect'});check(wrongdb.httpStatusCode===400,'Wrong MySQL credentials fail');check(!php.fileExists('/data/school.sqlite'),'No MySQL to SQLite fallback');
 }
 const result=await request({...input,csrf,install_access:owner});
 if(!result.text.includes('نصب با موفقیت پایان یافت')){
  console.error(result.text.replace(/<[^>]+>/g,' ').slice(0,5000));
  // Diagnostic mode uses a NEW isolated environment and the same production install function.
  const diag=await environment(platform);
  const r=await diag.code(`<?php define('RELEASE_INSTALLER',true);ini_set('session.save_path','/tmp/sess');session_start();require '/www/includes/release_install.php';$x=json_decode(base64_decode('${encode({...input,install_access:diag.owner})}'),true);try{release_install($x);echo 'OK';}catch(Throwable $e){echo get_class($e).': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine();}`);console.error(r.text);
 }
 check(result.text.includes('نصب با موفقیت پایان یافت'),'Installation reports success');
 check(php.fileExists('/www/config/installed.lock'),'Installed lock created');
 const key=php.readFileAsText('/www/config/desk-sync-key.php');check(/return '[a-f0-9]{64}'/.test(key),'Unique generated sync key');
 const verify=await code(`<?php require '/www/includes/functions.php';$tables=['students','teachers','classes','reports','report_grades','exam_schedules','class_exam_groups','class_exam_group_members','student_attendance','student_qr_tags','online_exams','activity_logs'];$out=[];foreach($tables as $t)$out[$t]=(int)DB::fetch('SELECT COUNT(*) n FROM '.$t)['n'];$a=DB::fetch('SELECT * FROM admins');$out['admins']=(int)DB::fetch('SELECT COUNT(*) n FROM admins')['n'];$out['valid_password']=password_verify('Install-Only-Test-2026',$a['password']);$out['role']=$a['role'];$out['year']=get_setting('current_academic_year');$out['school']=get_setting('school_name');$out['sync_configured']=get_setting('desk_sync_key','');echo json_encode($out);`);
 const data=JSON.parse(verify.text);for(const [name,count] of Object.entries(data).slice(0,12))check(count===0,'No sample data: '+name);
 check(data.admins===1&&data.valid_password&&data.role==='super_admin','Only the requested administrator, hashed password');
 check(data.year==='1405/1406'&&data.school===input.school_name,'Chosen school/year');check(data.sync_configured==='','No auto-pairing');
 const before=php.readFileAsText('/www/config/database.php');
 for(const query of ['','force=1','reset=1&force=1']){const r=await request({...input,csrf,install_access:owner,admin_password:'Different-Password-2026'},query);check(r.httpStatusCode===403,'Cannot reinstall: '+query)}
 php.unlink('/www/config/installed.lock');
 check((await request(null,'force=1')).httpStatusCode===403,'Deleting only lock cannot reopen installation');
 check(php.readFileAsText('/www/config/database.php')===before&&php.readFileAsText('/www/config/desk-sync-key.php')===key,'Config/key are never replaced');
 php.writeFile('/www/config/installed.lock','test-restored-lock');
 const migration=await code(`<?php define('RELEASE_INSTALLER',true);require '/www/includes/release_install.php';release_migrate();echo DB::fetch('SELECT COUNT(*) n FROM admins')['n'];`);check(migration.text.trim()==='1','Migrations remain idempotent');
 const pdf=await code(`<?php require '/www/vendor/tcpdf/tcpdf.php';$p=new TCPDF();$p->AddPage();$p->SetFont('dejavusans','',12);$p->setRTL(true);$p->Write(0,'آزمون فارسی');$s=$p->Output('test.pdf','S');echo substr($s,0,5).':'.strlen($s);`);check(pdf.text.startsWith('%PDF-'),'Bundled real Persian PDF renderer works');
 // Authenticate both real sync endpoints with the generated installation key.
 const syncKey=key.match(/return '([a-f0-9]{64})'/)[1];
 for(const endpoint of ['desk-sync-api.php','class-exam-sync-api.php']){
  for(const valid of [false,true]){
   const r=await php.run({scriptPath:'/www/'+endpoint,method:'POST',body:new TextEncoder().encode(JSON.stringify({key:valid?syncKey:'wrong',action:'handshake'})),headers:{'Content-Type':'application/json'}});
   const response=JSON.parse(r.text);check(response.ok===valid,'Generated key authentication: '+endpoint+' '+valid);
  }
 }
 const loginPage=await request(null,'','admin-login.php');
 const loginCsrf=loginPage.text.match(/name="csrf_token" value="([^"]+)"/)?.[1];check(!!loginCsrf,'Login CSRF form');
 const loginResult=await request({login_type:'admin',username:input.admin_username,password:input.admin_password,csrf_token:loginCsrf},'','admin-login.php');
 check((loginResult.headers.location||[]).some(x=>x.includes('view=dashboard'))||loginResult.text.includes('view=dashboard'),'Real administrator login succeeds');
 for(const page of ['index.php','students.php','teachers.php','classes.php','subjects.php','exams.php','online-exams.php','attendance.php','entry-cards.php','attendance-tags.php','admins.php']){
  if(!php.fileExists('/www/'+page))continue;
  const r=await request(null,page==='index.php'?'view=dashboard':'',page);
  check(r.httpStatusCode<400&&!/Fatal error|Uncaught PDOException/.test(r.text),'Fresh authenticated page: '+page);
 }
 if(platform==='desktop'){
  for(const uri of ['/config/installed.lock','/sql/schema-sqlite.sql','/backups/x.zip','/includes/functions.php','/uploads/shell.php','/%2e%2e/config/database.php','/vendor/tcpdf/tcpdf.php','/x.sqlite-wal']){
   const r=await code(`<?php $_SERVER['REQUEST_URI']='${uri}'; require '/www/router.php';`);check(r.httpStatusCode===403,'Desktop private path denied: '+uri);
  }
 }
 php.writeFile('/www/config/database.php','<?php return [];');
 const invalidConfig=await code(`<?php try { require '/www/includes/db.php';DB::getInstance();echo 'BAD'; } catch(Throwable $e) {echo 'BLOCKED';}`);
 check(invalidConfig.text==='BLOCKED','Incomplete config cannot fall back to implicit credentials');
 php.writeFile('/www/config/database.php',before);
 console.log(platform,'install/security/data/PDF/login/page checks passed');
}
console.log('PASS:',assertions,'full-release installation assertions');process.exit(0);
