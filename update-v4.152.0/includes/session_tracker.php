<?php
/**
 * includes/session_tracker.php — v4.68.0
 * ردیابی نشست‌های فعال هر کاربر (مدیر / دبیر / دانش‌آموز) روی همه دستگاه‌ها.
 * هر بارگذاری صفحه، رکورد نشست جاری upsert می‌شود (نام دستگاه، سیستم‌عامل،
 * مرورگر، IP و آخرین فعالیت). صفحه my-sessions.php لیست را نشان می‌دهد و
 * امکان بستن نشست هر دستگاه را می‌دهد: session_id هدف در بلک‌لیست جدول
 * علامت می‌خورد و اولین درخواست بعدی آن دستگاه با session_destroy خارج می‌شود.
 */

if (!function_exists('ensure_user_sessions_schema')) {
    function ensure_user_sessions_schema() {
        static $done = false;
        if ($done) return;
        if(!DB::getInstance()->getPdo())throw new RuntimeException('Database unavailable');

        try {
            try { DB::fetch('SELECT remember_hash FROM user_sessions LIMIT 1'); $done=true; return; } catch(PDOException $e) {}
            DB::execute("CREATE TABLE IF NOT EXISTS user_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session_id VARCHAR(128) NOT NULL,
                user_type VARCHAR(20) NOT NULL,
                user_id INT NOT NULL,
                user_name VARCHAR(190) DEFAULT NULL,
                device_label VARCHAR(190) DEFAULT NULL,
                os_name VARCHAR(80) DEFAULT NULL,
                browser_name VARCHAR(80) DEFAULT NULL,
                ip_address VARCHAR(64) DEFAULT NULL,
                user_agent TEXT,
                remember_hash VARCHAR(64) DEFAULT NULL,
                is_revoked TINYINT(1) NOT NULL DEFAULT 0,
                created_at_jalali VARCHAR(30) DEFAULT NULL,
                last_seen_at INT NOT NULL DEFAULT 0,
                last_seen_jalali VARCHAR(40) DEFAULT NULL,
                UNIQUE KEY uq_session (session_id),
                KEY idx_user (user_type, user_id),
                KEY idx_seen (last_seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            try { DB::fetch('SELECT remember_hash FROM user_sessions LIMIT 1'); } catch(PDOException $e) { DB::execute('ALTER TABLE user_sessions ADD COLUMN remember_hash varchar(64) DEFAULT NULL'); }
            $done=true;
        } catch (Throwable $e) { throw $e; }
    }
}

if (!function_exists('st_parse_user_agent')) {
    /** تشخیص سیستم‌عامل، مرورگر و نوع دستگاه از User-Agent (بدون کتابخانه خارجی) */
    function st_parse_user_agent($ua) {
        $ua = (string)$ua;
        // OS
        $os = 'نامشخص';
        if (preg_match('/Windows NT 11/i', $ua)) $os = 'Windows 11';
        elseif (preg_match('/Windows NT 10/i', $ua)) $os = 'Windows 10/11';
        elseif (preg_match('/Windows NT 6\.3/i', $ua)) $os = 'Windows 8.1';
        elseif (preg_match('/Windows NT 6\.1/i', $ua)) $os = 'Windows 7';
        elseif (preg_match('/Windows/i', $ua)) $os = 'Windows';
        elseif (preg_match('/Android\s*([\d.]+)?/i', $ua, $m)) $os = 'Android' . (isset($m[1]) && $m[1] !== '' ? ' ' . $m[1] : '');
        elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) $os = 'iOS';
        elseif (preg_match('/Mac OS X/i', $ua)) $os = 'macOS';
        elseif (preg_match('/Linux/i', $ua)) $os = 'Linux';
        // Browser (order matters)
        $br = 'نامشخص';
        if (preg_match('/Edg(?:e|A|iOS)?\/([\d.]+)/i', $ua, $m)) $br = 'Edge ' . strtok($m[1], '.');
        elseif (preg_match('/OPR\/([\d.]+)/i', $ua, $m)) $br = 'Opera ' . strtok($m[1], '.');
        elseif (preg_match('/SamsungBrowser\/([\d.]+)/i', $ua, $m)) $br = 'Samsung Internet ' . strtok($m[1], '.');
        elseif (preg_match('/Firefox\/([\d.]+)/i', $ua, $m)) $br = 'Firefox ' . strtok($m[1], '.');
        elseif (preg_match('/CriOS\/([\d.]+)/i', $ua, $m)) $br = 'Chrome iOS ' . strtok($m[1], '.');
        elseif (preg_match('/Chrome\/([\d.]+)/i', $ua, $m)) $br = 'Chrome ' . strtok($m[1], '.');
        elseif (preg_match('/Version\/([\d.]+).*Safari/i', $ua, $m)) $br = 'Safari ' . strtok($m[1], '.');
        elseif (preg_match('/Safari/i', $ua)) $br = 'Safari';
        // Device label
        $dev = 'رایانه دسکتاپ';
        if (preg_match('/iPad/i', $ua)) $dev = 'تبلت iPad';
        elseif (preg_match('/iPhone/i', $ua)) $dev = 'گوشی iPhone';
        elseif (preg_match('/Android/i', $ua)) {
            $dev = preg_match('/Mobile/i', $ua) ? 'گوشی اندروید' : 'تبلت اندروید';
            if (preg_match('/;\s*([^;)]{2,40})\s+Build\//i', $ua, $m)) $dev .= ' (' . trim($m[1]) . ')';
        } elseif (preg_match('/Mobile/i', $ua)) $dev = 'موبایل';
        elseif (preg_match('/Macintosh/i', $ua)) $dev = 'رایانه Mac';
        elseif (preg_match('/Windows/i', $ua)) $dev = 'رایانه ویندوزی';
        elseif (preg_match('/Linux/i', $ua)) $dev = 'رایانه لینوکسی';
        return ['os' => $os, 'browser' => $br, 'device' => $dev];
    }
}

function st_current_user() {
    foreach(['admin','teacher','student'] as $type)if(!empty($_SESSION[$type.'_id']))return [$type,(int)$_SESSION[$type.'_id'],(string)($_SESSION[$type.'_name']??$type)];
    return null;
}
function st_session_key() {return (string)($_SESSION['st_origin_sid']??session_id());}
function st_json_request() {
    return basename($_SERVER['SCRIPT_NAME']??'')==='session-status.php' || strpos(strtolower($_SERVER['HTTP_ACCEPT']??''),'application/json')!==false || strpos(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json')!==false || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='xmlhttprequest' || strpos(basename($_SERVER['SCRIPT_NAME']??''),'-api.php')!==false;
}
function st_expire_cookie($name) {
    if(!headers_sent())setcookie($name,'',['expires'=>time()-42000,'path'=>'/','secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'),'httponly'=>true,'samesite'=>'Lax']);
    unset($_COOKIE[$name]);
}
function st_deny_revoked() {
    // Keep the tombstone: an old PHP session or persistent cookie must never recreate it.
    $_SESSION=[];st_expire_cookie('school_remember');st_expire_cookie(session_name());
    if(session_status()===PHP_SESSION_ACTIVE)session_destroy();
    if(st_json_request()){
        if(!headers_sent()){http_response_code(401);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');}
        echo json_encode(['ok'=>false,'authenticated'=>false,'error'=>'نشست شما خاتمه یافته است؛ دوباره وارد شوید.'],JSON_UNESCAPED_UNICODE);exit;
    }
    redirect('index.php?view=login&session_closed=1');
}
function st_unavailable($e) {
    error_log('session validation unavailable: '.$e->getMessage());
    if(!headers_sent()){http_response_code(503);header('Cache-Control: no-store');}
    if(st_json_request())echo json_encode(['ok'=>false,'error'=>'بررسی نشست موقتاً ممکن نیست؛ دوباره تلاش کنید.'],JSON_UNESCAPED_UNICODE);
    else echo 'بررسی امنیت نشست موقتاً ممکن نیست؛ صفحه را دوباره باز کنید.';
    exit;
}
function track_user_session() {
    $u=st_current_user();if(!$u)return;
    [$type,$uid,$uname]=$u;$sid=st_session_key();if(!$sid || $uid<=0)return;
    try {
        ensure_user_sessions_schema();
        $row=DB::fetch('SELECT * FROM user_sessions WHERE session_id=?',[$sid]);
        if($row && ((int)$row['is_revoked']===1 || $row['user_type']!==$type || (int)$row['user_id']!==$uid))st_deny_revoked();
        $now=time();$nowJ=function_exists('jdate')?jdate('Y/m/d H:i'):date('Y/m/d H:i');$ua=(string)($_SERVER['HTTP_USER_AGENT']??'');$ip=substr((string)($_SERVER['REMOTE_ADDR']??''),0,64);
        if(!$row){
            // A restored session may not silently lose its authoritative device record.
            if(!empty($_SESSION['st_origin_sid']))st_deny_revoked();
            $info=st_parse_user_agent($ua);
            DB::execute('INSERT INTO user_sessions(session_id,user_type,user_id,user_name,device_label,os_name,browser_name,ip_address,user_agent,created_at_jalali,last_seen_at,last_seen_jalali) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[$sid,$type,$uid,$uname,$info['device'],$info['os'],$info['browser'],$ip,substr($ua,0,2000),$nowJ,$now,$nowJ]);
        }elseif($now-(int)$row['last_seen_at']>=60){
            DB::execute('UPDATE user_sessions SET last_seen_at=?,last_seen_jalali=?,ip_address=? WHERE session_id=? AND is_revoked=0',[$now,$nowJ,$ip,$sid]);
        }
    }catch(Throwable $e){st_unavailable($e);}
}
function st_forget_remember() {
    if(st_current_user()){
        ensure_user_sessions_schema();
        DB::execute('UPDATE user_sessions SET is_revoked=1,remember_hash=NULL WHERE session_id=?',[st_session_key()]);
    }
    st_expire_cookie('school_remember');
}
function st_issue_remember($type,$id) {
    track_user_session();
    $u=st_current_user();if(!$u || $u[0]!==$type || $u[1]!=(int)$id)return;
    $exp=time()+30*86400;$sid=st_session_key();
    $payload=$type.'|'.(int)$id.'|'.$exp.'|'.$sid.'|'.bin2hex(random_bytes(24));
    $token=rtrim(strtr(base64_encode($payload.'|'.hash_hmac('sha256',$payload,remember_secret())),'+/','-_'),'=');
    DB::execute('UPDATE user_sessions SET remember_hash=? WHERE session_id=? AND is_revoked=0',[hash('sha256',$token),$sid]);
    if(!headers_sent())setcookie('school_remember',$token,['expires'=>$exp,'path'=>'/','secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'),'httponly'=>true,'samesite'=>'Lax']);
    $_COOKIE['school_remember']=$token;
}
function st_restore_remember() {
    if(st_current_user() || empty($_COOKIE['school_remember']))return;
    $token=$_COOKIE['school_remember'];if(!is_string($token) || strlen($token)>1024){st_expire_cookie('school_remember');return;}
    $raw=base64_decode(strtr($token,'-_','+/'),true);$p=$raw===false?[]:explode('|',$raw);
    // Legacy stateless cookies cannot identify a revoked device: require a new password login.
    if(count($p)!==6){st_expire_cookie('school_remember');return;}
    [$type,$id,$exp,$sid,$nonce,$sig]=$p;$payload=implode('|',array_slice($p,0,5));
    if(!in_array($type,['admin','teacher','student'],true) || !ctype_digit($id) || !ctype_digit($exp) || (int)$exp<time() || !preg_match('/^[a-zA-Z0-9,-]{1,128}$/D',$sid) || !hash_equals(hash_hmac('sha256',$payload,remember_secret()),$sig)){st_expire_cookie('school_remember');return;}
    ensure_user_sessions_schema();
    $row=DB::fetch('SELECT * FROM user_sessions WHERE session_id=?',[$sid]);
    if(!$row || (int)$row['is_revoked'] || $row['user_type']!==$type || (int)$row['user_id']!==(int)$id || !hash_equals((string)$row['remember_hash'],hash('sha256',$token)))st_deny_revoked();
    $table=['admin'=>'admins','teacher'=>'teachers','student'=>'students'][$type];
    $u=DB::fetch("SELECT * FROM $table WHERE id=? AND status=?",[(int)$id,$type==='student'?'active':1]);
    if(!$u){st_expire_cookie('school_remember');return;}
    if(session_status()===PHP_SESSION_ACTIVE)@session_regenerate_id(true);
    $_SESSION[$type.'_id']=(int)$id;$_SESSION['auth_role']=$type;$_SESSION['st_origin_sid']=$sid;
    if($type==='admin'){$_SESSION['admin_username']=$u['username'];$_SESSION['admin_name']=$u['name'];$_SESSION['admin_role']=$u['role'];}
    elseif($type==='teacher'){$_SESSION['teacher_name']=$u['full_name'];$_SESSION['teacher_nid']=$u['national_id'];}
    else{$_SESSION['student_name']=$u['first_name'].' '.$u['last_name'];$_SESSION['student_nid']=$u['national_id'];}
}
function st_bootstrap() {
    try {
        track_user_session(); // Before restoration or ANY page/API mutation.
        if(!st_current_user()){st_restore_remember();track_user_session();}
        // Upgrade a still-authenticated device's legacy cookie without issuing another login.
        $token=$_COOKIE['school_remember']??'';
        if(st_current_user() && is_string($token) && $token!=='' && count(explode('|',(string)base64_decode(strtr($token,'-_','+/'),true)))===4){$u=st_current_user();st_issue_remember($u[0],$u[1]);}
    }catch(Throwable $e){st_unavailable($e);}
}
