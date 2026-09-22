/**
 * سوئیت ۳ — انتخاب گزینه و ذخیرهٔ پاسخ
 *
 * saveAnswer / updateProgress / setAutosaveState را از فایل واقعی استخراج و در
 * Node اجرا می‌کند. هدف: اثبات اینکه انتخاب گزینه هیچ ناوبری‌ای انجام نمی‌دهد
 * (یعنی دانش‌آموز از آزمون بیرون نمی‌افتد) و خطای سرور بی‌صدا نمی‌ماند.
 *
 * تگ‌های <?php echo ... ?> داخل جاوااسکریپت با مقدار رندرشده جایگزین می‌شوند —
 * دقیقاً همان چیزی که مرورگر دانش‌آموز می‌بیند.
 */
import { readFileSync } from 'node:fs';
import { resolveFile } from './harness/site.mjs';

const SRC = resolveFile('online-exam-take.php');
const src = readFileSync(SRC, 'utf8');
console.log(`منبع: ${SRC.split('/').slice(-3).join('/')}`);

function extractFn(name) {
  const i = src.indexOf('function ' + name + '(');
  if (i < 0) throw new Error('تابع پیدا نشد: ' + name);
  let depth = 0, started = false, inS = null;
  for (let j = src.indexOf('{', i); j < src.length; j++) {
    const c = src[j];
    if (inS) { if (c === '\\') { j++; continue; } if (c === inS) inS = null; continue; }
    if (c === "'" || c === '"' || c === '`') { inS = c; continue; }
    if (c === '{') { depth++; started = true; }
    else if (c === '}') { depth--; if (started && depth === 0) return src.slice(i, j + 1); }
  }
  throw new Error('آکولاد نابسته: ' + name);
}

const subs = [];
const renderPhp = code => code.replace(/<\?php\s*echo\s*([^;]*);\s*\?>/g, (m, e) => {
  const v = /count\(\$questions\)/.test(e) ? '12' : '0';
  subs.push(e.trim() + ' → ' + v);
  return v;
});
const fn = n => renderPhp(extractFn(n));

let pass = 0, fail = 0;
const ok = (n, c, d = '') => c ? (pass++, console.log(`  ✅ ${n}`)) : (fail++, console.log(`  ❌ ${n}${d ? '  → ' + d : ''}`));

function makeEnv({ apiResult, fetchFails = false }) {
  const navigations = [], states = [], storage = {};
  const els = {
    autosaveDot: { classList: { add() {}, remove() {} }, style: {} },
    autosaveText: { textContent: '', style: {} },
  };
  const box = { querySelector: s => (s.includes('radio') ? { value: 'b' } : null), querySelectorAll: () => [] };
  const env = {
    navigations, states, storage, els,
    document: { getElementById: id => (id.startsWith('qbox_') ? box : els[id] || null), querySelectorAll: () => [] },
    localStorage: { setItem: (k, v) => { storage[k] = v; }, getItem: k => storage[k] ?? null },
    FormData: class { constructor() { this.d = {}; } append(k, v) { this.d[k] = v; } },
    Array, JSON, console, setImmediate,
    location: { set href(v) { navigations.push(v); } },
    fetch: async () => {
      if (fetchFails) throw new Error('offline');
      return { json: async () => apiResult };
    },
  };
  const api = new Function('env', `
    const examId = 1, attemptId = 7;
    const document = env.document, localStorage = env.localStorage;
    const FormData = env.FormData, fetch = env.fetch, console = env.console;
    const location = env.location, Array = env.Array, JSON = env.JSON;
    const setImmediate = env.setImmediate;
    function setAutosaveState(st, msg){ env.states.push(st); ${fn('setAutosaveState').replace('function setAutosaveState(st, msg){', '').replace(/\}$/, '')} }
    ${fn('faNum')}
    ${fn('updateProgress')}
    ${fn('saveAnswer')}
    return { saveAnswer };
  `)(env);
  return { env, api, flush: () => new Promise(r => setImmediate(r)) };
}

console.log('جایگزینی تگ PHP با مقدار رندرشده:');
subs.length = 0; fn('updateProgress');
subs.forEach(s => console.log('  <?php echo ' + s + '; ?>'));

console.log('\n══ انتخاب گزینهٔ رادیو → saveAnswer ══');
{
  const t = makeEnv({ apiResult: { ok: true, msg: 'ذخیره شد' } });
  t.api.saveAnswer(101);
  await t.flush();
  ok('هیچ ناوبری‌ای انجام نشد (دانش‌آموز بیرون نمی‌افتد)', t.env.navigations.length === 0, JSON.stringify(t.env.navigations));
  ok('پاسخ در localStorage نگه داشته شد', !!t.env.storage['exam_1_attempt_7_q_101']);
  ok('گزینهٔ انتخاب‌شده درست خوانده شد', JSON.parse(t.env.storage['exam_1_attempt_7_q_101']).selected === 'b');
  ok('وضعیت «ذخیره شد» نمایش داده شد', t.env.states.includes('saved'), JSON.stringify(t.env.states));
  ok('وضعیت خطا نمایش داده نشد', !t.env.states.includes('error'));
}

console.log('\n══ سرور خطا می‌دهد (باگ بی‌صدا بودن خطا) ══');
{
  const t = makeEnv({ apiResult: { ok: false, msg: 'attempt یافت نشد' } });
  t.api.saveAnswer(101);
  await t.flush();
  ok('خطا به دانش‌آموز نشان داده می‌شود', t.env.states.includes('error'), JSON.stringify(t.env.states));
  ok('پیام واقعی سرور نمایش داده شد', t.env.els.autosaveText.textContent.includes('attempt یافت نشد'), t.env.els.autosaveText.textContent);
  ok('نقطهٔ نشانگر قرمز شد', t.env.els.autosaveDot.style.background === '#dc2626');
  ok('باز هم هیچ ناوبری‌ای نشد', t.env.navigations.length === 0);
}

console.log('\n══ اتصال قطع است ══');
{
  const t = makeEnv({ fetchFails: true });
  t.api.saveAnswer(101);
  await t.flush();
  ok('خطای شبکه اطلاع داده شد', t.env.states.includes('error'), JSON.stringify(t.env.states));
  ok('پیام راهنما نمایش داده شد', t.env.els.autosaveText.textContent.includes('اتصال قطع'), t.env.els.autosaveText.textContent);
  ok('پاسخ محلی نگه داشته شد', !!t.env.storage['exam_1_attempt_7_q_101']);
  ok('هیچ ناوبری‌ای نشد', t.env.navigations.length === 0);
}

console.log('\n════════════════════════════════');
console.log(`  سوئیت انتخاب گزینه: ${pass} PASS / ${fail} FAIL`);
console.log('════════════════════════════════');
process.exit(fail ? 1 : 0);
