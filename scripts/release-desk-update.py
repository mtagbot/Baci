#!/usr/bin/env python3
"""Publishable, same-version desktop hotfixes for the online-update feature.

Builds three archives, all from the sources in this repository:

  SITE-FIX-v4.152.0-desk-update.zip    site side  (publisher page + API + store)
  SchoolDeskPro-FIX-v2.83.0-desk-update.zip  desktop side (launcher + web files)
The desktop corrective carries the freshly compiled SchoolDeskPro.exe: without
it, an installed app cannot stage a future launcher replacement. Compilation
uses the same pinned Zig 0.14.1 toolchain as scripts/build-full-release.py.

Online packages (the ones a school publishes from the site) are built by
scripts/release-scanner.py for the scanner correction; the same shape applies
to any other correction: SchoolDeskPro/www/<files> + DESKTOP-UPDATE.json.
"""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib
import os
import shutil
import subprocess
import sys

ROOT = Path(__file__).resolve().parent.parent
PATCH = ROOT / 'update-v4.152.0'
LAUNCHER = ROOT / 'desktop-app-v2/launcher'
CACHE = ROOT / '.cache/release-v1'
STAMP = (2026, 9, 19, 0, 0, 0)

# web-relative source path → path inside the site corrective
SITE_FILES = (
    'desk-update-api.php',
    'desk-updates.php',
    'includes/desk_update_zip.php',
    'includes/desk_updates_store.php',
    'includes/management_hub.php',
    'uploads/desktop-updates/.htaccess',
)
# web-relative source path → path inside the desktop corrective (under SchoolDeskPro/)
DESKTOP_FILES = (
    'desk-update.php',
    'includes/desk_update.php',
    'includes/desk_update_zip.php',
    'includes/management_hub.php',
    'desk-sync-daemon.php',
)
SITE_ARCHIVE = 'SITE-FIX-v4.152.0-desk-update.zip'
DESKTOP_ARCHIVE = 'SchoolDeskPro-FIX-v2.83.0-desk-update.zip'
# Sources that live outside update-v4.152.0 (desktop patch folder) are copied here.
DESKTOP_SOURCES = {
    'desk-update.php': ROOT / 'desktop-app-v2/patch/www-desk-update.php',
    'includes/desk_update.php': ROOT / 'desktop-app-v2/patch/includes-desk_update.php',
    'includes/desk_update_zip.php': ROOT / 'desktop-app-v2/patch/includes-desk_update_zip.php',
    'desk-sync-daemon.php': ROOT / 'desktop-app-v2/patch/www-desk-sync-daemon.php',
}


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def compile_launcher() -> Path:
    zig = os.environ.get('ZIG') or shutil.which('zig') or str(CACHE / 'compiler/ziglang/zig')
    if not Path(zig).is_file():
        raise SystemExit('Install Zig 0.14.1 or set ZIG (see release-v1.0/BUILD.md)')
    if subprocess.run([zig, 'version'], capture_output=True, text=True).stdout.strip() != '0.14.1':
        raise SystemExit('Reproducible build requires Zig 0.14.1')
    exe = CACHE / 'SchoolDeskPro.exe'
    res = CACHE / 'app.res'
    subprocess.run([zig, 'rc', '/fo', str(res), str(LAUNCHER / 'res/app.rc')], check=True)
    subprocess.run([zig, 'cc', '-target', 'x86_64-windows-gnu', '-O2', '-s',
                    str(LAUNCHER / 'launcher.c'), str(res),
                    '-lws2_32', '-ladvapi32', '-lshell32', '-luser32', '-lgdi32',
                    '-Wl,--subsystem,windows', '-o', str(exe)], check=True)
    return exe


def zip_entry(name: str, data: bytes) -> ZipInfo:
    info = ZipInfo(name, STAMP)
    info.compress_type = ZIP_DEFLATED
    info.external_attr = 0o100644 << 16
    return info


def build_archive(target: Path, entries: dict) -> None:
    with ZipFile(target, 'w', compression=ZIP_DEFLATED, compresslevel=9) as z:
        for name in sorted(entries):
            z.writestr(zip_entry(name, entries[name]), entries[name])
    with ZipFile(target) as z:
        assert z.testzip() is None
        assert set(z.namelist()) == set(entries), (target.name, set(z.namelist()) ^ set(entries))
        for name, data in entries.items():
            assert z.read(name) == data, name
    print(f'{target.name}  {target.stat().st_size}  {sha(target.read_bytes())}')


def build() -> None:
    # both ZIPs must carry byte-identical copies of the shared reader
    shared_desktop = (ROOT / 'desktop-app-v2/patch/includes-desk_update_zip.php').read_bytes()
    shared_site = (PATCH / 'includes/desk_update_zip.php').read_bytes()
    assert shared_desktop == shared_site, 'the shared ZIP reader copies drifted apart'

    site_entries = {}
    for rel in SITE_FILES:
        source = PATCH / rel
        assert source.is_file(), source
        site_entries['site-update-v4.152.0/' + rel] = source.read_bytes()

    desktop_entries = {}
    for rel in DESKTOP_FILES:
        source = DESKTOP_SOURCES.get(rel, PATCH / rel)
        assert source.is_file(), source
        desktop_entries['SchoolDeskPro/www/' + rel] = source.read_bytes()
    exe = compile_launcher()
    binary = exe.read_bytes()
    assert binary[:2] == b'MZ', 'compiled launcher is not a PE file'
    desktop_entries['SchoolDeskPro/SchoolDeskPro.exe'] = binary

    build_archive(ROOT / SITE_ARCHIVE, site_entries)
    build_archive(ROOT / DESKTOP_ARCHIVE, desktop_entries)

if __name__ == '__main__':
    build()
