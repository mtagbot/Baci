/**
 * test-student-tiles.mjs — سوئیت «منوی کاشی و صفحه‌های پنل دانش‌آموز» (V4.210.0)
 *
 * درخواست مدیر مدرسه:
 *   ۱) دکمهٔ «حذف دانش‌آموز از حساب» از پنل دانش‌آموز برداشته شود،
 *   ۲) جای آن منوی کاشی مثل پنل دبیر با پنج گزینه بیاید: برنامه هفتگی،
 *      برنامه امتحانی، موارد انضباطی، کارنامه‌ها، آزمون مجازی،
 *   ۳) هر گزینه صفحهٔ مستقل خودش را داشته باشد تا اطلاعات در صفحهٔ اصلی
 *      (داشبورد) تلنبار نشود،
 *   ۴) جدول برنامهٔ هفتگی بر اساس کلاس دانش‌آموز،
 *   ۵) برنامهٔ امتحانی پیش‌فرض «جدیدترین ماه» را نشان دهد (آبان تمام شد و
 *      آذر تعریف شد ⇒ آذر)،
 *   ۶) مدیر در پایین صفحهٔ «امتحانات حضوری» با یک تیک بتواند ماه نمایشی را
 *      دستی انتخاب کند (حتی قدیمی‌تر) و پنل دانش‌آموز همان ماه را نشان دهد،
 *   ۷) نام دبیر در برنامهٔ امتحانی فقط «آقای + نام خانوادگی» باشد.
 *
 * همهٔ بررسی‌ها با اجرای واقعی PHP روی سورسِ وصله انجام می‌شوند.
 */
import { req, run, db, login, loginAdmin } from './harness/lib.mjs';

/* فایل‌های این نسخه از پوشهٔ وصله خوانده می‌شوند (همان چیزی که به هاست می‌رود) */
import { readFileSync, existsSync } from 'node:fs';
import { REPO } from './harness/site.mjs';
import { php } from './harness/lib.mjs';


const patchDir = process.env.PATCH || 'update-v4.212.0';
const files = [
  'index.php', 'report-view.php', 'student-panel.php', 'student-schedule.php', 'student-exam-schedule.php',
  'student-discipline.php', 'student-reports.php', 'exams.php',
  'includes/student_nav.php', 'includes/exams_helper.php', 'includes/header.php',
  'includes/functions.php',
];
/* فایل‌هایی که در نسخهٔ جاری دست نخورده‌اند از اورلیِ قبلی برداشته می‌شوند
   تا این سوئیت همیشه کل مجموعهٔ پنل دانش‌آموز را روی سایت بگذارد. */
const overlayPath = rel => {
  for (const dir of [patchDir, 'update-v4.212.0', 'update-v4.211.0', 'update-v4.210.0']) {
    const candidate = `${REPO}/${dir}/${rel}`;
    if (existsSync(candidate)) return candidate;
  }
  throw new Error('فایل اورلی پیدا نشد: ' + rel);
};
for (const rel of files) {
  php.writeFile('/www/' + rel, new Uint8Array(readFileSync(overlayPath(rel))));
}
console.log(`>>> اورلیِ نسخهٔ نو: ${files.length} فایل روی ${patchDir}`);

let pass = 0, fail = 0;
const ok = (name, cond, detail = '') => cond
  ? (pass++, console.log(`  ✅ ${name}`))
  : (fail++, console.log(`  ❌ ${name}${detail ? '  → ' + detail : ''}`));

const YEAR = '1404/1405';
const SID_STUDENT = 'stuTiles0001';
const SID_ADMIN = 'stuTiles0002';

