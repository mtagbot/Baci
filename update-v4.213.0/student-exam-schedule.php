<?php
// File: student-exam-schedule.php (v4.212.0)
/**
 * برنامهٔ امتحانی دانش‌آموز — «کدام ماه؟»
 *
 * قاعدهٔ نمایش (خواستهٔ مدیر مدرسه):
 *   پیش‌فرض: «جدیدترین ماهی» که برای همین دانش‌آموز برنامهٔ امتحان دارد.
 *            ملاک، ترتیب ماه در سال تحصیلی است (مهر … شهریور)، نه تاریخ ثبت؛
 *            پس وقتی برنامهٔ آذر تعریف شود، آذر جای آبان را می‌گیرد و با
 *            تعریف ماه بعدی، همان ماه جدید نمایش داده می‌شود.
 *   دستی:    اگر مدیر در پایین صفحهٔ «امتحانات» تیک «انتخاب دستی ماه»
 *            را بزند و ماهی را انتخاب کند، همان ماه (حتی قدیمی‌تر) نمایش
 *            داده می‌شود. این تصمیم فقط با تنظیم مدیر عوض می‌شود.
 *
 * V4.212.0 — خواستهٔ مدیر مدرسه: در این صفحه نام دبیر نمایش داده نمی‌شود و
 * فقط نام درس ذکر می‌شود. ستون «دبیر» از جدول حذف شده است؛ دادهٔ آزمون
 * (شامل teacher_id) دست‌نخورده می‌ماند و در صفحه‌های مدیریتی همان‌جا که لازم
 * است دیده می‌شود.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/exams_helper.php';
require_student();

$studentId = (int)$_SESSION['student_id'];
$student = DB::fetch("SELECT * FROM students WHERE id = ?", [$studentId]);
if (!$student) {
    session_destroy();
    redirect('index.php?view=login');
}

if (is_file(__DIR__ . '/includes/student_nav.php')) require_once __DIR__ . '/includes/student_nav.php';

$selection = exam_student_schedule_selection($studentId);
$examRows  = $selection['rows'];
$shownMonth = (string)$selection['month'];
/* $selection['months'] فهرست کامل ماه‌های این سال است؛ برای نمایش به دانش‌آموز
   لازم نیست، ولی ربات و صفحهٔ مدیریت از همان تابع استفاده می‌کنند. */

require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-6">
    <?php if (function_exists('render_student_nav')) render_student_nav('exams', $student); ?>

    <div class="card shadow-lg">
        <div class="flex flex-wrap justify-between items-center border-b pb-3 mb-4 gap-2">
            <div>
                <h3 class="font-bold text-lg text-primary">📝 برنامه امتحانی من</h3>
                <p class="text-xs text-muted mt-1">
                    <?php if ($shownMonth !== ''): ?>
                        برنامهٔ ماه <b><?php echo clean($shownMonth); ?></b> · <?php echo tr_num(count($examRows), 'fa'); ?> آزمون
                    <?php else: ?>
                        هنوز ماهی برای برنامهٔ امتحانی ثبت نشده است
                    <?php endif; ?>
                </p>
            </div>
            <div class="flex gap-2">
                <a href="student-schedule.php" class="btn btn-outline text-xs">برنامه هفتگی</a>
                <a href="student-panel.php" class="btn btn-secondary text-xs">داشبورد</a>
            </div>
        </div>

        <?php if ($examRows): ?>
        <div class="table-container">
            <table id="studentExamSchedule">
                <thead>
                    <tr>
                        <th>تاریخ</th>
                        <th>روز</th>
                        <th>ساعت</th>
                        <th>درس</th>
                        <th>کلاس امتحانی</th>
                        <th>صندلی</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($examRows as $ex):
                    $dayName = trim((string)($ex['exam_day_name'] ?? ''));
                    if ($dayName === '' && function_exists('exam_jalali_day_name')) {
                        $dayName = exam_jalali_day_name($ex['exam_date_jalali'] ?? '');
                    }
                    $room = trim((string)($ex['exam_room'] ?? '')) !== '' ? $ex['exam_room'] : ($ex['exam_room_default'] ?? '');
                ?>
                    <tr>
                        <td class="font-bold"><?php echo tr_num($ex['exam_date_jalali'] ?? '', 'fa'); ?></td>
                        <td><?php echo clean($dayName !== '' ? $dayName : '—'); ?></td>
                        <td><?php echo tr_num($ex['start_time'] ?? '', 'fa'); ?></td>
                        <td class="font-bold"><?php echo clean($ex['subject_name'] ?? ''); ?></td>
                        <td><?php echo clean($room !== '' ? $room : '—'); ?></td>
                        <td><?php echo tr_num(($ex['seat_number'] ?? '') !== '' ? $ex['seat_number'] : '---', 'fa'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-muted mt-2">ساعت شروع و محل برگزاری ممکن است تا روز آزمون به‌روزرسانی شود؛ پیش از هر آزمون یک‌بار این صفحه را تازه کنید.</p>
        <?php else: ?>
        <div class="text-center text-muted py-10">
            <p>برنامه امتحانی برای شما ثبت نشده است.</p>
            <p class="text-xs mt-2">به‌محض تعریف برنامهٔ ماه توسط مدرسه، همین‌جا نمایش داده می‌شود.</p>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
