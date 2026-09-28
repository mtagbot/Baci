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
# v4.170.0 / v2.92.0 — پنل مشاور واکنش‌گرا و بازشونده (حباب گفتگو، فیلتر
# وضعیت/پایه)، میان‌برهای پرونده، گزارش «آلبوم عکس» A4 با جای یادداشت.
SITE_ARCHIVE = 'MODIFIED-FILES-V4.170.0.zip'
DESKTOP_ARCHIVE = 'SchoolDesk-FIX-v2.92.0.zip'
SITE_FILES = {
    'site-update-v4.152.0/exam-design-api.php': ROOT / 'update-v4.152.0/exam-design-api.php',
    'site-update-v4.152.0/exam-print.php': ROOT / 'update-v4.152.0/exam-print.php',
    'site-update-v4.152.0/assets/css/exam-designer-mobile.css': ROOT / 'update-v4.152.0/assets/css/exam-designer-mobile.css',
    # v4.164.0: report which parents blocked the bot (403 chats → linked students).
    # v4.165.0: blocked chats leave the retry cycle and wake on the next user message.
    # v4.166.0: profile identity resolver — the 6-digit part links, letters/prefix never matter.
    'site-update-v4.152.0/includes/bot_admin_ui.php': ROOT / 'update-v4.152.0/includes/bot_admin_ui.php',
    'site-update-v4.152.0/includes/bot_outbox.php': ROOT / 'update-v4.152.0/includes/bot_outbox.php',
    'site-update-v4.152.0/includes/bot_webhook_engine.php': ROOT / 'update-v4.152.0/includes/bot_webhook_engine.php',
    'site-update-v4.152.0/includes/bot_helpers.php': ROOT / 'update-v4.152.0/includes/bot_helpers.php',
    # v4.169.0: بستهٔ هشت‌موردی
    'site-update-v4.152.0/counselor-panel.php': ROOT / 'update-v4.152.0/counselor-panel.php',
    'site-update-v4.152.0/counselor-file.php': ROOT / 'update-v4.152.0/counselor-file.php',
    'site-update-v4.152.0/reports-lists.php': ROOT / 'update-v4.152.0/reports-lists.php',
    'site-update-v4.152.0/executive-panel.php': ROOT / 'update-v4.152.0/executive-panel.php',
    'site-update-v4.152.0/staff-student-file.php': ROOT / 'update-v4.152.0/staff-student-file.php',
    'site-update-v4.152.0/students.php': ROOT / 'update-v4.152.0/students.php',
    'site-update-v4.152.0/student-modal.php': ROOT / 'update-v4.152.0/student-modal.php',
    'site-update-v4.152.0/assets/js/main.js': ROOT / 'update-v4.152.0/assets/js/main.js',
    'site-update-v4.152.0/includes/docx_class_list.php': ROOT / 'update-v4.152.0/includes/docx_class_list.php',
    'site-update-v4.152.0/includes/bot_role_engine.php': ROOT / 'update-v4.152.0/includes/bot_role_engine.php',
    # v4.170.0: پنل مشاور واکنش‌گرا + گزارش آلبوم عکس
    'site-update-v4.152.0/assets/css/counselor-panel.css': ROOT / 'update-v4.152.0/assets/css/counselor-panel.css',
    'site-update-v4.152.0/includes/photo_album.php': ROOT / 'update-v4.152.0/includes/photo_album.php',
}
DESKTOP_FILES = {
    'SchoolDeskPro/www/exam-design-api.php': ROOT / 'desktop-app-v2/patch/www-exam-design-api.php',
    'SchoolDeskPro/www/exam-print.php': ROOT / 'desktop-app-v2/patch/www-exam-print.php',
    'SchoolDeskPro/www/assets/css/exam-designer-mobile.css': ROOT / 'desktop-app-v2/patch/www-assets-css-exam-designer-mobile.css',
    # v4.166.0: identity resolver parity (the desktop mirror of bot_helpers).
    'SchoolDeskPro/reports/includes/bot_helpers.php': ROOT / 'desktop-app-v2/patch/includes-bot_helpers.php',
    # v4.169.0: نسخهٔ دسکتاپ این دو صفحه تفاوت دسترسی قدیمی دارد — جدا از سایت
    'SchoolDeskPro/www/students.php': ROOT / 'desktop-app-v2/patch/www-students.php',
    'SchoolDeskPro/www/student-modal.php': ROOT / 'desktop-app-v2/patch/www-student-modal.php',
    # v4.169.0: بستهٔ هشت‌موردی — آینهٔ صفحات و موتور PDF ربات در www دسکتاپ
    'SchoolDeskPro/www/counselor-panel.php': ROOT / 'desktop-app-v2/patch/www-counselor-panel.php',
    'SchoolDeskPro/www/counselor-file.php': ROOT / 'desktop-app-v2/patch/www-counselor-file.php',
    'SchoolDeskPro/www/reports-lists.php': ROOT / 'desktop-app-v2/patch/www-reports-lists.php',
    'SchoolDeskPro/www/executive-panel.php': ROOT / 'desktop-app-v2/patch/www-executive-panel.php',
    'SchoolDeskPro/www/staff-student-file.php': ROOT / 'desktop-app-v2/patch/www-staff-student-file.php',
    'SchoolDeskPro/www/assets/js/main.js': ROOT / 'desktop-app-v2/patch/www-assets-js-main.js',
    'SchoolDeskPro/www/includes/docx_class_list.php': ROOT / 'desktop-app-v2/patch/includes-docx_class_list.php',
    'SchoolDeskPro/www/includes/bot_role_engine.php': ROOT / 'desktop-app-v2/patch/includes-bot_role_engine.php',
    # v4.170.0: آینهٔ واکنش‌گرایی پنل مشاور + آلبوم عکس
    'SchoolDeskPro/www/assets/css/counselor-panel.css': ROOT / 'desktop-app-v2/patch/www-assets-css-counselor-panel.css',
    'SchoolDeskPro/www/includes/photo_album.php': ROOT / 'desktop-app-v2/patch/includes-photo_album.php',
}

