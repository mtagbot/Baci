#!/usr/bin/env python3
"""v4.183.0 / v2.107.0 — سه اصلاح بزرگ گزارش‌شدهٔ کارفرما.

    python3 scripts/release-v4.183.0.py

بسته‌ها:
  MODIFIED-FILES-V4.183.0.zip   → سایت   (site-update-v4.152.0/…)
  SchoolDesk-FIX-v2.107.0.zip   → دسکتاپ (SchoolDeskPro/www/…)

## ۱) وضعیت همگام‌سازی و کلید به‌روزرسانی (دو باگ جدا، یک نشانه)

کاربر دید: در «در انتظار وضعیت همگام‌سازی» می‌ماند («سرور مدرسه: اتصال هنوز
تأیید نشده است») و بنر بالا «invalid key» می‌دهد، در حالی که دکمهٔ «همگام‌سازی
همین حالا» موفق است.

الف) **وضعیت**: کاهش‌دهندهٔ وضعیت (`desk_connection_snapshot`) عمداً سخت‌گیر است
و قلب تپنده را فقط وقتی قبول می‌کند که کارگر پس‌زمینه پرچم `server_verified`
را نوشته باشد. اما چرخه‌های کامل نادرند (هر ۳۰۰ ثانیه یا پس از هر ویرایش)، پس
در ۳۰ ثانیهٔ بین دو probe، ضربانِ «بیکار» بدون پرچم می‌آمد و پنل به «در انتظار»
برمی‌گشت. حالا:
  • موتور همگام‌سازی پس از هر تبادل موفق — هم چرخهٔ کامل و هم probe — لحظهٔ
    تأیید را در `desk_sync_verified_at` ثبت می‌کند و پرچم صریح
    `server_verified` را در نتیجهٔ `run()`/`tick()` برمی‌گرداند.
  • `desk_connection_verified_heartbeat()` همان قلب را با اثباتِ ثبت‌شدهٔ موتور
    کامل می‌کند: ضربان زنده + بی‌خطا + تبادل موفق تا یک ساعت پیش = «همگام با
    سرور»؛ و اگر اصلاً ضربانی نباشد ولی تبادل چند لحظه پیش موفق بوده، همان
    اثبات به‌جای ضربان می‌نشیند. هیچ خطای واقعی (قطعی، backoff، خطا، رد شدن
    probe، در حال کار) پوشانده نمی‌شود — کاهش‌دهنده دست‌نخورده و سخت‌گیر است.

ب) **کلید**: سایت دو منبع کلید دارد (فایل خصوصی `config/desk-sync-key.php` که
نصب سایت می‌سازد، و کلید داخل `DESK_SYNC_API_KEY`/`desk-sync-api.php` که بستهٔ
دسکتاپ جفت می‌کند). همگام‌سازی هر دو را می‌پذیرد ولی API به‌روزرسانی فقط
اولی را می‌خواند؛ روی نصبی که با بستهٔ دسکتاپ جفت شده بود، همگام‌سازی سالم بود
و به‌روزرسانی «invalid key» می‌گرفت. حالا `desk_update_api_keys()` هر دو منبع
را می‌خواند و پاسخ‌های `key_missing` / `key_mismatch` فارسی و بدون افشای کلید
برمی‌گرداند؛ سمت دسکتاپ هم همان را به بنر روشن فارسی تبدیل می‌کند.

## ۲) «آماده‌سازی سوال جدید» در طراحی زندهٔ آزمون

  • متن هر نوع سوال دیگر **نوشته** نمی‌شود؛ راهنما (placeholder) است: لایهٔ
    `#qTextPh` بیرون از ویرایشگر (پس هرگز ذخیره/چاپ نمی‌شود) و متن راهنمای
    خانه‌های خالی گزینه‌ها با `data-ph` که فقط داخل `.rich-editor` دیده می‌شود.
    ساختار واقعی هر نوع (مربع صحیح/غلط، چهار خانهٔ گزینه) می‌ماند.
  • «بارم / نمره» خالی می‌آید و ۱ فقط راهنماست؛ پیش‌نمایش هم خالی است.
  • تصویرِ داخل سوال یک دکمهٔ × در گوشهٔ بالا گرفت (روی موبایل بالاتر و بزرگ‌تر
    تا با دستگیره‌های ۴۶ پیکسلی تلاقی نکند) که تصویر را از مدل و برگه حذف
    می‌کند و همان‌جا ذخیره می‌سازد. ابزارهای ویرایش در چاپ دیده نمی‌شوند.

## ۳) هم‌خوانی چاپ/PDF با طراحی زنده (چرا سوال‌ها به برگهٔ بعد می‌رفتند)

PDFی که ربات برای معاون اجرایی می‌فرستد با GD و از روی همان طراحی ساخته می‌شود،
ولی هندسهٔ کادر سوال در آن با ویرایشگر یکی نبود:
  • عرض کادر **۲۰۳mm** بود در حالی که CSS ویرایشگر `--qW:210mm` است → ستون
    سوال ۷ میلی‌متر باریک‌تر، متن زودتر می‌شکست.
  • صفحهٔ زوج **۲۸۶mm از ۵mm** بود در حالی که `.page.no-header .qwrap` ارتفاع
    ۲۹۴mm از ۱mm دارد → ۸ میلی‌متر فضای کمتر در برگه‌های دوم، چهارم، …
  • ارتفاع هر ردیف `محتوا + ۲×پدینگ + مرز` (۱۰px) حساب می‌شد، در حالی که در
    `.q-content` پدینگ بالا صفر است و ارتفاع ذخیره‌شده «مرز جعبه» — پس هر سوال
    ~۱٫۳ میلی‌متر بلندتر از طراحی زنده بود (یک برگهٔ هشت‌سوالی ≈ ۱۰ میلی‌متر).
حالا هر سه عدد از همان CSS ویرایشگر می‌آید: ۲۱۰mm، ۲۵۷mm فرد / ۲۹۴mm زوج از
۱mm، و `max(ارتفاع سوال، محتوا + پدینگ پایین + مرز)`. ویرایشگر دست‌نخورده ماند؛
فقط موتور PDF به آن رسید. (آستانهٔ سرریز ۴ پیکسل در هر دو یکی است.)
"""

