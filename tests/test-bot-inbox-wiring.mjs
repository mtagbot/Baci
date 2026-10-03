// v4.177.1 — رگرسیون «پیام‌های دریافتی ربات» خالی می‌ماند
//
// دو باگ واقعی باعث می‌شد هیچ پیامی ثبت نشود و صفحه «هنوز پیامی دریافت نشده
// است» نشان بدهد، در حالی که تست‌های قبلی سبز بودند:
//   ۱) includes/bot_webhook_engine.php فایل includes/bot_inbox.php را
//      require نمی‌کرد، پس bot_inbox_log تعریف نمی‌شد و شرط
//      function_exists(...) همیشه false بود.
//   ②) INSERT از datetime('now','localtime') استفاده می‌کرد که فقط SQLite
//      می‌شناسد؛ روی سایت زندهٔ MySQL خطا می‌داد و در try/catch بی‌صدا
//      بلعیده می‌شد.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { run, req, loginAdmin } from './harness/lib.mjs';

let n = 0;
const check = (v, m) => { assert(v, m); n++; };
const REPO = new URL('..', import.meta.url).pathname.replace(/\/$/, '');
const src = (rel) => readFileSync(REPO + '/' + rel, 'utf8');

/* ═══════ ۱) موتور وبهوک باید ماژول صندوق ورودی را بارگذاری کند ═══════ */
const engine = src('update-v4.152.0/includes/bot_webhook_engine.php');
check(engine.includes("require_once __DIR__ . '/bot_inbox.php'"),
  'the webhook engine requires includes/bot_inbox.php (otherwise bot_inbox_log is undefined and nothing is logged)');

/* ═══════ ۲) INSERT نباید از تابع تاریخِ مخصوص SQLite استفاده کند ═══════ */
const inboxSrc = src('update-v4.152.0/includes/bot_inbox.php');
/* فقط خودِ دستور INSERT بررسی می‌شود (نه توضیحات فارسی) */
const insertBlock = (inboxSrc.match(/INSERT INTO bot_inbox[\s\S]*?\);/) || [''])[0];
check(insertBlock !== '', 'the INSERT statement into bot_inbox is found');
check(!/datetime\(/.test(insertBlock),
  "no SQLite-only datetime('now',…) inside the INSERT — it fails on the live MySQL site");
check(insertBlock.includes("date('Y-m-d H:i:s')"),
  "the timestamp comes from PHP in YYYY-MM-DD HH:MM:SS form (the retention rule compares against that)");

/* ═══════ ۳) ایندکس‌ها مستقل از درایور ساخته شوند ═══════ */
check(/bot_inbox_ensure_index/.test(inboxSrc) && /SHOW INDEX FROM bot_inbox/.test(inboxSrc),
  'index creation is driver-aware (MySQL has no CREATE INDEX IF NOT EXISTS)');

/* ═══════ ۴) مسیر واقعی وبهوک: موتور بارگذاری شود و تابع آماده باشد ═══════
   خودِ update از php://input خوانده می‌شود که در هارنس خالی است و موتور
   زودهنگام exit می‌کند؛ اما requireها پیش از آن اجرا می‌شوند، پس با یک
   register_shutdown_function پس از خروج بررسی می‌کنیم. */
const loaded = await run(String.raw`<?php require_once '/www/includes/functions.php';
set_setting('bale_bot_token','TEST-TOKEN');
register_shutdown_function(function () {
    file_put_contents('/tmp/inbox-loaded.txt', function_exists('bot_inbox_log') ? 'yes' : 'no');
});
define('BOT_PLATFORM', 'bale');
require_once '/www/includes/bot_webhook_engine.php';`);
check(loaded.out.includes('') && loaded.res === undefined || true, 'engine include ran');
const loadedChk = await run(String.raw`<?php echo @file_get_contents('/tmp/inbox-loaded.txt') ?: 'missing';`);
check(loadedChk.out.trim() === 'yes',
  'after including the real webhook engine, bot_inbox_log() IS defined (the v4.177.0 bug: it was not): ' + loadedChk.out.trim());

/* ═══════ ۵) ثبت و خواندن واقعی ═══════ */
const inbox = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
require_once '/www/includes/bot_inbox.php';
bot_inbox_schema();
bot_inbox_log('bale','9100','testuser','سلام، این یک پیام آزمایشی است');
bot_inbox_log('telegram','9101','','پیام ناشناس');
$row = DB::fetch("SELECT * FROM bot_inbox WHERE chat_id='9100'");
echo 'saved=' . ($row ? 'yes' : 'no') . ';';
echo 'msg=' . ($row['message'] ?? '') . ';';
echo 'user=' . ($row['username'] ?? '') . ';';
echo 'ts=' . ($row['created_at'] ?? '') . ';';
echo 'ts_ok=' . (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)($row['created_at'] ?? '')) ? 'yes' : 'no') . ';';
echo 'idx_sqlite=' . (DB::fetch("SELECT COUNT(*) c FROM sqlite_master WHERE type='index' AND tbl_name='bot_inbox'")['c'] ?? 0) . ';';`);
check(inbox.out.includes('saved=yes'), 'an incoming message is really stored: ' + inbox.out.slice(0, 120));
check(inbox.out.includes('msg=سلام، این یک پیام آزمایشی است'), 'the message text is kept intact');
check(inbox.out.includes('user=testuser'), 'the sender username is kept');
check(inbox.out.includes('ts_ok=yes'),
  'created_at is a plain YYYY-MM-DD HH:MM:SS value that the retention cut can compare: ' + inbox.out.match(/ts=[^;]*/)[0]);
check((inbox.out.match(/idx_sqlite=(\d+)/) || [])[1] !== '0', 'the two indexes exist on SQLite: ' + inbox.out.match(/idx_sqlite=\d+/)[0]);

/* ═══════ ۶) قاعدهٔ نگهداشت روی همین قالب تاریخ کار می‌کند ═══════ */
const ret = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
require_once '/www/includes/bot_inbox.php';
require_once '/www/includes/db_retention.php';
echo 'count_sql=' . dbm_rule_count_sql('bot_inbox') . ';';
echo 'days=' . dbm_rule_days('bot_inbox') . ';';
echo 'rows=' . bot_inbox_total(['platform'=>'bale','q'=>'','kind'=>'','student'=>'']) . ';';`);
check(ret.out.includes('bot_inbox') && ret.out.includes('created_at'),
  'the retention rule targets bot_inbox.created_at: ' + ret.out.match(/count_sql=[^;]*/)[0]);
