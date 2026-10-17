/**
 * test-bot-soroush.mjs — سروش پلاس به‌عنوان پیام‌رسان سوم ربات (V4.185.0)
 *
 * چرا این سوئیت وجود دارد: تا v4.184.0 کل سیستم ربات دو مقداری بود
 * («تلگرام وگرنه بله»). افزودن یک پلتفرم سوم بدون آزمون یعنی هر ternary
 * جاافتاده، پیام‌های سروش را روی بله می‌فرستد یا برعکس — بی‌صدا و بی‌خطا.
 * این سوئیت همان مرزها را با کد واقعی PHP 8.3 (WASM) و SQLite می‌سنجد:
 *
 *   ۱) لایهٔ پلتفرم: bot_platforms/valid/label/table/column/token_key
 *   ۲) اسکیما: soroush_bot_users و soroush_bot_state با ستون سروش ساخته می‌شوند
 *   ۳) endpoint: https://api.splus.ir/bot<token>/<method> — و بله/تلگرام دست‌نخورده
 *   ۴) صف ماندگار: payload سه‌پلتفرمی، ذخیرهٔ soroush، رد پلتفرم ناشناخته
 *   ۵) قابلیت گفتگوها: getChat/leaveChat برای سروش «پشتیبانی نمی‌شود»
 *   ۶) وبهوک: هر سه شیم (bale/telegram/soroush) به همان موتور با پلتفرم درست
 *   ۷) صفحه‌ها: ربات سروش، حساب‌های متصل، صف، تگ حضور — رندر واقعی
 *   ۸) برچسب فارسی «سروش پلاس» در متن‌های کاربرپسند
 *
 * صادقانه: هیچ فراخوانی واقعی به api.splus.ir انجام نمی‌شود (نه توکن واقعی و
 * نه شبکه). هر چه اینجا سبز است، «قرارداد» را می‌سنجد نه پاسخ سرور سروش.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { REPO } from './harness/site.mjs';
import { php, run, req, loginAdmin } from './harness/lib.mjs';

let pass = 0, fail = 0;
const ok = (name, cond, detail = '') => cond
  ? (pass++, console.log(`  ✅ ${name}`))
  : (fail++, console.log(`  ❌ ${name}${detail ? '  → ' + detail : ''}`));
const read = (rel) => readFileSync(join(REPO, rel), 'utf8');
const J = (r) => { try { return JSON.parse(r.out.trim().split('\n').pop()); } catch (e) { return {}; } };

/* فایل‌های همین تسک — مستقل از نسخهٔ نصب‌شده در /www */
const FILES = {
  '/www/includes/bot_helpers.php': read('update-v4.152.0/includes/bot_helpers.php'),
  '/www/includes/bot_chats.php': read('update-v4.152.0/includes/bot_chats.php'),
  '/www/includes/bot_outbox.php': read('update-v4.152.0/includes/bot_outbox.php'),
  '/www/includes/bot_inbox.php': read('update-v4.152.0/includes/bot_inbox.php'),
  '/www/includes/bot_admin_ui.php': read('update-v4.152.0/includes/bot_admin_ui.php'),
  '/www/includes/bot_webhook_engine.php': read('update-v4.152.0/includes/bot_webhook_engine.php'),
  '/www/bot-inbox.php': read('update-v4.152.0/bot-inbox.php'),
  '/www/bot-accounts.php': read('update-v4.152.0/bot-accounts.php'),
  '/www/bot-queue.php': read('update-v4.152.0/bot-queue.php'),
  '/www/attendance-tags.php': read('update-v4.152.0/attendance-tags.php'),
  '/www/soroush-bot.php': read('update-v4.152.0/soroush-bot.php'),
  '/www/soroush-webhook.php': read('update-v4.152.0/soroush-webhook.php'),
};
for (const [p, text] of Object.entries(FILES)) {
  try { php.writeFile(p, text); } catch (e) { /* فایل‌های خارج از بستهٔ سایت */ }
}

/* ═══════ ۱) لایهٔ پلتفرم ═══════ */
const base = J(await run(String.raw`<?php
require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
$out = [];
$out['platforms'] = bot_platforms();
$out['labels'] = bot_platform_labels();
$out['label_s'] = bot_platform_label('soroush');
$out['valid'] = [bot_valid_platform('bale'), bot_valid_platform('telegram'), bot_valid_platform('soroush'),
                 bot_valid_platform('whatever'), bot_valid_platform('SOROUSH'), bot_valid_platform(null)];
$out['tables'] = [bot_user_table('soroush'), bot_state_table('soroush')];
$out['cols'] = [bot_chat_column('soroush'), bot_username_column('soroush'), bot_token_key('soroush')];
$out['tables_bad'] = [bot_user_table('nope'), bot_state_table('')];
echo json_encode($out, JSON_UNESCAPED_UNICODE);`));
ok('bot_platforms دقیقاً سه پیام‌رسان می‌دهد: بله، تلگرام، سروش پلاس',
  JSON.stringify(base.platforms) === JSON.stringify(['bale', 'telegram', 'soroush']),
  JSON.stringify(base.platforms));