/* ── دادهٔ آزمایشی ─────────────────────────────────────────────────────── */
const seed = await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/exams_helper.php';
ensure_exams_schema();
set_setting('current_academic_year','${YEAR}');
set_setting('student_exam_month_manual','0');
set_setting('student_exam_month_selected','');
DB::execute("DELETE FROM students WHERE id IN (8101,8102)");
DB::execute("DELETE FROM class_schedules WHERE class_name IN ('هفتم ۱','هفتم ۲')");
DB::execute("DELETE FROM exam_schedules");
DB::execute("DELETE FROM student_discipline_records WHERE student_id IN (8101,8102)");
DB::execute("DELETE FROM reports WHERE student_id IN (8101,8102)");
DB::execute("DELETE FROM teachers WHERE national_id = '90000008101'");
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year,serial_number) VALUES (8101,'0000008101','سارا','احمدی','هفتم ۱','هفتم','active','${YEAR}','810111')");
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year,serial_number) VALUES (8102,'0000008102','مهدی','کریمی','هفتم ۲','هفتم','active','${YEAR}','810222')");
DB::execute("INSERT INTO teachers (national_id,full_name,password,status) VALUES ('90000008101','رضا حسنی','x',1)");
$tid = (int)DB::lastInsertId();
foreach ([['شنبه','زنگ ۱','ریاضی',1],['شنبه','زنگ ۲','علوم',0],['یک‌شنبه','زنگ ۱','فارسی',0],['دوشنبه','زنگ ۳','عربی',0]] as $i => $row) {
    DB::execute("INSERT INTO class_schedules (academic_year,class_name,day_of_week,period_num,subject_name,teacher_name,teacher_id) VALUES (?,?,?,?,?,?,?)",
        ['${YEAR}','هفتم ۱',$row[0],$row[1],$row[2],$row[3] ? 'رضا حسنی' : '',$row[3] ? $tid : null]);
}
DB::execute("INSERT INTO class_schedules (academic_year,class_name,day_of_week,period_num,subject_name,teacher_name,teacher_id) VALUES (?,?,?,?,?,?,?)",
    ['${YEAR}','هفتم ۲','شنبه','زنگ ۱','ریاضی','رضا حسنی',$tid]);
