// فرم‌های اداری از قالب‌های .mrt — v4.176.0
import assert from 'node:assert/strict';
import { run, req, loginAdmin } from './harness/lib.mjs';

let n = 0;
const check = (v, m) => { assert(v, m); n++; };

/* داده: یک کلاس با ۳ دانش‌آموز */
await run(String.raw`<?php require_once '/www/includes/functions.php';
DB::execute("DELETE FROM students WHERE national_id LIKE '066%'");
foreach ([['آرش','کاظمی','پدر۱','06600000001'],['بهنام','احمدی','پدر۲','06600000002'],['پریسا','رضایی','پدر۳','06600000003']] as $i => $st)
  DB::execute("INSERT INTO students (first_name,last_name,father_name,national_id,class_name,grade_level,academic_year,status,password) VALUES (?,?,?,?,?,?,?,?,?)",
    [$st[0],$st[1],$st[2],$st[3],'۸/۱','هشتم','1403/1404','active','x']);
set_setting('school_name','دبیرستان آزمایشی راهنما');
set_setting('current_academic_year','1403/1404');
echo 'SEEDED';`);

/* ── ۱) برگهٔ حضور و غیاب ─────────────────────────────────────────── */
const absents = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/school_forms.php';
$ctx = sf_context();
$students = sf_students_of_class('۸/۱','1403/1404');
echo 'count=' . count($students) . ';';
$html = sf_render_absents_form('۸/۱','هشتم',$students,$ctx,false);
echo 'sheets=' . substr_count($html,'class="sheet"') . ';';
echo 'title=' . (strpos($html,'برگه حضور و غیاب') !== false ? 'yes':'no') . ';';
echo 'periods=' . (strpos($html,'زنگ اول') !== false && strpos($html,'زنگ دوم') !== false && strpos($html,'زنگ سوم') !== false && strpos($html,'زنگ چهارم') !== false ? 4 : 0) . ';';
echo 'rows=' . substr_count($html,'height:22mm') . ';';
echo 'sign=' . substr_count($html,'امضای دبیر') . ';';
echo 'school=' . (strpos($html,'دبیرستان آزمایشی راهنما') !== false ? 'yes':'no') . ';';
echo 'year=' . (strpos($html,'۱۴۰۳/۱۴۰۴') !== false ? 'yes':'no') . ';';`);
check(absents.out.includes('count=3'), 'the class students are found');
check(absents.out.includes('sheets=1'), 'one sheet per class');
check(absents.out.includes('title=yes'), 'the attendance sheet has the original title');
check(absents.out.includes('periods=4'), 'four period columns like the original: ' + absents.out.match(/periods=\d+/)[0]);
check(absents.out.includes('rows=24'), 'each period has 6 empty rows (4×6): ' + absents.out.match(/rows=\d+/)[0]);
check(absents.out.includes('sign=4'), 'a teacher-signature line under every period');
check(absents.out.includes('school=yes') && absents.out.includes('year=yes'), 'school name and academic year in the header');

/* ── ۲) فرم تحویل و دریافت کتاب ───────────────────────────────────── */
const amanat = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/school_forms.php';
$students = sf_students_of_class('۸/۱','1403/1404');
$html = sf_render_amanat_form($students, sf_context(), false);
echo 'sheets=' . substr_count($html,'class="sheet"') . ';';
echo 'title=' . (strpos($html,'فرم ثبت تحویل و دریافت کتاب') !== false ? 'yes':'no') . ';';
echo 'rows=' . substr_count($html,'height:16mm') . ';';
foreach (['نام کتاب','کد کتاب و قفسه','تاریخ دریافت','تاریخ تحویل','مبلغ جریمه دیرکرد','ملاحظات'] as $c)
  echo 'col[' . $c . ']=' . (strpos($html,$c) !== false ? 'yes':'no') . ';';
echo 'student=' . (strpos($html,'آرش کاظمی') !== false ? 'yes':'no') . ';';`);
check(amanat.out.includes('sheets=3'), 'one sheet per student: ' + amanat.out.match(/sheets=\d+/)[0]);
check(amanat.out.includes('title=yes'), 'the book form keeps the original title');
check(amanat.out.includes('rows=36'), '12 rows per student sheet (3×12): ' + amanat.out.match(/rows=\d+/)[0]);
check(/col\[نام کتاب\]=yes/.test(amanat.out) && /col\[کد کتاب و قفسه\]=yes/.test(amanat.out) && /col\[تاریخ دریافت\]=yes/.test(amanat.out) && /col\[تاریخ تحویل\]=yes/.test(amanat.out) && /col\[مبلغ جریمه دیرکرد\]=yes/.test(amanat.out) && /col\[ملاحظات\]=yes/.test(amanat.out),
  'all seven original columns are present: ' + amanat.out.slice(0, 260));
