// Real PHP/SQLite requests: privileged key rotation, device tombstones and cookie replay.
import assert from 'node:assert/strict';
import {run,req,php,db,student} from './harness/lib.mjs';
import {createRequire} from 'node:module';
import {readFileSync,existsSync} from 'node:fs';
import {REPO,resolveFile} from './harness/site.mjs';
const csrf='security-fixture-csrf', admin='secAdmin', deputy='secDeputy';
let checks=0;
async function execute(code){const r=await run('<?php '+code);assert(!r.err,r.err);assert(!/Fatal error/.test(r.out),r.out);return r.out.trim();}
async function seedSession(sid,role='admin',id=1){
  await execute(`ini_set('session.save_path','/tmp/sess');session_name('BACI_TEST');session_id('${sid}');session_start();$_SESSION=['${role}_id'=>${id},'csrf_token'=>'${csrf}'${role==='admin'?",'admin_role'=>'super_admin'":''}];`);
}
async function request(file,sid=admin,post=null,cookies={}){
  const r=await req('',{file,sid,method:post?'POST':'GET',post:post||{},cookies});
  assert(!r.stderr,r.stderr);assert(!r.res.fatal,r.res.fatal);
  const errors=r.res.php_issues.split('\n').filter(s=>s&&!s.includes('headers already sent')&&!s.includes('headers have already been sent'));
  assert.deepEqual(errors,[]);return r.res;
}
await execute(`require '/www/includes/school_roles.php';ensure_school_roles_schema();
DB::execute('UPDATE admins SET username=?,password=?,status=1 WHERE id=1',['fixtureadmin',password_hash('Correct-Admin!',PASSWORD_DEFAULT)]);
DB::execute('INSERT INTO teachers(id,national_id,full_name,password,status,is_deputy) VALUES (701,?,?,?,?,1)',['T701','معاون آزمون',password_hash('Deputy-Only!',PASSWORD_DEFAULT),1]);
DB::execute('INSERT INTO admins(id,username,name,password,role,permissions,status) VALUES (702,?,?,?,?,?,?)',['limited','محدود',password_hash('Limited!',PASSWORD_DEFAULT),'admin','[]',1]);
DB::execute('INSERT INTO admins(id,username,name,password,role,permissions,status) VALUES (703,?,?,?,?,?,?)',['disabled','غیرفعال',password_hash('Disabled!',PASSWORD_DEFAULT),'super_admin','[]',0]);
set_setting('att_auto_finalize','0');
require '/www/includes/attendance_helpers.php';ensure_attendance_schema_v2();att_scanner_key(false);`);
await seedSession(admin);await seedSession(deputy,'teacher',701);
const key=async()=>execute(`require '/www/includes/attendance_helpers.php';echo att_scanner_key(false);`);
const initial=await key();assert(initial.length>=20);
const post={regen_scanner_key:'1',csrf_token:csrf};
for(const pass of [undefined,'','wrong',['Correct-Admin!']]){
  const r=await request('attendance.php',admin,{...post,...(pass===undefined?{}:{admin_password:pass})});
  assert.equal(r.flash.type,'error');assert.equal(await key(),initial);checks++;
}
assert.equal((await request('attendance.php',admin,{...post,csrf_token:'bad',admin_password:'Correct-Admin!'})).flash.type,'error');assert.equal(await key(),initial);checks++;
assert.equal((await request('attendance.php',admin,{...post,admin_password:'Correct-Admin!'})).flash.type,'success');assert.notEqual(await key(),initial);checks++;
let old=await key();
for(const [name,pass] of [['fixtureadmin','Deputy-Only!'],['limited','Limited!'],['disabled','Disabled!'],['','Correct-Admin!']]){
  assert.equal((await request('attendance.php',deputy,{...post,admin_username:name,admin_password:pass})).flash.type,'error');assert.equal(await key(),old);checks++;
}
assert.equal((await request('attendance.php',deputy,{...post,admin_username:'fixtureadmin',admin_password:'Correct-Admin!'})).flash.type,'success');assert.notEqual(await key(),old);checks++;
old=await key();
for(let i=0;i<5;i++)await request('attendance.php',admin,{...post,admin_password:'wrong'});
await seedSession('secAdminOther');
assert.equal((await request('attendance.php','secAdminOther',{...post,admin_password:'Correct-Admin!'})).flash.type,'error');assert.equal(await key(),old);checks++;
await execute(`require '/www/includes/functions.php';DB::execute('UPDATE security_confirm_attempts SET window_start=?',[time()-601]);`);
assert.equal((await request('attendance.php',admin,{...post,admin_password:'Correct-Admin!'})).flash.type,'success');checks++;
const ui=await request('attendance.php',deputy);assert(ui.page.includes('name="admin_password" type="password" required'));assert(ui.page.includes('name="admin_username"'));checks++;

