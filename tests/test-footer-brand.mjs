/**
 * test-footer-brand.mjs — سوئیت ۵۳ (v4.179.0)
 *
 * نگهبانِ طراحی فوتر. در کامیت 1ad7148 فوتر از ۷۳۷۲ بایت به ۳۴۸۰ بایت
 * کوچک شد و آیکون SVG مدرسه، زیرعنوان و پیوند «بازگشت به محتوا» از آن
 * حذف گردید؛ v4.178.0 همان نسخهٔ کوچک‌شده را بسته‌بندی کرد، پس نصب آن
 * روی سایتی که طراحی تازه داشت، فوتر را به حالت قبلی برمی‌گرداند.
 * این سوئیت همان رگرسیون را می‌گیرد.
 *
 * هر دو توزیع سنجیده می‌شود، چون فایل یکی است و با config/release.php
 * رفتار عوض می‌کند:
 *   · سایت   — آیکون/زیرعنوان/پیوندها هست، پنل اتصال و همگام‌سازی نیست
 *   · دسکتاپ — همان‌ها هست، به‌علاوهٔ پنل اتصال و اسکریپت همگام‌سازی خودکار
 *
 * قاعدهٔ پروژه: «تستی که نتواند شکست بخورد، نگهبان نیست.»
 */
import { php, req, loginAdmin } from './harness/lib.mjs';
import { resolveFile } from './harness/site.mjs';
import { readFileSync } from 'node:fs';

let pass = 0, fail = 0;
const ok = (n, c, d = '') => c ? (pass++, console.log(`  ✅ ${n}`))
                               : (fail++, console.log(`  ❌ ${n}${d ? '  → ' + d : ''}`));

const RELEASE = '/www/config/release.php';
const SID = 'footerAdmin0001';   // req() پیش‌فرض نشست دانش‌آموز دارد؛ مدیر باید صریح باشد
const SVG_PATHS = 'm3 9 9-6 9 6v12H3Z';              // آیکون مدرسه
const SUBTITLE = 'آموزش، ارزشیابی و ارتباطات مدرسه';
const BACKLINK = 'بازگشت به محتوا';

/* نسخهٔ واقعیِ روی دیسک — نه عدد سخت‌کدشده */
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
ok('کادر آیکون فوتر رندر می‌شود', site.includes('class="school-footer-mark"'));
ok('آیکون SVG مدرسه داخل فوتر است', site.includes(SVG_PATHS));
ok('زیرعنوان فوتر برگشته است', site.includes(SUBTITLE));
ok('پیوند «بازگشت به محتوا» هست', site.includes(BACKLINK));
ok('لنگر #main-content واقعاً در صفحه وجود دارد', site.includes('id="main-content"'));
ok('اعتبارنامهٔ «معاونت فناوری» سر جایش است', site.includes('طراحی و توسعه : معاونت فناوری متوسطه اول'));
ok('«نشست‌های من» برای مدیر واردشده هست', site.includes('نشست‌های من'));
ok('بج نسخه چاپ می‌شود',
   site.includes('school-footer-version') && site.includes(`نسخهٔ ${siteVersion}`),
   siteVersion ? `انتظار «نسخهٔ ${siteVersion}»` : 'site_version در config/version.php پیدا نشد');

ok('روی سایت پنل اتصال دسکتاپ چاپ نمی‌شود', !site.includes('id="deskConnection"'));
ok('روی سایت پنل اتصال چاپ نمی‌شود (دوباره)', !site.includes('desk-connection.js'));

/* رگرسیون اصلی: برندِ بدون آیکون */
const bStart = site.indexOf('<div class="school-footer-brand">');
const bEnd = site.indexOf('<div class="school-footer-credit">', bStart);
const brandBlock = bStart >= 0 && bEnd > bStart ? site.slice(bStart, bEnd) : '';
ok('برند فوتر دیگر «فقط نام» نیست',
   brandBlock.includes('school-footer-mark') && brandBlock.includes(SUBTITLE),
   brandBlock ? brandBlock.slice(0, 160) : 'بلوک برند در خروجی پیدا نشد');

/* ═══════════════ ب) حالت دسکتاپ ═══════════════ */
console.log('\n══ فوتر در حالت دسکتاپ ══');
php.writeFile(RELEASE, "<?php return ['distribution'=>'desktop'];");
const desk = (await req('همان صفحه با توزیع دسکتاپ', { file: 'index.php', sid: SID })).res.page;

ok('صفحهٔ دسکتاپ رندر شد', desk.length > 2000, `${desk.length} بایت`);
ok('آیکون SVG در دسکتاپ هم هست', desk.includes(SVG_PATHS) && desk.includes('class="school-footer-mark"'));
ok('زیرعنوان در دسکتاپ هم هست', desk.includes(SUBTITLE));
ok('کلاس has-desk-state روی فوتر می‌نشیند', desk.includes('school-footer has-desk-state'));
ok('پنل اتصال و همگام‌سازی رندر می‌شود', desk.includes('id="deskConnection"'));
ok('desk-connection.js بارگذاری می‌شود', desk.includes('desk-connection.js'));
/* تیک قدیمی در 1ad7148 عمداً بازنشسته شد: endpoint آن (`desk-sync.php?ajax=tick`)
   دیگر وجود ندارد و همگام‌سازی را مانیتور desk-connection.js + سرویس پس‌زمینه
   انجام می‌دهند. برگرداندن طراحی فوتر نباید آن کد مرده را هم برگرداند.
   همان نگهبان در test-fast-ui.mjs:130 هم هست. */
ok('تیک قدیمی همگام‌سازی برنگشته است', !desk.includes('ajax=tick'),
   'desk-sync.php دیگر ajax=tick ندارد؛ این کد مرده است');
ok('بنر قدیمی sdpSyncBanner برنگشته است', !desk.includes('sdpSyncBanner'));
ok('مانیتور تازه desk-connection.js در دسکتاپ فعال است', desk.includes('desk-connection.js'));
ok('بج نسخه در دسکتاپ شمارهٔ دسکتاپ را نشان می‌دهد', desk.includes(`نسخهٔ ${deskVersion}`),
   deskVersion ? `انتظار «نسخهٔ ${deskVersion}»` : 'desktop_version در config/version.php پیدا نشد');

/* ═══════════════ ج) منبع روی دیسک ═══════════════ */
console.log('\n══ منبع فوتر ══');
const src = readFileSync(resolveFile('includes/footer.php'), 'utf8');
ok('فوتر منبع دیگر تیک قدیمی را ندارد', !src.includes('ajax=tick') && !src.includes('sdpSyncBanner'));
ok('هر require از release.php پشت is_file() محافظت شده',
   src.includes("is_file(dirname(__DIR__).'/config/release.php')?require dirname(__DIR__).'/config/release.php'")
   && !/(^|[^\)\?])\brequire\s+dirname\(__DIR__\)\.'\/config\/release\.php'/.test(src),
   'require بی‌قید release.php روی نصبی که آن فایل را ندارد fatal می‌دهد');
ok('فوتر منبع release.php را با is_file() می‌سنجد',
   /is_file\(dirname\(__DIR__\)\.'\/config\/release\.php'\)/.test(src));

if (savedRelease !== null) php.writeFile(RELEASE, savedRelease);

console.log(`\n>>> PASS ${pass} · FAIL ${fail}`);
process.exit(fail === 0 ? 0 : 1);
