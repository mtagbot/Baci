/**
 * test-v4.213.0-features.mjs — هشت خواستهٔ نشست ۱۱-۰۲ (V4.213.0)
 *
 * هر بخش، همان چیزی را می‌سنجد که مدیر خواسته بود — با اجرای واقعی PHP،
 * نه با جست‌وجوی متن در سورس:
 *
 *   ۱) پله‌های وضعیت نمره (۰ تا ۲۰) + نمرهٔ مشکی + راهنمای رنگ‌ها در کارنامه و چاپ
 *   ۲) اعلام سراسری پایه‌ای/کلاسی + مسدودیت فردی و گروهی + فهرست پلتفرم‌ها
 *   ۳) تصویر دانش‌آموز در ستون پیش از نام (کارنامه‌ها و انضباط)
 *   ۴) «عنوان نوبت» در مدیریت ستونی نمرات (ذخیره، اعمال روی کارنامه‌ها، پیش‌فرض کارنامهٔ تازه)
 *   ۵) «افزودن دانش‌آموز» بدون + و «بازیابی دانش‌آموز» کنار آن؛ حذف از منو و کاشی‌ها
 *   ۶) هدر: حذف «مورخ:» و «پنل مدیریت سیستم»، تاریخ کنار دکمهٔ کاربری
 *   ۷) «امتحانات حضوری» → «امتحانات» در همهٔ صفحه‌های اورلی
 *   ۸) مودال انضباط: دو پنل تمام‌عرض، ردیف به‌ازای هر مورد با همهٔ داده‌ها
 *   ۹) «مقایسه نمرات ماهانه»: ستون هر ماه، ردیف هر درس، معدل ماهانه، تحلیل، و قفل
 */
