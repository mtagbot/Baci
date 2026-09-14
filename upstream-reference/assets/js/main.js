/**
 * Main Interactive JS script
 */

document.addEventListener('DOMContentLoaded', function () {
    // Dark mode has been removed: always keep the interface in light mode.
    try { localStorage.setItem('theme', 'light'); } catch(e) {}
    document.documentElement.classList.remove('dark');
    document.body.classList.remove('dark');

    if (window.initStudentHover) window.initStudentHover();
    if (window.initSortableTables) window.initSortableTables();

    const alerts = document.querySelectorAll('.mb-4.p-4.rounded-lg.border');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transition = 'opacity 0.5s ease';
            setTimeout(() => alert.remove(), 500);
        }, 5000);
    });
});

/**
 * Ajax search helper for tables
 */
function liveSearchTable(inputId, tableId) {
    const input = document.getElementById(inputId);
    if (!input) return;
    input.addEventListener('keyup', function () {
        const filter = input.value.toLowerCase();
        const table = document.getElementById(tableId);
        const rows = table.getElementsByTagName('tr');
        for (let i = 1; i < rows.length; i++) {
            let rowText = rows[i].textContent || rows[i].innerText;
            rows[i].style.display = rowText.toLowerCase().indexOf(filter) > -1 ? '' : 'none';
        }
    });
}

// Global re-initializers used after AJAX filter refreshes.
window.initStudentHover = function(){
  const tip = document.getElementById('studentHoverTooltip');
  if(!tip) return;
  let timer=null;
  document.querySelectorAll('.student-row').forEach(row=>{
    if(row.dataset.hoverReady==='1') return;
    row.dataset.hoverReady='1';
    row.addEventListener('mousemove', e=>{
      const id=row.dataset.studentId;
      clearTimeout(timer);
      timer=setTimeout(()=>{
        fetch('student-modal.php?type=hover&student_id='+id).then(r=>r.json()).then(j=>{
          tip.innerHTML=j.html||''; tip.style.display='block';
          const w=320,h=230,pad=14; let x=e.clientX+18,y=e.clientY+18;
          if(x+w>innerWidth) x=e.clientX-w-18; if(y+h>innerHeight) y=e.clientY-h-18;
          tip.style.left=Math.max(pad,x)+'px'; tip.style.top=Math.max(pad,y)+'px';
        });
      },120);
    });
    row.addEventListener('mouseleave',()=>{clearTimeout(timer); tip.style.display='none';});
  });
};

window.initSortableTables = function(){
  document.querySelectorAll('table').forEach(table=>{
    if(table.dataset.sortReady==='1') return; table.dataset.sortReady='1';
    table.querySelectorAll('thead th').forEach((th,idx)=>{
      th.style.cursor='pointer'; th.title='مرتب‌سازی';
      th.addEventListener('click',()=>{
        const tbody=table.tBodies[0]; if(!tbody) return;
        const rows=[...tbody.rows]; const dir=th.dataset.dir==='asc'?-1:1; th.dataset.dir=dir===1?'asc':'desc';
        rows.sort((a,b)=> compareSmart(a.cells[idx]?.innerText||'', b.cells[idx]?.innerText||'')*dir);
        rows.forEach(r=>tbody.appendChild(r));
      });
    });
  });
};

// Collapsible categorized admin sidebar (desktop-app style)
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.sidebar-section-title').forEach(function(title, idx){
    title.addEventListener('click', function(){
      title.classList.toggle('collapsed');
      let n = title.nextElementSibling;
      while(n && !n.classList.contains('sidebar-section-title')){
        if(n.classList && n.classList.contains('sidebar-item')) n.classList.toggle('menu-collapsed', title.classList.contains('collapsed'));
        n = n.nextElementSibling;
      }
    });
    if (window.innerWidth < 1100 && idx > 1) title.click();
  });
});

