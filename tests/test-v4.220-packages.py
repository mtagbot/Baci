from pathlib import Path
from zipfile import ZipFile
import hashlib

ROOT = Path(__file__).resolve().parents[1]
SITE = ROOT / 'MODIFIED-FILES-V4.220.0.zip'
DESKTOP = ROOT / 'SchoolDesk-FIX-v2.144.0.zip'

for archive, prefix in [(SITE, ''), (DESKTOP, 'SchoolDeskPro/www/')]:
    with ZipFile(archive) as z:
        assert z.testzip() is None
        names = set(z.namelist())
        expected = {prefix + p for p in [
            'config/version.php', 'exams.php', 'teacher-panel.php', 'includes/exams_helper.php',
            'includes/bot_webhook_engine.php', 'includes/bot_role_engine.php', 'includes/bot_helpers.php']}
        assert names == expected, (names, expected)
        exams = z.read(prefix + 'exams.php').decode()
        teacher = z.read(prefix + 'teacher-panel.php').decode()
        webhook = z.read(prefix + 'includes/bot_webhook_engine.php').decode()
        bot_role = z.read(prefix + 'includes/bot_role_engine.php').decode()
        assert b"'4.220.0'" in z.read(prefix + 'config/version.php')
        assert all(k in exams for k in ['table_month', 'table_grade', 'table_class', 'save_teacher_exam_month', 'teacher_exam_month_show_all', 'نمایش همه ماه‌ها'])
        assert 'teacher_exam_month_manual' in teacher and 'exam_teacher_schedule_month_selection' in teacher
        assert 'exam_student_schedule_selection($effectiveId)' in webhook
        bot_helpers = z.read(prefix + 'includes/bot_helpers.php').decode()
        assert ':: روز:' in bot_helpers and 'ماه " . $month' in bot_helpers
        assert 'teacher_exam_month_show_all' in bot_role

checksums = {}
for line in (ROOT / 'V4.220.0-SHA256SUMS.txt').read_text().splitlines():
    digest, name = line.split('  ', 1)
    checksums[name] = digest
for name in [SITE.name, DESKTOP.name]:
    assert checksums[name] == hashlib.sha256((ROOT / name).read_bytes()).hexdigest()
print('V4.220 and V2.144 exam-month correction packages passed.')
