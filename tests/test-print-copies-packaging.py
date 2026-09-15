"""Archives must contain exactly the changed printing pages, byte-for-byte."""
from pathlib import Path
from zipfile import ZipFile
import unittest
ROOT = Path(__file__).resolve().parent.parent
FILES = {'attendance-tags.php', 'entry-cards.php'}
ARCHIVES = [('SITE-FIX-v4.152.0-print-copies.zip', 'site-update-v4.152.0/'),
            ('SchoolDeskPro-FIX-v2.83.0-print-copies.zip', 'SchoolDeskPro/www/')]


class PrintingPackages(unittest.TestCase):
    def test_site_manifest(self):
        self.check_manifest(*ARCHIVES[0])

    def test_desktop_manifest(self):
        self.check_manifest(*ARCHIVES[1])

    def check_manifest(self, name, prefix):
        with ZipFile(ROOT / name) as z:
            self.assertIsNone(z.testzip())
            self.assertEqual(len(z.infolist()), 2)
            self.assertEqual(set(z.namelist()), {prefix + f for f in FILES})

    def test_site_matches_tested_source(self):
        self.check_content(*ARCHIVES[0])

    def test_desktop_matches_tested_source(self):
        self.check_content(*ARCHIVES[1])

    def check_content(self, name, prefix):
        with ZipFile(ROOT / name) as z:
            for f in FILES:
                self.assertEqual(z.read(prefix + f), (ROOT / 'update-v4.152.0' / f).read_bytes())


if __name__ == '__main__':
    unittest.main()
