/**
 * test-v4.209.0-performance.mjs — سوئیت «همهٔ صفحه‌ها سریع» (V4.209.0 / v2.133.0)
 *
 * ادامهٔ trace واقعی V4.208.0. آن نسخه ۲۱۰۰ms را به ۶۶۱ms رساند؛ چیزهایی که
 * باقی مانده بود و این نسخه هدف گرفته است:
 *
 *   · یک درخواست CSS مستقل برای هر صفحه (student-workflow.css = ۱۴۵ms)
 *   · یک <script> درون‌خطی ۱۶ کیلوبایتی که تجزیهٔ HTML را متوقف می‌کرد
 *   · فونت‌ها با هر بازدید دوباره داوری می‌شدند (بدون Cache-Control)
 *   · TTF با type=application/octet-stream سرو می‌شد ⇒ preload بی‌اثر و
 *     دانلود دوبارهٔ فونت
 *   · نبودِ AddType/Deflate در .htaccess پیش‌فرض
 *
 * قواعد این سوئیت:
 *   ۱) هیچ چیزِ رفتاری عوض نشده باشد (فقط لایهٔ اجرا).
 *   ۲) اگر perf.php روی نصب نباشد، perf_fallback.php باید *همهٔ* نمادها را
 *      داشته باشد؛ این همان باگ «Call to undefined function perf_bundle_url()»
 *      بود که کل صفحه را سفید می‌کرد.
 */
import { run, php, loginAdmin } from './harness/lib.mjs';
import { REPO } from './harness/site.mjs';
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';

let pass = 0, fail = 0;
const ok = (name, cond, detail = '') => cond
  ? (pass++, console.log(`  ✅ ${name}`))
  : (fail++, console.log(`  ❌ ${name}${detail ? '  → ' + detail : ''}`));
const read = rel => readFileSync(join(REPO, rel), 'utf8');
const has = rel => existsSync(join(REPO, rel));

/* ═════════════════════════════════════════════════ ۰) فایل‌های این نسخه ══ */
console.log('\n══ ۰) فایل‌های این نسخه ══');
const FILES = {
  'includes/perf.php': 'update-v4.209.0/includes/perf.php',
  'includes/perf_fallback.php': 'update-v4.209.0/includes/perf_fallback.php',
  'includes/db.php': 'update-v4.209.0/includes/db.php',
  'includes/header.php': 'update-v4.209.0/includes/header.php',
  'includes/footer.php': 'update-v4.209.0/includes/footer.php',
  'includes/appearance.php': 'update-v4.209.0/includes/appearance.php',
  'includes/bot_helpers.php': 'update-v4.209.0/includes/bot_helpers.php',
  'students.php': 'update-v4.209.0/students.php',
  'exam-bank-import.php': 'update-v4.209.0/exam-bank-import.php',
  'report-print.php': 'update-v4.209.0/report-print.php',
  'bulk-print.php': 'update-v4.209.0/bulk-print.php',
  'attendance-scanner.php': 'update-v4.209.0/attendance-scanner.php',
  '.htaccess': 'release-v1.0/common/.htaccess',
  'assets/fonts/.htaccess': 'update-v4.209.0/assets/fonts/.htaccess',
  'assets/css/.htaccess': 'update-v4.209.0/assets/css/.htaccess',
  'assets/js/.htaccess': 'update-v4.209.0/assets/js/.htaccess',
  'config/version.php': 'update-v4.209.0/config/version.php',
};
const missing = [];
for (const [name, src] of Object.entries(FILES)) {
  if (!has(src)) missing.push(src);
}
ok('۱۶ فایل این نسخه در مخزن موجود است', missing.length === 0, missing.join(', '));
ok('نسخهٔ ۴.۲۰۹.x / ۲.۱۳۳.x ثبت شده',
  /'site_version'\s*=>\s*'4\.209\.\d+'/.test(read(FILES['config/version.php'])) &&
  /'desktop_version'\s*=>\s*'2\.133\.\d+'/.test(read(FILES['config/version.php'])) &&
  /'correction'\s*=>\s*'V4\.209\.\d+'/.test(read(FILES['config/version.php'])));

