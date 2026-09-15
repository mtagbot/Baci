"""Do not accidentally ship the entire app, overwrite data or lose rollback."""
from pathlib import Path
from zipfile import ZipFile
import hashlib
import unittest
ROOT = Path(__file__).resolve().parent.parent
FILES = {'attendance-scanner.php', 'attendance-scanner-legacy.php',
         'assets/js/attendance-scanner-light.js', 'assets/js/attendance-decoder-worker.js'}

class ScannerPackages(unittest.TestCase):
    def check_archive(self, archive, prefix):
        with ZipFile(ROOT / archive) as z:
            self.assertIsNone(z.testzip())
            self.assertEqual(len(z.infolist()), 4)
            self.assertEqual(set(z.namelist()), {prefix + name for name in FILES})
            for name in FILES:
                self.assertEqual(z.read(prefix + name), (ROOT / 'update-v4.152.0' / name).read_bytes())

    def test_site_payload(self):
        self.check_archive('SITE-FIX-v4.152.0-scanner.zip', 'site-update-v4.152.0/')

    def test_desktop_payload(self):
        self.check_archive('SchoolDeskPro-FIX-v2.83.0-scanner.zip', 'SchoolDeskPro/www/')

    def test_backup_exact_hash_and_original(self):
        backup = (ROOT / 'update-v4.152.0/attendance-scanner-legacy.php').read_bytes()
        self.assertEqual(backup, (ROOT / 'update-v4.94.0/attendance-scanner.php').read_bytes())
        self.assertEqual(hashlib.sha256(backup).hexdigest(), 'e64f8374c4ab8aaf23a00078d49ab9993bb12ce5ce33c599025265588b534d6a')

    def test_platforms_identical(self):
        with ZipFile(ROOT / 'SITE-FIX-v4.152.0-scanner.zip') as site, ZipFile(ROOT / 'SchoolDeskPro-FIX-v2.83.0-scanner.zip') as desktop:
            for name in FILES:
                self.assertEqual(site.read('site-update-v4.152.0/' + name), desktop.read('SchoolDeskPro/www/' + name))

if __name__ == '__main__':
    unittest.main()
