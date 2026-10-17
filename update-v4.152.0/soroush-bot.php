<?php
// File: soroush-bot.php
/**
 * Soroush Plus bot management panel: token/webhook, targeted messaging,
 * templates/buttons. v4.185.0 — third messenger alongside Bale and Telegram.
 *
 * همهٔ منطق در includes/bot_admin_ui.php است؛ این فایل فقط پلتفرم را تعیین
 * می‌کند تا رفتار سه پیام‌رسان از یک مسیر واحد بیاید.
 */

require_once __DIR__ . '/includes/bot_admin_ui.php';
require_permission('send_sms');
bot_admin_handle_request('soroush');
require_once __DIR__ . '/includes/header.php';
bot_admin_render_page('soroush');
require_once __DIR__ . '/includes/footer.php';
