<?php
// File: student-schedule.php (v4.210.0)
/**
 * برنامهٔ هفتگی دانش‌آموز — «هفتهٔ من»
 *
 * تا پیش از این نسخه، برنامهٔ هفتگی دانش‌آموز هیچ صفحه‌ای نداشت و فقط از
 * طریق ربات یا پرسیدن از مدرسه قابل مشاهده بود. این صفحه همان جدول
 * برنامهٔ هفتگی (class_schedules) را برای کلاس و سال تحصیلیِ خودِ
 * دانش‌آموز، فقط-خواندنی نشان می‌دهد: روزهای هفته × زنگ‌ها.
 *
 * هیچ داده‌ای نوشته نمی‌شود و هیچ منطق ثبت/ویرایشی تغییر نکرده است.
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

$studentYear = function_exists('student_panel_year') ? student_panel_year($student) : trim((string)($student['academic_year'] ?? ''));
$className   = trim((string)($student['class_name'] ?? ''));

/* ── خواندن برنامهٔ هفتگیِ همان کلاس و همان سال ──────────────────────────
   ترتیب تلاش: سال دقیق → همین سال با «-» → رکوردهای بدون سال (نصب قدیمی)
   → تطبیق نام کلاس با نرمال‌سازی (مثلاً «هفتم ۱» و «هفتم1»). */
$lessons = [];
if ($className !== '') {
    $yearAlt = str_replace('/', '-', $studentYear);
    $lessons = DB::fetchAll(
        "SELECT * FROM class_schedules WHERE class_name = ? AND (academic_year = ? OR academic_year = ?) ORDER BY id ASC",
        [$className, $studentYear, $yearAlt]
    );
    if (!$lessons && function_exists('norm_class_str')) {
        $needle = norm_class_str($className);
        $sameYear = DB::fetchAll(
            "SELECT * FROM class_schedules WHERE (academic_year = ? OR academic_year = ?) ORDER BY id ASC",
            [$studentYear, $yearAlt]
        );
        $lessons = array_values(array_filter($sameYear, function ($row) use ($needle) {
            return norm_class_str($row['class_name'] ?? '') === $needle;
        }));
    }
    if (!$lessons) {
        $lessons = DB::fetchAll("SELECT * FROM class_schedules WHERE class_name = ? AND (academic_year = '' OR academic_year IS NULL) ORDER BY id ASC", [$className]);
    }
}

/* روزهای هفته و نرمال‌سازی نام روز (نیم‌فاصله/فاصله/ي/ك) */
$weekDays = ['شنبه', 'یک‌شنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه'];
$dayKey = function ($value) {
    return str_replace(["\u{200C}", ' ', 'ي', 'ك'], ['', '', 'ی', 'ک'], trim((string)$value));
};
$week = [];
$dayLabels = [];
$maxPeriod = 4;
foreach ($lessons as $lesson) {
    $period = (int)preg_replace('/[^0-9]/', '', tr_num((string)($lesson['period_num'] ?? ''), 'en'));
    if ($period < 1) $period = 1;
    if ($period > $maxPeriod) $maxPeriod = $period;
    $dKey = $dayKey($lesson['day_of_week'] ?? '');
    $week[$dKey][$period][] = $lesson;
    if (!isset($dayLabels[$dKey])) $dayLabels[$dKey] = trim((string)($lesson['day_of_week'] ?? '')) ?: '—';
}
/* روزهایی که در فهرست استاندارد نیستند (مثلاً «جمعه») هم نمایش داده می‌شوند؛
   هیچ زنگی از جدول حذف نمی‌شود. */
$knownDayKeys = array_map($dayKey, $weekDays);
$extraDayLabels = [];
foreach (array_keys($week) as $dKey) {
    if (!in_array($dKey, $knownDayKeys, true)) $extraDayLabels[] = $dayLabels[$dKey] ?? '—';
}
/*
 * V4.211.0 — نام دبیر در برنامهٔ هفتگی فقط با «الگوی مرجع» نمایش داده می‌شود:
 * «آقای + نام خانوادگی» (مثلاً «آقای جهان تیغ»). اگر ردیف برنامه به پروندهٔ
 * دبیر وصل باشد، همان رکورد مرجع است (فیلد last_name قطعی)؛ وگرنه از نام
 * ذخیره‌شده در خودِ برنامه استفاده می‌شود و teacher_respectful_name جهت نام
 * را خودش تشخیص می‌دهد. این کار خطای «تیغ علیرضا» را برای نام‌های
 * سه‌کلمه‌ای/نام‌خانوادگی‌اول از بین می‌برد.
 */
