/**
 * test-v4.214.1-features.mjs — چهار اصلاح بازبینیِ V4.214.0
 *
 *   ۱) «بازیابی دانش‌آموز» در هیچ عرضی دو خط نمی‌شود
 *   ۲) هدر: فاصلهٔ تاریخ و «کاربری» بسته شد
 *   ۳) پنجرهٔ انضباط تقریباً تمام‌صفحه و بدون شکستن کلمه‌ها
 *      («ذخیره»، «حذف»، «موجه»، «ارسال شد»)
 *   ۴) کارنامهٔ ربات: راهنمای طیف رنگ و نقطهٔ «•» حذف شد
 *
 * همهٔ سنجه‌ها روی نسخهٔ نصب‌شده (SITE) اجرا می‌شوند؛ PATCH فقط برای
 * مقایسهٔ بستهٔ اصلاحی با سایت است.
 */
import { run, req, loginAdmin, php } from './harness/lib.mjs';
import { resolveSite, REPO } from './harness/site.mjs';
import { readFileSync, existsSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

let pass = 0, fail = 0;
const ok = (name, cond, detail = '') => cond
  ? (pass++, console.log(`  ✅ ${name}`))
  : (fail++, console.log(`  ❌ ${name}${detail ? '  → ' + detail : ''}`));

const SITE = resolveSite();
/* آخرین پوشهٔ اصلاحیِ موجود در مخزن — تا این سوئیت با هر نسخهٔ تازه هم
   بدون تنظیم PATCH سبز بماند. */
const latestPatch = () => {
  try {
    const dirs = readdirSync(REPO).filter(d => /^update-v4\.\d+\.\d+(\.\d+)?$/.test(d));
    const v = d => d.slice('update-v4.'.length).split('.').map(Number).concat([0, 0]).slice(0, 3);
    dirs.sort((a, b) => { const [x, y] = [v(a), v(b)]; for (let i = 0; i < 3; i++) if (x[i] !== y[i]) return x[i] - y[i]; return 0; });
    return dirs[dirs.length - 1] || 'update-v4.152.0';
  } catch (e) { return 'update-v4.152.0'; }
};
const PATCH = process.env.PATCH || latestPatch();
console.log(`>>> سورس: ${SITE} · وصله: ${PATCH}`);

const siteFile = rel => {
  try { return php.readFileAsText('/www/' + rel); } catch (e) { return ''; }
};
const patchFile = rel => {
  const p = join(REPO, PATCH, rel);
  return existsSync(p) ? readFileSync(p, 'utf8') : '';
};
const deskFile = rel => {
  const p = join(REPO, 'desktop-app-v2', 'patch', rel);
  return existsSync(p) ? readFileSync(p, 'utf8') : '';
};

/* ═══════════ ۰) دادهٔ آزمایشی ═══════════ */
const curYear = (await run(String.raw`<?php require_once '/www/includes/functions.php';
echo 'Y=' . get_current_academic_year();`)).out.match(/Y=([\d\/\-]+)/)[1];

await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
set_setting('current_academic_year', ${JSON.stringify(curYear)});
DB::execute("DELETE FROM students WHERE national_id LIKE '2141%'");
DB::execute("INSERT INTO students (first_name,last_name,father_name,national_id,class_name,grade_level,academic_year,status,password) VALUES ('مینا','کاظمی','پدر','21410000001','۹/۲','نهم',?,'active','x')", [${JSON.stringify(curYear)}]);
$sid = (int)DB::lastInsertId();
if (function_exists('ensure_discipline_schema')) ensure_discipline_schema();
DB::execute("INSERT INTO reports (student_id,class_name,academic_year,term,report_month,total_score,gpa) VALUES (?,? ,?,'نوبت دوم','آبان',0,14.25)", [$sid,'۹/۲',${JSON.stringify(curYear)}]);
$rid = (int)DB::lastInsertId();
foreach ([0, 5, 11, 15, 19.5, 21] as $i => $sc) {
  DB::execute("INSERT INTO report_grades (report_id,subject_name,score,max_score,coefficient,status) VALUES (?,?,?,20,1,'passed')", [$rid, 'درس ' . ($i+1), $sc]);
}
try {
  DB::execute("INSERT INTO student_discipline_records (student_id,title_text,occurred_at_jalali,is_justified,notify_parents,review_status,created_at_jalali) VALUES (?,'تأخیر صبحگاهی','1404/08/11',1,1,'reviewed','1404/08/11')", [$sid]);
  DB::execute("INSERT INTO student_discipline_records (student_id,title_text,occurred_at_jalali,is_justified,notify_parents,review_status,created_at_jalali) VALUES (?,'غیبت غیرموجه','1404/08/12',0,0,'pending','1404/08/12')", [$sid]);
} catch (Throwable $e) { echo 'DISC=' . $e->getMessage(); }
echo 'SID=' . $sid . ';RID=' . $rid;`);

const ids = (await run(String.raw`<?php require_once '/www/includes/functions.php';
$s = DB::fetch("SELECT id FROM students WHERE national_id='21410000001'");
$r = DB::fetch("SELECT id FROM reports WHERE student_id=? ORDER BY id DESC", [(int)$s['id']]);
echo 'IDS=' . json_encode(['sid'=>(int)$s['id'],'rid'=>(int)$r['id']]);`)).out.match(/IDS=(\{.*\})/)[1];
const SID = JSON.parse(ids).sid, RID = JSON.parse(ids).rid;
ok('دانش‌آموز و کارنامهٔ آزمایشی ساخته شدند', SID > 0 && RID > 0);

await run(`<?php @mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess'); session_name('BACI_TEST'); session_id('f2141adm'); session_start(); $_SESSION['csrf_token']='f2141-token'; echo 'OK';`);
await loginAdmin('f2141adm');

/* ═══════════ ۱) دکمهٔ «بازیابی دانش‌آموز» یک‌خطی ═══════════ */
console.log('\n══ ۱) «بازیابی دانش‌آموز»: یک‌خطی و بدون جمع‌شدن ══');
const studentsPage = await req('دانش‌آموزان', { file: 'students.php', sid: 'f2141adm' });
const stHtml = studentsPage.res.page || '';
ok('صفحهٔ مدیریت دانش‌آموزان رندر می‌شود', !studentsPage.res.fatal, studentsPage.res.fatal || '');
const recBtn = (stHtml.match(/<a[^>]*href="student-recovery\.php"[^>]*>[^<]*<\/a>/) || [''])[0];
ok('دکمهٔ بازیابی white-space:nowrap دارد', /white-space\s*:\s*nowrap/.test(recBtn), recBtn);
ok('دکمهٔ بازیابی اجازهٔ جمع‌شدن ندارد (قاعدهٔ نوار عملیات)', /\.student-list-actions\s+\.btn\s*\{[^}]*white-space:\s*nowrap[^}]*\}/.test(stHtml),
  (stHtml.match(/\.student-list-actions[^{]*\{[^}]*\}/) || [''])[0]);
