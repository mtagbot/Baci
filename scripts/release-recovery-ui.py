#!/usr/bin/env python3
"""Build the recovery-ui corrective (site + desktop).

Files (both distributions, identical content):
  includes/header.php    — header date without «مورخ:», Persian-font
                           fallback chain, desktop no-new-window shim
  import-photos.php      — restored batch photo ZIP importer
No configs, DB, launcher or daemon changes.
"""
from pathlib import Path
from zipfile import ZipFile,ZipInfo,ZIP_DEFLATED
import hashlib,json
ROOT=Path(__file__).resolve().parents[1]
SOURCES={
 'includes/header.php':ROOT/'desktop-app-v2/patch/includes-header.php',
 'import-photos.php':ROOT/'update-v4.152.0/import-photos.php',
}
# Short name of the last published commit (resolved to the full SHA by the
# packaging test once the git objects are available locally).
BASELINE_REF='e77d537'
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def build():
 before={p:sha(p)for p in ROOT.glob('*.zip')if 'recovery-ui' not in p.name}
 entries=[]
 for name,prefix in [('SchoolDeskPro-FIX-v2.83.0-recovery-ui.zip','SchoolDeskPro/reports/'),
                     ('SITE-FIX-v4.152.0-recovery-ui.zip','site-update-v4.152.0/')]:
  with ZipFile(ROOT/name,'w',ZIP_DEFLATED,compresslevel=9)as z:
   for rel,src in SOURCES.items():
    info=ZipInfo(prefix+rel,(2026,9,18,0,0,0));info.compress_type=ZIP_DEFLATED;info.external_attr=0o100644<<16
    z.writestr(info,src.read_bytes())
  entries.append({'name':name,'bytes':(ROOT/name).stat().st_size,'sha256':sha(ROOT/name),'files':list(SOURCES)})
  print(name,entries[-1]['bytes'],entries[-1]['sha256'])
 for p,h in before.items():assert sha(p)==h,'Historical archive changed: '+p.name
 (ROOT/'RECOVERY-UI-SHA256SUMS.txt').write_text(''.join(x['sha256']+'  '+x['name']+'\n' for x in entries))
 (ROOT/'docs/recovery-ui').mkdir(parents=True,exist_ok=True)
 (ROOT/'docs/recovery-ui/packages.json').write_text(json.dumps({'packages':entries,'historical_archives_unchanged':len(before),'baseline_ref':BASELINE_REF},indent=2)+'\n')
 print('Historical archives unchanged:',len(before))
if __name__=='__main__':build()
