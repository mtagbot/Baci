// Repeatable cold-resource Chromium comparison, not a claim about real WAN/Windows timing.
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFileSync,existsSync,writeFileSync,mkdirSync} from 'node:fs';
import {join} from 'node:path';
import {REPO,resolveSite} from './harness/site.mjs';
const require=createRequire(join(REPO,'.cache/browser/node_modules/_r.cjs'));
const {chromium:pw}=require('playwright'),mod=require('@sparticuz/chromium'),chromium=mod.default||mod;
const browser=await pw.launch({executablePath:await chromium.executablePath(),args:chromium.args.filter(a=>a!=='--single-process'),headless:true});
const results=[];
try{
 for(const phase of ['before','after'])for(const route of [{file:'index.php',role:'guest'},{file:'index.php',role:'admin'},{file:'students.php',role:'admin'}]){
  const base=phase==='before'?join(REPO,'.cache/speed-audit/before'):resolveSite();
  const cat=JSON.parse(readFileSync(join(REPO,phase==='before'?'.cache/speed-audit/before-catalog/routes.json':'.cache/ui-audit/routes.json')));
  const snapshot=cat.routes.find(r=>r.file===route.file&&r.role===route.role&&r.rendered).snapshot;
  const html=readFileSync(join(REPO,phase==='before'?'.cache/speed-audit/before-catalog/pages':'.cache/ui-audit/pages',snapshot));
  const context=await browser.newContext({viewport:{width:1440,height:960}});let requestedBytes=0,requests=[];
  await context.addInitScript(()=>{
   window.print=()=>{};window.alert=()=>{};window.__audit={shifts:[],dom:null};
   new PerformanceObserver(list=>{for(const e of list.getEntries())if(!e.hadRecentInput)window.__audit.shifts.push({at:e.startTime,value:e.value,nodes:e.sources.map(s=>({tag:s.node&&s.node.nodeName,id:s.node&&s.node.id,cls:s.node&&s.node.className,previous:s.previousRect,current:s.currentRect}))});}).observe({type:'layout-shift',buffered:true});
   document.addEventListener('DOMContentLoaded',()=>setTimeout(()=>{
    const rows=[...document.querySelectorAll('main tbody tr')];
    window.__audit.dom={at:performance.now(),rows:rows.length,hidden:rows.filter(r=>Number(getComputedStyle(r).opacity)<1).length,animated:rows.filter(r=>getComputedStyle(r).transform!=='none').length};
   },0));
  });
  await context.route('**/*',async r=>{
   const u=new URL(r.request().url()),p=u.pathname.slice(1);if(u.hostname!=='perf.test')return r.abort();
   if(p==='audit.html')return r.fulfill({contentType:'text/html;charset=utf-8',body:html});
   if(p.endsWith('.php'))return r.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,authenticated:true,need:false,pending:0,items:[],data:[]})});
   const f=join(base,p);if(!existsSync(f))return r.fulfill({status:404,body:''});
   const data=readFileSync(f);requestedBytes+=data.length;requests.push({path:p,bytes:data.length});
   await new Promise(r=>setTimeout(r,/\.(ttf|woff2)$/.test(p)?700:p.includes('chart.umd')?600:/\.(js|css)$/.test(p)?150:50));
   return r.fulfill({body:data,contentType:{js:'text/javascript',css:'text/css',woff2:'font/woff2',ttf:'font/ttf',svg:'image/svg+xml'}[p.split('.').pop()]||'application/octet-stream'});
  });
  const page=await context.newPage();const cdp=await context.newCDPSession(page);await cdp.send('Emulation.setCPUThrottlingRate',{rate:4});
  await page.goto('https://perf.test/audit.html',{waitUntil:'domcontentloaded'});await page.evaluate(()=>document.fonts.ready);await page.waitForTimeout(900);
  const measurement=await page.evaluate(()=>({dom:window.__audit.dom,cls:window.__audit.shifts.reduce((s,e)=>s+e.value,0),shifts:window.__audit.shifts,paint:performance.getEntriesByType('paint').map(e=>({name:e.name,ms:e.startTime})),fonts:[...document.fonts].map(f=>({family:f.family,status:f.status,display:f.display})),ui:window.SchoolUI&&window.SchoolUI.metrics}));
  results.push({phase,...route,requestedBytes,requests,...measurement});
  console.log(phase,route.file,route.role,JSON.stringify({dom:measurement.dom,cls:measurement.cls,bytes:requestedBytes,paint:measurement.paint}));
  if(phase==='after'){assert.equal(measurement.dom.hidden,0,'No hidden rows at startup');assert.equal(measurement.dom.animated,0,'No entry transform on rows');assert(!requests.some(r=>r.path.includes('chart.umd')),'No unused chart download');assert(requests.some(r=>r.path.endsWith('.woff2')),'Preloaded compressed screen font');}
  await context.close();
 }
 for(const after of results.filter(r=>r.phase==='after')){
  const before=results.find(r=>r.phase==='before'&&r.file===after.file&&r.role===after.role);
  assert(after.requestedBytes<before.requestedBytes,'Reduced cold transfer');
  // Compare geometry, not brittle absolute wall-clock timings on a shared CI machine.
  assert(after.cls<=Math.max(.005,before.cls),'No increase in startup layout shift');
 }
 mkdirSync(join(REPO,'docs/fast-ui'),{recursive:true});writeFileSync(join(REPO,'docs/fast-ui/performance.json'),JSON.stringify({environment:'Chromium, viewport 1440x960, CPU 4x, fresh context per page, fonts delayed 700ms, CSS/JS 150ms, Chart 600ms; mocked PHP background responses; one sample per route/version',results},null,2));
 console.log('PASS cold rendering: immediate rows, fewer bytes, layout-shift comparison');
}finally{await browser.close();}
