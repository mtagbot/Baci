#!/usr/bin/env python3
"""v4.176.0 — آلبوم عکس به «لیست یک‌صفحه‌ای برای هر کلاس» تبدیل شد، شمارش
«کاربران متصل» دیگر روی ۲۰۰ متوقف نمی‌شود، گزارش دانش‌آموزان ترتیبِ فهرست را
حفظ می‌کند، و چهار فرم اداری مدرسه از قالب‌های .mrt بازسازی شد.

    python3 scripts/release-v4.176.0.py

بسته‌ها:
  MODIFIED-FILES-V4.176.0.zip  → سایت (site-update-v4.152.0/…)

این نسخه فقط فایل‌های سایت را عوض می‌کند؛ هیچ فایل مشترکی با نسخهٔ مستقل
دسکتاپ (desktop-app-v2/patch/…) تغییر نکرده، پس بستهٔ دسکتاپ ساخته نمی‌شود.
"""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 10, 3, 0, 0, 0)

SITE_FILES = [
    'cron/bot-outbox-worker.php',
    'db-optimizer.php',
    'includes/bot_admin_ui.php',
    'includes/db_retention.php',
    'includes/photo_album.php',
    'includes/school_forms.php',
    'reports-lists.php',
    'student-bulk-report.php',
    'students.php',
]
DESKTOP_FILES = []

PACKAGES = [
    ('MODIFIED-FILES-V4.176.0.zip', 'site-update-v4.152.0/', SITE_FILES),
]
SUMS = 'V4.176.0-SHA256SUMS.txt'


def sha(p):
    return hashlib.sha256(p.read_bytes()).hexdigest()


def build():
    srcs = {}
    for _, _, files in PACKAGES:
        for f in files:
            p = ROOT / 'update-v4.152.0' / f
            assert p.is_file(), 'فایل منبع پیدا نشد: ' + f
            srcs[f] = p
    before = {p: sha(p) for p in ROOT.glob('*.zip') if p.name not in [n for n, _, _ in PACKAGES]}
    result = []
    for name, prefix, files in PACKAGES:
        with ZipFile(ROOT / name, 'w', ZIP_DEFLATED, compresslevel=9) as z:
            for f in sorted(files):
                info = ZipInfo(prefix + f, STAMP)
                info.compress_type = ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                z.writestr(info, srcs[f].read_bytes())
        entry = {'name': name, 'bytes': (ROOT / name).stat().st_size,
                 'sha256': sha(ROOT / name), 'files': sorted(files)}
        result.append(entry)
        print(name, entry['bytes'], entry['sha256'])
    for p, h in before.items():
        assert sha(p) == h, 'بستهٔ تاریخی تغییر کرد: ' + p.name
    (ROOT / SUMS).write_text(''.join(x['sha256'] + '  ' + x['name'] + '\n' for x in result), encoding='utf-8')
    print('بسته‌های تاریخی دست‌نخورده:', len(before))


if __name__ == '__main__':
    build()
