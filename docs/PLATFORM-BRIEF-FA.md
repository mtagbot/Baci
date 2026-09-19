# شناخت پلتفرم — سایت «کارنامه مدرسه» و دسکتاپ SchoolDesk Pro

> **هدف:** حافظهٔ کاری این نشست. هرچه اینجا آمده از خواندن خودِ فایل‌ها و اجرای دستور روی
> مخزن به دست آمده، نه از حدس.
>
> **تاریخ بررسی:** ۲۰۲۶-۰۹-۱۹ · **شاخهٔ این نشست:** `arena/01a0b793-baci` (پایه: کامیت `6a9026e` = سایت ۴.۱۲۴.۰ / دسکتاپ ۲.۵۵.۰)

---

## ۱. مخزن‌ها و شاخه‌ها — همین‌جا یک واگرایی مهم هست

| مخزن | نقش | وضعیت |
|---|---|---|
| [`Maxess/mtagbot`](https://github.com/Maxess/mtagbot) (عمومی، `master`) | **پلتفرم اولیه/بالادستی** — نقشهٔ کد اصلی | ۱۶۱ فایل · ۱۰۶ PHP · ۴۷ جدول · ۷۸ صفحهٔ ریشه · ۲۱ فایل `includes/` |
| [`mtagbot/Baci`](https://github.com/mtagbot/Baci) (خصوصی) | **ادامهٔ توسعه** | `main` فقط یک کامیت خالی است (`855aefa`) |
| شاخهٔ `upstream-reference/` در Baci | همان سورس بالادستی، وارد‌شده در گیت تا diff معنادار شود | روی شاخه‌های `01a0934a` و `01a0a1d9` |

### شاخه‌های کاری (همه از کامیت مشترک `6a9026e` = v4.124 منشعب شده‌اند)

| شاخه | بازهٔ نسخه | محتوا | آخرین کامیت |
|---|---|---|---|
| `arena/01a03f11-baci` | ۴.۱۲۴ | **پایهٔ همین نشست** | 2026-09-12 |
| `arena/01a0934a-baci` | ۴.۱۲۵ → ۴.۱۳۰ + دسکتاپ ۲.۵۶ → ۲.۶۱ | اولین «حافظه‌سازی»: `docs/CODE-MAP-FA.md`، `docs/STATUS-v4.124.0-FA.md`، `upstream-reference/`، هارنس تست php-wasm، `scripts/release.sh`، `ci/tests.yml`، PR باز **#1** | 2026-09-12 |
| `arena/01a097e6-baci` | ۴.۱۳۱ → ۴.۱۵۱ + دسکتاپ ۲.۶۲ → ۲.۸۲ | خط «قالب Word/PDF»: هم‌ترازی لیست کل مدرسه و لیست کلاسی با قالب Word، هندسهٔ A4/A3، ویرایشگر پیش از چاپ، `fullstd1406.docx` | 2026-09-15 |
| `arena/01a0a1d9-baci` | ۴.۱۳۱ → **۴.۱۵۲** + دسکتاپ ۲.۶۲ → **۲.۸۳** | **جدیدترین وضعیت**: `Release_V1.0` (نصب کامل سایت و دسکتاپ)، ۳۰+ بستهٔ اصلاحی (SITE-FIX / SchoolDeskPro-FIX)، کارت ورود، آزمون کلاسی و گروه‌ها، event-sync، bot outbox، تنظیمات/سلامت دیتابیس، مرکزهای موبایل، UI سریع | 2026-09-18 |
| `arena/01a0b793-baci` | **۴.۱۵۲ + دسکتاپ ۲.۸۳** | **همین نشست** — در ۲۰۲۶-۰۹-۱۹ با fast-forward به `49e49f4` هم‌تراز شد؛ حالا کل درخت، docs، tests، `Release_V1.0` و همهٔ اصلاحی‌ها را دارد | 2026-09-19 (ff) |

> ⚠️ **نکتهٔ کلیدی که باید تصمیم بگیرید:** خط `01a0934a → 01a097e6` و خط `01a0a1d9` **هر دو**
> شماره‌های ۴.۱۳۱ تا ۴.۱۵۱ را با محتوای متفاوت ساخته‌اند (دو خط محصول موازی). عددِ
> «۴.۱۵۲» فقط در خط دوم وجود دارد و کامل‌ترین بستهٔ نصب (`Release_V1.0`) هم همان‌جاست.
> تا وقتی خط مبنا یکی نشود، هر اصلاح بعدی باید برای هر دو خط دوباره ساخته یا یکی از
> آن‌ها به‌عنوان مرجع کنار گذاشته شود.

---

## ۲. سایت — معماری و جریان کار

**پشته:** PHP خالص + MySQL روی هاست اشتراکی؛ همان کدبیس بدون MySQL (SQLite) روی دسکتاپ اجرا می‌شود.
هیچ CDN و کتابخانهٔ بیرونی‌ای لازم نیست (Chart.js، TCPDF، jsQR و qrcode-generator همه محلی‌اند).

**اندازهٔ وضعیت جاری (از `Release_V1.0-Site.zip` که ۲۴۰ فایل دارد):**

| سنجه | مقدار |
|---|---|
| فایل PHP (بدون `vendor/tcpdf`) | ۱۵۴ — که ۹۸ صفحهٔ ریشه است |
| `includes/` | ۴۸ فایل مشترک |
| دارایی‌ها | ۱۴ فایل JS + ۴ CSS (بدون build step) |
| جدول‌ها | ۴۷ جدول در `sql/database.sql` (MySQL) · ۵۳ جدول در `sql/schema-sqlite.sql` (دسکتاپ) با ۵۹ ایندکس |
| نسخه | `config/config.php` → `APP_VERSION = 4.152.0` |

### ۲.۱ بوت‌استرپ و لایهٔ مشترک

- `includes/db.php` — کلاس `DB` (Singleton) با `fetch/fetchAll/execute/lastInsertId`.
  روی دسکتاپ همان فایل با `SQLitePDO` + `includes/db_sqlite_compat.php` کار می‌کند: SQL نوشته‌شده
  برای MySQL در لحظهٔ اجرا به SQLite ترجمه می‌شود. اسکیما هم در هر بوت «خودکار و idempotent» اعمال می‌شود.
- `includes/auth.php` — نشست با کلیدهای `admin_id`/`admin_role`/`teacher_id`/`student_id`؛
  `require_admin()`، `require_student()`، `has_permission()`/`require_permission()` (مجوزهای JSON در `admins.permissions`).
- `includes/functions.php` — `clean()`، `csrf_token()/verify_csrf()`، پیام‌های flash،
  `generate_captcha()/verify_captcha()`، سد ورود (`check_login_throttle` + جدول `login_guard` که با پاک‌کردن کوکی هم بازنشانی نمی‌شود)،
  `verify_user_password()` (از v4.131: فقط هش؛ رکوردهای قدیمی md5/خام **در همان ورود موفق** به bcrypt ارتقا می‌یابند؛ کد ملی/پرسنلی دیگر رمز نیست)،
  `make_report_print_token()` برای چاپ امن کارنامه، تاریخ شمسی از `includes/jdf.php`.
- `includes/header.php` + `header_tiles.php` + `tile_icons.php` + `em_icons.php` — منوی نقش‌محور
  (مدیر: داشبورد، دانش‌آموزان، حضور و غیاب، دروس، کارنامه‌ها، امتحانات حضوری، آزمون مجازی، بازیابی، پیامک/اعلان، ربات‌ها، اکانت‌های متصل، تنظیمات، همگام‌سازی، سلامت دیتابیس، سفارشی‌سازی، مدیران) + کاشی‌های هدر با sprite SVG و آیکون‌های خطی.

### ۲.۲ نقش‌ها و ورود

| نقش | جدول/پرچم | ورود |
|---|---|---|
| `super_admin` / `edu_admin` / `observer` | `admins.role` | `admin-login.php` |
| دبیر، معاون، مشاور، مجری | `teachers` + پرچم‌های `is_deputy/is_counselor/is_executive` | `index.php` (تب دبیر) یا `admin-login.php?tab=teacher` |
| دانش‌آموز | `students` | `index.php` — نام کاربری کد ملی، رمز پیش‌فرض سریال ۶ رقمی شناسنامه |
| استعلام سریع | — | `index.php` تب استعلام |

پنل‌ها: `teacher-panel.php`، `deputy-panel.php`، `counselor-panel.php`، `executive-panel.php`،
`student-panel.php`، `sms-panel.php`.
امنیت نشست: `includes/session_tracker.php` + `my-sessions.php` + `session-status.php` +
`assets/js/session-watch.js` — لغو واقعی نشست (فردی/همه)، «مرا به خاطر بسپار» با توکن مقید به دستگاه.

### ۲.۳ ماژول‌ها

**الف) دانش‌آموز و پرونده** — `students.php`، `student-modal.php`، `staff-student-file.php`،
`student-recovery.php` (+ تب «آپلود تصاویر ZIP» با نام فایل = کد ملی)، `import-photos.php`،
`import-students.php`، `import-teachers.php`، `export-students.php`، `includes/importer.php` + صف `import_queue` و `cron/import-queue-worker.php`.

**ب) کارنامه و نمره** — `reports.php`، `report-view.php`، `report-print.php`، `bulk-print.php`،
`student-bulk-report.php`، `report-broadcast.php`، `includes/report_image.php`، قفل کارنامه
(`report_locks`)، بازخورد والد (`report_parent_reviews`)، مجوز ورود نمره (`grade_entry_permissions` + `includes/grade_permissions.php`).

