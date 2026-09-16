import assert from 'node:assert/strict';
import {run,req,php,db,loginAdmin} from './harness/lib.mjs';
import {readFileSync,existsSync,writeFileSync,mkdirSync} from 'node:fs';
import {createRequire} from 'node:module';
import {REPO,resolveFile} from './harness/site.mjs';
const sid='logoAdmin',csrf='logo-csrf';await loginAdmin(sid);
await run(`<?php ini_set('session.save_path','/tmp/sess');session_name('BACI_TEST');session_id('${sid}');session_start();$_SESSION['csrf_token']='${csrf}';require '/www/includes/functions.php';set_setting('logo_url','');set_setting('card_custom_logo','');set_setting('current_academic_year','1404/1405');DB::execute("UPDATE students SET status='inactive'");DB::execute("INSERT INTO students(id,national_id,first_name,last_name,class_name,grade_level,status,academic_year) VALUES(9510,'0000009510','سید محمد طاها','آزمایشی','نهم ۲','نهم','active','1404/1405')");`);
const fixture=JSON.parse((await run(`<?php $i=imagecreatetruecolor(800,400);imagealphablending($i,false);imagesavealpha($i,true);imagefilledrectangle($i,0,0,799,399,imagecolorallocatealpha($i,0,0,0,127));imagefilledellipse($i,400,200,500,220,imagecolorallocate($i,255,255,255));ob_start();imagepng($i);$b=ob_get_clean();echo json_encode(['url'=>'data:image/png;base64,'.base64_encode($b),'raw'=>base64_encode($b)]);`)).out);
const current=async()=> (await db("SELECT key_value FROM settings WHERE key_name='card_custom_logo'"))[0]?.key_value||'';
async function api(post={},who=sid,method='POST'){
 const r=await req('',{file:'entry-card-logo.php',sid:who,method,post:{csrf_token:csrf,...post}});assert(!r.res.fatal,r.res.fatal);return JSON.parse(r.res.page);
}
assert.equal((await api({action:'upload',image_data:fixture.url},'anonymousLogo')).ok,false);
assert.equal((await api({action:'upload',image_data:fixture.url,csrf_token:'bad'})).ok,false);
assert.equal((await api({action:'reset'},sid,'GET')).ok,false);
assert.equal((await api({action:'unknown'})).ok,false);
for(const bad of ['',[], 'data:image/svg+xml;base64,'+Buffer.from('<svg onload="alert(1)"></svg>').toString('base64'),'data:image/png;base64,'+Buffer.from('<?php echo "bad";').toString('base64'),'data:image/png;base64,'+'A'.repeat(2800000)]){
 assert.equal((await api({action:'upload',image_data:bad})).ok,false);assert.equal(await current(),'');
}
const saved=await api({action:'upload',image_data:fixture.url});assert(saved.ok);assert.match(saved.url,/^uploads\/card-logos\/[a-f0-9]{32}\.png$/);assert.equal(await current(),saved.url);
const inspection=JSON.parse((await run(`<?php $p='/www/${saved.url}';$s=getimagesize($p);echo json_encode(['w'=>$s[0],'h'=>$s[1],'type'=>$s[2],'bytes'=>filesize($p)]);`)).out);
assert.deepEqual([inspection.w,inspection.h,inspection.type],[512,256,3]);assert(inspection.bytes>0);
assert.equal((await api({action:'upload',image_data:'data:image/png;base64,AA=='})).ok,false);assert.equal(await current(),saved.url);
const html=(await req('',{file:'entry-cards.php',sid,query:'print=1&layout=custom'})).res.page;assert(html.includes(saved.url));assert(html.includes('has-custom-logo'));assert.equal((await db("SELECT key_value FROM settings WHERE key_name='logo_url'"))[0].key_value,'');
const reset=await api({action:'reset'});assert(reset.ok);assert.equal(reset.url,'');assert.equal(await current(),'');assert(php.fileExists('/www/'+saved.url));
// Revocation runs before the upload handler too.
await loginAdmin('revokedLogo');await req('',{file:'entry-cards.php',sid:'revokedLogo'});
await run(`<?php require '/www/includes/functions.php';DB::execute("UPDATE user_sessions SET is_revoked=1 WHERE session_id='revokedLogo'");`);
await req('',{file:'entry-card-logo.php',sid:'revokedLogo',method:'POST',post:{csrf_token:csrf,action:'upload',image_data:fixture.url}});assert.equal(await current(),'');
console.log('PASS logo API: permissions, POST/CSRF, malformed/SVG/oversize rejection, GD re-encoding, bounded dimensions, failure preserves logo, reset and revocation');
if(process.env.PRINT_BROWSER){
 const require=createRequire((process.env.BROWSER_MODULES||REPO+'/.cache/browser/node_modules')+'/_resolver.cjs');
 const {chromium:pw}=require('playwright'),mod=require('@sparticuz/chromium'),chromium=mod.default||mod;
 const browser=await pw.launch({executablePath:await chromium.executablePath(),args:chromium.args,headless:true});
 try{
  const ctx=await browser.newContext({viewport:{width:1440,height:1050},deviceScaleFactor:2}),errors=[];
  await ctx.addInitScript(()=>{window.print=()=>window.printCalls=(window.printCalls||0)+1;window.open=url=>window.opened=url;});
  let queue=Promise.resolve();const serial=fn=>{const r=queue.then(fn);queue=r.catch(()=>{});return r;};
  await ctx.route('**/*',async route=>{
   const u=new URL(route.request().url()),f=decodeURIComponent(u.pathname).slice(1);
   if(f==='entry-cards.php'){const r=await serial(()=>req('',{file:f,sid,query:u.search.slice(1)}));return route.fulfill({contentType:'text/html',body:r.res.page});}
   if(f==='entry-card-logo.php'){
    const fields={},raw=route.request().postData()||'',boundary=(route.request().headers()['content-type'].split('boundary=')[1]||'');
    for(const part of raw.split('--'+boundary)){const m=part.match(/name="([^"]+)"\r\n\r\n([\s\S]*?)\r\n$/);if(m)fields[m[1]]=m[2];}
    const r=await serial(()=>api(fields));return route.fulfill({contentType:'application/json',body:JSON.stringify(r)});
   }
   if(f.endsWith('.php'))return route.fulfill({contentType:'application/json',body:'{"authenticated":true}'});
   if(f.startsWith('uploads/card-logos/')&&php.fileExists('/www/'+f))return route.fulfill({contentType:'image/png',body:Buffer.from(php.readFileAsBuffer('/www/'+f))});
   const p=resolveFile(f);if(!existsSync(p))return route.fulfill({status:404,body:''});
   return route.fulfill({contentType:{js:'application/javascript',css:'text/css',woff2:'font/woff2',svg:'image/svg+xml'}[f.split('.').pop()]||'application/octet-stream',body:readFileSync(p)});
  });
  const page=await ctx.newPage(),print=await ctx.newPage();for(const p of [page,print])p.on('pageerror',e=>errors.push(e.message));
  await page.goto('https://logo.test/entry-cards.php');await page.selectOption('#cLayout','custom');
  assert(await page.locator('#cCustomLogo').isVisible());
  assert(await page.locator('#cardPreview .custom').first().evaluate(c=>{const a=c.querySelector('.card-custom-mark').getBoundingClientRect(),b=c.querySelector('.card-school').getBoundingClientRect(),s=getComputedStyle(c);return a.x>=b.right&&s.borderTopWidth==='0px'&&s.outlineStyle==='none'&&s.borderRadius==='0px'&&s.boxShadow==='none';}));
  await page.setInputFiles('#cCustomLogo',{name:'logo.png',mimeType:'image/png',buffer:Buffer.from(fixture.raw,'base64')});
  await page.waitForFunction(()=>document.getElementById('cLogoStatus').textContent.indexOf('ذخیره شد')>=0);
  const uploaded=await serial(current);assert(uploaded);
  await page.waitForFunction(()=>document.querySelector('#cardPreview .card-custom-logo').naturalWidth===512);
  assert.equal(await page.locator('#cardPreview .card-custom-mark').first().isVisible(),false);
  await page.selectOption('#cCopies','3');assert(await page.locator('#cardPreview .card-custom-logo').evaluateAll(imgs=>imgs.every(i=>i.getAttribute('src').startsWith('uploads/card-logos/'))));
  await page.fill('#cCustomText','bacirat.ir — دستی');await page.evaluate(()=>openCardPrint());
  await print.goto(new URL(await page.evaluate(()=>window.opened),'https://logo.test/').href);await print.waitForFunction(()=>window.printCalls===1);
  assert.equal(await print.locator('.custom .card-custom-site').first().textContent(),'bacirat.ir — دستی');
  assert.equal(await print.locator('.custom .card-custom-logo').first().getAttribute('src'),uploaded);
  assert(await print.locator('.custom').first().evaluate(c=>{const a=c.querySelector('.card-custom-logo').getBoundingClientRect(),b=c.querySelector('.card-school').getBoundingClientRect();return a.x>=b.right&&getComputedStyle(c).outlineStyle==='none';}));
  await page.reload();assert.equal(await page.inputValue('#cCustomText'),'bacirat.ir — دستی');assert.equal(await page.locator('#cardPreview .card-custom-logo').first().getAttribute('src'),uploaded);
  page.on('dialog',d=>d.accept());await page.click('#cLogoReset');await page.waitForFunction(()=>document.getElementById('cLogoStatus').textContent.indexOf('برگشت')>=0);
  assert(await page.locator('#cardPreview .card-custom-mark').first().isVisible());
  await page.fill('#cCustomText','bacirat.ir');await page.evaluate(()=>openCardPrint());await print.goto(new URL(await page.evaluate(()=>window.opened),'https://logo.test/').href);await print.waitForFunction(()=>window.printCalls===1);await print.emulateMedia({media:'print'});
  mkdirSync(REPO+'/.cache/card-logo',{recursive:true});await print.locator('.custom').first().screenshot({path:REPO+'/.cache/card-logo/example.png'});
  await page.selectOption('#cSide','both');assert.equal(await page.locator('#cardPreview .card-back').first().evaluate(c=>getComputedStyle(c).borderTopWidth),'0px');
  await page.selectOption('#cLayout','full');assert.equal(await page.locator('#cardPreview .card-custom-logo').first().isVisible(),false);assert.notEqual(await page.locator('#cardPreview .card-id').first().evaluate(c=>getComputedStyle(c).borderTopWidth),'0px');
  assert.deepEqual(errors,[]);console.log('PASS browser: real file input to PHP/GD, live clone updates, persisted upload/reset, RTL logo-before-name, editable footer and clean edges in preview/print');
 }finally{await browser.close();}
}
process.exit(0);