import assert from 'node:assert/strict';
import { readFileSync, existsSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { run, req, login, loginAdmin, db } from './harness/lib.mjs';
import { REPO, resolveSite } from './harness/site.mjs';

let pass = 0, fail = 0;
const ok = (name, cond, detail = '') => cond
  ? (pass++, console.log(`  ✅ ${name}`))
  : (fail++, console.log(`  ❌ ${name}${detail ? '  → ' + detail : ''}`));

const SITE = resolveSite();
const PATCH = process.env.PATCH || 'update-v4.213.0';
/* اگر فایل در بستهٔ اورلی نبود (مثلاً نسخهٔ تازه‌تر آن را از فهرست انداخته)،
   همان فایلِ نصب‌شده خوانده می‌شود؛ سنجه‌ها رفتار را می‌سنجند، نه مسیر فایل. */
const patchFile = rel => {
  const p = join(REPO, PATCH, rel);
  if (existsSync(p)) return readFileSync(p, 'utf8');
  const site = join(SITE, rel);
  if (existsSync(site)) return readFileSync(site, 'utf8');
  throw new Error('فایل اورلی پیدا نشد: ' + p);
};

/* ═══════════ ۰) دادهٔ آزمایشی ═══════════ */
const curYear = (await run(String.raw`<?php require_once '/www/includes/functions.php';
echo 'Y=' . get_current_academic_year();`)).out.match(/Y=([\d\/\-]+)/)[1];

await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/school_roles.php';
ensure_school_roles_schema();
ensure_report_locks_schema();
set_setting('current_academic_year', ${JSON.stringify(curYear)});
DB::execute("DELETE FROM students WHERE national_id LIKE '213%'");
DB::execute("DELETE FROM reports WHERE academic_year = ? AND class_name IN ('۹/۱','۹/۲')", [${JSON.stringify(curYear)}]);
DB::execute("DELETE FROM report_locks WHERE reason LIKE 'قفل گروهی آزمون%'");
$rows = [
  ['آرمان','کریمی','21300000001','۹/۱','نهم','uploads/students/arman.jpg'],
  ['نگار','سلطانی','21300000002','۹/۱','نهم',''],
  ['سامان','مرادی','21300000003','۹/۲','نهم',''],
];
foreach ($rows as $r) {
  DB::execute("INSERT INTO students (first_name,last_name,father_name,national_id,class_name,grade_level,academic_year,status,password,photo_url) VALUES (?,?,'پدر',?,?,?,?,'active','x',?)",
    [$r[0],$r[1],$r[2],$r[3],$r[4],${JSON.stringify(curYear)},$r[5]]);
}
echo 'SEED=1';`);

const IDS = JSON.parse((await run(String.raw`<?php require_once '/www/includes/functions.php';
echo 'IDS=' . json_encode(array_column(DB::fetchAll("SELECT id, national_id FROM students WHERE national_id LIKE '213%'"), 'id', 'national_id'));`)).out.match(/IDS=(\{.*\})/)[1]);
const sidA = IDS['21300000001'], sidB = IDS['21300000002'], sidC = IDS['21300000003'];
ok('سه دانش‌آموز آزمایشی ساخته شدند', !!sidA && !!sidB && !!sidC);

/* سه ماه کارنامه برای دانش‌آموز اول: مهر/آبان/آذر با سه درس */
await run(`<?php require_once '/www/includes/functions.php';
$months = ['مهر' => [['ریاضی',12],['علوم تجربی',16],['زبان انگلیسی',18]], 'آبان' => [['ریاضی',14],['علوم تجربی',15],['زبان انگلیسی',19]], 'آذر' => [['ریاضی',17],['علوم تجربی',14],['زبان انگلیسی',20]]];
$gpas = ['مهر' => 15.33, 'آبان' => 16.0, 'آذر' => 17.0];
foreach ($months as $m => $grades) {
  DB::execute("INSERT INTO reports (student_id,class_name,academic_year,term,report_month,total_score,gpa) VALUES (?,?,?,?,?,0,?)",
    [${sidA},'۹/۱',${JSON.stringify(curYear)},'نوبت اول',$m,$gpas[$m]]);
  $rid = (int)DB::lastInsertId();
  foreach ($grades as $g) DB::execute("INSERT INTO report_grades (report_id,subject_name,score,max_score,coefficient,status) VALUES (?,?,?,20,1,'passed')", [$rid,$g[0],$g[1]]);
}
/* دانش‌آموز کلاس ۹/۲ — کارنامهٔ آذر برای آزمون قفل گروهی در اعلام سراسری */
DB::execute("INSERT INTO reports (student_id,class_name,academic_year,term,report_month,total_score,gpa) VALUES (?,?,?,'نوبت اول','آذر',0,15)", [${sidC},'۹/۲',${JSON.stringify(curYear)}]);
/* قفل گروهی روی ۹/۲ برای آذر (ستون reports.is_locked دست‌نخورده می‌ماند) */
DB::execute("INSERT INTO report_locks (academic_year,report_month,class_name,student_id,is_locked,reason) VALUES (?,?,?,0,1,'قفل گروهی آزمون V4.213.0')", [${JSON.stringify(curYear)},'آذر','۹/۲']);
echo 'OK';`);

/* ═══════════ ۱) پله‌های وضعیت نمره ═══════════ */
console.log('\n══ ۱) وضعیت نمره در کارنامه ══');
const bands = await run(String.raw`<?php require_once '/www/includes/functions.php';
$cases = [0 => 'کاملا مردود', 1.5 => 'کاملا مردود', 2 => 'بدون تلاش', 3.9 => 'بدون تلاش', 4 => 'بسیار ضعیف',
          5 => 'بسیار ضعیف', 6 => 'تلاش ناکافی', 9.75 => 'تلاش ناکافی', 10 => 'متوسط ضعیف', 11 => 'متوسط ضعیف',
          12 => 'متوسط', 13 => 'متوسط', 14 => 'متوسط خوب', 15 => 'متوسط خوب', 16 => 'بسیار خوب', 17 => 'بسیار خوب',
          18 => 'عالی', 19.5 => 'عالی', 20 => 'عالی'];
$bad = [];
foreach ($cases as $score => $want) {
  $got = report_grade_status_label($score);
  if ($got !== $want) $bad[] = $score . '→' . $got;
}
echo 'BAD=' . json_encode($bad, JSON_UNESCAPED_UNICODE) . ';';
echo 'ABS21=' . report_grade_status_label(21) . ';';
echo 'EMPTY=' . report_grade_status_label('') . ';';
echo 'N21=' . json_encode(report_grade_status(21)) . ';';
echo 'BANDS=' . count(report_grade_status_bands()) . ';';
echo 'DOTS=' . implode(',', array_column(report_grade_status_bands(), 'dot')) . ';';`);
ok('هر ۱۹ نمرهٔ نمونه به پلهٔ درست می‌افتد', /BAD=\[\]/.test(bands.out), bands.out.match(/BAD=(.*?);/)?.[1]);
ok('غیبت (۲۱) وضعیت «غیبت» می‌گیرد، نه «عالی»', /ABS21=غیبت;/.test(bands.out), bands.out.match(/ABS21=(.*?);/)?.[1]);
ok('نمرهٔ خالی «ثبت نشده» است', /EMPTY=ثبت نشده;/.test(bands.out), bands.out.match(/EMPTY=(.*?);/)?.[1]);
ok('برای ۲۱ پله‌ای برنمی‌گردد (null)', /N21=null;/.test(bands.out));
ok('دقیقاً نُه پله تعریف شده', /BANDS=9;/.test(bands.out), bands.out.match(/BANDS=(.*?);/)?.[1]);
const dots = (bands.out.match(/DOTS=(.*?);/) || [, ''])[1].split(',').filter(Boolean);
ok('رنگ پله‌ها از بحرانی به عالی یک‌دست عوض می‌شود (۹ رنگ یکتا)', new Set(dots).size === 9 && dots[0] === '#b91c1c' && dots[8] === '#047857', dots.join(','));

await loginAdmin('f213adm1');
const view = await req('کارنامه', { file: 'report-view.php', sid: 'f213adm1', query: 'id=' + (await db(`SELECT id FROM reports WHERE student_id=${sidA} AND report_month='آذر'`))[0].id + '&staff=1' });
const viewHtml = view.res.page;
ok('صفحهٔ کارنامه بدون خطای مهلک رندر می‌شود', !view.res.fatal, view.res.fatal || '');
ok('ستون وضعیت، برچسب «عالی» را نشان می‌دهد', viewHtml.includes('عالی'));
ok('سلول نمره دیگر رنگ‌بندی قدیم (قرمز/آبی بر اساس نمره) را ندارد',
  !patchFile('report-view.php').includes("text-red-600")
  && !patchFile('report-view.php').includes("text-blue-600")
  && !/<td class="border p-3 font-mono text-center[^"]*text-(red|blue|amber)-600/.test(viewHtml));
ok('نمره با استایل مشکی رندر می‌شود', viewHtml.includes('color:#111111'));
ok('راهنمای پله‌ها زیر جدول هست', viewHtml.includes('راهنمای وضعیت نمره'));

const printView = await req('چاپ کارنامه', { file: 'report-print.php', sid: 'f213adm1', query: 'id=' + (await db(`SELECT id FROM reports WHERE student_id=${sidA} AND report_month='آذر'`))[0].id + '&staff=1' });
ok('نسخهٔ چاپی هم همان وضعیت را چاپ می‌کند (بدون «قبول/مردود» قدیمی)',
  printView.res.page.includes('عالی') && !printView.res.page.includes('>قبول<'), printView.res.fatal || '');
ok('در چاپ، رنگ وضعیت با رنگ نمره قاطی نمی‌شود (فقط span وضعیت رنگ دارد)',
  /color:#047857;font-weight:bold/.test(printView.res.page));

/* ═══════════ ۲) اعلام سراسری پایه‌ای و کلاسی ═══════════ */
console.log('\n══ ۲) اعلام سراسری: گستره، قفل‌ها و پلتفرم‌ها ══');
const bc = await req('اعلام سراسری', { file: 'report-broadcast.php', sid: 'f213adm1', query: 'academic_year=' + encodeURIComponent(curYear) + '&report_month=' + encodeURIComponent('آذر') + '&scope=class&class_name=' + encodeURIComponent('۹/۲') });
const bcHtml = bc.res.page;
ok('صفحهٔ اعلام سراسری بدون خطا رندر می‌شود', !bc.res.fatal, bc.res.fatal || '');
ok('گزینهٔ «اعلام سراسری پایه‌ای و کلاسی» در صفحه هست', bcHtml.includes('اعلام سراسری پایه‌ای و کلاسی'));
ok('گسترهٔ «فقط یک پایه» و «فقط یک کلاس» موجود است',
  bcHtml.includes('فقط یک پایه') && bcHtml.includes('فقط یک کلاس'));
ok('کارنامهٔ گروهی‌قفل‌شده در پیش‌نمایش «قفل» علامت می‌خورد', bcHtml.includes('قفل — ارسال نمی‌شود'));
ok('علت قفل گروهی نمایش داده می‌شود', bcHtml.includes('قفل گروهی آزمون V4.213.0'));
/* V4.214.0 — روبیکا هم به فهرست اضافه شد؛ این سوئیت فقط می‌سنجد که فهرست
   همان سه پیام‌رسانِ خودِ سامانه باشد و مقدار ناشناخته به صفحه راه پیدا نکند.
   رفتار ارسال روبیکا در tests/test-v4.214.0-features.mjs سنجیده می‌شود. */
ok('فهرست پلتفرم‌ها همان سه پیام‌رسان سامانه است (بله، تلگرام، روبیکا)',
  ['bale','telegram','rubika'].every(pf => bcHtml.includes('value="' + pf + '"'))
  && (bcHtml.match(/name="platforms\[\]"/g) || []).length === 3);

/* تلاش برای ارسال با پلتفرم جعلی باید رد شود؛ با یک پلتفرم معتبر اما بدون
   اتصال ربات باید «قفل‌شده: ۱» گزارش شود و هیچ ارسالی رخ ندهد. */
await run(`<?php @mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess'); session_name('BACI_TEST'); session_id('f213adm1'); session_start(); $_SESSION['csrf_token']='f213-token'; echo 'OK';`);
const badPlatform = await req('ارسال با پلتفرم نامعتبر', {
  file: 'report-broadcast.php', sid: 'f213adm1', method: 'POST',
  post: { csrf_token: 'f213-token', academic_year: curYear, report_month: 'آذر', scope: 'all', platforms: ['soroush'] },
});
ok('پلتفرم ناشناخته (سروش) رد می‌شود', (badPlatform.res.flash || {}).type === 'error', JSON.stringify(badPlatform.res.flash || {}));

const sendTry = await req('ارسال با قفل گروهی', {
  file: 'report-broadcast.php', sid: 'f213adm1', method: 'POST',
  post: { csrf_token: 'f213-token', academic_year: curYear, report_month: 'آذر', scope: 'class', class_name: '۹/۲', platforms: ['bale'] },
});
const flash = (sendTry.res.flash || {}).message || '';
ok('ارسال برای دانش‌آموز گروهی‌قفل‌شده انجام نمی‌شود و در گزارش شمرده می‌شود',
  /قفل‌شده \(ارسال نشد\): 1/.test(flash) && /تلاش ارسال: 0/.test(flash), flash);
ok('گسترهٔ انتخاب‌شده در پیام پایان کار آمده است', flash.includes('کلاس ۹/۲'), flash);

const scopeSql = patchFile('report-broadcast.php');
ok('کوئری با شرط is_locked=0 تنها فیلتر نمی‌شود؛ تابع مشترک قفل صدا زده می‌شود',
  scopeSql.includes('is_report_locked_for_student(') && !scopeSql.includes('AND r.is_locked=0'));
ok('فیلتر پایه و کلاس در سورس هست', scopeSql.includes('s.grade_level = ?') && scopeSql.includes('rb_norm_class_sql'));

/* ═══════════ ۳) ستون تصویر ═══════════ */
console.log('\n══ ۳) تصویر دانش‌آموز در ردیف جدول ══');
const reportsPage = await req('کارنامه‌ها', { file: 'reports.php', sid: 'f213adm1', query: 'academic_year=' + encodeURIComponent(curYear) });
ok('ستون «تصویر» پیش از ستون نام در مدیریت کارنامه‌ها هست',
  /<th class="text-center">تصویر<\/th>\s*<th>دانش‌آموز<\/th>/.test(reportsPage.res.page), reportsPage.res.fatal || '');
ok('عکس دانش‌آموز در همان ردیف رندر می‌شود', reportsPage.res.page.includes('uploads/students/arman.jpg'));
ok('برای دانش‌آموز بدون عکس، حرف اول نام جای عکس می‌نشیند', reportsPage.res.page.includes('row-avatar'));

const drmPage = await req('مدیریت انضباطی', {
  file: 'discipline-report-management.php', sid: 'f213adm1',
  query: 'year=' + encodeURIComponent(curYear) + '&month=' + encodeURIComponent('آذر') + '&class=' + encodeURIComponent('۹/۱'),
});
const drmHtml = drmPage.res.page;
ok('ستون «تصویر» در تب انضباط هم پیش از نام است',
  drmHtml.indexOf('>تصویر</th>') > 0 && drmHtml.indexOf('>تصویر</th>') < drmHtml.indexOf('>دانش‌آموز</th>'), drmPage.res.fatal || '');
ok('تصویر دانش‌آموز در ردیف انضباط دیده می‌شود', drmHtml.includes('uploads/students/arman.jpg'));

/* ═══════════ ۴) عنوان نوبت در مدیریت ستونی ═══════════ */
console.log('\n══ ۴) «عنوان نوبت» ══');
const gcGet = await req('مدیریت ستونی', {
  file: 'grade-columns.php', sid: 'f213adm1',
  query: 'year=' + encodeURIComponent(curYear) + '&month=' + encodeURIComponent('مهر') + '&class=' + encodeURIComponent('۹/۱'),
});
ok('فیلد «عنوان نوبت» در صفحه هست',
  gcGet.res.page.includes('عنوان نوبت (سرصفحهٔ کارنامه‌های این ماه)') && gcGet.res.page.includes('name="term_title"'), gcGet.res.fatal || '');
ok('مقدار پیش‌فرض فیلد از عنوان فعلی همان ماه می‌آید', gcGet.res.page.includes('value="نوبت اول"'));

await run(`<?php @mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess'); session_name('BACI_TEST'); session_id('f213adm1'); session_start(); $_SESSION['csrf_token']='f213-token'; echo 'OK';`);
const termSave = await req('ذخیرهٔ عنوان نوبت', {
  file: 'grade-columns.php', sid: 'f213adm1', method: 'POST',
  query: 'year=' + encodeURIComponent(curYear) + '&month=' + encodeURIComponent('مهر') + '&class=' + encodeURIComponent('۹/۱'),
  post: { csrf_token: 'f213-token', gc_action: 'save_term', year: curYear, month: 'مهر', term_title: 'میان‌ترم مهر' },
});
ok('ذخیرهٔ عنوان نوبت پیام موفقیت می‌دهد', (termSave.res.flash || {}).type === 'success', JSON.stringify(termSave.res.flash || {}));
const termRows = await db(`SELECT term FROM reports WHERE student_id=${sidA} AND report_month='مهر'`);
ok('عنوان تازه روی همهٔ کارنامه‌های آن ماه نشست', termRows.length === 1 && termRows[0].term === 'میان‌ترم مهر', JSON.stringify(termRows));
const termSetting = (await run(String.raw`<?php require_once '/www/includes/functions.php';
echo 'S=' . get_setting('report_term_title|' . ${JSON.stringify(curYear)} . '|مهر', '');`)).out;
ok('عنوان برای ماه‌های بعدی هم ذخیره می‌شود', termSetting.includes('S=میان‌ترم مهر'), termSetting.trim());

/* کارنامهٔ تازه در همان ماه باید همین عنوان را بگیرد */
const newCell = await req('ذخیرهٔ نمرهٔ ماه با عنوان تازه', {
  file: 'grade-columns.php', sid: 'f213adm1', method: 'POST',
  query: 'year=' + encodeURIComponent(curYear) + '&month=' + encodeURIComponent('مهر') + '&class=' + encodeURIComponent('۹/۱'),
  post: { gc_action: 'save', csrf_token: 'f213-token', year: curYear, month: 'مهر', class_name: '۹/۱', student_id: sidB, subject: 'ریاضی', score: '16' },
});
let newCellJson = {}; try { newCellJson = JSON.parse(newCell.res.page || (newCell.res.raw_head || '')); } catch (e) {}
if (newCellJson.ok) {
  const created = await db(`SELECT term FROM reports WHERE student_id=${sidB} AND report_month='مهر'`);
  ok('کارنامه‌ای که بعداً ساخته می‌شود هم «عنوان نوبت» ذخیره‌شده را می‌گیرد',
    created.length === 1 && created[0].term === 'میان‌ترم مهر', JSON.stringify(created));
} else {
  ok('کارنامه‌ای که بعداً ساخته می‌شود هم «عنوان نوبت» ذخیره‌شده را می‌گیرد', false, 'پاسخ ذخیرهٔ نمره: ' + (newCell.res.page || '').slice(0, 120));
}

/* ═══════════ ۵) دکمه‌های صفحهٔ دانش‌آموزان و حذف از منو ═══════════ */
console.log('\n══ ۵) «افزودن دانش‌آموز» و «بازیابی دانش‌آموز» ══');
const studentsPage = await req('مدیریت دانش‌آموزان', { file: 'students.php', sid: 'f213adm1' });
const stHtml = studentsPage.res.page;
ok('برچسب «افزودن دانش‌آموز» (بدون +) در نوار عملیات هست', stHtml.includes('>افزودن دانش‌آموز</a>'));
ok('برچسب قدیمی «+ افزودن دانش‌آموز جدید» حذف شد', !stHtml.includes('افزودن دانش‌آموز جدید'));
ok('«بازیابی دانش‌آموز» کنار دکمهٔ افزودن است',
  stHtml.indexOf('>بازیابی دانش‌آموز</a>') > stHtml.indexOf('>افزودن دانش‌آموز</a>') &&
  stHtml.includes('href="student-recovery.php"'));
ok('«بازیابی دانش‌آموز» از منوی کناری حذف شد', !/<a href="student-recovery.php" class="sidebar-item/.test(stHtml));
ok('کاشی «بازیابی دانش‌آموز» از کاتالوگ حذف شد', !patchFile('includes/header_tiles.php').includes("'recovery'    =>"));
ok('صفحهٔ «سفارشی‌سازی» هم دیگر کاشی بازیابی را پیشنهاد نمی‌دهد',
  !(await req('سفارشی‌سازی', { file: 'settings.php', sid: 'f213adm1' })).res.page.includes('data-tile="recovery"'));

/* ═══════════ ۶) هدر ═══════════ */
console.log('\n══ ۶) هدر: تاریخ و زیرنویس ══');
ok('«مورخ:» از هدر حذف شد', !stHtml.includes('مورخ:'));
ok('«پنل مدیریت سیستم» از کنار نام مدرسه حذف شد', !stHtml.includes('پنل مدیریت سیستم'));
ok('تاریخ (روز هفته + تاریخ) هنوز نمایش داده می‌شود', /class="text-xs font-medium hdr-date"/.test(stHtml));
/* V4.214.0 — ترتیب برگشت: تاریخ پیش از دکمهٔ «کاربری» در DOM است تا در
   چیدمان راست‌به‌چپ، «کاربری» چپ‌ترین عنصر هدر شود و تاریخ کنارش بنشیند. */
ok('تاریخ کنار دکمهٔ کاربری و پیش از آن در DOM است (کاربری چپ‌ترین)',
  stHtml.indexOf('hdr-date') > 0 && stHtml.indexOf('hdr-date') < stHtml.indexOf('user-menu-wrap'),
  'index(user-menu)=' + stHtml.indexOf('user-menu-wrap') + ' index(date)=' + stHtml.indexOf('hdr-date'));
ok('نوار کاشی‌ها همچنان بیرون از گروه کاربری رندر می‌شود',
  /render_header_tiles\(\);\s*\?>\s*<div class="flex items-center gap-4">/.test(patchFile('includes/header.php')));

/* ═══════════ ۷) نام «امتحانات» ═══════════ */
console.log('\n══ ۷) «امتحانات حضوری» → «امتحانات» ══');
const leftovers = [];
(function walk(dir) {
  for (const e of readdirSync(dir, { withFileTypes: true })) {
    const abs = join(dir, e.name);
    if (e.isDirectory()) { walk(abs); continue; }
    if (!/\.(php|js|css|md|txt)$/.test(e.name)) continue;
    const text = readFileSync(abs, 'utf8');
    if (text.includes('امتحانات حضوری')) leftovers.push(abs.replace(REPO + '/', ''));
  }
})(join(REPO, PATCH));
ok('هیچ «امتحانات حضوری» در فایل‌های اورلی نمانده', leftovers.length === 0, leftovers.join(', '));
ok('کاشی هدر «امتحانات» شده', patchFile('includes/header_tiles.php').includes("'t' => 'امتحانات'"));
/* پنل اجرایی فقط برای دبیرِ معاون/اجرایی باز است، پس از سورس اورلی سنجیده می‌شود. */
ok('میان‌بر پنل اجرایی هم «امتحانات» است',
  /exams\.php\?embedded=1'\)">امتحانات<\/a>/.test(patchFile('executive-panel.php')),
  patchFile('executive-panel.php').match(/exams\.php\?embedded=1'\)">[^<]*/)?.[0] || '');
ok('عنوان بانک سوال و متن سال تحصیلی هم به «امتحانات» تغییر کرد',
  patchFile('exam-question-bank.php').includes('بانک سوالات امتحانات')
  && patchFile('academic-years.php').includes('کارنامه‌ها، امتحانات و آزمون‌های مجازی'));

/* ═══════════ ۸) مودال انضباط دو پنلی ═══════════ */
console.log('\n══ ۸) مودال انضباط: دو پنل و یک ردیف برای هر مورد ══');
await run(`<?php require_once '/www/includes/functions.php';
DB::execute("DELETE FROM student_discipline_records WHERE student_id = ?", [${sidA}]);
DB::execute("INSERT INTO student_discipline_records (student_id,title_id,title_text,internal_note,occurred_at_jalali,notify_parents,is_justified,review_status,review_note,created_at_jalali) VALUES (?,NULL,'بی‌نظمی کلاس','یادداشت یک','1404/08/10',1,0,'pending','', '1404/08/10')", [${sidA}]);
DB::execute("INSERT INTO student_discipline_records (student_id,title_id,title_text,internal_note,occurred_at_jalali,notify_parents,is_justified,review_status,review_note,created_at_jalali) VALUES (?,NULL,'تأخیر صبحگاهی','یادداشت دو','1404/08/12',0,1,'reviewed','بررسی شد', '1404/08/12')", [${sidA}]);
echo 'OK';`);
const modal = await req('مودال انضباط', { file: 'student-modal.php', sid: 'f213adm1', query: 'type=discipline&student_id=' + sidA });
let modalJson = {}; try { modalJson = JSON.parse(modal.res.page); } catch (e) {}
const modalHtml = modalJson.html || '';
ok('مودال JSON سالم برمی‌گرداند', modalJson.ok === true, (modal.res.page || '').slice(0, 160));
ok('چیدمان دو پنلی دارد', modalHtml.includes('disc-grid') && modalHtml.includes('disc-panel-entry') && modalHtml.includes('disc-panel-list'));
ok('پنل ثبت پیش از پنل فهرست در DOM است (در موبایل اول می‌آید)',
  modalHtml.indexOf('disc-panel-entry') < modalHtml.indexOf('disc-panel-list'));
ok('هر مورد انضباطی یک ردیف جدول با فرم مستقل دارد',
  (modalHtml.match(/<form id="disc-row-\d+"/g) || []).length === 2,
  String((modalHtml.match(/<form id="disc-row-\d+"/g) || []).length));
ok('ورودی‌های هر ردیف با ویژگی form به همان ردیف وصل‌اند', /<input form="disc-row-\d+" name="title_text"/.test(modalHtml));
ok('«متن بررسی» هم ورودیِ همان ردیف است و مقدار فعلی را نگه می‌دارد (ذخیرهٔ ردیف پاکش نمی‌کند)',
  /<textarea form="disc-row-\d+" name="review_note"/.test(modalHtml) && modalHtml.includes('>بررسی شد</textarea>'));
/* V4.214.2 — ستون «تاریخ» جدا نیست و زیر «عنوان» رفته؛ پس داده‌های همان ردیف
   سنجیده می‌شوند، نه شمار ستون‌ها. */
ok('همهٔ داده‌های ردیف هست (عنوان/تاریخ/موجه/بررسی/یادداشت/اعلان/اقدام)',
  ['name="title_text"', 'name="occurred_at_jalali"', 'name="is_justified"', 'name="review_status"',
   'name="internal_note"', 'name="review_note"'].every(h => modalHtml.includes(h))
  && modalHtml.includes('>موجه<') && modalHtml.includes('>اعلان<') && modalHtml.includes('>اقدام<'));
ok('وضعیت اعلان هر ردیف دیده می‌شود', modalHtml.includes('>ارسال شد<'));
ok('مودال تمام‌عرض می‌شود (CSS مودال کارت را باز می‌کند)', modalHtml.includes('#studentAjaxModal .modal-card') && modalHtml.includes('min(1180px'));

/* ویرایش یک ردیف باید متن بررسی را هم ذخیره کند */
const recId = (await db(`SELECT id FROM student_discipline_records WHERE student_id=${sidA} AND title_text='بی‌نظمی کلاس'`))[0].id;
const upd = await req('ویرایش ردیف انضباط', {
  file: 'student-modal.php', sid: 'f213adm1', method: 'POST',
  post: { csrf_token: 'f213-token', type: 'discipline_update', student_id: sidA, record_id: recId, title_text: 'بی‌نظمی کلاس', occurred_at_jalali: '1404/08/10', internal_note: 'یادداشت یک', review_status: 'reviewed', review_note: 'متن بررسی تازه' },
});
const recAfter = await db(`SELECT review_status, review_note FROM student_discipline_records WHERE id=${recId}`);
ok('ویرایش ردیف، «متن بررسی» را هم ذخیره می‌کند',
  recAfter[0] && recAfter[0].review_note === 'متن بررسی تازه' && recAfter[0].review_status === 'reviewed', JSON.stringify(recAfter));

/* ═══════════ ۹) مقایسهٔ نمرات ماهانه ═══════════ */
console.log('\n══ ۹) مقایسهٔ نمرات ماهانه ══');
const cmp = await req('مقایسه', { file: 'student-report-compare.php', sid: 'f213adm1', query: 'student_id=' + sidA });
const cHtml = cmp.res.page;
ok('صفحهٔ مقایسه بدون خطا رندر می‌شود', !cmp.res.fatal, cmp.res.fatal || '');
ok('هر سه ماه ستون جدول دارند', ['مهر','آبان','آذر'].every(m => cHtml.includes('>' + m + '<')));
ok('هر سه درس ردیف دارند', ['ریاضی','علوم تجربی','زبان انگلیسی'].every(s => cHtml.includes('>' + s + '</td>')));
ok('معدل هر ماه و میانگین نمایش داده می‌شود', cHtml.includes('معدل ماه') && cHtml.includes('میانگین معدل ماه‌ها'));
ok('تحلیل عملکرد کلی نوشته می‌شود', cHtml.includes('تحلیل عملکرد کلی') && cHtml.includes('روند معدل'));
ok('نمودار میله‌ای معدل ماه‌ها هست', cHtml.includes('src-bar-fill'));
ok('دکمهٔ چاپ دارد', cHtml.includes('چاپ مقایسه') && cHtml.includes('window.print()'));

/* نسخهٔ دانش‌آموز: ماه قفل‌شده نباید در مقایسه بیاید */
await run(`<?php require_once '/www/includes/functions.php';
DB::execute("INSERT INTO report_locks (academic_year,report_month,class_name,student_id,is_locked,reason) VALUES (?,?,?,?,1,'قفل فردی آزمون V4.213.0')", [${JSON.stringify(curYear)},'آذر','۹/۱',${sidA}]);
echo 'OK';`);
await login('f213stu1', sidA);
const stuCmp = await req('مقایسه (دانش‌آموز)', { file: 'student-report-compare.php', sid: 'f213stu1' });
const sHtml = stuCmp.res.page;
ok('دانش‌آموز صفحهٔ مقایسهٔ خودش را می‌بیند', !stuCmp.res.fatal && sHtml.includes('مقایسه نمرات ماهانه'));
ok('کارنامهٔ قفل‌شدهٔ او در مقایسه نمی‌آید', !sHtml.includes('>آذر<') && sHtml.includes('کارنامهٔ مسدود'), '');
ok('کارنامه‌های آزاد همان دانش‌آموز می‌مانند', sHtml.includes('>مهر<') && sHtml.includes('>آبان<'));

/* لینک مقایسه در مودال کارنامه‌ها */
const repModal = await req('مودال کارنامه‌ها', { file: 'student-modal.php', sid: 'f213adm1', query: 'type=reports&student_id=' + sidA });
let repJson = {}; try { repJson = JSON.parse(repModal.res.page); } catch (e) {}
ok('دکمهٔ «مقایسه نمرات ماهانه» کنار «+ ثبت کارنامه جدید» هست',
  (repJson.html || '').includes('مقایسه نمرات ماهانه') && (repJson.html || '').includes('student-report-compare.php?student_id=' + sidA));

/* پاک‌سازی قفل آزمون تا اجرای بعدی تحت تأثیر نماند */
await run(`<?php require_once '/www/includes/functions.php';
DB::execute("DELETE FROM report_locks WHERE reason LIKE '%V4.213.0%'"); echo 'OK';`);

console.log(`\n  سوئیت V4.213.0: ${pass} PASS / ${fail} FAIL`);
process.exit(fail ? 1 : 0);
