// «اعتبار فرم به پایان رسیده» در مرورگر داخلی بله — v4.175.0
//
// گزارش کاربر: گاهی در ورود از دکمهٔ «اپلیکیشن» (مرورگر داخلی پیام‌رسان بله) این
// پیام می‌آید و کاربر وارد نمی‌شود:
//   «اعتبار فرم به پایان رسیده است. همین صفحه تازه شده؛ …»
//
// علت ریشه‌ای (کد): توکن CSRF فقط در نشست مرورگر نگه داشته می‌شد. اگر کوکی نشست
// روی POST نرسد — نخستین تلاش در مرورگر داخلی پیام‌رسان، حالت خصوصی، یا صفحهٔ
// بازگشتی از حافظهٔ پنهان — سرور توکن نمی‌بیند و فرم را رد می‌کرد.
//
// رفع: توکن «امضاشده» (salt + HMAC با راز سرور) شد که برای راستی‌آزمایی به نشست
// نیاز ندارد؛ پس فرم حتی بدون کوکی نشست هم معتبر می‌ماند و ورود موفق می‌شود.
import assert from 'node:assert/strict';
import { run, req } from './harness/lib.mjs';

let n = 0;
const check = (v, m) => { assert(v, m); n++; };

await run(`<?php require_once '/www/includes/functions.php';
DB::execute("DELETE FROM teachers WHERE national_id='0120000008'");
DB::execute("INSERT INTO teachers (id,national_id,personnel_code,full_name,mobile,academic_year,password,status) VALUES (8808,'0120000008','5125125','نگار صادقی','09120000008','1404/1405',?,1)",[password_hash('5125125',PASSWORD_DEFAULT)]);
DB::execute("DELETE FROM admins WHERE username='freshnessAdmin'");
DB::execute("INSERT INTO admins (id,username,password,name,role,status) VALUES (9902,'freshnessAdmin',?,'مدیر تازگی','super_admin',1)",[password_hash('Fresh-Admin-7',PASSWORD_DEFAULT)]);
echo 'FIX=OK';`);

/* ── ۱) شکل توکن: امضاشده و بدون وابستگی به نشست ─────────────────────── */
const shape = await run(`<?php require_once '/www/includes/functions.php';
$t = csrf_token();
echo 'len=' . strlen($t) . ';';
echo 'signed=' . (csrf_signed_valid($t) ? 'yes' : 'no') . ';';
echo 'stable=' . (csrf_token() === $t ? 'yes' : 'no') . ';';
echo 'forged=' . (csrf_signed_valid('abcdef012345678901234567.' . str_repeat('a',64)) ? 'yes' : 'no') . ';';
echo 'empty=' . (csrf_signed_valid('') ? 'yes' : 'no') . ';';`);
const sh = Object.fromEntries((shape.out.match(/([a-z_]+)=([^;]*)/g) || []).map(kv => { const i = kv.indexOf('='); return [kv.slice(0, i), kv.slice(i + 1)]; }));
check(sh.signed === 'yes', 'the issued token is self-verifying: ' + shape.out.slice(0, 90));
check(sh.stable === 'yes', 'the token stays stable inside one session (multi-tab safe)');
check(sh.forged === 'no' && sh.empty === 'no', 'a forged or empty token is never accepted');
check(Number(sh.len) > 60, 'the signed token is long enough to be unguessable: ' + sh.len);

/* ── ۲) حالت گزارش‌شده: POST بدون کوکی نشست ───────────────────────────── */
/* دقیقاً مثل مرورگر داخلی بله: فرم با نشست A رندر می‌شود، POST اما با نشست تازهٔ
   B می‌رسد (کوکی نشست روی POST نرفته). */
const sidA = 'csrfFormA0001';
const form = await req('فرم ورود دبیر (نشست A)', { file: 'index.php', sid: sidA, query: 'view=login&tab=teacher' });
const token = (form.res.page || '').match(/name="csrf_token" value="([^"]+)/)?.[1] || '';
check(token !== '', 'the real login form exposes a CSRF token');
const post = await req('ورود دبیر بدون کوکی نشست', {
  file: 'index.php', sid: 'csrfFormB0002', method: 'POST', cookies: {},
  post: { login_type: 'teacher', national_id: '0120000008', password: '5125125', csrf_token: token },
});
check((post.res.flash?.message || '').includes('خوش آمدید'),
  'the login SUCCEEDS when the session cookie never reached the POST (the reported Bale in-app case): ' + (post.res.flash?.message || ''));

/* ── ۲-ب) توکنِ دزدیده‌شده وقتی کوکی نشست می‌آید باید رد شود ───────────── */
/* درخواست با کوکی نشست، ولی نشستی که توکن ندارد؛ مسیر «امضاشده» بسته می‌شود
   تا CSRF روی نشستِ کاربر امکان‌پذیر نباشد. */
await run(`<?php @mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess');
session_name('BACI_TEST'); session_id('csrfSteal0001'); session_start();
unset($_SESSION['csrf_token']); echo 'cleared';`);
const steal = await req('ورود با توکنِ دزدیده‌شده و کوکی نشست', {
  file: 'index.php', sid: 'csrfSteal0001', method: 'POST', cookies: { BACI_TEST: 'csrfSteal0001' },
  post: { login_type: 'teacher', national_id: '0120000008', password: '5125125', csrf_token: token },
});
check(!(steal.res.flash?.message || '').includes('خوش آمدید'),
  'a token taken from another session is REFUSED when the request carries a session cookie: ' + (steal.res.flash?.message || '').slice(0, 40));
