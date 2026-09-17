"""Extract published baselines + apply the explicit new payload. Never rebuild full archives."""
from pathlib import Path
from zipfile import ZipFile
import json
ROOT=Path(__file__).resolve().parents[1]
def stage(desktop=False):
 base=ROOT/'.cache/student-workflow'/('desktop' if desktop else 'site');base.mkdir(parents=True,exist_ok=True)
 with ZipFile(ROOT/('Release_V1.0-Desktop.zip' if desktop else 'Release_V1.0-Site.zip')) as z:z.extractall(base)
 dest=base/'SchoolDeskPro/www' if desktop else base
 with ZipFile(ROOT/('SchoolDeskPro-FIX-v2.83.0-svg-responsive.zip' if desktop else 'SITE-FIX-v4.152.0-svg-responsive.zip')) as z:
  prefix='SchoolDeskPro/www/' if desktop else 'site-update-v4.152.0/'
  for n in z.namelist():
   p=dest/n.removeprefix(prefix);p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(z.read(n))
 for f in json.loads((ROOT/'scripts/student-workflow-files.json').read_text()):
  p=dest/f;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/'update-v4.152.0'/f).read_bytes())
 return dest
if __name__=='__main__':
 import sys
 print(stage('--desktop' in sys.argv))
