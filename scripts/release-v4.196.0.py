#!/usr/bin/env python3
"""Build v4.196.0 / v2.120.0 packages restoring local Chart.js assets."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 10, 23, 0, 0, 0)
SUMS = 'V4.196.0-SHA256SUMS.txt'
SITE_FILES = [
    ('config/version.php', 'update-v4.152.0/config/version.php', b'4.196.0'),
    ('includes/footer.php', 'update-v4.152.0/includes/footer.php', b'chart.umd.min.js?v=4.6.1'),
    ('assets/vendor/chart.umd.min.js', 'update-v4.152.0/assets/vendor/chart.umd.min.js', b'Chart.js v4.6.1'),
    ('assets/vendor/CHARTJS-LICENSE.md', 'update-v4.152.0/assets/vendor/CHARTJS-LICENSE.md', b'MIT License'),
]
DESKTOP_FILES = [
    ('config/version.php', 'update-v4.152.0/config/version.php', b'2.120.0'),
    ('includes/footer.php', 'desktop-app-v2/patch/includes-footer.php', b'chart.umd.min.js?v=4.6.1'),
    ('assets/vendor/chart.umd.min.js', 'desktop-app-v2/patch/www-assets-vendor-chart.umd.min.js', b'Chart.js v4.6.1'),
    ('assets/vendor/CHARTJS-LICENSE.md', 'desktop-app-v2/patch/www-assets-vendor-CHARTJS-LICENSE.md', b'MIT License'),
]
PACKAGES = [
    ('MODIFIED-FILES-V4.196.0.zip', 'site-update-v4.152.0/', SITE_FILES),
    ('SchoolDesk-FIX-v2.120.0.zip', 'SchoolDeskPro/www/', DESKTOP_FILES),
]


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def build():
    for src, desk in [
        ('update-v4.152.0/assets/vendor/chart.umd.min.js', 'desktop-app-v2/patch/www-assets-vendor-chart.umd.min.js'),
        ('update-v4.152.0/assets/vendor/CHARTJS-LICENSE.md', 'desktop-app-v2/patch/www-assets-vendor-CHARTJS-LICENSE.md'),
    ]:
        assert sha(ROOT / src) == sha(ROOT / desk), f'site/desktop mismatch: {src}'
    outputs = []
    for name, prefix, files in PACKAGES:
        with ZipFile(ROOT / name, 'w', ZIP_DEFLATED, compresslevel=9) as z:
            for arc, source, marker in sorted(files):
                data = (ROOT / source).read_bytes()
                assert marker in data, f'marker missing in {arc}'
                info = ZipInfo(prefix + arc, STAMP)
                info.compress_type = ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                z.writestr(info, data)
        digest = sha(ROOT / name)
        outputs.append((digest, name))
        print(f'{name}: {(ROOT / name).stat().st_size} bytes, SHA-256 {digest}')
    (ROOT / SUMS).write_text(''.join(f'{digest}  {name}\n' for digest, name in outputs), encoding='utf-8')
    print(f'Wrote {SUMS}; local Chart.js and license are present in both packages.')


if __name__ == '__main__':
    build()
