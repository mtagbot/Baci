from pathlib import Path
from zipfile import ZipFile
import json,sys,importlib.util
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('prior',ROOT/'scripts/stage-report-tools.py');prior=importlib.util.module_from_spec(spec);spec.loader.exec_module(prior)
def stage(desktop=False):
 dest=prior.stage(desktop)
 for f in json.loads((ROOT/'scripts/settings-health-files.json').read_text()):
  p=dest/f;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/'update-v4.152.0'/f).read_bytes())
 return dest
if __name__=='__main__':print(stage('--desktop' in sys.argv))
