import hashlib, importlib.util, unittest
from pathlib import Path
from zipfile import ZipFile
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('release',ROOT/'scripts/release-svg-responsive.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class Packaging(unittest.TestCase):
 def test_historical_archives_untouched(self): m.check_history()
 def test_exact_minimal_payload(self):
  for name,prefix in m.ARCHIVES:
   with ZipFile(ROOT/name) as z:
    self.assertIsNone(z.testzip());self.assertEqual(len(z.namelist()),53)
    self.assertEqual(set(z.namelist()),{prefix+f for f in m.FILES})
    for f in m.FILES:self.assertEqual(z.read(prefix+f),(ROOT/'update-v4.152.0'/f).read_bytes())
 def test_checksums(self):
  for line in (ROOT/'SVG-RESPONSIVE-SHA256SUMS.txt').read_text().splitlines():
   digest,name=line.split();self.assertEqual(digest,m.sha(ROOT/name))
 def test_no_user_data_runtime_or_rollback(self):
  prohibited=('uploads/','data/','backups/','vendor/','config.php','installer.php','attendance-scanner-legacy.php','version.php','.exe','.sqlite','.db')
  for f in m.FILES:self.assertFalse(any(f.startswith(p) or f.endswith(p) for p in prohibited),f)
if __name__=='__main__':unittest.main()
