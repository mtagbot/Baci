/**
 * test-v4.214.0-features.mjs — شش خواستهٔ نشست ۱۱-۰۳ (V4.214.0)
 *
 * هر بخش همان چیزی را می‌سنجد که مدیر خواسته بود، با اجرای واقعی PHP روی
 * سایت ساخته‌شده + فایل مؤثرِ داخل رانتایم (نه سورس خیالی):
 *
 *   ۱) کارنامهٔ ربات: وضعیت نمره با طیف رنگ (نمره مشکی)، هم در تصویر و هم در PDF
 *   ۲) روبیکا در «اعلام سراسری پایه‌ای و کلاسی» — با ارسال واقعی (ستون چت درست)
 *   ۳) دکمهٔ «بازیابی دانش‌آموز» هم‌شکل با نوار و با رنگ ثانویهٔ سفارشی‌سازی
 *   ۴) هدر: «کاربری» چپ‌ترین عنصر، تاریخ کنارش
 *   ۵) پنجرهٔ انضباط بزرگ‌تر، جدول با چیدمان ثابت و بدون اسکرول افقی
 *   ۶) شناور ردیف (tooltip) زیر پنجره‌ها و خاموش تا وقتی پنجره‌ای باز است
 */
import { run, req, loginAdmin, php } from './harness/lib.mjs';
import { resolveSite } from './harness/site.mjs';

let pass = 0, fail = 0;
const ok = (name, cond, detail = '') => cond
  ? (pass++, console.log(`  ✅ ${name}`))
  : (fail++, console.log(`  ❌ ${name}${detail ? '  → ' + detail : ''}`));

const SITE = resolveSite();
const PATCH = process.env.PATCH || 'update-v4.214.0';
console.log(`>>> سورس: ${SITE} · وصله: ${PATCH}`);

const siteFile = rel => {
  try { return php.readFileAsText('/www/' + rel); } catch (e) { return ''; }
};

/* ═══════════ ۰) دادهٔ آزمایشی ═══════════ */
const curYear = (await run(String.raw`<?php require_once '/www/includes/functions.php';
echo 'Y=' . get_current_academic_year();`)).out.match(/Y=([\d\/\-]+)/)[1];

