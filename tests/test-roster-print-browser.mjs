// Optional visual print smoke test. npm install playwright @sparticuz/chromium
// Run test-school-list.mjs first to produce .cache/roster-tests/teacher-print.html.
// BROWSER_MODULES can point at a separate, untracked node_modules directory.
import { createRequire } from 'node:module';
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { REPO, resolveFile } from './harness/site.mjs';
const require = createRequire(join(process.env.BROWSER_MODULES || join(REPO, 'tests/node_modules'), '_resolver.cjs'));
const { chromium: playwright } = require('playwright');
const chromiumModule = require('@sparticuz/chromium');
const chromium = chromiumModule.default || chromiumModule;
const browser = await playwright.launch({executablePath:await chromium.executablePath(),args:chromium.args,headless:true});
try {
    const page = await browser.newPage({viewport:{width:1000,height:1200}});
    await page.route('https://roster.test/**', async route => {
        const path = new URL(route.request().url()).pathname;
        if (path.endsWith('.ttf')) return route.fulfill({body:readFileSync(resolveFile('uploads/B-Titr/B-Titr.ttf')),contentType:'font/ttf'});
        return route.fulfill({body:readFileSync(join(REPO,'.cache/roster-tests/teacher-print.html')),contentType:'text/html;charset=utf-8'});
    });
    await page.goto('https://roster.test/teacher-print.html');
    await page.evaluate(()=>document.fonts.ready);
    await page.emulateMedia({media:'print'});
    const metrics = await page.evaluate(()=>{
        const el=document.querySelector('.class-code'), node=el.firstChild;
        const xs=[];
        for(let i=0;i<node.length;i++){const r=new Range();r.setStart(node,i);r.setEnd(node,i+1);xs.push(r.getBoundingClientRect().x);}
        return {code:el.textContent,xs,height:document.querySelector('.title-row').getBoundingClientRect().height,
            sheets:document.querySelectorAll('.sheet').length,font:document.fonts.check('12px BTitr')};
    });
    if(metrics.code !== '1/7' || !(metrics.xs[0]<metrics.xs[1] && metrics.xs[1]<metrics.xs[2])) throw new Error('Class number visual direction regressed: '+JSON.stringify(metrics));
    if(metrics.height < 30 || metrics.sheets !== 2 || !metrics.font) throw new Error('Print dimensions/font regressed: '+JSON.stringify(metrics));
    const pdf=await page.pdf({preferCSSPageSize:true,printBackground:true});
    writeFileSync(join(REPO,'.cache/roster-tests/teacher-print.pdf'),pdf);
    const pages=(pdf.toString('latin1').match(/\/Type\s*\/Page\b/g)||[]).length;
    if(pages!==2)throw new Error('Expected 2 PDF pages; got '+pages);
    await page.screenshot({path:join(REPO,'.cache/roster-tests/teacher-print.png')});
    console.log('Browser print: PASS (LTR 1/7, 8mm title, B Titr loaded, exactly two A4 PDF pages)',metrics);
} finally {await browser.close();}
