#!/usr/bin/env python3
"""v4.179.0 / v2.102.0 — ستون «موجه» در سوابق حضور و غیاب + بازگرداندن طراحی فوتر.

    python3 scripts/release-v4.179.0.py

بسته‌ها:
  MODIFIED-FILES-V4.179.0.zip   → سایت   (site-update-v4.152.0/…)
  SchoolDesk-FIX-v2.102.0.zip   → دسکتاپ (SchoolDeskPro/www/…)

محتوای بسته‌ها (دو فایل در هر بسته):

  `attendance.php`        ستون «موجه» در جدول سوابق و ثبت آن در پرونده.
                          در دسکتاپ هم همین فایل سایت است — `desktop-app-v2/patch/`
                          نسخهٔ جداگانه‌ای از آن ندارد.

  `assets/js/ui-modern.js` حذف دکمهٔ شناور «بازگشت به بالا». در دسکتاپ هم
                          همین فایل سایت است.

  `includes/footer.php`   بازگرداندن طراحی فوتری که در کامیت 1ad7148 از دست
                          رفته بود (آیکون SVG مدرسه + زیرعنوان + «بازگشت به
                          محتوا»). v4.178.0 همان نسخهٔ کوچک‌شده را بسته‌بندی
                          کرده بود، پس نصبش فوتر را به حالت قبلی برمی‌گرداند.
                          فایل یکی است و با `config/release.php` رفتار عوض
                          می‌کند؛ اسکریپت همگام‌سازی خودکار دسکتاپ با شرط
                          `$footerDesk` حفظ شده و روی سایت چاپ نمی‌شود.
                          منبع سایت: `update-v4.152.0/includes/footer.php`
                          منبع دسکتاپ: `desktop-app-v2/patch/includes-footer.php`
                          بنا به درخواست کاربر، زیرعنوان و بج «نسخهٔ …» از فوتر
                          حذف شدند؛ `config/version.php` به‌عنوان metadata نصب
                          می‌ماند ولی دیگر رندر نمی‌شود.

**هیچ تغییر پایگاه‌داده‌ای و هیچ مهاجرت داده‌ای لازم نیست:** ستون
`is_justified` از v4.31.0 در `student_discipline_records` وجود دارد (در MySQL با
ALTER و در SQLite در CREATE TABLE) و در هر دو مسیر نصبِ `Release_V1.1` حاضر است.
رکوردهای انضباطیِ موجود دست‌نخورده می‌مانند.

**شمارهٔ نسخهٔ پایه (`config/version.php`) عمداً جلو نرفت.** آن فایل بخشی از
هویت `Release_V1.1` است و `tests/test-full-release-packaging.py` الزام می‌کند
«نصب تازه» بایت‌به‌بایت برابر «زنجیرهٔ اصلاحی» باشد؛ جلو بردن آن بدون ساخت
نسخهٔ کامل تازه، آن تست را قرمز می‌کرد. شمارهٔ پایه با نسخهٔ کامل بعدی جلو
می‌رود.
"""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 10, 5, 0, 0, 0)
SUMS = 'V4.179.0-SHA256SUMS.txt'

# (مسیر داخل بسته، مسیر منبع نسبت به ریشهٔ مخزن)
SITE_PAIRS = [
    ('attendance.php', 'update-v4.152.0/attendance.php'),
    ('assets/js/ui-modern.js', 'update-v4.152.0/assets/js/ui-modern.js'),
    ('includes/footer.php', 'update-v4.152.0/includes/footer.php'),
]
DESKTOP_PAIRS = [
    ('attendance.php', 'update-v4.152.0/attendance.php'),
    ('assets/js/ui-modern.js', 'update-v4.152.0/assets/js/ui-modern.js'),
    ('includes/footer.php', 'desktop-app-v2/patch/includes-footer.php'),
]

PACKAGES = [
    ('MODIFIED-FILES-V4.179.0.zip', 'site-update-v4.152.0/', SITE_PAIRS),
    ('SchoolDesk-FIX-v2.102.0.zip', 'SchoolDeskPro/www/',    DESKTOP_PAIRS),
]


def sha(p):
    return hashlib.sha256(p.read_bytes()).hexdigest()


def build():
    names = [n for n, _, _ in PACKAGES]
    # بسته‌های تاریخی ورودیِ تغییرناپذیرند — همان نگهبانِ release-v4.178.0.py
    before = {p: sha(p) for p in ROOT.glob('*.zip') if p.name not in names}
    result = []
    for name, prefix, pairs in PACKAGES:
        with ZipFile(ROOT / name, 'w', ZIP_DEFLATED, compresslevel=9) as z:
            for arc, src in sorted(pairs):
                data = (ROOT / src).read_bytes()
                info = ZipInfo(prefix + arc, STAMP)
                info.compress_type = ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                z.writestr(info, data)
                print('  ' + prefix + arc, len(data))
        entry = {'name': name, 'bytes': (ROOT / name).stat().st_size,
                 'sha256': sha(ROOT / name),
                 'files': sorted(a for a, _ in pairs)}
        result.append(entry)
        print(name, entry['bytes'], entry['sha256'])
    for p, h in before.items():
        assert sha(p) == h, 'بستهٔ تاریخی تغییر کرد: ' + p.name
    (ROOT / SUMS).write_text(
        ''.join(x['sha256'] + '  ' + x['name'] + '\n' for x in result), encoding='utf-8')
    print('بسته‌های تاریخی دست‌نخورده:', len(before))


if __name__ == '__main__':
    build()
