# اصلاحات نسخهٔ ۴.۱۷۸.۰ — شمارهٔ نسخهٔ کد + بهداشت مخزن

تاریخ: ۱۴۰۵/۰۷/۱۳ (۲۰۲۶-۱۰-۰۵)
شاخه: `arena/01a10d10-baci`

بستهٔ سایت: `MODIFIED-FILES-V4.178.0.zip` (۳ فایل، ۸٬۳۱۵ بایت)
sha256: `92c41e534f32d536426b99df0b3660be2adcc1fe1ef6b96d6ef8ee32e3dbfa3b`

بستهٔ دسکتاپ: `SchoolDesk-FIX-v2.101.0.zip` (۲ فایل، ۳٬۶۸۱ بایت)
sha256: `c4cc9bb1a14e2672855fc78f30176a4eb6a535dc5fc084ddd35bf4222165d993`

نسخهٔ کامل: `Release_V1.1-Site.zip` و `Release_V1.1-Desktop.zip`

> این بسته **جایگزین کامل v4.177.1** است. هیچ تغییر پایگاه‌داده‌ای ندارد.

---

## این نسخه چه کار می‌کند

پنج مورد بازِ بررسی ۲۰۲۶-۱۰-۰۵ بسته شدند. فقط **مورد ۱** فایل سرور دارد؛
چهار مورد دیگر سطح مخزن‌اند و چیزی روی هاست عوض نمی‌کنند.

---

## ۱) شمارهٔ نسخهٔ کد — مشکل و راه‌حل

### مشکل چه بود؟

تنها مرجع نسخه، `config/release.php` بود:

```php
return ['release'=>'Release_V1.0','distribution'=>'site',
        'site_version'=>'4.152.0','desktop_version'=>'2.83.0'];
```

دو اشکال:

1. **بیست‌وپنج نسخه عقب بود.** بسته‌های اصلاحی تا ۴.۱۷۷.۱/۲.۱۰۰.۰ رسیده بودند
   ولی این فایل روی ۴.۱۵۲.۰/۲.۸۳.۰ مانده بود.
2. **هیچ‌جای رابط دیده نمی‌شد.** بررسی واقعی کد نشان داد `site_version` و
   `desktop_version` در هیچ صفحه‌ای خوانده نمی‌شوند. از `release.php` فقط کلید
   `distribution` مصرف دارد، در این فایل‌ها:

   | فایل | مصرف |
   |---|---|
   | `config/config.php` | `distribution` ⇒ انتخاب درایور بانک (site ⇒ MySQL، desktop ⇒ SQLite) |
   | `includes/release_install.php` | `distribution` |
   | `includes/header.php` · `includes/footer.php` | `distribution` (حالت دسکتاپ) |
   | `includes/management_hub.php` | `distribution` (تب به‌روزرسانی نرم‌افزار) |
   | `desk-updates.php` · `desk-connection-status.php` | `distribution` |
   | `includes/bot_outbox.php` | `distribution` (مسیر relay) |

### چرا `release.php` را بازنویسی نکردیم

ساده‌ترین کار این بود که همان فایل را در بستهٔ اصلاحی بگذاریم. نکردیم، چون
کلید `distribution` **درایور بانک اطلاعاتی** را انتخاب می‌کند. اگر بستهٔ سایت
اشتباهی روی نصب دسکتاپ برود (یا برعکس)، درایور بانک عوض می‌شود — خرابی‌ای که
با هیچ اصلاحیِ دیگری قابل مقایسه نیست.

### چه کردیم

فایل تازهٔ `config/version.php` — **بدون کلید `distribution`**:

```php
return [
    'site_version'    => '4.178.0',
    'desktop_version' => '2.101.0',
    'correction'      => 'V4.178.0',
];
```

و فوتر هر صفحه نشانش می‌دهد: «نسخهٔ 4.178.0» کنار نام مدرسه. فوتر شمارهٔ
درستِ همان توزیع را انتخاب می‌کند — روی سایت `site_version` و روی دسکتاپ
`desktop_version`.

اگر فایل نسخه وجود نداشته باشد، فوتر **بدون خطا و بدون بج خالی** رندر می‌شود
(هر دو حالت تست شده‌اند).

---

## ۲) دو فایل گم‌شده در اسکوات تاریخچه — پیدا و برگردانده شدند

شاخهٔ `arena/01a0d88a-baci` یک کامیت ریشهٔ بی‌پدر (اسکوات‌شده) است. بررسی
`comm -23` روی فهرست فایل‌های دو شاخه نشان داد **دقیقاً دو فایل** در آن
اسکوات گم شده‌اند:

```
MODIFIED-FILES-V4.160.0.zip
SchoolDesk-FIX-v2.90.0.zip
```

این دو فقط «بستهٔ قدیمی» نبودند — **ورودیِ تغییرناپذیرِ سازندهٔ نسخهٔ کامل**اند
(`SITE_CORRECTIVES` در `scripts/build-full-release.py`). بدون آن‌ها ساخت
`Release_V1.1` با این پیام متوقف می‌شد:

