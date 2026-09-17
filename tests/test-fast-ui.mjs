import assert from 'node:assert/strict';
import {mkdirSync,writeFileSync,readFileSync} from 'node:fs';
import {run,req,php,loginAdmin,login} from './harness/lib.mjs';
import {REPO,resolveSite} from './harness/site.mjs';
const dir=REPO+'/.cache/fast-ui/fixtures';mkdirSync(dir,{recursive:true});let count=0;
const check=(v,m)=>{assert(v,m);count++;};
async function code(s){const r=await run(s);assert(!r.err,r.err);return r.out;}
// Leave HTTP headers unsent, and let the browser's following GET consume the flash, not the harness.
php.writeFile('/harness/run_request.php',php.readFileAsText('/harness/run_request.php').replace('echo " ";','').replace("unset($_SESSION['flash_message']);",'/* Preserve flash for the following request. */'));
async function request(file,options={}){const r=await req('',{file,sid:'fastGuest',method:options.post?'POST':'GET',...options});assert(!r.res.fatal,r.res.fatal);return r.res;}
async function session(id,body){return code(`<?php ini_set('session.save_path','/tmp/sess');session_name('BACI_TEST');session_id('${id}');session_start();${body}`);}
await code(`<?php require '/www/includes/functions.php';require '/www/includes/header_tiles.php';login_guard_ensure_schema();DB::execute('DELETE FROM login_guard');DB::execute("UPDATE students SET password=?,serial_number='ب/35/356750',status='active' WHERE id=1",[password_hash('StudentSecret9',PASSWORD_DEFAULT)]);DB::execute("INSERT INTO teachers(id,national_id,full_name,password,status) VALUES(909,'1234567891','دبیر آزمایشی',?,1)",[password_hash('TeacherSecret9',PASSWORD_DEFAULT)]);`);
const studentNid=await code(`<?php require '/www/includes/functions.php';echo DB::fetch('SELECT national_id FROM students WHERE id=1')['national_id'];`);
for(const role of ['student','teacher','inquiry']){
 const sid='fast'+role,nid=role==='teacher'?'1234567891':studentNid,secret=role==='teacher'?'TeacherSecret9':role==='inquiry'?'ب/35/356750':'StudentSecret9',field=role==='inquiry'?'serial_number':'password';
 let page=await request('index.php',{sid,query:'view=login&tab='+role});
 check(page.page.includes('id="'+role+'Tab"'),'Role form');
 check(new RegExp('data-cap-role="'+role+'" hidden').test(page.page),'First attempt has no captcha');
 const csrf=page.page.match(/name="csrf_token" value="([^"]+)"/)[1];
 const post={login_type:role,national_id:nid,[field]:'SecretMustNotReappear',captcha:'999',csrf_token:csrf};
 const bad=await request('index.php',{sid,post});check(bad.flash?.type==='error'&&!bad.flash.message.includes('سؤال امنیتی'),'First wrong password, not captcha');
 page=await request('index.php',{sid,query:'view=login&tab=student'});
 check(page.page.includes('id="'+role+'Feedback" role="alert"'),'Error belongs to failed role despite wrong GET tab');
 check(page.page.includes('value="'+nid+'"'),'Identity retained');check(!page.page.includes('SecretMustNotReappear'),'Secret never echoed');
 check(new RegExp('data-cap-role="'+role+'"\\s*>').test(page.page),'Retry captcha present in server HTML');
 writeFileSync(dir+'/'+role+'-error.html',page.page);
 const once=await request('index.php',{sid,query:'view=login&tab='+role});check(!once.page.includes('id="'+role+'Feedback"'),'Flash consumed exactly once');
 const blocked=await request('index.php',{sid,post:{...post,[field]:secret,captcha:''}});check(blocked.flash.message.includes('سؤال امنیتی'),'Cannot bypass second-attempt captcha');
 const captchaPage=await request('index.php',{sid,query:'view=login&tab='+role});writeFileSync(dir+'/'+role+'-captcha.html',captchaPage.page);
 const answer=await session(sid,'echo $_SESSION["captcha_ans"];');
 const ok=await request('index.php',{sid,post:{...post,[field]:secret,captcha:answer}});check(ok.flash?.type==='success','Correct credentials/captcha accepted: '+role);
 const csrfSid='csrf'+role,csrfBad=await request('index.php',{sid:csrfSid,post:{...post,csrf_token:'expired'}});check(csrfBad.flash.message.includes('اعتبار فرم'),'Actionable CSRF error');
 const csrfView=await request('index.php',{sid:csrfSid,query:'view=login'});check(csrfView.page.includes('id="'+role+'Feedback"'),'CSRF error visible');
 await session('throttle'+role,"$_SESSION['login_attempts']=6;$_SESSION['lockout_time']=time();");
 const blockedIP=await request('index.php',{sid:'throttle'+role,post});check(blockedIP.flash.message.includes('۱۰ دقیقه'),'Throttle guidance');
 const blockedView=await request('index.php',{sid:'throttle'+role,query:'view=login'});check(blockedView.page.includes('id="'+role+'Feedback"'),'Throttle preserves selected role');
 const emptySid='empty'+role,emptyPage=await request('index.php',{sid:emptySid,query:'view=login'}),emptyCsrf=emptyPage.page.match(/name="csrf_token" value="([^"]+)"/)[1];
 const missing=await request('index.php',{sid:emptySid,post:{login_type:role,csrf_token:emptyCsrf,national_id:'', [field]:''}});check(missing.flash.message.includes('وارد کنید'),'Missing fields');
 const missingView=await request('index.php',{sid:emptySid,query:'view=login'});check(missingView.page.includes('aria-invalid="true"'),'Invalid controls linked to feedback');
}
const invalidTab=await request('index.php',{sid:'tabUnknown',query:'view=login&tab=unknown'});check(/id="studentTab"[^>]+display:block/.test(invalidTab.page),'Unknown tab has visible default');
// Same response for unknown versus inactive accounts; no account enumeration via error wording.
for(const role of ['student','teacher','inquiry']){
 const secret=role==='inquiry'?'serial_number':'password',messages=[];
 for(const mode of ['unknown','inactive']){
  await code(`<?php require '/www/includes/functions.php';DB::execute("UPDATE students SET status='${mode==='inactive'?'inactive':'active'}' WHERE id=1");DB::execute("UPDATE teachers SET status=${mode==='inactive'?0:1} WHERE id=909");DB::execute('DELETE FROM login_guard');`);
  const sid=role+mode;const page=await request('index.php',{sid,query:'view=login'});const csrf=page.page.match(/name="csrf_token" value="([^"]+)"/)[1];
  const r=await request('index.php',{sid,post:{login_type:role,national_id:mode==='unknown'?'9999999999':role==='teacher'?'1234567891':studentNid,[secret]:'bad',csrf_token:csrf}});messages.push(r.flash.message);
 }
 check(messages[0]===messages[1],'No account enumeration: '+role);
}
// Compression is selected by source hash; customized originals and uploaded fonts are never overridden.
for(const family of ['Vazirmatn','Sahel','Yekan','CustomUploadedFont']){
 const obj=JSON.parse(await code(`<?php require '/www/includes/functions.php';set_setting('font_family','${family}');set_setting('custom_font_url','uploads/Sahel/Sahel.ttf');echo json_encode(['screen'=>app_screen_font_spec(),'print'=>app_font_spec(),'head'=>app_appearance_head(true),'preload'=>app_screen_font_preload()]);`));
 check(obj.print.url.endsWith('.ttf'),'Export retains original TTF');check(obj.head.includes('font-display:swap'),'Immediate screen text');check(obj.screen.url.endsWith(family==='CustomUploadedFont'?'.ttf':'.woff2'),'Exact local compressed font');
 check(obj.preload.includes(obj.screen.url),'Selected face preloaded');
 const html='<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'+obj.preload+obj.head+'</head><body><h1>قلم فارسی</h1><input value="متن"><button>ذخیره</button><table><tr><td>کارنامه فارسی</td></tr></table></body></html>';writeFileSync(dir+'/font-'+family+'.html',html);
}
const changed=await code(`<?php require '/www/includes/functions.php';set_setting('font_family','Vazirmatn');file_put_contents('/www/uploads/Vazirmatn/Vazirmatn-Regular.ttf','changed original');echo app_screen_font_spec()['url'];`);check(changed==='uploads/Vazirmatn/Vazirmatn-Regular.ttf','Replaced source font does not receive stale WOFF2');
php.writeFile('/www/uploads/Vazirmatn/Vazirmatn-Regular.ttf',readFileSync(resolveSite()+'/uploads/Vazirmatn/Vazirmatn-Regular.ttf'));
// Pure reducer with adverse, stale, disabled and partial results.
const cases=[
 [true,{ok:true,ts:1000,server_verified:true,warning:'partial'},0,999,true,'warning','reachable'],
 [true,{ok:true,ts:1000,skipped:'idle',server_verified:true},0,999,true,'warning','reachable',true],
 [true,{ok:true,ts:1000,skipped:'idle',server_verified:false,probe_failed:true},0,999,true,'error','unknown'],
 [true,{ok:true,ts:1000,skipped:'idle'},0,999,true,'waiting','unknown'],
 [false,{ok:true,ts:1000},0,999,true,'disabled','unknown'],[true,{ok:true,ts:900},0,999,true,'waiting','unknown'],
 [true,{ok:true,ts:1000,skipped:'backoff',fails:2,retry_in:20},0,999,true,'retrying','unreachable'],
 [true,{ok:true,ts:1000,skipped:'offline'},3,999,true,'retrying','unreachable'],
 [true,{ok:true,ts:1000,skipped:'locked'},0,999,true,'waiting','unknown'],
 [true,{ok:true,ts:1000,skipped:'recent'},0,999,true,'waiting','unknown'],
 [true,{ok:true,ts:1000,skipped:'idle',server_verified:true},0,999,true,'synced','reachable'],
 [true,{ok:true,ts:1000,server_verified:true},2,999,true,'queued','reachable'],
 [true,{phase:'syncing',ts:1000},0,999,true,'syncing','unknown'],
 [true,{ok:false,error:'secret-key-raw-error',ts:1000},0,999,true,'error','unknown'],
 [true,{ok:true,ts:1000,server_verified:true},0,0,false,'waiting','reachable'],[true,{ok:true,ts:2000},0,999,true,'waiting','unknown']
];
for(const [enabled,h,pending,last,snapshot,state,server,hasIssue=false] of cases){const payload=Buffer.from(JSON.stringify(h)).toString('base64');const j=JSON.parse(await code(`<?php require '/www/includes/desk_connection.php';echo json_encode(desk_connection_snapshot(${enabled},json_decode(base64_decode('${payload}'),true),${pending},${last},${snapshot},1000,${hasIssue}));`));check(j.state===state&&j.server===server,'Status '+state);check(!JSON.stringify(j).includes('secret-key'),'No raw errors');}
// Execute the production tick body with deterministic transport/storage doubles.
const engine=readFileSync(resolveSite()+'/includes/desk_sync.php','utf8');
const tick=engine.slice(engine.indexOf('    public static function tick()'),engine.indexOf('    private static function isOffline'));
php.writeFile('/harness/tick-probe.php',`<?php class TickProbe {
const MIN_INTERVAL=60;
${tick}
static function enabled(){return true;}
static function pendingCount(){return 0;}
static function getCfg($k,$default=''){return $GLOBALS['cfg'][$k]??$default;}
static function setCfg($k,$v){$GLOBALS['cfg'][$k]=$v;}
static function call($a,$b,$c){if(!empty($GLOBALS['badPing']))throw new Exception('rejected ping');return !empty($GLOBALS['emptyPing'])?['ok'=>true]:['log_max'=>0];}
static function isOffline($e){return false;}
static function run(){return $GLOBALS['runResult'];}
}`);
for(const [badPing,oldLast,runResult,verified,emptyPing=false] of [[false,false,{},false,true],[false,false,{},true],[true,false,{},false],[true,true,{ok:true,skipped:'locked'},false],[true,true,{ok:true,pushed:0,pulled:0},true]]){
 const encoded=Buffer.from(JSON.stringify(runResult)).toString('base64');
 const r=JSON.parse(await code(`<?php require '/harness/tick-probe.php';$GLOBALS['cfg']=['desk_sync_snapshot_done'=>'1','desk_sync_last'=>${oldLast?'0':'time()'}];$GLOBALS['badPing']=${badPing};$GLOBALS['emptyPing']=${emptyPing};$GLOBALS['runResult']=json_decode(base64_decode('${encoded}'),true);echo json_encode(TickProbe::tick());`));
 check(r.server_verified===verified,'Production tick proof flag');
 if((badPing||emptyPing)&&!oldLast)check(r.probe_failed===true,'Rejected ping cannot masquerade as successful idle');
}
// Endpoint access and sanitization: local file only, admin counts, no guest data.
php.writeFile('/www/config/release.php',"<?php return ['distribution'=>'desktop'];");
await loginAdmin('fastDesk');await login('fastDeskStudent');
await code(`<?php require '/www/includes/functions.php';require '/www/includes/desk_sync.php';DeskSync::setCfg('desk_sync_enabled','1');DeskSync::setCfg('desk_sync_url','https://private.example.test/desk-sync-api.php');DeskSync::setCfg('desk_sync_key','NeverExposeThisKey');DeskSync::setCfg('desk_sync_last_ok',(string)time());DeskSync::setCfg('desk_sync_snapshot_done','1');`);
php.writeFile('/data/sync-heartbeat.json',JSON.stringify({ok:true,ts:Math.floor(Date.now()/1000),skipped:'idle',server_verified:true,error:undefined,key:'NeverExposeThisKey'}));
let endpoint=await request('desk-connection-status.php',{sid:'fastDesk'});check(!endpoint.page.includes('NeverExpose')&&!endpoint.page.includes('private.example'),'No endpoint credentials');check(typeof JSON.parse(endpoint.page).pending==='number','Admin pending count');
endpoint=await request('desk-connection-status.php',{sid:'fastDeskStudent'});check(JSON.parse(endpoint.page).pending===null,'Student count masked');
endpoint=await request('desk-connection-status.php',{sid:'noDeskLogin'});check(JSON.parse(endpoint.page).state==='signed-out','Guest denied');
const desk=await request('index.php',{sid:'fastDesk',query:'view=dashboard'});writeFileSync(dir+'/desktop.html',desk.page);check(desk.page.includes('data-monitor="1"'),'Desktop indicator');check(!desk.page.includes('ajax=tick'),'No blocking legacy tick');
const embedded=await request('index.php',{sid:'fastDesk',query:'view=dashboard&embedded=1'});check(!embedded.page.includes('id="deskConnection"'),'No duplicate embedded monitor');
const deskGuest=await request('index.php',{sid:'deskGuest',query:'view=login'});writeFileSync(dir+'/desktop-guest.html',deskGuest.page);check(deskGuest.page.includes('data-monitor="0"'),'Guest indicator does not poll');
php.writeFile('/www/config/release.php',"<?php return ['distribution'=>'site'];");
endpoint=await request('desk-connection-status.php',{sid:'fastDesk'});check(!endpoint.page.includes('pending'),'Site endpoint has no status');
console.log('PASS',count,'fast UI/auth/font/desktop PHP checks');process.exit(0);