check(amanat.out.includes('student=yes'), 'each sheet carries the student name');

/* ── ۳) دعوتنامهٔ انجمن ───────────────────────────────────────────── */
const anjoman = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/school_forms.php';
$students = sf_students_of_class('۸/۱','1403/1404');
$html = sf_render_anjoman_letter($students, sf_context(), false);
echo 'sheets=' . substr_count($html,'class="sheet"') . ';';
echo 'title=' . (strpos($html,'دعوتنامه جلسهٔ عمومی انجمن اولیاء و مربیان') !== false ? 'yes':'no') . ';';
echo 'programs=' . (substr_count($html,'<li>') / max(1,substr_count($html,'class="sheet"'))) . ';';
echo 'member=' . (strpos($html,'نام و نام خانوادگی:') !== false && strpos($html,'شماره تماس:') !== false ? 'yes':'no') . ';';
echo 'parent=' . (strpos($html,'ولی محترم دانش‌آموز') !== false ? 'yes':'no') . ';';`);
check(anjoman.out.includes('sheets=3'), 'one letter per student');
check(anjoman.out.includes('title=yes'), 'the invitation keeps the original title');
check(anjoman.out.includes('programs=3'), 'the three agenda items from the original template: ' + anjoman.out.match(/programs=\d+/)[0]);
check(anjoman.out.includes('member=yes'), 'the membership block (name, phone) is there');
check(anjoman.out.includes('parent=yes'), 'the letter is addressed to the parent');

/* ── ۴) رضایت‌نامهٔ اردو ──────────────────────────────────────────── */
const camp = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/school_forms.php';
$students = sf_students_of_class('۸/۱','1403/1404');
$html = sf_render_camp_consent($students, sf_context(), false);
echo 'sheets=' . substr_count($html,'class="sheet"') . ';';
echo 'title=' . (strpos($html,'رضایت‌نامه اردو') !== false ? 'yes':'no') . ';';
preg_match_all('/\.{6,}/', $html, $m);
echo 'blanks=' . (count($m[0]) / max(1,substr_count($html,'class="sheet"'))) . ';';
echo 'notes=' . (strpos($html,'نکات مهم:') !== false ? 'yes':'no') . ';';
echo 'sign=' . (strpos($html,'محل امضا و اثر انگشت ولی دانش‌آموز') !== false ? 'yes':'no') . ';';`);
check(camp.out.includes('sheets=3'), 'one consent sheet per student');
check(camp.out.includes('title=yes'), 'the consent form keeps the original title');
check(camp.out.includes('blanks=7'), 'seven fill-in blanks (camp, date, time, from, to, period, price): ' + camp.out.match(/blanks=\d+/)[0]);
check(camp.out.includes('notes=yes') && camp.out.includes('sign=yes'), 'the important-notes block and the signature area are present');

/* ── ۵) صفحهٔ گزارشات: تب فرم‌ها و لینک‌ها ────────────────────────── */
await loginAdmin('forms01');
const tab = await req('تب فرم‌های اداری', { file: 'reports-lists.php', sid: 'forms01', query: 'tab=forms' });
check(tab.res.page.includes('فرم‌های اداری مدرسه'), 'the new tab renders its heading');
check(tab.res.page.includes('action=absents_form') && tab.res.page.includes('action=amanat_form')
      && tab.res.page.includes('action=anjoman_letter') && tab.res.page.includes('action=camp_consent'),
  'all four forms are linked per class');

/* ── ۶) خروجی واقعی هر چهار فرم از راه صفحهٔ سایت ─────────────────── */
for (const [action, needle] of [
  ['absents_form', 'برگه حضور و غیاب'],
  ['amanat_form', 'فرم ثبت تحویل و دریافت کتاب'],
  ['anjoman_letter', 'دعوتنامه جلسهٔ عمومی انجمن اولیاء و مربیان'],
  ['camp_consent', 'رضایت‌نامه اردو'],
]) {
  const out = await req('خروجی ' + action, { file: 'reports-lists.php', sid: 'forms01', query: 'action=' + action + '&class=' + encodeURIComponent('۸/۱') });
  check((out.res.page || '').includes(needle), 'the ' + action + ' output contains «' + needle + '»');
  check((out.res.page || '').includes('printForms'), 'the ' + action + ' output has the print button');
}

/* ── ۷) کلاس خالی پیام می‌دهد، نه صفحهٔ سفید ──────────────────────── */
const none = await req('کلاس بدون دانش‌آموز', { file: 'reports-lists.php', sid: 'forms01', query: 'action=absents_form&class=' + encodeURIComponent('۹/۹') });
check(/دانش‌آموز/.test(none.res.flash?.message || ''), 'an empty class shows a helpful message: ' + (none.res.flash?.message || ''));

console.log('PASS ' + n + ' school-form checks (four .mrt designs rebuilt with our own students and school)');
process.exit(0);
