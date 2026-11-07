from pathlib import Path
from zipfile import ZipFile
import hashlib

ROOT = Path(__file__).resolve().parents[1]
SITE_ZIP = ROOT / 'MODIFIED-FILES-V4.217.0.zip'
DESKTOP_ZIP = ROOT / 'SchoolDesk-FIX-v2.141.0.zip'

with ZipFile(SITE_ZIP) as z:
    assert z.testzip() is None
    assert set(z.namelist()) == {
        'backups/.htaccess', 'config/version.php', 'doctor.php', 'includes/header.php',
        'includes/perf.php', 'settings.php', 'uploads/.htaccess'
    }
    settings = z.read('settings.php').decode('utf-8')
    assert '<input type="file" name="logo_file"' in settings
    assert '<input type="file" name="favicon_file"' in settings
    assert '<input type="text" name="logo_url"' not in settings
    assert '<input type="text" name="favicon_url"' not in settings
    assert 'uploads/branding/' in settings and 'move_uploaded_file' in settings
    assert 'getimagesize' in settings and 'finfo_file' in settings
    assert b'Options ' not in z.read('uploads/.htaccess')
    assert b'Options ' not in z.read('backups/.htaccess')
    assert b'<link rel="icon"' in z.read('includes/header.php')
    assert b"'4.217.0'" in z.read('config/version.php')

with ZipFile(DESKTOP_ZIP) as z:
    assert z.testzip() is None
    assert len(z.namelist()) == 6
    for tree in ['reports', 'www']:
        settings = z.read(f'SchoolDeskPro/{tree}/settings.php').decode('utf-8')
        header = z.read(f'SchoolDeskPro/{tree}/includes/header.php')
        assert '<input type="file" name="logo_file"' in settings
        assert '<input type="file" name="favicon_file"' in settings
        assert b'<link rel="icon"' in header
        assert b"'2.141.0'" in z.read(f'SchoolDeskPro/{tree}/config/version.php')

checksums = {}
for line in (ROOT / 'SHA256SUMS-V4.217.0.txt').read_text().splitlines():
    digest, name = line.split('  ', 1)
    checksums[name] = digest
for name, path in [('MODIFIED-FILES-V4.217.0.zip', SITE_ZIP), ('SchoolDesk-FIX-v2.141.0.zip', DESKTOP_ZIP)]:
    assert checksums[name] == hashlib.sha256(path.read_bytes()).hexdigest()

print('V4.217.0 site and desktop packages passed.')
