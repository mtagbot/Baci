"""Desktop online-update correction packages.

Contract (see docs/DESKTOP-ONLINE-UPDATE-FA.md):
  SITE-FIX-v4.152.0-desk-update.zip        prefix site-update-v4.152.0/
  SchoolDeskPro-FIX-v2.83.0-desk-update.zip prefix SchoolDeskPro/

The site package must never publish school data, the desktop package must
never carry config/, data/ or the php runtime, the two shared ZIP readers must
stay byte-identical, and the launcher binary must be a real x86-64 GUI PE.
"""
from pathlib import Path
from zipfile import ZipFile
import hashlib
import unittest

ROOT = Path(__file__).resolve().parent.parent
SITE = ROOT / 'update-v4.152.0'
DESKTOP_SOURCES = {
    'desk-update.php': ROOT / 'desktop-app-v2/patch/www-desk-update.php',
    'includes/desk_update.php': ROOT / 'desktop-app-v2/patch/includes-desk_update.php',
    'includes/desk_update_zip.php': ROOT / 'desktop-app-v2/patch/includes-desk_update_zip.php',
    'includes/desk_update_zip_site.php': SITE / 'includes/desk_update_zip.php',
    'desk-sync-daemon.php': ROOT / 'desktop-app-v2/patch/www-desk-sync-daemon.php',
}
SITE_FILES = {
    'desk-update-api.php': SITE / 'desk-update-api.php',
    'desk-updates.php': SITE / 'desk-updates.php',
    'includes/desk_update_zip.php': SITE / 'includes/desk_update_zip.php',
    'includes/desk_updates_store.php': SITE / 'includes/desk_updates_store.php',
    'includes/management_hub.php': SITE / 'includes/management_hub.php',
}
DESKTOP_FILES = {
    'desk-update.php': DESKTOP_SOURCES['desk-update.php'],
    'includes/desk_update.php': DESKTOP_SOURCES['includes/desk_update.php'],
    'includes/desk_update_zip.php': DESKTOP_SOURCES['includes/desk_update_zip.php'],
    'includes/management_hub.php': SITE / 'includes/management_hub.php',
    'desk-sync-daemon.php': DESKTOP_SOURCES['desk-sync-daemon.php'],
}
FORBIDDEN = ('config/', 'data/', 'php/', 'backups/', 'profile/', 'server/')
EXECUTABLE = ('.bat', '.cmd', '.ps1', '.vbs', '.sh', '.py', '.dll', '.so')


def pe_binary(path: Path) -> bool:
    blob = path.read_bytes()
    if blob[:2] != b'MZ':
        return False
    offset = int.from_bytes(blob[0x3C:0x40], 'little')
    if blob[offset:offset + 4] != b'PE\0\0':
        return False
    machine = int.from_bytes(blob[offset + 4:offset + 6], 'little')
    subsystem = int.from_bytes(blob[offset + 24 + 68:offset + 24 + 70], 'little')
    return machine == 0x8664 and subsystem == 2