**ج) «لیست‌ها و گزارشات» (Word/PDF)** — `reports-lists.php` + `includes/docx_school_list.php`
و `includes/docx_class_list.php`. روش کار: **قالب واقعی `.docx`** (`assets/templates/school-students.docx`،
`teacher-class-list.docx`) با ZipArchive پر می‌شود؛ خروجی PDF از مسیر چاپ مرورگر گرفته می‌شود
(تصمیم آگاهانه از v4.146 به‌جای TCPDF). جزئیات هندسی: `tblLayout=autofit`، `line=240/Single`،
حداقل ۳۰ ردیف، قلم هدف ۸pt، A4/A3، تراز وسط، ترتیب RTL.

**د) حضور و غیاب و کارت‌ها** — `attendance.php`، `attendance-scanner.php`
(بازگشت اضطراری با `?scanner=legacy` به `attendance-scanner-legacy.php` که بایت‌به‌بایت نسخهٔ v4.94 است)،
`assets/js/attendance-scanner-light.js` (کنترلر ES5: یک درخواست دوربین، محافظ پاسخ دیررس، قفل فوکوس/اپتیک، بودجهٔ نوردهی)،
`assets/js/attendance-decoder-worker.js` + `jsqr.min.js`، `attendance-scan-api.php`،
`attendance-tags.php` و `entry-cards.php` (کارت ورود/تگ با هندسهٔ میلی‌متری، چاپ ۱ تا ۱۰ نسخه،
طرح‌های متنوع و امکان لوگو با `entry-card-logo.php` + `includes/card_*.php`).

