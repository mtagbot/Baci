"""Do not accidentally ship the entire app, overwrite data or lose rollback."""
from pathlib import Path
from zipfile import ZipFile
import hashlib
import json
import unittest
ROOT = Path(__file__).resolve().parent.parent
WEB = 'reports/'   # desktop web root since the reports migration
FILES = {'attendance-scanner.php', 'attendance-scanner-legacy.php',
         'assets/js/attendance-scanner-light.js', 'assets/js/attendance-decoder-worker.js',
         # v4.152.0-camera9-sounds: the recorded voice alerts of the scanner
         'assets/audio/net.ogg', 'assets/audio/hzr.ogg', 'assets/audio/tkhr.ogg'}
SOUNDS = ('assets/audio/net.ogg', 'assets/audio/hzr.ogg', 'assets/audio/tkhr.ogg')

class ScannerPackages(unittest.TestCase):
    def check_archive(self, archive, prefix):
        with ZipFile(ROOT / archive) as z:
            self.assertIsNone(z.testzip())
            self.assertEqual(len(z.infolist()), 7)
            self.assertEqual(set(z.namelist()), {prefix + name for name in FILES})
            for name in FILES:
                self.assertEqual(z.read(prefix + name), (ROOT / 'update-v4.152.0' / name).read_bytes())

    def test_site_payload(self):
        self.check_archive('SITE-FIX-v4.152.0-scanner.zip', 'site-update-v4.152.0/')

    def test_desktop_payload(self):
        self.check_archive('SchoolDeskPro-FIX-v2.83.0-scanner.zip', 'SchoolDeskPro/' + WEB)

    def test_voice_alerts_travel_as_real_ogg_files(self):
        """The three recorded alerts must be the uploaded recordings, verbatim:
        a truncated or re-encoded file would silence the scanner on the phone."""
        for name in SOUNDS:
            packaged = (ROOT / 'update-v4.152.0' / name).read_bytes()
            self.assertTrue(packaged.startswith(b'OggS'), name)
            self.assertEqual(packaged, (ROOT / name.rsplit('/', 1)[-1]).read_bytes(),
                             name + ': the packaged recording differs from the uploaded one')
        with ZipFile(ROOT / 'SITE-FIX-v4.152.0-scanner.zip') as z:
            for name in SOUNDS:
                data = z.read('site-update-v4.152.0/' + name)
                self.assertTrue(data.startswith(b'OggS'), name)
                self.assertEqual(data, (ROOT / 'update-v4.152.0' / name).read_bytes(), name)

    def test_backup_exact_hash_and_original(self):
        backup = (ROOT / 'update-v4.152.0/attendance-scanner-legacy.php').read_bytes()
        self.assertEqual(backup, (ROOT / 'update-v4.94.0/attendance-scanner.php').read_bytes())
        self.assertEqual(hashlib.sha256(backup).hexdigest(), 'e64f8374c4ab8aaf23a00078d49ab9993bb12ce5ce33c599025265588b534d6a')

    def test_online_package_is_the_same_four_files_plus_manifest(self):
        with ZipFile(ROOT / 'SchoolDeskPro-UPDATE-2.83.0-scanner-focus.zip') as z:
            self.assertIsNone(z.testzip())
            expected = {'SchoolDeskPro/' + WEB + name for name in FILES} | {'SchoolDeskPro/DESKTOP-UPDATE.json'}
            self.assertEqual(set(z.namelist()), expected)
            for name in FILES:
                self.assertEqual(z.read('SchoolDeskPro/' + WEB + name),
                                 (ROOT / 'update-v4.152.0' / name).read_bytes())
            meta = json.loads(z.read('SchoolDeskPro/DESKTOP-UPDATE.json'))
            self.assertEqual(meta['version'], '2.83.0-scanner-focus')
            self.assertTrue(meta['notes'].strip())
            self.assertEqual(set(meta['files']), set(FILES))
            for name in FILES:
                data = (ROOT / 'update-v4.152.0' / name).read_bytes()
                self.assertEqual(meta['files'][name]['sha256'], hashlib.sha256(data).hexdigest())
                self.assertEqual(meta['files'][name]['bytes'], len(data))
            # an online package must never smuggle launcher/config/data payloads
            for name in z.namelist():
                base = name.rsplit('/', 1)[-1].lower()
                self.assertNotIn(base, {'schooldeskpro.exe', 'php.ini', 'database.php', 'release.php'})

    def test_platforms_identical(self):
        with ZipFile(ROOT / 'SITE-FIX-v4.152.0-scanner.zip') as site, ZipFile(ROOT / 'SchoolDeskPro-FIX-v2.83.0-scanner.zip') as desktop:
            for name in FILES:
                self.assertEqual(site.read('site-update-v4.152.0/' + name), desktop.read('SchoolDeskPro/' + WEB + name))

if __name__ == '__main__':
    unittest.main()