# v4.169.0: فایل‌هایی که عیناً بین سایت و دسکتاپ مشترک‌اند (کپی بایت‌به‌بایت).
# students.php و student-modal.php عمداً اینجا نیستند — نسخهٔ دسکتاپشان
# تفاوت‌های دسترسی قدیمی دارد و جدا وصله می‌شود.
PARITY_PAIRS = [
    ('site-update-v4.152.0/counselor-panel.php', 'SchoolDeskPro/www/counselor-panel.php'),
    ('site-update-v4.152.0/counselor-file.php', 'SchoolDeskPro/www/counselor-file.php'),
    ('site-update-v4.152.0/reports-lists.php', 'SchoolDeskPro/www/reports-lists.php'),
    ('site-update-v4.152.0/executive-panel.php', 'SchoolDeskPro/www/executive-panel.php'),
    ('site-update-v4.152.0/staff-student-file.php', 'SchoolDeskPro/www/staff-student-file.php'),
    ('site-update-v4.152.0/assets/js/main.js', 'SchoolDeskPro/www/assets/js/main.js'),
    ('site-update-v4.152.0/includes/docx_class_list.php', 'SchoolDeskPro/www/includes/docx_class_list.php'),
    ('site-update-v4.152.0/includes/bot_role_engine.php', 'SchoolDeskPro/www/includes/bot_role_engine.php'),
    ('site-update-v4.152.0/assets/css/counselor-panel.css', 'SchoolDeskPro/www/assets/css/counselor-panel.css'),
    ('site-update-v4.152.0/includes/photo_album.php', 'SchoolDeskPro/www/includes/photo_album.php'),
]


