<?php
/**
 * Export Report Card to Excel / Spreadsheet format (export-excel.php)
 */

require_once __DIR__ . '/includes/auth.php';

$reportId = (int)($_GET['id'] ?? 0);
$report = DB::fetch("SELECT r.*, s.first_name, s.last_name, s.national_id, s.father_name, s.grade_level FROM reports r JOIN students s ON r.student_id = s.id WHERE r.id = ?", [$reportId]);

if (!$report) {
    die("Report not found.");
}

if (is_student_logged_in() && ($report['student_id'] != $_SESSION['student_id'] || $report['is_locked'])) {
    die("Unauthorized access.");
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
$filename = "Report_" . $report['national_id'] . "_" . $report['academic_year'] . ".xls";

// Emit Excel XML Spreadsheet format (opens nicely in MS Excel with formatting and RTL)
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:o="urn:schemas-microsoft-com:office:office"
          xmlns:x="urn:schemas-microsoft-com:office:excel"
          xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:html="http://www.w3.org/TR/REC-html40">
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal">
   <Alignment ss:Vertical="Center" ss:ReadingOrder="RightToLeft"/>
   <Font ss:FontName="Tahoma" ss:Size="11"/>
  </Style>
  <Style ss:ID="Header">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:ReadingOrder="RightToLeft"/>
   <Font ss:FontName="Tahoma" ss:Size="14" ss:Bold="1"/>
  </Style>
  <Style ss:ID="ColHeader">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:ReadingOrder="RightToLeft"/>
   <Font ss:FontName="Tahoma" ss:Size="11" ss:Bold="1"/>
   <Interior ss:Color="#E2E8F0" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="Data">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:ReadingOrder="RightToLeft"/>
  </Style>
 </Styles>
 <Worksheet ss:Name="کارنامه تحصیلی" ss:RightToLeft="1">
  <Table>
   <Row ss:Height="30">
    <Cell ss:MergeAcross="4" ss:StyleID="Header"><Data ss:Type="String"><?php echo clean($schoolName); ?> - کارنامه تحصیلی</Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="ColHeader"><Data ss:Type="String">نام و نام خانوادگی:</Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"><?php echo clean($report['first_name'] . ' ' . $report['last_name']); ?></Data></Cell>
    <Cell ss:StyleID="ColHeader"><Data ss:Type="String">کد ملی:</Data></Cell>
    <Cell ss:MergeAcross="1" ss:StyleID="Data"><Data ss:Type="String"><?php echo clean($report['national_id']); ?></Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="ColHeader"><Data ss:Type="String">کلاس و پایه:</Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"><?php echo clean($report['class_name'] . ' (' . $report['grade_level'] . ')'); ?></Data></Cell>
    <Cell ss:StyleID="ColHeader"><Data ss:Type="String">نوبت / ماه:</Data></Cell>
    <Cell ss:MergeAcross="1" ss:StyleID="Data"><Data ss:Type="String"><?php echo clean($report['term'] . ' - ' . $report['report_month']); ?></Data></Cell>
   </Row>
   <Row ss:Height="15"/>
   <Row>
    <Cell ss:StyleID="ColHeader"><Data ss:Type="String">ردیف</Data></Cell>
    <Cell ss:StyleID="ColHeader"><Data ss:Type="String">نام درس</Data></Cell>
    <Cell ss:StyleID="ColHeader"><Data ss:Type="String">نمره اخذشده</Data></Cell>
    <Cell ss:StyleID="ColHeader"><Data ss:Type="String">حداکثر نمره</Data></Cell>
    <Cell ss:StyleID="ColHeader"><Data ss:Type="String">ضریب درس</Data></Cell>
   </Row>
   <?php foreach ($grades as $idx => $g): ?>
   <Row>
    <Cell ss:StyleID="Data"><Data ss:Type="Number"><?php echo $idx + 1; ?></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"><?php echo clean($g['subject_name']); ?></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="Number"><?php echo $g['score']; ?></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="Number"><?php echo $g['max_score']; ?></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="Number"><?php echo $g['coefficient']; ?></Data></Cell>
   </Row>
   <?php endforeach; ?>
   <Row ss:Height="25">
    <Cell ss:MergeAcross="2" ss:StyleID="ColHeader"><Data ss:Type="String">معدل کل نوبت:</Data></Cell>
    <Cell ss:MergeAcross="1" ss:StyleID="ColHeader"><Data ss:Type="String"><?php echo format_score($computedGpa); ?></Data></Cell>
   </Row>
   <Row ss:Height="20">
    <Cell ss:MergeAcross="2" ss:StyleID="ColHeader"><Data ss:Type="String">نمره انضباط (غیرموثر در معدل):</Data></Cell>
    <Cell ss:MergeAcross="1" ss:StyleID="ColHeader"><Data ss:Type="String"><?php echo ($discScore !== null && $discScore !== '' && (float)$discScore >= 0) ? format_score($discScore) : 'بدون نمره'; ?></Data></Cell>
   </Row>
  </Table>
 </Worksheet>
</Workbook>
