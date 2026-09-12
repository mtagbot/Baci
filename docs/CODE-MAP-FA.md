# نقشهٔ کد و حافظهٔ پلتفرم — SchoolDesk Pro

> **هدف این فایل:** حافظهٔ ماندگار معماری سامانه. هر عدد و مسیر در این سند با
> اجرای دستور روی خود فایل‌ها به‌دست آمده، نه از حدس.
>
> **تاریخ راستی‌آزمایی:** ۲۰۲۶-۰۹-۱۲
> **نسخهٔ سایت:** ۴.۱۲۷.۰ &nbsp;·&nbsp; **نسخهٔ دسکتاپ:** ۲.۵۸.۰
> **شاخهٔ کاری:** `arena/01a0934a-baci`

---

## ۱. دو ریپو و نقش هرکدام

| ریپو | نقش | شاخهٔ پیش‌فرض | دسترسی |
|---|---|---|---|
| [`Maxess/mtagbot`](https://github.com/Maxess/mtagbot) | **پلتفرم پایه (بالادستی)** — معماری اولیه و نقشهٔ کد | `master` | عمومی |
| [`mtagbot/Baci`](https://github.com/mtagbot/Baci) | **ادامهٔ توسعه** — آخرین اصلاحات سایت و نرم‌افزار | `main` | خصوصی |

کار روی شاخهٔ `arena/01a0934a-baci` انجام و از طریق **PR #1** به `main` می‌رود.

### اعداد راستی‌آزمایی‌شده

| | بالادستی `Maxess/mtagbot` | فعلی (سایت v4.126.0) |
|---|---|---|
| کل فایل‌ها | ۲۱۱ | ۱۴۶ (فقط `www/`) |
| فایل PHP | ۱۰۶ | ۱۲۴ |
| جدول دیتابیس | ۴۷ | **۵۲** |
| آخرین باندل | `PROJECT-CODE-BUNDLE-v4.28.4.json` | — |
| فایل حافظهٔ بالادستی | `MEMORY-RESUME-v4.28.md` | همین فایل |

هر ۴۷ جدول بالادستی در نسخهٔ فعلی **حفظ شده‌اند**؛ ۵ جدول اضافه شده است.

---

## ۲. چیدمان پوشه‌ها

```
www/
├── api/            → API عمومی (index.php, docs.php, admin-import.php, openapi.json)
├── assets/         → css/ و js/ (Chart.js و TCPDF محلی، بدون CDN)
├── config/         → config.php, database.php, defaults.php, installed.lock
├── cron/           → import-queue-worker.php
├── includes/       → ۲۱ فایل مشترک (پایین‌تر فهرست شده)
├── sql/            → schema-sqlite.sql (۱۰۰۸ خط، ۵۲ جدول، idempotent)
├── backups/        → خروجی پشتیبان‌گیری
├── uploads/        → فایل‌های آپلودی (فونت‌ها، رسانهٔ آزمون، پاسخ‌ها)
└── *.php           → ۱۲۴ صفحه/اکشن
```

### `includes/` — لایهٔ مشترک

| فایل | نقش |
|---|---|
| `db.php` | لایهٔ دیتابیس (`DB::fetch/fetchAll/execute/lastInsertId`)، ساخت خودکار schema |
| `db_sqlite_compat.php` | **جدید** — سازگاری SQLite برای نسخهٔ دسکتاپ |
| `auth.php` | احراز هویت؛ کلیدهای نشست: `admin_id`, `admin_role`, `teacher_id`, `student_id` |
| `functions.php` | `set_flash_message()`, `redirect()`, `clean()`, `tr_num()` — `session_start()` با گارد |
| `header.php` / `footer.php` | هدر و منوی نقش‌محور + فوتر |
| `online_exam_helpers.php` | موتور آزمون آنلاین: انواع سوال، تصحیح، نهایی‌سازی |
| `exams_helper.php` | آزمون کاغذی/طراحی آزمون |
| `attendance_helpers.php` | **جدید** — حضور و غیاب |
| `bot_helpers.php` / `bot_webhook_engine.php` / `bot_admin_ui.php` | ربات بله و تلگرام |
| `bot_role_engine.php` | **جدید** — موتور نقش ربات |
| `desk_sync.php` | **جدید** — همگام‌سازی دوطرفهٔ دسکتاپ ↔ سایت |
| `school_roles.php` | تعریف نقش‌های مدرسه |
| `grade_permissions.php` | سطح دسترسی نمرات |
| `academic_year_helpers.php` | سال تحصیلی |
| `session_tracker.php` | **جدید** — ردیابی نشست‌های کاربر |
| `teacher_nav.php` | **جدید** — ناوبری پنل دبیر |
| `importer.php` / `export.php` | ایمپورت CSV / خروجی Excel و PDF |
| `report_image.php` | تولید تصویر کارنامه |
| `charts.php` | نمودارها |
| `jdf.php` | تاریخ شمسی |
| `logger.php` | لاگ فعالیت‌ها |
| `class_schedule_sync.php` | همگام‌سازی برنامهٔ کلاسی |
| `school_sort.php` | مرتب‌سازی طبیعی نام مدرسه |

---

## ۳. نقش‌ها و پنل‌ها

**ادمین** (ستون `admins.role`):

| نقش | برچسب |
|---|---|
| `super_admin` | سوپر ادمین |
| `edu_admin` | مدیر آموزشی |
| `observer` | مشاهده‌گر |

**نقش‌های مدرسه** (`includes/school_roles.php`): `teacher`, `deputy`, `counselor`, `executive`

**پنل‌ها:** `teacher-panel.php`, `deputy-panel.php`, `counselor-panel.php`,
`executive-panel.php`, `student-panel.php`, `sms-panel.php`

**ورود:** دانش‌آموز → `index.php` (کد ملی + رمز) · معلم/معاون/مشاور/مجری →
`admin-login.php?tab=teacher`

---

## ۴. ماژول‌ها

### ۴.۱ کارنامه و دانش‌آموز
`students.php` (۵۴ ک.ب) · `student-modal.php` · `staff-student-file.php` ·
`import-students.php` · `export-students.php` · `report-view.php` ·
`report-print.php` · `bulk-print.php` · `student-bulk-report.php` · `reports.php`

### ۴.۲ آزمون کاغذی / طراحی
`exams.php` · `exam-print.php` (۷۸ ک.ب) · `exam-design-api.php` ·
`exam-source-api.php` · `exam-question-bank.php` · `exam-bank-import.php` **(جدید)** ·
`import-schedule.php`

### ۴.۳ آزمون آنلاین ⭐ (ماژول اصلی اصلاحات اخیر)

| فایل | نقش |
|---|---|
| `online-exams.php` | لیست و مدیریت آزمون‌ها |
| `online-exam-form.php` | ایجاد/ویرایش (زمان‌بندی، مدت، نمرهٔ قبولی، تصادفی‌سازی) |
| `online-exam-questions.php` | استودیوی طراحی سوال با ویرایشگر WYSIWYG |
| `online-exam-take.php` | **برگهٔ برگزاری آزمون دانش‌آموز** (۱۲۷۳ خط در v4.126.0) |
| `online-exam-api.php` | **API آزمون — ۱۸ اکشن** (فهرست پایین) |
| `online-exam-result.php` / `online-exam-results.php` | نتیجهٔ تکی / لیست نتایج |
| `online-exam-grading.php` | تصحیح دستی |
| `online-exam-monitor.php` | مانیتورینگ زندهٔ ضدتقلب |
| `student-online-exams.php` | پنل دانش‌آموز برای شرکت در آزمون |
| `online-question-bank.php` / `online-exam-categories.php` | بانک سوال و دسته‌بندی |
| `online-exam-preview.php` / `online-exam-media-upload.php` | پیش‌نمایش و آپلود رسانه |

**۱۲ نوع سوال** (مستقیماً از `online_question_types()` در `includes/online_exam_helpers.php`):

`radio` 🔘 · `checkbox` ☑️ · `dropdown` 🔽 · `short_text` 📝 · `text` 📄 ·
`number` 🔢 · `info` ℹ️ (بدون نمره) · `fill_blank` ✍️ · `matching` 🔗 ·
`file_upload` 📎 · `voice_upload` 🎤 · `whiteboard` 🎨

**۱۸ اکشن `online-exam-api.php` در v4.126.0:**

```
ack_voice_note            get_webcam_snapshots        save_answer
check_time      ← جدید    get_webcam_request_status   save_webcam_snapshot
get_attempts_in_progress  heartbeat                   send_voice_note
get_live_sessions         proctoring_log              start_attempt
get_pending_webcam_...    request_webcam              submit_exam
get_proctoring_logs       upload_webcam_snapshot      upload_webcam_video
```

### ۴.۴ حضور و غیاب **(جدید)**
`attendance.php` · `attendance-scanner.php` · `attendance-tags.php` ·
`attendance-scan-api.php` · `includes/attendance_helpers.php`

### ۴.۵ ربات‌ها
`bale-bot.php` / `bale-webhook.php` · `telegram-bot.php` / `telegram-webhook.php` ·
`telegram-poll.php` **(جدید)** · `bot-accounts.php` · `bot-login.php`

### ۴.۶ همگام‌سازی دسکتاپ **(جدید)**
`desk-sync.php` · `desk-sync-daemon.php` · `desk-doctor.php` · `desk-prepend.php` ·
`router.php` · `../server/desk-sync-api.php`

### ۴.۷ سیستمی
`installer.php` · `migration-updater.php` · `backups.php` · `db-optimizer.php` **(جدید)** ·
`activity-logs.php` · `notifications.php` · `settings.php` · `admins.php` · `profile.php`

---

## ۵. دیتابیس — ۵۲ جدول

**۴۷ جدول ارثی از بالادستی:**

```
academic_years            bot_login_tokens            online_exam_categories
activity_logs             bot_message_logs            online_exam_live_sessions
admins                    bot_message_templates       online_exam_proctoring_logs
api_tokens                class_schedules             online_exam_webcam_requests
bale_bot_state            classes                     online_exam_webcam_snapshots
bale_bot_users            counseling_requests         online_exams
bot_admin_sessions        discipline_titles           online_question_bank
bot_button_templates      exam_assignments            online_question_categories
                          exam_designs                online_questions
                          exam_question_bank          report_grades
                          exam_schedules              report_locks
                          exam_student_seating        report_parent_reviews
                          grade_messages              reports
                          import_queue                settings
                          import_sessions             sms_logs
                          notifications               student_discipline_records
                                                      students · subjects · teachers
                                                      telegram_bot_state
                                                      telegram_bot_users
```

**۵ جدول جدید:**

| جدول |用途 |
|---|---|
| `student_attendance` | حضور و غیاب |
| `student_qr_tags` | کارت QR دانش‌آموز |
| `exam_design_archive` | بایگانی طراحی آزمون |
| `desk_change_log` | لاگ تغییرات همگام‌سازی دسکتاپ |
| `desk_sync_suppress` | سرکوب حلقهٔ همگام‌سازی |

### همگام‌سازی دوطرفه (`SYNC_TABLES` در `desk-sync-api.php`)

**۴۱ جدول از ۵۲ همگام می‌شوند.** این ۱۱ جدول عمداً همگام نمی‌شوند:

```
api_tokens                     desk_sync_suppress              telegram_bot_state
bale_bot_state                 import_queue
desk_change_log                import_sessions
online_exam_live_sessions      online_exam_proctoring_logs
online_exam_webcam_requests    online_exam_webcam_snapshots
```

> دلیل: دادهٔ محلی/گذرا (صف ایمپورت، وضعیت ربات، سشن و لاگ نظارت زنده).
> هیچ جدولِ موجود در `SYNC_TABLES` نیست که در schema نباشد (راستی‌آزمایی شد).

---

## ۶. فایل‌های جدید نسبت به بالادستی (۲۰ فایل PHP)

```
attendance.php               desk-sync-daemon.php        includes/bot_role_engine.php
attendance-scan-api.php      desk-sync.php               includes/db_sqlite_compat.php
attendance-scanner.php       download-sample-7reporte.php includes/desk_sync.php
attendance-tags.php          exam-bank-import.php        includes/session_tracker.php
db-optimizer.php             my-sessions.php             includes/teacher_nav.php
desk-doctor.php              router.php                  includes/attendance_helpers.php
desk-prepend.php             telegram-poll.php
```

**دو فایل بالادستی که در بستهٔ دسکتاپ نیستند:** `download-zip.php` و
`export-project-bundle.php` (خروجی گرفتن از کل سورس — در بستهٔ بسته‌بندی‌شده معنا ندارد).

---

## ۷. دو سکوی اجرا

| | سایت | SchoolDesk Pro (دسکتاپ) |
|---|---|---|
| نسخه | ۴.۱۲۷.۰ | ۲.۵۸.۰ |
| زبان | PHP + MySQL | PHP 8.1.34 داخلی + SQLite |
| نصب | آپلود در هاست | unzip + اجرای `SchoolDeskPro.exe` |
| پورت‌ها | — | ۸۱۲۳–۸۱۹۹ |
| ورود اولیه | — | `admin` / `admin123` |
| schema | `ensure_*_schema()` خودکار | `sql/schema-sqlite.sql` خودکار |

ساختار بستهٔ دسکتاپ: `SchoolDeskPro/{www/, server/, data/, README.txt, SchoolDeskPro.exe}`

---

## ۸. شیوهٔ راستی‌آزمایی (چگونه تست می‌کنم)

هیچ تغییری بدون اجرای کد تأیید نمی‌شود. ابزارها:

1. **`@php-wasm/node`** (v3.1.53) — اجرای PHP واقعی ۸.۳ با `pdo_sqlite` در Node،
   چون در این محیط باینری PHP وجود ندارد.
2. **هارنس درخواست** — `www/` در MEMFS کپی می‌شود، superglobalها دستی ست می‌شوند،
   `redirect()` با گرفتن خروجی شناسایی می‌شود.
3. **اجرای مستقیم جاوااسکریپت** — توابع کلاینتی از فایل واقعی استخراج و در Node
   اجرا می‌شوند (با stub برای DOM و `fetch`)، نه بازنویسی.
4. **Lint** — `token_get_all($src, TOKEN_PARSE)` چون `php -l` در دسترس نیست.

اجرا با یک دستور: `bash scripts/run-tests.sh`

نتیجهٔ آخرین اجرا (v4.127.0 / v2.58.0): **۸۳ PASS / ۰ FAIL**
(۳۵ سرور + ۱۸ تایمر + ۱۳ انتخاب گزینه + ۱۷ موقعیت مکانی) + lint ۱۲۴ فایل با ۰ خطا

---

## ۹. موارد باز (شناخته‌شده، هنوز رفع نشده)

1. `students.php:96-106` — حذف آبشاری دانش‌آموز، `voice_notes`/`voice_plays` را
   پاک نمی‌کند و فایل‌ها را unlink نمی‌کند.
2. `online-exams.php delete_exam` (خطوط ۴۹-۵۲) — attemptها یتیم می‌مانند.
3. کلید همگام‌سازی در ۵ فایل track‌شدهٔ گیت commit شده است.
4. هیچ تست خودکار / CI در ریپو نیست.
5. شکاف نسخه‌ای ۴.۸۰–۴.۸۵ در پوشه‌های `update-v*`.
6. ۹۶ فایل zip در ریشهٔ ریپو (حجم زیاد) + فایل `sssssssssssssss.jpg`.
7. ساخت `.exe` دسکتاپ در این محیط ممکن نیست (نیاز به `zig cc`)؛ مسیر انتشار،
   تعویض payload و bump نسخه است.
8. `export-excel.php` در بخش HTML خود پردازشگر خام `<?mso-application
   progid="Excel.Sheet"?>` دارد. بستهٔ دسکتاپ `short_open_tag = Off` دارد پس آنجا
   سالم است، ولی روی میزبانی با `short_open_tag = On` خطای fatal parse می‌گیرد
   و خروجی اکسل کامل از کار می‌افتد. (`tests/lint.mjs` هشدارش را می‌دهد.)
