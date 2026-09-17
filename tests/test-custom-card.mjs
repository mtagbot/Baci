import assert from 'node:assert/strict';
import {readFileSync,writeFileSync,existsSync,mkdirSync} from 'node:fs';
import {createRequire} from 'node:module';
import {run,req,php,loginAdmin} from './harness/lib.mjs';
import {REPO,resolveFile} from './harness/site.mjs';
const sid='customCards';await loginAdmin(sid);
await run(`<?php require '/www/includes/functions.php';set_setting('current_academic_year','1404/1405');set_setting('school_name','دبیرستان آزمایشی');set_setting('school_name_short','');set_setting('school_province','تهران');set_setting('school_region','شهریار');set_setting('school_unit_type','متوسطه اول پسرانه');set_setting('logo_url','');DB::execute("UPDATE students SET status='inactive'");
foreach(['سید محمد طاها','نام طولانی دانش‌آموز برای بررسی بدون بریدگی','بهار'] as $i=>$name)DB::execute('INSERT INTO students(id,national_id,first_name,last_name,class_name,grade_level,status,academic_year,photo_url) VALUES (?,?,?,?,?,?,?,?,?)',[8500+$i,sprintf('%010d',$i+8500),$name,'آزمایشی','نهم ۲','نهم','active','1404/1405',$i===0?'uploads/custom-fixture.svg':'']);`);
async function render(query=''){
 const r=await req('',{file:'entry-cards.php',sid,query});assert(!r.stderr,r.stderr);assert(!r.res.fatal,r.res.fatal);assert(!r.res.php_issues,r.res.php_issues);return r.res.page;
}
const tokens=h=>[...h.matchAll(/<canvas[^>]+data-qr="([^"]+)"/g)].map(x=>x[1]);
const normal=await render('print=1'),custom=await render('print=1&layout=custom');
assert.deepEqual(tokens(custom),tokens(normal));assert(custom.includes(' custom cut'));assert(custom.includes('Bacirat.ir'));
assert((await render()).includes('<option value="custom">سفارشی</option>'));
for(const raw of ['', 'school.example', '<img src=x onerror=alert(1)>', 'x'.repeat(100), 'متن دلخواه']){
 const h=await render('print=1&layout=custom&custom_text='+encodeURIComponent(raw));
 assert(!h.includes('<img src=x onerror'));assert.deepEqual(tokens(h),tokens(normal));
 const text=h.match(/class="card-custom-site card-custom-only" dir="ltr">([\s\S]*?)<\/div>/)[1];
 if(raw==='')assert.equal(text,'');if(raw.length===100)assert.equal(text.length,80);
}
assert(!(await render('print=1&layout=bad')).includes(' custom cut'));
console.log('PASS custom layout server selection, unchanged QR, text escaping/limit/empty label');
if(process.env.PRINT_BROWSER){
 const require=createRequire((process.env.BROWSER_MODULES||REPO+'/.cache/browser/node_modules')+'/_resolver.cjs');
 const {chromium:pw}=require('playwright'),mod=require('@sparticuz/chromium'),chromium=mod.default||mod,{PDFDocument}=require('pdf-lib');
 const browser=await pw.launch({executablePath:await chromium.executablePath(),args:chromium.args,headless:true});
 const dir=REPO+'/.cache/custom';mkdirSync(dir,{recursive:true});
 try{
  const context=await browser.newContext({viewport:{width:1440,height:1050},deviceScaleFactor:2}),errors=[];
  await context.addInitScript(()=>{window.print=()=>window.printCalls=(window.printCalls||0)+1;window.open=url=>window.opened=url;});
  await context.route('**/*',async route=>{
   const u=new URL(route.request().url()),f=decodeURIComponent(u.pathname).slice(1);
   if(f==='entry-cards.php')return route.fulfill({contentType:'text/html',body:await render(u.search.slice(1))});
   if(f==='uploads/custom-fixture.svg')return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="225" height="290"><rect width="225" height="290" fill="#e0eff7"/><circle cx="112" cy="85" r="40" fill="#8ca8be"/><path d="M40 290V205Q40 150 112 150T185 205V290" fill="#4c7293"/></svg>'});
   if(f.endsWith('.php'))return route.fulfill({contentType:'application/json',body:'{"authenticated":true}'});
   const p=resolveFile(f);if(!existsSync(p))return route.fulfill({status:404,body:''});
   return route.fulfill({contentType:{js:'application/javascript',css:'text/css',woff2:'font/woff2',ttf:'font/ttf',svg:'image/svg+xml'}[f.split('.').pop()]||'application/octet-stream',body:readFileSync(p)});
  });
  const live=await context.newPage(),print=await context.newPage();for(const p of [live,print])p.on('pageerror',e=>errors.push(e.message));
  await live.goto('https://custom.test/entry-cards.php?sort=nid');await live.evaluate(()=>document.fonts.ready);
  // Existing layouts restore their own geometry/font styles after switching away from custom.
  async function geometry(page,root){return page.evaluate(root=>{
   const c=document.querySelector(root),b=c.getBoundingClientRect(),scale=b.width/(85.6*96/25.4);
   const out={};for(const sel of ['.card-head','.card-photo','.card-name','.card-qr','.card-custom-site','.card-school','.card-meta','.card-year']){
    const e=c.querySelector(sel);if(!e)continue;const r=e.getBoundingClientRect(),s=getComputedStyle(e);
    out[sel]={x:!r.width&&!r.height?0:(r.x-b.x)/scale,y:!r.width&&!r.height?0:(r.y-b.y)/scale,w:r.width/scale,h:r.height/scale,font:s.fontSize,display:s.display,text:e.textContent,fit:e.scrollWidth<=e.clientWidth};
   }return out;
  },root);}
  const originals={};for(const layout of ['full','qrmax','sq']){await live.selectOption('#cLayout',layout);originals[layout]=await geometry(live,'#cardPreview .card-id');}
  for(const scale of [.6,1,1.6]){
   await live.evaluate(scale=>{setCardDesign({layout:'custom',scale,side:'both',copies:3,theme:'classic',paper:'A4',orient:'portrait',margin:8,gap:4,photo:true,year:true,nid:true});applyCardDesign();},scale);
   await live.evaluate(()=>document.fonts.ready);await live.evaluate(()=>fitCustomCards(document.getElementById('cardPreview')));
   assert(await live.locator('#customCardOptions').isVisible());
   await live.fill('#cCustomText','Bacirat.ir');await live.evaluate(()=>openCardPrint());
   const g=await live.evaluate(()=>gridInfo(getCardDesign()));
   await print.goto(new URL(await live.evaluate(()=>window.opened),'https://custom.test/').href);await print.waitForFunction(()=>window.printCalls===1);
   const a=await geometry(live,'#cardPreview .custom'),b=await geometry(print,'.page .custom');
   for(const k of Object.keys(a))for(const attr of ['x','y','w','h'])assert(Math.abs(a[k][attr]-b[k][attr])<.6,`${scale} ${k} ${attr}`);
   assert(b['.card-photo'].y<b['.card-head'].h);assert(b['.card-photo'].y+b['.card-photo'].h<b['.card-name'].y);
   assert(b['.card-name'].x+b['.card-name'].w<b['.card-qr'].x);assert(b['.card-name'].fit);assert(b['.card-custom-site'].fit);
   assert.equal(await print.locator('.custom .card-grade').first().isVisible(),false);
   assert(await print.evaluate(()=>[...document.querySelectorAll('.custom .card-name')].every(e=>e.scrollWidth<=e.clientWidth)));
   assert.equal(await print.locator('.custom .card-photo').first().evaluate(e=>getComputedStyle(e).objectFit),'contain');
   const pdf=await print.pdf({preferCSSPageSize:true,printBackground:true});assert.equal((await PDFDocument.load(pdf)).getPageCount(),Math.ceil(18/g.perPage));writeFileSync(dir+`/custom-${scale}.pdf`,pdf);
   await print.addScriptTag({content:readFileSync(resolveFile('assets/js/jsqr.min.js'),'utf8')});
   assert(await print.evaluate(()=>[...document.querySelectorAll('canvas[data-qr]')].every(c=>{const d=c.getContext('2d').getImageData(0,0,c.width,c.height),q=jsQR(d.data,d.width,d.height);return q&&q.data===c.dataset.qr;})));
   if(scale===1){await print.emulateMedia({media:'print'});await print.locator('.custom').first().screenshot({path:dir+'/custom-card.png'});await live.locator('#pvPaper').screenshot({path:dir+'/custom-preview.png'});}
  }
  await live.fill('#cCustomText','school.example');await live.reload();assert.equal(await live.inputValue('#cLayout'),'custom');assert.equal(await live.inputValue('#cCustomText'),'school.example');
  await live.fill('#cCustomText','');assert.equal(await live.locator('#cardPreview .card-custom-site').first().textContent(),'');
  await live.uncheck('#cPhoto');await live.uncheck('#cNid');await live.uncheck('#cYear');
  for(const sel of ['.card-photo','.card-nid','.card-year'])assert.equal(await live.locator('#cardPreview '+sel).first().isVisible(),false);
  await live.evaluate(()=>setCardDesign({scale:1,side:'front',copies:1,photo:true,nid:true,year:true,theme:'classic'}));
  for(const layout of ['full','qrmax','sq']){await live.selectOption('#cLayout',layout);const after=await geometry(live,'#cardPreview .card-id');// Normalized DOMRect division can differ by < 1/64 CSS px after scrolling.
   for(const k of Object.keys(after))if(k!=='.card-custom-site')for(const attr of Object.keys(after[k])){const actual=after[k][attr],expected=originals[layout][k][attr];if(typeof actual==='number')assert(Math.abs(actual-expected)<.02,layout+' '+k+' '+attr);else assert.deepEqual(actual,expected,layout+' '+k+' '+attr);}}
  assert.deepEqual(errors,[]);console.log('PASS Chromium custom: internal geometry parity, photo/QR separation, unclipped long names, QR decoding, A4 PDF pagination, persistence, toggles and original-layout restoration');
 }finally{await browser.close();}
}
process.exit(0);
