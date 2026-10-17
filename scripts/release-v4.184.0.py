#!/usr/bin/env python3
"""v4.184.0 / v2.108.0 — «گروه‌ها و کانال‌ها»: دیدن گفتگوهای ربات و خروج از گروه.

    python3 scripts/release-v4.184.0.py

بسته‌ها:
  MODIFIED-FILES-V4.184.0.zip   → سایت   (site-update-v4.152.0/…)
  SchoolDesk-FIX-v2.108.0.zip   → دسکتاپ (SchoolDeskPro/www/…)

## چرا این تغییر لازم بود

هیچ‌کدام از APIهای بله و تلگرام متدی برای «فهرست همهٔ گروه‌هایی که ربات در
آن‌هاست» ندارند. تا امروز تنها راهِ دیدنِ شناسهٔ یک گروه، پیدا کردن پیامش در
«پیام‌های دریافتی ربات» بود و خروج از گروه اصلاً از داخل سایت ممکن نبود.

## چه چیزی اضافه شد

  • جدول `bot_chats` (سازگار با MySQL و SQLite، idempotent): نوع گفتگو، عنوان،
    نام کاربری، وضعیت عضویت، اولین/آخرین مشاهده، زمان آخرین پیام، شمارندهٔ
    پیام، زمان بررسی، خطای آخر، و «چه کسی و کِی خارج کرد».
  • ثبت خودکار از دلِ همان آپدیت‌هایی که ربات می‌بیند: هر پیام، هر دکمه،
    `new_chat_members` / `left_chat_member` (هر دو پلتفرم) و `my_chat_member`
    (فقط تلگرام). همه در try/catch؛ جریان وبهوک هرگز نمی‌شکند.
  • تب دوم «گروه‌ها و کانال‌ها» در همان صفحهٔ «پیام‌های دریافتی ربات»: فهرست
    همهٔ گفتگوها (گروه/کانال/خصوصی)، فیلتر نوع و وضعیت، جستجو، «بروزرسانی
    وضعیت» با getChat (۲۰ مورد در هر کلیک، فقط موارد کهنه‌تر از ۱۰ دقیقه)،
    «خروج از گروه» با تأیید مرورگر و CSRF، و «افزودن دستی» با شناسه برای
    گروه‌های قدیمی که هنوز هیچ پیامی نفرستاده‌اند.
  • زیر نام فرستنده در جدول پیام‌ها، عنوان گروه/کانال هم نوشته می‌شود.
  • خروج برگشت‌ناپذیر است: ردیف حذف نمی‌شود، `status=left` می‌ماند و در
    `activity_logs` با عنوان «خروج ربات از گروه» ثبت می‌شود.

## محدودیت‌ها (همان‌ها که در صفحه هم نوشته شده)

  • فهرست فقط از چیزهایی ساخته می‌شود که ربات دیده است؛ گروهی که پیش از این
    نسخه اضافه شده و از آن پس هیچ پیامی نفرستاده، تا اولین پیام یا «افزودن
    دستی» دیده نمی‌شود.
  • بله آپدیت `my_chat_member` ندارد؛ آنجا `new_chat_members` کار می‌کند.
  • تست شبکه‌ای واقعی (getChat/leaveChat روی tapi.bale.ai) در محیط ساخت انجام
    نمی‌شود؛ سوئیت با transport تزریقی همان مسیر را می‌سنجد.

## بسته‌بندی

  • سایت: صفحه + ماژول + قلاب وبهوک + شمارهٔ نسخه.
  • دسکتاپ: همان صفحه و همان ماژول، بایت‌به‌بایت (نسخهٔ اختصاصی دسکتاپ ندارند).
    موتور وبهوک به دسکتاپ نمی‌رود چون دسکتاپ گیرندهٔ وبهوک نیست.
"""


from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 10, 17, 0, 0, 0)
SUMS = 'V4.184.0-SHA256SUMS.txt'

# (مسیر داخل بسته، مسیر منبع نسبت به ریشهٔ مخزن، نشانهٔ تغییر)
SITE_FILES = [
    ('config/version.php', 'update-v4.152.0/config/version.php', b'4.184.0'),
    ('bot-inbox.php', 'update-v4.152.0/bot-inbox.php', b'bot_chats_schema'),
    ('includes/bot_chats.php', 'update-v4.152.0/includes/bot_chats.php', b'bot_chats_leave'),
    ('includes/bot_webhook_engine.php', 'update-v4.152.0/includes/bot_webhook_engine.php', b'my_chat_member'),
]
# دسکتاپ همان صفحه و همان ماژول را بایت‌به‌بایت می‌گیرد (نسخهٔ اختصاصی ندارد).
# موتور وبهوک عمداً نمی‌آید: دسکتاپ گیرندهٔ وبهوک نیست (www-telegram-poll.php
# روی cli-server خودش را کنار می‌کشد).
DESKTOP_FILES = [
    ('config/version.php', 'update-v4.152.0/config/version.php', b'4.184.0'),
    ('bot-inbox.php', 'desktop-app-v2/patch/www-bot-inbox.php', b'bot_chats_schema'),
    ('includes/bot_chats.php', 'desktop-app-v2/patch/includes-bot_chats.php', b'bot_chats_leave'),
]
# فایل‌هایی که باید بایت‌به‌بایت بین سایت و دسکتاپ یکی باشند
IDENTICAL = [
    ('www-bot-inbox.php', 'bot-inbox.php'),
    ('includes-bot_chats.php', 'includes/bot_chats.php'),
]
PACKAGES = [
    ('MODIFIED-FILES-V4.184.0.zip', 'site-update-v4.152.0/', SITE_FILES),
    ('SchoolDesk-FIX-v2.108.0.zip', 'SchoolDeskPro/www/', DESKTOP_FILES),
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
                assert marker in data, 'فایل بدون تغییرات v4.184.0 است: ' + arc
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
