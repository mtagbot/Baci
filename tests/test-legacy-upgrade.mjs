/**
 * test-legacy-upgrade.mjs — «به‌روزرسانی روی نصب قدیمی» (V4.209.2)
 *
 * مبنای واقعی: روی یک هاست cPanel، بعد از نصب بستهٔ اصلاحی این خطا آمد:
 *
 *   db.php:13 Uncaught Error: Failed opening required
 *   '/home/bacira/public_html/reports/includes/install_guard.php'
 *
 * ریشه: `includes/db.php` از Release_V1.0 به بعد دو فایل «اسکلت نصب»
 * (`install_guard.php` و `db_sqlite_compat.php`) را با require بی‌قید
 * بارگذاری می‌کرد. نصب‌هایی که از نسخه‌های قدیمی‌تر آمده‌اند این دو فایل را
 * ندارند، و بستهٔ اصلاحی هم (به‌درستی) آن‌ها را همراه نمی‌آورد. نتیجه: سفیدی
 * کامل صفحه با ۵۰۰.
 *
 * این سوئیت سه چیز را تضمین می‌کند:
 *   ۱) db.php بدون آن دو فایل هم بالا می‌آید (اجرای واقعی PHP).
 *   ۲) در همهٔ فایل‌های بستهٔ اصلاحی، require هر فایلی که «ممکن است روی نصب
 *      قدیمی نباشد» با is_file محافظت شده است.
 *   ۳) هیچ فایل اسکلت نصب (install_guard / db_sqlite_compat / release_install)
 *      در بستهٔ اصلاحی منتشر نمی‌شود — چون جای‌گزینی آن‌ها رفتار نصب را
 *      عوض می‌کند.
 */
import { run, php } from './harness/lib.mjs';
import { REPO } from './harness/site.mjs';
import { readFileSync, readdirSync, existsSync } from 'node:fs';
import { join, relative } from 'node:path';

let pass = 0, fail = 0;
const ok = (name, cond, detail = '') => cond
  ? (pass++, console.log(`  ✅ ${name}`))
  : (fail++, console.log(`  ❌ ${name}${detail ? '  → ' + detail : ''}`));
const read = rel => readFileSync(join(REPO, rel), 'utf8');

const PATCH_DIR = join(REPO, 'update-v4.209.0');
const shipped = [];
(function walk(dir) {
  for (const e of readdirSync(dir, { withFileTypes: true })) {
    const abs = join(dir, e.name);
    if (e.isDirectory()) { if (e.name !== 'assets' && e.name !== 'config') walk(abs); }
    else if (e.name.endsWith('.php')) shipped.push(relative(PATCH_DIR, abs).split('\\').join('/'));
  }
})(PATCH_DIR);

/* ═══════════════════════ ۱) db.php بدون فایل‌های اسکلت Release_V1.0 ══ */
console.log('\n══ ۱) db.php روی نصب قدیمی (بدون install_guard.php) ══');

const probe = await run(`<?php
$dir = '/tmp/legacy_probe';
@mkdir($dir . '/includes', 0777, true);
@mkdir($dir . '/config', 0777, true);
copy('/www/includes/db.php', $dir . '/includes/db.php');
file_put_contents($dir . '/config/database.php', "<?php return ['driver'=>'mysql','host'=>'127.0.0.1','port'=>'3306','database'=>'x','username'=>'u','password'=>'p','charset'=>'utf8mb4'];");
@unlink($dir . '/includes/install_guard.php');
@unlink($dir . '/includes/db_sqlite_compat.php');
$err = '';
try { require $dir . '/includes/db.php'; } catch (Throwable $e) { $err = get_class($e) . ': ' . $e->getMessage(); }
echo '@@J@@' . json_encode([
  'error' => $err,
  'db_class' => class_exists('DB'),
  'sqlite_class' => class_exists('SQLitePDO'),
]);
`);
const p = JSON.parse((String(probe.out).match(/@@J@@(\{.*\})/s) || [, '{}'])[1]);
ok('db.php بدون install_guard.php و db_sqlite_compat.php بارگذاری می‌شود',
  p.error === '' && p.db_class === true, p.error || 'no error but class missing');
