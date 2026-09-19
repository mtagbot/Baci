"""The two cumulative corrective ZIPs must be exactly today's whole changes.

`SITE-FIX-v4.152.0-cumulative.zip` and `SchoolDeskPro-FIX-v2.83.0-cumulative.zip`
are what a school installs when it wants all corrections at once: the scanner
infinity-focus fix plus the desktop online-update feature. This test rebuilds
the union from the four individual published packages and from the working tree,
then compares bytes — a missing or extra file, or a stale copy inside the ZIP,
fails here and nowhere else.
"""
from pathlib import Path
from zipfile import ZipFile
import hashlib
import unittest

ROOT = Path(__file__).resolve().parent.parent
SITE_PREFIX = 'site-update-v4.152.0/'
DESKTOP_PREFIX = 'SchoolDeskPro/'
SITE_ARCHIVE = ROOT / 'SITE-FIX-v4.152.0-cumulative.zip'
DESKTOP_ARCHIVE = ROOT / 'SchoolDeskPro-FIX-v2.83.0-cumulative.zip'
PARTS = {
    'site': ['SITE-FIX-v4.152.0-scanner.zip', 'SITE-FIX-v4.152.0-desk-update.zip'],
    'desktop': ['SchoolDeskPro-FIX-v2.83.0-scanner.zip', 'SchoolDeskPro-FIX-v2.83.0-desk-update.zip'],
}
PRIVATE = ('config/', 'data/', 'php/', 'backups/', 'profile/', 'server/', 'licenses/')
EXECUTABLE = ('.bat', '.cmd', '.ps1', '.vbs', '.sh', '.py', '.dll', '.so')


def parts_union(platform):
    merged = {}
    for archive in PARTS[platform]:
        with ZipFile(ROOT / archive) as z:
            for name in z.namelist():
                if name.endswith('/'):
                    continue
                merged[name] = z.read(name)
    return merged


