#!/usr/bin/env python3
"""v4.180.0 / v2.104.0 — «ویرایش آزمون» هم مثل «طراحی آزمون» با توکن امضاشده.

    python3 scripts/release-v4.180.0.py

بسته‌ها:
  MODIFIED-FILES-V4.180.0.zip   → سایت   (site-update-v4.152.0/…)
  SchoolDesk-FIX-v2.104.0.zip   → دسکتاپ (SchoolDeskPro/www/…)
                                (شمارهٔ v2.103.0 پیش‌تر برای بستهٔ ربات منتشر شده است)

## مشکلی که این بسته حل می‌کند

دکمهٔ «طراحی آزمون جدید» (کلاسی و پایه‌ای) از دیرباز با **توکن امضاشدهٔ طراحی**
(`dt`) به طراح زندهٔ آزمون می‌رفت و در هر پنجره‌ای کار می‌کرد. اما دکمه‌های
**ویرایش** از مسیر دیگری می‌رفتند:

  * «ویرایش آزمون کلاسی» → `class-exam-create.php`  (فقط با نشست پنل)
  * «طراحی پایه‌ای کلاسی» → `class-exam-group.php`  (فقط با نشست پنل)
  * «ویرایش آزمون ماهانه» → صفحه‌های پنل مدیریت      (فقط با نشست پنل)

در پنجرهٔ تازه — مرورگر داخلی بله یا پنجرهٔ جدایی که اپ دسکتاپ باز می‌کند —
نشست پنل وجود ندارد؛ پس همان دکمه‌ها به **صفحهٔ ورود** می‌افتادند، در حالی که
دکمه‌های طراحی بدون ورود کار می‌کردند. دقیقاً همان چیزی که کاربر گزارش کرد.

## راه‌حل

۱) `includes/functions.php` — دو تابع تازه:

   `make_class_exam_entry_token($teacherId,$year,$class,$subject,$grade,$examId,$ttl,$allowCreate)`
   و `verify_class_exam_entry_token($token,$match)`؛ امضای HMAC-SHA256 با کلید
   مستقل `class_exam_entry_secret` (تنظیمات سامانه)، بدنهٔ JSON (تا نام کلاس با
   نویسهٔ `|` توکن را خراب نکند)، انقضای ۲۴ ساعته، و پرچم `n` فقط برای دکمهٔ
   «طراحی آزمون جدید». توکن دست‌کاری‌شده، منقضی، یا مربوط به کلاس/درس/دبیر دیگر
   رد می‌شود.

۲) `class-exam-create.php` — توکن `ct` را می‌سنجد؛ با توکن معتبر **بدون نشست**
   هم آزمون موجود را باز می‌کند و هم (فقط اگر `n=1` باشد) آزمون تازه می‌سازد و
   مثل قبل به `exam-print.php?…&dt=…` می‌رود. GET بی‌توکن همچنان چیزی نمی‌سازد،
   پس پیش‌بارگذاری مرورگر داده را عوض نمی‌کند.

۳) `class-exam-group.php` + `includes/class_exam_group_choice.php` — مسیر طراحی
   پایه‌ای هم توکن می‌پذیرد و همان توکن داخل فرم پنجرهٔ انتخاب روش طراحی می‌رود،
   پس انتخاب «کپی/شروع از نو» هم در پنجرهٔ تازه کار می‌کند.

۴) `includes/teacher_class_exams.php` — توکن در هر سه دکمه: «ویرایش آزمون کلاسی»،
   «طراحی آزمون جدید» و «طراحی پایه‌ای کلاسی — پایه»، به‌همراه «ویرایش / چاپ این
   کلاس» برای اعضای گروه. برچسب‌ها دست‌نخورده‌اند (تست‌ها همان‌ها را می‌بینند).

۵) `exams.php` + `teacher-panel.php` — «ویرایش آزمون ماهانه» با توکن طراحی، هم در
   جدول برنامهٔ آزمون‌های ماهانه و هم در پنل دبیر.

## آنچه عوض **نمی‌شود**

* مسیر نشست پنل دقیقاً مثل قبل کار می‌کند؛ توکن یک راه دوم و امن است، نه جایگزین.
* هیچ جدول، ستون یا مهاجرت داده‌ای لازم نیست (کلیدها در جدول `settings` هستند و
  خودشان ساخته می‌شوند).
* `config/version.php` به `4.180.0` / `2.104.0` جلو رفت (metadata نصب؛ در فوتر
  رندر نمی‌شود). این بسته به `VERSIONED_CORRECTIVES` در `build-full-release.py`
  اضافه **نشده** است، چون آن فهرست زنجیرهٔ نصب کامل `Release_V1.1` را می‌سازد و
  دست‌زدن به آن بدون بازسازی همان بسته‌ها معنا ندارد — همان تصمیمی که
  `release-v4.179.0.py` هم گرفته است.
"""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 10, 15, 0, 0, 0)
SUMS = 'V4.180.0-SHA256SUMS.txt'

# (مسیر داخل بسته، مسیر منبع نسبت به ریشهٔ مخزن، نشانهٔ تغییر)
# نشانه، دقیقاً همان چیزی است که این بسته برای آن ساخته شده؛ اگر روزی کسی
# فایل را از سورس قدیمی بسته‌بندی کند، ساخت بسته با خطا می‌ایستد.
FILES = [
    ('class-exam-create.php', 'update-v4.152.0/class-exam-create.php', 'class_exam_entry_token'.encode()),
    ('class-exam-group.php', 'update-v4.152.0/class-exam-group.php', 'class_exam_entry_token'.encode()),
    ('config/version.php', 'update-v4.152.0/config/version.php', "4.180.0".encode()),
    ('exams.php', 'update-v4.152.0/exams.php', 'ویرایش آزمون ماهانه'.encode()),
    ('includes/class_exam_group_choice.php', 'update-v4.152.0/includes/class_exam_group_choice.php', 'class_exam_entry_token'.encode()),
    ('includes/functions.php', 'update-v4.152.0/includes/functions.php', 'make_class_exam_entry_token'.encode()),
    ('includes/teacher_class_exams.php', 'update-v4.152.0/includes/teacher_class_exams.php', 'class_exam_entry_token'.encode()),
    ('teacher-panel.php', 'update-v4.152.0/teacher-panel.php', 'ویرایش آزمون ماهانه'.encode()),
]
# هر هشت فایل بین سایت و دسکتاپ مشترک‌اند و بایت‌به‌بایت یکی هستند
# (مثل بستهٔ v4.179.0؛ `desktop-app-v2/patch/` نسخهٔ جدایی برای این‌ها ندارد).
PACKAGES = [
    ('MODIFIED-FILES-V4.180.0.zip', 'site-update-v4.152.0/', FILES),
    ('SchoolDesk-FIX-v2.104.0.zip', 'SchoolDeskPro/www/', FILES),
]


def sha(p):
    return hashlib.sha256(p.read_bytes()).hexdigest()


def build():
    names = [n for n, _, _ in PACKAGES]
    # بسته‌های تاریخی ورودیِ تغییرناپذیرند — همان نگهبانِ release-v4.179.0.py
    before = {p: sha(p) for p in ROOT.glob('*.zip') if p.name not in names}
    result = []
    for name, prefix, pairs in PACKAGES:
        with ZipFile(ROOT / name, 'w', ZIP_DEFLATED, compresslevel=9) as z:
            for arc, src, marker in sorted(pairs):
                data = (ROOT / src).read_bytes()
                assert marker in data, 'فایل بدون تغییرات v4.180.0 است: ' + arc
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
