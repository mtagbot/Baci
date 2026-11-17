from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib
ROOT=Path(__file__).resolve().parents[1]
STAMP=(2026,11,4,0,0,0)
SITE='MODIFIED-FILES-V4.219.0.zip'
DESKTOP='SchoolDesk-FIX-v2.143.0.zip'
PAIRS=[
('config/version.php','update-v4.219.0/config/version.php'),
('exams.php','update-v4.219.0/exams.php'),
('teacher-panel.php','update-v4.219.0/teacher-panel.php'),
('includes/exams_helper.php','update-v4.219.0/includes/exams_helper.php'),
('includes/bot_webhook_engine.php','update-v4.219.0/includes/bot_webhook_engine.php'),
('includes/bot_role_engine.php','update-v4.219.0/includes/bot_role_engine.php'),
]
DESK_PAIRS=[
('config/version.php','desktop-app-v2/patch/www-config-version.php'),
('exams.php','desktop-app-v2/patch/www-exams.php'),
('teacher-panel.php','desktop-app-v2/patch/www-teacher-panel.php'),
('includes/exams_helper.php','desktop-app-v2/patch/includes-exams_helper.php'),
('includes/bot_webhook_engine.php','desktop-app-v2/patch/includes-bot_webhook_engine.php'),
('includes/bot_role_engine.php','desktop-app-v2/patch/includes-bot_role_engine.php'),
]
def archive(name,pairs,prefix=''):
 with ZipFile(ROOT/name,'w',ZIP_DEFLATED,compresslevel=9) as z:
  for arc,src in sorted(pairs):
   info=ZipInfo(prefix+arc,STAMP);info.compress_type=ZIP_DEFLATED;info.external_attr=0o100644<<16
   z.writestr(info,(ROOT/src).read_bytes())
  z.testzip()
 return hashlib.sha256((ROOT/name).read_bytes()).hexdigest()
def build():
 sums=[]
 sums.append((archive(SITE,PAIRS),SITE))
 sums.append((archive(DESKTOP,DESK_PAIRS,'SchoolDeskPro/www/'),DESKTOP))
 (ROOT/'V4.219.0-SHA256SUMS.txt').write_text(''.join(f'{d}  {n}\n' for d,n in sums),encoding='utf-8')
 print('\n'.join(f'{n}: {d}' for d,n in sums))
if __name__=='__main__':build()
