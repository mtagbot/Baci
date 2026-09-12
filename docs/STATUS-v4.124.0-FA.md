# وضعیت کامل سامانه — تا نسخه ۴.۱۲۴.۰ (سایت) و v2.55.0 (دسکتاپ)

> این سند از روی بررسی مستقیم مخزن ساخته شده است (کامیت `6a9026e`، برنچ `arena/01a0934a-baci`).
> هر عددی که اینجا آمده از دستور واقعی روی مخزن خوانده شده، نه تخمین.
> تاریخ بازبینی: ۱۴۰۴/۰۶/۲۱ (2026-09-12)

---

## ۱) خلاصه اجرایی — الان کجاییم

| لایه | نام | نسخه فعلی | وضعیت |
|---|---|---|---|
| وب‌سایت (PHP/MySQL، هاست اشتراکی) | سامانه کارنامه مدرسه | **v4.124.0** | فعال، ۹۱ بسته آپدیت منتشرشده |
| دسکتاپ ویندوز (نسل ۲) | SchoolDesk Pro | **v2.55.0** | فعال، باندل کامل سایت + PHP + SQLite |
| دسکتاپ ویندوز (نسل ۱) | SchoolDesk | v1.4.0 | منسوخ‌شده (جایگزین: نسل ۲) |
| ابزار استخراج سوال از PDF | `tools/analyze_exam_pdf.py` | هم‌نسخه با v4.110 | فعال، ۱۴۱۴ خط پایتون |

**اندازه کد فعلی سایت** (از دل باندل `SchoolDeskPro-v2.55.0-win64.zip` استخراج و شمرده شد):

- **۲۸٬۹۶۰ خط PHP** در **۱۲۴ فایل** (۹۰ صفحه در ریشه + ۲۷ در `includes/` + ۳ در `api/` + ۳ در `config/` + ۱ در `cron/`)
- **۱۴٬۱۲۳ خط CSS/JS** در ۹ فایل asset (بدون هیچ CDN/کتابخانه خارجی)
- **۵۶ جدول دیتابیس** (۴۷ تا در `sql/database.sql`، بقیه با `ensure_*_schema()` در زمان اجرا خودکار ساخته می‌شوند)
- **۴۱ جدول** بین سایت و دسکتاپ همگام می‌شود
- مخزن گیت: ۵۴۷ فایل ترک‌شده، `.git` = ۲۸ مگابایت، کل checkout = ۶۹ مگابایت

---

## ۲) معماری سایت

### ۲.۱ چیدمان فایل‌ها
```
www/
├── *.php              ۹۰ صفحه/endpoint (هر صفحه = یک بخش کامل UI + منطق)
├── includes/          ۲۷ فایل مشترک (db, auth, functions, header, footer, helpers)
├── api/               API عمومی + مستندات OpenAPI (index.php, docs.php, admin-import.php)
├── assets/            css/ (3 فایل) + js/ (6 فایل، شامل jsQR و qrcode-generator)
├── config/            config.php, database.php, defaults.php, installed.lock
├── cron/              import-queue-worker.php
├── sql/               database.sql (MySQL) + schema-sqlite.sql (دسکتاپ)
└── uploads/           exams/, online-exams/{answers,media,questions,voice,webcam}, فونت‌ها
```

### ۲.۲ مکانیزم‌های کلیدی

**مسیریابی:** روی هاست، Apache مستقیم فایل‌ها را اجرا می‌کند. روی دسکتاپ، `router.php`
نقش Apache را بازی می‌کند (PHP built-in server) — فایل استاتیک را مستقیم می‌دهد، فایل PHP
موجود را اجرا می‌کند، و در نهایت به `index.php` می‌افتد. یک نکته مهم در کد رعایت شده:
URL های استاتیکِ *موجود‌نبود* باید ۴۰۴ بگیرند و به `index.php` نیفتند، چون `index.php`
کپچای ورود را بازتولید می‌کند و کپچای در حال پاسخ را باطل می‌کرد.