**ه) آزمون حضوری و طراحی زنده** — `exams.php`، `exam-print.php` (۱۴۸۱ خط: سربرگ،
صفحه‌بندی، نمرهٔ خودکار)، `exam-design-api.php`، `exam-source-api.php`، `exam-question-bank.php`،
`exam-bank-import.php` + ابزار `tools/analyze_exam_pdf.py` (خروجی JSON با اسکیمای `baci-bank-import@1`).
**آزمون کلاسی (v4.152):** `class-exam-create.php`، `class-exam-group.php`، `class-exam-delete.php`،
`includes/class_exam_helpers.php` (هویت = دبیر/سال/کلاس/درس با قفل ردیف)، `includes/class_exam_groups.php`
(گروه پایدار، کپی طرح، استثنا با snapshot مستقل)، `includes/admin_class_exams.php`،
`includes/teacher_class_exams.php`، `includes/teacher_weekly_schedule.php` (شش‌روزه، فقط‌خواندنی).

**و) آزمون آنلاین — بزرگ‌ترین جبههٔ کاری** — `online-exams.php`، `online-exam-form.php`،
`online-exam-questions.php`، **`online-exam-take.php` (۱۴۷۷ خط = برگهٔ دانش‌آموز)**،
**`online-exam-api.php` (۶۹۹ خط، ۱۹ اکشن)**، `includes/online_exam_helpers.php` (۷۱۴ خط)،
`online-exam-monitor.php` (نظارت زنده)، `online-exam-results.php` / `online-exam-result.php`،
`online-exam-grading.php`، `student-online-exams.php`، `online-question-bank.php`، `online-exam-categories.php`.

اکشن‌های API: `start_attempt` · `check_time` · `claim_device` · `heartbeat` · `save_answer` ·
`submit_exam` · `proctoring_log` · `request_webcam` · `upload_webcam_snapshot` · `upload_webcam_video` ·
`save_webcam_snapshot` · `get_webcam_snapshots` · `get_pending_webcam_requests` · `get_webcam_request_status` ·
`send_voice_note` · `ack_voice_note` · `get_live_sessions` · `get_attempts_in_progress` · `get_proctoring_logs`.
هر اکشن جداگانه نقش را چک می‌کند (`$role['type']`) و اکشن‌های دانش‌آموز از کادر جدا هستند.

