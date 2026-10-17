<?php
// File: rubika-webhook.php
/**
 * Production Rubika Bot API v3 webhook endpoint.
 *
 * Rubika Bot API v3 posts Update and InlineMessage envelopes here;
 * bot_webhook_engine normalizes them into the shared workflow.
 */

define('BOT_PLATFORM', 'rubika');
require_once __DIR__ . '/includes/bot_webhook_engine.php';
