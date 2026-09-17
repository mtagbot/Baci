import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFileSync,writeFileSync,existsSync,mkdirSync} from 'node:fs';
import {join} from 'node:path';
import {REPO} from './harness/site.mjs';
const require=createRequire(join(REPO,'.cache/browser/package.json'));
const {chromium:pw}=require('playwright'),mod=await import(require.resolve('@sparticuz/chromium')),chromium=mod.default;
const {default:AxeBuilder}=require('@axe-core/playwright');
const browser=await pw.launch({executablePath:await chromium.executablePath(),args:chromium.args.filter(a=>!['--single-process','--disable-web-security'].includes(a)),headless:true});
const dir=join(REPO,process.env.HUB_EVIDENCE_DIR||'docs/mobile-hubs');mkdirSync(dir,{recursive:true});
const results=[],errors=[];let checks=0;const check=(v,m)=>{assert(v,m);checks++;};
try{
 for(const phase of (process.env.HUB_AFTER_ONLY?['after']:['before','after'])){
  const fixtures=join(REPO,'.cache/mobile-hubs',phase==='before'?'before-fixtures':process.env.HUB_DESKTOP?'desktop-fixtures':'fixtures'),site=join(REPO,'.cache/mobile-hubs',phase==='before'?'before':process.env.HUB_DESKTOP?'desktop/SchoolDeskPro/www':'site');
  const {groups,cases}=JSON.parse(readFileSync(join(fixtures,'cases.json')));
  const context=await browser.newContext({viewport:{width:390,height:844},reducedMotion:'reduce'});
  await context.addInitScript(()=>{window.alert=()=>{};window.confirm=()=>false;window.print=()=>{};});
  const requests=[],nestedPolls=[];
  const routeHandler=async route=>{
   const u=new URL(route.request().url()),p=decodeURIComponent(u.pathname).slice(1);if(p==='desk-connection-status.php'&&route.request().frame().parentFrame())nestedPolls.push(u.href);requests.push(p+'?'+u.searchParams);
   if(u.hostname!=='hubs.test')return route.abort();
   let fixture=cases.find(c=>c.url===p+(u.search?'?'+u.searchParams.toString():''));
   // Link/query order may differ after native GET forms. Match the specified view, not a generic dummy frame.
   if(!fixture&&p.endsWith('.php'))fixture=cases.find(c=>{if(c.file!==p)return false;const q=new URLSearchParams(c.query);return ['tab','action','hub_tab'].every(k=>(q.get(k)||'')===(u.searchParams.get(k)||''));});
   if(fixture)return route.fulfill({status:fixture.unavailable?404:200,contentType:'text/html;charset=utf-8',body:readFileSync(join(fixtures,fixture.snapshot))});
   if(p.endsWith('.php'))return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,authenticated:true,need:false,items:[],data:[]})});
   const f=join(site,p);if(!existsSync(f))return route.fulfill({status:404,body:''});
   return route.fulfill({body:readFileSync(f),contentType:{js:'text/javascript',css:'text/css',ttf:'font/ttf',woff2:'font/woff2',svg:'image/svg+xml'}[p.split('.').pop()]||'application/octet-stream'});
  };
  await context.route('**/*',routeHandler);
  const page=await context.newPage();page.on('pageerror',e=>errors.push({phase,message:e.message}));
  const widths=process.env.HUB_QUICK?[390,1440]:[320,390,768,1024,1440];
  for(const [file,title,tabs] of groups)for(const width of widths){
   if(phase==='before'&&width!==390)continue;
   await page.setViewportSize({width,height:900});await page.goto('https://hubs.test/'+file);
   for(const [index,[key,url]] of tabs.entries()){
    if(index>0){const tab=phase==='after'?page.locator('[data-hub-key="'+key+'"]'):page.locator('.integrated-tabs a').nth(index);await tab.click();}
    const handle=await page.locator('#hubFrame').elementHandle();const frame=await handle.contentFrame();
    await frame.waitForURL(u=>u.pathname.endsWith(url.split('?')[0]));await frame.waitForLoadState('load');
    await frame.evaluate(()=>document.fonts&&document.fonts.ready);await page.waitForTimeout(140);
    const parent=await page.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,frameHeight:document.getElementById('hubFrame').getBoundingClientRect().height,frameWidth:document.getElementById('hubFrame').getBoundingClientRect().width,frameCount:document.querySelectorAll('iframe').length,active:document.querySelectorAll('.hub-tab[aria-selected="true"]').length}));
    const child=await frame.evaluate(()=>{
     const main=document.querySelector('main')||document.querySelector('.container'),r=main&&main.getBoundingClientRect();
     const cards=[...document.querySelectorAll('main .grid>.card')].filter(e=>e.getBoundingClientRect().width>0).map(e=>({width:e.getBoundingClientRect().width,parent:e.parentElement.getBoundingClientRect().width,columns:getComputedStyle(e.parentElement).gridTemplateColumns,span:getComputedStyle(e).gridColumn}));
     const controls=[...document.querySelectorAll('input.form-input:not([type=hidden]),select.form-select,textarea.form-input')].filter(e=>e.offsetWidth&&e.offsetHeight&&!e.closest('.ui-table-scroll,.ws-wrap,.modal-content,[style*="display:none"],[style*="display: none"]')).map(e=>({width:e.getBoundingClientRect().width,height:e.getBoundingClientRect().height}));
     const clippedForms=[...document.querySelectorAll('main form')].filter(e=>e.offsetWidth&&e.offsetHeight&&!e.closest('.hub-mobile-dialog')&&getComputedStyle(e).overflowY!=='visible'&&e.scrollHeight>e.clientHeight+2).map(e=>({cls:e.className,height:e.clientHeight,scroll:e.scrollHeight}));
     return{clippedForms,width:innerWidth,scroll:document.documentElement.scrollWidth,height:document.body.getBoundingClientRect().height,mainWidth:r&&r.width,cards,controls,embedded:document.documentElement.classList.contains('hub-embedded'),unavailable:document.body.textContent.includes('در نسخهٔ مبنا موجود نیست')};
    });
    const item={phase,file,title,key,width,parent,child};results.push(item);
    if(phase==='after'){
     check(parent.scroll<=width+1,'Outer overflow '+JSON.stringify(item));check(child.scroll<=child.width+1,'Inner overflow '+JSON.stringify(item));
     check(parent.frameCount===1&&parent.active===1,'Single frame/active tab');check(child.embedded,'Child marked early');
     if(width<=900){check(!child.clippedForms.length,'Form still clipped '+JSON.stringify(item));check(parent.frameHeight>=240,'Collapsed iframe');check(Math.abs(parent.frameHeight-Math.max(240,Math.ceil(child.height)+2))<=5,'Mobile frame follows content '+JSON.stringify(item));
      for(const c of child.cards)check(c.width>=c.parent*.94,'Squashed grid card '+JSON.stringify(item));
      for(const c of child.controls)check(c.width>=140&&c.height>=43,'Unusable form size '+JSON.stringify(item));
     }
    }
    if(!process.env.HUB_DESKTOP&&width===390&&(phase==='before'||['teachers','analytics','sms','api'].includes(key)))await page.screenshot({path:join(dir,phase+'-'+file.replace('.php','')+'-'+key+'.png'),fullPage:true});
   }
  }
  if(phase==='after'){
   for(const c of cases.filter(x=>x.extra))for(const width of [320,390,768]){
    await page.setViewportSize({width,height:900});await page.goto('https://hubs.test/'+(c.file==='reports.php'?'reports-management.php':'courses-management.php'));
    const f=await (await page.locator('#hubFrame').elementHandle()).contentFrame();await f.goto('https://hubs.test/'+c.url);await f.waitForLoadState('load');await f.evaluate(()=>document.fonts&&document.fonts.ready);await page.waitForTimeout(200);
    const m=await f.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,forms:[...document.querySelectorAll('main form')].filter(e=>e.offsetHeight&&getComputedStyle(e).overflowY!=='visible'&&e.scrollHeight>e.clientHeight+2).length,body:document.body.offsetHeight}));
    check(m.scroll<=m.width+1&&!m.forms,'Inner view '+c.key+' at '+width);check(Math.abs(await page.locator('#hubFrame').evaluate(e=>e.offsetHeight)-Math.max(240,m.body+2))<5,'Inner view height');
    if(!process.env.HUB_DESKTOP&&c.key==='class-matrix'&&width===390)await page.screenshot({path:join(dir,'after-class-matrix.png'),fullPage:true});
   }
   // Dynamic content: same frame grows AND shrinks, with no viewport-height feedback loop.
   await page.setViewportSize({width:390,height:900});await page.goto('https://hubs.test/courses-management.php?hub_tab=teachers');
   let frame=await (await page.locator('#hubFrame').elementHandle()).contentFrame();await frame.waitForLoadState('load');await page.waitForTimeout(200);
   const initial=await page.locator('#hubFrame').evaluate(e=>e.offsetHeight);
   await frame.evaluate(()=>{var b=document.createElement('div');b.id='heightTest';b.style.height='700px';document.querySelector('main').appendChild(b);});await page.waitForTimeout(250);
   const grown=await page.locator('#hubFrame').evaluate(e=>e.offsetHeight);check(grown>initial+650,'Dynamic growth');
   await frame.locator('#heightTest').evaluate(e=>e.remove());await page.waitForTimeout(250);check(Math.abs(await page.locator('#hubFrame').evaluate(e=>e.offsetHeight)-initial)<5,'Dynamic shrink');
   // Native form and inner links remain in the same frame. No replacement of form handlers or CSRF.
   await frame.locator('a[href*="tab=import"]').first().click();await frame.waitForURL(/tab=import/);await frame.waitForLoadState('load');check(await frame.locator('input[type=file]').count()>0,'Teacher CSV form still available');
   await page.locator('[data-hub-key="years"]').click();await page.waitForTimeout(150);await page.goBack();await frame.waitForURL(/import-teachers.php/);
   check(await page.locator('[data-hub-key="teachers"]').getAttribute('aria-selected')==='true','Back selects correct tab');
   await page.locator('[data-hub-key="classes"]').focus();await page.keyboard.press('ArrowLeft');check(await page.locator('[data-hub-key="teachers"]').getAttribute('aria-selected')==='true','RTL keyboard tabs');
   // Wide schedule remains scrollable locally; no scale-to-fit or omitted columns.
   await frame.goto('https://hubs.test/classes.php?tab=schedule&embedded=1');await frame.waitForLoadState('load');await page.waitForTimeout(200);
   check(await frame.locator('#wsTable').count()===1,'Weekly matrix present');
   check(await frame.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Matrix does not widen document');
   await frame.goto('https://hubs.test/import-teachers.php?tab=list');await frame.waitForLoadState('load');
   check(await frame.evaluate(()=>document.documentElement.classList.contains('hub-embedded')),'Embedded layout survives a redirect without query flag');
   // A modal in an auto-height iframe must open in the PHONE viewport, not halfway down the long document.
   await page.goto('https://hubs.test/reports-management.php');frame=await (await page.locator('#hubFrame').elementHandle()).contentFrame();await frame.waitForLoadState('load');
   await frame.locator('button[onclick*="bulkLockModal"]').first().click();await page.waitForTimeout(200);
   const modal=await frame.locator('#bulkLockModal>div').boundingBox();check(modal&&modal.y>=0&&modal.y+modal.height<=901,'Mobile modal visible '+JSON.stringify(modal));
   await frame.locator('#bulkLockModal').evaluate(e=>e.style.display='none');
   const axe=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();writeFileSync(join(dir,process.env.HUB_DESKTOP?'accessibility-desktop.json':'accessibility.json'),JSON.stringify(axe.violations,null,2));check(!axe.violations.length,'Axe '+JSON.stringify(axe.violations));
   const tall=await page.locator('#hubFrame').evaluate(e=>e.offsetHeight);await page.waitForTimeout(900);check(Math.abs(tall-await page.locator('#hubFrame').evaluate(e=>e.offsetHeight))<3,'No frame height loop');
   await page.goto('https://hubs.test/other-settings.php?hub_tab=backups');frame=await (await page.locator('#hubFrame').elementHandle()).contentFrame();await frame.waitForLoadState('load');
   await frame.evaluate(()=>openRestoreModal('fixture-only.sql'));await page.waitForTimeout(200);
   const restore=await frame.locator('#restoreModal>div').boundingBox();check(restore&&restore.y>=0&&restore.y+restore.height<=901,'Restore dialog visible');
   // The modal is inspected, never submitted; the fixture does not restore any database.
   const touch=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true});await touch.route('**/*',routeHandler);
   const tp=await touch.newPage();await tp.goto('https://hubs.test/courses-management.php');await tp.locator('[data-hub-key="teachers"]').tap();await tp.waitForTimeout(200);
   check(await tp.locator('[data-hub-key="teachers"]').getAttribute('aria-selected')==='true','Touch selects tab');
   await tp.setViewportSize({width:844,height:390});await tp.waitForTimeout(200);check(await tp.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Landscape rotation');await touch.close();
   const nojs=await browser.newContext({viewport:{width:390,height:844},javaScriptEnabled:false});await nojs.route('**/*',routeHandler);
   const np=await nojs.newPage();await np.goto('https://hubs.test/courses-management.php');await np.locator('[data-hub-key="teachers"]').click();
   check(await np.locator('[data-hub-key="teachers"]').getAttribute('aria-selected')==='true','No-JS native tab navigation');
   check(await np.locator('.hub-fallback a').isVisible(),'No-JS direct link');await nojs.close();
   check(!nestedPolls.length,'No nested desktop polling');
  }
  await context.close();
 }
 check(!errors.length,JSON.stringify(errors));writeFileSync(join(dir,process.env.HUB_DESKTOP?'layouts-desktop.json':'layouts.json'),JSON.stringify({checks,results,errors},null,2));
 console.log('PASS',checks,'checks;',results.length,'real nested tab layouts');
}finally{await browser.close();}
