#!/usr/bin/env python3
"""V4.183.0 — همان اصلاح‌های «آماده‌سازی سوال جدید» را روی هر دو نسخهٔ
exam-print.php اعمال می‌کند (نسخهٔ سایت و آینهٔ دسکتاپ).

    python3 scripts/patch-v4.183.0-designer.py

سه اصلاح (بدون تغییر منطق طراحی/چاپ):
  ۱) راهنمای متن سوال به‌جای متن پیش‌نوشته (placeholder، بیرون از #qText)
  ۲) فیلد «بارم / نمره» خالی با راهنمای ۱
  ۳) دکمهٔ × حذف تصویر روی گوشهٔ بالای تصویر انتخاب‌شده
"""
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
TARGETS = [
    ROOT / 'update-v4.152.0/exam-print.php',
    ROOT / 'desktop-app-v2/patch/www-exam-print.php',
]

HELPER = (
    "/* v4.183.0: راهنمای «نوع سوال» به‌جای متن پیش‌نوشته. لایهٔ راهنما بیرون از #qText\n"
    "   است، پس هرگز در HTML سوال (و در نتیجه در چاپ/PDF) نمی‌آید؛ با شروع تایپ محو می‌شود. */\n"
    "const QUESTION_PH={text:'صورت سوال تشریحی را اینجا بنویسید...',mcq:'صورت سوال چندگزینه‌ای را اینجا بنویسید؛ گزینه‌ها را در چهار خانهٔ زیر بنویسید.',blank:'عبارت را بنویسید؛ برای نقطه‌چین جای خالی دکمهٔ «جای‌خالی» را بزنید.',tf:'متن عبارت صحیح یا غلط را اینجا بنویسید؛ مربع‌ها برای پاسخ دانش‌آموز است.'};\n"
    "function questionEditorHasContent(ed){if(!ed)return false; if(ed.querySelector('img'))return true; const clone=ed.cloneNode(true); clone.querySelectorAll('[data-ph-only]').forEach(x=>x.remove()); return clone.textContent.replace(/[\\s\\u200c\\u200e\\u200f\\u00a0]/g,'')!=='';}\n"
    "function syncQuestionPlaceholder(){const ed=document.getElementById('qText'), ph=document.getElementById('qTextPh'); if(!ed||!ph||!ed.parentElement)return; const sel=document.getElementById('qType'); ph.textContent=QUESTION_PH[(sel&&sel.value)||'text']||''; ed.parentElement.classList.toggle('rich-ph-hidden',questionEditorHasContent(ed));}\n"
)

TEMPLATE = (
    "function applyQuestionTemplate(){"
    "const t=document.getElementById('qType').value, ed=document.getElementById('qText'); if(!ed)return;"
    "/* v4.183.0: هیچ جملهٔ آماده‌ای داخل ویرایشگر نوشته نمی‌شود. ساختار واقعی هر نوع سوال"
    " (خانه‌های گزینه، مربع صحیح/غلط) می‌ماند و راهنمای متنی فقط به‌شکل placeholder"
    " (لایهٔ #qTextPh و data-ph روی خانه‌ها) نمایش داده می‌شود — پس کاربر هیچ‌وقت لازم نیست"
    " اول متن آماده را پاک کند و آن جمله هرگز داخل سوال ذخیره یا چاپ نمی‌شود. */"
    "if(t==='mcq')ed.innerHTML='<div class=\"q-options\"><span data-ph=\"الف) گزینه اول\"></span><span data-ph=\"ب) گزینه دوم\"></span><span data-ph=\"ج) گزینه سوم\"></span><span data-ph=\"د) گزینه چهارم\"></span></div>';"
    "else if(t==='tf')ed.innerHTML='<p><span class=\"tf-square\"></span> <span data-ph-only=\"1\">صحیح</span> &nbsp; <span class=\"tf-square\"></span> <span data-ph-only=\"1\">غلط</span></p>';"
    "else ed.innerHTML='';"
    "applyFontToEditor(); syncQuestionPlaceholder(); updateQuestionPreview();}"
)

