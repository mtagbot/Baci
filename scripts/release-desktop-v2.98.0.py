#!/usr/bin/env python3
"""SchoolDesk-FIX-v2.98.0 — آینهٔ تغییرات v4.176.0 برای نرم‌افزار دسکتاپ.

    python3 scripts/release-desktop-v2.98.0.py

بسته:
  SchoolDesk-FIX-v2.98.0.zip  → SchoolDeskPro/www/…

سه فایل «مشترک» از درخت سایت می‌آیند (نسخهٔ مستقل دسکتاپ ندارند) و پنج فایل
از درخت وصلهٔ دسکتاپ (desktop-app-v2/patch) می‌آیند، چون نسخهٔ دسکتاپ خودش
را دارد و نباید با نسخهٔ سایت بازنویسی شود:
  includes-bot_admin_ui.php  → includes/bot_admin_ui.php   (شمارش واقعی کاربران متصل)
  www-students.php           → students.php                (چینش فهرست + ترتیب گزارش)
  includes-photo_album.php   → includes/photo_album.php    (آلبوم یک صفحه برای هر کلاس)
  www-reports-lists.php      → reports-lists.php           (تب فرم‌های اداری)
  includes-school_forms.php  → includes/school_forms.php   (چهار فرم .mrt)
"""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 10, 3, 0, 0, 0)
PREFIX = 'SchoolDeskPro/www/'
SUMS = 'V4.176.0-SHA256SUMS.txt'

# (مسیر داخل بسته، مسیر منبع نسبته به ریشهٔ مخزن)
PAIRS = [
    ('includes/photo_album.php',   'desktop-app-v2/patch/includes-photo_album.php'),
    ('includes/bot_admin_ui.php',  'desktop-app-v2/patch/includes-bot_admin_ui.php'),
    ('includes/school_forms.php',  'desktop-app-v2/patch/includes-school_forms.php'),
    ('reports-lists.php',          'desktop-app-v2/patch/www-reports-lists.php'),
    ('students.php',               'desktop-app-v2/patch/www-students.php'),
    ('student-bulk-report.php',    'update-v4.152.0/student-bulk-report.php'),
    ('db-optimizer.php',           'update-v4.152.0/db-optimizer.php'),
    ('includes/db_retention.php',  'update-v4.152.0/includes/db_retention.php'),
    ('cron/bot-outbox-worker.php', 'update-v4.152.0/cron/bot-outbox-worker.php'),
]
NAME = 'SchoolDesk-FIX-v2.98.0.zip'


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
        '406998b55510ad24945df73a18c5acfc3bc5d65c49d65152dde8478f740abbf8  MODIFIED-FILES-V4.176.0.zip\n'
        + entry + '  ' + NAME + '\n', encoding='utf-8')


if __name__ == '__main__':
    build()
