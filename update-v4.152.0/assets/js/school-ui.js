/* Progressive school UI. ES5 syntax, no framework, no polling, no request interception. */
(function (w,d) {
 'use strict';
 if(w.SchoolUI || !w.SchoolIcons)return;
 var C=w.SchoolIcons,icons=C.icons,map=C.map,keys=Object.keys(map).sort(function(a,b){return b.length-a.length;}),parts=[];
 for(var k=0;k<keys.length;k++)parts.push(keys[k].replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+'[\uFE0E\uFE0F]?');
 var pattern=new RegExp(parts.join('|'),'g');
 var stats={icons:0,flushes:0,initMs:0},queue=[],scheduled=false,uid=0;
 var defer=w.requestAnimationFrame||function(f){return w.setTimeout(f,16);};
 function matches(el,s){return !!(el&&el.nodeType===1&&(el.matches||el.msMatchesSelector||el.webkitMatchesSelector).call(el,s));}
 function closest(el,s){while(el&&el.nodeType===1){if(matches(el,s))return el;el=el.parentNode;}return null;}
 function each(list,fn){for(var i=0;i<list.length;i++)fn(list[i],i);}
 function all(root,selector){var found=[];if(matches(root,selector))found.push(root);each(root.querySelectorAll(selector),function(el){found.push(el);});return found;}
 function nameFor(x){return map[x.replace(/[\uFE0E\uFE0F]/g,'')]||'school';}
 function svg(name){var box=d.createElement('span');box.innerHTML=(icons[name]||icons.school).svg;return box.firstChild;}
 function plain(text){return String(text).replace(pattern,'').replace(/\s+/g,' ').trim();}
 function skip(el){return closest(el,'script,style,svg,textarea,input,select,option,pre,code,canvas,[contenteditable],.q-content,.rich-editor,.card-id,.card-back,.docx,.tgsim-msg,.tgsim-messages,.tgsim-bubble,[data-ui-content],[data-ui-no-icons]');}
 function textIcon(node){
  var text=node.nodeValue;if(!text||!node.parentNode)return;pattern.lastIndex=0;if(!pattern.test(text))return;
  var el=node.parentNode;if(skip(el)&&!closest(el,'.q-actions'))return;
  // Unknown prose is data, not a UI label. Explicit opt-in is available for generated controls.
  if(!closest(el,'button,a,label,h1,h2,h3,h4,h5,h6,summary,legend,.badge,.status,.alert,.notice,.toast,[data-ui-icons]')&&!(closest(el,'td')&&!plain(text)))return;
  // Preserve student/user data in table cells; decorate only UI controls or a pure status symbol.
  if(closest(el,'td')&&!closest(el,'button,a,label,.badge,.status')&&plain(text))return;
  pattern.lastIndex=0;var fragment=d.createDocumentFragment(),last=0,match,first='';
  while((match=pattern.exec(text))){if(match.index>last)fragment.appendChild(d.createTextNode(text.slice(last,match.index)));var name=nameFor(match[0]);first=first||name;fragment.appendChild(svg(name));stats.icons++;last=pattern.lastIndex;}
  if(last<text.length)fragment.appendChild(d.createTextNode(text.slice(last)));
  var control=closest(el,'button,a');
  if(control&&!plain(control.textContent)){control.setAttribute('aria-label',(icons[first]||icons.school).label);control.setAttribute('data-ui-auto-label','1');}
  if(!control&&!plain(text)){var label=d.createElement('span');label.className='ui-sr';label.textContent=icons[first].label;fragment.appendChild(label);}
  el.replaceChild(fragment,node);
 }
 function iconsIn(root){
  if(!root||!root.parentNode&&root!==d.body)return;
  if(root.nodeType===3){textIcon(root);return;}
  if(root.nodeType!==1||skip(root))return;
  var walker=d.createTreeWalker(root,4,{acceptNode:function(node){pattern.lastIndex=0;return pattern.test(node.nodeValue||'')?1:3;}},false),nodes=[],n;
  while((n=walker.nextNode()))nodes.push(n);
  each(nodes,textIcon);
 }
 function decorate(root){
  iconsIn(root);if(!root.querySelectorAll)return;
  each(all(root,'option'),function(el){var text=el.textContent;pattern.lastIndex=0;if(pattern.test(text)){var label=plain(text)||icons[nameFor(text.trim())].label;if(!el.hasAttribute('value'))el.setAttribute('value',text);el.textContent=label;}});
  each(all(root,'button[title],a[title]'),function(el){el.title=plain(el.title)||el.getAttribute('aria-label')||'راهنما';});
  each(all(root,'button,a'),function(el){if(el.textContent.trim()||el.hasAttribute('aria-label'))return;var icon=el.querySelector('[data-ui-icon]');if(icon&&icons[icon.getAttribute('data-ui-icon')])el.setAttribute('aria-label',icons[icon.getAttribute('data-ui-icon')].label);});
 }
 function responsiveTables(root){
  if(!root.querySelectorAll||d.body.classList.contains('ui-scanner'))return;
  var tables=[];if(matches(root,'table'))tables.push(root);each(root.querySelectorAll('table'),function(t){tables.push(t);});
  each(tables,function(t){
   if(closest(t,'.page,.sheet,.docx,#cardPreview,[contenteditable],.math-token,[data-ui-no-scroll]'))return;
   if(closest(t,'.ui-table-scroll'))return;
   var existing=closest(t,'.table-container'),box=existing||d.createElement('div');box.classList.add('ui-table-scroll');box.tabIndex=0;box.setAttribute('role','region');var cap=t.querySelector('caption');box.setAttribute('aria-label',cap?cap.textContent:'جدول اطلاعات؛ برای دیدن ستون‌های بیشتر پیمایش کنید');if(!existing){t.parentNode.insertBefore(box,t);box.appendChild(t);}
  });
 }
 function labels(root){
  if(!root.querySelectorAll)return;
  each(all(root,'input,select,textarea'),function(el){
   if(el.type==='hidden'||el.type==='submit'||el.type==='button'||el.labels&&el.labels.length||el.hasAttribute('aria-label')||el.hasAttribute('aria-labelledby')||closest(el,'[contenteditable]'))return;
   var prev=el.previousElementSibling;
   if(prev&&prev.tagName==='LABEL'){if(!el.id)el.id='school-field-'+(++uid);prev.htmlFor=el.id;}
   else if(el.getAttribute('placeholder'))el.setAttribute('aria-label',el.getAttribute('placeholder'));
   else if(el.type==='checkbox'||el.type==='radio'){
    var row=closest(el,'tr'),head=closest(el,'th');el.setAttribute('aria-label',head?'انتخاب همه ردیف‌ها':row?'انتخاب ردیف: '+row.textContent.trim().slice(0,120):'انتخاب گزینه');
   } else {
    var group=el.parentNode,found=null;for(var depth=0;group&&depth<3;depth++,group=group.parentNode){each(group.children,function(c){if(c.tagName==='LABEL'&&c.textContent.trim()&&!found)found=c;});if(found)break;}
    if(found){el.setAttribute('aria-label',found.textContent.trim()+(el.disabled?' — مقدار نمایشی':''));}
   }
  });
 }
 function flush(){scheduled=false;stats.flushes++;var roots=queue;queue=[];if(roots.length>80)roots=[d.body];each(roots,function(root){if(root.isConnected===false)return;decorate(root);responsiveTables(root);labels(root);});}
 function enqueue(root){if(!root||closest(root.nodeType===1?root:root.parentNode,'svg,.ui-sr'))return;for(var i=0;i<queue.length;i++)if(queue[i]===root||queue[i].contains&&queue[i].contains(root))return;if(queue.length>=80)queue=[d.body];else queue.push(root);if(!scheduled){scheduled=true;defer(flush);}}
 function drawer(){
  var aside=d.querySelector('.sidebar'),toggle=d.getElementById('sidebarToggleBtn');if(!aside||!toggle)return;
  aside.id='school-nav';aside.setAttribute('aria-label','صفحات مدرسه');toggle.setAttribute('aria-controls',aside.id);toggle.setAttribute('aria-label','باز و بسته کردن فهرست صفحات');
  var shade=d.createElement('button');shade.type='button';shade.className='ui-drawer-shade';shade.hidden=true;shade.tabIndex=-1;shade.setAttribute('aria-label','بستن فهرست صفحات');d.body.appendChild(shade);
  var close=aside.querySelector('.ui-drawer-close');if(!close){close=d.createElement('button');close.type='button';close.className='ui-drawer-close';close.appendChild(svg('close'));close.appendChild(d.createTextNode(' بستن فهرست'));aside.insertBefore(close,aside.firstChild);}
  function closeMenu(){d.body.classList.remove('sidebar-open');sync();toggle.focus();}
  shade.onclick=closeMenu;close.onclick=closeMenu;
  var wasOpen=false;
  function sync(){var small=w.innerWidth<=900,open=small&&d.body.classList.contains('sidebar-open');shade.hidden=!open;close.hidden=!small;toggle.setAttribute('aria-expanded',String(small?open:!d.body.classList.contains('sidebar-collapsed')));aside.setAttribute('role',open?'dialog':'navigation');if(open)aside.setAttribute('aria-modal','true');else aside.removeAttribute('aria-modal');if(open&&!wasOpen)close.focus();wasOpen=open;}
  if(w.MutationObserver)new MutationObserver(sync).observe(d.body,{attributes:true,attributeFilter:['class']});
  toggle.addEventListener('click',function(){w.setTimeout(sync,0);});w.addEventListener('resize',sync);
  d.addEventListener('keydown',function(e){
   if(!wasOpen)return;if(e.key==='Escape'||e.keyCode===27){closeMenu();return;}
   if(e.key==='Tab'||e.keyCode===9){var items=[];each(aside.querySelectorAll('a[href],button,[tabindex="0"]'),function(el){if(el.offsetWidth||el.offsetHeight)items.push(el);});if(!items.length)return;var first=items[0],last=items[items.length-1];if(e.shiftKey&&d.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&d.activeElement===last){e.preventDefault();first.focus();}}
  },true);sync();
 }
 function menuIcons(){
  each(d.querySelectorAll('.sidebar-item'),function(a){
   if(a.classList.contains('active'))a.setAttribute('aria-current','page');if(a.querySelector('svg'))return;
   var href=a.getAttribute('href')||'',name=/student/.test(href)?'student':/teacher/.test(href)?'users':/attendance/.test(href)?'calendar':/exam/.test(href)?'exam':/report|grade/.test(href)?'report':/setting/.test(href)?'settings':/bot|telegram|bale/.test(href)?'bot':/sync/.test(href)?'refresh':/message|sms/.test(href)?'message':/session/.test(href)?'shield':/course|class/.test(href)?'book':/backup/.test(href)?'folder':/log/.test(href)?'clock':'home';a.insertBefore(svg(name),a.firstChild);
  });
  each(d.querySelectorAll('.sidebar-section-title'),function(el){el.setAttribute('role','button');el.tabIndex=0;el.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===' '||e.keyCode===13||e.keyCode===32){e.preventDefault();el.click();}});});
 }
 function paperPreview(){
  if(d.body.classList.contains('school-app'))return;
  var pages=[];each(d.body.children,function(el){if(matches(el,'.page,.sheet'))pages.push(el);});
  var editor=d.getElementById('pagesRoot');if(editor){editor.classList.add('ui-paper-scroll');editor.tabIndex=0;editor.setAttribute('role','region');editor.setAttribute('aria-label','پیش‌نمایش برگه؛ قابل پیمایش');}if(!pages.length)return;var box=d.createElement('div');box.className='ui-paper-scroll';box.tabIndex=0;box.setAttribute('role','region');box.setAttribute('aria-label','پیش‌نمایش برگه با اندازهٔ واقعی؛ قابل پیمایش');pages[0].parentNode.insertBefore(box,pages[0]);each(pages,function(el){box.appendChild(el);});
 }
 function tabs(){
  each(d.querySelectorAll('.auth-tabs'),function(list){
   list.setAttribute('aria-label','نوع ورود');var buttons=list.querySelectorAll('button');
   function sync(){each(buttons,function(b){var on=b.classList.contains('active');b.setAttribute('aria-selected',String(on));b.tabIndex=on?0:-1;});}
   each(buttons,function(b,i){b.setAttribute('role','tab');var m=(b.getAttribute('onclick')||'').match(/\('([^']+)'\)/);if(m){b.setAttribute('aria-controls',m[1]);var panel=d.getElementById(m[1]);if(panel){panel.setAttribute('role','tabpanel');panel.setAttribute('aria-labelledby',b.id);}}
    b.addEventListener('click',sync);b.addEventListener('keydown',function(e){var code=e.keyCode,index=i;if(code===37)index=(i+1)%buttons.length;else if(code===39)index=(i+buttons.length-1)%buttons.length;else if(code===36)index=0;else if(code===35)index=buttons.length-1;else return;e.preventDefault();buttons[index].click();buttons[index].focus();sync();});
   });sync();
  });
 }
 function start(){
  var time=Date.now();decorate(d.body);responsiveTables(d.body);labels(d.body);menuIcons();drawer();tabs();paperPreview();
  var nav=d.querySelector('.navbar');function sizeHeader(){if(nav)d.documentElement.style.setProperty('--ui-header-height',nav.offsetHeight+'px');}sizeHeader();w.addEventListener('resize',sizeHeader);
  if(w.ResizeObserver&&nav)new ResizeObserver(sizeHeader).observe(nav);
  if(w.MutationObserver)new MutationObserver(function(changes){each(changes,function(change){if(change.type==='characterData')enqueue(change.target);else each(change.addedNodes,enqueue);});}).observe(d.body,{childList:true,subtree:true,characterData:true});
  if(!w.Promise||!w.fetch){var note=d.createElement('p');note.className='ui-compat-note';note.setAttribute('role','status');note.textContent='نمایش پایه فعال است؛ برای آزمون آنلاین و دوربین از مرورگر به‌روز استفاده کنید.';d.body.insertBefore(note,d.body.firstChild);}
  stats.initMs=Date.now()-time;
 }
 // Native dialogs cannot contain SVG. Keep their blocking behaviour and remove only UI pictograms.
 each(['alert','confirm','prompt'],function(key){var original=w[key];if(!original)return;w[key]=function(message){var args=Array.prototype.slice.call(arguments);args[0]=String(message).replace(pattern,'').replace(/[ \t]{2,}/g,' ').trim()||'پیام سامانه';return original.apply(w,args);};});
 w.SchoolUI={refresh:enqueue,icon:svg,metrics:stats};
 if(d.readyState==='loading')d.addEventListener('DOMContentLoaded',start);else start();
})(window,document);
/* Explicit preview opt-in only. Deterministic GET return routes. Never history.back() into POST/print/delete actions. */
(function(w,d){'use strict';if(w.SchoolNavigation)return;
 var parents={'student-bulk-report.php':'students.php','discipline-bulk-report.php':'students.php','student-modal.php':'students.php','report-print.php':'reports.php','report-view.php':'reports.php','bulk-print.php':'reports.php','reports-lists.php':'reports.php','exam-print.php':'exams.php','entry-cards.php':'entry-cards.php','attendance-tags.php':'attendance-tags.php','attendance-scanner.php':'attendance-tags.php','online-exam-result.php':'index.php','api/docs.php':'../index.php'};
 var safe=/^(index|students|reports|exams|teacher-panel|student-panel|student-online-exams|online-exams|attendance-tags|entry-cards|settings|reports-lists|reports-management|courses-management)\.php$/;
 function start(){
  if(!d.body||d.body.getAttribute('data-school-return')!=='preview')return;
  if(d.querySelector('.school-return-nav,.school-editor-return,.school-preview-return'))return;
  var path=location.pathname,base=path.slice(path.lastIndexOf('/')+1),fallback=parents[base]||'index.php';if(/\/api\/docs.php$/.test(path))fallback='../index.php';
  if(base==='index.php'&&!location.search)return;
  var target=fallback;
  try{var ref=d.createElement('a');ref.href=d.referrer;var name=ref.pathname.split('/').pop();if(d.referrer&&ref.protocol===location.protocol&&ref.host===location.host&&safe.test(name)&&ref.pathname!==path&&!/[?&](action|print|download|export|delete|auto)=/.test(ref.search))target=ref.pathname+ref.search;}catch(ignore){}
  // The live editor uses its own native toolbar styling, never a page-level return strip.
  var editor=d.getElementById('mainToolbar');
  var toolbar=editor||d.querySelector('.seat-toolbar,.toolbar.no-print,#editor.noprint');
  if(toolbar){
   var group=d.createElement('div');group.className=editor?'tb-group no-ajax school-editor-return':'school-preview-return';
   var button=d.createElement('button');button.type='button';button.textContent='بازگشت';button.title=editor?'بازگشت از ویرایشگر آزمون':'بازگشت از پیش‌نمایش';
   button.onclick=function(){w.location.href=target;};group.appendChild(button);toolbar.insertBefore(group,toolbar.firstChild);
   // Existing editor layout measurement is bound to resize; account for the added button.
   if(w.dispatchEvent&&d.createEvent){var resized=d.createEvent('Event');resized.initEvent('resize',false,false);w.dispatchEvent(resized);}
   return;
  }
  var nav=d.createElement('nav');nav.className='school-return-nav';nav.setAttribute('aria-label','بازگشت و خروج از صفحه');
  var back=d.createElement('a');back.href=target;back.textContent='بازگشت';nav.appendChild(back);
  var home=d.createElement('a');home.href=/\/api\//.test(path)?'../index.php':'index.php';home.textContent='صفحهٔ اصلی';nav.appendChild(home);
  try{if(w.opener&&!w.opener.closed&&w.opener.location.origin===location.origin){var close=d.createElement('button');close.type='button';close.textContent='بستن و بازگشت به پنجرهٔ قبلی';close.onclick=function(){try{w.opener.focus();w.close();}catch(ignore){}w.setTimeout(function(){w.location.href=target;},150);};nav.appendChild(close);}}catch(ignore){}
  var style=d.createElement('style');style.textContent='@media screen{.school-return-nav{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px;padding:8px 12px;background:#fff;border:1px solid #cbd5e1;border-radius:10px;direction:rtl}.school-return-nav a,.school-return-nav button{display:inline-flex;align-items:center;min-height:40px;padding:6px 12px;font-size:13px;line-height:1.6;background:#f1f5f9;color:#173a65;border:1px solid #cbd5e1;border-radius:7px;text-decoration:none;font-family:inherit}.school-return-nav a:focus,.school-return-nav button:focus{outline:3px solid #173a65;outline-offset:2px}body>.school-return-nav{margin:12px}}@media print{.school-return-nav{display:none!important}}';d.head.appendChild(style);
  var host=d.querySelector('main')||d.body;host.insertBefore(nav,host.firstChild);
 }
 w.SchoolNavigation={start:start};if(d.readyState==='loading')d.addEventListener('DOMContentLoaded',start);else start();
})(window,document);
