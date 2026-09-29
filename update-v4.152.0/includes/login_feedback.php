<?php
/** One-shot, role-bound feedback. Never retain passwords, serials or captcha answers. */
function login_post_string($key) {
    return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : '';
}

/* v4.174.0: پیام خطا فقط در نشست (flash) نگهداری می‌شد. اگر مرورگر کوکی نشست را
   نگه نداشته باشد — مرورگر داخلی پیام‌رسان‌ها (دکمهٔ «اپلیکیشن» بله)، حالت
   خصوصی، یا هر refresh وسط کار — کاربر صفحهٔ ورود را خالی می‌بیند و نمی‌فهمد
   چرا وارد نشد. حالا کد خطا در خود آدرس هم می‌آید (مانند admin-login.php) و
   پیام حتی بدون نشست هم نمایش داده می‌شود. */
function login_error_codes($role) {
    $common = [
        'csrf'     => 'اعتبار فرم به پایان رسیده است. همین صفحه تازه شده؛ اطلاعات را دوباره وارد و ارسال کنید.',
        'captcha'  => 'پاسخ سؤال امنیتی درست نیست یا اعتبار آن تمام شده است. پاسخ سؤال نمایش‌داده‌شده را وارد کنید.',
        'throttle' => 'تلاش‌های ناموفق زیاد بوده است. لطفاً ۱۰ دقیقه صبر کنید و دوباره تلاش کنید؛ در صورت تکرار، با مدرسه تماس بگیرید.',
        'fields'   => 'کد ملی و رمز ورود را وارد کنید.',
    ];
    if ($role === 'inquiry') {
        $common['fields']  = 'کد ملی و سریال ثبت‌شده یا رمز ورود را وارد کنید.';
        $common['badpass'] = 'کد ملی یا سریال ثبت‌شده / رمز ورود مطابقت ندارد، یا دسترسی به حساب امکان‌پذیر نیست. سریال را مطابق ثبت مدرسه وارد کنید؛ نبودن کارنامه پس از ورود موفق، جداگانه اعلام می‌شود.';
        return $common;
    }
    if ($role === 'teacher') {
        $common['badpass'] = 'کد ملی یا رمز ورود دبیر نادرست است. کد پرسنلی یا رمزی که مدرسه برای شما ثبت کرده را وارد کنید — هر دو پذیرفته می‌شوند. اگر حساب شما غیرفعال است نیز این پیام را می‌بینید؛ برای بررسی با مدرسه تماس بگیرید.';
        return $common;
    }
    $common['badpass'] = 'کد ملی یا رمز ورود درست نیست، یا ورود به این حساب امکان‌پذیر نیست. رمزی که مدرسه برای شما ثبت کرده (یا شمارهٔ شناسنامهٔ شما) را وارد کنید؛ اگر رمز تغییر کرده است، از رمز جدید استفاده کنید. اگر همچنان وارد نشدید، به مدرسه اطلاع دهید تا وضعیت حساب بررسی شود.';
    return $common;
}

function login_error($role, $message, $fields = [], $code = 'badpass') {
    $_SESSION['login_retry'] = ['role' => $role, 'identity' => mb_substr(trim(login_post_string('national_id')), 0, 100), 'fields' => $fields];
    set_flash_message('error', $message);
    /* کد خطا در آدرس: پیام حتی با از دست رفتن نشست هم دیده می‌شود. */
    $code = preg_replace('/[^a-z_]/', '', (string)$code) ?: 'badpass';
    redirect('index.php?view=login&tab=' . $role . '&err=' . $code);
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
