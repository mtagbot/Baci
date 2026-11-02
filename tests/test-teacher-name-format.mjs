/**
 * test-teacher-name-format.mjs — «یک الگوی مرجع برای نام دبیر» (V4.211.0)
 *
 * درخواست مدیر مدرسه (V4.212.0 به‌روزرسانی شد):
 *   در بخش دبیران، نام بعضی دبیران اول نام و بعد نام خانوادگی ثبت شده و بعضی
 *   برعکس. همین ناهمگونی در برنامهٔ هفتگی پنل دانش‌آموز خودش را نشان داده:
 *   دبیر «علیرضا جهان تیغ» (درس ریاضی) به شکل «تیغ علیرضا» دیده می‌شد.
 *   → یک الگو انتخاب شود و همه‌جا فقط همان نمایش داده شود.
 *   سپس (V4.212.0): در برنامهٔ هفتگی و برنامهٔ امتحانیِ پنل دانش‌آموز
 *   اصلاً نام دبیر نمایش داده نشود و فقط نام درس ذکر شود.
 *
 * الگوی مرجع این نسخه:
 *   • نام کامل (صفحه‌های مدیریتی): «نام + نام خانوادگی»  → علیرضا جهان تیغ
 *   • پنل دانش‌آموز/دبیر/ربات: «آقای + نام خانوادگی»      → آقای جهان تیغ
 *
 * همهٔ بررسی‌ها با اجرای واقعی PHP روی سورسِ وصله انجام می‌شوند.
 */
import { req, run, login, loginAdmin, php } from './harness/lib.mjs';
import { readFileSync, existsSync } from 'node:fs';
import { REPO } from './harness/site.mjs';

const patchDir = process.env.PATCH || 'update-v4.212.0';
const files = [
  'includes/functions.php',
  'includes/exams_helper.php',
  'student-schedule.php',
  'student-exam-schedule.php',
  'import-teachers.php',
  'exams.php',
];
/* فایل‌هایی که در اورلیِ جاری نیستند از اورلیِ نسخهٔ قبل برداشته می‌شوند */
const overlayPath = rel => {
  for (const dir of [patchDir, 'update-v4.212.0', 'update-v4.211.0']) {
    const candidate = `${REPO}/${dir}/${rel}`;
    if (existsSync(candidate)) return candidate;
  }
  throw new Error('فایل اورلی پیدا نشد: ' + rel);
};
for (const rel of files) {
  php.writeFile('/www/' + rel, new Uint8Array(readFileSync(overlayPath(rel))));
}
console.log(`>>> اورلیِ نسخهٔ نو: ${files.length} فایل از ${patchDir}`);

let pass = 0, fail = 0;
const ok = (name, cond, detail = '') => cond
  ? (pass++, console.log(`  ✅ ${name}`))
  : (fail++, console.log(`  ❌ ${name}${detail ? '  → ' + detail : ''}`));

const YEAR = '1404/1405';
const SID_STUDENT = 'teaNames0001';
const SID_ADMIN = 'teaNames0002';

/* ── دادهٔ آزمایشی ─────────────────────────────────────────────────────── */
const seed = await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/school_roles.php';
ensure_school_roles_schema();
require_once '/www/includes/exams_helper.php';
ensure_exams_schema();
set_setting('current_academic_year','${YEAR}');
set_setting('student_exam_month_manual','0');
set_setting('student_exam_month_selected','');
DB::execute("DELETE FROM students WHERE id = 8201");
DB::execute("DELETE FROM class_schedules WHERE class_name = 'هفتم ۱'");
DB::execute("DELETE FROM exam_schedules");
DB::execute("DELETE FROM teachers WHERE national_id IN ('90000008201','90000008202','90000008203')");
/* ۱) دبیر مشکل‌دار: در دیتابیس معکوس ذخیره شده و فیلد last_name خالی است */
DB::execute("INSERT INTO teachers (national_id,full_name,password,status) VALUES ('90000008201','جهان تیغ علیرضا','x',1)");
$t1 = (int)DB::lastInsertId();
/* ۲) دبیری که درست (نام‌اول) ذخیره شده است */
DB::execute("INSERT INTO teachers (national_id,full_name,password,status) VALUES ('90000008202','مریم احمدی','x',1)");
$t2 = (int)DB::lastInsertId();
/* ۳) دبیری با فیلد مجزا و نام خانوادگی دوکلمه‌ای */
DB::execute("INSERT INTO teachers (national_id,full_name,first_name,last_name,password,status) VALUES ('90000008203','کوروش بهرامی','کوروش','بهرامی','x',1)");
$t3 = (int)DB::lastInsertId();
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year,serial_number) VALUES (8201,'0000008201','سارا','احمدی','هفتم ۱','هفتم','active','${YEAR}','820111')");
/* برنامهٔ هفتگی: یک ردیف با شناسهٔ دبیر، یک ردیف فقط با نام ذخیره‌شده (بدون شناسه) */
DB::execute("INSERT INTO class_schedules (academic_year,class_name,day_of_week,period_num,subject_name,teacher_name,teacher_id) VALUES (?,?,?,?,?,?,?)",
    ['${YEAR}','هفتم ۱','شنبه','زنگ 1','ریاضی','جهان تیغ علیرضا',$t1]);
