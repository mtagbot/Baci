"""Regression guard: both delivered platform ZIPs must be patch-only."""
from pathlib import Path
from zipfile import ZipFile
import unittest

ROOT = Path(__file__).resolve().parent.parent
FILES = {
    'reports-lists.php',
    'includes/docx_class_list.php',
    'includes/docx_school_list.php',
    'assets/templates/school-students.docx',
}


class RosterPackages(unittest.TestCase):
    def check_archive(self, archive, prefix):
        with ZipFile(ROOT / archive) as z:
            self.assertIsNone(z.testzip())
            self.assertEqual(len(z.infolist()), 4)
            self.assertEqual(set(z.namelist()), {prefix + name for name in FILES})
            for name in FILES:
                self.assertEqual(z.read(prefix + name), (ROOT / 'update-v4.152.0' / name).read_bytes())

    def test_site_only_changed_files(self):
        self.check_archive('SITE-UPDATE-v4.152.0.zip', 'site-update-v4.152.0/')

    def test_site_alias_only_changed_files(self):
        self.check_archive('MODIFIED-FILES-v4.152.0.zip', 'update-v4.152.0/')

    def test_desktop_only_changed_files(self):
        self.check_archive('SchoolDeskPro-v2.83.0-win64.zip', 'SchoolDeskPro/www/')

    def test_spacing_hotfixes_ship_only_one_file(self):
        for archive, prefix in [
            ('SITE-FIX-v4.152.0-name-spacing.zip', 'site-update-v4.152.0/'),
            ('SchoolDeskPro-FIX-v2.83.0-name-spacing.zip', 'SchoolDeskPro/www/'),
        ]:
            with self.subTest(archive=archive), ZipFile(ROOT / archive) as z:
                name = 'includes/docx_school_list.php'
                self.assertIsNone(z.testzip())
                self.assertEqual(z.namelist(), [prefix + name])
                self.assertEqual(z.read(prefix + name), (ROOT / 'update-v4.152.0' / name).read_bytes())

    def test_platform_payloads_identical(self):
        with ZipFile(ROOT / 'SITE-UPDATE-v4.152.0.zip') as site, ZipFile(ROOT / 'SchoolDeskPro-v2.83.0-win64.zip') as desktop:
            for name in FILES:
                self.assertEqual(site.read('site-update-v4.152.0/' + name), desktop.read('SchoolDeskPro/www/' + name))


if __name__ == '__main__':
    unittest.main()
