// فرم‌های اداری از قالب‌های .mrt — v4.177.0 و بازسازی v4.181.0
//
// v4.181.0: سه تغییر درخواستی کارفرما اینجا واقعاً اجرا و سنجیده می‌شود —
//   ۱) برگهٔ حضور و غیاب طبق تصویر ارسالی: شش کادر روز، سه ردیف زنگ،
//      ستون‌های «اسامی غایبین» و «امضای دبیر».
//   ۲) دعوتنامهٔ انجمن: متن، فهرست برنامه‌ها، زمان/مکان، تاریخ و شمارهٔ نامه از
//      ورودی کاربر + کلید فعال/غیرفعال هر فیلد + اصلاح «هرچند крат» → «کوتاه».
//   ۳) رضایت‌نامهٔ اردو: مقادیر اردو از ورودی کاربر با کلیدهای فعال/غیرفعال،
//      «سال تحصیلی» جای «تاریخ» در سرصفحه، و امضای مدیر به‌صورت overlay روی
//      «امضای مدیر / مهر مدرسه» (نه وسط کادر توضیحات).
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

/* ═══════ ۱) برگهٔ حضور و غیاب — چیدمان تصویر ارسالی ═══════ */
const absents = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/school_forms.php';
$ctx = sf_context();
$students = sf_students_of_class('۸/۱','1403/1404');
$html = sf_render_absents_form('۷-۱','هفتم',$students,$ctx,false,false);
echo 'sheets=' . substr_count($html,'class="sheet"') . ';';
echo 'title=' . (strpos($html,'لیست ثبت حضور و غیاب کلاس') !== false ? 'yes':'no') . ';';
echo 'classline=' . (strpos($html,'کلاس ۷-۱') !== false ? 'yes':'no') . ';';
echo 'boxes=' . substr_count($html,'border:0.5mm solid #000') . ';';
foreach (['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه'] as $d)
  echo 'day[' . $d . ']=' . (strpos($html,'روز ' . $d) !== false ? 'yes':'no') . ';';
echo 'periods=' . (substr_count($html,'>اول<') + substr_count($html,'>دوم<') + substr_count($html,'>سوم<')) . ';';
echo 'bellcol=' . substr_count($html,'>زنگ<') . ';';
echo 'absentcol=' . substr_count($html,'اسامی غایبین') . ';';
echo 'signcol=' . substr_count($html,'امضای دبیر') . ';';
echo 'dateline=' . substr_count($html,'تاریخ:') . ';';
echo 'names=' . (strpos($html,'آرش کاظمی') !== false ? 'present':'gone') . ';';`);
check(absents.out.includes('sheets=1'), 'one sheet per class (A4 portrait)');
check(absents.out.includes('title=yes') && absents.out.includes('classline=yes'),
  'the sheet is titled «لیست ثبت حضور و غیاب کلاس ۷-۱»: ' + absents.out.match(/title=\w+/)[0]);
check(absents.out.includes('boxes=6'), 'six day boxes: ' + absents.out.match(/boxes=\d+/)[0]);
for (const day of ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه'])
  check(absents.out.includes(`day[${day}]=yes`), 'the box «روز ' + day + '» is on the sheet');
check(absents.out.includes('periods=18'), 'three bells (اول/دوم/سوم) in each of the six boxes: ' + absents.out.match(/periods=\d+/)[0]);
check(absents.out.includes('bellcol=6'), 'the «زنگ» column header appears in every box: ' + absents.out.match(/bellcol=\d+/)[0]);
check(absents.out.includes('absentcol=6'), 'the «اسامی غایبین» column header appears in every box: ' + absents.out.match(/absentcol=\d+/)[0]);
check(absents.out.includes('signcol=6'), 'the «امضای دبیر» column header appears in every box: ' + absents.out.match(/signcol=\d+/)[0]);
check(absents.out.includes('dateline=6'), 'every box has a «تاریخ:  /  /» line: ' + absents.out.match(/dateline=\d+/)[0]);
check(absents.out.includes('names=gone'), 'the sheet records absentees by hand, so no student names are printed');

/* حالت «چاپ پایه» عین همین برگه است (هم برای نوشتن با دست) */
const absentsBlank = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/school_forms.php';
$students = sf_students_of_class('۸/۱','1403/1404');
$html = sf_render_absents_form('۷-۱','هفتم',$students,sf_context(),false,true);
echo 'boxes=' . substr_count($html,'border:0.5mm solid #000') . ';';
echo 'names=' . (strpos($html,'آرش کاظمی') !== false ? 'present':'gone') . ';';
echo 'rows=' . substr_count($html,'height:19mm') . ';';`);
check(absentsBlank.out.includes('boxes=6'), 'the blank sheet keeps the six boxes');
check(absentsBlank.out.includes('rows=18'), '18 bell rows (6 boxes × 3) on the blank sheet: ' + absentsBlank.out.match(/rows=\d+/)[0]);
check(absentsBlank.out.includes('names=gone'), 'no student names on the blank sheet');

