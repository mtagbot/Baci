/**
 * test-bot-chats.mjs — «گروه‌ها و کانال‌ها» زیر «پیام‌های دریافت‌شده از ربات» (V4.184.0)
 *
 * چرا این سوئیت وجود دارد: هیچ APIای در بله/تلگرام «فهرست همهٔ گفتگوهای ربات»
 * را نمی‌دهد. فهرست باید از چیزهایی ساخته شود که ربات واقعاً دیده است
 * (پیام‌ها، دکمه‌ها، پیام سرویسیِ افزودن/حذف عضو، و my_chat_member در تلگرام)
 * و با getChat تازه شود. اگر این مسیر خراب باشد، مدیر فهرست خالی یا دروغ
 * می‌بیند — و «خروج از گروه» هم روی ردیف اشتباه اجرا می‌شود.
 *
 * سنجش واقعی با PHP 8.3 (WASM) و transport تزریقی؛ هیچ شبکه‌ای لازم نیست.
 *   ۱) نحو، اسکیما و توابع خالص (نوع، برچسب، متن خطا)
 *   ۲) ثبت از پیام/دکمه/پیام سرویسی و my_chat_member
 *   ۳) کش هویت ربات (getMe) و باطل شدنش با تغییر توکن
 *   ۴) refresh با getChat: موفق، «پیدا نشد»، خطای موقت، و احترام به limit/فاصله
 *   ۵) خروج: برگشت‌ناپذیر، ممنوع برای خصوصی، ثبت در activity_logs
 *   ۶) افزودن دستی با شناسه و اعتبارسنجی آن
 *   ۷) فیلترها/جستجو/ترتیب/صفحه‌بندی/خلاصه/نگاشت عنوان
 *   ۸) رندر واقعی صفحه در هر دو تب + POSTهای صفحه (CSRF، خروج خصوصی، شناسهٔ بد)
 *   ۹) سیم‌کشی وبهوک و بسته‌بندی
 */
import { run, php, req, loginAdmin } from './harness/lib.mjs';
import { REPO } from './harness/site.mjs';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

let pass = 0, fail = 0;
const ok = (name, cond, detail = '') => cond ? (pass++, console.log(`  ✅ ${name}`))
                                              : (fail++, console.log(`  ❌ ${name}${detail ? '  → ' + detail : ''}`));
const read = rel => readFileSync(join(REPO, rel), 'utf8');
const J = (r) => { try { return JSON.parse(r.out.trim().split('\n').pop()); } catch (e) { return {}; } };

const HEAD = String.raw`<?php
require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
require_once '/www/includes/bot_chats.php';
`;

/* فایل‌های همین تسک را — مستقل از نسخهٔ نصب‌شده در /www — از مخزن می‌نویسیم */
const FILES = {
  '/www/includes/bot_chats.php': read('update-v4.152.0/includes/bot_chats.php'),
  '/www/bot-inbox.php': read('update-v4.152.0/bot-inbox.php'),
  '/www/includes/bot_webhook_engine.php': read('update-v4.152.0/includes/bot_webhook_engine.php'),
};
for (const [p, text] of Object.entries(FILES)) php.writeFile(p, text);
console.log('>>> bot_chats.php + bot-inbox.php + bot_webhook_engine.php در /www نوشته شد');

/* ═══════ ۱) نحو ═══════ */
php.writeFile('/harness/bc_lint.json', JSON.stringify(FILES));
const lint = await run(String.raw`<?php
$bad = []; $list = json_decode(file_get_contents('/harness/bc_lint.json'), true);
foreach ($list as $name => $src) {
    try { token_get_all($src, TOKEN_PARSE); } catch (Throwable $e) { $bad[] = $name . ': ' . $e->getMessage(); }
}
echo json_encode(['bad' => $bad]);`);
const lintBad = J(lint).bad || [];
ok('بررسی نحوی هر سه فایل این اصلاح', lintBad.length === 0, lintBad.join(' | '));

/* ═══════ ۲) توابع خالص + اسکیما ═══════ */
const basics = J(await run(HEAD + String.raw`
$out = [];
$out['types'] = [];
foreach (['private','group','supergroup','channel','Group','','bot','telegram'] as $t) $out['types'][] = bot_chats_norm_type($t);
$out['type_labels'] = [bot_chats_type_label('group'), bot_chats_type_label('channel'), bot_chats_type_label('private'), bot_chats_type_label('x')];
$out['status_labels'] = [bot_chats_status_label('member'), bot_chats_status_label('left'), bot_chats_status_label('unknown')];
$out['err'] = [
    bot_chats_error_text(new RuntimeException('{"ok":false,"description":"Bad Request: chat not found"}')),
    bot_chats_error_text(new RuntimeException('پاسخ ناموفق API: HTTP 400 - Bad Request: not enough rights')),
    bot_chats_error_text(new RuntimeException('   ')),
    bot_chats_error_text(new RuntimeException(str_repeat('x', 400))),
];
$mk = function ($id, $u, $bot = true) { return ['id' => $id, 'username' => $u, 'is_bot' => $bot]; };
$ident = ['id' => '555', 'username' => 'school_bot'];
$out['is_bot'] = [
    bot_chats_is_this_bot($mk(555, 'other'), $ident),
    bot_chats_is_this_bot($mk(9, 'School_Bot'), $ident),
    bot_chats_is_this_bot($mk(556, 'school_bot'), $ident),
    bot_chats_is_this_bot($mk(555, 'school_bot', false), $ident),
];
$out['schema'] = [bot_chats_schema(), bot_chats_schema()];
$cols = []; foreach (DB::fetchAll('PRAGMA table_info(bot_chats)') as $c) $cols[] = $c['name'];
$out['cols'] = $cols;
$idx = []; foreach (DB::fetchAll('PRAGMA index_list(bot_chats)') as $i) $idx[] = $i['name'] . ':' . (int)$i['unique'];
$out['idx'] = $idx;
$uniq = DB::fetch("SELECT COUNT(*) c FROM sqlite_master WHERE type='index' AND name='uniq_bot_chat'");
$out['uniq_idx'] = (int)($uniq['c'] ?? 0);
$out['err_len'] = mb_strlen($out['err'][3], 'UTF-8');
echo json_encode($out, JSON_UNESCAPED_UNICODE);`));
ok('normalize: گروه/کانال و انواع User/Bot روبیکا به نوع داخلی تبدیل می‌شوند',
   JSON.stringify(basics.types) === JSON.stringify(['private', 'group', 'group', 'channel', 'group', 'unknown', 'private', 'unknown']),
   JSON.stringify(basics.types));
