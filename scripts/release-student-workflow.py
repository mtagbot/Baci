#!/usr/bin/env python3
"""Only new corrective ZIPs. Desktop contains the rebuilt launcher plus the same web files."""
from pathlib import Path
from zipfile import ZipFile,ZipInfo,ZIP_DEFLATED
import hashlib,json,os,subprocess,sys
ROOT=Path(__file__).resolve().parents[1]
FILES=json.loads((ROOT/'scripts/student-workflow-files.json').read_text())
PACKAGES=[('SITE-FIX-v4.152.0-student-workflow.zip','site-update-v4.152.0/',False),('SchoolDeskPro-FIX-v2.83.0-student-workflow.zip','SchoolDeskPro/www/',True)]
HISTORY={'Release_V1.0-Site.zip':'acb2c0f8bf89a9fed7fda3959730ee9d3cbf9eaf389e3ca5443b81b571540fd2','Release_V1.0-Desktop.zip':'2a0c649ae5ebbf9e41cc8594fa123b7c7c28625072bb8c4feb5b4354f971d3a1','SITE-FIX-v4.152.0-svg-responsive.zip':'fd10fef1ef5d91619fd16c42b178084b0c092dc175f0ad1593490637c8abf786','SchoolDeskPro-FIX-v2.83.0-svg-responsive.zip':'3b92453c8127ee1593fd52a230dbaa9cbd3125f94e05ba179a1fc5fccab71421'}
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def history():
 for n,h in HISTORY.items():assert sha(ROOT/n)==h,n

def build():
 history();names={p[0]for p in PACKAGES};before={p:sha(p)for p in ROOT.glob('*.zip')if p.name not in names}
 cache=ROOT/'.cache/student-workflow';cache.mkdir(parents=True,exist_ok=True)
 cwd=ROOT/'desktop-app-v2/launcher';env=os.environ.copy()
 if (ROOT/'.cache/toolchain/ziglang').exists():env['PYTHONPATH']=str(ROOT/'.cache/toolchain')+os.pathsep+env.get('PYTHONPATH','')
 zig=[sys.executable,'-m','ziglang']
 subprocess.run(zig+['rc','/fo',str(cache/'app.res'),'res/app.rc'],cwd=cwd,env=env,check=True)
 subprocess.run(zig+['cc','-target','x86_64-windows-gnu','-O2','-s','launcher.c',str(cache/'app.res'),'-lws2_32','-ladvapi32','-lshell32','-luser32','-lgdi32','-Wl,--subsystem,windows','-o',str(cache/'SchoolDeskPro.exe')],cwd=cwd,env=env,check=True)
 assert FILES==sorted(set(FILES)) and len(FILES)==25
 sums=[]
 for name,prefix,desktop in PACKAGES:
  payload={prefix+f:(ROOT/'update-v4.152.0'/f).read_bytes()for f in FILES}
  if desktop:payload['SchoolDeskPro/SchoolDeskPro.exe']=(cache/'SchoolDeskPro.exe').read_bytes()
  with ZipFile(ROOT/name,'w',ZIP_DEFLATED,compresslevel=9)as z:
   for f,b in sorted(payload.items()):
    info=ZipInfo(f,(2026,9,17,0,0,0));info.compress_type=ZIP_DEFLATED;info.external_attr=0o100644<<16;z.writestr(info,b)
  sums.append(f'{sha(ROOT/name)}  {name}\n');print(name,(ROOT/name).stat().st_size,sha(ROOT/name))
 (ROOT/'STUDENT-WORKFLOW-SHA256SUMS.txt').write_text(''.join(sums))
 for p,h in before.items():assert sha(p)==h,'Historical archive changed: '+p.name
 history()
if __name__=='__main__':build()
