// Optional print QA for generated DOCX files, using docx-preview + Chromium.
// This is NOT a Microsoft Word rendering test. B Titr substitutes for the unavailable
// 2 Titr font in this test only; production DOCX font families are not replaced.
// npm install --prefix .cache/browser playwright @sparticuz/chromium docx-preview
// Run test-school-list.mjs first; set BROWSER_MODULES to that node_modules directory.
import { createRequire } from 'node:module';
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { REPO, resolveFile } from './harness/site.mjs';
const require = createRequire(join(process.env.BROWSER_MODULES || join(REPO,'tests/node_modules'), '_resolver.cjs'));
const {chromium: playwright} = require('playwright');
const module = require('@sparticuz/chromium');
const chromium = module.default || module;
const font = readFileSync(process.env.BROWSER_FONT || resolveFile('uploads/B-Titr/B-Titr.ttf')).toString('base64');
const fixtures=[];
for(const paper of ['A4','A3']) for(const mode of ['split','combined']) for(const label of ['sample','full','overflow','rows30','rows60'])
    fixtures.push({name:`one-page-${paper}-${mode}-${label}`,paper});
for (const {name,paper} of fixtures) {
    const expectedPages=1;
    const outputName=name+(process.env.BROWSER_FONT?'-font-stress':'');
    const paperWidth=paper==='A3'?23814/20:16838/20;
    const paperHeight=paper==='A3'?16839/20:11906/20;
    // A fresh browser also avoids reusing docx-preview's font-loading state.
    const browser=await playwright.launch({executablePath:await chromium.executablePath(),args:[...chromium.args,'--disable-gpu'],headless:true});
    try {
        const page=await browser.newPage({viewport:{width:1700,height:1200}});
        await page.setContent('<div id="document"></div>');
        await page.addScriptTag({path:require.resolve('jszip/dist/jszip.min.js')});
        await page.addScriptTag({path:require.resolve('docx-preview')});
        const bytes=readFileSync(join(REPO,'.cache/roster-tests',name+'.docx')).toString('base64');
        await page.evaluate(async b=>{
            const bytes=Uint8Array.from(atob(b),c=>c.charCodeAt(0));
            await window.docx.renderAsync(bytes,document.getElementById('document'),null,{breakPages:true,ignoreLastRenderedPageBreak:true});
            // docx-preview 0.4 ignores pPr/rPr font sizes, leaving the paragraph at
            // the browser's 12pt default even when its runs are 2-7pt. Apply the
            // actual OOXML paragraph-mark size for the Word-style auto-line test.
            // This does not alter rows, margins, alignment, paper size or run fonts.
            const zip=await window.JSZip.loadAsync(bytes);
            const xml=new DOMParser().parseFromString(await zip.file('word/document.xml').async('string'),'application/xml');
            const ns='http://schemas.openxmlformats.org/wordprocessingml/2006/main';
            const source=Array.from(xml.getElementsByTagNameNS(ns,'p'));
            const paragraphs=Array.from(document.querySelectorAll('section.docx p'));
            if(source.length!==paragraphs.length)throw new Error('Paragraph mapping differs from OOXML');
            source.forEach((p,i)=>{
                const pr=Array.from(p.children).find(e=>e.localName==='pPr');
                const mark=pr&&Array.from(pr.children).find(e=>e.localName==='rPr');
                const size=mark&&(Array.from(mark.children).find(e=>e.localName==='szCs')||Array.from(mark.children).find(e=>e.localName==='sz'));
                if(size)paragraphs[i].style.fontSize=(Number(size.getAttributeNS(ns,'val'))/2)+'pt';
            });
        },bytes);
        await page.addStyleTag({content:`
            @font-face{font-family:'ReferenceTitr';src:url(data:font/ttf;base64,${font})}
            .docx p,.docx span,.docx td{font-family:'ReferenceTitr'!important}
            @page{size:${paper} landscape;margin:0}
            html,body{margin:0;padding:0}
            .docx-wrapper{padding:0!important;background:white!important}
            section.docx{margin:0!important;box-shadow:none!important}
        `});
        await page.evaluate(()=>document.fonts.ready);
        if(!await page.evaluate(()=>Array.from(document.fonts).some(f=>f.family==='ReferenceTitr'&&f.status==='loaded')))throw new Error('Reference font failed to load');
        const geometry=await page.evaluate(()=>Array.from(document.querySelectorAll('section.docx table')).map(table=>{
            const p=table.closest('section.docx').getBoundingClientRect();
            const t=table.getBoundingClientRect();
            return {layout:getComputedStyle(table).tableLayout,pageWidth:p.width,tableWidth:t.width,tableHeight:t.height,left:t.left-p.left,right:t.right-p.left,bottom:t.bottom-p.top};
        }));
        if(geometry.length!==expectedPages || geometry.some(g=>g.layout!=='auto' || Math.abs(g.pageWidth-paperWidth*4/3)>1 || g.left<0 || g.right>g.pageWidth || g.bottom>paperHeight*4/3)) {
            throw new Error(name+': table overflow or wrong paper width: '+JSON.stringify(geometry));
        }
        if(!await page.evaluate(()=>Array.from(document.querySelectorAll('section.docx p')).every(p=>p.style.lineHeight==='1')))throw new Error(name+': a paragraph is not Single');
        const alignment=await page.evaluate(()=>{
            let count=0,maxOffset=0,maxTextOffset=0,wrongAlign=0;
            for(const td of document.querySelectorAll('section.docx td')) {
                const p=td.querySelector('p');if(!p || !p.textContent.trim())continue;
                if(getComputedStyle(td).writingMode!=='horizontal-tb' || getComputedStyle(p).writingMode!=='horizontal-tb')continue;
                const cell=td.getBoundingClientRect(),line=p.getBoundingClientRect();
                maxOffset=Math.max(maxOffset,Math.abs((line.top+line.bottom-cell.top-cell.bottom)/2));
                const range=document.createRange();range.selectNodeContents(p);const text=range.getBoundingClientRect();
                maxTextOffset=Math.max(maxTextOffset,Math.abs((text.top+text.bottom-cell.top-cell.bottom)/2));
                if(getComputedStyle(td).verticalAlign!=='middle')wrongAlign++;
                count++;
            }
            return {count,maxOffset,maxTextOffset,wrongAlign};
        });
        if(!alignment.count || alignment.wrongAlign || alignment.maxOffset>1.5 || alignment.maxTextOffset>1.5)
            throw new Error(name+': text line is not vertically centered: '+JSON.stringify(alignment));
        const pdf=await page.pdf({preferCSSPageSize:true,printBackground:true});
        const text=pdf.toString('latin1');
        const pages=(text.match(/\/Type\s*\/Page\b/g)||[]).length;
        if(pages!==expectedPages)throw new Error(`${name}: expected ${expectedPages} pages, got ${pages}`);
        const boxes=[...text.matchAll(/\/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)\s*\]/g)];
        if(!boxes.length || boxes.some(b=>Math.abs(+b[1]-paperWidth)>1 || Math.abs(+b[2]-paperHeight)>1))throw new Error(name+': PDF paper is not '+paper+' landscape');
        writeFileSync(join(REPO,'.cache/roster-tests',outputName+'.pdf'),pdf);
        await page.screenshot({path:join(REPO,'.cache/roster-tests',outputName+'.png')});
        console.log(`PASS ${name}: ${pages} ${paper} landscape page(s), vertically centered text, no extra pages or horizontal table overflow`);
    } finally {await browser.close();}
}
