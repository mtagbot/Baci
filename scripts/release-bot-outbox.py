#!/usr/bin/env python3
"""Build only the six-file corrective pair. No configs, DB, launcher or full release rebuild."""
from pathlib import Path
from zipfile import ZipFile,ZipInfo,ZIP_DEFLATED
import hashlib,importlib.util,json
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('stage',ROOT/'scripts/stage-bot-outbox.py');stage=importlib.util.module_from_spec(spec);spec.loader.exec_module(stage)
PACKAGES=[('SITE-FIX-v4.152.0-bot-outbox.zip','site-update-v4.152.0/',False),('SchoolDeskPro-FIX-v2.83.0-bot-outbox.zip','SchoolDeskPro/reports/',True)]
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def build():
 before={p:sha(p)for p in ROOT.glob('*.zip')if p.name not in [n for n,_,_ in PACKAGES]}
 result=[]
 for name,prefix,desktop in PACKAGES:
  files=stage.DESKTOP if desktop else stage.SITE
  with ZipFile(ROOT/name,'w',ZIP_DEFLATED,compresslevel=9)as z:
   for f in sorted(files):
    src=ROOT/'desktop-app-v2/patch/www-desk-sync-daemon.php' if f=='desk-sync-daemon.php' else ROOT/'update-v4.152.0'/f
    info=ZipInfo(prefix+f,(2026,9,18,0,0,0));info.compress_type=ZIP_DEFLATED;info.external_attr=0o100644<<16;z.writestr(info,src.read_bytes())
  entry={'name':name,'bytes':(ROOT/name).stat().st_size,'sha256':sha(ROOT/name),'files':files};result.append(entry);print(name,entry['bytes'],entry['sha256'])
 for p,h in before.items():assert sha(p)==h,'Historical archive changed: '+p.name
 (ROOT/'BOT-OUTBOX-SHA256SUMS.txt').write_text(''.join(x['sha256']+'  '+x['name']+'\n' for x in result))
 (ROOT/'docs/bot-outbox/packages.json').write_text(json.dumps({'packages':result,'historical_archives_unchanged':len(before)},indent=2)+'\n')
 print('Historical archives unchanged:',len(before))
if __name__=='__main__':build()
