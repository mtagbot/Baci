<?php
// File: rubika-bot.php
/**
 * Rubika bot management panel: token/webhook, targeted messaging,
 * templates/buttons. v4.186.0 — Rubika replaces Soroush Plus alongside Bale and Telegram.
 *
 * همهٔ منطق در includes/bot_admin_ui.php است؛ این فایل فقط پلتفرم را تعیین
 * می‌کند تا رفتار سه پیام‌رسان از یک مسیر واحد بیاید.
 */

require_once __DIR__ . '/includes/bot_admin_ui.php';
require_permission('send_sms');
bot_admin_handle_request('rubika');
require_once __DIR__ . '/includes/header.php';
bot_admin_render_page('rubika');
require_once __DIR__ . '/includes/footer.php';
