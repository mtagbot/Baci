#!/usr/bin/env python3
"""Build the two-file-set mobile/tablet live-exam-editor correction.

The product sources remain on the current 4.152.0/2.83.0 installation layout;
4.160.0 and 2.90.0 are the next correction-package labels.  Unlike the older
per-feature archives, this release deliberately emits only one site ZIP and
one desktop ZIP.  Each archive contains only the changed editor page and its
screen-only mobile CSS layer.
"""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parent.parent
STAMP = (2026, 9, 25, 0, 0, 0)
SITE_ARCHIVE = 'MODIFIED-FILES-V4.160.0.zip'
DESKTOP_ARCHIVE = 'SchoolDesk-FIX-v2.90.0.zip'
SITE_FILES = {
    'site-update-v4.152.0/exam-print.php': ROOT / 'update-v4.152.0/exam-print.php',
    'site-update-v4.152.0/assets/css/exam-designer-mobile.css': ROOT / 'update-v4.152.0/assets/css/exam-designer-mobile.css',
}
DESKTOP_FILES = {
    'SchoolDeskPro/www/exam-print.php': ROOT / 'desktop-app-v2/patch/www-exam-print.php',
    'SchoolDeskPro/www/assets/css/exam-designer-mobile.css': ROOT / 'desktop-app-v2/patch/www-assets-css-exam-designer-mobile.css',
}


def validate_sources():
    assert SITE_FILES['site-update-v4.152.0/assets/css/exam-designer-mobile.css'].read_bytes() == DESKTOP_FILES['SchoolDeskPro/www/assets/css/exam-designer-mobile.css'].read_bytes()
    for payload in (SITE_FILES, DESKTOP_FILES):
        for name, source in payload.items():
            assert source.is_file(), source
            data = source.read_bytes()
            assert b'..' not in name.encode()
            assert not name.startswith('/')
            assert data
    for page in (SITE_FILES['site-update-v4.152.0/exam-print.php'], DESKTOP_FILES['SchoolDeskPro/www/exam-print.php']):
        text = page.read_text(encoding='utf-8')
        assert 'exam-designer-mobile.css?v=4.160.1' in text
        assert 'function editorViewportScale()' in text
        assert 'function sourceCropHandleDown' in text
        assert 'function sourceCropApply' in text
        assert 'document.execCommand(\'fontName\'' in text
        assert 'mobile-range-preview' in text
        assert 'function moveQuestion(id,delta)' in text
        assert '@page{size:A4;margin:0}' in text
        assert 'width:210mm' in text and 'height:297mm' in text
        assert '@media print{' in text


def build(filename, payload):
    target = ROOT / filename
    with ZipFile(target, 'w', compression=ZIP_DEFLATED, compresslevel=9) as archive:
        for name, source in sorted(payload.items()):
            info = ZipInfo(name, STAMP)
            info.compress_type = ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, source.read_bytes())
    with ZipFile(target) as archive:
        assert archive.testzip() is None
        assert set(archive.namelist()) == set(payload)
        for name, source in payload.items():
            assert archive.read(name) == source.read_bytes(), name
    digest = hashlib.sha256(target.read_bytes()).hexdigest()
    print(filename, target.stat().st_size, digest)


if __name__ == '__main__':
    validate_sources()
    build(SITE_ARCHIVE, SITE_FILES)
    build(DESKTOP_ARCHIVE, DESKTOP_FILES)