ok('همان قاعده flex:none می‌دهد تا متن به دو خط نشکند',
  /\.student-list-actions\s+\.btn\s*\{[^}]*flex:\s*none/.test(stHtml));
ok('نوار عملیات کلاس student-list-actions را دارد', /class="[^"]*student-list-actions[^"]*"/.test(stHtml));
const stSrc = siteFile('students.php');
ok('نسخهٔ نصب‌شده همان اصلاح را دارد (نه فقط بستهٔ اصلاحی)', stSrc.includes('white-space:nowrap') && stSrc.includes('.student-list-actions .btn'));
const deskStudents = deskFile('www-students.php');
ok('بستهٔ دسکتاپ هم دکمه را یک‌خطی می‌کند',
  /style="white-space:nowrap(?:;min-width:\d+px)?"/.test(deskStudents) && deskStudents.includes('.student-list-actions .btn'));

/* ═══════════ ۲) هدر: تاریخ چسبیده به «کاربری» ═══════════ */
console.log('\n══ ۲) هدر: بدون فاصلهٔ اضافی بین تاریخ و «کاربری» ══');
const headerSlice = (stHtml.match(/<header[\s\S]*?<\/header>/) || [''])[0];
ok('هدر پیدا شد', headerSlice.length > 200, String(headerSlice.length));
ok('گروه راست هدر فاصلهٔ ۶ پیکسلی دارد (به‌جای gap-4)', /<div class="flex items-center gap-4" style="gap:6px">/.test(headerSlice),
  (headerSlice.match(/<div class="flex items-center[^"]*"[^>]*>/) || [''])[0]);
ok('جعبهٔ تاریخ به اندازهٔ متن است، نه ۱۸۰ پیکسل ثابت',
  /hdr-date"[^>]*style="[^"]*width:auto/.test(headerSlice) && /hdr-date"[^>]*style="[^"]*flex:0 0 auto/.test(headerSlice));
ok('تاریخ پیش از دکمهٔ کاربری و چسبیده به آن است',
  headerSlice.indexOf('hdr-date') > 0 && headerSlice.indexOf('user-menu-wrap') > headerSlice.indexOf('hdr-date'));
const deskHeader = deskFile('includes-header.php');
ok('بستهٔ دسکتاپ هم همین هدر را دارد',
  deskHeader.includes('style="gap:6px"') && deskHeader.includes('flex:0 0 auto;width:auto;white-space:nowrap'));

/* ═══════════ ۳) پنجرهٔ انضباط: بدون شکستن کلمه ═══════════ */
console.log('\n══ ۳) پنجرهٔ انضباط: تمام‌صفحه و بدون شکستن کلمه‌ها ══');
const modal = await req('مودال انضباط', { file: 'student-modal.php', sid: 'f2141adm', query: 'type=discipline&student_id=' + SID });
let mJson = {}; try { mJson = JSON.parse(modal.res.page); } catch (e) {}
const mHtml = mJson.html || '';
ok('مودال JSON سالم برمی‌گرداند', mJson.ok === true, (modal.res.page || '').slice(0, 160));
ok('پنجره دست‌کم ۹۸٪ عرض دید است', /#studentAjaxModal\.modal-disc \.modal-card\{width:(?:min\(\d+px,)?9\dvw[;}]/.test(mHtml), mHtml.match(/#studentAjaxModal\.modal-disc \.modal-card\{[^}]*\}/)?.[0] || '');
ok('حاشیهٔ پنجره کم شد (فضای بیشتر برای محتوا)', /#studentAjaxModal\.modal-disc\{padding:[68]px\}/.test(mHtml));
ok('سرستون‌ها نمی‌شکنند', /\.disc-table th\{[^}]*white-space:nowrap/.test(mHtml));
ok('سلول‌ها دیگر اجازهٔ شکستن کلمه ندارند', /\.disc-table td\{[^}]*word-break:normal/.test(mHtml) && !mHtml.includes('overflow-wrap:anywhere'));
ok('دکمه‌های «ذخیره» و «حذف» یک‌خطی‌اند', /\.disc-col-actions \.btn\{[^}]*white-space:nowrap/.test(mHtml));
ok('برچسب «موجه» یک‌خطی است', /\.disc-check\{[^}]*white-space:nowrap/.test(mHtml) && /\.disc-col-check,[^{]*\.disc-col-notify\{[^}]*white-space:nowrap/.test(mHtml));
ok('وضعیت اعلان «ارسال شد» یک‌خطی است', /\.disc-col-notify \.badge\{white-space:nowrap\}/.test(mHtml));
ok('متن واقعی «ارسال شد» در ردیف است', mHtml.includes('>ارسال شد<') || mHtml.includes('ارسال شد'));
const cols = mHtml.match(/<col style="width:(\d+)%">/g) || [];
const colSum = cols.reduce((a, c) => a + parseInt(c.match(/(\d+)%/)[1], 10), 0);
ok('ستون‌های درصدی و مجموعاً ۱۰۰٪', cols.length === 6 && colSum === 100, `${cols.length}/${colSum}`);
const deskModal = deskFile('www-student-modal.php');
ok('بستهٔ دسکتاپ هم پنجره را تمام‌صفحه و nowrap می‌کند',
  /width:(?:min\(\d+px,)?9\dvw/.test(deskModal) && /\.disc-col-actions \.btn\{[^}]*white-space:nowrap/.test(deskModal) && deskModal.includes('smIsDeputy'));

/* ═══════════ ۴) کارنامهٔ ربات: بی‌راهنما، بی‌نقطه ═══════════ */
console.log('\n══ ۴) کارنامهٔ ربات: حذف راهنمای رنگ و نقطهٔ «•» ══');
const imgSrc = siteFile('includes/report_image.php');
ok('فایل مؤثر سایت نسخهٔ تازهٔ report_image.php است',
  imgSrc.includes('V4.214.1') && imgSrc.includes('report_image_grade_style'), String(imgSrc.length));
ok('سطر راهنمای «از بحرانی تا عالی» در تصویر نیست', !imgSrc.includes('راهنمای وضعیت نمره'));
ok('نوار راهنمای رنگ و تیک‌هایش هم پاک شده‌اند',
  !imgSrc.includes('$lgX1') && !imgSrc.includes('$lgCursor') && !imgSrc.includes('$lgTick'));
const pillBody = (imgSrc.match(/function gd_status_pill\([\s\S]*?\n\}/) || [''])[0];
ok('در قرص وضعیت هیچ بیضیِ نقطه‌ای رسم نمی‌شود', pillBody && !pillBody.includes('imagefilledellipse'), String(pillBody.length));
ok('متن قرص وضعیت وسط‌چین و بدون جای نقطه است', /gd_text\(\$im, \$text, \(int\)round\(\$cx\), \(int\)round\(\$cy\) \+ 7, \$size,[\s\S]{0,90}'center'\)/.test(pillBody));
ok('جای دکمه/قرص وضعیت تنگ نشده (padding ۲۸)', pillBody.includes('+ 28'));
const imgDesk = deskFile('includes-report_image.php');
ok('بستهٔ دسکتاپ کارنامه هم همان تصویر را می‌دهد',
  imgDesk === patchFile('includes/report_image.php') && !imgDesk.includes('راهنمای وضعیت نمره'));
ok('کارت معدل و توزیع رنگ‌ها دست‌نخورده مانده (هیچ‌چیز جز راهنما حذف نشده)',
  imgSrc.includes("report_image_grade_style($r['gpa'] ?? null)") && imgSrc.includes("gd_status_pill($im,165,$y+37,$gStyle['label'],$gStyle,170);"));

const badge = await run(String.raw`<?php require_once '/www/includes/functions.php';
$out = [];
foreach ([0, 3, 7, 11, 15, 19, null, ''] as $sc) $out[] = report_grade_status_badge($sc);
echo 'BADGE=' . json_encode($out, JSON_UNESCAPED_UNICODE);
echo 'PRINT=' . json_encode([report_grade_status_print(11), report_grade_status_print(null)]);`);
const badges = JSON.parse(badge.out.match(/BADGE=(\[[\s\S]*\])PRINT=/)[1]);
ok('برچسب وضعیت در کارنامهٔ داخل سایت هم نقطه ندارد',
  badges.every(b => !/border-radius:50%|aria-hidden/.test(b)), badges[0] || '');
ok('هر برچسب فقط متن وضعیت است (بدون نقطهٔ آوانگاری)',
  badges.every(b => !/•/.test(b)) && badges.some(b => b.includes('متوسط ضعیف')));
ok('نسخهٔ چاپی هم مثل قبل فقط متن رنگی است', /PRINT=\["<span style=\\"color:#ca8a04/.test(badge.out) || /PRINT=\["<span style=\\"color:/.test(badge.out), badge.out.slice(-120));
const ftCheck = await run(String.raw`<?php echo 'FT=' . (@imagettfbbox(12,0,'/www/uploads/Vazirmatn/Vazirmatn-Regular.ttf','ت') ? 'yes' : 'no');`);
if (/FT=yes/.test(ftCheck.out)) {
  const render = await run(`<?php require_once '/www/includes/functions.php'; require_once '/www/includes/report_image.php';
    try { $p = generate_report_card_image(${RID}); $s = getimagesize($p); echo 'IMGOK=' . ($s ? $s[0] . 'x' . $s[1] : 'no'); }
    catch (Throwable $e) { echo 'IMGERR=' . $e->getMessage(); }`);
  ok('تصویر کارنامه بدون خطا ساخته می‌شود', /IMGOK=1600x\d+/.test(render.out), render.out);
} else {
  console.log('  ⏭  GD این محیط FreeType ندارد؛ سنجهٔ رستر انجام نشد (منطق بدون تغییر مانده).');
}

/* ═══════════ ۵) مهر نسخه و هم‌خوانی بسته‌ها ═══════════ */
console.log('\n══ ۵) مهر نسخه و هم‌خوانی بسته و سایت ══');
const verSrc = siteFile('config/version.php');
/* نسخه‌ها به‌جای عددِ ثابت، با «سقف همین نسخه» سنجیده می‌شوند تا هر اصلاح
   بعدی (۴.۲۱۴.۲، ۴.۲۱۴.۳، …) این سوئیت را قرمز نکند. */
const siteVer = (verSrc.match(/'site_version'\s*=>\s*'([^']+)'/) || [])[1] || '';
const deskVer = (verSrc.match(/'desktop_version'\s*=>\s*'([^']+)'/) || [])[1] || '';
const corrTag = (verSrc.match(/'correction'\s*=>\s*'([^']+)'/) || [])[1] || '';
const atLeast = (v, base) => {
  const a = v.split('.').map(Number), b = base.split('.').map(Number);
  for (let i = 0; i < Math.max(a.length, b.length); i++) {
    const x = a[i] || 0, y = b[i] || 0;
    if (x !== y) return x > y;
  }
  return true;
};
ok('نسخهٔ سایت ۴.۲۱۴.۱ یا تازه‌تر است', atLeast(siteVer, '4.214.1'), siteVer);
ok('نسخهٔ دسکتاپ ۲.۱۳۸.۱ یا تازه‌تر است', atLeast(deskVer, '2.138.1'), deskVer);
ok('برچسب اصلاح V4.214.1 یا تازه‌تر است', atLeast(corrTag.replace(/^V/i, ''), '4.214.1'), corrTag);
ok('perf.php نسخهٔ تازه را اعلام می‌کند', siteFile('includes/perf.php').includes("PERF_CORE_VERSION', '" + siteVer + "'"), siteVer);
ok('doctor.php مهر تازه دارد', siteFile('doctor.php').includes(corrTag), corrTag);
const pairs = [
  ['includes/report_image.php', 'includes-report_image.php'],
  ['includes/perf.php', 'includes-perf.php'],
  ['config/version.php', null],
];
for (const [siteRel, deskRel] of pairs) {
  const p = patchFile(siteRel);
  ok('بستهٔ اصلاحی همان ' + siteRel + ' است که در سایت نصب شده', p === siteFile(siteRel));
  if (deskRel) ok('همتای دسکتاپ ' + deskRel + ' با سایت یکی است', deskFile(deskRel) === p);
}

/* ═══════════ ۶) چیزی از کارهای قبلی نشکسته ═══════════ */
console.log('\n══ ۶) بی‌آسیب بودن کارهای قبلی ══');
const bands = await run(String.raw`<?php require_once '/www/includes/functions.php';
echo 'BANDS=' . count(report_grade_status_bands()) . ':' . implode(',', array_column(report_grade_status_bands(), 'dot'));`);
ok('نُه پلهٔ وضعیت با همان رنگ‌ها سرجایشان‌اند',
  /BANDS=9:#b91c1c,#dc2626,#ea580c,#d97706,#ca8a04,#65a30d,#16a34a,#15803d,#047857/.test(bands.out), bands.out);
ok('توزیع اعلام سراسری دست‌نخورده است (سه پیام‌رسان)', siteFile('report-broadcast.php').includes('bot_chat_column($pf)'));
ok('tooltip هنوز زیر پنجره‌هاست', stSrc.includes('.student-hover-tooltip{ z-index: 900 !important; }'));
ok('نوشتن فیلدهای ردیف انضباط دست‌نخورده است (همهٔ ورودی‌ها با form= وصل‌اند)',
  (mHtml.match(/<input form="/g) || []).length >= 3 && mHtml.includes('name="is_justified"'));

console.log(`\n  سوئیت V4.214.1: ${pass} PASS / ${fail} FAIL`);
process.exit(fail ? 1 : 0);
