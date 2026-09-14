/**
 * سوئیت ۲ — منطق تایمر
 *
 * تابع startTimer() را «از فایل واقعی» استخراج و در Node اجرا می‌کند. این
 * بازنویسی منطق نیست: همان کدی است که در مرورگر دانش‌آموز اجرا می‌شود، فقط با
 * stub برای DOM و fetch. بدون این، ادعای رفع بیرون‌افتادن از آزمون مدرک ندارد.
 */
import { readFileSync } from 'node:fs';
import { resolveFile } from './harness/site.mjs';

const SRC = resolveFile('online-exam-take.php');
const lines = readFileSync(SRC, 'utf8').split('\n');
console.log(`منبع: ${SRC.split('/').slice(-3).join('/')}`);

const start = lines.findIndex(l => l.trim() === 'function startTimer(){');
if (start < 0) { console.log('❌ startTimer پیدا نشد'); process.exit(1); }
let end = -1;
for (let i = start + 1; i < lines.length; i++) if (lines[i] === '}') { end = i; break; }
const timerSrc = lines.slice(start, end + 1).join('\n');
console.log(`استخراج شد: startTimer() خط ${start + 1}–${end + 1} (${timerSrc.split('\n').length} خط)`);

let pass = 0, fail = 0;
const ok = (n, c, d = '') => c ? (pass++, console.log(`  ✅ ${n}`)) : (fail++, console.log(`  ❌ ${n}${d ? '  → ' + d : ''}`));

function makeEnv({ duration = 3600, remaining = 3600, server = { ok: true, data: { expired: false, remaining: 1800 } }, fetchFails = false }) {
  const banners = [], submitted = [], warns = [];
  const el = { textContent: '', style: {}, classList: { add() {}, remove() {}, toggle() {} }, remove() {} };
  const env = {
    remainingSeconds: remaining, firstStartedTimestamp: null, examDurationSeconds: duration,
    attemptId: 7, submitted, warns, banners, now: 1_700_000_000_000,
    intervalFn: null, cleared: false,
    document: {
      hidden: false, getElementById: () => el, createElement: () => ({ ...el }),
      querySelectorAll: () => [], body: { appendChild: b => banners.push(b.textContent) },
      addEventListener() {},
    },
    console: { warn: m => warns.push(String(m)) },
    setInterval: fn => { env.intervalFn = fn; return 1; },
    clearInterval: () => { env.cleared = true; },
    setTimeout: fn => { fn(); return 1; },
    Date: { now: () => env.now },
    fetch: async () => {
      if (fetchFails) throw new Error('network down');
      return { json: async () => server };
    },
  };
  const api = new Function('env', `
    let remainingSeconds = env.remainingSeconds;
    let firstStartedTimestamp = env.firstStartedTimestamp;
    let examDurationSeconds = env.examDurationSeconds;
    let timerInterval = null;
    const attemptId = env.attemptId;
    const document = env.document, fetch = env.fetch;
    const setInterval = env.setInterval, clearInterval = env.clearInterval, setTimeout = env.setTimeout;
    const console = env.console, Date = env.Date;
    function submitExam(isAuto){ env.submitted.push(isAuto); }
    ${timerSrc}
    return { startTimer, get remaining(){ return remainingSeconds; }, get firstStarted(){ return firstStartedTimestamp; } };
  `)(env);
  return {
    env, api, start() { api.startTimer(); },
    async tick(ms = 1000, times = 1) {
      for (let i = 0; i < times; i++) {
        env.now += ms;
        if (env.intervalFn) env.intervalFn();
        await new Promise(r => setImmediate(r));
      }
    },
  };
}

console.log('\n══ سناریو ۱: آزمون عادی، تایمر واقعاً تمام می‌شود ══');
{
  const t = makeEnv({ server: { ok: true, data: { expired: true, remaining: 0 } } });
  t.start();
  ok('تایمر از مدت کامل شروع شد', t.api.remaining === 3600, 'remaining=' + t.api.remaining);
  await t.tick(1000, 60);
  ok('بعد از ۶۰ ثانیه، ۳۵۴۰ ثانیه باقی است', t.api.remaining === 3540, 'remaining=' + t.api.remaining);
  ok('هنوز ارسال نشده', t.env.submitted.length === 0);
  await t.tick(1000, 3540);
  ok('در پایان زمان، آزمون خودکار ارسال شد', t.env.submitted.length === 1, 'submitted=' + t.env.submitted.length);
  ok('بنر پایان نمایش داده شد', t.env.banners.some(b => b.includes('زمان آزمون تمام شد')), JSON.stringify(t.env.banners));
  ok('پرش ساعتی گزارش نشد', t.env.warns.length === 0, JSON.stringify(t.env.warns));
}

console.log('\n══ سناریو ۲: پرش ساعت دستگاه (همگام‌سازی NTP موبایل) ══');
{
  const t = makeEnv({ server: { ok: true, data: { expired: false, remaining: 3590 } } });
  t.start();
  await t.tick(1000, 10);
  await t.tick(600_000, 1);   // ساعت دستگاه ۱۰ دقیقه جلو می‌پرد
  ok('پرش ساعت تشخیص داده شد', t.env.warns.some(w => w.includes('جهش')), JSON.stringify(t.env.warns));
  ok('دانش‌آموز بیرون نیفتاد', t.env.submitted.length === 0, 'submitted=' + t.env.submitted.length);
  ok('تایمر از سرور بازمینی شد', t.api.remaining === 3590, 'remaining=' + t.api.remaining);
  ok('بنر همگام‌سازی نمایش داده شد', t.env.banners.some(b => b.includes('همگام شد')));
  await t.tick(1000, 30);
  ok('آزمون عادی ادامه یافت', t.api.remaining === 3560, 'remaining=' + t.api.remaining);
  ok('هنوز ارسال نشده', t.env.submitted.length === 0);
}

console.log('\n══ سناریو ۳: تایمر صفر شد ولی سرور می‌گوید وقت هست ══');
{
  const t = makeEnv({ duration: 100, remaining: 100, server: { ok: true, data: { expired: false, remaining: 1800 } } });
  t.start();
  await t.tick(1000, 100);
  ok('آزمون ارسال نشد', t.env.submitted.length === 0, 'submitted=' + t.env.submitted.length);
  ok('تایمر از سرور اصلاح شد', t.api.remaining === 1800, 'remaining=' + t.api.remaining);
  ok('تایمر دوباره راه افتاد', t.env.cleared === true && t.env.intervalFn !== null);
}

console.log('\n══ سناریو ۴: سرور تأیید می‌کند زمان تمام شده ══');
{
  const t = makeEnv({ duration: 100, remaining: 100, server: { ok: true, data: { expired: true, remaining: 0 } } });
  t.start();
  await t.tick(1000, 100);
  ok('آزمون ارسال شد', t.env.submitted.length === 1, 'submitted=' + t.env.submitted.length);
  ok('ارسال خودکار (isAuto=true) بود', t.env.submitted[0] === true);
}

console.log('\n══ سناریو ۵: سرور در دسترس نیست ══');
{
  const t = makeEnv({ duration: 100, remaining: 100, fetchFails: true });
  t.start();
  await t.tick(1000, 100);
  ok('در نبود سرور هم امن ارسال می‌شود', t.env.submitted.length === 1, 'submitted=' + t.env.submitted.length);
}

console.log('\n════════════════════════════════');
console.log(`  سوئیت تایمر: ${pass} PASS / ${fail} FAIL`);
console.log('════════════════════════════════');
process.exit(fail ? 1 : 0);
