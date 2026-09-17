import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFileSync,writeFileSync,existsSync,mkdirSync} from 'node:fs';
import {join} from 'node:path';
import {REPO} from './harness/site.mjs';
const require=createRequire(join(REPO,'.cache/browser/package.json'));
const {chromium:pw}=require('playwright'),chromium=(await import(require.resolve('@sparticuz/chromium'))).default;
const {default:AxeBuilder}=require('@axe-core/playwright');
const browser=await pw.launch({executablePath:await chromium.executablePath(),args:chromium.args.filter(a=>!['--single-process','--disable-web-security'].includes(a)),headless:true});
const dir=join(REPO,process.env.REPORT_TOOLS_DESKTOP?'docs/report-tools/desktop':'docs/report-tools'),fixtures=join(REPO,process.env.REPORT_TOOLS_DESKTOP?'.cache/restore/desktop-fixtures':'.cache/restore/fixtures'),site=join(REPO,process.env.REPORT_TOOLS_DESKTOP?'.cache/mobile-hubs/desktop/SchoolDeskPro/www':'.cache/mobile-hubs/site');mkdirSync(dir,{recursive:true});
const results=[],errors=[],violations=[];let checks=0,state='upload';
const cases={upload:'import.php.html',preview:'preview.html',result:'result.html',rules:'rules.html'};
const check=(v,m)=>{assert(v,m);checks++;};
try{
 const context=await browser.newContext({viewport:{width:390,height:900},reducedMotion:'reduce'});
 await context.route('**/*',async route=>{
  const u=new URL(route.request().url()),p=u.pathname.slice(1);
  if(u.hostname!=='restore.test')return route.abort();
  if(p==='reports-management.php')return route.fulfill({contentType:'text/html;charset=utf-8',body:readFileSync(join(REPO,'.cache/mobile-hubs/'+(process.env.REPORT_TOOLS_DESKTOP?'desktop-fixtures':'fixtures')+'/reports-management.php-selected-'+(state==='rules'?'grades':'wizard')+'.html'))});
  if(p==='import.php'||p==='grade-entry-management.php')return route.fulfill({contentType:'text/html;charset=utf-8',body:readFileSync(join(fixtures,cases[state]))});
  if(p.endsWith('.php'))return route.fulfill({contentType:'application/json',body:'{"ok":true,"authenticated":true,"items":[]}'});
  const path=join(site,p);return route.fulfill({status:existsSync(path)?200:404,body:existsSync(path)?readFileSync(path):'',contentType:{js:'text/javascript',css:'text/css',woff2:'font/woff2',ttf:'font/ttf',svg:'image/svg+xml'}[p.split('.').pop()]||'application/octet-stream'});
 });
 const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
 for(state of Object.keys(cases))for(const width of [320,390,768,1024,1440]){
  await page.setViewportSize({width,height:900});await page.goto('https://restore.test/reports-management.php');
  const f=await (await page.locator('#hubFrame').elementHandle()).contentFrame();await f.waitForLoadState('load');await f.evaluate(()=>document.fonts.ready);await page.waitForTimeout(200);
  const m=await f.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,height:document.body.offsetHeight,forms:[...document.querySelectorAll('main form')].filter(e=>e.offsetHeight&&getComputedStyle(e).overflowY!=='visible'&&e.scrollHeight>e.clientHeight+2).length}));
  const parent=await page.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,height:document.getElementById('hubFrame').offsetHeight}));
  check(parent.scroll<=width+1,'Parent width '+state+' '+width);check(m.scroll<=m.width+1,'Child width '+state+' '+width);check(!m.forms,'Forms unclipped '+state+' '+width);
  if(width<=900)check(Math.abs(parent.height-Math.max(240,m.height+2))<=5,'Content-sized frame');
  if(width===390){await page.screenshot({path:join(dir,state+'.png'),fullPage:true});const axe=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();violations.push({state,violations:axe.violations});}
  results.push({state,width,child:m,parent});
 }
 check(!errors.length,JSON.stringify(errors));
 check(!violations.some(v=>v.violations.length),'Accessibility: '+JSON.stringify(violations.filter(v=>v.violations.length)));
 writeFileSync(join(dir,'browser.json'),JSON.stringify({checks,results,errors,accessibility:violations},null,2));
 console.log('PASS',checks,'checks;',results.length,'actual restored nested layouts');await context.close();
}finally{await browser.close();}
