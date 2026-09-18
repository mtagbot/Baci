#!/usr/bin/env python3
"""Build the one-file optimized-sync corrective (desktop desk_sync.php only).

No configs, DB, launcher, daemon or full release rebuild. The desktop
sync-daemon is intentionally UNCHANGED: its 5-second loop keeps kick
wake-up and liveness, while the engine (this file) now throttles the
network work.
"""
from pathlib import Path
from zipfile import ZipFile,ZipInfo,ZIP_DEFLATED
import hashlib,json
ROOT=Path(__file__).resolve().parents[1]
NAME='SchoolDeskPro-FIX-v2.83.0-optimized-sync.zip'
PREFIX='SchoolDeskPro/reports/'
FILE='includes/desk_sync.php'
BASELINE='d44626fafc69f67ef2e32bde70022d5bfb73b3ad'  # published bot-outbox state
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def build():
 before={p:sha(p)for p in ROOT.glob('*.zip')if p.name!=NAME}
 src=(ROOT/'update-v4.152.0'/FILE).read_bytes()
 with ZipFile(ROOT/NAME,'w',ZIP_DEFLATED,compresslevel=9)as z:
  info=ZipInfo(PREFIX+FILE,(2026,9,18,0,0,0));info.compress_type=ZIP_DEFLATED;info.external_attr=0o100644<<16
  z.writestr(info,src)
 entry={'name':NAME,'bytes':(ROOT/NAME).stat().st_size,'sha256':sha(ROOT/NAME),'files':[FILE],'baseline':BASELINE}
 print(NAME,entry['bytes'],entry['sha256'])
 for p,h in before.items():assert sha(p)==h,'Historical archive changed: '+p.name
 (ROOT/'OPTIMIZED-SYNC-SHA256SUMS.txt').write_text(''.join(x['sha256']+'  '+x['name']+'\n' for x in [entry]))
 (ROOT/'docs/optimized-sync').mkdir(parents=True,exist_ok=True)
 (ROOT/'docs/optimized-sync/packages.json').write_text(json.dumps({'packages':[entry],'historical_archives_unchanged':len(before)},indent=2)+'\n')
 print('Historical archives unchanged:',len(before))
if __name__=='__main__':build()
