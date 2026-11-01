<?php
// File: student-discipline.php (v4.210.0)
/**
 * موارد انضباطیِ دانش‌آموز — صفحهٔ مستقل
 *
 * همان داده‌ای که پیش‌تر پایینِ صفحهٔ اصلی نمایش داده می‌شد، حالا صفحهٔ
 * خودش را دارد. سیاست حریم خصوصی دست‌نخورده است: فقط عنوان مورد و تاریخ
 * شمسی؛ یادداشت‌های داخلیِ معاونت هرگز برای دانش‌آموز/ولی خوانده یا نمایش
 * داده نمی‌شود (ستون internal_note حتی select هم نمی‌شود).
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

$disciplineItems = [];
try {
    $disciplineItems = DB::fetchAll(
        "SELECT title_text, occurred_at_jalali FROM student_discipline_records WHERE student_id = ? ORDER BY id DESC",
        [$studentId]
    );
} catch (Throwable $e) {
    $disciplineItems = [];
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-6">
    <?php if (function_exists('render_student_nav')) render_student_nav('discipline', $student); ?>

    <div class="card shadow-lg">
        <div class="flex flex-wrap justify-between items-center border-b pb-3 mb-4 gap-2">
            <div>
                <h3 class="font-bold text-lg text-amber-700">⚠️ موارد انضباطی ثبت‌شده</h3>
                <p class="text-xs text-muted mt-1">
                    <?php if ($disciplineItems): ?>
                        <?php echo tr_num(count($disciplineItems), 'fa'); ?> مورد ثبت شده است
                    <?php else: ?>
                        موردی برای شما ثبت نشده است
                    <?php endif; ?>
                </p>
            </div>
            <a href="student-panel.php" class="btn btn-secondary text-xs">داشبورد</a>
        </div>

        <?php if ($disciplineItems): ?>
        <div class="table-container">
            <table id="studentDisciplineList">
                <thead><tr><th>عنوان مورد</th><th>تاریخ و زمان شمسی</th></tr></thead>
                <tbody>
                <?php foreach ($disciplineItems as $row): ?>
                    <tr>
                        <td class="font-bold"><?php echo clean($row['title_text'] ?? ''); ?></td>
                        <td><?php echo tr_num($row['occurred_at_jalali'] ?? '', 'fa'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-muted mt-2">اگر به یکی از این موارد اعتراض دارید، از طریق دفتر مدرسه یا مشاور پیگیری کنید.</p>
        <?php else: ?>
        <div class="text-center text-muted py-10">
            <p>موردی ثبت نشده است.</p>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
