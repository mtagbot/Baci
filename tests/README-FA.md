# تست‌ها — اجرای کد واقعی، نه ادعا

> **قاعده:** هیچ باگی «رفع شده» حساب نمی‌شود مگر اینکه اجرای کد نشان دهد.
> changelog مدرک نیست.

## یک دستور برای همه‌چیز

```bash
bash scripts/run-tests.sh
```

بار اول `npm install` خودش اجرا می‌شود. بعد از آن هر اجرا چند ثانیه است.

## چهار سوئیت

| سوئیت | فایل | چه می‌سنجد |
|---|---|---|
| بررسی نحوی | `lint.mjs` | هر ۱۲۴ فایل PHP سایت با `token_get_all(…, TOKEN_PARSE)` |
| منطق تایمر | `test-timer.mjs` | `startTimer()` استخراج‌شده از فایل واقعی — ۱۸ بررسی |
| انتخاب گزینه | `test-save.mjs` | `saveAnswer()` استخراج‌شده از فایل واقعی — ۱۳ بررسی |
| راندن کامل آزمون | `verify.mjs` | سایت واقعی روی PHP + SQLite — ۳۵ بررسی |

## چرا php-wasm؟

در محیط توسعه باینری PHP و `php -l` وجود ندارد. `@php-wasm/node` نسخهٔ واقعی
PHP 8.3 با `pdo_sqlite` را در Node اجرا می‌کند، پس کد **واقعاً** اجرا می‌شود.

## چرا توابع جاوااسکریپتی «استخراج» می‌شوند؟

چون بازنویسیِ منطق، تستِ کدِ خودم است نه کدِ محصول. `test-timer.mjs` و
`test-save.mjs` متن تابع را با تطبیق آکولاد از `online-exam-take.php` بیرون
می‌کشند و فقط `document` و `fetch` را stub می‌کنند. تگ‌های `<?php echo … ?>`
داخل جاوااسکریپت با مقدار رندرشده جایگزین می‌شوند — همان چیزی که مرورگر
دانش‌آموز می‌بیند.

## استفاده‌های دیگر

**تست یک وصله قبل از بسته‌بندی:**
```bash
PATCH=update-v4.127.0 bash scripts/run-tests.sh
```

**تست یک سورس دیگر:**
```bash
SITE=.arena/current/SchoolDeskPro/www bash scripts/run-tests.sh
```
اگر `SITE` ست نشود، هارنس به‌ترتیب می‌گردد: `.arena/current/SchoolDeskPro/www`،
سپس جدیدترین `SchoolDeskPro-v*-win64.zip` در ریشهٔ ریپو (خودکار استخراج می‌شود).

**فقط یک سوئیت:**
```bash
cd tests && node verify.mjs
```

## چهار نکتهٔ حیاتی هارنس (با آزمون و خطا به دست آمده)

۱. `ini_set`/`session_name`/`session_id`/`session_start` باید **قبل از هر echo**
   اجرا شوند، وگرنه `session_start()` خود سایت خطا می‌دهد و هر صفحه به صفحهٔ
   ورود redirect می‌شود.

۲. `echo " "` بعد از session → `headers_sent()` راست می‌شود → `redirect()` به‌جای
   `header()` مقصد را echo می‌کند. در SAPI خط فرمان `header()` بی‌اثر است، پس این
   **تنها** راه گرفتن مقصد redirect است.

۳. شناسهٔ نشست فقط `[A-Za-z0-9-,]` — زیرخط (`_`) رد می‌شود.

۴. `require_once` برای `includes/functions.php` و `includes/db.php`؛ با `require`
   معمولی کلاس `SQLitePDO` دوباره declare می‌شود و fatal می‌دهد.

## یافته‌های جانبی که این تست‌ها بیرون دادند

**`export-excel.php` به `short_open_tag=Off` وابسته است.** این فایل در بخش HTML
خود پردازشگر خام `<?mso-application progid="Excel.Sheet"?>` دارد. بستهٔ دسکتاپ
در `php/php.ini` صریحاً `short_open_tag = Off` دارد، پس آنجا سالم است؛ ولی روی
میزبانی‌ای که این تنظیم `On` باشد، **fatal parse error** می‌گیرد و خروجی اکسل
کاملاً از کار می‌افتد. `lint.mjs` این را به‌عنوان هشدار قابلیت حمل گزارش می‌دهد.

> `short_open_tag` از نوع `PHP_INI_PERDIR` است، پس با `ini_set` در زمان اجرا
> عوض نمی‌شود. به همین دلیل lint همان معنای `Off` را بازسازی می‌کند.
