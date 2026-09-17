/* Keyboard-first entry, without positive tabindex, implicit submits or editing-time focus theft. */
(function(d){'use strict';
 function start(){var form=d.getElementById('student-profile-form');if(!form)return;
 function digits(s){return s.replace(/[۰-۹٠-٩]/g,function(c){var n=c.charCodeAt(0);return String(n>=1776?n-1776:n-1632);});}
 function fields(){return Array.prototype.filter.call(form.querySelectorAll('input,select,textarea,button'),function(e){return e.type!=='hidden'&&!e.disabled&&(e.offsetWidth||e.offsetHeight);});}
 function next(el,back){var list=fields(),i=list.indexOf(el)+(back?-1:1);if(list[i])list[i].focus();}
 function valid(el){var v=el.value,issue='';if(v!==''&&el.hasAttribute('data-min')&&(Number(v)<Number(el.getAttribute('data-min'))||Number(v)>Number(el.getAttribute('data-max'))))issue='عدد باید بین '+el.getAttribute('data-min')+' و '+el.getAttribute('data-max')+' باشد.';if(el.setCustomValidity)el.setCustomValidity(issue);return !issue&&(!el.checkValidity||el.checkValidity());}
 function foreign(){var el=form.elements.national_id,on=form.elements.is_foreign.checked;if(on){el.removeAttribute('pattern');el.removeAttribute('data-auto-length');}else{el.pattern='[0-9۰-۹٠-٩]{10}';el.setAttribute('data-auto-length','10');}}
 foreign();form.elements.is_foreign.addEventListener('change',foreign);
 form.addEventListener('focusin',function(e){e.target._stLength=(e.target.value||'').length;e.target._stWasComplete=e.target._stLength>=Number(e.target.getAttribute('data-auto-length')||99999);});
 form.addEventListener('beforeinput',function(e){e.target._stLength=(e.target.value||'').length;});
 function onInput(e){var el=e.target;if(e.isComposing||d.activeElement!==el)return;var old=el._stLength||0,n=Number(el.getAttribute('data-auto-length')||0);if(el.hasAttribute('data-digits')){var v=digits(el.value);if(v!==el.value){var pos=el.selectionStart;el.value=v;try{el.setSelectionRange(pos,pos);}catch(ignore){}}}
 el._stLength=el.value.length;valid(el);
 if(el._stWasComplete||!n||!d.getElementById('student-auto-focus').checked||e.inputType==='insertFromPaste'||e.inputType&&e.inputType.indexOf('delete')===0||old>=n||el.value.length!==n||!valid(el))return;
 if(el.hasAttribute('data-local-mobile')&&!/^09\d{9}$/.test(el.value))return;
 if(el.name==='serial_letter'&&!/^[\u0621-\u064a\u067e\u0686\u0698\u06af\u06a9\u06cc]$/.test(el.value))return;
 if(el.hasAttribute('data-digits')&&!/^\d+$/.test(el.value))return;
 next(el,false);
 }
 form.addEventListener('input',onInput);
 form.addEventListener('compositionstart',function(e){e.target._stCompositionLength=e.target.value.length;});
 form.addEventListener('compositionend',function(e){e.target._stLength=e.target._stCompositionLength||0;onInput({target:e.target,inputType:'insertText'});});
 form.addEventListener('keydown',function(e){var el=e.target;if(e.isComposing||e.keyCode===229)return;if((e.key==='Enter'||e.keyCode===13)&&el.tagName==='INPUT'&&!/^(checkbox|radio|submit|button|file)$/.test(el.type)){e.preventDefault();if(valid(el))next(el,e.shiftKey);else if(el.reportValidity)el.reportValidity();}});
 form.addEventListener('submit',function(){Array.prototype.forEach.call(form.querySelectorAll('[data-digits]'),function(el){el.value=digits(el.value);});});
 var errors=d.getElementById('student-errors');if(errors)errors.focus();
 }
 if(d.readyState==='loading')d.addEventListener('DOMContentLoaded',start);else start();
})(document);