**احراز هویت و نقش‌ها:**
- `admins` با نقش `super_admin` و سطوح دسترسی (`includes/auth.php`)
- نقش‌های کادر مدرسه روی جدول `teachers` با سه پرچم: `is_deputy`, `is_counselor`, `is_executive`
- پنل‌های جدا: `teacher-panel.php`, `deputy-panel.php`, `counselor-panel.php`, `executive-panel.php`, `student-panel.php`
- ورود دانش‌آموز با سریال/کد ملی، ورود کادر با کد پرسنلی

**منوی اصلی مدیر** (از `includes/header.php` استخراج شد):
داشبورد مدیریت · مدیریت دانش‌آموزان · حضور و غیاب · مدیریت دروس · مدیریت کارنامه‌ها ·
امتحانات حضوری · آزمون‌های مجازی (آنلاین) · بازیابی دانش‌آموز · پیامک و اعلان‌ها ·
بازوی بله · ربات تلگرام · اکانت‌های متصل · تنظیمات دیگر · همگام‌سازی با سایت ·
سلامت پایگاه داده · سفارشی‌سازی · مدیریت مدیران

**مهاجرت دیتابیس:** هیچ فایل migration رسمی وجود ندارد. الگوی پروژه این است که هر ماژول
تابع `ensure_*_schema()` دارد که با `CREATE TABLE IF NOT EXISTS` + `ALTER TABLE` داخل
`try/catch` ستون‌ها را اضافه می‌کند (idempotent). به همین دلیل در همه راهنماها نوشته شده
«نیازی به تغییر دیتابیس نیست». تنها استثناها: `migration-updater.php`,
`migration-academic-year-normalizer.php`, `migration-teachers-unique-fix.php`.

**تقویم:** همه تاریخ‌های نمایشی شمسی (`includes/jdf.php`)؛ در جداول هم ستون‌های
`*_jalali` به‌صورت رشته ذخیره می‌شوند.

---

## ۳) مدل داده — ۵۶ جدول، هشت حوزه

| حوزه | جدول‌ها |
|---|---|
| پایه | `academic_years`, `classes`, `subjects`, `teachers`, `students`, `class_schedules` |
| کارنامه/نمرات | `reports`, `report_grades`, `report_locks`, `report_parent_reviews`, `grade_messages`, `grade_entry_permissions` |
| امتحان حضوری | `exam_schedules`, `exam_assignments`, `exam_designs`, `exam_design_archive`, `exam_question_bank`, `exam_student_seating` |
| آزمون آنلاین | `online_exams`, `online_questions`, `online_question_bank`, `online_question_categories`, `online_exam_categories`, `online_exam_attempts`, `online_exam_answers`, `online_exam_live_sessions`, `online_exam_proctoring_logs`, `online_exam_webcam_requests`, `online_exam_webcam_snapshots`, `online_exam_voice_notes`, `online_exam_voice_plays` |
| حضور و غیاب | `student_attendance`, `student_qr_tags` |
| انضباطی/مشاوره | `discipline_titles`, `student_discipline_records`, `counseling_requests` |
| ربات‌ها | `bale_bot_users`, `telegram_bot_users`, `bale_bot_state`, `telegram_bot_state`, `bot_admin_sessions`, `bot_message_templates`, `bot_button_templates`, `bot_login_tokens`, `bot_message_logs` |
| سیستم/پلتفرم | `admins`, `settings`, `activity_logs`, `user_sessions`, `api_tokens`, `notifications`, `sms_logs`, `import_queue`, `import_sessions`, `desk_change_log`, `desk_sync_suppress` |

**۴۱ جدول همگام‌شونده با دسکتاپ** (`SYNC_TABLES` در `desk-sync-api.php v2.13`):
همه‌ی جدول‌های بالا به‌جز جدول‌های حالت/کش (`*_bot_state`, `user_sessions`, `api_tokens`,
`import_*`, `online_exam_live_sessions`, `online_exam_proctoring_logs`,
`online_exam_webcam_*`, `online_exam_voice_*`) — یعنی داده‌های زنده/حساس آزمون آنلاین
عمداً همگام نمی‌شوند.

---

## ۴) وضعیت ماژول‌ها