مکانیزم‌هایی که باید بدانیم:
- **۱۲ نوع سوال:** رادیو، چندجوابی، کشویی، متن کوتاه، متن بلند، عددی، اطلاع‌رسانی، جای خالی،
  تطبیقی، آپلود فایل، آپلود صدا، تختهٔ سفید.
- **موقعیت مکانی بی‌صدا (v4.124):** آبشار خودکار GPS دقیق → متعادل → شبکه‌ای → IP؛ دانش‌آموز فقط
  «لطفا صبر کنید…» و «✅ تایید شد» می‌بیند و در هر حالت وارد آزمون می‌شود. نتیجهٔ هر مرحله در
  لاگ نظارتی ثبت می‌شود و هشدار مجاورت GPS فقط بین دو GPS واقعی مقایسه می‌شود.
- **تایمر مقاوم:** مبنای زمان از «ماندهٔ اعلامی سرور» مشتق می‌شود، نه ساعت دستگاه؛ انقضا با
  ۳۰ ثانیه گریس و ثبت `auto_submitted` با نمرهٔ واقعی.
- **قفل تک‌دستگاهی:** `online_exam_live_sessions.device_token` + توکن `sdp_device_token` در
  `localStorage`؛ دستگاه دوم `kicked/other_device` می‌گیرد.
- **ضدتقلب:** heartbeat پانزده‌ثانیه‌ای، تازه‌سازی GPS هر ۵ دقیقه، درخواست وبکم، تذکر صوتی
  Push-To-Talk (Opus ~۱۰kbps، سقف ۵۱۲KB، پاک‌سازی خودکار ۲ ساعت)، لاگ نظارتی با سطوح و
  گزارش تفکیک‌شده بر اساس بیننده (حریم خصوصی).
- **حذف کامل نتیجه:** `online_exam_delete_attempt_full()` در همان helper — ۸ جدول + فایل‌های دیسک.

**ز) ربات بله/تلگرام** — `bale-bot.php`/`bale-webhook.php`، `telegram-bot.php`/`telegram-webhook.php`،
`telegram-poll.php`، `includes/bot_webhook_engine.php`، `includes/bot_role_engine.php` (نقش دبیر/ناظم/معاون)،
`includes/bot_admin_ui.php`، `includes/bot_helpers.php`.
در آخرین خط: `includes/bot_outbox.php` + `cron/bot-outbox-worker.php` — صف خروجی و رلهٔ احرازشده با
تلاش دوباره و رسید تحویل، تا اعلان‌ها در قطعی هم گم نشوند.

**ح) سیستمی** — `settings.php` (مرکز تب‌ها)، `admins.php`، `activity-logs.php`، `notifications.php`،
`sms-panel.php`، `messages-management.php`، `backups.php`، `db-optimizer.php` (از v4.131 دو-موتوره:
MySQL و SQLite)، `migration-updater.php`، `includes/db_health.php`، `includes/security_confirmation.php`
(تأیید رمز مدیر + CSRF برای تغییرات حساس)، `api/` (REST + `openapi.json` + `admin-import`)،
`installer.php` + `includes/install_guard.php` + `includes/release_install.php` (نصب Release_V1.0).

### ۲.۴ سخت‌سازی انجام‌شده (v4.131 به بعد) که باید حفظ شود
۱) تابع واحد احراز هویت با مهاجرت خودکار هش · ۲) ۵۹ ایندکس در اسکیمای SQLite (اندازه‌گیری‌شده
حدود ۴۳ برابر سریع‌تر) · ۳) `db-optimizer` سازگار با SQLite · ۴) CSRF + سقف حجم + MIME واقعی +
نام تصادفی در آپلود رسانه · ۵) `.htaccess` در `uploads/`, `backups/`, `config/`, `includes/`, `sql/`, `vendor/`
· ۶) حذف آزمون بدون یتیم‌ماندن رکوردها · ۷) کپچای تطبیقی و `login_guard`.

---

## ۳. دسکتاپ SchoolDesk Pro — نسخهٔ ۲.۸۳.۰

**بستهٔ نصب (`Release_V1.0-Desktop.zip`):** ۲۸۰ فایل، ۱۶.۸MB فشرده / ۴۴.۴MB باز، شامل:

