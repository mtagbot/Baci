/**
 * test-tags-select-theme.mjs — سوئیت ۱۶ (v4.135.0)
 *
 * سه خواستهٔ کاربر:
 *   ۱) چاپ تگ‌ها: ترتیب‌های مختلف چیدمان، فونت درست در چاپ نهایی،
 *      فیلتر پایه‌ای، و چاپ تکی
 *   ۲) جستجو در لیست‌های باز‌شوندهٔ انتخاب دانش‌آموز
 *   ۳) رنگ دوم سفارشی‌سازی واقعاً روی دکمه‌ها اثر کند
 */
import { run, php } from './harness/lib.mjs';
import { resolveFile } from './harness/site.mjs';
import { readFileSync } from 'node:fs';

let pass = 0, fail = 0;
const ok = (n, c, d = '') => c ? (pass++, console.log(`  ✅ ${n}`))
                               : (fail++, console.log(`  ❌ ${n}${d ? '  → ' + d : ''}`));
const j = async (code) => {
    const out = (await run(code)).out.trim();
    try { return JSON.parse(out); } catch (e) { return { __raw: out }; }
};
const stripComments = (src) => src
    .replace(/<\?php\s*\/\*[\s\S]*?\*\/\s*\?>/g, '')
    .replace(/\/\*[\s\S]*?\*\//g, '');

/* ═══════════ ۱) چاپ تگ‌های QR ═══════════ */
console.log('\n══ چاپ تگ‌های QR ══');

const tags = readFileSync(resolveFile('attendance-tags.php'), 'utf8');
const tagsCode = stripComments(tags);

/* --- مرتب‌سازی: منطق واقعی PHP اجرا می‌شود --- */
php.writeFile('/harness/sort.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/school_sort.php';

$rows = [
  ['id'=>1,'first_name'=>'زهرا','last_name'=>'ابراهیمی','class_name'=>'دوم ب','grade_level'=>'دوم','national_id'=>'3000000003'],
  ['id'=>2,'first_name'=>'آرش','last_name'=>'یوسفی','class_name'=>'اول الف','grade_level'=>'اول','national_id'=>'1000000001'],
  ['id'=>3,'first_name'=>'بهار','last_name'=>'آبادی','class_name'=>'دوم ب','grade_level'=>'دوم','national_id'=>'2000000002'],
];
function srt($rows,$sortKey){
  switch ($sortKey) {
    case 'name':   persian_usort_students($rows); break;
    case 'first':
      usort($rows, function($a,$b){ $c=persian_compare($a['first_name'],$b['first_name']);
        return $c!==0?$c:persian_compare($a['last_name'],$b['last_name']); }); break;
    case 'grade':
      usort($rows, function($a,$b){ $wa=grade_sort_weight($a['grade_level']); $wb=grade_sort_weight($b['grade_level']);
        if($wa!==$wb) return $wa<=>$wb; $c=persian_compare($a['class_name'],$b['class_name']);
        return $c!==0?$c:persian_compare($a['last_name'],$b['last_name']); }); break;
    case 'nid':
      usort($rows, function($a,$b){ return strcmp($a['national_id'],$b['national_id']); }); break;
    default:
      usort($rows, function($a,$b){ $c=persian_compare($a['class_name'],$b['class_name']);
        if($c!==0) return $c; $c=persian_compare($a['last_name'],$b['last_name']);
        return $c!==0?$c:persian_compare($a['first_name'],$b['first_name']); }); break;
  }
  return array_map(function($r){ return $r['id']; }, $rows);
}
echo json_encode([
  'name'  => srt($rows,'name'),
  'first' => srt($rows,'first'),
  'grade' => srt($rows,'grade'),
  'nid'   => srt($rows,'nid'),
  'class' => srt($rows,'class'),
]);`);
const S = await j("<?php require '/harness/sort.php';");
/* آبادی < ابراهیمی < یوسفی  (آ قبل از ا) */
ok('ترتیب «نام خانوادگی» درست است (آ قبل از ا)',
   JSON.stringify(S.name) === JSON.stringify([3, 1, 2]), JSON.stringify(S.name));
/* آرش < بهار < زهرا */
ok('ترتیب «نام» درست است',
   JSON.stringify(S.first) === JSON.stringify([2, 3, 1]), JSON.stringify(S.first));
/* اول قبل از دوم؛ داخل دوم: آبادی قبل از ابراهیمی */
ok('ترتیب «پایه سپس کلاس» درست است',
   JSON.stringify(S.grade) === JSON.stringify([2, 3, 1]), JSON.stringify(S.grade));
ok('ترتیب «کد ملی» درست است',
   JSON.stringify(S.nid) === JSON.stringify([2, 3, 1]), JSON.stringify(S.nid));
ok('ترتیب پیش‌فرض «کلاس» درست است',
   JSON.stringify(S.class) === JSON.stringify([2, 3, 1]), JSON.stringify(S.class));

/* --- خودِ صفحه --- */
ok('پارامتر مرتب‌سازی پذیرفته می‌شود', tagsCode.includes("$sortKey = $_GET['sort']"));
ok('فقط ترتیب‌های مجاز پذیرفته می‌شوند (allow-list)',
   /in_array\(\$sortKey, \['class', 'name', 'first', 'grade', 'nid'\], true\)/.test(tagsCode));
ok('هر ۵ گزینهٔ ترتیب در فرم هست',
   ['value="class"', 'value="name"', 'value="first"', 'value="grade"', 'value="nid"']
     .every(v => tagsCode.includes(v)));
ok('فیلتر پایه وجود دارد', tagsCode.includes("$fGrade") && tagsCode.includes("s.grade_level=?"));
ok('فیلتر پایه در فرم هست', tagsCode.includes('name="grade"'));
ok('چاپ تکی پشتیبانی می‌شود', tagsCode.includes("$fOne") && tagsCode.includes("s.id=?"));
ok('لیست انتخاب دانش‌آموز برای چاپ تکی هست', tagsCode.includes('id="oneStudent"'));
ok('دکمهٔ «چاپ این تگ» روی هر کارت هست', tagsCode.includes('printOne('));
ok('چاپ تکی طراحی فعلی را حفظ می‌کند', /function printOne\(id\)\{ openPrintView\(id\); \}/.test(tagsCode));
ok('فیلترها روی کوئری با پارامتر امن اعمال می‌شوند',
   !/s\.grade_level='\s*\.\s*\$/.test(tagsCode) && !/s\.id=\s*'\s*\.\s*\$/.test(tagsCode));

/* --- فونت چاپ: مهم‌ترین بخش --- */
console.log('\n══ فونت در چاپ نهایی ══');
ok('فونت سامانه در صفحهٔ چاپ خوانده می‌شود', tagsCode.includes("get_setting('font_family'"));
ok('@font-face در صفحهٔ چاپ تولید می‌شود', tagsCode.includes('$fontFaces'));
ok('Tahoma دیگر در استایل چاپ hardcode نیست',
   !/\.t-name\{font-family:Tahoma/.test(tagsCode) && !/\.t-sub\{font-family:Tahoma/.test(tagsCode));
ok('نام و کلاس تگ از فونت سامانه استفاده می‌کنند',
   (tagsCode.match(/font-family:<\?php echo \$fontStack/g) || []).length >= 3);
ok('فونت سفارشی آپلودی هم پشتیبانی می‌شود', tagsCode.includes('CustomUploadedFont'));
ok('فقط فایل فونتِ موجود روی دیسک اعلام می‌شود', tagsCode.includes('is_file(__DIR__'));
ok('font-display:block تا فونت برسد متن را نگه می‌دارد', tagsCode.includes('font-display:block'));
ok('پلاک روی QR هم با فونت سامانه کشیده می‌شود',
   tagsCode.includes('var PFONT') && !/ctx\.font = '700 ' \+ fs \+ 'px Tahoma/.test(tagsCode));
ok('چاپ تا آماده‌شدن فونت صبر می‌کند', tagsCode.includes('document.fonts.ready'));
ok('اگر فونت نیامد، چاپ گیر نمی‌کند (مهلت)', /setTimeout\(go, 3000\)/.test(tagsCode));
ok('مرورگر بدون fonts.ready هم پشتیبانی می‌شود', /setTimeout\(cb, 600\)/.test(tagsCode));
ok('پنجرهٔ چاپ فقط یک بار باز می‌شود', tagsCode.includes('if (printed) return;'));

/* رندر واقعی صفحهٔ چاپ.
   صفحهٔ چاپ در انتها exit می‌زند، پس هر echo بعد از include اجرا
   نمی‌شود. خروجی را در shutdown می‌گیریم و در گام بعد می‌سنجیم —
   همان الگویی که برای login-captcha-state.php هم لازم شد. */
php.writeFile('/harness/tagprint.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
@mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess');
session_id('tg'.mt_rand()); session_start();
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/auth.php';
set_setting('font_family','Vazirmatn');
$a = DB::fetch("SELECT * FROM admins WHERE status=1 ORDER BY id LIMIT 1");
$_SESSION = ['admin_id'=>$a['id'],'admin_role'=>'super_admin'];
$_GET = ['print'=>'1','sort'=>'name','size'=>'40'];
$_SERVER['REQUEST_METHOD']='GET';
chdir('/www');
register_shutdown_function(function(){
  file_put_contents('/tmp/tagout.html', ob_get_clean());
});
ob_start();
include '/www/attendance-tags.php';`);
await run("<?php require '/harness/tagprint.php';");
const P = await j(`<?php
$h = @file_get_contents('/tmp/tagout.html');
echo json_encode([
  'has_fontface' => substr_count($h,'@font-face'),
  'has_vazir'    => strpos($h,'Vazirmatn')!==false ?1:0,
  'no_tahoma_tn' => preg_match('/\\.t-name\\{font-family:Tahoma/',$h)?0:1,
  'fonts_ready'  => strpos($h,'fonts.ready')!==false ?1:0,
  'ttf_src'      => strpos($h,'Vazirmatn-Regular.ttf')!==false ?1:0,
  'len'          => strlen($h),
]);`);
ok('صفحهٔ چاپ واقعاً @font-face دارد', P.has_fontface >= 1, JSON.stringify(P));
ok('فونت انتخابی در خروجی چاپ هست', P.has_vazir === 1);
ok('فایل فونت واقعی لینک شده', P.ttf_src === 1);
ok('Tahoma جای فونت مدرسه را نگرفته', P.no_tahoma_tn === 1);
ok('منطق انتظار فونت در خروجی هست', P.fonts_ready === 1);

/* ═══════════ ۲) جستجو در لیست دانش‌آموز ═══════════ */
console.log('\n══ جستجو در لیست‌های باز‌شونده ══');

const ssSrc = readFileSync(resolveFile('assets/js/searchable-select.js'), 'utf8');
const footer = readFileSync(resolveFile('includes/footer.php'), 'utf8');

ok('اسکریپت در همهٔ صفحات لود می‌شود', footer.includes('searchable-select.js'));
ok('فقط برای دسکتاپ محدود نشده (سایت هم لازم دارد)',
   !/PHP_SAPI === 'cli-server'[\s\S]{0,120}searchable-select\.js/.test(footer));
ok('select اصلی در DOM می‌ماند (ارسال فرم نمی‌شکند)', ssSrc.includes('ss-hidden-select'));
ok('رویداد change دستی شلیک می‌شود', ssSrc.includes("new Event('change'"));
ok('دکمه type=button است تا فرم submit نشود', ssSrc.includes("btn.type = 'button'"));
ok('لیست‌های ساخته‌شده با JS هم پوشش داده می‌شوند', ssSrc.includes('MutationObserver'));
ok('روی لیست‌های دانش‌آموز حتماً فعال می‌شود', /\/student\/i\.test/.test(ssSrc));
ok('امکان خاموش‌کردن برای یک لیست خاص هست', ssSrc.includes('noSearch'));
ok('پشتیبانی کیبورد (بالا/پایین/Enter/Esc)',
   ['ArrowDown', 'ArrowUp', 'Enter', 'Escape'].every(k => ssSrc.includes(k)));
ok('خطای یک لیست کل صفحه را نمی‌شکند', /catch \(e\) \{ \/\* یک لیست نباید صفحه را بشکند \*\/ \}/.test(ssSrc));

/* منطق نرمال‌سازی جستجو واقعاً اجرا می‌شود */
const normFn = (() => {
    const m = ssSrc.match(/function norm\(s\) \{[\s\S]*?\n    \}/);
    return new Function('return ' + m[0].replace('function norm', 'function'))();
})();
ok('«ي» عربی با «ی» فارسی یکی حساب می‌شود', normFn('علي') === normFn('علی'));
ok('«ك» عربی با «ک» فارسی یکی حساب می‌شود', normFn('كريم') === normFn('کریم'));
ok('ارقام فارسی به انگلیسی تبدیل می‌شوند', normFn('۱۲۳۴') === '1234');
ok('نیم‌فاصله مزاحم جستجو نیست', normFn('محمد\u200cرضا') === normFn('محمد رضا'));

const cssAll = readFileSync(resolveFile('assets/css/style.css'), 'utf8');
ok('استایل لیست جستجو اضافه شده', cssAll.includes('.ss-panel'));
ok('در چاپ، پنل جستجو پنهان است', /@media print\{ \.ss-panel\{display:none!important\} \}/.test(cssAll));

/* ═══════════ ۳) رنگ دوم سفارشی‌سازی ═══════════ */
console.log('\n══ رنگ اول و دوم ══');

const header = readFileSync(resolveFile('includes/header.php'), 'utf8');
ok('متغیر --accent از رنگ دوم پر می‌شود', /--accent: <\?php echo clean\(\$accentColor\)/.test(header));
ok('--primary-hover دیگر ثابت نیست', header.includes('--primary-hover: <?php echo clean($primaryHover)'));
ok('--accent-hover ساخته می‌شود', header.includes('--accent-hover'));
ok('--primary-soft و --primary-ring هم ست می‌شوند',
   header.includes('--primary-soft:') && header.includes('--primary-ring:'));
ok('--admin-gold همچنان برای سازگاری ست می‌شود', header.includes('--admin-gold:'));
ok('btn-warning از رنگ دوم استفاده می‌کند',
   /\.btn-warning \{ background-color: var\(--accent/.test(cssAll));
ok('کلاس btn-accent اضافه شده', cssAll.includes('.btn-accent'));
ok('رنگ ثابت قبلی دکمهٔ warning حذف شد',
   !/\.btn-warning \{ background-color: #f59e0b/.test(cssAll));

/* توابع رنگ واقعاً اجرا می‌شوند */
php.writeFile('/harness/color.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/auth.php';
require_once '/www/includes/header_tiles.php';
/* تعریف توابع با اجرای بخش بالای header در یک محدودهٔ امن */
if (!function_exists('theme_shade')) {
  $src = file_get_contents('/www/includes/header.php');
  $start = strpos($src, "if (!function_exists('theme_shade'))");
  $end = strpos($src, '$primaryHover =');
  eval(substr($src, $start, $end - $start));
}
echo json_encode([
  'dark'      => theme_shade('#2563eb', -0.18),
  'light'     => theme_shade('#2563eb', 0.5),
  'short'     => theme_shade('#abc', -0.1),
  'bad'       => theme_shade('nonsense', -0.2),
  'rgba'      => theme_rgba('#2563eb','0.09'),
  'rgba_bad'  => theme_rgba('zzz','0.5'),
  'clamp_low' => theme_shade('#000000', -0.9),
  'clamp_hi'  => theme_shade('#ffffff', 0.9),
]);`);
const C = await j("<?php require '/harness/color.php';");
ok('تیره‌کردن رنگ درست کار می‌کند', /^#[0-9a-f]{6}$/.test(C.dark || '') && C.dark !== '#2563eb', String(C.dark));
ok('روشن‌کردن رنگ درست کار می‌کند', /^#[0-9a-f]{6}$/.test(C.light || ''), String(C.light));
ok('hex سه‌رقمی پشتیبانی می‌شود', /^#[0-9a-f]{6}$/.test(C.short || ''), String(C.short));
ok('ورودی نامعتبر برنامه را نمی‌شکند', typeof C.bad === 'string' && C.bad.startsWith('#'), String(C.bad));
ok('rgba درست تولید می‌شود', /^rgba\(37,99,235,0\.09\)$/.test(C.rgba || ''), String(C.rgba));
ok('rgba با ورودی بد هم امن است', /^rgba\(\d+,\d+,\d+,0\.5\)$/.test(C.rgba_bad || ''), String(C.rgba_bad));
ok('مقادیر خارج از محدوده بریده می‌شوند',
   C.clamp_low === '#000000' && C.clamp_hi === '#ffffff', C.clamp_low + ' ' + C.clamp_hi);

/* رندر واقعی: رنگ دوم در خروجی صفحه دیده شود */
php.writeFile('/harness/theme.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
@mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess');
session_id('th'.mt_rand()); session_start();
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/auth.php';
set_setting('theme_color','#111827');
set_setting('accent_color','#e11d48');
$a = DB::fetch("SELECT * FROM admins WHERE status=1 ORDER BY id LIMIT 1");
$_SESSION = ['admin_id'=>$a['id'],'admin_role'=>'super_admin'];
$_SERVER['PHP_SELF']='/index.php';
chdir('/www');
ob_start(); include '/www/includes/header.php'; $h = ob_get_clean();
echo json_encode([
  'primary' => strpos($h,'--primary: #111827')!==false ?1:0,
  'accent'  => strpos($h,'--accent: #e11d48')!==false ?1:0,
  'phover'  => preg_match('/--primary-hover: #[0-9a-f]{6}/',$h)?1:0,
  'ahover'  => preg_match('/--accent-hover: #[0-9a-f]{6}/',$h)?1:0,
  'soft'    => strpos($h,'--primary-soft: rgba(17,24,39')!==false ?1:0,
  'gold'    => strpos($h,'--admin-gold: #e11d48')!==false ?1:0,
]);`);
const T = await j("<?php require '/harness/theme.php';");
ok('رنگ اول در خروجی صفحه اعمال می‌شود', T.primary === 1, JSON.stringify(T));
ok('رنگ دوم در خروجی صفحه اعمال می‌شود', T.accent === 1);
ok('هاور رنگ اول محاسبه و تزریق می‌شود', T.phover === 1);
ok('هاور رنگ دوم محاسبه و تزریق می‌شود', T.ahover === 1);
ok('حالت نرم رنگ اول از همان رنگ ساخته می‌شود', T.soft === 1);
ok('admin-gold هم از رنگ دوم می‌آید', T.gold === 1);

console.log(`\n  سوئیت تگ‌ها، جستجو و رنگ‌بندی: ${pass} PASS / ${fail} FAIL`);
process.exit(fail ? 1 : 0);
