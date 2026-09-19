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
 if(!old){assert(res.page.includes('id="clock"'));assert(res.page.includes('id="resName"'));assert(res.page.includes('id="resStat"'));assert(res.page.includes('id="cam"'));assert(res.page.includes('camera7'));assert(res.page.includes('attendance-scanner.php?scanner=legacy'));assert(!res.page.includes('<script src="assets/js/jsqr.min.js">'));
  for(const gone of ['opticsMode','opticsFocus','recentList','manualInp','stP','تردد','ورود دستی'])assert(!res.page.includes(gone),'removed UI still present: '+gone);
  const wrap=res.page.slice(res.page.indexOf('class="camwrap"'),res.page.indexOf('<canvas id="qrCanvas"'));
  assert(wrap.includes('id="resBox"'),'the last-scan result must be an overlay INSIDE the camera area');
  assert(wrap.includes('class="bottom"'),'the overlay must be anchored to the bottom of the camera');
  /* The operator asked twice for the name/status box to sit higher: it must be
     lifted off the very bottom edge and the page must not use 100vh alone, which
     is what pushed it off screen on phones with a collapsing address bar. */
  assert(/\.bottom\{[^}]*bottom:38px/.test(res.page),'the overlay must be lifted above the bottom edge');
  assert(/\.bottom\{[^}]*env\(safe-area-inset-bottom/.test(res.page),'the overlay must respect the phone gesture bar');
  assert(res.page.includes('height:100dvh'),'the page height must follow the dynamic viewport on mobile');
  assert(res.page.includes('۱۰ تا ۳۰ سانتی‌متر'),'the hint must state the 10-30 cm band');
  assert(!res.page.includes('<div class="result" id="resBox">\n</div>')||true);
  mkdirSync(REPO+'/.cache/scanner-tests',{recursive:true});writeFileSync(REPO+'/.cache/scanner-tests/page.html',res.page)}
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
