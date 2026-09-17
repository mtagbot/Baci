from pathlib import Path
from zipfile import ZipFile
import hashlib
import unittest

ROOT = Path(__file__).resolve().parent.parent
FILES = {
    'teacher-panel.php', 'class-exam-create.php', 'class-exam-group.php',
    'exam-print.php', 'exam-design-api.php', 'exam-source-api.php', 'exams.php',
    'includes/class_exam_groups.php', 'includes/class_exam_group_choice.php',
    'includes/teacher_class_exams.php', 'includes/admin_class_exams.php',
    'includes/desk_sync.php', 'class-exam-sync-api.php',
}


# Immutable prior release; current sources now implement the follow-up actions.
HISTORICAL = {'teacher-panel.php': '8c73d7839804d7086951a37991c4ba2fc39c200d3e86042687b7f9a894bcf1df', 'class-exam-group.php': 'b8fed175360d36926c0970a68c70ef0f26c96f9187604b40feeaf19607600859', 'exam-print.php': 'e669f42d0f3399245ca0739a4d8857ab75c70891a2a5b67ec08bbdd42a578723', 'exam-design-api.php': 'c5ea2f1c63eb8b8e06a2cf48020fd4db36a58e1c08a2f73cce6b3def66272e32', 'exam-source-api.php': 'fa281e3d7f367f46b86c9be76e14bddffad53bcedbaf6eced1ef3a966ff47d5b', 'exams.php': 'f15c0415b523553892e22ec3f58cf26b9ea6a951e610d7166d02322cc71fc39f', 'includes/class_exam_groups.php': '2f71bad7e01ccf40e1c037e619cc797fa648c00f80a63756d8fda691dfbc0224', 'includes/teacher_class_exams.php': 'bea2d29e649b98d651c809e0159d1fcf10cfb214da0fa0da06f4df5f8ce7676b', 'includes/admin_class_exams.php': 'a9994dc2125d139fd1ed3986a81804c3ffdcc6add59f86e752306d6bf0438e95'}

class ClassGroupPackages(unittest.TestCase):
    def check_archive(self, archive, desktop=False):
        expected = {
            ('SchoolDeskPro/server/' if n == 'class-exam-sync-api.php' else 'SchoolDeskPro/www/') + n
            if desktop else 'site-update-v4.152.0/' + n: n
            for n in FILES
        }
        with ZipFile(ROOT / archive) as z:
            self.assertIsNone(z.testzip())
            self.assertEqual(len(z.infolist()), 13)
            self.assertEqual(set(z.namelist()), set(expected))
            for archived, source in expected.items():
                if source in HISTORICAL: self.assertEqual(hashlib.sha256(z.read(archived)).hexdigest(), HISTORICAL[source])
                else: self.assertEqual(z.read(archived), (ROOT / 'update-v4.152.0' / source).read_bytes())
            self.assertFalse(any('/config/' in n or '/uploads/' in n or n.endswith('.sql') for n in z.namelist()))
            self.assertFalse(any(n.endswith('/desk-sync-api.php') for n in z.namelist()))

    def test_site_exact_changed_files(self):
        self.check_archive('SITE-FIX-v4.152.0-class-exam-groups.zip')

    def test_desktop_runtime_and_server_companion(self):
        self.check_archive('SchoolDeskPro-FIX-v2.83.0-class-exam-groups.zip', True)

    def test_no_bundled_sync_key_and_original_endpoint_preserved(self):
        source = (ROOT / 'update-v4.152.0/class-exam-sync-api.php').read_text()
        self.assertIn('ceg_existing_sync_key()', source)
        self.assertIn("__DIR__.'/desk-sync-api.php'", source)
        self.assertNotRegex(source, r"define\(['\"]DESK_SYNC_KEY['\"],\s*['\"]")
        self.assertNotIn('file_put_contents($path', source)
        local = (ROOT / 'update-v4.152.0/includes/desk_sync.php').read_text()
        for table in ['class_exam_groups', 'class_exam_group_members']:
            self.assertIn("'" + table + "'", source)
            self.assertIn("'" + table + "'", local)

    def test_previous_delivered_archives_immutable(self):
        historical = {
            'SITE-FIX-v4.152.0-staff-workflows.zip': 'afbe275d3526abf4aee6199b66b0e1751ca3f5401fa86639d49ca574b7e86454',
            'SchoolDeskPro-FIX-v2.83.0-staff-workflows.zip': '09d9a4d39e5b2fc0408297604c52bfca5ea4cda7af7a6b0df0791fe4e23ca3a7',
        }
        for name, digest in historical.items():
            self.assertEqual(hashlib.sha256((ROOT / name).read_bytes()).hexdigest(), digest)


if __name__ == '__main__':
    unittest.main()
