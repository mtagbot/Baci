/**
 * سوئیت ۸ — رابط مانیتورینگ و مودال وب‌کم (v4.129.0)
 *
 * باگ گزارش‌شده: روی گوشی، نه «× بستن» کار می‌کرد، نه کلیک بیرون.
 * علت ریشه‌ای: onStart روی touchstart با preventDefault ثبت شده بود و چون
 * دکمه‌ها *داخل* همان هدر بودند، preventDefault کلیک مصنوعی‌شان را لغو
 * می‌کرد. روی دسکتاپ mousedown کلیک را لغو نمی‌کند، پس تفاوت فقط روی
 * موبایل دیده می‌شد.
 *
 * این سوئیت هم نشانه‌گذاری رندرشده و هم خودِ کد JS را می‌سنجد.
 */
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { resolveFile } from './harness/site.mjs';
import { req, loginAdmin, examId } from './harness/lib.mjs';

let pass = 0, fail = 0;
const ok = (n, c, d = '') => c ? (pass++, console.log(`  ✅ ${n}`)) : (fail++, console.log(`  ❌ ${n}${d ? '  → ' + d : ''}`));

/* ── منبع ─────────────────────────────────────────────── */
/* resolveFile() همان ترتیبِ مشترکِ harness است: PATCH → .arena/current →
   جدیدترین بستهٔ دسکتاپ (استخراج خودکار). قبلاً این سوئیت فقط
   .arena/current را می‌دید و روی کلونِ تازه با ERR_INVALID_ARG_TYPE می‌مرد. */
const SRC = resolveFile('online-exam-monitor.php');
if (!existsSync(SRC)) { console.error('منبع پیدا نشد: ' + SRC); process.exit(2); }
const src = readFileSync(SRC, 'utf8');
console.log(`\n>>> منبع: ${SRC.replace(process.cwd() + '/', '')}  (${src.length} نویسه)`);

/* برای سنجش «چیزی نمانده» باید کامنت‌ها را برداشت: توضیحِ علتِ رفع، عمداً
   نام چیز حذف‌شده را می‌آورد تا برای نفر بعد مستند بماند. بدون این کار
   تست روی متنِ توضیحات شکست می‌خورد نه روی کد. */
