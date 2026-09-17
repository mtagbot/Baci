import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFileSync,existsSync,writeFileSync} from 'node:fs';
import {join} from 'node:path';
import {REPO,resolveSite} from './harness/site.mjs';
const require=createRequire(join(REPO,'.cache/browser/node_modules/_r.cjs'));
const {chromium:pw}=require('playwright'),cm=require('@sparticuz/chromium'),chromium=cm.default||cm;
const browser=await pw.launch({executablePath:await chromium.executablePath(),args:chromium.args,headless:true});const dir=join(REPO,'.cache/student-workflow');let n=0;
try{
 const ctx=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'});
 await ctx.addInitScript(()=>{window.printCalls=[];window.print=function(){printCalls.push({fonts:[...document.fonts].map(f=>({family:f.family,status:f.status})),font:getComputedStyle(document.querySelector('td')||document.body).fontFamily});};});
 await ctx.route('**/*',async r=>{const u=new URL(r.request().url());if(u.host!=='school.test')return r.abort();let p=u.pathname.slice(1),file;
 if(p==='students.php')file=join(dir,'fixtures',u.searchParams.get('action')==='add'?'add.html':u.searchParams.get('action')==='edit'?'edit.html':'list.html');
 else if(p==='student-bulk-report.php')file=join(dir,'fixtures','report-'+(u.searchParams.get('font')||'Vazirmatn')+'.html');
 else if(p.endsWith('.php'))return r.fulfill({contentType:'application/json',body:'{"authenticated":true,"ok":true,"data":[]}'});
 else file=join(resolveSite(),p);
 if(!existsSync(file))return r.fulfill({status:404,body:''});
 await r.fulfill({body:readFileSync(file),contentType:/\.html$/.test(file)?'text/html;charset=utf-8':p.endsWith('.js')?'application/javascript':p.endsWith('.css')?'text/css':p.endsWith('.ttf')?'font/ttf':'application/octet-stream'});
 });
 const page=await ctx.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto('https://school.test/students.php?action=add');
 assert.equal(await page.locator('#student-profile-form fieldset').count(),5);n++;
 await page.locator('#st-serial_number').focus();await page.keyboard.type('356750');assert.equal(await page.evaluate(()=>document.activeElement.name),'serial_letter');
 await page.keyboard.insertText('ب');assert.equal(await page.evaluate(()=>document.activeElement.name),'serial_suffix');await page.keyboard.type('35');assert.equal(await page.evaluate(()=>document.activeElement.name),'birth_year');n++;
 await page.keyboard.type('1390');assert.equal(await page.evaluate(()=>document.activeElement.name),'birth_month');await page.keyboard.type('13');assert.equal(await page.evaluate(()=>document.activeElement.name),'birth_month');n++;
 await page.locator('[name=birth_month]').fill('07');await page.keyboard.press('Enter');assert.equal(await page.evaluate(()=>document.activeElement.name),'birth_day');await page.keyboard.press('Shift+Tab');assert.equal(await page.evaluate(()=>document.activeElement.name),'birth_month');n++;
 await page.locator('#student-auto-focus').uncheck();await page.locator('#st-postal_code').focus();await page.keyboard.type('1234567890');assert.equal(await page.evaluate(()=>document.activeElement.name),'postal_code');n++;
 await page.locator('#student-auto-focus').check();await page.locator('#st-father_phone').focus();await page.keyboard.type('09123456789');assert.equal(await page.evaluate(()=>document.activeElement.name),'mother_phone');n++;
 await page.goto('https://school.test/students.php?action=edit');await page.locator('#st-serial_number').focus();await page.keyboard.press('End');await page.keyboard.press('Backspace');await page.keyboard.type('1');assert.equal(await page.evaluate(()=>document.activeElement.name),'serial_number');n++;
 for(const width of [320,390,768,1024,1440,1920]){
  await page.setViewportSize({width,height:1000});await page.goto('https://school.test/students.php?action=add');await page.evaluate(()=>document.fonts.ready);
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Form overflow '+width);
  if(width>900){const m=await page.locator('.hdr-tiles').evaluate(e=>{const r=e.getBoundingClientRect();return{center:r.x+r.width/2,width:innerWidth};});assert(Math.abs(m.center-m.width/2)<2,JSON.stringify(m));}
  if([390,1440].includes(width))await page.screenshot({path:join(dir,'student-form-'+width+'.png'),fullPage:true});n++;
 }
 await page.goto('https://school.test/students.php');await page.locator('.st-check').first().check();await page.evaluate(()=>openReportModal());assert(await page.locator('#reportModal').isVisible());const fields=await page.locator('#reportModal [name="fields[]"]').count();assert(fields>=50);await page.evaluate(()=>selectReportFields(false));assert.equal(await page.locator('#reportModal [name="fields[]"]:checked').count(),0);await page.evaluate(()=>selectReportFields(true));assert.equal(await page.locator('#reportModal [name="fields[]"]:checked').count(),fields);n++;
 for(const font of ['Vazirmatn','Sahel','Yekan','CustomUploadedFont']){
  await page.goto('https://school.test/student-bulk-report.php?font='+font);await page.waitForFunction(()=>printCalls.length===1);
  const state=await page.evaluate(()=>printCalls[0]);assert(state.font.startsWith(font));assert(state.fonts.filter(x=>x.family===font).every(x=>x.status==='loaded'));assert(await page.locator('a[href="students.php"]').count()>0);
  await page.emulateMedia({media:'print'});assert(!await page.locator('.school-return-nav').isVisible());assert(!await page.locator('.report-actions').isVisible());
  const pdf=await page.pdf({preferCSSPageSize:true,printBackground:true});writeFileSync(join(dir,'report-'+font+'.pdf'),pdf);assert(pdf.toString('latin1').includes('/FontFile2'),'Embedded TrueType in final PDF');
  const bounds=await page.locator('table').evaluate(e=>({width:e.getBoundingClientRect().width,scroll:e.scrollWidth}));assert(bounds.scroll<=bounds.width+1,'Unclipped report columns');n++;
  await page.emulateMedia({media:'screen'});
 }
 // Direct open has deterministic GET navigation; it never relies on an empty history stack.
 assert.equal(await page.locator('.school-return-nav a').first().getAttribute('href'),'students.php');n++;
 await page.goto('https://school.test/students.php');await page.locator('.st-check').first().check();
 const [popup]=await Promise.all([page.waitForEvent('popup'),page.evaluate(()=>window.open('student-bulk-report.php?font=Vazirmatn','_blank'))]);await popup.waitForLoadState();
 await Promise.all([popup.waitForEvent('close'),popup.locator('.school-return-nav button').click()]);assert(await page.locator('.st-check').first().isChecked());n++;
 await ctx.route('**/*.ttf',r=>r.fulfill({status:404,body:''}));await page.goto('https://school.test/student-bulk-report.php?font=Vazirmatn');await page.waitForFunction(()=>document.getElementById('report-print-status').textContent.includes('متوقف شد'));assert.equal(await page.evaluate(()=>printCalls.length),0);n++;
 assert.deepEqual(errors,[]);console.log('PASS',n,'browser profile/keyboard/header/report-font/failure checks');
}finally{await browser.close();}
