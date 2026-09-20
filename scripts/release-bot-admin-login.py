#!/usr/bin/env python3
"""Same-version hotfix: the manager logs in from inside the bot with his own account.

Two files, byte-for-byte from the tested source in update-v4.152.0/:

  includes/bot_login_flow.php      the new flow: manager username → password prompt
  includes/bot_webhook_engine.php  the Bale/Telegram webhook: the manager username is
                                   accepted wherever an identity or a secret is asked,
                                   and the password step connects his account

Published archives:

  SITE-FIX-v4.152.0-bot-admin-login.zip             site corrective
  SchoolDeskPro-FIX-v2.83.0-bot-admin-login.zip     desktop corrective
  SchoolDeskPro-UPDATE-2.83.0-bot-admin-login.zip   online desktop package
      (the same two files + DESKTOP-UPDATE.json, installed from the school site)

Nothing else is shipped: no launcher, config, data or roster files.
"""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib
import json

ROOT = Path(__file__).resolve().parent.parent
PATCH = ROOT / 'update-v4.152.0'
FILES = ('includes/bot_login_flow.php', 'includes/bot_webhook_engine.php')
ARCHIVES = {
    'SITE-FIX-v4.152.0-bot-admin-login.zip': 'site-update-v4.152.0/',
    # The desktop web root was renamed www -> reports; the desktop updater
    # accepts both, reports/ is what a migrated machine expects.
    'SchoolDeskPro-FIX-v2.83.0-bot-admin-login.zip': 'SchoolDeskPro/reports/',
}
ONLINE_ARCHIVE = 'SchoolDeskPro-UPDATE-2.83.0-bot-admin-login.zip'
ONLINE_VERSION = '2.83.0-bot-admin-login'
STAMP = (2026, 9, 20, 0, 0, 0)
NOTES = ('ورود مدیر از داخل ربات: مدیر در همان مرحله‌ای که ربات «کد ملی ۱۰ رقمی» می‌پرسد، نام کاربری '
         'مدیریت خود را می‌فرستد؛ ربات نقش مدیریت را تشخیص می‌دهد، رمز عبور مدیر را می‌پرسد و با تأیید '
         'رمز حساب مدیریت را روی همان پیام‌رسان (بله یا تلگرام) به ربات اضافه می‌کند — یعنی همان حسابی '
         'که پیام آزمایشی «تگ آزمایشی» و اعلان‌های مدیریتی به آن می‌رسد. سه تلاش نادرست، مرحله را '
         'می‌بندد و ورود مخفی پیشین (/start admin_SECRET) هم دست‌نخورده می‌ماند.')
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
    flow = payload['includes/bot_login_flow.php'].decode('utf-8')
    engine = payload['includes/bot_webhook_engine.php'].decode('utf-8')
    # The flow and its wiring must really be in the shipped bytes.
    assert 'function bot_login_manager_entry' in flow and 'function bot_login_admin_by_username' in flow
    assert "require_once __DIR__ . '/bot_login_flow.php';" in engine
    assert engine.count('bot_login_manager_entry($platform, $chatId, $text)') >= 5, 'entry points'
    assert "'admin_password'" in engine and "verify_user_password" in engine
    assert "role_type, is_active, created_at_jalali) VALUES (?, ?, ?, 'admin', 1, ?)" in engine
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