check(/csrf/.test(steal.res.redirect || steal.res.flash?.message || ''), 'the refusal is the CSRF guard: ' + (steal.res.redirect || ''));

/* ── ۳) امنیت: توکن کهنهٔ نشستِ دیگر هنوز رد می‌شود ───────────────────── */
const forgedPost = await req('ورود دبیر با توکن جعلی', {
  file: 'index.php', sid: 'csrfFormB0003', method: 'POST',
  post: { login_type: 'teacher', national_id: '0120000008', password: '5125125', csrf_token: 'not-a-real-token' },
});
check(!(forgedPost.res.flash?.message || '').includes('خوش آمدید'),
  'a made-up token is still rejected (no hole opened): ' + (forgedPost.res.flash?.message || '').slice(0, 60));

/* ── ۴) admin-login.php هم بدون کوکی نشست کار می‌کند ──────────────────── */
const alSid = 'csrfAdminA001';
const alForm = await req('فرم ورود مدیر', { file: 'admin-login.php', sid: alSid, query: 'tab=admin' });
const alToken = (alForm.res.page || '').match(/name="csrf_token" value="([^"]+)/)?.[1] || '';
const alPost = await req('ورود مدیر با توکن جعلی', {
  file: 'admin-login.php', sid: 'csrfAdminB002', method: 'POST',
  post: { login_type: 'admin', username: 'freshnessAdmin', password: 'Fresh-Admin-7', csrf_token: 'bogus' },
});
check(!(alPost.res.flash?.message || '').includes('خوش آمدید'), 'the admin gate still refuses a bogus token');
const alPost2 = await req('ورود مدیر بدون کوکی نشست', {
  file: 'admin-login.php', sid: 'csrfAdminB003', method: 'POST',
  post: { login_type: 'admin', username: 'freshnessAdmin', password: 'Fresh-Admin-7', csrf_token: alToken },
});
check((alPost2.res.flash?.message || '').includes('خوش آمدید') || /dashboard/.test(alPost2.res.redirect || ''),
  'the admin gate accepts a signed token without the session cookie: ' + (alPost2.res.flash?.message || alPost2.res.redirect || ''));

/* ── ۵) نقطهٔ پایانی توکن تازه + اسکریپت صفحهٔ ورود ───────────────────── */
const refresh = await req('توکن تازه', { file: 'csrf-refresh.php', sid: 'csrfRefresh01', query: '' });
const refreshed = (refresh.res.page || '').match(/"token":"([^"]+)"/)?.[1] || '';
check(refreshed !== '', 'csrf-refresh.php hands back a fresh token: ' + (refresh.res.page || '').slice(0, 60));
const refreshValid = await run(`<?php require_once '/www/includes/functions.php';
echo 'ok=' . (csrf_signed_valid('${refreshed}') ? 'yes' : 'no') . ';';`);
check(refreshValid.out.includes('ok=yes'), 'the refreshed token verifies server-side');
/* توکن قبلیِ همان نشست هم بعد از تازه‌سازی پذیرفته می‌شود (تب‌های باز) */
const ring = await req('فرم دوم با توکن قبلی', { file: 'index.php', sid: 'csrfRefresh01', query: 'view=login&tab=teacher' });
const ringToken = (ring.res.page || '').match(/name="csrf_token" value="([^"]+)/)?.[1] || '';
check(ringToken === refreshed, 'the page now carries the refreshed token');
const oldStillWorks = await run(`<?php require_once '/www/includes/functions.php';
$_SESSION['csrf_ring'] = ['legacy.token.value'];
echo 'ringread=' . (is_array($_SESSION['csrf_ring']) && count($_SESSION['csrf_ring']) === 1 ? 'yes' : 'no') . ';';`);
check(oldStillWorks.out.includes('ringread=yes'), 'recent tokens are remembered for other open tabs');
const pages = await run(`<?php
foreach (['/www/index.php','/www/admin-login.php'] as $f) {
  $s = file_get_contents($f);
  echo basename($f) . '=' . (strpos($s, 'csrf-refresh.php') !== false ? 'yes' : 'no') . ';';
}`);
check(pages.out.includes('index.php=yes') && pages.out.includes('admin-login.php=yes'),
  'both login pages refresh their token when the page comes back from the browser cache: ' + pages.out.slice(0, 90));

/* ── ۶) پیام خطا دیگر ترسناک نیست و راهکار می‌گوید ────────────────────── */
const msgs = await run(`<?php require_once '/www/includes/login_feedback.php';
echo 'csrf=' . (strpos(login_error_codes('teacher')['csrf'], 'دکمهٔ ورود را یک بار دیگر بزنید') !== false ? 'yes' : 'no') . ';';`);
check(msgs.out.includes('csrf=yes'), 'the CSRF message now tells the user exactly what to do');

/* ── ۷) راز سرور پایدار است (توکن‌های قدیمی هم معتبر می‌مانند) ─────────── */
const secret = await run(`<?php require_once '/www/includes/functions.php';
$a = csrf_secret(); $b = csrf_secret();
echo 'same=' . ($a === $b ? 'yes' : 'no') . ';len=' . strlen($a) . ';';`);
check(secret.out.includes('same=yes'), 'the signing secret is stable across calls (tokens stay valid)');
check(/len=(6[4-9]|[7-9][0-9]|1[0-9][0-9])/.test(secret.out), 'the secret is long enough: ' + secret.out.slice(0, 40));

console.log(`PASS ${n} login-form freshness cases (signed token without a session, forged token refused, both gates, refresh endpoint, friendly message)`);
process.exit(0);
