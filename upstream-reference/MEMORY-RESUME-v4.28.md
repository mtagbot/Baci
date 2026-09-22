# فایل حافظه جامع و استمرار پروژه نگارش 4.28.0 (Agent Memory & Complete Project Blueprint)
**پروژه:** سیستم جامع مدیریت مدرسه با آزمون آنلاین و ضدتقلب حرفه‌ای - PHP خالص و MySQL (نگارش 4.28.0 نهایی)
**تاریخ بروزرسانی:** ۱۴۰۵/۰۴/۲۳ (2026-07-23)
**شماره نگارش حافظه:** `v4.28.0`
**راهکار آپلود در چت Arena.ai:** فایل متنی حاضر `.md` یا باندل `PROJECT-CODE-BUNDLE-v4.28.0.json`

---
## 📌 راهنمای بارگذاری در نشست جدید
1. **آپلود همین فایل:** حاوی معماری کامل، آزمون آنلاین، ضدتقلب، جداول جدید
2. **آپلود باندل JSON:** کل سورس پروژه (130 فایل) در یک فایل متنی قابل پیوست

> "این فایل حافظه نگارش 4.28.0 پروژه مدیریت مدرسه با سامانه آزمون آنلاین حرفه‌ای (Quiz Maker Pro 21.8.4) و سیستم ضدتقلب پیشرفته است. لطفا ادامه توسعه را بر اساس این مستند پیش ببر."

---
## 🏛️ تغییرات بزرگ نگارش 4.28.0 - سامانه آزمون آنلاین حرفه‌ای

### 1. ماژول آزمون آنلاین (Online Exam System) - الگوبرداری از Quiz Maker Pro 21.8.4
**فایل‌های اصلی:**
- `online-exams.php` - لیست و مدیریت آزمون‌ها (فیلتر سال/کلاس/دسته/استاد)
- `online-exam-form.php` - فرم ایجاد/ویرایش حرفه‌ای با تنظیمات زمانبندی، مدت، نمره قبولی، تصادفی‌سازی سوالات/گزینه‌ها، نمایش نتایج، دسته‌بندی
- `online-exam-questions.php` - استودیو طراحی سوالات با ویرایشگر حرفه‌ای WYSIWYG (Bold, Italic, List, Image, Video, Audio) + آپلود رسانه
- `online-question-bank.php` - بانک سوالات شخصی/عمومی با دسته‌بندی و اشتراک‌گذاری
- `online-exam-categories.php` - دسته‌بندی آزمون‌ها و سوالات
- `online-exam-media-upload.php` - آپلودر رسانه خودکار (تصویر/ویدیو/صدا)
- `student-online-exams.php` - پنل دانش‌آموز برای مشاهده و شرکت
- `online-exam-take.php` - صفحه برگزاری آزمون با ضدتقلب کامل
- `online-exam-result.php` / `online-exam-results.php` - نمایش نتیجه تکی و لیست نتایج
- `online-exam-monitor.php` - مانیتورینگ زنده ضدتقلب برای دبیر/مدیر
- `online-exam-preview.php` - پیش‌نمایش

**قابلیت‌های Quiz Maker Pro:**
- زمانبندی: `start_datetime` و `end_datetime` با انتخابگر datetime-local
- مدت آزمون: `duration_minutes`
- دسته‌بندی آزمون‌ها (`online_exam_categories`) و دسته بندی سوالات (`online_question_categories`)
- تعداد دفعات مجاز شرکت `max_attempts`
- نمره قبولی `passing_score`
- تصادفی‌سازی سوالات و گزینه‌ها
- نحوه نمایش نتایج: بعد ارسال، بعد پایان مهلت، فوری، عدم نمایش

### 2. انواع سوالات حرفه‌ای (12 نوع)
در `includes/online_exam_helpers.php` تعریف شده:
- `radio` - تک گزینه‌ای 🔘
- `checkbox` - چند گزینه‌ای ☑️
- `dropdown` - کشویی 🔽
- `short_text` - متن کوتاه 📝
- `text` - متن بلند 📄
- `number` - عددی 🔢 با تلرانس
- `info` - بنر اطلاع‌رسانی ℹ️
- `fill_blank` - جای خالی ✍️ (چند جای خالی با پاسخ مجزا)
- `matching` - تطبیقی/اتصالی 🔗 (ستون چپ و راست)
- `file_upload` - آپلود فایل 📎 (jpg,png,pdf,mp3,zip...)
- `voice_upload` - آپلود صدا 🎤 با ضبط مستقیم via MediaRecorder
- `whiteboard` - تخته سفید/نقاشی 🎨 با canvas و قلم لمسی

