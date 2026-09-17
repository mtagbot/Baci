/* One same-origin frame, accessible tabs, event-driven mobile height. No polling or scale-to-fit. */
(function(w,d){'use strict';
 var hub=d.querySelector('[data-school-hub]'),frame=d.getElementById('hubFrame');
 if(!hub||!frame||hub.getAttribute('data-hub-ready'))return;
 hub.setAttribute('data-hub-ready','1');
 var tabs=hub.querySelectorAll('[data-hub-key]'),panel=d.getElementById('hubPanel'),status=d.getElementById('hubLoadStatus');
 var child=null,resizeObserver=null,mutationObserver=null,queued=0,timeout=0,stopped=false;
 var raf=w.requestAnimationFrame||function(f){return w.setTimeout(f,16);};
 function mobile(){return w.innerWidth<=900;}
 function cleanup(){if(resizeObserver)resizeObserver.disconnect();if(mutationObserver)mutationObserver.disconnect();resizeObserver=mutationObserver=null;child=null;}
 function setVar(name,value){if(child&&child.documentElement.style.getPropertyValue(name)!==value)child.documentElement.style.setProperty(name,value);}
 function dialogs(){
  if(!child||!mobile())return;
  var box=frame.getBoundingClientRect(),nav=d.querySelector('.navbar'),top=nav?Math.max(0,nav.getBoundingClientRect().bottom):0;
  var footer=d.querySelector('.school-footer'),bottom=footer&&w.getComputedStyle(footer).position==='fixed'?footer.offsetHeight:0;
  setVar('--hub-visible-top',Math.max(12,top-box.top+12)+'px');
  setVar('--hub-visible-height',Math.max(160,w.innerHeight-Math.max(top,box.top)-bottom-24)+'px');
  var list=child.querySelectorAll('[id$="Modal"],.modal-overlay');
  for(var i=0;i<list.length;i++){
   var el=list[i],css=frame.contentWindow.getComputedStyle(el);
   if(css.position==='fixed'&&css.display!=='none'&&!el.hidden&&!el.classList.contains('hub-mobile-dialog'))el.classList.add('hub-mobile-dialog');
  }
 }
 function measure(){
  queued=0;if(stopped||!child||d.hidden)return;
  if(!mobile()){if(frame.style.height)frame.style.height='';var marked=child.querySelectorAll('.hub-mobile-dialog');for(var k=0;k<marked.length;k++)marked[k].classList.remove('hub-mobile-dialog');return;}
  // CSS removes viewport-sized minimums in the child. Measuring BODY avoids a feedback loop with iframe height.
  var body=child.body;if(!body)return;
  var height=Math.ceil(Math.max(body.offsetHeight,body.scrollHeight,body.getBoundingClientRect().height))+2;
  height=Math.max(240,Math.min(30000,height));
  if(Math.abs(frame.getBoundingClientRect().height-height)>2)frame.style.height=height+'px';
  dialogs();
 }
 function schedule(){if(!queued&&!stopped)queued=raf(measure);}
 function loaded(){
  cleanup();w.clearTimeout(timeout);panel.removeAttribute('aria-busy');status.textContent='';
  try{child=frame.contentDocument;if(!child||!child.body)return;
   if(!child.documentElement.classList.contains('hub-embedded'))child.documentElement.classList.add('hub-embedded');
   if(w.ResizeObserver){resizeObserver=new w.ResizeObserver(schedule);resizeObserver.observe(child.body);var main=child.querySelector('main');if(main)resizeObserver.observe(main);}
   if(w.MutationObserver){mutationObserver=new w.MutationObserver(schedule);mutationObserver.observe(child.body,{childList:true,subtree:true,attributes:true,attributeFilter:['class','style','hidden','open']});}
   child.addEventListener('load',schedule,true);child.addEventListener('change',schedule,true);child.addEventListener('toggle',schedule,true);
   if(child.fonts&&child.fonts.ready)child.fonts.ready.then(schedule);
   schedule();
  }catch(ignore){child=null;status.textContent='این محتوا در قاب قابل بررسی نیست.';}
 }
 function select(tab,history){
  if(!tab)return;
  if(tab.getAttribute('aria-selected')==='true'&&history)return;
  for(var i=0;i<tabs.length;i++){var on=tabs[i]===tab;tabs[i].classList.toggle('is-active',on);tabs[i].setAttribute('aria-selected',on?'true':'false');tabs[i].tabIndex=on?0:-1;}
  panel.setAttribute('aria-labelledby',tab.id);panel.setAttribute('aria-busy','true');
  frame.title=tab.textContent.trim();status.textContent='در حال باز کردن بخش…';cleanup();
  if(mobile())frame.style.height='680px';
  var target=d.createElement('a');target.href=tab.getAttribute('data-hub-src');
  // Do not add a second, iframe-only history entry for a tab change.
  try{frame.contentWindow.location.replace(target.href);}catch(ignore){frame.src=target.href;}
  if(history&&w.history&&w.history.pushState)w.history.pushState(null,'',tab.getAttribute('href'));
  w.clearTimeout(timeout);timeout=w.setTimeout(function(){status.textContent='دریافت پاسخ طول کشیده است؛ اتصال را بررسی کنید یا تب دیگری را باز کنید.';panel.removeAttribute('aria-busy');},15000);
 }
 for(var i=0;i<tabs.length;i++)(function(tab,index){
  tab.tabIndex=tab.getAttribute('aria-selected')==='true'?0:-1;
  tab.addEventListener('click',function(e){if(e.ctrlKey||e.metaKey||e.shiftKey||e.altKey||e.button>0)return;e.preventDefault();select(tab,true);});
  tab.addEventListener('keydown',function(e){var at=index;if(e.keyCode===37)at=(index+1)%tabs.length;else if(e.keyCode===39)at=(index+tabs.length-1)%tabs.length;else if(e.keyCode===36)at=0;else if(e.keyCode===35)at=tabs.length-1;else return;e.preventDefault();tabs[at].focus();select(tabs[at],true);});
 })(tabs[i],i);
 w.addEventListener('popstate',function(){var m=w.location.search.match(/[?&]hub_tab=([^&]+)/),key='';try{key=m?decodeURIComponent(m[1]):'';}catch(ignore){}var tab=tabs[0];for(var i=0;i<tabs.length;i++)if(tabs[i].getAttribute('data-hub-key')===key)tab=tabs[i];select(tab,false);});
 frame.addEventListener('load',loaded);w.addEventListener('resize',schedule);w.addEventListener('scroll',schedule,{passive:true});
 d.addEventListener('visibilitychange',schedule);
 w.addEventListener('pagehide',function(){stopped=true;cleanup();w.clearTimeout(timeout);});
 w.addEventListener('pageshow',function(e){if(e.persisted){stopped=false;loaded();}});
 try{if(frame.contentDocument&&frame.contentDocument.readyState==='complete')loaded();}catch(ignore){}
})(window,document);
