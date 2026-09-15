// Real canvas/video + bundled jsQR + actual Worker/XHR in Chromium.
// Synthetic QR fixtures, NOT a physical phone/camera benchmark.
import assert from 'node:assert/strict';
import {readFileSync,writeFileSync} from 'node:fs';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
const root=fileURLToPath(new URL('../',import.meta.url));
const require=createRequire((process.env.BROWSER_MODULES||root+'.cache/browser/node_modules')+'/_resolver.cjs');
const {chromium:playwright}=require('playwright'), chromiumModule=require('@sparticuz/chromium');
const chromium=chromiumModule.default||chromiumModule;
const acorn=require('acorn');
const controller=readFileSync(process.env.SCANNER_JS || root+'update-v4.152.0/assets/js/attendance-scanner-light.js','utf8');
const worker=readFileSync(root+'update-v4.152.0/assets/js/attendance-decoder-worker.js','utf8');
const qr=readFileSync(root+'update-v4.51.0/assets/js/qrcode-generator.js','utf8');
const decoder=readFileSync(root+'update-v4.51.0/assets/js/jsqr.min.js','utf8');
for(const code of [controller,worker,decoder])acorn.parse(code,{ecmaVersion:5});
console.log('PASS ES5 parsing: controller, worker, bundled decoder');
const html=readFileSync(root+'.cache/scanner-tests/page.html','utf8');
let browser;
let count=0; const measurements=[];
try {
 for(const mode of ['worker','compat']) {
  browser=await playwright.launch({executablePath:await chromium.executablePath(),args:[...chromium.args,'--disable-gpu'],headless:true});
  const context=await browser.newContext({viewport:{width:400,height:780}}), posts=[],errors=[],assets=[];
  await context.route('**/*',async route=>{
   const url=new URL(route.request().url());
   if(url.pathname.endsWith('attendance-scan-api.php')) {
    if(route.request().method()==='POST'){const j=JSON.parse(route.request().postData());posts.push(j.payload);if(process.env.SCANNER_SERVER_DELAY)await new Promise(r=>setTimeout(r,Number(process.env.SCANNER_SERVER_DELAY)));await route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,code:'present',student:j.payload,class:'Demo',time:'08:00',message:'Synthetic test'})})}
    else await route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,present:posts.length,late:0,absent:0,recent:[]})});return;
   }
   const body=url.pathname.endsWith('attendance-scanner.php')?html:url.pathname.endsWith('attendance-scanner-light.js')?controller:url.pathname.endsWith('attendance-decoder-worker.js')?'Uint8ClampedArray.from=undefined;\n'+worker:url.pathname.endsWith('jsqr.min.js')?decoder:null;
   if(body===null)throw Error('Unexpected network dependency '+url.pathname);
   assets.push(url.pathname);
   await route.fulfill({contentType:url.pathname.endsWith('.php')?'text/html':'application/javascript',body});
  });
  await context.addInitScript({content:qr+`;(${function(mode,opticalMock){
   const realPromise=window.Promise;
   window.__shownAt={};window.__scanTimings={};
   const originalSend=XMLHttpRequest.prototype.send;
   XMLHttpRequest.prototype.send=function(body){
    let data;try{data=JSON.parse(body)}catch(e){}
    if(data&&data.action==='scan'){
     const started=performance.now(),shown=window.__shownAt[data.payload];
     window.__scanTimings[data.payload]={detectMs:started-shown};
     this.addEventListener('load',function(){const t=window.__scanTimings[data.payload];t.serverMs=performance.now()-started;t.totalMs=performance.now()-shown});
    }
    return originalSend.call(this,body);
   };
   const input=document.createElement('canvas');input.width=640;input.height=480;
   const cx=input.getContext('2d');let spec=null,patch=null;
   window.drawFixture=function(next){spec=next;input.width=next.portrait?480:640;input.height=next.portrait?640:480;const code=qrcode(0,'M');code.addData(next.payload);code.make();const n=code.getModuleCount();patch=document.createElement('canvas');patch.width=patch.height=(n+8)*4;const p=patch.getContext('2d');p.fillStyle=next.inverted?'#111':'#fff';p.fillRect(0,0,patch.width,patch.height);p.fillStyle=next.inverted?'#fff':'#111';for(let y=0;y<n;y++)for(let x=0;x<n;x++)if(code.isDark(y,x))p.fillRect((x+4)*4,(y+4)*4,4,4);window.__shownAt[next.payload]=performance.now();paint()};
   function paint(){cx.fillStyle=spec&&spec.inverted?'#111':'#fff';cx.fillRect(0,0,input.width,input.height);if(!spec)return;cx.save();const t=performance.now();cx.translate(spec.x+(spec.motion?Math.sin(t/70)*25:0),spec.y+(spec.motion?Math.cos(t/90)*15:0));cx.rotate(((spec.angle||0)+(spec.motion?Math.sin(t/80)*8:0))*Math.PI/180);cx.imageSmoothingEnabled=false;if(spec.blur){cx.globalAlpha=1/3;for(let b=-1;b<=1;b++)cx.drawImage(patch,-spec.size/2+b*spec.blur,-spec.size/2,spec.size,spec.size)}else cx.drawImage(patch,-spec.size/2,-spec.size/2,spec.size,spec.size);cx.restore()}
   paint();setInterval(paint,16);
   const streams=[];window.fixtureStreams=streams;
   function open(constraints){let id=constraints.video&&constraints.video.deviceId?constraints.video.deviceId.exact:'A';if(constraints.video&&constraints.video.optional)id=constraints.video.optional[0].sourceId;const stream=input.captureStream(constraints.video&&constraints.video.frameRate?constraints.video.frameRate.ideal:30),track=stream.getVideoTracks()[0];track.getSettings=()=>({deviceId:id});
    if(opticalMock){
     const settings={deviceId:id,focusMode:'continuous',focusDistance:8,exposureMode:'continuous',exposureTime:400,iso:100,frameRate:30,torch:false};
     track.getSettings=()=>({...settings});
     track.getCapabilities=()=>({focusMode:['continuous','manual','single-shot'],focusDistance:{min:0,max:10,step:.1},exposureMode:['continuous','manual'],exposureTime:{min:1,max:1000,step:1},iso:{min:50,max:3200,step:50},frameRate:{min:1,max:60},torch:true});
     track.getConstraints=()=>({deviceId:{exact:id},width:{ideal:1280},height:{ideal:720},frameRate:{ideal:30,max:30}});
     track.applyConstraints=c=>{for(const f of c.advanced||[])Object.assign(settings,f);if(c.frameRate)settings.frameRate=c.frameRate.ideal;return realPromise.resolve()};
    }
    streams.push({stream,id});return stream}
   const devices=['A','B'].map(deviceId=>({kind:'videoinput',deviceId,label:'Camera '+deviceId}));
   Object.defineProperty(navigator,'deviceMemory',{value:2});Object.defineProperty(navigator,'hardwareConcurrency',{value:2});
   window.BarcodeDetector=undefined; // Exercise the real jsQR sensitivity, not an OS implementation.
   if(mode==='compat'){
    window.Worker=undefined;window.fetch=undefined;Promise.prototype.finally=undefined;Uint8ClampedArray.from=undefined;
    Object.defineProperty(navigator,'mediaDevices',{value:undefined});navigator.webkitGetUserMedia=(c,ok)=>ok(open(c));
    window.MediaStreamTrack.getSources=fn=>fn(devices.map(d=>({kind:'video',id:d.deviceId,label:d.label})));
    HTMLVideoElement.prototype.requestVideoFrameCallback=undefined;HTMLVideoElement.prototype.cancelVideoFrameCallback=undefined;
   }else Object.defineProperty(navigator,'mediaDevices',{value:{getUserMedia:c=>realPromise.resolve(open(c)),enumerateDevices:()=>realPromise.resolve(devices),addEventListener(){}}});
  }.toString()})(${JSON.stringify(mode)},${!!process.env.OPTICS_TEST});`});
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  if(mode==='compat') {const cdp=await context.newCDPSession(page);await cdp.send('Emulation.setCPUThrottlingRate',{rate:4});}
  await page.goto('http://scanner.test/attendance-scanner.php');
  await page.waitForFunction(()=>document.getElementById('camMsg').textContent.includes('تگ را هر جای'));
  // B remains selected after actual QR decoding and successful XHR registration.
  await page.locator('#camSwitch').click();
  await page.waitForFunction(()=>document.getElementById('camLabel').textContent==='Camera B'&&!document.getElementById('camSwitch').disabled);
  const fixtures=[
   {name:'normal',x:320,y:240,size:240},
   {name:'rotated90',x:320,y:240,size:180,angle:90},
   {name:'rotated180-inverted',x:320,y:240,size:180,angle:180,inverted:true},
   {name:'tilted25',x:320,y:240,size:180,angle:25},
   {name:'small-center',x:320,y:240,size:110},
   {name:'small-top-left',x:70,y:70,size:110},
   {name:'small-top-right',x:570,y:70,size:110},
   {name:'small-bottom-left',x:70,y:410,size:110},
   {name:'small-bottom-right-inverted',x:570,y:410,size:110,inverted:true},
   {name:'portrait',x:240,y:320,size:180,portrait:true}
  ];
  if(process.env.SCANNER_MOTION_FIXTURES)fixtures.push(
   {name:'moving-center',x:320,y:240,size:180,motion:true},
   {name:'moving-tilted',x:320,y:240,size:180,angle:20,motion:true},
   {name:'moving-light-blur',x:320,y:240,size:200,motion:true,blur:1},
   {name:'moving-inverted',x:320,y:240,size:180,motion:true,inverted:true}
  );
  const repeated=Array.from({length:Number(process.env.BENCH_REPEATS||1)},()=>fixtures).flat();
  for(const [i,f] of repeated.entries()){
   const payload='MTAG-ATT:'+(i+1)+':'+(i+1).toString(16).padStart(32,'0');
   await page.evaluate(spec=>window.drawFixture(spec),{...f,payload});
   await page.waitForFunction(p=>document.getElementById('resName').textContent===p,payload,{timeout:18000});
   assert(posts.includes(payload));
   const timing=await page.evaluate(p=>window.__scanTimings[p],payload);
   assert(Number.isFinite(timing.detectMs)&&Number.isFinite(timing.totalMs));
   measurements.push({mode,fixture:f.name,...timing});
   if(process.env.OPTICS_TEST&&i===0){
    await page.waitForFunction(()=>document.getElementById('opticsDetails').textContent.includes('فوکوس ثابت'));
    const settings=await page.evaluate(()=>window.fixtureStreams.find(s=>s.stream.getVideoTracks()[0].readyState==='live').stream.getVideoTracks()[0].getSettings());
    assert.equal(settings.focusDistance,8);assert.equal(settings.frameRate,60);assert.equal(settings.exposureTime,100);assert.equal(settings.iso,400);
    console.log('PASS',mode,'optical controls verified against simulated driver settings (not physical lens/shutter)');
   }
   assert.equal(await page.evaluate(()=>localStorage.getItem('mtag_scanner_cam')),'B');
   assert.equal(await page.evaluate(()=>window.fixtureStreams.filter(s=>s.stream.getVideoTracks()[0].readyState==='live').length),1);
   count++;console.log('PASS',mode,f.name,'actual video -> jsQR -> XHR; B retained');
  }
  assert.deepEqual(errors,[]);
  const resources=await page.evaluate(()=>performance.getEntriesByType('resource').map(e=>e.name));
  if(mode==='worker'){assert(assets.some(u=>u.includes('attendance-decoder-worker.js')));assert(!resources.some(u=>u.includes('jsqr.min.js')),'Worker test silently fell back to main thread');}
  else {assert(!assets.some(u=>u.includes('attendance-decoder-worker.js')));assert(resources.some(u=>u.includes('jsqr.min.js')));}
  await browser.close();
 }
 console.log(`PASS ${count} real-browser QR fixtures; synthetic frames, no physical-camera/old-phone guarantee`);
}finally{await browser.close()}
for(const mode of ['worker','compat']){
 const rows=measurements.filter(m=>m.mode===mode), sorted=rows.map(m=>m.detectMs).sort((a,b)=>a-b);
 const average=key=>Math.round(rows.reduce((s,r)=>s+r[key],0)/rows.length);
 console.log('TIMING',mode,JSON.stringify({samples:rows.length,detectMeanMs:average('detectMs'),detectP95Ms:Math.round(sorted[Math.ceil(sorted.length*.95)-1]),serverMeanMs:average('serverMs'),totalMeanMs:average('totalMs')}));
}
if(process.env.BENCH_REPORT)writeFileSync(process.env.BENCH_REPORT,JSON.stringify(measurements,null,2));
