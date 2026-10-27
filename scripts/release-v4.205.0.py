#!/usr/bin/env python3
"""Build v4.205.0 site and v2.129.0 desktop WAF-resilient sync & daemon self-heal packages."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 10, 27, 0, 0, 0)
SUMS = 'V4.205.0-SHA256SUMS.txt'
VERSION = ('config/version.php', 'update-v4.152.0/config/version.php')
SITE_FILES = [
    (*VERSION, b"'4.205.0'"),
    ('class-exam-sync-api.php', 'update-v4.152.0/class-exam-sync-api.php', b'desk_sync_unpack_token'),
    ('includes/desk_sync_endpoint.php', 'update-v4.152.0/includes/desk_sync_endpoint.php', b'desk_sync_unpack_token'),
    ('includes/desk_sync.php', 'update-v4.152.0/includes/desk_sync.php', b'tryFallbackTransports'),
    ('includes/functions.php', 'update-v4.152.0/includes/functions.php', b'$__isSdpSyncRelay'),
    ('desk-connection-status.php', 'update-v4.152.0/desk-connection-status.php', b'SDP_SYNC_ACTIVE'),
    ('desk-update-api.php', 'update-v4.152.0/desk-update-api.php', b'sdp_z'),
    ('includes/desk_updates_store.php', 'update-v4.152.0/includes/desk_updates_store.php', b'config/version.php'),
]
DESKTOP_FILES = [
    (*VERSION, b"'2.129.0'"),
    ('class-exam-sync-api.php', 'update-v4.152.0/class-exam-sync-api.php', b'desk_sync_unpack_token'),
    ('includes/desk_sync_endpoint.php', 'desktop-app-v2/patch/includes-desk_sync_endpoint.php', b'desk_sync_unpack_token'),
    ('includes/desk_sync.php', 'desktop-app-v2/patch/includes-desk-sync.php', b'tryFallbackTransports'),
    ('includes/desk_update.php', 'desktop-app-v2/patch/includes-desk_update.php', b'sdp_z'),
    ('includes/desk_updates_store.php', 'update-v4.152.0/includes/desk_updates_store.php', b'config/version.php'),
    ('includes/functions.php', 'update-v4.152.0/includes/functions.php', b'$__isSdpSyncRelay'),
    ('desk-connection-status.php', 'update-v4.152.0/desk-connection-status.php', b'SDP_SYNC_ACTIVE'),
    ('desk-update-api.php', 'update-v4.152.0/desk-update-api.php', b'sdp_z'),
    ('desk-sync.php', 'desktop-app-v2/patch/www-desk-sync.php', b'session_write_close()'),
    ('desk-sync-daemon.php', 'desktop-app-v2/patch/www-desk-sync-daemon.php', b'bootMtime'),
    ('desk-prepend.php', 'desktop-app-v2/patch/www-desk-prepend.php', b'sync-engine-mtime.txt'),
]
PACKAGES = [
    ('MODIFIED-FILES-V4.205.0.zip', 'site-update-v4.152.0/', SITE_FILES),
    ('SchoolDesk-FIX-v2.129.0.zip', 'SchoolDeskPro/www/', DESKTOP_FILES),
]


def build():
    assert b'tryFallbackTransports' in (ROOT / 'desktop-app-v2/patch/includes-desk-sync.php').read_bytes()
    assert b'relayBotNotifications' in (ROOT / 'desktop-app-v2/patch/includes-desk-sync.php').read_bytes()
    assert b'desk_sync_unpack_token' in (ROOT / 'desktop-app-v2/server/desk-sync-api.php').read_bytes()
    output = []
    for name, prefix, files in PACKAGES:
        with ZipFile(ROOT / name, 'w', ZIP_DEFLATED, compresslevel=9) as z:
            for arc, source, marker in sorted(files):
                data = (ROOT / source).read_bytes()
                assert marker in data, f'marker missing in {arc}'
                info = ZipInfo(prefix + arc, STAMP)
                info.compress_type = ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                z.writestr(info, data)
        digest = hashlib.sha256((ROOT / name).read_bytes()).hexdigest()
        output.append((digest, name))
        print(f'{name}: {(ROOT / name).stat().st_size} bytes, SHA-256 {digest}')
    (ROOT / SUMS).write_text(''.join(f'{digest}  {name}\n' for digest, name in output), encoding='utf-8')
    print(f'Wrote {SUMS}')


if __name__ == '__main__':
    build()