DB::execute("INSERT INTO class_schedules (academic_year,class_name,day_of_week,period_num,subject_name,teacher_name,teacher_id) VALUES (?,?,?,?,?,?,?)",
    ['${YEAR}','هفتم ۱','شنبه','زنگ 2','علوم','جهان تیغ علیرضا',null]);
DB::execute("INSERT INTO class_schedules (academic_year,class_name,day_of_week,period_num,subject_name,teacher_name,teacher_id) VALUES (?,?,?,?,?,?,?)",
    ['${YEAR}','هفتم ۱','یک‌شنبه','زنگ 1','فارسی','مریم احمدی',$t2]);
DB::execute("INSERT INTO class_schedules (academic_year,class_name,day_of_week,period_num,subject_name,teacher_name,teacher_id) VALUES (?,?,?,?,?,?,?)",
    ['${YEAR}','هفتم ۱','دوشنبه','زنگ 3','عربی','کوروش بهرامی',$t3]);
/* برنامهٔ امتحانی ماه آذر با همان دبیر */
DB::execute("INSERT INTO exam_schedules (academic_year,exam_month,grade_level,class_name,subject_name,teacher_id,teacher_name,exam_date_jalali,exam_day_name,start_time,duration_minutes,exam_room_default,header_config,is_active,exam_kind,created_at_jalali) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
    ['${YEAR}','آذر','هفتم','هفتم ۱','ریاضی',$t1,'جهان تیغ علیرضا','1404/09/12','','08:00',90,'سالن ۱','{}',1,'official','1404/08/01']);
