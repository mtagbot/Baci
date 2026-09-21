/**
 * سوئیت ۶ — هندسهٔ تختهٔ سفید (v4.128.0)
 *
 * خواستهٔ کاربر: «بوم به اندازهٔ قاب نمایش داده شود، زوم حذف شود، اسکرول نباشد».
 *
 * دو تابع واقعی از فایل PHP بیرون کشیده و روی چند عرض نمایش اجرا می‌شوند:
 *   getCanvasCoords() — نقطهٔ لمس → مختصات بوم
 *   displayScale()    — نسبت وضوح داخلی به اندازهٔ نمایش
 * بعلاوه بازرسی خودِ نشانه‌گذاری رندرشده (کلاس CSS، حذف بزرگنمایی).
 */
import { readFileSync } from 'node:fs';
import { req, examId } from './harness/lib.mjs';
import { resolveFile } from './harness/site.mjs';

let pass = 0, fail = 0;
const ok = (n, c, d = '') => c ? (pass++, console.log(`  ✅ ${n}`)) : (fail++, console.log(`  ❌ ${n}${d ? '  → ' + d : ''}`));

/* ── منبع ─────────────────────────────────────────────── */
const SRC = resolveFile('online-exam-take.php');
if (!SRC) { console.error('منبع پیدا نشد'); process.exit(2); }
const src = readFileSync(SRC, 'utf8');
console.log(`\n>>> منبع: ${SRC.replace(process.cwd() + '/', '')}  (${src.length} نویسه)`);

/* ── استخراج تابع از میان کد PHP ─────────────────────── */
function extractFn(name) {
  const re = new RegExp(`(?:async\\s+)?function\\s+${name}\\s*\\(`);
  const m = re.exec(src);
  if (!m) throw new Error('تابع پیدا نشد: ' + name);
  let i = src.indexOf('{', m.index), depth = 0, q = null;
  for (let j = i; j < src.length; j++) {
    const c = src[j];
    if (q) { if (c === '\\') j++; else if (c === q) q = null; continue; }
    if (c === '"' || c === "'" || c === '`') { q = c; continue; }
    if (c === '{') depth++;
    else if (c === '}') { depth--; if (!depth) return src.slice(m.index, j + 1); }
  }
  throw new Error('بستن تابع پیدا نشد: ' + name);
}

const getCanvasCoords = new Function('canvas', 'clientX', 'clientY',
  extractFn('getCanvasCoords').replace(/^function\s+getCanvasCoords\s*\([^)]*\)\s*\{/, '').replace(/\}\s*$/, ''));
const displayScale = new Function('canvas',
  extractFn('displayScale').replace(/^function\s+displayScale\s*\([^)]*\)\s*\{/, '').replace(/\}\s*$/, ''));

/* بوم ساخته‌شده: وضوح داخلی ۱۰۰۰×۵۶۰، نمایش با width:100% و height:auto */
const CW = 1000, CH = 560, AR = CW / CH;
const mkCanvas = (dispW, left = 12, top = 200) => ({
  width: CW, height: CH,
  getBoundingClientRect: () => ({ width: dispW, height: dispW / AR, left, top, right: left + dispW, bottom: top + dispW / AR }),
});
/* ضخامت واقعی قلم در کد: Math.max(1, st.width * displayScale(canvas)) */
const strokeWidth = (dispW, picked) => Math.max(1, picked * displayScale(mkCanvas(dispW)));

/* ── ۱) مختصات روی هر دستگاهی یکی است ──────────────── */
console.log('\n══ ۱) نقطهٔ لمس → مختصات بوم، مستقل از عرض نمایش ══');
for (const w of [360, 414, 768, 1024, 1440]) {
  const c = mkCanvas(w), rect = c.getBoundingClientRect();
  const centre = getCanvasCoords(c, rect.left + rect.width / 2, rect.top + rect.height / 2);
  const corner = getCanvasCoords(c, rect.right - 0.001, rect.bottom - 0.001);
  const quarter = getCanvasCoords(c, rect.left + rect.width * 0.25, rect.top + rect.height * 0.75);
  const good = Math.abs(centre.x - 500) < 0.01 && Math.abs(centre.y - 280) < 0.01
    && Math.abs(corner.x - 1000) < 0.01 && Math.abs(corner.y - 560) < 0.01
    && Math.abs(quarter.x - 250) < 0.01 && Math.abs(quarter.y - 420) < 0.01;
  ok(`عرض ${w}px → مرکز (500,280) · گوشه (1000,560) · ربع (250,420)`, good,
    JSON.stringify({ centre, corner, quarter }));
}

console.log('\n══ ۲) نسبت نمایش درست حساب می‌شود ══');
ok('در 1000px نسبت ۱ است', Math.abs(displayScale(mkCanvas(1000)) - 1) < 1e-9, String(displayScale(mkCanvas(1000))));
ok('در 500px نسبت ۲ است', Math.abs(displayScale(mkCanvas(500)) - 2) < 1e-9, String(displayScale(mkCanvas(500))));
ok('در 360px نسبت ≈۲٫۷۸ است', Math.abs(displayScale(mkCanvas(360)) - 1000 / 360) < 1e-9, String(displayScale(mkCanvas(360))));
ok('بوم صفر‌عرضی به‌جای تقسیم بر صفر، ۱ برمی‌گرداند',
  displayScale({ width: 1000, getBoundingClientRect: () => ({ width: 0 }) }) === 1);