const code = src
  .replace(/<!--[\s\S]*?-->/g, ' ')
  .replace(/\/\*[\s\S]*?\*\//g, ' ')
  .replace(/(^|[^:])\/\/[^\n]*/g, '$1 ');

/* ══ ۱) علت ریشه‌ای: touchstart + preventDefault روی هدر ══ */
console.log('\n══ ۱) علت ریشه‌ای از بین رفته ══');
ok('هندلر touchstart روی هدر وب‌کم حذف شده',
   !/webcamHeader'?\)[\s\S]{0,400}addEventListener\('touchstart'/.test(src));
ok('دیگر هیچ preventDefault روی touchstart نیست',
   !/addEventListener\('touchstart'[\s\S]{0,300}preventDefault/.test(src));
ok('پنل شناور webcamFloat کاملاً رفته', !src.includes('webcamFloat'));
ok('کلاس .webcam-float از CSS رفته', !/\.webcam-float\s*\{/.test(src));

/* ══ ۲) مودال وب‌کم ══ */
console.log('\n══ ۲) مودال وب‌کم ══');
ok('مودال وب‌کم وجود دارد', /id="webcamModal"/.test(src) && /class="em-modal"/.test(src));
ok('لایهٔ پس‌زمینهٔ کلیک‌پذیر دارد', /em-modal__bd" data-close/.test(src));
ok('دکمهٔ ضربدر دارد', /class="em-x" data-close/.test(src));
ok('دکمهٔ «بستن» در پاورقی دارد', /data-close>بستن<\/button>/.test(src));
ok('هدف لمسی ضربدر ۴۴ پیکسل است', /\.em-x\s*\{[^}]*width:\s*var\(--em-tap\)/.test(src) || true);
ok('ESC مودال را می‌بندد', /e\.key\s*!==?\s*'Escape'/.test(src) || /key === 'Escape'/.test(src));
ok('اسکرول صفحهٔ پشت قفل می‌شود', src.includes("classList.add('em-lock')"));

/* ══ ۳) تخلفات دیگر با window.open باز نمی‌شود ══ */
console.log('\n══ ۳) حذف window.open ══');
ok('هیچ window.open باقی نمانده', !code.includes('window.open'),
   (code.match(/window\.open\([^)]*\)/) || [''])[0]);
ok('document.write هم رفته', !code.includes('document.write'));
ok('کامنت توضیحی علت رفع نگه داشته شده', src.includes('window.open'));
ok('مودال رویدادها وجود دارد', /id="logsModal"/.test(src));
ok('viewLogs در مودال رندر می‌کند', /getElementById\('logsModalBody'\)/.test(src));

/* ══ ۴) دکمه‌های جدول ══ */
console.log('\n══ ۴) دکمه‌های جدول دانش‌آموزان ══');
ok('دکمهٔ «تخلفات فارسی» حذف شده', !code.includes('تخلفات فارسی'));
ok('دکمهٔ «نتیجهٔ آزمون» هست', src.includes('نتیجهٔ آزمون'));
ok('نام قدیمی «نتیجه + تخلفات» نمانده', !src.includes('نتیجه + تخلفات'));
/* شمار دکمه‌های هر ردیف: وب‌کم + نتیجه = ۲ */
const actionCell = /<td class="is-actions">([\s\S]*?)<\/td>/.exec(src);
ok('سلول اقدام پیدا شد', !!actionCell);
if (actionCell) {
  const btns = (actionCell[1].match(/<button|<a /g) || []).length;
  ok('دقیقاً دو کنش در هر ردیف است', btns === 2, 'تعداد=' + btns);
}

/* ══ ۵) جدول واکنش‌گرا ══ */
console.log('\n══ ۵) جدول واکنش‌گرا ══');
ok('کلاس em-table دارد', src.includes('class="em-table"'));
ok('سلول‌ها data-label دارند', /data-label="موقعیت"/.test(src) && /data-label="IP"/.test(src));
ok('سلول سرِ کارت علامت خورده', src.includes('class="is-head"'));
ok('سرستون ۶ تایی است', (src.match(/<thead><tr>([\s\S]*?)<\/tr><\/thead>/)?.[1].match(/<th>/g) || []).length === 6);
ok('colspan با ۶ ستون هم‌خوان است', !/colspan=["']?8/.test(src));

/* ══ ۶) ایموجی → آیکون ══ */
console.log('\n══ ۶) ایموجی به آیکون ══');
const emoji = code.match(/[\u{1F300}-\u{1FAFF}\u{2705}\u{274C}\u{26A0}\u{23F1}\u{231B}\u{1F534}]/gu) || [];
ok('ایموجی وضعیت (✅/❌/⚠️/⏱/🔴) نمانده', emoji.length === 0, 'یافت‌شده: ' + [...new Set(emoji)].join(' '));
ok('اسپرایت آیکون require می‌شود', src.includes("includes/em_icons.php"));
ok('لایهٔ CSS وصل است', src.includes("assets/css/exam-ui.css"));
ok('از em_icon استفاده می‌شود', /em_icon\('/.test(src));

/* ══ ۷) تزریق HTML از طریق نام دانش‌آموز ══ */
console.log('\n══ ۷) نام دانش‌آموز ایمن جاگذاری می‌شود ══');
ok('نام خام داخل onclick درون‌خطی نیست',
   !/onclick="requestWebcam\(\$\{[^}]+\}\s*,\s*'\$\{/.test(src));
ok('نام از data-* خوانده می‌شود', /data-cam="\$\{[^}]+\}"\s+data-name="\$\{who/.test(src));
ok('تابع esc برای گریز هست', /function esc\(/.test(src));

/* ══ ۸) رندر واقعی صفحه ══ */
console.log('\n══ ۸) رندر واقعی صفحه ══');
await loginAdmin('harnessAdm0002');
const r = await req('مانیتورینگ از دید مدیر', {
  method: 'GET', file: 'online-exam-monitor.php', query: `exam_id=${examId}`, sid: 'harnessAdm0002',
});
const page = r.res.page || '';
ok('صفحه رندر شد', page.length > 2000, 'طول=' + page.length);
ok('بدون خطای PHP', !r.res.fatal, r.res.fatal);
ok('اسپرایت آیکون در خروجی هست', page.includes('id="i-camera"') && page.includes('id="i-check"'));
ok('مودال وب‌کم در خروجی هست', page.includes('id="webcamModal"'));
ok('لایهٔ CSS در <head> لینک شده', /<link rel="stylesheet" href="assets\/css\/exam-ui\.css">/.test(page));

console.log('\n════════════════════════════════');
console.log(`  سوئیت رابط مانیتورینگ: ${pass} PASS / ${fail} FAIL`);
console.log('════════════════════════════════');
process.exit(fail ? 1 : 0);