ok('برچسب فارسی نوع گفتگو', JSON.stringify(basics.type_labels) === JSON.stringify(['گروه', 'کانال', 'گفتگوی خصوصی', 'نامشخص']),
   JSON.stringify(basics.type_labels));
ok('برچسب فارسی وضعیت', JSON.stringify(basics.status_labels) === JSON.stringify(['عضو', 'خارج‌شده', 'نامشخص']),
   JSON.stringify(basics.status_labels));
ok('متن خطا از JSON بیرون کشیده می‌شود', basics.err[0] === 'Bad Request: chat not found', basics.err[0]);
ok('پیشوند «پاسخ ناموفق API: HTTP n» حذف می‌شود', basics.err[1] === 'Bad Request: not enough rights', basics.err[1]);
ok('خطای خالی جایگزین فارسی می‌گیرد', basics.err[2] === 'پاسخ نامعتبر از سرور پیام‌رسان', basics.err[2]);
ok('متن خطا به ۱۸۰ نویسه کوتاه می‌شود', basics.err_len === 181 && basics.err[3].endsWith('…'), String(basics.err_len));
ok('تشخیص خودِ ربات: با شناسه، با نام کاربری (بی‌توجه به بزرگی حروف)، نه برای غیرربات',
   JSON.stringify(basics.is_bot) === JSON.stringify([true, true, true, false]), JSON.stringify(basics.is_bot));
ok('اسکیما idempotent است (دو بار true)', JSON.stringify(basics.schema) === '[true,true]', JSON.stringify(basics.schema));
const NEED = ['id', 'platform', 'chat_id', 'chat_type', 'title', 'username', 'status', 'added_at', 'first_seen',
              'last_seen', 'last_message_at', 'msg_count', 'checked_at', 'left_at', 'left_by', 'last_error'];
ok('همهٔ ستون‌های لازم ساخته شده‌اند', NEED.every(c => basics.cols.includes(c)),
   NEED.filter(c => !basics.cols.includes(c)).join(','));
ok('کلید یکتا روی (platform, chat_id) هست', basics.uniq_idx === 1 && basics.idx.some(x => x === 'uniq_bot_chat:1'),
   JSON.stringify(basics.idx));