await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
ensure_bot_schema('rubika');
set_setting('current_academic_year', ${JSON.stringify(curYear)});
DB::execute("DELETE FROM students WHERE national_id LIKE '214%'");
$sid = 0;
DB::execute("INSERT INTO students (first_name,last_name,father_name,national_id,class_name,grade_level,academic_year,status,password) VALUES ('سارا','رستمی','پدر','21400000001','۹/۱','نهم',?,'active','x')", [${JSON.stringify(curYear)}]);
$sid = (int)DB::lastInsertId();
DB::execute("INSERT INTO reports (student_id,class_name,academic_year,term,report_month,total_score,gpa) VALUES (?,? ,?,'نوبت اول','مهر',0,12.5)", [$sid,'۹/۱',${JSON.stringify(curYear)}]);
$rid = (int)DB::lastInsertId();
$scores = [0, 1.5, 3, 5, 7, 11, 13, 15, 17, 19.5, 21];
foreach ($scores as $i => $sc) {
  DB::execute("INSERT INTO report_grades (report_id,subject_name,score,max_score,coefficient,status) VALUES (?,?,?,20,1,'passed')", [$rid, 'درس ' . ($i+1), $sc]);
}
echo 'SID=' . $sid . ';RID=' . $rid;`);

const ids = (await run(String.raw`<?php require_once '/www/includes/functions.php';
$s = DB::fetch("SELECT id FROM students WHERE national_id='21400000001'");
$r = DB::fetch("SELECT id FROM reports WHERE student_id=? ORDER BY id DESC", [(int)$s['id']]);
echo 'IDS=' . json_encode(['sid'=>(int)$s['id'],'rid'=>(int)$r['id']]);`)).out.match(/IDS=(\{.*\})/)[1];
const SID = JSON.parse(ids).sid, RID = JSON.parse(ids).rid;
ok('دانش‌آموز و کارنامهٔ آزمایشی ساخته شدند', SID > 0 && RID > 0);

/* ═══════════ ۱) وضعیت نمره در کارنامهٔ ربات (تصویر + PDF) ═══════════ */
console.log('\n══ ۱) کارنامهٔ ربات: نمرهٔ مشکی + طیف رنگ وضعیت ══');
const styles = await run(String.raw`<?php
require_once '/www/includes/functions.php';
require_once '/www/includes/report_image.php';
$out = [];
foreach ([0, 1.5, 3, 5, 7, 11, 13, 15, 17, 19.5, 21, null, ''] as $sc) {
  $st = report_image_grade_style($sc);
  $out[] = ['score' => $sc, 'label' => $st['label'], 'score_color' => $st['score_color'], 'dot' => $st['dot'], 'bg' => $st['bg']];
}
echo 'STYLES=' . json_encode($out, JSON_UNESCAPED_UNICODE);
echo 'BANDS=' . json_encode(array_column(report_grade_status_bands(), 'dot'));
/* یک نمره از هر پله، به ترتیب ۰→۲۰ */
echo 'ONE=' . json_encode(array_map(function ($sc) { return report_image_grade_style($sc)['dot']; }, [1, 3, 5, 7, 11, 13, 15, 17, 19]));`);
const ST = JSON.parse(styles.out.match(/STYLES=(\[.*\])BANDS=/)[1]);
const BAND_DOTS = JSON.parse(styles.out.match(/BANDS=(\[[^\]]*\])/)[1]);
const ONE_DOTS = JSON.parse(styles.out.match(/ONE=(\[[^\]]*\])/)[1]);
const expect = {
  '0': 'کاملا مردود', '1.5': 'کاملا مردود', '3': 'بدون تلاش', '5': 'بسیار ضعیف',
  '7': 'تلاش ناکافی', '11': 'متوسط ضعیف', '13': 'متوسط', '15': 'متوسط خوب',
  '17': 'بسیار خوب', '19.5': 'عالی', '21': 'غیبت', 'null': 'ثبت نشده', '': 'ثبت نشده',
};
let labelsOk = true, blackOk = true, detail = '';
for (const row of ST) {
  const key = row.score === null ? 'null' : String(row.score);
  if (row.label !== expect[key]) { labelsOk = false; detail += `${key}⇒${row.label} `; }
  if (row.score_color !== '#0f172a') { blackOk = false; detail += `${key}⇒${row.score_color} `; }
}
ok('هر بازهٔ نمره همان برچسب خواسته‌شده را می‌گیرد (۰ تا ۲۰ + غیبت)', labelsOk, detail);
ok('رنگ نمره در همهٔ حالت‌ها مشکی است (#0f172a)', blackOk, detail);
ok('۲۱ «غیبت» است و رنگ وضعیت خاکستری می‌گیرد', ST.find(r => r.score === 21)?.label === 'غیبت' && ST.find(r => r.score === 21)?.dot === '#64748b');
ok('نمرهٔ خالی «ثبت نشده» است، نه «کاملا مردود»', ST.find(r => r.score === null)?.label === 'ثبت نشده');

ok('طیف رنگ کارنامهٔ ربات دقیقاً همان پله‌های سایت است (۹ رنگ یکتا و یکسان)',
  JSON.stringify(ONE_DOTS) === JSON.stringify(BAND_DOTS) && new Set(ONE_DOTS).size === 9,
  ONE_DOTS.join(','));
ok('طیف از قرمز بحرانی تا سبز عالی حرکت می‌کند',
  ONE_DOTS[0] === '#b91c1c' && ONE_DOTS[8] === '#047857');

const imgSrc = siteFile('includes/report_image.php');
ok('فایل مؤثر سایت نسخهٔ تازهٔ report_image.php است',
  imgSrc.includes("V4.214.0") && imgSrc.includes('report_image_grade_style'), String(imgSrc.length));
ok('نمره در جدول با رنگ مشکی ($ink) رسم می‌شود، نه رنگ شرطی قدیمی',
  imgSrc.includes("gd_text($im,display_score($g['score'] ?? null),700,$y+48,22,$ink,true,'center');")
  && !imgSrc.includes('$scoreColor'));
ok('وضعیت به‌صورت قرص رنگی در ستون خودش رسم می‌شود',
  imgSrc.includes("gd_status_pill($im,165,$y+37,$gStyle['label'],$gStyle,170);"));
/* V4.214.1 — نقطهٔ دایره‌ای از قرص وضعیت برداشته شد؛ فقط متن. */
ok('قرص وضعیت نقطهٔ دایره‌ای ندارد و متنش وسط‌چین است',
  !/gd_status_pill[\s\S]{0,1200}imagefilledellipse/.test(imgSrc)
  && /gd_text\(\$im, \$text, \(int\)round\(\$cx\), \(int\)round\(\$cy\) \+ 7, \$size,[\s\S]{0,80}'center'\)/.test(imgSrc));
/* V4.214.1 — مدیر خواست راهنمای طیف رنگ از تصویر برداشته شود. */
ok('راهنمای طیف رنگ (بحرانی تا عالی) در تصویر نیست',
  !imgSrc.includes('راهنمای وضعیت نمره: از بحرانی تا عالی') && !imgSrc.includes('$lgX1'));
ok('کارت «معدل کل» هم رنگ و برچسب وضعیت را می‌گیرد',
  imgSrc.includes("report_image_grade_style($r['gpa'] ?? null)"));
const helperSrc = siteFile('includes/bot_helpers.php');
ok('نسخهٔ PDF ربات از همین تصویر ساخته می‌شود (پس همان وضعیت‌ها را دارد)',
  /function bot_generate_report_pdf[\s\S]{0,400}generate_report_card_image\(/.test(helperSrc));
ok('دکمهٔ «دریافت نسخه PDF» به همان تابع PDF وصل است',
  helperSrc.includes("bot_send_report_pdf_to_chat") && helperSrc.includes("pdfrep_"));

/* رندر واقعی تصویر فقط وقتی GD با FreeType باشد ممکن است؛ در غیر این صورت
   همان منطقِ رنگ/برچسب بالا سنجیده شده است. وضعیت به‌صورت خط روشن چاپ می‌شود. */
const ftCheck = await run(String.raw`<?php echo 'FT=' . (@imagettfbbox(12,0,'/www/uploads/Vazirmatn/Vazirmatn-Regular.ttf','ت') ? 'yes' : 'no');`);
if (/FT=yes/.test(ftCheck.out)) {
  const render = await run(`<?php require_once '/www/includes/functions.php'; require_once '/www/includes/report_image.php';
    try { $p = generate_report_card_image(${RID}); $s = getimagesize($p); echo 'IMGOK=' . ($s ? $s[0] . 'x' . $s[1] : 'no'); }
    catch (Throwable $e) { echo 'IMGERR=' . $e->getMessage(); }`);
  ok('تصویر کارنامهٔ ربات بدون خطا ساخته می‌شود', /IMGOK=1600x\d+/.test(render.out), render.out);
} else {
  console.log('  ⏭  GD این محیط FreeType ندارد؛ رستر واقعی تصویر ممکن نیست — منطق رنگ/برچسب اجرا و سنجیده شد.');
}

/* ═══════════ ۲) روبیکا در اعلام سراسری ═══════════ */
console.log('\n══ ۲) اعلام سراسری: بله، تلگرام و روبیکا ══');
await run(`<?php @mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess'); session_name('BACI_TEST'); session_id('f214adm1'); session_start(); $_SESSION['csrf_token']='f214-token'; echo 'OK';`);
await loginAdmin('f214adm1');
const bcGet = await req('اعلام سراسری', {
  file: 'report-broadcast.php', sid: 'f214adm1',
  query: 'academic_year=' + encodeURIComponent(curYear) + '&report_month=' + encodeURIComponent('مهر') + '&scope=all',
});
const bcHtml = bcGet.res.page;
ok('صفحه بدون خطا رندر می‌شود', !bcGet.res.fatal, bcGet.res.fatal || '');
ok('هر سه پیام‌رسان در فرم هست: بله، تلگرام، روبیکا',
  ['bale', 'telegram', 'rubika'].every(pf => bcHtml.includes('value="' + pf + '"'))
  && (bcHtml.match(/name="platforms\[\]"/g) || []).length === 3);
