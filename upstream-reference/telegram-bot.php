<?php
// File: telegram-bot.php
/**
 * Telegram bot management panel: token/webhook, targeted messaging, templates/buttons.
 */

require_once __DIR__ . '/includes/bot_admin_ui.php';
require_permission('send_sms');
bot_admin_handle_request('telegram');
require_once __DIR__ . '/includes/header.php';
bot_admin_render_page('telegram');
require_once __DIR__ . '/includes/footer.php';
