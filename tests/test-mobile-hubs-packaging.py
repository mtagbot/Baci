"""Exact minimal payload, immutable historical archives, and retained permission gates."""
import unittest, json, hashlib, subprocess
from pathlib import Path
from zipfile import ZipFile
ROOT=Path(__file__).resolve().parents[1]
BASE='1ad71484e8de8523fce642f9ea5b9329134b2047'
FILES=json.loads((ROOT/'scripts/mobile-hubs-files.json').read_text())
ARCHIVES=[('SITE-FIX-v4.152.0-mobile-hubs.zip','site-update-v4.152.0/'),('SchoolDeskPro-FIX-v2.83.0-mobile-hubs.zip','SchoolDeskPro/www/')]
class MobileHubs(unittest.TestCase):
 def test_exact_payload(self):
  for name,prefix in ARCHIVES:
   with ZipFile(ROOT/name) as z:
    self.assertIsNone(z.testzip());self.assertEqual(set(z.namelist()),{prefix+p for p in FILES})
    for p in FILES:self.assertEqual(z.read(prefix+p),subprocess.check_output(['git','show','3ed3d70924a5615a2708578a6486d275967340e2:update-v4.152.0/'+p],cwd=ROOT))
 def test_hashes(self):
  for line in (ROOT/'MOBILE-HUBS-SHA256SUMS.txt').read_text().splitlines():
   digest,name=line.split();self.assertEqual(hashlib.sha256((ROOT/name).read_bytes()).hexdigest(),digest)
 def test_old_archives_unchanged(self):
  for raw in subprocess.check_output(['git','ls-tree','-rz',BASE],cwd=ROOT).split(b'\0'):
   if not raw:continue
   meta,name=raw.split(b'\t',1);path=name.decode()
   if path.endswith('.zip'):
    blob=meta.split()[2].decode();old=subprocess.check_output(['git','cat-file','blob',blob],cwd=ROOT)
    self.assertEqual(hashlib.sha256(old).digest(),hashlib.sha256((ROOT/path).read_bytes()).digest(),path)
 def test_scope(self):
  self.assertEqual(len(FILES),12);self.assertEqual(FILES,sorted(set(FILES)))
  self.assertFalse(any(p.startswith(('uploads/','data/','config/','sql/')) or p.endswith('.exe') for p in FILES))
  for p in ['import.php','grade-entry-management.php','import-photos.php']:self.assertNotIn(p,FILES,'Do not ship test stubs over unavailable modules')
  self.assertTrue((ROOT/'update-v4.152.0/assets/js/school-ui.js').read_bytes().endswith((ROOT/'update-v4.152.0/assets/js/school-navigation.js').read_bytes()))
 def test_permission_gates(self):
  with ZipFile(ROOT/'Release_V1.0-Site.zip') as z:
   for p in ['courses-management.php','reports-management.php','student-recovery.php','messages-management.php','other-settings.php']:
    old=z.read(p).decode().split("require_once __DIR__ . '/includes/header.php';")[0]
    new=(ROOT/'update-v4.152.0'/p).read_text().split("require_once __DIR__ . '/includes/management_hub.php';")[0]
    self.assertEqual(old,new,p)
if __name__=='__main__':unittest.main()
