/**
 * test-class-list-docx.mjs — سوئیت ۱۸ (v4.145.0)
 *
 * «لیست‌ها و گزارشات» و اولین گزارشش: لیست کلاسی دبیر در قالب Word.
 *
 * تست فایل docx واقعی را می‌سازد، بازش می‌کند و XML داخلش را بازرسی
 * می‌کند — نه اینکه فقط دنبال رشته در سورس بگردد.
 */
import { run, php } from './harness/lib.mjs';
import { resolveFile, resolveSite } from './harness/site.mjs';
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';

let pass = 0, fail = 0;
const ok = (n, c, d = '') => c ? (pass++, console.log(`  ✅ ${n}`))
                               : (fail++, console.log(`  ❌ ${n}${d ? '  → ' + d : ''}`));
const j = async (code) => {
    const out = (await run(code)).out.trim();
    try { return JSON.parse(out); } catch (e) { return { __raw: out }; }
};
// کامنت‌زدایی برای بررسی‌هایی که نباید متنِ توضیحات را کد بشمارند.
// نکتهٔ مهم: الگوی «php-tag تا پایان-کامنت» نباید اول اجرا شود — تگ
// بازکنندهٔ ابتدای فایل با نزدیک‌ترین پایانِ کامنتِ بعدی جفت می‌شد و
// ۷۷٪ فایل را می‌بلعید (اولین بار همین ۲۲ هشدار کاذب داد). پس اول
// کامنت‌های بلوکی حذف می‌شوند و بعد بقایای تگ خالی.
const strip = (src) => src
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/<\?php\s*\?>/g, '');

const page = readFileSync(resolveFile('reports-lists.php'), 'utf8');
const lib  = readFileSync(resolveFile('includes/docx_class_list.php'), 'utf8');
const hdr  = readFileSync(resolveFile('includes/header.php'), 'utf8');
const pageC = strip(page);
const libC  = strip(lib);

/* ═══ ۱) منو و صفحه ═══ */
console.log('\n══ منو و دسترسی ══');
ok('لینک منو زیر «آموزش، کلاس و دبیران» است', (() => {
    const i = hdr.indexOf('آموزش، کلاس و دبیران');
    const j2 = hdr.indexOf('sidebar-section-title', i + 10);
    return i !== -1 && hdr.slice(i, j2 === -1 ? undefined : j2).includes('reports-lists.php');
})());
ok('عنوان منو درست است', hdr.includes('لیست‌ها و گزارشات'));
ok('صفحه در همان بخش مجوز قرار دارد', pageC.includes("require_permission('manage_classes')"));
ok('عنوان صفحه درست است', pageC.includes('<h2 class="text-2xl font-bold">لیست‌ها و گزارشات</h2>'));
ok('صفحه برای افزودن گزارش‌های بعدی جا دارد', pageC.includes('گزارش‌های بعدی'));

/* ═══ ۲) قالب Word ═══ */
console.log('\n══ قالب Word ══');
const tplPath = join(resolveSite(), 'assets', 'templates', 'teacher-class-list.docx');
ok('فایل قالب در بسته هست', existsSync(tplPath));
ok('قالب یک فایل zip معتبر است', (() => {
    if (!existsSync(tplPath)) return false;
    const b = readFileSync(tplPath);
    return b[0] === 0x50 && b[1] === 0x4b;   /* PK */
})());
ok('مسیر قالب از یک تابع می‌آید (نه تکرار رشته)', libC.includes('function dcl_template_path'));

