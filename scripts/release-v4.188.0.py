#!/usr/bin/env python3
"""v4.188.0 / v2.112.0 — جلوگیری از ثبت و طراحی برنامه‌های ناقص.

    python3 scripts/release-v4.188.0.py

بسته‌ها:
  MODIFIED-FILES-V4.188.0.zip   → سایت
  SchoolDesk-FIX-v2.112.0.zip   → دسکتاپ

ثبت برنامهٔ رسمیِ فاقد سال/درس/تاریخ/ساعت/مدت از سمت سرور رد می‌شود. برنامه‌های
قدیمی ناقص از پنل طراحی دبیر و رابط مستقیم طراحی کنار گذاشته می‌شوند. در ابزار
«داده‌های یتیم» فقط رکوردهای ناقصِ فعال و بدون طراحی/بایگانی/تخصیص/فایل منبع قابل
حذف امن‌اند؛ آزمون کلاسی که عمداً تاریخ ندارد مستثناست. پیام ربات نیز به شکل
فهرست چندخطی با تفکیک تاریخ، ساعت، کلاس، صندلی و حوزه/سالن ارسال می‌شود.
"""



from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 10, 18, 0, 0, 0)
SUMS = 'V4.188.0-SHA256SUMS.txt'

# (مسیر داخل بسته، مسیر منبع نسبت به ریشهٔ مخزن، نشانهٔ تغییر)
SITE_FILES = [
    ('config/version.php', 'update-v4.152.0/config/version.php', b'4.188.0'),
    ('includes/exams_helper.php', 'update-v4.152.0/includes/exams_helper.php', b"exam_schedule_design_ready"),
    ('db-optimizer.php', 'update-v4.152.0/db-optimizer.php', b'incomplete_exam_schedules'),
    ('exam-design-api.php', 'update-v4.152.0/exam-design-api.php', b'exam_schedule_design_ready'),
    ('exam-print.php', 'update-v4.152.0/exam-print.php', b'exam_schedule_design_ready'),
    ('exams.php', 'update-v4.152.0/exams.php', b'$scheduleDuration<=0'),
    ('teacher-panel.php', 'update-v4.152.0/teacher-panel.php', b'duration_minutes>0 ORDER BY'),
    ('includes/bot_helpers.php', 'update-v4.152.0/includes/bot_helpers.php', b"bot_format_student_exam_schedule"),
    ('includes/bot_outbox.php', 'update-v4.152.0/includes/bot_outbox.php', b"'rubika'"),
    ('includes/bot_webhook_engine.php', 'update-v4.152.0/includes/bot_webhook_engine.php', b"bot_format_student_exam_schedule"),
    ('includes/bot_admin_ui.php', 'update-v4.152.0/includes/bot_admin_ui.php', b'bot_platform_label'),
    ('includes/bot_login_flow.php', 'update-v4.152.0/includes/bot_login_flow.php', b'bot_platform_label'),
    ('includes/bot_inbox.php', 'update-v4.152.0/includes/bot_inbox.php', b'bot_chat_column'),
    ('includes/bot_chats.php', 'update-v4.152.0/includes/bot_chats.php', b'bot_chats_capabilities'),
    ('includes/bot_role_engine.php', 'update-v4.152.0/includes/bot_role_engine.php', b'bot_platforms()'),
    ('includes/attendance_helpers.php', 'update-v4.152.0/includes/attendance_helpers.php', b'bot_platform_label'),
    ('includes/school_roles.php', 'update-v4.152.0/includes/school_roles.php', b"'rubika'"),
    ('includes/header.php', 'update-v4.152.0/includes/header.php', b'rubika-bot.php'),
    ('includes/header_tiles.php', 'update-v4.152.0/includes/header_tiles.php', b"'rubika'"),
    ('includes/desk_sync.php', 'update-v4.152.0/includes/desk_sync.php', b'rubika_bot_users'),
    ('bot-accounts.php', 'update-v4.152.0/bot-accounts.php', b'bot_platform_labels'),
    ('bot-inbox.php', 'update-v4.152.0/bot-inbox.php', b'bot_chats_schema'),
    ('bot-queue.php', 'update-v4.152.0/bot-queue.php', b'bot_platform_labels'),
    ('attendance-tags.php', 'update-v4.152.0/attendance-tags.php', b'bot_platform_labels'),
    ('index.php', 'update-v4.152.0/index.php', b'rubika-bot.php'),
    ('students.php', 'update-v4.152.0/students.php', b'rubika'),
    ('counselor-file.php', 'update-v4.152.0/counselor-file.php', b'bot_chat_column'),
    ('counselor-panel.php', 'update-v4.152.0/counselor-panel.php', b'bot_platforms'),
    ('class-exam-sync-api.php', 'update-v4.152.0/class-exam-sync-api.php', b'rubika_bot_users'),
    ('rubika-bot.php', 'update-v4.152.0/rubika-bot.php', b'rubika'),
    ('rubika-webhook.php', 'update-v4.152.0/rubika-webhook.php', b'rubika'),
]
# دسکتاپ همان صفحه و همان ماژول را بایت‌به‌بایت می‌گیرد (نسخهٔ اختصاصی ندارد).
# موتور وبهوک عمداً نمی‌آید: دسکتاپ گیرندهٔ وبهوک نیست (www-telegram-poll.php
# روی cli-server خودش را کنار می‌کشد).
DESKTOP_FILES = [
    ('config/version.php', 'update-v4.152.0/config/version.php', b'4.188.0'),
    ('includes/exams_helper.php', 'desktop-app-v2/patch/includes-exams_helper.php', b"exam_schedule_design_ready"),
    ('db-optimizer.php', 'desktop-app-v2/patch/www-db-optimizer.php', b'incomplete_exam_schedules'),
    ('exam-design-api.php', 'desktop-app-v2/patch/www-exam-design-api.php', b'exam_schedule_design_ready'),
    ('exam-print.php', 'desktop-app-v2/patch/www-exam-print.php', b'exam_schedule_design_ready'),
    ('exams.php', 'desktop-app-v2/patch/www-exams.php', b'$scheduleDuration<=0'),
    ('teacher-panel.php', 'desktop-app-v2/patch/www-teacher-panel.php', b'duration_minutes>0 ORDER BY'),
    ('includes/bot_helpers.php', 'desktop-app-v2/patch/includes-bot_helpers.php', b'bot_platforms'),
    ('includes/bot_admin_ui.php', 'desktop-app-v2/patch/includes-bot_admin_ui.php', b'bot_platform_label'),
    ('includes/bot_role_engine.php', 'desktop-app-v2/patch/includes-bot_role_engine.php', b'bot_platforms()'),
    ('includes/bot_chats.php', 'desktop-app-v2/patch/includes-bot_chats.php', b'bot_chats_capabilities'),
    ('includes/school_roles.php', 'desktop-app-v2/patch/includes-school_roles.php', b"'rubika'"),
    ('includes/desk_sync.php', 'desktop-app-v2/patch/includes-desk-sync.php', b'rubika_bot_users'),
    ('includes/header.php', 'desktop-app-v2/patch/includes-header.php', b'rubika-bot.php'),
    ('bot-inbox.php', 'desktop-app-v2/patch/www-bot-inbox.php', b'bot_chats_schema'),
    ('bot-accounts.php', 'desktop-app-v2/patch/www-bot-accounts.php', b'bot_platform_labels'),
    ('bot-queue.php', 'desktop-app-v2/patch/www-bot-queue.php', b'bot_platform_labels'),
    ('attendance-tags.php', 'desktop-app-v2/patch/www-attendance-tags.php', b'bot_platform_labels'),
    ('rubika-bot.php', 'desktop-app-v2/patch/www-rubika-bot.php', b'rubika'),
    ('index.php', 'desktop-app-v2/patch/www-index.php', b'rubika-bot.php'),
    ('counselor-file.php', 'desktop-app-v2/patch/www-counselor-file.php', b'bot_platforms()'),
    ('counselor-panel.php', 'desktop-app-v2/patch/www-counselor-panel.php', b'bot_platforms'),
]
# فایل‌هایی که باید بایت‌به‌بایت بین سایت و دسکتاپ یکی باشند
IDENTICAL = [
    ('www-bot-inbox.php', 'bot-inbox.php'),
    ('includes-bot_chats.php', 'includes/bot_chats.php'),
    ('includes-school_roles.php', 'includes/school_roles.php'),
    ('www-rubika-bot.php', 'rubika-bot.php'),
    ('includes-exams_helper.php', 'includes/exams_helper.php'),
]
PACKAGES = [
    ('MODIFIED-FILES-V4.188.0.zip', 'site-update-v4.152.0/', SITE_FILES),
    ('SchoolDesk-FIX-v2.112.0.zip', 'SchoolDeskPro/www/', DESKTOP_FILES),
]


