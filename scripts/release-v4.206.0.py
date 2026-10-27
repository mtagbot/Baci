#!/usr/bin/env python3
"""Build v4.206.0 site and v2.130.0 desktop sync packages (No-Proxy + Browser Sync Bridge + Dual Root)."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 10, 27, 0, 0, 0)
SUMS = 'V4.206.0-SHA256SUMS.txt'
VERSION = ('config/version.php', 'update-v4.152.0/config/version.php')
SITE_FILES = [
    (*VERSION, b"'4.206.0'"),
    ('desk-sync-api.php', 'update-v4.152.0/class-exam-sync-api.php', b'sdp_bridge'),
    ('class-exam-sync-api.php', 'update-v4.152.0/class-exam-sync-api.php', b'sdp_bridge'),
    ('includes/desk_sync_endpoint.php', 'update-v4.152.0/includes/desk_sync_endpoint.php', b'sdp_bridge'),
    ('includes/desk_sync.php', 'update-v4.152.0/includes/desk_sync.php', b'bridgeInit'),
    ('includes/functions.php', 'update-v4.152.0/includes/functions.php', b'$__isSdpSyncRelay'),
    ('desk-connection-status.php', 'update-v4.152.0/desk-connection-status.php', b'SDP_SYNC_ACTIVE'),
    ('desk-update-api.php', 'update-v4.152.0/desk-update-api.php', b'sdp_z'),
    ('csrf-refresh.php', 'update-v4.152.0/csrf-refresh.php', b'includes/functions.php'),
    ('includes/desk_updates_store.php', 'update-v4.152.0/includes/desk_updates_store.php', b'config/version.php'),
]
DESKTOP_BASE_FILES = [
    (*VERSION, b"'2.130.0'"),
    ('class-exam-sync-api.php', 'update-v4.152.0/class-exam-sync-api.php', b'sdp_bridge'),
    ('includes/desk_sync_endpoint.php', 'desktop-app-v2/patch/includes-desk_sync_endpoint.php', b'sdp_bridge'),
    ('includes/desk_sync.php', 'desktop-app-v2/patch/includes-desk-sync.php', b'bridgeInit'),
    ('includes/desk_update.php', 'desktop-app-v2/patch/includes-desk_update.php', b'sdp_z'),
    ('includes/desk_updates_store.php', 'update-v4.152.0/includes/desk_updates_store.php', b'config/version.php'),
    ('includes/functions.php', 'update-v4.152.0/includes/functions.php', b'$__isSdpSyncRelay'),
    ('desk-connection-status.php', 'update-v4.152.0/desk-connection-status.php', b'SDP_SYNC_ACTIVE'),
    ('desk-update-api.php', 'update-v4.152.0/desk-update-api.php', b'sdp_z'),
    ('desk-sync.php', 'desktop-app-v2/patch/www-desk-sync.php', b'runBrowserBridgeSync'),
    ('desk-sync-daemon.php', 'desktop-app-v2/patch/www-desk-sync-daemon.php', b'engine_mtime'),
    ('desk-prepend.php', 'desktop-app-v2/patch/www-desk-prepend.php', b'sdpDaemonRunning'),
]
# Ship inside BOTH SchoolDeskPro/reports/ (Release_V1.2+) and SchoolDeskPro/www/ (legacy)
# so extracting at SchoolDeskPro/ root always updates the live folder immediately.
DESKTOP_FILES = (
    [('reports/' + arc, src, marker) for arc, src, marker in DESKTOP_BASE_FILES]
    + [('www/' + arc, src, marker) for arc, src, marker in DESKTOP_BASE_FILES]
)
PACKAGES = [
    ('MODIFIED-FILES-V4.206.0.zip', 'site-update-v4.152.0/', SITE_FILES),
    ('SchoolDesk-FIX-v2.130.0.zip', 'SchoolDeskPro/', DESKTOP_FILES),
]


def build():
    assert b'bridgeInit' in (ROOT / 'desktop-app-v2/patch/includes-desk-sync.php').read_bytes()
    assert b'runBrowserBridgeSync' in (ROOT / 'desktop-app-v2/patch/www-desk-sync.php').read_bytes()
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
