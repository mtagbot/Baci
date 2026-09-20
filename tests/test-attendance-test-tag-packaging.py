#!/usr/bin/env python3
"""The «تگ آزمایشی» correction must ship exactly its two files — and reach a fresh install.

It is also the guard that the option really is inside the shipped bytes (a page that
renders in the repo but not in the ZIP would be a false green) and that the corrective
is wired into the cumulative package and the full release.
"""
from pathlib import Path
from zipfile import ZipFile
import importlib.util
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]
FILES = ('attendance-tags.php', 'includes/attendance_helpers.php')
SITE_ARCHIVE = ('SITE-FIX-v4.152.0-attendance-test-tag.zip', 'site-update-v4.152.0/')
DESKTOP_ARCHIVE = ('SchoolDeskPro-FIX-v2.83.0-attendance-test-tag.zip', 'SchoolDeskPro/reports/')
ONLINE_ARCHIVE = 'SchoolDeskPro-UPDATE-2.83.0-attendance-test-tag.zip'
WEB = 'reports/'
FORBIDDEN = {'schooldeskpro.exe', 'php.ini', 'database.php', 'release.php',
             'desk-sync-key.php', 'installed.lock'}


def load(name):
    spec = importlib.util.spec_from_file_location(name.replace('-', '_'), ROOT / 'scripts' / name)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class TestTagPackages(unittest.TestCase):
    def payload(self):
        return {name: (ROOT / 'update-v4.152.0' / name).read_bytes() for name in FILES}

    def check_archive(self, archive, prefix):
        with ZipFile(ROOT / archive) as z:
            self.assertIsNone(z.testzip())
            self.assertEqual(len(z.infolist()), len(FILES))
            self.assertEqual(set(z.namelist()), {prefix + name for name in FILES})
            for name, data in self.payload().items():
                self.assertEqual(z.read(prefix + name), data, name)
            for name in z.namelist():
                self.assertNotIn(name.rsplit('/', 1)[-1].lower(), FORBIDDEN, name)

    def test_01_site_payload_is_the_tested_source(self):
        self.check_archive(*SITE_ARCHIVE)

    def test_02_desktop_payload_is_the_tested_source(self):
        self.check_archive(*DESKTOP_ARCHIVE)

    def test_03_platforms_are_identical(self):
        with ZipFile(ROOT / SITE_ARCHIVE[0]) as site, ZipFile(ROOT / DESKTOP_ARCHIVE[0]) as desktop:
            for name in FILES:
                self.assertEqual(site.read(SITE_ARCHIVE[1] + name), desktop.read(DESKTOP_ARCHIVE[1] + name))

    def test_04_online_package_has_the_manifest(self):
        payload = self.payload()
        with ZipFile(ROOT / ONLINE_ARCHIVE) as z:
            self.assertIsNone(z.testzip())
            expected = {'SchoolDeskPro/' + WEB + name for name in FILES} | {'SchoolDeskPro/DESKTOP-UPDATE.json'}
            self.assertEqual(set(z.namelist()), expected)
            meta = json.loads(z.read('SchoolDeskPro/DESKTOP-UPDATE.json'))
            self.assertEqual(meta['version'], '2.83.0-attendance-test-tag')
            self.assertTrue(meta['notes'].strip())
            self.assertIn('تگ آزمایشی', meta['notes'])
            self.assertEqual(set(meta['files']), set(FILES))
            for name, data in payload.items():
                self.assertEqual(meta['files'][name]['bytes'], len(data))
                self.assertEqual(meta['files'][name]['sha256'], __import__('hashlib').sha256(data).hexdigest())
                self.assertEqual(z.read('SchoolDeskPro/' + WEB + name), data)
            for name in z.namelist():
                self.assertNotIn(name.rsplit('/', 1)[-1].lower(), FORBIDDEN, name)

    def test_05_cumulative_and_full_release_carry_it(self):
        payload = self.payload()
        with ZipFile(ROOT / 'SITE-FIX-v4.152.0-cumulative.zip') as z:
            for name, data in payload.items():
                self.assertEqual(z.read('site-update-v4.152.0/' + name), data, name)
        with ZipFile(ROOT / 'SchoolDeskPro-FIX-v2.83.0-cumulative.zip') as z:
            for name, data in payload.items():
                self.assertEqual(z.read('SchoolDeskPro/' + WEB + name), data, name)
        with ZipFile(ROOT / 'Release_V1.0-Site.zip') as z:
            for name, data in payload.items():
                self.assertEqual(z.read(name), data, name)
        with ZipFile(ROOT / 'Release_V1.0-Desktop.zip') as z:
            for name, data in payload.items():
                self.assertEqual(z.read('SchoolDeskPro/www/' + name), data, name)

    def test_06_builders_are_wired(self):
        builder = load('build-full-release.py')
        self.assertIn('attendance-test-tag', builder.SITE_CORRECTIVES)
        self.assertIn('attendance-test-tag', builder.DESKTOP_CORRECTIVES)
        cumulative = load('release-cumulative.py')
        self.assertIn(SITE_ARCHIVE[0], cumulative.PARTS['site'])
        self.assertIn(DESKTOP_ARCHIVE[0], cumulative.PARTS['desktop'])
        packages, payload, _, _ = builder.corrective_payload('site')
        self.assertIn('attendance-tags.php', payload)
        self.assertIn('includes/attendance_helpers.php', payload)
        for name, data in self.payload().items():
            self.assertEqual(payload[name], data, name)

    def test_07_the_option_is_inside_the_shipped_bytes(self):
        payload = self.payload()
        tags = payload['attendance-tags.php'].decode('utf-8')
        helpers = payload['includes/attendance_helpers.php'].decode('utf-8')
        self.assertIn('openTestPrint', tags)
        self.assertIn("params.set('test', '1')", tags)
        self.assertIn('$testSheet', tags)
        self.assertIn('att_test_tag_print_rows', tags)
        self.assertIn('MTAG-ATT-TEST:', helpers)
        self.assertIn('att_process_test_scan', helpers)
        self.assertIn('att_test_notify_management', helpers)
        self.assertIn('att_management_chats', helpers)
        # The real tag format and its parser must stay exactly as they were.
        self.assertIn("return 'MTAG-ATT:' . (int)$studentId . ':' . $token;", helpers)
        self.assertIn(r"preg_match('/^MTAG-ATT:(\d+):([a-f0-9]{16,64})$/i'", helpers)
        # The test tag must never write attendance.
        test_scan = helpers[helpers.index('function att_process_test_scan'):helpers.index('function att_process_scan')]
        self.assertNotIn('INSERT INTO student_attendance', test_scan)


if __name__ == '__main__':
    unittest.main(verbosity=2)
