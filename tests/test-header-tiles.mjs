/**
 * test-header-tiles.mjs — سوئیت ۱۲ (v4.132.0)
 *
 * دو قابلیت را می‌سنجد، با اجرای کد واقعی نه خواندن آن:
 *   الف) منوی کاشی‌ای هدر  — کاتالوگ، سقف ۸، مجوزها، رندر، ذخیره
 *   ب) کپچای تطبیقی        — بار اول بدون کد، بعد از اشتباه با کد
 *
 * قاعدهٔ پروژه: «تستی که نتواند شکست بخورد، نگهبان نیست.» هر گارد
 * اینجا با تزریق حالت خراب راستی‌آزمایی شده است.
 */
import { run, php } from './harness/lib.mjs';
import { resolveFile } from './harness/site.mjs';
import { readFileSync } from 'node:fs';

let pass = 0, fail = 0;
const ok = (n, c, d = '') => c ? (pass++, console.log(`  ✅ ${n}`))
                               : (fail++, console.log(`  ❌ ${n}${d ? '  → ' + d : ''}`));

const j = async (code) => {
  const out = (await run(code)).out.trim();
  try { return JSON.parse(out); }
  catch (e) { return { __raw: out }; }
};

/* ═══════════════ الف) منوی کاشی‌ای ═══════════════ */
console.log('\n══ منوی کاشی‌ای هدر ══');

php.writeFile('/harness/tiles1.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/auth.php';
require_once '/www/includes/header_tiles.php';
$cat = header_tiles_catalog();
$bad = [];
foreach ($cat as $k => $t) {
  if (!isset($t['t'],$t['i'],$t['u']) || !array_key_exists('p',$t)) $bad[] = $k;
  /* هیچ کاشی‌ای نباید URL مطلق یا جاوااسکریپت داشته باشد */
  if (preg_match('~^(https?:)?//|^javascript:~i', $t['u'])) $bad[] = 'url:'.$k;
}
echo json_encode([
  'count'   => count($cat),
  'bad'     => $bad,
  'max'     => header_tiles_max(),
  'defcount'=> count(header_tiles_default()),
  /* هر پیش‌فرض باید در کاتالوگ باشد */
  'defok'   => count(array_diff(header_tiles_default(), array_keys($cat))) === 0,
]);`);
const c = await j("<?php require '/harness/tiles1.php';");
ok('کاتالوگ کاشی‌ها ساختار درست دارد', Array.isArray(c.bad) && c.bad.length === 0, JSON.stringify(c.bad));
ok('حداکثر کاشی برابر ۸ است', c.max === 8, String(c.max));
ok('پیش‌فرض‌ها دقیقاً ۸ کاشی‌اند', c.defcount === 8, String(c.defcount));
ok('همهٔ پیش‌فرض‌ها در کاتالوگ وجود دارند', c.defok === true);

/* سقف ۸ روی ورودی فرم — حتی اگر ۲۰ تا بفرستند */
php.writeFile('/harness/tiles2.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/auth.php';
require_once '/www/includes/header_tiles.php';
$all = array_keys(header_tiles_catalog());
$r = [];
$r['over']    = header_tiles_sanitize_post($all);                 // ۲۰ تا → باید ۸ شود
$r['dupes']   = header_tiles_sanitize_post(['students','students','students']);
$r['bogus']   = header_tiles_sanitize_post(['students','../../etc/passwd','<script>','settings']);
$r['empty']   = header_tiles_sanitize_post([]);
$r['notarr']  = header_tiles_sanitize_post('students');
echo json_encode($r);`);
const t2 = await j("<?php require '/harness/tiles2.php';");
ok('سقف ۸ روی ورودی فرم اعمال می‌شود',
   typeof t2.over === 'string' && t2.over.split(',').length === 8, String(t2.over));
ok('کاشی تکراری حذف می‌شود', t2.dupes === 'students', String(t2.dupes));
ok('کلید ناشناخته/خطرناک دور ریخته می‌شود', t2.bogus === 'students,settings', String(t2.bogus));
ok('ورودی خالی به رشتهٔ خالی تبدیل می‌شود', t2.empty === '');
ok('ورودی غیرآرایه امن مدیریت می‌شود', t2.notarr === '');

