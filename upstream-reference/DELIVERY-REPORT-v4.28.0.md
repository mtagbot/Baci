# گزارش تحویل v4.28.0 - سامانه آزمون آنلاین حرفه‌ای با ضدتقلب

## خروجی‌ها
- `PROJECT-CODE-BUNDLE-v4.28.0.json` (130 فایل، 1.35 MB)
- `MEMORY-RESUME-v4.28.md`
- فایل‌های جدید (14 فایل):
  - `includes/online_exam_helpers.php`
  - `online-exams.php`
  - `online-exam-form.php`
  - `online-exam-questions.php`
  - `online-exam-media-upload.php`
  - `online-question-bank.php`
  - `online-exam-categories.php`
  - `online-exam-take.php`
  - `online-exam-result.php`
  - `online-exam-results.php`
  - `online-exam-monitor.php`
  - `online-exam-preview.php`
  - `online-exam-api.php`
  - `student-online-exams.php`

## تغییرات اصلی - پیاده‌سازی کامل درخواست کاربر

### 1. سامانه مدیریت آزمون آنلاین (Quiz Maker Pro 21.8.4 Features)
تمام قابلیت‌های افزونه وردپرسی Quiz Maker Pro الگوبرداری شد:
- زمانبندی: تاریخ/زمان شروع و پایان با datetime-local، مدت آزمون
- دسته‌بندی آزمون‌ها و دسته‌بندی سوالات
- نمره و وزن پاسخ‌ها: points per question + per option + partial scoring برای checkbox
- انتخابگر تاریخ و زمان شروع/پایان، مدت، تعداد تلاش مجاز، نمره قبولی
- تصادفی‌سازی سوالات و گزینه‌ها
- نحوه نمایش نتایج: بعد ارسال، بعد پایان مهلت، فوری، عدم نمایش

#### انواع سوالات (12 نوع) با ویرایشگر حرفه‌ای:
- تک‌گزینه‌ای Radio، چندگزینه‌ای Checkbox، کشویی Dropdown
- متن کوتاه، متن بلند، عددی با تلرانس
- اطلاع‌رسانی/بنر Info (بدون نمره)
- جای خالی Fill in the blanks (چند جای خالی)
- تطبیقی Matching (چپ/راست)
- آپلود فایل، آپلود صدا Voice (با ضبط مستقیم MediaRecorder)
- تخته سفید Whiteboard (canvas نقاشی با لمس)

#### ویرایشگر حرفه‌ای سوالات:
- contenteditable با toolbar: Bold, Italic, List, Image Upload, Video, Audio
- آپلود تصویر اصلی سوال، ویدیو، صدا
- گزینه‌ها با متن، صحیح/غلط، نمره وزنی
- تصویر، ویدیو، صدا در متن سوال پشتیبانی می‌شود (self-hosted)

#### جواب‌ها با ویرایشگر:
- هر جواب می‌تواند تصویر داشته باشد
- برای file/voice/whiteboard: آپلود و پیش‌نمایش

#### بانک سوالات:
- ذخیره سوالات دبیر با تیک "ذخیره در بانک"
- بانک شخصی + عمومی با دسته، سختی، تعداد استفاده
- درج از بانک به آزمون با انتخاب چندتایی

### 2. دانش‌آموزان در پنل خود آزمون‌ها را می‌بینند
- در `student-panel.php` بخش جدید
- `student-online-exams.php` لیست آزمون‌های قابل شرکت بر اساس کلاس/سال
- ادامه آزمون ناتمام

### 3. سیستم امنیتی ضدتقلب - پیاده‌سازی کامل

#### وب‌کم گوشه پایین:
- در `online-exam-take.php`: ویدیو 160x120 پایین چپ با border قرمز
- در `online-exam-monitor.php`: لیست لحظه‌ای دانش‌آموزان در حال آزمون + دکمه "مشاهده وب‌کم دانش‌آموز"
- با کلیک: درخواست pending → دانش‌آموز heartbeat بعدی snapshot کم‌حجم (320x240, jpeg 0.4) می‌گیرد و به `uploads/online-exams/webcam/temp/` آپلود می‌کند
- نمایش در کادر شناور با دکمه ذخیره و بستن با کلیک بیرون (per spec)

