#!/usr/bin/env python3
"""v4.209.3 / v2.133.3 — نمایش «علت مسدودسازی» کارنامه در ربات (دانش‌آموز/اولیا).

    python3 scripts/release-v4.209.3.py

بسته‌ها:
  MODIFIED-FILES-V4.209.3.zip   -> سایت   (site-update-v4.152.0/...)
  SchoolDesk-FIX-v2.133.3.zip   -> دسکتاپ (SchoolDeskPro/reports/... و SchoolDeskPro/www/...)

محتوا:
  · includes/perf.php            هستهٔ کارایی (DDL یک‌بار، کش داده، باندل دارایی)
  · includes/db.php              آمار کوئری + حذف DDL تکراری + حافظهٔ خواندن
  · includes/header.php|footer   باندل CSS/JS (۱۱ درخواست → ۴)
  · includes/appearance.php      فونت WOFF2 تنها + کش هش + بازهٔ وزن
  · includes/functions.php       گزارش علتِ مسدودی کارنامه (report_lock_reason_for)
  · includes/bot_webhook_engine.php  ربات: نمایش علت مسدودی هنگام درخواست کارنامه
  · includes/bot_helpers.php     الگوهای «کارنامه مسدود است» + ساخت پیام علت
  · includes/school_roles.php    DDL دبیران یک‌بار در هر نسخه
  · includes/session_tracker.php DDL نشست‌ها یک‌بار در هر نسخه
  · includes/management_hub.php  لینک صفحهٔ کارایی
  · students.php                 فهرست بدون پنج زیرکوئری تجمعی در هر بازدید
  · performance.php              داشبورد کارایی + تنظیمات آمادهٔ سرور
  · perf-config/*                nginx / Apache / php.ini پیشنهادی
"""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 10, 30, 0, 0, 0)
SUMS = 'V4.209.3-SHA256SUMS.txt'

SITE_PAIRS = [
    ('api/docs.php', 'update-v4.209.0/api/docs.php'),
    ('doctor.php', 'update-v4.209.0/doctor.php'),
    ('assets/css/.htaccess', 'update-v4.209.0/assets/css/.htaccess'),
    ('assets/css/exam-designer-mobile.css', 'update-v4.152.0/assets/css/exam-designer-mobile.css'),
    ('assets/fonts/.htaccess', 'update-v4.209.0/assets/fonts/.htaccess'),
    ('assets/fonts/screen/Sahel.woff2', 'update-v4.152.0/assets/fonts/screen/Sahel.woff2'),
    ('assets/fonts/screen/Vazirmatn-Bold.woff2', 'update-v4.152.0/assets/fonts/screen/Vazirmatn-Bold.woff2'),
    ('assets/fonts/screen/Vazirmatn-Regular.woff2', 'update-v4.152.0/assets/fonts/screen/Vazirmatn-Regular.woff2'),
    ('assets/fonts/screen/Yekan.woff2', 'update-v4.152.0/assets/fonts/screen/Yekan.woff2'),
    ('assets/fonts/screen/manifest.json', 'update-v4.152.0/assets/fonts/screen/manifest.json'),
    ('assets/js/.htaccess', 'update-v4.209.0/assets/js/.htaccess'),
    ('attendance-scanner.php', 'update-v4.209.0/attendance-scanner.php'),
    ('bulk-print.php', 'update-v4.209.0/bulk-print.php'),
    ('config/version.php', 'update-v4.209.0/config/version.php'),
    ('desk-sync-api.php', 'update-v4.152.0/includes/desk_sync_endpoint.php'),
    ('exam-bank-import.php', 'update-v4.209.0/exam-bank-import.php'),
    ('exam-print.php', 'update-v4.209.0/exam-print.php'),
    ('includes/appearance.php', 'update-v4.209.0/includes/appearance.php'),
    ('includes/bot_helpers.php', 'update-v4.209.0/includes/bot_helpers.php'),
    ('includes/bot_webhook_engine.php', 'update-v4.209.0/includes/bot_webhook_engine.php'),
    ('includes/db.php', 'update-v4.209.0/includes/db.php'),
    ('includes/fatal_guard.php', 'update-v4.209.0/includes/fatal_guard.php'),
    ('includes/footer.php', 'update-v4.209.0/includes/footer.php'),
    ('includes/functions.php', 'update-v4.209.0/includes/functions.php'),
    ('includes/header.php', 'update-v4.209.0/includes/header.php'),
    ('includes/management_hub.php', 'update-v4.209.0/includes/management_hub.php'),
    ('includes/perf.php', 'update-v4.209.0/includes/perf.php'),
    ('includes/perf_fallback.php', 'update-v4.209.0/includes/perf_fallback.php'),
    ('includes/school_roles.php', 'update-v4.209.0/includes/school_roles.php'),
    ('includes/session_tracker.php', 'update-v4.209.0/includes/session_tracker.php'),
    ('performance.php', 'update-v4.209.0/performance.php'),
    ('perf-config/.htaccess', 'update-v4.209.0/perf-config/.htaccess'),
    ('perf-config/apache-performance.htaccess', 'update-v4.209.0/perf-config/apache-performance.htaccess'),
    ('perf-config/nginx-performance.conf', 'update-v4.209.0/perf-config/nginx-performance.conf'),
    ('perf-config/README-FA.txt', 'update-v4.209.0/perf-config/README-FA.txt'),
    ('perf-config/php-performance.ini', 'update-v4.209.0/perf-config/php-performance.ini'),
    ('report-print.php', 'update-v4.209.0/report-print.php'),
    ('students.php', 'update-v4.209.0/students.php'),
]