from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 10, 17, 0, 0, 0)
SUMS = 'V4.183.0-SHA256SUMS.txt'

# (مسیر داخل بسته، مسیر منبع نسبت به ریشهٔ مخزن، نشانهٔ تغییر)
# نشانه، دقیقاً همان چیزی است که این بسته برای آن ساخته شده؛ اگر روزی کسی فایل
# را از سورس قدیمی بسته‌بندی کند، ساخت بسته با خطا می‌ایستد.
SITE_FILES = [
    ('config/version.php', 'update-v4.152.0/config/version.php', b'4.183.0'),
    ('desk-connection-status.php', 'update-v4.152.0/desk-connection-status.php', b'desk_connection_verified_heartbeat'),
    ('desk-update-api.php', 'update-v4.152.0/desk-update-api.php', b'desk_update_api_keys'),
    ('exam-print.php', 'update-v4.152.0/exam-print.php', b'syncQuestionPlaceholder'),
    ('assets/css/exam-designer-mobile.css', 'update-v4.152.0/assets/css/exam-designer-mobile.css', b'.img-del-btn'),
    ('includes/bot_role_engine.php', 'update-v4.152.0/includes/bot_role_engine.php', b'$innerH'),
    ('includes/desk_connection.php', 'update-v4.152.0/includes/desk_connection.php', b'desk_connection_verified_copy'),
    ('includes/desk_sync.php', 'update-v4.152.0/includes/desk_sync.php', b'desk_sync_verified_at'),
]
# دسکتاپ: فایل‌های مشترک از خود سایت می‌آید و فایل‌های اختصاصی دسکتاپ از پوشهٔ
# وصله. `includes/desk_sync.php` عمداً از پوشهٔ وصله می‌آید: نسخهٔ سایت مسیر
# endpoint را به class-exam-sync-api.php بازنویسی می‌کند و آن فایل روی سایت‌های
# نصب‌شده نیست؛ نسخهٔ دسکتاپ همان موتور است بدون آن بازنویسی.
DESKTOP_FILES = [
    ('config/version.php', 'update-v4.152.0/config/version.php', b'4.183.0'),
    ('desk-connection-status.php', 'update-v4.152.0/desk-connection-status.php', b'desk_connection_verified_heartbeat'),
    ('exam-print.php', 'desktop-app-v2/patch/www-exam-print.php', b'syncQuestionPlaceholder'),
    ('assets/css/exam-designer-mobile.css', 'desktop-app-v2/patch/www-assets-css-exam-designer-mobile.css', b'.img-del-btn'),
    ('includes/bot_role_engine.php', 'desktop-app-v2/patch/includes-bot_role_engine.php', b'$innerH'),
    ('includes/desk_connection.php', 'update-v4.152.0/includes/desk_connection.php', b'desk_connection_verified_copy'),
    ('includes/desk_sync.php', 'desktop-app-v2/patch/includes-desk-sync.php', b'desk_sync_verified_at'),
    ('includes/desk_update.php', 'desktop-app-v2/patch/includes-desk_update.php', b'desk_update_error_result'),
]
# فایل‌هایی که باید بایت‌به‌بایت بین سایت و دسکتاپ یکی باشند
IDENTICAL = [
    ('www-assets-css-exam-designer-mobile.css', 'assets/css/exam-designer-mobile.css'),
]
PACKAGES = [
    ('MODIFIED-FILES-V4.183.0.zip', 'site-update-v4.152.0/', SITE_FILES),
    ('SchoolDesk-FIX-v2.107.0.zip', 'SchoolDeskPro/www/', DESKTOP_FILES),
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
                assert marker in data, 'فایل بدون تغییرات v4.183.0 است: ' + arc
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
