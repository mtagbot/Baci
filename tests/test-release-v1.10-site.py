from pathlib import Path
from zipfile import ZipFile
import hashlib
import json

ROOT = Path(__file__).resolve().parents[1]
PACKAGE = ROOT / 'Release_V1.10-Site.zip'
CHECKSUMS = ROOT / 'Release_V1.10-SHA256SUMS.txt'

with ZipFile(PACKAGE) as z:
    assert z.testzip() is None
    names = set(z.namelist())
    assert 'index.php' in names and 'installer.php' in names
    assert 'RELEASE-MANIFEST.json' in names
    assert all(not n.startswith('/') and '..' not in Path(n).parts for n in names)
    manifest = json.loads(z.read('RELEASE-MANIFEST.json'))
    assert manifest['release'] == 'Release_V1.10'
    assert manifest['platform'] == 'site'
    assert manifest['site_version'] == '4.216.0'
    assert manifest['patches'][-1] == 'update-v4.216.0'
    files = names - {'RELEASE-MANIFEST.json'}
    assert files == set(manifest['files'])
    for name in files:
        content = z.read(name)
        item = manifest['files'][name]
        assert item['bytes'] == len(content), name
        assert item['sha256'] == hashlib.sha256(content).hexdigest(), name
    assert b"'distribution'=>'site'" in z.read('config/release.php')
    assert b"'site_version'    => '4.216.0'" in z.read('config/version.php')
    assert b'<link rel="icon"' in z.read('includes/header.php')
    assert b'desk-doctor.php' in z.read('doctor.php')
    assert b'pdo_sqlite' in z.read('doctor.php')
    assert b'Options ' not in z.read('uploads/.htaccess')
    assert b'Options ' not in z.read('backups/.htaccess')
    assert b'RemoveHandler' in z.read('uploads/.htaccess')
    assert b'Require all denied' in z.read('backups/.htaccess')

entries = {}
for line in CHECKSUMS.read_text().splitlines():
    digest, name = line.split('  ', 1)
    entries[name] = digest
for name in ['Release_V1.10-Site.zip', 'MODIFIED-FILES-V4.216.0.zip']:
    assert entries[name] == hashlib.sha256((ROOT / name).read_bytes()).hexdigest()

print('Release V1.10 complete site package checks passed.')
