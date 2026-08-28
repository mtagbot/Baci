<?php
// File: includes/teacher_nav.php  (v4.61.0)
/**
 * Shared teacher-panel hero + app-style navigation grid.
 * Include this right after header.php on every page a teacher visits
 * (teacher-panel.php, online-exams.php, online-question-bank.php, ...).
 * Renders nothing unless a teacher is logged in (admins keep their sidebar).
 */
if (!function_exists('render_teacher_nav')) {
    function render_teacher_nav() {
        if (empty($_SESSION['teacher_id']) || !empty($_SESSION['admin_id'])) return;
        $tnTeacherId = (int)$_SESSION['teacher_id'];
        $tnTeacher = DB::fetch("SELECT * FROM teachers WHERE id = ?", [$tnTeacherId]);
        if (!$tnTeacher) return;
        $tnYear = trim($_GET['year'] ?? ($_SESSION['teacher_year'] ?? get_setting('current_academic_year', '1404/1405')));
        $tnYears = function_exists('get_academic_years_for_filter') ? get_academic_years_for_filter() : [];
        $tnSelf = basename($_SERVER['PHP_SELF']);
        $tnTab = $_GET['tab'] ?? '';
        $tiles = [
            ['teacher-panel.php?tab=schedule&year=' . urlencode($tnYear), '📅', 'برنامه هفتگی من', $tnSelf === 'teacher-panel.php' && in_array($tnTab, ['', 'schedule'], true)],
            ['teacher-panel.php?tab=grading&year=' . urlencode($tnYear), '📝', 'ثبت و ویرایش نمرات', $tnSelf === 'teacher-panel.php' && $tnTab === 'grading'],
            ['teacher-panel.php?tab=messages&year=' . urlencode($tnYear), '💬', 'اعتراضات و پیام‌ها', $tnSelf === 'teacher-panel.php' && $tnTab === 'messages'],
            ['teacher-panel.php?tab=exams&year=' . urlencode($tnYear), '🖊️', 'طراحی سوالات تشریحی', $tnSelf === 'teacher-panel.php' && $tnTab === 'exams'],
            /* v4.62.0: تب «بانک سوالات» داخل صفحه آزمون‌های آنلاین هست؛
               کاشی جداگانه حذف شد. کاشی آزمون‌ها در صفحه بانک هم active می‌ماند. */
            ['online-exams.php', '🧪', 'آزمون‌های آنلاین', in_array($tnSelf, ['online-exams.php', 'online-question-bank.php'], true)],
        ];
        if (!empty($tnTeacher['is_deputy'])) $tiles[] = ['deputy-panel.php', '🛡️', 'پنل معاونت', $tnSelf === 'deputy-panel.php'];
        if (!empty($tnTeacher['is_counselor'])) $tiles[] = ['counselor-panel.php', '🧭', 'پنل مشاور', $tnSelf === 'counselor-panel.php'];
        if (!empty($tnTeacher['is_executive'])) $tiles[] = ['executive-panel.php', '🏢', 'پنل معاون اجرایی', $tnSelf === 'executive-panel.php'];
        ?>
    <style>
    .tp-hero{background:linear-gradient(135deg,#059669,#065f46);color:#fff;border-radius:18px;padding:22px;box-shadow:0 10px 25px rgba(5,150,105,.28);margin-bottom:24px}
    .tp-hero .tp-badge{display:inline-block;background:rgba(255,255,255,.2);color:#fff;border-radius:999px;padding:4px 12px;font-size:.68rem;font-weight:700;margin-bottom:8px}
    .tp-hero h2{color:#fff !important}
    .tp-hero .tp-meta{font-size:.72rem;color:#d1fae5}
    .tp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(118px,1fr));gap:10px;margin-top:18px}
    .tp-tile{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.28);border-radius:14px;padding:14px 8px;color:#fff !important;text-decoration:none !important;font-size:.72rem;font-weight:700;text-align:center;transition:transform .15s ease,background .15s ease;min-height:86px}
    .tp-tile:hover{background:rgba(255,255,255,.28);transform:translateY(-2px)}
    .tp-tile.active{background:rgba(255,255,255,.32);border-color:#fff}
    .tp-tile .ic{font-size:1.55rem;line-height:1}
    @media(max-width:480px){.tp-grid{grid-template-columns:repeat(3,1fr)}}
    </style>
    <div class="tp-hero">
        <div>
            <span class="tp-badge">پورتال اختصاصی دبیران</span>
            <h2 class="text-2xl font-bold mb-1">استاد <?php echo clean($tnTeacher['full_name']); ?></h2>
            <p class="tp-meta">کد ملی: <span class="font-mono"><?php echo tr_num($tnTeacher['national_id'], 'fa'); ?></span> | کد پرسنلی: <b><?php echo clean($tnTeacher['personnel_code']); ?></b></p>
            <?php if ($tnSelf === 'teacher-panel.php' && $tnYears): ?>
            <form method="GET" class="mt-2"><select name="year" class="form-select text-xs" style="max-width:160px" onchange="this.form.submit()"><?php foreach ($tnYears as $ty): ?><option value="<?php echo clean($ty['academic_year']); ?>" <?php echo $tnYear === $ty['academic_year'] ? 'selected' : ''; ?>><?php echo clean($ty['academic_year']); ?></option><?php endforeach; ?></select></form>
            <?php endif; ?>
        </div>
        <div class="tp-grid">
            <?php foreach ($tiles as $t): ?>
            <a href="<?php echo clean($t[0]); ?>" class="tp-tile<?php echo $t[3] ? ' active' : ''; ?>"><span class="ic"><?php echo $t[1]; ?></span><?php echo clean($t[2]); ?></a>
            <?php endforeach; ?>
        </div>
    </div>
        <?php
    }
}