class DeskUpdatePackages(unittest.TestCase):
    def archive(self, name):
        return ZipFile(ROOT / name)

    def test_site_package_exact_payload(self):
        with self.archive('SITE-FIX-v4.152.0-desk-update.zip') as z:
            self.assertIsNone(z.testzip())
            expected = {'site-update-v4.152.0/' + rel for rel in SITE_FILES}
            self.assertEqual(set(z.namelist()), expected)
            for rel, source in SITE_FILES.items():
                self.assertEqual(z.read('site-update-v4.152.0/' + rel), source.read_bytes(), rel)

    def test_desktop_package_exact_payload(self):
        with self.archive('SchoolDeskPro-FIX-v2.83.0-desk-update.zip') as z:
            self.assertIsNone(z.testzip())
            expected = {'SchoolDeskPro/www/' + rel for rel in DESKTOP_FILES} | {'SchoolDeskPro/SchoolDeskPro.exe'}
            self.assertEqual(set(z.namelist()), expected)
            for rel, source in DESKTOP_FILES.items():
                self.assertEqual(z.read('SchoolDeskPro/www/' + rel), source.read_bytes(), rel)
            binary = z.read('SchoolDeskPro/SchoolDeskPro.exe')
            self.assertGreater(len(binary), 20000)
            self.assertNotIn(b'libgcc_s', binary, 'statically linked launcher expected')

    def test_launcher_binary_is_a_windows_gui_build(self):
        self.assertTrue(pe_binary(ROOT / '.cache/release-v1/SchoolDeskPro.exe'))

    def test_upload_folder_guard_is_created_at_runtime_not_shipped(self):
        # the release build wipes uploads/ (student files must never ship), so the
        # publisher page creates uploads/desktop-updates/.htaccess on first use.
        store = (SITE / 'includes/desk_updates_store.php').read_text(encoding='utf-8')
        self.assertIn('uploads/desktop-updates', store)
        self.assertIn("'/.htaccess'", store)
        page = (SITE / 'desk-updates.php').read_text(encoding='utf-8')
        self.assertIn('desk_updates_ensure_dir()', page)
        for name in ('SITE-FIX-v4.152.0-desk-update.zip', 'SchoolDeskPro-FIX-v2.83.0-desk-update.zip'):
            with self.archive(name) as z:
                self.assertFalse([n for n in z.namelist() if 'uploads/' in n], name)

    def test_no_private_or_executable_payload_anywhere(self):
        for name in ('SITE-FIX-v4.152.0-desk-update.zip', 'SchoolDeskPro-FIX-v2.83.0-desk-update.zip'):
            with self.archive(name) as z:
                for entry in z.namelist():
                    rel = entry.split('SchoolDeskPro/www/')[-1].split('site-update-v4.152.0/')[-1]
                    self.assertFalse(any(rel.startswith(prefix) for prefix in FORBIDDEN), entry)
                    if entry == 'SchoolDeskPro/SchoolDeskPro.exe':
                        continue
                    self.assertFalse(entry.lower().endswith(EXECUTABLE), entry)
                    self.assertNotIn('..', entry.split('/'), entry)

    def test_shared_reader_copies_are_identical(self):
        desktop = (ROOT / 'desktop-app-v2/patch/includes-desk_update_zip.php').read_bytes()
        site = (SITE / 'includes/desk_update_zip.php').read_bytes()
        self.assertEqual(desktop, site)
        digest = hashlib.sha256(site).hexdigest()
        self.assertEqual(digest, hashlib.sha256(
            self.archive_bytes('SITE-FIX-v4.152.0-desk-update.zip', 'site-update-v4.152.0/includes/desk_update_zip.php')).hexdigest())
        self.assertEqual(digest, hashlib.sha256(
            self.archive_bytes('SchoolDeskPro-FIX-v2.83.0-desk-update.zip', 'SchoolDeskPro/www/includes/desk_update_zip.php')).hexdigest())

    def archive_bytes(self, archive, entry):
        with self.archive(archive) as z:
            return z.read(entry)

    def test_site_publisher_is_super_admin_only(self):
        source = (SITE / 'desk-updates.php').read_text(encoding='utf-8')
        self.assertIn("$admin['role'] !== 'super_admin'", source)
        self.assertIn('verify_csrf', source)
        self.assertIn("'desktop'", source, 'publishing must stay a site-only page')
        self.assertIn("desk_updates_report", source)

    def test_api_authorizes_with_the_per_install_key_file(self):
        source = (SITE / 'desk-update-api.php').read_text(encoding='utf-8')
        self.assertIn("config/desk-sync-key.php", source)
        self.assertIn('hash_equals', source)
        self.assertIn("strlen($stored) < 32", source)

    def test_engine_never_touches_config_data_or_runtime(self):
        source = (ROOT / 'desktop-app-v2/patch/includes-desk_update.php').read_text(encoding='utf-8')
        for protected in ('config', 'data', 'php', 'backups', 'licenses'):
            self.assertIn(protected, source)
        self.assertIn("desk_update_private_path", source)
        self.assertIn("desk_update_blocked_extension", source)
        self.assertIn("desk_update_pe_ok", source)
        # the swap is done by a detached helper started by the launcher on next boot
        self.assertIn('apply-launcher-update.cmd', source)
        self.assertIn('desk_update_launcher_helper', source)


if __name__ == '__main__':
    unittest.main()
