#!/usr/bin/env python3
"""Minimal same-version attendance/session/physical-card correction; never rebuild older ZIPs."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib
ROOT=Path(__file__).resolve().parent.parent
FILES=('attendance.php','entry-cards.php','my-sessions.php','session-status.php',
       'includes/functions.php','includes/header.php','includes/session_tracker.php',
       'includes/security_confirmation.php','includes/card_sheet_layout.php','assets/js/session-watch.js')
ARCHIVES=(('SITE-FIX-v4.152.0-security-print.zip','site-update-v4.152.0/'),
          ('SchoolDeskPro-FIX-v2.83.0-security-print.zip','SchoolDeskPro/www/'))
def build():
    for name,prefix in ARCHIVES:
        with ZipFile(ROOT/name,'w',compression=ZIP_DEFLATED,compresslevel=9) as z:
            for f in sorted(FILES):
                info=ZipInfo(prefix+f,(2026,9,16,0,0,0));info.compress_type=ZIP_DEFLATED;info.external_attr=0o100644<<16
                z.writestr(info,(ROOT/'update-v4.152.0'/f).read_bytes())
        with ZipFile(ROOT/name) as z:
            assert z.testzip() is None
            assert set(z.namelist())=={prefix+f for f in FILES}
            for f in FILES:assert z.read(prefix+f)==(ROOT/'update-v4.152.0'/f).read_bytes()
        b=(ROOT/name).read_bytes();print(name,len(b),hashlib.sha256(b).hexdigest())
if __name__=='__main__':build()