#### IP و موقعیت:
- لیست IP همه دانش‌آموزان هر 15 ثانیه
- تشخیص IP تکراری (same_ip)
- موقعیت جغرافیایی هر دانش‌آموز + محاسبه فاصله Haversine + هشدار نزدیکی <150 متر

#### ردیابی خروج:
- visibilitychange, blur, fullscreen_exit, copy, paste, right_click, PrintScreen, F12 → همه در `online_exam_proctoring_logs`
- شمارنده tab_switch, exit, copy در attempts و live_sessions
- لیست در مانیتور و نتایج

#### بررسی دسترسی قبل آزمون:
- `permissionStage` با چک دوربین، میکروفون، موقعیت، اینترنت
- درخواست اجازه با اعلان
- حین آزمون اگر دسترسی قطع شود → overlay block + پیام تا فعال‌سازی مجدد

#### کیفیت اینترنت:
- چک latency قبل آزمون با fetch HEAD

#### تایمر و ذخیره:
- خروج از صفحه تایمر را متوقف نمی‌کند (بر اساس start_time سرور)
- پاسخ‌ها در localStorage ذخیره حتی برای فایل/voice/whiteboard
- `loadLocalAnswers` بازیابی

#### Overlay ضد AI:
- watermark-overlay با 80 متن تکراری opacity 0.03 شامل شناسه دانش‌آموز و آزمون
- متن مخفی opacity 0 برای فریب OCR
- هدف: اگر اسکرین‌شات به AI داده شود، AI متوجه تقلب شود

#### مسدودسازی:
- user-select:none، copy/paste/contextmenu/PrintScreen/F12/Ctrl+C/V/P/S/U مسدود
- @media print {display:none}

### 4. API و دیتابیس
- 10 جدول جدید + ensure_online_exams_schema()
- API مرکزی با 9 action
- بدون CDN، self-hosted کامل

### 5. ادغام
- منوهای جدید در header برای مدیر/دبیر/دانش‌آموز
- teacher-panel دکمه‌های آزمون آنلاین
- executive-panel تب آزمون آنلاین
- student-panel بخش آزمون‌های آنلاین

## فایل‌های تغییر یافته
- `includes/header.php`
- `teacher-panel.php`
- `executive-panel.php`
- `student-panel.php`
- `sql/database.sql` (اضافه شدن 10 جدول)
- 14 فایل جدید

## نحوه تست
1. ورود به عنوان مدیر/دبیر → منو "🧪 آزمون‌های آنلاین" → ایجاد آزمون جدید → دسته، سال، کلاس، درس، زمان شروع/پایان، مدت، تنظیمات ضدتقلب → ذخیره
2. طراحی سوالات → انتخاب نوع → ویرایشگر حرفه‌ای → تصویر/ویدیو/صدا → ذخیره + تیک بانک
3. انتشار آزمون
4. ورود به عنوان دانش‌آموز → "🧪 آزمون‌های آنلاین من" → شروع آزمون → تایید دسترسی‌ها (دوربین، مکان، میکروفون) → آزمون با وب‌کم گوشه + تایمر + watermark + ضدکپی
5. ورود به عنوان دبیر همزمان → "📡 مانیتورینگ زنده" → لیست دانش‌آموزان آنلاین، IP، موقعیت، خروج‌ها → دکمه مشاهده وب‌کم → snapshot لحظه‌ای در کادر شناور → ذخیره
6. ارسال آزمون → نتیجه

## نکات امنیتی
- تصاویر وب‌کم موقت 10 دقیقه‌ای حذف می‌شوند مگر ذخیره
- همه درخواست‌ها با CSRF
- دسترسی‌ها با session role check
- فایل‌ها با random name و خارج از web root قابل حدس نیستند

---
آماده برای تست production و توسعه بیشتر.