/* ═══ ۳) کد کلاس ═══ */
console.log('\n══ تبدیل نام کلاس به کد ══');
php.writeFile('/harness/code.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/school_sort.php';
require_once '/www/includes/class_schedule_sync.php';
require_once '/www/includes/docx_class_list.php';
echo json_encode([
  'h1'  => dcl_class_code('هفتم1','هفتم'),
  'h2'  => dcl_class_code('هفتم2','هفتم'),
  'h3'  => dcl_class_code('هفتم3','هفتم'),
  'e1'  => dcl_class_code('هشتم1','هشتم'),
  'n2'  => dcl_class_code('نهم2','نهم'),
  'd3'  => dcl_class_code('دهم3','دهم'),
  'y1'  => dcl_class_code('یازدهم1','یازدهم'),
  'dv4' => dcl_class_code('دوازدهم4','دوازدهم'),
  'noGrade' => dcl_class_code('هشتم1',''),
  'faDigit' => dcl_class_code('هفتم۲','هفتم'),
  'weird'   => dcl_class_code('کلاس ویژه',''),
  'empty'   => dcl_class_code('',''),
], JSON_UNESCAPED_UNICODE);`);
const C = await j("<?php require '/harness/code.php';");
ok('هفتم1 → «1/7» در XML (روی کاغذ RTL = 7/1)', C.h1 === '1/7', String(C.h1));
ok('هفتم2 → 2/7', C.h2 === '2/7', String(C.h2));
ok('هفتم3 → 3/7', C.h3 === '3/7', String(C.h3));
ok('هشتم1 → 1/8', C.e1 === '1/8', String(C.e1));
ok('نهم2 → 2/9', C.n2 === '2/9', String(C.n2));
ok('دهم3 → 3/10', C.d3 === '3/10', String(C.d3));
ok('یازدهم1 → 1/11', C.y1 === '1/11', String(C.y1));
/* «دهم» زیررشتهٔ «دوازدهم» است؛ این مورد یک بار واقعاً اشتباه داد */
ok('دوازدهم4 → «4/12» (نه 4/10)', C.dv4 === '4/12', String(C.dv4));
ok('اگر پایه ثبت نشده باشد از نام کلاس استنتاج می‌شود', C.noGrade === '1/8', String(C.noGrade));
ok('ارقام فارسی هم پشتیبانی می‌شوند', C.faDigit === '2/7', String(C.faDigit));
ok('نام نامتعارف، خودش برگردانده می‌شود (نه کد غلط)', C.weird === 'کلاس ویژه', String(C.weird));
ok('نام خالی، خروجی خالی می‌دهد', C.empty === '', String(C.empty));

/* ═══ ۴) اسامی ═══ */
console.log('\n══ اسامی دانش‌آموزان ══');
php.writeFile('/harness/names.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/school_sort.php';
require_once '/www/includes/class_schedule_sync.php';
require_once '/www/includes/docx_class_list.php';
DB::execute("DELETE FROM students WHERE national_id LIKE '5500%'");
$rows = [['آبادی','بهار'],['ابراهیمی','زهرا'],['یوسفی','آرش'],['کریمی','نگار'],['حسینی','محمد']];
$i = 0;
foreach ($rows as $r) {
  $i++;
  DB::execute("INSERT INTO students (national_id,first_name,last_name,class_name,grade_level,status,academic_year,password)
               VALUES (?,?,?,'هفتم1','هفتم','active','1404/1405','x')",
              ['5500' . str_pad((string)$i, 6, '0', STR_PAD_LEFT), $r[1], $r[0]]);
}
/* یک دانش‌آموز غیرفعال و یکی از کلاس دیگر، که نباید بیایند */
DB::execute("INSERT INTO students (national_id,first_name,last_name,class_name,grade_level,status,academic_year,password)
             VALUES ('5500999901','حذفی','بایدنیاید','هفتم1','هفتم','inactive','1404/1405','x')");
DB::execute("INSERT INTO students (national_id,first_name,last_name,class_name,grade_level,status,academic_year,password)
             VALUES ('5500999902','دیگر','کلاس','هشتم1','هشتم','active','1404/1405','x')");
echo json_encode(dcl_students_of_class('هفتم1','1404/1405'), JSON_UNESCAPED_UNICODE);`);
const N = await j("<?php require '/harness/names.php';");
ok('اسامی به ترتیب الفبای فارسی‌اند (آ پیش از ا)',
   Array.isArray(N) && N[0] && N[0].last === 'آبادی' && N[1] && N[1].last === 'ابراهیمی',
   JSON.stringify(N));
ok('نام و نام خانوادگی جدا برمی‌گردند',
   Array.isArray(N) && N[0] && N[0].last === 'آبادی' && N[0].first === 'بهار', JSON.stringify(N[0]));
ok('فقط دانش‌آموزان همان کلاس می‌آیند',
   Array.isArray(N) && !N.some(x => (x.last || '').includes('کلاس')), JSON.stringify(N));
ok('دانش‌آموز غیرفعال نمی‌آید',
   Array.isArray(N) && !N.some(x => (x.last || '').includes('بایدنیاید')));
ok('تعداد درست است', Array.isArray(N) && N.length === 5, String(N.length));

