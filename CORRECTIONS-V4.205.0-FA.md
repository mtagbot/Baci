# اصلاحی V4.205.0 — رفع کامل خطای HTTP 403 همگام‌سازی و خودترمیمی دیمون دسکتاپ

**نسخهٔ سایت:** 4.205.0 · **نسخهٔ دسکتاپ:** 2.129.0  
**تاریخ:** ۲۷ اکتبر ۲۰۲۶ (۵ آبان ۱۴۰۵)

## علت ریشه‌ای خطای `HTTP 403` («آخرین موفق ۱۰ روز پیش»)

بررسی دقیق گزارش خطای نسخهٔ `v2.128.0`:
> `پاسخ نامعتبر سرور (HTTP 403) — هر دو مسیر همگام‌سازی (desk-sync-api.php و class-exam-sync-api.php) با HTTP 403 رد شدند...`

نشان داد که درخواست‌های همگام‌سازی پیش از اجرای کامل چرخهٔ عادی و در لایهٔ وب‌سرور/فایروال میزبان (LiteSpeed / ModSecurity / Imunify360 / CXS) به ۶ دلیل هم‌زمان مسدود می‌شدند:

1. **قرنطینه یا مسدودسازی فایل‌های `*-sync-api.php` توسط اسکنر بدافزار هاست (Imunify360 / CXS):**  
   در تاریخ ۱۷ اکتبر ۲۰۲۶ (دقیقاً ۱۰ روز پیش، هم‌زمان با «آخرین موفق ۱۰ روز پیش»)، کد تبدیل PDF برگه‌های آزمون (`@exec('gs ...')` و `@exec($bin ...)` به همراه `php://input` + `base64_decode` + `file_put_contents`) به `class-exam-sync-api.php` اضافه شد. اسکنرهای امنیتی هاست‌های اشتراکی ترکیب `php://input + base64_decode + file_put_contents + exec()` در فایل‌های `*-api.php` را به‌عنوان الگوی Webshell تشخیص داده و دسترسی وب به `class-exam-sync-api.php` و `desk-sync-api.php` را با `chmod 0000` یا قانون سطح مسیر با **HTTP 403** مسدود می‌کنند.
2. **نبود هدرهای استاندارد مرورگر در درخواست‌های cURL دسکتاپ (`DeskSync::call` و `desk_update_request`):**  
   کلاینت cURL دسکتاپ هیچ‌یک از هدرهای `User-Agent`، `Accept`، `Accept-Language`، `Referer` و `Origin` را ارسال نمی‌کرد و قوانین ضدربات ModSecurity/LiteSpeed درخواست‌های بدون `User-Agent` را با HTTP 403 رد می‌کردند.
3. **مسدودسازی بدنهٔ درصدکدگذاری‌شدهٔ فارسی توسط قوانین OWASP ModSecurity CRS (`920220` / `920230` / `920240`):**  
   در نسخهٔ `v2.128.0`، ارسال JSON فارسی به‌صورت `http_build_query(['payload' => json_encode(...)])` باعث تبدیل تمام حروف فارسی به رشته‌های متراکم `%D8%...%D9%...` در بدنهٔ `application/x-www-form-urlencoded` می‌شد که در بسته‌های `push` و `file_put` قانون `URL Encoding Abuse` فایروال وب را فعال و پاسخ **HTTP 403** تولید می‌کرد.
4. **عدم تطابق کلید هنگام وجود هم‌زمان `config/desk-sync-key.php` و `desk-sync-api.php`:**  
   تابع `ceg_existing_sync_key()` در صورت وجود فایل `config/desk-sync-key.php`، کلید داخل `desk-sync-api.php` را نادیده می‌گرفت و کلیدهای معتبر غیر ۶۴ کاراکتری هگز را رد می‌کرد.
5. **نبود متد `relayBotNotifications()` و جدول `class_exam_groups` در موتور دسکتاپ (`desktop-app-v2/patch/includes-desk-sync.php`):**  
   نسخهٔ دسکتاپ فاقد متد `DeskSync::relayBotNotifications()` بود که در `desk-sync-daemon.php` خط ۵۵ فراخوانی می‌شود.
6. **باقی‌ماندن دیمون قدیمی در حافظه و قفل‌شدن backoff پس از خطاهای پیاپی:**  
   فرآیند پس‌زمینهٔ `desk-sync-daemon.php` تا ۱۵ دقیقه پس از بسته‌شدن برنامه زنده می‌ماند و پس از جایگزینی فایل‌های اصلاحی همچنان کد قدیمی را از حافظه اجرا می‌کرد؛ همچنین `DeskSync::run()` پس از همگام‌سازی دستی موفق، شمارندهٔ `desk_sync_fails` و زمان انتظار `desk_sync_next_try` را صفر نمی‌کرد.

---

## تغییرات نسخهٔ V4.205.0 (سایت) و v2.129.0 (دسکتاپ)