def validate_sources():
    assert SITE_FILES['site-update-v4.152.0/assets/css/exam-designer-mobile.css'].read_bytes() == DESKTOP_FILES['SchoolDeskPro/www/assets/css/exam-designer-mobile.css'].read_bytes()
    assert SITE_FILES['site-update-v4.152.0/exam-design-api.php'].read_bytes() == DESKTOP_FILES['SchoolDeskPro/www/exam-design-api.php'].read_bytes()
    # v4.169.0: آینه‌های بایت‌به‌بایت سایت/دسکتاپ
    for site_key, desk_key in PARITY_PAIRS:
        assert SITE_FILES[site_key].read_bytes() == DESKTOP_FILES[desk_key].read_bytes(), (site_key, desk_key)
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
    # v4.165.0: blocked chats park after 3x403, born-parked messages, auto wake
    assert "state='blocked'" in outbox_text
    assert 'function bot_outbox_chat_is_blocked' in outbox_text
    assert 'function bot_outbox_unblock_chat' in outbox_text
    assert "'blocked'=>'مسدود (بدون تلاش)'" in ui_text
    # v4.168.0: compact queue list — repeated errors grouped, raw JSON never shown
    assert "state NOT IN ('sent','blocked')" in ui_text
    assert 'خطای ۴۰۳ — کاربر ربات را مسدود کرده است' in ui_text
    assert '$queueGroups' in ui_text
    engine_text = SITE_FILES['site-update-v4.152.0/includes/bot_webhook_engine.php'].read_text(encoding='utf-8')
    assert 'bot_outbox_unblock_chat($platform, $chatId)' in engine_text
    # v4.166.0: identity resolver — 6-digit profile identity links; letters/prefix never matter
    helpers_text = SITE_FILES['site-update-v4.152.0/includes/bot_helpers.php'].read_text(encoding='utf-8')
    desk_helpers_text = DESKTOP_FILES['SchoolDeskPro/reports/includes/bot_helpers.php'].read_text(encoding='utf-8')
    for text in (helpers_text, desk_helpers_text):
        assert 'function bot_find_student_by_identity' in text
        assert 'شناسهٔ ۶ رقمی پرونده' in text
    assert "preg_match('/^\\d{6,10}$/', $nid)" in engine_text
    assert 'bot_find_student_by_identity($nid)' in engine_text
    assert "(string)$student['national_id']" in engine_text
    # v4.167.0: composite serials («ب/26/265486») match by their 6-digit part
    assert 'student_serial_parts((string)$student[\'serial_number\'])' in engine_text
    assert "$sp['recognized'] && $sp['number'] !== '' && $sp['letter'] !== ''" in engine_text

    # ═══ v4.169.0: بستهٔ هشت‌موردی ═══
    # ۰+۱+۲: پنل مشاور — فیلتر سال، دکمهٔ یادداشت، برچسب/عکس، مکالمهٔ ربات
    cp_text = SITE_FILES['site-update-v4.152.0/counselor-panel.php'].read_text(encoding='utf-8')
    assert "$cns_year_select('year'" in cp_text, 'year filter select (shared renderer)'
    assert 'یادداشت مشاور' in cp_text, 'counselor-note button first'
    assert 'counselor-file.php?id=' in cp_text, 'link to the per-student file'
    assert 'cns-photo' in cp_text, 'ID-photo frame class'
    assert "callback_data' => 'counselreply_'" in cp_text, 'glass reply button enqueued via outbox'
    assert 'bot_send_message($reqRow[\'platform\']' in cp_text, 'reply goes through the queue'
    cf_text = SITE_FILES['site-update-v4.152.0/counselor-file.php'].read_text(encoding='utf-8')
    assert 'teacher_has_counselor' in cf_text and 'counselor_notes' in cf_text, 'counselor-only notes page'
    # ۲: موتور وب‌هوک — دکمهٔ پاسخ و گام مکالمه (پیش از ماشین‌حالت counsel_)
    assert "strpos($data, 'counselreply_')" in engine_text, 'reply callback handled'
    assert "'counsel_reply_wait'" in engine_text, 'parent follow-up step'
    assert engine_text.index("step === 'counsel_reply_wait'") < engine_text.index("strpos($step, 'counsel_')"), 'follow-up step runs before the counsel_ state machine'
    for htext in (helpers_text, desk_helpers_text):
        assert 'function ensure_counseling_schema' in htext
        assert 'function counseling_resolve_students' in htext
        assert 'function counseling_notify_counselors' in htext
    # ۳: لیست‌ها و گزارشات برای معاون اجرایی
    rl_text = SITE_FILES['site-update-v4.152.0/reports-lists.php'].read_text(encoding='utf-8')
    assert 'if (!current_teacher_is_executive()) require_permission(\'manage_classes\');' in rl_text
    ep_text = SITE_FILES['site-update-v4.152.0/executive-panel.php'].read_text(encoding='utf-8')
    assert "loadHub(this,'reports-lists.php?embedded=1')" in ep_text
    # ۴: حافظهٔ فیلترها — back= در فایل دانش‌آموز + بازگردانی با back مرورگر
    ssf_text = SITE_FILES['site-update-v4.152.0/staff-student-file.php'].read_text(encoding='utf-8')
    assert "$_GET['back']" in ssf_text and "strpos($backParam, '://')" in ssf_text
    # ۵: جستجوی سریع + به‌روزرسانی در لحظهٔ ستون انضباط
    js_text = SITE_FILES['site-update-v4.152.0/assets/js/main.js'].read_text(encoding='utf-8')
    desk_js_text = DESKTOP_FILES['SchoolDeskPro/www/assets/js/main.js'].read_text(encoding='utf-8')
    for jst in (js_text, desk_js_text):
        assert 'applyStudentRowStats' in jst, 'row stats live update'
        assert "'flt:'+location.pathname" in jst, 'filter memory'
        assert "navType==='back_forward'" in jst, 'restore only on back/forward'
        assert 'studentsTable' in jst, 'instant filter for the students table'
    for sm in (SITE_FILES['site-update-v4.152.0/student-modal.php'], DESKTOP_FILES['SchoolDeskPro/www/student-modal.php']):
        assert "'stats'=>$rowStats" in sm.read_text(encoding='utf-8'), 'modal returns fresh counters'
    for st in (SITE_FILES['site-update-v4.152.0/students.php'], DESKTOP_FILES['SchoolDeskPro/www/students.php']):
        assert 'data-role="disc"' in st.read_text(encoding='utf-8'), 'row cells tagged for live update'
    # ۶: لیست کلاسی دبیر — جلسات/تاریخ با سلول چپ خود ادغام (Word + PDF)
    dcl_text = SITE_FILES['site-update-v4.152.0/includes/docx_class_list.php'].read_text(encoding='utf-8')
    assert 'dcl_merge_header_label_pair' in dcl_text
    assert '<w:gridSpan w:val="2"/>' in dcl_text
    assert 'colspan="2">جلسات' in dcl_text and 'colspan="2">تاریخ' in dcl_text
    # ۷: PDF آزمون — تمام صفحه (بدون مقیاس حاشیهٔ ۵mm) + کف کادر سوال داخل صفحه
    for eng in (SITE_FILES['site-update-v4.152.0/includes/bot_role_engine.php'], DESKTOP_FILES['SchoolDeskPro/www/includes/bot_role_engine.php']):
        etext = eng.read_text(encoding='utf-8')
        assert '$safeM = 0.0;' in etext, 'no forced 5mm print margin'
        assert '296 - $headerH - $qTopMm' in etext, 'question box bottom stays on the page'

    # ═══ v4.170.0 ═══
    # ۱: لیست واکنش‌گرا + میان‌برهای پرونده (یادداشت ↔ اطلاعات/کارنامه/انضباط)
    assert 'class="cns-students"' in cp_text, 'responsive students table'
    assert 'data-role="actions"' in cp_text, 'action cell tagged for mobile card layout'
    assert "assets/css/counselor-panel.css" in cp_text, 'panel stylesheet hooked in'
    cpcss_text = SITE_FILES['site-update-v4.152.0/assets/css/counselor-panel.css'].read_text(encoding='utf-8')
    assert 'object-fit:cover' in cpcss_text, 'ID-photo style portrait'
    assert '@media (max-width:767px)' in cpcss_text, 'mobile card rows'
    assert 'attr(data-label)' in cpcss_text, 'row labels in card mode'
    assert 'staff-student-file.php?tab=info' in cf_text and 'staff-student-file.php?tab=discipline' in cf_text, 'notes → dossier shortcuts'
    assert 'counselor-file.php?id=' in ssf_text, 'dossier → notes shortcut'
    # ۲: درخواست‌های بازشونده + حباب گفتگو + فیلترها
    assert '<details class="cns-req"' in cp_text, 'collapsible request card'
    assert 'cns-bubble-row' in cp_text, 'messenger-style bubbles'
    assert 'name="rstatus"' in cp_text and 'name="grade"' in cp_text, 'status + grade filters'
    assert "$cns_req_year" in cp_text, 'academic-year filter derived from the Jalali date'
    assert '.cns-bubble.counselor' in cpcss_text and '.cns-bubble.parent' in cpcss_text, 'bubble styling'
    # ۳: گزارش آلبوم عکس
    pa_text = SITE_FILES['site-update-v4.152.0/includes/photo_album.php'].read_text(encoding='utf-8')
    assert 'function pab_render_print_html' in pa_text
    assert 'function pab_photo_data_uri' in pa_text
    assert "strpos($rel, '..') !== false" in pa_text, 'photo path traversal guard'
    assert '@page{size:A4 portrait;margin:0}' in pa_text, 'A4 print page'
    assert "array_chunk($students, $perPage)" in pa_text, 'overflow to the next sheet'
    assert 'photo_album_pdf' in rl_text and 'pab_render_print_html' in rl_text, 'album wired into the hub'
    assert 'آلبوم عکس کلاس‌ها' in rl_text, 'album tab'


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
