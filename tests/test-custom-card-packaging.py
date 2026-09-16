from pathlib import Path
from zipfile import ZipFile
import unittest,hashlib
ROOT=Path(__file__).resolve().parent.parent
FILES={'entry-cards.php','includes/card_face.php','includes/card_custom_styles.php','assets/js/card-custom.js'}
class Packages(unittest.TestCase):
 def check(self,name,prefix):
  with ZipFile(ROOT/name) as z:
   self.assertIsNone(z.testzip());self.assertEqual(len(z.infolist()),4);self.assertEqual(set(z.namelist()),{prefix+f for f in FILES})
   for f in FILES:self.assertEqual(z.read(prefix+f),(ROOT/'update-v4.152.0'/f).read_bytes())
 def test_site(self):self.check('SITE-FIX-v4.152.0-custom-card.zip','site-update-v4.152.0/')
 def test_desktop(self):self.check('SchoolDeskPro-FIX-v2.83.0-custom-card.zip','SchoolDeskPro/www/')
 def test_prior_security_archives_unchanged(self):
  for f,digest in [('SITE-FIX-v4.152.0-security-print.zip','8e8be13daa2342f04a22878f2e47d3a810944cf2591efd983d5a8b13172ce2ca'),('SchoolDeskPro-FIX-v2.83.0-security-print.zip','7e1a92026899b895717896f97fa8049407478a250d575d1ff11e0d41244fb2b9')]:self.assertEqual(hashlib.sha256((ROOT/f).read_bytes()).hexdigest(),digest)
if __name__=='__main__':unittest.main()