```
SchoolDeskPro/
├── SchoolDeskPro.exe        لانچر بومی (PE32+، چند ده کیلوبایت؛ با zig cc ساخته می‌شود)
├── php/                     PHP 8.1.34 NTS x64 + ext(curl, gd, mbstring, openssl, sqlite3, pdo_sqlite, opcache)
│                            + php.ini + cacert.pem + DLL های vcruntime/openssl/sqlite
├── www/ یا reports/         کل کد سایت (۲۴۹ فایل) روی SQLite
├── data/                    sessions/، uploads/، browser-profile/، port.txt، sync-heartbeat.json
├── licenses/ , README-FA.md , RELEASE-MANIFEST.json
```

**لانچر (`desktop-app-v2/launcher/launcher.c` = ۳۶۹ خط + `reports-layout.h` + `window-policy.h`):**
1. mutex نام‌دار برای تک‌نمونه‌بودن؛ اجرای دوباره = بازگرداندن پنجرهٔ موجود.
2. آماده‌سازی `reports/` به‌عنوان ریشهٔ وب (`sdp_prepare_reports`) با پیام خطای دقیق فارسی اگر ادغام امن ممکن نبود.
3. انتخاب پورت آزاد در بازهٔ ۸۱۲۳–۸۱۹۹ و نوشتن `data/port.txt`.
4. اجرای مخفی `php.exe -S 127.0.0.1:<port> -t SchoolDeskPro reports/router.php` داخل **Job object**
   (اگر لانچر بمیرد، PHP هم کشته می‌شود).
5. جست‌وجوی موتور مرورگر (App Paths → Uninstall → مسیرهای ثابت → LocalAppData/ProgramFiles → WebView2)
   و باز کردن `--app` با پروفایل ایزوله؛ مرورگر پیش‌فرض آخرین گزینه است.
6. **سیاست پنجره:** فقط «بیشینه در ناحیهٔ کاری» یا «کوچک‌شده» — نه resize و نه Restore؛ نوار عنوان
   و دکمهٔ بستن حفظ می‌شوند؛ قفل با WinEvent hooks مقید به PID مرورگر خودِ برنامه (بدون دست‌زدن به
   دیالوگ چاپ/مرورگرهای دیگر و بدون تزریق به پروسهٔ دیگر). پنجرهٔ جدید باز نمی‌شود (چاپ/پیش‌نمایش در همان پنجره).

**روتر (`reports/router.php`، پیشوند `SDP_REPORTS_ROOT_V1`):** ریشهٔ سند همان پوشهٔ برنامه است و فقط
`reports/` عمومی است؛ `config`, `includes`, `sql`, `vendor`, `backups`, `data`, `php` مسدودند،
پسوندهای اجرایی و مسیرهای خروج از ریشه بسته‌اند، PHP داخل `uploads/` و `assets/` اجرا نمی‌شود،
درخواست فایل استاتیک ناموجود ۴۰۴ می‌گیرد (وگرنه کپچای ورود بی‌صدا باطل می‌شد)، و اگر نصب کامل
نباشد کاربر به `installer.php` هدایت می‌شود. سپس کار به سرور توکار PHP واگذار می‌شود.

**`desk-prepend.php` (auto_prepend_file):** تعمیر خطاهای کلاسیک «حلقهٔ بی‌صدای ورود»؛ آزمون واقعیِ
نوشتن در پوشهٔ session و کوچ خودکار به temp، تشخیص نرسیدن کوکی و نمایش پیام فارسی به‌جای شکست خاموش،
و کپچای مسابقه‌ای (استخر آخرین پاسخ‌ها به‌مدت ۱۵ دقیقه) چون دسکتاپ تک‌کاربره چند درخواست موازی دارد.

**دیتابیس:** SQLite با `SQLitePDO` + `SQLiteCompat::rewrite`، حالت WAL، `schema-sqlite.sql`
(۵۳ جدول، idempotent) در هر بوت؛ اثر انگشت اسکیما در `settings.desk_schema_version`؛ هنگام اعمال
اسکیما پرچم `desk_sync_suppress` روشن می‌شود تا تریگرهای همگام‌سازی الکی تغییر ثبت نکنند.

### ۳.۱ همگام‌سازی سایت ↔ دسکتاپ (قلب ماجرا)

- **کلید جفت‌شدن** `SDP-<48 hex>`؛ در بستهٔ جدید سمت سایت داخل `config/desk-sync-key.php` نگهداری می‌شود
  (نه داخل فایل endpoint) و با `hash_equals` بررسی می‌شود.