// v4.7: embedded iframe mode + lightweight AJAX GET filters
(function(){
  function addEmbeddedParam(url){
    try{const u=new URL(url, location.href); if(u.origin===location.origin && !u.searchParams.has('embedded')) u.searchParams.set('embedded','1'); return u.pathname+u.search+u.hash;}catch(e){return url;}
  }
  function initEmbeddedMode(){
    if(window.self!==window.top){
      document.documentElement.classList.add('embedded-mode');
      document.body.classList.add('embedded-mode');
      document.querySelectorAll('a[href]').forEach(a=>{
        const href=a.getAttribute('href')||'';
        if(href && !href.startsWith('#') && !href.startsWith('javascript:') && !a.target) a.setAttribute('href', addEmbeddedParam(href));
      });
      document.querySelectorAll('form[method="GET"],form:not([method])').forEach(f=>{
        if(!f.querySelector('input[name="embedded"]')){const i=document.createElement('input');i.type='hidden';i.name='embedded';i.value='1';f.appendChild(i);}
      });
    }
  }
  function initAjaxFilters(){
    document.querySelectorAll('main form').forEach(form=>{
      const method=(form.getAttribute('method')||'GET').toUpperCase();
      if(method!=='GET' || form.dataset.noAjax==='1' || form.closest('.no-ajax')) return;
      let timer=null;
      const run=(ev)=>{
        const active=document.activeElement;
        const focusName=active && active.name ? active.name : null;
        const focusId=active && active.id ? active.id : null;
        const focusValue=active && ('value' in active) ? active.value : null;
        const caret=active && active.selectionStart !== undefined ? active.selectionStart : null;
        clearTimeout(timer); timer=setTimeout(()=>{
          const url=(form.getAttribute('action')||location.pathname)+'?'+new URLSearchParams(new FormData(form)).toString();
          fetch(url,{headers:{'X-Requested-With':'fetch'}}).then(r=>r.text()).then(html=>{
            const doc=new DOMParser().parseFromString(html,'text/html');
            const nmain=doc.querySelector('main'); const main=document.querySelector('main');
            if(nmain&&main){
              main.innerHTML=nmain.innerHTML; history.replaceState(null,'',url);
              initEmbeddedMode(); initAjaxFilters();
              if(window.initStudentHover) window.initStudentHover();
              if(window.initSortableTables) window.initSortableTables();
              let el = focusId ? document.getElementById(focusId) : null;
              if(!el && focusName) el = document.querySelector(`[name="${CSS.escape(focusName)}"]`);
              if(el){ el.focus(); if(focusValue!==null && el.value!==focusValue) el.value=focusValue; try{ if(caret!==null) el.setSelectionRange(caret, caret); }catch(e){} }
            }
          }).catch(()=>form.submit());
        },250);
      };
      form.querySelectorAll('select,input[type="checkbox"],input[type="radio"],input[type="date"],input[type="number"]').forEach(el=>el.addEventListener('change',run));
      form.querySelectorAll('input[type="text"],input[type="search"]').forEach(el=>el.addEventListener('input',run));
    });
  }
  document.addEventListener('DOMContentLoaded',()=>{initEmbeddedMode(); initAjaxFilters();});
})();

