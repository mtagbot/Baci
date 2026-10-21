/**
 * test-desk-sync-status.mjs — سوئیت «وضعیت همگام‌سازی دسکتاپ» (V4.183.0)
 *
 * سنجش واقعی، نه جست‌وجوی رشته:
 *   ۱) کاهش‌دهندهٔ خالص وضعیت (includes/desk_connection.php) با PHP 8.3 (WASM)
 *      روی ۱۴ حالت مختلف اجرا می‌شود، از جمله حالت گزارش‌شدهٔ کارفرما:
 *      «قلب تپندهٔ کارگر پس‌زمینه بدون server_verified ولی تبادل موفق چند
 *      لحظه پیش» باید «همگام با سرور» بدهد، نه «در انتظار وضعیت همگام‌سازی».
 *   ۲) خودِ سرویس وضعیت (desk-connection-status.php) با همان سناریو صدا زده
 *      می‌شود و JSON واقعی‌اش بررسی می‌شود.
 *   ۳) API به‌روزرسانی (desk-update-api.php) با کلید نصب‌شدهٔ واقعی — همان
 *      کلیدی که همگام‌سازی قبول می‌کند — احراز هویت می‌کند: قبل از این اصلاح،
 *      دستگاهی که همگام‌سازی‌اش سالم بود در بنر به‌روزرسانی «invalid key»
 *      می‌گرفت چون کلید فقط از config/desk-sync-key.php خوانده می‌شد.
 *
 * هیچ شبکه‌ای لازم نیست: تمام مسیرها محلی و قطعی‌اند.
 */
import { run, php, req, loginAdmin } from './harness/lib.mjs';
import { REPO } from './harness/site.mjs';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

let pass = 0, fail = 0;
const ok = (name, cond, detail = '') => cond ? (pass++, console.log(`  ✅ ${name}`))
                                              : (fail++, console.log(`  ❌ ${name}${detail ? '  → ' + detail : ''}`));
const read = rel => readFileSync(join(REPO, rel), 'utf8');

/* ═══ ۱) فایل‌های واقعی این اصلاح داخل /www ═══ */
const FILES = {
  '/www/includes/desk_connection.php': read('update-v4.152.0/includes/desk_connection.php'),
  '/www/includes/desk_sync.php': read('update-v4.152.0/includes/desk_sync.php'),
  '/www/desk-connection-status.php': read('update-v4.152.0/desk-connection-status.php'),
  '/www/desk-update-api.php': read('update-v4.152.0/desk-update-api.php'),
  '/www/includes/desk_update.php': read('desktop-app-v2/patch/includes-desk_update.php'),
  '/www/desk-sync-daemon.php': read('desktop-app-v2/patch/www-desk-sync-daemon.php'),
};
for (const [p, text] of Object.entries(FILES)) php.writeFile(p, text);
console.log('>>> فایل‌های همگام‌سازی/به‌روزرسانی داخل /www نوشته شد');

/* ═══ ۲) بررسی نحوی PHP ═══ */
php.writeFile('/harness/dss_lint.json', JSON.stringify(FILES));
const lint = await run(String.raw`<?php
$bad = []; $list = json_decode(file_get_contents('/harness/dss_lint.json'), true);
foreach ($list as $name => $src) {
    try { token_get_all($src, TOKEN_PARSE); }
    catch (Throwable $e) { $bad[] = $name . ': ' . $e->getMessage(); }
}
echo json_encode(['bad' => $bad]);`);
const lintBad = JSON.parse(lint.out).bad;
ok('بررسی نحوی همهٔ فایل‌های این اصلاح', lintBad.length === 0, lintBad.join(' | '));

