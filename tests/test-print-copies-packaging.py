"""Archives must contain exactly the changed printing pages, byte-for-byte."""
from pathlib import Path
from zipfile import ZipFile
import unittest
import hashlib
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
                # The historical copy-count release must not be rebuilt for this later physical-layout fix.
                if f == 'entry-cards.php':
                    self.assertEqual(hashlib.sha256(z.read(prefix + f)).hexdigest(), '92c0331e8ce6e56c53c25833efa857325f9c710aa740b5c23efd7c6e3b5e13bf')
                else:
                    self.assertEqual(z.read(prefix + f), (ROOT / 'update-v4.152.0' / f).read_bytes())


if __name__ == '__main__':
    unittest.main()
