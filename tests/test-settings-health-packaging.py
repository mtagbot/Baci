import unittest, json, hashlib, subprocess
from pathlib import Path
from zipfile import ZipFile
ROOT=Path(__file__).resolve().parents[1]
BASE='b60156dc89210def7408643aa46f8f859778ab15'
FILES=json.loads((ROOT/'scripts/settings-health-files.json').read_text())
ARCHIVES=[('SITE-FIX-v4.152.0-settings-health.zip','site-update-v4.152.0/'),('SchoolDeskPro-FIX-v2.83.0-settings-health.zip','SchoolDeskPro/www/')]
class SettingsHealth(unittest.TestCase):
 def test_exact_payload(self):
  for name,prefix in ARCHIVES:
   with ZipFile(ROOT/name) as z:
    self.assertIsNone(z.testzip());self.assertEqual(set(z.namelist()),{prefix+p for p in FILES})
    for p in FILES:self.assertEqual(z.read(prefix+p),(ROOT/'update-v4.152.0'/p).read_bytes())
 def test_hashes(self):
  for line in (ROOT/'SETTINGS-HEALTH-SHA256SUMS.txt').read_text().splitlines():
   digest,name=line.split();self.assertEqual(hashlib.sha256((ROOT/name).read_bytes()).hexdigest(),digest)
 def test_old_archives_unchanged(self):
  for raw in subprocess.check_output(['git','ls-tree','-rz',BASE],cwd=ROOT).split(b'\0'):
   if not raw:continue
   meta,name=raw.split(b'\t',1);path=name.decode()
   if path.endswith('.zip'):
    old=subprocess.check_output(['git','cat-file','blob',meta.split()[2].decode()],cwd=ROOT)
    self.assertEqual(hashlib.sha256(old).digest(),hashlib.sha256((ROOT/path).read_bytes()).digest(),path)
 def test_scope(self):
  self.assertEqual(len(FILES),5);self.assertEqual(FILES,sorted(set(FILES)))
  self.assertFalse(any(p.startswith(('uploads/','data/','config/','sql/')) or p.endswith('.exe') for p in FILES))
  self.assertNotIn('import-photos.php',FILES)
 def test_scope_and_driver_guards(self):
  self.assertNotIn('desk-sync.php',FILES) # Different site/desktop controllers are preserved.
  header=(ROOT/'update-v4.152.0/includes/header.php').read_text()
  for path in ['desk-sync.php','db-optimizer.php','admins.php']:
   self.assertNotIn('href="'+path+'" class="sidebar-item',header)
  health=(ROOT/'update-v4.152.0/db-optimizer.php').read_text()
  self.assertIn("require_permission('system_settings')",health)
  self.assertIn('verify_csrf',health)
  self.assertNotIn('ENGINE=InnoDB',health)
  self.assertNotIn('CONVERT TO CHARACTER SET',health)
if __name__=='__main__':unittest.main()
