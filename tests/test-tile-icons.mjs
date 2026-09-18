/**
 * test-tile-icons.mjs — سوئیت ۱۴ (v4.133.0)
 *
 * آیکون‌های خطی جایگزین ایموجی در کاشی‌های هدر.
 *
 * تمرکز این سوئیت روی سه چیز است:
 *   ۱) هیچ ایموجی‌ای در خروجی نماند
 *   ۲) sprite دقیقاً یک‌بار چاپ شود و همهٔ ارجاع‌ها معتبر باشند
 *   ۳) چیزی از CSS/SVG استفاده نشده باشد که مرورگر قدیمی نفهمد
 */
import { run, php } from './harness/lib.mjs';
import { resolveFile } from './harness/site.mjs';
import { readFileSync } from 'node:fs';

let pass = 0, fail = 0;
const ok = (n, c, d = '') => c ? (pass++, console.log(`  ✅ ${n}`))
                               : (fail++, console.log(`  ❌ ${n}${d ? '  → ' + d : ''}`));

const EMOJI = /[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}\u{FE0F}]/u;

const sprite  = readFileSync(resolveFile('includes/tile_icons.php'), 'utf8');
const tiles   = readFileSync(resolveFile('includes/header_tiles.php'), 'utf8');
const css     = readFileSync(resolveFile('assets/css/style.css'), 'utf8');
const setts   = readFileSync(resolveFile('settings.php'), 'utf8');

/* ═══ ۱) کاتالوگ و sprite هم‌خوان‌اند ═══ */
console.log('\n══ کاتالوگ و آیکون‌ها ══');