ok('برچسب فارسی سروش «سروش پلاس» است',
  base.label_s === 'سروش پلاس' && base.labels.soroush === 'سروش پلاس', JSON.stringify(base.labels));
ok('پلتفرم ناشناخته/بزرگ‌نویسی/تهی به بله برمی‌گردد (نه سروش)',
  JSON.stringify(base.valid) === JSON.stringify(['bale', 'telegram', 'soroush', 'bale', 'bale', 'bale']),
  JSON.stringify(base.valid));
ok('نام جدول‌ها و ستون‌ها از کلید پلتفرم ساخته می‌شوند',
  JSON.stringify(base.tables) === JSON.stringify(['soroush_bot_users', 'soroush_bot_state']) &&
  JSON.stringify(base.cols) === JSON.stringify(['soroush_chat_id', 'soroush_username', 'soroush_bot_token']),
  JSON.stringify([base.tables, base.cols]));
ok('همان توابع برای پلتفرم نامعتبر هم جدول بله می‌دهند (بدون ساخت جدول ناشناخته)',
  JSON.stringify(base.tables_bad) === JSON.stringify(['bale_bot_users', 'bale_bot_state']),
  JSON.stringify(base.tables_bad));

/* ═══════ ۲) اسکیما ═══════ */
const schema = J(await run(String.raw`<?php
require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
$out = [];
$out['first'] = ensure_bot_schema('soroush');
$out['second'] = ensure_bot_schema('soroush');
$out['users_cols'] = array_map(function ($r) { return $r['name']; }, DB::fetchAll('PRAGMA table_info(soroush_bot_users)'));
$out['state_cols'] = array_map(function ($r) { return $r['name']; }, DB::fetchAll('PRAGMA table_info(soroush_bot_state)'));
$out['bale_users'] = array_map(function ($r) { return $r['name']; }, DB::fetchAll('PRAGMA table_info(bale_bot_users)'));
$out['msg_cols'] = array_map(function ($r) { return $r['name']; }, DB::fetchAll('PRAGMA table_info(bot_message_templates)'));
echo json_encode($out, JSON_UNESCAPED_UNICODE);`));
ok('ensure_bot_schema سروش idempotent است (دو بار بدون خطا)',
  schema.first !== false && schema.second !== false);
ok('جدول soroush_bot_users با ستون soroush_chat_id و soroush_username ساخته می‌شود',
  (schema.users_cols || []).includes('soroush_chat_id') && (schema.users_cols || []).includes('soroush_username'),
  JSON.stringify(schema.users_cols));
ok('جدول soroush_bot_state با ستون soroush_chat_id ساخته می‌شود',
  (schema.state_cols || []).includes('soroush_chat_id'), JSON.stringify(schema.state_cols));
ok('جداول بله و سروش هم‌زمان و مستقل‌اند (ستون سروش در بله نیست)',
  (schema.bale_users || []).includes('bale_chat_id') && !(schema.bale_users || []).includes('soroush_chat_id'),
  JSON.stringify(schema.bale_users));
ok('جدول متن‌های مشترک ستون platform دارد و سروش را می‌پذیرد',
  (schema.msg_cols || []).includes('platform') && (schema.msg_cols || []).includes('template_key'));

/* ═══════ ۳) endpoint ═══════ */
const ep = J(await run(String.raw`<?php
require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
set_setting('soroush_bot_token', '111:AAA');
set_setting('bale_bot_token', '222:BBB');
set_setting('telegram_bot_token', '333:CCC');
set_setting('telegram_api_base', '');
$out = [];
$out['s'] = bot_api_endpoint('soroush', 'sendMessage');
$out['b'] = bot_api_endpoint('bale', 'getMe');
$out['t'] = bot_api_endpoint('telegram', 'getMe');
$out['s_getupdates'] = bot_api_endpoint('soroush', 'getUpdates');
$out['s_webhook'] = bot_api_endpoint('soroush', 'setWebhook');
set_setting('soroush_bot_token', '');
try { bot_api_endpoint('soroush', 'sendMessage'); $out['empty'] = 'NO_THROW'; }
catch (Throwable $e) { $out['empty'] = $e->getMessage(); }
echo json_encode($out, JSON_UNESCAPED_UNICODE);`));
ok('endpoint سروش دقیقاً https://api.splus.ir/bot<token>/<method> است',
  ep.s === 'https://api.splus.ir/bot111:AAA/sendMessage', String(ep.s));