/* رندر واقعی: مدیر می‌بیند، دانش‌آموز نمی‌بیند */
php.writeFile('/harness/tiles3.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
@mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess');
session_id('tileprobe'.mt_rand()); session_start();
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/auth.php';
require_once '/www/includes/header_tiles.php';
set_setting('header_tiles','dashboard,students,settings');
set_setting('header_tiles_enabled','1');

function cap($fn){ ob_start(); $fn(); return ob_get_clean(); }

/* --- مدیر --- */
$_SESSION = ['admin_id'=>1,'admin_username'=>'a','admin_role'=>'super_admin','admin_permissions'=>'all'];
$asAdmin = cap('render_header_tiles');

/* --- دانش‌آموز --- */
$_SESSION = ['student_id'=>5,'student_name'=>'x'];
$asStudent = cap('render_header_tiles');

/* --- مدیر، ولی منو خاموش --- */
$_SESSION = ['admin_id'=>1,'admin_role'=>'super_admin','admin_permissions'=>'all'];
set_setting('header_tiles_enabled','0');
$disabled = cap('render_header_tiles');
set_setting('header_tiles_enabled','1');

/* --- مقدار خراب در دیتابیس --- */
set_setting('header_tiles','zzz,../x,students');
$corrupt = cap('render_header_tiles');
set_setting('header_tiles','dashboard,students,settings');

echo json_encode([
  'admin_has'    => substr_count($asAdmin,'hdr-tile"')+substr_count($asAdmin,'hdr-tile '),
  'admin_nav'    => strpos($asAdmin,'hdr-tiles')!==false ? 1:0,
  'admin_count'  => substr_count($asAdmin,'<a class="hdr-tile'),
  'student_empty'=> trim($asStudent)==='' ? 1:0,
  'disabled_empty'=> trim($disabled)==='' ? 1:0,
  'corrupt_count'=> substr_count($corrupt,'<a class="hdr-tile'),
  'corrupt_no_zzz'=> strpos($corrupt,'zzz')===false ? 1:0,
]);`);
const t3 = await j("<?php require '/harness/tiles3.php';");
ok('مدیر نوار کاشی را می‌بیند', t3.admin_nav === 1);
ok('برای مدیر دقیقاً ۳ کاشی رندر شد', t3.admin_count === 3, String(t3.admin_count));
ok('دانش‌آموز نوار کاشی را نمی‌بیند', t3.student_empty === 1);
ok('با خاموش‌بودن تنظیم، چیزی رندر نمی‌شود', t3.disabled_empty === 1);
ok('مقدار خراب در دیتابیس فقط کاشی معتبر می‌دهد', t3.corrupt_count === 1, String(t3.corrupt_count));
ok('کلید ناشناخته وارد HTML نمی‌شود', t3.corrupt_no_zzz === 1);

/* مجوزها: مدیر محدود نباید کاشی بی‌مجوز ببیند */
php.writeFile('/harness/tiles4.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
@mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess');
session_id('tileperm'.mt_rand()); session_start();
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/auth.php';
require_once '/www/includes/header_tiles.php';
set_setting('header_tiles','dashboard,students,settings,admins');
set_setting('header_tiles_enabled','1');
/* مدیر معمولی با مجوز محدود — باید واقعاً در دیتابیس باشد،
   چون has_permission() رکورد را از current_admin() می‌خواند. */
DB::execute("DELETE FROM admins WHERE username='limitedadm'");
DB::execute("INSERT INTO admins (username,password,name,role,permissions,status)
             VALUES ('limitedadm',?,'مدیر محدود','admin',?,1)",
            [password_hash('x', PASSWORD_DEFAULT), json_encode(['manage_students'])]);
$aid = DB::fetch("SELECT id FROM admins WHERE username='limitedadm'")['id'];
$_SESSION = ['admin_id'=>$aid,'admin_role'=>'admin'];
ob_start(); render_header_tiles(); $h = ob_get_clean();
DB::execute("DELETE FROM admins WHERE username='limitedadm'");
echo json_encode([
  'has_students'=> strpos($h,'students.php')!==false ?1:0,
  'has_settings'=> strpos($h,'href="settings.php"')!==false ?1:0,
  'has_admins'  => strpos($h,'admins.php')!==false ?1:0,
  'has_dash'    => strpos($h,'view=dashboard')!==false ?1:0,
]);`);
const t4 = await j("<?php require '/harness/tiles4.php';");
ok('مدیر محدود کاشی مجازش را می‌بیند', t4.has_students === 1);
ok('کاشی بدون مجوز (سفارشی‌سازی) پنهان می‌شود', t4.has_settings === 0);
ok('کاشی مخصوص سوپرادمین (مدیران) پنهان می‌شود', t4.has_admins === 0);
ok('داشبورد برای همه در دسترس است', t4.has_dash === 1);

/* ═══════════════ ب) کپچای تطبیقی ═══════════════ */
console.log('\n══ کپچای تطبیقی ══');

