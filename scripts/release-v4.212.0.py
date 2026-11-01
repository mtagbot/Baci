#!/usr/bin/env python3
"""v4.212.0 / v2.136.0 — پنل دانش‌آموز: حذف نام دبیر از برنامهٔ هفتگی و برنامهٔ امتحانی.

    python3 scripts/release-v4.212.0.py

بسته‌ها:
  MODIFIED-FILES-V4.212.0.zip   -> سایت   (site-update-v4.152.0/...)
  SchoolDesk-FIX-v2.136.0.zip   -> دسکتاپ (SchoolDeskPro/reports/... و SchoolDeskPro/www/...)

محتوا (این نسخه):
  · student-schedule.php         برنامهٔ هفتگی فقط نام درس را نشان می‌دهد (نام دبیر حذف شد)
  · student-exam-schedule.php    ستون «دبیر» از جدول برنامهٔ امتحانی حذف شد
  · config/version.php           مهر نسخه 4.212.0 / 2.136.0
  · includes/perf.php            نسخهٔ هستهٔ کارایی برای تازه‌سازی نام باندل‌ها
  · doctor.php                   عیب‌یاب با شمارهٔ نسخهٔ تازه

محتوا (انباشته از نسخه‌های پیش):
  · includes/functions.php       الگوی مرجع نام دبیر (teacher_name_parts / canonical / respectful)
  · import-teachers.php          فهرست دبیران با نام کامل مرتب + رفع جابه‌جایی فرم ویرایش
  · exams.php / classes.php / grade-entry-management.php   همان الگو در صفحه‌های مدیریتی
  · includes/exams_helper.php    برچسب دبیر در برنامهٔ امتحانی + ماهِ مؤثر
  · includes/student_nav.php     منوی کاشی دانش‌آموز + شمارنده‌ها
  · student-panel / student-discipline / student-reports   صفحه‌های مستقل پنل دانش‌آموز
  · index.php / report-view.php  صفحهٔ نخست دانش‌آموز = برنامه هفتگی + بازگشت از کارنامه
  · includes/perf.php            هستهٔ کارایی (DDL یک‌بار، کش داده، باندل دارایی)
  · includes/functions.php       لایه‌های مسدودسازی (گروهی/فردی) + علت مسدودی
  · includes/db.php              آمار کوئری + حافظهٔ خواندن
  · includes/header.php|footer   باندل CSS/JS (۱۱ درخواست → ۴)
  · includes/appearance.php      فونت WOFF2 تنها + کش هش
  · includes/bot_webhook_engine.php / bot_helpers.php   نمایش علت مسدودی کارنامه در ربات
  · includes/school_roles.php / session_tracker.php      DDL یک‌بار در هر نسخه
  · students.php / teacher-panel / grade-columns / discipline-report-management
  · staff-student-file / student-modal / export-excel / report_image / report-grade-transfer
  · performance.php / perf-config/*                     داشبورد کارایی + تنظیمات آمادهٔ سرور
"""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 11, 1, 0, 0, 0)
SUMS = 'V4.212.0-SHA256SUMS.txt'