console.log('\n══ ۳) ضخامت قلم روی هر دستگاهی یکسان دیده می‌شود ══');
/* ضخامتی که کاربر انتخاب کرده به پیکسل CSS است؛ ضخامت بوم باید با نسبت جبران شود */
for (const w of [360, 414, 768, 1024, 1440]) {
  const bad = [3, 6, 12, 24].filter(picked => Math.abs(strokeWidth(w, picked) / displayScale(mkCanvas(w)) - picked) > 0.01);
  ok(`عرض ${w}px → ضخامت‌های ۳/۶/۱۲/۲۴ دقیقاً همان‌قدر دیده می‌شوند`, bad.length === 0, 'خطا در ' + bad.join(','));
}
ok('ضخامت هرگز از ۱ کمتر نمی‌شود (نامرئی نمی‌شود)', strokeWidth(4000, 0) >= 1, String(strokeWidth(4000, 0)));
console.log(`     · نمونه: قلم ۳px در عرض 360 → ضخامت بوم ${strokeWidth(360, 3).toFixed(2)}` +
  ` (چشم همان 3px می‌بیند) و در عرض 1440 → ${strokeWidth(1440, 3).toFixed(2)}`);

console.log('\n══ ۴) با width:100% و height:auto نسبت حفظ می‌شود ══');
for (const w of [360, 768, 1440]) {
  const c = mkCanvas(w), r = c.getBoundingClientRect();
  const sx = c.width / r.width, sy = c.height / r.height;
  ok(`عرض ${w}px → کشیدگی ندارد (scaleX==scaleY)`, Math.abs(sx - sy) < 1e-9, `${sx} vs ${sy}`);
}

console.log('\n══ ۵) نشانه‌گذاری: بوم دقیقاً داخل قاب، بدون زوم، بدون اسکرول ══');
/* کامنت‌ها را برمی‌داریم تا نام تابع‌های حذف‌شده در توضیحات، تست را فریب ندهد */
const htmlOf = src;
const code = htmlOf
  .replace(/\/\*[\s\S]*?\*\//g, ' ')
  .replace(/(^|[^:])\/\/[^\n]*/g, '$1 ');
const called = (fn) => new RegExp('\\b' + fn + '\\s*\\(').test(code);

ok('هیچ فراخوانی zoomWhiteboard نمانده', !called('zoomWhiteboard'));
ok('هیچ فراخوانی resetZoomWhiteboard نمانده', !called('resetZoomWhiteboard'));
ok('تابع zoomWhiteboard از فایل رفته', !/function\s+zoomWhiteboard/.test(code));
ok('تابع resetZoomWhiteboard از فایل رفته', !/function\s+resetZoomWhiteboard/.test(code));
ok('هیچ transform برای بوم نمانده', !/transform\s*:\s*scale/.test(code.slice(code.indexOf('getCanvasCoords'))));

/* تگ canvas تا اولین «>» واقعی — نه «?>» داخل PHP */
function canvasTag() {
  const i = code.indexOf('<canvas class="whiteboard-canvas');
  if (i < 0) return '';
  for (let j = i; j < code.length; j++) if (code[j] === '>' && code[j - 1] !== '?') return code.slice(i, j + 1);
  return '';
}
const cv = canvasTag();
ok('تگ بوم پیدا شد', cv.length > 0, String(cv.length));
ok('بوم width:100% دارد (به اندازهٔ قاب)', /width\s*:\s*100%/.test(cv), cv);
ok('بوم height:auto دارد (ارتفاع از نسبت می‌آید)', /height\s*:\s*auto/.test(cv), cv);
ok('بوم display:block دارد (فاصلهٔ اضافی زیرش نمی‌ماند)', /display\s*:\s*block/.test(cv), cv);
ok('قاب بوم overflow:hidden است (اسکرول ندارد)', /whiteboard-container[^>]*overflow\s*:\s*hidden/.test(code));
ok('قاب بوم width:100% دارد', /whiteboard-container[^>]*width\s*:\s*100%/.test(code));
ok('بوم ۱۰۰۰×۵۶۰ تعریف شده', /width="1000"/.test(cv) && /height="560"/.test(cv), cv);

console.log('\n══ ۶) نشانه‌گذاری: نقشهٔ سوالات روی دکمهٔ ارسال نمی‌افتد ══');
const r = await req('رندر برگهٔ آزمون', { method: 'GET', file: 'online-exam-take.php', query: `exam_id=${examId}` });
const page = r.res.page || '';
const iMap = page.indexOf('id="qMap"');
const iBtn = page.indexOf('onclick="submitExam()"');
ok('نقشهٔ سوالات رندر شد', iMap > 0, String(iMap));
ok('دکمهٔ ارسال رندر شد', iBtn > 0, String(iBtn));
ok('نقشه پیش از دکمهٔ ارسال می‌آید (پس رویش نمی‌افتد)', iMap > 0 && iBtn > 0 && iMap < iBtn, `map=${iMap} btn=${iBtn}`);
console.log(`     · جای نقشه: ${iMap} · جای دکمهٔ ارسال: ${iBtn}`);
ok('در موبایل نقشه از fixed خارج می‌شود', /\.q-map\s*\{[^}]*position\s*:\s*static/.test(page));
ok('قاعدهٔ موبایل داخل @media است', /@media\s*\(max-width:\s*768px\)/.test(page));

console.log('\n════════════════════════════════');
console.log(`  سوئیت تختهٔ سفید و چیدمان: ${pass} PASS / ${fail} FAIL`);
console.log('════════════════════════════════');
process.exit(fail ? 1 : 0);