ok('کلاس SQLitePDO هم بدون شیم SQLite تعریف می‌شود (روی MySQL استفاده نمی‌شود)',
  p.sqlite_class === true);

const guardedSrc = read('update-v4.209.0/includes/db.php');
ok('require هر دو فایل اسکلت با is_file محافظت شده',
  /if \(is_file\(__DIR__ \. '\/install_guard\.php'\)\) require_once/.test(guardedSrc) &&
  /if \(is_file\(__DIR__ \. '\/db_sqlite_compat\.php'\)\) require_once/.test(guardedSrc));
ok('بازنویسی SQLite فقط وقتی شیم هست صدا زده می‌شود',
  guardedSrc.includes("class_exists('SQLiteCompat') ? SQLiteCompat::rewrite($sql) : $sql"));
ok('API پایهٔ DB دست‌نخورده مانده (getInstance/fetch/fetchAll/execute/query)',
  ['function getInstance', 'function fetchAll', 'function fetch(', 'function execute', 'function query', 'function lastInsertId']
    .every(sig => guardedSrc.includes(sig)));

/* ═════════ ۱ب) config/database.php با شکل قدیمی هم پذیرفته می‌شود ══ */
console.log('\n══ ۱ب) تحمل شکل‌های قدیمی config/database.php ══');

/* هاست واقعی: نصب قدیمی، فایل کانفیگ با کلیدهای دیگر یا بدون driver/port. */
const shapes = {
  'کلیدهای قدیمی (db_name/db_user/db_pass/db_host)': `<?php return ['db_host'=>'127.0.0.1','db_name'=>'school','db_user'=>'u','db_pass'=>'p'];`,
  'بدون driver ولی با host+database':                `<?php return ['host'=>'127.0.0.1','database'=>'school','username'=>'u','password'=>'p'];`,
  'بدون port و charset':                             `<?php return ['driver'=>'mysql','host'=>'127.0.0.1','database'=>'school','username'=>'u','password'=>'p'];`,
  'driver=mysqli':                                   `<?php return ['driver'=>'mysqli','host'=>'127.0.0.1','database'=>'school','username'=>'u','password'=>'p'];`,
};
for (const [label, cfg] of Object.entries(shapes)) {
  const r = await run(`<?php
$dir = '/tmp/cfg_probe';
@mkdir($dir . '/includes', 0777, true); @mkdir($dir . '/config', 0777, true);
copy('/www/includes/db.php', $dir . '/includes/db.php');
file_put_contents($dir . '/config/database.php', ${JSON.stringify(cfg)});
$err = '';
try {
  require $dir . '/includes/db.php';
  $ref = new ReflectionClass('DB');
  $ctor = $ref->getConstructor(); $ctor->setAccessible(true);
  $obj = $ref->newInstanceWithoutConstructor();
  $ctor->invoke($obj);
} catch (Throwable $e) { $err = get_class($e) . ': ' . $e->getMessage(); }
echo '@@J@@' . json_encode(['error' => $err]);
`);
  const res = JSON.parse((String(r.out).match(/@@J@@(\{.*\})/s) || [, '{}'])[1] || '{}');
  /* اتصال واقعی به MySQL در محیط تست ممکن نیست؛ فقط نباید خطای «پیکربندی»
     بدهد. خطای اتصال (Database connection failed) پذیرفته است. */
  const configError = /Invalid database configuration|Incomplete database configuration/.test(res.error || '');
  ok(`پیکربندی «${label}» پذیرفته می‌شود`, !configError, res.error);
}

