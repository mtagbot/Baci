import hashlib,importlib.util,unittest,zipfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('builder',ROOT/'scripts/release-ui-print.py');b=importlib.util.module_from_spec(spec);spec.loader.exec_module(b)
class Corrective(unittest.TestCase):
 def test_exact_minimal_files(self):
  for name,prefix in b.ARCHIVES:
   with zipfile.ZipFile(ROOT/name) as z:
    self.assertIsNone(z.testzip());self.assertEqual(set(z.namelist()),{prefix+f for f in b.FILES})
    for f in b.FILES:self.assertEqual(z.read(prefix+f),(ROOT/'update-v4.152.0'/f).read_bytes())
 def test_no_live_state_installer_or_legacy_scanner(self):
  self.assertEqual(len(b.FILES),24)
  self.assertTrue(all(not f.startswith(('uploads/','config/','sql/','backups/')) for f in b.FILES))
  self.assertNotIn('attendance-scanner-legacy.php',b.FILES)
  self.assertNotIn('installer.php',b.FILES)
  self.assertIn("if (is_file(__DIR__.'/install_guard.php')) require_once __DIR__.'/install_guard.php';",(ROOT/'update-v4.152.0/includes/functions.php').read_text())
 def test_checksums(self):
  for line in (ROOT/'UI-PRINT-SHA256SUMS.txt').read_text().splitlines():
   sha,name=line.split();self.assertEqual(hashlib.sha256((ROOT/name).read_bytes()).hexdigest(),sha)
if __name__=='__main__':unittest.main()
