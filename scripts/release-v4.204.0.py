#!/usr/bin/env python3
"""Build v4.204.0 site and v2.128.0 desktop form-encoded sync compatibility fixes."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 10, 27, 0, 0, 0)
SUMS = 'V4.204.0-SHA256SUMS.txt'
VERSION = ('config/version.php', 'update-v4.152.0/config/version.php')
SITE_FILES = [
    (*VERSION, b"'site_version'"),
    ('includes/desk_sync.php', 'update-v4.152.0/includes/desk_sync.php', b'application/x-www-form-urlencoded'),
    ('class-exam-sync-api.php', 'update-v4.152.0/class-exam-sync-api.php', b"$formPayload = $_POST['payload']"),
]
DESKTOP_FILES = [
    (*VERSION, b"'desktop_version'"),
    ('includes/desk_sync.php', 'desktop-app-v2/patch/includes-desk-sync.php', b'application/x-www-form-urlencoded'),
    ('class-exam-sync-api.php', 'update-v4.152.0/class-exam-sync-api.php', b"$formPayload = $_POST['payload']"),
]
PACKAGES = [
    ('MODIFIED-FILES-V4.204.0.zip', 'site-update-v4.152.0/', SITE_FILES),
    ('SchoolDesk-FIX-v2.128.0.zip', 'SchoolDeskPro/www/', DESKTOP_FILES),
]


def build():
    assert b'application/x-www-form-urlencoded' in (ROOT / 'desktop-app-v2/patch/includes-desk-sync.php').read_bytes()
    assert b"$formPayload = $_POST['payload']" in (ROOT / 'desktop-app-v2/server/desk-sync-api.php').read_bytes()
    output = []
    for name, prefix, files in PACKAGES:
        with ZipFile(ROOT / name, 'w', ZIP_DEFLATED, compresslevel=9) as z:
            for arc, source, marker in sorted(files):
                data = (ROOT / source).read_bytes()
                assert marker in data, f'marker missing in {arc}'
                if arc == 'config/version.php':
                    data = data.replace(b"'site_version'    => '4.203.0'", b"'site_version'    => '4.204.0'")
                    data = data.replace(b"'desktop_version' => '2.127.0'", b"'desktop_version' => '2.128.0'")
                    data = data.replace(b"'correction'      => 'V4.203.0'", b"'correction'      => 'V4.204.0'")
                info = ZipInfo(prefix + arc, STAMP)
                info.compress_type = ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                z.writestr(info, data)
        digest = hashlib.sha256((ROOT / name).read_bytes()).hexdigest()
        output.append((digest, name))
        print(f'{name}: {(ROOT / name).stat().st_size} bytes, SHA-256 {digest}')
    (ROOT / SUMS).write_text(''.join(f'{digest}  {name}\n' for digest, name in output), encoding='utf-8')
    print(f'Wrote {SUMS}')

if __name__ == '__main__':
    build()
