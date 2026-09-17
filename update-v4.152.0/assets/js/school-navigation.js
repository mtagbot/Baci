/* Deterministic GET return routes. Never history.back() into POST/print/delete actions. */
(function(w,d){'use strict';if(w.SchoolNavigation)return;
 var parents={'student-bulk-report.php':'students.php','discipline-bulk-report.php':'students.php','student-modal.php':'students.php','report-print.php':'reports.php','report-view.php':'reports.php','bulk-print.php':'reports.php','reports-lists.php':'reports.php','exam-print.php':'exams.php','entry-cards.php':'entry-cards.php','attendance-tags.php':'attendance-tags.php','attendance-scanner.php':'attendance-tags.php','online-exam-result.php':'index.php','api/docs.php':'../index.php'};
 var safe=/^(index|students|reports|exams|teacher-panel|student-panel|student-online-exams|online-exams|attendance-tags|entry-cards|settings|reports-lists|reports-management|courses-management)\.php$/;
 function start(){
  if(d.querySelector('.school-return-nav'))return;
  var path=location.pathname,base=path.slice(path.lastIndexOf('/')+1),fallback=parents[base]||'index.php';if(/\/api\/docs.php$/.test(path))fallback='../index.php';
  if(base==='index.php'&&!location.search)return;
  var target=fallback;
  try{var ref=d.createElement('a');ref.href=d.referrer;var name=ref.pathname.split('/').pop();if(d.referrer&&ref.protocol===location.protocol&&ref.host===location.host&&safe.test(name)&&ref.pathname!==path&&!/[?&](action|print|download|export|delete|auto)=/.test(ref.search))target=ref.pathname+ref.search;}catch(ignore){}
  var nav=d.createElement('nav');nav.className='school-return-nav';nav.setAttribute('aria-label','بازگشت و خروج از صفحه');
  var back=d.createElement('a');back.href=target;back.textContent='بازگشت';nav.appendChild(back);
  var home=d.createElement('a');home.href=/\/api\//.test(path)?'../index.php':'index.php';home.textContent='صفحهٔ اصلی';nav.appendChild(home);
  try{if(w.opener&&!w.opener.closed&&w.opener.location.origin===location.origin){var close=d.createElement('button');close.type='button';close.textContent='بستن و بازگشت به پنجرهٔ قبلی';close.onclick=function(){try{w.opener.focus();w.close();}catch(ignore){}w.setTimeout(function(){w.location.href=target;},150);};nav.appendChild(close);}}catch(ignore){}
  var style=d.createElement('style');style.textContent='@media screen{.school-return-nav{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px;padding:8px 12px;background:#fff;border:1px solid #cbd5e1;border-radius:10px;direction:rtl}.school-return-nav a,.school-return-nav button{display:inline-flex;align-items:center;min-height:40px;padding:6px 12px;font-size:13px;line-height:1.6;background:#f1f5f9;color:#173a65;border:1px solid #cbd5e1;border-radius:7px;text-decoration:none;font-family:inherit}.school-return-nav a:focus,.school-return-nav button:focus{outline:3px solid #173a65;outline-offset:2px}body>.school-return-nav{margin:12px}}@media print{.school-return-nav{display:none!important}}';d.head.appendChild(style);
  var host=d.querySelector('main')||d.body;host.insertBefore(nav,host.firstChild);
 }
 w.SchoolNavigation={start:start};if(d.readyState==='loading')d.addEventListener('DOMContentLoaded',start);else start();
})(window,document);
