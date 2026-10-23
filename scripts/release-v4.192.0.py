#!/usr/bin/env python3
"""Build the v4.192.0 / v2.116.0 discipline and report-grade-transfer patches."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 10, 23, 0, 0, 0)
SUMS = 'V4.192.0-SHA256SUMS.txt'
SITE_FILES = [
    ('config/version.php', 'update-v4.152.0/config/version.php', b'4.192.0'),
    ('includes/management_hub.php', 'update-v4.152.0/includes/management_hub.php', 'مدیریت انضباطی'.encode()),
    ('discipline-report-management.php', 'update-v4.152.0/discipline-report-management.php', 'ثبت انضباط کارنامه'.encode()),
    ('report-grade-transfer.php', 'update-v4.152.0/report-grade-transfer.php', 'رونوشت نمرات'.encode()),
]
DESKTOP_FILES = [
    ('config/version.php', 'update-v4.152.0/config/version.php', b'4.192.0'),
    ('includes/management_hub.php', 'desktop-app-v2/patch/includes-management_hub.php', 'مدیریت انضباطی'.encode()),
    ('discipline-report-management.php', 'desktop-app-v2/patch/www-discipline-report-management.php', 'ثبت انضباط کارنامه'.encode()),
    ('report-grade-transfer.php', 'desktop-app-v2/patch/www-report-grade-transfer.php', 'رونوشت نمرات'.encode()),
]
PACKAGES = [
    ('MODIFIED-FILES-V4.192.0.zip', 'site-update-v4.152.0/', SITE_FILES),
    ('SchoolDesk-FIX-v2.116.0.zip', 'SchoolDeskPro/www/', DESKTOP_FILES),
]


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def build():
    pairs = [
        ('update-v4.152.0/includes/management_hub.php', 'desktop-app-v2/patch/includes-management_hub.php'),
        ('update-v4.152.0/discipline-report-management.php', 'desktop-app-v2/patch/www-discipline-report-management.php'),
        ('update-v4.152.0/report-grade-transfer.php', 'desktop-app-v2/patch/www-report-grade-transfer.php'),
    ]
    for site, desktop in pairs:
        assert sha(ROOT / site) == sha(ROOT / desktop), f'site/desktop mismatch: {site}'
    outputs = []
    for name, prefix, files in PACKAGES:
        with ZipFile(ROOT / name, 'w', ZIP_DEFLATED, compresslevel=9) as z:
            for arc, source, marker in sorted(files):
                data = (ROOT / source).read_bytes()
                assert marker in data, f'marker missing in {arc}'
                info = ZipInfo(prefix + arc, STAMP)
                info.compress_type = ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                z.writestr(info, data)
        digest = sha(ROOT / name)
        outputs.append((digest, name))
        print(f'{name}: {(ROOT / name).stat().st_size} bytes, SHA-256 {digest}')
    (ROOT / SUMS).write_text(''.join(f'{digest}  {name}\n' for digest, name in outputs), encoding='utf-8')
    print(f'Wrote {SUMS}; site and desktop files match.')


if __name__ == '__main__':
    build()
