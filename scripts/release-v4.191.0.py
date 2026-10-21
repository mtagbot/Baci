#!/usr/bin/env python3
"""Build the v4.191.0 / v2.115.0 correction packages reproducibly.

Hides the teacher's entire class-exam design section when the school-wide
switch is off, reorders the monthly-exam table, and adds safe server-layer
403 diagnostics to both sync clients.
"""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 10, 22, 0, 0, 0)
SUMS = 'V4.191.0-SHA256SUMS.txt'

SITE_FILES = [
    ('config/version.php', 'update-v4.152.0/config/version.php', b'4.191.0'),
    ('teacher-panel.php', 'update-v4.152.0/teacher-panel.php', b'teacherMonthlyExams'),
    ('includes/desk_sync.php', 'update-v4.152.0/includes/desk_sync.php', b'diagnose403Response'),
]
DESKTOP_FILES = [
    ('config/version.php', 'update-v4.152.0/config/version.php', b'4.191.0'),
    ('teacher-panel.php', 'desktop-app-v2/patch/www-teacher-panel.php', b'teacherMonthlyExams'),
    ('includes/desk_sync.php', 'desktop-app-v2/patch/includes-desk-sync.php', b'diagnose403Response'),
]

PACKAGES = [
    ('MODIFIED-FILES-V4.191.0.zip', 'site-update-v4.152.0/', SITE_FILES),
    ('SchoolDesk-FIX-v2.115.0.zip', 'SchoolDeskPro/www/', DESKTOP_FILES),
]


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def build():
    site_panel = ROOT / 'update-v4.152.0/teacher-panel.php'
    desk_panel = ROOT / 'desktop-app-v2/patch/www-teacher-panel.php'
    assert sha(site_panel) == sha(desk_panel), 'site/desktop teacher-panel mismatch'
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
    print(f'Wrote {SUMS}; site/desktop teacher panels match.')


if __name__ == '__main__':
    build()
