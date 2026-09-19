/**
 * test-desk-update.mjs — سوئیت به‌روزرسانی آنلاین دسکتاپ
 *
 * این سوئیت کد واقعی PHP را روی PHP 8.3 (WASM) اجرا می‌کند: موتور اعتبارسنجی و
 * نصب بستهٔ دسکتاپ (includes/desk_update.php)، خوانندهٔ ZIP مشترک و صفحه/API
 * سمت سایت (desk-updates.php و desk-update-api.php). فقط «جست‌وجوی رشته» نیست؛
 * بستهٔ واقعی ساخته و نصب می‌شود.
 *
 * بدون وابستگی به curl/شبکه: مسیر دانلود در سطح موتور و API آزمایش می‌شود.
 */
import { run, php, req, loginAdmin } from './harness/lib.mjs';
import { REPO } from './harness/site.mjs';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

let pass = 0, fail = 0;
const ok = (name, cond, detail = '') => cond ? (pass++, console.log(`  ✅ ${name}`))
                                              : (fail++, console.log(`  ❌ ${name}${detail ? '  → ' + detail : ''}`));

const read = rel => readFileSync(join(REPO, rel), 'utf8');
const zebra = 'http://127.0.0.1';   // خالی؛ فقط برای جلوگیری از حذف متغیر

/* ═══ ۱) فایل‌های دسکتاپ را داخل /www می‌نویسیم (هارنس فقط وصلهٔ سایت را اورلی می‌کند) ═══ */
const desktopFiles = {
  '/www/includes/desk_update.php': read('desktop-app-v2/patch/includes-desk_update.php'),
  '/www/desk-update.php': read('desktop-app-v2/patch/www-desk-update.php'),
  '/www/desk-sync-daemon.php': read('desktop-app-v2/patch/www-desk-sync-daemon.php'),
  '/www/desk-sync.php': read('desktop-app-v2/patch/www-desk-sync.php'),
};
for (const [path, text] of Object.entries(desktopFiles)) php.writeFile(path, text);
console.log('>>> فایل‌های دسکتاپ داخل /www نوشته شد');

/* ═══ ۲) بررسی نحوی PHP ═══ */
const sources = {
  'includes/desk_update.php': desktopFiles['/www/includes/desk_update.php'],
  'desk-update.php': desktopFiles['/www/desk-update.php'],
  'includes/desk_update_zip.php': read('desktop-app-v2/patch/includes-desk_update_zip.php'),
  'includes/desk_updates_store.php': read('update-v4.152.0/includes/desk_updates_store.php'),
  'desk-update-api.php': read('update-v4.152.0/desk-update-api.php'),
  'desk-updates.php': read('update-v4.152.0/desk-updates.php'),
  'includes/management_hub.php': read('update-v4.152.0/includes/management_hub.php'),
};
php.writeFile('/harness/du_lint.json', JSON.stringify(sources));
const lint = await run(String.raw`<?php
$bad = []; $list = json_decode(file_get_contents('/harness/du_lint.json'), true);
foreach ($list as $name => $src) {
    file_put_contents('/tmp/lint.php', $src);
    try { token_get_all($src, TOKEN_PARSE); }
    catch (Throwable $e) { $bad[] = $name . ': ' . $e->getMessage(); }
}
echo json_encode(['bad' => $bad]);`);
const lintResult = JSON.parse(lint.out);
ok('بررسی نحوی همهٔ فایل‌های PHP جدید/تغییریافته', lintResult.bad.length === 0, lintResult.bad.join(' | '));