// A functions-only handler mutates before any header/auth include: it must still be guarded.
php.writeFile('/www/security-probe.php',`<?php require __DIR__.'/includes/functions.php';
if(!st_current_user()){http_response_code(401);echo json_encode(['authenticated'=>false]);exit;}
if($_SERVER['REQUEST_METHOD']==='POST')DB::execute("UPDATE settings SET key_value='MUTATED' WHERE key_name='security_probe'");
echo json_encode(['authenticated'=>true,'sid'=>st_session_key(),'user'=>st_current_user()]);`);
await execute(`require '/www/includes/functions.php';set_setting('security_probe','UNCHANGED');`);
async function issue(sid,role='admin',id=1){
  await seedSession(sid,role,id);
  return execute(`ini_set('session.save_path','/tmp/sess');session_name('BACI_TEST');session_id('${sid}');session_start();require '/www/includes/functions.php';set_remember_login('${role}',${id});echo $_COOKIE['school_remember'];`);
}
const token=await issue('secTarget');
const row=(await db("SELECT * FROM user_sessions WHERE session_id='secTarget'"))[0];assert(row.remember_hash.length===64);checks++;
assert.equal(JSON.parse((await request('security-probe.php','secRestored',null,{school_remember:token})).page).sid,'secTarget');checks++;
const restoredList=await request('my-sessions.php','secRestored');assert(restoredList.page.includes('همین دستگاه'));assert(!restoredList.page.includes('msAskPassword(\'one\', '+row.id+','));checks++;
const revoke={revoke_session:'1',session_row_id:row.id,csrf_token:csrf,account_password:'Correct-Admin!'};
for(const over of [{account_password:''},{account_password:'wrong'},{csrf_token:'wrong'}]){
  await request('my-sessions.php',admin,{...revoke,...over});assert.equal((await db(`SELECT is_revoked FROM user_sessions WHERE id=${row.id}`))[0].is_revoked,0);checks++;
}
await request('my-sessions.php',deputy,{...revoke,account_password:'Deputy-Only!'});assert.equal((await db(`SELECT is_revoked FROM user_sessions WHERE id=${row.id}`))[0].is_revoked,0);checks++;
assert.equal((await request('my-sessions.php',admin,revoke)).flash.type,'success');checks++;
for(const sid of ['secTarget','secRestored','secReplayFresh']){
  const r=await request('security-probe.php',sid,{action:'mutate'},{school_remember:token});
  assert(r.redirect?.includes('session_closed=1')||JSON.parse(r.page).authenticated===false);
  assert.equal((await db("SELECT key_value FROM settings WHERE key_name='security_probe'"))[0].key_value,'UNCHANGED');checks++;
}
assert.equal((await db(`SELECT is_revoked FROM user_sessions WHERE id=${row.id}`))[0].is_revoked,1);checks++;
// Deleted PHP session does NOT delete the authoritative revocation tombstone.
await execute(`require '/www/includes/functions.php';DB::execute('UPDATE user_sessions SET last_seen_at=? WHERE id=?',[time()-90*86400,${row.id}]);`);
await issue('secNewDevice');
assert.equal((await db(`SELECT is_revoked FROM user_sessions WHERE id=${row.id}`))[0].is_revoked,1);checks++;
// A real HTTP-style request verifies 401 + both expired cookies (no artificial harness output).
php.writeFile('/www/security-http.php',`<?php ini_set('session.save_path','/tmp/sess');session_name('BACI_TEST');session_id('secHttpReplay');session_start();$_SERVER['HTTP_ACCEPT']='application/json';$_COOKIE['school_remember']='${token}';require __DIR__.'/includes/functions.php';echo 'UNREACHABLE';`);
const http=await php.run({scriptPath:'/www/security-http.php'});
assert.equal(http.httpStatusCode,401);assert.equal(JSON.parse(http.text).authenticated,false);
assert(JSON.stringify(http.headers).includes('school_remember='));assert(JSON.stringify(http.headers).includes('BACI_TEST='));checks++;
// Never accept an old, correctly signed but device-unbound remember cookie.
const legacy=await execute(`require '/www/includes/functions.php';$p='admin|1|'.(time()+86400);echo rtrim(strtr(base64_encode($p.'|'.hash_hmac('sha256',$p,remember_secret())),'+/','-_'),'=');`);
assert.equal(JSON.parse((await request('security-probe.php','secLegacyFresh',null,{school_remember:legacy})).page).authenticated,false);checks++;
// Active legacy sessions migrate transparently to a device-bound cookie.
await seedSession('secLegacyActive');
await request('security-probe.php','secLegacyActive',null,{school_remember:legacy});
assert((await db("SELECT remember_hash FROM user_sessions WHERE session_id='secLegacyActive'"))[0].remember_hash?.length===64);checks++;
// Revoke all others retains the originating device even after remember restoration.
const own=await issue('secOwner');await request('security-probe.php','secOwnerRestored',null,{school_remember:own});
await execute(`ini_set('session.save_path','/tmp/sess');session_name('BACI_TEST');session_id('secOwnerRestored');session_start();$_SESSION['csrf_token']='${csrf}';`);
await request('my-sessions.php','secOwnerRestored',{revoke_all_others:'1',csrf_token:csrf,account_password:'Correct-Admin!'});
assert.equal((await db("SELECT is_revoked FROM user_sessions WHERE session_id='secOwner'"))[0].is_revoked,0);
assert.equal((await db("SELECT is_revoked FROM user_sessions WHERE session_id='secNewDevice'"))[0].is_revoked,1);
assert.equal((await db("SELECT is_revoked FROM user_sessions WHERE session_id='secDeputy'"))[0].is_revoked,0);checks++;
// A fresh password login may create a NEW device, not revive a revoked identifier.
const fresh=await issue('secPasswordFresh');assert.notEqual(fresh,token);
assert.equal(JSON.parse((await request('security-probe.php','secPasswordRestore',null,{school_remember:fresh})).page).authenticated,true);checks++;
// Explicit logout also prevents replay of the persistent cookie.
await request('logout.php','secPasswordFresh');
assert((await request('security-probe.php','secLogoutReplay',null,{school_remember:fresh})).redirect?.includes('session_closed=1'));checks++;
// Teacher and student devices obey the same central gate.
await execute(`require '/www/includes/functions.php';DB::execute("UPDATE students SET status='active' WHERE id=${student.id}");`);
for(const [role,id] of [['teacher',701],['student',student.id]]){
  const sid='sec'+role, t=await issue(sid,role,id);
  assert.equal(JSON.parse((await request('security-probe.php',sid+'Restored',null,{school_remember:t})).page).user[0],role);
  await execute(`require '/www/includes/functions.php';DB::execute('UPDATE user_sessions SET is_revoked=1 WHERE session_id=?',['${sid}']);`);
  assert((await request('security-probe.php',sid,{action:'mutate'},{school_remember:t})).redirect?.includes('session_closed=1'));
  assert((await request('security-probe.php',sid+'Replay',null,{school_remember:t})).redirect?.includes('session_closed=1'));checks++;
}
// Additive migration retains old revocations; neither renewal nor migration un-revokes them.
await execute(`require '/www/includes/functions.php';DB::execute('ALTER TABLE user_sessions DROP COLUMN remember_hash');`);
await seedSession('secMigrating');await request('security-probe.php','secMigrating');
assert.equal((await db(`SELECT is_revoked FROM user_sessions WHERE id=${row.id}`))[0].is_revoked,1);checks++;
// Simulate a database outage, exercising the actual guard rather than a permissive header catch.
const unavailable=await php.run({code:`<?php ini_set('session.save_path','/tmp/sess');session_id('securityoutage');session_start();$_SESSION=['admin_id'=>1];$_SERVER['HTTP_ACCEPT']='application/json';
class DB {static function getInstance(){return new self;}function getPdo(){return null;}}
require '/www/includes/session_tracker.php';track_user_session();echo 'UNREACHABLE';`});
assert.equal(unavailable.httpStatusCode,503);assert(!unavailable.text.includes('UNREACHABLE'));checks++;
console.log(`PASS ${checks} password/key/device/cookie-replay regression scenarios`);

