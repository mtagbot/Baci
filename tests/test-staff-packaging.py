from pathlib import Path
from zipfile import ZipFile
import unittest
import hashlib
ROOT=Path(__file__).resolve().parent.parent
FILES={'classes.php','teacher-panel.php','class-exam-create.php','exam-print.php',
       'includes/class_exam_helpers.php','includes/docx_class_list.php'}

# The delivered archives are immutable; subsequent group work modifies these sources.
HISTORICAL = {
 'teacher-panel.php':'0a4fd077c44d901dfd328fca7ad348a1aec912584249d61c6884551cd20784a5',
 'exam-print.php':'9ce7cf8ab17772ca2c3b3149fd09e00042702e07c8ed9ae96c36dc17fdf1535c',
 'class-exam-create.php':'a57bcd5e29a50e900d0e8d37632692141d4b34f58fdfdfcd9b158d16f3c0a7bd',
}
class StaffPackages(unittest.TestCase):
    def check(self,archive,prefix):
        with ZipFile(ROOT/archive) as z:
            self.assertIsNone(z.testzip())
            self.assertEqual(len(z.infolist()),6)
            self.assertEqual(set(z.namelist()),{prefix+n for n in FILES})
            for name in FILES:
                if name in HISTORICAL: self.assertEqual(hashlib.sha256(z.read(prefix+name)).hexdigest(),HISTORICAL[name])
                else: self.assertEqual(z.read(prefix+name),(ROOT/'update-v4.152.0'/name).read_bytes())

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