/* ═══════ ۳) ثبت از پیام، دکمه و پیام سرویسی ═══════ */
const touch = J(await run(HEAD + String.raw`
DB::execute('DELETE FROM bot_chats');
bot_chats_schema();
$ident = ['id' => '555', 'username' => 'school_bot'];
$g = ['id' => -1001, 'type' => 'group', 'title' => 'گروه الف'];
$out = [];
$out['r1'] = bot_chats_touch('bale', $g, ['id' => 7, 'username' => 'ali'], ['message_id' => 1, 'text' => 'سلام'], $ident);
$row = DB::fetch("SELECT * FROM bot_chats WHERE platform='bale' AND chat_id='-1001'");
$out['row1'] = $row;
bot_chats_touch('bale', $g, ['id' => 7], ['message_id' => 2, 'text' => 'دوباره'], $ident);
$out['row2'] = DB::fetch("SELECT * FROM bot_chats WHERE platform='bale' AND chat_id='-1001'");
/* دکمه (بدون پیام متنی): آخرین‌دیده تازه می‌شود ولی شمارش پیام نه */
bot_chats_touch('bale', $g, ['id' => 7], [], $ident);
$out['row_cb'] = DB::fetch("SELECT * FROM bot_chats WHERE platform='bale' AND chat_id='-1001'");
/* پیام سرویسی: خودِ ربات اضافه شده */
bot_chats_touch('bale', $g, ['id' => 1, 'is_bot' => true, 'username' => 'school_bot'],
    ['message_id' => 3, 'new_chat_members' => [['id' => 555, 'is_bot' => true, 'username' => 'school_bot']]], $ident);
$out['after_add'] = DB::fetch("SELECT * FROM bot_chats WHERE platform='bale' AND chat_id='-1001'");
/* و بعد ربات حذف شده */
bot_chats_touch('bale', $g, ['id' => 1, 'is_bot' => true],
    ['message_id' => 4, 'left_chat_member' => ['id' => 555, 'is_bot' => true, 'username' => 'school_bot']], $ident);
$out['after_left'] = DB::fetch("SELECT * FROM bot_chats WHERE platform='bale' AND chat_id='-1001'");
/* پیام تازه بعد از خروج ⇒ دوباره عضو */
bot_chats_touch('bale', $g, ['id' => 7], ['message_id' => 5, 'text' => 'برگشتم'], $ident);
$out['after_msg'] = DB::fetch("SELECT * FROM bot_chats WHERE platform='bale' AND chat_id='-1001'");
/* عضو انسانی (نه ربات) در گروه دیگر — گفتگو ثبت می‌شود ولی شمارنده بالا نمی‌رود */
bot_chats_touch('bale', ['id' => -1002, 'type' => 'supergroup', 'title' => 'گروه ب'], ['id' => 9],
    ['message_id' => 6, 'new_chat_members' => [['id' => 9, 'is_bot' => false]]], $ident);
$out['human_join'] = DB::fetch("SELECT * FROM bot_chats WHERE platform='bale' AND chat_id='-1002'");
/* گفتگوی خصوصی: عنوان از first_name */
bot_chats_touch('bale', ['id' => 777, 'type' => 'private', 'first_name' => 'علی', 'last_name' => 'رضایی'],
    ['id' => 777, 'username' => 'ali_r'], ['message_id' => 7, 'text' => 'سلام'], $ident);
$out['private'] = DB::fetch("SELECT * FROM bot_chats WHERE platform='bale' AND chat_id='777'");
/* شناسهٔ خالی ⇒ false و هیچ ردیفی */
$out['empty'] = bot_chats_touch('bale', [], [], ['message_id' => 8, 'text' => 'x'], $ident);
$out['count'] = (int)DB::fetch('SELECT COUNT(*) c FROM bot_chats')['c'];
echo json_encode($out, JSON_UNESCAPED_UNICODE);`));
const t1 = touch.row1 || {}, t2 = touch.row2 || {};
ok('پیام اول گفتگو را می‌سازد: نوع group، عنوان، عضو، شمارنده ۱',
   t1.chat_type === 'group' && t1.title === 'گروه الف' && t1.status === 'member' && Number(t1.msg_count) === 1,
   JSON.stringify([t1.chat_type, t1.title, t1.status, t1.msg_count]));
ok('first_seen و last_message_at با قالب تاریخِ قابل‌مقایسه پر می‌شوند',
   /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(t1.first_seen || '') &&
   /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(t1.last_message_at || ''),
   JSON.stringify([t1.first_seen, t1.last_message_at]));
ok('پیام دوم شمارنده را ۲ می‌کند', Number(t2.msg_count) === 2, String(t2.msg_count));
ok('دکمه (بدون متن) شمارنده را بالا نمی‌برد ولی ردیف را نگه می‌دارد',
   Number(touch.row_cb.msg_count) === 2 && touch.row_cb.status === 'member', String(touch.row_cb.msg_count));
ok('new_chat_members برای خودِ ربات ⇒ added_at پر و وضعیت عضو',
   Boolean(touch.after_add.added_at) && touch.after_add.status === 'member', JSON.stringify(touch.after_add.added_at));
ok('left_chat_member برای خودِ ربات ⇒ وضعیت خارج‌شده + left_at',
   touch.after_left.status === 'left' && Boolean(touch.after_left.left_at), touch.after_left.status);
ok('پیام تازه بعد از خروج، وضعیت را به «عضو» برمی‌گرداند',
   touch.after_msg.status === 'member' && touch.after_msg.last_error === null, String(touch.after_msg.status));
ok('supergroup ⇒ group و پیوستن یک انسان شمارنده را بالا نمی‌برد',
   touch.human_join.chat_type === 'group' && Number(touch.human_join.msg_count) === 0,
   JSON.stringify([touch.human_join.chat_type, touch.human_join.msg_count]));
ok('گفتگوی خصوصی عنوانش از نام فرستنده ساخته می‌شود',
   touch.private.title === 'علی رضایی' && touch.private.chat_type === 'private' && touch.private.username === 'ali_r',
   JSON.stringify([touch.private.title, touch.private.chat_type]));
ok('شناسهٔ خالی ردیف نمی‌سازد', touch.empty === false && touch.count === 3, JSON.stringify([touch.empty, touch.count]));

/* ═══════ ۴) my_chat_member (تلگرام) ═══════ */
const mym = J(await run(HEAD + String.raw`
DB::execute("DELETE FROM bot_chats WHERE platform='telegram'");
$out = [];
$base = ['chat' => ['id' => -2001, 'type' => 'supergroup', 'title' => 'گروه ت']];
bot_chats_touch_my_member('telegram', $base + ['new_chat_member' => ['status' => 'administrator']]);
$out['admin'] = DB::fetch("SELECT * FROM bot_chats WHERE platform='telegram' AND chat_id='-2001'");
bot_chats_touch_my_member('telegram', $base + ['new_chat_member' => ['status' => 'kicked']]);
$out['kicked'] = DB::fetch("SELECT * FROM bot_chats WHERE platform='telegram' AND chat_id='-2001'");
bot_chats_touch_my_member('telegram', $base + ['new_chat_member' => ['status' => 'member']]);
$out['back'] = DB::fetch("SELECT * FROM bot_chats WHERE platform='telegram' AND chat_id='-2001'");
$out['bad'] = bot_chats_touch_my_member('telegram', ['chat' => []]);
echo json_encode($out, JSON_UNESCAPED_UNICODE);`));
ok('my_chat_member administrator ⇒ عضو + added_at', mym.admin.status === 'member' && Boolean(mym.admin.added_at), mym.admin.status);
ok('my_chat_member kicked ⇒ خارج‌شده + left_at', mym.kicked.status === 'left' && Boolean(mym.kicked.left_at), mym.kicked.status);
ok('افزودن دوباره ⇒ عضو و پاک شدن left_at', mym.back.status === 'member' && mym.back.left_at === null, JSON.stringify(mym.back.left_at));
ok('آپدیت بی‌گفتگو نادیده گرفته می‌شود', mym.bad === false);

