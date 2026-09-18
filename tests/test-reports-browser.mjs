/* Application physically relocated in WASM. Browser transport is intercepted, not native PHP/Windows. */
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFileSync,writeFileSync,mkdirSync} from 'node:fs';
import {join} from 'node:path';
import {php,loginAdmin} from './harness/lib.mjs';
import {REPO} from './harness/site.mjs';
const setting=await php.run({code:`<?php require '/www/includes/functions.php';set_setting('logo_url','/reports/assets/logo/logo.png');echo 'OK';`});assert(setting.text.includes('OK'),setting.errors+setting.text);await loginAdmin('reportsAdm');
php.mkdirTree('/SchoolDeskPro');php.mv('/www','/SchoolDeskPro/www');php.mv('/data','/SchoolDeskPro/data');
// Production installer uses a parent-relative SQLite path; its bytes survive the actual rename.
const config="<?php return ['driver'=>'sqlite','database'=>dirname(__DIR__,2).'/data/school.sqlite'];";
php.writeFile('/SchoolDeskPro/www/config/database.php',config);
const dbBefore=php.readFileAsBuffer('/SchoolDeskPro/data/school.sqlite');
php.mv('/SchoolDeskPro/www','/SchoolDeskPro/reports');
assert.equal(php.readFileAsText('/SchoolDeskPro/reports/config/database.php'),config);
assert.deepEqual(php.readFileAsBuffer('/SchoolDeskPro/data/school.sqlite'),dbBefore);
const root='/SchoolDeskPro',web=root+'/reports';php.mkdirTree(web+'/assets/logo');
// Existing approved executable icon supplies a fixture image, not a replacement customer logo.
const ico=readFileSync(join(REPO,'desktop-app-v2/launcher/res/app.ico'));let png;
for(let i=0;i<ico.readUInt16LE(4);i++){const n=ico.readUInt32LE(14+i*16),off=ico.readUInt32LE(18+i*16);if(ico.subarray(off,off+8).equals(Buffer.from([137,80,78,71,13,10,26,10]))){png=ico.subarray(off,off+n);break;}}
assert(png);php.writeFile(web+'/assets/logo/logo.png',png);php.writeFile(web+'/router.php',readFileSync(join(REPO,'desktop-app-v2/patch/reports-router.php')));
// Parent DOCUMENT_ROOT resolves the shared absolute URL locally in TCPDF (no self-HTTP).
const pdf=await php.run({code:`<?php $_SERVER['DOCUMENT_ROOT']='${root}';$_SERVER['HTTP_HOST']='reports.test';chdir('${web}');require '${web}/vendor/tcpdf/tcpdf.php';$pdf=new TCPDF();$pdf->setPrintHeader(false);$pdf->setPrintFooter(false);$pdf->AddPage();$pdf->writeHTML('<img src="/reports/assets/logo/logo.png" width="24" height="24">');$out=$pdf->Output('test.pdf','S');echo json_encode(['pdf'=>substr($out,0,5)==='%PDF-','image'=>strpos($out,'/Subtype /Image')!==false]);`});
assert.deepEqual(JSON.parse(pdf.text),{pdf:true,image:true},pdf.errors);
const require=createRequire(join(REPO,'.cache/browser/package.json')),{chromium:pw}=require('playwright'),chromium=(await import(require.resolve('@sparticuz/chromium'))).default;
const browser=await pw.launch({executablePath:await chromium.executablePath(),args:chromium.args.filter(a=>!['--single-process','--disable-web-security'].includes(a)),headless:true});
let checks=3,role='guest',queue=Promise.resolve();const results=[],errors=[];
const check=(v,m)=>{assert(v,m);checks++;};
const mime={css:'text/css',js:'text/javascript',png:'image/png',svg:'image/svg+xml',woff2:'font/woff2',ttf:'font/ttf'};
try{
 const context=await browser.newContext({viewport:{width:1280,height:900}});
 await context.route('**/*',route=>{const task=queue.then(async()=>{
  const u=new URL(route.request().url());if(u.hostname!=='reports.test')return route.abort();const path=u.pathname+u.search;
  const r=await php.run({relativeUri:path,method:route.request().method(),body:new Uint8Array(route.request().postDataBuffer()||[]),headers:route.request().headers(),code:`<?php ini_set('display_errors','0');ini_set('session.save_path','/tmp/sess');session_name('BACI_TEST');session_id('${role==='admin'?'reportsAdm':'reportsGuest'}');$_SERVER['REQUEST_URI']=${JSON.stringify(path)};$_SERVER['DOCUMENT_ROOT']='${root}';$_SERVER['HTTP_HOST']='reports.test';$_SERVER['SERVER_NAME']='reports.test';$r=require '${web}/router.php';if($r===false){$f='${root}'.rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));if(is_dir($f))$f.='/index.php';$_SERVER['SCRIPT_FILENAME']=$f;$_SERVER['SCRIPT_NAME']=substr($f,strlen('${root}'));$_SERVER['PHP_SELF']=$_SERVER['SCRIPT_NAME'];chdir(dirname($f));if(strtolower(pathinfo($f,PATHINFO_EXTENSION))==='php')require $f;else readfile($f);}`});
  if(r.errors.trim())errors.push({path,error:r.errors});
  const headers=Object.fromEntries(Object.entries(r.headers).map(([k,v])=>[k,v.join(', ')]));delete headers['content-length'];headers['content-type']=mime[u.pathname.split('.').pop()]||headers['content-type']||'text/html;charset=utf-8';
  await route.fulfill({status:r.httpStatusCode,headers,body:Buffer.from(r.bytes)});
 });queue=task.catch(()=>{});return task;});
 const page=await context.newPage();page.on('pageerror',e=>errors.push({browser:e.message}));
 for(role of ['guest','admin']){
  await page.goto('http://reports.test/reports/');await page.waitForLoadState('networkidle');
  check(new URL(page.url()).pathname==='/reports/','canonical reports URL');
  const logo=page.locator('img[src="/reports/assets/logo/logo.png"]').first();check(await logo.count()===1,role+' shared logo HTML');check(await logo.evaluate(i=>i.complete&&i.naturalWidth>0),role+' shared logo loaded');
  const files=await page.evaluate(()=>[...document.querySelectorAll('script[src],link[rel="stylesheet"]')].map(x=>x.src||x.href));check(files.every(x=>new URL(x).pathname.startsWith('/reports/')),'relative assets stay under reports');
  results.push({role,url:page.url(),logo:await logo.evaluate(i=>({src:i.src,width:i.naturalWidth,height:i.naturalHeight})),assets:files.length});
 }
 check(!errors.length,JSON.stringify(errors));mkdirSync(join(REPO,'docs/reports-layout'),{recursive:true});writeFileSync(join(REPO,'docs/reports-layout/browser.json'),JSON.stringify({runtime:'PHP 8.3 WASM, Playwright intercepted transport, actual renamed app; no native cli-server/Windows claim',checks,results,errors},null,2));console.log('PASS',checks,'browser checks');await context.close();
}finally{await browser.close();php.exit();}
process.exit(0);
