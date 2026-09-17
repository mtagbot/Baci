"""Stage immutable published baselines and optionally the new mobile hub overlay."""
from pathlib import Path
from zipfile import ZipFile
import json,sys
ROOT=Path(__file__).resolve().parents[1]
def stage(desktop=False,before=False):
 base=ROOT/'.cache/mobile-hubs'/('before' if before else 'desktop' if desktop else 'site');base.mkdir(parents=True,exist_ok=True)
 with ZipFile(ROOT/('Release_V1.0-Desktop.zip' if desktop else 'Release_V1.0-Site.zip')) as z:z.extractall(base)
 dest=base/'SchoolDeskPro/www' if desktop else base
 for suffix in ['svg-responsive','student-workflow','preview-navigation','fast-ui']:
  prefix='SchoolDeskPro/www/' if desktop else 'site-update-v4.152.0/'
  with ZipFile(ROOT/(('SchoolDeskPro-FIX-v2.83.0-' if desktop else 'SITE-FIX-v4.152.0-')+suffix+'.zip')) as z:
   for n in z.namelist():
    if n.startswith(prefix):
     p=dest/n[len(prefix):];p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(z.read(n))
 if not before:
  for f in json.loads((ROOT/'scripts/mobile-hubs-files.json').read_text()):
   p=dest/f;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/'update-v4.152.0'/f).read_bytes())
 return dest
if __name__=='__main__':print(stage('--desktop' in sys.argv,'--before' in sys.argv))
