from pathlib import Path
from zipfile import ZipFile
import hashlib
import unittest
ROOT=Path(__file__).resolve().parent.parent
FILES={'teacher-panel.php','class-exam-group.php','class-exam-delete.php','exam-print.php','exam-design-api.php','exam-source-api.php','exams.php','includes/class_exam_groups.php','includes/teacher_class_exams.php','includes/admin_class_exams.php','includes/teacher_weekly_schedule.php'}
class Packages(unittest.TestCase):
    def check(self,name,prefix):
        with ZipFile(ROOT/name) as z:
            self.assertIsNone(z.testzip())
            self.assertEqual(set(z.namelist()),{prefix+x for x in FILES})
            self.assertEqual(len(z.infolist()),11)
            for f in FILES:self.assertEqual(z.read(prefix+f),(ROOT/'update-v4.152.0'/f).read_bytes())
    def test_site(self):self.check('SITE-FIX-v4.152.0-class-exam-actions.zip','site-update-v4.152.0/')
    def test_desktop(self):self.check('SchoolDeskPro-FIX-v2.83.0-class-exam-actions.zip','SchoolDeskPro/www/')
    def test_prior_group_archives_untouched(self):
        for f,d in [('SITE-FIX-v4.152.0-class-exam-groups.zip','3ba7e66d078a2f8d21f219f397e18a82a85bb7dd6e0c426ea801c43afd9d62e6'),('SchoolDeskPro-FIX-v2.83.0-class-exam-groups.zip','891f175bf5f72fcea20c6f8f34ae2a6a9f44aeffe0f8fe1fdd0886489f18aa9c')]:self.assertEqual(hashlib.sha256((ROOT/f).read_bytes()).hexdigest(),d)
if __name__=='__main__':unittest.main()