const catKeys = [...tiles.matchAll(/'(\w+)'\s*=>\s*\['t'\s*=>/g)].map(m => m[1]);
const icRefs  = [...tiles.matchAll(/'ic'\s*=>\s*'([\w-]+)'/g)].map(m => m[1]);
const symbols = [...sprite.matchAll(/<symbol id="t-([\w-]+)"/g)].map(m => m[1]);

ok('کاتالوگ ۱۷ کاشی دارد؛ سه ابزار زیر تنظیمات دیگر هستند', catKeys.length === 17, String(catKeys.length));
ok('هر ۱۷ کاشی آیکون دارد', icRefs.length === 17, String(icRefs.length));
ok('sprite ۲۰ آیکون دارد', symbols.length === 20, String(symbols.length));
ok('هیچ آیکونی در sprite گم نیست',
   icRefs.every(r => symbols.includes(r)),
   JSON.stringify(icRefs.filter(r => !symbols.includes(r))));
ok('فقط سه آیکون منتقل‌شده برای سازگاری در sprite باقی‌اند',
   JSON.stringify(symbols.filter(sy => !icRefs.includes(sy)).sort())===JSON.stringify(['admins','dbhealth','sync']),
   JSON.stringify(symbols.filter(sy => !icRefs.includes(sy))));
ok('شناسهٔ آیکون‌ها تکراری نیست', new Set(symbols).size === symbols.length);

/* ═══ ۲) کیفیت خودِ SVG ═══ */
console.log('\n══ کیفیت SVG ══');

const symbolBlocks = [...sprite.matchAll(/<symbol id="t-([\w-]+)" viewBox="([^"]+)">([\s\S]*?)<\/symbol>/g)];
ok('همهٔ آیکون‌ها viewBox یکسان ۲۴×۲۴ دارند',
   symbolBlocks.length === 20 && symbolBlocks.every(b => b[2].trim() === '0 0 24 24'),
   JSON.stringify(symbolBlocks.filter(b => b[2].trim() !== '0 0 24 24').map(b => b[1])));
/* رنگ نباید در خودِ آیکون قفل شود؛ باید از currentColor بیاید */
ok('هیچ رنگ ثابتی داخل آیکون‌ها نیست',
   !/(fill|stroke)="(?!none)[^"]+"/.test(sprite),
   (sprite.match(/(fill|stroke)="(?!none)[^"]+"/g) || []).join(' '));
/* افکت‌های سنگین یا ناسازگار با مرورگر قدیمی */
for (const bad of ['<filter', '<mask', 'Gradient', '<clipPath', '<foreignObject', '<style']) {
    ok(`آیکون‌ها از ${bad} استفاده نمی‌کنند`, !sprite.includes(bad));
}
/* فقط مختصات مطلق سنجیده می‌شود. در دستورهای کوچکِ SVG (l, m, c, ...)
   عدد منفی یعنی «جابه‌جایی به عقب» و کاملاً معتبر است — سنجیدن آن‌ها
   باعث هشدار کاذب می‌شد. */
ok('مختصات مطلق آیکون‌ها داخل کادر ۲۴×۲۴ است', (() => {
    const bad = [];
    for (const b of symbolBlocks) {
        for (const a of ['cx', 'cy', 'x', 'y', 'r', 'rx', 'ry', 'width', 'height']) {
            const re = new RegExp(a + '="(-?\\d+(?:\\.\\d+)?)"', 'g');
            let m;
            while ((m = re.exec(b[3])) !== null) {
                const v = parseFloat(m[1]);
                if (v < 0 || v > 24) bad.push(b[1] + ':' + a + '=' + v);
            }
        }
        /* مختصات مطلق در دستورهای M و L بزرگ */
        for (const seg of b[3].match(/[ML]\s*-?\d+(?:\.\d+)?\s+-?\d+(?:\.\d+)?/g) || []) {
            for (const n of seg.slice(1).trim().split(/[\s,]+/)) {
                const v = parseFloat(n);
                if (v < 0 || v > 24) bad.push(b[1] + ':' + seg.trim());
            }
        }
    }
    return bad.length === 0 || (console.log('     ', bad.join(' ')), false);
})());

/* ═══ ۳) سازگاری با مرورگر قدیمی ═══ */
console.log('\n══ سازگاری با مرورگرهای قدیمی ══');

ok('هر <use> هم href و هم xlink:href دارد',
   /href="#t-' \. \$k \. '" xlink:href="#t-' \. \$k \. '"/.test(sprite) ||
   (sprite.includes("'<use href=\"#t-'") && sprite.includes('xlink:href="#t-')));
ok('فضای‌نام xlink روی svg اعلام شده', sprite.includes('xmlns:xlink="http://www.w3.org/1999/xlink"'));
ok('sprite فضای‌نام svg دارد', sprite.includes('xmlns="http://www.w3.org/2000/svg"'));

/* CSS: هر چیزی که مرورگر قدیمی نفهمد باید یا پشتیبان داشته باشد یا نباشد */
const tileCss = css.slice(css.indexOf('v4.133.0 — منوی کاشی‌ای هدر'),
                          css.indexOf('/* چیدمان کاشی‌ها در صفحهٔ سفارشی‌سازی */'));
/* هر استفاده از color-mix در کل فایل باید داخل یک بلوک @supports
   باشد. اگر بیرون باشد، مرورگر قدیمی کل آن اعلان را دور می‌ریزد و
   عنصر بی‌رنگ می‌ماند — دقیقاً چیزی که در v4.132.0 روی .tile-opt.is-on
   رخ داده بود و همین تست پیدایش کرد. */
ok('هیچ color-mix بیرون از @supports نمانده', (() => {
    const bad = [];
    /* کامنت‌ها حذف شوند تا متنِ توضیحات با کد اشتباه گرفته نشود */
    const clean = css.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));
    const lines = clean.split('\n');
    let depth = 0, inSupports = false, supDepth = 0;
    for (let i = 0; i < lines.length; i++) {
        const L = lines[i];
        if (/@supports[^{]*color-mix/.test(L)) { inSupports = true; supDepth = depth; }
        if (L.includes('color-mix') && !/@supports/.test(L)) {
            if (!inSupports) bad.push((i + 1) + ': ' + L.trim().slice(0, 60));
        }
        depth += (L.match(/\{/g) || []).length;
        depth -= (L.match(/\}/g) || []).length;
        if (inSupports && depth <= supDepth) inSupports = false;
    }
    return bad.length === 0 || (console.log('     ', bad.join(' | ')), false);
})());
ok('برای هاور و فعال، رنگ ثابتِ پشتیبان وجود دارد',
   /\.hdr-tile:hover\{[^}]*background:#[0-9a-f]{3,6}/i.test(tileCss) &&
   /\.hdr-tile\.is-active\{[^}]*background:#[0-9a-f]{3,6}/i.test(tileCss));
ok('transition با پیشوند -webkit هم آمده', tileCss.includes('-webkit-transition'));
ok('flex با پیشوند قدیمی هم آمده', tileCss.includes('-ms-flex-align') || tileCss.includes('-webkit-box-align'));
ok('نشانگر کاشی فعال از transform استفاده نمی‌کند (margin به‌جایش)',
   /is-active::after\{[^}]*margin-left:-7px/.test(tileCss.replace(/\s+/g, '')) ||
   /margin-left:-7px/.test(tileCss));
for (const heavy of ['backdrop-filter', 'filter:blur', 'mix-blend-mode', 'clip-path', 'mask-image']) {
    ok(`از ${heavy} استفاده نشده (سبک ماندن)`, !tileCss.includes(heavy));
}
ok('انیمیشن بی‌پایان (keyframes) روی کاشی‌ها نیست', !/@keyframes[^}]*hdr-tile/.test(css));
ok('تنظیم «کاهش حرکت» سیستم رعایت شده', tileCss.includes('prefers-reduced-motion'));

/* ═══ ۴) رندر واقعی ═══ */
console.log('\n══ رندر واقعی ══');

php.writeFile('/harness/ti.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
@mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess');
session_id('ti'.mt_rand()); session_start();
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/auth.php';
require_once '/www/includes/header_tiles.php';
set_setting('header_tiles','dashboard,students,attendance,reports,online,messages,backups,settings');
set_setting('header_tiles_enabled','1');
$a = DB::fetch("SELECT * FROM admins WHERE status=1 ORDER BY id LIMIT 1");
$_SESSION = ['admin_id'=>$a['id'],'admin_role'=>'super_admin'];
$_SERVER['PHP_SELF'] = '/students.php';
ob_start(); render_header_tiles(); $h = ob_get_clean();

/* هر کلیدی که <use> به آن ارجاع می‌دهد باید در همین خروجی symbol داشته باشد */
preg_match_all('/<use href="#t-([\\\\w-]+)"/', $h, $u);
preg_match_all('/<symbol id="t-([\\\\w-]+)"/', $h, $sy);
$missing = array_values(array_diff(array_unique($u[1]), $sy[1]));

echo json_encode([
  'tiles'       => substr_count($h,'<a class="hdr-tile'),
  'svgs'        => substr_count($h,'class="hdr-tile-ic"'),
  'xlink'       => substr_count($h,'xlink:href'),
  'sprite_once' => substr_count($h,'id="t-dashboard"'),
  'missing'     => $missing,
  'emoji'       => preg_match('/[\\\\x{1F300}-\\\\x{1FAFF}\\\\x{2600}-\\\\x{27BF}]/u',$h) ?1:0,
  'active'      => substr_count($h,'is-active'),
  'aria_current'=> substr_count($h,'aria-current="page"'),
  'aria_hidden' => substr_count($h,'aria-hidden="true"'),
  'has_title'   => substr_count($h,'title="'),
]);`);
const r = JSON.parse((await run("<?php require '/harness/ti.php';")).out.trim());
ok('۸ کاشی رندر شد', r.tiles === 8, String(r.tiles));
ok('هر ۸ کاشی آیکون SVG دارد', r.svgs === 8, String(r.svgs));
ok('هر آیکون xlink پشتیبان دارد', r.xlink === 8, String(r.xlink));
ok('sprite فقط یک‌بار چاپ می‌شود', r.sprite_once === 1, String(r.sprite_once));
ok('هیچ ارجاع شکسته‌ای به آیکون نیست', Array.isArray(r.missing) && r.missing.length === 0, JSON.stringify(r.missing));
ok('هیچ ایموجی‌ای در خروجی نمانده', r.emoji === 0);
ok('کاشی صفحهٔ جاری فعال است', r.active === 1, String(r.active));
ok('کاشی فعال aria-current دارد', r.aria_current === 1);
ok('آیکون‌ها برای صفحه‌خوان پنهان‌اند', r.aria_hidden >= 8, String(r.aria_hidden));
ok('هر کاشی title راهنما دارد', r.has_title === 8, String(r.has_title));

/* صفحهٔ سفارشی‌سازی هم باید همان آیکون‌ها را نشان دهد */
console.log('\n══ صفحهٔ سفارشی‌سازی ══');
ok('فهرست انتخاب، آیکون SVG نشان می‌دهد', setts.includes("tile_icon($tile['ic']"));
ok('پیش‌نمایش زنده هم SVG می‌سازد نه ایموجی',
   setts.includes('createElementNS') && setts.includes("'#t-' + ic"));
ok('پیش‌نمایش زنده xlink پشتیبان می‌گذارد', setts.includes('setAttributeNS'));
ok('دیگر از data-icon ایموجی استفاده نمی‌شود', !setts.includes('dataset.icon'));

/* ═══ ۵) ایموجی به‌عنوان پشتیبان حفظ شده ═══ */
console.log('\n══ پشتیبان ══');
ok("کلید 'i' (ایموجی) برای پشتیبانی حذف نشده",
   (tiles.match(/'i'\s*=>/g) || []).length === 17);
ok('اگر آیکون نبود به ایموجی برمی‌گردد', /else\s*\{?\s*echo \$tile\['i'\]/.test(tiles));

console.log(`\n  سوئیت آیکون کاشی‌ها: ${pass} PASS / ${fail} FAIL`);
process.exit(fail ? 1 : 0);
