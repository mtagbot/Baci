/**
 * test-footer-brand.mjs — سوئیت ۵۳ (v4.179.0)
 *
 * دو چیز را نگهبانی می‌کند:
 *
 * ۱) طراحی فوتر که در کامیت 1ad7148 از دست رفته بود (فوتر از ۷۳۷۲ به ۳۴۸۰
 *    بایت کوچک شد) در v4.179.0 برگردانده شد: کادر آیکون SVG مدرسه و پیوند
 *    «بازگشت به محتوا». v4.178.0 همان نسخهٔ کوچک‌شده را بسته‌بندی کرده بود،
 *    پس نصبش فوتر را به حالت قبلی برمی‌گرداند.
 *
 * ۲) سه عنصری که بنا به درخواست کاربر نباید در فوتر باشند:
 *      · زیرعنوان «آموزش، ارزشیابی و ارتباطات مدرسه»
 *      · بج «نسخهٔ …» (config/version.php همچنان metadata نصب است، فقط رندر نمی‌شود)
 *      · دکمهٔ شناور «بازگشت به بالا» (در assets/js/ui-modern.js ساخته می‌شد)
 *
 * هر دو توزیع سنجیده می‌شود، چون فایل یکی است و با config/release.php رفتار
 * عوض می‌کند. قاعدهٔ پروژه: «تستی که نتواند شکست بخورد، نگهبان نیست.»
 */
import { php, req, loginAdmin } from './harness/lib.mjs';
import { resolveFile } from './harness/site.mjs';
import { readFileSync } from 'node:fs';

let pass = 0, fail = 0;
const ok = (n, c, d = '') => c ? (pass++, console.log(`  ✅ ${n}`))
                               : (fail++, console.log(`  ❌ ${n}${d ? '  → ' + d : ''}`));

const RELEASE = '/www/config/release.php';
const SID = 'footerAdmin0001';   // req() پیش‌فرض نشست دانش‌آموز دارد؛ مدیر باید صریح باشد
const SVG_PATHS = 'm3 9 9-6 9 6v12H3Z';                 // آیکون مدرسه
const SUBTITLE = 'آموزش، ارزشیابی و ارتباطات مدرسه';    // نباید باشد
const BACKTOP = 'بازگشت به بالا';                       // نباید باشد
const BACKCONTENT = 'بازگشت به محتوا';                  // باید باشد

/* نسخهٔ واقعیِ روی دیسک — برای اثباتِ نبودنِ بج در خروجی */
const versionSrc = readFileSync(resolveFile('config/version.php'), 'utf8');
const siteVersion = (versionSrc.match(/'site_version'\s*=>\s*'([^']+)'/) || [])[1];
const deskVersion = (versionSrc.match(/'desktop_version'\s*=>\s*'([^']+)'/) || [])[1];
console.log(`>>> نسخهٔ خوانده‌شده: سایت ${siteVersion} · دسکتاپ ${deskVersion}`);

let savedRelease = null;
try { savedRelease = php.readFileAsText(RELEASE); } catch (e) { savedRelease = null; }

/* ═══════════════ الف) حالت سایت ═══════════════ */
console.log('\n══ فوتر در حالت سایت ══');
php.writeFile(RELEASE, "<?php return ['distribution'=>'site'];");

await loginAdmin(SID);
const site = (await req('صفحهٔ اصلی با ورود مدیر', { file: 'index.php', sid: SID })).res.page;

ok('صفحه رندر شد', site.length > 2000, `${site.length} بایت`);
ok('بدون خطای PHP', !/Fatal error|Parse error/.test(site));
ok('فوتر رندر می‌شود', site.includes('school-footer'));
ok('کادر آیکون فوتر رندر می‌شود', site.includes('class="school-footer-mark"'));
ok('آیکون SVG مدرسه داخل فوتر است', site.includes(SVG_PATHS));
ok('نام مدرسه در فوتر هست', /school-footer-brand[\s\S]{0,600}?<strong>/.test(site));
ok('پیوند «بازگشت به محتوا» هست', site.includes(BACKCONTENT));
ok('لنگر #main-content واقعاً در صفحه وجود دارد', site.includes('id="main-content"'));
ok('اعتبارنامهٔ «معاونت فناوری» سر جایش است', site.includes('طراحی و توسعه : معاونت فناوری متوسطه اول'));
ok('«نشست‌های من» برای مدیر واردشده هست', site.includes('نشست‌های من'));

console.log('\n── سه عنصری که نباید باشند (سایت) ──');
ok('زیرعنوان حذف شده است', !site.includes(SUBTITLE));
ok('بج نسخه حذف شده است', !site.includes('school-footer-version'));
ok('شمارهٔ نسخهٔ سایت در صفحه چاپ نمی‌شود', !site.includes(`نسخهٔ ${siteVersion}`));
ok('دکمهٔ «بازگشت به بالا» در صفحه نیست', !site.includes(BACKTOP) && !site.includes('ui-backtop'));

