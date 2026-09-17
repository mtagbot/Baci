<?php
// File: bulk-print.php
/**
 * Bulk Reports Printer with Grid Layout (bulk-print.php)
 * Supports printing 1, 2, or 4 report cards per A4 sheet!
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/academic_year_helpers.php';
require_permission('manage_reports');

$year  = resolve_academic_year_request($_GET['year'] ?? get_current_academic_year()); // v4.38.0 unified
$month = trim($_GET['month'] ?? 'آبان');
$class = trim($_GET['class'] ?? '');
$layout = (int)($_GET['layout'] ?? 1);
if (!in_array($layout, [1, 2, 4])) $layout = 1;

if (isset($_GET['print'])) {
    $where = ["r.academic_year = ?", "r.report_month = ?"];
    $params = [$year, $month];
    if ($class) { $where[] = "r.class_name = ?"; $params[] = $class; }
    $whereSql = implode(' AND ', $where);

    $reports = DB::fetchAll("SELECT r.*, s.first_name, s.last_name, s.national_id, s.father_name, s.grade_level, s.photo_url FROM reports r JOIN students s ON r.student_id = s.id WHERE $whereSql ORDER BY r.gpa DESC", $params);

    $rLine1 = get_setting('report_header_line1', 'بسمه تعالی');
    $rLine2 = get_setting('report_header_line2', 'دبیرستان غیردولتی بصیرت');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head><meta name=viewport content="width=device-width, initial-scale=1">
    <meta charset="UTF-8">
    <title>چاپ گروهی کارنامه‌ها - کلاس <?php echo clean($class ?: 'همه'); ?></title>
    <style>
        @font-face { font-family: 'Vazirmatn'; src: url('uploads/Vazirmatn/Vazirmatn-Regular.woff2') format('woff2'), url('uploads/Vazirmatn/Vazirmatn-Regular.ttf') format('truetype'); font-display: swap; }
        @font-face { font-family: 'Vazirmatn'; src: url('uploads/Vazirmatn/Vazirmatn-Bold.woff2') format('woff2'), url('uploads/Vazirmatn/Vazirmatn-Bold.ttf') format('truetype'); font-weight: 700; font-display: swap; }
        * { box-sizing: border-box; }
        body { font-family: 'Vazirmatn', Tahoma, sans-serif; background: #fff; color: #000; margin: 0; padding: 0; }
        .btn-print { display: block; margin: 15px auto; padding: 10px 25px; background: #2563eb; color: #fff; border: none; border-radius: 5px; font-size: 15px; cursor: pointer; }
        
        <?php if ($layout === 1): ?>
        @page { size: A4 portrait; margin: 10mm; }
        .grid-container { display: block; }
        .report-card { border: 2px solid #000; padding: 15px; margin-bottom: 20px; page-break-after: always; height: 95vh; display: flex; flex-direction: column; justify-content: space-between; }
        table { font-size: 13px; }
        /* v4.74.0: قبلاً h1/h2 سربرگ در چینش ۱تایی اندازه پیش‌فرض مرورگر (بسیار بزرگ) داشتند */
        .header h1 { font-size: 15px; margin: 0 0 3px 0; }
        .header h2 { font-size: 13px; margin: 0; }
        <?php elseif ($layout === 2): ?>
        @page { size: A4 portrait; margin: 5mm; }
        .grid-container { display: block; }
        .report-card { border: 1.5px solid #000; padding: 8px; margin-bottom: 5mm; height: 46vh; overflow: hidden; page-break-inside: avoid; }
        table { font-size: 10px; }
        th, td { padding: 3px !important; }
        .header h1 { font-size: 13px !important; margin: 0 !important; }
        .header h2 { font-size: 11px !important; margin: 0 !important; }
        .info-grid { font-size: 11px !important; padding: 4px !important; margin-bottom: 5px !important; }
        <?php elseif ($layout === 4): ?>
        @page { size: A4 landscape; margin: 5mm; }
        .grid-container { display: grid; grid-template-columns: 1fr 1fr; gap: 5mm; }
        .report-card { border: 1px solid #000; padding: 5px; height: 46vh; overflow: hidden; page-break-inside: avoid; }
        table { font-size: 8.5px; margin-bottom: 5px !important; }
        th, td { padding: 2px !important; }
        .header { padding-bottom: 4px !important; margin-bottom: 5px !important; }
        .header h1 { font-size: 11px !important; margin: 0 !important; }
        .header h2 { font-size: 9.5px !important; margin: 0 !important; }
        .info-grid { font-size: 9.5px !important; padding: 2px !important; margin-bottom: 4px !important; }
        .signatures { margin-top: 10px !important; font-size: 10px !important; }
        <?php endif; ?>

        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1.5px solid #000; padding-bottom: 8px; margin-bottom: 10px; }
        .header-text { text-align: center; flex: 1; }
        .photo-box { width: 55px; height: 73px; /* v4.91.0: نسبت ۳×۴ */ border: 1px solid #000; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .photo-box img { width: 100%; height: 100%; object-fit: cover; }
        .info-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 5px; border: 1px solid #000; padding: 6px; margin-bottom: 8px; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th, td { border: 1px solid #000; padding: 5px; text-align: center; }
        th { background: #f0f0f0; }
        .signatures { display: flex; justify-content: space-around; margin-top: 20px; font-size: 12px; font-weight: bold; }
        .sig-box { text-align: center; width: 180px; }
        @media print { .btn-print, .no-print { display: none !important; } }
    </style>
<?php echo app_appearance_head(); ?>
<link rel=stylesheet href=assets/css/school-ui.css?v20260917c><script defer src=assets/js/school-icons.js?v20260917c></script><script defer src=assets/js/school-ui.js?v20260917c></script></head>
<body>
<button onclick="appPrint()" class="btn-print"><svg data-ui-icon="print" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M7 8V3h10v5M7 17H3V9h18v8h-4M7 14h10v8H7ZM17 11h.1"/></svg> چاپ گروهی (Layout: <?php echo tr_num($layout, 'fa'); ?> کارنامه در صفحه)</button>

<div class="grid-container">
    <?php foreach ($reports as $idx => $report): 
        $rawGrades = DB::fetchAll("SELECT * FROM report_grades WHERE report_id = ?", [$report['id']]);
        $parsedData = extract_clean_grades_and_discipline($rawGrades, $report['discipline_score']);
        $grades = $parsedData['regular_grades'];
        $computedGpa = $parsedData['count'] > 0 ? $parsedData['calculated_gpa'] : $report['gpa'];
        $discScore = $parsedData['discipline_score'];

        if ($parsedData['count'] > 0 && abs((float)$report['gpa'] - (float)$computedGpa) > 0.001) {
            DB::execute("UPDATE reports SET gpa = ?, total_score = ? WHERE id = ?", [$computedGpa, $computedGpa * $parsedData['count'], $report['id']]);
            $report['gpa'] = $computedGpa;
        }
        $classReports = DB::fetchAll("SELECT id FROM reports WHERE academic_year = ? AND term = ? AND report_month = ? AND class_name = ? ORDER BY gpa DESC",
            [$report['academic_year'], $report['term'], $report['report_month'], $report['class_name']]);
        $classRank = 1;
        foreach ($classReports as $ci => $cr) { if ($cr['id'] == $report['id']) { $classRank = $ci + 1; break; } }
        
        $gradeReports = DB::fetchAll("SELECT id FROM reports WHERE academic_year = ? AND term = ? AND report_month = ? ORDER BY gpa DESC",
            [$report['academic_year'], $report['term'], $report['report_month']]);
        $gradeRank = 1;
        foreach ($gradeReports as $gi => $gr) { if ($gr['id'] == $report['id']) { $gradeRank = $gi + 1; break; } }
    ?>
    <div class="report-card">
        <div>
            <div class="header">
                <div style="width: 120px; text-align: right; font-size: 11px; line-height: 1.5;">
                    <p style="margin:1px 0;">استان: <b><?php echo clean(get_setting('school_province', 'تهران')); ?></b></p>
                    <p style="margin:1px 0;">منطقه: <b><?php echo clean(get_setting('school_region', 'منطقه ۳')); ?></b></p>
                    <p style="margin:1px 0;">نوع واحد: <b><?php echo clean(get_setting('school_unit_type', 'متوسطه اول پسرانه')); ?></b></p>
                    <p style="margin:1px 0;">تاریخ صدور: <b style="font-family:monospace;"><?php echo jdate('Y/m/d'); ?></b></p>
                </div>
                <div class="header-text">
                    <h1><?php echo clean($rLine1); ?></h1>
                    <h1><?php echo clean($rLine2); ?></h1>
                    <h2>کارنامه تحصیلی دانش‌آموز - <?php echo clean($report['term']); ?> سال <?php echo tr_num($report['academic_year'], 'fa'); ?></h2>
                </div>
                <div class="photo-box">
                    <?php if (!empty($report['photo_url'])): ?>
                        <img src="<?php echo clean($report['photo_url']); ?>" alt="عکس">
                    <?php else: ?>
                        <span style="font-size: 9px;">عکس ندارد</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="info-grid">
                <div><span>نام: </span><b><?php echo clean($report['first_name'] . ' ' . $report['last_name']); ?></b></div>
                <div><span>کد ملی: </span><b style="font-family: monospace;"><?php echo tr_num($report['national_id'], 'fa'); ?></b></div>
                <div><span>پدر: </span><b><?php echo clean($report['father_name'] ?: '---'); ?></b></div>
                <div><span>کلاس: </span><b><?php echo clean($report['class_name']); ?></b></div>
            </div>

            <!-- v4.73.0: کادر تکراری «معدل کل / رتبه کلاس / رتبه پایه» حذف شد؛ همین اطلاعات در ردیف پایانی جدول نمرات وجود دارد -->
            <table>
                <thead>
                    <tr>
                        <th>نام درس</th>
                        <th>نمره</th>
                        <th>رتبه کلاس</th>
                        <th>رتبه پایه</th>
                        <th>نتیجه</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grades as $g): 
                        $sClassReps = DB::fetchAll("SELECT score FROM report_grades rg JOIN reports r ON rg.report_id = r.id WHERE r.academic_year = ? AND r.term = ? AND r.report_month = ? AND r.class_name = ? AND rg.subject_name = ? ORDER BY rg.score DESC",
                            [$report['academic_year'], $report['term'], $report['report_month'], $report['class_name'], $g['subject_name']]);
                        $sClsRank = 1;
                        foreach ($sClassReps as $si => $scr) { if ((float)$scr['score'] <= (float)$g['score']) { $sClsRank = $si + 1; break; } }

                        $sGradeReps = DB::fetchAll("SELECT score FROM report_grades rg JOIN reports r ON rg.report_id = r.id WHERE r.academic_year = ? AND r.term = ? AND r.report_month = ? AND rg.subject_name = ? ORDER BY rg.score DESC",
                            [$report['academic_year'], $report['term'], $report['report_month'], $g['subject_name']]);
                        $sGrdRank = 1;
                        foreach ($sGradeReps as $si => $sgr) { if ((float)$sgr['score'] <= (float)$g['score']) { $sGrdRank = $si + 1; break; } }
                    ?>
                    <tr>
                        <td style="font-weight:bold;"><?php echo clean($g['subject_name']); ?></td>
                        <td style="font-weight:bold;"><?php echo format_score($g['score']); ?></td>
                        <td>#<?php echo tr_num($sClsRank, 'fa'); ?></td>
                        <td>#<?php echo tr_num($sGrdRank, 'fa'); ?></td>
                        <td><?php echo $g['score'] >= 10 ? 'قبول' : 'مردود'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f0f0f0; font-weight:bold;">
                        <td colspan="2" style="text-align:right;">معدل نهایی کارنامه:</td>
                        <td><?php echo format_score($computedGpa); ?></td>
                        <td>رتبه کلاس: #<?php echo tr_num($classRank, 'fa'); ?></td>
                        <td>رتبه پایه: #<?php echo tr_num($gradeRank, 'fa'); ?></td>
                    </tr>
                    <tr>
                        <td colspan="2" style="text-align:right; font-weight:bold; font-size:10px;">نمره انضباط (غیرموثر در معدل):</td>
                        <!-- v4.73.0: سلول عدد بدون font-size کوچک — هم‌اندازه با سایر نمرات عددی جدول -->
                        <td style="font-weight:bold; color:#10b981;"><?php echo ($discScore !== null && $discScore !== '' && (float)$discScore >= 0) ? format_score($discScore) : 'بدون نمره'; ?></td>
                        <td colspan="2" style="font-size:10px;">ارزشیابی انضباطی مدرسه</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div>
            <hr style="border: none; border-top: 1px dashed #000; margin: 10px 0;">
            <p style="font-size: 11px; margin: 4px 0;"><svg data-ui-icon="crop" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 2v16h16M2 6h16v16M3 21 21 3"/></svg> اینجانب ............................................................................ ولی دانش‌آموز <b><?php echo clean($report['first_name'] . ' ' . $report['last_name']); ?></b> عملکرد تحصیلی نوبت <b><?php echo clean($report['term']); ?></b> را مشاهده و بررسی نموده‌ام.</p>
            <div class="signatures">
                <div class="sig-box">
                    <p>امضا و تاریخ رویت ولی دانش‌آموز</p>
                    <div style="border-bottom: 1px solid #000; width: 120px; margin: 25px auto 0 auto;"></div>
                </div>
                <div class="sig-box" style="position: relative;">
                    <p style="position: relative; z-index: 1;">مهر و امضای مدیر مدرسه</p>
                    <div style="height: 45px;"></div>
                    <!-- v4.73.0: تصاویر مهر و امضا بالاتر آمدند تا روی متن «مهر و امضای مدیر مدرسه» قرار بگیرند و آن را بپوشانند -->
                    <?php if ($sStamp = get_setting('school_stamp_url')): ?>
                        <img src="<?php echo clean($sStamp); ?>" style="max-height: 58px; position: absolute; left: 50%; transform: translateX(-58%); top: -14px; opacity: 0.9; z-index: 5;">
                    <?php endif; ?>
                    <?php if ($pSig = get_setting('principal_signature_url')): ?>
                        <img src="<?php echo clean($pSig); ?>" style="max-height: 48px; position: absolute; left: 50%; transform: translateX(-42%); top: -8px; z-index: 10;">
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; if (empty($reports)): ?>
        <p style="text-align: center; padding: 50px;">هیچ کارنامه‌ای برای چاپ یافت نشد.</p>
    <?php endif; ?>
</div>
</body>
</html>
<?php
    exit;
}

require_once __DIR__ . '/includes/header.php';
$classes = DB::fetchAll("SELECT DISTINCT name FROM classes");
$years = get_academic_years_for_filter();
?>
<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold"><svg data-ui-icon="print" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M7 8V3h10v5M7 17H3V9h18v8h-4M7 14h10v8H7ZM17 11h.1"/></svg> چاپ گروهی کارنامه‌ها با چینش انتخابی (A4 Grid Layout)</h2>
            <p class="text-sm text-muted">امکان چاپ ۱، ۲ یا ۴ کارنامه در هر صفحه A4 با حداقل حاشیه جهت صرفه‌جویی در مصرف کاغذ</p>
        </div>
        <a href="reports.php" class="btn btn-secondary text-sm">&rarr; بازگشت به مدیریت کارنامه‌ها</a>
    </div>

    <div class="card max-w-xl mx-auto shadow-lg p-6">
        <form method="GET" action="bulk-print.php" target="_blank">
            <input type="hidden" name="print" value="1">
            <div class="mb-4">
                <label class="block text-xs font-bold mb-1.5">انتخاب سال تحصیلی</label>
                <select name="year" class="form-select font-bold">
                    <?php foreach ($years as $yy): ?>
                        <option value="<?php echo clean($yy['academic_year']); ?>" <?php echo $year===$yy['academic_year']?'selected':''; ?>><?php echo clean($yy['academic_year']); ?><?php echo !empty($yy['is_default'])?' - پیش‌فرض':''; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-4">
                <label class="block text-xs font-bold mb-1.5">ماه / نوبت کارنامه</label>
                <select name="month" class="form-select font-bold"><?php $bpSel = trim($_POST['month'] ?? 'آبان'); foreach (['مهر','آبان','آذر','دی','بهمن','اسفند','فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','نوبت اول','نوبت دوم'] as $bpM): ?><option value="<?php echo $bpM; ?>" <?php echo $bpSel===$bpM?'selected':''; ?>><?php echo $bpM; ?></option><?php endforeach; ?></select>
            </div>
            <div class="mb-4">
                <label class="block text-xs font-bold mb-1.5">انتخاب کلاس</label>
                <select name="class" class="form-select font-bold">
                    <option value="">چاپ کل مدرسه (همه کلاس‌ها)</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo clean($c['name']); ?>"><?php echo clean($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-6 bg-slate-50 dark:bg-slate-800 p-4 rounded-lg border">
                <label class="block text-xs font-bold mb-2 text-primary">چینش و تراکم چاپ در کاغذ A4 (Layout Option):</label>
                <div class="space-y-2 text-sm font-semibold">
                    <label class="flex items-center gap-2">
                        <input type="radio" name="layout" value="1" checked>
                        <span><svg data-ui-icon="report" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14 2H5v20h14V7Zm0 0v5h5M8 17v-3m4 3v-6m4 6v-4"/></svg> ۱ کارنامه کامل در هر صفحه A4 (استاندارد بزرگ)</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" name="layout" value="2">
                        <span><svg data-ui-icon="report" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14 2H5v20h14V7Zm0 0v5h5M8 17v-3m4 3v-6m4 6v-4"/></svg> ۲ کارنامه در هر صفحه A4 (دو کارنامه افقی بالا و پایین)</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" name="layout" value="4">
                        <span><svg data-ui-icon="card" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="2" y="4" width="20" height="16" rx="2"/><circle cx="8" cy="10" r="2"/><path d="M5 17c0-5 6-5 6 0M14 9h5m-5 5h5"/></svg> ۴ کارنامه در هر صفحه A4 (شبکه ۲×۲ بسیار فشرده و اقتصادی)</span>
                    </label>
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-full py-3.5 text-base font-extrabold shadow-lg"><svg data-ui-icon="print" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M7 8V3h10v5M7 17H3V9h18v8h-4M7 14h10v8H7ZM17 11h.1"/></svg> تولید و پیش‌نمایش چاپ گروهی کارنامه‌ها &larr;</button>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
