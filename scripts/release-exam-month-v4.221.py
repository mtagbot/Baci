#!/usr/bin/env python3
"""Build the v4.221 exam-design site and desktop correction packages."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib
ROOT=Path(__file__).resolve().parents[1]
STAMP=(2026,11,4,0,0,0)
SITE='MODIFIED-FILES-V4.221.0.zip'
DESKTOP='SchoolDesk-FIX-v2.145.0.zip'
SITE_FILES=[
('config/version.php','config/version.php'),
('teacher-panel.php','teacher-panel.php'),
('exam-print.php','exam-print.php'),
('exam-design-api.php','exam-design-api.php'),
('class-exam-group.php','class-exam-group.php'),
('includes/class_exam_groups.php','includes/class_exam_groups.php'),
('includes/class_exam_group_choice.php','includes/class_exam_group_choice.php'),
('includes/bot_helpers.php','includes/bot_helpers.php'),
]
DESK_FILES=[(a,b) for a,b in [
('config/version.php','www-config-version.php'),('teacher-panel.php','www-teacher-panel.php'),('exam-print.php','www-exam-print.php'),
('exam-design-api.php','www-exam-design-api.php'),('class-exam-group.php','www-class-exam-group.php'),
('includes/class_exam_groups.php','includes-class_exam_groups.php'),
('includes/class_exam_group_choice.php','includes-class_exam_group_choice.php'),
('includes/bot_helpers.php','includes-bot_helpers.php')]]
def archive(name,files,prefix='',base='update-v4.221.0'):
    target=ROOT/name
    with ZipFile(target,'w',ZIP_DEFLATED,compresslevel=9) as z:
        for path,src in sorted(files):
            info=ZipInfo(prefix+path,STAMP);info.compress_type=ZIP_DEFLATED;info.external_attr=0o100644<<16
            z.writestr(info,(ROOT/base/src).read_bytes())
        assert z.testzip() is None
    return hashlib.sha256(target.read_bytes()).hexdigest()
def main():
    sums=[(archive(SITE,SITE_FILES),(ROOT/SITE).name),(archive(DESKTOP,DESK_FILES,'SchoolDeskPro/www/','desktop-app-v2/patch'),DESKTOP)]
    (ROOT/'V4.221.0-SHA256SUMS.txt').write_text(''.join(f'{digest}  {name}\n' for digest,name in sums),encoding='utf-8')
    print('\n'.join(f'{name}: {digest}' for digest,name in sums))
if __name__=='__main__':main()