function exam_fixture($month,$subject,$date,$time,$class='هفتم ۱',$teacherId=0,$teacherName='') {
    DB::execute("INSERT INTO exam_schedules (academic_year,exam_month,grade_level,class_name,subject_name,teacher_id,teacher_name,exam_date_jalali,exam_day_name,start_time,duration_minutes,exam_room_default,header_config,is_active,exam_kind,created_at_jalali) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        ['${YEAR}',$month,'هفتم',$class,$subject,$teacherId ?: null,$teacherName,$date,'',$time,90,'سالن ۱','{}',1,'official','1404/08/01']);
    return (int)DB::lastInsertId();
}
$aban  = exam_fixture('آبان','ریاضی','1404/08/12','08:00','هفتم ۱',$tid,'رضا حسنی');
$aban2 = exam_fixture('آبان','علوم','1404/08/14','10:00','هفتم ۱',$tid,'رضا حسنی');
$azar  = exam_fixture('آذر','ریاضی','1404/09/12','08:00','هفتم ۱',$tid,'رضا حسنی');
$azar2 = exam_fixture('آذر','فارسی','1404/09/15','10:00','هفتم ۱',0,'');
/* رکورد قدیمی بدون exam_month (فرم تعریف امتحان تکی) — باید از تاریخش ماه آذر فهمیده شود */
DB::execute("INSERT INTO exam_schedules (academic_year,exam_month,grade_level,class_name,subject_name,teacher_name,exam_date_jalali,start_time,duration_minutes,is_active,exam_kind,created_at_jalali) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
    ['${YEAR}','','هفتم','هفتم ۱','عربی','دبیر مدرسه','1404/09/20','12:00',90,1,'official','1404/09/01']);
/* آزمون کلاسی/غیرفعال نباید هیچ‌جا دیده شود */
exam_fixture('آذر','آزمون کلاسی','1404/09/21','09:00','هفتم ۱');
DB::execute("UPDATE exam_schedules SET exam_kind='class' WHERE subject_name='آزمون کلاسی'");
/* سالِ دیگر */
exam_fixture('خرداد','تاریخِ سالِ قبل','1403/03/10','08:00','هفتم ۱');
DB::execute("UPDATE exam_schedules SET academic_year='1403/1404' WHERE subject_name='تاریخِ سالِ قبل'");
DB::execute("INSERT INTO student_discipline_records (student_id,title_text,internal_note,occurred_at_jalali,notify_parents,created_at_jalali) VALUES (8101,'تأخیر غیرموجه در ورود به مدرسه','یادداشت داخلیِ محرمانهٔ معاونت','1404/08/20',0,'1404/08/20')");
DB::execute("INSERT INTO reports (student_id,class_name,academic_year,term,report_month,total_score,gpa,is_locked) VALUES (8101,'هفتم ۱','${YEAR}','نوبت اول','آبان',18,18,0)");
echo 'SEEDED';`);
ok('دادهٔ آزمایشی ساخته شد', seed.out.includes('SEEDED'), seed.out + seed.err);

await login(SID_STUDENT, 8101);
await loginAdmin(SID_ADMIN);

const page = async (file, sid = SID_STUDENT, query = '') => (await req('', { file, sid, query })).res;

/* ── ۱) داشبورد: دکمه حذف رفته، کاشی‌ها آمده، جزئیات تلنبار نشده ─────── */
console.log('\n══ ۱) داشبورد دانش‌آموز ══');
const dash = await page('student-panel.php');
ok('داشبورد بدون خطا رندر می‌شود', !dash.fatal, dash.fatal || '');
ok('دکمهٔ «حذف دانش‌آموز از حساب» حذف شده است',
   !dash.page.includes('حذف دانش‌آموز از حساب') && !dash.page.includes('remove_current_student'),
   (dash.page.match(/حذف دانش‌آموز از حساب/g) || []).length + ' occurrence');
const tileLinks = ['student-schedule.php', 'student-exam-schedule.php', 'student-discipline.php', 'student-reports.php', 'student-online-exams.php'];
for (const link of tileLinks) {
  ok(`کاشی «${link}» در داشبورد هست`, dash.page.includes(`href="${link}"`));
}
ok('پنج کاشی در شبکهٔ منو رندر شده است', (dash.page.match(/class="sn-tile[\s"]/g) || []).length === 5,
   (dash.page.match(/class="sn-tile[\s"]/g) || []).length + ' tiles');
ok('جدول کارنامه‌ها از داشبورد برداشته شده است', !dash.page.includes('studentReportsTable'));
ok('جدول انضباطی از داشبورد برداشته شده است', !dash.page.includes('studentDisciplineList'));
ok('جدول برنامهٔ امتحانی از داشبورد برداشته شده است', !dash.page.includes('studentExamSchedule'));
ok('شمارندهٔ کاشی‌ها روی کاشی‌ها رندر شده است',
   dash.page.includes('sn-tile-n">۱ کارنامه') && dash.page.includes('sn-tile-n">۱ مورد'),
   (dash.page.match(/sn-tile-n">[^<]*/g) || []).join(' · '));

/* صفحهٔ نخست پس از ورود باید «برنامه هفتگی» باشد (خواستهٔ مدیر) */
const landing = await req('', { file: 'index.php', sid: SID_STUDENT, query: '' });
ok('دانش‌آموزِ واردشده با باز کردن index.php به «برنامه هفتگی» می‌رود',
   (landing.res.redirect || '').includes('student-schedule.php'),
   landing.res.redirect || '(no redirect)');

/* مسیر «استعلام کارنامه» با ?year= باید مستقیم به صفحهٔ کارنامه‌ها برود */
const inquiry = await req('', { file: 'student-panel.php', sid: SID_STUDENT, query: 'year=' + encodeURIComponent(YEAR) });
ok('ورود از مسیر استعلام کارنامه به صفحهٔ کارنامه‌ها هدایت می‌شود',
   (inquiry.res.redirect || '').includes('student-reports.php'),
   inquiry.res.redirect || '(no redirect)');

/* کارنامهٔ ناموجود/مسدود: دانش‌آموز به فهرست کارنامه‌ها برمی‌گردد، نه داشبورد */
const rv = await req('', { file: 'report-view.php', sid: SID_STUDENT, query: 'id=999999' });
ok('بازگشت از نمایش کارنامه به صفحهٔ «کارنامه‌ها» می‌رود',
   (rv.res.redirect || '').includes('student-reports.php'),
   rv.res.redirect || '(no redirect)');

/* ── ۲) برنامهٔ هفتگی ─────────────────────────────────────────────────── */
console.log('\n══ ۲) صفحهٔ برنامه هفتگی ══');
const sched = await page('student-schedule.php');
ok('صفحهٔ برنامه هفتگی بدون خطا رندر می‌شود', !sched.fatal, sched.fatal || '');
ok('جدول هفتگی کلاس خودِ دانش‌آموز است', sched.page.includes('studentWeeklySchedule') && sched.page.includes('هفتم ۱'));
for (const subject of ['ریاضی', 'علوم', 'فارسی', 'عربی']) {
  ok(`درس «${subject}» از برنامهٔ کلاس دیده می‌شود`, sched.page.includes(subject));
}
ok('روزهای هفته در جدول هست', ['شنبه', 'یک‌شنبه', 'دوشنبه'].every(d => sched.page.includes(d)));
ok('زنگ‌ها به عدد فارسی ستون‌بندی شده‌اند', sched.page.includes('زنگ ۱') && sched.page.includes('زنگ ۳'));
/* V4.212.0 — خواستهٔ مدیر: در برنامهٔ هفتگی نام دبیر نمایش داده نمی‌شود */
ok('نام دبیر در برنامهٔ هفتگی نمایش داده نمی‌شود',
   !sched.page.includes('حسنی') && !sched.page.includes('رضا') && !sched.page.includes('آقای'),
   (sched.page.match(/حسنی|آقای[^<]*/g) || []).join(' · '));
ok('در برنامهٔ هفتگی فقط نام درس در خانه‌ها آمده است',
   (sched.page.match(/class="font-bold text-xs block"/g) || []).length >= 4);
ok('درسِ کلاس دیگر در برنامهٔ این دانش‌آموز نیست', (sched.page.match(/عربی/g) || []).length === 1);

/* ── ۳) برنامهٔ امتحانی: پیش‌فرض جدیدترین ماه (آذر) ───────────────────── */
console.log('\n══ ۳) برنامهٔ امتحانی — پیش‌فرض جدیدترین ماه ══');
const examPage = await page('student-exam-schedule.php');
ok('صفحهٔ برنامه امتحانی بدون خطا رندر می‌شود', !examPage.fatal, examPage.fatal || '');
ok('ماه نمایشی، آذر است (آبان تمام شده و آذر تعریف شده)',
   examPage.page.includes('فارسی') && !examPage.page.includes('علوم'),
   (examPage.page.match(/برنامهٔ ماه[^<]*/g) || []).join(''));
ok('آزمون‌های آذر دیده می‌شوند', examPage.page.includes('ریاضی') && examPage.page.includes('فارسی'));
ok('رکورد بدون exam_month از تاریخش به آذر نسبت داده شد', examPage.page.includes('عربی'));
ok('آزمون کلاسی/سالِ دیگر در برنامه نیست',
   !examPage.page.includes('آزمون کلاسی') && !examPage.page.includes('تاریخِ سالِ قبل'));
/* V4.212.0 — خواستهٔ مدیر: در برنامهٔ امتحانی هم نام دبیر نمی‌آید */
ok('ستون «دبیر» از جدول برنامهٔ امتحانی حذف شده است',
   !examPage.page.includes('<th>دبیر</th>') && !examPage.page.includes('آقای حسنی'),
   (examPage.page.match(/<th>[^<]*<\/th>/g) || []).join(' '));
ok('نام دبیر هیچ‌جای برنامهٔ امتحانی (حتی در متن) نیامده است',
   !examPage.page.includes('حسنی') && !examPage.page.includes('رضا'));
ok('ستون صندلی/کلاس امتحانی موجود است', examPage.page.includes('صندلی') && examPage.page.includes('کلاس امتحانی'));

/* افزودن ماه جدید (دی) ⇒ خودکار به دی می‌رود */
await run(`<?php require_once '/www/includes/functions.php'; require_once '/www/includes/exams_helper.php'; ensure_exams_schema();
DB::execute("INSERT INTO exam_schedules (academic_year,exam_month,grade_level,class_name,subject_name,exam_date_jalali,start_time,duration_minutes,is_active,exam_kind,created_at_jalali) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
 ['${YEAR}','دی','هفتم','هفتم ۱','زیست','1404/10/11','08:00',90,1,'official','1404/10/01']);`);
const examDay = await page('student-exam-schedule.php');
ok('با تعریف ماه دی، پنل خودکار ماه جدید را نشان می‌دهد',
   examDay.page.includes('زیست') && !examDay.page.includes('فارسی') && !examDay.page.includes('علوم'),
   (examDay.page.match(/برنامهٔ ماه[^<]*/g) || []).join(''));

/* ── ۴) تنظیم مدیر: نمایش دستی ماه در پنل دانش‌آموز ──────────────────── */
console.log('\n══ ۴) تنظیمگر مدیر در صفحهٔ امتحانات حضوری ══');
const adminExams = await page('exams.php', SID_ADMIN, 'tab=schedule&year=' + encodeURIComponent(YEAR));
ok('صفحهٔ امتحانات حضوری برای مدیر رندر می‌شود', !adminExams.fatal, adminExams.fatal || '');
ok('تنظیمگر «ماه نمایشی در پنل دانش‌آموز» در پایین صفحه هست', adminExams.page.includes('studentExamMonthCard'));
ok('تیک فعال‌ساز وجود دارد', /name="student_exam_month_enabled"/.test(adminExams.page));
ok('فهرست ماه‌ها با تعداد آزمون ساخته شده است',
   /name="student_exam_month_selected"[\s\S]{0,1200}?آبان — ۲ آزمون/.test(adminExams.page),
   (adminExams.page.match(/آبان — [^<]*/g) || []).join(''));
ok('حالت فعلی روی کارت نوشته شده است', adminExams.page.includes('وضعیت فعلی:'));
const csrf = (adminExams.page.match(/name="csrf_token" value="([^"]+)"/) || [])[1] || '';
ok('توکن CSRF برای ذخیرهٔ تنظیم گرفته شد', csrf !== '');

const saveAban = await req('ثبت تنظیم: ماه آبان', {
  file: 'exams.php', sid: SID_ADMIN, query: 'tab=schedule&year=' + encodeURIComponent(YEAR), method: 'POST',
  post: { csrf_token: csrf, save_student_exam_month: '1', student_exam_month_enabled: '1', student_exam_month_selected: 'آبان' },
});
ok('ذخیرهٔ تنظیم بدون خطا انجام شد', !saveAban.res.fatal && !/خطای CSRF/.test(saveAban.res.raw_head || ''), saveAban.res.fatal || '');
const saved = await db("SELECT key_name,key_value FROM settings WHERE key_name IN ('student_exam_month_manual','student_exam_month_selected') ORDER BY key_name");
const savedMap = Object.fromEntries(saved.map(r => [r.key_name, r.key_value]));
ok('تنظیم در جدول settings ذخیره شد (و با دسکتاپ همگام می‌شود)',
   savedMap.student_exam_month_manual === '1' && savedMap.student_exam_month_selected === 'آبان',
   JSON.stringify(savedMap));

const examManual = await page('student-exam-schedule.php');
ok('پنل دانش‌آموز حالا همان ماهِ انتخابی مدیر (آبان) را نشان می‌دهد',
   examManual.page.includes('علوم') && !examManual.page.includes('زیست') && !examManual.page.includes('فارسی'),
   (examManual.page.match(/برنامهٔ ماه[^<]*/g) || []).join(''));
ok('در حالت دستی هم نام دبیر نمایش داده نمی‌شود',
   !examManual.page.includes('آقای') && !examManual.page.includes('حسنی'));

/* ماهی که برای این دانش‌آموز برنامه دارد ولی خیلی قدیمی است: خرداد سال قبل
   — سالِ دیگر است، پس باید بی‌صدا به خودکار برگردد (صفحه خالی نماند) */
const saveImpossible = await req('ثبت تنظیم ناموجود', {
  file: 'exams.php', sid: SID_ADMIN, query: 'tab=schedule&year=' + encodeURIComponent(YEAR), method: 'POST',
  post: { csrf_token: csrf, save_student_exam_month: '1', student_exam_month_enabled: '1', student_exam_month_selected: 'شهریور' },
});
ok('ماه بدون برنامه هم پذیرفته می‌شود (بدون خطا)', !saveImpossible.res.fatal, saveImpossible.res.fatal || '');
const examFallback = await page('student-exam-schedule.php');
ok('اگر ماه انتخابی برنامه‌ای نداشته باشد، خودکار جدیدترین ماه نمایش داده می‌شود',
   examFallback.page.includes('زیست') && !examFallback.page.includes('علوم'),
   (examFallback.page.match(/برنامهٔ ماه[^<]*/g) || []).join(''));

/* خاموش کردن تیک ⇒ برگشت به خودکار */
const saveOff = await req('خاموش کردن تنظیم', {
  file: 'exams.php', sid: SID_ADMIN, query: 'tab=schedule&year=' + encodeURIComponent(YEAR), method: 'POST',
  post: { csrf_token: csrf, save_student_exam_month: '1' },
});
ok('خاموش کردن تنظیم بدون خطا انجام شد', !saveOff.res.fatal, saveOff.res.fatal || '');
const afterOff = await db("SELECT key_name,key_value FROM settings WHERE key_name IN ('student_exam_month_manual','student_exam_month_selected') ORDER BY key_name");
const offMap = Object.fromEntries(afterOff.map(r => [r.key_name, r.key_value]));
ok('خاموش کردن، تنظیم را پاک می‌کند', offMap.student_exam_month_manual === '0' && offMap.student_exam_month_selected === '',
   JSON.stringify(offMap));
const examAuto = await page('student-exam-schedule.php');
ok('با خاموش شدن تیک، دوباره جدیدترین ماه (دی) نمایش داده می‌شود',
   examAuto.page.includes('زیست') && !examAuto.page.includes('علوم') && !examAuto.page.includes('فارسی'));

/* ── ۵) موارد انضباطی ────────────────────────────────────────────────── */
console.log('\n══ ۵) صفحهٔ موارد انضباطی ══');
const disc = await page('student-discipline.php');
ok('صفحهٔ انضباطی بدون خطا رندر می‌شود', !disc.fatal, disc.fatal || '');
ok('عنوان مورد و تاریخ دیده می‌شود',
   disc.page.includes('تأخیر غیرموجه در ورود به مدرسه') && disc.page.includes('۱۴۰۴/۰۸/۲۰'));
ok('یادداشت داخلی معاونت هرگز نمایش داده نمی‌شود', !disc.page.includes('یادداشت داخلیِ محرمانهٔ معاونت'));

/* ── ۶) کارنامه‌ها ───────────────────────────────────────────────────── */
console.log('\n══ ۶) صفحهٔ کارنامه‌ها ══');
const reps = await page('student-reports.php');
ok('صفحهٔ کارنامه‌ها بدون خطا رندر می‌شود', !reps.fatal, reps.fatal || '');
ok('کارنامه و لینک مشاهده/چاپ موجود است',
   reps.page.includes('studentReportsTable') && reps.page.includes('مشاهده کارنامه') && reps.page.includes('نوبت اول'));
ok('نمودار روند پیشرفت به صفحهٔ کارنامه‌ها منتقل شده است', reps.page.includes('studentProgressChart'));

/* ── ۷) آزمون مجازی: کاشی به صفحهٔ موجود وصل است ────────────────────── */
console.log('\n══ ۷) آزمون مجازی ══');
const online = await page('student-online-exams.php');
ok('صفحهٔ آزمون‌های مجازی همچنان رندر می‌شود', !online.fatal, online.fatal || '');
ok('همان صفحه در داشبورد لینک شده است', dash.page.includes('href="student-online-exams.php"'));

/* ── ۸) منطق موجود (ربات) دست‌نخورده ───────────────────────────────── */
console.log('\n══ ۸) بدون تغییر در منطق فعلی ══');
const legacy = await run(`<?php require_once '/www/includes/functions.php'; require_once '/www/includes/exams_helper.php';
$rows = exam_student_schedule(8101);
$months = array_values(array_unique(array_map('exam_schedule_month_label', $rows)));
echo 'L=' . count($rows) . ';M=' . implode(',', $months) . ';';`);
ok('exam_student_schedule() هنوز همهٔ ماه‌ها را برمی‌گرداند (ربات همین را می‌خواند)',
   /L=6;/.test(legacy.out), legacy.out.trim());
ok('ترتیب/ماه‌ها در تابع قدیمی تغییری نکرده است',
   /M=آبان,آذر,دی/.test(legacy.out), legacy.out.trim());
const dashLegacy = await page('student-panel.php');
ok('خروج از حساب همچنان در نوار بالا هست', dashLegacy.page.includes('index.php?action=logout'));

/* ── ۹) یکسانی نسخهٔ توسعه و نسخهٔ منتشرشده ───────────────────────────── */
console.log('\n══ ۹) بهداشت نسخه‌ها ══');
/* V4.211.0: اورلیِ جاری update-v4.211.0 است؛ فایل‌هایی که در این نسخه دست
   نخورده‌اند از اورلیِ قبلی (4.210.0) خوانده می‌شوند تا بررسی همچنان کامل بماند. */
const shippedDir = rel => {
  for (const dir of ['update-v4.214.1', 'update-v4.214.0', 'update-v4.213.0', 'update-v4.212.0', 'update-v4.211.0', 'update-v4.210.0']) {
    const candidate = `${REPO}/${dir}/${rel}`;
    if (existsSync(candidate)) return candidate;
  }
  return `${REPO}/update-v4.210.0/${rel}`;
};
for (const rel of ['student-panel.php', 'student-schedule.php', 'student-exam-schedule.php',
                   'student-discipline.php', 'student-reports.php', 'exams.php',
                   'includes/student_nav.php', 'includes/exams_helper.php']) {
  const dev = readFileSync(`${REPO}/update-v4.152.0/${rel}`);
  const shipped = readFileSync(shippedDir(rel));
  ok(`نسخهٔ توسعه و منتشرشدهٔ ${rel} یکی است`, dev.equals(shipped));
}
for (const rel of ['config/version.php', 'includes/perf.php']) {
  const a = readFileSync(`${REPO}/update-v4.152.0/${rel}`, 'utf8').includes('4.214.1');
  const b = readFileSync(shippedDir(rel), 'utf8').includes('4.214.1');
  ok(`مهر نسخه در ${rel} تازه است (4.214.1)`, a && b);
}

console.log(`\n  سوئیت منوی کاشی دانش‌آموز: ${pass} PASS / ${fail} FAIL`);
process.exit(fail ? 1 : 0);
