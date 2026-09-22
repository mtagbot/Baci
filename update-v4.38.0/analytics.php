<?php
/**
 * Advanced Analytics & Class/Month Comparisons (analytics.php)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/academic_year_helpers.php';
require_once __DIR__ . '/includes/header.php';

require_permission('manage_reports');

$selectedYear  = resolve_academic_year_request($_GET['year'] ?? get_current_academic_year()); // v4.38.0 unified
$selectedMonth = trim($_GET['month'] ?? '');
$selectedClass = trim($_GET['class'] ?? '');
$selectedGrade = trim($_GET['grade'] ?? '');

$years = get_academic_years_for_filter();
$months  = DB::fetchAll("SELECT DISTINCT report_month FROM reports");
$classes = DB::fetchAll("SELECT DISTINCT class_name FROM reports");
$grades  = DB::fetchAll("SELECT DISTINCT s.grade_level FROM reports r JOIN students s ON s.id=r.student_id WHERE s.grade_level<>'' ORDER BY s.grade_level");

// Calculate GPA per class for chart
$cWhere = ["r.academic_year = ?"];
$cParams = [$selectedYear];
if (!empty($selectedMonth)) { $cWhere[] = "r.report_month = ?"; $cParams[] = $selectedMonth; }
if (!empty($selectedGrade)) { $cWhere[] = "s.grade_level = ?"; $cParams[] = $selectedGrade; }
$cWhereSql = implode(' AND ', $cWhere);
$classGpaRaw = DB::fetchAll("SELECT r.class_name, AVG(r.gpa) as avg_gpa, COUNT(*) as count_rep FROM reports r JOIN students s ON s.id=r.student_id WHERE $cWhereSql GROUP BY r.class_name", $cParams);
$classLabels = [];
$classGpas   = [];
foreach ($classGpaRaw as $row) {
    $classLabels[] = $row['class_name'];
    $classGpas[]   = round((float)$row['avg_gpa'], 2);
}

// Calculate GPA per month/term for chart
$monthWhere = ["r.academic_year = ?"];
$monthParams = [$selectedYear];
if ($selectedClass) {
    $monthWhere[] = "r.class_name = ?";
    $monthParams[] = $selectedClass;
}
if ($selectedGrade) {
    $monthWhere[] = "s.grade_level = ?";
    $monthParams[] = $selectedGrade;
}
$monthWhereSql = implode(' AND ', $monthWhere);
$monthGpaRaw = DB::fetchAll("SELECT r.report_month, AVG(r.gpa) as avg_gpa FROM reports r JOIN students s ON s.id=r.student_id WHERE $monthWhereSql GROUP BY r.report_month", $monthParams);
$monthLabels = [];
$monthGpas   = [];
foreach ($monthGpaRaw as $row) {
    $monthLabels[] = $row['report_month'] ?: 'نامشخص';
    $monthGpas[]   = round((float)$row['avg_gpa'], 2);
}

// Top Students filtered by Year, Month, and Class
$topWhere = ["r.academic_year = ?"];
$topParams = [$selectedYear];
if (!empty($selectedMonth)) { $topWhere[] = "r.report_month = ?"; $topParams[] = $selectedMonth; }
if (!empty($selectedClass)) { $topWhere[] = "r.class_name = ?"; $topParams[] = $selectedClass; }
if (!empty($selectedGrade)) { $topWhere[] = "s.grade_level = ?"; $topParams[] = $selectedGrade; }
$topWhereSql = implode(' AND ', $topWhere);
$topStudents = DB::fetchAll("SELECT r.gpa, r.class_name, r.term, r.report_month, s.first_name, s.last_name, s.national_id, s.grade_level FROM reports r JOIN students s ON r.student_id = s.id WHERE $topWhereSql ORDER BY r.gpa DESC, s.last_name ASC, s.first_name ASC", $topParams);

// Cross-year monthly and yearly averages
$crossMonthRaw = DB::fetchAll("SELECT academic_year, report_month, AVG(gpa) avg_gpa FROM reports WHERE report_month IS NOT NULL AND report_month<>'' GROUP BY academic_year, report_month ORDER BY academic_year, report_month");
$crossLabels = [];
$crossData = [];
foreach ($crossMonthRaw as $r) { $label = $r['academic_year'].' - '.$r['report_month']; $crossLabels[]=$label; $crossData[]=round((float)$r['avg_gpa'],2); }
$yearAvgRaw = DB::fetchAll("SELECT academic_year, AVG(gpa) avg_gpa FROM reports GROUP BY academic_year ORDER BY academic_year");
$yearAvgLabels=[]; $yearAvgData=[];
foreach($yearAvgRaw as $r){$yearAvgLabels[]=$r['academic_year']; $yearAvgData[]=round((float)$r['avg_gpa'],2);} 

?>
<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold">تحلیل و مقایسه پیشرفته کارنامه‌ها</h2>
            <p class="text-sm text-muted">بررسی روند پیشرفت تحصیلی کلاس‌ها، ماه‌ها و نفرات برتر</p>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="card p-4 shadow-md">
        <form method="GET" class="grid grid-cols-5 gap-4 items-end">
            <div>
                <label class="block text-xs font-semibold mb-1">۱. سال تحصیلی</label>
                <select name="year" class="form-select font-bold text-xs" onchange="this.form.submit()">
                    <?php foreach ($years as $y): ?>
                        <option value="<?php echo clean($y['academic_year']); ?>" <?php echo $selectedYear === $y['academic_year'] ? 'selected' : ''; ?>><?php echo clean($y['academic_year']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">۲. ماه / نوبت</label>
                <select name="month" class="form-select font-bold text-xs" onchange="this.form.submit()">
                    <option value="">همه ماه‌ها</option>
                    <?php foreach ($months as $m): ?>
                        <option value="<?php echo clean($m['report_month']); ?>" <?php echo $selectedMonth === $m['report_month'] ? 'selected' : ''; ?>><?php echo clean($m['report_month']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">۳. فیلتر پایه</label>
                <select name="grade" class="form-select font-bold text-xs" onchange="this.form.submit()">
                    <option value="">همه پایه‌ها</option>
                    <?php foreach ($grades as $gr): ?>
                        <option value="<?php echo clean($gr['grade_level']); ?>" <?php echo $selectedGrade === $gr['grade_level'] ? 'selected' : ''; ?>><?php echo clean($gr['grade_level']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">۴. فیلتر کلاس</label>
                <select name="class" class="form-select font-bold text-xs" onchange="this.form.submit()">
                    <option value="">همه کلاس‌ها</option>
                    <?php foreach ($classes as $cls): ?>
                        <option value="<?php echo clean($cls['class_name']); ?>" <?php echo $selectedClass === $cls['class_name'] ? 'selected' : ''; ?>><?php echo clean($cls['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary w-full text-xs font-bold">اعمال فیلتر دقیق</button>
                <?php if ($selectedClass || $selectedMonth): ?>
                <a href="analytics.php?year=<?php echo urlencode($selectedYear); ?>" class="btn btn-secondary text-xs px-3">حذف</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Charts Grid -->
    <div class="grid grid-cols-2 gap-6">
        <div class="card">
            <h4 class="font-bold mb-4">مقایسه میانگین معدل کل کلاس‌ها (سال <?php echo clean($selectedYear); ?>)</h4>
            <canvas id="classAvgChart" height="220"></canvas>
        </div>
        <div class="card">
            <h4 class="font-bold mb-4">روند تغییرات معدل در ماه‌ها و نوبت‌های تحصیلی</h4>
            <canvas id="monthAvgChart" height="220"></canvas>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-6">
        <div class="card"><h4 class="font-bold mb-4">مقایسه میانگین ماه‌ها در سال‌های تحصیلی</h4><canvas id="crossYearMonthChart" height="220"></canvas></div>
        <div class="card"><h4 class="font-bold mb-4">میانگین معدل کل سال‌های تحصیلی</h4><canvas id="yearAvgChart" height="220"></canvas></div>
    </div>

    <!-- Top Students Table -->
    <div class="card">
        <h4 class="font-bold mb-4 text-green-700">🏆 نفرات برتر تحصیلی در سال <?php echo clean($selectedYear); ?></h4>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>رتبه</th>
                        <th>نام و نام خانوادگی</th>
                        <th>کد ملی</th>
                        <th>کلاس</th>
                        <th>نوبت / ماه</th>
                        <th>معدل کل</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topStudents as $idx => $ts): ?>
                    <tr>
                        <td class="font-bold text-amber-500">#<?php echo tr_num($idx + 1, 'fa'); ?></td>
                        <td class="font-bold"><?php echo clean($ts['first_name'] . ' ' . $ts['last_name']); ?></td>
                        <td class="font-mono text-xs"><?php echo clean($ts['national_id']); ?></td>
                        <td><?php echo clean($ts['class_name']); ?></td>
                        <td><?php echo clean($ts['term']); ?></td>
                        <td><span class="badge badge-success text-sm"><?php echo format_score($ts['gpa']); ?></span></td>
                    </tr>
                    <?php endforeach; if (empty($topStudents)): ?>
                    <tr><td colspan="6" class="text-center text-muted">داده‌ای برای نمایش نفرات برتر وجود ندارد.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Chart !== 'undefined') {
        // Class Avg Chart
        const classCtx = document.getElementById('classAvgChart');
        if (classCtx) {
            new Chart(classCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($classLabels, JSON_UNESCAPED_UNICODE); ?>,
                    datasets: [{
                        label: 'میانگین معدل کلاس',
                        data: <?php echo json_encode($classGpas); ?>,
                        backgroundColor: '#2563eb',
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    scales: { y: { min: 0, max: 20 } },
                    plugins: { legend: { display: false } }
                }
            });
        }

        const crossCtx=document.getElementById('crossYearMonthChart'); if(crossCtx){new Chart(crossCtx,{type:'bar',data:{labels:<?php echo json_encode($crossLabels, JSON_UNESCAPED_UNICODE); ?>,datasets:[{label:'میانگین',data:<?php echo json_encode($crossData); ?>,backgroundColor:'#8b5cf6'}]},options:{responsive:true,scales:{y:{min:0,max:20}}}});}
        const yearCtx=document.getElementById('yearAvgChart'); if(yearCtx){new Chart(yearCtx,{type:'line',data:{labels:<?php echo json_encode($yearAvgLabels, JSON_UNESCAPED_UNICODE); ?>,datasets:[{label:'میانگین کل سال',data:<?php echo json_encode($yearAvgData); ?>,borderColor:'#f59e0b',backgroundColor:'rgba(245,158,11,.15)',fill:true}]},options:{responsive:true,scales:{y:{min:0,max:20}}}});}

        // Month Avg Chart
        const monthCtx = document.getElementById('monthAvgChart');
        if (monthCtx) {
            new Chart(monthCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($monthLabels, JSON_UNESCAPED_UNICODE); ?>,
                    datasets: [{
                        label: 'میانگین معدل در ماه/نوبت',
                        data: <?php echo json_encode($monthGpas); ?>,
                        borderColor: '#16a34a',
                        backgroundColor: 'rgba(22, 163, 74, 0.1)',
                        fill: true,
                        tension: 0.3
                    }]
                },
                options: {
                    responsive: true,
                    scales: { y: { min: 0, max: 20 } }
                }
            });
        }
    }
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
