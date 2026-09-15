// Real PHP/SQLite requests; optional real Chromium UI/QR/PDF regression checks.
// PRINT_BROWSER=1 enables browser checks (same dependencies as scanner browser tests).
import assert from 'node:assert/strict';
import {readFileSync,writeFileSync,mkdirSync,existsSync} from 'node:fs';
import {createRequire} from 'node:module';
import {run,req,loginAdmin,db} from './harness/lib.mjs';
import {REPO,resolveFile} from './harness/site.mjs';

const sid='copiesAdmin';
await loginAdmin(sid);
const setup=await run(`<?php require '/www/includes/functions.php';
set_setting('current_academic_year','1404/1405');
DB::execute("UPDATE students SET status='inactive'");
foreach ([
 [7001,'9000000003','علی','آذر','101','دهم','active','1404/1405'],
 [7002,'9000000001','بهار','بهرامی','101','دهم','active','1404/1405'],
 [7003,'9000000002','امیر','کریمی','102','یازدهم','active','1404/1405'],
 [7004,'9000000004','غیرفعال','نمونه','101','دهم','inactive','1404/1405'],
 [7005,'9000000005','قدیمی','نمونه','101','دهم','active','1403/1404']
] as $s) DB::execute('INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year) VALUES (?,?,?,?,?,?,?,?)',$s);
`);
assert(!setup.err,setup.err);
let checks=0;
async function render(file,query='') {
  const {res,stderr}=await req('',{file,query,sid});
  assert(!res.fatal,res.fatal);assert(!stderr,stderr);assert(!res.php_issues,res.php_issues);
  return res.page;
}
const qrList=html=>[...html.matchAll(/<canvas\b[^>]*data-qr="([^"]+)"/g)].map(m=>m[1]);
const expand=(list,n)=>list.flatMap(x=>Array(n).fill(x));
const ids=list=>list.map(q=>Number(q.split(':')[1]));
const fa=n=>String(n).replace(/\d/g,d=>'۰۱۲۳۴۵۶۷۸۹'[d]);
const pages=html=>(html.match(/class="page"/g)||[]).length;
const files=['attendance-tags.php','entry-cards.php'];
const baseline={};
for(const file of files){
  baseline[file]=qrList(await render(file,'print=1'));
  assert.deepEqual(ids(baseline[file]),[7001,7002,7003]);checks++;
  const cases=[['',1],['1',1],['2',2],['3',3],['10',10],['0',1],['11',1],['-1',1],['999999999999999999',1],['2.5',1],['1e1',1],['abc',1],['<script>',1],['۰۳',1],['۳',3],['١٠',10]];
  for(const [raw,n] of cases){
    const html=await render(file,'print=1&copies='+encodeURIComponent(raw));
    assert.deepEqual(qrList(html),expand(baseline[file],n),`${file}: ${raw}`);
    if(file==='entry-cards.php') assert.equal(pages(html),Math.ceil(3*n/8));
    else assert(html.includes(`تعداد تگ: ${fa(3*n)}`));
    checks++;
  }
  assert.deepEqual(qrList(await render(file,'print=1&copies[]=10')),baseline[file]);checks++;
  for(const sort of ['class','name','first','grade','nid']){
    const one=qrList(await render(file,`print=1&sort=${sort}&copies=1`));
    assert.deepEqual(qrList(await render(file,`print=1&sort=${sort}&copies=3`)),expand(one,3));checks++;
  }
  for(const [query,wanted] of [['class=101',[7001,7002]],['grade='+encodeURIComponent('یازدهم'),[7003]],['student_id=7002',[7002]],['class=missing',[]]]){
    assert.deepEqual(ids(qrList(await render(file,'print=1&copies=10&'+query))),expand(wanted,10));checks++;
  }
  const ui=await render(file),id=file==='attendance-tags.php'?'dCopies':'cCopies';
  const options=ui.match(new RegExp(`<select id="${id}"[\\s\\S]*?</select>`))[0];
  assert.deepEqual([...options.matchAll(/<option value="(\d+)"/g)].map(m=>+m[1]),[1,2,3,4,5,6,7,8,9,10]);
  assert(/value="1" selected/.test(options));checks++;
}
for(const n of [1,2,3,10]){
  const html=await render('entry-cards.php',`print=1&side=both&copies=${n}`);
  assert.deepEqual(qrList(html),expand(baseline['entry-cards.php'],n*2));
  const faces=[...html.matchAll(/<div class="(card-id|card-back)(?: |")/g)].map(m=>m[1]);
  assert.deepEqual(faces,Array(3*n).fill(['card-id','card-back']).flat());
  assert.equal(pages(html),Math.ceil(3*n*2/8));checks++;
}
const singleBoth=await render('entry-cards.php','print=1&side=both&copies=10&student_id=7002');
assert.deepEqual(ids(qrList(singleBoth)),Array(20).fill(7002));assert.equal(pages(singleBoth),3);checks++;
const nofit=await render('entry-cards.php','print=1&copies=10&paper=A6&scale=1.6&margin=50');
assert.equal(qrList(nofit).length,0);assert(nofit.includes('حتی یک کارت'));checks++;
assert.deepEqual(baseline[files[0]],baseline[files[1]],'Both pages use exactly the same permanent QR');
const tokens=await db('SELECT student_id,token FROM student_qr_tags WHERE student_id>=7001 ORDER BY student_id');
assert.deepEqual(tokens.map(t=>t.student_id),[7001,7002,7003]);
assert.deepEqual(tokens.map(t=>'MTAG-ATT:'+t.student_id+':'+t.token),baseline[files[0]]);checks++;
console.log(`PASS ${checks} print-copy PHP cases: validation, filters, all sorts, contiguous tokens, pairs, counts`);