ok('getUpdates و setWebhook هم از همان قاعده پیروی می‌کنند',
  ep.s_getupdates === 'https://api.splus.ir/bot111:AAA/getUpdates' &&
  ep.s_webhook === 'https://api.splus.ir/bot111:AAA/setWebhook',
  JSON.stringify([ep.s_getupdates, ep.s_webhook]));
ok('بله و تلگرام دست‌نخورده‌اند',
  ep.b === 'https://tapi.bale.ai/bot222:BBB/getMe' && ep.t === 'https://api.telegram.org/bot333:CCC/getMe',
  JSON.stringify([ep.b, ep.t]));
ok('توکن خالی برای سروش خطای فارسی می‌دهد و به سروش/بله نمی‌رود',
  String(ep.empty).includes('توکن ربات تنظیم نشده'), String(ep.empty));

/* ═══════ ۴) صف ماندگار ═══════ */
const outbox = J(await run(String.raw`<?php
require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
require_once '/www/includes/bot_outbox.php';
$out = [];
$out['payload_s'] = bot_outbox_payload('soroush', ['chat_id' => '4242', 'text' => 'سلام سروش']);
$out['payload_t'] = bot_outbox_payload('telegram', ['chat_id' => '1', 'text' => 't']);
try { bot_outbox_payload('unknownmessenger', ['chat_id' => '1', 'text' => 'x']); $out['bad'] = 'NO_THROW'; }
catch (Throwable $e) { $out['bad'] = get_class($e); }
$id = str_repeat('a', 32);
bot_outbox_store($id, 'soroush', ['chat_id' => '4242', 'text' => 'سلام سروش']);
$out['row'] = DB::fetch('SELECT platform, payload, owner, state FROM bot_outbox WHERE job_id = ?', [$id]);
$out['same'] = bot_outbox_store($id, 'soroush', ['chat_id' => '4242', 'text' => 'سلام سروش']);
try { bot_outbox_store($id, 'soroush', ['chat_id' => '4242', 'text' => 'متن دیگر']); $out['conflict'] = 'NO_THROW'; }
catch (Throwable $e) { $out['conflict'] = $e->getMessage(); }
echo json_encode($out, JSON_UNESCAPED_UNICODE);`));
ok('payload سروش پذیرفته و به JSON پایدار تبدیل می‌شود',
  JSON.parse(outbox.payload_s || '{}').chat_id === '4242', String(outbox.payload_s));
ok('پلتفرم ناشناخته با InvalidArgumentException رد می‌شود',
  outbox.bad === 'InvalidArgumentException', String(outbox.bad));
ok('صف، ردیف سروش را با پلتفرم درست ذخیره می‌کند',
  outbox.row && outbox.row.platform === 'soroush' && JSON.parse(outbox.row.payload).text === 'سلام سروش',
  JSON.stringify(outbox.row));
ok('همان job_id با همان محتوا تأیید مجدد می‌شود (idempotent)',
  outbox.same === 'a'.repeat(32), String(outbox.same));
ok('همان job_id با محتوای متفاوت خطای تعارض می‌دهد',
  String(outbox.conflict).includes('conflict'), String(outbox.conflict));

/* ═══════ ۵) قابلیت گفتگوها ═══════ */
const caps = J(await run(String.raw`<?php
require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
require_once '/www/includes/bot_chats.php';
$boom = function () { throw new RuntimeException('TRANSPORT_CALLED'); };
$out = [];
$out['caps_s'] = bot_chats_capabilities('soroush');
$out['caps_b'] = bot_chats_capabilities('bale');
$out['refresh'] = bot_chats_refresh('soroush', 20, $boom);
$out['add'] = bot_chats_add('soroush', '-1001234567890', $boom);
$out['leave'] = bot_chats_leave('soroush', '-1001234567890', $boom, 'admin');
bot_chats_schema();
bot_chats_save('soroush', '-1001234567890', ['chat_type' => 'group', 'title' => 'گروه سروش', 'status' => 'member']);
$out['row'] = DB::fetch("SELECT * FROM bot_chats WHERE platform='soroush' AND chat_id='-1001234567890'");
$out['count_bale'] = bot_chats_total(['platform' => 'bale', 'type' => '', 'status' => '', 'q' => '']);
$out['count_s'] = bot_chats_total(['platform' => 'soroush', 'type' => '', 'status' => '', 'q' => '']);
echo json_encode($out, JSON_UNESCAPED_UNICODE);`));
ok('قابلیت‌ها: سروش getChat/leaveChat ندارد، بله دارد',
  caps.caps_s.get_chat === false && caps.caps_s.leave === false &&
  caps.caps_b.get_chat === true && caps.caps_b.leave === true, JSON.stringify(caps.caps_s));
