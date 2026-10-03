// فرم‌های اداری از قالب‌های .mrt — v4.177.0
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
set_setting('current_academic_year','1403/1404');`);

/* ═══════ ۱) برگهٔ حضور و غیاب هفتگی ═══════ */
const absents = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/school_forms.php';
$ctx = sf_context();
$students = sf_students_of_class('۸/۱','1403/1404');
$html = sf_render_absents_form('۷-۱','هفتم',$students,$ctx,false,false);
echo 'sheets=' . substr_count($html,'class="sheet"') . ';';
echo 'header_box=' . (strpos($html,'class="hdr"') !== false ? 'present':'gone') . ';';
echo 'classline=' . (strpos($html,'کلاس ۷-۱') !== false ? 'yes':'no') . ';';
echo 'days=' . (preg_match_all('/>(شنبه|یکشنبه|دوشنبه|سه‌شنبه|چهارشنبه|پنجشنبه)</',$html,$m) ? count($m[0]) : 0) . ';';
echo 'perrow=' . (int)(substr_count($html,'<td style="border:0.25mm solid #94a3b8"></td>') / max(1,count($students))) . ';';
echo 'names=' . (strpos($html,'آرش کاظمی') !== false ? 'yes':'no') . ';';
echo 'footer=' . (strpos($html,'امضای دبیر') !== false || strpos($html,'هر ستون یک زنگ') !== false ? 'present':'gone') . ';';
echo 'date_footer=' . (strpos($html,'۱۴۰۳') !== false && strpos($html,'برگهٔ') !== false ? 'present':'gone') . ';';`);
check(absents.out.includes('sheets=1'), 'one sheet per class');
check(absents.out.includes('header_box=gone'), 'the attendance sheet has no header box');
check(absents.out.includes('classline=yes'), 'only the class name is written on one line: ' + absents.out.match(/classline=\w+/)[0]);
check(absents.out.includes('days=6'), 'the six weekdays شنبه تا پنجشنبه: ' + absents.out.match(/days=\d+/)[0]);
check(absents.out.includes('perrow=18'), 'each day has 3 periods (6×3=18 cells): ' + absents.out.match(/perrow=\d+/)[0]);
check(absents.out.includes('names=yes'), 'student names are printed in the rows');
check(absents.out.includes('footer=gone'), 'no footer explanation and no teacher-signature line');
check(absents.out.includes('date_footer=gone'), 'no class/date footer line');

/* حالت «چاپ پایه»: برگهٔ خالی بدون نام */
const absentsBlank = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/school_forms.php';
$students = sf_students_of_class('۸/۱','1403/1404');
$html = sf_render_absents_form('۷-۱','هفتم',$students,sf_context(),false,true);
echo 'rows=' . substr_count($html,'border:0.25mm solid #64748b;font-size:8pt') . ';';
echo 'names=' . (strpos($html,'آرش کاظمی') !== false ? 'present':'gone') . ';';
echo 'cells=' . substr_count($html,'<td style="border:0.25mm solid #94a3b8"></td>') . ';';`);
check(absentsBlank.out.includes('rows=30'), 'the blank sheet has 30 numbered rows: ' + absentsBlank.out.match(/rows=\d+/)[0]);
check(absentsBlank.out.includes('names=gone'), 'the blank sheet has no student names');
check(absentsBlank.out.includes('cells=540'), '30 rows × 18 attendance cells: ' + absentsBlank.out.match(/cells=\d+/)[0]);

/* ═══════ ۲) فرم ثبت تحویل و دریافت کتاب ═══════ */
const amanat = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/school_forms.php';
$students = sf_students_of_class('۸/۱','1403/1404');
$html = sf_render_amanat_form($students,sf_context(),false,true,'۸/۱');
echo 'sheets=' . substr_count($html,'class="sheet"') . ';';
echo 'title=' . (strpos($html,'فرم ثبت تحویل و دریافت کتاب') !== false ? 'yes':'no') . ';';
echo 'rows=' . substr_count($html,'height:15mm') . ';';
echo 'receiver=' . (strpos($html,'نام دریافت کننده') !== false ? 'yes':'no') . ';';
echo 'name=' . (strpos($html,'دانش‌آموز:') !== false ? 'present':'gone') . ';';
foreach (['نام کتاب','کد کتاب و قفسه','تاریخ دریافت','تاریخ تحویل','مبلغ جریمه دیرکرد','ملاحظات'] as $c)
  echo 'col[' . $c . ']=' . (strpos($html,$c) !== false ? 'yes':'no') . ';';`);