/* ═══════ ۵) کش هویت ربات ═══════ */
const identT = J(await run(HEAD + String.raw`
DB::execute("DELETE FROM settings WHERE key_name IN ('bale_bot_identity','bale_bot_token')");
set_setting('bale_bot_token', 'tok-A');
$calls = 0;
$tr = function ($m, $p) use (&$calls) { $calls++; return ['ok' => true, 'result' => ['id' => 555, 'username' => 'school_bot']]; };
$out = [];
$out['a'] = bot_chats_identity('bale', $tr);
$out['calls_a'] = $calls;
$out['b'] = bot_chats_identity('bale', $tr);
$out['calls_b'] = $calls;
set_setting('bale_bot_token', 'tok-B');
$out['c'] = bot_chats_identity('bale', $tr);
$out['calls_c'] = $calls;
set_setting('bale_bot_token', '');
$out['d'] = bot_chats_identity('bale', $tr);
$out['calls_d'] = $calls;
echo json_encode($out, JSON_UNESCAPED_UNICODE);`));
ok('getMe فقط یک بار صدا زده می‌شود (کش)', identT.calls_a === 1 && identT.calls_b === 1, JSON.stringify([identT.calls_a, identT.calls_b]));
ok('هویت با getMe درست خوانده می‌شود', identT.a.id === '555' && identT.a.username === 'school_bot', JSON.stringify(identT.a));
ok('تغییر توکن کش را باطل می‌کند', identT.calls_c === 2, String(identT.calls_c));
ok('بی‌توکن هیچ فراخوانی‌ای انجام نمی‌شود', identT.calls_d === 2 && identT.d.id === '', JSON.stringify([identT.calls_d, identT.d]));

/* ═══════ ۶) refresh با getChat ═══════ */
const ref = J(await run(HEAD + String.raw`
DB::execute('DELETE FROM bot_chats');
bot_chats_schema();
$seed = function ($cid, $type, $title, $checked) {
    bot_chats_save('bale', $cid, ['chat_type' => $type, 'title' => $title, 'status' => 'member', 'checked_at' => $checked]);
};
$seed('-1001', 'group', 'قدیمی ۱', '2020-01-01 00:00:01');
$seed('-1002', 'group', 'قدیمی ۲', '2020-01-01 00:00:02');
$seed('-1003', 'group', 'قدیمی ۳', '2020-01-01 00:00:03');
$seed('-1004', 'channel', 'قدیمی ۴', '2020-01-01 00:00:04');
$seed('-1005', 'group', 'تازه', date('Y-m-d H:i:s'));
$seen = [];
$tr = function ($m, $p) use (&$seen) {
    $id = (string)($p['chat_id'] ?? '');
    $seen[] = $m . ':' . $id;
    if ($m !== 'getChat') throw new RuntimeException('متد غیرمنتظره');
    if ($id === '-1001') return ['ok' => true, 'result' => ['id' => -1001, 'type' => 'group', 'title' => 'گروه تازه']];
    if ($id === '-1002') throw new RuntimeException('{"ok":false,"description":"Bad Request: chat not found"}');
    if ($id === '-1003') throw new RuntimeException('Too Many Requests: retry after 5');
    if ($id === '-1004') return ['ok' => true, 'result' => ['id' => -1004, 'type' => 'channel', 'title' => 'کانال چهارم', 'username' => 'ch4']];
    return ['ok' => true, 'result' => ['id' => $id, 'type' => 'group']];
};
$out = [];
$out['first'] = bot_chats_refresh('bale', 3, $tr);
$out['seen1'] = $seen;
$out['r1'] = DB::fetch("SELECT * FROM bot_chats WHERE chat_id='-1001'");
$out['r2'] = DB::fetch("SELECT * FROM bot_chats WHERE chat_id='-1002'");
$out['r3'] = DB::fetch("SELECT * FROM bot_chats WHERE chat_id='-1003'");
$out['r4'] = DB::fetch("SELECT * FROM bot_chats WHERE chat_id='-1004'");
$out['r5'] = DB::fetch("SELECT * FROM bot_chats WHERE chat_id='-1005'");
$seen = [];
$out['second'] = bot_chats_refresh('bale', 3, $tr);
$out['seen2'] = $seen;
$out['r4b'] = DB::fetch("SELECT * FROM bot_chats WHERE chat_id='-1004'");
$out['limit'] = bot_chats_refresh('bale', 500, $tr);
echo json_encode($out, JSON_UNESCAPED_UNICODE);`));
const f1 = ref.first || {}, s1 = ref.seen1 || [];
ok('refresh فقط ردیف‌های کهنه را برمی‌دارد و به limit احترام می‌گذارد',
   s1.length === 3 && s1.join(',') === 'getChat:-1001,getChat:-1002,getChat:-1003', s1.join(','));
