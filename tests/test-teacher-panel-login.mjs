// ورود دبیر به پنل با کد ملی + کد پرسنلی — v4.173.0
//
// گزارش کاربر: دبیر با «کد ملی و کد پرسنلی» خودش به بازوی بله متصل شده و در
// ربات کار می‌کند، ولی همان اطلاعات را در مرورگر وب داخلی بله (دکمهٔ اپلیکیشن)
// یا مرورگرهای دیگر وارد می‌کند و رد می‌شود.
//
// علت ریشه‌ای (کد): ربات در مرحلهٔ «کد پرسنلی» هم personnel_code و هم رمز را
// می‌پذیرفت (includes/bot_webhook_engine.php)، ولی دو درگاه ورود وب
// (index.php و admin-login.php) فقط رمز ستون teachers.password را می‌پذیرفتند —
// در حالی که برچسب فرم از قبل «کد پرسنلی / رمز ورود» بود. یعنی UI قول چیزی
// را می‌داد که کد آن را اجرا نمی‌کرد.
//
// اینجا همان جریان واقعی هر دو درگاه اجرا می‌شود.
import assert from 'node:assert/strict';
import { run, req } from './harness/lib.mjs';

let n = 0;
const check = (v, m) => { assert(v, m); n++; };

/* ── fixture: شش دبیر با شش شکل مختلف رمز/کد پرسنلی ───────────────────── */
await run(`<?php require_once '/www/includes/functions.php';
DB::execute("DELETE FROM teachers WHERE national_id IN ('0050000001','0060000002','0070000003','0080000004','0090000005','0100000006')");
DB::execute("DELETE FROM admins WHERE username = 'zzharnessadmin'");
// ۱) بدون رمز + کد پرسنلی — همان مورد گزارش‌شده (دبیر فقط با کد پرسنلی متصل شده)
DB::execute("INSERT INTO teachers (id,national_id,personnel_code,full_name,mobile,academic_year,password,status) VALUES (8801,'0050000001','5000001','رضا مردانی','09120000001','1404/1405','',1)");
// ۲) رمز هش‌شده + کد پرسنلی — هر دو باید کار کنند
DB::execute("INSERT INTO teachers (id,national_id,personnel_code,full_name,mobile,academic_year,password,status) VALUES (8802,'0060000002','6000002','فاطمه نوری','09120000002','1404/1405',?,1)",[password_hash('Teach-Secret-3',PASSWORD_DEFAULT)]);
// ۳) رمز هش‌شده + کد پرسنلی — برای آزمون رد شدن
DB::execute("INSERT INTO teachers (id,national_id,personnel_code,full_name,mobile,academic_year,password,status) VALUES (8803,'0070000003','7000003','مهدی سلطانی','09120000003','1404/1405',?,1)",[password_hash('Teach-Secret-3',PASSWORD_DEFAULT)]);
// ۴) کد پرسنلی غیرعددی (دارای حرف)
DB::execute("INSERT INTO teachers (id,national_id,personnel_code,full_name,mobile,academic_year,password,status) VALUES (8804,'0080000004','AB-7788','سارا کاظمی','09120000004','1404/1405',?,1)",[password_hash('Teach-Secret-3',PASSWORD_DEFAULT)]);
// ۵) کد پرسنلی خیلی کوتاه
DB::execute("INSERT INTO teachers (id,national_id,personnel_code,full_name,mobile,academic_year,password,status) VALUES (8805,'0090000005','123','حسین احمدی','09120000005','1404/1405',?,1)",[password_hash('Teach-Secret-3',PASSWORD_DEFAULT)]);
// ۶) حساب غیرفعال — حتی با کد پرسنلی درست نباید وارد شود
DB::execute("INSERT INTO teachers (id,national_id,personnel_code,full_name,mobile,academic_year,password,status) VALUES (8806,'0100000006','1000006','نادر رحیمی','09120000006','1404/1405','',0)");
// مدیر برای اطمینان از دست‌نخورده‌ماندن درگاه مدیریت
DB::execute("INSERT INTO admins (id,username,password,name,role,status) VALUES (9901,'zzharnessadmin',?,'مدیر هارنس','admin',1)",[password_hash('Admin-Secret-5',PASSWORD_DEFAULT)]);
echo 'FIXTURE=OK';`);

/* نشستٔ تازه برای هر تلاش — مثل مرورگر واقعی یک دبیر که صفحه را باز کرده. */
let counter = 0;
async function tryTeacherLogin(nid, pass, tag, page = 'index.php') {
  const fresh = 'freshTeachLogin' + (++counter);
  const form = await req('فرم ورود دبیر ' + tag, { file: page, sid: fresh, query: page === 'index.php' ? 'view=login&tab=teacher' : 'tab=teacher' });
  const token = (form.res.page || '').match(/name="csrf_token" value="([^"]+)/)?.[1] || '';
  assert(token !== '', 'the real teacher form exposes a CSRF token');
  return req('ورود دبیر ' + tag, {
    file: page, sid: fresh, method: 'POST',
    post: { login_type: 'teacher', national_id: nid, password: pass, csrf_token: token },
  });
}

