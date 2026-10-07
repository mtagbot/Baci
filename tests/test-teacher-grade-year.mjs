/**
 * test-teacher-grade-year.mjs — سوئیت ۵۶ (v4.179.0)
 *
 * گزارش کاربر: در پنل دبیر «ثبت و ویرایش نمرات»، پس از انتخاب کلاس، فهرست
 * نمره‌دهی دانش‌آموزانِ *همهٔ سال‌های تحصیلی* را که در آن کلاس‌اند بالا می‌آورد،
 * در حالی که فقط باید دانش‌آموزانِ سالِ انتخاب‌شده (منوی بالای صفحه / ?year=)
 * لیست شوند و نمره برای همان سال ثبت/نمایش یابد.
 *
 * علت: کوئری فهرست `academic_year` نداشت و کوئری نمرهٔ فعلی هم فقط بر اساس
 * نام ماه بود (که در همهٔ سال‌ها تکرار می‌شود).
 */
import assert from 'node:assert/strict';
import { run, req } from './harness/lib.mjs';

let n = 0;
const check = (v, m) => { assert(v, m); n++; };

/* ── fixture ─ */
await run(`<?php require_once '/www/includes/functions.php';
DB::execute("DELETE FROM teachers WHERE id=8810");
DB::execute("DELETE FROM students WHERE id IN (9401,9402,9403,9404)");
DB::execute("DELETE FROM class_schedules WHERE teacher_id=8810");
DB::execute("DELETE FROM reports WHERE student_id IN (9401,9402,9403,9404)");
DB::execute("INSERT INTO teachers (id,national_id,personnel_code,full_name,mobile,academic_year,password,status) VALUES (8810,'0099000099','9900099','دبیر هارنس','09120099000','1404/1405','',1)");
DB::execute("INSERT INTO class_schedules (teacher_id,class_name,subject_name,academic_year,day_of_week,period_num) VALUES (8810,'۷۰','ریاضی','1404/1405','شنبه','1')");
// دو دانش‌آموزِ سالِ جاری (باید لیست شوند)
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,academic_year,status) VALUES (9401,'1101','آ','حاضرامسال','۷۰','هفتم','1404/1405','active')");
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,academic_year,status) VALUES (9402,'1102','ب','حاضردوم','۷۰','هفتم','1404/1405','active')");
// دانش‌آموزِ سالِ قبل در همان کلاس (نباید لیست شود)
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,academic_year,status) VALUES (9403,'1103','ج','مالقبل','۷۰','هفتم','1403/1404','active')");
// دانش‌آموزِ غیرفعالِ سال جاری (نباید لیست شود)
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,academic_year,status) VALUES (9404,'1104','د','غیرفعال','۷۰','هفتم','1404/1405','inactive')");
// برای ۹۴۰ (دانش‌آموزِ سالِ قبل) نمرهٔ ۱۵ در همان سال بگذار تا وقتی سالِ
// قبل انتخاب شد پیش‌پر شود، و برای ۹۴۰ نمره‌ای در سال جاری نگذار تا در نمای
// سال جاری value=15 ظاهر نشود.
DB::execute("INSERT INTO reports (id,student_id,class_name,academic_year,term,report_month,total_score,gpa) VALUES (7701,9403,'۷۰','1403/1404','نوبت اول','آبان',0,0)");
DB::execute("INSERT INTO report_grades (report_id,subject_name,score) VALUES (7701,'ریاضی',15)");
echo 'FIXTURE=OK';`);

/* ── ورود دبیر ── */
const form = await req('فرم ورود دبیر', { file: 'index.php', sid: 'gradeYear01', query: 'view=login&tab=teacher' });
const token = (form.res.page || '').match(/name="csrf_token" value="([^"]+)/)?.[1] || '';
assert(token, 'teacher login form has csrf');
const _lr = await req('ورود دبیر', { file: 'index.php', sid: 'gradeYear01', method: 'POST',
  post: { login_type: 'teacher', national_id: '0099000099', password: '9900099', csrf_token: token } });

/* ── فهرست نمره‌دهی برای سال جاری ── */
const g = await req('لیست نمره‌دهی ریاضی ۷۰۱', { file: 'teacher-panel.php', sid: 'gradeYear01',
  query: 'action=grade&class=' + encodeURIComponent('۷۰') + '&subject=' + encodeURIComponent('ریاضی') + '&year=' + encodeURIComponent('1404/1405') + '&month=' + encodeURIComponent('آبان') });
const page = g.res.page;
check(page.includes('لیست نمره‌دهی'), 'grade list page rendered');
check(page.includes('حاضرامسال'), 'current-year student ۹۴۰۱ is listed');
check(page.includes('حاضردوم'), 'current-year student ۹۴۰۲ is listed');
check(!page.includes('مالقبل'), 'student from OTHER academic year (۹۴۰۳) is NOT listed');
check(!page.includes('غیرفعال'), 'inactive student (۹۴۰۴) is NOT listed');
const rows = (page.match(/name="student_id\[\]"/g) || []).length;
check(rows === 2, `exactly 2 grade rows (got ${rows})`);
check(!page.includes('value="15"'), 'a grade saved under a DIFFERENT academic year is not prefilled into the current-year form');

/* ── سالِ دیگر: دانش‌آموزانِ آن سال باید لیست شوند ── */
const g2 = await req('لیست نمره‌دهی برای سال قبل', { file: 'teacher-panel.php', sid: 'gradeYear01',
  query: 'action=grade&class=' + encodeURIComponent('۷۰') + '&subject=' + encodeURIComponent('ریاضی') + '&year=' + encodeURIComponent('1403/1404') + '&month=' + encodeURIComponent('آبان') });
check(g2.res.page.includes('مالقبل'), 'when year=۱۴۰۳/۱۴۰۴ is selected, that year\'s student IS listed');
check(!g2.res.page.includes('حاضرامسال'), 'and current-year students are NOT listed for the other year');
check(g2.res.page.includes('value="15"'), 'and the ۱۴۰۳/۱۴۴ grade (15) IS prefilled when that year is selected');

console.log(`\n>>> PASS ${n} teacher-grade-year checks`);
process.exit(0);