php.writeFile('/harness/cap1.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
@mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess');
session_id('capprobe'.mt_rand()); session_start();
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/auth.php';
require_once '/www/includes/header_tiles.php';
login_guard_ensure_schema();
DB::execute("DELETE FROM login_guard WHERE identity LIKE 'admin:capt%'");
$r = [];
$r['fresh']      = login_needs_captcha('admin','captest') ? 1:0;   // بار اول → ۰
login_guard_fail('admin','captest');
$r['after_fail'] = login_needs_captcha('admin','captest') ? 1:0;   // بعد از خطا → ۱
login_guard_fail('admin','captest');
$r['after_two']  = login_needs_captcha('admin','captest') ? 1:0;
login_guard_success('admin','captest');
$r['after_ok']   = login_needs_captcha('admin','captest') ? 1:0;   // بعد از موفقیت → ۰
/* حساب دیگر نباید آلوده شود */
login_guard_fail('admin','captest2');
$r['other_acct'] = login_needs_captcha('admin','captest') ? 1:0;
/* نقش‌ها از هم جدا باشند */
$r['other_role'] = login_needs_captcha('student','captest2') ? 1:0;
/* شناسهٔ خالی نباید کپچا بخواهد (فرم هنوز پر نشده) */
$r['empty_id']   = login_needs_captcha('admin','') ? 1:0;
/* حساسیت به حروف بزرگ/کوچک نداشته باشد */
login_guard_fail('admin','MiXeD');
$r['case_ins']   = login_needs_captcha('admin','mixed') ? 1:0;
DB::execute("DELETE FROM login_guard WHERE identity LIKE 'admin:capt%' OR identity LIKE 'admin:mixed%'");
echo json_encode($r);`);
const g = await j("<?php require '/harness/cap1.php';");
ok('بار اول کد امنیتی لازم نیست', g.fresh === 0);
ok('بعد از اولین اشتباه، کد امنیتی لازم می‌شود', g.after_fail === 1);
ok('با اشتباه دوم همچنان لازم است', g.after_two === 1);
ok('بعد از ورود موفق دوباره برداشته می‌شود', g.after_ok === 0);
ok('اصلاح نام کاربری پس از خطا، کپچا را دور نمی‌زند', g.other_acct === 1);
ok('نقش‌ها از هم جدا هستند', g.other_role === 0);
ok('پس از خطا کپچا حتی با شناسهٔ خالی دیده می‌شود', g.empty_id === 1);
ok('بزرگی/کوچکی حروف مهم نیست', g.case_ins === 1);

/* دروازه: کپچای غلط باید رد شود و شمارنده را بالا ببرد */
php.writeFile('/harness/cap2.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
@mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess');
session_id('capgate'.mt_rand()); session_start();
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/auth.php';
require_once '/www/includes/header_tiles.php';
login_guard_ensure_schema();
DB::execute("DELETE FROM login_guard WHERE identity = 'admin:gate'");
$r = [];
/* بار اول: کپچا لازم نیست، پس دروازه حتی با پاسخ خالی باز است */
$r['first_open'] = login_captcha_gate('admin','gate','') ? 1:0;
login_guard_fail('admin','gate');
/* حالا لازم است. پاسخ غلط → بسته */
$_SESSION['captcha_ans'] = 7;
$r['wrong_blocked'] = login_captcha_gate('admin','gate','999') ? 1:0;
/* و شمارنده بالا رفته باشد (تا با کپچای غلط نشود دورش زد) */
$row = DB::fetch("SELECT fail_count FROM login_guard WHERE identity='admin:gate'");
$r['count_grew'] = ((int)$row['fail_count'] >= 2) ? 1:0;
/* پاسخ درست → باز */
$_SESSION['captcha_ans'] = 7;
unset($_SESSION['captcha_pool']);
$r['right_opens'] = login_captcha_gate('admin','gate','7') ? 1:0;
DB::execute("DELETE FROM login_guard WHERE identity = 'admin:gate'");
echo json_encode($r);`);
const gate = await j("<?php require '/harness/cap2.php';");
ok('بار اول دروازه باز است (بدون کد امنیتی)', gate.first_open === 1);
ok('بعد از اشتباه، پاسخ غلط کد امنیتی رد می‌شود', gate.wrong_blocked === 0);
ok('کپچای غلط شمارنده را بالا می‌برد (قابل دور زدن نیست)', gate.count_grew === 1);
ok('پاسخ درست کد امنیتی اجازهٔ عبور می‌دهد', gate.right_opens === 1);

/* endpoint وضعیت */
/* endpoint وضعیت — هر فراخوانی در یک اجرای PHP تازه، چون فایل
   واقعی exit می‌زند و include بار دوم خروجی نمی‌دهد. */
