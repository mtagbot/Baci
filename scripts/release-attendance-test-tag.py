#!/usr/bin/env python3
"""Same-version hotfix: the «تگ آزمایشی» sheet and its management test message.

Two files, byte-for-byte from the tested source in update-v4.152.0/:

  attendance-tags.php             the tag-printing page (the «تگ آزمایشی» option)
  includes/attendance_helpers.php the attendance core: the test payload is handled
                                  there, so the real scanner endpoint is untouched

Published archives:

  SITE-FIX-v4.152.0-attendance-test-tag.zip             site corrective
  SchoolDeskPro-FIX-v2.83.0-attendance-test-tag.zip     desktop corrective
  SchoolDeskPro-UPDATE-2.83.0-attendance-test-tag.zip   online desktop package
      (the same two files + DESKTOP-UPDATE.json, installed from the school site)

Nothing else is shipped: no launcher, config, data or roster files.
"""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib
import json

ROOT = Path(__file__).resolve().parent.parent
PATCH = ROOT / 'update-v4.152.0'
FILES = ('attendance-tags.php', 'includes/attendance_helpers.php')
ARCHIVES = {
    'SITE-FIX-v4.152.0-attendance-test-tag.zip': 'site-update-v4.152.0/',
    # The desktop web root was renamed www -> reports; the desktop updater
    # accepts both, reports/ is what a migrated machine expects.
    'SchoolDeskPro-FIX-v2.83.0-attendance-test-tag.zip': 'SchoolDeskPro/reports/',
}
ONLINE_ARCHIVE = 'SchoolDeskPro-UPDATE-2.83.0-attendance-test-tag.zip'
ONLINE_VERSION = '2.83.0-attendance-test-tag'
STAMP = (2026, 9, 20, 0, 0, 0)
NOTES = ('تگ آزمایشی: چاپ یک برگهٔ آزمایشی با دو تگ «حضور به موقع» و «تأخیر»؛ با اسکن هر تگ '
         'همان مسیر واقعی اسکنر طی می‌شود و پیام آزمایشی فقط برای حساب مدیریتی فرستاده می‌شود که '
         'خودش از طریق ربات با نام کاربری و رمز، حسابش را به ربات اضافه کرده است — هم در بله و هم '
         'در تلگرام؛ برای هیچ نقش دیگری (دبیر، معاون/ناظم، معاون اجرایی، مشاور) و برای هیچ والدی '
         'پیامی نمی‌رود و هیچ حضور یا غیابی ثبت نمی‌شود.')
# A payload must never smuggle credentials, data or a replacement launcher.
FORBIDDEN = {'schooldeskpro.exe', 'php.ini', 'database.php', 'release.php',
             'desk-sync-key.php', 'installed.lock'}


def manifest(version, payload, notes):
    return (json.dumps({
        'version': version,
        'notes': notes,
        'files': {name: {'sha256': hashlib.sha256(data).hexdigest(), 'bytes': len(data)}
                  for name, data in sorted(payload.items())},
    }, ensure_ascii=False, indent=2, sort_keys=True) + '\n').encode()


def write_archive(target, entries, stamp):
    with ZipFile(target, 'w', compression=ZIP_DEFLATED, compresslevel=9) as z:
        for name, content in sorted(entries.items()):
            info = ZipInfo(name, stamp)
            info.compress_type = ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            z.writestr(info, content)
    with ZipFile(target) as z:
        assert z.testzip() is None
        assert set(z.namelist()) == set(entries)
        for name, content in entries.items():
            assert z.read(name) == content
    for name in entries:
        assert name.rsplit('/', 1)[-1].lower() not in FORBIDDEN, name
    print(target.name, target.stat().st_size, hashlib.sha256(target.read_bytes()).hexdigest())


def build():
    payload = {name: (PATCH / name).read_bytes() for name in FILES}
    # The option and the scan branch must really be in the shipped bytes.
    tags = payload['attendance-tags.php'].decode('utf-8')
    assert "'MTAG-ATT-TEST:'" in payload['includes/attendance_helpers.php'].decode('utf-8')
    assert 'att_test_tag_print_rows' in tags and 'openTestPrint' in tags
    for archive, prefix in ARCHIVES.items():
        write_archive(ROOT / archive, {prefix + name: data for name, data in payload.items()}, STAMP)

    online = {'SchoolDeskPro/reports/' + name: data for name, data in payload.items()}
    online['SchoolDeskPro/DESKTOP-UPDATE.json'] = manifest(ONLINE_VERSION, payload, NOTES)
    target = ROOT / ONLINE_ARCHIVE
    write_archive(target, online, STAMP)
    with ZipFile(target) as z:
        meta = json.loads(z.read('SchoolDeskPro/DESKTOP-UPDATE.json'))
        assert meta['version'] == ONLINE_VERSION
        for name in FILES:
            assert meta['files'][name]['sha256'] == hashlib.sha256(payload[name]).hexdigest()
            assert meta['files'][name]['bytes'] == len(payload[name])
            assert z.read('SchoolDeskPro/reports/' + name) == payload[name]


if __name__ == '__main__':
    build()
