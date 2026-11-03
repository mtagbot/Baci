/**
 * test-v4.214.2-features.mjs — سه خواستهٔ بازبینیِ V4.214.1
 *
 *   ۱) عرض دکمهٔ «بازیابی دانش‌آموز» دست‌کم ۱۲۰ پیکسل
 *   ۲) پنجرهٔ «مدیریت انضباط» تا ۹۰٪ عرض صفحه (و بیشتر) باز می‌شود
 *   ۳) روی دسکتاپ، فیلد تاریخ زیر فیلد «عنوان مورد انضباطی» است،
 *      هم‌عرض آن، و هر دو پهن‌تر از قبل
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

const siteFile = rel => { try { return php.readFileAsText('/www/' + rel); } catch (e) { return ''; } };
const patchFile = rel => { const p = join(REPO, PATCH, rel); return existsSync(p) ? readFileSync(p, 'utf8') : ''; };
const deskFile = rel => { const p = join(REPO, 'desktop-app-v2', 'patch', rel); return existsSync(p) ? readFileSync(p, 'utf8') : ''; };

/* ═══════════ ۰) دادهٔ آزمایشی ═══════════ */
const curYear = (await run(String.raw`<?php require_once '/www/includes/functions.php';
echo 'Y=' . get_current_academic_year();`)).out.match(/Y=([\d\/\-]+)/)[1];

await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
set_setting('current_academic_year', ${JSON.stringify(curYear)});
DB::execute("DELETE FROM students WHERE national_id LIKE '2142%'");
DB::execute("INSERT INTO students (first_name,last_name,father_name,national_id,class_name,grade_level,academic_year,status,password) VALUES ('نگار','شریفی','پدر','21420000001','۸/۳','هشتم',?,'active','x')", [${JSON.stringify(curYear)}]);
$sid = (int)DB::lastInsertId();
DB::execute("INSERT INTO student_discipline_records (student_id,title_text,occurred_at_jalali,is_justified,notify_parents,review_status,created_at_jalali) VALUES (?,'بی‌نظمی در کلاس ریاضی','1404/08/14',0,1,'reviewed','1404/08/14')", [$sid]);
echo 'SID=' . $sid;`);

const ids = (await run(String.raw`<?php require_once '/www/includes/functions.php';
$s = DB::fetch("SELECT id FROM students WHERE national_id='21420000001'");
echo 'IDS=' . json_encode(['sid'=>(int)$s['id']]);`)).out.match(/IDS=(\{.*\})/)[1];
const SID = JSON.parse(ids).sid;
ok('دانش‌آموز و یک مورد انضباطی ساخته شدند', SID > 0);

await run(`<?php @mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess'); session_name('BACI_TEST'); session_id('f2142adm'); session_start(); $_SESSION['csrf_token']='f2142-token'; echo 'OK';`);
await loginAdmin('f2142adm');

/* ═══════════ ۱) عرض دکمهٔ «بازیابی دانش‌آموز» ═══════════ */
console.log('\n══ ۱) دکمهٔ «بازیابی دانش‌آموز»: دست‌کم ۱۲۰ پیکسل ══');
const studentsPage = await req('دانش‌آموزان', { file: 'students.php', sid: 'f2142adm' });
const stHtml = studentsPage.res.page || '';
ok('صفحهٔ مدیریت دانش‌آموزان رندر می‌شود', !studentsPage.res.fatal, studentsPage.res.fatal || '');
const recBtn = (stHtml.match(/<a[^>]*href="student-recovery\.php"[^>]*>[^<]*<\/a>/) || [''])[0];
const minW = Number((recBtn.match(/min-width\s*:\s*(\d+)px/) || [])[1] || 0);
ok('دکمهٔ بازیابی min-width دارد', minW > 0, recBtn);
ok('min-width آن دست‌کم ۱۲۰ پیکسل است', minW >= 120, String(minW));
ok('متن دکمه همچنان یک‌خطی است', /white-space\s*:\s*nowrap/.test(recBtn));
ok('کلاس دکمه‌های همسایه حفظ شده (btn + btn-warning + text-xs)', /class="btn btn-warning text-xs"/.test(recBtn), recBtn);
ok('قاعدهٔ نوار عملیات هم کمینهٔ ۱۲۰ پیکسل می‌دهد',
  /\.student-list-actions\s+\.btn\s*\{[^}]*min-width:\s*120px/.test(stHtml),
  (stHtml.match(/\.student-list-actions[^{]*\{[^}]*\}/) || [''])[0]);
