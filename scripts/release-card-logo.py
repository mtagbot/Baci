#!/usr/bin/env python3
"""Incremental custom card logo/clean-edge correction. No version bump or rebuilding previous releases."""
from pathlib import Path
from zipfile import ZipFile,ZipInfo,ZIP_DEFLATED
import hashlib
ROOT=Path(__file__).resolve().parent.parent
FILES=('entry-card-logo.php','entry-cards.php','includes/card_face.php','includes/card_custom_styles.php','assets/js/card-custom.js')
ARCHIVES=(('SITE-FIX-v4.152.0-custom-card-logo.zip','site-update-v4.152.0/'),('SchoolDeskPro-FIX-v2.83.0-custom-card-logo.zip','SchoolDeskPro/www/'))
def build():
 for name,prefix in ARCHIVES:
  with ZipFile(ROOT/name,'w',compression=ZIP_DEFLATED,compresslevel=9) as z:
   for f in sorted(FILES):
    info=ZipInfo(prefix+f,(2026,9,16,0,0,0));info.compress_type=ZIP_DEFLATED;info.external_attr=0o100644<<16
    z.writestr(info,(ROOT/'update-v4.152.0'/f).read_bytes())
  with ZipFile(ROOT/name) as z:
   assert z.testzip() is None and set(z.namelist())=={prefix+f for f in FILES}
   for f in FILES:assert z.read(prefix+f)==(ROOT/'update-v4.152.0'/f).read_bytes()
  data=(ROOT/name).read_bytes();print(name,len(data),hashlib.sha256(data).hexdigest())
if __name__=='__main__':build()