// Student list modals remain available after AJAX filter refreshes
window.openStudentModal = function(type, id){
  const modal=document.getElementById('studentAjaxModal'), content=document.getElementById('studentAjaxContent');
  if(!modal||!content){ location.href = type==='reports' ? 'reports.php?student_id='+id : 'staff-student-file.php?id='+id+'&tab='+type; return; }
  content.innerHTML='در حال بارگذاری...'; modal.style.display='flex';
  fetch('student-modal.php?type='+encodeURIComponent(type)+'&student_id='+id).then(r=>r.json()).then(j=>{content.innerHTML=j.html||'خطا';});
};
window.closeStudentModal = function(){const m=document.getElementById('studentAjaxModal'); if(m)m.style.display='none';};
window.saveDisciplineAjax = function(form){const fd=new FormData(form); fetch('student-modal.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{document.getElementById('studentAjaxContent').innerHTML=j.html;}); return false;};
window.deleteDisciplineAjax = function(studentId, recordId){
  if(!confirm('حذف شود؟')) return; const fd=new FormData(); const token=document.querySelector('input[name="csrf_token"]')?.value||'';
  fd.append('csrf_token',token); fd.append('type','discipline_delete'); fd.append('student_id',studentId); fd.append('record_id',recordId);
  fetch('student-modal.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{document.getElementById('studentAjaxContent').innerHTML=j.html;});
};
window.openReportModal = function(type){
  if(!document.querySelector('.st-check:checked')){ alert('ابتدا حداقل یک دانش‌آموز را انتخاب کنید.'); return; }
  const rt=document.getElementById('reportType'), mt=document.getElementById('modalTitle'), rm=document.getElementById('reportModal');
  if(rt) rt.value=type; if(mt) mt.textContent = type==='info'?'گزارش اطلاعات دانش‌آموزان':(type==='discipline'?'گزارش انضباطی':'گزارش تحلیلی نمرات'); if(rm) rm.style.display='flex';
};
window.closeReportModal = function(){const m=document.getElementById('reportModal'); if(m)m.style.display='none';};
window.updateDisciplineAjax = function(form){const fd=new FormData(form); fetch('student-modal.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{document.getElementById('studentAjaxContent').innerHTML=j.html;}); return false;};
// Close hamburger/user menus by clicking outside
window.addEventListener('click', function(e){
  if(document.body.classList.contains('sidebar-open') && !e.target.closest('.sidebar') && !e.target.closest('.hamburger-btn')) document.body.classList.remove('sidebar-open');
  if(document.body.classList.contains('user-menu-open') && !e.target.closest('.user-menu-wrap')) document.body.classList.remove('user-menu-open');
});
function gradeWeightFa(txt){txt=String(txt||''); const map=[['اول',1],['دوم',2],['سوم',3],['چهارم',4],['پنجم',5],['ششم',6],['هفتم',7],['هشتم',8],['نهم',9],['دهم',10],['یازدهم',11],['دوازدهم',12]]; for(const [k,v] of map){if(txt.includes(k))return v;} const m=txt.replace(/[۰-۹]/g,d=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).match(/\d+/); return m?parseInt(m[0],10):99;}
function classNoFa(txt){txt=String(txt||'').replace(/[۰-۹]/g,d=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(d)); const m=[...txt.matchAll(/\d+/g)]; return m.length?parseInt(m[m.length-1][0],10):999;}
function compareSmart(a,b){const ga=gradeWeightFa(a),gb=gradeWeightFa(b); if(ga!==99||gb!==99){if(ga!==gb)return ga-gb; const ca=classNoFa(a),cb=classNoFa(b); if(ca!==cb)return ca-cb;} return String(a).localeCompare(String(b),'fa',{numeric:true});}
window.openTransferModal = function(){ if(!document.querySelector('.st-check:checked')){alert('ابتدا دانش‌آموزان را انتخاب کنید.');return;} const m=document.getElementById('transferModal'); if(m)m.style.display='flex'; };
window.closeTransferModal = function(){ const m=document.getElementById('transferModal'); if(m)m.style.display='none'; const f=document.getElementById('transferFlag'); if(f)f.value='0'; };
window.confirmTransferStudents = function(){
  if(!document.querySelector('.st-check:checked')){alert('ابتدا دانش‌آموزان را انتخاب کنید.');return false;}
  const y=document.getElementById('destYearSelect')?.value, g=document.getElementById('destGradeSelect')?.value, c=document.getElementById('destClassSelect')?.value;
  if(!y||!g||!c){alert('سال، پایه و کلاس مقصد را انتخاب کنید.');return false;}
  return confirm('انتقال/کپی دانش‌آموزان انتخاب‌شده در بانک اطلاعاتی اعمال شود؟');
};
window.submitTransferStudents = function(){
  const form=document.getElementById('bulkStudentsForm'); if(!form) return;
  if(!window.confirmTransferStudents()) return;
  form.setAttribute('action','students.php');
  form.submit();
};
