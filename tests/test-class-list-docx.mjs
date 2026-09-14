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
const strip = (s) => s.replace(/<\?php\s*\/\*[\s\S]*?\*\/\s*\?>/g, '').replace(/\/\*[\s\S]*?\*\//g, '');

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
ok('هفتم1 → 7/1', C.h1 === '7/1', String(C.h1));
ok('هفتم2 → 7/2', C.h2 === '7/2', String(C.h2));
ok('هفتم3 → 7/3', C.h3 === '7/3', String(C.h3));
ok('هشتم1 → 8/1', C.e1 === '8/1', String(C.e1));
ok('نهم2 → 9/2', C.n2 === '9/2', String(C.n2));
ok('دهم3 → 10/3', C.d3 === '10/3', String(C.d3));
ok('یازدهم1 → 11/1', C.y1 === '11/1', String(C.y1));
/* «دهم» زیررشتهٔ «دوازدهم» است؛ این مورد یک بار واقعاً اشتباه داد */
ok('دوازدهم4 → 12/4 (نه 10/4)', C.dv4 === '12/4', String(C.dv4));
ok('اگر پایه ثبت نشده باشد از نام کلاس استنتاج می‌شود', C.noGrade === '8/1', String(C.noGrade));
ok('ارقام فارسی هم پشتیبانی می‌شوند', C.faDigit === '7/2', String(C.faDigit));
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
   Array.isArray(N) && N[0] === 'آبادی بهار' && N[1] === 'ابراهیمی زهرا', JSON.stringify(N));
ok('قالب «نام خانوادگی نام» است', Array.isArray(N) && /^آبادی بهار$/.test(N[0]));
ok('فقط دانش‌آموزان همان کلاس می‌آیند',
   Array.isArray(N) && !N.some(x => x.includes('کلاس')), JSON.stringify(N));
ok('دانش‌آموز غیرفعال نمی‌آید',
   Array.isArray(N) && !N.some(x => x.includes('بایدنیاید')));
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
$doc = dcl_generate('7/1', dcl_students_of_class('هفتم1','1404/1405'));
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
  'hdrCell'   => isset($rm[0][4]) ? $cellText($rm[0][4], 1) : '',
  'n1'        => isset($rm[0][5]) ? $cellText($rm[0][5], 1) : '',
  'n2'        => isset($rm[0][6]) ? $cellText($rm[0][6], 1) : '',
  'n5'        => isset($rm[0][9]) ? $cellText($rm[0][9], 1) : '',
  'n6empty'   => isset($rm[0][10]) ? $cellText($rm[0][10], 1) : 'MISSING',
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
ok('سلول کلاس پر شد: «کلاس : 7/1»', D.classCell === 'کلاس : 7/1', String(D.classCell));
ok('سرستون «نام خانوادگی و نام» دست‌نخورده است', D.hdrCell === 'نام خانوادگی و نام', String(D.hdrCell));
ok('نام اول در ردیف اول', D.n1 === 'آبادی بهار', String(D.n1));
ok('نام دوم در ردیف دوم', D.n2 === 'ابراهیمی زهرا', String(D.n2));
ok('نام پنجم در ردیف پنجم', D.n5 === 'یوسفی آرش', String(D.n5));
ok('ردیف ششم خالی ماند (کلاس ۵ نفر دارد)', D.n6empty === '', String(D.n6empty));
ok('ردیف سی‌ام خالی ماند', D.lastEmpty === '', String(D.lastEmpty));
ok('شماره‌های ردیف دست‌نخورده‌اند', D.rowNum1 === '1' && D.rowNum30 === '30', `${D.rowNum1}/${D.rowNum30}`);
/* قالب خودش پر از «B Titr» است، پس شمردن کل فایل نگهبان نیست —
   وقتی فونت را از run تزریق‌شده حذف کردم، تست همچنان سبز ماند.
   حالا دقیقاً همان run ای که نام دانش‌آموز در آن است بررسی می‌شود. */
ok('فونت B Titr روی خودِ نام دانش‌آموز اعمال شده', D.nameRunHasTitr === 1,
   'run: ' + String(D.nameRun || '').slice(0, 120));
ok('جدول دوم (ثبت میزان تدریس) دست‌نخورده است', D.tbl2 === 1);

/* ═══ ۶) امنیت و مقاومت ═══ */
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
