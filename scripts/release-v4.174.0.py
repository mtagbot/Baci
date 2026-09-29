#!/usr/bin/env python3
"""v4.174.0 — «رمز همهٔ دبیران = کد پرسنلی»، پیام خطای ورود همیشه قابل مشاهده،
دکمهٔ ورود به پنل از داخل پیام‌رسان.

    python3 scripts/release-v4.174.0.py

بسته‌ها:
  MODIFIED-FILES-V4.174.0.zip  → سایت (site-update-v4.152.0/…)
  SchoolDesk-FIX-v2.96.0.zip   → دسکتاپ (SchoolDeskPro/www/…)

دسکتاپ فقط فایل‌های مشترک را می‌گیرد: index.php و admin-login.php نسخهٔ مستقل
خودشان را در desktop-app-v2/patch/www-*.php دارند و از قبل کد پرسنلی را
می‌پذیرفتند؛ includes/bot_helpers.php و includes/bot_role_engine.php هم نسخهٔ
مستقل دارند و دست‌نخورده می‌مانند (کد جدید به آن‌ها وابسته نیست).
"""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 9, 29, 0, 0, 0)

SITE_FILES = [
    'index.php',
    'admin-login.php',
    'import-teachers.php',
    'includes/login_feedback.php',
    'includes/functions.php',
    'includes/bot_helpers.php',
    'includes/bot_role_engine.php',
    'includes/bot_webhook_engine.php',
]
DESKTOP_FILES = [
    'import-teachers.php',
    'includes/login_feedback.php',
    'includes/functions.php',
    'includes/bot_webhook_engine.php',
]
PACKAGES = [
    ('MODIFIED-FILES-V4.174.0.zip', 'site-update-v4.152.0/', SITE_FILES),
    ('SchoolDesk-FIX-v2.96.0.zip', 'SchoolDeskPro/www/', DESKTOP_FILES),
]
SUMS = 'V4.174.0-SHA256SUMS.txt'


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