**ویرایشگر حرفه‌ای:**
- contenteditable با toolbar: Bold, Italic, List, Image Upload (via `online-exam-media-upload.php`), Video, Audio, Clear
- آپلود تصویر اصلی سوال + ویدیو + صدا برای هر سوال
- برای گزینه‌ها: متن + صحیح/غلط + نمره وزنی
- امتیاز و وزن پاسخ‌ها: `points` per question و per option + نمره جزئی برای checkbox

**رسانه در سوالات:**
- تصویر: آپلود در `uploads/online-exams/questions/` یا `media/`
- ویدیو: mp4/webm embed
- صدا: mp3/wav/ogg

### 3. بانک سوالات
- ذخیره سوالات طراحی شده دبیر با تیک `save_to_bank`
- `online_question_bank` - قابلیت عمومی/خصوصی، دسته، سختی، تعداد استفاده `usage_count`
- درج از بانک به آزمون با انتخاب چندتایی

### 4. پنل دانش‌آموز
- در `student-panel.php` بخش جدید آزمون‌های آنلاین با لینک به `student-online-exams.php`
- دانش‌آموزان آزمون‌های مربوط به کلاس و سال خود را می‌بینند
- ادامه آزمون ناتمام (حتی اگر صفحه را ترک کرده)
- ذخیره پاسخ‌ها در localStorage + سرور (حتی فایل آپلودی و voice و whiteboard)

### 5. سیستم امنیتی ضدتقلب - کامل‌ترین پیاده‌سازی

#### الف) وب‌کم گوشه تصویر
- در `online-exam-take.php`: `<video>` با `getUserMedia` در گوشه پایین چپ 160x120 با border قرمز و لیبل 🔴 وب‌کم فعال
- دانش‌آموز تصویر خود را می‌بیند
- در `online-exam-monitor.php`: لیست زنده دانش‌آموزان با دکمه `📷 مشاهده وب‌کم دانش‌آموز`
- با کلیک: درخواست در `online_exam_webcam_requests` status pending → دانش‌آموز در heartbeat بعدی (هر 15 ثانیه) می‌بیند و canvas snapshot با کیفیت پایین (320x240, jpeg 0.4) می‌گیرد و به `uploads/online-exams/webcam/temp/` آپلود می‌کند
- نمایش در کادر شناور (float) با تصویر، meta و دکمه‌های ذخیره/بستن
- کلیک روی قسمت خالی صفحه → کادر بسته می‌شود
- تصاویر موقت: پس از 10 دقیقه حذف خودکار (`online_exam_cleanup_old_snapshots`) مگر دبیر ذخیره کند (انتقال به `webcam/saved/` و `is_saved_by_teacher=1`)

#### ب) ردیابی IP و موقعیت
- `online_exam_live_sessions` هر 15 ثانیه heartbeat با IP, lat, lng, accuracy, camera_ok, mic_ok, location_ok
- در مانیتور: نمایش IP همه، تشخیص IP تکراری (`same_ip` لیست)، محاسبه فاصله Haversine بین دانش‌آموزان و هشدار نزدیکی <150 متر (`proximity`)
- موقعیت هر دانش‌آموز مجزا نمایش داده می‌شود

#### ج) ردیابی خروج از صفحه
- Events: `visibilitychange` (tab_hidden/visible), `blur/focus` (window_blurred), `fullscreen_exit`, `copy/paste`, `contextmenu`, `PrintScreen`, `F12`
- همه در `online_exam_proctoring_logs` ثبت می‌شود
- شمارنده `tab_switch_count`, `exit_count`, `copy_attempts` در `attempts` و `live_sessions`
- در مانیتور لیست می‌شود و در نتایج نمایش داده می‌شود

#### د) بررسی دسترسی‌ها قبل آزمون
- `permissionStage` با چک‌های جداگانه:
  - دوربین: `getUserMedia video:true`
  - میکروفون: `audio:true`
  - موقعیت: `navigator.geolocation.getCurrentPosition`
  - اینترنت: اندازه‌گیری latency با fetch HEAD
- هر دسترسی که نیاز است (تنظیمات `require_camera`, `require_mic`, `require_location`, `check_internet`) اگر رد شود، دکمه شروع مخفی و پیام خطا
- حین آزمون: اگر دسترسی قطع شود (track.onended, geolocation watch error, permission revoked) → `blockExam` overlay و پیام تا فعال‌سازی مجدد