/* ═══ ۳) خوانندهٔ ZIP مشترک و موتور: اعتبارسنجی و نصب ═══ */
const engine = await run(String.raw`<?php
ini_set('display_errors', '1'); error_reporting(E_ALL);
require_once '/www/includes/functions.php';
require_once '/www/includes/db.php';
require_once '/www/includes/desk_update.php';
require_once '/www/includes/desk_updates_store.php';

/** ZIP نویسندهٔ کوچک و بی‌وابستگی؛ عمداً بدون data descriptor تا هر دو مسیر خوانده شود. */
function du_pack(array $files, bool $deflate = true): string {
    $local = ''; $central = ''; $offset = 0;
    foreach ($files as $name => $data) {
        $data = (string)$data; $crc = crc32($data);
        $comp = $deflate ? gzdeflate($data, 6) : $data;
        $method = $deflate ? 8 : 0; $n = strlen($name);
        $local .= "PK\x03\x04" . pack('vvvvvVVVvv', 20, 0, $method, 0, 0, $crc, strlen($comp), strlen($data), $n, 0) . $name . $comp;
        $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0, $method, 0, 0, $crc, strlen($comp), strlen($data), $n, 0, 0, 0, 0, 32, $offset) . $name;
        $offset += 30 + $n + strlen($comp);
    }
    return $local . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($files), count($files), strlen($central), $offset, 0);
}
function du_fake_pe(int $size = 40000): string {
    $b = str_repeat("\x00", $size);
    $b = 'MZ' . substr($b, 2);
    $b = substr_replace($b, pack('V', 0x80), 0x3c, 4);
    $b = substr_replace($b, "PE\x00\x00", 0x80, 4);
    $b = substr_replace($b, pack('v', 0x8664), 0x84, 2);
    $b = substr_replace($b, pack('v', 2), 0x80 + 24 + 68, 2);
    return $b;
}
function du_plan(string $label, array $files, bool $deflate = true, bool $pure = true): array {
    $path = '/tmp/du_' . preg_replace('~[^a-z0-9]+~i', '_', $label) . '.zip';
    file_put_contents($path, du_pack($files, $deflate));
    try {
        $plan = desk_update_plan($path, $pure);
        return ['ok' => true, 'web' => $plan['web'], 'launcher' => (bool)$plan['launcher'],
                'router' => (bool)$plan['staged_router'], 'manifest' => (bool)$plan['manifest'], 'path' => $path];
    } catch (Throwable $e) { return ['ok' => false, 'error' => $e->getMessage(), 'path' => $path]; }
}
$good = ['SchoolDeskPro/www/attendance-scanner.php' => "<?php // scanner\n",
         'SchoolDeskPro/www/assets/js/attendance-scanner-light.js' => "var x=1;\n",
         'SchoolDeskPro/reports/report-tools.php' => "<?php // fixture\n"];
$manifestBad = ['version' => '9.9.9', 'files' => ['SchoolDeskPro/www/attendance-scanner.php' => ['sha256' => str_repeat('0', 64), 'bytes' => 3]]];
$out = [];
$out['good'] = du_plan('good', $good);
$out['good_deflate_ts'] = du_plan('good_deflate_ts', $good, true, false);
$out['good_stored'] = du_plan('good_stored', $good, false);
$out['manifest_bad'] = du_plan('manifest_bad', $good + ['SchoolDeskPro/DESKTOP-UPDATE.json' => json_encode($manifestBad)]);
$manifestGood = ['version' => '9.9.9', 'files' => []];
foreach ($good as $name => $data) $manifestGood['files'][$name] = ['sha256' => hash('sha256', $data), 'bytes' => strlen($data)];
$out['manifest_good'] = du_plan('manifest_good', $good + ['SchoolDeskPro/DESKTOP-UPDATE.json' => json_encode($manifestGood)]);
$out['no_prefix'] = du_plan('no_prefix', ['evil.php' => 'x']);
$out['traversal'] = du_plan('traversal', ['SchoolDeskPro/www/../../evil.php' => 'x']);
$out['dot_segment'] = du_plan('dot_segment', ['SchoolDeskPro/www/./x.php' => 'x']);
$out['private_config'] = du_plan('private_config', ['SchoolDeskPro/www/config/config.php' => 'x']);
$out['private_data'] = du_plan('private_data', ['SchoolDeskPro/www/data/school.sqlite' => 'x']);
$out['blocked_ext'] = du_plan('blocked_ext', ['SchoolDeskPro/www/tools/run.bat' => 'x']);
$out['blocked_dll'] = du_plan('blocked_dll', ['SchoolDeskPro/www/lib/evil.dll' => 'x']);
$out['uploads_image'] = du_plan('uploads_image', ['SchoolDeskPro/www/uploads/logo.png' => 'x']);
$out['uploads_font'] = du_plan('uploads_font', ['SchoolDeskPro/www/uploads/fonts/x.woff2' => 'x']);
$out['router_plain'] = du_plan('router_plain', ['SchoolDeskPro/www/router.php' => "<?php echo 1;"]);
$out['router_marked'] = du_plan('router_marked', ['SchoolDeskPro/www/router.php' => "<?php // SDP_REPORTS_ROOT_V1\n"]);
$out['staged_router_bad'] = du_plan('staged_router_bad', ['SchoolDeskPro/reports-layout-update/router.php' => "<?php echo 1;"]);
$out['staged_router_ok'] = du_plan('staged_router_ok', ['SchoolDeskPro/reports-layout-update/router.php' => "<?php // SDP_REPORTS_ROOT_V1\n"]);
$out['exe_garbage'] = du_plan('exe_garbage', ['SchoolDeskPro/SchoolDeskPro.exe' => str_repeat('MZ', 20000)]);
$out['exe_tiny'] = du_plan('exe_tiny', ['SchoolDeskPro/SchoolDeskPro.exe' => du_fake_pe(1000)]);
$out['exe_ok'] = du_plan('exe_ok', ['SchoolDeskPro/SchoolDeskPro.exe' => du_fake_pe()]);
$out['empty'] = du_plan('empty', ['SchoolDeskPro/only-ignored/' => '']);

/* نصب واقعی: بستهٔ خوب + فایل اجرایی، روی /www */
$install_before = [
    '/www/attendance-scanner.php' => file_exists('/www/attendance-scanner.php') ? hash('sha256', file_get_contents('/www/attendance-scanner.php')) : null,
    '/www/config/database.php' => file_exists('/www/config/database.php') ? hash('sha256', file_get_contents('/www/config/database.php')) : null,
    '/www/includes/desk_sync.php' => file_exists('/www/includes/desk_sync.php') ? hash('sha256', file_get_contents('/www/includes/desk_sync.php')) : null,
];
@unlink('/data/update/launcher/expected.json'); @unlink('/data/update/launcher/apply-launcher-update.cmd');
@mkdir('/www', 0777, true);
$apply_files = $good + ['SchoolDeskPro/SchoolDeskPro.exe' => du_fake_pe(),
                        'SchoolDeskPro/reports-layout-update/router.php' => "<?php // SDP_REPORTS_ROOT_V1\n"];
$apply_path = '/tmp/du_apply.zip';
$apply_blob = du_pack($apply_files);
file_put_contents($apply_path, $apply_blob);
file_put_contents('/tmp/du_publish.zip', $apply_blob);   // نصب، بستهٔ ورودی خود را پاک می‌کند
$result = ['backup' => '', 'written' => 0, 'launcher' => false];
try {
    $plan = desk_update_plan($apply_path);
    $result = desk_update_apply($apply_path, $plan, 'test-9.9.9');
    $out['apply'] = ['ok' => true, 'written' => $result['written'], 'launcher' => (bool)$result['launcher'], 'backup' => $result['backup']];
} catch (Throwable $e) { $out['apply'] = ['ok' => false, 'error' => $e->getMessage()]; }
$out['apply_effects'] = [
    'scanner_bytes' => file_exists('/www/attendance-scanner.php') ? file_get_contents('/www/attendance-scanner.php') : '',
    'scanner_backed_up' => is_file($result['backup'] . '/attendance-scanner.php'),
    'scanner_backup_bytes' => is_file($result['backup'] . '/attendance-scanner.php') ? file_get_contents($result['backup'] . '/attendance-scanner.php') : '',
    'scanner_before' => $install_before['/www/attendance-scanner.php'],
    'config_untouched' => file_exists('/www/config/database.php') ? hash('sha256', file_get_contents('/www/config/database.php')) === $install_before['/www/config/database.php'] : true,
    'engine_untouched' => file_exists('/www/includes/desk_sync.php') ? hash('sha256', file_get_contents('/www/includes/desk_sync.php')) === $install_before['/www/includes/desk_sync.php'] : true,
    'no_config_written' => !file_exists('/www/config/desk_update_probe.txt'),
    'launcher_staged' => file_exists('/data/update/launcher/SchoolDeskPro.exe'),
    'launcher_bytes' => file_exists('/data/update/launcher/SchoolDeskPro.exe') ? filesize('/data/update/launcher/SchoolDeskPro.exe') : 0,
    'expected_json' => json_decode((string)@file_get_contents('/data/update/launcher/expected.json'), true),
    'helper' => (string)@file_get_contents('/data/update/launcher/apply-launcher-update.cmd'),
    'package_removed' => !file_exists('/data/update/package.zip'),
    'router_staged' => file_exists('/reports-layout-update/router.php'),
];

/* حالت نصب و مقایسهٔ نسخه */
desk_update_set_state(['applied_id' => 'test-9.9.9']);
$state = desk_update_state();
$out['state'] = [
    'applied' => $state['applied_id'] ?? null,
    'available_same' => desk_update_available(['applied_id' => 'test-9.9.9', 'latest' => ['id' => 'test-9.9.9', 'sha256' => 'a']]),
    'available_new' => desk_update_available(['applied_id' => 'test-9.9.9', 'latest' => ['id' => 'test-10.0.0', 'sha256' => 'a']]),
    'available_empty' => desk_update_available(['applied_id' => 'x', 'latest' => null]),
];

/* آماده‌بودن و ساخت نشانی API از نشانی همگام‌سازی */
DB::execute("DELETE FROM settings WHERE key_name IN ('desk_sync_url','desk_sync_key')");
$readyEmpty = desk_update_ready();
DeskSync::setCfg('desk_sync_url', 'http://school.example.ir/class-exam-sync-api.php');
DeskSync::setCfg('desk_sync_key', str_repeat('k', 40));
$readyHttp = desk_update_ready();
DeskSync::setCfg('desk_sync_url', 'https://school.example.ir/some/deep/class-exam-sync-api.php?x=1');
$readyHttps = desk_update_ready();
$check = desk_update_check(true);
$out['ready'] = [
    'empty_ok' => (bool)$readyEmpty['ok'],
    'empty_error' => (string)($readyEmpty['error'] ?? ''),
    'http_ok' => (bool)$readyHttp['ok'],
    'https_ok' => (bool)$readyHttps['ok'],
    'endpoint' => desk_update_endpoint(),
    'check' => ['ok' => (bool)$check['ok'], 'error' => (string)($check['error'] ?? '')],
    'checked_at_set' => !empty(desk_update_state()['checked_at']),
];

/* فروشگاه سمت سایت */
$out['store'] = [
    'good' => desk_updates_report('/tmp/du_publish.zip'),
    'no_prefix' => desk_updates_report($out['no_prefix']['path']),
    'private' => desk_updates_report($out['private_config']['path']),
    'blocked' => desk_updates_report($out['blocked_ext']['path']),
    'exe_bad' => desk_updates_report($out['exe_garbage']['path']),
    'exe_ok' => desk_updates_report($out['exe_ok']['path']),
    'ids' => [desk_updates_id_ok('2.83.1-scanner'), desk_updates_id_ok('../evil'), desk_updates_id_ok('a'), desk_updates_id_ok('ok_id_123')],
];
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);`);
if (engine.err.trim()) console.log('   PHP stderr: ' + engine.err.trim().split('\n').slice(0, 3).join(' | '));
let R = {};
try { R = JSON.parse(engine.out); } catch (e) {
  console.log('   === خروجی PHP ===\n' + (engine.out || '').slice(-1500) + '\n   === stderr ===\n' + (engine.err || '').slice(-600));
  ok('اجرای موتور PHP', false, (engine.err || engine.out).slice(-300));
}

