<?php
// Run every minute using hosting Cron: php /absolute/path/reports/cron/bot-outbox-worker.php
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only');}
require_once dirname(__DIR__).'/includes/bot_helpers.php';
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
try { echo 'sent='.bot_outbox_drain(100,40).PHP_EOL; }
catch(Throwable $e){fwrite(STDERR,"Notification queue unavailable; messages retained.\n");exit(1);}