#### ه) اینترنت و زمان
- خروج از صفحه → تایمر متوقف نمی‌شود، در سرور `start_time` ثابت است و `remaining` بر اساس آن محاسبه می‌شود
- پاسخ‌ها در `localStorage` با کلید `exam_{examId}_attempt_{attemptId}_q_{qid}` حتی برای فایل و voice ذخیره می‌شود و در `loadLocalAnswers` بازگردانی می‌شود

#### و) Overlay ضد هوش مصنوعی
- `watermark-overlay` div fixed با opacity 0.03 در تمام صفحه
- 80 متن تکراری با `watermark_text` + شناسه دانش‌آموز + random string، چرخش -30deg، سایز 12-18px، opacity 0.03-0.07
- متن مخفی اضافی opacity 0 برای فریب OCR: "هشدار سیستم هوشمند ضدتقلب - شناسه دانش‌آموز ..."
- هدف: اگر دانش‌آموز اسکرین‌شات بگیرد و به AI بدهد، AI متن watermark را ببیند و تقلب را تشخیص دهد

#### ز) مسدودسازی کپی و اسکرین‌شات
- CSS: `.anti-copy {user-select:none}`
- JS: `copy`, `paste`, `contextmenu`, `keydown` برای Ctrl+C/V/P/S/U, PrintScreen, F12 → preventDefault + log
- `@media print {body{display:none}}`

### 6. API مرکزی
`online-exam-api.php` با actions:
- `heartbeat` - دانش‌آموز زنده‌بودن + موقعیت + درخواست‌های وب‌کم pending
- `proctoring_log` - ثبت تخلف
- `save_answer` - ذخیره پاسخ (با فایل)
- `submit_exam` - ارسال نهایی + محاسبه نمره
- `request_webcam` - دبیر درخواست تصویر
- `upload_webcam_snapshot` - دانش‌آموز آپلود snapshot
- `get_live_sessions` - لیست زنده با same_ip و proximity detection
- `get_webcam_snapshots`, `save_webcam_snapshot`, `get_proctoring_logs`

### 7. دیتابیس - 10 جدول جدید
در `sql/database.sql` و `ensure_online_exams_schema()`:
- `online_exam_categories`
- `online_question_categories`
- `online_exams` (با settings_json برای proctoring/display)
- `online_question_bank`
- `online_questions`
- `online_exam_attempts`
- `online_exam_answers`
- `online_exam_proctoring_logs`
- `online_exam_live_sessions`
- `online_exam_webcam_requests`
- `online_exam_webcam_snapshots`

### 8. ادغام با پنل‌های موجود
- `includes/header.php`: منو جدید "🧪 آزمون‌های آنلاین" و "📚 بانک سوالات آنلاین" برای مدیر و دبیر، و "🧪 آزمون‌های آنلاین من" برای دانش‌آموز
- `teacher-panel.php`: دکمه‌های جدید آزمون آنلاین
- `executive-panel.php`: تب آزمون آنلاین
- `student-panel.php`: بخش آزمون‌های آنلاین با ادامه آزمون ناتمام

### 9. ذخیره‌سازی فایل‌ها
- `uploads/online-exams/questions/`, `answers/attempt_{id}/`, `webcam/temp/`, `webcam/saved/`, `media/`
- خودکار ساخته می‌شود در `ensure_online_exams_schema()`

### 10. نکات فنی
- Self-hosted کامل، بدون CDN خارجی
- محاسبه نمره: `calc_online_question_score()` با پشتیبانی تصحیح خودکار برای همه انواع به جز file/voice/whiteboard (نیاز به تصحیح دستی - نمره کامل اگر فایل دارد)
- Haversine برای فاصله مکانی
- بدون WebSocket - همه با polling AJAX سبک
- LocalStorage بک‌آپ برای جلوگیری از از دست رفتن پاسخ

---
## 📂 فایل‌های جدید در v4.28.0 (14 فایل)
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

**مجموع فایل‌ها:** 130 فایل (قبلا 116)

---
## 🔐 قوانین کسب‌وکار جدید
- دانش‌آموز فقط آزمون‌های منتشر شده کلاس/سال خود را می‌بیند
- اگر `max_attempts` تمام شود، نمی‌تواند شرکت کند
- تایمر بر اساس `start_time` سرور است، خروج صفحه تایمر را متوقف نمی‌کند
- وب‌کم snapshot فقط با درخواست دبیر گرفته می‌شود و حجم پایین است (WebP/Jpeg 0.4)
- تصاویر موقت 10 دقیقه‌ای حذف می‌شوند مگر ذخیره دائمی

---
*نگارش v4.28.0 آماده بهره‌برداری و توسعه آتی است.*