/* ═════════════════════════════════════════ ۱) تناسب perf.php با fallback ══ */
console.log('\n══ ۱) پوشش کامل fallback (باگ صفحهٔ سفید) ══');
const perfSrc = read(FILES['includes/perf.php']);
const fbSrc = read(FILES['includes/perf_fallback.php']);
const symbols = src => [...src.matchAll(/function\s+(perf_[a-z0-9_]+)\s*\(/g)].map(m => m[1]);
const perfFns = [...new Set(symbols(perfSrc))];
const fbFns = new Set(symbols(fbSrc));
const notStubbed = perfFns.filter(fn => !fbFns.has(fn));
ok(`هر ${perfFns.length} نماد perf_* در fallback هم هست`, notStubbed.length === 0, notStubbed.join(', '));
ok('نمادهای تازهٔ V4.209.0 در هر دو فایل‌اند',
  ['perf_bundle_url', 'perf_css_base_files', 'perf_css_app_files', 'perf_js_core_files',
   'perf_inline_js', 'perf_screen_font_map', 'perf_css_fix_font_urls',
   'perf_css_drop_empty_faces', 'perf_cache_web_headers']
    .every(fn => perfFns.includes(fn) && fbFns.has(fn)));
ok('fallback برای perf_bundle_url رشتهٔ خالی می‌دهد (یعنی مسیر قبلی)',
  /function perf_bundle_url\([^)]*\)\s*\{\s*return '';\s*\}/.test(fbSrc));
