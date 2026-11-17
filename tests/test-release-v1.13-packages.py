from pathlib import Path
from zipfile import ZipFile
import hashlib, json
ROOT = Path(__file__).resolve().parents[1]

for archive, manifest_name, expected_platform in [
    ('Release_V1.13-Site.zip', 'RELEASE-MANIFEST.json', 'site'),
    ('Release_V1.13-Desktop.zip', 'SchoolDeskPro/RELEASE-MANIFEST.json', 'desktop'),
]:
    with ZipFile(ROOT / archive) as z:
        assert z.testzip() is None
        names = set(z.namelist())
        assert all(not n.startswith('/') and '..' not in Path(n).parts for n in names)
        manifest = json.loads(z.read(manifest_name))
        assert manifest['release'] == 'Release_V1.13'
        assert manifest['platform'] == expected_platform
        assert manifest['site_version'] == '4.219.0'
        assert manifest['desktop_version'] == '2.143.0'
        assert manifest['patches'][-1] == 'update-v4.219.0'
        files = {n for n in names - {manifest_name} if not n.endswith('/')}
        if expected_platform == 'desktop':
            files = {n[len('SchoolDeskPro/'):] for n in files}
            assert 'SchoolDeskPro/SchoolDeskPro.exe' in names
            assert 'SchoolDeskPro/php/php.exe' in names
        assert files == set(manifest['files'])
        for rel in files:
            full = rel if expected_platform == 'site' else 'SchoolDeskPro/' + rel
            blob = z.read(full); item = manifest['files'][rel]
            assert item['bytes'] == len(blob)
            assert item['sha256'] == hashlib.sha256(blob).hexdigest()
        exams_path = 'exams.php' if expected_platform == 'site' else 'SchoolDeskPro/www/exams.php'
        teacher_path = 'teacher-panel.php' if expected_platform == 'site' else 'SchoolDeskPro/www/teacher-panel.php'
        webhook_path = 'includes/bot_webhook_engine.php' if expected_platform == 'site' else 'SchoolDeskPro/www/includes/bot_webhook_engine.php'
        role_path = 'includes/bot_role_engine.php' if expected_platform == 'site' else 'SchoolDeskPro/www/includes/bot_role_engine.php'
        assert all(token in z.read(exams_path).decode() for token in ['table_month', 'table_grade', 'table_class', 'save_teacher_exam_month'])
        assert 'teacher_exam_month_manual' in z.read(teacher_path).decode()
        assert 'exam_student_schedule_selection($effectiveId)' in z.read(webhook_path).decode()
        assert 'teacher_exam_month_selected' in z.read(role_path).decode()
        if expected_platform == 'site':
            settings = z.read('settings.php').decode()
            assert '<input type="file" name="logo_file"' in settings
            assert '<input type="file" name="favicon_file"' in settings
            assert 'width:84px;height:84px;max-width:84px;max-height:84px' in settings
            assert b'<link rel="icon"' in z.read('includes/header.php')
            assert b"'distribution'=>'site'" in z.read('config/release.php')
        else:
            settings = z.read('SchoolDeskPro/www/settings.php').decode()
            assert '<input type="file" name="logo_file"' in settings
            assert '<input type="file" name="favicon_file"' in settings
            assert 'width:84px;height:84px;max-width:84px;max-height:84px' in settings
            assert b'<link rel="icon"' in z.read('SchoolDeskPro/www/includes/header.php')
            assert b"'distribution'=>'desktop'" in z.read('SchoolDeskPro/www/config/release.php')

sums = {}
for line in (ROOT / 'Release_V1.13-SHA256SUMS.txt').read_text().splitlines():
    digest, filename = line.split('  ', 1); sums[filename] = digest
for filename, digest in sums.items():
    assert digest == hashlib.sha256((ROOT/filename).read_bytes()).hexdigest()
print('V1.13 full website and desktop packages passed.')
