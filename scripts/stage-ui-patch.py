"""Overlay the corrective payload on the already-published full ZIP; never rebuild that ZIP."""
from pathlib import Path
from zipfile import ZipFile
import shutil,json
ROOT=Path(__file__).resolve().parents[1]
def stage():
 p=ROOT/'.cache/ui-audit/current';p.mkdir(parents=True,exist_ok=True)
 with ZipFile(ROOT/'Release_V1.0-Site.zip') as z:z.extractall(p)
 for rel in json.loads((ROOT/'scripts/ui-patch-files.json').read_text()):
  f=ROOT/'update-v4.152.0'/rel;dest=p/rel;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(f,dest)
 return p
if __name__=='__main__':print(stage())
