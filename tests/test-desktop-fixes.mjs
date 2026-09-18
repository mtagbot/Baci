// Desktop/site corrective checks: header date label, Persian-font fallback
// chain, no-new-window shim (desktop only), restored import-photos tool.
// Run: SITE=<staged-build> node tests/test-desktop-fixes.mjs
import {php,run,req,loginAdmin,login,db} from './harness/lib.mjs';
import nodeVm from 'node:vm';

let checks=0;
const check=(v,m)=>{if(!v){console.error('FAIL:',m);process.exit(1)}checks++;console.log('  · '+m)};
const DESK=(process.env.SITE||'').includes('desktop');
async function code(c){
  const r=await run(`<?php error_reporting(E_ALL & ~E_DEPRECATED); require_once '/www/includes/functions.php'; require_once '/www/includes/db.php'; ${c}`);
  if(r.err)throw new Error(r.err);
  return r.out.trim();
}

await loginAdmin('fixAdm');

/* ── 1) Header date: no «مورخ:» label — weekday + date only ─────────── */
const home=await req('header date',{file:'index.php',sid:'fixAdm'});
const dm=home.res.page.match(/hdr-date">([^<]+)</);
check(!!dm,'header date present');
check(dm&&!dm[1].includes('مورخ'),'header shows weekday+date without the «مورخ:» label');
check(dm&&/جمعه|شنبه|یکشنبه|دوشنبه|سهشنبه|چهارشنبه|پنجشنبه/.test(dm[1]),'header date is a Persian weekday');

/* ── 2) Font fallback: bundled Persian fonts before Tahoma ──────────── */
check(home.res.page.includes("font-family: 'Vazirmatn', Vazirmatn, Sahel, Yekan, Tahoma, sans-serif !important"),
      'default body font chain falls back to bundled Persian fonts');
await code(`set_setting('font_family','CustomUploadedFont'); set_setting('custom_font_url','uploads/fonts/does-not-exist.ttf');`);
const custom=await req('custom font',{file:'index.php',sid:'fixAdm'});
check(custom.res.page.includes("font-family: 'CustomUploadedFont', Vazirmatn, Sahel, Yekan, Tahoma, sans-serif !important"),
      'unresolvable custom font falls back to Vazirmatn (Persian digits) instead of Tahoma');
check(custom.res.page.includes("h1, h2, h3, h4, h5, h6 { font-family: 'Vazirmatn', Vazirmatn"),
      'heading font chain also keeps the Persian fallback');
await code(`DB::execute("DELETE FROM settings WHERE key_name IN ('font_family','custom_font_url')");`);

/* ── 3) No new windows in the desktop app ───────────────────────────── */
const shimSig='window.open = function (u) { go(u); return null; }';
check(DESK?home.res.page.includes(shimSig):!home.res.page.includes(shimSig),
      DESK?'desktop build ships the no-new-window shim':'site build keeps native new windows (no shim)');
if (DESK) {
  const m=home.res.page.match(/<script>\s*\(function\(\)\{\s*function go\(url\)[\s\S]*?\}\)\(\);\s*<\/script>/);
  check(!!m,'shim script present and extractable');
  const shimCode=m[0].replace(/<\/?script>/g,'');
  const assigned=[];const listeners={};
  const fakeWindow={location:{assign:(u)=>assigned.push(u)}};
  const ctx=nodeVm.createContext({window:fakeWindow,document:{addEventListener:(t,f)=>{listeners[t]=f}},console});
  nodeVm.runInContext(shimCode,ctx);
  const r1=ctx.window.open('students.php?x=1');
  check(r1===null&&assigned.length===1&&assigned[0]==='students.php?x=1','window.open(...) re-routes to the app window');
  ctx.window.open('about:blank');
  check(assigned.length===1,'window.open about:blank ignored');
  const click=(a,meta=false)=>{const ev={defaultPrevented:false,metaKey:meta,ctrlKey:false,shiftKey:false,button:0,target:{closest:()=>a},preventDefault:()=>{ev.defaultPrevented=true}};listeners['click'](ev);return ev;};
  const ev1=click({getAttribute:()=>'exam-print.php?type=questions&exam_id=5'});
  check(ev1.defaultPrevented&&assigned.at(-1)==='exam-print.php?type=questions&exam_id=5','live-design link (target=_blank) navigates in place');
  const ev2=click(null);
  check(!ev2.defaultPrevented&&assigned.length===2,'normal links untouched');
  const ev3=click({getAttribute:()=>'https://example.test'},true);
  check(!ev3.defaultPrevented&&assigned.length===2,'meta-click stays a native user gesture');
}

/* ── 4) Restored import-photos tool (recovery hub → ZIP tab) ────────── */
const hub=await req('recovery hub',{file:'student-recovery.php',query:'hub_tab=photos',sid:'fixAdm'});
check(hub.res.page.includes('آپلود تصاویر ZIP'),'recovery hub lists the ZIP tab');
check(hub.res.page.includes('import-photos.php?embedded=1'),'tab points at the restored tool');
const page1=await req('photos tool',{file:'import-photos.php',query:'embedded=1',sid:'fixAdm'});
check(page1.res.page.includes('آپلود دسته‌جمعی تصاویر')&&page1.res.page.includes('photos_zip'),'tool renders its upload form');
check(page1.res.page.includes('hub-embedded'),'embedded mode hides the app chrome');
check(!page1.res.page.includes('در نسخهٔ مبنا موجود نیست'),'missing-module stub no longer reached');
check(!page1.res.page.includes('🖼️')&&!page1.res.page.includes('📅'),'no emoji in the restored tool');

const nid=(await db('SELECT national_id FROM students WHERE id=1'))[0].national_id;
const JPEG='/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AVN//2Q==';
await code(`$z=new ZipArchive();$z->open('/tmp/h-photos.zip',ZipArchive::CREATE);
            $z->addFromString('photos/${nid}.jpg',base64_decode('${JPEG}'));
            $z->addFromString('photos/bad name.jpg','x');
            $z->close();`);
const tok=(page1.res.page.match(/name="csrf_token" value="([^"]+)"/)||[])[1];
check(!!tok,'csrf token present in the upload form');
const up=await req('zip upload',{file:'import-photos.php',query:'embedded=1',sid:'fixAdm',method:'POST',
  post:{upload_zip:'1',csrf_token:tok,academic_year:''},
  files:{photos_zip:{name:'photos.zip',type:'application/zip',tmp_name:'/tmp/h-photos.zip',error:0,size:2048}}});
check(up.res.redirect&&up.res.redirect.startsWith('import-photos.php?step=2'),'successful upload redirects to the report step');
check((up.res.redirect||'').includes('embedded=1'),'embedded=1 preserved across the redirect');
check(await code(`echo file_exists('/www/uploads/photos/${nid}.jpg') ? 'y':'n';`)==='y','photo written to uploads/photos/<nid>.jpg');
check((await db('SELECT photo_url FROM students WHERE id=1'))[0].photo_url==='uploads/photos/'+nid+'.jpg','student photo_url updated');
const rep=await req('report step',{file:'import-photos.php',query:'step=2&embedded=1',sid:'fixAdm'});
check(rep.res.page.includes('تخصیص موفق'),'report lists the matched photo');
check(rep.res.page.includes('نامعتبر'),'report lists the invalid-name entry as skipped');
check((await db("SELECT COUNT(*) c FROM desk_change_log WHERE tbl='students'"))[0].c>0,'photo_url update registered in the sync change log (will push to the site)');

await login('fixStu');
const denied=await req('permission denied',{file:'import-photos.php',sid:'fixStu'});
check(!denied.res.page.includes('photos_zip'),'student role cannot open the tool');

console.log(`PASS ${checks} desktop-fixes checks (${DESK?'desktop':'site'} staged build)`);
process.exit(0);
