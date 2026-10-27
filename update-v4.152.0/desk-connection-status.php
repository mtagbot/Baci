<?php
/** Desktop UI heartbeat reads local state only. Never run a network sync inside a page worker. */
if (!defined('SDP_SYNC_ACTIVE') && (!empty($_REQUEST['sdp_sync']) || !empty($_REQUEST['sdp_z']) || !empty($_REQUEST['sdp_cid']) || !empty($_REQUEST['sdp_bridge']) || !empty($_POST['payload']) || !empty($_SERVER['HTTP_X_SDP_Z']))) {
    $__sdpHandler = is_file(__DIR__.'/includes/desk_sync_endpoint.php') ? (__DIR__.'/includes/desk_sync_endpoint.php') : (__DIR__.'/class-exam-sync-api.php');
    if (is_file($__sdpHandler)) { require $__sdpHandler; exit; }
}
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
    $now=time();
    /* v2.107.0: the worker heartbeat is one source of proof, the engine's own
       «last successful exchange» record is the other. Feeding both into the
       (unchanged, strict) reducer keeps the panel honest: a sync that really
       succeeded seconds ago always shows as successful, and a genuine failure
       still wins. */
    $hb=desk_connection_verified_heartbeat($hb,(int)DeskSync::getCfg('desk_sync_verified_at','0'),(int)DeskSync::getCfg('desk_sync_last_ok','0'),$now);
    $state=desk_connection_snapshot(DeskSync::enabled(),$hb,DeskSync::pendingCount(),(int)DeskSync::getCfg('desk_sync_last_ok','0'),DeskSync::getCfg('desk_sync_snapshot_done')==='1',$now,DeskSync::getCfg('desk_sync_err','')!=='');
    if(!$admin)$state['pending']=null; // Do not reveal school-wide record counts to students or teachers.
    echo json_encode($state,JSON_UNESCAPED_UNICODE);
} catch(Throwable $e) {
    http_response_code(503);echo '{"state":"unavailable"}'; // no SQL errors, server URLs, keys or user records
}
