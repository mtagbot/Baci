#!/usr/bin/env python3
"""v4.171.0 — «بهداشت صف مسدود»: جفت بستهٔ اصلاحی، بدون بازسازی کامل.

فقط دو فایل سایت (includes/bot_outbox.php + includes/bot_webhook_engine.php) و
همان bot_outbox.php برای دسکتاپ. هیچ پیکربندی، دیتابیس، لانچر یا بستهٔ تاریخی
دوباره ساخته نمی‌شود؛ زمان‌مهر ثابت است تا خروجی قابل بازتولید بماند.

    python3 scripts/release-bot-queue-hygiene.py
"""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 9, 29, 0, 0, 0)
SITE_FILES = ['includes/bot_outbox.php', 'includes/bot_webhook_engine.php']
DESKTOP_FILES = ['includes/bot_outbox.php']
PACKAGES = [
    ('MODIFIED-FILES-V4.171.0.zip', 'site-update-v4.152.0/', SITE_FILES),
    ('SchoolDesk-FIX-v2.93.0.zip', 'SchoolDeskPro/reports/', DESKTOP_FILES),
]
SUMS = 'BOT-QUEUE-HYGIENE-V4.171.0-SHA256SUMS.txt'


def sha(p):
    return hashlib.sha256(p.read_bytes()).hexdigest()


def build():
    srcs = {}
    for _, _, files in PACKAGES:
        for f in files:
            p = ROOT / 'update-v4.152.0' / f
            assert p.is_file(), 'فایل منبع پیدا نشد: ' + f
            srcs[f] = p
    # محافظ: هیچ بستهٔ تاریخی دست نخوری باقی نمانده باشد.
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
        assert sha(p) == h, 'بستهٔ historical تغییر کرد: ' + p.name
    (ROOT / SUMS).write_text(''.join(x['sha256'] + '  ' + x['name'] + '\n' for x in result), encoding='utf-8')
    print('بسته‌های تاریخی دست‌نخورده:', len(before))


if __name__ == '__main__':
    build()