/* ۱) همان مورد گزارش‌شده: دبیرِ بدون رمز، فقط با کد ملی + کد پرسنلی */
for (const page of ['index.php', 'admin-login.php']) {
  const r = await tryTeacherLogin('0050000001', '5000001', 'کد پرسنلی (' + page + ')', page);
  check((r.res.flash?.message || '').includes('خوش آمدید'),
    'a teacher linked in the bot with national id + personnel code can log in through ' + page + ': ' + (r.res.flash?.message || ''));
}

/* ۲) رمز مدرسه هنوز کار می‌کند و کد پرسنلی هم پذیرفته می‌شود */
const b = await tryTeacherLogin('0060000002', 'Teach-Secret-3', 'رمز مدرسه');
check((b.res.flash?.message || '').includes('خوش آمدید'), 'the school password still works: ' + (b.res.flash?.message || ''));
const b2 = await tryTeacherLogin('0060000002', '6000002', 'کد پرسنلی + رمز');
check((b2.res.flash?.message || '').includes('خوش آمدید'), 'the personnel code is accepted even when a password is set: ' + (b2.res.flash?.message || ''));

/* ۲ب) کد پرسنلی با ارقام فارسی */
const b3 = await tryTeacherLogin('0060000002', '۶۰۰۰۰۰۲', 'کد پرسنلی فارسی');
check((b3.res.flash?.message || '').includes('خوش آمدید'), 'Persian digits in the personnel code are accepted');

/* ۳) رمز غلط + کد پرسنلی غلط → رد (هیچ سوراخ امنیتی جدیدی) */
const c = await tryTeacherLogin('0070000003', 'Teach-Secret-9', 'رمز غلط');
check((c.res.flash?.message || '').includes('نادرست') || (c.res.flash?.message || '').includes('درست نیست'),
  'a wrong password is still rejected: ' + (c.res.flash?.message || ''));
const c2 = await tryTeacherLogin('0070000003', '7000004', 'کد پرسنلی غلط');
check((c2.res.flash?.message || '').includes('نادرست') || (c2.res.flash?.message || '').includes('درست نیست'),
  'a wrong personnel code is still rejected: ' + (c2.res.flash?.message || ''));

/* ۳ب) حساب غیرفعال حتی با کد پرسنلی درست رد می‌شود */
const c3 = await tryTeacherLogin('0100000006', '1000006', 'حساب غیرفعال');
check((c3.res.flash?.message || '').includes('نادرست') || (c3.res.flash?.message || '').includes('درست نیست'),
  'an inactive teacher is still rejected: ' + (c3.res.flash?.message || ''));

/* ۴) کد دارای حرف: فقط دقیق؛ «تطبیق بخشی» ممکن نیست */
const d = await tryTeacherLogin('0080000004', 'AB-7788', 'کد حرف‌دار دقیق');
check((d.res.flash?.message || '').includes('خوش آمدید'), 'a letter-bearing code matches exactly: ' + (d.res.flash?.message || ''));
const d2 = await tryTeacherLogin('0080000004', '7788', 'بخش رقمی کد حرف‌دار');
check((d2.res.flash?.message || '').includes('نادرست') || (d2.res.flash?.message || '').includes('درست نیست'),
  'the digits of a letter-bearing code are NOT accepted on their own: ' + (d2.res.flash?.message || ''));

/* ۵) کد خیلی کوتاه: دقیق بله، با صفر پر شده خیر */
const e = await tryTeacherLogin('0090000005', '123', 'کد کوتاه دقیق');
check((e.res.flash?.message || '').includes('خوش آمدید'), 'a very short code still matches exactly (bot parity): ' + (e.res.flash?.message || ''));
const e2 = await tryTeacherLogin('0090000005', '0123', 'کد کوتاه با صفر');
check((e2.res.flash?.message || '').includes('نادرست') || (e2.res.flash?.message || '').includes('درست نیست'),
  'a zero-padded short code is not accepted: ' + (e2.res.flash?.message || ''));