if(process.env.SECURITY_BROWSER){
  const require=createRequire((process.env.BROWSER_MODULES||REPO+'/.cache/browser/node_modules')+'/_resolver.cjs');
  const {chromium:pw}=require('playwright'),mod=require('@sparticuz/chromium'),chromium=mod.default||mod;
  const browser=await pw.launch({executablePath:await chromium.executablePath(),args:chromium.args,headless:true});
  try{
    const ownerSid='browserOwner',targetSid='browserTarget';await issue(ownerSid);await issue(targetSid);
    const targetRow=(await db("SELECT id FROM user_sessions WHERE session_id='browserTarget'"))[0].id;
    let queue=Promise.resolve();const serial=fn=>{const result=queue.then(fn);queue=result.catch(()=>{});return result;};
    const contexts=[],pages=[],errors=[];
    for(const sid of [ownerSid,targetSid]){
      const context=await browser.newContext();contexts.push(context);
      await context.route('**/*',async route=>{
        const url=new URL(route.request().url()),file=decodeURIComponent(url.pathname).slice(1);
        if(file==='index.php')return route.fulfill({contentType:'text/html',body:'<h1>ورود دوباره</h1>'});
        if(['my-sessions.php','attendance.php','session-status.php'].includes(file)){
          let r=await serial(()=>request(file,sid,route.request().method()==='POST'?Object.fromEntries(new URLSearchParams(route.request().postData())):null));
          // Follow the application's PRG inside the CLI transport (redirect headers are not page HTML).
          if(route.request().method()==='POST' && r.flash)r=await serial(()=>request(file,sid));
          if(r.redirect)return route.fulfill({status:302,headers:{location:r.redirect},body:''});
          return route.fulfill({status:file==='session-status.php'&&JSON.parse(r.page).authenticated===false?401:200,contentType:file==='session-status.php'?'application/json':'text/html',body:r.page});
        }
        if(file.endsWith('.php'))return route.fulfill({contentType:'application/json',body:'{}'});
        const path=resolveFile(file);if(!existsSync(path))return route.fulfill({status:404,body:''});
        return route.fulfill({contentType:{js:'application/javascript',css:'text/css',woff2:'font/woff2',ttf:'font/ttf',svg:'image/svg+xml'}[file.split('.').pop()]||'application/octet-stream',body:readFileSync(path)});
      });
      const page=await context.newPage();pages.push(page);page.on('pageerror',e=>errors.push(e.message));
    }
    const [owner,target]=pages;await owner.goto('https://session.test/my-sessions.php');await target.goto('https://session.test/my-sessions.php');
    await owner.locator(`button[onclick^="msAskPassword('one', ${targetRow},"]`).click();
    await owner.fill('#msPassInput','wrong');await owner.locator('#msPassForm button[type=submit]').click();await owner.waitForLoadState('networkidle');
    assert.equal((await serial(()=>db(`SELECT is_revoked FROM user_sessions WHERE id=${targetRow}`)))[0].is_revoked,0);
    await owner.locator(`button[onclick^="msAskPassword('one', ${targetRow},"]`).click();await owner.fill('#msPassInput','Correct-Admin!');
    await owner.locator('#msPassForm button[type=submit]').click();
    await target.waitForURL('**/index.php?view=login&session_closed=1',{timeout:22000});
    assert(!owner.url().includes('index.php'));assert.equal((await serial(()=>db(`SELECT is_revoked FROM user_sessions WHERE id=${targetRow}`)))[0].is_revoked,1);
    // The actual attendance UI retains the old key after a wrong password.
    await owner.goto('https://session.test/attendance.php');const before=await serial(key);await owner.locator('summary').filter({hasText:'کلید جدید'}).click();
    await owner.fill('input[name=admin_password]','wrong');owner.on('dialog',d=>d.accept());
    await owner.locator('button[type=submit]').filter({hasText:'تأیید رمز و ساخت کلید جدید'}).click();await owner.waitForLoadState('networkidle');assert.equal(await serial(key),before);
    await owner.locator('summary').filter({hasText:'کلید جدید'}).click();await owner.fill('input[name=admin_password]','Correct-Admin!');
    await owner.locator('button[type=submit]').filter({hasText:'تأیید رمز و ساخت کلید جدید'}).click();await owner.waitForLoadState('networkidle');assert.notEqual(await serial(key),before);
    assert.deepEqual(errors,[]);console.log('PASS Chromium: two isolated device contexts, password dialog, automatic target logout, owner retained and scanner-key confirmation');
  }finally{await browser.close();}
}
process.exit(0);
