#!/usr/bin/env python3
"""Same-version, scanner-only hotfixes. No backend, data, config or roster files.
The backup must be byte-for-byte the accepted v4.94 scanner. Run from any cwd.
"""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib
ROOT = Path(__file__).resolve().parent.parent
PATCH = ROOT / 'update-v4.152.0'
FILES = ('attendance-scanner.php', 'attendance-scanner-legacy.php',
         'assets/js/attendance-scanner-light.js', 'assets/js/attendance-decoder-worker.js')
ARCHIVES = {'SITE-FIX-v4.152.0-scanner.zip': 'site-update-v4.152.0/',
            'SchoolDeskPro-FIX-v2.83.0-scanner.zip': 'SchoolDeskPro/www/'}

def build():
    payload = {name: (PATCH / name).read_bytes() for name in FILES}
    backup = payload['attendance-scanner-legacy.php']
    assert backup == (ROOT / 'update-v4.94.0/attendance-scanner.php').read_bytes()
    assert hashlib.sha256(backup).hexdigest() == 'e64f8374c4ab8aaf23a00078d49ab9993bb12ce5ce33c599025265588b534d6a'
    for archive, prefix in ARCHIVES.items():
        target = ROOT / archive
        with ZipFile(target, 'w', compression=ZIP_DEFLATED, compresslevel=9) as z:
            for name, content in sorted(payload.items()):
                info = ZipInfo(prefix + name, (2026, 9, 15, 0, 0, 0))
                info.compress_type = ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                z.writestr(info, content)
        with ZipFile(target) as z:
            assert z.testzip() is None
            assert len(z.infolist()) == len(FILES)
            assert set(z.namelist()) == {prefix + name for name in FILES}
            for name in FILES:
                assert z.read(prefix + name) == payload[name]
        print(archive, target.stat().st_size, hashlib.sha256(target.read_bytes()).hexdigest())

if __name__ == '__main__':
    build()