$teacherNameOf = function ($lesson) {
    $teacher = null;
    if (!empty($lesson['teacher_id'])) {
        try {
            $teacher = DB::fetch("SELECT full_name, first_name, last_name FROM teachers WHERE id = ?", [(int)$lesson['teacher_id']]) ?: null;
        } catch (Throwable $e) {
            $teacher = null;
        }
    }
    /* اگر اورلی ناقص باشد و تابع تازه نبود، همان تابع قدیمی سایت استفاده می‌شود
       تا صفحه هرگز با fatal سفید نشود. */
    $labelOf = function ($value) {
        if (function_exists('teacher_respectful_name')) return trim((string)teacher_respectful_name($value));
        return trim((string)teacher_family_name($value));
    };
    if ($teacher) {
        $label = $labelOf($teacher);
        if ($label !== '') return $label;
    }
    $name = trim((string)($lesson['teacher_name'] ?? ''));
    if ($name === '') return '';
    return $labelOf($name);
};

require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-6">
    <?php if (function_exists('render_student_nav')) render_student_nav('schedule', $student); ?>

    <div class="card shadow-lg">
        <div class="flex flex-wrap justify-between items-center border-b pb-3 mb-4 gap-2">
            <div>
                <h3 class="font-bold text-lg text-primary">📅 برنامه هفتگی من</h3>
                <p class="text-xs text-muted mt-1">
                    کلاس <b><?php echo clean($className !== '' ? $className : 'نامشخص'); ?></b>
                    <?php if ($studentYear !== ''): ?> · سال تحصیلی <b><?php echo clean(tr_num($studentYear, 'fa')); ?></b><?php endif; ?>
                    <?php if ($lessons): ?> · <?php echo tr_num(count($lessons), 'fa'); ?> زنگ در هفته<?php endif; ?>
                </p>
            </div>
            <div class="flex gap-2">
                <a href="student-exam-schedule.php" class="btn btn-outline text-xs">برنامه امتحانی</a>
                <a href="student-panel.php" class="btn btn-secondary text-xs">داشبورد</a>
            </div>
        </div>

        <?php if (!$lessons): ?>
        <div class="text-center text-muted py-10">
            <p>برنامه هفتگی برای کلاس شما ثبت نشده است.</p>
            <p class="text-xs mt-2">اگر کلاس شما مشخص است و این پیام را می‌بینید، با دفتر مدرسه تماس بگیرید.</p>
        </div>
        <?php else: ?>
        <div class="table-container">
            <table id="studentWeeklySchedule">
                <thead>
                    <tr>
                        <th>روز هفته</th>
                        <?php for ($p = 1; $p <= $maxPeriod; $p++): ?>
                        <th>زنگ <?php echo tr_num($p, 'fa'); ?></th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                <?php
                $renderDayRow = function ($dayLabel) use ($week, $dayKey, $maxPeriod, $teacherNameOf) {
                    $dKey = $dayKey($dayLabel);
                    ?>
                    <tr data-day="<?php echo clean($dayLabel); ?>">
                        <td class="font-bold" style="background:rgba(67,56,202,.06)"><?php echo clean($dayLabel); ?></td>
                        <?php for ($p = 1; $p <= $maxPeriod; $p++):
                            $cellLessons = $week[$dKey][$p] ?? [];
                        ?>
                        <td class="text-center" data-period="<?php echo $p; ?>">
                            <?php foreach ($cellLessons as $lesson): $tName = $teacherNameOf($lesson); ?>
                            <div class="mb-1">
                                <span class="font-bold text-xs block"><?php echo clean($lesson['subject_name'] ?? ''); ?></span>
                                <?php if ($tName !== ''): ?><span class="text-[11px] text-muted block"><?php echo clean($tName); ?></span><?php endif; ?>
                            </div>
                            <?php endforeach; if (!$cellLessons): ?>
                            <span class="text-muted text-xs">---</span>
                            <?php endif; ?>
                        </td>
                        <?php endfor; ?>
                    </tr>
                    <?php
                };
                foreach ($weekDays as $dayLabel) { $renderDayRow($dayLabel); }
                foreach ($extraDayLabels as $extraLabel) { $renderDayRow($extraLabel); }
                ?>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-muted mt-2">این جدول فقط-خواندنی است و از برنامهٔ هفتگیِ ثبت‌شده توسط مدرسه خوانده می‌شود.</p>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
