import unittest,json,hashlib
from pathlib import Path
from zipfile import ZipFile
ROOT=Path(__file__).resolve().parents[1]
FILES=json.loads((ROOT/'scripts/preview-navigation-files.json').read_text())
class Payload(unittest.TestCase):
 def test_payloads(self):
  for name,prefix in [('SITE-FIX-v4.152.0-preview-navigation.zip','site-update-v4.152.0/'),('SchoolDeskPro-FIX-v2.83.0-preview-navigation.zip','SchoolDeskPro/www/')]:
   with ZipFile(ROOT/name)as z:
    self.assertIsNone(z.testzip());self.assertEqual(len(z.namelist()),16);self.assertEqual(set(z.namelist()),{prefix+f for f in FILES})
    for f in FILES:self.assertEqual(z.read(prefix+f),(ROOT/'update-v4.152.0'/f).read_bytes())
 def test_checksums(self):
  for line in (ROOT/'PREVIEW-NAVIGATION-SHA256SUMS.txt').read_text().splitlines():
   digest,name=line.split();self.assertEqual(digest,hashlib.sha256((ROOT/name).read_bytes()).hexdigest())
 def test_previous_release_unchanged(self):
  for name,digest in {'SITE-FIX-v4.152.0-student-workflow.zip':'818c35c52e6af1bc15d3332ff84a4db560ce70af990a5950ff6919cd3aaa35f7','SchoolDeskPro-FIX-v2.83.0-student-workflow.zip':'cb509c2b26f5a3e3f3ff6cf39d9dc7e9a1b7947b3fd52d9ec8bc5542094af95b','Release_V1.0-Site.zip':'acb2c0f8bf89a9fed7fda3959730ee9d3cbf9eaf389e3ca5443b81b571540fd2','Release_V1.0-Desktop.zip':'2a0c649ae5ebbf9e41cc8594fa123b7c7c28625072bb8c4feb5b4354f971d3a1'}.items():self.assertEqual(hashlib.sha256((ROOT/name).read_bytes()).hexdigest(),digest)
 def test_shared_navigation(self):
  self.assertTrue((ROOT/'update-v4.152.0/assets/js/school-ui.js').read_bytes().endswith((ROOT/'update-v4.152.0/assets/js/school-navigation.js').read_bytes()))
  self.assertFalse(any(f.startswith(('data/','uploads/','config/','sql/')) or f.endswith('.exe') for f in FILES))
if __name__=='__main__':unittest.main()