async function hitEndpoint(role, id) {
  php.writeFile('/harness/ep.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
@mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess');
session_id('qualityEndpointSession'); session_start();
$_GET = ['role' => ${JSON.stringify(role)}, 'id' => ${JSON.stringify(id)}];
require '/www/login-captcha-state.php';`);
  return (await run("<?php require '/harness/ep.php';")).out.trim();
}

/* حالت اولیه را آماده کن */
php.writeFile('/harness/epseed.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/auth.php';
require_once '/www/includes/header_tiles.php';
login_guard_ensure_schema();
DB::execute("DELETE FROM login_guard WHERE identity='admin:epuser'");
echo 'SEEDED';`);
await run("<?php require '/harness/epseed.php';");

const epFresh = await hitEndpoint('admin', 'epuser');

php.writeFile('/harness/epfail.php', `<?php
ini_set('session.save_path','/tmp/sess');session_id('qualityEndpointSession');session_start();
ini_set('display_errors','0'); error_reporting(0);
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/auth.php';
require_once '/www/includes/header_tiles.php';
login_guard_fail('admin','epuser'); echo 'FAILED';`);
await run("<?php require '/harness/epfail.php';");

const epAfter   = await hitEndpoint('admin', 'epuser');
const epBadRole = await hitEndpoint('nonsense', 'epuser');
const epLongId  = await hitEndpoint('admin', 'x'.repeat(300));

const isNeed = (s, v) => typeof s === 'string' && s.includes('"need":' + v);
ok('endpoint بار اول need=false می‌دهد', isNeed(epFresh, 'false'), String(epFresh));
ok('endpoint بعد از خطا need=true می‌دهد', isNeed(epAfter, 'true'), String(epAfter));
ok('نقش نامعتبر → need=true (سخت‌گیرانه)', isNeed(epBadRole, 'true'), String(epBadRole));
ok('شناسهٔ خیلی بلند → need=true', isNeed(epLongId, 'true'), String(epLongId));

/* ═══════════════ ج) اتصال‌ها در سورس ═══════════════ */
console.log('\n══ اتصال‌ها ══');

const idx   = readFileSync(resolveFile('index.php'), 'utf8');
const alog  = readFileSync(resolveFile('admin-login.php'), 'utf8');
const hdr   = readFileSync(resolveFile('includes/header.php'), 'utf8');
const setts = readFileSync(resolveFile('settings.php'), 'utf8');
const js    = readFileSync(resolveFile('assets/js/login-captcha.js'), 'utf8');

/* هیچ فرم ورودی نباید کپچای همیشگی داشته باشد */
const staleCaptcha = (s) => (s.match(/verify_captcha\(\$_POST/g) || []).length;
ok('index.php دیگر کپچای بی‌قید ندارد', staleCaptcha(idx) === 0, String(staleCaptcha(idx)));
ok('admin-login.php دیگر کپچای بی‌قید ندارد', staleCaptcha(alog) === 0, String(staleCaptcha(alog)));
ok('هر ۴ فرم index از دروازه رد می‌شوند',
   (idx.match(/login_captcha_gate/g) || []).length === 4,
   String((idx.match(/login_captcha_gate/g) || []).length));
ok('هر ۲ فرم admin-login از دروازه رد می‌شوند',
   (alog.match(/login_captcha_gate/g) || []).length === 2);
/* هر مسیر موفق و ناموفق باید ثبت شود */
ok('index: موفقیت‌ها ثبت می‌شوند', (idx.match(/login_guard_success/g) || []).length === 4);
ok('index: شکست‌ها ثبت می‌شوند',   (idx.match(/login_guard_fail/g) || []).length === 4);
ok('admin-login: موفقیت‌ها ثبت می‌شوند', (alog.match(/login_guard_success/g) || []).length === 2);
ok('admin-login: شکست‌ها ثبت می‌شوند',   (alog.match(/login_guard_fail/g) || []).length === 2);
/* کادر کپچا باید پیش‌فرض مخفی و disabled باشد */
ok('کادر کپچا در index پیش‌فرض مخفی است', (idx.match(/js-captcha-wrap/g) || []).length === 3);
ok('ورودی کپچا پیش‌فرض disabled است (فرم قفل نشود)',
   idx.includes("? 'required' : 'disabled'") && (idx.match(/js-captcha-input/g) || []).length === 3);
ok('اسکریپت کپچا در هر دو صفحه لود می‌شود',
   idx.includes('login-captcha.js') && alog.includes('login-captcha.js'));
ok('خطای شبکه، تلاش اول را بی‌دلیل با کپچا مسدود نمی‌کند',
   js.includes('finish(null)') && js.includes('ontimeout'));
ok('هدر، نوار کاشی را صدا می‌زند', hdr.includes('render_header_tiles'));
ok('صفحهٔ سفارشی‌سازی سکشن کاشی‌ها را دارد',
   setts.includes('headerTilesSection') && setts.includes('header_tiles[]'));
ok('سفارشی‌سازی مقدار را پاک‌سازی‌شده ذخیره می‌کند',
   setts.includes('header_tiles_sanitize_post'));

console.log(`\n  سوئیت منوی کاشی‌ای و کپچای تطبیقی: ${pass} PASS / ${fail} FAIL`);
process.exit(fail ? 1 : 0);
