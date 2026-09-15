#!/usr/bin/env python3
"""Same-version, two-page-only printing hotfix. No scanner/roster/data payload."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib
ROOT = Path(__file__).resolve().parent.parent
PATCH = ROOT / 'update-v4.152.0'
FILES = ('attendance-tags.php', 'entry-cards.php')
ARCHIVES = {'SITE-FIX-v4.152.0-print-copies.zip': 'site-update-v4.152.0/',
            'SchoolDeskPro-FIX-v2.83.0-print-copies.zip': 'SchoolDeskPro/www/'}


def build():
    for archive, prefix in ARCHIVES.items():
        target = ROOT / archive
        with ZipFile(target, 'w', compression=ZIP_DEFLATED, compresslevel=9) as z:
            for name in FILES:
                info = ZipInfo(prefix + name, (2026, 9, 15, 0, 0, 0))
                info.compress_type = ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                z.writestr(info, (PATCH / name).read_bytes())
        with ZipFile(target) as z:
            assert z.testzip() is None
            assert set(z.namelist()) == {prefix + name for name in FILES}
            for name in FILES:
                assert z.read(prefix + name) == (PATCH / name).read_bytes()
        print(archive, target.stat().st_size, hashlib.sha256(target.read_bytes()).hexdigest())


if __name__ == '__main__':
    build()