- **سمت سایت:** `desk-sync-api.php` (+ `class-exam-sync-api.php` در v4.152) — روی هر جدول synced
  تریگر `AFTER INSERT/UPDATE/DELETE` می‌سازد که تغییر را در `desk_change_log (tbl, rid, op, ts)`
  می‌نویسد؛ درج‌های سمت دسکتاپ با خاموش‌کردن موقت تریگرها (متغیر نشست `@desk_sync_suppress`)
  اعمال می‌شوند تا حلقه نسازند.
- **سمت دسکتاپ:** `includes/desk_sync.php` (۸۳۴ خط) — `snapshot` اولیه، سپس pull افزایشی با نشانگر
  `after` در بسته‌های ۴۰۰ ردیفی؛ شناسهٔ ردیف‌های ساخته‌شده در دسکتاپ از `ID_BASE = 5,000,000` شروع
  می‌شود تا با سایت تصادم نکند؛ تریگرهای محلی SQLite تغییرات را صف می‌کنند؛ `MIN_INTERVAL=120s`،
  `LOCK_TTL=180s`، `RECON_INTERVAL=1h` برای آشتی آینه‌ای — و **هیچ‌وقت** جدولی که تغییر پوش‌نشدهٔ محلی
  دارد با snapshot بازنویسی نمی‌شود. همگام‌سازی فایل (منبع آزمون/PDF) و backoff و تشخیص آفلاین هم دارد.
- **۴۱ جدول synced از ۵۳**؛ عمداً synced نمی‌شوند: `api_tokens`, `user_sessions`, `bale_bot_state`,
  `telegram_bot_state`, `import_queue`, `import_sessions`, `desk_change_log`, `desk_sync_suppress`,
  `grade_entry_permissions` و همهٔ جدول‌های زنده/ضدتقلب آزمون آنلاین (`live_sessions`,
  `proctoring_logs`, `webcam_*`, `voice_*`). کلیدهای `settings` با پیشوند `desk_` هرگز synced نمی‌شوند.
- **بهینه‌سازی‌های آخر:** «optimized sync» (کاوش بیکاری ۳۰ ثانیه، چرخهٔ ایمنی ۳۰۰ ثانیه، و بدون
  HTTP وقتی صف رله خالی است) و «event-only» (درخواست فقط وقتی تغییر واقعی هست)؛ وضعیت اتصال با
  `data/sync-heartbeat.json` + `desk-connection-status.php` + `assets/js/desk-connection.js` نمایش
  داده می‌شود و برای دانش‌آموز/دبیر شمارش رکوردها پنهان است. رلهٔ ربات‌ها (`bot_outbox`) هم همین مسیر
  را برای اعلان‌ها استفاده می‌کند.

**نسل ۱ (بازنشسته):** `desktop-app/` + `SchoolDesk-v1.4.0` — پنجرهٔ بومی Win32 با UI داخلی HTML،
tray، ماژول‌های آفلاین و `server/sync-api.php`. جایگزین: نسل ۲.

---

## ۴. انتشار و راستی‌آزمایی — چرخهٔ واقعی پروژه

**بسته‌ها:**
- تاریخی: `update-vX.Y.Z/` + `راهنمای-بروزرسانی.txt` + `MODIFIED-FILES-vX.Y.Z.zip` (۱۲۰ پوشه در خط جدید).
- امروز: `SITE-FIX-v4.152.0-<slug>.zip` و `SchoolDeskPro-FIX-v2.83.0-<slug>.zip` که با
  `scripts/release-<slug>.py` ساخته می‌شوند و `*-SHA256SUMS.txt` دارند. ترتیب نصب مهم است
  (هر اصلاحی روی دیگری می‌نشیند) و در `README.md` و `CORRECTIONS-*.md` مستند شده.
- نسخهٔ کامل: `Release_V1.0-Site.zip` / `Release_V1.0-Desktop.zip` با
  `scripts/build-full-release.py` — زمان‌مهر ثابت، فهرست مرتب، `RELEASE-MANIFEST.json` با SHA256 هر فایل،
  TCPDF/Chart.js با نسخهٔ پین‌شده، مجوزها داخل بسته، و هیچ دیتابیس/تنظیم/توکن کاربر داخلش نیست.
- `update-v4.152.0/` نقش «پوشهٔ stage تجمعی» را دارد: بسیاری از اسکریپت‌های انتشار از آن می‌خوانند؛
  ویرایش سرخود در آن می‌تواند در چند بستهٔ آینده سرریز کند.

