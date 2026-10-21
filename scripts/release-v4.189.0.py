#!/usr/bin/env python3
"""Build the v4.189.0 / v2.113.0 correction packages reproducibly.

python3 scripts/release-v4.189.0.py

The site and desktop packages contain the save-choice flow, admin-only class-exam
availability control, and monthly exam-print table improvements. The desktop
patch copies are required to match the site implementation byte-for-byte.
"""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 10, 21, 0, 0, 0)
SUMS = 'V4.189.0-SHA256SUMS.txt'

SITE_FILES = [
    ('config/version.php', 'update-v4.152.0/config/version.php', b'4.189.0'),
    ('includes/exams_helper.php', 'update-v4.152.0/includes/exams_helper.php', b'exam_teacher_class_design_enabled'),
    ('exam-design-api.php', 'update-v4.152.0/exam-design-api.php', b'$sendForPrint'),
    ('exam-print.php', 'update-v4.152.0/exam-print.php', b'openSaveChoiceModal'),
    ('exams.php', 'update-v4.152.0/exams.php', b'adminMonthlyExams'),
    ('class-exam-create.php', 'update-v4.152.0/class-exam-create.php', b'exam_teacher_class_design_enabled'),
    ('includes/admin_class_exams.php', 'update-v4.152.0/includes/admin_class_exams.php', b'set_class_design_enabled'),
    ('includes/teacher_class_exams.php', 'update-v4.152.0/includes/teacher_class_exams.php', b'exam_teacher_class_design_enabled'),
]

DESKTOP_FILES = [
    ('config/version.php', 'update-v4.152.0/config/version.php', b'4.189.0'),
    ('includes/exams_helper.php', 'desktop-app-v2/patch/includes-exams_helper.php', b'exam_teacher_class_design_enabled'),
    ('exam-design-api.php', 'desktop-app-v2/patch/www-exam-design-api.php', b'$sendForPrint'),
    ('exam-print.php', 'desktop-app-v2/patch/www-exam-print.php', b'openSaveChoiceModal'),
    ('exams.php', 'desktop-app-v2/patch/www-exams.php', b'adminMonthlyExams'),
    ('class-exam-create.php', 'desktop-app-v2/patch/www-class-exam-create.php', b'exam_teacher_class_design_enabled'),
    ('includes/admin_class_exams.php', 'desktop-app-v2/patch/includes-admin_class_exams.php', b'set_class_design_enabled'),
    ('includes/teacher_class_exams.php', 'desktop-app-v2/patch/includes-teacher_class_exams.php', b'exam_teacher_class_design_enabled'),
]

IDENTICAL = [
    ('includes-exams_helper.php', 'includes/exams_helper.php'),
    ('www-exam-design-api.php', 'exam-design-api.php'),
    ('www-exam-print.php', 'exam-print.php'),
    ('www-exams.php', 'exams.php'),
    ('www-class-exam-create.php', 'class-exam-create.php'),
    ('includes-admin_class_exams.php', 'includes/admin_class_exams.php'),
    ('includes-teacher_class_exams.php', 'includes/teacher_class_exams.php'),
]
PACKAGES = [
    ('MODIFIED-FILES-V4.189.0.zip', 'site-update-v4.152.0/', SITE_FILES),
    ('SchoolDesk-FIX-v2.113.0.zip', 'SchoolDeskPro/www/', DESKTOP_FILES),
]


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def check_desktop_copies():
    for patch_name, site_rel in IDENTICAL:
        site = ROOT / 'update-v4.152.0' / site_rel
        desk = ROOT / 'desktop-app-v2/patch' / patch_name
        assert desk.is_file(), f'missing desktop mirror: {patch_name}'
        assert sha(site) == sha(desk), f'site/desktop mismatch: {site_rel}'


def build():
    check_desktop_copies()
    outputs = []
    for name, prefix, files in PACKAGES:
        with ZipFile(ROOT / name, 'w', ZIP_DEFLATED, compresslevel=9) as z:
            for arc, source, marker in sorted(files):
                data = (ROOT / source).read_bytes()
                assert marker in data, f'version marker missing: {arc}'
                info = ZipInfo(prefix + arc, STAMP)
                info.compress_type = ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                z.writestr(info, data)
        digest = sha(ROOT / name)
        outputs.append((digest, name))
        print(f'{name}: {(ROOT / name).stat().st_size} bytes, SHA-256 {digest}')
    (ROOT / SUMS).write_text(''.join(f'{digest}  {name}\n' for digest, name in outputs), encoding='utf-8')
    print(f'Wrote {SUMS}; site and desktop implementations match.')


if __name__ == '__main__':
    build()