| ماژول | فایل‌های اصلی | وضعیت | آخرین کار |
|---|---|---|---|
| دانش‌آموزان / پرسنلی | `students.php`, `student-modal.php`, `import-students.php`, `export-students.php` | کامل | v4.91 عکس ۳×۴ |
| کارنامه و گزارش | `reports.php`, `report-view.php`, `report-print.php`, `bulk-print.php`, `report_image.php` | کامل | v4.91 |
| حضور و غیاب + QR | `attendance.php`, `attendance-scanner.php`, `attendance-tags.php`, `attendance-scan-api.php` | کامل | v4.94 سبک‌سازی اسکنر |
| امتحان حضوری + طراحی زنده | `exams.php`, `exam-print.php` (۱۴۲۴ خط), `exam-design-api.php`, `exam-source-api.php` | کامل و بلوغ‌یافته | v4.118 سربرگ لوکس |
| بانک سوالات + ایمپورت | `exam-question-bank.php`, `exam-bank-import.php` | کامل | v4.111 |
| **آزمون آنلاین** | `online-exam-take.php` (۱۱۳۷ خط), `online-exam-api.php` (۵۸۱ خط), `online-exam-monitor.php`, `online-exam-form/questions/grading/results` | **فعال‌ترین جبهه کاری** | **v4.124.0** |
| ربات تلگرام/بله | `bot_webhook_engine.php`, `bot_role_engine.php` (۱۱۹۱ خط), `bot_admin_ui.php`, `telegram-poll.php` | کامل، چندنقشی | v4.118 (هماهنگی PDF) |
| پنل معاون/مشاور/اجرایی | `deputy-panel.php`, `counselor-panel.php`, `executive-panel.php`, `school_roles.php` | کامل | v4.90 |
| پیامک/اعلان | `messages-management.php`, `sms-panel.php`, `report-broadcast.php` | موجود | — |
| همگام‌سازی دسکتاپ | `desk-sync.php`, `desk-sync-api.php`, `desk-doctor.php`, `desk-sync-daemon.php` | کامل | v4.101 |

### ۴.۱ آزمون آنلاین — دقیقاً چه چیزی ساخته شده

**انواع سوال (۱۲ نوع، همه در v4.123 تست راندمان شده‌اند):**
رادیو · چندجوابی · کشویی · عددی · متن کوتاه · متن بلند · جای خالی · تطبیقی ·
آپلود فایل · آپلود صدا · تخته سفید · اطلاع‌رسانی
> نتیجه تست ثبت‌شده در راهنمای v4.123: آزمون جامع ۱۳ سواله، نمره خودکار ۱۱/۲۱ مطابق انتظار.

**ضدتقلب / نظارت (`online-exam-api.php` — ۱۹ اکشن):**
`heartbeat` · `proctoring_log` · `save_answer` · `submit_exam` · `start_attempt` ·
`send_voice_note` · `ack_voice_note` · `request_webcam` · `upload_webcam_snapshot` ·
`upload_webcam_video` · `get_live_sessions` · `get_webcam_snapshots` ·
`get_pending_webcam_requests` · `get_webcam_request_status` · `save_webcam_snapshot` ·
`get_attempts_in_progress` · `get_proctoring_logs`

- **موقعیت مکانی (v4.120 → v4.124):** مختصات جعلی تهران (35.68, 51.38) که در نبود GPS
  ثبت می‌شد کاملاً حذف شد. حالا آبشاری: GPS دقیق (۲ تلاش، ۲۵ ثانیه) → متعادل → کم‌دقت →
  IP تقریبی. GPS حین آزمون هر ۵ دقیقه تازه می‌شود (v4.121)، heartbeat هر ۱۵ ثانیه.
- **هشدار مجاورت:** فقط GPS واقعی با GPS واقعی مقایسه می‌شود، با لحاظ کردن دقت دو طرف؛
  سه سطح 🔴 <۲۵m · 🟠 <۸۰m · 🟡 ≤۱۵۰m + بودجه خطا.
