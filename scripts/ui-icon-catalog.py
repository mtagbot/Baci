"""Original 24-unit school/content icon family. No font icons or external assets."""
import json
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
# All geometry is fixed application-owned SVG; never render user-supplied SVG.
ICONS={
'school':('مدرسه','<path d="m3 9 9-6 9 6v12H3Z"/><path d="M9 21v-6h6v6M7 11h.01M17 11h.01M12 7v3M10.5 8.5h3"/>'),
'book':('محتوای آموزشی','<path d="M12 5C8 2 4 3 2 4v15c3-1 7-1 10 2 3-3 7-3 10-2V4c-3-1-7-2-10 1Zm0 0v16"/>'),
'student':('دانش‌آموز','<path d="m2 7 10-4 10 4-10 4Zm3 2v5c3 3 11 3 14 0V9M22 7v7M5 21c1-5 13-5 14 0"/>'),
'users':('اعضای مدرسه','<circle cx="9" cy="7" r="3"/><path d="M2 21v-3c0-6 14-6 14 0v3M16 4c5 0 5 6 1 6M19 14c3 1 3 4 3 7"/>'),
'user':('حساب کاربری','<circle cx="12" cy="7" r="4"/><path d="M4 21v-2c0-8 16-8 16 0v2"/>'),
'check':('تأیید','<circle cx="12" cy="12" r="9"/><path d="m7 12 3 3 7-7"/>'),
'close':('بستن','<path d="m6 6 12 12M6 18 18 6"/>'),
'error':('خطا','<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m-6 0 6-6"/>'),
'warning':('هشدار','<path d="M12 3 2 21h20Z"/><path d="M12 9v5m0 3v.1"/>'),
'info':('راهنما','<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v.1"/>'),
'calendar':('برنامه و تقویم','<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 11h18M8 15h2m4 0h2m-8 3h2"/>'),
'exam':('آزمون و ارزشیابی','<rect x="5" y="4" width="14" height="18" rx="2"/><path d="M9 2h6v4H9Zm0 8h6m-6 4h3m-3 4h6"/>'),
'edit':('ویرایش','<path d="m14 5 5 5M3 21l5-1L21 7c2-2-2-6-4-4L4 16Z"/>'),
'report':('گزارش و کارنامه','<path d="M14 2H5v20h14V7Zm0 0v5h5M8 17v-3m4 3v-6m4 6v-4"/>'),
'chart':('تحلیل عملکرد','<path d="M3 3v18h18M7 17v-4m5 4V8m5 9V4"/>'),
'print':('چاپ','<path d="M7 8V3h10v5M7 17H3V9h18v8h-4M7 14h10v8H7ZM17 11h.1"/>'),
'save':('ذخیره','<path d="M3 3h15l3 3v15H3Zm4 0v7h10V3M7 21v-7h10v7"/>'),
'search':('جستجو','<circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/>'),
'lock':('امنیت و ورود','<rect x="4" y="10" width="16" height="12" rx="2"/><path d="M7 10V6c0-6 10-6 10 0v4M12 15v3"/>'),
'unlock':('دسترسی باز','<rect x="4" y="10" width="16" height="12" rx="2"/><path d="M7 10V6c0-6 10-6 10 0M12 15v3"/>'),
'key':('کلید دسترسی','<circle cx="8" cy="8" r="5"/><path d="m12 12 9 9m-5-5 3-3m-6 0 3-3"/>'),
'settings':('تنظیمات','<path d="M4 6h16M4 12h16M4 18h16M8 3v6m8 0v6m-8 0v6"/>'),
'shield':('حفاظت','<path d="m12 2 9 4v6c0 5-9 10-9 10S3 17 3 12V6Zm-5 9 3 3 7-7"/>'),
'camera':('دوربین','<path d="M8 6 9 3h6l1 3h5v15H3V6Z"/><circle cx="12" cy="13" r="4"/>'),
'eye':('نمایش','<path d="M2 12c5-10 15-10 20 0-5 10-15 10-20 0Z"/><circle cx="12" cy="12" r="3"/>'),
'eye-off':('پنهان کردن','<path d="M2 12c5-10 15-10 20 0-5 10-15 10-20 0ZM3 3l18 18"/>'),
'refresh':('بازخوانی','<path d="M21 3v6h-6M3 21v-6h6M20 9a8 8 0 0 0-14-4M4 15a8 8 0 0 0 14 4"/>'),
'upload':('بارگذاری','<path d="M3 16v5h18v-5M12 16V3m-5 5 5-5 5 5"/>'),
'download':('دریافت','<path d="M3 16v5h18v-5M12 3v13m-5-5 5 5 5-5"/>'),
'folder':('پرونده‌ها','<path d="M2 5h8l3 3h9v13H2Z"/>'),
'message':('پیام‌ها','<path d="M3 3h18v14H9l-6 5ZM7 8h10M7 12h7"/>'),
'mail':('ارسال پیام','<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m3 6 9 7 9-7"/>'),
'bell':('اعلان‌ها','<path d="M4 18h16l-2-4V9c0-8-12-8-12 0v5Zm6 3h4M12 2V1"/>'),
'bot':('دستیار ارتباطات','<rect x="3" y="7" width="18" height="14" rx="4"/><path d="M12 7V2M10 2h4M7 12v2m10-2v2m-9 3h8"/>'),
'link':('پیوند','<path d="m10 7 3-3c6-6 13 1 7 7l-3 3M14 17l-3 3C5 26-2 19 4 13l3-3m1 6 8-8"/>'),
'phone':('تماس','<path d="m3 2 5 1 1 5-3 2c2 4 4 6 8 8l2-3 5 1 1 5C11 26-2 13 3 2Z"/>'),
'device':('دستگاه','<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/>'),
'desktop':('نمایشگر','<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M12 17v5m-5 0h10"/>'),
'location':('موقعیت','<path d="M12 22S4 13 4 9a8 8 0 0 1 16 0c0 4-8 13-8 13Z"/><circle cx="12" cy="9" r="3"/>'),
'globe':('وب و ارتباط','<circle cx="12" cy="12" r="10"/><ellipse cx="12" cy="12" rx="4" ry="10"/><path d="M2 12h20"/>'),
'award':('موفقیت تحصیلی','<circle cx="12" cy="8" r="6"/><path d="m8 13-2 9 6-3 6 3-2-9"/>'),
'palette':('سفارشی‌سازی','<path d="M12 2C-1 2-1 22 12 22c5 0-1-6 4-6 9 0 8-14-4-14Z"/><path d="M7 8h.1M12 6h.1M17 9h.1M6 13h.1"/>'),
'image':('تصویر','<rect x="2" y="3" width="20" height="18" rx="2"/><circle cx="8" cy="8" r="2"/><path d="m3 18 5-5 4 4 5-7 5 8"/>'),
'video':('ویدئو','<rect x="2" y="5" width="14" height="14" rx="2"/><path d="m16 10 6-4v12l-6-4"/>'),
'audio':('صوت','<rect x="8" y="2" width="8" height="13" rx="4"/><path d="M4 11a8 8 0 0 0 16 0M12 19v3m-4 0h8"/>'),
'add':('افزودن','<path d="M12 3v18M3 12h18"/>'),
'delete':('حذف','<path d="M3 6h18M9 6V3h6v3M5 6l1 16h12l1-16M10 10v8m4-8v8"/>'),
'logout':('خروج','<path d="M10 3H3v18h7M9 12h13m-5-5 5 5-5 5"/>'),
'menu':('فهرست صفحات','<path d="M3 5h18M3 12h18M3 19h18"/>'),
'home':('صفحه نخست','<path d="m2 10 10-8 10 8M5 8v14h14V8M9 22v-8h6v8"/>'),
'clock':('زمان','<circle cx="12" cy="12" r="10"/><path d="M12 5v7l5 3"/>'),
'science':('آزمایشگاه','<path d="M8 2h8m-6 0v7L3 21h18L14 9V2M7 15h10"/>'),
'attach':('پیوست','<path d="m8 13 7-7c5-5 10 0 5 5L9 22C3 28-3 20 3 14L14 3"/>'),
'card':('کارت شناسایی','<rect x="2" y="4" width="20" height="16" rx="2"/><circle cx="8" cy="10" r="2"/><path d="M5 17c0-5 6-5 6 0M14 9h5m-5 5h5"/>'),
'health':('سلامت','<path d="M12 21S-1 13 3 5c3-5 9 0 9 0s6-5 9 0c4 8-9 16-9 16Z"/><path d="M8 12h8m-4-4v8"/>'),
'network':('اتصال','<path d="M2 8c6-6 14-6 20 0M5 12c4-4 10-4 14 0M8 16c2-2 6-2 8 0M12 20h.1"/>'),
'filter':('فیلتر','<path d="M2 3h20l-8 10v7l-4 2V13Z"/>'),
'list':('فهرست','<path d="M8 5h13M8 12h13M8 19h13M3 5h.1M3 12h.1M3 19h.1"/>'),
'bolt':('عملیات سریع','<path d="m14 2-11 12h8l-1 8 11-12h-8Z"/>'),
'crop':('برش','<path d="M6 2v16h16M2 6h16v16M3 21 21 3"/>'),
'help':('راهنما','<circle cx="12" cy="12" r="10"/><path d="M9 8c0-5 9-4 6 1l-3 3v2m0 4v.1"/>'),
}
GROUPS={
'school':'🏫 🏢','book':'📚 📘 📗 📙 📕 📖','student':'🎓 👦 👧','users':'👥 👨‍🏫 👩‍🏫 🧑‍🏫','user':'👤 👨 👩 🧑',
'check':'✅ ☑ ✔ ✓','error':'❌ ❎ ✗ ✖ ⛔ 🚫','warning':'⚠','info':'ℹ','calendar':'📅 🗓 📆','exam':'📝 📋','edit':'✏ ✍ 🖊','report':'📄 📑 📃','chart':'📊 📈 📉',
'print':'🖨','save':'💾','search':'🔍 🔎','lock':'🔒 🔐','unlock':'🔓','key':'🔑 🗝','settings':'⚙ 🔧 🧰','shield':'🛡','camera':'📷','eye':'👁','eye-off':'🙈','refresh':'🔄 🔁 ♻ 🔃',
'upload':'📤','download':'📥','folder':'🗂 📁 📂 🗄 🗜','message':'💬 📢 📣','mail':'📨 ✉ ✈','bell':'🔔','bot':'🤖','link':'🔗','phone':'📞 ☎','device':'📱','desktop':'🖥 💻','location':'📍 🧭 📌','globe':'🌐 📡',
'award':'🎉 🎊 🏆 ✨ 🏅 🎖 🌺','palette':'🎨','image':'🖼','video':'🎬','audio':'🎤 🎵','add':'➕','delete':'🗑 🧹','logout':'🚪','menu':'☰','home':'🏠','clock':'🕓 ⏳ ⌛','science':'🧪 ⚗','attach':'📎','card':'🪪 🏷','health':'🩺 ❤ ♥ 🖤','network':'🟢 🟡 🔴 🟠','bolt':'⚡ 🚀 🏃','crop':'✂','help':'❓ ❔',
}
MAP={s:k for k,v in GROUPS.items() for s in v.split()}
def svg(name):
 label,paths=ICONS[name]
 return '<svg data-ui-icon="'+name+'" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'+paths+'</svg>'
if __name__=='__main__':
 out=ROOT/'update-v4.152.0/assets/js/school-icons.js';out.parent.mkdir(parents=True,exist_ok=True)
 out.write_text('/* Original school icon geometry. Decorative SVG; text labels remain authoritative. */\nwindow.SchoolIcons='+json.dumps({'icons':{k:{'label':v[0],'svg':svg(k)} for k,v in ICONS.items()},'map':MAP},ensure_ascii=False,separators=(',',':'))+';\n')
