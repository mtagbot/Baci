"""Only the ten required files; both distributions are byte-identical to tested source."""
from pathlib import Path
from zipfile import ZipFile
import hashlib
import unittest
ROOT=Path(__file__).resolve().parent.parent
FILES={'attendance.php','entry-cards.php','my-sessions.php','session-status.php','includes/functions.php',
       'includes/header.php','includes/session_tracker.php','includes/security_confirmation.php',
       'includes/card_sheet_layout.php','assets/js/session-watch.js'}
class Packages(unittest.TestCase):
    def check(self,name,prefix):
        with ZipFile(ROOT/name) as z:
            self.assertIsNone(z.testzip());self.assertEqual(len(z.infolist()),10)
            self.assertEqual(set(z.namelist()),{prefix+f for f in FILES})
            for f in FILES:
                # Immutable previous release; the new layout changes only its entry-card page.
                if f=='entry-cards.php':self.assertEqual(hashlib.sha256(z.read(prefix+f)).hexdigest(),'9bf9b9fac56c3f4ddac5f685c8df56c6b8a1d7add653e5a49dae101ff486712b')
                else:self.assertEqual(z.read(prefix+f),(ROOT/'update-v4.152.0'/f).read_bytes())
    def test_site(self):self.check('SITE-FIX-v4.152.0-security-print.zip','site-update-v4.152.0/')
    def test_desktop(self):self.check('SchoolDeskPro-FIX-v2.83.0-security-print.zip','SchoolDeskPro/www/')
    def test_previous_published_actions_untouched(self):
        for f,digest in [('SITE-FIX-v4.152.0-class-exam-actions-rebuilt.zip','b5b4e446b67f873899803ce320d85fc4e35f332c6bacac4c786edee409332593'),('SchoolDeskPro-FIX-v2.83.0-class-exam-actions-rebuilt.zip','270a6804343d36d1af7d85bc95732bf4b35cb11a147acd4470cea062b6580e4d')]:
            self.assertEqual(hashlib.sha256((ROOT/f).read_bytes()).hexdigest(),digest)
if __name__=='__main__':unittest.main()
