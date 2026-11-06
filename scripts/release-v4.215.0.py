#!/usr/bin/env python3
"""Package V4.215.0 / v2.139.0: targeted review audiences, emoji picker and UI/report fixes."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib
ROOT=Path(__file__).resolve().parents[1]
STAMP=(2026,11,6,0,0,0)
PAIRS=[
 ('assets/css/school-ui.css','update-v4.215.0/assets/css/school-ui.css'),
 ('config/version.php','update-v4.215.0/config/version.php'),
 ('includes/bot_admin_ui.php','update-v4.215.0/includes/bot_admin_ui.php'),
 ('includes/bot_webhook_engine.php','update-v4.215.0/includes/bot_webhook_engine.php'),
 ('includes/bot_helpers.php','update-v4.215.0/includes/bot_helpers.php'),
 ('includes/photo_album.php','update-v4.215.0/includes/photo_album.php'),
 ('includes/report_image.php','update-v4.215.0/includes/report_image.php'),
]
def make(name,prefix,pairs):
 path=ROOT/name
 with ZipFile(path,'w',ZIP_DEFLATED,compresslevel=9) as z:
  for arc,src in sorted(pairs):
   i=ZipInfo(prefix+arc,STAMP);i.compress_type=ZIP_DEFLATED;i.external_attr=0o100644<<16
   z.writestr(i,(ROOT/src).read_bytes())
 return hashlib.sha256(path.read_bytes()).hexdigest(),path.stat().st_size
site=make('MODIFIED-FILES-V4.215.0.zip','site-update-v4.152.0/',PAIRS)
desk_pairs=[(tree+arc,src) for tree in ('www/','reports/') for arc,src in PAIRS]
desk=make('SchoolDesk-FIX-v2.139.0.zip','SchoolDeskPro/',desk_pairs)
lines=[f'{site[0]}  MODIFIED-FILES-V4.215.0.zip',f'{desk[0]}  SchoolDesk-FIX-v2.139.0.zip']
(ROOT/'V4.215.0-SHA256SUMS.txt').write_text('\n'.join(lines)+'\n',encoding='utf-8')
print('MODIFIED-FILES-V4.215.0.zip',site[1],site[0])
print('SchoolDesk-FIX-v2.139.0.zip',desk[1],desk[0])
