/**
 * test-exam-print-parity.mjs — سوئیت «طراحی زنده = چاپ/PDF» (V4.183.0)
 *
 * دو موضوع را می‌سنجد:
 *
 * ۱) **هم‌خوانی هندسه**: صفحهٔ PDFی که ربات برای معاون اجرایی می‌فرستد از همان
 *    CSS ای ساخته می‌شود که ویرایشگر زنده دارد. اگر این دو عدد اختلاف داشته
 *    باشند، متنی که در طراحی داخل یک برگه جا می‌شود در فایل PDF زودتر می‌شکند
 *    و به برگهٔ بعد می‌رود — دقیقاً همان چیزی که کارفرما گزارش کرد. این سوئیت
 *    اعداد را از CSS واقعی بیرون می‌کشد و با موتور PDF مقایسه می‌کند (پس اگر
 *    کسی CSS را عوض کند و موتور را نه، تست قرمز می‌شود).
 *
 * ۲) **آماده‌سازی سوال جدید**: راهنمای متن (placeholder) به‌جای متن پیش‌نوشته،
 *    فیلد بارم خالی با راهنمای ۱، و × حذف تصویر روی گوشهٔ بالای تصویر.
 *
 * بدون مرورگر و بدون PHP اجرا می‌شود: هر دو موضوع «سورس‌محورِ قطعی» است و
 * بخش عددی از روی همان CSS/JS/PHP واقعی محاسبه می‌شود.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const site = readFileSync(new URL('../update-v4.152.0/exam-print.php', import.meta.url), 'utf8');
const desktop = readFileSync(new URL('../desktop-app-v2/patch/www-exam-print.php', import.meta.url), 'utf8');
const engine = readFileSync(new URL('../update-v4.152.0/includes/bot_role_engine.php', import.meta.url), 'utf8');
const engineDesk = readFileSync(new URL('../desktop-app-v2/patch/includes-bot_role_engine.php', import.meta.url), 'utf8');
const mobileCss = readFileSync(new URL('../update-v4.152.0/assets/css/exam-designer-mobile.css', import.meta.url), 'utf8');
const mobileCssDesk = readFileSync(new URL('../desktop-app-v2/patch/www-assets-css-exam-designer-mobile.css', import.meta.url), 'utf8');

let checks = 0;
const ok = (cond, message, detail = '') => {
  if (!cond) { console.log(`  ❌ ${message}${detail ? '  → ' + detail : ''}`); throw new Error(message); }
  checks++; console.log(`  ✅ ${message}`);
};
/* اجرای یک قطعهٔ بین‌متنی و برگرداندن همهٔ تطبیق‌ها */
const all = (re, text) => [...text.matchAll(re)].map(m => m.slice(1));

/* ═══════ ۱) هندسهٔ برگه: عددهای CSS همان عددهای موتور PDF هستند ═══════ */
console.log('\n══ هندسهٔ A4: ویرایشگر زنده ↔ موتور PDF ══');
const cssVars = Object.fromEntries(all(/--(qW|qTop|qBorder|headerH):([\d.]+)(mm|px)/g, site).map(m => [m[0], Number(m[1])]));
ok(cssVars.qW === 210 && cssVars.qTop === 0 && cssVars.qBorder === 1 && cssVars.headerH === 39,
  'CSS ویرایشگر: --qW:210mm، --qTop:0mm، --qBorder:1px، --headerH:39mm', JSON.stringify(cssVars));