ok('گزارش refresh: ۲ بررسی‌شده، ۱ ناموفق، ۱ خارج‌شده',
   f1.checked === 2 && f1.failed === 1 && f1.left === 1, JSON.stringify(f1));
ok('getChat موفق عنوان را تازه و last_error را پاک می‌کند',
   ref.r1.title === 'گروه تازه' && ref.r1.last_error === null && Boolean(ref.r1.checked_at),
   JSON.stringify([ref.r1.title, ref.r1.last_error]));
ok('«chat not found» ⇒ وضعیت خارج‌شده با متن خطا',
   ref.r2.status === 'left' && String(ref.r2.last_error).includes('chat not found'), JSON.stringify([ref.r2.status, ref.r2.last_error]));
ok('خطای موقت وضعیت را عوض نمی‌کند و در last_error می‌ماند',
   ref.r3.status === 'member' && String(ref.r3.last_error).includes('Too Many Requests'), JSON.stringify([ref.r3.status, ref.r3.last_error]));
ok('ردیف بیرون از limit دست‌نخورده می‌ماند', ref.r4.title === 'قدیمی ۴' && ref.r4.checked_at === '2020-01-01 00:00:04',
   JSON.stringify([ref.r4.title, ref.r4.checked_at]));
ok('ردیف تازه‌بررسی‌شده (کمتر از ۱۰ دقیقه) دوباره بررسی نمی‌شود', ref.r5.checked_at !== null && ref.r5.title === 'تازه');
ok('refresh دوم فقط ردیف باقی‌مانده را می‌گیرد و عنوانش را تازه می‌کند',
   (ref.second || {}).checked === 1 && (ref.seen2 || []).join(',') === 'getChat:-1004' && ref.r4b.title === 'کانال چهارم',
   JSON.stringify([ref.second, ref.seen2]));
ok('limit بیرون از بازه به بازهٔ مجاز محدود می‌شود (بدون خطا)', typeof (ref.limit || {}).checked === 'number');

/* ═══════ ۷) خروج ═══════ */
const leave = J(await run(HEAD + String.raw`
DB::execute('DELETE FROM bot_chats');
DB::execute('DELETE FROM activity_logs');
bot_chats_schema();
bot_chats_save('bale', '-1001', ['chat_type' => 'group', 'title' => 'گروه الف', 'status' => 'member']);
bot_chats_save('bale', '999', ['chat_type' => 'private', 'title' => 'اولیا', 'status' => 'member']);
bot_chats_save('bale', '-1002', ['chat_type' => 'group', 'title' => 'گروه ب', 'status' => 'member']);
$calls = []; $fail = false;
$tr = function ($m, $p) use (&$calls, &$fail) {
    $calls[] = $m . ':' . ($p['chat_id'] ?? '');
    if ($fail) throw new RuntimeException('Bad Request: chat not found');
    return ['ok' => true, 'result' => true];
};
$out = [];
$out['ok'] = bot_chats_leave('bale', '-1001', $tr, 'admin1');
$out['row'] = DB::fetch("SELECT * FROM bot_chats WHERE chat_id='-1001'");
$out['log'] = DB::fetch("SELECT COUNT(*) c FROM activity_logs WHERE action = 'خروج ربات از گروه' AND description LIKE '%-1001%'");
$out['private'] = bot_chats_leave('bale', '999', $tr, 'admin1');
$out['calls_private'] = $calls;
$fail = true;
$out['err'] = bot_chats_leave('bale', '-1002', $tr, 'admin1');
$out['row_err'] = DB::fetch("SELECT status, last_error FROM bot_chats WHERE chat_id='-1002'");
$n_before = (int)DB::fetch('SELECT COUNT(*) c FROM bot_chats')['c'];
$out['unknown'] = bot_chats_leave('bale', '-1009', $tr, 'admin1');
$out['count_same'] = ((int)DB::fetch('SELECT COUNT(*) c FROM bot_chats')['c']) === $n_before;
$out['empty'] = bot_chats_leave('bale', '', $tr, 'admin1');
echo json_encode($out, JSON_UNESCAPED_UNICODE);`));
ok('خروج موفق: ok، وضعیت left و left_by و left_at', leave.ok.ok === true && leave.row.status === 'left' &&
   leave.row.left_by === 'admin1' && Boolean(leave.row.left_at), JSON.stringify([leave.row.status, leave.row.left_by]));
ok('خروج در activity_logs با عنوان «خروج ربات از گروه» و شناسهٔ گفتگو ثبت می‌شود',
   Number((leave.log || {}).c) === 1, JSON.stringify(leave.log));
ok('خروج برای گفتگوی خصوصی رد می‌شود و هیچ فراخوانی API ندارد',
   leave.private.ok === false && String(leave.private.error).includes('خصوصی') && (leave.calls_private || []).length === 1,
   JSON.stringify([leave.private, leave.calls_private]));
