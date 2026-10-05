// v4.178.0 — شمارهٔ نسخهٔ کد + بهداشت مخزن (۵ اصلاح نشست ۱۰-۰۵)
// v4.179.0 — بج نسخه بنا به درخواست کاربر از فوتر حذف شد.
//
// چه چیزی اینجا «اجرا» می‌شود، نه ادعا:
//   ۱) config/version.php واقعاً با PHP خوانده می‌شود و شماره‌ها را برمی‌گرداند
//      (metadata نصب — همچنان بخشی از هویت Release_V1.1 است).
//   ۲) فوتر سایت در یک درخواست واقعی صفحه، دیگر شمارهٔ نسخه را رندر نمی‌کند.
//   ۳) با distribution=desktop هم چیزی چاپ نمی‌شود.
//   ۴) اگر config/version.php نباشد، فوتر بدون خطا رندر می‌شود.
//   ۵) بستهٔ اصلاحی config/release.php را شامل نمی‌شود (آن فایل درایور بانک را
//      انتخاب می‌کند؛ بازنویسی‌اش با بستهٔ اصلاحی یعنی ریسک عوض‌شدن MySQL↔SQLite).
//   ۶) test-bot-outbox.mjs در run-tests.sh ثبت است و دو آرایه هم‌اندازه‌اند.
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { execFileSync } from 'node:child_process';
import { REPO } from './harness/site.mjs';
import { php, run, req, loginAdmin } from './harness/lib.mjs';

let n = 0, skipped = 0;
const check = (v, m) => { assert(v, m); n++; };
const skip = (m) => { console.log('  ⏭  skip: ' + m); skipped++; };

const SITE_VERSION = '4.178.0';
const DESKTOP_VERSION = '2.101.0';

/* ═══════ ۱) فایل نسخه واقعاً با PHP خوانده می‌شود ═══════ */
const meta = await run(String.raw`<?php
$p = '/www/config/version.php';
if (!is_file($p)) { echo 'MISSING'; exit; }
$v = require $p;
echo 'site=' . ($v['site_version'] ?? '-') . ';';
echo 'desk=' . ($v['desktop_version'] ?? '-') . ';';
echo 'corr=' . ($v['correction'] ?? '-') . ';';
echo 'keys=' . implode(',', array_keys((array)$v)) . ';';`);
check(meta.out.includes('site=' + SITE_VERSION),
  'config/version.php reports the site version ' + SITE_VERSION + ': ' + meta.out.trim());
check(meta.out.includes('desk=' + DESKTOP_VERSION),
  'config/version.php reports the desktop version ' + DESKTOP_VERSION);
check(meta.out.includes('corr=V4.178.0'), 'the correction label is recorded');

/* فایل نسخه نباید distribution داشته باشد — وگرنه همان ریسک release.php را دارد.
   این بررسی روی «آرایهٔ برگشتیِ واقعی» انجام می‌شود، نه روی متن فایل؛ وگرنه
   واژهٔ distribution که در توضیحِ همان فایل آمده هم مطابقت می‌کرد. */
const keys = (meta.out.match(/keys=([^;]*);/) || [, ''])[1].split(',').filter(Boolean);
check(!keys.includes('distribution'),
  'the returned array has NO distribution key (it must never select the DB driver); keys=' + keys.join(','));
check(keys.length === 3 && keys.includes('site_version') && keys.includes('desktop_version'),
  'the stamp carries exactly the three version keys: ' + keys.join(','));

/* ═══════ ۲) فوتر سایت در یک درخواست واقعی ═══════ */
const originalRelease = php.readFileAsText('/www/config/release.php');
php.writeFile('/www/config/release.php',
  "<?php\nreturn ['release'=>'Release_V1.0','distribution'=>'site','site_version'=>'4.152.0','desktop_version'=>'2.83.0'];\n");

await loginAdmin('verstamp01');
const sitePage = await req('فوتر سایت', { file: 'other-settings.php', sid: 'verstamp01' });
check(!sitePage.res.fatal, 'the page renders with no fatal error: ' + (sitePage.res.fatal || ''));
check(!sitePage.res.page.includes('school-footer-version'),
  'the version badge element is gone from the footer (removed in v4.179.0)');
check(!sitePage.res.page.includes('نسخهٔ ' + SITE_VERSION),
  'the SITE version is NOT printed in the footer any more: ' + SITE_VERSION);
check(sitePage.res.page.includes('school-footer'), 'the footer itself still renders');
check(sitePage.res.page.includes('school-footer-mark'), 'the restored footer mark survives the badge removal');