**تست:** `bash scripts/run-tests.sh` → **۳۱ سوئیت**. هارنس (`tests/harness/`) کل سایت را در MEMFS
روی **PHP 8.3 واقعی به‌شکل WASM** (`@php-wasm/node`) اجرا می‌کند، superglobalها را دستی ست می‌کند،
`redirect()` را با گرفتن خروجی تشخیص می‌دهد، توابع JS را از خود فایل واقعی استخراج و در Node اجرا می‌کند،
و lint با `token_get_all(..., TOKEN_PARSE)` انجام می‌شود. سوئیت‌های دامنه‌ای: تایمر، ذخیرهٔ پاسخ،
موقعیت، راندن کامل آزمون، قفل دستگاه، تخته سفید، حریم خصوصی نظارت، مانیتورینگ، سخت‌سازی،
کاشی‌های هدر/کپچا، کارت ورود، لیست کلاسی/کل مدرسه، اسکنر، چاپ چندنسخه‌ای، کارگردان گردش‌کار دبیر،
گروه آزمون کلاسی، عملیات آزمون کلاسی، امنیت نشست، چاپ فیزیکی کارت، طرح سفارشی/لوگو، UI سریع.
همراهش: `python3 tests/test-*-packaging.py` که خودِ ZIPها را باز و مقایسه می‌کند.
آخرین اعداد رسمی خودِ تیم: ۱۹ سوئیت / ۹۸۸ PASS در یک مقطع، و ۳۱ مجموعه‌آزمون روی هر توزیع در آخرین اصلاحی‌ها.

**محدودیت‌های محیط (باید در حساب بیاید):** در این سندباکس PHP بومی نیست (تست‌ها از php-wasm استفاده
می‌کنند)، ساخت `.exe` ویندوز ممکن نیست (نیاز به zig)، MySQL زنده و رفتار واقعی ویندوز/چاپگر/دوربین
تست نمی‌شود، و فایل `ci/tests.yml` آماده است ولی GitHub اجازهٔ نوشتن در `.github/workflows/` را به
اپ نمی‌دهد — فعال‌سازی CI یک `git mv` دستی از طرف شما لازم دارد.

---

## ۵. وضعیت و ریسک‌های باز (برای تصمیم‌گیری)

| # | موضوع | وضعیت |
|---|---|---|
| ۱ | **دو خط موازی ۴.۱۳۱–۴.۱۵۱** با محتوای متفاوت | ⚠️ باید یکی مرجع شود |
| ۲ | کلید همگام‌سازی در تاریخ گیت لو رفته (کامیت‌های قدیمی) و در چند بستهٔ تاریخی هست | باید چرخانده/باطل شود |
| ۳ | حجم مخزن: ۱۳۳۵ فایل ترک‌شده، ۱۵۲ فایل ZIP، `.git` ≈ ۸۴MB و هر انتشار ~۱۵MB | GitHub Releases / LFS |
| ۴ | شکاف نسخه‌ای ۴.۸۰–۴.۸۵ | بازسازی یک نصب از صفر از این مخزن ممکن نیست |
| ۵ | صفحات دیباگ (`online-exam-diagnose.php`, `online-exam-monitor-debug.php`, `online-exam-location-test.php`) در بستهٔ منتشرشده | یا حذف یا محافظت با نقش |
| ۶ | CI غیرفعال | یک بار `git mv ci/tests.yml .github/workflows/` |
| ۷ | قفل دستگاه با `localStorage` (حالت incognito/غیرفعال = بی‌اثر) | راه پایدار: کوکی HttpOnly سمت سرور |
| ۸ | خروجی PDF وابسته به چاپ مرورگر است (نه TCPDF) | آگاهانه؛ ولی در هاست‌های عجیب ریسک دارد |
| ۹ | مهاجرت ریشهٔ وب دسکتاپ از `www/` به `reports/` در جریان است | بستهٔ قدیمی را نباید روی ساختار جدید اعمال کرد |
| ۱۰ | `update-v4.152.0/` هم‌زمان «ورودی چند اسکریپت انتشار» و «محل ویرایش» شده | خطر سرریز تغییر به بسته‌های نامرتبط |

**نقطهٔ قوت واقعی پروژه:** چرخهٔ «تغییر → تست اجرایی روی PHP واقعی → بستهٔ ZIP + SHA256 → راهنمای نصب
فارسی» جا افتاده و هر ادعای نسخه با تست یا اندازه‌گیری پشتیبانی شده. این را باید حفظ کنیم.

---

## ۶. تصمیم‌های گرفته‌شده و پیکربندی محیط این نشست

