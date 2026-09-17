<?php
/** Import API: same validated transaction as the Wizard, never simulated completion. */
require_once dirname(__DIR__).'/includes/auth.php';
require_once dirname(__DIR__).'/includes/report_import_wizard.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function ri_api_reply($code,$data) { http_response_code($code); echo json_encode($data,JSON_UNESCAPED_UNICODE); exit; }
$adminId=0; $bearer=false;
if (is_admin_logged_in() && has_permission('import_data')) { $active=current_admin(); if ($active && (int)$active['status']===1) $adminId=(int)$active['id']; }
if (!$adminId) {
    $authorization=$_SERVER['HTTP_AUTHORIZATION']??'';
    if (preg_match('/^Bearer\s+(\S+)$/i',$authorization,$m)) {
        $token=DB::fetch("SELECT user_id FROM api_tokens WHERE access_token=? AND user_type='admin' AND expires_at>NOW()",[$m[1]]);
        $admin=$token?DB::fetch('SELECT id,role,permissions FROM admins WHERE id=? AND status=1',[$token['user_id']]):null;
        $permissions=$admin?(json_decode($admin['permissions']??'[]',true)?:[]):[];
        if ($admin && ($admin['role']==='super_admin' || in_array('all',$permissions,true) || in_array('import_data',$permissions,true))) { $adminId=(int)$admin['id']; $bearer=true; }
    }
}
if (!$adminId) ri_api_reply(403,['status'=>'error','message'=>'دسترسی غیرمجاز']);
$resource=is_string($_GET['resource']??null)?$_GET['resource']:'';
if (in_array($resource,['preview-execute','resolve-student-edit','queue-process'],true)) {
    if ($_SERVER['REQUEST_METHOD']!=='POST') ri_api_reply(405,['status'=>'error','message'=>'برای تغییر داده، درخواست POST لازم است.']);
    if (!$bearer && (!is_string($_POST['csrf_token']??null) || !verify_csrf($_POST['csrf_token']))) ri_api_reply(403,['status'=>'error','message'=>'اعتبار فرم منقضی شده است.']);
    // The old queue/ambiguity handlers only changed status; do not pretend they imported any grades.
    if ($resource!=='preview-execute') ri_api_reply(409,['status'=>'error','message'=>'این عملیات قدیمی پردازش واقعی نداشت؛ فایل اصلاح‌شده را در Wizard پیش‌نمایش و تأیید کنید.']);
    try {
        $count=ri_execute((int)ri_text($_POST['session_id']??0),$adminId,ri_text($_POST['target_academic_year']??''));
        ri_api_reply(200,['status'=>'success','message'=>'نمرات ثبت شد.','processed_rows'=>$count]);
    } catch (InvalidArgumentException $e) { ri_api_reply(409,['status'=>'error','message'=>$e->getMessage()]); }
    catch (Throwable $e) { error_log('Import API failed: '.$e->getMessage()); ri_api_reply(500,['status'=>'error','message'=>'ثبت انجام نشد؛ تغییرات این درخواست بازگردانده شد.']); }
}
if ($resource==='sessions') ri_api_reply(200,['status'=>'success','data'=>DB::fetchAll('SELECT id,filename,status,total_rows,processed_rows,created_at FROM import_sessions WHERE admin_id=? ORDER BY id DESC LIMIT 50',[$adminId])]);
if ($resource==='queue') ri_api_reply(200,['status'=>'success','data'=>DB::fetchAll('SELECT q.*,s.filename FROM import_queue q JOIN import_sessions s ON q.session_id=s.id WHERE s.admin_id=? ORDER BY q.id DESC LIMIT 50',[$adminId])]);
if ($resource==='preview-state') {
    $id=is_scalar($_GET['session_id']??null)?(int)$_GET['session_id']:0;
    $s=DB::fetch('SELECT * FROM import_sessions WHERE id=? AND admin_id=?',[$id,$adminId]);
    if (!$s) ri_api_reply(404,['status'=>'error','message'=>'نشست یافت نشد.']);
    ri_api_reply(200,['status'=>'success','session'=>$s,'preview'=>json_decode($s['preview_data']??'[]',true),'ambiguities'=>json_decode($s['ambiguities_data']??'[]',true)]);
}
ri_api_reply(400,['status'=>'error','message'=>'منبع نامعتبر']);