- **تذکر صوتی Push-To-Talk (v4.121–4.122):** ضبط با MediaRecorder، کدک Opus ۱۰kbps
  مونو ۱۶kHz + حذف نویز/اکو → هر دقیقه ≈ ۸۰ کیلوبایت؛ سقف سرور ۵۱۲KB؛ ارسال به همه یا
  یک دانش‌آموز؛ تأیید پخش + صف + لاگ نظارتی؛ پاک‌سازی خودکار بعد از ۲ ساعت.
- **تایمر ضدخرابی (v4.123):** ریشه «پرت شدن از آزمون با انتخاب گزینه» پیدا شد — تایمر
  `Date.now()` دستگاه دانش‌آموز را با epoch سرور مقایسه می‌کرد. حالا نقطه شروع از
  «باقیمانده اعلام‌شده سرور» مشتق می‌شود و مستقل از ساعت دستگاه است.
- **عدالت زمانی (v4.119):** انقضا فقط از `first_started_at` + ۳۰ ثانیه گریس سنجیده
  می‌شود؛ در انقضا پاسخ‌های ذخیره‌شده جمع و با وضعیت `auto_submitted` و نمره واقعی ثبت
  می‌شوند (قبلاً نمره صفر می‌خورد)؛ حفره تقلب `save_answer` بعد از مهلت بسته شد؛
  ترتیب تصادفی با سید پایدار per-attempt.
- **UI دانش‌آموز (v4.120):** نوار وضعیت چسبان، تایمر رنگی (سبز→نارنجی→قرمز تپنده)،
  نوار پیشرفت، نقشه سوالات شناور، نشانگر ذخیره خودکار — بدون هیچ کتابخانه خارجی.

---

## ۵) پلتفرم دسکتاپ

### SchoolDesk Pro v2.55.0 (نسل ۲ — فعلی)
ساختار باندل (۲۰۵ فایل، ۳۹٫۴MB فشرده‌نشده):
```
SchoolDeskPro/
├── SchoolDeskPro.exe   لانچر ۸۷KB (C، کامپایل با zig cc)
├── php/                PHP 8.1.34 NTS x64 + ext (curl, gd, mbstring, sqlite3, pdo_sqlite, openssl…)
├── www/                کل سایت (همان کدبیس وب) + SQLite
├── server/             desk-sync-api.php (نصب روی سایت)
└── data/               sessions/, uploads/, browser-profile, port.txt
```
مکانیزم: لانچر یک `php.exe -S 127.0.0.1:<8123..8199> -t www` مخفی بالا می‌آورد، منتظر
پاسخ می‌ماند، سپس Edge/Chrome را در حالت `--app` با پروفایل ایزوله باز می‌کند (فالبک:
مرورگر پیش‌فرض + پنجره وضعیت بومی). تک‌نمونه با named mutex، و با job object حتی در
کرش هم فرزند PHP کشته می‌شود. موتور MSHTML/IE در v2.3 بازنشسته شد چون JS سایت اجرا نمی‌شد.

**همگام‌سازی:** دو طرفه، با کلید جفت‌شدن `SDP-<48hex>`، روی ۴۱ جدول، با
trigger های SQLite (`trg_sync_*`) که تغییرات محلی را در صف می‌گذارند، `desk_change_log`
و `desk_sync_suppress` برای جلوگیری از حلقه، و `sqlite_sequence` با `ID_BASE` برای
جلوگیری از تداخل شناسه‌ها. کلیدهای `settings` که با `desk_` شروع شوند هرگز همگام
نمی‌شوند.

### SchoolDesk v1.4.0 (نسل ۱ — منسوخ)
پنجره بومی Win32 با UI داخلی HTML، tray icon، ماژول‌های ثبت نمرات/کارنامه/حضور و غیاب
آفلاین، میانبرهای کامل کیبورد، و `server/sync-api.php` برای همگام‌سازی با سایت.

---

## ۶) ابزار PDF (بانک سوال)

