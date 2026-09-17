/* Local-only monitor. The daemon owns remote I/O, retries and synchronization. */
(function(w,d){'use strict';
 if(w.self!==w.top)return; // The containing application owns the only connection monitor.
 var root=d.getElementById('deskConnection');if(!root||root.getAttribute('data-ready'))return;
 root.setAttribute('data-ready','1');
 var monitor=root.getAttribute('data-monitor')==='1',timer=null,xhr=null,pending=0,innerNav=false,stopped=false,last=null;
 var labels={checking:'در حال بررسی اتصال',synced:'همگام با سرور',syncing:'در حال همگام‌سازی',queued:'تغییرات در صف ارسال',retrying:'در انتظار اتصال به سرور',warning:'همگام‌سازی کامل تأیید نشد',error:'همگام‌سازی نیاز به بررسی دارد',waiting:'در انتظار وضعیت همگام‌سازی',offline:'شبکهٔ دستگاه قطع است',unavailable:'وضعیت در دسترس نیست','signed-out':'برای بررسی وضعیت وارد شوید',disabled:'همگام‌سازی غیرفعال است'};
 function text(id,value){var el=d.getElementById(id);if(el&&el.textContent!==value)el.textContent=value;}
 function paint(j){
  last=j;var offline=w.navigator.onLine===false,state=offline?'offline':j.state;
  if(!labels[state])state='unavailable';root.setAttribute('data-state',state);
  text('deskConnectionLabel',labels[state]);
  text('deskConnectionNetwork',offline?'شبکهٔ دستگاه: قطع؛ تغییرات محلی محفوظ می‌مانند.':'شبکهٔ دستگاه: در دسترس طبق مرورگر؛ این به‌تنهایی تأیید اتصال اینترنت نیست.');
  text('deskConnectionServer',offline?'سرور مدرسه: اتصال فعلاً تأیید نمی‌شود.':j.server==='reachable'?'سرور مدرسه: پاسخ معتبر دریافت شده است.':j.server==='unreachable'?'سرور مدرسه: در دسترس نیست؛ اینترنت یا تنظیمات سرور را بررسی کنید.':'سرور مدرسه: اتصال هنوز تأیید نشده است.');
  var detail=state==='disabled'?'برای ارسال و دریافت خودکار، همگام‌سازی را در تنظیمات فعال کنید.':state==='signed-out'?'برای دریافت وضعیت زنده وارد حساب خود شوید.':state==='waiting'?'موفقیت همگام‌سازی هنوز تأیید نشده است؛ در صورت تداوم، سرویس پس‌زمینه و تنظیمات را بررسی کنید.':state==='warning'?'موتور همگام‌سازی هشدار دارد؛ ممکن است بخشی از جدول‌ها یا فایل‌ها منتقل نشده باشد. مدیر مدرسه باید جزئیات را بررسی کند.':state==='error'?'همگام‌سازی تأیید نشد. مدیر مدرسه باید کلید، آدرس سرور و گزارش خطا را بررسی کند.':state==='syncing'?'سرویس پس‌زمینه در حال بررسی یا تبادل داده با سرور است.':'تغییرات ابتدا در بانک اطلاعاتی همین دستگاه نگهداری می‌شوند.';
  if(typeof j.pending==='number'){pending=Math.max(0,j.pending);if(pending>0)detail+=' '+pending+' تغییر هنوز ارسال نشده است.';}
  if(j.retry_in>0)detail+=' تلاش بعدی سرور حداکثر حدود '+j.retry_in+' ثانیه دیگر است.';
  text('deskConnectionDetail',detail);
  var date=j.last_success?new Date(j.last_success*1000):null,when='هنوز ثبت نشده';
  if(date&&!isNaN(date.getTime()))try{when=date.toLocaleString('fa-IR');}catch(ignore){when=date.toLocaleString();}
  text('deskConnectionTime',(j.warning?'آخرین اجرای ثبت‌شده؛ تأیید کامل نشده: ':'آخرین همگام‌سازی موفق: ')+when);
 }
 function schedule(ms){w.clearTimeout(timer);if(monitor&&!stopped&&!d.hidden)timer=w.setTimeout(poll,ms);}
 function poll(){
  if(!monitor||stopped||d.hidden||xhr)return;
  // navigator.onLine is only a hint. Even offline, local state is readable without internet.
  xhr=new XMLHttpRequest();var req=xhr;req.open('GET','desk-connection-status.php',true);req.timeout=3500;
  function done(){
   if(xhr!==req)return;xhr=null;
   if(req.status===401){monitor=false;paint({state:'signed-out'});return;}
   var j=null;if(req.status===200)try{j=JSON.parse(req.responseText);}catch(ignore){}
   if(j&&typeof j.state==='string')paint(j);else paint({state:'unavailable',pending:pending,last_success:last&&last.last_success});
   schedule(j?5000:15000);
  }
  req.onload=done;req.onerror=done;req.ontimeout=done;
  try{req.send();}catch(ignore){done();}
 }
 function resume(){if(stopped)return;if(last)paint(last);if(monitor&&!d.hidden&&!xhr)schedule(100);}
 d.addEventListener('visibilitychange',function(){root.classList.toggle('is-paused',!!d.hidden);if(d.hidden)w.clearTimeout(timer);else resume();});
 w.addEventListener('online',resume);w.addEventListener('offline',resume);w.addEventListener('focus',resume);
 d.addEventListener('keydown',function(e){if(e.keyCode===27&&root.open){root.open=false;root.querySelector('summary').focus();}});
 d.addEventListener('click',function(e){if(!root.contains(e.target))root.open=false;},true);
 // Warn only on a real close with unsent changes, not normal in-app navigation.
 d.addEventListener('click',function(e){var a=e.target;while(a&&a.tagName!=='A')a=a.parentElement;if(a&&a.host===w.location.host&&(!a.target||a.target==='_self')&&!a.hasAttribute('download')){innerNav=true;w.setTimeout(function(){innerNav=false;},4000);}},true);
 d.addEventListener('submit',function(){innerNav=true;w.setTimeout(function(){innerNav=false;},4000);},true);
 w.addEventListener('beforeunload',function(e){if(pending>0&&!innerNav){var msg='تغییرات ارسال‌نشده در بانک اطلاعاتی این دستگاه محفوظ است و پس از برقراری اتصال و فعال بودن همگام‌سازی، ارسال خواهد شد.';e.preventDefault();e.returnValue=msg;return msg;}});
 w.addEventListener('pagehide',function(){stopped=true;w.clearTimeout(timer);if(xhr){var req=xhr;xhr=null;req.abort();}});
 w.addEventListener('pageshow',function(e){if(e.persisted){stopped=false;resume();}});
 paint({state:monitor?'checking':'signed-out'});schedule(350);
})(window,document);