check(amanat.out.includes('sheets=1'), 'a single blank sheet, not one per student: ' + amanat.out.match(/sheets=\d+/)[0]);
check(amanat.out.includes('title=yes'), 'the book form keeps the original title');
check(amanat.out.includes('rows=14'), '14 empty rows on that sheet: ' + amanat.out.match(/rows=\d+/)[0]);
check(amanat.out.includes('receiver=yes'), 'the new «نام دریافت کننده» column is there');
check(amanat.out.includes('name=gone'), 'no student name on the blank sheet');
check(/col\[نام کتاب\]=yes/.test(amanat.out) && /col\[کد کتاب و قفسه\]=yes/.test(amanat.out) && /col\[تاریخ دریافت\]=yes/.test(amanat.out) && /col\[تاریخ تحویل\]=yes/.test(amanat.out) && /col\[مبلغ جریمه دیرکرد\]=yes/.test(amanat.out) && /col\[ملاحظات\]=yes/.test(amanat.out),
  'all original columns are present: ' + amanat.out.slice(0, 240));

/* حالت «کل دانش‌آموزان»: برای هر دانش‌آموز یک برگه */
const amanatStu = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/school_forms.php';
$students = sf_students_of_class('۸/۱','1403/1404');
$html = sf_render_amanat_form($students,sf_context(),false,false,'۸/۱');
echo 'sheets=' . substr_count($html,'class="sheet"') . ';';
echo 'name=' . (strpos($html,'دانش‌آموز: آرش کاظمی') !== false ? 'yes':'no') . ';';`);
check(amanatStu.out.includes('sheets=3'), 'with names: one sheet per student: ' + amanatStu.out.match(/sheets=\d+/)[0]);
check(amanatStu.out.includes('name=yes'), 'the student name appears on the sheet');

/* ═══════ ۳) دعوتنامهٔ انجمن — دو تا در هر برگه ═══════ */
const anjoman = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/school_forms.php';
$students = sf_students_of_class('۸/۱','1403/1404');
$html = sf_render_anjoman_letter($students,sf_context(),false,false);
echo 'blocks=' . substr_count($html,'class="blk"') . ';';
echo 'sheets=' . substr_count($html,'class="sheet"') . ';';
echo 'title=' . (strpos($html,'دعوتنامهٔ جلسهٔ عمومی انجمن اولیاء و مربیان') !== false ? 'yes':'no') . ';';
echo 'subject=' . (strpos($html,'شمارهٔ نامه') !== false ? 'yes':'no') . ';';
echo 'date_top=' . (strpos($html,'تاریخ:') !== false ? 'yes':'no') . ';';
echo 'justify=' . (strpos($html,'text-align:justify') !== false ? 'yes':'no') . ';';
echo 'rtl=' . (strpos($html,'dir="rtl"') !== false ? 'yes':'no') . ';';
echo 'ol=' . (strpos($html,'list-style:persian decimal') !== false ? 'yes':'no') . ';';
echo 'programs=' . substr_count($html,'<li>') . ';';
echo 'member=' . (strpos($html,'نام و نام خانوادگی:') !== false && strpos($html,'شمارهٔ تماس:') !== false ? 'yes':'no') . ';';
echo 'sign_left=' . (strpos($html,'امضای ولی دانش‌آموز') !== false ? 'yes':'no') . ';';`);
check(anjoman.out.includes('blocks=3'), 'one letter per student: ' + anjoman.out.match(/blocks=\d+/)[0]);
check(anjoman.out.includes('sheets=2'), 'two letters per page (3 letters → 2 sheets): ' + anjoman.out.match(/sheets=\d+/)[0]);
check(anjoman.out.includes('title=yes'), 'the invitation keeps the original title');
check(anjoman.out.includes('subject=yes') && anjoman.out.includes('date_top=yes'), 'letter number and date sit at the top');
check(anjoman.out.includes('justify=yes') && anjoman.out.includes('rtl=yes'), 'the body is justified and right-to-left');
check(anjoman.out.includes('ol=yes'), 'Persian decimal numbering for the agenda list');
check(anjoman.out.includes('programs=9'), 'three agenda items per letter (3 letters × 3): ' + anjoman.out.match(/programs=\d+/)[0]);
check(anjoman.out.includes('member=yes'), 'the membership block (name, phone) is there');
check(anjoman.out.includes('sign_left=yes'), 'the signature line is at the bottom');

