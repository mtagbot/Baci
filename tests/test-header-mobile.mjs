/**
 * test-header-mobile.mjs — سوئیت ۱۳ (v4.132.1)
 *
 * خواستهٔ کاربر: «برای موبایل و تبلت همان حالت قبلی Header را قرار بده.»
 *
 * این سوئیت با یک پارسر CSS ساده، قواعدِ *مؤثر* را در عرض‌های واقعی
 * دستگاه‌ها حساب می‌کند — نه اینکه فقط دنبال رشته بگردد. چون سؤال
 * اصلی این نیست که «آیا فلان قاعده نوشته شده؟» بلکه «در ۷۶۸ پیکسل
 * واقعاً چه چیزی رندر می‌شود؟»
 */
import { resolveFile } from './harness/site.mjs';
import { readFileSync } from 'node:fs';

let pass = 0, fail = 0;
const ok = (n, c, d = '') => c ? (pass++, console.log(`  ✅ ${n}`))
                               : (fail++, console.log(`  ❌ ${n}${d ? '  → ' + d : ''}`));

const css = readFileSync(resolveFile('assets/css/style.css'), 'utf8');

/* ─── پارسر کوچک: قواعد مؤثر برای یک عرض مشخص ───────────────────
   بلوک‌های @media را با عرض ارزیابی می‌کند و اعلان‌های داخلشان را،
   به ترتیب ظهور، روی نتیجه اعمال می‌کند (آخرین برنده). */
