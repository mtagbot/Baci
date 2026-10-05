# Baci — سامانهٔ مدیریت مدرسهٔ SchoolDesk Pro

سایت (PHP + MySQL) و نرم‌افزار دسکتاپ ویندوز (SQLite) به‌همراه بازوی بله/تلگرام،
اسکنر حضور و غیاب، آزمون آنلاین، طراح زندهٔ آزمون، کارت و تگ QR، و گزارش‌های
Word/PDF.

**شاخهٔ جاری:** `arena/01a10d10-baci`
**نسخهٔ کد:** سایت **4.178.0** / دسکتاپ **2.101.0**

---

## Release_V1.1 — نصب از صفر

بستهٔ کامل، با همهٔ اصلاحات تا **v4.178.0** ادغام‌شده. پس از نصب این بسته،
**نیازی به نصب زنجیرهٔ اصلاحی نیست.**

| بسته | حجم | sha256 |
|---|---|---|
| [Release_V1.1-Site.zip](https://github.com/mtagbot/Baci/raw/refs/heads/arena/01a10d10-baci/Release_V1.1-Site.zip) | ۳٬۷۱۴٬۴۰۵ بایت | `b0357c23…d983326` |
| [Release_V1.1-Desktop.zip](https://github.com/mtagbot/Baci/raw/refs/heads/arena/01a10d10-baci/Release_V1.1-Desktop.zip) | ۱۷٬۳۳۷٬۶۶۸ بایت | `6a77824d…c6732` |

- [SHA256 کامل هر دو بسته](Release_V1.1-SHA256SUMS.txt)
- [راهنمای نصب کامل](release-v1.0/README-FA.md)
- هر بسته یک `RELEASE-MANIFEST.json` دارد با sha256 تک‌تک فایل‌ها و کامیتِ منبعِ ساخت.

**پیش از نصب:** از اطلاعات مدرسهٔ فعال پشتیبان بگیرید. بستهٔ کامل را روی نصب
دارای داده استخراج نکنید؛ برای نصب موجود از بستهٔ اصلاحی پایین استفاده کنید.

---

## بستهٔ اصلاحی — برای نصبِ موجود

اگر نصبِ فعال دارید، این بسته‌ها را جایگزین کنید (نه بستهٔ کامل). به ترتیب نصب شوند:

### v4.179.0 — غیبت و تأخیر موجه (جدیدترین)

- [MODIFIED-FILES-V4.179.0.zip](https://github.com/mtagbot/Baci/raw/refs/heads/arena/01a10d10-baci/MODIFIED-FILES-V4.179.0.zip) — سایت
- [SchoolDesk-FIX-v2.102.0.zip](https://github.com/mtagbot/Baci/raw/refs/heads/arena/01a10d10-baci/SchoolDesk-FIX-v2.102.0.zip) — دسکتاپ
- [راهنما](CORRECTIONS-V4.179.0-FA.md) · [SHA256](V4.179.0-SHA256SUMS.txt)

### v4.178.0 — شمارهٔ نسخه در فوتر

- [MODIFIED-FILES-V4.178.0.zip](https://github.com/mtagbot/Baci/raw/refs/heads/arena/01a10d10-baci/MODIFIED-FILES-V4.178.0.zip) — سایت
- [SchoolDesk-FIX-v2.101.0.zip](https://github.com/mtagbot/Baci/raw/refs/heads/arena/01a10d10-baci/SchoolDesk-FIX-v2.101.0.zip) — دسکتاپ
- [راهنما](CORRECTIONS-V4.178.0-FA.md) · [SHA256](V4.178.0-SHA256SUMS.txt)

هیچ‌کدام تغییر پایگاه‌داده‌ای ندارند.

---

## چگونه بفهمیم کدام نسخه نصب است؟

از v4.178.0 به بعد شمارهٔ نسخه در **فوتر هر صفحه** نمایش داده می‌شود
(«نسخهٔ 4.178.0» کنار نام مدرسه) و در فایل `config/version.php` ذخیره است.

تا پیش از این تنها مرجع `config/release.php` بود که روی ۴.۱۵۲.۰ مانده بود و
هیچ‌جای رابط دیده نمی‌شد. آن فایل عمداً با بستهٔ اصلاحی بازنویسی **نمی‌شود**،
چون کلید `distribution` در آن درایور بانک اطلاعاتی را انتخاب می‌کند
(site ⇒ MySQL، desktop ⇒ SQLite).

---

## نقشهٔ شاخه‌ها و تاریخچهٔ نسخه‌ها

- [docs/BRANCH-TIMELINE-FA.md](docs/BRANCH-TIMELINE-FA.md) — نقشهٔ زمانی همهٔ شاخه‌ها از v4.29 تا v4.178.0
- [docs/CODE-MAP-FA.md](docs/CODE-MAP-FA.md) — نقشهٔ کد
- [docs/PLATFORM-BRIEF-FA.md](docs/PLATFORM-BRIEF-FA.md) — شناخت پلتفرم

---

## تست‌ها

۵۱ سوئیت با اجرای **واقعی** PHP (php-wasm 8.3 + SQLite) — نه بازنویسی منطق در تست:

```sh
npm ci --prefix tests
unzip -qo Release_V1.1-Site.zip -d /tmp/baci-site
SITE=/tmp/baci-site PATCH=update-v4.152.0 bash scripts/run-tests.sh
```

> سورس تست باید توزیع **سایت** باشد. با سورس دسکتاپ دو سوئیت به‌طور محیطی قرمز
> می‌شوند — جزئیات در بند ۷ `docs/BRANCH-TIMELINE-FA.md`.

بازسازی `Release_V1.1` از صفر:

```sh
python3 -m pip install --target .cache/release-v1/compiler ziglang==0.14.1
python3 scripts/build-full-release.py --stage-only
node tests/test-full-release-install.mjs        # نصب تازه، ۵۸ assertion
python3 scripts/build-full-release.py
python3 tests/test-full-release-packaging.py    # ۱۱ تست بسته‌بندی
```

جزئیات در [release-v1.0/BUILD.md](release-v1.0/BUILD.md).
