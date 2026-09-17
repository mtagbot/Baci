<?php
/** Desktop UI heartbeat reads local state only. Never run a network sync inside a page worker. */
$release=is_file(__DIR__.'/config/release.php')?require __DIR__.'/config/release.php':[];
if(($release['distribution']??(PHP_SAPI==='cli-server'?'desktop':'site'))!=='desktop'){http_response_code(404);exit;}
require_once __DIR__.'/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
if(!is_admin_logged_in()&&!is_student_logged_in()&&!(function_exists('is_teacher_logged_in')&&is_teacher_logged_in())){
    http_response_code(401);echo '{"state":"signed-out"}';exit;
}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);header('Allow: GET');exit;}
$admin=is_admin_logged_in();
// Release the login-session lock before local status queries.
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
try {
    require_once __DIR__.'/includes/desk_sync.php';
    require_once __DIR__.'/includes/desk_connection.php';
    $path=dirname(__DIR__).'/data/sync-heartbeat.json';$hb=null;
    if(is_file($path)&&filesize($path)<65536)$hb=json_decode((string)file_get_contents($path),true);
    $state=desk_connection_snapshot(DeskSync::enabled(),$hb,DeskSync::pendingCount(),(int)DeskSync::getCfg('desk_sync_last_ok','0'),DeskSync::getCfg('desk_sync_snapshot_done')==='1',time(),DeskSync::getCfg('desk_sync_err','')!=='');
    if(!$admin)$state['pending']=null; // Do not reveal school-wide record counts to students or teachers.
    echo json_encode($state,JSON_UNESCAPED_UNICODE);
} catch(Throwable $e) {
    http_response_code(503);echo '{"state":"unavailable"}'; // no SQL errors, server URLs, keys or user records
}