/* و شکل استاندارد دقیقاً مثل قبل کار می‌کند (بدون رگرسیون) */
const stdProbe = await run(`<?php
$dir = '/tmp/cfg_std';
@mkdir($dir . '/includes', 0777, true); @mkdir($dir . '/config', 0777, true);
copy('/www/includes/db.php', $dir . '/includes/db.php');
file_put_contents($dir . '/config/database.php', "<?php return ['driver'=>'mysql','host'=>'db.example','port'=>3307,'database'=>'school','username'=>'u','password'=>'p','charset'=>'utf8mb4'];");
require $dir . '/includes/db.php';
echo '@@J@@' . json_encode(['has_class' => class_exists('DB'), 'sqlite_pdo' => class_exists('SQLitePDO')]);
`);
const std = JSON.parse((String(stdProbe.out).match(/@@J@@(\{.*\})/s) || [, '{}'])[1] || '{}');
ok('شکل استاندارد Release_V1.x بدون تغییر بارگذاری می‌شود',
  std.has_class === true && std.sqlite_pdo === true);

/* ═════════════════ ۲) require های پرخطر در کل بستهٔ اصلاحی ══ */
console.log('\n══ ۲) هیچ require بی‌محافظ روی فایل‌های «ممکن است نباشد» ══');

/* فایل‌هایی که روی نصب‌های قدیمی می‌توانند غایب باشند. اگر بسته آن‌ها را
   نمی‌آورد، require باید با is_file محافظت شده باشد. */
/* فقط فایل‌هایی که «تازه در Release_V1.0 / نسخهٔ دسکتاپ» اضافه شده‌اند یا
   از بسته‌های اصلاحی جدا آمده‌اند. فایل‌های قدیمی‌ترِ هسته (auth، db، jdf،
   academic_year_helpers، page_navigation…) روی هر نصب فعالی وجود دارند و
   require کردنشان مثل قبل درست است. */