ok('رفرش سروش unsupported برمی‌گرداند و transport را صدا نمی‌زند',
  caps.refresh.ok === false && caps.refresh.unsupported === true, JSON.stringify(caps.refresh));
ok('افزودن دستی سروش بدون فراخوانی API رد می‌شود',
  caps.add.ok === false && String(caps.add.error).includes('getChat'), JSON.stringify(caps.add));
ok('خروج سروش بدون فراخوانی API رد می‌شود',
  caps.leave.ok === false && String(caps.leave.error).includes('leaveChat'), JSON.stringify(caps.leave));
ok('گفتگوهای سروش در فضای خودشان ذخیره می‌شوند (بدون سرریز به بله)',
  caps.row && caps.row.platform === 'soroush' && caps.count_s === 1 && caps.count_bale === 0,
  JSON.stringify([caps.count_s, caps.count_bale]));

/* ═══════ ۶) موتور وبهوک — سیم‌کشی هر سه شیم ═══════ */
const shims = J(await run(String.raw`<?php
$out = [];
foreach (['bale', 'telegram', 'soroush'] as $p) {
    $file = '/www/' . $p . '-webhook.php';
    $out[$p . '_exists'] = is_file($file);
    $out[$p . '_src'] = is_file($file) ? file_get_contents($file) : '';
}
$out['engine_src'] = file_get_contents('/www/includes/bot_webhook_engine.php');
echo json_encode($out, JSON_UNESCAPED_UNICODE);`));
for (const p of ['bale', 'telegram', 'soroush']) {
  ok(`شیم ${p}-webhook.php وجود دارد و BOT_PLATFORM را تعریف می‌کند`,
    shims[p + '_exists'] === true && shims[p + '_src'].includes(`define('BOT_PLATFORM', '${p}')`) &&
    shims[p + '_src'].includes("includes/bot_webhook_engine.php"),
    shims[p + '_src'].slice(0, 120));
}
ok('موتور مشترک پلتفرم را از BOT_PLATFORM می‌خواند (نه هاردکد بله)',
  shims.engine_src.includes("defined('BOT_PLATFORM')") && shims.engine_src.includes('bot_valid_platform(BOT_PLATFORM)'),
  'engine platform bootstrap');

/* ═══════ ۷) صفحه‌ها ═══════ */
await loginAdmin('harnessSor0001');
const clearTok = await run(String.raw`<?php
require_once '/www/includes/functions.php';
set_setting('soroush_bot_token', '');
echo 'OK';`);
assert.equal(clearTok.out.trim(), 'OK');

const botPage = await req('soroush-bot', { file: 'soroush-bot.php', sid: 'harnessSor0001' });
const bp = botPage.res.page || '';
ok('صفحهٔ ربات سروش بدون خطای PHP رندر می‌شود',
  !botPage.res.fatal && bp.includes('سروش پلاس'), (botPage.res.fatal || '').slice(0, 200));
ok('صفحه، فیلد توکن و نام فایل وبهوک درست را نشان می‌دهد',
  bp.includes('name="bot_token"') && bp.includes('soroush-webhook.php'),
  'token/webhook form');
ok('صفحه راهنمای ساخت ربات در splus.ir/botfather را دارد',
  bp.includes('botfather') || bp.includes('سروش'), 'setup guide');
ok('صفحه هیچ ارجاعی به فایل وبهوک بله/تلگرام به‌عنوان فایل این پلتفرم ندارد',
  !bp.includes('bale-webhook.php') && !bp.includes('telegram-webhook.php'));

const acc = await req('soroush-accounts', { file: 'bot-accounts.php', query: 'platform=soroush', sid: 'harnessSor0001' });
ok('صفحهٔ حساب‌های متصل با platform=soroush بی‌خطا رندر می‌شود',
  !acc.res.fatal && (acc.res.page || '').includes('سروش'), (acc.res.fatal || '').slice(0, 200));

