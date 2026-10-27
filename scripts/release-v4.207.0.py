#!/usr/bin/env python3
"""v4.207.0 / v2.131.0 — ۵ بهبود جامع (رتبه پایه، برش ۱۰۰٪ در ویرایشگر زنده آزمون،
مسدودسازی پیشینی کارنامه ماه، خودکارسازی همگام‌سازی و الزام همگام‌سازی اولیه دسکتاپ،
و صفحه وضعیت و اطلاعات همگام‌سازی در پنل سایت).

    python3 scripts/release-v4.207.0.py

بسته‌ها:
  MODIFIED-FILES-V4.207.0.zip   -> سایت   (site-update-v4.152.0/...)
  SchoolDesk-FIX-v2.131.0.zip   -> دسکتاپ (SchoolDeskPro/reports/... و SchoolDeskPro/www/...)
"""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 10, 27, 0, 0, 0)
SUMS = 'V4.207.0-SHA256SUMS.txt'

SITE_PAIRS = [
    ('.htaccess', 'release-v1.0/common/.htaccess'),
    ('admin-ajax.php', 'update-v4.152.0/admin-ajax.php'),
    ('assets/css/exam-designer-mobile.css', 'update-v4.152.0/assets/css/exam-designer-mobile.css'),
    ('bulk-print.php', 'update-v4.152.0/bulk-print.php'),
    ('class-exam-sync-api.php', 'update-v4.152.0/class-exam-sync-api.php'),
    ('config/version.php', 'update-v4.152.0/config/version.php'),
    ('csrf-refresh.php', 'update-v4.152.0/csrf-refresh.php'),
    ('desk-connection-status.php', 'update-v4.152.0/desk-connection-status.php'),
    ('desk-sync-api.php', 'update-v4.152.0/includes/desk_sync_endpoint.php'),
    ('desk-sync.php', 'update-v4.152.0/desk-sync.php'),
    ('desk-update-api.php', 'update-v4.152.0/desk-update-api.php'),
    ('discipline-report-management.php', 'update-v4.152.0/discipline-report-management.php'),
    ('exam-print.php', 'update-v4.152.0/exam-print.php'),
    ('export-pdf.php', 'update-v4.152.0/export-pdf.php'),
    ('grade-columns.php', 'update-v4.152.0/grade-columns.php'),
    ('includes/bot_webhook_engine.php', 'update-v4.152.0/includes/bot_webhook_engine.php'),
    ('includes/desk_sync.php', 'update-v4.152.0/includes/desk_sync.php'),
    ('includes/desk_sync_endpoint.php', 'update-v4.152.0/includes/desk_sync_endpoint.php'),
    ('includes/desk_updates_store.php', 'update-v4.152.0/includes/desk_updates_store.php'),
    ('includes/functions.php', 'update-v4.152.0/includes/functions.php'),
    ('includes/header.php', 'update-v4.152.0/includes/header.php'),
    ('includes/report_image.php', 'update-v4.152.0/includes/report_image.php'),
    ('report-print.php', 'update-v4.152.0/report-print.php'),
    ('report-view.php', 'update-v4.152.0/report-view.php'),
    ('reports.php', 'update-v4.152.0/reports.php'),
    ('student-panel.php', 'update-v4.152.0/student-panel.php'),
    ('teacher-panel.php', 'update-v4.152.0/teacher-panel.php'),
]

DESKTOP_BASE_PAIRS = [
    ('admin-ajax.php', 'update-v4.152.0/admin-ajax.php'),
    ('assets/css/exam-designer-mobile.css', 'desktop-app-v2/patch/www-assets-css-exam-designer-mobile.css'),
    ('bulk-print.php', 'update-v4.152.0/bulk-print.php'),
    ('class-exam-sync-api.php', 'update-v4.152.0/class-exam-sync-api.php'),
    ('config/version.php', 'update-v4.152.0/config/version.php'),
    ('csrf-refresh.php', 'update-v4.152.0/csrf-refresh.php'),
    ('desk-connection-status.php', 'update-v4.152.0/desk-connection-status.php'),
    ('desk-prepend.php', 'desktop-app-v2/patch/www-desk-prepend.php'),
    ('desk-sync-api.php', 'update-v4.152.0/includes/desk_sync_endpoint.php'),
    ('desk-sync-daemon.php', 'desktop-app-v2/patch/www-desk-sync-daemon.php'),
    ('desk-sync.php', 'desktop-app-v2/patch/www-desk-sync.php'),
    ('desk-update-api.php', 'update-v4.152.0/desk-update-api.php'),
    ('discipline-report-management.php', 'desktop-app-v2/patch/www-discipline-report-management.php'),
    ('exam-print.php', 'desktop-app-v2/patch/www-exam-print.php'),
    ('export-pdf.php', 'update-v4.152.0/export-pdf.php'),
    ('grade-columns.php', 'desktop-app-v2/patch/www-grade-columns.php'),
    ('includes/bot_webhook_engine.php', 'update-v4.152.0/includes/bot_webhook_engine.php'),
    ('includes/desk_sync.php', 'desktop-app-v2/patch/includes-desk-sync.php'),
    ('includes/desk_sync_endpoint.php', 'desktop-app-v2/patch/includes-desk_sync_endpoint.php'),
    ('includes/desk_update.php', 'desktop-app-v2/patch/includes-desk_update.php'),
    ('includes/desk_updates_store.php', 'update-v4.152.0/includes/desk_updates_store.php'),
    ('includes/footer.php', 'desktop-app-v2/patch/includes-footer.php'),
    ('includes/functions.php', 'update-v4.152.0/includes/functions.php'),
    ('includes/header.php', 'desktop-app-v2/patch/includes-header.php'),
    ('includes/report_image.php', 'update-v4.152.0/includes/report_image.php'),
    ('report-print.php', 'update-v4.152.0/report-print.php'),
    ('report-view.php', 'update-v4.152.0/report-view.php'),
    ('reports.php', 'desktop-app-v2/patch/www-reports.php'),
    ('student-panel.php', 'update-v4.152.0/student-panel.php'),
    ('teacher-panel.php', 'desktop-app-v2/patch/www-teacher-panel.php'),
]

DESKTOP_PAIRS = (
    [('reports/' + arc, src) for arc, src in DESKTOP_BASE_PAIRS]
    + [('www/' + arc, src) for arc, src in DESKTOP_BASE_PAIRS]
)

PACKAGES = [
    ('MODIFIED-FILES-V4.207.0.zip', 'site-update-v4.152.0/', SITE_PAIRS),
    ('SchoolDesk-FIX-v2.131.0.zip', 'SchoolDeskPro/',        DESKTOP_PAIRS),
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
