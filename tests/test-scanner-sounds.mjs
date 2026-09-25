// صداهای ضبط‌شدهٔ اسکنر حضور و غیاب — اجرای واقعی کنترلر مستقر (ES5) با موتور
// صوتی و شبکهٔ شبیه‌سازی‌شده. هیچ دوربین یا کارت صوتی واقعی لازم نیست.
//
//   حضور به موقع → اول buzzer، بعد hzr.ogg
//   تأخیر        → اول buzzer، بعد tkhr.ogg
//   خطای شبکه   → net.ogg به‌جای buzzer (نه بعد از آن)
//
// همهٔ حالت‌ها با همان کنترلر نسخهٔ ارسالی اجرا می‌شوند؛ ترتیب صداها از فراخوانی
// واقعی createOscillator/createBufferSource گرفته می‌شود، نه از ادعای مستندات.
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';

const JS_PATH = process.env.SCANNER_JS || new URL('../update-v4.152.0/assets/js/attendance-scanner-light.js', import.meta.url);
const source = readFileSync(JS_PATH, 'utf8');
const instrumented = source.replace(/\}\)\(\);\s*$/, `window.test={openCamera:openCamera,scanTick:scanTick,suspend:suspend,resume:resume,scheduleRecovery:scheduleRecovery,sendScan:sendScan,stallCheck:stallCheck,stopScanLoop:stopScanLoop,debugDraw:debugDraw,
state:function(){return {camState:camState,desiredId:desiredId,generation:cameraGeneration,busy:busy,decodeBusy:decodeBusy,workerBroken:workerBroken,nativeBroken:nativeBroken,pending:!!pendingOpen,scanning:scanning}}};})();`);
assert.notEqual(instrumented, source, 'the controller must expose its test hooks');

