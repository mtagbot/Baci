#!/usr/bin/env python3
"""Same-version incremental group-exam correction, after the class-exam-groups fix."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parent.parent
FILES = ('teacher-panel.php', 'class-exam-group.php', 'exam-print.php', 'exam-design-api.php', 'exam-source-api.php', 'exams.php', 'includes/class_exam_groups.php', 'includes/teacher_class_exams.php', 'includes/admin_class_exams.php', 'class-exam-delete.php', 'includes/teacher_weekly_schedule.php')

ARCHIVES = (
    ('SITE-FIX-v4.152.0-class-exam-actions.zip', False),
    ('SchoolDeskPro-FIX-v2.83.0-class-exam-actions.zip', True),
)


def destination(name, desktop):
    if not desktop:
        return 'site-update-v4.152.0/' + name
    if name == 'class-exam-sync-api.php':
        # Companion for the live site, like the original desktop server/ endpoint.
        return 'SchoolDeskPro/server/' + name
    return 'SchoolDeskPro/www/' + name


def build():
    for archive, desktop in ARCHIVES:
        with ZipFile(ROOT / archive, 'w', compression=ZIP_DEFLATED, compresslevel=9) as z:
            for name in sorted(FILES):
                info = ZipInfo(destination(name, desktop), (2026, 9, 16, 0, 0, 0))
                info.compress_type = ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                z.writestr(info, (ROOT / 'update-v4.152.0' / name).read_bytes())
        with ZipFile(ROOT / archive) as z:
            assert z.testzip() is None
            assert set(z.namelist()) == {destination(n, desktop) for n in FILES}
            for name in FILES:
                assert z.read(destination(name, desktop)) == (ROOT / 'update-v4.152.0' / name).read_bytes()
        data = (ROOT / archive).read_bytes()
        print(archive, len(data), hashlib.sha256(data).hexdigest())


if __name__ == '__main__':
    build()
