#!/usr/bin/env python3
"""Build deterministic incremental/site-cumulative/desktop roster updates.
Usage: python3 scripts/release-rosters.py path/to/SchoolDeskPro-v2.82.0-win64.zip
Only the payload patch and desktop README may differ from the base bundle.
"""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib
import re
import sys

ROOT = Path(__file__).resolve().parent.parent
PATCH = ROOT / 'update-v4.152.0'
BASE = Path(sys.argv[1]).resolve()
STAMP = (2026, 9, 14, 0, 0, 0)
GUIDE = 'راهنمای-بروزرسانی.txt'

def write_zip(path, files):
    with ZipFile(path, 'w', compression=ZIP_DEFLATED, compresslevel=9) as z:
        for name, data in sorted(files.items()):
            info = ZipInfo(name, STAMP)
            info.compress_type = ZIP_DEFLATED
            info.external_attr = (0o40755 if name.endswith('/') else 0o100644) << 16
            z.writestr(info, data)
    with ZipFile(path) as z:
        assert z.testzip() is None
    print(path.name, path.stat().st_size, hashlib.sha256(path.read_bytes()).hexdigest())

patch = {p.relative_to(PATCH).as_posix(): p.read_bytes() for p in PATCH.rglob('*') if p.is_file()}
write_zip(ROOT / 'MODIFIED-FILES-v4.152.0.zip', {'update-v4.152.0/' + n: b for n, b in patch.items()})

cumulative = {}
versions = sorted(ROOT.glob('update-v*'), key=lambda p: tuple(map(int, p.name[8:].split('.'))))
for d in versions:
    version = tuple(map(int, d.name[8:].split('.')))
    if (4, 124, 0) < version <= (4, 152, 0):
        for p in d.rglob('*'):
            if p.is_file() and p.name != GUIDE:
                cumulative[p.relative_to(d).as_posix()] = p.read_bytes()
cumulative[GUIDE] = patch[GUIDE]
write_zip(ROOT / 'SITE-UPDATE-v4.152.0.zip', {'site-update-v4.152.0/' + n: b for n, b in cumulative.items()})

with ZipFile(BASE) as z:
    old = {i.filename: z.read(i) for i in z.infolist()}
new = dict(old)
for n, b in patch.items():
    if n != GUIDE:
        new['SchoolDeskPro/www/' + n] = b
readme = 'SchoolDeskPro/README.txt'
text, replacements = re.subn(r'(^\s*نسخه\s+)\d+\.\d+\.\d+', r'\g<1>2.83.0', old[readme].decode('utf-8'), count=1, flags=re.M)
assert replacements == 1
new[readme] = text.encode('utf-8')
expected = {'SchoolDeskPro/www/' + n for n in patch if n != GUIDE} | {readme}
changed = {n for n in new if n not in old or old[n] != new[n]}
assert changed == expected, (changed, expected)
assert not (old.keys() - new.keys())
write_zip(ROOT / 'SchoolDeskPro-v2.83.0-win64.zip', new)
print('Desktop changed/added entries (all other entries byte-identical):')
print('\n'.join(sorted(changed)))
print('Desktop files added:', ', '.join(sorted(new.keys() - old.keys())))