SITE_PAIRS = [
    ('api/docs.php', 'update-v4.209.0/api/docs.php'),
    ('doctor.php', 'update-v4.212.0/doctor.php'),
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
    ('config/version.php', 'update-v4.212.0/config/version.php'),
    ('desk-sync-api.php', 'update-v4.152.0/includes/desk_sync_endpoint.php'),
    ('includes/desk_sync.php', 'update-v4.152.0/includes/desk_sync.php'),
    ('exam-bank-import.php', 'update-v4.209.0/exam-bank-import.php'),
    ('index.php', 'update-v4.210.0/index.php'),
    ('report-view.php', 'update-v4.210.0/report-view.php'),
    ('exam-print.php', 'update-v4.209.0/exam-print.php'),
    ('includes/appearance.php', 'update-v4.209.0/includes/appearance.php'),
    ('includes/bot_helpers.php', 'update-v4.209.0/includes/bot_helpers.php'),
    ('includes/bot_webhook_engine.php', 'update-v4.209.0/includes/bot_webhook_engine.php'),
    ('includes/report_image.php', 'update-v4.209.0/includes/report_image.php'),
    ('includes/report_import_wizard.php', 'update-v4.209.0/includes/report_import_wizard.php'),
    ('admin-ajax.php', 'update-v4.209.0/admin-ajax.php'),
    ('discipline-report-management.php', 'update-v4.209.0/discipline-report-management.php'),
    ('export-excel.php', 'update-v4.209.0/export-excel.php'),
    ('grade-columns.php', 'update-v4.209.0/grade-columns.php'),
    ('report-grade-transfer.php', 'update-v4.209.0/report-grade-transfer.php'),
    ('reports.php', 'update-v4.209.0/reports.php'),
    ('staff-student-file.php', 'update-v4.209.0/staff-student-file.php'),
    ('student-modal.php', 'update-v4.209.0/student-modal.php'),
    ('teacher-panel.php', 'update-v4.209.0/teacher-panel.php'),
    ('includes/db.php', 'update-v4.209.0/includes/db.php'),
    ('includes/fatal_guard.php', 'update-v4.209.0/includes/fatal_guard.php'),
    ('includes/footer.php', 'update-v4.209.0/includes/footer.php'),
    ('exams.php', 'update-v4.211.0/exams.php'),
    ('import-teachers.php', 'update-v4.211.0/import-teachers.php'),
    ('classes.php', 'update-v4.211.0/classes.php'),
    ('grade-entry-management.php', 'update-v4.211.0/grade-entry-management.php'),
    ('includes/exams_helper.php', 'update-v4.211.0/includes/exams_helper.php'),
    ('includes/student_nav.php', 'update-v4.210.0/includes/student_nav.php'),
    ('student-panel.php', 'update-v4.210.0/student-panel.php'),
    ('student-schedule.php', 'update-v4.212.0/student-schedule.php'),
    ('student-exam-schedule.php', 'update-v4.212.0/student-exam-schedule.php'),
    ('student-discipline.php', 'update-v4.210.0/student-discipline.php'),
    ('student-reports.php', 'update-v4.210.0/student-reports.php'),
    ('includes/functions.php', 'update-v4.211.0/includes/functions.php'),
    ('includes/header.php', 'update-v4.210.0/includes/header.php'),
    ('includes/management_hub.php', 'update-v4.209.0/includes/management_hub.php'),
    ('includes/perf.php', 'update-v4.212.0/includes/perf.php'),
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
    ('config/version.php', 'update-v4.212.0/config/version.php'),
    ('index.php', 'desktop-app-v2/patch/www-index.php'),
    ('report-view.php', 'desktop-app-v2/patch/www-report-view.php'),
    ('desk-prepend.php', 'desktop-app-v2/patch/www-desk-prepend.php'),
    ('desk-sync-api.php', 'update-v4.152.0/includes/desk_sync_endpoint.php'),
    ('includes/desk_sync.php', 'desktop-app-v2/patch/includes-desk-sync.php'),
    ('includes/appearance.php', 'desktop-app-v2/patch/includes-appearance.php'),
    ('includes/bot_helpers.php', 'desktop-app-v2/patch/includes-bot_helpers.php'),
    ('includes/report_image.php', 'desktop-app-v2/patch/includes-report_image.php'),
    ('admin-ajax.php', 'desktop-app-v2/patch/www-admin-ajax.php'),
    ('discipline-report-management.php', 'desktop-app-v2/patch/www-discipline-report-management.php'),
    ('exam-print.php', 'desktop-app-v2/patch/www-exam-print.php'),
    ('export-excel.php', 'desktop-app-v2/patch/www-export-excel.php'),
    ('grade-columns.php', 'desktop-app-v2/patch/www-grade-columns.php'),
    ('report-grade-transfer.php', 'desktop-app-v2/patch/www-report-grade-transfer.php'),
    ('reports.php', 'desktop-app-v2/patch/www-reports.php'),
    ('staff-student-file.php', 'desktop-app-v2/patch/www-staff-student-file.php'),
    ('student-modal.php', 'desktop-app-v2/patch/www-student-modal.php'),
    ('teacher-panel.php', 'desktop-app-v2/patch/www-teacher-panel.php'),
    ('includes/db.php', 'release-v1.0/common/includes/db.php'),
    ('includes/footer.php', 'desktop-app-v2/patch/includes-footer.php'),
    ('exams.php', 'update-v4.211.0/exams.php'),
    ('import-teachers.php', 'desktop-app-v2/patch/www-import-teachers.php'),
    ('classes.php', 'update-v4.211.0/classes.php'),
    ('grade-entry-management.php', 'desktop-app-v2/patch/www-grade-entry-management.php'),
    ('includes/exams_helper.php', 'desktop-app-v2/patch/includes-exams_helper.php'),
    ('includes/student_nav.php', 'update-v4.210.0/includes/student_nav.php'),
    ('student-panel.php', 'update-v4.210.0/student-panel.php'),
    ('student-schedule.php', 'update-v4.212.0/student-schedule.php'),
    ('student-exam-schedule.php', 'update-v4.212.0/student-exam-schedule.php'),
    ('student-discipline.php', 'update-v4.210.0/student-discipline.php'),
    ('student-reports.php', 'update-v4.210.0/student-reports.php'),
    ('includes/functions.php', 'desktop-app-v2/patch/includes-functions.php'),
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
    ('MODIFIED-FILES-V4.212.0.zip', 'site-update-v4.152.0/', SITE_PAIRS),
    ('SchoolDesk-FIX-v2.136.0.zip', 'SchoolDeskPro/',        DESKTOP_PAIRS),
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
