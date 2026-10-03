#!/usr/bin/env python3
"""SchoolDesk-FIX-v2.100.0 — آینهٔ اصلاح فوری v4.177.1 برای دسکتاپ.

    python3 scripts/release-desktop-v2.100.0.py

بسته:
  SchoolDesk-FIX-v2.100.0.zip  → SchoolDeskPro/www/…

فهرست عیناً مثل v2.99.0 است (۱۱ فایل) اما محتوای سه فایلِ صندوق ورودی
اصلاح شده است. سه فایل «وصلهٔ دسکتاپ» (photo_album، school_forms،
reports-lists) نسبت به v2.99.0 تغییر نکرده‌اند و فقط برای کامل‌بودن بسته
همراه‌شان می‌آیند.

اصلاح‌ها (مشترک با سایت):
  includes/bot_inbox.php          — زمان از PHP، ایندکس مستقل از درایور
  includes/bot_webhook_engine.php — require ماژول صندوق ورودی
  bot-inbox.php                   — زمان شمسی، راهنمای بین‌پلتفرمی، تشخیص خطا
"""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 10, 4, 0, 0, 0)
PREFIX = 'SchoolDeskPro/www/'
SUMS = 'V4.177.1-SHA256SUMS.txt'

# (مسیر داخل بسته، مسیر منبع نسبته به ریشهٔ مخزن)
PAIRS = [
    ('includes/photo_album.php',        'desktop-app-v2/patch/includes-photo_album.php'),
    ('includes/school_forms.php',       'desktop-app-v2/patch/includes-school_forms.php'),
    ('reports-lists.php',               'desktop-app-v2/patch/www-reports-lists.php'),
    ('bot-inbox.php',                   'update-v4.152.0/bot-inbox.php'),
    ('cron/bot-outbox-worker.php',      'update-v4.152.0/cron/bot-outbox-worker.php'),
    ('db-optimizer.php',                'update-v4.152.0/db-optimizer.php'),
    ('includes/bot_inbox.php',          'update-v4.152.0/includes/bot_inbox.php'),
    ('includes/bot_webhook_engine.php', 'update-v4.152.0/includes/bot_webhook_engine.php'),
    ('includes/db_retention.php',       'update-v4.152.0/includes/db_retention.php'),
    ('includes/management_hub.php',     'update-v4.152.0/includes/management_hub.php'),
    ('student-bulk-report.php',         'update-v4.152.0/student-bulk-report.php'),
]
NAME = 'SchoolDesk-FIX-v2.100.0.zip'


def sha(p):
    return hashlib.sha256(p.read_bytes()).hexdigest()


def build():
    with ZipFile(ROOT / NAME, 'w', ZIP_DEFLATED, compresslevel=9) as z:
        for arc, src in sorted(PAIRS):
            data = (ROOT / src).read_bytes()
            info = ZipInfo(PREFIX + arc, STAMP)
            info.compress_type = ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            z.writestr(info, data)
            print(PREFIX + arc, len(data))
    entry = sha(ROOT / NAME)
    print(NAME, (ROOT / NAME).stat().st_size, entry)
    (ROOT / SUMS).write_text(
        entry + '  ' + NAME + '\n', encoding='utf-8')


if __name__ == '__main__':
    build()
