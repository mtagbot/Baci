"""Stage the previous mobile-hubs installation and the seven restored runtime paths."""
from pathlib import Path
import importlib.util,json,sys
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('hubs',ROOT/'scripts/stage-mobile-hubs.py')
hubs=importlib.util.module_from_spec(spec);spec.loader.exec_module(hubs)
def stage(desktop=False):
 dest=hubs.stage(desktop)
 for f in json.loads((ROOT/'scripts/report-tools-files.json').read_text()):
  p=dest/f;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/'update-v4.152.0'/f).read_bytes())
 return dest
if __name__=='__main__':print(stage('--desktop' in sys.argv))
