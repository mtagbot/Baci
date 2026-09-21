#!/usr/bin/env python3
"""The «ورود مدیر از داخل ربات» correction must ship exactly its two files — and reach a fresh install.

It is also the guard that the flow really is inside the shipped bytes (a flow that
works in the repo but not in the ZIP would be a false green), that the manager
audience rule of the test-tag round still holds in the same engine, and that the
corrective is wired into the cumulative package and both Release zips.
"""
from pathlib import Path
from zipfile import ZipFile
import hashlib
import importlib.util
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]
FILES = ('includes/bot_login_flow.php', 'includes/bot_webhook_engine.php')
SITE_ARCHIVE = ('SITE-FIX-v4.152.0-bot-admin-login.zip', 'site-update-v4.152.0/')
DESKTOP_ARCHIVE = ('SchoolDeskPro-FIX-v2.83.0-bot-admin-login.zip', 'SchoolDeskPro/reports/')
ONLINE_ARCHIVE = 'SchoolDeskPro-UPDATE-2.83.0-bot-admin-login.zip'
WEB = 'reports/'
FORBIDDEN = {'schooldeskpro.exe', 'php.ini', 'database.php', 'release.php',
             'desk-sync-key.php', 'installed.lock'}


def load(name):
    spec = importlib.util.spec_from_file_location(name.replace('-', '_'), ROOT / 'scripts' / name)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class BotAdminLoginPackages(unittest.TestCase):
    def payload(self):
        return {name: (ROOT / 'update-v4.152.0' / name).read_bytes() for name in FILES}

    def check_archive(self, archive, prefix):
        with ZipFile(ROOT / archive) as z:
            self.assertIsNone(z.testzip())
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
            self.assertEqual(meta['version'], '2.83.0-bot-admin-login')
            self.assertIn('نام کاربری', meta['notes'])
            self.assertEqual(set(meta['files']), set(FILES))
            for name, data in payload.items():
                self.assertEqual(meta['files'][name]['bytes'], len(data))
                self.assertEqual(meta['files'][name]['sha256'], hashlib.sha256(data).hexdigest())
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
        self.assertIn('bot-admin-login', builder.SITE_CORRECTIVES)
        self.assertIn('bot-admin-login', builder.DESKTOP_CORRECTIVES)
        cumulative = load('release-cumulative.py')
        self.assertIn(SITE_ARCHIVE[0], cumulative.PARTS['site'])
        self.assertIn(DESKTOP_ARCHIVE[0], cumulative.PARTS['desktop'])
        packages, payload, _, _ = builder.corrective_payload('site')
        for name, data in self.payload().items():
            self.assertEqual(payload[name], data, name)

    def test_07_the_flow_is_inside_the_shipped_bytes(self):
        payload = self.payload()
        flow = payload['includes/bot_login_flow.php'].decode('utf-8')
        engine = payload['includes/bot_webhook_engine.php'].decode('utf-8')
        # the flow itself
        for needle in ('function bot_login_manager_entry', 'function bot_login_admin_by_username',
                       'function bot_login_ask_admin_password'):
            self.assertIn(needle, flow)
        self.assertIn('status=1', flow)                       # a disabled manager can never log in
        # and its wiring in the webhook: national id, teacher entry, serial, personnel code
        self.assertTrue("require_once __DIR__ . '/bot_login_flow.php';" in engine, 'the engine must load the flow')
        self.assertGreaterEqual(engine.count('bot_login_manager_entry($platform, $chatId, $text)'), 5)
        self.assertTrue("if ($step === 'admin_password') {" in engine, 'password step')
        self.assertTrue("role_type, is_active, created_at_jalali) VALUES (?, ?, ?, 'admin', 1, ?)" in engine, 'admin insert')
        self.assertTrue("verify_user_password($text, $admin['password']" in engine, 'password check')
        self.assertTrue('$tries >= 3' in engine, 'three attempts')     # then the step is closed
        self.assertTrue('نام کاربری مدیریت خود را ارسال کنید.' in engine, 'manager hint in the error text')
        # the hidden /start admin_SECRET entry is kept
        self.assertTrue('/^\\/(?:start|admin)' in engine, 'hidden entry')
        # the test-tag round's audience rule must still be intact in the same engine's world
        helpers = (ROOT / 'update-v4.152.0/includes/attendance_helpers.php').read_text(encoding='utf-8')
        self.assertIn("bs.role_type = 'admin'", helpers)
        # the test driver replaces exactly this line, so it may never change silently
        self.assertTrue("$input = file_get_contents('php://input');" in engine, 'the input line the harness replaces')

    def test_08_historical_correctives_are_not_touched(self):
        """This round ships two new files; the packages it does not own must stay
        byte-identical (they are immutable inputs for it). The scanner packages are
        rebuilt in the NEXT round (the recorded alerts), so only the packages no
        later round touches are pinned here."""
        expected = {
            'SITE-FIX-v4.152.0-desk-update.zip': '99366e9daeb0278b323ae1d696a435702c87bc0b57807d0d82f228e4b88e4fae',
            'SchoolDeskPro-FIX-v2.83.0-desk-update.zip': '06a6de4284d0ba22cdb3197cfbb82b0aebc39da4057e4c51e6b2c274f7fcbcaf',
            'SITE-FIX-v4.152.0-attendance-test-tag.zip': '2d967419bc946fd62fd41e4b6ab1c6008b147310bd09ccc77d7d1f71cc269fca',
            'SchoolDeskPro-FIX-v2.83.0-attendance-test-tag.zip': '3e78b6e84d82507e3c955274167d78738355b76aa6e838af43a585b6abb4c900',
            'SchoolDeskPro-UPDATE-2.83.0-attendance-test-tag.zip': '6ebb96db98644963e4b9527cb2f60fe275422894c2229ccca0439ad888eb0867',
        }
        for name, digest in expected.items():
            self.assertEqual(hashlib.sha256((ROOT / name).read_bytes()).hexdigest(), digest, name)


if __name__ == '__main__':
    unittest.main(verbosity=2)