if(process.env.PRINT_BROWSER){
  const require=createRequire((process.env.BROWSER_MODULES||REPO+'/.cache/browser/node_modules')+'/_resolver.cjs');
  const {chromium:pw}=require('playwright'),mod=require('@sparticuz/chromium'),chromium=mod.default||mod,acorn=require('acorn');
  const browser=await pw.launch({executablePath:await chromium.executablePath(),args:chromium.args,headless:true});
  const cacheDir=REPO+'/.cache/print-copies';mkdirSync(cacheDir,{recursive:true});
  let browserChecks=0;
  try{
    const context=await browser.newContext({viewport:{width:1400,height:1000}});
    await context.addInitScript(()=>{window.print=()=>{window.printCalls=(window.printCalls||0)+1;};window.open=url=>{window.opened=url;};});
    const errors=[];const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
    await context.route('**/*',async route=>{
      const url=new URL(route.request().url()),file=decodeURIComponent(url.pathname).slice(1);
      if(files.includes(file)) return route.fulfill({contentType:'text/html',body:await render(file,url.search.slice(1))});
      if(file.endsWith('.php'))return route.fulfill({contentType:'application/json',body:'{}'});
      const path=resolveFile(file);
      if(!existsSync(path))return route.fulfill({status:404,body:''});
      const ext=file.split('.').pop(),type={js:'application/javascript',css:'text/css',woff2:'font/woff2',ttf:'font/ttf',png:'image/png',svg:'image/svg+xml'}[ext]||'application/octet-stream';
      return route.fulfill({contentType:type,body:readFileSync(path)});
    });
    for(const file of files){
      const tag=file===files[0],select=tag?'dCopies':'cCopies',reset=tag?'resetDesign':'resetCardDesign',single=tag?'printOne':'printOneCard',open=tag?'openPrintView':'openCardPrint';
      await page.goto('https://print.test/'+file+'?sort=nid&class=101');
      assert.equal(await page.inputValue('#'+select),'1');browserChecks++;
      for(const text of await page.locator('script:not([src])').allTextContents()) if(text.includes(tag?'function getDesign':'function getCardDesign'))acorn.parse(text,{ecmaVersion:5});
      for(const n of [1,2,3,10]){
        await page.selectOption('#'+select,String(n));
        await page.evaluate(fn=>window[fn](),open);
        let url=new URL(await page.evaluate(()=>window.opened),'https://print.test/');
        assert.equal(url.searchParams.get('copies'),String(n));assert.equal(url.searchParams.get('sort'),'nid');assert.equal(url.searchParams.get('class'),'101');
        await page.evaluate(fn=>window[fn](7002),single);
        url=new URL(await page.evaluate(()=>window.opened),'https://print.test/');
        assert.equal(url.searchParams.get('student_id'),'7002');assert.equal(url.searchParams.get('copies'),String(n));browserChecks++;
      }
      await page.reload();assert.equal(await page.inputValue('#'+select),'10');browserChecks++;
      await page.evaluate(fn=>window[fn](),reset);assert.equal(await page.inputValue('#'+select),'1');
      await page.reload();assert.equal(await page.inputValue('#'+select),'1');browserChecks++;
      // Find the real key, including legacy design objects that lack copies.
      const actualKey=await page.evaluate(tag=>tag?LS_KEY:CARD_LS,tag);
      await page.evaluate(key=>{const d=JSON.parse(localStorage.getItem(key));delete d.copies;localStorage.setItem(key,JSON.stringify(d));},actualKey);
      await page.reload();assert.equal(await page.inputValue('#'+select),'1');browserChecks++;
      await page.goto('https://print.test/'+file+'?copies=3');assert.equal(await page.inputValue('#'+select),'3');browserChecks++;
      await page.goto('https://print.test/'+file+'?copies[]=10');assert.equal(await page.inputValue('#'+select),'1');browserChecks++;
      const values=await page.evaluate(()=>[undefined,null,{},0,11,'1e1','۳','١٠',10].map(printCopies));
      assert.deepEqual(values,[1,1,1,1,1,1,3,10,10]);browserChecks++;
    }
    // Live preview is the actual first-page order, not a multiplied whole-school DOM.
    await page.goto('https://print.test/entry-cards.php?copies=3');
    for(const side of ['front','both']) for(const copies of [1,2,3,10]){
      await page.selectOption('#cSide',side);await page.selectOption('#cCopies',String(copies));
      const result=await page.evaluate(()=>({
        qr:[...document.querySelectorAll('#cardPreview canvas[data-qr]')].map(c=>c.dataset.qr),
        faces:[...document.querySelectorAll('#cardPreview > div')].map(c=>c.classList.contains('card-back')?'back':'front'),
        capacity:gridInfo(getCardDesign()).perPage,
        info:document.getElementById('pvNote').textContent,
        drawn:[...document.querySelectorAll('#cardPreview canvas')].every(c=>c.width>300&&c.getContext('2d').getImageData(0,0,1,1).data[3]===255)
      }));
      assert.deepEqual(result.qr,expand(baseline['entry-cards.php'],copies*(side==='both'?2:1)).slice(0,result.capacity));
      assert(result.drawn);assert(result.info.includes(fa(Math.ceil(3*copies*(side==='both'?2:1)/result.capacity))+' صفحه'));
      if(side==='both')assert.deepEqual(result.faces,Array(3*copies).fill(['front','back']).flat().slice(0,result.capacity));
      browserChecks++;
    }
    // Theme/layout changes on cloned backs and fronts must remain live.
    for(const layout of ['full','qrmax','sq']) for(const theme of ['classic','tile','sarv']){
      await page.selectOption('#cLayout',layout);await page.selectOption('#cTheme',theme);
      assert(await page.evaluate(()=>[...document.querySelectorAll('#cardPreview > div')].every(c=>c.classList.contains('sq')===(getCardDesign().layout==='sq'))));browserChecks++;
    }
    await page.locator('#pvPaper').screenshot({path:cacheDir+'/card-preview.png'});
    // Actual print script draws every repeated QR; pixel buffers decode to the unchanged token.
    const decoder=readFileSync(resolveFile('assets/js/jsqr.min.js'),'utf8');
    for(const file of files) for(const copies of [3,10]){
      await page.goto('https://print.test/'+file+'?print=1&copies='+copies+'&side=both&plate=0');
      await page.waitForFunction(()=>window.printCalls===1);
      await page.addScriptTag({content:decoder});
      const result=await page.evaluate(()=>[...document.querySelectorAll('canvas[data-qr]')].map(c=>{
        const data=c.getContext('2d').getImageData(0,0,c.width,c.height);
        const decoded=jsQR(data.data,data.width,data.height);
        return {qr:c.dataset.qr,decoded:decoded&&decoded.data,pixels:c.toDataURL()};
      }));
      assert.equal(result.length,(file===files[0]?3:6)*copies);
      const seen=new Map();for(const r of result){assert.equal(r.decoded,r.qr);if(seen.has(r.qr))assert.equal(r.pixels,seen.get(r.qr));seen.set(r.qr,r.pixels);}
      const pdf=await page.pdf({preferCSSPageSize:true,printBackground:true});writeFileSync(cacheDir+'/'+file+'-'+copies+'.pdf',pdf);browserChecks++;
    }
    // A large cohort must not allocate N copies of every source in the live preview.
    await run(`<?php require '/www/includes/functions.php';
      for($i=0;$i<200;$i++) DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year) VALUES (?,?,?,?,?,?,?,?)",[7100+$i,'800000'.str_pad($i,4,'0',STR_PAD_LEFT),'آزمایشی','نسخه '.$i,'103','دهم','active','1404/1405']);`);
    await page.goto('https://print.test/entry-cards.php?copies=10');
    await page.selectOption('#cSide','both');await page.selectOption('#cPaper','A3');await page.selectOption('#cLayout','sq');
    await page.evaluate(()=>{document.getElementById('cScale').value='60';document.getElementById('cGap').value='0';document.getElementById('cMargin').value='0';applyCardDesign();});
    const large=await page.evaluate(()=>({capacity:gridInfo(getCardDesign()).perPage,live:document.querySelectorAll('#cardPreview > div').length,sources:document.querySelectorAll('#cardPreviewSources .preview-source').length,cap:PREVIEW_CARDS,total:TOTAL_STUDENTS,note:document.getElementById('pvNote').textContent}));
    assert.equal(large.total,203);assert.equal(large.live,large.capacity);assert.equal(large.sources,large.cap);assert(large.live<large.total*10);assert(large.note.includes(fa(Math.ceil(203*20/large.capacity))+' صفحه'));browserChecks++;
    assert.deepEqual(errors,[]);console.log(`PASS ${browserChecks} Chromium cases; actual repeated QR decoded; PDFs saved to .cache/print-copies`);
  }finally{await browser.close();}
}
process.exit(0);