def sha(p):
    return hashlib.sha256(p.read_bytes()).hexdigest()


def check_desktop_copies():
    """نگهبان واپاشی سایت/دسکتاپ.

    ۱) فایل‌های «باید یکسان» بایت‌به‌بایت یکی باشند.
    ۲) هر فایل مشترکی که هم در سایت و هم در دسکتاپ بسته‌بندی می‌شود، نشانهٔ
       v4.188.0 را داشته باشد (هر دو طرف اصلاح شده باشند).
    """
    for patch_name, site_rel in IDENTICAL:
        site = ROOT / 'update-v4.152.0' / site_rel
        desk = ROOT / 'desktop-app-v2/patch' / patch_name
        assert desk.exists(), 'کپی دسکتاپ پیدا نشد: ' + patch_name
        assert sha(site) == sha(desk), 'سایت و دسکتاپ یکسان نیستند: ' + site_rel
    site_map = {arc: (src, marker) for arc, src, marker in SITE_FILES}
    for arc, src, marker in DESKTOP_FILES:
        if arc not in site_map:
            continue
        if site_map[arc][0] != src.split('/', 1)[-1]:
            continue
        assert marker in (ROOT / src).read_bytes(), 'نشانهٔ نسخه در کپی دسکتاپ نیست: ' + arc