ok('fallback برای perf_inline_js همان <script> درون‌خطی را می‌دهد',
  /function perf_inline_js\([^)]*\)\s*\{\s*return '<script>'.*<\/script>';/.test(fbSrc));
ok('fallback هیچ‌وقت exception پرت نمی‌کند', !/throw new/.test(fbSrc));

/* ═══════════════════════════════════════ ۲) قرارداد توابع تازهٔ باندل ══ */
console.log('\n══ ۲) قرارداد باندل و کش ══');
ok('نسخهٔ هسته به ۴.۲۰۹.x رسیده (نام باندل‌ها تازه می‌شود)',
  /define\('PERF_CORE_VERSION', '4\.209\.\d+'\)/.test(perfSrc));
ok('perf_bundle_url امضای ?v= و تکرارها را پاک می‌کند',
  perfSrc.includes("preg_replace('/[?#].*$/', '', $f)") && perfSrc.includes('$clean[$f] = true;'));
ok('perf_bundle_url در نبود perf_asset_url رشتهٔ خالی می‌دهد',
  perfSrc.includes("if (!function_exists('perf_asset_url')) return '';"));
ok('perf_inline_js زیر آستانه، درون‌خطی می‌ماند و بالای آن هش‌دار می‌شود',
  perfSrc.includes('strlen($code) < (int)$minBytes') && perfSrc.includes("'assets/cache/js-i' . $hash . '.js'"));
ok('فایل درون‌خطی هم کش یک‌ساله می‌گیرد (perf_cache_web_headers)',
  perfSrc.includes('function perf_cache_web_headers') && perfSrc.includes('perf_cache_web_headers($dir)'));
ok('هدر CSS/JS پوشهٔ کش با immutable تنظیم می‌شود',
  /Header set Cache-Control .*max-age=31536000, immutable/.test(perfSrc));
ok('فونت ناموجود حذف می‌شود، نه اینکه ۴۰۴ بخورد',
  perfSrc.includes('function perf_css_fix_font_urls') && perfSrc.includes('function perf_css_drop_empty_faces'));
ok('اصلاح فونت با callback انجام می‌شود (نه Closure در preg_replace)',
  perfSrc.includes('preg_replace_callback') && !/preg_replace\(\s*\/[^\/]*\/[a-z]*\s*,\s*function/.test(perfSrc));

/* ═══════════════════════════════════════════ ۳) هدر: یک درخواست کمتر ══ */
console.log('\n══ ۳) هدر و باندل صفحه‌ای ══');
const headerSrc = read(FILES['includes/header.php']);
ok('هدر از perf_bundle_url + فهرست‌های استاندارد استفاده می‌کند',
  headerSrc.includes('perf_bundle_url(perf_css_base_files(), \'css\')') &&
  headerSrc.includes('perf_bundle_url(perf_css_app_files($perfPageCss), \'css\')') &&
  headerSrc.includes("perf_bundle_url(perf_js_core_files(), 'js')"));
ok('استایل اختصاصی صفحه ($pageCss) داخل باندل برنامه می‌رود',
  headerSrc.includes('$perfPageCss[] = $__sheet;') &&
  headerSrc.includes("if (!empty($pageCss) && $perfCssApp === '')"));
ok('session-watch از باندل جدا شد (کش مشترک پنل و چاپ)',
  headerSrc.includes('$perfJsWatch') && !headerSrc.includes("'assets/js/session-watch.js'];"));
ok('مسیر «بدون باندل» دست‌نخورده مانده (سایت هرگز نمی‌شکند)',
  headerSrc.includes('<link rel="stylesheet" href="assets/css/style.css">'));

const deskHeader = read('desktop-app-v2/patch/includes-header.php');
ok('هدر دسکتاپ هم همان سه باندل را می‌سازد',
  deskHeader.includes('perf_bundle_url(perf_css_base_files(), \'css\')') &&
  deskHeader.includes('perf_bundle_url(perf_css_app_files($perfPageCss), \'css\')') &&
  deskHeader.includes("perf_bundle_url(perf_js_core_files(), 'js')"));
ok('هدر دسکتاپ session-watch را جدا و defer می‌گذارد',
  deskHeader.includes('$perfJsWatch') && deskHeader.includes('clean($perfJsWatch)'));
ok('محافظ پنجرهٔ دسکتاپ (window.open) دست‌نخورده مانده',
  deskHeader.includes('window.open = function (u)'));
ok('فهرست فونت جایگزین دسکتاپ حفظ شده',
  deskHeader.includes('Vazirmatn, Sahel, Yekan, Tahoma'));

/* ═════════════════════════════════════ ۴) .htaccess: کش و فشرده‌سازی ══ */
console.log('\n══ ۴) .htaccess — کش، MIME، فشرده‌سازی ══');
const rootHt = read(FILES['.htaccess']);
const fontHt = read(FILES['assets/fonts/.htaccess']);
const cssHt = read(FILES['assets/css/.htaccess']);
const jsHt = read(FILES['assets/js/.htaccess']);
ok('ریشه: نوع MIME فونت‌ها اعلام شده (پایان octet-stream)',
  rootHt.includes('AddType font/woff2') && rootHt.includes('AddType font/ttf'));
ok('ریشه: فشرده‌سازی متن‌ها روشن است',
  /mod_deflate/.test(rootHt) && rootHt.includes('application/javascript'));
ok('ریشه: درخواست دارایی ناموجود به ۴۰۴ می‌رود، نه به PHP',
  /RewriteRule \^\(assets\|uploads\)\/ - \[R=404,L\]/.test(rootHt));
/* V4.209.1 — `Options` و `Require` بدون محافظ حذف شدند (روی بعضی هاست‌ها
   ۵۰۰ می‌دهند)؛ هم‌ارزشان با IfModule هست و قواعد امنیتی سرجایش است. */
ok('ریشه: قواعد امنیتی حفظ شده و دستورهای پرخطر محافظت‌دارند',
  rootHt.includes('Require all denied') &&
  /<IfModule mod_authz_core\.c>[\s\S]{0,80}Require all denied/.test(rootHt) &&
  !/^\s*Options\s/m.test(rootHt) &&
  rootHt.includes('RewriteRule ^(?:config|includes|sql|vendor|backups)'));
ok('فونت: کش ۳۰ روزه با نوع MIME درست', /max-age=2592000/.test(fontHt) && fontHt.includes('AddType font/woff2'));
ok('CSS/JS: کش یک‌ساعته (اصلاحیه همان روز اعمال می‌شود)', /max-age=3600/.test(cssHt) && /max-age=3600/.test(jsHt));
ok('هر سه .htaccess با IfModule محافظت شده‌اند (روی سرور بدون ماژول خطا نمی‌دهند)',
  [fontHt, cssHt, jsHt].every(t => t.includes('<IfModule') && (t.match(/<IfModule/g) || []).length === (t.match(/<\/IfModule>/g) || []).length));

/* ═══════════════════════════════════════════ ۵) اجرای واقعی صفحه با PHP ══ */
console.log('\n══ ۵) اجرای واقعی students.php (PHP 8.3 WASM) ══');
await loginAdmin();

const render = async (file, query = '') => {
  const code = `<?php
@mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess'); session_name('BACI_TEST');
session_id('harnessAdm0001'); session_start();
$_SESSION['admin_id']=1; $_SESSION['admin_role']='super_admin';
$_SERVER['REQUEST_METHOD']='GET'; $_SERVER['PHP_SELF']='/www/${file}'; $_SERVER['SCRIPT_NAME']='/${file}';
$_SERVER['HTTP_HOST']='harness.local'; $_SERVER['HTTP_USER_AGENT']='perf-suite'; $_SERVER['REQUEST_URI']='/${file}';
parse_str(${JSON.stringify(query)}, $_GET); $_REQUEST = array_merge($_REQUEST, $_GET);
chdir('/www'); ob_start();
try { include '/www/${file}'; } catch (Throwable $e) { echo "\\n<!--TB:".$e->getMessage()."-->"; }
$html = ob_get_clean();
$out = ['len'=>strlen($html),'fatal'=>(strpos($html,'<!--TB:')!==false)];
preg_match_all('#<link[^>]+href="(assets/[^"\\s]+)"#', $html, $m);
$out['css'] = array_values(array_unique($m[1]));
preg_match_all('#<script[^>]+src="(assets/[^"\\s]+)"#', $html, $js);
$out['js'] = array_values(array_unique($js[1]));
preg_match_all('#<script(?![^>]*\\bsrc=)[^>]*>(.*?)</script>#s', $html, $im);
$out['inline_max'] = 0; foreach ($im[1] as $b) { $out['inline_max'] = max($out['inline_max'], strlen($b)); }
/* بعضی صفحات چاپ بافر خودشان را باز می‌گذارند یا با exit تمام می‌شوند؛
   پس نتیجه در فایل نوشته می‌شود (مستقل از بافر و exit). */
$GLOBALS['__suite_out'] = $out;
register_shutdown_function(function () {
    @file_put_contents('/harness/suite-render.json', json_encode($GLOBALS['__suite_out']));
});
`;
  await run(`<?php @unlink('/harness/suite-render.json'); echo 'x';`);
  const res = await run(code);
  let txt = '';
  try { txt = php.readFileAsText('/harness/suite-render.json'); } catch (e) { txt = ''; }
  if (!txt) return { fatal: true, len: 0, css: [], js: [], inline_max: 0, raw: String(res.out || '').slice(-200) };
  return JSON.parse(txt);
};

const page = await render('students.php');
ok('students.php رندر شد و خطای PHP ندارد', !page.fatal && page.len > 5000, `len=${page.len}`);
const cssBundles = page.css.filter(u => u.startsWith('assets/cache/css-'));
const jsBundles = page.js.filter(u => u.startsWith('assets/cache/js-'));
const fontFiles = page.css.filter(u => u.includes('/fonts/'));
const looseCss = page.css.filter(u => !u.startsWith('assets/cache/css-') && !u.includes('/fonts/'));
const looseJs = page.js.filter(u => !u.startsWith('assets/cache/js-'));
ok('همهٔ CSS صفحه در باندل هش‌دار است (هیچ link سرگردانی نمانده)',
  looseCss.length === 0, looseCss.join(', '));
ok('CSS = ۲ باندل (پایه + برنامه) + ۲ preload فونت',
  cssBundles.length === 2 && fontFiles.length === 2, `css=${cssBundles.length} fonts=${fontFiles.length}`);
ok('JS = باندل + session-watch (بدون فایل پراکنده)', looseJs.length <= 1 && jsBundles.length === 1,
  looseJs.join(', '));
/* ۲ CSS + ۱ باندل JS + ۱ session-watch + ۲ فونت = ۶ درخواست؛ پیش از
   V4.208.0 این عدد ۱۶ بود (۵ CSS + ۶ JS + ۳ فونت + ۲ مورد صفحه‌ای). */
ok('کل درخواست‌های دارایی صفحه ≤ ۶ است', page.css.length + page.js.length <= 6,
  `css=${page.css.length} js=${page.js.length}`);
ok('فونت‌ها فقط WOFF2 هستند (هیچ TTF ای در صفحه نیست)',
  fontFiles.every(u => u.endsWith('.woff2')), fontFiles.join(', '));

const bundleCss = await run(`<?php
$p=getcwd();
$out=[];
foreach ((array)@glob('/www/assets/cache/css-*.css') as $f) {
  $b=(string)@file_get_contents($f);
  /* هر قاعدهٔ @font-face باید src داشته باشد؛ و هیچ قلمی نباید با TTF شروع شود. */
  preg_match_all('/@font-face\{[^}]*\}/', $b, $faces);
  $bad = 0; $ttfFirst = 0;
  foreach ($faces[0] as $face) {
    if (stripos($face, 'src:') === false) $bad++;
    if (preg_match('/src:\s*url\([^)]*\.ttf/i', $face)) $ttfFirst++;
  }
  $out[basename($f)]=['bytes'=>strlen($b),'ttf_first'=>$ttfFirst,'empty_face'=>$bad,
    'workflow'=>(strpos($b,'.student-editor-title')!==false),
    'faces'=>count($faces[0])];
}
echo "@@J@@" . json_encode($out);`);
const bundles = JSON.parse((String(bundleCss.out).match(/@@J@@(\{.*\})/s) || [, '{}'])[1]);
const allBundles = Object.values(bundles);
ok('هیچ @font-face ای با TTF شروع نمی‌شود (WOFF2 همیشه اول است)',
  allBundles.length > 0 && allBundles.every(b => b.ttf_first === 0), JSON.stringify(bundles).slice(0, 200));
ok('استایل اختصاصی صفحه (student-workflow) داخل باندل است',
  allBundles.some(b => b.workflow));
ok('هر @font-face باندل src دارد (قاعدهٔ خالی حذف شده)',
  allBundles.every(b => b.empty_face === 0) && allBundles.some(b => b.faces >= 2),
  JSON.stringify(allBundles.map(b => [b.faces, b.empty_face])));

/* ═════════════════════════════════ ۶) صفحات چاپ/اسکنر بدون خطا ══ */
console.log('\n══ ۶) صفحات چاپ و اسکنر ══');
/* این صفحات بافر خودشان را باز می‌گذارند یا با exit تمام می‌شوند؛ پس خروجی
   خام بررسی می‌شود، نه JSON. مهم: fatal نباشد و باندل‌ها در <head> باشند. */
for (const [file, label, wantBundle] of [
  ['report-print.php', 'کارنامهٔ چاپی', false],
  ['bulk-print.php', 'چاپ گروهی', true],
  ['attendance-scanner.php', 'اسکنر حضور', true],
]) {
  const raw = String((await run(`<?php
@mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess'); session_name('BACI_TEST');
session_id('harnessAdm0001'); session_start();
$_SESSION['admin_id']=1; $_SESSION['admin_role']='super_admin';
$_SERVER['REQUEST_METHOD']='GET'; $_SERVER['PHP_SELF']='/www/${file}'; $_SERVER['SCRIPT_NAME']='/${file}';
$_SERVER['HTTP_HOST']='harness.local'; $_SERVER['HTTP_USER_AGENT']='perf-suite'; $_SERVER['REQUEST_URI']='/${file}';
parse_str('id=1&report_id=1&student_id=1&exam_id=1&class_only=0', $_GET); $_REQUEST = array_merge($_REQUEST, $_GET);
chdir('/www'); include '/www/${file}';`)).out || '');
  const fatal = /Standard input code|Fatal error|Parse error|Call to undefined function/.test(raw);
  /* صفحهٔ کارنامه بدون شناسهٔ معتبر عمداً پیام «امکان نمایش نیست» می‌دهد؛
     مهم این است که چارچوب صفحه (با باندل‌ها) ساخته شود. */
  ok(`${label} بدون خطای PHP رندر شد`, !fatal && raw.length > 800, `len=${raw.length}`);
  /* صفحهٔ کارنامه بدون رکورد معتبر، صفحهٔ خطای کوتاهِ عمدی (app_page_error)
     می‌دهد که هیچ دارایی‌ای ندارد؛ بقیه باید باندل بگیرند. */
  const bundled = /assets\/cache\/css-[a-f0-9]+\.css/.test(raw);
  ok(`${label} ${wantBundle ? 'دارایی‌ها را باندل می‌گیرد' : 'صفحهٔ خطای سبک دارد (بدون دارایی)'}`,
    wantBundle ? bundled : !/assets\/css\/style\.css/.test(raw));
}

/* ═══════════════════════════════════ ۷) جاوااسکریپت درون‌خطی سنگین ══ */
console.log('\n══ ۷) حذف بلوک درون‌خطی ۱۶ کیلوبایتی ══');
const impSrc = read(FILES['exam-bank-import.php']);
ok('exam-bank-import از perf_inline_js استفاده می‌کند',
  impSrc.includes("perf_inline_js($__impJs, 'exam-bank-import')"));
ok('مسیر بازگشتی (بدون perf.php) همان <script> قبلی است',
  impSrc.includes(": '<script>' . $__impJs . '</script>'"));
const staticBlocks = [...impSrc.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)]
  .map(m => m[1]).filter(b => !b.includes('<?php'));
ok('هیچ بلوک درون‌خطی ثابتِ بزرگ‌تر از ۱۲ کیلوبایت نمانده',
  staticBlocks.every(b => b.length < 12000),
  'max=' + Math.max(0, ...staticBlocks.map(b => b.length)));

const inlineProbe = await run(`<?php
chdir('/www'); require_once '/www/includes/perf.php';
$big = str_repeat('function f(){return 1;}\\n', 700);
$tag = perf_inline_js($big, 'suite-probe');
$small = perf_inline_js('var x=1;', 'suite-small');
$file = preg_match('#src="([^"]+)"#', $tag, $m) ? $m[1] : '';
echo "@@J@@" . json_encode(['tag'=>$tag, 'small'=>$small, 'file'=>$file,
  'bytes'=>$file ? strlen((string)@file_get_contents('/www/' . $file)) : 0,
  'code'=>strlen($big),
  'htaccess'=>is_file('/www/assets/cache/.htaccess')]);`);
const ip = JSON.parse((String(inlineProbe.out).match(/@@J@@(\{.*\})/s) || [, '{}'])[1]);
ok('perf_inline_js فایل هش‌دار می‌سازد و defer می‌دهد',
  /<script defer src="assets\/cache\/js-i[0-9a-f]+\.js"><\/script>/.test(ip.tag || ''), ip.tag);
ok('محتوای فایل دقیقاً همان کد است (بدون تغییر رفتار)',
  ip.bytes === ip.code && ip.code > 15000, `file=${ip.bytes} code=${ip.code}`);
ok('کد کوچک درون‌خطی می‌ماند (ارزش درخواست ندارد)', ip.small === '<script>var x=1;</script>', ip.small);
ok('پوشهٔ کش .htaccess کش یک‌ساله دارد', ip.htaccess === true);

console.log('\n════════════════════════════════════════════════════');
console.log(`  سوئیت کارایی V4.209.0: ${pass} PASS / ${fail} FAIL`);
console.log('════════════════════════════════════════════════════');
process.exit(fail ? 1 : 0);