/* ═══ ۳) کاهش‌دهندهٔ وضعیت: ماتریس حالت‌ها ═══ */
const matrix = await run(String.raw`<?php
require_once '/www/includes/desk_connection.php';
$NOW = 1000000;
$fresh = function (array $h) use ($NOW) { return $h + ['ts' => $NOW - 2]; };
$case = function ($name, $hb, $verifiedAt, $lastOk, $enabled = true, $pending = 0, $snapshot = true) use ($NOW) {
    $ev = desk_connection_verified_heartbeat($hb, $verifiedAt, $lastOk, $NOW);
    $out = desk_connection_snapshot($enabled, $ev, $pending, $lastOk, $snapshot, $NOW, false);
    return ['case' => $name, 'state' => $out['state'], 'server' => $out['server'],
            'keys' => array_keys($out), 'checked_at' => $out['checked_at'], 'retry_in' => $out['retry_in'],
            'proof' => $ev['proof'] ?? '', 'verified' => $ev['server_verified'] ?? null];
};
$r = [];
/* ۱) کارگر قدیمی: قلب تپندهٔ idel بدون server_verified، ولی تبادل موفق ۵ ثانیه پیش */
$r[] = $case('old-worker-idle-recent-ok', $fresh(['ok' => true, 'skipped' => 'idle', 'fails' => 0, 'error' => '']), 0, $NOW - 5);
/* ۲) کارگر نو: خودش تأیید کرده */
$r[] = $case('worker-verified', $fresh(['ok' => true, 'skipped' => 'idle', 'server_verified' => true]), $NOW - 5, $NOW - 5);
/* ۳) قفل موقت کارگر دیگر + تبادل موفق اخیر → نباید «در انتظار» بدهد */
$r[] = $case('locked-with-recent-proof', $fresh(['ok' => true, 'skipped' => 'locked', 'server_verified' => false]), $NOW - 5, $NOW - 5);
/* ۴) قطع اینترنت: خطا هرگز پوشانده نمی‌شود */
$r[] = $case('offline-not-masked', $fresh(['ok' => true, 'skipped' => 'offline', 'fails' => 3, 'retry_in' => 20]), $NOW - 5, $NOW - 5);
/* ۵) خطای واقعی موتور */
$r[] = $case('engine-error-not-masked', $fresh(['ok' => false, 'error' => 'invalid key']), $NOW - 5, $NOW - 5);
/* ۶) probe_failed (کلید رد شده) با تبادل موفق اخیر → خطا می‌ماند */
$r[] = $case('probe-failed-not-masked', $fresh(['ok' => true, 'skipped' => 'idle', 'probe_failed' => true]), $NOW - 5, $NOW - 5);
/* ۷) در حال همگام‌سازی */
$r[] = $case('syncing', $fresh(['ok' => true, 'phase' => 'syncing']), $NOW - 5, $NOW - 5);
/* ۸) اثبات کهنه (بیش از ۹۰ ثانیه) → همان سختگیری قبلی */
/* ۷.۵) کارگر زندهٔ idle با اثبات ۳۰ دقیقه پیش — ماشین مدرسه بیکار است، خراب نیست */
$r[] = $case('idle-worker-old-proof', $fresh(['ok' => true, 'skipped' => 'idle', 'fails' => 0, 'error' => '']), 0, $NOW - 1800);
/* ۸) قلب تپندهٔ کهنه + اثبات کهنه (بیش از ۹۰ ثانیه) → همان سخت‌گیری قبلی */
$r[] = $case('stale-heartbeat-old-proof', ['ok' => true, 'skipped' => 'idle', 'ts' => $NOW - 400], $NOW - 400, $NOW - 400);
/* ۸.۵) کارگر زنده اما خطای واقعی و اثبات کهنه → error */
$r[] = $case('idle-worker-old-proof-with-error', $fresh(['ok' => false, 'error' => 'خطای جدول‌ها']), 0, $NOW - 1800);
/* ۹) بدون هیچ قلبی */
$r[] = $case('no-heartbeat-no-proof', null, 0, 0);
/* ۱۰) همگام‌سازی خاموش */
$r[] = $case('disabled', $fresh(['ok' => true, 'skipped' => 'disabled']), $NOW - 5, $NOW - 5, false);
/* ۱۱) صف ارسال‌نشده با سرور تأییدشده */
$r[] = $case('queued', $fresh(['ok' => true, 'skipped' => 'idle', 'server_verified' => true]), $NOW - 5, $NOW - 5, true, 4);
/* ۱۲) اسنپ‌شات اولیه هنوز کامل نیست */
$r[] = $case('no-snapshot-yet', $fresh(['ok' => true, 'skipped' => 'idle', 'server_verified' => true]), $NOW - 5, $NOW - 5, true, 0, false);
echo json_encode($r, JSON_UNESCAPED_UNICODE);`);
let M = [];
try { M = JSON.parse(matrix.out); } catch (e) { console.log('   PHP: ' + matrix.out.slice(0, 400)); }
const at = n => (M.find(x => x.case === n) || {});
ok('ماتریس وضعیت با ۱۴ حالت اجرا شد', M.length === 14, `دریافتی: ${M.length}`);

