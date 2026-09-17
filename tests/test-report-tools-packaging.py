import unittest, json, hashlib, subprocess
from pathlib import Path
from zipfile import ZipFile
ROOT=Path(__file__).resolve().parents[1]
BASE='3ed3d70924a5615a2708578a6486d275967340e2'
FILES=json.loads((ROOT/'scripts/report-tools-files.json').read_text())
ARCHIVES=[('SITE-FIX-v4.152.0-report-tools.zip','site-update-v4.152.0/'),('SchoolDeskPro-FIX-v2.83.0-report-tools.zip','SchoolDeskPro/www/')]
class ReportTools(unittest.TestCase):
 def test_exact_payload(self):
  for name,prefix in ARCHIVES:
   with ZipFile(ROOT/name) as z:
    self.assertIsNone(z.testzip());self.assertEqual(set(z.namelist()),{prefix+p for p in FILES})
    for p in FILES:self.assertEqual(z.read(prefix+p),(ROOT/'update-v4.152.0'/p).read_bytes())
 def test_hashes(self):
  for line in (ROOT/'REPORT-TOOLS-SHA256SUMS.txt').read_text().splitlines():
   digest,name=line.split();self.assertEqual(hashlib.sha256((ROOT/name).read_bytes()).hexdigest(),digest)
 def test_old_archives_unchanged(self):
  for raw in subprocess.check_output(['git','ls-tree','-rz',BASE],cwd=ROOT).split(b'\0'):
   if not raw:continue
   meta,name=raw.split(b'\t',1);path=name.decode()
   if path.endswith('.zip'):
    old=subprocess.check_output(['git','cat-file','blob',meta.split()[2].decode()],cwd=ROOT)
    self.assertEqual(hashlib.sha256(old).digest(),hashlib.sha256((ROOT/path).read_bytes()).digest(),path)
 def test_scope(self):
  self.assertEqual(len(FILES),7);self.assertEqual(FILES,sorted(set(FILES)))
  self.assertFalse(any(p.startswith(('uploads/','data/','config/','sql/')) or p.endswith('.exe') for p in FILES))
  self.assertNotIn('import-photos.php',FILES)
 def test_real_controllers_and_legacy_worker(self):
  for file,permission in [('import.php','import_data'),('grade-entry-management.php','manage_reports')]:
   s=(ROOT/'update-v4.152.0'/file).read_text()
   self.assertNotIn('unavailable_route',s)
   self.assertLess(s.index("require_permission('"+permission+"')"),s.index("/includes/header.php"))
   self.assertIn('verify_csrf',s)
  worker=(ROOT/'update-v4.152.0/cron/import-queue-worker.php').read_text()
  self.assertNotIn('UPDATE',worker);self.assertNotIn('sleep(',worker)
  self.assertIn('PHP_SAPI',worker)
if __name__=='__main__':unittest.main()