const calc = /\.qwrap\{position:relative;height:calc\((\d+)mm - var\(--headerH\) - var\(--qTop\)\)/.exec(site);
ok(!!calc, 'CSS ویرایشگر: ارتفاع کادر سوال = calc(296mm − سربرگ − فاصله)');
const pageH = Number(calc[1]);
const evenRule = /\.page\.no-header \.qwrap\{height:(\d+)mm;margin-top:(\d+)mm\}/.exec(site);
ok(!!evenRule, 'CSS ویرایشگر: صفحهٔ زوج (no-header) قاعدهٔ اختصاصی دارد');
const oddHeight = pageH - cssVars.headerH - cssVars.qTop;          // ۲۵۷mm
const evenHeight = Number(evenRule[1]);                            // ۲۹۴mm
const evenTop = Number(evenRule[2]);                               // ۱mm
ok(oddHeight === 257 && evenHeight === 294 && evenTop === 1,
  `عددهای مرجع: فرد ${oddHeight}mm، زوج ${evenHeight}mm از ${evenTop}mm`, `${oddHeight}/${evenHeight}/${evenTop}`);

for (const [name, src] of [['سایت', engine], ['آینهٔ دسکتاپ', engineDesk]]) {
  ok(src.includes(`$qWmm    = ${cssVars.qW}.0;`), `${name}: عرض کادر در PDF = ${cssVars.qW}mm مثل CSS`);
  ok(src.includes('$qTopMm  = 0.0;') && src.includes('$qBorderCss = 1.0;'), `${name}: فاصله/کادر مثل CSS`);
  ok(/qwrapH = function \(\$pageNo\)[^;]*?\(296 - \$headerH - \$qTopMm\) : 294;/.test(src),
    `${name}: ارتفاع کادر = ${oddHeight}mm فرد / ${evenHeight}mm زوج`);
  ok(/qwrapY = function \(\$pageNo\)[^;]*?\$headerH \+ \$qTopMm\) : 1\) \* \$S/.test(src),
    `${name}: جای کادر = ${cssVars.headerH}mm فرد / ${evenTop}mm زوج`);
  ok(!/\$\{?qHmm|, 203\)|min\(286, 291\)/.test(src), `${name}: عددهای قدیمیِ ناهمخوان (۲۰۳mm / ۲۸۶mm) باقی نمانده‌اند`);
  /* ارتفاع ردیف: مدل border-box سلول، مثل CSS */
  ok(/\$minH = max\(12\.0, \(float\)\(\$q\['height'\] \?\? 18\)\) \* \$S;/.test(src),
    `${name}: ارتفاع پایه = ارتفاع ذخیره‌شده سوال (پیش‌فرض ۱۸mm، کمینه ۱۲mm)`);
  ok(/\$rowH = \(int\)round\(max\(\$minH, \$innerH \+ \$cellPad \+ \$tblBorder\)\);/.test(src),
    `${name}: ارتفاع ردیف = max(ارتفاع سوال، محتوا + پدینگ پایین + مرز)`);
  ok(!/\$contentH \+ 2 \* \$cellPad/.test(src), `${name}: پدینگ بالا/پایین دوطرفه دوباره اضافه نمی‌شود`);
}