/* ── محیط شبیه‌سازی‌شده ─────────────────────────────────────────────────── */
function harness(options = {}) {
  let time = 10000, id = 0, decodeCount = 0;
  const timers = new Map(), elements = new Map(), events = {};
  const requests = [], codes = [], audio = {created: 0, started: [], decoded: [], elements: [], resumes: 0};
  const opens = [];
  const storage = {mtag_scanner_cam: 'A'};

  function element(name) {
    if (elements.has(name)) return elements.get(name);
    const e = {style: {}, events: {}, children: [], value: '', textContent: '', innerHTML: '', disabled: false,
      addEventListener(k, fn) { this.events[k] = fn }, removeEventListener() {}, appendChild(c) { this.children.push(c) },
      setAttribute() {}, removeAttribute() {},
      getContext() { return {drawImage() {}, getImageData(x, y, w, h) { return {data: new Uint8ClampedArray([120, 120, 120, 255]), width: w, height: h} }} },
      srcObject: null, readyState: 2, videoWidth: 640, videoHeight: 480, currentTime: 1, paused: false,
      play() { this.paused = false }, pause() { this.paused = true }};
    elements.set(name, e);
    return e;
  }
  const thenable = record => ({then(ok, fail) { record.resolve = (...a) => ok(...a); record.reject = (...a) => fail(...a) }});

  class XHR {
    constructor() { requests.push(this); this.readyState = 0; this.status = 0 }
    open(method, url) { this.method = method; this.url = url }
    setRequestHeader() {}
    send(body) { this.body = body; this.sent = true }
    abort() { this.aborted = true; if (this.onabort) this.onabort() }
    respond(data, status = 200) {
      this.status = status; this.readyState = 4;
      this.responseText = typeof data === 'string' ? data : JSON.stringify(data);
      if (this.onreadystatechange) this.onreadystatechange();
    }
    /* پاسخ فایل صوتی (GET با responseType=arraybuffer) */
    serveSound(bytes = 1000) {
      this.status = 200; this.readyState = 4;
      this.response = {__name: String(this.url).split('/').pop(), __bytes: bytes};
      if (this.onload) this.onload();
    }
    fail() { this.status = 0; if (this.onerror) this.onerror() }
  }

  class AudioContextStub {
    constructor() { audio.created++; this.destination = {}; this.state = 'running' }
    get currentTime() { return (time - 10000) / 1000 }
    resume() { audio.resumes++; this.state = 'running'; return {then() {}} }
    createOscillator() {
      const o = {type: '', frequency: {setValueAtTime(f, at) { o.freq = f }, exponentialRampToValueAtTime(f) { o.slide = f }},
        connect() {}, stop() {}};
      o.start = at => audio.started.push({kind: 'buzz', freq: o.freq, type: o.type, at});
      return o;
    }
    createGain() { return {gain: {value: 0, setValueAtTime() {}, exponentialRampToValueAtTime() {}}, connect() {}} }
    createBufferSource() {
      const s = {buffer: null, connect() {}, start(at) { audio.started.push({kind: 'voice', name: s.buffer && s.buffer.__name, at}) }};
      return s;
    }
    decodeAudioData(data, ok, no) {
      decodeCount++;
      audio.decoded.push(data && data.__name);
      if (options.decodeFails) { if (no) no(); return }
      ok({__name: data && data.__name, duration: 1});
    }
  }

  const ctx = {
    console, Date: class extends Date {static now() { return time }}, Promise: undefined, fetch: undefined,
    XMLHttpRequest: XHR, Uint8ClampedArray,
    setTimeout(fn, ms) { const n = ++id; timers.set(n, {fn, at: time + ms}); return n },
    clearTimeout(n) { timers.delete(n) },
    setInterval() {}, clearInterval() {},
    localStorage: {getItem: k => storage[k], setItem: (k, v) => { storage[k] = v }},
    location: {reload() {}, search: ''},
    URL: {createObjectURL() { return 'blob:camera' }, revokeObjectURL() {}},
    document: {
      hidden: false, getElementById: element,
      createElement: tag => { const e = element('new' + (++id)); e.tagName = tag; return e },
      body: {children: [], appendChild(c) { this.children.push(c) }},
      head: {appendChild() {}}, addEventListener(k, f) { this.events[k] = f }, removeEventListener() {}, events: {}
    },
    navigator: {deviceMemory: 2, hardwareConcurrency: 2, mediaDevices: {getUserMedia(c) { const r = {constraints: c}; opens.push(r); return thenable(r) }, enumerateDevices() { const r = {}; return thenable(r) }, addEventListener() {}}},
    jsQR: () => null
  };
  ctx.window = ctx;
  ctx.addEventListener = (k, f) => { events[k] = f };
  if (!options.noAudio) ctx.AudioContext = AudioContextStub;
  if (!options.noElementAudio) ctx.Audio = class {
    constructor(url) { this.src = url; this.events = {}; audio.elements.push(this) }
    addEventListener(name, fn) { this.events[name] = fn }
    play() { this.plays = (this.plays || 0) + 1; return {catch() {}} }
  };
  vm.createContext(ctx);
  vm.runInContext(instrumented, ctx);

  function stream(camera = 'A') {
    const track = {label: 'Camera ' + camera, readyState: 'live', muted: false, stop() { this.stopped = true }, getSettings() { return {deviceId: camera} }, getCapabilities() { return {} }, getConstraints() { return {deviceId: {exact: camera}}}};
    return {id: camera, track, getTracks() { return [track] }, getVideoTracks() { return [track] }};
  }
  function tick(ms) {
    const end = time + ms; let count = 0;
    for (;;) {
      const next = [...timers].filter(([, t]) => t.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
      if (!next) break;
      if (++count > 5000) throw Error('timer loop');
      timers.delete(next[0]); time = next[1].at; next[1].fn();
    }
    time = end;
  }
  return {
    ctx, el: element, tick, audio, requests, opens, storage,
    open(n = 0, camera = 'A') { const s = stream(camera); opens[n].resolve(s); return s },
    state: () => ctx.test.state(),
    sounds: () => requests.filter(r => r.method === 'GET'),
    posts: () => requests.filter(r => r.method === 'POST'),
    serveSounds(bytes) { requests.filter(r => r.method === 'GET' && r.sent && !r.response).forEach(r => r.serveSound(bytes)) },
    buzzes: () => audio.started.filter(x => x.kind === 'buzz'),
    voices: () => audio.started.filter(x => x.kind === 'voice'),
    get decodes() { return decodeCount }
  };
}

let passed = 0;
function test(name, fn) { try { fn(); console.log('PASS', name); passed++ } catch (e) { console.error('FAIL', name); throw e } }

const present = {ok: true, code: 'present', student: 'زهرا احمدی', class: '۱۰۱', time: '07:55', message: 'ok'};
const late = {ok: true, code: 'late', student: 'زهرا احمدی', class: '۱۰۱', time: '08:52', message: 'ok'};
const testTag = {ok: true, code: 'present', student: 'حضور به موقع — تگ آزمایشی', class: '', time: '08:10', message: 'تگ آزمایشی خوانده شد'};
/* یک اسکن کامل: عامل، ارسال، پاسخ سرور. */
function scan(h, response, payload = 'MTAG-ATT:1:abcdef') {
  h.ctx.test.sendScan(payload, true);
  h.posts().pop().respond(response);
}

test('boot: هر سه فایل صوتی یک‌بار و از مسیر uploads/sounds دانلود می‌شوند', () => {
  const h = harness();
  const gets = h.sounds();
  assert.deepEqual(gets.map(r => r.url).sort(), ['uploads/sounds/hzr.ogg', 'uploads/sounds/net.ogg', 'uploads/sounds/tkhr.ogg']);
  assert(gets.every(r => r.responseType === 'arraybuffer'), 'the sounds are fetched as bytes');
  assert.equal(new Set(gets.map(r => r.url)).size, 3, 'each file is requested exactly once');
  h.serveSounds();
  assert.deepEqual(h.audio.decoded.sort(), ['hzr.ogg', 'net.ogg', 'tkhr.ogg'], 'each file is decoded into the audio context');
  assert.equal(h.sounds().length, 3, 'no second download of the same file');
});

test('حضور به موقع: اول buzzer، بعد صدای hzr.ogg', () => {
  const h = harness(); h.serveSounds(); h.open();
  scan(h, present);
  const buzz = h.buzzes(), voice = h.voices();
  assert.equal(buzz.length, 2, 'the two-tone accept buzzer still plays');
  assert.deepEqual(buzz.map(b => b.freq), [1318.5, 1975.5]);
  assert.equal(voice.length, 1, 'the recorded present alert plays once');
  assert.equal(voice[0].name, 'hzr.ogg');
  assert(voice[0].at >= 0.31, 'the voice starts after the buzzer ends, got ' + voice[0].at);
  assert(Math.max(...buzz.map(b => b.at)) < voice[0].at, 'the buzzer is always first');
  assert.equal(h.el('resBox').className, 'result ok');
});

test('تأخیر: اول buzzer، بعد صدای tkhr.ogg', () => {
  const h = harness(); h.serveSounds(); h.open();
  scan(h, late);
  const buzz = h.buzzes(), voice = h.voices();
  assert.equal(buzz.length, 2);
  assert.deepEqual(buzz.map(b => b.freq), [740, 740], 'the warn buzzer is unchanged');
  assert.equal(voice[0].name, 'tkhr.ogg');
  assert(voice[0].at >= 0.36, 'the voice starts after the warn buzzer, got ' + voice[0].at);
  assert(Math.max(...buzz.map(b => b.at)) < voice[0].at);
  assert.equal(h.el('resBox').className, 'result warn');
});

test('خطای شبکه: net.ogg جای buzzer را می‌گیرد (هیچ buzzerی زده نمی‌شود)', () => {
  const h = harness(); h.serveSounds(); h.open();
  h.ctx.test.sendScan('tag-1', true);
  h.posts()[0].fail();                 // تلاش اول
  h.tick(10);
  assert.equal(h.posts().length, 2, 'the scanner still retries once');
  h.posts()[1].fail();
  assert.equal(h.buzzes().length, 0, 'the error buzzer must NOT sound for a network failure');
  assert.equal(h.voices().length, 1);
  assert.equal(h.voices()[0].name, 'net.ogg');
  assert(h.voices()[0].at <= 0.05, 'the recording itself starts immediately, not after a buzzer (got ' + h.voices()[0].at + ')');
  assert.match(h.el('resName').textContent, /خطای شبکه/);
});

test('پاسخ نرسیدن از سرور (timeout): همان net.ogg، بدون buzzer', () => {
  const h = harness(); h.serveSounds(); h.open();
  h.ctx.test.sendScan('tag-2', true);
  h.posts()[0].ontimeout(); h.tick(10);
  h.posts()[1].ontimeout();
  assert.equal(h.buzzes().length, 0);
  assert.equal(h.voices().length, 1);
  assert.equal(h.voices()[0].name, 'net.ogg');
  assert.match(h.el('resName').textContent, /پاسخ سرور دیر شد/);
});

test('خطای سرور (پاسخ خراب): buzzer خطا می‌ماند و هیچ صدای ضبط‌شده‌ای پخش نمی‌شود', () => {
  const h = harness(); h.serveSounds(); h.open();
  scan(h, {error: 'boom'}, 'tag-3');           // HTTP 500
  assert.deepEqual(h.buzzes().map(b => b.freq), [160, 160], 'the harsh double BZZT stays');
  assert.equal(h.voices().length, 0, 'a server response problem is not a network failure');
});

test('تگ تکراری و کد نامعتبر: buzzer می‌ماند، صدای ضبط‌شده پخش نمی‌شود', () => {
  for (const response of [{ok: false, code: 'duplicate', student: 'زهرا احمدی', status: 'ورود به موقع'},
                          {ok: false, code: 'invalid', message: 'کد QR نامعتبر است.'}]) {
    const h = harness(); h.serveSounds(); h.open();
    scan(h, response, 'tag-' + response.code);
    assert(h.buzzes().length > 0, response.code + ': the buzzer still announces the outcome');
    assert.equal(h.voices().length, 0, response.code + ': no recorded alert');
  }
});

test('تگ آزمایشی «حضور به موقع» همان صدای واقعی را می‌دهد (آزمون کل زنجیره)', () => {
  const h = harness(); h.serveSounds(); h.open();
  scan(h, testTag, 'MTAG-ATT-TEST:present');
  assert.equal(h.buzzes().length, 2);
  assert.equal(h.voices()[0].name, 'hzr.ogg');
  assert.match(h.el('resName').textContent, /تگ آزمایشی/);
});

test('صدای حضور فقط یک‌بار برای هر اسکن پخش می‌شود', () => {
  const h = harness(); h.serveSounds(); h.open();
  scan(h, present, 'tag-a'); scan(h, present, 'tag-b'); scan(h, present, 'tag-c');
  assert.equal(h.voices().length, 3, 'one alert per confirmed scan');
  assert.deepEqual(h.voices().map(v => v.name), ['hzr.ogg', 'hzr.ogg', 'hzr.ogg']);
  assert.equal(h.ctx.test.sendScan('tag-a', false), false, 'the per-tag cooldown is untouched');
});

test('آماده‌بودن فایل دیرتر از اسکن: پیام بلافاصله بعد از decode پخش می‌شود', () => {
  const h = harness();
  h.open();
  scan(h, present, 'tag-late-file');            // فایل‌ها هنوز نیامده‌اند
  assert.equal(h.voices().length, 0, 'nothing can play before the file is ready');
  h.serveSounds();                              // پاسخ فایل‌ها می‌رسد (کمتر از ۱.۵ ثانیه)
  assert.equal(h.voices().length, 1);
  assert.equal(h.voices()[0].name, 'hzr.ogg');
});

test('فایل خیلی دیرهنگام پخش نمی‌شود (صدای کهنه برای اسکن قدیمی)', () => {
  const h = harness();
  h.open();
  scan(h, present, 'tag-stale');
  h.tick(4000);                                 // بیش از پنجرهٔ ۱.۵ ثانیه‌ای
  h.serveSounds();
  assert.equal(h.voices().length, 0, 'a voice must never arrive long after its scan');
});

test('نبود WebAudio و Audio: اسکنر کار می‌کند و بی‌صدا (بدون هیچ خطا) می‌ماند', () => {
  const h = harness({noAudio: true, noElementAudio: true});
  h.open();
  scan(h, present, 'tag-silent');
  scan(h, late, 'tag-silent2');
  h.ctx.test.sendScan('tag-silent3', true);
  h.posts().pop().fail(); h.tick(10); h.posts().pop().fail();
  assert.equal(h.el('resBox').className, 'result err');
  assert.equal(h.audio.started.length, 0);
  assert.equal(h.state().camState, 'ready', 'scanning is never disturbed by audio');
});

test('شکست decode (Vorbis پشتیبانی نمی‌شود): به عنصر <audio> برمی‌گردد و باز هم بعد از buzzer پخش می‌کند', () => {
  const h = harness({decodeFails: true});
  h.serveSounds();
  assert.equal(h.audio.elements.length, 3, 'the fallback element is created for each alert');
  assert.deepEqual(h.audio.elements.map(a => a.src).sort(), ['uploads/sounds/hzr.ogg', 'uploads/sounds/net.ogg', 'uploads/sounds/tkhr.ogg']);
  h.open();
  scan(h, present, 'tag-fallback');
  const el = h.audio.elements.find(a => a.src === 'uploads/sounds/hzr.ogg');
  assert(!el.plays, 'the voice waits for the buzzer to finish');
  h.tick(400);
  assert.equal(el.plays, 1, 'then the recorded alert plays through the element');
});

test('نبود مسیر اصلی: از uploads/sounds به assets/audio برمی‌گردد', () => {
  const h = harness();
  const primary = h.sounds().filter(r => r.url.indexOf('uploads/sounds/') === 0);
  primary.forEach(r => { r.status = 404; r.response = null; if (r.onload) r.onload() });
  const fallback = h.sounds().filter(r => r.url.indexOf('assets/audio/') === 0);
  assert.equal(fallback.length, 3, 'the read-only fallback is requested for all three files');
  fallback.forEach(r => r.serveSound());
  assert.deepEqual(h.audio.decoded.sort(), ['hzr.ogg', 'net.ogg', 'tkhr.ogg']);
  h.open();
  scan(h, present, 'tag-assets-fallback');
  assert.equal(h.voices()[0].name, 'hzr.ogg');
  assert.equal(h.el('resBox').className, 'result ok');
});

test('نبود هر دو مسیر صوتی (۴۰۴): اسکنر سالم می‌ماند و هیچ صدایی ادعا نمی‌شود', () => {
  const h = harness();
  h.sounds().slice().forEach(r => { r.status = 404; r.response = null; if (r.onload) r.onload() });
  h.sounds().filter(r => r.url.indexOf('assets/audio/') === 0 && !r.response)
    .forEach(r => { r.status = 404; r.response = null; if (r.onload) r.onload() });
  h.open();
  scan(h, present, 'tag-404');
  assert.equal(h.buzzes().length, 2, 'the buzzer keeps working');
  assert.equal(h.voices().length, 0);
  assert.equal(h.el('resBox').className, 'result ok');
});

test('خرابی عنصر audio هم مسیر جایگزین را امتحان می‌کند', () => {
  const h = harness({decodeFails: true});
  h.serveSounds();
  const primary = h.audio.elements.find(a => a.src === 'uploads/sounds/hzr.ogg');
  assert(primary && primary.events.error, 'the primary audio element has an error fallback');
  primary.events.error();
  const fallback = h.audio.elements.find(a => a.src === 'assets/audio/hzr.ogg');
  assert(fallback, 'the fallback audio element is created');
  h.open();
  scan(h, present, 'tag-element-fallback');
  h.tick(400);
  assert.equal(fallback.plays, 1);
});

test('مسیر صدا از پیکربندی صفحه قابل تغییر است (و پیش‌فرض uploads/sounds است)', () => {
  const html = readFileSync(new URL('../update-v4.152.0/attendance-scanner.php', import.meta.url), 'utf8');
  assert.match(html, /sounds:\s*'uploads\/sounds\/'/, 'the page must point at the sounds directory');
  assert.match(html, /attendance-scanner-light\.js\?v=4\.152\.0-camera9-sounds-upload-path/, 'the JS cache id must change');
  assert.match(html, /camera9/, 'the existing cache token is kept (rollback and page tests rely on it)');
  const js = readFileSync(JS_PATH, 'utf8');
  assert.match(js, /SOUND_FILES = \{ net: 'net\.ogg', present: 'hzr\.ogg', late: 'tkhr\.ogg' \}/);
});

console.log(`PASS ${passed} scanner voice-alert cases (buzzer order, network alert, graceful degradation)`);
process.exit(0);
