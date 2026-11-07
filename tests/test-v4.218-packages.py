from pathlib import Path
from zipfile import ZipFile
import hashlib

ROOT = Path(__file__).resolve().parents[1]

with ZipFile(ROOT / 'MODIFIED-FILES-V4.218.0.zip') as z:
    assert z.testzip() is None
    settings = z.read('settings.php').decode('utf-8')
    assert 'width:84px;height:84px;max-width:84px;max-height:84px' in settings
    assert 'max-width:64px;max-height:64px' in settings
    assert b"'4.218.0'" in z.read('config/version.php')

with ZipFile(ROOT / 'SchoolDesk-FIX-v2.142.0.zip') as z:
    assert z.testzip() is None and len(z.namelist()) == 6
    for tree in ['www', 'reports']:
        settings = z.read(f'SchoolDeskPro/{tree}/settings.php').decode('utf-8')
        assert 'width:84px;height:84px;max-width:84px;max-height:84px' in settings
        assert 'max-width:64px;max-height:64px' in settings
        assert b"'2.142.0'" in z.read(f'SchoolDeskPro/{tree}/config/version.php')

checksums = {}
for line in (ROOT / 'SHA256SUMS-V4.218.0.txt').read_text().splitlines():
    digest, name = line.split('  ', 1)
    checksums[name] = digest
for name in ['MODIFIED-FILES-V4.218.0.zip', 'SchoolDesk-FIX-v2.142.0.zip']:
    assert checksums[name] == hashlib.sha256((ROOT / name).read_bytes()).hexdigest()
print('V4.218 and V2.142 correction packages passed.')
