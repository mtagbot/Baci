(function(w,d){'use strict';
 var busy=false;
 function printReport(){if(busy)return;busy=true;var status=d.getElementById('report-print-status'),button=d.getElementById('report-print');button.disabled=true;
 function fail(){busy=false;button.disabled=false;status.textContent='قلم انتخاب‌شده بارگذاری نشد؛ اتصال و فایل قلم را بررسی و دوباره تلاش کنید. چاپ خودکار متوقف شد.';}
 function ready(){status.textContent='قلم آماده است؛ می‌توانید چاپ یا PDF تهیه کنید.';button.disabled=false;busy=false;w.print();}
 if(!d.fonts||!w.Promise){status.textContent='این مرورگر تأیید بارگذاری قلم را پشتیبانی نمی‌کند؛ برای چاپ دقیق از مرورگر به‌روز استفاده کنید.';button.disabled=false;busy=false;return;}
 var family=d.body.getAttribute('data-report-font'),sample='آزمایش قلم فارسی ۱۲۳۴۵۶۷۸۹۰',local=d.body.getAttribute('data-report-local-font')==='1';
 Promise.all([d.fonts.load('400 14px "'+family+'"',sample),d.fonts.load('700 14px "'+family+'"',sample)]).then(function(faces){if(local&&(!faces[0].length||!faces[1].length))throw new Error('Missing font');return d.fonts.ready;}).then(function(){(w.requestAnimationFrame||w.setTimeout)(function(){(w.requestAnimationFrame||w.setTimeout)(ready);});}).catch(fail);
 }
 function start(){d.getElementById('report-print').onclick=printReport;printReport();}
 if(d.readyState==='loading')d.addEventListener('DOMContentLoaded',start);else start();
})(window,document);