**تصمیم‌ها (۲۰۲۶-۰۹-۱۹):** مبنای کار = **جدیدترین خط** (`arena/01a0a1d9-baci`، سایت ۴.۱۵۲.۰ /
دسکتاپ ۲.۸۳.۰) و این شاخه با `git merge --ff-only origin/arena/01a0a1d9-baci` به همان کامیت
هم‌تراز شد. از این پس هر بهبود روی همین شاخه و از همین وضعیت ساخته می‌شود.
هنوز **پوش‌نشده** (به `origin` فرستاده نشده) تا از تکرار سنگین ۱۵۰+ زیپ روی ریموت پرهیز شود؛
هر وقت خواستید `git push origin arena/01a0b793-baci` کافی است.

### ۶.۱ دستورهای آماده‌سازی (یک‌بار در هر نشست)

`.arena/`، `tests/node_modules/` و `/tmp` بین نشست‌ها پاک می‌شوند؛ پس این‌ها را دوباره بسازید:

```bash
cd /home/user/Baci

# ۱) وابستگی تست (php-wasm) — نیازمند شبکه
npm install --prefix tests --silent --no-audit --no-fund

# ۲) سورس «سایت» برای تست — از بستهٔ کامل سایت
mkdir -p .arena/current/SchoolDeskPro/www
unzip -q -o Release_V1.0-Site.zip -d .arena/current/SchoolDeskPro/www
PATCH=update-v4.152.0 bash scripts/run-tests.sh      # ۳۰ از ۳۱ سوئیت سبز

# ۳) سورس «دسکتاپ» برای سوئیت قرارداد همگام‌سازی (test-fast-ui)
#    ترتیب واقعی نصب: Release_V1.0-Desktop → اصلاحی‌های www → reports-layout → اصلاحی‌های reports
SITE=/tmp/desk_ok/SchoolDeskPro/reports bash scripts/run-tests.sh
```

### ۶.۲ دو یافتهٔ زمینیِ مهم که از اجرای واقعی به دست آمد

۱. **زنجیرهٔ نصب دسکتاپ دو مرحله‌ای است.** همهٔ اصلاحی‌های تا `settings-health` داخل
   `SchoolDeskPro/www/` می‌نویسند؛ بعد `reports-layout` فایل اجرایی و `reports-layout-update/router.php`
   را می‌آورد و در اولین اجرا `www` به `reports` تغییر نام می‌دهد؛ از آن به بعد اصلاحی‌ها
   (`bot-outbox`, `optimized-sync`, `event-sync`, `recovery-ui`) داخل `reports/` می‌نویسند.
   **پس هرگز نباید بستهٔ قدیمیِ `www`-دار را روی نصبِ مهاجرت‌کرده استخراج کرد** — این خودِ
   مستندات هم هشدار داده و router/launcher هم جلویش را می‌گیرد.

۲. **`tests/test-fast-ui.mjs` موتور همگام‌سازی را از `SITE/includes/desk_sync.php` می‌خواند.**
   روی توزیع سایت (که موتور قدیمی‌تر را دارد) این سوئیت قرمز می‌شود و این **باگ محصول نیست**؛
   با `SITE` روی توزیع دسکتاپِ کاملِ اصلاح‌شده: **۱۲۱ PASS / ۰ FAIL**.
   (درس پروژه هم همین است: «تست باید همان چیزی را بسنجد که فکر می‌کنیم.»)

### ۶.۳ چیزی که برای مراحل بعد لازم است از شما بدانم

۱. **کدام بخش‌ها روی نصب فعلی مدرسه واقعاً نصب شده‌اند؟** (فهرست اصلاحی‌ها) تا پیشنهادها
   روی همان وضعیت سوار شود و نسخهٔ تحویل درست انتخاب شود.
۲. **شکل تحویل هر بهبود:** طبق روال پروژه، اصلاحی کم‌حجم (`SITE-FIX-*` + `SchoolDeskPro-FIX-*`)
   با راهنمای فارسی و SHA256 — یا در مقطع مشخص، بازسازی `Release_V1.0` کامل؟ (پیش‌فرض من: اصلاحی.)
۳. اگر رفع‌های امنیتی بازِ مورد علاقهٔ شماست (چرخاندن کلید همگام‌سازی، فعال‌کردن CI،
   حذف صفحات دیباگ از بسته)، بگویید تا همان‌ها را اولویت بدهم.

> پیگیری‌ها و نسخهٔ کامل این شناخت در همین فایل به‌روز می‌شود؛ اگر خواستید، نسخهٔ انگلیسی یا
> یک نقشهٔ کد فشرده (جدول فایل ← مسئولیت) هم اضافه می‌کنم.