`tools/analyze_exam_pdf.py` (۱۴۱۴ خط) + `tools/TACTICS-fa-exam-extraction.md` (مرجع دائمی).
خروجی: JSON با فرمت `baci-bank-import@1` که در `exam-bank-import.php` ایمپورت می‌شود.
مسائل حل‌شده‌ی مستندشده: جهت‌دهی bidi (متن visual معکوس)، امتیازدهی دوطرفه برای تشخیص جهت،
بازخوانی حرف‌به‌حرف `rawdict` برای ریاضی، کسر با U+2044 و رندر `span.pfrac`، آینه‌کردن
پرانتزها، ارقام فارسی در عبارت ریاضی.
۴ آزمون نمونه با PDF + JSON در `exams/` (۴٫۳MB).

---

## ۷) تایم‌لاین نسخه‌ها (۹۱ بسته، v4.29.1 → v4.124.0)

| فاز | نسخه‌ها | موضوع |
|---|---|---|
| ۱. پایه و UI | 4.29–4.45 | دانش‌آموزان، سال تحصیلی، مرتب‌سازی، `ui-modern.css/js`، کلاس‌ها |
| ۲. ربات | 4.46–4.50 | بازوی بله، `bot_admin_ui`, `bot_helpers`, `bot_webhook_engine` |
| ۳. حضور و غیاب QR | 4.51–4.59 | jsQR، برچسب QR، اسکنر، تگ‌ها |
| ۴. پنل دبیر + آزمون آنلاین (تولد) | 4.60–4.74 | `teacher-panel`, `online-exams`, فرم/سوال/پیش‌نمایش/تصحیح/مانیتور/نتیجه |
| ۵. نقش‌ها و یکپارچه‌سازی | 4.75–4.79 | `school_roles`, پنل معاون/مشاور، `session_tracker` |
| ⚠️ شکاف | **4.80–4.85** | **در مخزن نیست** (راهنمای v4.86 پیش‌نیاز «v4.85.0 حالت دریافت با Cron» را ذکر می‌کند) |
| ۶. ربات چندنقشی | 4.86–4.90 | `bot_role_engine` (دبیر/ناظم/معاون اجرایی)، دریافت با Cron |
| ۷. چاپ و PDF | 4.91–4.98 | عکس ۳×۴، سربرگ آزمون، فونت‌ها، PDF ربات، `desk-sync-api` (تولد v4.94) |
| ۸. طراحی زنده + آرشیو | 4.99–4.103 | `exam_design_archive`, فایل منبع، ویرایشگر زنده |
| ۹. ایمپورت PDF | 4.104–4.111 | `exam-bank-import.php` + ابزار پایتون (۵ نسخه پیاپی بهبود دقت) |
| ۱۰. پولیش ویرایشگر | 4.112–4.118 | درگ ارتفاع سوال، resize تصویر، صفحه‌بندی بانک، نمره خودکار سربرگ، سربرگ لوکس |
| ۱۱. بلوغ آزمون آنلاین | **4.119–4.124** | عدالت زمانی، ضدتقلب موقعیتی، UI مدرن، Push-To-Talk، تایمر ضدخرابی، موقعیت بی‌صدا |

**الگوی انتشار:** هر نسخه = پوشه `update-vX.Y.Z/` با فایل‌های جایگزین +
`راهنمای-بروزرسانی.txt`، و همزمان یک `MODIFIED-FILES-vX.Y.Z.zip`.

---

## ۸) بدهی فنی و ریسک‌ها (همه با شاهد مستقیم)

1. **🔑 کلید همگام‌سازی در گیت لو رفته.** `BUILD-NOTES.md` می‌گوید «کلید فقط داخل ZIP
   است، هرگز در گیت»، اما `git grep` همان کلید (`SDP-63fd1610…`) را در **۵ فایل
   ترک‌شده** پیدا می‌کند:
   `desktop-app-v2/server/desk-sync-api.php`، `update-v4.101.0/desk-sync-api.php`،
   `update-v4.99.0/`، `update-v4.94.0/` و `desktop-app-v2/patch/schema-sqlite.sql`.
   این کلید دسترسی کامل pull/push به ۴۱ جدول (شامل نمرات و اطلاعات دانش‌آموزان) می‌دهد.