ok('خطای API: ok=false، پیام فارسی‌شده، وضعیت عوض نمی‌شود ولی last_error می‌ماند',
   leave.err.ok === false && leave.row_err.status === 'member' && String(leave.row_err.last_error).includes('chat not found'),
   JSON.stringify([leave.err, leave.row_err]));
ok('خروج ناموفق برای گفتگوی ناشناخته ردیف نمی‌سازد', leave.unknown.ok === false && leave.count_same === true,
   JSON.stringify([leave.unknown, leave.count_same]));
ok('شناسهٔ خالی بدون فراخوانی رد می‌شود', leave.empty.ok === false && String(leave.empty.error).includes('خالی'));

/* ═══════ ۸) افزودن دستی ═══════ */
const add = J(await run(HEAD + String.raw`
DB::execute('DELETE FROM bot_chats');
bot_chats_schema();
$calls = [];
$tr = function ($m, $p) use (&$calls) {
    $id = (string)($p['chat_id'] ?? '');
    $calls[] = $m . ':' . $id;
    if ($m !== 'getChat') throw new RuntimeException('متد غیرمنتظره');
    if ($id === '@my_channel') return ['ok' => true, 'result' => ['id' => -1005, 'type' => 'channel', 'title' => 'کانال من', 'username' => 'my_channel']];
    if ($id === '-1007') return ['ok' => true, 'result' => ['id' => -1007, 'type' => 'group', 'title' => 'گروه هفت']];
    if ($id === '-1008') throw new RuntimeException('{"ok":false,"description":"Bad Request: chat not found"}');
    return ['ok' => true, 'result' => ['type' => 'group']];
};
$out = [];
$out['ch'] = bot_chats_add('bale', '@my_channel', $tr);
$out['row_ch'] = DB::fetch("SELECT * FROM bot_chats WHERE chat_id='-1005'");
$out['g'] = bot_chats_add('bale', '-1007', $tr);
$out['row_g'] = DB::fetch("SELECT * FROM bot_chats WHERE chat_id='-1007'");
$out['bad'] = bot_chats_add('bale', '!!!', $tr);
$out['calls_bad'] = count($calls);
$out['missing'] = bot_chats_add('bale', '-1006', $tr);
$out['err'] = bot_chats_add('bale', '-1008', $tr);
$out['empty'] = bot_chats_add('bale', '   ', $tr);
$out['calls'] = $calls;
echo json_encode($out, JSON_UNESCAPED_UNICODE);`));
ok('افزودن با @username کانال را با شناسهٔ واقعی و عنوان ثبت می‌کند',
   add.ch.ok === true && add.ch.type === 'channel' && add.row_ch && add.row_ch.title === 'کانال من' &&
   add.row_ch.chat_type === 'channel' && add.row_ch.checked_at !== null,
   JSON.stringify([add.ch, add.row_ch]));
ok('افزودن با شناسهٔ عددی گروه را ثبت می‌کند', add.g.ok === true && add.row_g && add.row_g.chat_type === 'group',
   JSON.stringify([add.g, add.row_g]));
ok('شناسهٔ نامعتبر بدون هیچ فراخوانی API رد می‌شود',
   add.bad.ok === false && add.calls_bad === 2 && String(add.bad.error).includes('شناسه'), JSON.stringify(add.bad));
ok('getChat بدون شناسهٔ برگشتی خطا می‌دهد', add.missing.ok === false && String(add.missing.error).includes('پیدا نشد'), JSON.stringify(add.missing));
ok('خطای getChat به پیام فارسی‌شده تبدیل می‌شود', add.err.ok === false && String(add.err.error).includes('chat not found'), JSON.stringify(add.err));
ok('شناسهٔ خالی رد می‌شود', add.empty.ok === false && String(add.empty.error).includes('وارد کنید'));

/* ═══════ ۹) فیلترها، ترتیب، صفحه‌بندی، خلاصه، نگاشت ═══════ */
const list = J(await run(HEAD + String.raw`
DB::execute('DELETE FROM bot_chats');
bot_chats_schema();
$rows = [
    ['-1003', 'group', 'ج', 'member', 'u3'],
    ['-1001', 'group', 'الف', 'member', 'u1'],
    ['-1002', 'group', 'ب', 'left', 'u2'],
    ['-1004', 'channel', 'کانال مدرسه', 'member', 'schoolch'],
    ['-1005', 'private', 'ز', 'member', 'parent1'],
    ['-1006', 'unknown', '', 'unknown', ''],
];
foreach ($rows as $r) {
    bot_chats_save('bale', $r[0], ['chat_type' => $r[1], 'title' => $r[2], 'status' => $r[3], 'username' => $r[4],
                                   'last_message_at' => '2026-10-10 10:00:00', 'msg_count' => 2]);
}
$f = function ($over = []) { return $over + ['platform' => 'bale', 'type' => '', 'status' => '', 'q' => '']; };
$out = [];
$out['all'] = bot_chats_total($f());
$out['groups'] = bot_chats_total($f(['type' => 'group']));
$out['left'] = bot_chats_total($f(['status' => 'left']));
$out['q_title'] = bot_chats_total($f(['q' => 'کانال']));
$out['q_user'] = bot_chats_total($f(['q' => 'parent1']));
$out['q_id'] = bot_chats_total($f(['q' => '-1002']));
$out['q_none'] = bot_chats_total($f(['q' => 'چیزی که نیست']));
$out['bad_filter'] = bot_chats_total($f(['type' => 'x; DROP TABLE bot_chats']));
$out['order'] = array_map(function ($r) { return $r['chat_id']; }, bot_chats_rows($f(), 1, 10));
$out['page2'] = array_map(function ($r) { return $r['chat_id']; }, bot_chats_rows($f(), 2, 4));
$out['summary'] = bot_chats_summary('bale');
$out['tmap'] = bot_chats_title_map('bale', ['-1001', '-1004', 'nope']);
$out['tmap_keys'] = array_keys($out['tmap']);
echo json_encode($out, JSON_UNESCAPED_UNICODE);`));
ok('شمارش کل ۶', list.all === 6, String(list.all));
ok('فیلتر نوع = group ⇒ ۳', list.groups === 3, String(list.groups));
ok('فیلتر وضعیت = left ⇒ ۱', list.left === 1, String(list.left));
ok('جستجو در عنوان، نام کاربری و شناسه',
   list.q_title === 1 && list.q_user === 1 && list.q_id === 1, JSON.stringify([list.q_title, list.q_user, list.q_id]));