const stSrc = siteFile('students.php');
ok('نسخهٔ نصب‌شدهٔ سایت هم همین را دارد (نه فقط بستهٔ اصلاحی)',
  stSrc.includes('min-width:120px') && /\.student-list-actions \.btn\{[^}]*min-width: 120px/.test(stSrc));
const deskStudents = deskFile('www-students.php');
ok('بستهٔ دسکتاپ هم min-width:120px دارد',
  /style="white-space:nowrap;min-width:120px"/.test(deskStudents) && /\.student-list-actions \.btn\{[^}]*min-width: 120px/.test(deskStudents));

/* ═══════════ ۲) عرض پنجرهٔ انضباط ═══════════ */
console.log('\n══ ۲) پنجرهٔ «مدیریت انضباط»: ۹۰٪ صفحه یا بیشتر ══');
const modal = await req('مودال انضباط', { file: 'student-modal.php', sid: 'f2142adm', query: 'type=discipline&student_id=' + SID });
let mJson = {}; try { mJson = JSON.parse(modal.res.page); } catch (e) {}
const mHtml = mJson.html || '';
ok('مودال JSON سالم برمی‌گرداند', mJson.ok === true, (modal.res.page || '').slice(0, 160));
const cardRule = (mHtml.match(/#studentAjaxModal\.modal-disc \.modal-card\{[^}]*\}/) || [''])[0];
const vw = Number((cardRule.match(/(\d+)vw/) || [])[1] || 0);
ok('عرض پنجره درصدی از دید است', vw > 0, cardRule);
ok('عرض پنجره دست‌کم ۹۰ درصد صفحه است', vw >= 90, String(vw));
ok('سقف پیکسلیِ قبلی (min(2000px,…)) برداشته شد؛ روی نمایشگر بزرگ هم ۹۰٪+ می‌ماند',
  !/width:min\(\d+px,/.test(cardRule) || Number((cardRule.match(/min\((\d+)px/) || [])[1]) >= 3200, cardRule);
ok('حداکثر عرض هم از عرض دید بیرون نمی‌زند', /max-width:calc\(100vw - \dpx\)/.test(cardRule), cardRule);
const gridRule = (mHtml.match(/\.disc-grid\{[^}]*\}/) || [''])[0];
ok('سهم پنل فهرست از عرض بیشتر شد (`.6fr` در برابر `2.4fr`)',
  /minmax\(280px,\.6fr\) minmax\(0,2\.4fr\)/.test(gridRule), gridRule);

/* ═══════════ ۳) چیدمان عنوان و تاریخ ═══════════ */
console.log('\n══ ۳) تاریخ زیر عنوان، هم‌عرض آن، هر دو پهن‌تر ══');
const cols = [...mHtml.matchAll(/<col style="width:(\d+)%">/g)].map(m => Number(m[1]));
ok('شش ستون شد (ستون جداگانهٔ تاریخ حذف شد)', cols.length === 6, cols.join('/'));
ok('مجموع ستون‌ها ۱۰۰٪ است', cols.reduce((a, b) => a + b, 0) === 100, String(cols.reduce((a, b) => a + b, 0)));
ok('ستون عنوان پهن‌تر از قبل (۱۸٪) شد و دست‌کم ۲۸٪ است', cols[0] >= 28, String(cols[0]));
ok('سرستون «تاریخ» جداگانه حذف شد و در عنوان ادغام شد',
  !/<th>تاریخ<\/th>/.test(mHtml) && mHtml.includes('<th>عنوان مورد انضباطی و تاریخ</th>'));
const titleCell = (mHtml.match(/<td class="disc-col-title">[\s\S]*?<\/td>/) || [''])[0];
const iTitle = titleCell.indexOf('name="title_text"');
const iDate = titleCell.indexOf('name="occurred_at_jalali"');
ok('هر دو فیلد در یک سلول‌اند', iTitle > 0 && iDate > iTitle, `${iTitle}/${iDate}`);
ok('فیلد تاریخ پس از فیلد عنوان (زیر آن) رندر می‌شود', iDate > iTitle);
ok('هر دو با هم‌عرض‌سازی CSS به ۱۰۰٪ سلول می‌روند',
  /\.disc-title-input,\.disc-date-field \.disc-date\{width:100%;box-sizing:border-box\}/.test(mHtml),
  (mHtml.match(/\.disc-title-input[^}]*\}/) || [''])[0]);
ok('برچسب کوچک «تاریخ وقوع» بالای فیلد تاریخ هست', titleCell.includes('disc-date-cap') && titleCell.includes('تاریخ وقوع'));
ok('فیلد تاریخ همان کلاس جهت‌دهی قبلی را دارد (LTR)', /class="form-input text-xs disc-date"/.test(titleCell));
/* ردیف «هنوز موردی ثبت نشده» فقط وقتی رکوردی نیست رندر می‌شود، پس در سورس
   سنجیده می‌شود (و همان شرط، ستون‌های تازه را پوشش می‌دهد). */
const modalSrc = siteFile('student-modal.php');
const discSrc = (modalSrc.match(/<table class="disc-table">[\s\S]*?<\/table>/) || [''])[0];
ok('ردیف خالی هم شش ستون را پوشش می‌دهد',
  discSrc.includes('colspan="6"') && !discSrc.includes('colspan="7"'), discSrc.length + 'b');
ok('هیچ فیلدی برای ذخیرهٔ ردیف گم نشده (عنوان، تاریخ، موجه، بررسی، یادداشت)',
  ['title_text', 'occurred_at_jalali', 'is_justified', 'review_status', 'review_note', 'internal_note']
    .every(n => mHtml.includes('name="' + n + '"')));

/* همان تغییر در بستهٔ دسکتاپ */
const deskModal = deskFile('www-student-modal.php');
ok('بستهٔ دسکتاپ هم همین ساختار را دارد',
  deskModal.includes('<th>عنوان مورد انضباطی و تاریخ</th>') && !/<th>تاریخ<\/th>/.test(deskModal)
  && /<col style="width:30%"><col style="width:7%">/.test(deskModal) && deskModal.includes('colspan="6"'));
ok('بستهٔ دسکتاپ هم تاریخ را زیر عنوان و هم‌عرض می‌گذارد',
  /name="title_text"[\s\S]{0,400}name="occurred_at_jalali"/.test(deskModal)
  && deskModal.includes('.disc-title-input,.disc-date-field .disc-date{width:100%;box-sizing:border-box}'));
ok('قابلیت دبیر (smIsDeputy) در بستهٔ دسکتاپ دست‌نخورده مانده', deskModal.includes('smIsDeputy'));
ok('پنجرهٔ دسکتاپ هم ۹۸vw و بدون سقف پیکسلی است',
  /\.modal-disc \.modal-card\{width:98vw;max-width:calc\(100vw - 6px\)\}/.test(deskModal));

/* ═══════════ ۴) مهر نسخه و بسته‌ها ═══════════ */
console.log('\n══ ۴) مهر نسخه و هم‌خوانی بسته با سایت ══');
const verSrc = siteFile('config/version.php');
ok('نسخهٔ سایت 4.214.2 است', /'site_version'\s*=>\s*'4\.214\.2'/.test(verSrc), (verSrc.match(/site_version[^,]*/) || [''])[0]);
ok('نسخهٔ دسکتاپ 2.138.2 است', /'desktop_version'\s*=>\s*'2\.138\.2'/.test(verSrc));
ok('برچسب اصلاح V4.214.2 است', /'correction'\s*=>\s*'V4\.214\.2'/.test(verSrc));
ok('perf.php نسخهٔ تازه را اعلام می‌کند', siteFile('includes/perf.php').includes("PERF_CORE_VERSION', '4.214.2'"));
ok('doctor.php مهر تازه دارد', siteFile('doctor.php').includes('V4.214.2'));
/* نسخهٔ سایت و دسکتاپِ students.php و student-modal.php دو فایل مستقل‌اند
   (پوستهٔ دسکتاپ و بلوک‌های مخصوص خودش را دارند)؛ برابری بایتی فقط برای
   فایل‌های مشترکِ includes معنا دارد و همان‌جا سنجیده می‌شود. */
for (const [siteRel, deskRel] of [
  ['includes/perf.php', 'includes-perf.php'],
  ['includes/report_image.php', 'includes-report_image.php'],
  ['includes/functions.php', 'includes-functions.php'],
]) {
  ok('بستهٔ اصلاحی همان ' + siteRel + ' است که نصب شده', patchFile(siteRel) === siteFile(siteRel));
  ok('همتای دسکتاپ ' + deskRel + ' با آن یکی است', deskFile(deskRel) === patchFile(siteRel));
}
ok('بستهٔ اصلاحی config/version.php را هم دارد', patchFile('config/version.php') === siteFile('config/version.php'));
/* هدر دسکتاپ بلوک‌های اختصاصی خودش را دارد (نگهبان window.open و فونت جایگزین)،
   پس برابری بایتی ندارد؛ فقط همان اصلاح مشترک سنجیده می‌شود. */
const deskHeader = deskFile('includes-header.php');
ok('هدر سایت و دسکتاپ هر دو فاصلهٔ ۶px و تاریخ خودکار دارند',
  deskHeader.includes('style="gap:6px"') && deskHeader.includes('flex:0 0 auto;width:auto;white-space:nowrap'));
ok('بلوک‌های اختصاصی هدر دسکتاپ سالم‌اند',
  deskHeader.includes('window.open = function (u)') && deskHeader.includes('Vazirmatn, Sahel, Yekan, Tahoma'));
ok('بستهٔ اصلاحی students.php هم دکمهٔ ۱۲۰ پیکسلی را دارد', patchFile('students.php').includes('min-width:120px'));
ok('بستهٔ اصلاحی student-modal.php هم چیدمان تازه را دارد',
  patchFile('student-modal.php').includes('<th>عنوان مورد انضباطی و تاریخ</th>')
  && patchFile('student-modal.php').includes('width:98vw;max-width:calc(100vw - 6px)'));

/* ═══════════ ۵) بی‌آسیب بودن اصلاح‌های قبلی ═══════════ */
console.log('\n══ ۵) اصلاح‌های V4.214.0 و V4.214.1 سالم مانده‌اند ══');
ok('نقطهٔ «•» هنوز از برچسب وضعیت کارنامه برداشته شده',
  !/border-radius:50%/.test(siteFile('includes/functions.php')));
ok('راهنمای طیف رنگ هنوز در تصویر کارنامه نیست',
  !siteFile('includes/report_image.php').includes('راهنمای وضعیت نمره'));
ok('هدر هنوز فاصلهٔ ۶ پیکسلی و تاریخ خودکار دارد',
  siteFile('includes/header.php').includes('style="gap:6px"')
  && siteFile('includes/header.php').includes('flex:0 0 auto;width:auto;white-space:nowrap'));
ok('شش ستون جدول هیچ‌کدام شکستن کلمه ندارند',
  /\.disc-table td\{[^}]*word-break:normal/.test(mHtml) && /\.disc-col-actions \.btn\{[^}]*white-space:nowrap/.test(mHtml));
ok('tooltip ردیف هنوز زیر پنجره‌هاست', stSrc.includes('.student-hover-tooltip{ z-index: 900 !important; }'));
ok('نُه پلهٔ وضعیت دست‌نخورده‌اند',
  /BANDS=9:#b91c1c,#dc2626,#ea580c,#d97706,#ca8a04,#65a30d,#16a34a,#15803d,#047857/.test(
    (await run(String.raw`<?php require_once '/www/includes/functions.php';
echo 'BANDS=' . count(report_grade_status_bands()) . ':' . implode(',', array_column(report_grade_status_bands(), 'dot'));`)).out));

console.log(`\n  سوئیت V4.214.2: ${pass} PASS / ${fail} FAIL`);
process.exit(fail ? 1 : 0);
