#!/usr/bin/env python3
"""Minimal desktop migration only. Historical full/corrective archives are immutable."""
from pathlib import Path
from zipfile import ZipFile,ZipInfo,ZIP_DEFLATED
import hashlib,os,subprocess,sys
ROOT=Path(__file__).resolve().parents[1]
NAME='SchoolDeskPro-FIX-v2.83.0-reports-layout.zip'
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def build():
 before={p:sha(p)for p in ROOT.glob('*.zip')if p.name!=NAME}
 cache=ROOT/'.cache/reports-root';cache.mkdir(parents=True,exist_ok=True)
 cwd=ROOT/'desktop-app-v2/launcher';env=os.environ.copy();env['PYTHONPATH']=str(ROOT/'.cache/toolchain')+os.pathsep+env.get('PYTHONPATH','')
 zig=[sys.executable,'-m','ziglang']
 subprocess.run(zig+['rc','/fo',str(cache/'app.res'),'res/app.rc'],cwd=cwd,env=env,check=True)
 subprocess.run(zig+['cc','-target','x86_64-windows-gnu','-O2','-s','launcher.c',str(cache/'app.res'),'-lws2_32','-ladvapi32','-lshell32','-luser32','-lgdi32','-Wl,--subsystem,windows','-o',str(cache/'SchoolDeskPro.exe')],cwd=cwd,env=env,check=True)
 payload={'SchoolDeskPro/SchoolDeskPro.exe':(cache/'SchoolDeskPro.exe').read_bytes(),'SchoolDeskPro/reports-layout-update/router.php':(ROOT/'desktop-app-v2/patch/reports-router.php').read_bytes()}
 with ZipFile(ROOT/NAME,'w',ZIP_DEFLATED,compresslevel=9)as z:
  for n,b in sorted(payload.items()):
   info=ZipInfo(n,(2026,9,18,0,0,0));info.compress_type=ZIP_DEFLATED;info.external_attr=0o100644<<16;z.writestr(info,b)
 for p,h in before.items():assert sha(p)==h,'Historical archive changed: '+p.name
 (ROOT/'REPORTS-LAYOUT-SHA256SUMS.txt').write_text(f'{sha(ROOT/NAME)}  {NAME}\n')
 print(NAME,(ROOT/NAME).stat().st_size,sha(ROOT/NAME));print('Historical archives unchanged:',len(before))
if __name__=='__main__':build()