ok('جستجوی بی‌نتیجه ۰ می‌دهد', list.q_none === 0, String(list.q_none));
ok('فیلتر نامعتبر تزریق نمی‌کند و مثل «همه» رفتار می‌کند', list.bad_filter === 6, String(list.bad_filter));
ok('ترتیب: گروه‌ها (الف، ب، ج) بعد کانال بعد خصوصی بعد نامشخص',
   JSON.stringify(list.order) === JSON.stringify(['-1001', '-1002', '-1003', '-1004', '-1005', '-1006']),
   JSON.stringify(list.order));
ok('صفحه‌بندی: صفحهٔ دوم با ۴تایی ⇒ ردیف‌های ۵ و ۶',
   JSON.stringify(list.page2) === JSON.stringify(['-1005', '-1006']), JSON.stringify(list.page2));
ok('خلاصه: ۳ گروه، ۱ کانال، ۱ خصوصی، ۱ خارج‌شده، ۴ عضو',
   list.summary.group === 3 && list.summary.channel === 1 && list.summary.private === 1 &&
   list.summary.left === 1 && list.summary.member === 4 && list.summary.total === 6,
   JSON.stringify(list.summary));
ok('نگاشت عنوان فقط ردیف‌های خواسته‌شده را برمی‌گرداند',
   list.tmap_keys.length === 2 && list.tmap['-1001'].title === 'الف' && list.tmap['-1004'].chat_type === 'channel',
   JSON.stringify(list.tmap_keys));

/* ═══════ ۱۰) رندر صفحه — هر دو تب ═══════ */
await loginAdmin('harnessAdm0001');
const seedInbox = await run(HEAD + String.raw`
require_once '/www/includes/bot_inbox.php';
bot_inbox_schema();
DB::execute('DELETE FROM bot_inbox');
DB::execute("INSERT INTO bot_inbox (platform, chat_id, username, message, kind, created_at) VALUES ('bale','-1001','ali','سلام از گروه','text','2026-10-16 10:00:00')");
echo 'SEEDED';`);
if (!seedInbox.out.includes('SEEDED')) console.log('   seed stderr:', seedInbox.err.slice(0, 200));

const msgPage = await req('messages', { file: 'bot-inbox.php', query: 'platform=bale', sid: 'harnessAdm0001' });
const mp = msgPage.res.page || '';
ok('تب پیام‌ها بدون خطا رندر می‌شود', !msgPage.res.fatal && mp.includes('پیام‌های دریافتی ربات'),
   (msgPage.res.fatal || '').slice(0, 200));
ok('تب‌های «پیام‌های دریافتی» و «گروه‌ها و کانال‌ها» هر دو هستند',
   mp.includes('پیام‌های دریافتی') && mp.includes('گروه‌ها و کانال‌ها'), 'tabs');
ok('زیر نام فرستنده، گروهِ همان گفتگو نوشته می‌شود', mp.includes('در گروه «الف»'), 'chat title under sender');
ok('پیام‌ها همچنان دیده می‌شوند', mp.includes('سلام از گروه'));

const chatsPage = await req('chats', { file: 'bot-inbox.php', query: 'platform=bale&view=chats', sid: 'harnessAdm0001' });
const cp = chatsPage.res.page || '';
ok('تب گفتگوها بدون خطا رندر می‌شود', !chatsPage.res.fatal && cp.includes('گفتگوهای بله'),
   (chatsPage.res.fatal || '').slice(0, 200));
ok('جدول همهٔ گفتگوها را نشان می‌دهد', cp.includes('کانال مدرسه') && cp.includes('گفتگوی خصوصی') && cp.includes('الف'));
ok('نوار خلاصه اعداد را نشان می‌دهد', cp.includes('گروه') && cp.includes('کانال') && cp.includes('خارج‌شده'));
ok('دکمهٔ خروج فقط برای گروه/کانالِ عضو هست', (cp.match(/name="chats_action" value="leave"/g) || []).length === 3,
   String((cp.match(/name="chats_action" value="leave"/g) || []).length));
ok('برای گفتگوی خصوصی دکمهٔ خروج نیست و توضیح می‌دهد',
   cp.includes('گفتگوی خصوصی — خروج معنا ندارد') && !cp.includes('name="chat_id" value="-1005"'), 'private hint');
