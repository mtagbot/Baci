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
const font = readFileSync(resolveFile('uploads/B-Titr/B-Titr.ttf')).toString('base64');
const fixtures = [
    ['school-sample',1], ['school-combined',1],
    ['school-overflow',3], ['school-combined-overflow',3],
    ['school-full-split',1], ['school-full-combined',1],
];
for (const [name, expectedPages] of fixtures) {
    // A fresh browser also avoids reusing docx-preview's font-loading state.
    const browser=await playwright.launch({executablePath:await chromium.executablePath(),args:chromium.args,headless:true});
    try {
        const page=await browser.newPage({viewport:{width:1250,height:1100}});
        await page.setContent('<div id="document"></div>');
        await page.addScriptTag({path:require.resolve('jszip/dist/jszip.min.js')});
        await page.addScriptTag({path:require.resolve('docx-preview')});
        const bytes=readFileSync(join(REPO,'.cache/roster-tests',name+'.docx')).toString('base64');
        await page.evaluate(async b=>{
            const bytes=Uint8Array.from(atob(b),c=>c.charCodeAt(0));
            await window.docx.renderAsync(bytes,document.getElementById('document'),null,{breakPages:true,ignoreLastRenderedPageBreak:true});
        },bytes);
        await page.addStyleTag({content:`
            @font-face{font-family:'ReferenceTitr';src:url(data:font/ttf;base64,${font})}
            .docx p,.docx span,.docx td{font-family:'ReferenceTitr'!important}
            @page{size:A4 landscape;margin:0}
            html,body{margin:0;padding:0}
            .docx-wrapper{padding:0!important;background:white!important}
            section.docx{margin:0!important;box-shadow:none!important}
        `});
        await page.evaluate(()=>document.fonts.ready);
        const geometry=await page.evaluate(()=>Array.from(document.querySelectorAll('section.docx table')).map(table=>{
            const p=table.closest('section.docx').getBoundingClientRect();
            const t=table.getBoundingClientRect();
            return {pageWidth:p.width,tableWidth:t.width,tableHeight:t.height,left:t.left-p.left,right:t.right-p.left};
        }));
        if(geometry.length!==expectedPages || geometry.some(g=>Math.abs(g.pageWidth-1122.53)>1 || g.left<18 || g.right>g.pageWidth-18 || g.tableHeight>700)) {
            throw new Error(name+': table overflow or wrong paper width: '+JSON.stringify(geometry));
        }
        const pdf=await page.pdf({preferCSSPageSize:true,printBackground:true});
        const text=pdf.toString('latin1');
        const pages=(text.match(/\/Type\s*\/Page\b/g)||[]).length;
        if(pages!==expectedPages)throw new Error(`${name}: expected ${expectedPages} pages, got ${pages}`);
        const boxes=[...text.matchAll(/\/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)\s*\]/g)];
        if(!boxes.length || boxes.some(b=>Math.abs(+b[1]-841.9)>1 || Math.abs(+b[2]-595.3)>1))throw new Error(name+': PDF paper is not A4 landscape');
        writeFileSync(join(REPO,'.cache/roster-tests',name+'-a4.pdf'),pdf);
        await page.screenshot({path:join(REPO,'.cache/roster-tests',name+'-a4.png')});
        console.log(`PASS ${name}: ${pages} A4 landscape page(s), no extra pages or horizontal table overflow`);
    } finally {await browser.close();}
}