const MAY_BE_ABSENT = [
  'install_guard.php', 'db_sqlite_compat.php', 'release_install.php',
  'bot_outbox.php', 'report_image.php',
];
const shippedSet = new Set(shipped);
const unguarded = [];
for (const rel of shipped) {
  const src = read('update-v4.209.0/' + rel);
  const lines = src.split('\n');
  lines.forEach((line, i) => {
    const m = line.match(/^\s*(?:require|require_once)\b[^;]*['"]([^'"]+\.php)['"]/);
    if (!m) return;
    const target = m[1].replace(/^\.\//, '').replace(/^\/+/, '');
    const base = target.split('/').pop();
    const risky = MAY_BE_ABSENT.includes(base);
    const isGuarded = /is_file\s*\(/.test(line) || /is_file\s*\(/.test(lines[i - 1] || '') || /is_file\s*\(/.test(lines[i - 2] || '');
    const inPackage = shippedSet.has(target) || shippedSet.has('includes/' + target) ||
                      shipped.some(f => f.endsWith('/' + target));
    if (risky && !isGuarded && !inPackage) unguarded.push(`${rel}:${i + 1} → ${target}`);
  });
}
ok('هیچ require بی‌محافظ روی فایل‌های احتمالی‌غایب نمانده', unguarded.length === 0, unguarded.join(' | '));

const botSrc = read('update-v4.209.0/includes/bot_helpers.php');
ok('bot_helpers هم report_image و bot_outbox را محافظت‌دار بار می‌کند',
  botSrc.includes("if (is_file(__DIR__ . '/report_image.php'))") &&
  botSrc.includes("if (is_file(__DIR__ . '/bot_outbox.php'))"));

/* ═══════════════════════ ۳) اسکلت نصب در بسته منتشر نمی‌شود ══ */
console.log('\n══ ۳) فایل‌های اسکلت نصب به بستهٔ اصلاحی راه پیدا نمی‌کنند ══');
for (const forbidden of ['includes/install_guard.php', 'includes/db_sqlite_compat.php',
                         'includes/release_install.php', '.htaccess', 'config/config.php']) {
  ok(`«${forbidden}» در بستهٔ اصلاحی نیست`, !shippedSet.has(forbidden));
}

/* ═══════════════════════ ۴) رفتار عادی سایت تغییر نکرده ══ */
console.log('\n══ ۴) نصب سالم (با فایل‌های اسکلت) هنوز کار می‌کند ══');
const healthy = await run(`<?php
@mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess'); session_name('BACI_TEST');
session_id('harnessAdm0001'); session_start();
$_SESSION['admin_id']=1; $_SESSION['admin_role']='super_admin';
$_SERVER['REQUEST_METHOD']='GET'; $_SERVER['PHP_SELF']='/www/index.php'; $_SERVER['SCRIPT_NAME']='/index.php';
$_SERVER['HTTP_HOST']='harness.local'; $_SERVER['HTTP_USER_AGENT']='legacy-suite';
chdir('/www'); ob_start();
try { include '/www/index.php'; } catch (Throwable $e) { echo "TB: " . $e->getMessage(); }
$h = ob_get_clean();
echo '@@J@@' . json_encode([
  'len' => strlen($h),
  'fatal' => strpos($h, 'TB:') !== false,
  'guard_loaded' => function_exists('release_require_install'),
  'sqlite_class' => class_exists('SQLitePDO'),
]);
`);
const h = JSON.parse((String(healthy.out).match(/@@J@@(\{.*\})/s) || [, '{}'])[1]);
ok('index.php روی نصب کامل رندر می‌شود', !h.fatal && h.len > 3000, `len=${h.len}`);
ok('install_guard وقتی فایل هست، همچنان اجرا می‌شود (رفتار نصب حفظ شده)',
  h.guard_loaded === true);
ok('SQLitePDO با شیم موجود هم تعریف می‌شود', h.sqlite_class === true);

/* ═════════ ۵) همان مسیر خطای هاست واقعی، بدون install_guard.php ══ */
console.log('\n══ ۵) students.php روی نصب بدون install_guard.php (همان stack trace) ══');
/* این همان زنجیرهٔ گزارش‌شده است: students.php:8 → auth.php:6 → functions.php:46 → db.php:13 */
try { php.unlink('/www/includes/install_guard.php'); } catch (e) {}
const stillThere = await run(`<?php echo is_file('/www/includes/install_guard.php') ? 'YES' : 'NO';`);
ok('فایل install_guard.php از محیط آزمایش حذف شد (شبیه‌سازی نصب قدیمی)',
  String(stillThere.out).includes('NO'));

const legacyPage = await run(`<?php
@mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess'); session_name('BACI_TEST');
session_id('harnessAdm0001'); session_start();
$_SESSION['admin_id']=1; $_SESSION['admin_role']='super_admin';
$_SERVER['REQUEST_METHOD']='GET'; $_SERVER['PHP_SELF']='/www/students.php'; $_SERVER['SCRIPT_NAME']='/students.php';
$_SERVER['HTTP_HOST']='harness.local'; $_SERVER['HTTP_USER_AGENT']='legacy-suite';
chdir('/www'); ob_start();
try { include '/www/students.php'; } catch (Throwable $e) { echo 'TB: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(); }
$h = ob_get_clean();
echo '@@J@@' . json_encode([
  'len' => strlen($h),
  'fatal' => (strpos($h, 'TB:') !== false),
  'tail' => mb_substr(trim(strip_tags($h)), -120),
], JSON_UNESCAPED_UNICODE);
`);
const lp = JSON.parse((String(legacyPage.out).match(/@@J@@(\{.*\})/s) || [, '{}'])[1] || '{}');
ok('students.php بدون install_guard.php کامل رندر می‌شود (خطای ۵۰۰ رفع شد)',
  lp.fatal === false && lp.len > 20000, `len=${lp.len} ${lp.tail || ''}`);
ok('صفحه به «Students» واقعی رسیده است (نه صفحهٔ خطا)',
  !/Failed opening required|Fatal error|خطای داخلی/.test(lp.tail || ''));

console.log('\n════════════════════════════════════════════════════');
console.log(`  سوئیت به‌روزرسانی نصب قدیمی: ${pass} PASS / ${fail} FAIL`);
console.log('════════════════════════════════════════════════════');
process.exit(fail ? 1 : 0);