/* ═══ ۵) فایل واقعی ═══ */
console.log('\n══ فایل Word تولیدشده ══');
php.writeFile('/harness/gen.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/school_sort.php';
require_once '/www/includes/class_schedule_sync.php';
require_once '/www/includes/docx_class_list.php';
$doc = dcl_generate(dcl_class_code('هفتم1','هفتم'), dcl_students_of_class('هفتم1','1404/1405'));
if ($doc === null) { echo 'NULL'; exit; }
file_put_contents('/tmp/gen.docx', $doc);
/* بازش کن و XML را بیرون بده */
$z = new ZipArchive();
if ($z->open('/tmp/gen.docx') !== true) { echo 'BADZIP'; exit; }
$xml = $z->getFromName('word/document.xml');
$n   = $z->numFiles;
$z->close();
preg_match_all('/<w:tbl>.*?<\\\\/w:tbl>/s', $xml, $tm);
$rows = [];
if (isset($tm[0][0])) preg_match_all('/<w:tr[ >].*?<\\\\/w:tr>/s', $tm[0][0], $rm);
/* خودِ <w:r> ای که نام اولین دانش‌آموز در آن است */
$nameRun = '';
if (isset($rm[0][5])) {
    preg_match_all('/<w:tc>.*?<\\/w:tc>/s', $rm[0][5], $c5);
    if (isset($c5[0][1]) && preg_match('/<w:r>.*?<\\/w:r>/s', $c5[0][1], $r5)) $nameRun = $r5[0];
}
$gridCount = 0; $gw1 = ''; $gw2 = ''; $gridSum = 0;
if (preg_match('/<w:tblGrid>.*?<\\/w:tblGrid>/s', $tm[0][0], $gm)) {
    preg_match_all('/w:w="(\\d+)"/', $gm[0], $gwm);
    $gridCount = count($gwm[1]);
    $gridSum = array_sum(array_map('intval', $gwm[1]));
    $gw1 = $gwm[1][1] ?? ''; $gw2 = $gwm[1][2] ?? '';
}
$cellCount = function ($tr) {
    preg_match_all('/<w:tc>.*?<\\/w:tc>/s', $tr, $c);
    return count($c[0]);
};
$cellText = function ($tr, $idx) {
    preg_match_all('/<w:tc>.*?<\\\\/w:tc>/s', $tr, $cm);
    if (!isset($cm[0][$idx])) return '';
    preg_match_all('/<w:t[^>]*>([^<]*)<\\\\/w:t>/', $cm[0][$idx], $t);
    return trim(implode('', $t[1]));
};
echo json_encode([
  'size'      => strlen($doc),
  'entries'   => $n,
  'tables'    => count($tm[0]),
  'rows'      => isset($rm[0]) ? count($rm[0]) : 0,
  'classCell' => isset($rm[0][0]) ? $cellText($rm[0][0], 1) : '',
  'r0span'    => (preg_match('/<w:gridSpan w:val="(\\d+)"/', $rm[0][0], $sm0) ? $sm0[1] : '?'),
  'gridCols'  => $gridCount,
  'gridSum'   => $gridSum,
  'gridW1'    => $gw1,
  'gridW2'    => $gw2,
  'hdrLast'   => isset($rm[0][4]) ? $cellText($rm[0][4], 1) : '',
  'hdrFirst'  => isset($rm[0][4]) ? $cellText($rm[0][4], 2) : '',
  'r5cells'   => isset($rm[0][5]) ? $cellCount($rm[0][5]) : 0,
  'n1last'    => isset($rm[0][5]) ? $cellText($rm[0][5], 1) : '',
  'n1first'   => isset($rm[0][5]) ? $cellText($rm[0][5], 2) : '',
  'n2last'    => isset($rm[0][6]) ? $cellText($rm[0][6], 1) : '',
  'n5last'    => isset($rm[0][9]) ? $cellText($rm[0][9], 1) : '',
  'n5first'   => isset($rm[0][9]) ? $cellText($rm[0][9], 2) : '',
  'n6empty'   => isset($rm[0][10]) ? $cellText($rm[0][10], 1) : 'MISSING',
  'n6first'   => isset($rm[0][10]) ? $cellText($rm[0][10], 2) : 'MISSING',
  'lastEmpty' => isset($rm[0][34]) ? $cellText($rm[0][34], 1) : 'MISSING',
  'rowNum1'   => isset($rm[0][5]) ? $cellText($rm[0][5], 0) : '',
  'rowNum30'  => isset($rm[0][34]) ? $cellText($rm[0][34], 0) : '',
  'titrRuns'  => substr_count($xml, 'B Titr'),
  'nameRun'   => $nameRun,
  'nameRunHasTitr' => (strpos($nameRun, 'B Titr') !== false) ? 1 : 0,
  'tbl2'      => isset($tm[0][1]) && strpos($tm[0][1], 'جدول ثبت میزان تدریس') !== false ? 1 : 0,
], JSON_UNESCAPED_UNICODE);`);
const D = await j("<?php require '/harness/gen.php';");
ok('فایل docx ساخته شد', typeof D.size === 'number' && D.size > 10000, JSON.stringify(D).slice(0, 160));
ok('فایل zip معتبر با اجزای کامل است', D.entries >= 8, String(D.entries));
ok('هر دو جدول قالب حفظ شده‌اند', D.tables === 2, String(D.tables));
ok('جدول اول ۳۵ ردیف دارد (۵ سرستون + ۳۰ دانش‌آموز)', D.rows === 35, String(D.rows));
ok('سلول کلاس پر شد: «کلاس : 1/7»', D.classCell === 'کلاس : 1/7', String(D.classCell));
/* v4.146.0 — ستون نام به دو ستون تقسیم شد */
/* v4.147.0 — یک ستون جلسه حذف شد و عرضش به دو ستون نام رسید، پس
   تعداد کل ستون‌ها همان ۱۶ می‌ماند و gridSpan سربرگ هم دست‌نخورده. */
ok('تعداد ستون‌ها همان ۱۶ ماند (یکی اضافه، یکی حذف)', D.gridCols === 16, String(D.gridCols));
ok('ستون‌های نام پهن‌تر شدند', D.gridW1 === '1563' && D.gridW2 === '1252',
   `${D.gridW1}/${D.gridW2}`);
ok('ستون‌های نام از نسخهٔ قبل پهن‌ترند',
   parseInt(D.gridW1, 10) > 1256 && parseInt(D.gridW2, 10) > 1000);
ok('عرض کل جدول تغییر نکرده (از کاغذ بیرون نمی‌زند)',
   D.gridSum === 10652, String(D.gridSum));
ok('gridSpan سربرگ بالا دست‌نخورده ماند (۱۳)', D.r0span === '13', String(D.r0span));
ok('سرستون راست «نام خانوادگی» است', D.hdrLast === 'نام خانوادگی', String(D.hdrLast));
ok('سرستون بعدی «نام» است', D.hdrFirst === 'نام', String(D.hdrFirst));
ok('هر ردیف ۱۶ سلول دارد', D.r5cells === 16, String(D.r5cells));
ok('نام خانوادگی اول در ستون راست', D.n1last === 'آبادی', String(D.n1last));
ok('نام اول در ستون کناری', D.n1first === 'بهار', String(D.n1first));
ok('نام خانوادگی دوم درست است', D.n2last === 'ابراهیمی', String(D.n2last));
ok('نام پنجم درست است', D.n5last === 'یوسفی' && D.n5first === 'آرش',
   `${D.n5last}/${D.n5first}`);
ok('ردیف ششم خالی ماند (کلاس ۵ نفر دارد)', D.n6empty === '', String(D.n6empty));
ok('ستون نام ردیف ششم هم خالی است', D.n6first === '', String(D.n6first));
ok('ردیف سی‌ام خالی ماند', D.lastEmpty === '', String(D.lastEmpty));
ok('شماره‌های ردیف دست‌نخورده‌اند', D.rowNum1 === '1' && D.rowNum30 === '30', `${D.rowNum1}/${D.rowNum30}`);
/* قالب خودش پر از «B Titr» است، پس شمردن کل فایل نگهبان نیست —
   وقتی فونت را از run تزریق‌شده حذف کردم، تست همچنان سبز ماند.
   حالا دقیقاً همان run ای که نام دانش‌آموز در آن است بررسی می‌شود. */
ok('فونت B Titr روی خودِ نام دانش‌آموز اعمال شده', D.nameRunHasTitr === 1,
   'run: ' + String(D.nameRun || '').slice(0, 120));
ok('جدول دوم (ثبت میزان تدریس) دست‌نخورده است', D.tbl2 === 1);

/* ═══ ۶) امنیت و مقاومت ═══ */
console.log('\n══ جا شدن نام‌های بلند (v4.147.0) ══');
/* مشکل گزارش‌شده: نام‌های چندکلمه‌ای در ستون باریک به خط دوم
   می‌رفتند و ارتفاع ردیف را می‌شکستند، که صفحه‌بندی دو صفحه‌ای را
   به‌هم می‌ریخت. رفع: حذف یک ستون جلسه و دادن عرضش به دو ستون نام. */
ok('یک ستون جلسه حذف شده (تعداد ستون ثابت مانده)', libC.includes('$DROP_W'));
ok('عرض ستون حذف‌شده به دو ستون نام رسیده', (() => {
    const l = libC.match(/\$W_LAST\s*=\s*(\d+)/);
    const f = libC.match(/\$W_FIRST\s*=\s*(\d+)/);
    const d = libC.match(/\$DROP_W\s*=\s*(\d+)/);
    if (!l || !f || !d) return false;
    /* ۱۲۵۶+۱۰۰۰ قبلی + ۵۵۹ آزادشده = عرض جدید دو ستون */
    return (parseInt(l[1]) + parseInt(f[1])) === (1256 + 1000 + parseInt(d[1]));
})(), (libC.match(/\$W_LAST\s*=\s*(\d+)/) || [])[1] + '/' + (libC.match(/\$W_FIRST\s*=\s*(\d+)/) || [])[1]);
ok('نام خانوادگی سهم بیشتری گرفته (بلندتر است)', (() => {
    const l = parseInt((libC.match(/\$W_LAST\s*=\s*(\d+)/) || [])[1], 10);
    const f = parseInt((libC.match(/\$W_FIRST\s*=\s*(\d+)/) || [])[1], 10);
    return l > f;
})());
ok('سلول دارای vMerge قربانی حذف نمی‌شود (ادغام عمودی نمی‌شکند)',
   libC.includes('vMerge') && libC.includes("w:val=\"restart\""));
/* تعداد ستون جلسه حالا از خود شبکه حساب می‌شود، نه عدد ثابت — پس
   اگر شبکه عوض شود خودکار درست می‌ماند. */
ok('نمای PDF ستون‌ها را از شبکه حساب می‌کند، نه عدد ثابت',
   /\$nCols\s*=\s*count\(\$grid\)/.test(libC));
ok('عرض ستون‌های PDF از همان شبکهٔ Word می‌آید',
   libC.includes('function dcl_print_grid') && /\$pc\[\]\s*=\s*round\(\$w \* 100 \/ \$total,/.test(libC));

/* نام واقعاً بلند نباید ساختار را بشکند */
php.writeFile('/harness/longname.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/school_sort.php';
require_once '/www/includes/class_schedule_sync.php';
require_once '/www/includes/docx_class_list.php';
$doc = dcl_generate('1/9', [
  ['last' => 'حسینی نژاد اصفهانی', 'first' => 'محمدرضا'],
  ['last' => 'آبادی',              'first' => 'بهار'],
]);
if ($doc === null) { echo 'NULL'; exit; }
file_put_contents('/tmp/long.docx', $doc);
$z = new ZipArchive(); $z->open('/tmp/long.docx');
$xml = $z->getFromName('word/document.xml'); $z->close();
preg_match_all('/<w:tbl>.*?<\\/w:tbl>/s', $xml, $tm);
preg_match_all('/<w:tr[ >].*?<\\/w:tr>/s', $tm[0][0], $rm);
$nc = function ($tr) { preg_match_all('/<w:tc>.*?<\\/w:tc>/s', $tr, $c); return count($c[0]); };
$ct = function ($tr, $i) {
    preg_match_all('/<w:tc>.*?<\\/w:tc>/s', $tr, $c);
    if (!isset($c[0][$i])) return 'MISSING';
    preg_match_all('/<w:t[^>]*>([^<]*)<\\/w:t>/', $c[0][$i], $t);
    return trim(implode('', $t[1]));
};
$counts = array_map($nc, array_slice($rm[0], 1));
echo json_encode([
  'longLast'  => $ct($rm[0][5], 1),
  'longFirst' => $ct($rm[0][5], 2),
  'allRowsSameCells' => count(array_unique($counts)) === 1 ? 1 : 0,
  'cellCount' => $counts[0],
  'rows'      => count($rm[0]),
  'tables'    => count($tm[0]),
], JSON_UNESCAPED_UNICODE);`);
const L = await j("<?php require '/harness/longname.php';");
ok('نام خانوادگی سه‌کلمه‌ای کامل درج می‌شود',
   L.longLast === 'حسینی نژاد اصفهانی', String(L.longLast));