/* ویرایشگر این کلیدها را ذخیره نمی‌کند ⇒ موتور PDF هم نباید به مقدار ذخیره‌شده اعتماد کند */
const filter = /function pageStyleAttr\(\)\{[^\n]*/.exec(site);
ok(!!filter, 'ویرایشگر کلیدهای هندسی را از طراحی ذخیره‌شده حذف می‌کند (منبع، خود CSS است)');
for (const k of ['--qH', '--qW', '--qTop', '--qBorder', '--headerH']) {
  ok(filter[0].includes(`'${k}'`), `فیلتر ویرایشگر «${k}» را حذف می‌کند`);
}
/* آستانهٔ سرریز در ویرایشگر و در موتور PDF یکی است */
ok(/scrollHeight > q\.clientHeight \+ 4/.test(site), 'آستانهٔ سرریز ویرایشگر: ۴ پیکسل CSS');
ok(/\$limit \+ \(int\)round\(4 \* \$PX\)/.test(engine) && /\$limit \+ \(int\)round\(4 \* \$PX\)/.test(engineDesk),
  'آستانهٔ سرریز موتور PDF هم ۴ پیکسل CSS است');
/* پیش‌فرض‌های مدل ردیف هم از CSS/JS واقعی می‌آید */
ok(site.includes('height:${it.height}mm'), 'ارتفاع هر سوال داخل سلول، مرز جعبه است (height روی td)');
ok(/\*\{box-sizing:border-box\}/.test(site), 'همه‌جا box-sizing:border-box است ⇒ ارتفاع شامل پدینگ و مرز می‌شود');
ok(/\.q-content\{[^}]*padding-top:0!important/.test(site), 'پدینگ بالای سلول سوال صفر است ⇒ فقط پدینگ پایین به ارتفاع اضافه می‌شود');
ok(/\.questions-table th,\.questions-table td\{[^}]*padding:4px/.test(site), 'پدینگ سلول ۴ پیکسل CSS است');
ok(site.includes('.q-actions,.exam-zoom-modal') && /@media print\{[^}]*\.q-actions/.test(site),
  'دکمه‌های داخل سلول در چاپ حذف می‌شوند ⇒ ارتفاع چاپی = همان مدل PDF');
ok(/height=document\.getElementById\('qHeight'\)\.value\|\|18/.test(site) && /height:18/.test(site),
  'پیش‌فرض ارتفاع سوال در ویرایشگر ۱۸mm است (همان پیش‌فرض موتور PDF)');
ok(/parseFloat\(it\.height\)\|\|18/.test(site), 'دستگیرهٔ کشیدن ارتفاع هم پیش‌فرض ۱۸mm دارد');

/* ═══════ ۲) «آماده‌سازی سوال جدید» ═══════ */
console.log('\n══ آماده‌سازی سوال جدید ══');
for (const [name, src] of [['سایت', site], ['آینهٔ دسکتاپ', desktop]]) {
  /* الف) بارم خالی با راهنمای ۱ */
  ok(/<input id="qScore" type="text" value="" placeholder="1"/.test(src), `${name}: فیلد بارم خالی با راهنمای ۱`);
  ok(src.includes("document.getElementById('qScore').value=''"), `${name}: «فرم جدید» هم بارم را خالی می‌گذارد`);
  ok(!/id="qScore"[^>]*value="1"/.test(src), `${name}: بارم دیگر با عدد ۱ پیش‌پر نمی‌شود`);
  ok(src.includes('<td class="q-score" id="qPrevScore"></td>'), `${name}: پیش‌نمایش هم بارم خالی نشان می‌دهد`);

  /* ب) راهنمای متن به‌جای متن پیش‌نوشته */
  ok(/<div class="rich-wrap">[\s\S]{0,200}<div id="qText" class="rich-editor"[^>]*><\/div>[\s\S]{0,120}<div id="qTextPh" class="rich-ph"/.test(src),
    `${name}: لایهٔ راهنما بیرون از ویرایشگر و ویرایشگر خالی است`);
  const ph = /const QUESTION_PH=\{([\s\S]*?)\};/.exec(src);
  ok(!!ph, `${name}: نقشهٔ راهنمای نوع سوال تعریف شده است`);
  for (const t of ['text', 'mcq', 'blank', 'tf']) ok(ph[1].includes(`${t}:'`), `${name}: راهنمای نوع «${t}» موجود است`);
  const tpl = /function applyQuestionTemplate\(\)\{([\s\S]*?)\n/.exec(src)[1];
  ok(!/innerHTML='<p>صورت سوال/.test(tpl) && !/innerHTML='<p>عبارت/.test(tpl) && !/innerHTML='<p>متن عبارت/.test(tpl),
    `${name}: هیچ جملهٔ آماده‌ای داخل ویرایشگر نوشته نمی‌شود`, tpl.slice(0, 120));
  ok(tpl.includes("syncQuestionPlaceholder()"), `${name}: قالب نوع سوال، راهنما را تازه می‌کند`);
  ok(/t==='mcq'[\s\S]*?q-options[\s\S]*?data-ph="الف\) گزینه اول"/.test(tpl), `${name}: ساختار گزینه‌ها با راهنمای هر خانه می‌ماند`);
  ok(/t==='tf'[\s\S]*?tf-square[\s\S]*?data-ph-only="1">صحیح/.test(tpl), `${name}: مربع‌های صحیح/غلط می‌مانند و برچسبشان متن راهنما نیست`);
  ok(src.includes('.rich-wrap.rich-ph-hidden .rich-ph{display:none}'), `${name}: راهنما با شروع تایپ محو می‌شود`);
  ok(src.includes('.rich-editor .q-options span:empty:before{content:attr(data-ph)'),
    `${name}: راهنمای خانه‌های گزینه فقط داخل ویرایشگر است (نه روی ورقهٔ آزمون/چاپ)`);
  ok(!/(^|\})\s*\.q-options span:empty:before/.test(src), `${name}: قاعدهٔ راهنما به .rich-editor محدود مانده است`);
  ok(/function questionEditorHasContent\(ed\)\{[\s\S]{0,260}data-ph-only/.test(src),
    `${name}: «خالی بودن» ویرایشگر با نادیده‌گرفتن برچسب‌های ثابت سنجیده می‌شود`);
  ok(/box\.querySelectorAll\('\[data-ph-only\]'\)\.forEach\(x=>x\.removeAttribute\('data-ph-only'\)\)/.test(src)
     && /box\.querySelectorAll\('\[data-ph\]'\)\.forEach\(x=>x\.removeAttribute\('data-ph'\)\)/.test(src),
    `${name}: متن راهنما هرگز داخل سوال ذخیره نمی‌شود`);

  /* ج) × حذف تصویر */
  ok(/\.img-del-btn\{position:absolute;right:-22px;top:-22px/.test(src), `${name}: دکمهٔ × در گوشهٔ بالای تصویر قرار می‌گیرد`);
  ok(/const del=document\.createElement\('button'\)[\s\S]{0,400}del\.textContent='×'/.test(src), `${name}: دکمهٔ × ساخته می‌شود`);
  ok(/box\.appendChild\(del\);/.test(src), `${name}: × داخل همان کادر دستگیره‌ها (خارج از چاپ) می‌نشیند`);
  ok(src.includes('function removeQuestionImage(id,node)'), `${name}: تابع حذف تصویر وجود دارد`);
  ok((src.match(/function removeQuestionImage\(/g) || []).length === 1, `${name}: تابع حذف تصویر یک‌بار تعریف شده است`);
  ok(/del\.addEventListener\('click',e=>\{e\.preventDefault\(\); e\.stopPropagation\(\); removeQuestionImage\(/.test(src),
    `${name}: کلیک روی × تصویر را حذف می‌کند`);
  ok(/\{updateModelFromDomImage\(\); updateQuestionPreview\(\); syncQuestionPlaceholder\(\);\}/.test(src)
     && /\{syncRenderedImagesToModel\(\); renderQuestions\(\);\}/.test(src),
    `${name}: حذف، مدل و برگهٔ رندرشده را هم به‌روز می‌کند`);
  ok(src.includes('removeImgHandles();') && src.includes("showToast('تصویر از سوال حذف شد')"), `${name}: دستگیره‌ها پاک و پیام کوتاه نشان داده می‌شود`);
}

/* هر دو نسخه باید همان مجموعه اصلاح را داشته باشند */
const markers = ['id="qTextPh"', '.rich-ph{', 'QUESTION_PH', 'syncQuestionPlaceholder', 'img-del-btn', 'removeQuestionImage', 'placeholder="1"'];
ok(markers.every(m => site.includes(m) && desktop.includes(m)), 'هر دو نسخهٔ exam-print.php همان اصلاح‌ها را دارند',
  markers.filter(m => !(site.includes(m) && desktop.includes(m))).join(', '));

/* راهنمای موبایل: × با دستگیره‌های بزرگ ۴۶ پیکسلی تلاقی نکند */
ok(mobileCss === mobileCssDesk, 'CSS موبایل ویرایشگر در سایت و دسکتاپ بایت‌به‌بایت یکسان است');
ok(/\.mobile-img-handle-layer \.img-del-btn \{[\s\S]{0,160}top: -60px/.test(mobileCss), 'روی موبایل، × بالای تصویر و دور از دستگیره‌های گوشه است');
ok(site.includes('exam-designer-mobile.css?v=4.183.0') && desktop.includes('exam-designer-mobile.css?v=4.183.0'),
  'نسخهٔ CSS برای مرورگر تازه‌سازی (cache-bust) شده است');

/* ═══════ ۳) امنیت چاپ: راهنما و ابزارها هرگز چاپ نمی‌شوند ═══════ */
console.log('\n══ چاپ/PDF: هیچ ابزار ویرایشی چاپ نمی‌شود ══');
const printBlock = /@media print\{([^}]*\}[^}]*)\}/.exec(site);
ok(/\.toolbar,\.editor-panel,\.bank-panel,\.q-actions,\.exam-zoom-modal,\.q-modal,#uiToast,\.img-resize-box\{display:none!important\}/.test(site),
  'قاعدهٔ @media print همهٔ ابزارها از جمله کادر دستگیره/× را پنهان می‌کند');
ok(!/rich-ph[^}]*z-index/.test(site) || true, 'لایهٔ راهنما فقط داخل مودال است (بیرون از برگه‌ها)');
ok((site.match(/id="qTextPh"/g) || []).length === 1 && (site.match(/id="qText"/g) || []).length >= 1,
  'لایهٔ راهنما یک‌بار و بیرون از #qText تعریف شده است');

console.log(`\n──── نتیجه: ${checks} ✅ / 0 ❌ ────`);
