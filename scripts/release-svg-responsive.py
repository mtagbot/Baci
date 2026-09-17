#!/usr/bin/env python3
"""Build ONLY the two new, manifest-driven corrective archives; never full releases."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib, json
ROOT = Path(__file__).resolve().parents[1]
FILES = json.loads((ROOT/'scripts/ui-patch-files.json').read_text())
ARCHIVES = [('SITE-FIX-v4.152.0-svg-responsive.zip', 'site-update-v4.152.0/'),
            ('SchoolDeskPro-FIX-v2.83.0-svg-responsive.zip', 'SchoolDeskPro/www/')]
HISTORICAL = {
 'SITE-FIX-v4.152.0-ui-print.zip':'2206e6931320516f448853967e84bccca1a5a1e8f7df86d661ebb82dbb9b147f',
 'SchoolDeskPro-FIX-v2.83.0-ui-print.zip':'4c600567cc1163eb968292398e1669b34e8b0ff468d51bb4eaf3b1735d12060c',
 'Release_V1.0-Site.zip':'acb2c0f8bf89a9fed7fda3959730ee9d3cbf9eaf389e3ca5443b81b571540fd2',
 'Release_V1.0-Desktop.zip':'2a0c649ae5ebbf9e41cc8594fa123b7c7c28625072bb8c4feb5b4354f971d3a1',
}
def sha(path): return hashlib.sha256(path.read_bytes()).hexdigest()
def check_history():
 for name, digest in HISTORICAL.items():
  assert sha(ROOT/name) == digest, 'Historical archive changed: '+name

def build():
 check_history()
 assert FILES == sorted(set(FILES)) and len(FILES) == 53
 with ZipFile(ROOT/'Release_V1.0-Site.zip') as baseline:
  for f in FILES:
   assert not f.startswith(('/', 'uploads/', 'data/', 'backups/')) and '..' not in Path(f).parts
   payload = (ROOT/'update-v4.152.0'/f).read_bytes()
   assert f not in baseline.namelist() or baseline.read(f) != payload, 'Unchanged payload file: '+f
 sums=[]
 for name, prefix in ARCHIVES:
  with ZipFile(ROOT/name,'w',compression=ZIP_DEFLATED,compresslevel=9) as z:
   for f in FILES:
    info=ZipInfo(prefix+f,(2026,9,17,0,0,0));info.compress_type=ZIP_DEFLATED;info.external_attr=0o100644<<16
    z.writestr(info,(ROOT/'update-v4.152.0'/f).read_bytes())
  sums.append(f'{sha(ROOT/name)}  {name}\n')
  print(name,(ROOT/name).stat().st_size,sha(ROOT/name))
 (ROOT/'SVG-RESPONSIVE-SHA256SUMS.txt').write_text(''.join(sums))
 check_history()
if __name__=='__main__': build()
