<?php
/**
 * Charts Helper Functions (includes/charts.php)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

if (!function_exists('render_course_chart')) {
    function render_course_chart($report_id, $canvasId = 'courseChart') {
        $grades = DB::fetchAll("SELECT subject_name, score FROM report_grades WHERE report_id = ?", [$report_id]);
        $labels = [];
        $scores = [];
        foreach ($grades as $g) {
            $labels[] = clean($g['subject_name']);
            $scores[] = (float)$g['score'];
        }
        $labelsJson = json_encode($labels, JSON_UNESCAPED_UNICODE);
        $scoresJson = json_encode($scores);
        return "
        <canvas id='{$canvasId}' height='150'></canvas>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof Chart !== 'undefined') {
                const ctx = document.getElementById('{$canvasId}');
                if (ctx) {
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: {$labelsJson},
                            datasets: [{
                                label: 'نمره درس',
                                data: {$scoresJson},
                                backgroundColor: '#2563eb',
                                borderRadius: 4
                            }]
                        },
                        options: { responsive: true, scales: { y: { min: 0, max: 20 } } }
                    });
                }
            }
        });
        </script>";
    }
}

if (!function_exists('render_gpa_chart')) {
    function render_gpa_chart($student_id, $year = null, $canvasId = 'gpaChart') {
        $params = [$student_id];
        $where = "student_id = ?";
        if ($year) {
            $where .= " AND academic_year = ?";
            $params[] = $year;
        }
        $reps = DB::fetchAll("SELECT report_month, term, gpa FROM reports WHERE {$where} ORDER BY id ASC", $params);
        $labels = [];
        $gpas = [];
        foreach ($reps as $r) {
            $labels[] = clean($r['term'] . ' (' . $r['report_month'] . ')');
            $gpas[] = (float)$r['gpa'];
        }
        $labelsJson = json_encode($labels, JSON_UNESCAPED_UNICODE);
        $gpasJson = json_encode($gpas);
        return "
        <canvas id='{$canvasId}' height='150'></canvas>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof Chart !== 'undefined') {
                const ctx = document.getElementById('{$canvasId}');
                if (ctx) {
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: {$labelsJson},
                            datasets: [{
                                label: 'روند تغییرات معدل',
                                data: {$gpasJson},
                                borderColor: '#16a34a',
                                backgroundColor: 'rgba(22, 163, 74, 0.1)',
                                fill: true,
                                tension: 0.3
                            }]
                        },
                        options: { responsive: true, scales: { y: { min: 0, max: 20 } } }
                    });
                }
            }
        });
        </script>";
    }
}

if (!function_exists('render_ranking_table')) {
    function render_ranking_table($class_name, $month, $year) {
        $reps = DB::fetchAll("SELECT r.gpa, s.first_name, s.last_name, s.national_id FROM reports r JOIN students s ON r.student_id = s.id WHERE r.class_name = ? AND r.report_month = ? AND r.academic_year = ? ORDER BY r.gpa DESC",
        [$class_name, $month, $year]);

        $html = '<div class="table-container"><table><thead><tr><th>رتبه</th><th>نام دانش‌آموز</th><th>معدل کل</th></tr></thead><tbody>';
        foreach ($reps as $idx => $r) {
            $rank = $idx + 1;
            $html .= "<tr><td><span class='badge badge-warning'>#{$rank}</span></td><td class='font-bold'>" . clean($r['first_name'] . ' ' . $r['last_name']) . "</td><td><span class='badge badge-success'>" . format_score($r['gpa']) . "</span></td></tr>";
        }
        if (empty($reps)) {
            $html .= "<tr><td colspan='3' class='text-center text-muted'>رتبه‌ای برای این کلاس ثبت نشده است.</td></tr>";
        }
        $html .= '</tbody></table></div>';
        return $html;
    }
}