ok('برچسب «روبیکا» دیده می‌شود', bcHtml.includes('> روبیکا<'));

const bcSrc = siteFile('report-broadcast.php');
ok('ستون چت از خودِ پیام‌رسان خوانده می‌شود (نه حدس بله/تلگرام)',
  bcSrc.includes('$chatCol = bot_chat_column($pf);') && !bcSrc.includes("$pf === 'telegram' ? 'telegram_chat_id' : 'bale_chat_id'"));
ok('rb_platforms از bot_platforms می‌آید (یک فهرست در کل سامانه)',
  /function rb_platforms\(\)[\s\S]{0,400}bot_platforms\(\)/.test(bcSrc));

/* لینک روبیکای دانش‌آموز فقط در ستون rubika_chat_id ثبت می‌شود */
await run(`<?php require_once '/www/includes/functions.php'; require_once '/www/includes/bot_helpers.php';
ensure_bot_schema('rubika');
DB::execute("DELETE FROM rubika_bot_users WHERE student_id=?", [${SID}]);
DB::execute("INSERT INTO rubika_bot_users (student_id, rubika_chat_id, rubika_username, created_at) VALUES (?, 'RUB-CHAT-214', 'sara_r', '1404/08/10')", [${SID}]);
echo 'OK';`);
const chatCol = await run(String.raw`<?php require_once '/www/includes/functions.php'; require_once '/www/includes/bot_helpers.php';
echo 'COL=' . bot_chat_column('rubika');`);
ok('ستون چت روبیکا rubika_chat_id است', /COL=rubika_chat_id/.test(chatCol.out), chatCol.out);