/* ═══════ ۲) فرم ثبت تحویل و دریافت کتاب (بدون تغییر) ═══════ */
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
check(['نام کتاب', 'کد کتاب و قفسه', 'تاریخ دریافت', 'تاریخ تحویل', 'مبلغ جریمه دیرکرد', 'ملاحظات']
      .every(c => amanat.out.includes(`col[${c}]=yes`)), 'all original columns are present');

const amanatStu = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/school_forms.php';
$students = sf_students_of_class('۸/۱','1403/1404');
$html = sf_render_amanat_form($students,sf_context(),false,false,'۸/۱');
echo 'sheets=' . substr_count($html,'class="sheet"') . ';';
echo 'name=' . (strpos($html,'دانش‌آموز: آرش کاظمی') !== false ? 'yes':'no') . ';';`);
check(amanatStu.out.includes('sheets=3'), 'with names: one sheet per student: ' + amanatStu.out.match(/sheets=\d+/)[0]);
check(amanatStu.out.includes('name=yes'), 'the student name appears on the sheet');

/* ═══════ ۳) دعوتنامهٔ انجمن — مقادیر ورودی کاربر + کلید نمایش ═══════ */
const anjoman = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/school_forms.php';
$students = sf_students_of_class('۸/۱','1403/1404');
$opts = sf_form_defaults('anjoman');
$opts['date'] = '۱۴۰۵/۰۷/۲۴ ۱۷:۱۲';
$opts['number'] = '۱۲۳/۴۵۶';
$opts['place'] = 'پنج‌شنبه ۸ آبان، ساعت ۱۶ — سالن اجتماعات';
$opts['agenda'] = "گزارش عملکرد مالی\nانتخاب هیئت‌رئیسه";
$html = sf_render_anjoman_letter($students,sf_context(),false,false,$opts);
echo 'blocks=' . substr_count($html,'class="blk"') . ';';
echo 'sheets=' . substr_count($html,'class="sheet"') . ';';
echo 'title=' . (strpos($html,'دعوتنامهٔ جلسهٔ عمومی انجمن اولیاء و مربیان') !== false ? 'yes':'no') . ';';
echo 'number=' . (strpos($html,'۱۲۳/۴۵۶') !== false ? 'yes':'no') . ';';
echo 'place=' . (strpos($html,'ساعت ۱۶ — سالن اجتماعات') !== false ? 'yes':'no') . ';';
echo 'agenda_custom=' . (substr_count($html,'گزارش عملکرد مالی') + substr_count($html,'انتخاب هیئت‌رئیسه')) . ';';
echo 'agenda_default=' . substr_count($html,'معرفی داوطلبان عضویت در انجمن') . ';';
echo 'fixed_text=' . (strpos($html,'هرچند کوتاه، در کیفیت آموزش') !== false ? 'yes':'no') . ';';
echo 'broken_text=' . (strpos($html,'крат') !== false ? 'present':'gone') . ';';
echo 'member=' . (strpos($html,'نام و نام خانوادگی:') !== false && strpos($html,'شمارهٔ تماس:') !== false ? 'yes':'no') . ';';
echo 'sign=' . (strpos($html,'امضای ولی دانش‌آموز') !== false ? 'yes':'no') . ';';
$off = $opts; $off['show'] = array_merge($opts['show'], ['number'=>false,'place'=>false,'member_name'=>false,'member_phone'=>false,'member_education'=>false,'parent_sign'=>false,'agenda'=>false]);
$htmlOff = sf_render_anjoman_letter([$students[0]],sf_context(),false,false,$off);
echo 'off_number=' . (strpos($htmlOff,'۱۲۳/۴۵۶') !== false ? 'shown':'hidden') . ';';
echo 'off_place=' . (strpos($htmlOff,'سالن اجتماعات') !== false ? 'shown':'hidden') . ';';
echo 'off_member=' . (strpos($htmlOff,'شمارهٔ تماس:') !== false ? 'shown':'hidden') . ';';
echo 'off_sign=' . (strpos($htmlOff,'امضای ولی دانش‌آموز') !== false ? 'shown':'hidden') . ';';
echo 'off_agenda=' . (substr_count($htmlOff,'<li>')) . ';';
echo 'off_intro=' . (strpos($htmlOff,'هرچند کوتاه') !== false ? 'shown':'hidden') . ';';`);
check(anjoman.out.includes('blocks=3'), 'one letter per student: ' + anjoman.out.match(/blocks=\d+/)[0]);
check(anjoman.out.includes('sheets=2'), 'two letters per page (3 letters → 2 sheets): ' + anjoman.out.match(/sheets=\d+/)[0]);
check(anjoman.out.includes('title=yes'), 'the invitation keeps the original title');
check(anjoman.out.includes('number=yes'), 'the user-entered letter number is printed');
check(anjoman.out.includes('place=yes'), 'the user-entered meeting time/place is printed');
check(anjoman.out.includes('agenda_custom=6') && anjoman.out.includes('agenda_default=0'),
  'the agenda list comes from the user input, not the hard-coded list (2 items × 3 letters): ' + anjoman.out.match(/agenda_custom=\d+/)[0]);
