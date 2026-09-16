#!/usr/bin/env python3
"""Same-version staff workflow correction; six required PHP files only."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib
import subprocess
REVISION="b125632" # Immutable historical staff release, not the current group sources.
def source(name):
    return subprocess.check_output(["git","show",REVISION+":update-v4.152.0/"+name],cwd=ROOT)
ROOT = Path(__file__).resolve().parent.parent
FILES = ('classes.php','teacher-panel.php','class-exam-create.php','exam-print.php',
         'includes/class_exam_helpers.php','includes/docx_class_list.php')
ARCHIVES = {'SITE-FIX-v4.152.0-staff-workflows.zip':'site-update-v4.152.0/',
            'SchoolDeskPro-FIX-v2.83.0-staff-workflows.zip':'SchoolDeskPro/www/'}

def build():
    for archive,prefix in ARCHIVES.items():
        with ZipFile(ROOT/archive,'w',compression=ZIP_DEFLATED,compresslevel=9) as z:
            for name in sorted(FILES):
                info=ZipInfo(prefix+name,(2026,9,16,0,0,0))
                info.compress_type=ZIP_DEFLATED
                info.external_attr=0o100644<<16
                z.writestr(info,source(name))
        with ZipFile(ROOT/archive) as z:
            assert z.testzip() is None
            assert set(z.namelist())=={prefix+n for n in FILES}
            for name in FILES: assert z.read(prefix+name)==source(name)
        data=(ROOT/archive).read_bytes()
        print(archive,len(data),hashlib.sha256(data).hexdigest())

if __name__=='__main__': build()
