<?php
/**
 * Export Report Card to Real PDF using local TCPDF (export-pdf.php)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/school_roles.php';
ensure_school_roles_schema();

$reportId = (int)($_GET['id'] ?? 0);
$report = DB::fetch("SELECT r.*, s.first_name, s.last_name, s.national_id, s.father_name, s.grade_level FROM reports r JOIN students s ON r.student_id = s.id WHERE r.id = ?", [$reportId]);

if (!$report) {
    die("Report not found.");
}

// The downloadable export must enforce the same scope as its report-print preview.
$printTokenOk=verify_report_print_token($_GET['pt'] ?? '',$reportId,$report['student_id']);
$staffFileAccess=current_teacher_has_student_file_access();
if (!$printTokenOk && ((is_student_logged_in() && ($report['student_id'] != $_SESSION['student_id'] || $report['is_locked'])) || (!is_student_logged_in() && !is_admin_logged_in() && !$staffFileAccess))) {
    http_response_code(403);
    die('دسترسی غیرمجاز به کارنامه.');
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
$schoolName = get_setting('school_name', 'دبیرستان نمونه دولتی نخبگان');
$filename = "Report_" . $report['national_id'] . ".pdf";

// Include local TCPDF
$tcpdfPath = __DIR__ . '/vendor/tcpdf/tcpdf.php';
if (file_exists($tcpdfPath)) {
    require_once $tcpdfPath;
}

if (class_exists('TCPDF')) {
    $fontInfo=app_pdf_font();
    if(!$fontInfo){redirect('report-print.php?id='.$reportId);exit;}
    $GLOBALS['report_pdf_font']=$fontInfo['name'];
    // Custom PDF class with RTL support
    class ReportPDF extends TCPDF {
        public function Header() {
            global $schoolName, $report;
            $this->SetFont($GLOBALS['report_pdf_font'], 'B', 14);
            $this->Cell(0, 10, clean($schoolName), 0, false, 'C', 0, '', 0, false, 'M', 'M');
            $this->Ln(8);
            $this->SetFont($GLOBALS['report_pdf_font'], '', 11);
            $this->Cell(0, 10, 'کارنامه تحصیلی - ' . clean($report['term'] . ' (' . $report['report_month'] . ')'), 0, false, 'C', 0, '', 0, false, 'M', 'M');
        }
        public function Footer() {
            $this->SetY(-15);
            $this->SetFont($GLOBALS['report_pdf_font'], 'I', 8);
            $this->Cell(0, 10, 'صفحه ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'C', 0, '', 0, false, 'T', 'M');
        }
    }

    $pdf = new ReportPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setRTL(true);
    $pdf->SetCreator('Student Report System');
    $pdf->SetTitle('کارنامه ' . $report['first_name'] . ' ' . $report['last_name']);
    $pdf->setPrintHeader(true);
    $pdf->setPrintFooter(true);
    $pdf->SetMargins(15, 30, 15);
    $boldInfo=app_pdf_font(true);
    foreach(['','B','I','BI'] as $style)$pdf->AddFont($fontInfo['name'],$style,strpos($style,'B')!==false?$boldInfo['file']:$fontInfo['file']);
    $pdf->AddPage();

    // Set font
    $pdf->SetFont($GLOBALS['report_pdf_font'], '', 10);

    // HTML Content
    $html = '
    <style>
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #444; padding: 6px; text-align: center; }
        th { background-color: #eee; font-weight: bold; }
    </style>
    <div style="background-color: #f9f9f9; padding: 10px; border: 1px solid #ddd; margin-bottom: 15px;">
        <table border="0" cellpadding="4">
            <tr>
                <td><b>نام و نام خانوادگی:</b> ' . clean($report['first_name'] . ' ' . $report['last_name']) . '</td>
                <td><b>کد ملی:</b> ' . clean($report['national_id']) . '</td>
            </tr>
            <tr>
                <td><b>کلاس و پایه:</b> ' . clean($report['class_name'] . ' (' . $report['grade_level'] . ')') . '</td>
                <td><b>سال تحصیلی:</b> ' . clean($report['academic_year']) . '</td>
            </tr>
        </table>
    </div>
    <br><br>
    <table>
        <thead>
            <tr>
                <th>ردیف</th>
                <th>نام درس</th>
                <th>نمره اخذشده</th>
                <th>حداکثر نمره</th>
                <th>نتیجه</th>
            </tr>
        </thead>
        <tbody>';

    foreach ($grades as $idx => $g) {
        $status = $g['score'] >= 10 ? 'قبول' : 'مردود';
        $html .= '<tr>
            <td>' . ($idx + 1) . '</td>
            <td><b>' . clean($g['subject_name']) . '</b></td>
            <td><b>' . $g['score'] . '</b></td>
            <td>' . $g['max_score'] . '</td>
            <td>' . $status . '</td>
        </tr>';
    }

    $html .= '</tbody>
        <tfoot>
            <tr style="background-color: #eee; font-weight: bold;">
                <td colspan="3" style="text-align: right;">معدل کل نوبت:</td>
                <td colspan="2" style="font-size: 14px; color: #2563eb;">' . format_score($computedGpa) . '</td>
            </tr>
            <tr style="font-size: 11px;">
                <td colspan="3" style="text-align: right; font-weight: bold;">نمره انضباط (غیرموثر در معدل):</td>
                <td colspan="2" style="font-weight: bold; color: #10b981;">' . (($discScore !== null && $discScore !== '' && (float)$discScore >= 0) ? format_score($discScore) : 'بدون نمره') . '</td>
            </tr>
        </tfoot>
    </table>
    <br><br><br>
    <table border="0" style="width: 100%; margin-top: 30px;">
        <tr>
            <td style="border: none; text-align: center;"><b>امضای ولی دانش‌آموز</b></td>
            <td style="border: none; text-align: center;"><b>مهر و امضای مدیریت مدرسه</b></td>
        </tr>
    </table>';

    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output($filename, 'D');
    exit;
} else {
    // If TCPDF class failed to load, fallback to clean print redirect
    header("Location: report-print.php?id=" . $reportId);
    exit;
}