function declarationsFor(selector, viewport) {
    const result = {};
    /* بلوک‌های @media را جدا کن */
    const mediaRe = /@media([^{]+)\{/g;
    const segments = [];
    let idx = 0, m;
    while ((m = mediaRe.exec(css)) !== null) {
        segments.push({ kind: 'top', text: css.slice(idx, m.index) });
        /* انتهای بلوک @media را با شمارش آکولاد پیدا کن */
        let depth = 1, i = mediaRe.lastIndex;
        while (i < css.length && depth > 0) {
            if (css[i] === '{') depth++;
            else if (css[i] === '}') depth--;
            i++;
        }
        segments.push({ kind: 'media', cond: m[1].trim(), text: css.slice(mediaRe.lastIndex, i - 1) });
        idx = i;
        mediaRe.lastIndex = i;
    }
    segments.push({ kind: 'top', text: css.slice(idx) });

    const condMatches = (cond) => {
        if (/print/i.test(cond)) return false;
        let okAll = true;
        const maxes = [...cond.matchAll(/max-width:\s*(\d+)px/g)];
        const mins  = [...cond.matchAll(/min-width:\s*(\d+)px/g)];
        for (const x of maxes) if (!(viewport <= +x[1])) okAll = false;
        for (const x of mins)  if (!(viewport >= +x[1])) okAll = false;
        return okAll;
    };

    for (const seg of segments) {
        if (seg.kind === 'media' && !condMatches(seg.cond)) continue;
        /* قواعد داخل این قطعه */
        const ruleRe = /([^{}]+)\{([^{}]*)\}/g;
        let r;
        while ((r = ruleRe.exec(seg.text)) !== null) {
            const sels = r[1].split(',').map(s => s.trim());
            if (!sels.some(s => s === selector)) continue;
            for (const decl of r[2].split(';')) {
                const c = decl.indexOf(':');
                if (c === -1) continue;
                result[decl.slice(0, c).trim()] = decl.slice(c + 1).trim();
            }
        }
    }
    return result;
}

const isHidden = (sel, vw) => (declarationsFor(sel, vw).display || '').replace('!important', '').trim() === 'none';

console.log('\n══ کاشی‌ها در موبایل و تبلت پنهان‌اند ══');

/* عرض‌های واقعی دستگاه‌های رایج */
const mobileAndTablet = [
    [320, 'موبایل کوچک'],
    [360, 'اندروید رایج'],
    [390, 'آیفون'],
    [430, 'آیفون Max'],
    [600, 'تبلت کوچک عمودی'],
    [768, 'آیپد عمودی'],
    [820, 'آیپد Air عمودی'],
    [900, 'تبلت'],
    [1024, 'آیپد افقی'],
];
for (const [w, label] of mobileAndTablet) {
    ok(`${label} (${w}px): نوار کاشی پنهان است`, isHidden('.hdr-tiles', w));
}

console.log('\n══ در دسکتاپ همچنان کار می‌کند ══');
for (const [w, label] of [[1025, 'لپ‌تاپ کوچک'], [1280, 'لپ‌تاپ'], [1440, 'دسکتاپ'], [1920, 'مانیتور بزرگ']]) {
    ok(`${label} (${w}px): نوار کاشی نمایش داده می‌شود`, !isHidden('.hdr-tiles', w));
}

console.log('\n══ بازهٔ ۹۰۱ تا ۱۰۲۴ (باگی که رفع شد) ══');
/* خودِ پروژه در ≤1024px هدر را ستونی می‌کند (flex-direction:column).
   اگر کاشی‌ها تا 900px باقی می‌ماندند، در این بازه روی هدرِ ستونی
   می‌نشستند و ارتفاع نوار را زیاد می‌کردند. */
ok('پروژه در ≤۱۰۲۴px هدر را ستونی می‌کند (فرض این تست)',
   /@media \(max-width: 1024px\)[\s\S]{0,400}?header\.navbar[^}]*flex-direction:\s*column/.test(css));
for (const w of [901, 960, 1000, 1024]) {
    ok(`${w}px: کاشی‌ها روی هدرِ ستونی‌شده نمی‌نشینند`, isHidden('.hdr-tiles', w));
}

console.log('\n══ تاریخ «مورخ:» رفتار قبلی را دارد ══');
/* قبل از v4.132.0 این span هیچ قاعدهٔ CSS نداشت. پنهان‌کردنش فقط
   برای باز کردن جا برای کاشی‌ها بود، پس باید محدود به همان بازه بماند. */
for (const [w, label] of [[360, 'موبایل'], [768, 'آیپد عمودی'], [1024, 'آیپد افقی']]) {
    ok(`${label} (${w}px): تاریخ مثل قبل نمایش داده می‌شود`, !isHidden('.hdr-date', w));
}
ok('۱۱۰۰px (کاشی‌ها هستند): تاریخ برای باز شدن جا پنهان می‌شود', isHidden('.hdr-date', 1100));
ok('۱۹۲۰px: تاریخ نمایش داده می‌شود', !isHidden('.hdr-date', 1920));

console.log('\n══ هدر در موبایل با نسخهٔ قبل یکسان است ══');
const hdr = readFileSync(resolveFile('includes/header.php'), 'utf8');
/* تنها دو تفاوت با v2.62.0 مجاز است و هر دو در موبایل بی‌اثرند:
   ۱) فراخوانی render_header_tiles که خروجی‌اش با CSS پنهان می‌شود
   ۲) کلاس hdr-date که زیر ۱۰۲۴px هیچ قاعده‌ای نمی‌گیرد */
ok('هدر فقط یک نقطهٔ الحاق برای کاشی‌ها دارد',
   (hdr.match(/render_header_tiles/g) || []).length === 1);
ok('نوار کاشی بیرون از ساختار قبلی هدر قرار دارد (چیدمان قبلی دست‌نخورده)',
   /<\/div>\s*<\?php\s*\/\*[\s\S]*?render_header_tiles\(\);\s*\?>\s*<div class="flex items-center gap-4">/.test(hdr));
ok('هیچ عنصر دیگری از هدر تغییر نکرده',
   hdr.includes('hamburger-btn') && hdr.includes('user-menu-wrap') &&
   hdr.includes('sidebarToggleBtn') && hdr.includes('mtagToggleSidebar'));
/* کاشی‌ها نباید داخل کانتینرهایی باشند که موبایل wrap می‌کند */
ok('نوار کاشی فرزند مستقیم header است، نه داخل .navbar > .flex',
   !/<div class="flex items-center gap-3">[\s\S]*?hdr-tiles/.test(hdr));

console.log('\n══ سلامت CSS ══');
const tilesCss = css.slice(css.indexOf('v4.132.0 — منوی کاشی‌ای هدر'));
ok('آکولادهای بخش کاشی‌ها متوازن‌اند',
   (tilesCss.match(/\{/g) || []).length === (tilesCss.match(/\}/g) || []).length);
ok('در چاپ هم پنهان است', /@media print\{\s*\.hdr-tiles\{display:none!important\}/.test(css));
/* نباید دو قاعدهٔ متناقض برای همان بازه بماند */
const tileDisplayRules = [...css.matchAll(/max-width:\s*(\d+)px\)\s*\{\s*\.hdr-tiles\s*\{\s*display:\s*none/g)]
    .map(m => +m[1]);
ok('فقط یک نقطهٔ شکست برای پنهان‌سازی کاشی‌ها وجود دارد',
   tileDisplayRules.length === 1 && tileDisplayRules[0] === 1024,
   JSON.stringify(tileDisplayRules));

console.log(`\n  سوئیت هدر موبایل و تبلت: ${pass} PASS / ${fail} FAIL`);
process.exit(fail ? 1 : 0);