check(anjoman.out.includes('fixed_text=yes') && anjoman.out.includes('broken_text=gone'),
  'the broken «هرچند крат» is replaced with «هرچند کوتاه»');
check(anjoman.out.includes('member=yes') && anjoman.out.includes('sign=yes'), 'the membership block and signature line are there');
check(anjoman.out.includes('off_number=hidden') && anjoman.out.includes('off_place=hidden'),
  'unchecking a field removes it from the sheet');
check(anjoman.out.includes('off_member=hidden') && anjoman.out.includes('off_sign=hidden'),
  'the member fields and the parent signature can be switched off per session');
check(anjoman.out.includes('off_agenda=0'), 'the agenda list disappears when switched off: ' + anjoman.out.match(/off_agenda=\d+/)[0]);
check(anjoman.out.includes('off_intro=shown'), 'unrelated fields stay as they were');

const anjomanBlank = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/school_forms.php';
$html = sf_render_anjoman_letter([],sf_context(),false,true);
echo 'blocks=' . substr_count($html,'class="blk"') . ';';
echo 'blank_name=' . (strpos($html,'................................') !== false ? 'yes':'no') . ';';`);
check(anjomanBlank.out.includes('blocks=1') && anjomanBlank.out.includes('blank_name=yes'), 'the blank invitation has a fill-in name line');

/* ═══════ ۴) رضایت‌نامهٔ اردو — مقادیر اردو، سال تحصیلی، overlay امضا ═══════ */
await run(String.raw`<?php require_once '/www/includes/functions.php';
@mkdir('/www/uploads', 0777, true);
file_put_contents('/www/uploads/manager-sig.png', base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc9PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='));
set_setting('principal_signature_url','uploads/manager-sig.png');`);
const camp = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/school_forms.php';
$students = sf_students_of_class('۸/۱','1403/1404');
$opts = sf_form_defaults('consent');
$opts['year_label'] = '۱۴۰۴/۱۴۰۵';
$opts['trip'] = 'بازدید از موزهٔ علوم';
$opts['date'] = '۱۴۰۵/۰۸/۱۲';
$opts['time'] = '۷:۳۰';
$opts['from'] = 'مدرسه';
$opts['to'] = 'موزهٔ علوم تهران';
$opts['days'] = '۱';
$opts['amount'] = '۲۵۰۰۰۰';
$opts['notes'] = 'همراه داشتن کارت ملی الزامی است.';
$html = sf_render_camp_consent($students,sf_context(),false,false,$opts);
echo 'blocks=' . substr_count($html,'class="blk"') . ';';
echo 'sheets=' . substr_count($html,'class="sheet"') . ';';
echo 'title=' . (strpos($html,'رضایت‌نامهٔ اردو') !== false ? 'yes':'no') . ';';
echo 'yearlabel=' . (strpos($html,'سال تحصیلی:') !== false ? 'yes':'no') . ';';
echo 'datelabel=' . (strpos($html,'تاریخ:') !== false ? 'present':'gone') . ';';
foreach (['بازدید از موزهٔ علوم','۱۴۰۵/۰۸/۱۲','۷:۳۰','موزهٔ علوم تهران','۲۵۰۰۰۰','کارت ملی'] as $v)
  echo 'val[' . $v . ']=' . (strpos($html,$v) !== false ? 'yes':'no') . ';';
echo 'stamp=' . (strpos($html,'data:image/png;base64,') !== false ? 'yes':'no') . ';';
echo 'overlay=' . (strpos($html,'sfe-stamp') !== false && strpos($html,'transform:translate(-50%,-50%)') !== false ? 'yes':'no') . ';';
$sigPos = strpos($html,'امضای مدیر / مهر مدرسه');
$imgPos = strpos($html,'class="sfe-stamp"');
echo 'sig_before_img=' . ($sigPos !== false && $imgPos !== false && $sigPos < $imgPos ? 'yes':'no') . ';';
$notesStart = strpos($html,'data-sfe-opt="notes"');
$notesEnd = strpos($html,'امضای مدیر / مهر مدرسه');
echo 'no_img_in_notes=' . ($notesStart !== false && $notesEnd !== false && strpos(substr($html,$notesStart,$notesEnd-$notesStart),'<img') === false ? 'yes':'no') . ';';
$off = $opts; $off['show'] = array_merge($opts['show'], ['amount'=>false,'days'=>false,'notes'=>false]);
$htmlOff = sf_render_camp_consent([$students[0]],sf_context(),false,false,$off);
echo 'off_amount=' . (strpos($htmlOff,'۲۵۰۰۰۰') !== false ? 'shown':'hidden') . ';';
echo 'off_days_clause=' . (strpos($htmlOff,'به مدت') !== false ? 'shown':'hidden') . ';';
echo 'off_notes_box=' . (strpos($htmlOff,'کارت ملی') !== false ? 'shown':'hidden') . ';';
echo 'off_notes_attr=' . (strpos($htmlOff,'data-sfe-opt="notes"') !== false ? 'hidden':'gone') . ';';
echo 'off_to=' . (strpos($htmlOff,'موزهٔ علوم تهران') !== false ? 'shown':'hidden') . ';';`);
check(camp.out.includes('blocks=3'), 'one consent sheet per student: ' + camp.out.match(/blocks=\d+/)[0]);
check(camp.out.includes('sheets=1'), 'four per page (3 sheets fit one page): ' + camp.out.match(/sheets=\d+/)[0]);
check(camp.out.includes('yearlabel=yes') && camp.out.includes('datelabel=gone'),
  'the header shows «سال تحصیلی» instead of «تاریخ»');
