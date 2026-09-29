// ورود دانش‌آموز به پنل از طریق دکمهٔ «اپلیکیشن» بله — v4.172.0
//
// گزارش کاربر: تعدادی دانش‌آموز با «کد ملی + شمارهٔ شناسنامه» در ربات متصل
// شده‌اند، ولی در صفحهٔ ورود پنل (همان آدرسی که دکمهٔ اپلیکیشن باز می‌کند)
// پیام «اطلاعات نادرست» می‌گیرند.
// علت ریشه‌ای (کد): تا v4.171.0 اگر ستون students.password پر بود، ورود پنل
// فقط رمز را می‌پذیرفت و سریال/کدملی را نادیده می‌گرفت — برخلاف ربات (اتصال
// حساب) و «استعلام» که هر دو رمز *یا* سریال را می‌پذیرفتند.
//
// اینجا همان جریان واقعی index.php (POST ورود دانش‌آموز) اجرا می‌شود.
import assert from 'node:assert/strict';
import { run, req } from './harness/lib.mjs';

// نشستٔ تازه (بدون ورود مدیر) — دقیقاً مثل مرورگر واقعی یک ولی که دکمهٔ
// «اپلیکیشن» را زده و صفحهٔ ورود پنل باز شده است.
const sid = 'freshStudentLogin';
let n = 0;
const check = (v, m) => { assert(v, m); n++; };