const bcPost = await req('ارسال روبیکا', {
  file: 'report-broadcast.php', sid: 'f214adm1', method: 'POST',
  post: { csrf_token: 'f214-token', academic_year: curYear, report_month: 'مهر', scope: 'all', platforms: ['rubika'] },
});
const flash = (bcPost.res.flash || {}).message || '';
ok('ارسال با روبیکا پذیرفته می‌شود (پیام «حداقل یک ربات» نمی‌آید)',
  (bcPost.res.flash || {}).type !== 'error', flash);
ok('لینک روبیکای دانش‌آموز پیدا و ارسال «تلاش» شمرده می‌شود (یعنی ستون درست خوانده شده)',
  /تلاش ارسال: 1/.test(flash) && /بدون اتصال ربات: 0/.test(flash), flash);
ok('ارسال ناموفق (اینترنتِ این محیط) درست شمرده می‌شود، نه «موفق»',
  /موفق: 0/.test(flash) && /ناموفق: 1/.test(flash), flash);

const badPf = await req('پلتفرم ناشناخته', {
  file: 'report-broadcast.php', sid: 'f214adm1', method: 'POST',
  post: { csrf_token: 'f214-token', academic_year: curYear, report_month: 'مهر', scope: 'all', platforms: ['soroush'] },
});
ok('هر مقداری جز سه پیام‌رسان همان‌جا دور ریخته می‌شود',
  (badPf.res.flash || {}).type === 'error' && String((badPf.res.flash || {}).message || '').includes('روبیکا'),
  JSON.stringify(badPf.res.flash || {}));

/* ═══════════ ۳) دکمهٔ «بازیابی دانش‌آموز» ═══════════ */
console.log('\n══ ۳) دکمهٔ بازیابی: هم‌شکل نوار + رنگ ثانویهٔ سفارشی‌سازی ══');
const studentsPage = await req('دانش‌آموزان', { file: 'students.php', sid: 'f214adm1' });
const stHtml = studentsPage.res.page;
ok('صفحهٔ مدیریت دانش‌آموزان رندر می‌شود', !studentsPage.res.fatal, studentsPage.res.fatal || '');
const recBtn = (stHtml.match(/<a[^>]*href="student-recovery\.php"[^>]*>[^<]*<\/a>/) || [''])[0];
ok('دکمهٔ بازیابی همان کلاس دکمه‌های کنارش را دارد (btn + btn-warning + text-xs)',
  /class="btn btn-warning text-xs"/.test(recBtn), recBtn);
ok('دیگر کلاس خاکستری btn-secondary روی این دکمه نیست', !/btn-secondary/.test(recBtn), recBtn);
ok('دکمه بعد از «افزودن دانش‌آموز» و در همان نوار عملیات است',
  stHtml.indexOf('>افزودن دانش‌آموز</a>') > 0
  && stHtml.indexOf('>افزودن دانش‌آموز</a>') < stHtml.indexOf('student-recovery.php'));