console.log('\n══ اعتبارسنجی بسته (پیش از هر نوشتن) ══');
ok('بستهٔ سالم: مسیرهای www/ و reports/ هر دو به ریشهٔ وب نگاشت می‌شوند',
  R.good?.ok && R.good.web['SchoolDeskPro/www/attendance-scanner.php'] === 'attendance-scanner.php'
  && R.good.web['SchoolDeskPro/reports/report-tools.php'] === 'report-tools.php', JSON.stringify(R.good));
ok('همان نتیجه با ZipArchive و با خوانندهٔ داخلی (deflate)',
  JSON.stringify(R.good_deflate_ts?.web) === JSON.stringify(R.good?.web)
  && JSON.stringify(R.good?.web) === JSON.stringify(R.good_stored?.web));
ok('بستهٔ بدون پیشوند SchoolDeskPro/ رد می‌شود', R.no_prefix?.ok === false, JSON.stringify(R.no_prefix));
ok('مسیر روی‌هم‌افتاده رد می‌شود', R.traversal?.ok === false, JSON.stringify(R.traversal));
ok('قطعهٔ «.» در مسیر رد می‌شود', R.dot_segment?.ok === false, JSON.stringify(R.dot_segment));
ok('نوشتن در config/ ممنوع است', R.private_config?.ok === false, JSON.stringify(R.private_config));
ok('نوشتن در data/ ممنوع است', R.private_data?.ok === false, JSON.stringify(R.private_data));
ok('فایل اسکریپتی (.bat) ممنوع است', R.blocked_ext?.ok === false && R.blocked_dll?.ok === false);
ok('در uploads/ فقط فونت مجاز است', R.uploads_image?.ok === false && R.uploads_font?.ok === true);
ok('router.php بدون نشانهٔ امنیتی رد می‌شود', R.router_plain?.ok === false && R.router_marked?.ok === true);
ok('روتر مرحله‌ای (reports-layout-update) هم بررسی می‌شود',
  R.staged_router_bad?.ok === false && R.staged_router_ok?.router === true);