/* ── fixture: سه دانش‌آموز با سه شکل مختلف سریال/رمز ───────────────────── */
await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/student_profile_fields.php';
DB::execute("DELETE FROM students WHERE national_id IN ('0011111111','0022222222','0033333333')");
// ۱) رمز خالی + سریال ساده — رفتار قبلی باید حفظ شود
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year,serial_number,password) VALUES (7701,'0011111111','علی','رضایی','هفتم ۱','هفتم','active','1404/1405','123456','')");
// ۲) رمز هش‌شده + سریال مرکب «ب/26/265486» — همان موردی که کاربر گزارش کرده
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year,serial_number,password) VALUES (7702,'0022222222','مریم','کریمی','هشتم ۲','هشتم','active','1404/1405','ب/26/265486',?)",[password_hash('School-Secret-9',PASSWORD_DEFAULT)]);
// ۳) رمز هش‌شده + سریال ساده
DB::execute("INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year,serial_number,password) VALUES (7703,'0033333333','زهرا','احمدی','نهم ۱','نهم','active','1404/1405','654321',?)",[password_hash('Other-Secret-7',PASSWORD_DEFAULT)]);
echo 'FIXTURE=OK';`);

/* هر تلاش با نشستٔ تازهٔ خودش — ورود موفق شناسهٔ نشست را بازتولید می‌کند
   (قفل تک‌دستگاهی) و مثل مرورگر واقعی، هر ولی نشستٔ مستقل دارد. */
let counter = 0;
async function tryLogin(nid, pass, tag) {
  const fresh = 'freshStuLogin' + (++counter);
  const page = await req('فرم ورود ' + tag, { file: 'index.php', sid: fresh, query: 'view=login&tab=student' });
  const token = (page.res.page || '').match(/name="csrf_token" value="([^"]+)/)?.[1] || '';
  assert(token !== '', 'the real login form exposes a CSRF token');
  return req('ورود ' + tag, {
    file: 'index.php', sid: fresh, method: 'POST',
    post: { login_type: 'student', national_id: nid, password: pass, csrf_token: token },
  });
}

/* ۱) سریال ساده + رمز خالی (رفتار قدیمی — نباید تغییر کند) */
const a = await tryLogin('0011111111', '123456', 'سریال ساده');
check((a.res.flash?.message || '').includes('خوش آمدید'), 'a student with no password still logs in with the serial: ' + (a.res.flash?.message || ''));

/* ۲) سریال مرکب + رمز هش‌شده → دقیقاً همان مورد گزارش‌شدهٔ کاربر */
const b = await tryLogin('0022222222', '265486', 'شمارهٔ شناسنامه');
check((b.res.flash?.message || '').includes('خوش آمدید'), 'a linked student whose password is set can now log in with the 6-digit birth-certificate number: ' + (b.res.flash?.message || ''));

/* ۲ب) همان سریال با ارقام فارسی و فاصله */
const b2 = await tryLogin('0022222222', ' ۲۶۵۴۸۶ ', 'سریال فارسی');
check((b2.res.flash?.message || '').includes('خوش آمدید'), 'Persian digits and stray spaces are accepted too');

/* ۳) رمز درست همچنان کار می‌کند */
const c = await tryLogin('0022222222', 'School-Secret-9', 'رمز مدرسه');
check((c.res.flash?.message || '').includes('خوش آمدید'), 'the school password still works');

/* ۴) رمز غلط نباید با سریال دور زده شود */
const d = await tryLogin('0022222222', '999999', 'سریال غلط');
check((d.res.flash?.message || '').includes('کد ملی یا رمز ورود درست نیست'), 'a wrong serial is still rejected (no security hole): ' + (d.res.flash?.message || ''));

/* ۴ب) کد ملی به‌عنوان رمز هم مثل قبل کار می‌کند */
const e = await tryLogin('0033333333', '0033333333', 'کد ملی');
check((e.res.flash?.message || '').includes('خوش آمدید'), 'the national id is still accepted as before');

/* ۵) استعلام (کارنامهٔ سریع) با همان سریال مرکب هم باید کار کند */
const inquirySid = 'freshInquiryLogin';
const inquiryPage = await req('فرم استعلام', { file: 'index.php', sid: inquirySid, query: 'view=login&tab=inquiry' });
const itoken = (inquiryPage.res.page || '').match(/name="csrf_token" value="([^"]+)/)?.[1] || '';
const f = await req('استعلام', {
  file: 'index.php', sid: inquirySid, method: 'POST',
  post: { login_type: 'inquiry', national_id: '0022222222', serial_number: '265486', academic_year: '1404/1405', csrf_token: itoken },
});
check((f.res.flash?.message || '').includes('استعلام موفق'), 'the quick inquiry accepts the same serial: ' + (f.res.flash?.message || ''));

/* ۶) تابع مشترک سریال — مرکب/فارسی/کوتاه/غلط */
const link = await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/student_profile_fields.php';
echo 'fn=' . (function_exists('student_serial_matches') ? 'yes' : 'no') . ';';
echo 'compound=' . (student_serial_matches('ب/26/265486','265486') ? 'yes' : 'no') . ';';
echo 'exact=' . (student_serial_matches('123456','123456') ? 'yes' : 'no') . ';';
echo 'persian=' . (student_serial_matches('123456','۱۲۳۴۵۶') ? 'yes' : 'no') . ';';
echo 'short=' . (student_serial_matches('123456','12') ? 'yes' : 'no') . ';';
echo 'wrong=' . (student_serial_matches('123456','654321') ? 'yes' : 'no') . ';';
echo 'empty=' . (student_serial_matches('','123456') ? 'yes' : 'no') . ';';`);
const l = Object.fromEntries((link.out.match(/([a-z_]+)=([^;]*)/g) || []).map(kv => { const i = kv.indexOf('='); return [kv.slice(0, i), kv.slice(i + 1)]; }));
check(l.fn === 'yes', 'the shared serial matcher is available to every entry point');
check(l.compound === 'yes', 'a compound serial matches its 6-digit part');
check(l.exact === 'yes' && l.persian === 'yes', 'exact and Persian-digit input match');
check(l.short === 'no' && l.wrong === 'no' && l.empty === 'no', 'short, wrong and empty input never match');

/* ۷) جدول اتصال ربات دست‌نخورده می‌ماند */
const users = await run(`<?php require_once '/www/includes/functions.php';
echo 'links=' . DB::fetch("SELECT COUNT(*) c FROM bale_bot_users")['c'] . ';';`);
check(users.out.includes('links=0'), 'the linking table is untouched by the login fix: ' + users.out.slice(0, 80));

console.log(`PASS ${n} student panel-login cases (serial with password, compound serial, Persian digits, rejection, inquiry, bot parity)`);
process.exit(0);
