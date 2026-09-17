import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFileSync,writeFileSync,existsSync,mkdirSync} from 'node:fs';
import {join} from 'node:path';
import {REPO,resolveSite} from './harness/site.mjs';
const require=createRequire(join(REPO,'.cache/browser/node_modules/_r.cjs'));
const {chromium:pw}=require('playwright'),mod=require('@sparticuz/chromium'),chromium=mod.default||mod;
const {default:AxeBuilder}=require('@axe-core/playwright');
const browser=await pw.launch({executablePath:await chromium.executablePath(),args:chromium.args,headless:true});
const dir=join(REPO,'docs/fast-ui');mkdirSync(dir,{recursive:true});let n=0;
function check(v,m){assert(v,m);n++;}
const fixtures=join(REPO,'.cache/fast-ui/fixtures');let state={state:'synced',server:'reachable',pending:0,last_success:Math.floor(Date.now()/1000)},polls=0,delay=0;
try{
 const context=await browser.newContext({viewport:{width:1440,height:960}});
 await context.addInitScript(()=>{window.print=()=>{};window.alert=()=>{};});
 await context.route('**/*',async r=>{
  const url=new URL(r.request().url()),p=url.pathname.slice(1);if(url.hostname!=='fast.test')return r.abort();
  if(p==='desk-connection-status.php'){polls++;if(delay)await new Promise(r=>setTimeout(r,delay));return r.fulfill({status:state.state==='signed-out'?401:200,contentType:'application/json',body:JSON.stringify(state)});}
  if(p.endsWith('.php'))return r.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,authenticated:true,need:!p.includes('admin'),items:[],data:[]})});
  const file=p.endsWith('.html')?join(fixtures,p):join(resolveSite(),p);
  if(!existsSync(file))return r.fulfill({status:404,body:''});
  return r.fulfill({body:readFileSync(file),contentType:{html:'text/html;charset=utf-8',js:'text/javascript',css:'text/css',woff2:'font/woff2',ttf:'font/ttf',svg:'image/svg+xml'}[p.split('.').pop()]||'application/octet-stream'});
 });
 const page=await context.newPage();await page.clock.install();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 for(const role of ['student','teacher','inquiry'])for(const width of [390,1440]){
  await page.setViewportSize({width,height:960});await page.goto('https://fast.test/'+role+'-error.html');await page.evaluate(()=>document.fonts.ready);
  check(await page.locator('#'+role+'Feedback').isVisible(),'Visible active-role error');
  check(await page.evaluate(role=>document.activeElement.id===role+'Feedback',role),'Error focus');
  check(await page.locator('#'+role+'Tab input[name="national_id"]').getAttribute('aria-invalid')==='true','Invalid field');
  check(await page.locator('#'+role+'Tab .auth-captcha').isVisible(),'Retry captcha');
  check(await page.locator('#'+role+'Tab input[name="'+(role==='inquiry'?'serial_number':'password')+'"]').inputValue()==='','No secret retained');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No horizontal overflow');
  const axe=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();check(!axe.violations.length,JSON.stringify(axe.violations));
  if(role==='teacher'&&width===1440)await page.screenshot({path:join(dir,'teacher-login-error.png'),fullPage:true});
 }
 // Error/warning is not timed out after 15 seconds (advance timers virtually).
 await page.clock.fastForward(16000);check(await page.locator('#inquiryFeedback').isVisible(),'Error persists');await page.clock.resume();
 for(const f of ['Vazirmatn','Sahel','Yekan','CustomUploadedFont']){
  await page.goto('https://fast.test/font-'+f+'.html');await page.evaluate(()=>document.fonts.ready);
  check(await page.evaluate(f=>[...document.fonts].some(face=>face.family===f&&face.status==='loaded'),f),'Real chosen screen font loaded '+f);
 }
 await page.setViewportSize({width:1440,height:960});await page.goto('https://fast.test/desktop.html');await page.waitForFunction(()=>document.getElementById('deskConnection').dataset.state==='synced');
 check(polls===1,'Single initial local request');check(await page.locator('.ui-loading-bar.on').count()===0,'No global loading stripe');
 await page.locator('#deskConnection summary').click();check(await page.locator('#deskConnectionPanel').isVisible(),'Accessible detail disclosure');
 const widths=[];
 for(const next of ['syncing','queued','retrying','error','warning','waiting','disabled','unavailable','synced']){
  state={state:next,server:next==='synced'?'reachable':'unknown',pending:next==='queued'?3:0,retry_in:next==='retrying'?20:0,last_success:Math.floor(Date.now()/1000)};
  await page.evaluate(()=>window.dispatchEvent(new Event('focus')));await page.waitForFunction(s=>document.getElementById('deskConnection').dataset.state===s,next);
  widths.push(await page.locator('#deskConnection').evaluate(e=>e.getBoundingClientRect().width));
  const spinning=await page.locator('.desk-connection-ring').evaluate(e=>getComputedStyle(e).animationName!=='none');check(spinning===(next==='syncing'),'Motion only during actual checking/work '+next);
  if(next==='syncing')await page.screenshot({path:join(dir,'desktop-connection.png'),fullPage:false});
 }
 check(Math.max(...widths)-Math.min(...widths)<.02,'Status changes never resize the capsule');
 await page.evaluate(()=>{Object.defineProperty(navigator,'onLine',{configurable:true,get:()=>false});window.dispatchEvent(new Event('offline'));});
 check(await page.locator('#deskConnection').getAttribute('data-state')==='offline','Offline overrides cached sync success');
 await page.evaluate(()=>{Object.defineProperty(navigator,'onLine',{configurable:true,get:()=>true});window.dispatchEvent(new Event('online'));});await page.waitForFunction(()=>document.getElementById('deskConnection').dataset.state==='synced');
 await page.emulateMedia({reducedMotion:'reduce'});state={state:'syncing'};await page.evaluate(()=>window.dispatchEvent(new Event('focus')));await page.waitForFunction(()=>document.getElementById('deskConnection').dataset.state==='syncing');
 check(await page.locator('.desk-connection-ring').evaluate(e=>getComputedStyle(e).animationName==='none'),'Reduced motion');
 await page.keyboard.press('Escape');check(!await page.locator('#deskConnectionPanel').isVisible(),'Escape closes details');
 // Poll lifecycle: background tabs pause, in-flight refreshes do not overlap.
 await page.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,get:()=>true});document.dispatchEvent(new Event('visibilitychange'));});
 const paused=polls;await page.clock.fastForward(20000);check(polls===paused,'No hidden-tab polls');await page.clock.resume();
 await page.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,get:()=>false});document.dispatchEvent(new Event('visibilitychange'));});await page.waitForTimeout(300);
 delay=1000;const start=polls;await page.evaluate(()=>{for(var i=0;i<10;i++)window.dispatchEvent(new Event('focus'));});await page.waitForTimeout(300);check(polls===start+1,'Refresh events coalesce');await page.waitForTimeout(1100);delay=0;
 state={state:'queued',pending:2};await page.evaluate(()=>window.dispatchEvent(new Event('focus')));await page.waitForFunction(()=>document.getElementById('deskConnection').dataset.state==='queued');
 const warning=await page.evaluate(()=>{const e=new Event('beforeunload',{cancelable:true});window.dispatchEvent(e);return e.defaultPrevented;});check(warning,'Unsent changes close warning remains');
 await page.evaluate(()=>{var e=new Event('submit',{bubbles:true});document.body.dispatchEvent(e);});
 check(!await page.evaluate(()=>{const e=new Event('beforeunload',{cancelable:true});window.dispatchEvent(e);return e.defaultPrevented;}),'Internal form navigation does not warn');
 // Fixed footer must stay visible, wrap at narrow widths, and not conceal final content.
 for(const width of [320,390,768,1024,1440]){
  await page.setViewportSize({width,height:960});await page.evaluate(()=>scrollTo(0,document.documentElement.scrollHeight));
  const g=await page.evaluate(()=>{const f=document.querySelector('.school-footer').getBoundingClientRect(),m=document.querySelector('main').getBoundingClientRect();return{width:document.documentElement.scrollWidth,viewport:innerWidth,footTop:f.top,footBottom:f.bottom,mainBottom:m.bottom};});
  check(g.width<=g.viewport+1,'Desktop width '+width);check(g.footBottom<=961&&g.footTop>=0,'Visible bottom indicator '+width);check(g.mainBottom<=g.footTop+1,'Last content remains above fixed footer '+width);
 }
 await page.emulateMedia({media:'print'});check(!await page.locator('#deskConnection').isVisible(),'No status UI in print');await page.emulateMedia({media:'screen'});
 state={state:'signed-out'};await page.evaluate(()=>window.dispatchEvent(new Event('focus')));await page.waitForFunction(()=>document.getElementById('deskConnection').dataset.state==='signed-out');
 const stopped=polls;await page.clock.fastForward(20000);check(polls===stopped,'Stop polling after 401');await page.clock.resume();
 await page.goto('https://fast.test/desktop-guest.html');const guestPolls=polls;await page.waitForTimeout(800);check(polls===guestPolls,'No status requests before login');
 // Large data lists: no startup row animation and no hidden dropdown option DOM built in advance.
 const head=readFileSync(join(fixtures,'font-Vazirmatn.html'),'utf8').match(/<head>([\s\S]*?)<\/head>/)[1];
 writeFileSync(join(fixtures,'stress.html'),'<!doctype html><html lang="fa" dir="rtl"><head>'+head+'<link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/ui-modern.css"><link rel="stylesheet" href="assets/css/school-ui.css"></head><body class="school-app"><main><label for="longSelect">انتخاب آزمایشی</label><select class="form-select" id="longSelect">'+Array.from({length:500},(_,i)=>'<option value="'+(i+1)+'">گزینه '+(i+1)+'</option>').join('')+'</select><div class="table-container"><table id="stressRows"><tbody>'+Array.from({length:500},(_,i)=>'<tr><td>'+i+'</td><td>ردیف آزمایشی</td></tr>').join('')+'</tbody></table></div></main><script defer src="assets/js/school-icons.js"></script><script defer src="assets/js/school-ui.js"></script><script defer src="assets/js/ui-modern.js"></script><script defer src="assets/js/searchable-select.js"></script></body></html>');
 await page.goto('https://fast.test/stress.html');
 check(await page.locator('#stressRows tr').count()===500,'All 500 data rows present');
 check(await page.locator('#stressRows tr').evaluateAll(rows=>rows.every(r=>getComputedStyle(r).opacity==='1'&&getComputedStyle(r).transform==='none')),'All 500 rows immediately visible without entry transform');
 check(await page.locator('.ss-opt').count()===0,'Do not build 500 hidden dropdown rows at startup');
 await page.locator('.ss-btn').click();check(await page.locator('.ss-opt').count()===500,'Build searchable options on demand');
 await page.locator('.ss-search').fill('۵۰۰');check(await page.locator('.ss-opt:visible').count()===1,'Persian-digit search');
 await page.locator('.ss-search').press('Enter');check(await page.locator('#longSelect').inputValue()==='500','Keyboard selection still works');
 check(!errors.length,JSON.stringify(errors));
 console.log('PASS',n,'browser feedback, fonts, status, lifecycle and desktop layout checks');
}finally{await browser.close();}
