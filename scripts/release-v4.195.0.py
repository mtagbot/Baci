#!/usr/bin/env python3
"""Build the v4.195.0 site and v2.119.0 desktop compact-filter patches."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 10, 23, 0, 0, 0)
SITE_VERSION = b'4.195.0'
DESKTOP_VERSION = b'2.119.0'
SUMS = 'V4.195.0-SHA256SUMS.txt'
PAGES = [
    'analytics.php',
    'grade-entry-management.php',
    'reports.php',
    'exams.php',
    'online-exams.php',
    'discipline-report-management.php',
    'report-grade-transfer.php',
]
SITE_FILES = [('config/version.php', 'update-v4.152.0/config/version.php', SITE_VERSION)]
DESKTOP_FILES = [('config/version.php', 'update-v4.152.0/config/version.php', DESKTOP_VERSION)]
for page in PAGES:
    SITE_FILES.append((page, f'update-v4.152.0/{page}', b'compact-filter-grid'))
    DESKTOP_FILES.append((page, f'desktop-app-v2/patch/www-{page}', b'compact-filter-grid'))
SITE_FILES.append(('assets/css/management-hub.css', 'update-v4.152.0/assets/css/management-hub.css', b'compact-filter-grid'))
DESKTOP_FILES.append(('assets/css/management-hub.css', 'desktop-app-v2/patch/www-assets-css-management-hub.css', b'compact-filter-grid'))
PACKAGES = [
    ('MODIFIED-FILES-V4.195.0.zip', 'site-update-v4.152.0/', SITE_FILES),
    ('SchoolDesk-FIX-v2.119.0.zip', 'SchoolDeskPro/www/', DESKTOP_FILES),
]


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def build():
    for page in PAGES:
        assert sha(ROOT / f'update-v4.152.0/{page}') == sha(ROOT / f'desktop-app-v2/patch/www-{page}'), f'site/desktop mismatch: {page}'
    assert sha(ROOT / 'update-v4.152.0/assets/css/management-hub.css') == sha(ROOT / 'desktop-app-v2/patch/www-assets-css-management-hub.css'), 'site/desktop CSS mismatch'
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
    print(f'Wrote {SUMS}; site/desktop page and CSS files match.')


if __name__ == '__main__':
    build()