console.log('\n══ حالت‌های سنجیده‌شده ══');
for (const c of M) console.log(`   ${c.case} → ${c.state} (server=${c.server}, proof=${c.proof || '—'})`);

/* قلب تپندهٔ کارگر قدیمی + تبادل موفق چند لحظه پیش ⇒ «همگام با سرور» */
ok('۱) قلب تپندهٔ قدیمی + آخرین تبادل موفق → synced/reachable',
  at('old-worker-idle-recent-ok').state === 'synced' && at('old-worker-idle-recent-ok').server === 'reachable',
  JSON.stringify(at('old-worker-idle-recent-ok')));
ok('۲) گزارش صریح خود کارگر (server_verified) → synced',
  at('worker-verified').state === 'synced');
ok('۳) قفل موقت کارگر دیگر، اثبات موفق اخیر → synced (نه در انتظار)',
  at('locked-with-recent-proof').state === 'synced', JSON.stringify(at('locked-with-recent-proof')));
ok('۴) قطع اینترنت → retrying و سرور unreachable (پوشانده نمی‌شود)',
  at('offline-not-masked').state === 'retrying' && at('offline-not-masked').server === 'unreachable');
ok('۵) خطای واقعی موتور → error (پوشانده نمی‌شود)', at('engine-error-not-masked').state === 'error');
ok('۶) رد شدن probe → error حتی با تبادل موفق اخیر', at('probe-failed-not-masked').state === 'error');
ok('۷) حین کار → syncing', at('syncing').state === 'syncing');
ok('۷.۵) کارگر زندهٔ بیکار + اثبات ۳۰ دقیقه پیش → synced (نه «در انتظار»)',
  at('idle-worker-old-proof').state === 'synced' && at('idle-worker-old-proof').server === 'reachable',
  JSON.stringify(at('idle-worker-old-proof')));
ok('۸) قلب تپندهٔ کهنه + اثبات کهنه → waiting (سخت‌گیری حفظ شده)',
  at('stale-heartbeat-old-proof').state === 'waiting' && at('stale-heartbeat-old-proof').server === 'unknown');
ok('۸.۵) خطای واقعی روی کارگر زنده → error (پوشانده نمی‌شود)',
  at('idle-worker-old-proof-with-error').state === 'error');
ok('۹) بدون قلب و بدون اثبات → waiting', at('no-heartbeat-no-proof').state === 'waiting');
ok('۱۰) همگام‌سازی خاموش → disabled', at('disabled').state === 'disabled');
ok('۱۱) صف ارسال‌نشده با سرور تأییدشده → queued',
  at('queued').state === 'queued', JSON.stringify(at('queued')));
ok('۱۲) اسنپ‌شات ناتمام (سختگیری حفظ شده) → waiting', at('no-snapshot-yet').state === 'waiting');
ok('خروجی عمومی هیچ کلید/نشانی‌ای افشا نمی‌کند',
  M.every(c => JSON.stringify(c.keys) === JSON.stringify(['state', 'server', 'pending', 'last_success', 'checked_at', 'retry_in', 'warning'])),
  JSON.stringify(M[0] && M[0].keys));