check(['بازدید از موزهٔ علوم', '۱۴۰۵/۰۸/۱۲', '۷:۳۰', 'موزهٔ علوم تهران', '۲۵۰۰۰۰', 'کارت ملی']
      .every(v => camp.out.includes(`val[${v}]=yes`)), 'every trip field entered by the user is printed: ' + camp.out.match(/val\[[^\]]+\]=\w+/g).filter(x => x.endsWith('no')).join(' '));
check(camp.out.includes('stamp=yes'), 'the manager signature image is embedded');
check(camp.out.includes('overlay=yes') && camp.out.includes('sig_before_img=yes'),
  'the signature is an overlay placed on «امضای مدیر / مهر مدرسه»');
check(camp.out.includes('no_img_in_notes=yes'), 'the signature is NOT inside the notes box any more');
check(camp.out.includes('off_amount=hidden') && camp.out.includes('off_days_clause=hidden') && camp.out.includes('off_notes_box=hidden')
      && camp.out.includes('off_notes_attr=hidden'),
  'switching a trip field off removes it from the sheet and from the sentence');
check(camp.out.includes('off_to=shown'), 'the other trip fields stay untouched');

/* ═══════ ۵) صفحهٔ گزارشات: دو حالت چاپ + ویرایشگر زنده ═══════ */
await loginAdmin('forms02');
const tab = await req('تب فرم‌های اداری', { file: 'reports-lists.php', sid: 'forms02', query: 'tab=forms' });
check(tab.res.page.includes('mode=blank') && tab.res.page.includes('mode=students'), 'each form offers blank and with-names printing');
check(tab.res.page.includes('scope=all'), 'all-students printing is offered');
check(tab.res.page.includes('چاپ دسته‌جمعی'), 'the bulk-print panel is on the tab');
check(/action=absents_form/.test(tab.res.page) && /action=amanat_form/.test(tab.res.page)
      && /action=anjoman_letter/.test(tab.res.page) && /action=camp_consent/.test(tab.res.page),
  'all four forms are linked per class');