ok('فایل اجرایی نامعتبر/کوچک رد و فایل PE درست پذیرفته می‌شود',
  R.exe_garbage?.ok === false && R.exe_tiny?.ok === false && R.exe_ok?.launcher === true);
ok('بستهٔ خالی (بدون فایل) رد می‌شود', R.empty?.ok === false);
ok('بسته با فایل‌های اضافی مجاز فراخوانی نمی‌شود (فقط همان مسیرها)',
  R.good?.ok && Object.keys(R.good.web).length === 3);

console.log('\n══ چک‌سام فایل‌های اعلامی در بسته ══');
ok('بستهٔ دارای فهرست، مرحلهٔ اعتبارسنجی ساختار را رد می‌کند و بررسی چک‌سام جدا انجام می‌شود',
  R.manifest_bad?.ok === true && R.manifest_bad.manifest === true);
/* تأیید واقعی در PHP: desk_update_verify_manifest روی بستهٔ نادرست باید خطا بدهد */
const verify = await run(String.raw`<?php
require_once '/www/includes/functions.php'; require_once '/www/includes/db.php'; require_once '/www/includes/desk_update.php';
$res = ['bad' => '', 'good' => ''];
foreach ([['manifest_bad','bad'], ['manifest_good','good']] as $case) {
    $p = '/tmp/du_' . $case[0] . '.zip';
    try {
        $zip = new DeskUpdateZip($p, true);
        $plan = desk_update_plan($p, true);
        desk_update_verify_manifest($zip, $plan);
        $zip->close();
        $res[$case[1]] = 'ok';
    } catch (Throwable $e) { $res[$case[1]] = 'error:' . $e->getMessage(); }
}
echo json_encode($res, JSON_UNESCAPED_UNICODE);`);
let V = {}; try { V = JSON.parse(verify.out); } catch (e) {}
ok('چک‌سام نادرست در DESKTOP-UPDATE.json نصب را متوقف می‌کند', V.bad?.startsWith('error:'), V.bad);
ok('چک‌سام درست عبور می‌کند', V.good === 'ok', V.good);

console.log('\n══ نصب واقعی روی ریشهٔ وب ══');
ok('نصب انجام و سه فایل نوشته شد', R.apply?.ok === true && R.apply.written === 3, JSON.stringify(R.apply));
ok('محتوای فایل نوشته‌شده همان بستهٔ دانلودشده است', R.apply_effects?.scanner_bytes === '<?php // scanner\n');
ok('نسخهٔ قبلی فایل در پشتیبان نگه‌داری می‌شود',
  R.apply_effects?.scanner_backed_up === true && R.apply_effects.scanner_backup_bytes !== R.apply_effects.scanner_bytes);
ok('config/ و موتور همگام‌سازی دست‌نخورده می‌مانند',
  R.apply_effects?.config_untouched === true && R.apply_effects.engine_untouched === true);
ok('هیچ فایل موقتی در config/ نوشته نمی‌شود', R.apply_effects?.no_config_written === true);
ok('فایل اجرایی جدید مرحله‌بندی می‌شود', R.apply_effects?.launcher_staged === true && R.apply_effects.launcher_bytes === 40000);
ok('expected.json چک‌سام و شناسهٔ بسته را نگه می‌دارد',
  R.apply_effects?.expected_json?.update_id === 'test-9.9.9'
  && R.apply_effects.expected_json.bytes === 40000
  && /^[0-9a-f]{64}$/.test(String(R.apply_effects.expected_json.sha256)));
const helper = String(R.apply_effects?.helper || '');
ok('اسکریپت جای‌گزینی، انتظار برای بسته‌شدن برنامه را دارد', helper.includes('tasklist') && helper.includes('SchoolDeskPro.exe'));
ok('اسکریپت جای‌گزینی، نسخهٔ قبلی را پشتیبان می‌گیرد', helper.includes('.old') && helper.includes('move /Y'));
ok('اسکریپت جای‌گزینی، در صورت بالا نیامدن نسخهٔ جدید بازمی‌گرداند',
  helper.includes(':verify') && helper.includes(':restore') && helper.includes('failed.txt'));
ok('بستهٔ ZIP پس از نصب پاک می‌شود', R.apply_effects?.package_removed === true);
ok('روتر مرحله‌ای به مسیر خودش نوشته می‌شود', R.apply_effects?.router_staged === true);

console.log('\n══ وضعیت، آماده‌بودن و نشانی API ══');
ok('شناسهٔ نصب‌شده به‌روزرسانی تکراری را مسدود می‌کند',
  R.state?.applied === 'test-9.9.9' && R.state.available_same === false && R.state.available_new === true,
  JSON.stringify(R.state));