ok('نام کنارش درست است', L.longFirst === 'محمدرضا', String(L.longFirst));
ok('همهٔ ردیف‌ها تعداد سلول یکسان دارند (ساختار نشکسته)',
   L.allRowsSameCells === 1, JSON.stringify(L));
ok('هر ردیف ۱۶ سلول دارد', L.cellCount === 16, String(L.cellCount));
ok('جدول همچنان ۳۵ ردیف و ۲ جدول است',
   L.rows === 35 && L.tables === 2, `${L.rows}/${L.tables}`);

console.log('\n══ خروجی PDF (v4.146.0) ══');
/* PDF از مسیر مرورگر ساخته می‌شود، چون TCPDF در بسته نیست و برای
   فارسی به فونت تبدیل‌شده نیاز دارد؛ هر خطا در آن مسیر به‌جای فونت
   تیتر مربع خالی چاپ می‌کند. */
ok('اکشن PDF در صفحه هست', pageC.includes("class_list_pdf"));
ok('دکمهٔ PDF برای هر کلاس نمایش داده می‌شود', /action=class_list_pdf&class=/.test(pageC));
ok('PDF به قالب docx وابسته نیست', (() => {
    const i = pageC.indexOf("$action === 'class_list_pdf'");
    const j = pageC.indexOf("if ($action === 'class_list_all'", i);
    const seg = pageC.slice(i, j === -1 ? i + 900 : j);
    return !seg.includes('$templateOk') && !seg.includes('dcl_generate');
})());
ok('تابع رندر چاپی وجود دارد', libC.includes('function dcl_render_print_html'));
ok('صفحهٔ PDF در استثنای چاپ دسکتاپ هست',
   readFileSync(resolveFile('assets/js/desk-shell.js'), 'utf8').includes('reports-lists.php'));

