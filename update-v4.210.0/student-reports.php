<?php
// File: student-reports.php (v4.210.0)
/**
 * کارنامه‌های دانش‌آموز — صفحهٔ مستقل
 *
 * جدول کارنامه‌ها و نمودار روند پیشرفت از صفحهٔ اصلی به این صفحه منتقل
 * شده‌اند. منطق مسدودسازیِ کارنامه دست‌نخورده است: هر ردیف همچنان با
 * is_report_locked_for_student() سنجیده می‌شود و در صورت مسدودی، علتِ
 * ثبت‌شده (گروهی/فردی) نمایش داده می‌شود — همان تابعی که ربات و پنل
 * مدیریت استفاده می‌کنند، پس هر سه یک تصمیم می‌گیرند.
 */
require_once __DIR__ . '/includes/auth.php';
require_student();

$studentId = (int)$_SESSION['student_id'];
$student = DB::fetch("SELECT * FROM students WHERE id = ?", [$studentId]);
if (!$student) {
    session_destroy();
    redirect('index.php?view=login');
}

if (is_file(__DIR__ . '/includes/student_nav.php')) require_once __DIR__ . '/includes/student_nav.php';

/* v4.210.0 — ورود از مسیر «استعلام کارنامه» با ?year= به این صفحه می‌رسد؛
   فهرست مثل قبل همهٔ کارنامه‌های دانش‌آموز است و سال درخواستی فقط یادآوری می‌شود. */
$requestedYear = trim((string)($_GET['year'] ?? ''));

$reports = DB::fetchAll("SELECT * FROM reports WHERE student_id = ? ORDER BY id DESC", [$studentId]);

/* نمودار فقط از کارنامه‌های آزاد ساخته می‌شود؛ مسدود = نمره‌ای نمایش داده نمی‌شود. */
$chartLabels = [];
$chartGpas   = [];
foreach (array_reverse($reports) as $rep) {
    if (!is_report_locked_for_student($rep)) {
        $chartLabels[] = clean($rep['term'] . ' (' . $rep['report_month'] . ')');
        $chartGpas[]   = (float)$rep['gpa'];
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-6">
    <?php if (function_exists('render_student_nav')) render_student_nav('reports', $student); ?>

    <?php if (count($chartGpas) > 0): ?>
    <div class="card shadow-lg">
        <h3 class="font-bold mb-4">📈 نمودار روند پیشرفت تحصیلی شما (معدل‌های کارنامه)</h3>
        <canvas id="studentProgressChart" height="150"></canvas>
    </div>
    <?php endif; ?>

    <div class="card shadow-lg">
        <div class="flex flex-wrap justify-between items-center border-b pb-3 mb-4 gap-2">
            <div>
                <h3 class="font-bold text-lg text-primary">📊 کارنامه‌های صادرشده برای شما</h3>
                <p class="text-xs text-muted mt-1">
                    <?php if ($reports): ?><?php echo tr_num(count($reports), 'fa'); ?> کارنامه<?php else: ?>هنوز کارنامه‌ای صادر نشده است<?php endif; ?>
                    <?php if ($requestedYear !== ''): ?> · استعلام برای سال تحصیلی <b><?php echo clean(tr_num($requestedYear, 'fa')); ?></b><?php endif; ?>
                </p>
            </div>
            <a href="student-panel.php" class="btn btn-secondary text-xs">داشبورد</a>
        </div>
        <div class="table-container">
            <table id="studentReportsTable">
                <thead>
                    <tr>
                        <th>سال تحصیلی</th>
                        <th>نوبت / ماه</th>
                        <th>مجموع نمرات</th>
                        <th>معدل کل</th>
                        <th>وضعیت دسترسی</th>
                        <th>عملیات و دریافت فایل</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $rep):
                        $repLockReason = '';
                        $repLocked = is_report_locked_for_student($rep, $repLockReason);
                    ?>
                    <tr>
                        <td class="font-bold"><?php echo clean($rep['academic_year']); ?></td>
                        <td><?php echo clean($rep['term'] . ' (' . $rep['report_month'] . ')'); ?></td>
                        <td><?php echo $repLocked ? '<span class="text-muted">---</span>' : format_score($rep['total_score']); ?></td>
                        <td>
                            <?php if ($repLocked): ?>
                                <span class="text-muted">---</span>
                            <?php else: ?>
                                <span class="badge badge-info text-sm"><?php echo format_score($rep['gpa']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($repLocked): ?>
                                <span class="badge badge-danger">مشاهده مسدود است</span>
                            <?php else: ?>
                                <span class="badge badge-success">آزاد و قابل دریافت</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($repLocked): ?>
                                <span class="text-xs text-red-600"><?php echo clean($repLockReason ?: (function_exists('report_lock_generic_reason') ? report_lock_generic_reason() : 'به دلیل امور اداری/مالی یا تکمیل نمرات مسدود است.')); ?></span>
                            <?php else: ?>
                                <div class="flex gap-2 justify-end">
                                    <a href="report-view.php?id=<?php echo $rep['id']; ?>" class="btn btn-primary text-xs px-3 py-1">مشاهده کارنامه</a>
                                    <a href="report-print.php?id=<?php echo $rep['id']; ?>&pt=<?php echo urlencode(make_report_print_token($rep['id'], $studentId)); ?>" target="_self" class="btn btn-secondary text-xs px-3 py-1">🖨️ چاپ کارنامه</a>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; if (empty($reports)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-6">هنوز کارنامه‌ای برای شما صادر نشده است.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Chart !== 'undefined') {
        const ctx = document.getElementById('studentProgressChart');
        if (ctx) {
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($chartLabels, JSON_UNESCAPED_UNICODE); ?>,
                    datasets: [{
                        label: 'معدل کل نوبت',
                        data: <?php echo json_encode($chartGpas); ?>,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.1)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 6,
                        pointHoverRadius: 8
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