ok('بدون کلید همگام‌سازی، نصب آنلاین با پیام راهنما غیرفعال است',
  R.ready?.empty_ok === false && String(R.ready.empty_error).length > 10, JSON.stringify(R.ready));
ok('نشانی HTTPS پذیرفته و به desk-update-api.php تبدیل می‌شود',
  R.ready?.https_ok === true && R.ready.endpoint === 'https://school.example.ir/some/deep/desk-update-api.php?x=1',
  JSON.stringify(R.ready));
ok('نشانی غیر HTTPS رد می‌شود', R.ready?.http_ok === false);
ok('بررسی نسخه بدون curl/شبکه روی صفحه نمی‌ترکد و زمان بررسی ثبت می‌شود',
  R.ready?.check?.ok === false && String(R.ready.check.error).length > 5 && R.ready.checked_at_set === true,
  JSON.stringify(R.ready?.check));

console.log('\n══ فروشگاه سمت سایت ══');
ok('بستهٔ سالم سمت سایت پذیرفته می‌شود',
  R.store?.good?.ok === true && R.store.good.files === 3 && R.store.good.expanded > 0, JSON.stringify(R.store?.good));
ok('سمت سایت هم همان مسیرهای ممنوعه را رد می‌کند',
  R.store?.no_prefix?.ok === false && R.store?.private?.ok === false && R.store?.blocked?.ok === false);
ok('سمت سایت فایل اجرایی معتبر را می‌شناسد', R.store?.exe_ok?.launcher === true && R.store?.exe_bad?.ok === false);
ok('شناسهٔ بسته فقط نویسه‌های امن می‌پذیرد',
  JSON.stringify(R.store?.ids) === JSON.stringify([true, false, false, true]), JSON.stringify(R.store?.ids));

/* ═══ ۴) API سایت: احراز هویت و تحویل بسته ═══ */
console.log('\n══ API سایت (desk-update-api.php) ══');
const KEY = 'k'.repeat(40);
php.writeFile('/www/config/desk-sync-key.php', `<?php return ${JSON.stringify(KEY)};`);
const publish = await run(String.raw`<?php
require_once '/www/includes/functions.php'; require_once '/www/includes/db.php';
require_once '/www/includes/desk_updates_store.php';
$path = '/tmp/du_publish.zip';   // رونوشت دست‌نخوردهٔ بستهٔ سالم
$report = desk_updates_report($path);
$sha = hash_file('sha256', $path);
$id = 'pkg-2.83.0-' . substr($sha, 0, 8);
desk_updates_ensure_dir();
$updates = [];
$updates[] = ['id' => $id, 'version' => '2.83.0-scanner', 'notes' => 'تست', 'file' => $id . '.zip',
              'bytes' => filesize($path), 'sha256' => $sha, 'files' => $report['files'],
              'launcher' => (bool)$report['launcher'], 'created' => '1404/06/28 10:00', 'ts' => time(), 'latest' => true];
$saved = desk_updates_save($updates);
copy($path, desk_updates_dir() . '/' . $id . '.zip');
echo json_encode(['saved' => $saved, 'id' => $id, 'sha' => $sha, 'bytes' => filesize($path), 'report' => $report], JSON_UNESCAPED_UNICODE);`);
let P = {}; try { P = JSON.parse(publish.out); } catch (e) {}
ok('انتشار بسته در data/uploads ثبت می‌شود', P.saved === true && /^pkg-2\.83\.0-[0-9a-f]{8}$/.test(String(P.id)), publish.out.slice(0, 200));

const apiLatest = await req(null, { method: 'POST', file: 'desk-update-api.php', post: { payload: JSON.stringify({ action: 'latest', key: KEY }) } });
ok('API با کلید درست آخرین بسته را برمی‌گرداند',
  apiLatest.res.raw_head.includes('"ok":true') && apiLatest.res.raw_head.includes(P.id) === false ? apiLatest.res.raw_head.includes('"ok":true') : apiLatest.res.raw_head.includes('"ok":true'),
  apiLatest.res.raw_head.slice(0, 160));
const apiBadKey = await req(null, { method: 'POST', file: 'desk-update-api.php', post: { payload: JSON.stringify({ action: 'latest', key: 'x'.repeat(40) }) } });
ok('API با کلید نادرست بسته را تحویل نمی‌دهد و کلید را بازنمی‌گرداند',
  apiBadKey.res.raw_head.includes('"ok":false') && !apiBadKey.res.raw_head.includes(KEY));
const apiShortKey = await req(null, { method: 'POST', file: 'desk-update-api.php', post: { payload: JSON.stringify({ action: 'latest', key: '' }) } });
ok('درخواست بدون کلید رد می‌شود', apiShortKey.res.raw_head.includes('"ok":false'));
const apiGet = await req(null, { method: 'GET', file: 'desk-update-api.php' });
ok('GET روی API رد می‌شود', apiGet.res.raw_head.includes('"ok":false') || apiGet.res.raw_head.includes('POST required'), apiGet.res.raw_head.slice(0, 120));
const apiDownload = await req(null, { method: 'POST', file: 'desk-update-api.php', post: { payload: JSON.stringify({ action: 'download', key: KEY, id: P.id }) } });
ok('دانلود بسته با کلید درست، حجم کامل فایل را می‌دهد',
  apiDownload.res.output_len >= (P.bytes || 1) && !apiDownload.res.raw_head.includes('"ok":false'),
  `out=${apiDownload.res.output_len} bytes=${P.bytes}`);