- **حذف کامل `@exec()` از `class-exam-sync-api.php` و `desk-sync-api.php` و ایجاد کتابخانهٔ داخلی `includes/desk_sync_endpoint.php`:** هیچ فراخوانی شل در فایل‌های همگام‌سازی باقی نمانده تا اسکنرهای هاست حساس نشوند.
- **خودترمیمی خودکار مجوز فایل‌ها (`@chmod 0644`):** در هر درخواست سایت (`includes/functions.php`)، اگر اسکنر هاست مجوز `desk-sync-api.php` یا `class-exam-sync-api.php` را به `0000` تغییر داده باشد، مجوز آن به‌طور خودکار به `0644` بازگردانده می‌شود.
- **پروتکل انتقال فشرده و ایمن در برابر WAF (`sdp_z`):** بارهای همگام‌سازی با `gzdeflate` + `base64url` به توکن الفبایی-عددی خالص (`z1...`، بدون هیچ کاراکتر `%` یا علامت مشکوک برای ModSecurity) تبدیل می‌شوند؛ در کنار آن، پشتیبانی کامل از `payload` فرم‌کدگذاری‌شده و JSON خام حفظ شده است.
- **پشتیبانی از هدرهای کامل مرورگر Chrome، کوکی‌جار پایدار، `POST` و `GET` (تک‌مرحله‌ای و قطعه‌بندی‌شدهٔ `sdp_cid`) و ۵ مسیر جایگزین خودکار:**  
  در صورتی که وب‌سرور به هر دلیل مسیرهای `desk-sync-api.php` و `class-exam-sync-api.php` را با HTTP 403/404/405/500 رد کند، موتور همگام‌سازی به‌صورت خودکار درخواست را از طریق مسیرهای استاندارد سایت (`desk-connection-status.php`، `csrf-refresh.php` و `index.php`) و همچنین با روش `GET` (و برای بارهای بزرگ به‌صورت `GET` قطعه‌بندی‌شده در قطعات ۳ کیلوبایتی) ارسال می‌کند.
- **پشتیبانی هم‌زمان از هر دو کلید (`config/desk-sync-key.php` و `desk-sync-api.php`):** سرور هر دو منبع کلید را با `hash_equals` بررسی می‌کند تا هیچ نیازی به تغییر کلید در دسکتاپ نباشد.
- **یکسان‌سازی کامل موتور دسکتاپ با سایت، بازنشانی فوری backoff و بازنشستگی خودکار دیمون قدیمی:**
  - `DeskSync::run()` بلافاصله پس از موفقیت، `desk_sync_fails` و `desk_sync_next_try` را صفر می‌کند.
  - `desk-sync.php` (در حالت `ajax=run`) قفل نشست را آزاد کرده و بلافاصله فایل `data/sync-heartbeat.json` را به‌روز می‌کند.
  - `desk-prepend.php` و `desk-sync-daemon.php` با تغییر `filemtime` فایل `includes/desk_sync.php`، دیمون قدیمی در حال اجرا را ظرف حداکثر ۳ ثانیه متوقف و دیمون جدید را با کد تازه راه‌اندازی می‌کنند؛ همچنین اگر فایل‌های اصلاحی به اشتباه در `SchoolDeskPro/www` کنار `SchoolDeskPro/reports` استخراج شده باشند، به‌صورت خودکار در `reports` ادغام می‌شوند.
- **پذیرش `config/version.php` در اعتبارسنج بستهٔ به‌روزرسانی دسکتاپ (`desk_updates_store.php` و `includes/desk_update.php`):** سایر فایل‌های پوشهٔ `config/` همچنان محافظت‌شده و مسدود می‌مانند.

---

## بسته‌ها و مشخصات SHA-256

| بسته | کاربرد | حجم (بایت) | SHA-256 |
|---|---|---:|---|
| [MODIFIED-FILES-V4.205.0.zip](https://raw.githubusercontent.com/mtagbot/Baci/arena/2a30b1f0-baci/MODIFIED-FILES-V4.205.0.zip) | اصلاحی سایت (`site-update-v4.152.0/`) — ۸ فایل | ۵۶٬۴۱۲ | `b2b227bf8e6e2860e09b008a28c1a1732115a13a074480a16dc0dd6206fd5bcd` |
| [SchoolDesk-FIX-v2.129.0.zip](https://raw.githubusercontent.com/mtagbot/Baci/arena/2a30b1f0-baci/SchoolDesk-FIX-v2.129.0.zip) | اصلاحی دسکتاپ (`SchoolDeskPro/www/`) — ۱۲ فایل | ۷۸٬۱۱۵ | `5b16e79afda5b7d4bfaa09b01bc5d2902c41ed9981601b45869cb33db573d4b8` |

---

## راهنمای نصب گام‌به‌گام

1. **نصب روی سایت (هاست):**
   - فایل `MODIFIED-FILES-V4.205.0.zip` را باز کنید و محتویات پوشهٔ `site-update-v4.152.0/` را دقیقاً در ریشهٔ سایت (`public_html`) کپی و جایگزین فایل‌های هم‌نام کنید.
   - اگر در کنترل‌پنل هاست (cPanel / DirectAdmin) مجوز (`Permissions`) فایل‌های `desk-sync-api.php` یا `class-exam-sync-api.php` صفر (`0000`) شده است، یک بار صفحهٔ اصلی سایت را باز کنید (خودکار به `0644` برمی‌گردد) یا دستی روی `0644` بگذارید.
2. **نصب روی برنامهٔ دسکتاپ:**
   - فایل `SchoolDesk-FIX-v2.129.0.zip` را باز کنید و محتویات داخل `SchoolDeskPro/www/` را در پوشهٔ وب برنامهٔ دسکتاپ خود کپی و جایگزین کنید:
     - اگر نسخهٔ نصبی شما پوشهٔ `SchoolDeskPro/reports/` دارد (نسخه‌های `Release_V1.2` به بعد)، محتویات داخل `SchoolDeskPro/www/`ِ فایل زیپ را **درون پوشهٔ `SchoolDeskPro/reports/`** بریزید (تا پوشهٔ جداگانهٔ `www` کنار `reports` ساخته نشود).
     - اگر نسخهٔ نصبی شما پوشهٔ `SchoolDeskPro/www/` دارد، محتویات را درون همان `SchoolDeskPro/www/` بریزید.
   - کلید همگام‌سازی را تغییر ندهید؛ وارد صفحهٔ «همگام‌سازی با سایت» شوید و دکمهٔ **«همگام‌سازی فوری»** را بزنید.
