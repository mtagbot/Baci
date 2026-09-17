import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFileSync,writeFileSync,existsSync} from 'node:fs';
import {join} from 'node:path';
import {REPO,resolveFile} from './harness/site.mjs';
const require=createRequire(join(process.env.BROWSER_MODULES||join(REPO,'tests/node_modules'),'_r.cjs'));
const {chromium:pw}=require('playwright'),cm=require('@sparticuz/chromium'),chromium=cm.default||cm;
const browser=await pw.launch({executablePath:await chromium.executablePath(),args:chromium.args,headless:true});
try{
 const page=await browser.newPage({viewport:{width:1050,height:1250}});
 await page.route('**/*',async route=>{
  const u=new URL(route.request().url()),p=decodeURIComponent(u.pathname).slice(1);
  if(u.hostname!=='quality.test')return route.abort();
  if(p==='login-captcha-state.php')return route.fulfill({contentType:'application/json',body:JSON.stringify({need:(route.request().headers().referer||'').includes('retry.html')})});
  const file=p.endsWith('.html')?join(REPO,'.cache/quality/fixtures',p):resolveFile(p);
  if(!existsSync(file))return route.fulfill({status:404,body:''});
  await route.fulfill({body:readFileSync(file),contentType:p.endsWith('.html')?'text/html;charset=utf-8':p.endsWith('.js')?'text/javascript':p.endsWith('.css')?'text/css':p.endsWith('.ttf')?'font/ttf':'application/octet-stream'});
 });
 for(const font of ['Vazirmatn','Sahel','Yekan','CustomUploadedFont']){
  await page.goto('https://quality.test/font-'+font+'.html');await page.evaluate(()=>document.fonts.ready);
  const families=await page.locator('h1,input,button,td').evaluateAll(es=>es.map(e=>getComputedStyle(e).fontFamily));
  assert(families.length>=4,'Missing font fixture');assert(families.every(f=>f.startsWith(font)),JSON.stringify(families));
  assert(await page.evaluate(f=>[...document.fonts].some(face=>face.family===f&&face.status==='loaded'),font));
 }
 await page.goto('https://quality.test/login.html');
 const cap=page.locator('#adminTab .auth-captcha');assert.equal(await cap.isVisible(),false);
 assert.equal(await cap.locator('input').isDisabled(),true);
 await page.goto('https://quality.test/retry.html');assert.equal(await page.locator('#adminTab .auth-captcha').isVisible(),true);assert.equal(await page.locator('#adminTab .js-captcha-input').getAttribute('required'),'');
 await page.goto('https://quality.test/square.html');await page.evaluate(()=>document.fonts.ready);
 const square=await page.locator('.card-id.sq').first().evaluate(e=>{const r=e.getBoundingClientRect(),c=e.querySelector('.card-qr'),q=c.getBoundingClientRect();return{width:q.width*25.4/96,left:q.left-r.left,right:r.right-q.right,bottom:r.bottom-q.bottom,pixels:c.width};});
 assert(Math.abs(square.width-50.4)<.1&&square.left>0&&square.right>0&&square.bottom>0&&square.pixels>500,JSON.stringify(square));
 await page.goto('https://quality.test/teacher.html');await page.evaluate(()=>document.fonts.ready);await page.emulateMedia({media:'print'});
 assert.equal(await page.locator('.class-code').innerText(),'1/7');
 for(const zoom of ['default','max']){
  if(zoom==='max')await page.evaluate(()=>{for(const id of ['cFont','cRow']){const e=document.getElementById(id);e.value=e.max;e.dispatchEvent(new Event('input'));}fitClassSheets();});
  const geometry=await page.evaluate(()=>[...document.querySelectorAll('.sheet')].map(s=>{const r=s.getBoundingClientRect(),t=s.querySelector('table').getBoundingClientRect();return{left:t.left-r.left,right:r.right-t.right,top:t.top-r.top,bottom:r.bottom-t.bottom};}));
  assert(geometry.every(g=>Object.values(g).every(v=>v>=21.5)),JSON.stringify({zoom,geometry}));
  const headers=await page.locator('.h-nam').evaluateAll(es=>es.map(e=>({text:e.textContent,x:e.getBoundingClientRect().x,w:e.getBoundingClientRect().width})));
  assert.equal(headers.length,2);assert.equal(headers[1].text,'نام');assert(headers.every(h=>h.w>15&&h.x>0));
  const pdf=await page.pdf({preferCSSPageSize:true,printBackground:true});writeFileSync(join(REPO,'.cache/quality/teacher-'+zoom+'.pdf'),pdf);
  assert.equal((pdf.toString('latin1').match(/\/Type\s*\/Page\b/g)||[]).length,2,'Exactly two pages at '+zoom);
 }
 await page.screenshot({path:join(REPO,'.cache/quality/teacher.png'),fullPage:true});
 console.log('PASS Chromium: three bundled fonts and a custom TTF on headings/forms/table, first login hidden+disabled, both name headers, >=5.7mm measured safety, exactly 2 A4 PDF pages at default and maximum controls');
}finally{await browser.close();}
