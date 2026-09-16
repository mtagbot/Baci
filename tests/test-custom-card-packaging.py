from pathlib import Path
from zipfile import ZipFile
import unittest,hashlib
ROOT=Path(__file__).resolve().parent.parent
FILES={'entry-cards.php','includes/card_face.php','includes/card_custom_styles.php','assets/js/card-custom.js'}
HISTORICAL={'entry-cards.php': '41bcf01cc8c1a8af308f7591cf6b4c5c51af174646bf5699dc6924ef69672357', 'includes/card_face.php': '8142d94ad0986ff161846a9b07a70774a7224d3cc7189297b4a8c8337bc33f1b', 'includes/card_custom_styles.php': '8c15d493e792b3fbf12ac29e92cd8dfd981ed9264bd09ce96563fc4bb9da3cfb', 'assets/js/card-custom.js': '86c3e378cbf8d1c51c8f6d6bbf87af7c5df20356752fd5f18017a25e8a019654'}
class Packages(unittest.TestCase):
 def check(self,name,prefix):
  with ZipFile(ROOT/name) as z:
   self.assertIsNone(z.testzip());self.assertEqual(len(z.infolist()),4);self.assertEqual(set(z.namelist()),{prefix+f for f in FILES})
   for f in FILES:self.assertEqual(hashlib.sha256(z.read(prefix+f)).hexdigest(),HISTORICAL[f])
 def test_site(self):self.check('SITE-FIX-v4.152.0-custom-card.zip','site-update-v4.152.0/')
 def test_desktop(self):self.check('SchoolDeskPro-FIX-v2.83.0-custom-card.zip','SchoolDeskPro/www/')
 def test_prior_security_archives_unchanged(self):
  for f,digest in [('SITE-FIX-v4.152.0-security-print.zip','8e8be13daa2342f04a22878f2e47d3a810944cf2591efd983d5a8b13172ce2ca'),('SchoolDeskPro-FIX-v2.83.0-security-print.zip','7e1a92026899b895717896f97fa8049407478a250d575d1ff11e0d41244fb2b9')]:self.assertEqual(hashlib.sha256((ROOT/f).read_bytes()).hexdigest(),digest)
if __name__=='__main__':unittest.main()
