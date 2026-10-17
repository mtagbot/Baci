#!/usr/bin/env python3
"""v4.186.0 / v2.110.0 — روبیکا: پیام‌رسان سوم ربات، کنار بله و تلگرام.

    python3 scripts/release-v4.186.0.py

بسته‌ها:
  MODIFIED-FILES-V4.186.0.zip   → سایت   (site-update-v4.152.0/…)
  SchoolDesk-FIX-v2.110.0.zip   → دسکتاپ (SchoolDeskPro/www/…)

## چه چیزی اضافه شد

  • پلتفرم `rubika` در همان لایهٔ مشترک ربات‌ها: توکن، تنظیمات، وبهوک،
    متن‌ها/دکمه‌ها، ارسال هدفمند، صف ماندگار و تلاش مجدد. آدرس API:
    https://botapi.rubika.ir/v3/<token>/<method> (مطابق مستندات رسمی).
  • صفحهٔ «ربات روبیکا» + فایل وبهوک `rubika-webhook.php` که همان موتور
    مشترک را با BOT_PLATFORM=rubika اجرا می‌کند.
  • لایهٔ پلتفرم دیگر ternaryهای «تلگرام وگرنه بله» ندارد: bot_platforms()،
    bot_user_table()، bot_state_table()، bot_chat_column()، bot_username_column().
  • افزودن روبیکا به: اکانت‌های متصل، صف اعلان‌ها، صندوق ورودی و تب گفتگوها،
    ورود مدیر/دبیر از داخل ربات، نقش‌ها و منوهای دبیر/معاون/مشاور، تگ و پیام
    آزمایشی حضور، بررسی کارنامهٔ والدین، کاشی/سایدبار/میان‌بر داشبورد و
    فهرست جدول‌های همگام‌سازی دسکتاپ.
  • مهاجرت MySQL: enumهای قدیمی ('bale','telegram') با ALTER محافظت‌شده
    چهارمقداری می‌شوند (سه جدول متن/دکمه/لاگ + گزارش بررسی والدین +
    counseling_requests + bot_admin_sessions). SQLite enum ندارد.

## محدودیت‌های صادقانه (همان‌ها که در صفحه هم آمده)

  • Rubika Bot API v3 رویدادها را با envelope متفاوت (`update` و `inline_message`)
    می‌فرستد؛ این وصله آن‌ها را به موتور مشترک تبدیل می‌کند. ثبت endpoint با
    `updateBotEndpoints` و دو نوع `ReceiveUpdate`/`ReceiveInlineMessage` انجام می‌شود.
  • `getChat` مستند است و فهرست/افزودن دستی کار می‌کند؛ `leaveChat` در فهرست
    API رسمی نیست، بنابراین خروج از گفتگو در رابط غیرفعال می‌ماند.
  • هیچ فراخوانی واقعی به botapi.rubika.ir در محیط ساخت انجام نشده (نه توکن
    واقعی و نه شبکهٔ پایدار). وبهوک واقعی هم ثبت نشده. تست‌ها با SQLite و
    php-wasm همان مسیر داده و صفحه را می‌سنجند.

## بسته‌بندی

  • سایت: ۲۵ فایل (شامل includes/school_roles.php که تازه به اورلی آمده).
  • دسکتاپ: ۱۶ فایل از forks دسکتاپ + کپی‌های بایت‌به‌بایت صفحه/ماژول.
"""



from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 10, 17, 0, 0, 0)
SUMS = 'V4.186.0-SHA256SUMS.txt'

# (مسیر داخل بسته، مسیر منبع نسبت به ریشهٔ مخزن، نشانهٔ تغییر)
SITE_FILES = [
    ('config/version.php', 'update-v4.152.0/config/version.php', b'4.186.0'),
    ('includes/bot_helpers.php', 'update-v4.152.0/includes/bot_helpers.php', b"bot_platforms"),
    ('includes/bot_outbox.php', 'update-v4.152.0/includes/bot_outbox.php', b"'rubika'"),
    ('includes/bot_webhook_engine.php', 'update-v4.152.0/includes/bot_webhook_engine.php', b"'rubika'"),
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
    ('config/version.php', 'update-v4.152.0/config/version.php', b'4.186.0'),
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
]
PACKAGES = [
    ('MODIFIED-FILES-V4.186.0.zip', 'site-update-v4.152.0/', SITE_FILES),
    ('SchoolDesk-FIX-v2.110.0.zip', 'SchoolDeskPro/www/', DESKTOP_FILES),
]


def sha(p):
    return hashlib.sha256(p.read_bytes()).hexdigest()


def check_desktop_copies():
    """نگهبان واپاشی سایت/دسکتاپ.

    ۱) فایل‌های «باید یکسان» بایت‌به‌بایت یکی باشند.
    ۲) هر فایل مشترکی که هم در سایت و هم در دسکتاپ بسته‌بندی می‌شود، نشانهٔ
       v4.183.0 را داشته باشد (هر دو طرف اصلاح شده باشند).
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
                assert marker in data, 'فایل بدون تغییرات v4.186.0 است: ' + arc
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
