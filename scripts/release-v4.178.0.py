#!/usr/bin/env python3
"""v4.178.0 / v2.101.0 — شمارهٔ نسخهٔ کد در فوتر + بهداشت مخزن.

    python3 scripts/release-v4.178.0.py

بسته‌ها:
  MODIFIED-FILES-V4.178.0.zip   → سایت   (site-update-v4.152.0/…)
  SchoolDesk-FIX-v2.101.0.zip   → دسکتاپ (SchoolDeskPro/www/…)

پنج اصلاح این نسخه (نشست ۲۰۲۶-۱۰-۰۵):

  ۱) شمارهٔ نسخهٔ کد جلو رفت. تا حالا تنها مرجع، `config/release.php` بود که
     روی ۴.۱۵۲.۰/۲.۸۳.۰ مانده بود در حالی که بسته‌های اصلاحی به ۴.۱۷۷.۱/۲.۱۰۰.۰
     رسیده بودند — و هیچ‌جای رابط هم نمایش داده نمی‌شد. حالا فایل تازهٔ
     `config/version.php` شمارهٔ واقعی کد را دارد و فوتر نشانش می‌دهد.

     **چرا `config/release.php` را بازنویسی نکردیم:** کلید `distribution` در آن
     فایل، در `config/config.php` درایور بانک اطلاعاتی را انتخاب می‌کند
     (site ⇒ MySQL، desktop ⇒ SQLite). بازنویسی آن با بستهٔ اصلاحی یعنی اگر
     بستهٔ سایت اشتباهی روی نصب دسکتاپ برود، درایور بانک عوض می‌شود. فایل
     تازه هیچ رفتاری به آن وابسته نیست، پس این ریسک را ندارد.

  ۲) `BACI-RESTORE-PRE-LIVE-EXAM-DESIGNER.zip` از شاخهٔ `arena/01a0c9d7-baci`
     به خط جاری منتقل شد (فایل مخزن است، نه فایل سرور).
  ۳) پیوندهای دانلود README از `arena/01a0a1d9-baci`/v4.152.0 به روز شدند.
  ۴) `tests/test-bot-outbox.mjs` از مسیرهای وابسته به CWD به `resolveFile()`
     هارنس منتقل و در `scripts/run-tests.sh` ثبت شد.
  ۵) فایل یک‌بایتیِ سرگردان `school-report` از ریشهٔ مخزن حذف شد.

فقط مورد ۱ فایل سرور دارد؛ چهار مورد دیگر سطح مخزن‌اند.
"""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 10, 5, 0, 0, 0)
SUMS = 'V4.178.0-SHA256SUMS.txt'

# (مسیر داخل بسته، مسیر منبع نسبت به ریشهٔ مخزن)
SITE_PAIRS = [
    ('config/version.php',        'update-v4.152.0/config/version.php'),
    ('includes/footer.php',       'update-v4.152.0/includes/footer.php'),
    ('assets/css/school-ui.css',  'update-v4.152.0/assets/css/school-ui.css'),
]
DESKTOP_PAIRS = [
    ('config/version.php',        'update-v4.152.0/config/version.php'),
    ('includes/footer.php',       'desktop-app-v2/patch/includes-footer.php'),
]

PACKAGES = [
    ('MODIFIED-FILES-V4.178.0.zip', 'site-update-v4.152.0/', SITE_PAIRS),
    ('SchoolDesk-FIX-v2.101.0.zip', 'SchoolDeskPro/www/',    DESKTOP_PAIRS),
]


def sha(p):
    return hashlib.sha256(p.read_bytes()).hexdigest()


def build():
    names = [n for n, _, _ in PACKAGES]
    # بسته‌های تاریخی ورودیِ تغییرناپذیرند؛ این نگهبان همان قراردادی است که
    # scripts/release-v4.177.1.py دارد.
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
