// Compare actual card rectangles in live preview/print, then inspect PHYSICAL PDF pages.
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFileSync,existsSync,writeFileSync,mkdirSync} from 'node:fs';
import {run,req,loginAdmin} from './harness/lib.mjs';
import {REPO,resolveFile} from './harness/site.mjs';
const sid='physicalCards';await loginAdmin(sid);
await run(`<?php require '/www/includes/functions.php';set_setting('current_academic_year','1404/1405');DB::execute("UPDATE students SET status='inactive'");
foreach(['سید محمد طاها','علی','بهار'] as $i=>$name)DB::execute('INSERT INTO students(id,national_id,first_name,last_name,class_name,grade_level,status,academic_year) VALUES (?,?,?,?,?,?,?,?)',[8200+$i,'Card'.($i+8200),$name,'آزمایشی','101','دهم','active','1404/1405']);`);
async function render(query=''){
  const r=await req('',{file:'entry-cards.php',sid,query});assert(!r.stderr,r.stderr);assert(!r.res.fatal,r.res.fatal);assert(!r.res.php_issues,r.res.php_issues);return r.res.page;
}
const css=readFileSync(resolveFile('includes/card_sheet_layout.php'),'utf8');
assert(css.includes('position:absolute'));assert(css.includes('margin:0'));assert(css.includes('scale(var(--card-scale,1))'));
for(const scale of [.6,1,1.6]){
  const html=await render('print=1&paper=A4&copies=10&side=both&scale='+scale);
  assert.equal((html.match(/data-qr="/g)||[]).length,60);assert(html.includes('height:297mm'));assert(!html.includes('min-height:297mm'));
}
console.log('PASS shared physical layout and 3 server scale cases');
if(process.env.PRINT_BROWSER){
  const require=createRequire((process.env.BROWSER_MODULES||REPO+'/.cache/browser/node_modules')+'/_resolver.cjs');
  const {chromium:pw}=require('playwright'),mod=require('@sparticuz/chromium'),chromium=mod.default||mod,{PDFDocument}=require('pdf-lib');
  const browser=await pw.launch({executablePath:await chromium.executablePath(),args:chromium.args,headless:true});
  const dir=REPO+'/.cache/security/physical';mkdirSync(dir,{recursive:true});
  try{
    const context=await browser.newContext({viewport:{width:1400,height:1000}}),errors=[];
    await context.addInitScript(()=>{window.print=()=>{window.printCalls=(window.printCalls||0)+1;};window.open=url=>window.opened=url;});
    await context.route('**/*',async route=>{
      const url=new URL(route.request().url()),file=decodeURIComponent(url.pathname).slice(1);
      if(file==='entry-cards.php')return route.fulfill({contentType:'text/html',body:await render(url.search.slice(1))});
      if(file.endsWith('.php'))return route.fulfill({contentType:'application/json',body:'{"authenticated":true}'});
      const path=resolveFile(file);if(!existsSync(path))return route.fulfill({status:404,body:''});
      const type={js:'application/javascript',css:'text/css',woff2:'font/woff2',ttf:'font/ttf',svg:'image/svg+xml',png:'image/png'}[file.split('.').pop()]||'application/octet-stream';
      return route.fulfill({contentType:type,body:readFileSync(path)});
    });
    const page=await context.newPage(),printed=await context.newPage();page.on('pageerror',e=>errors.push(e.message));printed.on('pageerror',e=>errors.push(e.message));
    await page.goto('https://cards.test/entry-cards.php');await page.evaluate(()=>document.fonts.ready);
    const cases=[];
    for(const orient of ['portrait','landscape'])for(const layout of ['full','qrmax','sq'])for(const scale of [.6,1,1.6]){
      const i=cases.length;cases.push({paper:'A4',orient,layout,scale,margin:i%2?8:0,gap:i%3?4:0,side:i%2?'both':'front',copies:i%3?3:10,theme:['classic','tile','sarv'][i%3],cut:true,photo:true,nid:true,year:true});
    }
    for(const paper of ['A5','A3','A2'])cases.push({...cases[0],paper,layout:'sq',side:'both',copies:10});
    function rectangles(selector,rootSelector){
      const root=document.querySelector(rootSelector),r=root.getBoundingClientRect(),scale=r.width/root.offsetWidth;
      return [...document.querySelectorAll(selector)].map(c=>{const b=c.getBoundingClientRect(),s=getComputedStyle(c);return {x:(b.x-r.x)/scale,y:(b.y-r.y)/scale,w:b.width/scale,h:b.height/scale,qr:c.querySelector('[data-qr]').dataset.qr,face:c.classList.contains('card-back')?'back':'front',font:s.fontFamily};});
    }
    for(const [i,d] of cases.entries()){
      await page.evaluate(d=>{setCardDesign(d);applyCardDesign();openCardPrint();},d);
      const preview=await page.evaluate(({fn})=>eval('('+fn+')')('#cardPreview > div','#pvPaper'),{fn:rectangles.toString()});
      const info=await page.evaluate(()=>({grid:gridInfo(getCardDesign()),url:window.opened}));
      await printed.goto(new URL(info.url,'https://cards.test/').href);await printed.waitForFunction(()=>window.printCalls===1);await printed.evaluate(()=>document.fonts.ready);await printed.emulateMedia({media:'print'});
      const actual=await printed.evaluate(({fn})=>{document.querySelector('.page').id='firstPhysical';return eval('('+fn+')')('#firstPhysical .sheet > div','#firstPhysical');},{fn:rectangles.toString()});
      assert.equal(actual.length,preview.length,JSON.stringify(d));
      for(let c=0;c<preview.length;c++){
        assert.equal(actual[c].qr,preview[c].qr);assert.equal(actual[c].face,preview[c].face);assert.equal(actual[c].font,preview[c].font);
        for(const key of ['x','y','w','h'])assert(Math.abs(actual[c][key]-preview[c][key])<.6,`case ${i} card ${c} ${key}: ${actual[c][key]} vs ${preview[c][key]}`);
      }
      const fit=await printed.evaluate(()=>[...document.querySelectorAll('.page')].every(p=>{
        const b=p.getBoundingClientRect();return [...p.querySelectorAll('.sheet > div')].every(c=>{const r=c.getBoundingClientRect();return r.x>=b.x-.1&&r.y>=b.y-.1&&r.right<=b.right+.1&&r.bottom<=b.bottom+.1;});
      }));assert(fit,'card outside page '+i);
      const expected=Math.ceil(3*d.copies*(d.side==='both'?2:1)/info.grid.perPage);
      assert.equal(await printed.locator('.page').count(),expected);
      const pdf=await printed.pdf({preferCSSPageSize:true,printBackground:true,displayHeaderFooter:false}),doc=await PDFDocument.load(pdf);
      assert.equal(doc.getPageCount(),expected,`PHYSICAL pages case ${i} ${JSON.stringify(d)}`);
      for(const p of doc.getPages()){assert(Math.abs(p.getWidth()-info.grid.pw*72/25.4)<1);assert(Math.abs(p.getHeight()-info.grid.ph*72/25.4)<1);}
      writeFileSync(dir+`/cards-${i}.pdf`,pdf);
      if(i===0){await page.locator('#pvPaper').screenshot({path:dir+'/preview.png'});await printed.locator('.page').first().screenshot({path:dir+'/print.png'});}
    }
    await page.keyboard.press('Control+p');assert((await page.evaluate(()=>window.opened)).includes('print=1'));
    assert.deepEqual(errors,[]);console.log(`PASS ${cases.length} real Chromium preview/print/PDF cases: matching order, faces, millimetres, dimensions and physical page counts`);
  }finally{await browser.close();}
}
process.exit(0);
