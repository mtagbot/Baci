<?php
/** Password confirmations are single-action, never cached; throttle by authenticated actor. */
function security_confirm_password($action, callable $verify) {
    $u=st_current_user();if(!$u)return false;
    DB::execute('CREATE TABLE IF NOT EXISTS security_confirm_attempts (actor_key VARCHAR(64) PRIMARY KEY, attempts INT NOT NULL DEFAULT 0, window_start INT NOT NULL)');
    $key=hash('sha256',$action.'|'.$u[0].'|'.$u[1]);$now=time();
    try {DB::execute('INSERT INTO security_confirm_attempts(actor_key,attempts,window_start) VALUES (?,0,?)',[$key,$now]);}
    catch(PDOException $e){if(!DB::fetch('SELECT actor_key FROM security_confirm_attempts WHERE actor_key=?',[$key]))throw $e;}
    DB::execute('UPDATE security_confirm_attempts SET attempts=0,window_start=? WHERE actor_key=? AND window_start<?',[$now,$key,$now-600]);
    // Reserve an attempt atomically before hashing, including concurrent sessions of the same actor.
    $used=DB::query('UPDATE security_confirm_attempts SET attempts=attempts+1 WHERE actor_key=? AND attempts<5',[$key]);
    if(!$used || $used->rowCount()!==1)return false;
    if(!$verify())return false;
    DB::execute('DELETE FROM security_confirm_attempts WHERE actor_key=?',[$key]);
    return true;
}
function attendance_confirm_admin($password,$username='') {
    return security_confirm_password('attendance-scanner-key',function()use($password,$username){
        if(!is_string($password) || $password==='' || strlen($password)>4096)return false;
        if(is_admin_logged_in())$admin=DB::fetch('SELECT * FROM admins WHERE id=? AND status=1',[(int)$_SESSION['admin_id']]);
        else {
            if(!is_string($username) || trim($username)==='')return false;
            $admin=DB::fetch('SELECT * FROM admins WHERE username=? AND status=1',[trim($username)]);
        }
        if(!$admin)return false;
        $perms=json_decode($admin['permissions']??'[]',true)?:[];
        if($admin['role']!=='super_admin' && !in_array('all',$perms,true) && !in_array('manage_students',$perms,true))return false;
        return verify_user_password($password,(string)$admin['password'],['table'=>'admins','id'=>$admin['id']]);
    });
}
