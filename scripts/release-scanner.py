#!/usr/bin/env python3
"""Same-version, scanner-only hotfixes. No backend, data, config or roster files.
The backup must be byte-for-byte the accepted v4.94 scanner. Run from any cwd.
"""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib
import json
ROOT = Path(__file__).resolve().parent.parent
PATCH = ROOT / 'update-v4.152.0'
FILES = ('attendance-scanner.php', 'attendance-scanner-legacy.php',
         'assets/js/attendance-scanner-light.js', 'assets/js/attendance-decoder-worker.js')
ARCHIVES = {'SITE-FIX-v4.152.0-scanner.zip': 'site-update-v4.152.0/',
            # The desktop web root was renamed www -> reports; the desktop updater
            # accepts both, reports/ is what a migrated machine expects.
            'SchoolDeskPro-FIX-v2.83.0-scanner.zip': 'SchoolDeskPro/reports/'}
# Same payload, in the shape the desktop app can install ONLINE from the school
# site (see docs/DESKTOP-ONLINE-UPDATE-FA.md). Same four files, no launcher.
ONLINE_ARCHIVE = 'SchoolDeskPro-UPDATE-2.83.0-scanner-focus.zip'

def manifest(version, payload, notes):
    return (json.dumps({
        'version': version,
        'notes': notes,
        'files': {name: {'sha256': hashlib.sha256(data).hexdigest(), 'bytes': len(data)}
                  for name, data in sorted(payload.items())},
    }, ensure_ascii=False, indent=2, sort_keys=True) + '\n').encode()


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

    online = {'SchoolDeskPro/reports/' + name: data for name, data in payload.items()}
    online['SchoolDeskPro/DESKTOP-UPDATE.json'] = manifest(
        '2.83.0-scanner-focus', payload,
        'اسکنر سریع‌تر: فوکوس روی فاصلهٔ ۵ تا ۲۰ سانتی‌متر قفل می‌شود، شاتر کوتاه و نرخ فریم بالا می‌رود و بین اسکن‌های متوالی هیچ فوکوس مجددی رخ نمی‌دهد.')
    target = ROOT / ONLINE_ARCHIVE
    with ZipFile(target, 'w', compression=ZIP_DEFLATED, compresslevel=9) as z:
        for name, content in sorted(online.items()):
            info = ZipInfo(name, (2026, 9, 19, 0, 0, 0))
            info.compress_type = ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            z.writestr(info, content)
    with ZipFile(target) as z:
        assert z.testzip() is None
        assert set(z.namelist()) == set(online)
        meta = json.loads(z.read('SchoolDeskPro/DESKTOP-UPDATE.json'))
        for name in FILES:
            assert meta['files'][name]['sha256'] == hashlib.sha256(payload[name]).hexdigest()
            assert z.read('SchoolDeskPro/reports/' + name) == payload[name]
    print(ONLINE_ARCHIVE, target.stat().st_size, hashlib.sha256(target.read_bytes()).hexdigest())

if __name__ == '__main__':
    build()
