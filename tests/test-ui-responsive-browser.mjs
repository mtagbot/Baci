import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFileSync,writeFileSync,existsSync,mkdirSync} from 'node:fs';
import {join} from 'node:path';
import {REPO,resolveSite} from './harness/site.mjs';
const require=createRequire(join(REPO,'.cache/browser/node_modules/_resolver.cjs'));
const {chromium:pw}=require('playwright'),mod=require('@sparticuz/chromium'),chromium=mod.default||mod;
const {default:AxeBuilder}=require('@axe-core/playwright');
const cat=JSON.parse(readFileSync(join(REPO,'.cache/ui-audit/routes.json'))),byName={};
for(const r of cat.routes)if(r.rendered)byName[r.snapshot]=r;
const dir=join(REPO,'.cache/ui-audit/browser');mkdirSync(dir,{recursive:true});
const browser=await pw.launch({executablePath:await chromium.executablePath(),args:chromium.args,headless:true});
let results=[],axe=[];
try{
 const context=await browser.newContext({viewport:{width:390,height:844},reducedMotion:'reduce'});
 await context.addInitScript(()=>{window.print=()=>{};window.confirm=()=>false;window.alert=()=>{};});
 await context.route('**/*',async route=>{
  const u=new URL(route.request().url()),p=decodeURIComponent(u.pathname).slice(1);
  if(u.hostname!=='school.test')return route.abort();
  const snap=byName[p]||byName[u.searchParams.get('_audit')];if(snap)return route.fulfill({contentType:'text/html;charset=utf-8',body:readFileSync(join(REPO,'.cache/ui-audit/pages',snap.snapshot))});
  if(p.endsWith('.php'))return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,authenticated:true,need:false,pending:0,items:[],data:[]})});
  const file=join(resolveSite(),p);if(!existsSync(file))return route.fulfill({status:404,body:''});
  return route.fulfill({body:readFileSync(file),contentType:{js:'application/javascript',css:'text/css',ttf:'font/ttf',woff2:'font/woff2',svg:'image/svg+xml'}[p.split('.').pop()]||'application/octet-stream'});
 });
 const page=await context.newPage();let errors=[];page.on('pageerror',e=>errors.push(e.message));
 const widths=process.env.UI_QUICK? [390,1440]:[320,390,768,1024,1440];
 for(const r of Object.values(byName)){
  if(process.env.UI_QUICK&&!['index.php','admin-login.php','students.php','teacher-panel.php','settings.php','entry-cards.php','api/docs.php'].includes(r.file))continue;
  for(const width of widths){
   errors=[];await page.setViewportSize({width,height:900});await page.goto('https://school.test/'+r.file+'?_audit='+encodeURIComponent(r.snapshot));await page.evaluate(()=>document.fonts?document.fonts.ready:Promise.resolve());
   await page.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))));
   const m=await page.evaluate(()=>{
    const w=innerWidth,main=document.querySelector('main'),foot=document.querySelector('.school-footer'),nav=document.querySelector('.navbar'),shell=document.querySelector('body.school-app>.flex.flex-1');
    const overflow=[];for(const e of document.querySelectorAll('body *')){const s=getComputedStyle(e);if(s.display==='none'||s.visibility==='hidden'||s.position==='fixed'||e.closest('svg,.ui-sr,.ui-table-scroll,.ui-paper-scroll,.sidebar,.tile-sprite,.ui-skip,[hidden]'))continue;const r=e.getBoundingClientRect();if(r.width>0&&(r.left<-.8||r.right>w+.8))overflow.push({tag:e.tagName,cls:e.className,x:Math.round(r.x),width:Math.round(r.width)});if(overflow.length>=8)break;}
    return{headerHeight:nav?nav.getBoundingClientRect().height:0,shellClipped:!!(shell&&getComputedStyle(shell).overflowY==='hidden'&&shell.scrollHeight>shell.clientHeight+1),width:w,scrollWidth:document.documentElement.scrollWidth,overflow,footer:foot?{top:foot.getBoundingClientRect().top,bottom:foot.getBoundingClientRect().bottom,mainBottom:main?main.getBoundingClientRect().bottom:0}:null,returnLinks:!!document.querySelector('.school-return-nav a,a[href="students.php"],a[href="index.php"]'),ui:!!window.SchoolUI,icons:document.querySelectorAll('.school-icon').length,metrics:window.SchoolUI&&SchoolUI.metrics};
   });
   results.push({file:r.file,role:r.role,snapshot:r.snapshot,...m,errors});
   if((r.file==='index.php'||r.file==='settings.php'||r.file==='teacher-panel.php')&&[390,1440].includes(width))await page.screenshot({path:join(dir,r.snapshot+'-'+width+'.png'),fullPage:true});
  }
  if(['index.php','admin-login.php','settings.php','students.php'].includes(r.file)&&!process.env.UI_NO_AXE){
   const a=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21aa','wcag22aa','wcag2aaa']).analyze();axe.push({file:r.file,role:r.role,violations:a.violations.map(v=>({id:v.id,impact:v.impact,description:v.description,nodes:v.nodes.map(n=>({html:n.html,target:n.target,summary:n.failureSummary}))}))});
  }
  console.log('AUDIT',r.file,r.role);
 }
 writeFileSync(join(dir,'layout.json'),JSON.stringify(results,null,2));writeFileSync(join(dir,'accessibility.json'),JSON.stringify(axe,null,2));
 const bad=results.filter(r=>r.scrollWidth>r.width+1);console.log('OVERFLOW',bad.length,'/',results.length);for(const r of bad)console.log(r.file,r.role,r.width,r.scrollWidth,JSON.stringify(r.overflow));
 console.log('Shared shell footer overlap:',results.filter(r=>r.footer&&r.footer.top<r.footer.mainBottom-1).length);
 assert.equal(bad.length,0,'Document overflow');assert(!results.some(r=>!r.returnLinks),'A page has no return route');assert(!results.some(r=>r.headerHeight>220),'Oversized header');assert(!results.some(r=>r.shellClipped),'Clipped main content');assert(!results.some(r=>r.footer&&r.footer.top<r.footer.mainBottom-1),'Footer overlap');assert(!results.some(r=>r.errors.length),'Browser errors');assert(!axe.some(r=>r.violations.length),'Sample Axe violations');
}finally{await browser.close();}
