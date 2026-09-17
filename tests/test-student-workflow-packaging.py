from pathlib import Path
from zipfile import ZipFile
import importlib.util,struct,unittest
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('release',ROOT/'scripts/release-student-workflow.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class Package(unittest.TestCase):
 def test_history(self):m.history()
 def test_exact_payload(self):
  for name,prefix,desktop in m.PACKAGES:
   with ZipFile(ROOT/name) as z:
    self.assertIsNone(z.testzip());expected={prefix+f for f in m.FILES}
    if desktop:expected.add('SchoolDeskPro/SchoolDeskPro.exe')
    self.assertEqual(set(z.namelist()),expected)
    for f in m.FILES:self.assertEqual(z.read(prefix+f),(ROOT/'update-v4.152.0'/f).read_bytes())
 def test_checksums(self):
  for line in (ROOT/'STUDENT-WORKFLOW-SHA256SUMS.txt').read_text().splitlines():
   digest,name=line.split();self.assertEqual(digest,m.sha(ROOT/name))
 def test_windows_executable(self):
  with ZipFile(ROOT/m.PACKAGES[1][0])as z:b=z.read('SchoolDeskPro/SchoolDeskPro.exe')
  self.assertEqual(b[:2],b'MZ');pe=struct.unpack_from('<I',b,60)[0];self.assertEqual(b[pe:pe+4],b'PE\0\0');self.assertEqual(struct.unpack_from('<H',b,pe+4)[0],0x8664)
  self.assertEqual(b,(ROOT/'.cache/student-workflow/SchoolDeskPro.exe').read_bytes())
 def test_no_data_or_settings(self):
  for f in m.FILES:self.assertFalse(f.startswith(('uploads/','data/','backups/','config/','sql/')));self.assertNotIn('legacy',f)
 def test_navigation_bundle_parity(self):
  self.assertTrue((ROOT/'update-v4.152.0/assets/js/school-ui.js').read_bytes().endswith((ROOT/'update-v4.152.0/assets/js/school-navigation.js').read_bytes()))
if __name__=='__main__':unittest.main()
