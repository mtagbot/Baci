<?php
/** One-shot, role-bound feedback. Never retain passwords, serials or captcha answers. */
function login_post_string($key) {
    return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : '';
}
function login_error($role, $message, $fields=[]) {
    $_SESSION['login_retry']=['role'=>$role,'identity'=>mb_substr(trim(login_post_string('national_id')),0,100),'fields'=>$fields];
    set_flash_message('error',$message);
    redirect('index.php?view=login&tab='.$role);
}
function login_require_fields($role) {
    $secret=$role==='inquiry'?'serial_number':'password';$missing=[];
    if(trim(login_post_string('national_id'))==='')$missing[]='national_id';
    if(login_post_string($secret)==='')$missing[]=$secret;
    if(!$missing)return;
    login_guard_fail($role,tr_num(trim(login_post_string('national_id')),'en'));
    record_failed_login();
    login_error($role,$role==='inquiry'?'کد ملی و سریال ثبت‌شده یا رمز ورود را وارد کنید.':'کد ملی و رمز ورود را وارد کنید.',$missing);
}
function login_feedback_markup($role,$active,$flash,$retry) {
    if(!$flash || $active!==$role)return;
    $isError=($flash['type']??'')==='error';
    $title=$isError?($role==='inquiry'?'استعلام انجام نشد':'ورود انجام نشد'):'پیام سامانه';
    echo '<div class="auth-feedback'.($isError?' auth-feedback-error':'').'" id="'.$role.'Feedback" role="'.($isError?'alert':'status').'" tabindex="-1"><strong>'.clean($title).'</strong><p>'.nl2br(clean($flash['message']??'')).'</p></div>';
}
function login_field_attributes($role,$field,$active,$flash,$retry) {
    $out='';
    if($flash && $role===$active)$out.=' aria-describedby="'.$role.'Feedback"';
    if(($retry['role']??'')===$role){
        if(in_array($field,$retry['fields']??[],true))$out.=' aria-invalid="true"';
        if($field==='national_id')$out.=' value="'.clean($retry['identity']??'').'"';
    }
    return $out;
}
