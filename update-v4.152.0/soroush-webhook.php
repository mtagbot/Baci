<?php
// File: soroush-webhook.php
/**
 * Production Soroush Plus webhook endpoint.
 *
 * Vazh: مستندات سروش پلاس آدرس وبهوک را روی پورت‌های 443/80/88/8443 قبول
 * می‌کند و بدنهٔ آپدیت همان شکل تلگرام است؛ پس همان موتور مشترک وب‌هوک
 * (bot_webhook_engine.php) با BOT_PLATFORM=soroush اجرا می‌شود.
 */

define('BOT_PLATFORM', 'soroush');
require_once __DIR__ . '/includes/bot_webhook_engine.php';
