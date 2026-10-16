#!/usr/bin/env python3
"""v4.181.0 / v2.105.0 — چهار تغییر درخواستی کارفرما در سامانهٔ وب.

    python3 scripts/release-v4.181.0.py

بسته‌ها:
  MODIFIED-FILES-V4.181.0.zip   → سایت   (site-update-v4.152.0/…)
  SchoolDesk-FIX-v2.105.0.zip   → دسکتاپ (SchoolDeskPro/www/…)

## ۱) مدیریت کارنامه‌ها ← تب تازهٔ «مدیریت ستونی»

`grade-columns.php` (فایل تازه): یک کلاس، یک ماه، یک جدول.

  * فیلترهای سال تحصیلی / ماه / کلاس (ماه فهرست ثابت شمسی + ماه جاری).
  * یک ردیف برای هر دانش‌آموز کلاس (مرتب‌سازی الفبایی پیش‌فرض) و یک ستون برای
    هر درسی که در **برنامهٔ هفتگی همان کلاس** آمده؛ ترتیب ستون‌ها = اولین زنگِ
    هفتهٔ آن درس (شنبه/اول → …).
  * زیر نام هر درس، **فقط نام خانوادگی دبیر** (پیشوند «آقای/خانم/استاد…» حذف
    می‌شود؛ در `gc_teacher_label`)، و در انتها ستون **معدل**.
  * سلول دانش‌آموزِ بی‌نمره «-» می‌ماند. هر سلول ورودی زندهٔ متن است و با هر
    تغییر (change یا خروج از کادر) همان نمره با `fetch` POST می‌شود:
    `gc_save_cell` کارنامهٔ آن ماه را در صورت نبودن می‌سازد (نوبت از ماه)، نمرهٔ
    آن درس را می‌نویسد/به‌روزرسانی می‌کند، خالی‌کردن سلول آن نمره را حذف می‌کند و
    معدل را با همان قاعدهٔ `reports.php` بازمی‌سازد (۲۱ = غیبت، بی‌اثر).
  * کلیک روی سرستون هر درس (و نام/معدل) جدول را صعودی/نزولی مرتب می‌کند؛
    «بی‌نمره»ها همیشه آخر می‌مانند. کلید Tab و ↑ ↓ ← → بین سلول‌ها جابه‌جا
    می‌شوند. رنگ: زیر ۱۰ قرمز، ۲۱ کهربایی.
  * منطق نابِ مرتب‌سازی/تبدیل عدد در متن HTML با نشانه‌های
    `@gc-logic-start/end` علامت خورده و تست، همان متن عرضه‌شده را اجرا می‌کند.

تب در `includes/management_hub.php` بلافاصله بعد از «کارنامه‌ها» اضافه شده است.

## ۲) برگهٔ حضور و غیاب کلاسی دقیقاً طبق تصویر ارسالی

`sf_render_absents_form` از نو نوشته شد: یک A4 عمودی، عنوان
«لیست ثبت حضور و غیاب کلاس ۷-۱»، سپس شش کادر روز در سه ردیف دوتایی
(شنبه|یکشنبه، دوشنبه|سه‌شنبه، چهارشنبه|پنجشنبه). هر کادر: «روز …» و
«تاریخ: / /» و جدولی سه‌ستونی «زنگ | اسامی غایبین | امضای دبیر» با سه ردیف
«اول/دوم/سوم». این برگه جای نام دانش‌آموز، خودِ غایبین را ثبت می‌کند؛ پس در
حالت «پایه» و «با نام» یکی است.

## ۳) دعوتنامهٔ انجمن — ورودی‌محور با کلید نمایش برای هر فیلد

  * متن نامه، **فهرست برنامه‌ها** (هر خط یک بند)، **زمان و مکان جلسه**،
    **شمارهٔ نامه** و **تاریخ** از «گزینه‌های فرم» می‌آید؛ پیش‌فرض‌ها در
    `sf_form_defaults('anjoman')` و در جدول `settings` ذخیره می‌شوند.
  * هر فیلد کلید «نمایش» دارد: نام و نام خانوادگی، شمارهٔ تماس، رشته و میزان
    تحصیلات، امضای ولی دانش‌آموز، زمان/مکان، شمارهٔ نامه، تاریخ، متن و فهرست
    برنامه‌ها. فیلد خاموش **چاپ نمی‌شود** (نه اینکه فقط با CSS پنهان شود).
  * متن خراب «حضور و نظر شما، هرچند крат، …» به «کوتاه» اصلاح شد.
  * `includes/school_form_editor.php` (فایل تازه) ویرایشگر زندهٔ همان صفحه را
    می‌سازد: هر که چیزی بنویسد، پیش‌نمایش همان لحظه عوض می‌شود و «ذخیرهٔ
    پیش‌فرض» مقادیر را برای بار بعد نگه می‌دارد.

## ۴) رضایت‌نامهٔ اردو — همان رفتار ورودی‌محور

  * عنوان اردو، تاریخ، ساعت، مبدأ، مقصد، مدت، مبلغ و **توضیحات** از ورودی
    کاربر می‌آید (هر اردو متفاوت است) و هر کدام کلید نمایش جدا دارد.
  * جملهٔ متن از بخش‌های فعال ساخته می‌شود؛ با خاموش‌کردن یک فیلد، متن
    به‌هم‌ریخته نمی‌شود (نه «که در تاریخ» بی‌دنباله می‌ماند).
  * در سرصفحه به‌جای «تاریخ»، **سال تحصیلی** چاپ می‌شود.
  * امضای مدیر از وسط کادر توضیحات برداشته شد و به‌صورت overlay روی خط
    «امضای مدیر / مهر مدرسه» می‌افتد.

## آنچه عوض نمی‌شود

* مسیر نشست پنل، چاپ و همهٔ فرم‌های دیگر (فرم کتاب، …. ) دست‌نخورده است.
* هیچ مهاجرت داده‌ای لازم نیست؛ مقادیر فرم‌ها در همان جدول `settings` می‌نشینند
  و کارنامهٔ ماه در صورت نبودن خودکار ساخته می‌شود.
* `config/version.php` به `4.181.0` / `2.105.0` جلو رفت (metadata نصب؛ در فوتر
  رندر نمی‌شود). به `VERSIONED_CORRECTIVES` در `build-full-release.py` اضافه
  **نشده** است، چون آن فهرست زنجیرهٔ نصب کامل `Release_V1.1` را می‌سازد.
"""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 10, 16, 0, 0, 0)
SUMS = 'V4.181.0-SHA256SUMS.txt'