/* ۶) تابع مشترک — دقیق/نرمال/حروف/کوتاه/خالی */
const link = await run(`<?php require_once '/www/includes/functions.php';
echo 'fn=' . (function_exists('staff_personnel_matches') ? 'yes' : 'no') . ';';
echo 'exact=' . (staff_personnel_matches('5000001','5000001') ? 'yes' : 'no') . ';';
echo 'persian=' . (staff_personnel_matches('6000002','۶۰۰۰۰۰۲') ? 'yes' : 'no') . ';';
echo 'spaces=' . (staff_personnel_matches('6000002',' 60-00 002 ') ? 'yes' : 'no') . ';';
echo 'zeros=' . (staff_personnel_matches('0060002','60002') ? 'yes' : 'no') . ';';
echo 'wrong=' . (staff_personnel_matches('7000003','7000004') ? 'yes' : 'no') . ';';
echo 'short=' . (staff_personnel_matches('123','0123') ? 'yes' : 'no') . ';';
echo 'shortok=' . (staff_personnel_matches('123','123') ? 'yes' : 'no') . ';';
echo 'letters=' . (staff_personnel_matches('AB-7788','7788') ? 'yes' : 'no') . ';';
echo 'empty=' . (staff_personnel_matches('','5000001') ? 'yes' : 'no') . ';';
echo 'nostored=' . (staff_personnel_matches(null,'5000001') ? 'yes' : 'no') . ';';`);
const l = Object.fromEntries((link.out.match(/([a-z_]+)=([^;]*)/g) || []).map(kv => { const i = kv.indexOf('='); return [kv.slice(0, i), kv.slice(i + 1)]; }));
check(l.fn === 'yes', 'the shared personnel-code matcher is available to every entry point');
check(l.exact === 'yes' && l.persian === 'yes' && l.spaces === 'yes' && l.zeros === 'yes',
  'exact, Persian-digit, spaced and zero-prefixed input match: ' + link.out.slice(0, 120));
check(l.wrong === 'no' && l.short === 'no' && l.letters === 'no' && l.empty === 'no' && l.nostored === 'no',
  'wrong, padded-short, letter-code-partial, empty and missing codes never match');
check(l.shortok === 'yes', 'a short code still matches when typed exactly (no regression against the bot)');

/* ۷) ربات دست‌نخورده مانده — قرارداد کد پرسنلی همان است که بود */
const bot = await run(`<?php $s = file_get_contents('/www/includes/bot_webhook_engine.php');
echo 'hash=' . (strpos($s, "hash_equals((string)\\$teacher['personnel_code'], \\$code)") !== false ? 'yes' : 'no') . ';';
echo 'verify=' . (strpos($s, "verify_user_password(\\$code, (string)\\$teacher['password']") !== false ? 'yes' : 'no') . ';';`);
check(bot.out.includes('hash=yes') && bot.out.includes('verify=yes'),
  'the bot still accepts personnel_code OR password — untouched: ' + bot.out.slice(0, 80));

/* ۸) هر دو درگاه وب از همان تابع مشترک استفاده می‌کنند (یک قرارداد) */
const gates = await run(`<?php
foreach (['/www/index.php','/www/admin-login.php'] as $f) {
  $s = file_get_contents($f);
  echo basename($f) . '=' . (strpos($s, 'staff_personnel_matches(') !== false ? 'yes' : 'no') . ';';
  echo basename($f) . '-label=' . (strpos($s, 'کد پرسنلی / رمز ورود') !== false || strpos($s, 'کد پرسنلی / کلمه عبور') !== false ? 'yes' : 'no') . ';';
}`);
check(gates.out.includes('index.php=yes') && gates.out.includes('admin-login.php=yes'),
  'both web gates call the shared matcher: ' + gates.out.slice(0, 120));
check(gates.out.includes('index.php-label=yes') && gates.out.includes('admin-login.php-label=yes'),
  'the form labels promise the personnel code — and now the code honours it');

/* ۹) درگاه مدیریت تغییری نکرده: نام کاربری + رمز (ربات هم همان است) */
const adminSid = 'freshAdminLogin';
const adminForm = await req('فرم ورود مدیر', { file: 'admin-login.php', sid: adminSid, query: 'tab=admin' });
const atoken = (adminForm.res.page || '').match(/name="csrf_token" value="([^"]+)/)?.[1] || '';
const a1 = await req('ورود مدیر درست', {
  file: 'admin-login.php', sid: adminSid, method: 'POST',
  post: { login_type: 'admin', username: 'zzharnessadmin', password: 'Admin-Secret-5', csrf_token: atoken },
});
check((a1.res.flash?.message || '').includes('خوش آمدید'), 'the admin gate still works with username + password: ' + (a1.res.flash?.message || ''));
const adminSid2 = 'freshAdminLogin2';
const adminForm2 = await req('فرم ورود مدیر ۲', { file: 'admin-login.php', sid: adminSid2, query: 'tab=admin' });
const atoken2 = (adminForm2.res.page || '').match(/name="csrf_token" value="([^"]+)/)?.[1] || '';
const a2 = await req('ورود مدیر با کد پرسنلی', {
  file: 'admin-login.php', sid: adminSid2, method: 'POST',
  post: { login_type: 'admin', username: '0050000001', password: '5000001', csrf_token: atoken2 },
});
check(!(a2.res.flash?.message || '').includes('خوش آمدید'),
  'the admin gate is NOT widened by the personnel code (username + password only): ' + (a2.res.flash?.message || ''));

console.log(`PASS ${n} teacher panel-login cases (personnel code on both gates, password priority, Persian digits, rejection, inactive, letter/short codes, bot parity, admin gate intact)`);
process.exit(0);