/* حالت «چاپ پایه» دعوتنامه */
const anjomanBlank = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/school_forms.php';
$html = sf_render_anjoman_letter([],sf_context(),false,true);
echo 'blocks=' . substr_count($html,'class="blk"') . ';';
echo 'blank_name=' . (strpos($html,'................................') !== false ? 'yes':'no') . ';';`);
check(anjomanBlank.out.includes('blocks=1') && anjomanBlank.out.includes('blank_name=yes'), 'the blank invitation has a fill-in name line');

/* ═══════ ۴) رضایت‌نامهٔ اردو — چهار تا در هر صفحه ═══════ */
await run(String.raw`<?php require_once '/www/includes/functions.php';
@mkdir('/www/uploads', 0777, true);
file_put_contents('/www/uploads/manager-sig.png', base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc9PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='));
set_setting('principal_signature_url','uploads/manager-sig.png');`);
const camp = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/school_forms.php';
$students = sf_students_of_class('۸/۱','1403/1404');
$html = sf_render_camp_consent($students,sf_context(),false,false);
echo 'blocks=' . substr_count($html,'class="blk"') . ';';
echo 'sheets=' . substr_count($html,'class="sheet"') . ';';
echo 'title=' . (strpos($html,'رضایت‌نامهٔ اردو') !== false ? 'yes':'no') . ';';
echo 'stamp=' . (strpos($html,'data:image/png;base64,') !== false ? 'yes':'no') . ';';
echo 'overlay=' . (strpos($html,'transform:translate(-50%,-50%)') !== false ? 'yes':'no') . ';';
echo 'faded=' . (strpos($html,'opacity:.3') !== false ? 'yes':'no') . ';';
echo 'abs=' . (strpos($html,'position:absolute;left:50%') !== false ? 'yes':'no') . ';';
echo 'sign=' . (strpos($html,'محل امضا و اثر انگشت ولی دانش‌آموز') !== false ? 'yes':'no') . ';';
echo 'notes=' . (strpos($html,'محل امضا') !== false ? 'yes':'no') . ';';`);
check(camp.out.includes('blocks=3'), 'one consent sheet per student: ' + camp.out.match(/blocks=\d+/)[0]);
check(camp.out.includes('sheets=1'), 'four per page (3 sheets fit one page): ' + camp.out.match(/sheets=\d+/)[0]);
check(camp.out.includes('title=yes'), 'the consent form keeps the original title');
check(camp.out.includes('stamp=yes'), 'the manager stamp image is embedded');
check(camp.out.includes('overlay=yes') && camp.out.includes('abs=yes'), 'the stamp is positioned as an overlay over the text');
check(camp.out.includes('faded=yes'), 'the stamp is faded so the text stays readable');
check(camp.out.includes('sign=yes'), 'the signature/thumbprint line is there');

/* ═══════ ۵) صفحهٔ گزارشات: دو حالت چاپ ═══════ */
await loginAdmin('forms02');
const tab = await req('تب فرم‌های اداری', { file: 'reports-lists.php', sid: 'forms02', query: 'tab=forms' });
check(tab.res.page.includes('mode=blank') && tab.res.page.includes('mode=students'), 'each form offers blank and with-names printing');
check(tab.res.page.includes('scope=all'), 'all-students printing is offered');
check(tab.res.page.includes('چاپ دسته‌جمعی'), 'the bulk-print panel is on the tab');
check(/action=absents_form/.test(tab.res.page) && /action=amanat_form/.test(tab.res.page)
      && /action=anjoman_letter/.test(tab.res.page) && /action=camp_consent/.test(tab.res.page),
  'all four forms are linked per class');

/* ═══════ ۶) خروجی واقعی هر چهار فرم از راه صفحهٔ سایت ═══════ */
for (const [action, needle] of [
  ['absents_form', 'کلاس ۸/۱'],
  ['amanat_form', 'نام دریافت کننده'],
  ['anjoman_letter', 'دعوتنامهٔ جلسهٔ عمومی انجمن اولیاء و مربیان'],
  ['camp_consent', 'رضایت‌نامهٔ اردو'],
]) {
  const out = await req('خروجی ' + action, { file: 'reports-lists.php', sid: 'forms02', query: 'action=' + action + '&class=' + encodeURIComponent('۸/۱') + '&mode=students' });
  check((out.res.page || '').includes(needle), 'the ' + action + ' output contains «' + needle + '»');
  const blank = await req('خروجی خالی ' + action, { file: 'reports-lists.php', sid: 'forms02', query: 'action=' + action + '&class=' + encodeURIComponent('۸/۱') + '&mode=blank' });
  check((blank.res.page || '').includes(needle), 'the blank ' + action + ' output also contains «' + needle + '»');
}

/* ═══════ ۷) گسترهٔ پایه و کل مدرسه ═══════ */
const byGrade = await req('چاپ پایه‌ای', { file: 'reports-lists.php', sid: 'forms02', query: 'action=anjoman_letter&grade=' + encodeURIComponent('هشتم') + '&mode=students' });
check(byGrade.res.page.includes('class="blk"'), 'a grade-wide print produces letters for every student of that grade');
const all = await req('چاپ کل مدرسه', { file: 'reports-lists.php', sid: 'forms02', query: 'action=camp_consent&scope=all&mode=blank' });
check(all.res.page.includes('class="blk"'), 'a school-wide print produces consent sheets');

/* ═══════ ۸) کلاس خالی پیام می‌دهد، نه صفحهٔ سفید ═══════ */
const none = await req('کلاس بدون دانش‌آموز', { file: 'reports-lists.php', sid: 'forms02', query: 'action=absents_form&class=' + encodeURIComponent('۹/۹') + '&mode=students' });
check(/دانش‌آموز/.test(none.res.flash?.message || ''), 'an empty class shows a helpful message: ' + (none.res.flash?.message || ''));

console.log('PASS ' + n + ' school-form checks (weekly attendance, blank book form, 2-up invitations, 4-up consents)');
process.exit(0);
