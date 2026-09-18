#!/usr/bin/env python3
"""Build only this corrective pair, and prove that every older ZIP is untouched."""
from pathlib import Path
from zipfile import ZipFile,ZipInfo,ZIP_DEFLATED
import hashlib,json
ROOT=Path(__file__).resolve().parents[1]
FILES=json.loads((ROOT/'scripts/settings-health-files.json').read_text())
ARCHIVES=[('SITE-FIX-v4.152.0-settings-health.zip','site-update-v4.152.0/',False),('SchoolDeskPro-FIX-v2.83.0-settings-health.zip','SchoolDeskPro/www/',True)]
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def build():
 names={n for n,_,_ in ARCHIVES};before={p:sha(p) for p in ROOT.glob('*.zip') if p.name not in names}
 assert FILES==sorted(set(FILES))
 sums=[]
 for name,prefix,desktop in ARCHIVES:
  payload={f:ROOT/'update-v4.152.0'/f for f in FILES}
  with ZipFile(ROOT/name,'w',ZIP_DEFLATED,compresslevel=9) as z:
   for f,p in sorted(payload.items()):
    info=ZipInfo(prefix+f,(2026,9,18,0,0,0));info.compress_type=ZIP_DEFLATED;info.external_attr=0o100644<<16
    z.writestr(info,p.read_bytes())
  sums.append(f'{sha(ROOT/name)}  {name}\n');print(name,len(payload),(ROOT/name).stat().st_size,sha(ROOT/name))
 (ROOT/'SETTINGS-HEALTH-SHA256SUMS.txt').write_text(''.join(sums))
 for p,digest in before.items():assert sha(p)==digest,'Historical ZIP changed: '+p.name
if __name__=='__main__':build()