DESKTOP_BASE_PAIRS = [
    ('assets/css/exam-designer-mobile.css', 'desktop-app-v2/patch/www-assets-css-exam-designer-mobile.css'),
    ('assets/fonts/screen/Sahel.woff2', 'update-v4.152.0/assets/fonts/screen/Sahel.woff2'),
    ('assets/fonts/screen/Vazirmatn-Bold.woff2', 'update-v4.152.0/assets/fonts/screen/Vazirmatn-Bold.woff2'),
    ('assets/fonts/screen/Vazirmatn-Regular.woff2', 'update-v4.152.0/assets/fonts/screen/Vazirmatn-Regular.woff2'),
    ('assets/fonts/screen/Yekan.woff2', 'update-v4.152.0/assets/fonts/screen/Yekan.woff2'),
    ('assets/fonts/screen/manifest.json', 'update-v4.152.0/assets/fonts/screen/manifest.json'),
    ('config/version.php', 'update-v4.209.0/config/version.php'),
    ('desk-prepend.php', 'desktop-app-v2/patch/www-desk-prepend.php'),
    ('desk-sync-api.php', 'update-v4.152.0/includes/desk_sync_endpoint.php'),
    ('includes/appearance.php', 'desktop-app-v2/patch/includes-appearance.php'),
    ('includes/bot_helpers.php', 'desktop-app-v2/patch/includes-bot_helpers.php'),
    ('includes/db.php', 'release-v1.0/common/includes/db.php'),
    ('includes/footer.php', 'desktop-app-v2/patch/includes-footer.php'),
    ('includes/functions.php', 'update-v4.209.0/includes/functions.php'),
    ('includes/header.php', 'desktop-app-v2/patch/includes-header.php'),
    ('includes/management_hub.php', 'desktop-app-v2/patch/includes-management_hub.php'),
    ('includes/perf.php', 'desktop-app-v2/patch/includes-perf.php'),
    ('includes/perf_fallback.php', 'desktop-app-v2/patch/includes-perf_fallback.php'),
    ('includes/school_roles.php', 'desktop-app-v2/patch/includes-school_roles.php'),
    ('includes/session_tracker.php', 'desktop-app-v2/patch/includes-session_tracker.php'),
    ('performance.php', 'desktop-app-v2/patch/www-performance.php'),
    ('perf-config/.htaccess', 'update-v4.209.0/perf-config/.htaccess'),
    ('perf-config/apache-performance.htaccess', 'update-v4.209.0/perf-config/apache-performance.htaccess'),
    ('perf-config/nginx-performance.conf', 'update-v4.209.0/perf-config/nginx-performance.conf'),
    ('perf-config/README-FA.txt', 'update-v4.209.0/perf-config/README-FA.txt'),
    ('perf-config/php-performance.ini', 'update-v4.209.0/perf-config/php-performance.ini'),
    ('students.php', 'desktop-app-v2/patch/www-students.php'),
]

DESKTOP_PAIRS = (
    [('reports/' + arc, src) for arc, src in DESKTOP_BASE_PAIRS]
    + [('www/' + arc, src) for arc, src in DESKTOP_BASE_PAIRS]
)

PACKAGES = [
    ('MODIFIED-FILES-V4.209.3.zip', 'site-update-v4.152.0/', SITE_PAIRS),
    ('SchoolDesk-FIX-v2.133.3.zip', 'SchoolDeskPro/',        DESKTOP_PAIRS),
]


def sha(p: Path) -> str:
    return hashlib.sha256(p.read_bytes()).hexdigest()


def build() -> None:
    names = {n for n, _, _ in PACKAGES}
    before = {p: sha(p) for p in ROOT.glob('*.zip') if p.name not in names}
    result = []
    for name, prefix, pairs in PACKAGES:
        out_zip = ROOT / name
        with ZipFile(out_zip, 'w', ZIP_DEFLATED, compresslevel=9) as z:
            for arc, src in sorted(pairs):
                data = (ROOT / src).read_bytes()
                info = ZipInfo(prefix + arc, STAMP)
                info.compress_type = ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                z.writestr(info, data)
                print('  ' + prefix + arc, len(data))
        entry = {
            'name': name,
            'bytes': out_zip.stat().st_size,
            'sha256': sha(out_zip),
            'files': sorted(a for a, _ in pairs),
        }
        result.append(entry)
        print(name, entry['bytes'], entry['sha256'])
    for p, h in before.items():
        assert sha(p) == h, 'Historical archive modified: ' + p.name
    (ROOT / SUMS).write_text(
        ''.join(x['sha256'] + '  ' + x['name'] + '\n' for x in result),
        encoding='utf-8',
    )
    print('Historical archives verified unchanged:', len(before))


if __name__ == '__main__':
    build()
