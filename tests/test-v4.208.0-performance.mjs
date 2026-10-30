/**
 * test-v4.208.0-performance.mjs — سوئیت «کارایی حداکثری» (V4.208.0 / v2.132.0)
 *
 * این سوئیت سه لایه را می‌سنجد:
 *
 *   ۱) مبنای واقعی trace مرورگر:
 *      · صفحهٔ مدیریت دانش‌آموزان ۹۸۷ میلی‌ثانیه TTFB داشت؛ عمدهٔ آن از
 *        DDLهایی بود که در «هر درخواست» تکرار می‌شدند (۵ ALTER در
 *        school_roles + ۳ DDL در students.php + …).
 *      · یک درخواست فونتِ ناموجود ۷۲۵ میلی‌ثانیه طول کشید، چون ۴۰۴ به PHP
 *        می‌رسید و کل برنامه اجرا می‌شد.
 *      · ۱۱ فایل CSS/JS جدا بارگذاری می‌شد (هر کدام ۱۰۰ تا ۲۳۵ میلی‌ثانیه TTFB).
 *
 *   ۲) اجرای واقعی صفحه با PHP 8.3 (WASM) و دیتابیس SQLite:
 *      · بار اول: DDL اجرا و ثبت می‌شود.
 *      · بار دوم: هیچ DDL‌ای اجرا نمی‌شود (ddl = 0) و تعداد کوئری‌ها به‌شدت کم است.
 *      · خروجی فهرست دانش‌آموزان برای همهٔ فیلترها با انتظار مستقل مقایسه می‌شود
 *        (بازنویسی کوئری تجمعی نباید حتی یک ردیف را جابه‌جا کند).
 *
 *   ۳) فایل‌های بستهٔ اصلاحی: باندل دارایی‌ها، فونت WOFF2، لایهٔ DB، پیکربندی سرور.
 */
import { run, php, dbExec, loginAdmin } from './harness/lib.mjs';
import { REPO } from './harness/site.mjs';
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';

let pass = 0, fail = 0;
const ok = (name, cond, detail = '') => cond
  ? (pass++, console.log(`  ✅ ${name}`))
  : (fail++, console.log(`  ❌ ${name}${detail ? '  → ' + detail : ''}`));
const read = rel => readFileSync(join(REPO, rel), 'utf8');
const has = (rel) => existsSync(join(REPO, rel));

/* ═══════════════════════════════════════════════════════════════════════
   ۰) پیش‌نیاز: وصله باید در دسترس باشد (سوئیت روی سورسِ پایه سبز نشود)
   ═══════════════════════════════════════════════════════════════════════ */
console.log('\n══ ۰) فایل‌های این نسخه ══');
const FILES = {
  'includes/perf.php': 'update-v4.208.0/includes/perf.php',
  'includes/perf_fallback.php': 'update-v4.208.0/includes/perf_fallback.php',
  'includes/db.php': 'update-v4.208.0/includes/db.php',
  'includes/header.php': 'update-v4.208.0/includes/header.php',
  'includes/footer.php': 'update-v4.208.0/includes/footer.php',
  'includes/functions.php': 'update-v4.208.0/includes/functions.php',
  'includes/appearance.php': 'update-v4.208.0/includes/appearance.php',
  'includes/school_roles.php': 'update-v4.208.0/includes/school_roles.php',
  'includes/session_tracker.php': 'update-v4.208.0/includes/session_tracker.php',
  'includes/management_hub.php': 'update-v4.208.0/includes/management_hub.php',
  'students.php': 'update-v4.208.0/students.php',
  'performance.php': 'update-v4.208.0/performance.php',
  'config/version.php': 'update-v4.208.0/config/version.php',
};
let missing = [];
for (const [www, src] of Object.entries(FILES)) {
  if (!has(src)) { missing.push(src); continue; }
  php.writeFile('/www/' + www, read(src));
}
ok('همهٔ ۱۳ فایل این نسخه موجود و در سایت نوشته شدند', missing.length === 0, missing.join(', '));
ok('نسخه ۴.۲۰۸.۰ و ۲.۱۳۲.۰ ثبت شده',
  /'site_version'\s*=>\s*'4\.208\.0'/.test(read(FILES['config/version.php'])) &&
  /'desktop_version'\s*=>\s*'2\.132\.0'/.test(read(FILES['config/version.php'])));

/* ═══════════════════════════════════════════════════════════════════════
   ۱) کد: صفر شدن DDL تکراری
   ═══════════════════════════════════════════════════════════════════════ */
