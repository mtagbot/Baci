#!/usr/bin/env python3
"""Minimal ten-correction payloads. Does not touch historical or full release archives."""
from pathlib import Path
from zipfile import ZipFile,ZipInfo,ZIP_DEFLATED
import hashlib
ROOT=Path(__file__).resolve().parents[1]
FILES=(
 'admin-login.php','assets/css/ui-modern.css','assets/js/login-captcha.js',
 'attendance-scanner.php','attendance-tags.php','bulk-print.php','entry-cards.php',
 'exam-print.php','export-excel.php','export-pdf.php','includes/admin_class_exams.php',
 'includes/appearance.php','includes/card_styles.php','includes/docx_class_list.php',
 'includes/docx_school_list.php','includes/functions.php','includes/header.php',
 'includes/header_tiles.php','includes/report_image.php','index.php','report-print.php',
 'report-view.php','settings.php','student-bulk-report.php',
)
ARCHIVES=(('SITE-FIX-v4.152.0-ui-print.zip','site-update-v4.152.0/'),('SchoolDeskPro-FIX-v2.83.0-ui-print.zip','SchoolDeskPro/www/'))
def build():
 sums=[]
 for name,prefix in ARCHIVES:
  with ZipFile(ROOT/name,'w',compression=ZIP_DEFLATED,compresslevel=9) as z:
   for f in sorted(FILES):
    info=ZipInfo(prefix+f,(2026,9,17,0,0,0));info.compress_type=ZIP_DEFLATED;info.external_attr=0o100644<<16
    z.writestr(info,(ROOT/'update-v4.152.0'/f).read_bytes())
  with ZipFile(ROOT/name) as z:
   assert z.testzip() is None and set(z.namelist())=={prefix+f for f in FILES}
   for f in FILES:assert z.read(prefix+f)==(ROOT/'update-v4.152.0'/f).read_bytes()
  sha=hashlib.sha256((ROOT/name).read_bytes()).hexdigest();sums.append(f'{sha}  {name}\n')
  print(name,(ROOT/name).stat().st_size,sha)
 (ROOT/'UI-PRINT-SHA256SUMS.txt').write_text(''.join(sums))
if __name__=='__main__':build()