ok('فرم بروزرسانی و افزودن دستی با CSRF هستند',
   (cp.match(/name="csrf_token"/g) || []).length >= 3 && cp.includes('value="refresh"') && cp.includes('value="add"'),
   String((cp.match(/name="csrf_token"/g) || []).length));
ok('محدودیت فهرست صادقانه توضیح داده شده است',
   cp.includes('هیچ APIای فهرست کامل گفتگوها را نمی‌دهد'), 'honesty note');

const csrf = (cp.match(/name="csrf_token" value="([^"]+)"/) || [])[1] || '';
const chFilter = await req('filter', { file: 'bot-inbox.php', query: 'platform=bale&view=chats&ctype=channel', sid: 'harnessAdm0001' });
const fp = chFilter.res.page || '';
ok('فیلتر نوع=کانال فقط کانال را نشان می‌دهد', fp.includes('کانال مدرسه') && !fp.includes('گفتگوی خصوصی — '),
   'channel filter');
const stFilter = await req('filter-status', { file: 'bot-inbox.php', query: 'platform=bale&view=chats&cstatus=left', sid: 'harnessAdm0001' });
const sp = stFilter.res.page || '';
ok('فیلتر وضعیت=خارج‌شده فقط ردیف خارج‌شده را نشان می‌دهد',
   sp.includes('قبلاً خارج شده') && !sp.includes('کانال مدرسه') && !sp.includes('گفتگوی خصوصی — '), 'status filter');
const qFilter = await req('filter-q', { file: 'bot-inbox.php', query: 'platform=bale&view=chats&cq=' + encodeURIComponent('کانال'), sid: 'harnessAdm0001' });
ok('جستجوی صفحه واقعاً فیلتر می‌کند', (qFilter.res.page || '').includes('کانال مدرسه') && !(qFilter.res.page || '').includes('گفتگوی خصوصی — '));

const noCsrf = await req('no-csrf', { file: 'bot-inbox.php', method: 'POST', sid: 'harnessAdm0001', post: { chats_action: 'refresh', csrf_token: 'wrong' } });
ok('POST بدون CSRF معتبر رد می‌شود', noCsrf.res.flash && noCsrf.res.flash.type === 'error' &&
   noCsrf.res.flash.message.includes('نشست منقضی'), JSON.stringify(noCsrf.res.flash));
ok('پس از POST به همان تب گفتگوها برگشت داده می‌شود',
   String(noCsrf.res.redirect || '').includes('view=chats'), String(noCsrf.res.redirect));

const priv = await req('leave-private', { file: 'bot-inbox.php', method: 'POST', sid: 'harnessAdm0001',
  post: { chats_action: 'leave', chat_id: '-1005', csrf_token: csrf } });
ok('خروج گفتگوی خصوصی از خود صفحه هم رد می‌شود',
   priv.res.flash && priv.res.flash.type === 'error' && priv.res.flash.message.includes('خصوصی'), JSON.stringify(priv.res.flash));

const badAdd = await req('add-bad', { file: 'bot-inbox.php', method: 'POST', sid: 'harnessAdm0001',
  post: { chats_action: 'add', chat_id: '!!!', csrf_token: csrf } });
ok('افزودن دستی با شناسهٔ نامعتبر پیام خطای فارسی می‌دهد',
   badAdd.res.flash && badAdd.res.flash.type === 'error' && badAdd.res.flash.message.includes('شناسه'), JSON.stringify(badAdd.res.flash));

/* ═══════ ۱۱) سیم‌کشی وبهوک و بسته‌بندی ═══════ */
const engine = read('update-v4.152.0/includes/bot_webhook_engine.php');
const page = read('update-v4.152.0/bot-inbox.php');
ok('موتور وبهوک ماژول گفتگوها را require می‌کند', engine.includes("require_once __DIR__ . '/bot_chats.php'"));
ok('آپدیت my_chat_member در موتور گرفته و با ۲۰۰ بسته می‌شود',
   engine.includes("bot_chats_touch_my_member($platform, $update['my_chat_member'])") &&
   /my_chat_member[\s\S]{0,700}http_response_code\(200\);\s*\n\s*exit;/.test(engine));
ok('هر دو قلاب پیام و دکمه در try/catch هستند و فقط لاگ می‌کنند',
   /try \{ bot_chats_touch\(\$platform, \$message\['chat'\]/.test(engine) &&
   /try \{ bot_chats_touch\(\$platform, \$callbackQuery/.test(engine));
ok('صفحه ماژول را require می‌کند', page.includes("require_once __DIR__ . '/includes/bot_chats.php'"));
ok('صفحه هر سه عملیات را با verify_csrf انجام می‌دهد',
   page.includes('verify_csrf') && page.includes("=== 'refresh'") && page.includes("=== 'leave'") && page.includes("=== 'add'"));
ok('صفحه دو تب را با if/else واقعی جدا می‌کند',
   page.includes("$inboxView === 'messages'") && /<\?php else:\s*\/\* v4\.184\.0/.test(page) &&
   /<\?php endif; \?>\s*<\/div>/.test(page));
ok('خروج از صفحه با تأیید مرورگر انجام می‌شود (برگشت‌ناپذیر)',
   page.includes('onsubmit="return confirm('));


console.log(`\n──── نتیجه: ${pass} ✅ / ${fail} ❌ ────`);
process.exit(fail ? 1 : 0);