# (مسیر داخل بسته، مسیر منبع نسبت به ریشهٔ مخزن، نشانهٔ تغییر)
# نشانه، دقیقاً همان چیزی است که این بسته برای آن ساخته شده؛ اگر روزی کسی فایل
# را از سورس قدیمی بسته‌بندی کند، ساخت بسته با خطا می‌ایستد.
FILES = [
    ('config/version.php', 'update-v4.152.0/config/version.php', "4.181.0".encode()),
    ('grade-columns.php', 'update-v4.152.0/grade-columns.php', 'gc_save_cell'.encode()),
    ('includes/management_hub.php', 'update-v4.152.0/includes/management_hub.php', "'columns'=>['مدیریت ستونی',".encode()),
    ('includes/school_form_editor.php', 'update-v4.152.0/includes/school_form_editor.php', 'sfe_render'.encode()),
    ('includes/school_forms.php', 'update-v4.152.0/includes/school_forms.php', 'sf_camp_blocks'.encode()),
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
    ('MODIFIED-FILES-V4.181.0.zip', 'site-update-v4.152.0/', FILES),
    ('SchoolDesk-FIX-v2.105.0.zip', 'SchoolDeskPro/www/', FILES),
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
    # بسته‌های تاریخی ورودیِ تغییرناپذیرند — همان نگهبانِ release-v4.180.0.py
    before = {p: sha(p) for p in ROOT.glob('*.zip') if p.name not in names}
    result = []
    for name, prefix, pairs in PACKAGES:
        with ZipFile(ROOT / name, 'w', ZIP_DEFLATED, compresslevel=9) as z:
            for arc, src, marker in sorted(pairs):
                data = (ROOT / src).read_bytes()
                assert marker in data, 'فایل بدون تغییرات v4.181.0 است: ' + arc
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
