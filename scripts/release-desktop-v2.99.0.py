#!/usr/bin/env python3
"""SchoolDesk-FIX-v2.99.0 — آینهٔ تغییرات v4.177.0 برای نرم‌افزار دسکتاپ.

    python3 scripts/release-desktop-v2.99.0.py

بسته:
  SchoolDesk-FIX-v2.99.0.zip  → SchoolDeskPro/www/…

سه فایل «وصلهٔ دسکتاپ» (desktop-app-v2/patch) به‌روز شدند. این سه فایل در
نسخهٔ v4.176.0 مو‌به‌مو کپیِ نسخهٔ سایت بودند (تفاوتی با سایت نداشتند)، پس
کپیٔ بی‌خطر است و بازنویسی‌شان نسخهٔ مستقل دسکتاپ را از بین نمی‌برد:
  includes-photo_album.php  → includes/photo_album.php   (آلبوم دوستونه)
  www-reports-lists.php     → reports-lists.php          (خام/دانش‌آموزان + سطح‌ها)
  includes-school_forms.php → includes/school_forms.php  (چهار فرم بازسازی‌شده)

فایل‌های «مشترک» از درخت سایت می‌آیند، چون نسخهٔ مستقل دسکتاپ ندارند و
باید دست‌نخورده روی نسخهٔ کامل سایت بنشینند. دو فایل دست‌نخورده مانده‌اند،
چون در این نسخه تغییر نکرده‌اند:
  includes-bot_admin_ui.php (شمارش کاربران متصل — مثل v4.176.0)
  www-students.php          (چینش فهرست — مثل v4.176.0)
"""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 10, 3, 0, 0, 0)
PREFIX = 'SchoolDeskPro/www/'
SUMS = 'V4.177.0-SHA256SUMS.txt'

# (مسیر داخل بسته، مسیر منبع نسبته به ریشهٔ مخزن)
PAIRS = [
    ('includes/photo_album.php',       'desktop-app-v2/patch/includes-photo_album.php'),
    ('includes/school_forms.php',      'desktop-app-v2/patch/includes-school_forms.php'),
    ('reports-lists.php',              'desktop-app-v2/patch/www-reports-lists.php'),
    ('bot-inbox.php',                  'update-v4.152.0/bot-inbox.php'),
    ('cron/bot-outbox-worker.php',     'update-v4.152.0/cron/bot-outbox-worker.php'),
    ('db-optimizer.php',               'update-v4.152.0/db-optimizer.php'),
    ('includes/bot_inbox.php',         'update-v4.152.0/includes/bot_inbox.php'),
    ('includes/bot_webhook_engine.php', 'update-v4.152.0/includes/bot_webhook_engine.php'),
    ('includes/db_retention.php',      'update-v4.152.0/includes/db_retention.php'),
    ('includes/management_hub.php',    'update-v4.152.0/includes/management_hub.php'),
    ('student-bulk-report.php',        'update-v4.152.0/student-bulk-report.php'),
]
NAME = 'SchoolDesk-FIX-v2.99.0.zip'


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
        'f38d44526cdbc16d0d6a0f42327d37861a173117cb06069c250a1d258ee4f7c4  MODIFIED-FILES-V4.177.0.zip\n'
        + entry + '  ' + NAME + '\n', encoding='utf-8')


if __name__ == '__main__':
    build()