const siteCss = siteFile('assets/css/style.css');
ok('btn-warning رنگ ثانویهٔ سایت (--accent) را می‌خواند؛ پس با سفارشی‌سازی هم‌رنگ می‌ماند',
  /\.btn-warning\s*\{[^}]*var\(--accent/.test(siteCss));

/* ═══════════ ۴) ترتیب هدر ═══════════ */
console.log('\n══ ۴) هدر: «کاربری» چپ‌ترین، تاریخ کنارش ══');
const headerSlice = (stHtml.match(/<header[\s\S]*?<\/header>/) || [''])[0];
ok('هدر پیدا شد', headerSlice.length > 200, String(headerSlice.length));
const iDate = headerSlice.indexOf('hdr-date');
const iUser = headerSlice.indexOf('user-menu-wrap');
ok('در DOM تاریخ پیش از دکمهٔ کاربری است (پس «کاربری» چپ‌ترین عنصر می‌شود)', iDate > 0 && iUser > iDate, `${iDate}/${iUser}`);
ok('«مورخ:» در هدر نیست', !headerSlice.includes('مورخ:'));
ok('تاریخ شمسی همان‌جا رندر می‌شود', /hdr-date[^>]*>[^<]*[0-9۰-۹]/.test(headerSlice));

/* ═══════════ ۵) پنجرهٔ انضباط ═══════════ */
console.log('\n══ ۵) پنجرهٔ انضباط: بزرگ‌تر، بدون اسکرول افقی ══');
const modal = await req('مودال انضباط', { file: 'student-modal.php', sid: 'f214adm1', query: 'type=discipline&student_id=' + SID });
let mJson = {}; try { mJson = JSON.parse(modal.res.page); } catch (e) {}
const mHtml = mJson.html || '';
ok('مودال JSON سالم برمی‌گرداند', mJson.ok === true, (modal.res.page || '').slice(0, 140));
/* V4.214.0 عرض ۱۵۶۰ را آورد؛ V4.214.1 آن را تا ۹۸٪ صفحه بزرگ کرد — سنجه «بزرگ‌تر»
   است، نه عددِ ثابت. */
const discW = (mHtml.match(/#studentAjaxModal\.modal-disc \.modal-card\{width:min\((\d+)px,98vw\)/) || [])[1];
ok('پنجرهٔ انضباط عرض اختصاصی و بزرگ‌تر دارد', Number(discW) >= 1560, String(discW));
ok('جدول چیدمان ثابت دارد (ستون‌ها از عرض بیرون نمی‌زنند)', mHtml.includes('table-layout:fixed'));
const cols = mHtml.match(/<col style="width:(\d+)%">/g) || [];
const colSum = cols.reduce((a, c) => a + parseInt(c.match(/(\d+)%/)[1], 10), 0);
ok('هفت ستون درصدی و مجموعاً ۱۰۰٪', cols.length === 7 && colSum === 100, `${cols.length}/${colSum}`);
ok('ورودی‌های داخل جدول به عرض ستون محدود شده‌اند', /\.disc-table \.form-input[^{]*\{[^}]*width:100%/.test(mHtml));
ok('اسکرول افقی بسته شده است', /\.disc-table-wrap\{[^}]*overflow-x:hidden/.test(mHtml));
const stSrc = siteFile('students.php');
ok('students.php هنگام باز کردن انضباط کلاس modal-disc را می‌گذارد',
  stSrc.includes("modal.classList.toggle('modal-disc', type === 'discipline')"));

/* ═══════════ ۶) شناور ردیف و پنجره‌ها ═══════════ */
console.log('\n══ ۶) tooltip ردیف: زیر پنجره‌ها و خاموش وقتی پنجره باز است ══');
ok('لایهٔ شناور پایین‌تر از لایهٔ مودال‌هاست (۹۰۰ < ۱۰۰۰)',
  /\.student-hover-tooltip\{\s*z-index:\s*900\s*!important/.test(stSrc)
  && /\.modal-backdrop\s*\{[^}]*z-index:\s*1000/.test(siteCss));
ok('با باز بودن پنجره، شناور اصلاً نمایش داده نمی‌شود',
  stSrc.includes('body.student-modal-open .student-hover-tooltip{ display: none !important; }'));
ok('باز کردن پنجره شناور را پاک می‌کند و کلاس را می‌گذارد',
  /function openStudentModal[\s\S]{0,400}hideStudentTooltip\(\)[\s\S]{0,200}classList\.add\('student-modal-open'\)/.test(stSrc));
ok('بستن پنجره کلاس را برمی‌دارد',
  /function closeStudentModal[\s\S]{0,300}classList\.remove\('student-modal-open'\)/.test(stSrc));
ok('حتی با حرکت نشانگر روی ردیف، تا باز بودن پنجره شناور نمی‌آید',
  /mousemove[\s\S]{0,300}if\(document\.body\.classList\.contains\('student-modal-open'\)\) return;/.test(stSrc));
/* در سورس، بعضی فراخوانی‌ها داخل رشتهٔ JS با \' نوشته شده‌اند؛ برای همین
   قبل از مقایسه، بک‌اسلش‌ها برداشته می‌شوند. */
const stFlat = stSrc.replace(/\\'/g, "'");
ok('پنجره‌های کارنامه/انضباط/اطلاعات همه از همین مسیر باز می‌شوند',
  ['reports', 'discipline', 'info'].every(t => stFlat.includes("openStudentModal('" + t + "'")));

console.log(`\n  سوئیت V4.214.0: ${pass} PASS / ${fail} FAIL`);
process.exit(fail ? 1 : 0);
