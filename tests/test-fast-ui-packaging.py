import unittest,json,hashlib,subprocess
from pathlib import Path
from zipfile import ZipFile
ROOT=Path(__file__).resolve().parents[1]
FILES=json.loads((ROOT/'scripts/fast-ui-files.json').read_text())
class FastUI(unittest.TestCase):
 def test_exact_payload(self):
  for name,prefix,desktop in [('SITE-FIX-v4.152.0-fast-ui.zip','site-update-v4.152.0/',False),('SchoolDeskPro-FIX-v2.83.0-fast-ui.zip','SchoolDeskPro/www/',True)]:
   paths=FILES+(['desk-sync-daemon.php'] if desktop else [])
   with ZipFile(ROOT/name) as z:
    self.assertIsNone(z.testzip());self.assertEqual(set(z.namelist()),{prefix+p for p in paths})
    for p in paths:self.assertEqual(z.read(prefix+p),(ROOT/('desktop-app-v2/patch/www-desk-sync-daemon.php' if p=='desk-sync-daemon.php' else 'update-v4.152.0/'+p)).read_bytes())
 def test_hashes(self):
  for line in (ROOT/'FAST-UI-SHA256SUMS.txt').read_text().splitlines():
   digest,name=line.split();self.assertEqual(hashlib.sha256((ROOT/name).read_bytes()).hexdigest(),digest)
 def test_no_historical_changes(self):
  for name in subprocess.check_output(['git','ls-tree','--name-only','d60ee429b30d324c6986e823fabe07fed9f25b25'],cwd=ROOT,text=True).splitlines():
   if name.endswith('.zip'):
    old=subprocess.check_output(['git','show','d60ee429b30d324c6986e823fabe07fed9f25b25:'+name],cwd=ROOT)
    self.assertEqual(hashlib.sha256(old).digest(),hashlib.sha256((ROOT/name).read_bytes()).digest(),name)
 def test_scope_and_original_fonts(self):
  self.assertEqual(len(FILES),21)
  self.assertFalse(any(p.startswith(('uploads/','data/','config/','sql/')) or p.endswith('.exe') for p in FILES))
  manifest=json.loads((ROOT/'update-v4.152.0/assets/fonts/screen/manifest.json').read_text())
  with ZipFile(ROOT/'Release_V1.0-Site.zip') as z:
   for src,item in manifest.items():
    self.assertEqual(hashlib.sha256(z.read(src)).hexdigest(),item['sha256']);self.assertEqual((ROOT/'update-v4.152.0'/item['url']).read_bytes()[:4],b'wOF2')
 def test_preview_navigation_stays_identical(self):
  self.assertTrue((ROOT/'update-v4.152.0/assets/js/school-ui.js').read_bytes().endswith((ROOT/'update-v4.152.0/assets/js/school-navigation.js').read_bytes()))
if __name__=='__main__':unittest.main()
