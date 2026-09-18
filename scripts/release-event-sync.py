#!/usr/bin/env python3
"""Build the two-file event-sync corrective (desktop only).

Files:
  SchoolDeskPro/reports/includes/desk_sync.php  (engine: event mode)
  SchoolDeskPro/reports/desk-sync.php           (settings page: mode selector)

Installs on top of the optimized-sync corrective. No configs, DB, launcher
or daemon changes.
"""
from pathlib import Path
from zipfile import ZipFile,ZipInfo,ZIP_DEFLATED
import hashlib,json
ROOT=Path(__file__).resolve().parents[1]
NAME='SchoolDeskPro-FIX-v2.83.0-event-sync.zip'
PREFIX='SchoolDeskPro/reports/'
FILES=[('includes/desk_sync.php',ROOT/'update-v4.152.0/includes/desk_sync.php'),
       ('desk-sync.php',ROOT/'desktop-app-v2/patch/www-desk-sync.php')]
BASELINE='5336dcde3b91c5087ec178c931fa859b820b048c'  # published optimized-sync state
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def build():
 before={p:sha(p)for p in ROOT.glob('*.zip')if p.name!=NAME}
 with ZipFile(ROOT/NAME,'w',ZIP_DEFLATED,compresslevel=9)as z:
  for rel,src in FILES:
   info=ZipInfo(PREFIX+rel,(2026,9,18,0,0,0));info.compress_type=ZIP_DEFLATED;info.external_attr=0o100644<<16
   z.writestr(info,src.read_bytes())
 entry={'name':NAME,'bytes':(ROOT/NAME).stat().st_size,'sha256':sha(ROOT/NAME),'files':[r for r,_ in FILES],'baseline':BASELINE}
 print(NAME,entry['bytes'],entry['sha256'])
 for p,h in before.items():assert sha(p)==h,'Historical archive changed: '+p.name
 (ROOT/'EVENT-SYNC-SHA256SUMS.txt').write_text(''.join(x['sha256']+'  '+x['name']+'\n' for x in [entry]))
 (ROOT/'docs/event-sync').mkdir(parents=True,exist_ok=True)
 (ROOT/'docs/event-sync/packages.json').write_text(json.dumps({'packages':[entry],'historical_archives_unchanged':len(before)},indent=2)+'\n')
 print('Historical archives unchanged:',len(before))
if __name__=='__main__':build()