def build():
    check_desktop_copies()
    names = [n for n, _, _ in PACKAGES]
    # بسته‌های تاریخی ورودیِ تغییرناپذیرند — همان نگهبانِ بسته‌های پیشین
    before = {p: sha(p) for p in ROOT.glob('*.zip') if p.name not in names}
    result = []
    for name, prefix, pairs in PACKAGES:
        with ZipFile(ROOT / name, 'w', ZIP_DEFLATED, compresslevel=9) as z:
            for arc, src, marker in sorted(pairs):
                data = (ROOT / src).read_bytes()
                assert marker in data, 'فایل بدون تغییرات v4.188.0 است: ' + arc
                info = ZipInfo(prefix + arc, STAMP)
                info.compress_type = ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                z.writestr(info, data)
                print('  ' + prefix + arc, len(data))
        entry = {'name': name, 'bytes': (ROOT / name).stat().st_size,
                 'sha256': sha(ROOT / name),
                 'files': sorted(a for a, _, _ in pairs)}
        result.append(entry)
        print(name, entry['bytes'], entry['sha256'])
    for p, h in before.items():
        assert sha(p) == h, 'بستهٔ تاریخی تغییر کرد: ' + p.name
    (ROOT / SUMS).write_text(
        ''.join(x['sha256'] + '  ' + x['name'] + '\n' for x in result), encoding='utf-8')
    print('بسته‌های تاریخی دست‌نخورده:', len(before))


if __name__ == '__main__':
    build()
