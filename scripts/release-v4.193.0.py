#!/usr/bin/env python3
"""Build the v4.193.0 / v2.117.0 discipline-management refinement patches."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 10, 23, 0, 0, 0)
SUMS = 'V4.193.0-SHA256SUMS.txt'
SITE_FILES = [
    ('config/version.php', 'update-v4.152.0/config/version.php', b'4.193.0'),
    ('discipline-report-management.php', 'update-v4.152.0/discipline-report-management.php', 'drmRepeatScore'.encode()),
]
DESKTOP_FILES = [
    ('config/version.php', 'update-v4.152.0/config/version.php', b'4.193.0'),
    ('discipline-report-management.php', 'desktop-app-v2/patch/www-discipline-report-management.php', 'drmRepeatScore'.encode()),
]
PACKAGES = [
    ('MODIFIED-FILES-V4.193.0.zip', 'site-update-v4.152.0/', SITE_FILES),
    ('SchoolDesk-FIX-v2.117.0.zip', 'SchoolDeskPro/www/', DESKTOP_FILES),
]


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def build():
    assert sha(ROOT / 'update-v4.152.0/discipline-report-management.php') == sha(ROOT / 'desktop-app-v2/patch/www-discipline-report-management.php'), 'site/desktop discipline pages differ'
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
    print(f'Wrote {SUMS}; site and desktop discipline pages match.')


if __name__ == '__main__':
    build()