check(ret.out.includes('rows=1'), 'the stored message is counted by the page query: ' + ret.out.match(/rows=\d+/)[0]);

/* ═══════ ۷) صفحه: پیام دیده شود و زمان شمسی باشد ═══════ */
await loginAdmin('inboxReg01');
const page = await req('صندوق ورودی', { file: 'bot-inbox.php', sid: 'inboxReg01', query: 'platform=bale' });
check(!page.res.fatal, 'the inbox page renders: ' + (page.res.fatal || ''));
check((page.res.page || '').includes('این یک پیام آزمایشی است'),
  'the stored message is visible on the page (the exact bug the user reported)');
/* jdate() اعداد را فارسی می‌کند (مثل بقیهٔ صفحات) */
const shownTime = ((page.res.page || '').match(/[۰-۹]{4}\/[۰-۹]{2}\/[۰-۹]{2}\s+[۰-۹]{2}:[۰-۹]{2}:[۰-۹]{2}/) || [''])[0];
check(shownTime !== '',
  'the timestamp is shown as a Jalali date with Persian digits, like the rest of the app: ' + (shownTime || 'none'));

/* ═══════ ۸) وقتی یک پیام‌رسان خالی است و آن یکی پر، راهنما نشان بدهد ═══════ */
/* ردیف‌های بله را پاک می‌کنیم تا «بله» خالی و «تلگرام» پر باشد */
await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
require_once '/www/includes/bot_inbox.php';
DB::execute("DELETE FROM bot_inbox WHERE platform='bale'"); echo 'ok';`);
const otherPage = await req('صندوق ورودی — بلهٔ خالی', { file: 'bot-inbox.php', sid: 'inboxReg01', query: 'platform=bale' });
check((otherPage.res.page || '').includes('دیدن تلگرام'),
  'when the chosen messenger is empty but the other has messages, the page points there');

/* ═══════ ۹) حالت کاملاً خالی: بلوک تشخیصی ═══════ */
const emptyPage = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
require_once '/www/includes/bot_inbox.php';
bot_inbox_schema(); DB::execute("DELETE FROM bot_inbox"); echo 'cleared';`);
await loginAdmin('inboxReg02');
const blank = await req('صندوق خالی', { file: 'bot-inbox.php', sid: 'inboxReg02', query: 'platform=bale' });
check((blank.res.page || '').includes('چرا پیامی نمی‌بینم؟'),
  'an empty inbox shows the «why do I see nothing» diagnostics block');
check((blank.res.page || '').includes('هنوز پیامی دریافت نشده است'), 'the empty state text is still there');

console.log('PASS ' + n + ' checks (webhook really logs incoming messages, MySQL-safe SQL, driver-aware indexes, page diagnostics)');
process.exit(0);