PH_CSS = (
    ".rich-editor:focus{border-color:var(--brand);box-shadow:var(--focus)}\n"
    "/* v4.183.0: راهنمای نوع سوال به‌جای متنِ پیش‌نوشته. قبلاً قالب هر نوع سوال یک\n"
    "   جملهٔ واقعی داخل ویرایشگر می‌نوشت و کاربر باید اول آن را پاک می‌کرد. حالا\n"
    "   ویرایشگر خالی می‌ماند و این لایه (بیرون از #qText، پس هرگز ذخیره/چاپ\n"
    "   نمی‌شود) فقط راهنما را نشان می‌دهد و با شروع تایپ محو می‌شود. */\n"
    ".rich-wrap{position:relative}\n"
    ".rich-ph{position:absolute;top:7px;inset-inline-start:12px;color:#94a3b8;font-size:12px;line-height:1.6;pointer-events:none;user-select:none;max-width:calc(100% - 24px)}\n"
    ".rich-wrap.rich-ph-hidden .rich-ph{display:none}\n"
    ".q-options span:empty{min-height:16px}\n"
    ".q-options span:empty:before{content:attr(data-ph);color:#94a3b8;font-weight:400}"
)

DEL_CSS = (
    ".img-handle-w{left:-6px;top:calc(50% - 6px);cursor:ew-resize}\n"
    "/* v4.183.0: × حذف تصویر روی گوشهٔ بالای همان تصویر انتخاب‌شده */\n"
    ".img-del-btn{position:absolute;right:-22px;top:-22px;width:24px;height:24px;padding:0;border:0;border-radius:50%;background:#ef4444;color:#fff;font-size:15px;font-weight:700;line-height:1;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:62;pointer-events:auto;box-shadow:0 1px 5px rgba(2,6,23,.4)}\n"
    ".img-del-btn:hover{background:#dc2626}\n"
    ".img-del-btn:focus-visible{outline:2px solid #fff;outline-offset:-2px}"
)

HANDLE_JS = (
    "    box.appendChild(h);\n"
    "  });\n"
    "  /* v4.183.0: × حذف در گوشهٔ بالای همان تصویر انتخاب‌شده — کاربر دیگر برای\n"
    "     برداشتن تصویر لازم نیست ویرایشگر را دست‌کاری کند. مکان دکمه در موبایل با\n"
    "     قاعدهٔ اختصاصی در exam-designer-mobile.css جابه‌جا می‌شود تا با دستگیره‌های\n"
    "     بزرگ گوشه تلاقی نکند. */\n"
    "  const del=document.createElement('button');\n"
    "  del.type='button'; del.className='img-del-btn'; del.textContent='×';\n"
    "  del.title='حذف تصویر'; del.setAttribute('aria-label','حذف تصویر');\n"
    "  del.addEventListener('pointerdown',e=>{e.preventDefault(); e.stopPropagation();});\n"
    "  del.addEventListener('click',e=>{e.preventDefault(); e.stopPropagation(); removeQuestionImage(img.dataset.imgid,img);});\n"
    "  box.appendChild(del);\n"
    "  host.appendChild(box); imgHandleBox=box; positionImgHandles(img);"
)

REMOVE_JS = (
    "/* v4.183.0: حذف تصویر سوال. تصویر در هر دو جا می‌تواند باشد: داخل ویرایشگر\n"
    "   (#qText) یا روی برگهٔ رندرشده. مسیر به‌روزرسانی مدل بر همان اساس انتخاب\n"
    "   می‌شود تا تغییر، مانند جابه‌جایی/تغییر اندازه، فوراً ذخیره شود. */\n"
    "function removeQuestionImage(id,node){\n"
    "  if(!id&&node)id=node.dataset.imgid;\n"
    "  if(!id)return;\n"
    "  const nodes=questionImageNodes(id);\n"
    "  if(!nodes.length)return;\n"
    "  const ed=document.getElementById('qText');\n"
    "  const inEditor=nodes.some(n=>!!(ed&&ed.contains(n)));\n"
    "  removeImgHandles();\n"
    "  nodes.forEach(n=>n.remove());\n"
    "  selectedImageId=null; selectedImageNode=null; hideImageTouchTools();\n"
    "  if(inEditor){updateModelFromDomImage(); updateQuestionPreview(); syncQuestionPlaceholder();}\n"
    "  else{syncRenderedImagesToModel(); renderQuestions();}\n"
    "  autosaveLocal(); showToast('تصویر از سوال حذف شد');\n"
    "}\n"
    "function attachImgHandles(img){"
)