php.writeFile('/harness/pdf.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/school_sort.php';
require_once '/www/includes/class_schedule_sync.php';
require_once '/www/includes/docx_class_list.php';
$html = dcl_render_print_html(dcl_class_code('نهم1','نهم'), dcl_students_of_class('هفتم1','1404/1405'), 'مدرسه', false);
/* شمارش ردیف‌های دانش‌آموز و بررسی فونت */
preg_match('/@font-face\\{[^}]*\\}/', $html, $ff);
echo json_encode([
  'len'        => strlen($html),
  'sheets'     => substr_count($html, 'class="sheet"'),
  'fontface'   => isset($ff[0]) ? $ff[0] : '',
  'usesTitrTtf'=> (isset($ff[0]) && strpos($ff[0], 'B-Titr/B-Titr.ttf') !== false) ? 1 : 0,
  'bodyFont'   => (strpos($html, "font-family:'BTitr'") !== false) ? 1 : 0,
  'rows'       => substr_count($html, 'class="r nm"') / 2,
  'hdrLast'    => (strpos($html, '>نام خانوادگی<') !== false) ? 1 : 0,
  'hdrFirst'   => (strpos($html, '>نام<') !== false) ? 1 : 0,
  'hasClass'   => (strpos($html, 'کلاس : 1/9') !== false) ? 1 : 0,
  'tbl2'       => (strpos($html, 'جدول ثبت میزان تدریس') !== false) ? 1 : 0,
  'portrait'   => (strpos($html, 'size:A4 portrait') !== false) ? 1 : 0,
  'firstName'  => (strpos($html, '>آبادی<') !== false) ? 1 : 0,
  'escaped'    => (strpos($html, '<script>alert') === false) ? 1 : 0,
], JSON_UNESCAPED_UNICODE);`);
const P = await j("<?php require '/harness/pdf.php';");
ok('نمای چاپی تولید می‌شود', typeof P.len === 'number' && P.len > 5000, JSON.stringify(P).slice(0, 140));
ok('دو صفحه دارد (مثل قالب Word)', P.sheets === 2, String(P.sheets));
ok('@font-face تعریف شده', P.fontface !== '', String(P.fontface).slice(0, 80));
ok('فونت از همان فایل B-Titr بسته می‌آید', P.usesTitrTtf === 1);
ok('کل متن با فونت تیتر است', P.bodyFont === 1);
ok('جدول ۳۰ ردیف دانش‌آموز دارد', P.rows === 30, String(P.rows));
ok('سرستون «نام خانوادگی» هست', P.hdrLast === 1);
ok('سرستون «نام» هست', P.hdrFirst === 1);
ok('کد کلاس با ترتیب RTL درست است', P.hasClass === 1);
ok('صفحهٔ دوم «ثبت میزان تدریس» را دارد', P.tbl2 === 1);
/* v4.149.0 — قالب Word صریحاً A4 «عمودی» است (pgSz 11906×16838)؛
   حالت افقی اشتباه من بود و کارفرما گرفتش. */
ok('کاغذ A4 عمودی تنظیم شده', P.portrait === 1, String(P.portrait));
ok('اسامی واقعی درج شده‌اند', P.firstName === 1);
ok('خروجی HTML امن‌سازی می‌شود', P.escaped === 1);
ok('چاپ تا آماده‌شدن فونت صبر می‌کند', libC.includes('document.fonts.ready'));
ok('اگر فونت نیامد چاپ گیر نمی‌کند', /setTimeout\(go, 3000\)/.test(libC));

console.log('\n══ padding و تطابق PDF با Word (v4.148.0) ══');
ok('تابع کم‌کردن حاشیهٔ سلول وجود دارد', libC.includes('function dcl_tighten_cell'));
ok('حاشیهٔ افقی سلول نام کم شده', /<w:left w:w="28" w:type="dxa"\/>/.test(libC));
ok('حاشیه روی هر دو ستون نام اعمال می‌شود',
   (libC.match(/dcl_tighten_cell\(/g) || []).length >= 3);
ok('tcMar پیش از vAlign درج می‌شود (ترتیب معتبر OOXML)',
   libC.includes("preg_replace('/(<w:vAlign)/'"));
ok('شبکهٔ عرض PDF از یک منبع می‌آید', libC.includes('function dcl_print_grid'));
ok('پیش‌نمایش پیش‌فرض بدون چاپ خودکار باز می‌شود',
   pageC.includes("$autoPrint = (($_GET['auto'] ?? '0') === '1')"));
ok('ویرایشگر زنده در نمای چاپ هست', libC.includes('id="editor"'));
ok('ویرایشگر اندازهٔ متن و ارتفاع ردیف دارد',
   libC.includes('id="cFont"') && libC.includes('id="cRow"'));
ok('تنظیمات ویرایشگر ذخیره می‌شود', libC.includes('mtag_classlist_editor_v1'));
ok('ویرایشگر در چاپ دیده نمی‌شود', /@media print\{[\s\S]{0,200}\.noprint\{display:none!important\}/.test(libC));
ok('عنوان‌های اختراعی حذف شدند',
   !libC.includes('جمع غیبت') && !libC.includes('نمره مستمر') && !/>ملاحظات</.test(libC));

/* تطابق واقعی: نسبت ستون‌های PDF باید مو به مو با Word یکی باشد */
php.writeFile('/harness/match.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/school_sort.php';
require_once '/www/includes/class_schedule_sync.php';
require_once '/www/includes/docx_class_list.php';
$names = [
  ['last' => 'حسینی نژاد اصفهانی', 'first' => 'محمدرضا'],
  ['last' => 'آبادی',              'first' => 'بهار'],
];
$doc = dcl_generate('1/9', $names);
file_put_contents('/tmp/m.docx', $doc);
$z = new ZipArchive(); $z->open('/tmp/m.docx');
$xml = $z->getFromName('word/document.xml'); $z->close();
preg_match_all('/<w:tbl>.*?<\\/w:tbl>/s', $xml, $tm);
preg_match('/<w:tblGrid>.*?<\\/w:tblGrid>/s', $tm[0][0], $gm);
preg_match_all('/w:w="(\\d+)"/', $gm[0], $gw);
$wordCols = array_map('intval', $gw[1]);
$sum = array_sum($wordCols);
$wordPct = array_map(function ($w) use ($sum) { return round($w * 100 / $sum, 4); }, $wordCols);

$html = dcl_render_print_html('1/9', $names, '', false);
$cg = '';
if (preg_match('/<colgroup>.*?<\\/colgroup>/s', $html, $c)) $cg = $c[0];
preg_match_all('/<col style="width:([\\d.]+)%">/', $cg, $cm);
$htmlPct = array_map('floatval', $cm[1]);

/* شمارش سلول هر ردیف در HTML جدول اول */
/* جدول حالا style عرض دارد، پس <table> خالی دیگر تطابق نمی‌کند. */
preg_match('/<table[^>]*>.*?<\\/table>/s', $html, $t1);
preg_match_all('/<tr>.*?<\\/tr>/s', $t1[0], $trs);
$dataRow = '';
foreach ($trs[0] as $tr) { if (strpos($tr, 'حسینی نژاد') !== false) { $dataRow = $tr; break; } }
if ($dataRow === '') { foreach ($trs[0] as $tr) { if (strpos($tr, 'class="r nm"') !== false) { $dataRow = $tr; break; } } }
echo json_encode([
  'wordCols'  => count($wordCols),
  'htmlCols'  => count($htmlPct),
  'same'      => ($wordPct === $htmlPct) ? 1 : 0,
  'gridFnOk'  => (dcl_print_grid() === $wordCols) ? 1 : 0,
  'dataCells' => $dataRow === '' ? 0 : substr_count($dataRow, '<td'),
  'tcMar'     => substr_count($xml, '<w:tcMar>'),
  'htmlRows'  => substr_count($html, 'class="r nm"') / 2,
  'sheets'    => substr_count($html, 'class="sheet"'),
], JSON_UNESCAPED_UNICODE);`);
const M = await j("<?php require '/harness/match.php';");
ok('تعداد ستون PDF و Word یکی است', M.wordCols === M.htmlCols && M.wordCols === 16,
   `${M.wordCols}/${M.htmlCols}`);