console.log('\n══ ۱) حذف DDL تکراری ══');
const perfSrc = read(FILES['includes/perf.php']);
const studentsSrc = read(FILES['students.php']);
const rolesSrc = read(FILES['includes/school_roles.php']);
const dbSrc = read(FILES['includes/db.php']);
const funcSrc = read(FILES['includes/functions.php']);

ok('perf_schema_once / perf_daily_once / perf_ddl_skip در هستهٔ کارایی هست',
  ['function perf_schema_once', 'function perf_daily_once', 'function perf_ddl_skip', 'function perf_ddl_mark']
    .every(s => perfSrc.includes(s)));
ok('هستهٔ کارایی هیچ‌گاه exception به کاربر نمی‌دهد (try/catch + @)',
  perfSrc.includes('catch (Throwable $e)') && perfSrc.includes('@file_put_contents'));
const firstGuard = studentsSrc.indexOf('perf_schema_once(');
const tail = studentsSrc.slice(studentsSrc.indexOf('$__parentBirthSchema'));
ok('students.php هیچ DDL بی‌گاردی در سطح صفحه ندارد',
  firstGuard > 0 && studentsSrc.slice(0, firstGuard).indexOf('ALTER TABLE') === -1 &&
  studentsSrc.slice(studentsSrc.indexOf("perf_schema_once('students_page_schema_v1'"), firstGuard + 4000).includes('ALTER TABLE') &&
  /function_exists\('perf_schema_once'\)[\s\S]{0,80}perf_schema_once\('students_parent_birth_v1'/.test(tail));
ok('ensure_school_roles_schema با نشانهٔ نسخه محافظت شده',
  rolesSrc.includes("perf_schema_once('school_roles_v1'") && rolesSrc.includes('sd_ensure_school_roles_schema_legacy'));
ok('لایهٔ DB: DDL تکراری رد می‌شود و موفقیتش ثبت می‌شود',
  dbSrc.includes('perf_ddl_skip($sql)') && dbSrc.includes('perf_ddl_mark($sql)'));
ok('لایهٔ DB: آمار کوئری/زمان SQL برای صفحهٔ کارایی',
  dbSrc.includes('public static function stats()') && dbSrc.includes("'sql_ms'"));
ok('لایهٔ DB: نوشتن‌های گذرا کش را باطل نمی‌کنند',
  dbSrc.includes('perf_write_is_ephemeral') && perfSrc.includes("'user_sessions' => true"));

/* ═══════════════════════════════════════════════════════════════════════
   ۲) کد: همگام‌سازی عکس‌ها (حذف N کوئری در هر بازدید)
   ═══════════════════════════════════════════════════════════════════════ */
console.log('\n══ ۲) همگام‌سازی عکس‌ها ══');
ok('تابع با perf_daily_once محدود شده', funcSrc.includes("perf_daily_once('photo_sync'"));
ok('به‌جای یک SELECT برای هر فایل، یک کوئری IN دسته‌ای',
  funcSrc.includes('national_id IN ($ph)') && funcSrc.includes('array_chunk($ids, 400)'));
ok('امضای تابع سازگار مانده (limit + force)', /function sync_student_photos_from_uploads\(\$limit = 1000, \$force = false\)/.test(funcSrc));

/* ═══════════════════════════════════════════════════════════════════════
   ۳) کد: ۱۱ درخواست → ۴ درخواست
   ═══════════════════════════════════════════════════════════════════════ */
console.log('\n══ ۳) باندل دارایی‌ها ══');
const headerSrc = read(FILES['includes/header.php']);
const footerSrc = read(FILES['includes/footer.php']);
ok('هدر از perf_asset_url برای CSS و JS استفاده می‌کند',
  headerSrc.includes("perf_asset_url(['assets/css/style.css', 'assets/css/ui-modern.css'], 'css')") &&
  headerSrc.includes('perf_asset_url($perfJsFiles, \'js\')'));
ok('فوتر اسکریپت‌های باندل‌شده را دوباره بار نمی‌کند',
  footerSrc.includes("empty($GLOBALS['__perf_js_bundled'])"));
ok('اگر پوشهٔ کش قابل نوشتن نبود، همان لینک‌های قبلی برمی‌گردند',
  headerSrc.includes('<link rel="stylesheet" href="assets/css/style.css">'));
ok('باندل CSS کوچک‌سازی محافظه‌کارانه دارد (رشته‌ها دست‌نخورده)',
  perfSrc.includes('function perf_minify_css') && perfSrc.includes('$quote'));

/* ═══════════════════════════════════════════════════════════════════════
   ۴) کد: فونت — پایان درخواست ۴۰۴ گران‌قیمت
   ═══════════════════════════════════════════════════════════════════════ */
console.log('\n══ ۴) فونت ══');
const appearanceSrc = read(FILES['includes/appearance.php']);
ok('فقط WOFF2 اعلام می‌شود (بدون fallback به TTF در مرورگر)',
  appearanceSrc.includes("format(\"woff2\")") && !appearanceSrc.includes('format("truetype")'));
ok('قلم تک‌وزنی با بازهٔ ۱۰۰..۹۰۰ اعلام می‌شود (وزن ۵۰۰/۶۰۰ به فایل گم‌شده نمی‌رسد)',
  appearanceSrc.includes("$faces[]=[$font,'100 900']"));
ok('خانواده‌های قدیمی style.css با فایل موجود خنثی می‌شوند (بدون درخواست ۴۰۴)',
  appearanceSrc.includes('function app_legacy_font_faces') &&
  appearanceSrc.includes("'Yekan'") && appearanceSrc.includes("'Vazirmatn-Regular.woff2'") &&
  appearanceSrc.includes("app_legacy_font_faces($font['family'])"));
ok('هش فونت کش می‌شود (بدون hash_file در هر بازدید)',
  appearanceSrc.includes('function app_file_sha256') && appearanceSrc.includes("'/font-'"));

/* ═══════════════════════════════════════════════════════════════════════
   ۵) اجرای واقعی: دو بار باز کردن صفحهٔ دانش‌آموزان
   ═══════════════════════════════════════════════════════════════════════ */
console.log('\n══ ۵) اجرای واقعی صفحه (PHP 8.3) ══');
php.writeFile('/harness/perf_fixtures.php', `<?php
require_once '/www/includes/functions.php';
require_once '/www/includes/db.php';
function pf_ins($sql,$p){ try { DB::execute($sql,$p); return true; } catch (Throwable $e) { return $e->getMessage(); } }
$out=[];
$students=[
 ['4000000001','آزمون','الف','7','1/7','1404/1405'],
 ['4000000002','آزمون','ب','7','1/7','1404/1405'],
 ['4000000003','آزمون','ج','8','2/8','1404/1405'],
 ['4000000004','آزمون','د','9','3/9','1404/1405'],
 ['4000000005','آزمون','ه','7','1/7','1403/1404'],
];
foreach($students as $s){
  $out[]=pf_ins('INSERT INTO students(national_id,first_name,last_name,grade_level,class_name,academic_year,status) VALUES(?,?,?,?,?,?,\\'active\\')',$s);
}
$ids=[]; foreach(DB::fetchAll("SELECT id,national_id FROM students WHERE national_id LIKE '400000000%' ORDER BY national_id") as $r) $ids[$r['national_id']]=(int)$r['id'];
$A=$ids['4000000001'];$B=$ids['4000000002'];$C=$ids['4000000003'];
$out[]=pf_ins("INSERT INTO reports(student_id,class_name,academic_year,term,report_month,gpa,is_locked) VALUES(?,?,?,?,?,?,0)",[$A,'1/7','1404/1405','نوبت اول','مهر',18]);
$out[]=pf_ins("INSERT INTO reports(student_id,class_name,academic_year,term,report_month,gpa,is_locked) VALUES(?,?,?,?,?,?,0)",[$A,'1/7','1404/1405','نوبت اول','آبان',19.5]);
$out[]=pf_ins("INSERT INTO reports(student_id,class_name,academic_year,term,report_month,gpa,is_locked) VALUES(?,?,?,?,?,?,0)",[$B,'1/7','1404/1405','نوبت اول','مهر',11]);
$out[]=pf_ins("INSERT INTO reports(student_id,class_name,academic_year,term,report_month,gpa,is_locked) VALUES(?,?,?,?,?,?,0)",[$C,'2/8','1404/1405','نوبت اول','مهر',8]);
$rid=[]; foreach(DB::fetchAll("SELECT r.id,r.student_id FROM reports r JOIN students s ON s.id=r.student_id WHERE s.national_id LIKE '400000000%'") as $r) $rid[]=$r;
foreach($rid as $r){
  if((int)$r['student_id']===$A){ pf_ins("INSERT INTO report_grades(report_id,subject_name,score) VALUES(?,?,?)",[$r['id'],'ریاضی',20]); }
  elseif((int)$r['student_id']===$B){ pf_ins("INSERT INTO report_grades(report_id,subject_name,score) VALUES(?,?,?)",[$r['id'],'ریاضی',14]); pf_ins("INSERT INTO report_grades(report_id,subject_name,score) VALUES(?,?,?)",[$r['id'],'علوم',9]); }
  elseif((int)$r['student_id']===$C){ pf_ins("INSERT INTO report_grades(report_id,subject_name,score) VALUES(?,?,?)",[$r['id'],'ریاضی',5]); }
}
$today=function_exists('jdate')?jdate('Y/m/d'):date('Y/m/d');
pf_ins("INSERT INTO student_discipline_records(student_id,title_text,occurred_at_jalali,created_at_jalali,review_status) VALUES(?,?,?,?,'pending')",[$A,'تأخیر',$today,$today]);
pf_ins("INSERT INTO student_discipline_records(student_id,title_text,occurred_at_jalali,created_at_jalali,review_status) VALUES(?,?,?,?,'approved')",[$A,'غیبت',$today,$today]);
pf_ins("INSERT INTO student_discipline_records(student_id,title_text,occurred_at_jalali,created_at_jalali,review_status) VALUES(?,?,?,?,'approved')",[$B,'تأخیر',$today,$today]);
echo 'FIXTURES_OK '.json_encode(['A'=>$A,'B'=>$B,'C'=>$C,'ids'=>$ids],JSON_UNESCAPED_UNICODE);
`);

php.writeFile('/harness/perf_page.php', `<?php
$spec=json_decode(@file_get_contents('/harness/perf_spec.json'),true)?:['query'=>''];
@mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess');
session_name('BACI_TEST'); session_id('harnessAdm0001'); session_start();
$_SESSION['admin_id']=1; $_SESSION['admin_role']='super_admin';
echo ' ';
$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['REQUEST_URI']='/students.php'.($spec['query']!==''?'?'.$spec['query']:'');
$_SERVER['SCRIPT_NAME']='/students.php'; $_SERVER['PHP_SELF']='/students.php';
$_SERVER['REMOTE_ADDR']='127.0.0.1'; $_SERVER['HTTP_HOST']='localhost';
$_SERVER['HTTP_USER_AGENT']='PerfSuite/1.0'; $_SERVER['HTTPS']='';
$_GET=[]; if($spec['query']!=='')parse_str($spec['query'],$_GET);
$_POST=[]; $_REQUEST=$_GET;
set_error_handler(function($n,$s,$f,$l){ $GLOBALS['__err']=($GLOBALS['__err']??'')."[$n] $s ($f:$l)\\n"; return true; });
if(method_exists('DB','resetStats')) DB::resetStats();
chdir('/www'); ob_start();
include '/www/students.php';
$html=ob_get_clean();
$ids=[]; foreach((array)($studentsList??[]) as $row) $ids[]=(int)($row['id']??0);
sort($ids);
@file_put_contents('/harness/perf_result.json', json_encode([
  'ids'=>$ids,
  'count'=>count($ids),
  'stats'=>method_exists('DB','stats')?DB::stats():null,
  'html'=>$html,
  'errors'=>substr((string)($GLOBALS['__err']??''),0,400),
], JSON_UNESCAPED_UNICODE));
echo 'PAGE_OK';
`);

const fx = await run("<?php require '/harness/perf_fixtures.php';");
ok('دادهٔ آزمایشی ساخته شد', fx.out.includes('FIXTURES_OK'), (fx.out + fx.err).slice(0, 400));
const fixture = (() => { try { return JSON.parse(fx.out.slice(fx.out.indexOf('{'), fx.out.lastIndexOf('}') + 1)); } catch (e) { return {}; } })();

async function loadPage(query = '') {
  php.writeFile('/harness/perf_spec.json', JSON.stringify({ query }));
  const r = await run("<?php require '/harness/perf_page.php';");
  let res = null;
  try { res = JSON.parse(php.readFileAsText('/harness/perf_result.json')); } catch (e) {}
  return { res, out: r.out, err: r.err };
}

await loginAdmin();
const cold = await loadPage();
ok('صفحهٔ دانش‌آموزان واقعاً رندر شد (بدون خطای PHP)',
  cold.out.includes('PAGE_OK') && cold.res && cold.res.count >= 4 && !(cold.res.errors || '').match(/Fatal|Uncaught/),
  (cold.out + ' ' + (cold.res?.errors || '') + cold.err).slice(0, 400));
const warm = await loadPage();
ok('بار دوم همان تعداد ردیف را می‌دهد (خروجی پایدار)', cold.res?.count === warm.res?.count,
  `${cold.res?.count} vs ${warm.res?.count}`);

if (cold.res?.stats && warm.res?.stats) {
  /* V4.209.0: کلید کش DDL شامل نسخهٔ هسته است؛ با هر ارتقا «بار اول» همهٔ
     DDLها یک‌بار اجرا می‌شوند. ضمانت واقعی این است که از بار دوم به بعد
     هیچ DDL ای اجرا نشود — که در بار سوم هم بررسی می‌شود. */
  const third = await loadPage();
  ok('بار سوم هم صفر DDL اجرا می‌شود (نه فقط بار دوم)',
    (third.res?.stats?.ddl || 0) === 0 && (third.res?.stats?.writes || 0) === 0,
    JSON.stringify(third.res?.stats));
  ok('بار دوم: صفر DDL اجرا می‌شود (پیش‌تر ۴۰ DDL در هر بازدید)',
    cold.res.stats.ddl > 0 && warm.res.stats.ddl === 0, JSON.stringify(warm.res.stats));
  ok('بار دوم: تعداد کوئری‌ها ≤ ۴۰ (پیش‌تر ۵۵+)',
    warm.res.stats.queries <= 40, String(warm.res.stats.queries));
  ok('بار دوم: نوشتن در دیتابیس رخ نمی‌دهد', warm.res.stats.writes === 0, JSON.stringify(warm.res.stats));
  ok('زمان SQL بار دوم کمتر از ۵۰ میلی‌ثانیه', (warm.res.stats.sql_ms || 0) < 50, String(warm.res.stats.sql_ms));
  ok('کوئری کندتر از ۱۲۰ms در هیچ‌کدام از دو بار نیست',
    (cold.res.stats.slow || []).length === 0 && (warm.res.stats.slow || []).length === 0,
    JSON.stringify([cold.res.stats.slow, warm.res.stats.slow]));
} else {
  ok('آمار کوئری در دسترس است', false, 'DB::stats() نبود — لایهٔ DB اعمال نشده؟');
}

/* وضعیت همگام‌سازی عکس‌ها باید از کوئری‌های صفحه حذف شده باشد */
ok('همگام‌سازی عکس‌ها در بازدید صفحه اجرا نمی‌شود (نشانهٔ daily ساخته شد)',
  php.fileExists('/www/config/.state/daily-photo_sync.lock') || !php.fileExists('/www/uploads/photos'),
  'نشانهٔ daily پیدا نشد');

/* ═══════════════════════════════════════════════════════════════════════
   ۶) باندل و فونت در HTML واقعی
   ═══════════════════════════════════════════════════════════════════════ */
console.log('\n══ ۶) HTML واقعی: درخواست کمتر، فونت سالم ══');
const html = cold.res?.html || '';
const cssLinks = [...html.matchAll(/<link rel="stylesheet" href="([^"]+)"/g)].map(m => m[1]);
const jsTags = [...html.matchAll(/<script defer src="?([^"\s>]+)/g)].map(m => m[1]);
ok('تعداد CSS ≤ ۳ (پیش‌تر ۵)', cssLinks.length <= 3, cssLinks.join(' | '));
ok('تعداد JS با defer ≤ ۲ (پیش‌تر ۶)', jsTags.length <= 2, jsTags.join(' | '));
ok('باندل CSS با نام هش‌دار استفاده شده', cssLinks.some(u => /^assets\/cache\/css-[a-f0-9]{14}\.css$/.test(u)), cssLinks.join(' | '));
ok('باندل JS با نام هش‌دار استفاده شده', jsTags.some(u => /^assets\/cache\/js-[a-f0-9]{14}\.js$/.test(u)), jsTags.join(' | '));
ok('هیچ ارجاعی به فایل ناموجود uploads/*.woff2 در HTML نیست',
  !/uploads\/[^"')]+\.woff2/.test(html));
ok('قلم از assets/fonts/screen با فرمت woff2 می‌آید',
  /assets\/fonts\/screen\/[A-Za-z-]+\.woff2"\)\s+format\("woff2"\)/.test(html));
ok('fallback به truetype در @font-face حذف شده', !/format\("truetype"\)/.test(html));

/* محتوای باندل‌ها واقعی است و هر دو فایل را دارد */
const cssBundles = cssLinks.filter(u => u.startsWith('assets/cache/css-'));
if (cssBundles.length) {
  const bodies = cssBundles.map(u => php.readFileAsText('/www/' + u));
  const all = bodies.join('\n');
  ok('دو باندل CSS ساخته شد و هر چهار فایل را پوشش می‌دهد',
    cssBundles.length === 2 && all.length > 60000 &&
    all.includes('--primary') && all.includes('.school-app') && all.includes('students-filters'),
    cssBundles.join(' | ') + ' / ' + bodies.map(b => b.length).join(','));
  ok('باندل CSS کوچک‌سازی شده (کامنت بلوکی ندارد)',
    bodies.every(b => !/\/\*[\s\S]*?\*\//.test(b.replace('/* SchoolDesk Pro asset bundle — generated; do not edit. */', ''))));
}
const bundleJs = jsTags.find(u => u.startsWith('assets/cache/js-'));
if (bundleJs) {
  const body = php.readFileAsText('/www/' + bundleJs);
  ok('باندل JS محتوای هر شش فایل را دارد',
    body.includes('session-status.php')            // session-watch.js
    && body.includes('window.SchoolIcons')          // school-icons.js
    && body.includes('SchoolUI')                    // school-ui.js
    && body.includes('DOMContentLoaded')            // main.js / ui-modern.js
    && body.includes('searchable-select')           // searchable-select.js
    && body.length > 50000,
    String(body.length));
}

/* ═══════════════════════════════════════════════════════════════════════
   ۷) بازنویسی کوئری فهرست: فیلترها باید دقیقاً مثل قبل کار کنند
   ═══════════════════════════════════════════════════════════════════════ */
console.log('\n══ ۷) فیلترها پس از بازنویسی ══');
const A = fixture.A, B = fixture.B, C = fixture.C, D = fixture.ids?.['4000000004'];
const same = (got, want) => JSON.stringify([...got].sort((x, y) => x - y)) === JSON.stringify([...want].sort((x, y) => x - y));
const cases = [
  ['بدون فیلتر → چهار دانش‌آموز سال ۱۴۰۴/۱۴۰۵', '', [A, B, C, D]],
  ['دارای سابقهٔ انضباطی → الف و ب', 'discipline_filter=has_records', [A, B]],
  ['بدون سابقهٔ انضباطی → ج و د', 'discipline_filter=no_records', [C, D]],
  ['سابقهٔ ۳۰ روز اخیر → هر سه', 'discipline_filter=recent_30', [A, B]],
  ['نمرهٔ زیر ۱۰ دارند → ب و ج', 'academic_filter=failed', [B, C]],
  ['معدل ضعیف (۰ تا ۱۲) → ب و ج', 'academic_filter=weak', [B, C]],
  ['معدل عالی (≥۱۷) → فقط الف', 'academic_filter=excellent', [A]],
  ['حداقل معدل ۱۵ → فقط الف', 'min_gpa=15', [A]],
  ['حداکثر معدل ۱۲ → ب و ج', 'max_gpa=12', [B, C]],
  ['درس ریاضی دارند → الف، ب، ج', 'subject_name=' + encodeURIComponent('ریاضی'), [A, B, C]],
  ['درس علوم دارند → فقط ب', 'subject_name=' + encodeURIComponent('علوم'), [B]],
];
for (const [label, query, want] of cases) {
  const r = await loadPage(query);
  ok(label, same(r.res?.ids || [], want), `گرفت [${(r.res?.ids || []).join(',')}] انتظار [${want.join(',')}]`);
}

/* فیلتر سال: دانش‌آموز سال ۱۴۰۳ نباید بیاید، و اگر سال را ۱۴۰۳ بگذاریم باید بیاید */
const y1403 = await loadPage('academic_year=' + encodeURIComponent('1403/1404'));
ok('فیلتر سال تحصیلی ۱۴۰۳/۱۴۰۴ فقط دانش‌آموز همان سال را می‌دهد',
  same(y1403.res?.ids || [], [fixture.ids?.['4000000005']]),
  `گرفت [${(y1403.res?.ids || []).join(',')}]`);

/* ═══════════════════════════════════════════════════════════════════════
   ۸) کش داده: نوشتن در دیتابیس باید فوراً آن را باطل کند
   ═══════════════════════════════════════════════════════════════════════ */
console.log('\n══ ۸) ابطال کش با نوشتن ══');
const before = await loadPage('academic_filter=failed');
php.writeFile('/harness/perf_write.php', `<?php
require_once '/www/includes/functions.php'; require_once '/www/includes/db.php';
$sid=(int)($argv[1] ?? 0);
$r=DB::fetch("SELECT id FROM reports WHERE student_id=(SELECT id FROM students WHERE national_id='4000000004') LIMIT 1");
if(!$r){ DB::execute("INSERT INTO reports(student_id,class_name,academic_year,term,report_month,gpa,is_locked) VALUES((SELECT id FROM students WHERE national_id='4000000004'),'3/9','1404/1405','نوبت اول','مهر',15,0)"); }
$rid=(int)DB::fetch("SELECT id FROM reports WHERE student_id=(SELECT id FROM students WHERE national_id='4000000004') LIMIT 1")['id'];
DB::execute("INSERT INTO report_grades(report_id,subject_name,score) VALUES(?,?,?)",[$rid,'ریاضی',6]);
echo 'WRITE_OK';
`);
const w = await run("<?php require '/harness/perf_write.php';");
ok('نوشتن آزمایشی انجام شد', w.out.includes('WRITE_OK'), (w.out + w.err).slice(0, 200));
const after = await loadPage('academic_filter=failed');
ok('پس از ثبت نمرهٔ ۶ برای «د»، فیلتر «نمرهٔ زیر ۱۰» بلافاصله او را نشان می‌دهد',
  (after.res?.ids || []).includes(D) && (before.res?.ids || []).includes(D) === false,
  `قبل [${(before.res?.ids || []).join(',')}] بعد [${(after.res?.ids || []).join(',')}]`);

/* ═══════════════════════════════════════════════════════════════════════
   ۹) تنظیمات سرور و صفحهٔ کارایی
   ═══════════════════════════════════════════════════════════════════════ */
console.log('\n══ ۹) تنظیمات و صفحهٔ کارایی ══');
const nginx = read('update-v4.208.0/perf-config/nginx-performance.conf');
ok('nginx: فایل استاتیک گم‌شده به PHP نمی‌رود (try_files =404)',
  /location \^~ \/assets\/[\s\S]*?try_files \$uri =404/.test(nginx) &&
  /location \^~ \/uploads\/[\s\S]*?try_files \$uri =404/.test(nginx));
ok('nginx: کش یک‌ساله و immutable برای assets', nginx.includes('immutable') && nginx.includes('expires 1y'));
ok('nginx: gzip/brotli و open_file_cache', nginx.includes('gzip on;') && nginx.includes('open_file_cache'));
const ini = read('update-v4.208.0/perf-config/php-performance.ini');
ok('php.ini: OPcache و realpath_cache تنظیم شده',
  ini.includes('opcache.enable=1') && ini.includes('realpath_cache_size=4M'));
const apache = read('update-v4.208.0/perf-config/apache-performance.htaccess');
ok('Apache: کش، فشرده‌سازی و ۴۰۴ سریع برای استاتیک', apache.includes('mod_expires') && apache.includes('R=404'));
ok('صفحهٔ کارایی: CSRF + مجوز + دکمه‌های کش/ایندکس',
  read(FILES['performance.php']).includes("require_permission('system_settings')") &&
  read(FILES['performance.php']).includes('verify_csrf') &&
  read(FILES['performance.php']).includes('perf_cache_flush'));
ok('صفحهٔ کارایی در منوی «تنظیمات دیگر» لینک شده',
  read(FILES['includes/management_hub.php']).includes("'performance'=>['کارایی و سرعت سایت','performance.php?embedded=1']"));

/* اجرای واقعی صفحهٔ کارایی */
const perfPage = await run(`<?php
$spec=['query'=>'']; @mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess');
session_name('BACI_TEST'); session_id('harnessAdm0001'); session_start();
$_SESSION['admin_id']=1; $_SESSION['admin_role']='super_admin';
echo ' ';
$_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']='/performance.php';
$_SERVER['SCRIPT_NAME']='/performance.php'; $_SERVER['PHP_SELF']='/performance.php';
$_SERVER['REMOTE_ADDR']='127.0.0.1'; $_SERVER['HTTP_HOST']='localhost'; $_SERVER['HTTP_USER_AGENT']='PerfSuite/1.0'; $_SERVER['HTTPS']='';
$_GET=[];$_POST=[];$_REQUEST=[];
set_error_handler(function($n,$s,$f,$l){ $GLOBALS['__err']=($GLOBALS['__err']??'')."[$n] $s\\n"; return true; });
chdir('/www'); ob_start(); include '/www/performance.php'; $h=ob_get_clean();
@file_put_contents('/harness/perf_page.html', $h);
@file_put_contents('/harness/perf_page.err', (string)($GLOBALS['__err']??''));
echo 'PERF_PAGE_OK';
`);
const perfHtml = (() => { try { return php.readFileAsText('/harness/perf_page.html'); } catch (e) { return ''; } })();
const perfErr = (() => { try { return php.readFileAsText('/harness/perf_page.err'); } catch (e) { return ''; } })();
ok('صفحهٔ کارایی بدون خطا رندر می‌شود',
  perfPage.out.includes('PERF_PAGE_OK') && perfHtml.length > 4000 && !/Fatal|Uncaught/.test(perfErr),
  (perfPage.out + perfErr).slice(0, 300));
ok('صفحهٔ کارایی سنجه‌های واقعی را نشان می‌دهد',
  perfHtml.includes('زمان تولید صفحه') && perfHtml.includes('DDL صرف‌نظر‌شده') && perfHtml.includes('ایندکس‌های حیاتی'));
ok('صفحهٔ کارایی snippetهای سرور را دارد',
  perfHtml.includes('try_files $uri =404') && perfHtml.includes('opcache.enable=1'));

/* ═══════════════════════════════════════════════════════════════════════
   ۱۰) بسته‌های اصلاحی و نسخهٔ کامل
   ═══════════════════════════════════════════════════════════════════════ */
console.log('\n══ ۱۰) بسته‌بندی ══');
const relScript = read('scripts/release-v4.208.0.py');
ok('اسکریپت بستهٔ سایت، فایل‌های کارایی را شامل می‌شود',
  relScript.includes("('includes/perf.php'") && relScript.includes("('performance.php'") &&
  relScript.includes("('includes/db.php'"));
ok('اسکریپت بستهٔ دسکتاپ، هر دو مسیر reports/ و www/ را می‌سازد',
  relScript.includes("'reports/' + arc") && relScript.includes("'www/' + arc"));
ok('فایل‌های پیکربندی سرور در بسته هستند',
  relScript.includes('perf-config/nginx-performance.conf') && relScript.includes('perf-config/php-performance.ini'));

/* ═══════════════════════════════════════════════════════════════════════
   ۱۱) ایمنی نصب ناقص: perf_fallback هرگز نمی‌گذارد صفحه Fatal بدهد
   ═══════════════════════════════════════════════════════════════════════ */
console.log('\n══ ۱۱) ایمنی نصب ناقص (perf_fallback) ══');
const fbSrc = read(FILES['includes/perf_fallback.php']);
const perfNames = [...new Set([...perfSrc.matchAll(/function (perf_[a-z_]+)\s*\(/g)].map(m => m[1]))];
const fbNames = new Set([...fbSrc.matchAll(/function (perf_[a-z_]+)\s*\(/g)].map(m => m[1]));
const notCovered = perfNames.filter(n => !fbNames.has(n));
ok('هر تابع perf_* در فایل جایگزین هم تعریف شده است', notCovered.length === 0,
  notCovered.join(', '));
ok('هدر و functions فقط در صورت وجود، perf.php را می‌خوانند و fallback دارند',
  headerSrc.includes("if (is_file(__DIR__ . '/perf.php')) require_once __DIR__ . '/perf.php';") &&
  headerSrc.includes("elseif (is_file(__DIR__ . '/perf_fallback.php'))") &&
  funcSrc.includes("if (is_file(__DIR__.'/perf.php')) require_once __DIR__.'/perf.php';") &&
  funcSrc.includes("elseif (is_file(__DIR__.'/perf_fallback.php'))"));
ok('students.php هم بدون perf.php کار می‌کند (بارگذاری جایگزین)', 
  studentsSrc.includes("elseif (is_file(__DIR__ . '/includes/perf_fallback.php'))"));
const fbRun = await run(`<?php
@mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess'); session_name('BACI_TEST');
session_id('harnessFb00001'); session_start();
require '/www/includes/perf_fallback.php';
$out = [];
$out['url'] = perf_asset_url(['assets/css/style.css'],'css');
$out['schema'] = 0; perf_schema_once('x', function () use (&$out) { $out['schema']++; });
$out['daily'] = 0; perf_daily_once('y', function () use (&$out) { $out['daily']++; }, 3600, 'sig');
$out['cache'] = perf_cache_remember('k', 60, function () { return 42; });
$out['ddl'] = perf_ddl_skip('ALTER TABLE t ADD COLUMN c INT');
$out['idx'] = json_encode(perf_ensure_indexes());
$out['ms'] = is_float(perf_elapsed_ms()) || is_int(perf_elapsed_ms()) || is_numeric(perf_elapsed_ms());
echo 'FB=' . json_encode($out);
`);
let fbData = {};
try { fbData = JSON.parse((fbRun.out.match(/FB=(\{.*\})/) || [])[1] || '{}'); } catch (e) {}
ok('جایگزین واقعاً اجرا می‌شود و همان رفتار قبلی را می‌دهد',
  fbData.url === '' && fbData.schema === 1 && fbData.daily === 1 && fbData.cache === 42 &&
  fbData.ddl === false && fbData.idx === '[]',
  fbRun.out.slice(0, 200));

console.log(`\n──── نتیجه: ${pass} ✅ / ${fail} ❌ ────`);
process.exit(fail === 0 ? 0 : 1);
