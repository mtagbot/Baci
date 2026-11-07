from pathlib import Path
from zipfile import ZipFile
import hashlib
import json

ROOT = Path(__file__).resolve().parents[1]
PACKAGE = ROOT / 'Release_V1.11-Site.zip'

with ZipFile(PACKAGE) as z:
    assert z.testzip() is None
    names = set(z.namelist())
    assert 'index.php' in names and 'installer.php' in names
    assert all(not n.startswith('/') and '..' not in Path(n).parts for n in names)
    manifest = json.loads(z.read('RELEASE-MANIFEST.json'))
    assert manifest['release'] == 'Release_V1.11'
    assert manifest['platform'] == 'site'
    assert manifest['site_version'] == '4.217.0'
    assert manifest['patches'][-1] == 'update-v4.217.0'
    file_names = names - {'RELEASE-MANIFEST.json'}
    assert file_names == set(manifest['files'])
    for name in file_names:
        content = z.read(name)
        entry = manifest['files'][name]
        assert entry['bytes'] == len(content), name
        assert entry['sha256'] == hashlib.sha256(content).hexdigest(), name
    settings = z.read('settings.php').decode('utf-8')
    assert '<input type="file" name="logo_file"' in settings
    assert '<input type="file" name="favicon_file"' in settings
    assert '<input type="text" name="logo_url"' not in settings
    assert '<input type="text" name="favicon_url"' not in settings
    assert b'<link rel="icon"' in z.read('includes/header.php')
    assert b"'release'=>'Release_V1.11'" in z.read('config/release.php')
    assert b"'4.217.0'" in z.read('config/version.php')

checksums = {}
for line in (ROOT / 'Release_V1.11-SHA256SUMS.txt').read_text().splitlines():
    digest, filename = line.split('  ', 1)
    checksums[filename] = digest
for filename in ['Release_V1.11-Site.zip', 'MODIFIED-FILES-V4.217.0.zip', 'SchoolDesk-FIX-v2.141.0.zip']:
    assert checksums[filename] == hashlib.sha256((ROOT / filename).read_bytes()).hexdigest()

print('Release V1.11 site and release checks passed.')
