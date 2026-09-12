/**
 * سوئیت ۴ — مرحلهٔ موقعیت مکانی
 *
 * باگ: در مرورگر کامپیوتر، «لطفا صبر کنید» می‌ماند و دکمهٔ شروع آزمون هرگز
 * ظاهر نمی‌شود. علت: getCurrentPosition وقتی مجوز پاسخ داده نشده باشد هیچ
 * callback صدا نمی‌زند و timeout هم در آن حالت اعمال نمی‌شود. مرورگر موبایل
 * دیالوگ مودال می‌دهد (پاسخ داده می‌شود) ولی دسکتاپ حباب کوچکی کنار نوار آدرس
 * می‌دهد که دیده نمی‌شود.
 *
 * این تست با «ساعت مجازی» اجرا می‌شود تا بتواند بی‌نهایت را در زمان محدود
 * اثبات کند: تابع واقعی از فایل بیرون کشیده می‌شود، گیت واقعی
 * checkAllPermissionsDone هم همین‌طور (پس واقعاً startExamBtn سنجیده می‌شود).
 */
import { readFileSync } from 'node:fs';
import { resolveSite, resolveFile } from './harness/site.mjs';
import { join } from 'node:path';

/* ── استخراج تابع با تطبیق آکولاد ── */
function extractFn(src, name) {
  const i = src.indexOf(name + '(');
  if (i < 0) throw new Error('تابع پیدا نشد: ' + name);
  let start = src.lastIndexOf('function', i);
  // کلمهٔ async «قبل از» function می‌آید، نه بین function و نام
  const before = src.slice(Math.max(0, start - 12), start).trimEnd();
  if (before.endsWith('async')) start = src.lastIndexOf('async', start);
  let depth = 0, began = false, inS = null;
  for (let j = src.indexOf('{', i); j < src.length; j++) {
    const c = src[j];
    if (inS) { if (c === '\\') { j++; continue; } if (c === inS) inS = null; continue; }
    if (c === "'" || c === '"' || c === '`') { inS = c; continue; }
    if (c === '{') { depth++; began = true; }
    else if (c === '}') { depth--; if (began && depth === 0) return src.slice(start, j + 1); }
  }
  throw new Error('آکولاد نابسته: ' + name);
}
const renderPhp = code => code
  .replace(/<\?php\s*echo\s*json_encode\([^;]*\);\s*\?>/g, '{"camera":false,"mic":false,"location":true}')
  .replace(/<\?php\s*echo\s*([^;]*);\s*\?>/g, '0');

const OLD_SRC = readFileSync(join(resolveSite(), 'online-exam-take.php'), 'utf8');
const NEW_PATH = resolveFile('online-exam-take.php');
const NEW_SRC = readFileSync(NEW_PATH, 'utf8');
console.log(`قدیمی: ${resolveSite().split('/').slice(-3).join('/')}`);
console.log(`جدید : ${NEW_PATH.split('/').slice(-4).join('/')}`);
const HAS_BASELINE = OLD_SRC !== NEW_SRC;
let skipped = 0;
if (!HAS_BASELINE) console.log('⚠️  سورس قدیمی و جدید یکسان‌اند (PATCH ست نشده) — دو تست بازتولید باگ skip می‌شوند');

/* ── ساعت مجازی ── */
const flush = async () => { for (let i = 0; i < 4; i++) await new Promise(r => setImmediate(r)); };
class Clock {
  constructor() { this.now = 0; this.timers = new Map(); this.seq = 1; }
  setTimeout(fn, ms) { const id = this.seq++; this.timers.set(id, { at: this.now + (ms || 0), fn }); return id; }
  clearTimeout(id) { this.timers.delete(id); }
  async advance(ms) {
    const target = this.now + ms;
    for (let guard = 0; guard < 500; guard++) {
      /* اول میکروتسک‌های «زمان جاری» را اجرا می‌کنیم. در دنیای واقعی اینها
         همین لحظه حل می‌شوند و تایمرهایشان را clearTimeout می‌کنند؛ اگر اول به
         تایمر بعدی بپریم، ترتیب را اشتباه شبیه‌سازی کرده‌ایم. */
      await flush();
      let next = null, nid = null;
      for (const [id, t] of this.timers) if (t.at <= target && (!next || t.at < next.at)) { next = t; nid = id; }
      if (!next) break;
      this.now = next.at; this.timers.delete(nid);
      try { next.fn(); } catch (e) { /* خطای تایمر نباید تست را بشکند */ }
      await flush();
    }
    this.now = target; await flush();
  }
}