const queue = await req('soroush-queue', { file: 'bot-queue.php', query: 'platform=soroush', sid: 'harnessSor0001' });
ok('صف اعلان‌ها با platform=soroush بی‌خطا رندر می‌شود',
  !queue.res.fatal && (queue.res.page || '').includes('سروش'), (queue.res.fatal || '').slice(0, 200));

const tags = await req('soroush-tags', { file: 'attendance-tags.php', sid: 'harnessSor0001' });
ok('صفحهٔ تگ حضور گزینهٔ سروش پلاس را دارد',
  !tags.res.fatal && (tags.res.page || '').includes('سروش پلاس'), (tags.res.fatal || '').slice(0, 200));

/* ═══════ ۸) برچسب فارسی در مسیرهای واقعی ═══════ */
const labels = J(await run(String.raw`<?php
require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
require_once '/www/includes/bot_login_flow.php';
require_once '/www/includes/attendance_helpers.php';
$out = [];
$out['login_label'] = bot_login_platform_fa('soroush');
$out['test_msg'] = att_test_scan_message('soroush', 'present', '08:15');
$out['notify_src'] = strpos(file_get_contents('/www/includes/attendance_helpers.php'), 'bot_platforms()') !== false;
$out['notify_keys'] = array_keys(array_fill_keys(bot_platforms(), 0) + ['missing' => []]);
$out['text_label'] = bot_text('soroush', 'welcome');
echo json_encode($out, JSON_UNESCAPED_UNICODE);`));
ok('ورود از داخل ربات هم «سروش پلاس» را نشان می‌دهد (نه بله)',
  labels.login_label === 'سروش پلاس', String(labels.login_label));
ok('پیام نمونهٔ حضور، نام «سروش پلاس» را در متن دارد',
  String(labels.test_msg).includes('سروش پلاس'), String(labels.test_msg).split('\n')[0]);
ok('att_test_notify_management از فهرست پلتفرم‌ها می‌سازد (نه سه مقدار هاردکد) و کلیدها درست‌اند',
  labels.notify_src === true &&
  JSON.stringify(labels.notify_keys) === JSON.stringify(['bale', 'telegram', 'soroush', 'missing']),
  JSON.stringify([labels.notify_src, labels.notify_keys]));
ok('متن پیش‌فرض سروش از بانک متن‌ها خوانده می‌شود (نه متن بله)',
  typeof labels.text_label === 'string' && labels.text_label.length > 0);

/* ═══════ ۹) سیم‌کشی لینک‌ها و همگام‌سازی ═══════ */
const wiring = (() => {
  const h = read('update-v4.152.0/includes/header.php');
  const t = read('update-v4.152.0/includes/header_tiles.php');
  const i = read('update-v4.152.0/index.php');
  const d = read('update-v4.152.0/includes/desk_sync.php');
  const x = read('update-v4.152.0/class-exam-sync-api.php');
  return { h, t, i, d, x };
})();
ok('سایدبار لینک صفحهٔ سروش را دارد', wiring.h.includes('soroush-bot.php'));
ok('کاشی هدر پلتفرم سروش را می‌شناسد', wiring.t.includes('soroush'));
ok('میان‌بر داشبورد به صفحهٔ سروش می‌رود', wiring.i.includes('soroush-bot.php'));
ok('فهرست همگام‌سازی دسکتاپ soroush_bot_users را دارد', wiring.d.includes('soroush_bot_users'));
ok('API همگام‌سازی آزمون هم soroush_bot_users را می‌پذیرد', wiring.x.includes("'soroush_bot_users'"));

/* ═══════ ۱۰) نحو ═══════ */
const lint = J(await run(String.raw`<?php
$bad = [];
foreach (['/www/includes/bot_helpers.php', '/www/includes/bot_chats.php', '/www/includes/bot_admin_ui.php',
          '/www/includes/bot_webhook_engine.php', '/www/soroush-bot.php', '/www/soroush-webhook.php'] as $f) {
    try { token_get_all(file_get_contents($f), TOKEN_PARSE); }
    catch (Throwable $e) { $bad[] = basename($f) . ': ' . $e->getMessage(); }
}
echo json_encode(['bad' => $bad]);`));
ok('همهٔ فایل‌های کلیدی این تسک نحوی سالم‌اند',
  (lint.bad || []).length === 0, (lint.bad || []).join(' | '));

console.log(`\n──── نتیجه: ${pass} ✅ / ${fail} ❌ ────`);
console.log('یادآوری: هیچ فراخوانی واقعی به api.splus.ir انجام نشد؛ این سوئیت فقط داده، اسکیمای SQLite، صفحه‌ها و مسیرهای کد را می‌سنجد.');
assert(fail === 0);
process.exit(fail ? 1 : 0);