/* ═══ ۴) سرویس وضعیت: JSON واقعی صفحه (مسیر همان پنل فوتر) ═══ */
console.log('\n══ سرویس وضعیت (desk-connection-status.php) ══');
const releaseFile = '/www/config/release.php';
const releaseBackup = php.readFileAsText(releaseFile);
php.writeFile(releaseFile, "<?php return ['distribution' => 'desktop'];");
const prep = await run(String.raw`<?php
require_once '/www/includes/functions.php'; require_once '/www/includes/db.php';
require_once '/www/includes/desk_sync.php';
DeskSync::setCfg('desk_sync_enabled', '1');
DeskSync::setCfg('desk_sync_url', 'https://school.example/desk-sync-api.php');
DeskSync::setCfg('desk_sync_key', 'SDP-' . str_repeat('a', 48));
DeskSync::setCfg('desk_sync_snapshot_done', '1');
DeskSync::setCfg('desk_sync_err', '');
$now = time();
DeskSync::setCfg('desk_sync_last_ok', (string)$now);
DeskSync::setCfg('desk_sync_verified_at', (string)$now);
/* کارگر قدیمی: قلب تپندهٔ تازهٔ idle بدون server_verified */
file_put_contents('/data/sync-heartbeat.json', json_encode(['phase' => 'settled', 'ts' => $now, 'ok' => true, 'skipped' => 'idle', 'fails' => 0, 'error' => '', 'pending' => 0], JSON_UNESCAPED_UNICODE));
echo 'PREP_OK';`);
ok('آماده‌سازی سرویس وضعیت', prep.out.includes('PREP_OK'), prep.out.slice(0, 200));
await loginAdmin();
const panel = await req(null, { file: 'desk-connection-status.php', sid: 'harnessAdm0001' });
let P = {}; try { P = JSON.parse(panel.res.raw_head.trim()); } catch (e) {}
ok('پنل با قلب تپندهٔ کارگر قدیمی، «در انتظار وضعیت همگام‌سازی» نمی‌دهد',
  (P.state === 'synced' || P.state === 'queued') && P.server === 'reachable',
  panel.res.raw_head.slice(0, 200));
ok('وضعیت دقیقاً بر اساس صف محلی گزارش می‌شود (synced یا queued)',
  P.state === (P.pending > 0 ? 'queued' : 'synced'), `state=${P.state} pending=${P.pending}`);
ok('زمان آخرین همگام‌سازی موفق در پاسخ هست', (P.last_success || 0) > 0);

/* همان سرویس با شکست واقعی: نباید سبز شود */
const failPrep = await run(String.raw`<?php
require_once '/www/includes/functions.php'; require_once '/www/includes/db.php';
require_once '/www/includes/desk_sync.php';
$now = time();
DeskSync::setCfg('desk_sync_last_ok', (string)$now);
DeskSync::setCfg('desk_sync_verified_at', (string)$now);
file_put_contents('/data/sync-heartbeat.json', json_encode(['phase' => 'settled', 'ts' => $now, 'ok' => true, 'skipped' => 'offline', 'fails' => 4, 'error' => 'اتصال به سرور برقرار نشد', 'retry_in' => 20], JSON_UNESCAPED_UNICODE));
echo 'PREP_FAIL';`);
ok('آماده‌سازی حالت شکست', failPrep.out.includes('PREP_FAIL'));
const panelFail = await req(null, { file: 'desk-connection-status.php' });
let PF = {}; try { PF = JSON.parse(panelFail.res.raw_head.trim()); } catch (e) {}
ok('قطعی واقعی اینترنت در پنل «در انتظار اتصال» می‌شود، نه «همگام»',
  PF.state === 'retrying' && PF.server === 'unreachable', panelFail.res.raw_head.slice(0, 200));

/* دانش‌آموز نباید شمارش تغییرات را ببیند */
await run(`<?php @mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess'); session_name('BACI_TEST'); session_id('harnessStu0001'); session_start();
$_SESSION['student_id']=1; unset($_SESSION['admin_id']); unset($_SESSION['teacher_id']); echo 'STU';`);
const panelStu = await req(null, { file: 'desk-connection-status.php', sid: 'harnessStu0001' });
let PS = {}; try { PS = JSON.parse(panelStu.res.raw_head.trim()); } catch (e) {}
ok('دانش‌آموز وضعیت را می‌بیند ولی شمارش رکوردهای مدرسه را نه',
  typeof PS.state === 'string' && PS.pending === null, panelStu.res.raw_head.slice(0, 200));
php.writeFile(releaseFile, releaseBackup);

/* ═══ ۵) کلید به‌روزرسانی = کلید همگام‌سازی ═══ */
console.log('\n══ API به‌روزرسانی: پذیرش همان کلید همگام‌سازی ══');
const syncApiFile = '/www/desk-sync-api.php';
const syncApiBackup = php.readFileAsText(syncApiFile);
const LEGACY = 'SDP-' + 'b'.repeat(48);
const PRIVATE_KEY = 'c'.repeat(64);
const setKeys = await run(`<?php
$legacy = ${JSON.stringify(LEGACY)};
file_put_contents('/www/desk-sync-api.php', "<?php\\n// legacy paired install: key lives in this file\\ndefine('DESK_SYNC_KEY', '" . $legacy . "');\\n");
@unlink('/www/config/desk-sync-key.php');
echo 'KEYS_SET';`);
ok('کلید قدیمی داخل desk-sync-api.php و بدون فایل کلید خصوصی', setKeys.out.includes('KEYS_SET'));