/* ── محیط آزمون ── */
function makeEnv({ perm = 'prompt', geo = 'never', ip = 'ok', clock }) {
  const logs = [], unhidden = [];
  const mkEl = id => ({ id, classList: {
    _s: new Set(['hidden']),
    add(...c) { c.forEach(x => this._s.add(x)); },
    remove(...c) { c.forEach(x => { this._s.delete(x); if (x === 'hidden' && id === 'startExamBtn') unhidden.push(clock.now); }); },
  }, textContent: '', style: {} });
  const els = { startExamBtn: mkEl('startExamBtn'), check_location: mkEl('check_location'), locationDetail: mkEl('locationDetail') };
  const statusEl = mkEl('status');

  const geoApi = {
    getCurrentPosition(ok, err) {
      if (geo === 'never') return;                       // ← باگ دسکتاپ: هیچ callback
      if (geo === 'ok') return ok({ coords: { latitude: 35.7, longitude: 51.4, accuracy: 20 } });
      if (geo === 'deny') return err({ code: 1, message: 'denied' });
      if (geo === 'slow-ok') { clock.setTimeout(() => ok({ coords: { latitude: 35.7, longitude: 51.4, accuracy: 900 } }), 3000); return; }
      clock.setTimeout(() => err({ code: 3, message: 'timeout' }), 1000);      // 'timeout'
    },
  };

  const env = {
    logs, unhidden, els,
    permissionState: { camera: false, mic: false, location: false, internet: false },
    document: {
      querySelector: s => (s.includes('#check_location') ? statusEl : null),
      getElementById: id => els[id] || mkEl(id),
    },
    navigator: {
      geolocation: geoApi,
      permissions: { query: async () => ({ state: perm }) },
    },
    location: { protocol: 'https:', hostname: 'school.example' },
    window: {},
    fetch: async (url, o) => {
      if (ip === 'hang') return new Promise(() => {});            // هرگز حل نمی‌شود
      if (ip === 'fail') throw new Error('blocked');
      if (url.includes('ipapi.co') && ip === 'second') throw new Error('blocked');
      return { json: async () => ({ latitude: 35.68, longitude: 51.41 }) };
    },
    setTimeout: (fn, ms) => clock.setTimeout(fn, ms),
    clearTimeout: id => clock.clearTimeout(id),
    AbortController: class { constructor() { this.signal = {}; } abort() {} },
    logProctor: (t, d) => logs.push(t + ':' + d),
  };
  return env;
}

function build(src) {
  const loc = extractFn(src, 'checkLocationRobust');
  const gate = renderPhp(extractFn(src, 'function checkAllPermissionsDone'));
  return new Function('env', `
    const permissionState = env.permissionState, document = env.document;
    const navigator = env.navigator, location = env.location, window = env.window;
    const fetch = env.fetch, setTimeout = env.setTimeout, clearTimeout = env.clearTimeout;
    const AbortController = env.AbortController, console = { log(){}, warn(){} };
    const logProctor = env.logProctor;
    ${gate}
    ${loc}
    return { checkLocationRobust, permissionState };
  `);
}

let pass = 0, fail = 0;
/* لحظهٔ واقعی ظاهرشدن دکمهٔ شروع — نه مقدار پیشروی ساعت */
const solvedAt = r => (r.env.unhidden.length ? r.env.unhidden[0] : Infinity);

const ok = (n, c, d = '') => c ? (pass++, console.log(`  ✅ ${n}`)) : (fail++, console.log(`  ❌ ${n}${d ? '  → ' + d : ''}`));

async function scenario(src, label, opts, advanceMs) {
  const clock = new Clock();
  const env = makeEnv({ ...opts, clock });
  const api = build(src)(env);
  const p = api.checkLocationRobust();
  await clock.advance(advanceMs);
  return { env, done: api.permissionState.location, clock, p };
}

const OLD = OLD_SRC, NEW = NEW_SRC;

console.log('\n══ بازتولید باگ با کد قدیمی ══');
if (HAS_BASELINE) {
  const r = await scenario(OLD, 'old', { perm: 'prompt', geo: 'never', ip: 'ok' }, 300_000);
  ok('[قدیمی] مجوز بی‌پاسخ → حتی بعد از ۵ دقیقه قفل می‌ماند (همان باگ دسکتاپ)',
    r.done === false && r.env.unhidden.length === 0,
    'location=' + r.done + ' btn=' + r.env.unhidden.length);

  const r2 = await scenario(OLD, 'old', { perm: 'prompt', geo: 'timeout', ip: 'hang' }, 300_000);
  ok('[قدیمی] GPS رد + سرویس IP بی‌پاسخ → قفل می‌ماند', r2.done === false, 'location=' + r2.done);
} else {
  skipped = 2;
  console.log('  ⏭  skip: سورس پایه‌ای در دسترس نیست که باگ را بازتولید کند');
}