/* ═══════ ۳) همان فوتر، توزیع دسکتاپ ═══════ */
php.writeFile('/www/config/release.php',
  "<?php\nreturn ['release'=>'Release_V1.0','distribution'=>'desktop','site_version'=>'4.152.0','desktop_version'=>'2.83.0'];\n");
const deskPage = await req('فوتر دسکتاپ', { file: 'other-settings.php', sid: 'verstamp01' });
check(!deskPage.res.fatal, 'the desktop-distribution page renders: ' + (deskPage.res.fatal || ''));
check(!deskPage.res.page.includes('نسخهٔ ' + DESKTOP_VERSION),
  'a desktop install no longer prints the DESKTOP version ' + DESKTOP_VERSION);
check(!deskPage.res.page.includes('school-footer-version'), 'no badge on the desktop footer either');
check(!deskPage.res.page.includes('نسخهٔ ' + SITE_VERSION),
  'a desktop install must not show the site version');
php.writeFile('/www/config/release.php', originalRelease);

/* ═══════ ۴) نبودِ فایل نسخه نباید صفحه را بشکند ═══════ */
const versionBackup = php.readFileAsText('/www/config/version.php');
php.unlink('/www/config/version.php');
const noVer = await req('فوتر بدون فایل نسخه', { file: 'other-settings.php', sid: 'verstamp01' });
check(!noVer.res.fatal, 'the footer survives a missing config/version.php: ' + (noVer.res.fatal || ''));
check(!noVer.res.page.includes('school-footer-version'), 'no badge with the file absent (now true either way)');
check(noVer.res.page.includes('school-footer'), 'the footer itself still renders');
php.writeFile('/www/config/version.php', versionBackup);

/* ═══════ ۵) محتوای بسته‌های اصلاحی ═══════ */
const zipList = (name) => {
  const p = join(REPO, name);
  if (!existsSync(p)) return null;
  return execFileSync('unzip', ['-Z1', p], { encoding: 'utf8' }).split('\n').filter(Boolean);
};

for (const [name, prefix, wanted] of [
  ['MODIFIED-FILES-V4.178.0.zip', 'site-update-v4.152.0/', ['config/version.php', 'includes/footer.php']],
  ['SchoolDesk-FIX-v2.101.0.zip', 'SchoolDeskPro/www/', ['config/version.php', 'includes/footer.php']],
]) {
  const list = zipList(name);
  if (!list) { skip(name + ' ساخته نشده — بسته‌بندی هنوز اجرا نشده'); continue; }
  for (const w of wanted) {
    check(list.includes(prefix + w), name + ' ships ' + prefix + w);
  }
  check(!list.includes(prefix + 'config/release.php'),
    name + ' must NOT ship config/release.php (it selects the DB driver)');
}

/* ═══════ ۶) بهداشت مخزن — چهار اصلاح دیگر ═══════ */
const runner = readFileSync(join(REPO, 'scripts/run-tests.sh'), 'utf8');
check(runner.includes('node test-bot-outbox.mjs'),
  'test-bot-outbox.mjs is registered in scripts/run-tests.sh');
const names = (runner.match(/NAMES=\(([\s\S]*?)\)\n/) || [, ''])[1].match(/"[^"]+"/g) || [];
const cmds = (runner.match(/CMDS=\(([\s\S]*?)\)\n/) || [, ''])[1].match(/"node [^"]+"/g) || [];
check(names.length === cmds.length && names.length > 0,
  'the NAMES and CMDS arrays stay parallel (' + names.length + ' vs ' + cmds.length + ')');
for (const c of cmds) {
  const f = c.replace(/^"node /, '').replace(/"$/, '');
  check(existsSync(join(REPO, 'tests', f)), 'every registered suite exists: ' + f);
}

check(!existsSync(join(REPO, 'school-report')), 'the 1-byte stray file school-report is gone');
check(existsSync(join(REPO, 'BACI-RESTORE-PRE-LIVE-EXAM-DESIGNER.zip')),
  'the pre-live-exam-designer restore bundle from arena/01a0c9d7-baci is now merged');

const readme = readFileSync(join(REPO, 'README.md'), 'utf8');
check(!readme.includes('arena/01a0a1d9-baci'), 'README no longer points downloads at the stale 01a0a1d9 branch');
check(readme.includes('Release_V1.1'), 'README advertises Release_V1.1');

console.log('PASS ' + n + ' version-stamp and repo-hygiene checks' + (skipped ? ' (' + skipped + ' skipped)' : ''));
process.exit(0);
