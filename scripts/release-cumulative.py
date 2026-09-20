#!/usr/bin/env python3
"""All of today's corrections in one ZIP per platform.

  SITE-FIX-v4.152.0-cumulative.zip            whole site correction
  SchoolDeskPro-FIX-v2.83.0-cumulative.zip    whole desktop correction

Contents are exactly the union of the two published correctives
(`*-scanner.zip` = fast near-band scanner, `*-desk-update.zip` = online desktop
updates); nothing else is added, so a machine that installs this pair gets the
same bytes as one that installs the four individual packages. The desktop ZIP
also carries the freshly compiled SchoolDeskPro.exe, because the online-update
feature cannot stage a future launcher swap without it.

The self-check below rebuilds the union from the four published archives and
refuses to write a cumulative ZIP that differs by a single byte.
"""
from pathlib import Path
from zipfile import ZipFile
import hashlib
import importlib.util
import sys

ROOT = Path(__file__).resolve().parent.parent
SITE_PREFIX = 'site-update-v4.152.0/'
DESKTOP_PREFIX = 'SchoolDeskPro/'
# The desktop web root is called reports/ since the reports migration.
DESKTOP_WEB = 'reports/'
SITE_ARCHIVE = 'SITE-FIX-v4.152.0-cumulative.zip'
DESKTOP_ARCHIVE = 'SchoolDeskPro-FIX-v2.83.0-cumulative.zip'
PARTS = {
    'site': ['SITE-FIX-v4.152.0-scanner.zip', 'SITE-FIX-v4.152.0-desk-update.zip',
             'SITE-FIX-v4.152.0-attendance-test-tag.zip'],
    'desktop': ['SchoolDeskPro-FIX-v2.83.0-scanner.zip', 'SchoolDeskPro-FIX-v2.83.0-desk-update.zip',
                'SchoolDeskPro-FIX-v2.83.0-attendance-test-tag.zip'],
}


def load(name):
    path = ROOT / 'scripts' / name
    spec = importlib.util.spec_from_file_location(name.replace('-', '_'), path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


def union(platform):
    merged = {}
    for archive in PARTS[platform]:
        with ZipFile(ROOT / archive) as z:
            for name in z.namelist():
                if name.endswith('/'):
                    continue
                data = z.read(name)
                if name in merged and merged[name] != data:
                    raise SystemExit(f'{archive}: conflicting bytes for {name}')
                merged[name] = data
    return merged


def main():
    desk_update = load('release-desk-update.py')
    scanner = load('release-scanner.py')

    # Build from source first, so the cumulative pair is never older than its parts.
    scanner.build()
    desk_update.build()
    launcher = desk_update.compile_launcher().read_bytes()
    assert launcher[:2] == b'MZ'

    expectations = {
        'site': union('site'),
        'desktop': dict(union('desktop'), **{DESKTOP_PREFIX + 'SchoolDeskPro.exe': launcher}),
    }
    for platform, entries in expectations.items():
        scripts = {
            'site': [(SITE_PREFIX + rel, (scanner.PATCH / rel).read_bytes()) for rel in scanner.FILES]
                    + [(SITE_PREFIX + rel, (desk_update.PATCH / rel).read_bytes()) for rel in desk_update.SITE_FILES],
            'desktop': [(DESKTOP_PREFIX + DESKTOP_WEB + rel, (scanner.PATCH / rel).read_bytes()) for rel in scanner.FILES]
                       + [(DESKTOP_PREFIX + DESKTOP_WEB + rel, desk_update.DESKTOP_SOURCES.get(rel, desk_update.PATCH / rel).read_bytes())
                          for rel in desk_update.DESKTOP_FILES],
        }[platform]
        for name, data in scripts:
            assert entries.get(name) == data, (platform, name)

    desk_update.build_archive(ROOT / SITE_ARCHIVE, expectations['site'])
    desk_update.build_archive(ROOT / DESKTOP_ARCHIVE, expectations['desktop'])
    for name in (SITE_ARCHIVE, DESKTOP_ARCHIVE):
        blob = (ROOT / name).read_bytes()
        print(f'{name}  {len(blob)}  {hashlib.sha256(blob).hexdigest()}')


if __name__ == '__main__':
    main()