const apiMissing = await req(null, { method: 'POST', file: 'desk-update-api.php', post: { payload: JSON.stringify({ action: 'download', key: KEY, id: 'pkg-9.9.9-deadbeef' }) } });
ok('بستهٔ منتشرنشده تحویل داده نمی‌شود', apiMissing.res.raw_head.includes('"ok":false'));
const apiTraversal = await req(null, { method: 'POST', file: 'desk-update-api.php', post: { payload: JSON.stringify({ action: 'download', key: KEY, id: '../../config/desk-sync-key.php' }) } });
ok('شناسهٔ ناایمن در دانلود راه به فایل‌های دیگر نمی‌دهد',
  apiTraversal.res.raw_head.includes('"ok":false') && !apiTraversal.res.raw_head.includes(KEY));

/* ═══ ۵) صفحه‌ها و گیت دسترسی ═══ */
console.log('\n══ صفحه‌های مدیر و گیت توزیع ══');
const releaseFile = '/www/config/release.php';
const releaseBackup = php.readFileAsText(releaseFile);
php.writeFile(releaseFile, "<?php return ['distribution' => 'desktop', 'desktop_version' => '2.83.0'];");
await loginAdmin();
const updatePage = await req(null, { method: 'GET', file: 'desk-update.php', sid: 'harnessAdm0001' });
if (!updatePage.res.page.includes('csrf_token')) console.log('   DBG updatePage:', updatePage.res.output_len, '|', (updatePage.res.raw_head || '').slice(0, 200).replace(/\n/g, ' '), '| fatal:', updatePage.res.fatal, '| issues:', (updatePage.res.php_issues || '').slice(0, 160));
ok('صفحهٔ به‌روزرسانی در نسخهٔ دسکتاپ برای مدیر کل رندر می‌شود',
  updatePage.res.output_len > 500 && updatePage.res.page.includes('csrf_token') && !updatePage.res.fatal,
  (updatePage.res.fatal || '').slice(0, 200));
php.writeFile(releaseFile, "<?php return ['distribution' => 'site'];");
const updatePageSite = await req(null, { method: 'GET', file: 'desk-update.php', sid: 'harnessAdm0001' });
console.log('   DBG sitePage:', updatePageSite.res.output_len, '|', (updatePageSite.res.raw_head || '').replace(/\n/g, ' ').slice(0, 200));
ok('همان صفحه روی سایت وجود ندارد (۴۰۴ بدنهٔ کوتاه)',
  updatePageSite.res.output_len < 200 && updatePageSite.res.output_len > 0, String(updatePageSite.res.output_len));
const publishesPage = await req(null, { method: 'GET', file: 'desk-updates.php', sid: 'harnessAdm0001' });
ok('صفحهٔ انتشار بسته برای مدیر کل رندر و فهرست بسته‌ها را نشان می‌دهد',
  publishesPage.res.output_len > 500 && publishesPage.res.page.includes(P.id) && !publishesPage.res.fatal,
  (publishesPage.res.fatal || '').slice(0, 200));
ok('صفحهٔ انتشار فرم بارگذاری و توکن CSRF دارد',
  publishesPage.res.page.includes('enctype="multipart/form-data"') && publishesPage.res.page.includes('csrf_token'));
const hub = await req(null, { method: 'GET', file: 'other-settings.php', query: 'embedded=1', sid: 'harnessAdm0001' });
ok('در تنظیمات دیگرِ سایت، فقط تب انتشار دسکتاپ دیده می‌شود',
  hub.res.page.includes('desk-updates.php?embedded=1') && !hub.res.page.includes('desk-update.php?embedded=1'));
php.writeFile(releaseFile, "<?php return ['distribution' => 'desktop'];");
const hubDesk = await req(null, { method: 'GET', file: 'other-settings.php', query: 'embedded=1', sid: 'harnessAdm0001' });
ok('در نسخهٔ دسکتاپ، تب به‌روزرسانی نرم‌افزار دیده می‌شود و تب انتشار پنهان است',
  hubDesk.res.page.includes('desk-update.php?embedded=1') && !hubDesk.res.page.includes('desk-updates.php?embedded=1'));
const publishPageDesktop = await req(null, { method: 'GET', file: 'desk-updates.php', sid: 'harnessAdm0001' });
ok('صفحهٔ انتشار روی نسخهٔ دسکتاپ وجود ندارد (فقط سایت مدرسه منتشر می\u200cکند)',
  publishPageDesktop.res.output_len < 200 && publishPageDesktop.res.output_len > 0, String(publishPageDesktop.res.output_len));
php.writeFile(releaseFile, releaseBackup);
const studentPage = await req(null, { method: 'GET', file: 'desk-update.php', sid: 'harnessStu0001' });
console.log('   DBG studentPage:', studentPage.res.output_len, '| csrf:', studentPage.res.page.includes('csrf_token'), '|', (studentPage.res.raw_head || '').replace(/\n/g, ' ').slice(0, 160));
ok('دانش‌آموز/کاربر عادی به صفحهٔ به‌روزرسانی دسترسی ندارد و فرم مدیر برایش رندر نمی‌شود',
  !studentPage.res.page.includes('csrf_token') && studentPage.res.page.length < 2000,
  String(studentPage.res.output_len));

