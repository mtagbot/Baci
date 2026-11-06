import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const engine = readFileSync(new URL('../update-v4.214.3/includes/bot_webhook_engine.php', import.meta.url), 'utf8');
const markFn = engine.slice(engine.indexOf('function bot_webhook_mark_report_reviewed'), engine.indexOf('\n}\n', engine.indexOf('function bot_webhook_mark_report_reviewed')) + 3);
const reportCallback = engine.slice(engine.indexOf('bot_send_report_image_to_chat($platform, $chatId, $repId);'));
const explicitAck = engine.slice(engine.indexOf("if ($chatId !== '' && strpos($data, 'ack_rep_')"), engine.indexOf("if ($chatId !== '' && strpos($data, 'ack_disc_')"));

assert.match(markFn, /bot_user_table\(\$platform\)/, 'review is tied to this platform link table');
assert.match(markFn, /bot_chat_column\(\$platform\)/, 'review is tied to this platform chat id');
assert.match(markFn, /JOIN `\$users` b ON b\.student_id=r\.student_id WHERE r\.id=\? AND b\.`\$chatCol`=\?/, 'a chat can only acknowledge its linked student report');
assert.match(markFn, /INSERT INTO report_parent_reviews[\s\S]*?ON DUPLICATE KEY UPDATE/, 'review write is idempotent');
assert.match(reportCallback, /^bot_send_report_image_to_chat\(\$platform, \$chatId, \$repId\);[\s\S]*?bot_webhook_mark_report_reviewed\(\$repId, \$platform, \$chatId\);/, 'choosing a report month marks that exact report only after delivery succeeds');
assert.match(explicitAck, /bot_webhook_mark_report_reviewed\(\$repId, \$platform, \$chatId\)/, 'the existing explicit acknowledge button remains supported');
assert.match(engine, /if \(\$platform === 'rubika'\) \$update = bot_rubika_normalize_update\(\$update\)/, 'Rubika normalized callbacks still reach the shared handler');
console.log('✅ report-view-auto-ack: 7 checks passed (Bale, Telegram, Rubika use the shared, platform-scoped workflow)');
