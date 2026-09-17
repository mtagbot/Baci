#!/usr/bin/env python3
"""New corrective archives only: restrict return controls to opted-in previews."""
from pathlib import Path
from zipfile import ZipFile,ZipInfo,ZIP_DEFLATED
import json,hashlib
ROOT=Path(__file__).resolve().parents[1]
FILES=json.loads((ROOT/'scripts/preview-navigation-files.json').read_text())
ARCHIVES=[('SITE-FIX-v4.152.0-preview-navigation.zip','site-update-v4.152.0/'),('SchoolDeskPro-FIX-v2.83.0-preview-navigation.zip','SchoolDeskPro/www/')]
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def build():
 names={n for n,_ in ARCHIVES};before={p:sha(p) for p in ROOT.glob('*.zip') if p.name not in names}
 assert len(FILES)==16 and FILES==sorted(set(FILES))
 sums=[]
 for name,prefix in ARCHIVES:
  with ZipFile(ROOT/name,'w',ZIP_DEFLATED,compresslevel=9) as z:
   for f in FILES:
    info=ZipInfo(prefix+f,(2026,9,17,0,0,0));info.compress_type=ZIP_DEFLATED;info.external_attr=0o100644<<16
    z.writestr(info,(ROOT/'update-v4.152.0'/f).read_bytes())
  sums.append(f'{sha(ROOT/name)}  {name}\n');print(name,(ROOT/name).stat().st_size,sha(ROOT/name))
 (ROOT/'PREVIEW-NAVIGATION-SHA256SUMS.txt').write_text(''.join(sums))
 for p,h in before.items():assert sha(p)==h,'Historical archive changed: '+p.name
if __name__=='__main__':build()
