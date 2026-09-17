/* Progressive feedback: server-rendered messages remain usable without JavaScript. */
(function(d){'use strict';
 var error=d.querySelector('.auth-feedback[role="alert"]');
 if(error)try{error.focus();}catch(ignore){}
 var forms=d.querySelectorAll('.auth-panel');
 for(var i=0;i<forms.length;i++){
  forms[i].addEventListener('invalid',function(e){
   var el=e.target;if(!el.setCustomValidity)return;
   el.setCustomValidity('');
   if(el.validity.valueMissing)el.setCustomValidity(el.name==='national_id'?'کد ملی را وارد کنید.':el.name==='captcha'?'پاسخ سؤال امنیتی را وارد کنید.':el.name==='serial_number'?'سریال ثبت‌شده یا رمز ورود را وارد کنید.':'رمز ورود را وارد کنید.');
  },true);
  forms[i].addEventListener('input',function(e){if(e.target.setCustomValidity)e.target.setCustomValidity('');e.target.removeAttribute('aria-invalid');});
 }
})(document);
