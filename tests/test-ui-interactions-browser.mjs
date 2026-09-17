import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFileSync,existsSync} from 'node:fs';
import {join} from 'node:path';
import {REPO,resolveSite} from './harness/site.mjs';
const require=createRequire(join(REPO,'.cache/browser/node_modules/_resolver.cjs'));
const {chromium:pw}=require('playwright'),mod=require('@sparticuz/chromium'),chromium=mod.default||mod;
const catalog=JSON.parse(readFileSync(join(REPO,'.cache/ui-audit/routes.json')));
const browser=await pw.launch({executablePath:await chromium.executablePath(),args:chromium.args,headless:true});let count=0;
async function setup(opts={}){
 const ctx=await browser.newContext({viewport:{width:390,height:844},...opts});
 await ctx.route('**/*',async route=>{const u=new URL(route.request().url()),p=decodeURIComponent(u.pathname).slice(1);if(u.hostname!=='school.test')return route.abort();const snap=catalog.routes.find(r=>r.snapshot===p&&r.rendered);if(snap)return route.fulfill({contentType:'text/html;charset=utf-8',body:readFileSync(join(REPO,'.cache/ui-audit/pages',p))});if(p.endsWith('.php'))return route.fulfill({contentType:'application/json',body:'{"ok":true,"authenticated":true,"items":[],"data":[]}'});const f=join(resolveSite(),p);return route.fulfill({status:existsSync(f)?200:404,body:existsSync(f)?readFileSync(f):'',contentType:{js:'application/javascript',css:'text/css',ttf:'font/ttf',woff2:'font/woff2'}[p.split('.').pop()]||'application/octet-stream'});});
 return ctx;
}
function url(file,role){return 'https://school.test/'+catalog.routes.find(r=>r.file===file&&r.role===role&&r.rendered).snapshot;}
try{
 const ctx=await setup(),page=await ctx.newPage();await page.goto(url('index.php','admin'));
 await page.locator('#sidebarToggleBtn').click();await page.waitForFunction(()=>document.querySelector('.sidebar').getAttribute('aria-modal')==='true');
 assert.equal(await page.locator('#sidebarToggleBtn').getAttribute('aria-expanded'),'true');assert(await page.locator('.ui-drawer-close').evaluate(e=>e===document.activeElement));count++;
 await page.keyboard.press('Shift+Tab');assert(await page.evaluate(()=>!!document.activeElement.closest('.sidebar')));await page.keyboard.press('Tab');assert(await page.locator('.ui-drawer-close').evaluate(e=>e===document.activeElement));count++;
 await page.keyboard.press('Escape');assert.equal(await page.locator('#sidebarToggleBtn').getAttribute('aria-expanded'),'false');assert(await page.locator('#sidebarToggleBtn').evaluate(e=>e===document.activeElement));count++;
 await page.locator('#sidebarToggleBtn').click();await page.locator('.ui-drawer-close').click();assert.equal(await page.locator('#sidebarToggleBtn').getAttribute('aria-expanded'),'false');count++;
 await page.goto(url('index.php','guest'));const tabs=page.locator('.auth-tabs button');assert(await tabs.count()>1);await tabs.first().focus();await page.keyboard.press('ArrowLeft');assert.equal(await tabs.nth(1).getAttribute('aria-selected'),'true');await page.keyboard.press('Home');assert.equal(await tabs.first().getAttribute('aria-selected'),'true');count++;
 await page.goto(url('students.php','admin'));assert(await page.locator('.ui-table-scroll').count()>0);await page.locator('.ui-table-scroll').first().focus();assert(await page.locator('.ui-table-scroll').first().evaluate(e=>document.activeElement===e));count++;
 // Isolated content fixture exercises both automatic additions and protected user values.
 await page.setContent('<body><div id="host"></div></body>');
 await page.evaluate(()=>{window.SchoolUI=undefined;window.dialogs=[];window.confirm=function(s){dialogs.push(s);return false;};});
 await page.addScriptTag({path:join(resolveSite(),'assets/js/school-icons.js')});await page.addScriptTag({path:join(resolveSite(),'assets/js/school-ui.js')});
 const input='<p id="prose">یادداشت کاربر ✅ همان متن</p><div data-ui-content id="data">✅ متن اصلی</div><textarea id="text">✅ متن اصلی</textarea><div class="tgsim-bubble" id="bubble">✅ پیام ذخیره‌شده</div><table><tr><td id="cell">نام ✅ دانش‌آموز</td></tr></table><select id="pick"><option>✅ مقدار اصلی</option></select><button id="action">➕ افزودن</button><button id="only">➕</button><code id="code">✅ کد</code><div contenteditable id="rich">✅ محتوای سؤال</div>';
 await page.locator('#host').evaluate((e,s)=>e.innerHTML=s,input);await page.waitForFunction(()=>!!document.querySelector('#action svg'));
 const data=await page.evaluate(()=>({prose:document.querySelector('#prose').textContent,text:document.querySelector('#text').value,data:document.querySelector('#data').textContent,bubble:document.querySelector('#bubble').textContent,cell:document.querySelector('#cell').textContent,rich:document.querySelector('#rich').textContent,code:document.querySelector('#code').textContent,option:document.querySelector('#pick').value,label:document.querySelector('#pick option').textContent,only:document.querySelector('#only').getAttribute('aria-label')}));
 assert.deepEqual(data,{prose:'یادداشت کاربر ✅ همان متن',text:'✅ متن اصلی',data:'✅ متن اصلی',bubble:'✅ پیام ذخیره‌شده',cell:'نام ✅ دانش‌آموز',rich:'✅ محتوای سؤال',code:'✅ کد',option:'✅ مقدار اصلی',label:'مقدار اصلی',only:'افزودن'});count++;
 await page.evaluate(()=>{const o=document.createElement('option');o.textContent='✅ گزینه جدید';document.querySelector('#pick').appendChild(o);});await page.waitForFunction(()=>document.querySelector('#pick option:last-child').textContent==='گزینه جدید');assert.equal(await page.locator('#pick option:last-child').getAttribute('value'),'✅ گزینه جدید');count++;
 assert.equal(await page.evaluate(()=>confirm('⚠️ سطر اول\nسطر دوم')),false);assert.equal(await page.evaluate(()=>dialogs[0]),'سطر اول\nسطر دوم');count++;
 const before=await page.locator('svg').count();await page.evaluate(()=>SchoolUI.refresh(document.body));await page.waitForTimeout(80);assert.equal(await page.locator('svg').count(),before);assert.equal(await page.locator('.ui-table-scroll .ui-table-scroll').count(),0);count++;
 const fallback=await setup();await fallback.addInitScript(()=>{window.MutationObserver=undefined;window.ResizeObserver=undefined;window.requestAnimationFrame=undefined;});const old=await fallback.newPage();await old.goto(url('index.php','admin'));assert(await old.evaluate(()=>!!window.SchoolUI));await old.locator('#sidebarToggleBtn').click();await old.waitForTimeout(80);assert.equal(await old.locator('#sidebarToggleBtn').getAttribute('aria-expanded'),'true');await old.keyboard.press('Escape');count++;
 const nojs=await setup({javaScriptEnabled:false});const basic=await nojs.newPage();await basic.goto(url('index.php','admin'));assert(await basic.locator('.sidebar').isVisible());assert(await basic.locator('.sidebar a[href]').first().isVisible());assert(await basic.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));count++;
 console.log(`PASS ${count} UI interaction/data/feature-off checks; mocked snapshot network, not live backend or a legacy-browser certification`);
}finally{await browser.close();}