2. **📦 «کد منبع» سایت فقط داخل یک ZIP باینری است.** هیچ درخت کاملی از سایت در گیت
   نیست؛ جدیدترین نسخه کامل کد فقط با باز کردن `SchoolDeskPro-v2.55.0-win64.zip`
   به دست می‌آید (۱۴۶ فایل واقعی در `www/`). `git diff` روی سایت عملاً بی‌معنی است.
3. **🧪 هیچ تست خودکار و CI وجود ندارد.** `grep -rniE "TODO|FIXME|XXX|HACK"` روی همه
   فایل‌های php/py/js/c صفر نتیجه داد، و هیچ پوشه `tests/` یا workflow گیت‌هابی نیست.
   همه «تست‌شده ✓»ها دستی و ثبت‌شده در راهنماها هستند.
4. **🕳 شکاف نسخه 4.80–4.85.** شش بسته در مخزن نیست؛ زنجیره پیش‌نیازها از v4.79 به
   v4.86 می‌پرد. بازسازی یک نصب از صفر با این مخزن ممکن نیست.
5. **🐞 صفحات دیباگ در باندل نهایی:** `online-exam-diagnose.php`,
   `online-exam-monitor-debug.php` همراه نسخه منتشرشده ship شده‌اند (فقط داخل ZIP).
6. **🗑 آشغال مخزن:** `sssssssssssssss.jpg` در ریشه ترک شده؛ ۹۲ فایل ZIP (بیش از نیمی از
   حجم ۶۹MB) کنار پوشه‌های هم‌محتوای `update-v*` نگهداری می‌شود — تکرار کامل.
7. **📄 `README.md` عملاً خالی است** (۷ بایت: فقط `# Baci`). هیچ راهنمای نصب/معماری در
   ریشه مخزن نیست.
8. **بیلد دسکتاپ دستی است** (مراحل `zig cc` در `BUILD-NOTES.md`) — بدون اسکریپت
   خودکار، بدون نسخه‌گذاری خودکار، بدون امضای باینری.

---

## ۹) مسیر پیشنهادی برای ادامه کار

**الف) فوری (۱–۲ ساعت، بدون ریسک عملکردی)**
1. چرخاندن `DESK_SYNC_KEY` و خارج‌کردن آن از گیت (کلید را در سایت و باندل عوض کن،
   بعد از فایل‌های ترک‌شده حذف و به `.gitignore` بسپار).
2. استخراج درخت کامل سایت از باندل v2.55.0 به یک پوشه `site/` در گیت تا diff معنادار شود.
3. نوشتن `README.md` واقعی + حذف `sssssssssssssss.jpg`.

**ب) کوتاه‌مدت (استحکام)**
4. حذف صفحات `-diagnose` و `-monitor-debug` از باندل انتشاری (یا گیت‌کردن پشت نقش مدیر).
5. یک اسکریپت بیلد برای دسکتاپ (`build-desktop.sh`) + نسخه‌گذاری خودکار.
6. بازیابی/بازسازی بسته‌های 4.80–4.85 یا مستندکردن رسمی شکاف.

**ج) محصولی (ادامه جبهه آزمون آنلاین)**
7. تصحیح خودکار/نیمه‌خودکار سوالات تشریحی (الان تا تصحیح دستی دبیر نمره ۰ می‌مانند).
8. گزارش تحلیلی آزمون (سختی سوال، توزیع نمره، ضریب تفکیک) روی `online_exam_answers`.
9. حالت آفلاین آزمون آنلاین روی دسکتاپ (الان جدول‌های آزمون آنلاین عمداً همگام نمی‌شوند).
10. اعلان والدین از نتیجه آزمون آنلاین (زیرساخت `report_parent_reviews` و پیامک موجود است).

---

*سند توسط بازبینی خودکار مخزن تولید شد؛ برای به‌روزرسانی، همین مسیرها را دوباره بشمارید:*
`git log -1` · `ls -d update-v*` · `unzip -l SchoolDeskPro-v2.55.0-win64.zip` ·
`grep -c "CREATE TABLE" .arena/current/SchoolDeskPro/www/sql/database.sql`
