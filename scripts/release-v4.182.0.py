#!/usr/bin/env python3
"""v4.182.0 / v2.106.0 — چهار اصلاح گزارش‌شدهٔ کارفرما روی بستهٔ v4.181.0.

    python3 scripts/release-v4.182.0.py

بسته‌ها:
  MODIFIED-FILES-V4.182.0.zip   → سایت   (site-update-v4.152.0/…)
  SchoolDesk-FIX-v2.106.0.zip   → دسکتاپ (SchoolDeskPro/www/…)

## ۱) کلیدهای جهت‌دار در «مدیریت ستونی» (باگ واقعی)

کاربر دید: ↓ یک سلول پایین **و** یک سلول چپ می‌رفت، ↑ بالا و راست، → هیچ کاری
نمی‌کرد و ← دو سلول می‌رفت. ریشه: هندلر قبلی محل سلول را از شمارهٔ ستونِ `td`
می‌گرفت، در حالی که جدول راست‌به‌چپ است و دو ستون «ردیف» و «نام» هم دارد؛ پس
هم مبدأ اشتباه بود و هم جهت‌ها جابه‌جا می‌شدند.

حالا `gcTargetIndex(key, idx, perRow, total)` (داخل بلوک `@gc-logic-start/end`,
پس تست همان متن عرضه‌شده را اجرا می‌کند) روی «ترتیب خواندن» حساب می‌کند:
۰ = بالا-راست. ↓ یک ردیف پایین در همان ستون، ↑ یک ردیف بالا، ← یک قدم به چپ
(اندیس +1)، → یک قدم به راست (اندیس −1) و Enter = ذخیره + مثل ↓. در لبه‌ها
هیچ حرکتی نمی‌کند و مکان‌نما از جدول بیرون نمی‌رود.

## ۲) جای امضای رضایت‌نامهٔ اردو

ردیف پایین سه عنصر داشت (برچسب ولی / امضای مدیر / یک فاصله‌گذار ۱ پیکسلی) و
`space-between` همان فاصله‌گذار را هم می‌چید، به همین دلیل «امضای مدیر / مهر
مدرسه» وسط کادر می‌افتاد. حالا فقط دو عنصر است و بلوک با `margin-left:-2.5mm`
به لب کادر (سمت چپ برگه) می‌چسبد؛ تصویر امضا هم از ۲۶×۱۲ به **۳۶×۱۷ میلی‌متر**
بزرگ‌تر شد و همچنان روی همان خط می‌نشیند.

## ۳) فاصله‌های خط «تاریخ:» در برگهٔ حضور و غیاب

«تاریخ:» با یک فاصلهٔ واقعی (۷ میلی‌متر) از اسلش جدا شد و بین دو اسلش و بعد از
اسلش دوم ۱۳ میلی‌متر جا باز شد تا روز/ماه/سال دستی نوشته شود. جعبه‌های هر ردیف
هم مثل تصویر ارسالی به هم چسبیدند (فاصلهٔ افقی صفر، عمودی ۳ میلی‌متر).

## ۴) تاریخ دعوتنامهٔ انجمن بدون ساعت

پیش‌فرض قبلی «۱۴۰۵/۰۷/۲۴ ۱۷:۱۲» بود. حالا `sf_today_date()` فقط تاریخ می‌دهد و
`sf_strip_time()` ساعتی را که نسخهٔ پیشین در جدول `settings` ذخیره کرده بود،
هنگام خواندن جدا می‌کند (پس نصب‌های فعلی هم تاریخ بدون ساعت می‌گیرند).

"""

from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 10, 17, 0, 0, 0)
SUMS = 'V4.182.0-SHA256SUMS.txt'

# (مسیر داخل بسته، مسیر منبع نسبت به ریشهٔ مخزن، نشانهٔ تغییر)
# نشانه، دقیقاً همان چیزی است که این بسته برای آن ساخته شده؛ اگر روزی کسی فایل
# را از سورس قدیمی بسته‌بندی کند، ساخت بسته با خطا می‌ایستد.
FILES = [
    ('config/version.php', 'update-v4.152.0/config/version.php', "4.182.0".encode()),
    ('grade-columns.php', 'update-v4.152.0/grade-columns.php', 'gcTargetIndex'.encode()),
    ('includes/management_hub.php', 'update-v4.152.0/includes/management_hub.php', "'columns'=>['مدیریت ستونی',".encode()),
    ('includes/school_form_editor.php', 'update-v4.152.0/includes/school_form_editor.php', 'sfe_render'.encode()),
    ('includes/school_forms.php', 'update-v4.152.0/includes/school_forms.php', 'sf_today_date'.encode()),
    ('reports-lists.php', 'update-v4.152.0/reports-lists.php', 'anjoman_editor'.encode()),
]
SOURCE = {
    'www-grade-columns.php': 'grade-columns.php',
    'includes-management_hub.php': 'includes/management_hub.php',
    'includes-school_form_editor.php': 'includes/school_form_editor.php',
    'includes-school_forms.php': 'includes/school_forms.php',
    'www-reports-lists.php': 'reports-lists.php',
}
PACKAGES = [
    ('MODIFIED-FILES-V4.182.0.zip', 'site-update-v4.152.0/', FILES),
    ('SchoolDesk-FIX-v2.106.0.zip', 'SchoolDeskPro/www/', FILES),
]


def sha(p):
    return hashlib.sha256(p.read_bytes()).hexdigest()


def check_desktop_copies():
    """نسخهٔ دسکتاپِ هر فایل باید با نسخهٔ سایت بایت‌به‌بایت یکی باشد.

    اگر روزی کسی فقط یکی از دو طرف را عوض کند، این نگهبان جلوی انتشار بستهٔ
    ناهمخوان (سایت و دسکتاپ متفاوت) را می‌گیرد.
    """
    for patch_name, site_rel in SOURCE.items():
        site = ROOT / 'update-v4.152.0' / site_rel
        desk = ROOT / 'desktop-app-v2/patch' / patch_name
        assert desk.exists(), 'کپی دسکتاپ پیدا نشد: ' + patch_name
        assert sha(site) == sha(desk), 'سایت و دسکتاپ یکسان نیستند: ' + site_rel


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
                assert marker in data, 'فایل بدون تغییرات v4.182.0 است: ' + arc
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