console.log('\n══ رفتار کد جدید ══');
{
  const r = await scenario(NEW, 'new', { perm: 'prompt', geo: 'never', ip: 'ok' }, 60_000);
  ok('مجوز بی‌پاسخ (باگ دسکتاپ) → دانش‌آموز وارد می‌شود', r.done === true, 'location=' + r.done);
  ok('دکمهٔ «شروع آزمون» ظاهر شد', r.env.unhidden.length > 0, 'unhidden=' + r.env.unhidden.length);
  ok('در کمتر از ۱۵ ثانیه حل شد', solvedAt(r) <= 15_000, clock_ms(solvedAt(r)));
  console.log('       ⏱ زمان واقعی حل‌شدن: ' + clock_ms(solvedAt(r)));
  console.log('       لاگ: ' + r.env.logs.join(' | '));
}
{
  const r = await scenario(NEW, 'new', { perm: 'prompt', geo: 'never', ip: 'hang' }, 90_000);
  ok('GPS بی‌پاسخ + IP بی‌پاسخ → واتچ‌داگ نجات می‌دهد', r.done === true && r.env.unhidden.length > 0);
  ok('واتچ‌داگ ثبت شد', r.env.logs.some(l => l.startsWith('location_watchdog')), r.env.logs.join(' | '));
}
{
  const r = await scenario(NEW, 'new', { perm: 'prompt', geo: 'never', ip: 'fail' }, 90_000);
  ok('هر دو منبع IP مسدود → باز هم وارد می‌شود', r.done === true && r.env.unhidden.length > 0);
}
{
  const r = await scenario(NEW, 'new', { perm: 'denied', geo: 'deny', ip: 'ok' }, 60_000);
  ok('مجوز رد شده → مستقیم IP، بدون تلاش بیهودهٔ GPS', r.done === true && r.env.logs.some(l => l.startsWith('location_denied')),
    r.env.logs.join(' | '));
  ok('سریع حل شد (زیر ۲ ثانیه)', solvedAt(r) <= 2_000, clock_ms(solvedAt(r)));
}
{
  const r = await scenario(NEW, 'new', { perm: 'granted', geo: 'ok', ip: 'ok' }, 60_000);
  ok('GPS سالم → موقعیت دقیق ثبت شد', r.done === true && r.env.window._lastPos?.coords?.accuracy === 20,
    JSON.stringify(r.env.window._lastPos?.coords));
  ok('فوراً حل شد (زیر ۱ ثانیه)', solvedAt(r) <= 1_000, clock_ms(solvedAt(r)));
  ok('location_acquired ثبت شد', r.env.logs.some(l => l.startsWith('location_acquired')));
}
{
  const r = await scenario(NEW, 'new', { perm: 'granted', geo: 'slow-ok', ip: 'ok' }, 60_000);
  ok('GPS کند (۳ ثانیه) → موفق', r.done === true && r.env.logs.some(l => l.startsWith('location_acquired')));
}
{
  const r = await scenario(NEW, 'new', { perm: 'granted', geo: 'timeout', ip: 'ok' }, 90_000);
  ok('GPS تایم‌اوت → موقعیت IP جایگزین شد', r.done === true && r.env.window._lastPos?.coords?.isIp === true,
    JSON.stringify(r.env.window._lastPos?.coords));
}
{
  const r = await scenario(NEW, 'new', { perm: 'granted', geo: 'timeout', ip: 'second' }, 90_000);
  ok('منبع اول IP مسدود → منبع جایگزین جواب داد', r.done === true && r.env.window._lastPos?.coords?.isIp === true,
    JSON.stringify(r.env.window._lastPos?.coords));
}
{
  const r = await scenario(NEW, 'new', { perm: 'granted', geo: 'timeout', ip: 'hang' }, 120_000);
  ok('بدترین حالت (همه‌چیز گیر) → باز هم زیر ۴۵ ثانیه آزاد می‌شود',
    r.done === true && solvedAt(r) <= 45_000, clock_ms(solvedAt(r)));
  console.log('       ⏱ بدترین حالت: ' + clock_ms(solvedAt(r)));
}

function clock_ms(ms) {
  if (!Number.isFinite(ms)) return '∞ (هرگز)';
  const s = Math.round(ms / 1000);
  return (s >= 60 ? Math.floor(s / 60) + 'm' + (s % 60) + 's' : s + 's');
}

console.log('\n════════════════════════════════');
console.log(`  سوئیت موقعیت مکانی: ${pass} PASS / ${fail} FAIL` + (skipped ? ` / ${skipped} SKIP` : ''));
console.log('════════════════════════════════');
process.exit(fail ? 1 : 0);
