from pathlib import Path
from zipfile import ZipFile
import unittest
ROOT=Path(__file__).resolve().parent.parent
FILES={'classes.php','teacher-panel.php','class-exam-create.php','exam-print.php',
       'includes/class_exam_helpers.php','includes/docx_class_list.php'}

class StaffPackages(unittest.TestCase):
    def check(self,archive,prefix):
        with ZipFile(ROOT/archive) as z:
            self.assertIsNone(z.testzip())
            self.assertEqual(len(z.infolist()),6)
            self.assertEqual(set(z.namelist()),{prefix+n for n in FILES})
            for name in FILES: self.assertEqual(z.read(prefix+name),(ROOT/'update-v4.152.0'/name).read_bytes())

    def test_site_only_required_files(self):
        self.check('SITE-FIX-v4.152.0-staff-workflows.zip','site-update-v4.152.0/')

    def test_desktop_only_required_files(self):
        self.check('SchoolDeskPro-FIX-v2.83.0-staff-workflows.zip','SchoolDeskPro/www/')

    def test_teacher_word_unchanged_only_pdf_cell_differs(self):
        with ZipFile(ROOT/'SITE-UPDATE-v4.152.0.zip') as z:
            old=z.read('site-update-v4.152.0/includes/docx_class_list.php')
        before=b"htmlspecialchars($classCode, ENT_QUOTES, 'UTF-8'); ?></bdi>"
        after=b"htmlspecialchars(preg_replace('/^(\\d+)\\/(\\d+)$/', '$2/$1', $classCode), ENT_QUOTES, 'UTF-8'); ?></bdi>"
        self.assertEqual(old.count(before),1)
        self.assertEqual((ROOT/'update-v4.152.0/includes/docx_class_list.php').read_bytes(),old.replace(before,after,1))

if __name__=='__main__': unittest.main()