echo 'SEEDED|' . $t1 . '|' . $t2 . '|' . $t3;`);
ok('دادهٔ آزمایشی ساخته شد', seed.out.includes('SEEDED'), seed.out + seed.err);
const [, T1, T2, T3] = seed.out.trim().split('|');

await login(SID_STUDENT, 8201);
await loginAdmin(SID_ADMIN);

const page = async (file, sid = SID_STUDENT, query = '') => (await req('', { file, sid, query })).res;

/* ── ۱) توابع مرجع ───────────────────────────────────────────────────── */
console.log('\n══ ۱) توابع مرجعِ نام دبیر ══');
const fn = await run(`<?php require_once '/www/includes/functions.php';
$cases = ['علیرضا جهان تیغ','جهان تیغ علیرضا','مریم احمدی','احمدی مریم','خانم زهرا رضایی','کوروش بهرامی',''];
foreach ($cases as $c) {
    $p = teacher_name_parts($c);
    echo $c . ' => first=[' . $p['first'] . '] family=[' . $p['family'] . '] full=[' .
         teacher_canonical_full_name($c) . '] respectful=[' . teacher_respectful_name($c) . "]\\n";
}
$arr = ['full_name' => 'جهان تیغ علیرضا', 'first_name' => 'جهان تیغ', 'last_name' => 'علیرضا'];
$ap = teacher_name_parts($arr);
echo 'ARRAY => first=[' . $ap['first'] . '] family=[' . $ap['family'] . '] respectful=[' . teacher_respectful_name($arr) . "]\\n";
$arr2 = ['full_name' => 'کوروش بهرامی', 'first_name' => 'کوروش', 'last_name' => 'بهرامی'];
echo 'ARRAY2 => family=[' . teacher_name_parts($arr2)['family'] . "]\\n";
echo 'ORDERJS=' . json_encode(['a' => teacher_name_parts('جهان تیغ علیرضا'), 'b' => teacher_name_parts('علیرضا جهان تیغ')], JSON_UNESCAPED_UNICODE);`);
const out = fn.out || '';

ok('نام عادی: علیرضا جهان تیغ → نام خانوادگی «جهان تیغ»',
   /علیرضا جهان تیغ => first=\[علیرضا\] family=\[جهان تیغ\]/.test(out), out.split('\n')[0] || '');
ok('نام معکوس: جهان تیغ علیرضا → همان تفکیک درست',
   /جهان تیغ علیرضا => first=\[علیرضا\] family=\[جهان تیغ\]/.test(out));
ok('نام کامل مرجع همیشه «نام + نام خانوادگی» است',
   (out.match(/full=\[علیرضا جهان تیغ\]/g) || []).length === 2, (out.match(/full=\[[^\]]*\]/g) || []).join(' · '));
ok('نام محترمانه در هر دو حالت «آقای جهان تیغ» است',
   (out.match(/respectful=\[آقای جهان تیغ\]/g) || []).length >= 2,
   (out.match(/respectful=\[[^\]]*\]/g) || []).join(' · '));
ok('نام‌های دوکلمه‌ای معکوس درست خوانده می‌شوند (احمدی مریم → آقای احمدی)',
   /احمدی مریم => first=\[مریم\] family=\[احمدی\]/.test(out) && /احمدی مریم => .*respectful=\[آقای احمدی\]/.test(out));
ok('پیشوند «خانم» حفظ می‌شود و «آقای خانم» ساخته نمی‌شود',
   /خانم زهرا رضایی => .*respectful=\[خانم رضایی\]/.test(out));
ok('نام خالی هیچ خطا/متنی تولید نمی‌کند', /=> first=\[\] family=\[\] full=\[\] respectful=\[\]/.test(out));
ok('رکورد با فیلدهای جابه‌جا (first=جهان تیغ / last=علیرضا) اصلاح می‌شود',
   /ARRAY => first=\[علیرضا\] family=\[جهان تیغ\] respectful=\[آقای جهان تیغ\]/.test(out));
ok('فیلد مجزای نام خانوادگی مرجع است (بهرامی)',
   /ARRAY2 => family=\[بهرامی\]/.test(out));

/* ── ۲) برنامهٔ هفتگی دانش‌آموز: هیچ نام دبیری نمایش داده نمی‌شود ─────── */
console.log('\n══ ۲) برنامهٔ هفتگی (فقط نام درس) ══');
const sched = await page('student-schedule.php');
ok('صفحهٔ برنامه هفتگی بدون خطا رندر می‌شود', !sched.fatal, sched.fatal || '');
ok('نام دبیر («آقای …») در برنامهٔ هفتگی دیده نمی‌شود',
   !sched.page.includes('آقای') && !sched.page.includes('جهان تیغ'),
   (sched.page.match(/آقای[^<]*|جهان تیغ/g) || []).join(' · '));
ok('نام کوچک دبیر هم در برنامهٔ هفتگی نیست', !/>\s*علیرضا\s*</.test(sched.page) && !sched.page.includes('علیرضا'));
ok('رشتهٔ معیوب «تیغ علیرضا» هم دیده نمی‌شود', !sched.page.includes('تیغ'));
/* «احمدی» نام خانوادگیِ خودِ دانش‌آموز آزمایشی هم هست؛ پس فقط نشانه‌های
   مخصوص دبیران بررسی می‌شود. */
ok('نام هیچ دبیری (مریم/کوروش/بهرامی) در صفحه نیامده است',
   !sched.page.includes('مریم') && !sched.page.includes('کوروش') && !sched.page.includes('بهرامی'));
for (const subject of ['ریاضی', 'علوم', 'فارسی', 'عربی']) {
  ok(`نام درس «${subject}» همچنان نمایش داده می‌شود`, sched.page.includes(subject));
}

/* ── ۳) برنامهٔ امتحانی دانش‌آموز: ستون دبیر حذف شده ─────────────────── */
console.log('\n══ ۳) برنامهٔ امتحانی (بدون ستون دبیر) ══');
const exam = await page('student-exam-schedule.php');
ok('صفحهٔ برنامه امتحانی بدون خطا رندر می‌شود', !exam.fatal, exam.fatal || '');
ok('ستون «دبیر» در جدول نیست', !exam.page.includes('<th>دبیر</th>'));
ok('هیچ نام دبیری در برنامهٔ امتحانی نیامده است',
   !exam.page.includes('آقای') && !exam.page.includes('جهان تیغ') && !exam.page.includes('علیرضا'));
ok('نام درس و تاریخ/ساعت آزمون همچنان نمایش داده می‌شود',
   exam.page.includes('ریاضی') && exam.page.includes('تاریخ') && exam.page.includes('ساعت'));

/* ── ۴) بخش دبیران (صفحهٔ مدیریت) ────────────────────────────────────── */
console.log('\n══ ۴) بخش دبیران ══');
const list = await page('import-teachers.php', SID_ADMIN, 'tab=list');
ok('فهرست دبیران بدون خطا رندر می‌شود', !list.fatal, list.fatal || '');
ok('نام دبیر معکوس در فهرست به شکل مرجع «علیرضا جهان تیغ» دیده می‌شود',
   list.page.includes('علیرضا جهان تیغ'));
ok('نام معکوسِ خام در فهرست دبیران چاپ نمی‌شود', !list.page.includes('جهان تیغ علیرضا'));
ok('نام دبیران درست هم دست‌نخورده می‌مانند', list.page.includes('مریم احمدی'));
ok('دبیر با فیلد مجزا در فهرست همان «کوروش بهرامی» است', list.page.includes('کوروش بهرامی'));

const edit = await page('import-teachers.php', SID_ADMIN, 'tab=edit&id=' + T1);
ok('فرم ویرایش دبیر باز می‌شود', !edit.fatal && edit.page.includes('last_name'), edit.fatal || '');
const firstVal = /name="first_name"[^>]*value="([^"]*)"/.exec(edit.page)?.[1] ?? '';
const lastVal  = /name="last_name"[^>]*value="([^"]*)"/.exec(edit.page)?.[1] ?? '';
ok('فرم ویرایش، نام و نام خانوادگی را جابه‌جا نشان نمی‌دهد',
   firstVal === 'علیرضا' && lastVal === 'جهان تیغ', `first=[${firstVal}] last=[${lastVal}]`);

/* ── ۵) فقط نمایش؛ هیچ داده‌ای بازنویسی نمی‌شود ───────────────────────── */
console.log('\n══ ۵) بدون تغییر در داده ══');
const after = await run(`<?php require_once '/www/includes/functions.php';
$rows = DB::fetchAll("SELECT national_id, full_name, COALESCE(last_name,'') AS last_name FROM teachers WHERE national_id IN ('90000008201','90000008202','90000008203') ORDER BY national_id");
foreach ($rows as $r) echo $r['national_id'] . '=' . $r['full_name'] . '|' . $r['last_name'] . "\\n";`);
ok('مقدار ذخیره‌شدهٔ دبیران دست‌نخورده است (نمایش، داده را بازنویسی نمی‌کند)',
   after.out.includes('90000008201=جهان تیغ علیرضا|') && after.out.includes('90000008203=کوروش بهرامی|بهرامی'),
   after.out.trim());

/* ── ۶) یک الگو برای همه: هیچ جای دیگری معکوس نیست ───────────────────── */
console.log('\n══ ۶) یکسانی الگو ══');
const examAdmin = await page('exams.php', SID_ADMIN, 'tab=schedule&year=' + encodeURIComponent(YEAR));
ok('صفحهٔ امتحانات مدیریت بدون خطا رندر می‌شود', !examAdmin.fatal, examAdmin.fatal || '');
ok('در صفحهٔ مدیریت هم نام دبیر «علیرضا جهان تیغ» است',
   examAdmin.page.includes('علیرضا جهان تیغ'));
ok('در صفحهٔ مدیریت هم «جهان تیغ علیرضا» دیده نمی‌شود',
   !examAdmin.page.includes('جهان تیغ علیرضا'));

console.log(`\n  سوئیت نام دبیر (الگو + حذف از پنل دانش‌آموز): ${pass} PASS / ${fail} FAIL`);
process.exit(fail === 0 ? 0 : 1);
