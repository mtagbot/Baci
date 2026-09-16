from pathlib import Path
from zipfile import ZipFile
import unittest,hashlib
ROOT=Path(__file__).resolve().parent.parent
FILES={'entry-card-logo.php','entry-cards.php','includes/card_face.php','includes/card_custom_styles.php','assets/js/card-custom.js'}
class Packages(unittest.TestCase):
 def check(self,name,prefix):
  with ZipFile(ROOT/name) as z:
   self.assertIsNone(z.testzip());self.assertEqual(len(z.infolist()),5);self.assertEqual(set(z.namelist()),{prefix+f for f in FILES})
   for f in FILES:self.assertEqual(z.read(prefix+f),(ROOT/'update-v4.152.0'/f).read_bytes())
 def test_site(self):self.check('SITE-FIX-v4.152.0-custom-card-logo.zip','site-update-v4.152.0/')
 def test_desktop(self):self.check('SchoolDeskPro-FIX-v2.83.0-custom-card-logo.zip','SchoolDeskPro/www/')
 def test_prior_custom_unchanged(self):
  for f,d in [('SITE-FIX-v4.152.0-custom-card.zip','ff6e228e4c586a83248b907f97e95ef3b3db0aced55a26074bf03dc16e87e8c6'),('SchoolDeskPro-FIX-v2.83.0-custom-card.zip','b1bd50241c178a5262d3a6da0c6fe6c13d8685fec4903b85c7b57f5e41d2b3c3')]:self.assertEqual(hashlib.sha256((ROOT/f).read_bytes()).hexdigest(),d)
if __name__=='__main__':unittest.main()