class CumulativeCorrective(unittest.TestCase):
    def test_site_archive_is_the_union_of_the_published_packages(self):
        expected = parts_union('site')
        with ZipFile(SITE_ARCHIVE) as z:
            self.assertIsNone(z.testzip())
            self.assertEqual(set(z.namelist()), set(expected))
            for name, data in expected.items():
                self.assertEqual(z.read(name), data, name)
            self.assertEqual(len(z.namelist()), 9)

    def test_desktop_archive_is_the_union_plus_the_launcher(self):
        expected = {k: v for k, v in parts_union('desktop').items() if k != DESKTOP_PREFIX + 'SchoolDeskPro.exe'}
        with ZipFile(DESKTOP_ARCHIVE) as z:
            self.assertIsNone(z.testzip())
            binary_name = DESKTOP_PREFIX + 'SchoolDeskPro.exe'
            self.assertIn(binary_name, z.namelist())
            self.assertEqual(set(z.namelist()), set(expected) | {binary_name})
            for name, data in expected.items():
                self.assertEqual(z.read(name), data, name)
            binary = z.read(binary_name)
            self.assertEqual(binary[:2], b'MZ')
            offset = int.from_bytes(binary[0x3C:0x40], 'little')
            self.assertEqual(binary[offset:offset + 4], b'PE\0\0')
            self.assertEqual(int.from_bytes(binary[offset + 4:offset + 6], 'little'), 0x8664)
            self.assertEqual(int.from_bytes(binary[offset + 24 + 68:offset + 24 + 70], 'little'), 2)
            self.assertNotIn(b'libgcc_s', binary)

    def test_both_platforms_carry_the_same_web_bytes(self):
        with ZipFile(SITE_ARCHIVE) as site, ZipFile(DESKTOP_ARCHIVE) as desktop:
            site_files = {n[len(SITE_PREFIX):] for n in site.namelist()}
            desktop_files = {n[len(DESKTOP_PREFIX + 'www/'):] for n in desktop.namelist() if n.startswith(DESKTOP_PREFIX + 'www/')}
            self.assertEqual(site_files - desktop_files, {'desk-update-api.php', 'desk-updates.php', 'includes/desk_updates_store.php'},
                             'site-only files must be the publisher side of the online update')
            for name in sorted(site_files & desktop_files):
                self.assertEqual(site.read(SITE_PREFIX + name), desktop.read(DESKTOP_PREFIX + 'www/' + name), name)

    def test_no_private_or_executable_payload(self):
        for archive in (SITE_ARCHIVE, DESKTOP_ARCHIVE):
            with ZipFile(archive) as z:
                for entry in z.namelist():
                    rel = entry.split(DESKTOP_PREFIX, 1)[-1].split(SITE_PREFIX, 1)[-1]
                    rel = rel[4:] if rel.startswith('www/') else rel
                    self.assertFalse(any(rel.startswith(p) for p in PRIVATE), entry)
                    self.assertNotIn('..', entry.split('/'), entry)
                    if entry == DESKTOP_PREFIX + 'SchoolDeskPro.exe':
                        continue
                    self.assertFalse(entry.lower().endswith(EXECUTABLE), entry)
                    self.assertFalse('uploads/' in entry, entry)

    def test_sources_in_the_working_tree_are_what_shipped(self):
        site_sources = {
            'attendance-scanner.php': ROOT / 'update-v4.152.0/attendance-scanner.php',
            'attendance-scanner-legacy.php': ROOT / 'update-v4.152.0/attendance-scanner-legacy.php',
            'assets/js/attendance-scanner-light.js': ROOT / 'update-v4.152.0/assets/js/attendance-scanner-light.js',
            'assets/js/attendance-decoder-worker.js': ROOT / 'update-v4.152.0/assets/js/attendance-decoder-worker.js',
            'desk-update-api.php': ROOT / 'update-v4.152.0/desk-update-api.php',
            'desk-updates.php': ROOT / 'update-v4.152.0/desk-updates.php',
            'includes/desk_update_zip.php': ROOT / 'update-v4.152.0/includes/desk_update_zip.php',
            'includes/desk_updates_store.php': ROOT / 'update-v4.152.0/includes/desk_updates_store.php',
            'includes/management_hub.php': ROOT / 'update-v4.152.0/includes/management_hub.php',
        }
        desktop_extra = {
            'desk-update.php': ROOT / 'desktop-app-v2/patch/www-desk-update.php',
            'includes/desk_update.php': ROOT / 'desktop-app-v2/patch/includes-desk_update.php',
            'desk-sync-daemon.php': ROOT / 'desktop-app-v2/patch/www-desk-sync-daemon.php',
        }
        with ZipFile(SITE_ARCHIVE) as z:
            for rel, source in site_sources.items():
                self.assertEqual(z.read(SITE_PREFIX + rel), source.read_bytes(), rel)
        shared = {rel: source for rel, source in site_sources.items()
                  if rel not in ('desk-update-api.php', 'desk-updates.php', 'includes/desk_updates_store.php')}
        with ZipFile(DESKTOP_ARCHIVE) as z:
            for rel, source in {**shared, **desktop_extra}.items():
                self.assertEqual(z.read(DESKTOP_PREFIX + 'www/' + rel), source.read_bytes(), rel)

    def test_shared_reader_copy_is_identical_across_the_pair(self):
        with ZipFile(SITE_ARCHIVE) as site, ZipFile(DESKTOP_ARCHIVE) as desktop:
            a = site.read(SITE_PREFIX + 'includes/desk_update_zip.php')
            b = desktop.read(DESKTOP_PREFIX + 'www/includes/desk_update_zip.php')
            self.assertEqual(a, b)
            self.assertEqual(hashlib.sha256(a).hexdigest(), hashlib.sha256(
                (ROOT / 'update-v4.152.0/includes/desk_update_zip.php').read_bytes()).hexdigest())


if __name__ == '__main__':
    unittest.main()