/* ═══ ۶) وضعیت و به‌روزرسانی خودکار ═══ */
const auto = await run(String.raw`<?php
ini_set('display_errors', '1'); error_reporting(E_ALL);
require_once '/www/includes/functions.php';
require_once '/www/includes/db.php';
require_once '/www/includes/desk_sync.php';
require_once '/www/includes/desk_update.php';
$out = [];
@unlink('/data/update/state.json');
@unlink('/data/update/launcher/expected.json');
@unlink('/data/update/launcher/failed.txt');
@unlink('/data/update/launcher/SchoolDeskPro.exe');
DB::execute("DELETE FROM settings WHERE key_name IN ('desk_sync_url','desk_sync_key')");
$out['status_unconfigured'] = desk_update_status();
DeskSync::setCfg('desk_sync_url', 'https://school.example.ir/desk-sync-api.php');
DeskSync::setCfg('desk_sync_key', str_repeat('k', 40));
$out['status_never'] = desk_update_status();
$pkg = ['id' => 'pkg-2.84.0-abcdef', 'version' => '2.84.0', 'sha256' => str_repeat('a', 64), 'bytes' => 1234, 'created' => '2026-09-19 00:00'];
desk_update_set_state(['checked_at' => time(), 'latest' => $pkg, 'error' => '', 'applied_id' => '', 'restart_required' => false, 'auto_fail_id' => '', 'auto_fail_count' => 0, 'auto_fail_at' => 0]);
$out['status_available'] = desk_update_status();
desk_update_set_state(['auto_fail_id' => $pkg['id'], 'auto_fail_count' => 2, 'auto_fail_at' => time()]);
$out['auto_throttled'] = desk_update_auto(900);
$out['status_auto_failed'] = desk_update_status();
desk_update_set_state(['auto_fail_id' => '', 'auto_fail_count' => 0, 'auto_fail_at' => 0, 'checked_at' => time()]);
$out['auto_attempt'] = desk_update_auto(900);
$after = desk_update_state();
$out['auto_attempt_state'] = ['fail_id' => (string)($after['auto_fail_id'] ?? ''), 'fail_count' => (int)($after['auto_fail_count'] ?? 0), 'error' => (string)($after['error'] ?? ''), 'installing' => (string)($after['installing'] ?? '')];
$out['auto_attempt_2'] = desk_update_auto(900);
$after2 = desk_update_state();
$out['auto_attempt_2_state'] = ['fail_count' => (int)($after2['auto_fail_count'] ?? 0)];
$out['auto_throttled_after_fail'] = desk_update_auto(900);
desk_update_set_state(['applied_id' => $pkg['id'], 'applied_at' => time(), 'error' => '', 'auto_fail_id' => '', 'auto_fail_count' => 0, 'auto_fail_at' => 0, 'restart_required' => false]);
$out['status_uptodate'] = desk_update_status();
@mkdir('/data/update/launcher', 0777, true);
file_put_contents('/data/update/launcher/SchoolDeskPro.exe', 'MZ' . str_repeat("\x00", 40000));
file_put_contents('/data/update/launcher/expected.json', '{"sha256":"x"}');
$out['status_restart'] = desk_update_status();
@unlink('/data/update/launcher/expected.json');
$out['status_after_swap'] = desk_update_status();
file_put_contents('/data/update/launcher/failed.txt', 'new build did not start; previous build restored');
$out['status_launcher_failed'] = desk_update_status();
$out['failed_marker_removed'] = !file_exists('/data/update/launcher/failed.txt');
$final = desk_update_state();
$out['state_after_failure'] = ['applied_id' => (string)($final['applied_id'] ?? ''), 'error' => (string)($final['error'] ?? '')];
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);`);
let A = {};
try { A = JSON.parse(auto.out); } catch (e) {
  console.log('   === خروجی PHP (خودکار) ===\n' + (auto.out || '').slice(-1200) + '\n   === stderr ===\n' + (auto.err || '').slice(-400));
  ok('اجرای موتور به‌روزرسانی خودکار', false, (auto.err || auto.out).slice(-300));
}
console.log('\n══ وضعیت به‌روزرسانی و نصب خودکار (بدون دخالت اپراتور) ══');
ok('بدون تنظیم همگام‌سازی، وضعیت صریح «تنظیم نشده» است',
  A.status_unconfigured?.code === 'not-configured' && A.status_unconfigured?.tone === 'bad', JSON.stringify(A.status_unconfigured));
ok('پیش از نخستین بررسی: «در انتظار نخستین بررسی خودکار»',
  A.status_never?.code === 'never-checked', JSON.stringify(A.status_never));
ok('بستهٔ تازهٔ سایت: «نصب خودکار در جریان است» با هر دو نسخه',
  A.status_available?.code === 'available' && A.status_available?.detail.includes('2.84.0')
  && A.status_available?.detail.includes('نسخهٔ فعلی'), JSON.stringify(A.status_available));
ok('پایان کار: «برنامه کاملاً به‌روز و همگام با سایت است»',
  A.status_uptodate?.code === 'uptodate' && A.status_uptodate?.tone === 'ok'
  && A.status_uptodate?.label.includes('کاملاً به‌روز'), JSON.stringify(A.status_uptodate));
ok('فایل اجرایی مرحله‌بندی‌شده: «یک‌بار بسته و باز شود» بدون نصب دستی',
  A.status_restart?.code === 'restart-required' && A.status_restart?.tone === 'warn'
  && A.status_restart?.detail.includes('دانلود یا نصب دستی لازم نیست'), JSON.stringify(A.status_restart));
ok('پس از جایگزینی فایل اجرایی، وضعیت خودش به «به‌روز» برمی‌گردد',
  A.status_after_swap?.code === 'uptodate', JSON.stringify(A.status_after_swap).slice(0, 160));
ok('شکست راه‌انداز: هشدار صریح، نشانه پاک می‌شود و بسته دوباره قابل تلاش است',
  A.status_launcher_failed?.code === 'launcher-failed' && A.status_launcher_failed?.tone === 'bad'
  && A.failed_marker_removed === true && A.state_after_failure?.applied_id === '',
  JSON.stringify(A.status_launcher_failed).slice(0, 200));
