#!/usr/bin/env python3
"""Build the two-file-set mobile/tablet live-exam-editor correction.

The product sources remain on the current 4.152.0/2.83.0 installation layout;
4.160.0 and 2.90.0 are the next correction-package labels.  Unlike the older
per-feature archives, this release deliberately emits only one site ZIP and
one desktop ZIP.  Each archive contains only the changed editor page, its catalog API,
and the screen-only mobile CSS layer.
"""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parent.parent
STAMP = (2026, 9, 25, 0, 0, 0)
SITE_ARCHIVE = 'MODIFIED-FILES-V4.160.0.zip'
DESKTOP_ARCHIVE = 'SchoolDesk-FIX-v2.90.0.zip'
SITE_FILES = {
    'site-update-v4.152.0/exam-design-api.php': ROOT / 'update-v4.152.0/exam-design-api.php',
    'site-update-v4.152.0/exam-print.php': ROOT / 'update-v4.152.0/exam-print.php',
    'site-update-v4.152.0/assets/css/exam-designer-mobile.css': ROOT / 'update-v4.152.0/assets/css/exam-designer-mobile.css',
    # v4.164.0: report which parents blocked the bot (403 chats → linked students).
    'site-update-v4.152.0/includes/bot_admin_ui.php': ROOT / 'update-v4.152.0/includes/bot_admin_ui.php',
    'site-update-v4.152.0/includes/bot_outbox.php': ROOT / 'update-v4.152.0/includes/bot_outbox.php',
}
DESKTOP_FILES = {
    'SchoolDeskPro/www/exam-design-api.php': ROOT / 'desktop-app-v2/patch/www-exam-design-api.php',
    'SchoolDeskPro/www/exam-print.php': ROOT / 'desktop-app-v2/patch/www-exam-print.php',
    'SchoolDeskPro/www/assets/css/exam-designer-mobile.css': ROOT / 'desktop-app-v2/patch/www-assets-css-exam-designer-mobile.css',
}


def validate_sources():
    assert SITE_FILES['site-update-v4.152.0/assets/css/exam-designer-mobile.css'].read_bytes() == DESKTOP_FILES['SchoolDeskPro/www/assets/css/exam-designer-mobile.css'].read_bytes()
    assert SITE_FILES['site-update-v4.152.0/exam-design-api.php'].read_bytes() == DESKTOP_FILES['SchoolDeskPro/www/exam-design-api.php'].read_bytes()
    for payload in (SITE_FILES, DESKTOP_FILES):
        for name, source in payload.items():
            assert source.is_file(), source
            data = source.read_bytes()
            assert b'..' not in name.encode()
            assert not name.startswith('/')
            assert data
    api_text = SITE_FILES['site-update-v4.152.0/exam-design-api.php'].read_text(encoding='utf-8')
    assert "catalogMode === 'design'" in api_text
    assert "catalogMode === 'bank'" in api_text
    assert 'SELECT id, question_html' in api_text
    assert 'design_json' in api_text
    assert 'ALTER TABLE exam_question_bank ADD COLUMN grade_level' not in api_text
    # v4.160.1 catalog speed: fingerprint cache + cached scan + light thumbs
    assert 'exam_page_signature_cached' in api_text
    assert 'exam_catalog_scan_cached' in api_text
    assert 'exam_page_thumbs_for' in api_text
    assert "@md5_file(__DIR__ . '/' . $pages[0])" not in api_text
    # v4.163.0 catalog split: fast bank half (part=q) + heavy design half (part=d)
    assert 'exam_catalog_bank_scan_cached' in api_text
    assert 'exam_catalog_design_scan_cached' in api_text
    assert "$_GET['part']" in api_text
    assert "in_array($part, ['', 'q', 'd'], true)" in api_text
    for page in (SITE_FILES['site-update-v4.152.0/exam-print.php'], DESKTOP_FILES['SchoolDeskPro/www/exam-print.php']):
        text = page.read_text(encoding='utf-8')
        assert 'exam-designer-mobile.css?v=4.163.0' in text
        assert 'function editorViewportScale()' in text
        assert 'function sourceCropHandleDown' in text
        assert 'function sourceCropApply' in text
        assert 'function normalizeQuestionHtml' in text
        assert 'function finishImageResize' in text
        assert 'mobile-img-handle-layer' in text
        assert 'let imageEditOriginal=null, imageEditTarget=null' in text
        assert 'imageEditorStatus' in text
        assert 'mobileImageTools' in text
        assert 'syncRenderedImagesToModel(false)' in text
        assert 'document.execCommand(\'fontName\'' in text
        assert 'mobile-range-preview' in text
        assert 'function moveQuestion(id,delta)' in text
        assert 'catalog=design' in text and 'catalog=bank' in text
        assert 'function deferBankCatalog' in text
        assert 'function gotoBankPage' in text
        assert 'bankTotal' in text and 'designBankTotal' in text
        assert 'bankPageViews(x,x.thumbs)' in text
        assert 'bankCatalogAbort' in text
        assert '@page{size:A4;margin:0}' in text
        assert 'width:210mm' in text and 'height:297mm' in text
        assert '@media print{' in text
        # v4.163.0: catalog split client, selection-safe toolbar, adaptive print
        # chunking, crop touch UX and the bottom-sheet chrome.
        assert 'bankPartsReady' in text and "'&part='+part" in text
        assert 'function applyBankCatalog' in text
        assert 'function cmd(c){qRestoreSel();' in text
        assert "span.dataset.qFontFix='1'" in text
        assert 'function printChunkPages' in text
        assert 'function autosaveLocalNow' in text
        assert 'function imgEditFlush' in text
        assert 'function sourceCropResetCorner' in text
        assert 'sourceCropBadge' in text
        assert 'data-mobile-control="newq"' in text
        assert 'mobile-sheet-backdrop' in text
        assert "const{_normSrc,...rest}=it" in text
    css_text = SITE_FILES['site-update-v4.152.0/assets/css/exam-designer-mobile.css'].read_text(encoding='utf-8')
    assert 'mobile-sheet-open' in css_text
    assert '.source-crop-badge' in css_text
    assert 'attr(data-page-index)' in css_text
    assert '.mobile-sheet-backdrop' in css_text
    assert '@media print {' in css_text
    # v4.164.0: blocked-parent report (403 chats mapped to linked students)
    outbox_text = SITE_FILES['site-update-v4.152.0/includes/bot_outbox.php'].read_text(encoding='utf-8')
    assert 'function bot_outbox_blocked_chats' in outbox_text
    assert "last_error LIKE '%HTTP 403%'" in outbox_text
    ui_text = SITE_FILES['site-update-v4.152.0/includes/bot_admin_ui.php'].read_text(encoding='utf-8')
    assert 'bot_outbox_blocked_chats($platform)' in ui_text
    assert 'ولی‌هایی که ربات را مسدود کرده‌اند' in ui_text


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
