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
    deliveries = [(name, prefix, payload) for name, prefix in ARCHIVES.items()]
    report_fix = {name: payload[name] for name in ('includes/docx_school_list.php', 'reports-lists.php')}
    center_fix = {'includes/docx_school_list.php': payload['includes/docx_school_list.php']}
    deliveries.extend([
        ('SITE-FIX-v4.152.0-print-options.zip', 'site-update-v4.152.0/', report_fix),
        ('SchoolDeskPro-FIX-v2.83.0-print-options.zip', 'SchoolDeskPro/www/', report_fix),
        ('SITE-FIX-v4.152.0-center-year.zip', 'site-update-v4.152.0/', center_fix),
        ('SchoolDeskPro-FIX-v2.83.0-center-year.zip', 'SchoolDeskPro/www/', center_fix),
        ('SITE-FIX-v4.152.0-one-page-a4-a3.zip', 'site-update-v4.152.0/', report_fix),
        ('SchoolDeskPro-FIX-v2.83.0-one-page-a4-a3.zip', 'SchoolDeskPro/www/', report_fix),
        ('SITE-FIX-v4.152.0-a4-paper.zip', 'site-update-v4.152.0/', report_fix),
        ('SchoolDeskPro-FIX-v2.83.0-a4-paper.zip', 'SchoolDeskPro/www/', report_fix),
        ('SITE-FIX-v4.152.0-roster-layouts.zip', 'site-update-v4.152.0/', report_fix),
        ('SchoolDeskPro-FIX-v2.83.0-roster-layouts.zip', 'SchoolDeskPro/www/', report_fix),
        ('SITE-FIX-v4.152.0-single-line-names.zip', 'site-update-v4.152.0/', report_fix),
        ('SchoolDeskPro-FIX-v2.83.0-single-line-names.zip', 'SchoolDeskPro/www/', report_fix),
        ('SITE-FIX-v4.152.0-name-spacing.zip', 'site-update-v4.152.0/', report_fix),
        ('SchoolDeskPro-FIX-v2.83.0-name-spacing.zip', 'SchoolDeskPro/www/', report_fix),
    ])
    for archive, prefix, files in deliveries:
        target = ROOT / archive
        with ZipFile(target, 'w', compression=ZIP_DEFLATED, compresslevel=9) as z:
            for name, content in sorted(files.items()):
                info = ZipInfo(prefix + name, STAMP)
                info.compress_type = ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                z.writestr(info, content)
        with ZipFile(target) as z:
            assert z.testzip() is None
            assert set(z.namelist()) == {prefix + name for name in files}
            assert len(z.infolist()) == len(files)
            for name, content in files.items():
                assert z.read(prefix + name) == content
        print(archive, target.stat().st_size, hashlib.sha256(target.read_bytes()).hexdigest())


if __name__ == '__main__':
    build()
