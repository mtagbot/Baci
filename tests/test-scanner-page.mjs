import assert from 'node:assert/strict';
import {mkdirSync,writeFileSync} from 'node:fs';
import {run,req,loginAdmin} from './harness/lib.mjs';
import {REPO} from './harness/site.mjs';
const sid='scannerAdmin'; await loginAdmin(sid);
let count=0;
for(const [name,query,old] of [['new','',false],['rollback','scanner=legacy',true],['direct','',true]]) {
 const file=name==='direct'?'attendance-scanner-legacy.php':'attendance-scanner.php';
 const {res,stderr}=await req('scanner '+name,{file,query,sid});
 assert(!res.fatal,res.fatal);assert(!stderr,stderr);assert(res.page.includes('id="cam"'));
 assert.equal(res.page.includes('attendance-scanner-light.js'),!old);
 assert.equal(res.page.includes('function startCam(deviceId)'),old);count++;
 if(!old){assert(res.page.includes('attendance-scanner.php?scanner=legacy'));assert(!res.page.includes('<script src="assets/js/jsqr.min.js">'));mkdirSync(REPO+'/.cache/scanner-tests',{recursive:true});writeFileSync(REPO+'/.cache/scanner-tests/page.html',res.page)}
}
await run(`<?php require '/www/includes/functions.php'; set_setting('attendance_scanner_key','DEMO-only-safe-key');`);
for(const query of ['key=DEMO-only-safe-key','key=DEMO-only-safe-key&scanner=legacy']) {
 const {res}=await req('scanner key auth',{file:'attendance-scanner.php',sid:'scannerKeyOnly',query});assert(res.page.includes('id="cam"'));assert(!res.fatal,res.fatal);if(!query.includes('legacy'))assert(res.page.includes('scanner=legacy&amp;key=DEMO-only-safe-key'));count++;
}
for(const file of ['attendance-scanner.php','attendance-scanner-legacy.php'])for(const query of ['','key=bad','scanner=legacy&key=bad']) {
 const {res}=await req('scanner unauthorized',{file,sid:'scannerUnauthorized',query});assert(!res.page.includes('id="cam"'));assert(res.page.includes('دسترسی به اسکنر مجاز نیست'));count++;
}
console.log(`PASS ${count} scanner page/auth/rollback cases (real PHP, isolated fixture DB)`);
process.exit(0);