check(/action=anjoman_editor/.test(tab.res.page) && /action=camp_editor/.test(tab.res.page),
  'the live editor is offered for the invitation and the consent form');

/* ویرایشگر زندهٔ دعوتنامه: ورودی‌ها + پیش‌نمایش همان برگه */
const editor = await req('ویرایشگر دعوتنامه', { file: 'reports-lists.php', sid: 'forms02', query: 'action=anjoman_editor&class=' + encodeURIComponent('۸/۱') + '&mode=blank' });
check(editor.res.page.includes('ویرایشگر زنده'), 'the editor page opens');
for (const key of ['intro', 'agenda', 'place', 'number', 'date'])
  check(editor.res.page.includes(`data-sfe-input="${key}"`), 'the invitation editor exposes the «' + key + '» field');
for (const key of ['member_name', 'member_phone', 'member_education', 'parent_sign'])
  check(editor.res.page.includes(`data-sfe-toggle="${key}"`), 'the «' + key + '» field can be switched off');
check(editor.res.page.includes('class="sheet"') && editor.res.page.includes('data-sfe="place"'),
  'the live preview renders the real sheet with bindable nodes');
check(editor.res.page.includes('ذخیرهٔ پیش‌فرض'), 'the editor can save the values as the new default');

/* ذخیرهٔ پیش‌فرض رضایت‌نامه از راه ویرایشگر، سپس چاپ همان مقادیر */
await run(String.raw`<?php ini_set('session.save_path','/tmp/sess');session_name('BACI_TEST');session_id('forms02');session_start();$_SESSION['csrf_token']='forms-fixture';`);
const saved = await req('ذخیرهٔ پیش‌فرض رضایت‌نامه', {
  file: 'reports-lists.php', sid: 'forms02', method: 'POST', query: 'action=camp_editor&class=' + encodeURIComponent('۸/۱') + '&mode=blank',
  post: {
    csrf_token: 'forms-fixture', sfe_save: '1',
    f_year_label: '۱۴۰۴/۱۴۰۵', f_trip: 'اردوی آزمایشی کویر', f_date: '۱۴۰۵/۰۹/۰۱', f_time: '۶:۰۰',
    f_from: 'مدرسه', f_to: 'کویر ورزنه', f_days: '۲', f_amount: '۴۵۰۰۰۰', f_notes: 'کفش مناسب بیاورید.',
    show_year_label: '1', show_trip: '1', show_date: '1', show_time: '1', show_from: '1', show_to: '1', show_days: '1', show_notes: '1',
  },
});
check(saved.res.page.includes('ذخیره شد'), 'the editor confirms the saved defaults');
const printAfterSave = await req('چاپ رضایت‌نامه با پیش‌فرض ذخیره‌شده', {
  file: 'reports-lists.php', sid: 'forms02', query: 'action=camp_consent&class=' + encodeURIComponent('۸/۱') + '&mode=blank',
});
check(printAfterSave.res.page.includes('اردوی آزمایشی کویر'), 'the stored trip title is printed next time');
check(printAfterSave.res.page.includes('کفش مناسب بیاورید.'), 'the stored notes are printed');
check(!/به مبلغ/.test(printAfterSave.res.page), 'the amount clause is gone because its checkbox was left unchecked');
check(printAfterSave.res.page.includes('۱۴۰۴/۱۴۰۵'), 'the academic year is printed in the header');

/* ═══════ ۶) خروجی واقعی فرم‌ها از راه صفحهٔ سایت ═══════ */
for (const [action, needle] of [
  ['absents_form', 'اسامی غایبین'],
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
const allEditor = await req('ویرایشگر کل مدرسه', { file: 'reports-lists.php', sid: 'forms02', query: 'action=camp_editor&scope=all&mode=blank' });
check(allEditor.res.page.includes('ویرایشگر زنده') && allEditor.res.page.includes('class="blk"'), 'the editor also works school-wide');

/* ═══════ ۸) کلاس خالی پیام می‌دهد، نه صفحهٔ سفید ═══════ */
const none = await req('کلاس بدون دانش‌آموز', { file: 'reports-lists.php', sid: 'forms02', query: 'action=absents_form&class=' + encodeURIComponent('۹/۹') + '&mode=students' });
check(/دانش‌آموز/.test(none.res.flash?.message || ''), 'an empty class shows a helpful message: ' + (none.res.flash?.message || ''));

console.log('PASS ' + n + ' school-form checks (v4.181.0 attendance sheet, customisable invitation and consent form)');
process.exit(0);