const legacyOk = await req(null, { method: 'POST', file: 'desk-update-api.php',
  post: { payload: JSON.stringify({ action: 'latest', key: LEGACY }) } });
ok('🟢 کلید همگام‌سازیِ داخل desk-sync-api.php پذیرفته می‌شود (باگ «invalid key» رفع شد)',
  legacyOk.res.raw_head.includes('"ok":true'), legacyOk.res.raw_head.slice(0, 200));
ok('کلید در پاسخ تکرار نمی‌شود', !legacyOk.res.raw_head.includes(LEGACY));

const wrongKey = await req(null, { method: 'POST', file: 'desk-update-api.php',
  post: { payload: JSON.stringify({ action: 'latest', key: 'x'.repeat(40) }) } });
ok('کلید نادرست رد می‌شود و پیام دقیق فارسی می‌گیرد (نه «invalid key»)',
  wrongKey.res.raw_head.includes('"ok":false') && wrongKey.res.raw_head.includes('key_mismatch')
  && !wrongKey.res.raw_head.includes('invalid key') && !wrongKey.res.raw_head.includes(LEGACY),
  wrongKey.res.raw_head.slice(0, 220));

const withPrivate = await run(`<?php
file_put_contents('/www/config/desk-sync-key.php', "<?php return " . var_export(str_repeat('c', 64), true) . ";\\n");
echo 'PRIVATE_WRITTEN';`);
ok('فایل کلید خصوصی سایت نوشته شد', withPrivate.out.includes('PRIVATE_WRITTEN'));
const privOk = await req(null, { method: 'POST', file: 'desk-update-api.php',
  post: { payload: JSON.stringify({ action: 'latest', key: PRIVATE_KEY }) } });
ok('کلید ۶۴رقمی فایل خصوصی هم (مثل قبل) پذیرفته می‌شود', privOk.res.raw_head.includes('"ok":true'), privOk.res.raw_head.slice(0, 160));
const bothOk = await req(null, { method: 'POST', file: 'desk-update-api.php',
  post: { payload: JSON.stringify({ action: 'latest', key: LEGACY }) } });
ok('هر دو منبع کلید کنار هم کار می‌کنند (سازگاری نصب‌های مختلف)',
  bothOk.res.raw_head.includes('"ok":true'), bothOk.res.raw_head.slice(0, 160));

const noKeys = await run(`<?php @unlink('/www/config/desk-sync-key.php');
file_put_contents('/www/desk-sync-api.php', "<?php\\nrequire_once __DIR__.'/config/desk-sync-key.php';\\n");
echo 'NO_KEYS';`);
ok('سایت بدون هیچ کلیدی آمادهٔ آزمایش شد', noKeys.out.includes('NO_KEYS'));
const missing = await req(null, { method: 'POST', file: 'desk-update-api.php',
  post: { payload: JSON.stringify({ action: 'latest', key: LEGACY }) } });
ok('سایت بدون کلید تنظیم‌شده، پیام «کلید تنظیم نشده» می‌دهد (fail-closed)',
  missing.res.raw_head.includes('"ok":false') && missing.res.raw_head.includes('key_missing'),
  missing.res.raw_head.slice(0, 200));
php.writeFile(syncApiFile, syncApiBackup);