ok('نسبت عرض هر ستون در PDF دقیقاً مثل Word است', M.same === 1, JSON.stringify(M));
ok('شبکهٔ PDF با شبکهٔ واقعی فایل Word یکی است', M.gridFnOk === 1);
ok('هر ردیف داده در PDF ۱۶ سلول دارد', M.dataCells === 16, String(M.dataCells));
ok('حاشیهٔ سلول‌ها در فایل Word اعمال شده', M.tcMar > 0, String(M.tcMar));
ok('PDF هم ۳۰ ردیف دانش‌آموز دارد', M.htmlRows === 30, String(M.htmlRows));
ok('PDF دو برگه است', M.sheets === 2, String(M.sheets));

console.log('\n══ A4 عمودی و جدول دعوت از اولیا (v4.149.0) ══');
/* سه ایراد گزارش‌شده: کاغذ افقی بود، ابعاد با Word نمی‌خواند، و
   جدول «دعوت از اولیا» اصلاً در PDF نبود. */
ok('ابعاد صفحه از sectPr قالب می‌آید', libC.includes('function dcl_print_page'));
ok('شبکهٔ جدول دوم هم تعریف شده', libC.includes('function dcl_print_grid2'));
ok('جدول دوم ۷ ستون دارد (مثل قالب)',
   /return \[773, 1409, 559, 1129, 3770, 1415, 1600\];/.test(libC));