```
Missing corrective package: MODIFIED-FILES-V4.160.0.zip
```

هر دو از `arena/01a0d74d-baci` برگردانده و **بایت‌به‌بایت** با آن شاخه
مقایسه شدند (`git hash-object` برابر با blob همان شاخه).

همچنین `BACI-RESTORE-PRE-LIVE-EXAM-DESIGNER.zip` از `arena/01a0c9d7-baci`
(شاخهٔ ادغام‌نشده) به خط جاری منتقل شد.

---

## ۳) پیوندهای دانلود README

همهٔ ۱۲ پیوند دانلود README به `refs/heads/arena/01a0a1d9-baci` و فقط تا
v4.152.0 اشاره داشتند. README بازنویسی شد: `Release_V1.1`، بستهٔ اصلاحی
v4.178.0، شمارهٔ نسخهٔ جاری، و دستور تست و بازسازی.

---

## ۴) `test-bot-outbox.mjs` — از خارجِ runner به داخل آن

این سوئیت در `scripts/run-tests.sh` ثبت نبود. علتش هم پیدا شد: سه مسیر
وابسته به CWD داشت که بیرون از ریشهٔ مخزن می‌شکستند:

| قبل | بعد |
|---|---|
| `readFileSync('update-v4.152.0/class-exam-sync-api.php')` | `readFileSync(resolveFile('class-exam-sync-api.php'))` |
| `['update-v4.152.0/includes/desk_sync.php', …]` | `['includes/desk_sync.php','class-exam-sync-api.php']` با `resolveFile` |
| `mkdirSync('docs/bot-outbox')` | `mkdirSync(join(REPO,'docs/bot-outbox'))` |

`run-tests.sh` اول `cd "$REPO/tests"` می‌کند، پس مسیرهای نسبیِ قدیمی همیشه
با `ENOENT` می‌شکستند و خروجی تست در `tests/docs/` می‌ریخت. همان الگویی که
شاخهٔ `01a0c533` برای بقیهٔ سوئیت‌ها اعمال کرده بود، اینجا جا مانده بود.

بعد از اصلاح: **PASS 34 site outbox checks · exit=0**، و سوئیت در runner ثبت شد.

---

## ۵) فایل سرگردان `school-report`

فایلی یک‌بایتی (فقط `\n`) در ریشهٔ مخزن بود — artifact تصادفی. حذف شد.

---

## فایل‌های بستهٔ سایت (۳)

```
config/version.php        (تازه)  شمارهٔ نسخهٔ کد، بدون distribution
includes/footer.php               نمایش نسخه در فوتر
assets/css/school-ui.css          استایل بج نسخه
```

## فایل‌های بستهٔ دسکتاپ (۲)

```
config/version.php        (تازه)  همان فایل سایت (مشترک)
includes/footer.php               فوتر دسکتاپ با نسخهٔ دسکتاپ
```

> دسکتاپ فایل CSS جدا نگرفت، چون فوترش از کلاس‌های همان سامانهٔ استایل خودش
> استفاده می‌کند (`ml-2 opacity-70`).

---

## نصب

### نصب تازه
`Release_V1.1-Site.zip` یا `Release_V1.1-Desktop.zip` را طبق
[راهنمای نصب](release-v1.0/README-FA.md) نصب کنید. هیچ اصلاحیِ دیگری لازم نیست.

### نصب موجود
1. از هر دو بسته پشتیبان بگیرید.
2. محتوای `site-update-v4.152.0/` را روی ریشهٔ سایت کپی کنید (۳ فایل).
3. برای دسکتاپ: محتوای `SchoolDeskPro/www/` را در همان پوشه باز کنید (۲ فایل).
4. هر صفحه را باز کنید — شمارهٔ نسخه باید در فوتر دیده شود.

**هیچ تغییر پایگاه‌داده‌ای لازم نیست.**

---

## آزمون‌ها

- سوئیت تازهٔ `tests/test-version-stamp.mjs` — ۲۰+ بررسی، همه با اجرای واقعی PHP
- `tests/test-full-release-install.mjs` — **۵۸ assertion** نصب تازهٔ هر دو توزیع
- `tests/test-full-release-packaging.py` — **۱۱ تست** بسته‌بندی
- لینت PHP: `COUNT=216 BAD=0`

### تضمین‌های ویژه

- `config/version.php` هیچ کلید `distribution` ندارد (تست می‌شود — چون همان
  ریسک `release.php` را داشت).
- بستهٔ اصلاحی **`config/release.php` را شامل نمی‌شود** (تست می‌شود).
- نصب تازه **بایت‌به‌بایت** برابر نتیجهٔ زنجیرهٔ اصلاحی است
  (`test_11_published_correctives_are_inside`).
- نبودِ فایل نسخه صفحه را نمی‌شکند و بج خالی نمی‌سازد.
- هر ۱۸۳ بستهٔ تاریخی زیپ دست‌نخورده ماندند (نگهبان در
  `scripts/release-v4.178.0.py`).
