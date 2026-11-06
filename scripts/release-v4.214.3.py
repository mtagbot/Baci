#!/usr/bin/env python3
"""Package V4.214.3 / v2.138.3 report viewed acknowledgement fix."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib
ROOT=Path(__file__).resolve().parents[1]
STAMP=(2026,11,6,0,0,0)
ENGINE='update-v4.214.3/includes/bot_webhook_engine.php'
VERSION='update-v4.214.3/config/version.php'
def package(path, prefix, files):
    with ZipFile(ROOT/path,'w',ZIP_DEFLATED,compresslevel=9) as z:
        for arc,src in files:
            info=ZipInfo(prefix+arc,STAMP); info.compress_type=ZIP_DEFLATED; info.external_attr=0o100644<<16
            z.writestr(info,(ROOT/src).read_bytes())
    p=ROOT/path
    return hashlib.sha256(p.read_bytes()).hexdigest(),p.stat().st_size
site=[('includes/bot_webhook_engine.php',ENGINE),('config/version.php',VERSION)]
desk=[]
for tree in ('www/','reports/'):
    desk += [(tree+'includes/bot_webhook_engine.php',ENGINE),(tree+'config/version.php',VERSION)]
outputs=[]
for name,prefix,files in [('MODIFIED-FILES-V4.214.3.zip','site-update-v4.152.0/',site),('SchoolDesk-FIX-v2.138.3.zip','SchoolDeskPro/',desk)]:
    digest,size=package(name,prefix,files); outputs.append(f'{digest}  {name}')
    print(f'{name}: {size} bytes sha256={digest}')
(ROOT/'V4.214.3-SHA256SUMS.txt').write_text('\n'.join(outputs)+'\n',encoding='utf-8')