EDITS = [
    # (anchor, replacement, must_appear_once)
    ('<div class="q-field"><label>بارم / نمره</label><input id="qScore" type="text" value="1" oninput="updateQuestionPreview()"></div>',
     '<div class="q-field"><label>بارم / نمره</label><input id="qScore" type="text" value="" placeholder="1" oninput="updateQuestionPreview()"></div>'),
    ('<td class="q-score" id="qPrevScore">1</td>',
     '<td class="q-score" id="qPrevScore"></td>'),
    ('<div id="qText" class="rich-editor" contenteditable="true" oninput="updateQuestionPreview()"></div>',
     '<div class="rich-wrap">\n          <div id="qText" class="rich-editor" contenteditable="true" oninput="updateQuestionPreview()"></div>\n          <div id="qTextPh" class="rich-ph" aria-hidden="true"></div>\n        </div>'),
    ('.rich-editor:focus{border-color:var(--brand);box-shadow:var(--focus)}', PH_CSS),
    ('.img-handle-w{left:-6px;top:calc(50% - 6px);cursor:ew-resize}', DEL_CSS),
    ("document.getElementById('qScore').value='1';",
     "document.getElementById('qScore').value='';   /* v4.183.0: بارم خالی می‌آید و ۱ فقط راهنما است */"),
    ("function renderQuestionPreviewNow(){\n  const c=document.getElementById('qPrevContent'),ed=document.getElementById('qText'); if(!c||!ed)return;",
     "function renderQuestionPreviewNow(){\n  const c=document.getElementById('qPrevContent'),ed=document.getElementById('qText'); if(!c||!ed)return;\n  syncQuestionPlaceholder();"),
    ("document.getElementById('qText').innerHTML=normalizeQuestionHtml(it.html); decorateQuestionImages(document.getElementById('qText'));",
     "document.getElementById('qText').innerHTML=normalizeQuestionHtml(it.html); decorateQuestionImages(document.getElementById('qText')); syncQuestionPlaceholder();"),
    ("  const box=document.createElement('div'); box.innerHTML=String(html||'');\n  box.querySelectorAll('.img-resize-box').forEach(x=>x.remove());",
     "  const box=document.createElement('div'); box.innerHTML=String(html||'');\n  box.querySelectorAll('.img-resize-box').forEach(x=>x.remove());\n  /* v4.183.0: نشانهٔ «متن راهنما» هرگز داخل سوال ذخیره نمی‌شود */\n  box.querySelectorAll('[data-ph-only]').forEach(x=>x.removeAttribute('data-ph-only'));"),
    ("    box.appendChild(h);\n  });\n  host.appendChild(box); imgHandleBox=box; positionImgHandles(img);", HANDLE_JS),
    ("function attachImgHandles(img){", REMOVE_JS),
    ("function applyQuestionTemplate(){", HELPER + TEMPLATE),
]


def patch(path: Path) -> None:
    text = path.read_text(encoding='utf-8')
    if 'QUESTION_PH' in text:
        print(f'— {path.relative_to(ROOT)}: از قبل اصلاح شده است')
        return
    for anchor, replacement in EDITS:
        if text.count(anchor) != 1:
            raise SystemExit(f'لنگر یکتا پیدا نشد در {path}: {anchor[:70]!r} ({text.count(anchor)})')
        text = text.replace(anchor, replacement, 1)
    path.write_text(text, encoding='utf-8')
    print(f'✅ {path.relative_to(ROOT)} اصلاح شد')


for target in TARGETS:
    patch(target)