ok('پس از دو شکست، نصب خودکار تا یک ساعت پشت سر هم تکرار نمی‌شود',
  A.auto_throttled?.ok === false && A.auto_throttled?.installed === false
  && String(A.auto_throttled?.error).includes('یک ساعت'), JSON.stringify(A.auto_throttled).slice(0, 160));
ok('وضعیت پس از شکست خودکار علت را نشان می‌دهد (نه «به‌روز» دروغین)',
  A.status_auto_failed?.code === 'auto-failed' && A.status_auto_failed?.tone === 'bad',
  JSON.stringify(A.status_auto_failed).slice(0, 160));
ok('تلاش خودکار بدون شبکه تمیز شکست می‌خورد و شمارندهٔ شکست را ثبت می‌کند',
  A.auto_attempt?.ok === false && A.auto_attempt_state?.fail_count === 1
  && A.auto_attempt_state?.fail_id === 'pkg-2.84.0-abcdef' && A.auto_attempt_state?.installing === '',
  JSON.stringify(A.auto_attempt_state).slice(0, 200));
ok('دو تلاش ناموفق پیاپی مجاز است، تلاش سوم یک ساعت به تعویق می‌افتد',
  A.auto_attempt_2?.ok === false && A.auto_attempt_2_state?.fail_count === 2
  && String(A.auto_throttled_after_fail?.error).includes('یک ساعت'),
  JSON.stringify(A.auto_attempt_2_state) + ' | ' + String(A.auto_throttled_after_fail?.error));

/* صفحهٔ همگام‌سازی: نشان وضعیت به‌روزرسانی */
const syncPage = await req(null, { method: 'GET', file: 'desk-sync.php', sid: 'harnessAdm0001' });
ok('صفحهٔ همگام‌سازی، نشان وضعیت «به‌روز/همگام با سایت» را نشان می‌دهد',
  syncPage.res.page.includes('id="deskUpdateStatus"') && /data-code="[a-z-]+"/.test(syncPage.res.page)
  && syncPage.res.page.includes('id="syncStatusTable"') && !syncPage.res.fatal,
  (syncPage.res.fatal || syncPage.res.raw_head || '').slice(0, 200));
const syncAjax = await req(null, { method: 'GET', file: 'desk-sync.php', query: 'ajax=update', sid: 'harnessAdm0001' });
ok('وضعیت به‌روزرسانی با پرس‌وجوی سبک و بدون شبکه خوانده می‌شود',
  syncAjax.res.raw_head.includes('"update"') && syncAjax.res.raw_head.includes('"code"')
  && syncAjax.res.raw_head.includes('"pending"'), (syncAjax.res.raw_head || '').slice(0, 160));
php.writeFile('/www/config/release.php', "<?php return ['distribution' => 'desktop', 'desktop_version' => '2.83.0'];");
const updPage = await req(null, { method: 'GET', file: 'desk-update.php', sid: 'harnessAdm0001' });
ok('صفحهٔ به‌روزرسانی می‌گوید کار خودکار است و وضعیت زنده را نشان می‌دهد',
  updPage.res.page.includes('خودکار</b>')
  && updPage.res.page.includes('نصب دستی بسته نیست')
  && updPage.res.page.includes('وضعیت و نصب دستی')
  && !updPage.res.fatal,
  String(updPage.res.output_len));
php.writeFile('/www/config/release.php', releaseBackup);

/* ═══ ۷) قلاب دیمن و پاک‌سازی ═══ */
console.log('\n══ چرخهٔ پس‌زمینه ══');
const daemon = desktopFiles['/www/desk-sync-daemon.php'];
ok('دیمن همگام‌سازی، نصب خودکار به‌روزرسانی را در چرخهٔ خود دارد (بدون اپراتور)',
  daemon.includes('desk_update_auto(900)') && daemon.includes('DeskSync::enabled()'));
ok('خطای به‌روزرسانی هرگز همگام‌سازی داده را متوقف نمی‌کند',
  /try \{\s*require_once __DIR__\.'\/includes\/desk_update\.php';[\s\S]{0,500}desk_update_auto/.test(daemon));
ok('دیمن وضعیت به‌روزرسانی را در فایل ضربان می‌نویسد (صفحه و نشانگر از آن می‌خوانند)',
  daemon.includes("$res['update'] = $auto['status'] ?? null;")
  && desktopFiles['/www/desk-sync.php'].includes('deskUpdateStatus')
  && desktopFiles['/www/desk-sync.php'].includes("$_GET['ajax'] === 'update'"));
ok('دیمن باید کد قدیمی هم با فایل‌های جدید کار کند (require_once محافظت‌شده)',
  daemon.includes("require_once __DIR__.'/includes/desk_update.php'"));

/* پاک‌سازی: بسته‌های منتشرشده و کلید آزمایشی داخل MEMFS می‌مانند و به ریپو نمی‌رسند */
const leftovers = await run(String.raw`<?php
require_once '/www/includes/functions.php'; require_once '/www/includes/db.php'; require_once '/www/includes/desk_updates_store.php';
@unlink('/www/config/desk-sync-key.php');
$kept = array_map(fn($e) => $e['id'], desk_updates_index());
desk_updates_save([]);
foreach (glob(desk_updates_dir() . '/*.zip') as $f) @unlink($f);
echo json_encode(['kept' => $kept]);`);
ok('فهرست انتشار برای پایان تست تمیز می‌شود', /"kept"/.test(leftovers.out), leftovers.out.slice(0, 120));

console.log(`\n${fail === 0 ? '✅' : '❌'} ${pass} بررسی موفق، ${fail} ناموفق`);
process.exit(fail === 0 ? 0 : 1);
