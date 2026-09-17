import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFileSync,existsSync,mkdirSync} from 'node:fs';
import {join} from 'node:path';
import {REPO,resolveSite} from './harness/site.mjs';
const require=createRequire(join(REPO,'.cache/browser/node_modules/_r.cjs'));
const {chromium:pw}=require('playwright'),cm=require('@sparticuz/chromium'),chromium=cm.default||cm;
const catalog=JSON.parse(readFileSync(join(REPO,'.cache/ui-audit/routes.json')));
const browser=await pw.launch({executablePath:await chromium.executablePath(),args:chromium.args,headless:true});let count=0;
try{
 const context=await browser.newContext({viewport:{width:1440,height:900},reducedMotion:'reduce'});
 await context.addInitScript(()=>{window.print=()=>{};window.confirm=()=>false;window.alert=()=>{};});
 await context.route('**/*',async route=>{
  const u=new URL(route.request().url());if(u.hostname!=='school.test')return route.abort();
  const snapshot=u.searchParams.get('_audit'),r=catalog.routes.find(r=>r.rendered&&r.snapshot===snapshot);
  if(r)return route.fulfill({contentType:'text/html;charset=utf-8',body:readFileSync(join(REPO,'.cache/ui-audit/pages',r.snapshot))});
  const name=decodeURIComponent(u.pathname).slice(1),file=join(resolveSite(),name);
  if(name.endsWith('.php'))return route.fulfill({contentType:'application/json',body:'{"authenticated":true,"ok":true,"data":[]}'});
  return route.fulfill({status:existsSync(file)?200:404,body:existsSync(file)?readFileSync(file):'',contentType:{js:'application/javascript',css:'text/css',ttf:'font/ttf',woff2:'font/woff2'}[name.split('.').pop()]||'application/octet-stream'});
 });
 const page=await context.newPage();const selector='.school-return-nav,.school-editor-return,.school-preview-return';
 for(const r of catalog.routes.filter(r=>r.rendered)){
  await page.goto('https://school.test/'+r.file+'?_audit='+encodeURIComponent(r.snapshot));
  const allowed=await page.locator('body').getAttribute('data-school-return')==='preview';
  assert.equal(await page.locator(selector).count(),allowed?1:0,r.file+' '+r.query);
  if(allowed){
   await page.evaluate(()=>SchoolNavigation.start());assert.equal(await page.locator(selector).count(),1,'Idempotent');
   if(await page.locator('#mainToolbar').count()){
    assert.equal(await page.locator('.school-return-nav').count(),0,'No detached editor bar');
    const button=page.locator('#mainToolbar .school-editor-return button');assert.equal(await button.getAttribute('type'),'button');
    const style=e=>{const s=getComputedStyle(e);return ['fontFamily','fontSize','padding','borderRadius','backgroundColor','color'].map(k=>s[k]);};
    assert.deepEqual(await button.evaluate(style),await page.locator('#mainToolbar .tb-group:not(.school-editor-return) button').first().evaluate(style),'Same editor button styling');
    mkdirSync(join(REPO,'.cache/preview-navigation'),{recursive:true});await page.locator('#mainToolbar').screenshot({path:join(REPO,'.cache/preview-navigation/editor-toolbar.png')});
    const target=new URL('exams.php',page.url()).href;await button.click();assert.equal(page.url(),target);count++;
    await page.goto('https://school.test/'+r.file+'?_audit='+encodeURIComponent(r.snapshot));
   }
   await page.emulateMedia({media:'print'});assert.equal(await page.locator(selector).evaluateAll(es=>es.filter(e=>e.getClientRects().length&&e.getBoundingClientRect().height>0).length),0,'No controls in print: '+r.file);await page.emulateMedia({media:'screen'});
  }
  count++;
 }
 console.log('PASS',count,'preview scope/style/navigation/print checks');
}finally{await browser.close();}