/* ═══ ۶) اتصال زنجیرهٔ اثبات در سورس ═══ */
console.log('\n══ زنجیرهٔ ثبت اثبات (سورس) ══');
const engine = FILES['/www/includes/desk_sync.php'];
const status = FILES['/www/desk-connection-status.php'];
const reducer = FILES['/www/includes/desk_connection.php'];
const upd = FILES['/www/includes/desk_update.php'];
const daemon = FILES['/www/desk-sync-daemon.php'];
ok('موتور: پس از موفقیت کامل، اثبات تازه ثبت می‌شود',
  /setCfg\('desk_sync_last_ok'[\s\S]{0,900}setCfg\('desk_sync_verified_at'/.test(engine));
ok('موتور: پس از probe موفق هم اثبات ثبت می‌شود',
  /setCfg\('desk_sync_ping_last'[\s\S]{0,1200}setCfg\('desk_sync_verified_at'/.test(engine));
ok('موتور: نتیجهٔ موفق، پرچم صریح server_verified را برمی‌گرداند',
  /'pulled' => \$pulled, 'server_verified' => true/.test(engine));
ok('سرویس وضعیت: قلب تپنده را با اثبات محلی کامل می‌کند',
  status.includes('desk_connection_verified_heartbeat'));
ok('کاهش‌دهنده همچنان سخت‌گیر است (پرچم لازم، بدون پذیرش idle تنها)',
  reducer.includes("if(($h['server_verified']??false)!==true)return $out;")
  && reducer.includes("if($skip!==''&&$skip!=='idle')return $out;"));
ok('کارگر پس‌زمینه فقط با تأیید واقعی سرور، تحویل اعلان را انجام می‌دهد',
  daemon.includes("!empty($res['server_verified'])"));
ok('بنر به‌روزرسانی، تفاوت کلید را دقیق و فارسی می‌گوید (نه invalid key)',
  upd.includes("'code' => 'key_mismatch'") && upd.includes('key-mismatch') && upd.includes('کلید به‌روزرسانی با کلید همگام‌سازی سایت یکسان نیست'));

/* ═══ ۷) آینهٔ دسکتاپ: همان موتور، بدون بازنویسی مسیر سایت ═══ */
console.log('\n══ موتور دسکتاپ ══');
const deskEngine = read('desktop-app-v2/patch/includes-desk-sync.php');
php.writeFile('/harness/desk_engine.txt', deskEngine);
const lint2 = await run(String.raw`<?php
$bad = ''; try { token_get_all(file_get_contents('/harness/desk_engine.txt'), TOKEN_PARSE); } catch (Throwable $e) { $bad = $e->getMessage(); }
echo $bad === '' ? 'OK' : $bad;`);
ok('بررسی نحوی موتور همگام‌سازی دسکتاپ', lint2.out.trim() === 'OK', lint2.out.slice(0, 200));
ok('موتور دسکتاپ هم پرچم صریح و لحظهٔ تأیید را دارد',
  /'server_verified' => false/.test(deskEngine) && (deskEngine.match(/desk_sync_verified_at/g) || []).length >= 3,
  String((deskEngine.match(/desk_sync_verified_at/g) || []).length));
ok('موتور دسکتاپ پس از probe موفق هم اثبات ثبت می‌کند',
  /setCfg\('desk_sync_ping_last'[\s\S]{0,900}setCfg\('desk_sync_verified_at'/.test(deskEngine));
ok('موتور دسکتاپ URL تنظیم‌شده را حفظ می‌کند و فقط پس از HTTP 403 به endpoint جفت‌شده می‌رود',
  !/\$url\s*=\s*preg_replace[^;]*class-exam-sync-api/.test(deskEngine)
  && deskEngine.includes('alternateSyncEndpoint') && deskEngine.includes('$code === 403'));
ok('نسخهٔ سایت نیز fallback محدود به 403 را نگه می‌دارد',
  engine.includes('alternateSyncEndpoint') && engine.includes('$code === 403'));
ok('خطای 403 علت‌یابی‌شده و بدون افشای بدنهٔ خام گزارش می‌شود',
  deskEngine.includes('diagnose403Response') && deskEngine.includes('Cloudflare')
  && deskEngine.includes('کلید نادرست پاسخ HTTP 200 می‌دهد') && deskEngine.includes('access/error/WAF log'));
const rel = read('scripts/release-v4.183.0.py');
ok('بستهٔ دسکتاپ V4.183.0 همین نسخهٔ موتور را بسته‌بندی می‌کند',
  /\('includes\/desk_sync\.php', 'desktop-app-v2\/patch\/includes-desk-sync\.php'/.test(rel));
ok('بستهٔ سایت نسخهٔ سایت را می‌فرستد و هر دو نشانهٔ تازه دارند',
  /\('includes\/desk_sync\.php', 'update-v4\.152\.0\/includes\/desk_sync\.php'/.test(rel)
  && (rel.match(/desk_sync_verified_at/g) || []).length >= 2);

console.log(`\n──── نتیجه: ${pass} ✅ / ${fail} ❌ ────`);
process.exit(fail ? 1 : 0);