php.writeFile('/harness/p2.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/school_sort.php';
require_once '/www/includes/class_schedule_sync.php';
require_once '/www/includes/docx_class_list.php';
$html = dcl_render_print_html('1/9', [['last'=>'آبادی','first'=>'بهار']], '', false);
/* قالب واقعی */
$z = new ZipArchive(); $z->open('/www/assets/templates/teacher-class-list.docx');
$xml = $z->getFromName('word/document.xml'); $z->close();
preg_match('/<w:pgSz w:w="(\\d+)" w:h="(\\d+)"/', $xml, $pg);
preg_match('/<w:pgMar w:top="(\\d+)" w:right="(\\d+)" w:bottom="(\\d+)" w:left="(\\d+)"/', $xml, $mg);
preg_match_all('/<w:tbl>.*?<\\/w:tbl>/s', $xml, $tm);
preg_match('/<w:tblGrid>.*?<\\/w:tblGrid>/s', $tm[0][1], $g2);
preg_match_all('/w:w="(\\d+)"/', $g2[0], $gw2);
$tplG2 = array_map('intval', $gw2[1]);
$sum2 = array_sum($tplG2);
$tplPct2 = array_map(function ($w) use ($sum2) { return round($w * 100 / $sum2, 4); }, $tplG2);
preg_match_all('/<colgroup>.*?<\\/colgroup>/s', $html, $cgs);
preg_match_all('/<col style="width:([\\d.]+)%">/', $cgs[0][1] ?? '', $c2);
$h2 = array_map('floatval', $c2[1]);
preg_match('/\\.sheet\\{\\s*width:([\\d.]+)mm/', $html, $sw);
preg_match('/min-height:([\\d.]+)mm/', $html, $sh);
echo json_encode([
  'portrait'   => strpos($html, 'size:A4 portrait') !== false ? 1 : 0,
  'noLandscape'=> strpos($html, 'landscape') === false ? 1 : 0,
  'pageW'      => isset($sw[1]) ? (float)$sw[1] : 0,
  'pageH'      => isset($sh[1]) ? (float)$sh[1] : 0,
  'tplW'       => round((int)$pg[1] / 56.7, 1),
  'tplH'       => round((int)$pg[2] / 56.7, 1),
  'grid2Match' => ($tplPct2 === $h2) ? 1 : 0,
  'olia'       => substr_count($html, 'دعوت از اولیا'),
  'oliaCols'   => (strpos($html, 'نام دانش آموز') !== false
                   && strpos($html, 'علت دعوت') !== false
                   && strpos($html, 'نتیجه') !== false) ? 1 : 0,
  'tadris'     => substr_count($html, 'جدول ثبت میزان تدریس'),
  'tplOlia'    => substr_count($xml, 'دعوت از اولیا'),
  'sheets'     => substr_count($html, 'class="sheet"'),
], JSON_UNESCAPED_UNICODE);`);
const A = await j("<?php require '/harness/p2.php';");
ok('کاغذ A4 عمودی است', A.portrait === 1, JSON.stringify(A).slice(0, 120));
ok('هیچ اثری از حالت افقی نمانده', A.noLandscape === 1);
ok('عرض برگه با قالب یکی است', Math.abs(A.pageW - A.tplW) < 0.6, `${A.pageW} vs ${A.tplW}`);
ok('ارتفاع برگه با قالب یکی است', Math.abs(A.pageH - A.tplH) < 0.6, `${A.pageH} vs ${A.tplH}`);
ok('نسبت ستون‌های جدول دوم دقیقاً مثل Word است', A.grid2Match === 1);
ok('جدول «دعوت از اولیا» در قالب هست', A.tplOlia >= 1, String(A.tplOlia));
ok('جدول «دعوت از اولیا» در PDF هم آمد', A.olia >= 1, String(A.olia));
ok('سرستون‌های دعوت از اولیا کامل‌اند', A.oliaCols === 1);
ok('«ثبت میزان تدریس» هم سر جایش است', A.tadris >= 1, String(A.tadris));
ok('خروجی دو برگه است', A.sheets === 2, String(A.sheets));

console.log('\n══ امنیت و حالت‌های مرزی ══');
ok('نام دانش‌آموز برای XML امن‌سازی می‌شود', libC.includes('function dcl_xml_escape'));
ok('از htmlspecialchars با ENT_XML1 استفاده می‌شود', libC.includes('ENT_XML1'));
ok('نبودِ قالب باعث خطای مهلک نمی‌شود', libC.includes('if (!is_file($tpl)') && libC.includes('return null'));
ok('نبودِ ZipArchive هم مدیریت شده', libC.includes("class_exists('ZipArchive')"));
ok('صفحه هم پیش‌نیازها را بررسی می‌کند', pageC.includes('$templateOk') && pageC.includes('$zipOk'));
ok('فایل موقت پاک می‌شود', (libC.match(/@unlink\(\$tmp\)/g) || []).length >= 3);
/* در سورس PHP کوتیشن‌ها فرار داده شده‌اند (\\'\\')، پس جست‌وجوی
   رشتهٔ خام کار نمی‌کند. */
ok('نام فایل دانلود برای یونیکد درست انکود می‌شود',
   /filename\*=UTF-8/.test(pageC) && (pageC.match(/rawurlencode\(\$fname\)/g) || []).length >= 4);

php.writeFile('/harness/edge.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/school_sort.php';
require_once '/www/includes/class_schedule_sync.php';
require_once '/www/includes/docx_class_list.php';
/* نامی با کاراکتر خطرناک XML */
$doc = dcl_generate('7/1', ['تست <&> "نقل"', 'دومی']);
if ($doc === null) { echo 'NULL'; exit; }
file_put_contents('/tmp/edge.docx', $doc);
$z = new ZipArchive(); $z->open('/tmp/edge.docx');
$xml = $z->getFromName('word/document.xml'); $z->close();
/* بیش از ۳۰ نام: نباید بشکند */
$many = [];
for ($i = 1; $i <= 40; $i++) $many[] = 'دانش‌آموز ' . $i;
$doc2 = dcl_generate('9/9', $many);
$z2 = new ZipArchive(); file_put_contents('/tmp/many.docx', $doc2); $z2->open('/tmp/many.docx');
$xml2 = $z2->getFromName('word/document.xml'); $z2->close();
preg_match_all('/<w:tbl>.*?<\\\\/w:tbl>/s', $xml2, $tm2);
preg_match_all('/<w:tr[ >].*?<\\\\/w:tr>/s', $tm2[0][0], $rm2);
echo json_encode([
  'escaped'   => strpos($xml, '&lt;&amp;&gt;') !== false ? 1 : 0,
  'noRawAmp'  => preg_match('/<w:t[^>]*>[^<]*<&>/', $xml) ? 0 : 1,
  'many_ok'   => $doc2 !== null ? 1 : 0,
  'many_rows' => count($rm2[0]),
  'empty_ok'  => dcl_generate('7/1', []) !== null ? 1 : 0,
], JSON_UNESCAPED_UNICODE);`);
const E = await j("<?php require '/harness/edge.php';");
ok('کاراکترهای خطرناک XML امن می‌شوند', E.escaped === 1, JSON.stringify(E));
ok('هیچ کاراکتر خام <&> در XML نمی‌ماند', E.noRawAmp === 1);
ok('بیش از ۳۰ دانش‌آموز فایل را نمی‌شکند', E.many_ok === 1);
ok('جدول همچنان ۳۵ ردیف می‌ماند (سرریز نمی‌کند)', E.many_rows === 35, String(E.many_rows));
ok('کلاس بدون دانش‌آموز هم فایل معتبر می‌دهد', E.empty_ok === 1);
ok('صفحه به کاربر دربارهٔ بیش از ۳۰ نفر هشدار می‌دهد', pageC.includes('بیش از ۳۰'));

console.log(`\n  سوئیت لیست کلاسی Word: ${pass} PASS / ${fail} FAIL`);
process.exit(fail ? 1 : 0);
