#!/usr/bin/env python3
"""Rebuild the current release as patch-only archives (no version bump).
Usage: python3 scripts/release-rosters.py
Both platforms ship exactly the four required changed/new application files.
Installation instructions live outside the ZIPs in docs/RELEASE-v4.152.0-FA.md.
"""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parent.parent
PATCH = ROOT / 'update-v4.152.0'
STAMP = (2026, 9, 15, 0, 0, 0)
FILES = (
    'reports-lists.php',
    'includes/docx_class_list.php',
    'includes/docx_school_list.php',
    'assets/templates/school-students.docx',
)
ARCHIVES = {
    'MODIFIED-FILES-v4.152.0.zip': 'update-v4.152.0/',
    'SITE-UPDATE-v4.152.0.zip': 'site-update-v4.152.0/',
    # Keep the previously delivered URL, but this is now a PATCH, not a full app.
    'SchoolDeskPro-v2.83.0-win64.zip': 'SchoolDeskPro/www/',
}


def build():
    payload = {name: (PATCH / name).read_bytes() for name in FILES}
    for archive, prefix in ARCHIVES.items():
        target = ROOT / archive
        with ZipFile(target, 'w', compression=ZIP_DEFLATED, compresslevel=9) as z:
            for name, content in sorted(payload.items()):
                info = ZipInfo(prefix + name, STAMP)
                info.compress_type = ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                z.writestr(info, content)
        with ZipFile(target) as z:
            assert z.testzip() is None
            assert set(z.namelist()) == {prefix + name for name in FILES}
            assert len(z.infolist()) == len(FILES)
            for name, content in payload.items():
                assert z.read(prefix + name) == content
        print(archive, target.stat().st_size, hashlib.sha256(target.read_bytes()).hexdigest())


if __name__ == '__main__':
    build()
