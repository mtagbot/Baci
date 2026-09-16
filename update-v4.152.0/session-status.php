<?php
// Read-only heartbeat; functions.php enforces device revocation before this handler.
require_once __DIR__.'/includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$authenticated=st_current_user()!==null;
if(!$authenticated)http_response_code(401);
echo json_encode(['authenticated'=>$authenticated]);