ok('روی سایت پنل اتصال دسکتاپ چاپ نمی‌شود', !site.includes('id="deskConnection"'));
ok('روی سایت desk-connection.js بارگذاری نمی‌شود', !site.includes('desk-connection.js'));

/* ═══════════════ ب) حالت دسکتاپ ═══════════════ */
console.log('\n══ فوتر در حالت دسکتاپ ══');
php.writeFile(RELEASE, "<?php return ['distribution'=>'desktop'];");
const desk = (await req('همان صفحه با توزیع دسکتاپ', { file: 'index.php', sid: SID })).res.page;

ok('صفحهٔ دسکتاپ رندر شد', desk.length > 2000, `${desk.length} بایت`);
ok('آیکون SVG در دسکتاپ هم هست', desk.includes(SVG_PATHS) && desk.includes('class="school-footer-mark"'));
ok('کلاس has-desk-state روی فوتر می‌نشیند', desk.includes('school-footer has-desk-state'));
ok('پنل اتصال و همگام‌سازی رندر می‌شود', desk.includes('id="deskConnection"'));
ok('مانیتور desk-connection.js فعال است', desk.includes('desk-connection.js'));
ok('زیرعنوان در دسکتاپ هم نیست', !desk.includes(SUBTITLE));
ok('بج نسخه در دسکتاپ هم نیست', !desk.includes('school-footer-version'));
ok('شمارهٔ نسخهٔ دسکتاپ چاپ نمی‌شود', !desk.includes(`نسخهٔ ${deskVersion}`));
ok('دکمهٔ «بازگشت به بالا» در دسکتاپ هم نیست', !desk.includes(BACKTOP) && !desk.includes('ui-backtop'));

/* تیک قدیمی همگام‌سازی در 1ad7148 بازنشسته شد و endpoint آن
   (`desk-sync.php?ajax=tick`) دیگر وجود ندارد. همان نگهبان در
   test-fast-ui.mjs:130 هم هست. */
ok('تیک قدیمی همگام‌سازی برنگشته است', !desk.includes('ajax=tick'));
ok('بنر قدیمی sdpSyncBanner برنگشته است', !desk.includes('sdpSyncBanner'));

/* ═══════════════ ج) منبع روی دیسک ═══════════════ */
console.log('\n══ منبع فوتر و ui-modern.js ══');
const src = readFileSync(resolveFile('includes/footer.php'), 'utf8');
ok('فوتر منبع دیگر config/version.php را نمی‌خواند', !src.includes('config/version.php'),
   'بج حذف شده، پس خواندنش هم بی‌فایده است');
ok('فوتر منبع زیرعنوان ندارد', !src.includes(SUBTITLE));
ok('فوتر منبع release.php را پشت is_file() می‌خواند',
   src.includes("is_file(dirname(__DIR__).'/config/release.php')?require dirname(__DIR__).'/config/release.php'"));
ok('فوتر منبع تیک قدیمی همگام‌سازی را ندارد', !src.includes('ajax=tick') && !src.includes('sdpSyncBanner'));

/* محتوای ui-modern.js عوض شد، پس رشتهٔ کش‌شکنِ ?v= هم باید عوض شود؛
   وگرنه مرورگر همان نسخهٔ کش‌شدهٔ قدیمی را سرو می‌کند و دکمهٔ حذف‌شده
   سر جایش می‌ماند. */
const ver = (src.match(/assets\/js\/ui-modern\.js\?v=([0-9a-z]+)/) || [])[1] || '';
ok('رشتهٔ کش‌شکن ui-modern.js جلو رفته است', ver !== '' && ver !== '20260917e',
   'v=20260917e همان نسخهٔ کش‌شده‌ای است که initBackTop داشت');

const js = readFileSync(resolveFile('assets/js/ui-modern.js'), 'utf8');
ok('ui-modern.js دیگر initBackTop ندارد', !js.includes('initBackTop'));
ok('ui-modern.js دیگر کلاس ui-backtop نمی‌سازد', !js.includes('ui-backtop'));
ok('ui-modern.js دیگر عنوان «بازگشت به بالا» ندارد', !js.includes(BACKTOP));
ok('ui-modern.js هنوز DOMContentLoaded و ensureBar را دارد',
   js.includes('DOMContentLoaded') && js.includes('ensureBar()'),
   'حذف دکمه نباید بقیهٔ راه‌اندازی را ببرد');

if (savedRelease !== null) php.writeFile(RELEASE, savedRelease);

console.log(`\n>>> PASS ${pass} · FAIL ${fail}`);
process.exit(fail === 0 ? 0 : 1);
