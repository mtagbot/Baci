<?php
// File: report-print.php
/**
 * Printable Version of Report Card (report-print.php) - Clean without coefficients
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/school_roles.php';
ensure_school_roles_schema();

$reportId = (int)($_GET['id'] ?? 0);
$report = DB::fetch("SELECT r.*, s.first_name, s.last_name, s.national_id, s.father_name, s.grade_level, s.photo_url FROM reports r JOIN students s ON r.student_id = s.id WHERE r.id = ?", [$reportId]);

if (!$report) {
    die('کارنامه یافت نشد.');
}

$staffFileAccess = current_teacher_has_student_file_access();
$printTokenOk = verify_report_print_token($_GET['pt'] ?? '', $reportId, $report['student_id']);
if (!$printTokenOk && is_student_logged_in() && ($report['student_id'] != $_SESSION['student_id'] || $report['is_locked'])) {
    die('غیرمجاز یا مسدود.');
}
if (!$printTokenOk && !is_student_logged_in() && !is_admin_logged_in() && !$staffFileAccess) {
    die('برای مشاهده کارنامه باید وارد سیستم شوید.');
}

$rawGrades = DB::fetchAll("SELECT * FROM report_grades WHERE report_id = ?", [$reportId]);
$parsedData = extract_clean_grades_and_discipline($rawGrades, $report['discipline_score']);
$grades = $parsedData['regular_grades'];
$computedGpa = $parsedData['count'] > 0 ? $parsedData['calculated_gpa'] : $report['gpa'];
$discScore = $parsedData['discipline_score'];

if ($parsedData['count'] > 0 && abs((float)$report['gpa'] - (float)$computedGpa) > 0.001) {
    DB::execute("UPDATE reports SET gpa = ?, total_score = ? WHERE id = ?", [$computedGpa, $computedGpa * $parsedData['count'], $reportId]);
    $report['gpa'] = $computedGpa;
}

// Compute overall ranks
$classReports = DB::fetchAll("SELECT id FROM reports WHERE academic_year = ? AND term = ? AND report_month = ? AND class_name = ? ORDER BY gpa DESC",
    [$report['academic_year'], $report['term'], $report['report_month'], $report['class_name']]);
$classRank = 1;
foreach ($classReports as $idx => $cr) { if ($cr['id'] == $reportId) { $classRank = $idx + 1; break; } }

$gradeReports = DB::fetchAll("SELECT id FROM reports WHERE academic_year = ? AND term = ? AND report_month = ? ORDER BY gpa DESC",
    [$report['academic_year'], $report['term'], $report['report_month']]);
$gradeRank = 1;
foreach ($gradeReports as $idx => $gr) { if ($gr['id'] == $reportId) { $gradeRank = $idx + 1; break; } }

$rLine1 = get_setting('report_header_line1', 'بسمه تعالی');
$rLine2 = get_setting('report_header_line2', 'دبیرستان غیردولتی بصیرت');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>چاپ کارنامه - <?php echo clean($report['first_name'] . ' ' . $report['last_name']); ?></title>
    <style>
        @font-face { font-family: 'Vazirmatn'; src: url('uploads/Vazirmatn/Vazirmatn-Regular.woff2') format('woff2'), url('uploads/Vazirmatn/Vazirmatn-Regular.ttf') format('truetype'); font-display: swap; }
        @font-face { font-family: 'Vazirmatn'; src: url('uploads/Vazirmatn/Vazirmatn-Bold.woff2') format('woff2'), url('uploads/Vazirmatn/Vazirmatn-Bold.ttf') format('truetype'); font-weight: 700; font-display: swap; }
        body { font-family: 'Vazirmatn', Tahoma, sans-serif; background: #fff; color: #000; margin: 0; padding: 20px; }
        .print-container { max-width: 800px; margin: 0 auto; border: 2px solid #000; padding: 25px; }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #000; padding-bottom: 15px; margin-bottom: 20px; }
        .header-text { text-align: center; flex: 1; }
        .header-text h1 { font-size: 18px; margin: 0 0 5px 0; }
        .header-text h2 { font-size: 16px; margin: 0; }
        .photo-box { width: 85px; height: 105px; border: 1px solid #000; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .photo-box img { width: 100%; height: 100%; object-fit: cover; }
        .info-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; border: 1px solid #000; padding: 10px; margin-bottom: 15px; font-size: 13px; }
        .ranks-box { display: flex; justify-content: space-around; border: 1px solid #000; background: #f9f9f9; padding: 10px; margin-bottom: 20px; font-weight: bold; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 25px; font-size: 13px; }
        th, td { border: 1px solid #000; padding: 8px; text-align: center; }
        th { background: #f0f0f0; }
        .signatures { display: flex; justify-content: space-around; margin-top: 50px; font-size: 14px; font-weight: bold; }
        .sig-box { text-align: center; width: 200px; }
        .btn-print { display: block; margin: 20px auto; padding: 10px 25px; background: #2563eb; color: #fff; border: none; border-radius: 5px; font-size: 15px; cursor: pointer; }
        @media print { .btn-print { display: none; } .print-container { border: none; padding: 0; } }
    </style>
</head>
<body>

<button onclick="window.print()" class="btn-print">🖨️ چاپ یا ذخیره PDF از مرورگر</button>

<div class="print-container">
    <div class="header">
        <div style="width: 120px; text-align: right; font-size: 11px; line-height: 1.6;">
            <p style="margin:2px 0;">استان: <b><?php echo clean(get_setting('school_province', 'تهران')); ?></b></p>
            <p style="margin:2px 0;">منطقه: <b><?php echo clean(get_setting('school_region', 'منطقه ۳')); ?></b></p>
            <p style="margin:2px 0;">نوع واحد: <b><?php echo clean(get_setting('school_unit_type', 'متوسطه اول پسرانه')); ?></b></p>
            <p style="margin:2px 0;">تاریخ صدور: <b style="font-family:monospace;"><?php echo jdate('Y/m/d'); ?></b></p>
        </div>
        <div class="header-text">
            <h1><?php echo clean($rLine1); ?></h1>
            <h1><?php echo clean($rLine2); ?></h1>
            <h2>کارنامه تحصیلی دانش‌آموز - <?php echo clean($report['term'] . ' (' . $report['report_month'] . ')'); ?> سال <?php echo tr_num($report['academic_year'], 'fa'); ?></h2>
        </div>
        <div class="photo-box">
            <?php if (!empty($report['photo_url'])): ?>
                <img src="<?php echo clean($report['photo_url']); ?>" alt="عکس">
            <?php else: ?>
                <span style="font-size: 11px;">عکس ندارد</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="info-grid">
        <div><span>نام و نام خانوادگی: </span><b><?php echo clean($report['first_name'] . ' ' . $report['last_name']); ?></b></div>
        <div><span>کد ملی: </span><b style="font-family: monospace;"><?php echo tr_num($report['national_id'], 'fa'); ?></b></div>
        <div><span>نام پدر: </span><b><?php echo clean($report['father_name'] ?: '---'); ?></b></div>
        <div><span>کلاس و پایه: </span><b><?php echo clean($report['class_name'] . ' (' . $report['grade_level'] . ')'); ?></b></div>
    </div>

    <div class="ranks-box">
        <div>معدل کل: <span><?php echo format_score($computedGpa); ?></span></div>
        <div>رتبه در کلاس: <span>#<?php echo tr_num($classRank, 'fa'); ?></span> (از <?php echo tr_num(count($classReports), 'fa'); ?> نفر)</div>
        <div>رتبه در پایه: <span>#<?php echo tr_num($gradeRank, 'fa'); ?></span> (از <?php echo tr_num(count($gradeReports), 'fa'); ?> نفر)</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>ردیف</th>
                <th>نام درس</th>
                <th>نمره اخذشده</th>
                <th>رتبه در کلاس</th>
                <th>رتبه در پایه</th>
                <th>نتیجه ارزشیابی</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($grades as $idx => $g): 
                // Subject ranks
                $sClassReps = DB::fetchAll("SELECT rg.score FROM report_grades rg JOIN reports r ON rg.report_id = r.id WHERE r.academic_year = ? AND r.term = ? AND r.report_month = ? AND r.class_name = ? AND rg.subject_name = ? ORDER BY rg.score DESC",
                    [$report['academic_year'], $report['term'], $report['report_month'], $report['class_name'], $g['subject_name']]);
                $sClsRank = 1;
                foreach ($sClassReps as $si => $scr) { if ((float)$scr['score'] <= (float)$g['score']) { $sClsRank = $si + 1; break; } }

                $sGradeReps = DB::fetchAll("SELECT rg.score FROM report_grades rg JOIN reports r ON rg.report_id = r.id WHERE r.academic_year = ? AND r.term = ? AND r.report_month = ? AND rg.subject_name = ? ORDER BY rg.score DESC",
                    [$report['academic_year'], $report['term'], $report['report_month'], $g['subject_name']]);
                $sGrdRank = 1;
                foreach ($sGradeReps as $si => $sgr) { if ((float)$sgr['score'] <= (float)$g['score']) { $sGrdRank = $si + 1; break; } }
            ?>
            <tr>
                <td><?php echo tr_num($idx + 1, 'fa'); ?></td>
                <td style="font-weight:bold;"><?php echo clean($g['subject_name']); ?></td>
                <td style="font-weight:bold; font-size:14px;"><?php echo display_score($g['score']); ?></td>
                <td>#<?php echo tr_num($sClsRank, 'fa'); ?></td>
                <td>#<?php echo tr_num($sGrdRank, 'fa'); ?></td>
                <td><?php echo ((float)$g['score'] == 21.0) ? 'غیبت' : ($g['score'] >= 10 ? 'قبول' : 'مردود'); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="background:#f0f0f0; font-weight:bold;">
                <td colspan="2" style="text-align:right;">معدل نهایی کارنامه:</td>
                <td><?php echo format_score($computedGpa); ?></td>
                <td>رتبه کلاس: #<?php echo tr_num($classRank, 'fa'); ?></td>
                <td colspan="2">رتبه پایه: #<?php echo tr_num($gradeRank, 'fa'); ?></td>
            </tr>
            <tr style="font-size:11px;">
                <td colspan="2" style="text-align:right; font-weight:bold;">نمره انضباط (غیرموثر در معدل):</td>
                <td style="font-weight:bold; color:#10b981;"><?php echo ($discScore !== null && $discScore !== '' && (float)$discScore >= 0) ? format_score($discScore) : 'بدون نمره'; ?></td>
                <td colspan="3">ارزشیابی انضباطی مدرسه</td>
            </tr>
        </tfoot>
    </table>

    <div style="margin-top: 30px;">
        <hr style="border: none; border-top: 1.5px dashed #000; margin: 20px 0;">
        <p style="font-size: 12px; margin: 10px 0;">✂️ اینجانب ولی دانش‌آموز <b><?php echo clean($report['first_name'] . ' ' . $report['last_name']); ?></b> گزارش عملکرد تحصیلی نوبت <b><?php echo clean($report['term']); ?></b> را مشاهده و بررسی نموده‌ام.</p>
        <div class="signatures">
            <div class="sig-box">
                <p>امضا و تاریخ رویت ولی دانش‌آموز</p>
                <div style="border-bottom: 1px solid #000; width: 130px; margin: 40px auto 0 auto;"></div>
            </div>
            <div class="sig-box" style="position: relative;">
                <p>مهر و امضای مدیر مدرسه</p>
                <div style="height: 60px; position: relative;">
                    <?php if ($sStamp = get_setting('school_stamp_url')): ?>
                        <img src="<?php echo clean($sStamp); ?>" style="max-height: 65px; position: absolute; left: 35px; top: -5px; opacity: 0.8;">
                    <?php endif; ?>
                    <?php if ($pSig = get_setting('principal_signature_url')): ?>
                        <img src="<?php echo clean($pSig); ?>" style="max-height: 55px; position: absolute; left: 50px; top: 0; z-index: 10;">
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
