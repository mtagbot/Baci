<?php
// File: student-report-compare.php — v4.213.0
/**
 * «مقایسهٔ نمرات ماهانه»
 *
 * چه کاری می‌کند؟
 *   هر درس، در «همهٔ ماه‌هایی که برای دانش‌آموز کارنامه دارد» کنار هم گذاشته
 *   می‌شود؛ یک ردیف برای هر درس، یک ستون برای هر ماه. بالای جدول، معدل هر ماه
 *   با نمودار میله‌ای و در پایان، یک تحلیل کوتاه از روند کلی ارائه می‌شود.
 *
 * چرا یک صفحهٔ جدا؟
 *   مودال کارنامه‌های دانش‌آموز جای یک جدول کوچک است؛ مقایسهٔ ماه‌به‌ماه هم
 *   ستون‌های زیادی دارد و هم در موبایل باید افقی اسکرول شود. صفحهٔ مستقل،
 *   چاپ و ذخیره‌سازی را هم ممکن می‌کند.
 *
 * دسترسی و قفل:
 *   · دانش‌آموز فقط کارنامهٔ خودش را می‌بیند و کارنامهٔ قفل‌شده (فردی یا
 *     گروهی) در مقایسه نمی‌آید — همان تابع is_report_locked_for_student که
 *     پنل و ربات استفاده می‌کنند.
 *   · مدیر با مجوز «مدیریت کارنامه‌ها» و معاون/اجرایی، کارنامهٔ هر دانش‌آموزی
 *     را می‌بینند (مدیر باید کارنامهٔ قفل‌شده را هم برای بررسی ببیند).
 *   · رنگ وضعیت از همان پله‌های مشترک گزارش‌نامه می‌آید (report_grade_status)
 *     و خودِ نمره‌ها مشکی می‌مانند.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/school_roles.php';
if (function_exists('ensure_school_roles_schema')) ensure_school_roles_schema();

$isStudentView = false;
$studentId     = 0;

if (is_student_logged_in()) {
    $studentId     = (int)$_SESSION['student_id'];
    $isStudentView = true;
} else {
    $staffOk = is_admin_logged_in() && has_permission('manage_reports');
    if (!$staffOk && function_exists('is_teacher_logged_in') && is_teacher_logged_in()) {
        $tid = (int)($_SESSION['teacher_id'] ?? 0);
        $staffOk = (function_exists('teacher_has_deputy') && teacher_has_deputy($tid))
                || (function_exists('teacher_has_executive') && teacher_has_executive($tid));
    }
    if (!$staffOk) {
        set_flash_message('error', 'شما مجوز دسترسی به این بخش را ندارید.');
        redirect('index.php?view=dashboard');
    }
    $studentId = (int)($_GET['student_id'] ?? 0);
}

$student = $studentId > 0 ? DB::fetch("SELECT * FROM students WHERE id = ?", [$studentId]) : null;

if (!function_exists('src_month_rank')) {
    /** رتبهٔ ماه در سال تحصیلی: مهر = ۱ … شهریور = ۱۲ (نوبت‌ها بین آن‌ها). */
    function src_month_rank($month) {
        static $map = null;
        if ($map === null) {
            $map = [
                'مهر' => 1, 'آبان' => 2, 'آذر' => 3, 'دی' => 4, 'بهمن' => 5, 'اسفند' => 6,
                'فروردین' => 7, 'اردیبهشت' => 8, 'خرداد' => 9, 'تیر' => 10, 'مرداد' => 11, 'شهریور' => 12,
                'نوبت اول' => 0, 'نوبت دوم' => 6.5,
            ];
        }
        $m = trim((string)$month);
        return $map[$m] ?? 13;
    }
}

if (!function_exists('src_report_gpa')) {
    /**
     * معدل یک کارنامه از روی نمره‌های همان کارنامه، با وزن (ضریب).
     * «غیبت» (۲۱) مثل بقیهٔ محاسبات مدرسه در معدل نمی‌آید. اگر نمره‌ای نبود،
     * همان معدل ذخیره‌شده برمی‌گردد (اگر بزرگ‌تر از صفر باشد).
     */
    function src_report_gpa(array $report, array $grades) {
        $weighted = 0.0;
        $coefficients = 0.0;
        foreach ($grades as $g) {
            $n = report_grade_numeric($g['score'] ?? null);
            if ($n === null || $n >= 21) continue;
            $coef = (float)($g['coefficient'] ?? 1);
            if ($coef <= 0) $coef = 1;
            $weighted += $n * $coef;
            $coefficients += $coef;
        }
        if ($coefficients > 0) return round($weighted / $coefficients, 2);
        $stored = (float)($report['gpa'] ?? 0);
        return $stored > 0 ? round($stored, 2) : null;
    }
}

if (!function_exists('src_fa_number')) {
    /** عدد اعشاری با ارقام فارسی و حداکثر دو رقم اعشار (بدون صفرهای اضافه). */
    function src_fa_number($value) {
        if ($value === null || $value === '') return '—';
        $text = number_format((float)$value, 2, '.', '');
        $text = rtrim(rtrim($text, '0'), '.');
        return tr_num($text, 'fa');
    }
}

$reports   = $student ? (DB::fetchAll("SELECT * FROM reports WHERE student_id = ? ORDER BY id ASC", [$studentId]) ?: []) : [];

/* ── کارنامه‌ها: قفل‌شده‌ها برای دانش‌آموز حذف، برای مدیر فقط علامت می‌خورند ── */
$lockedCount = 0;
$byMonth     = [];
foreach ($reports as $rep) {
    $reason = '';
    $locked = is_report_locked_for_student($rep, $reason);
    if ($locked && $isStudentView) { $lockedCount++; continue; }
    $year = function_exists('unify_academic_year') ? unify_academic_year((string)($rep['academic_year'] ?? '')) : (string)($rep['academic_year'] ?? '');
    $key  = $year . '|' . trim((string)($rep['report_month'] ?? ''));
    /* اگر برای همان ماه دو کارنامه ثبت شده باشد، جدیدترین می‌ماند. */
    $rep['_locked']       = $locked;
    $rep['_lock_reason']  = $locked ? (trim((string)$reason) !== '' ? trim((string)$reason) : report_lock_generic_reason()) : '';
    $rep['_year_norm']    = $year;
    $rep['_month_rank']   = src_month_rank($rep['report_month'] ?? '');
    $byMonth[$key] = $rep;
}
$months = array_values($byMonth);
usort($months, static function ($a, $b) {
    $ya = (string)$a['_year_norm'];
    $yb = (string)$b['_year_norm'];
    if ($ya !== $yb) return strcmp($ya, $yb);
    if ($a['_month_rank'] != $b['_month_rank']) return $a['_month_rank'] <=> $b['_month_rank'];
    return (int)$a['id'] <=> (int)$b['id'];
});

/* ── نمره‌های همهٔ همین کارنامه‌ها در یک کوئری ── */
$gradesByReport = [];
$reportIds = array_map(static function ($r) { return (int)$r['id']; }, $months);
if ($reportIds) {
    $marks = implode(',', array_fill(0, count($reportIds), '?'));
    $gradeRows = DB::fetchAll("SELECT report_id, subject_name, score, coefficient, status FROM report_grades WHERE report_id IN ($marks) ORDER BY id", $reportIds) ?: [];
    foreach ($gradeRows as $g) {
        $gradesByReport[(int)$g['report_id']][] = $g;
    }
}

/* ── ساختار جدول: درس‌ها × ماه‌ها ── */
$monthColumns = [];
$subjectMap   = [];   // subject => [report_id => ['score'=>..,'coef'=>..]]
$subjectOrder = [];
foreach ($months as $idx => $rep) {
    $rid = (int)$rep['id'];
    $grades = $gradesByReport[$rid] ?? [];
    $gpa = src_report_gpa($rep, $grades);
    $monthColumns[] = [
        'index'   => $idx,
        'id'      => $rid,
        'year'    => (string)$rep['academic_year'],
        'month'   => (string)$rep['report_month'],
        'term'    => (string)$rep['term'],
        'gpa'     => $gpa,
        'locked'  => !empty($rep['_locked']),
        'reason'  => (string)($rep['_lock_reason'] ?? ''),
        'count'   => count($grades),
    ];
    foreach ($grades as $g) {
        $subject = trim((string)$g['subject_name']);
        if ($subject === '') continue;
        if (!isset($subjectMap[$subject])) {
            $subjectMap[$subject] = [];
            $subjectOrder[$subject] = count($subjectOrder);
        }
        $subjectMap[$subject][$rid] = [
            'score' => $g['score'],
            'coef'  => (float)($g['coefficient'] ?? 1),
        ];
    }
}

/* ترتیب درس‌ها: اول درس‌های آخرین ماه (همان ترتیب کارنامه)، بعد بقیه الفبایی. */
$lastRid = $months ? (int)$months[count($months) - 1]['id'] : 0;
$lastOrder = [];
if ($lastRid) {
    foreach ($gradesByReport[$lastRid] ?? [] as $g) {
        $s = trim((string)$g['subject_name']);
        if ($s !== '' && !in_array($s, $lastOrder, true)) $lastOrder[] = $s;
    }
}
$subjects = array_keys($subjectMap);
usort($subjects, static function ($a, $b) use ($lastOrder, $subjectOrder) {
    $ia = array_search($a, $lastOrder, true);
    $ib = array_search($b, $lastOrder, true);
    if ($ia !== false || $ib !== false) {
        if ($ia === false) return 1;
        if ($ib === false) return -1;
        if ($ia !== $ib) return $ia <=> $ib;
    }
    return strcmp($a, $b);
});

/* ── آمار هر درس ── */
$subjectStats = [];
foreach ($subjects as $subject) {
    $values = [];
    $first = null; $last = null; $firstMonth = ''; $lastMonth = '';
    foreach ($monthColumns as $col) {
        $cell = $subjectMap[$subject][$col['id']] ?? null;
        if (!$cell) continue;
        $n = report_grade_numeric($cell['score']);
        if ($n === null || $n >= 21) continue;
        $values[] = $n;
        if ($first === null) { $first = $n; $firstMonth = $col['month']; }
        $last = $n; $lastMonth = $col['month'];
    }
    $subjectStats[$subject] = [
        'count'       => count($values),
        'avg'         => $values ? round(array_sum($values) / count($values), 2) : null,
        'min'         => $values ? min($values) : null,
        'max'         => $values ? max($values) : null,
        'first'       => $first,
        'last'        => $last,
        'first_month' => $firstMonth,
        'last_month'  => $lastMonth,
        'delta'       => ($first !== null && $last !== null && count($values) > 1) ? round($last - $first, 2) : null,
    ];
}

/* ── آمار کلی ── */
$gpaValues = [];
foreach ($monthColumns as $col) if ($col['gpa'] !== null) $gpaValues[] = $col['gpa'];
$avgGpa   = $gpaValues ? round(array_sum($gpaValues) / count($gpaValues), 2) : null;
$bestCol  = null; $worstCol = null;
foreach ($monthColumns as $col) {
    if ($col['gpa'] === null) continue;
    if ($bestCol === null || $col['gpa'] > $bestCol['gpa']) $bestCol = $col;
    if ($worstCol === null || $col['gpa'] < $worstCol['gpa']) $worstCol = $col;
}
$firstGpa = null; $lastGpa = null;
foreach ($monthColumns as $col) { if ($col['gpa'] !== null) { if ($firstGpa === null) $firstGpa = $col['gpa']; $lastGpa = $col['gpa']; } }
$gpaDelta = ($firstGpa !== null && $lastGpa !== null && count($gpaValues) > 1) ? round($lastGpa - $firstGpa, 2) : null;

$below10 = 0; $below12 = 0; $excellent = 0; $absences = 0; $gradeTotal = 0;
foreach ($subjectMap as $subject => $cells) {
    foreach ($cells as $rid => $cell) {
        $n = report_grade_numeric($cell['score']);
        if ($n === null) continue;
        if ($n >= 21) { $absences++; continue; }
        $gradeTotal++;
        if ($n < 10) $below10++;
        if ($n < 12) $below12++;
        if ($n >= 18) $excellent++;
    }
}

$strongest = null; $weakest = null;
foreach ($subjectStats as $subject => $st) {
    if ($st['avg'] === null) continue;
    if ($strongest === null || $st['avg'] > $subjectStats[$strongest]['avg']) $strongest = $subject;
    if ($weakest === null || $st['avg'] < $subjectStats[$weakest]['avg']) $weakest = $subject;
}
$bestDelta = null; $worstDelta = null;
foreach ($subjectStats as $subject => $st) {
    if ($st['delta'] === null) continue;
    if ($bestDelta === null || $st['delta'] > $subjectStats[$bestDelta]['delta']) $bestDelta = $subject;
    if ($worstDelta === null || $st['delta'] < $subjectStats[$worstDelta]['delta']) $worstDelta = $subject;
}

/* ── تحلیل کوتاه (جمله‌های ساخته‌شده از همان اعداد) ── */
$analysis = [];
$overallStatus = $avgGpa !== null ? report_grade_status($avgGpa) : null;
if ($avgGpa !== null) {
    $analysis[] = 'میانگین معدل ' . tr_num(count($gpaValues), 'fa') . ' ماهِ دارای کارنامه، '
        . '<b>' . src_fa_number($avgGpa) . '</b> از ۲۰ است؛ وضعیت کلی: <b>' . clean(report_grade_status_label($avgGpa)) . '</b>.';
} else {
    $analysis[] = 'برای این دانش‌آموز هنوز کارنامه‌ای با نمرهٔ ثبت‌شده وجود ندارد؛ مقایسه پس از ثبت اولین نمره‌ها معنا پیدا می‌کند.';
}
if ($gpaDelta !== null) {
    if ($gpaDelta > 0.5) {
        $analysis[] = 'روند معدل <b>صعودی</b> است: از ' . src_fa_number($firstGpa) . ' در ' . clean($monthColumns[0]['month'])
            . ' به ' . src_fa_number($lastGpa) . ' در آخرین ماه؛ رشد ' . src_fa_number(abs($gpaDelta)) . ' نمره‌ای.';
    } elseif ($gpaDelta < -0.5) {
        $analysis[] = 'روند معدل <b>نزولی</b> است: از ' . src_fa_number($firstGpa) . ' به ' . src_fa_number($lastGpa)
            . '؛ افت ' . src_fa_number(abs($gpaDelta)) . ' نمره‌ای که بررسی علت آن را لازم می‌کند.';
    } else {
        $analysis[] = 'معدل میان ماه‌ها تقریباً <b>ثابت</b> مانده است (' . src_fa_number($firstGpa) . ' → ' . src_fa_number($lastGpa) . ').';
    }
}
if ($bestCol && $worstCol && $bestCol['id'] !== $worstCol['id']) {
    $analysis[] = 'بهترین ماه: <b>' . clean($bestCol['month']) . '</b> با معدل ' . src_fa_number($bestCol['gpa'])
        . ' · ضعیف‌ترین ماه: <b>' . clean($worstCol['month']) . '</b> با معدل ' . src_fa_number($worstCol['gpa']) . '.';
}
if ($strongest !== null && $weakest !== null && $strongest !== $weakest) {
    $analysis[] = 'قوی‌ترین درس: <b>' . clean($strongest) . '</b> (میانگین ' . src_fa_number($subjectStats[$strongest]['avg'])
        . ') · ضعیف‌ترین درس: <b>' . clean($weakest) . '</b> (میانگین ' . src_fa_number($subjectStats[$weakest]['avg']) . ').';
}
if ($bestDelta !== null && $subjectStats[$bestDelta]['delta'] > 0) {
    $analysis[] = 'بیشترین پیشرفت در <b>' . clean($bestDelta) . '</b>: ' . src_fa_number($subjectStats[$bestDelta]['first'])
        . ' → ' . src_fa_number($subjectStats[$bestDelta]['last']) . ' (+' . src_fa_number($subjectStats[$bestDelta]['delta']) . ').';
}
if ($worstDelta !== null && $subjectStats[$worstDelta]['delta'] < 0) {
    $analysis[] = 'بیشترین افت در <b>' . clean($worstDelta) . '</b>: ' . src_fa_number($subjectStats[$worstDelta]['first'])
        . ' → ' . src_fa_number($subjectStats[$worstDelta]['last']) . ' (' . src_fa_number($subjectStats[$worstDelta]['delta']) . ').';
}
if ($gradeTotal > 0) {
    $line = 'از ' . tr_num($gradeTotal, 'fa') . ' نمرهٔ ثبت‌شده، ' . tr_num($excellent, 'fa') . ' نمره ۱۸ یا بالاتر، '
        . tr_num($below12, 'fa') . ' نمره زیر ۱۲ و ' . tr_num($below10, 'fa') . ' نمره زیر ۱۰ است.';
    if ($absences > 0) $line .= ' ' . tr_num($absences, 'fa') . ' غیبت (۲۱) هم ثبت شده که در معدل حساب نمی‌شود.';
    $analysis[] = $line;
}
if ($lockedCount > 0) {
    $analysis[] = tr_num($lockedCount, 'fa') . ' کارنامهٔ مسدود در این مقایسه نیامده است؛ پس از آزادسازی، ماه آن‌ها هم اضافه می‌شود.';
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-4 src-wrap">
    <div class="flex flex-wrap justify-between items-center gap-2">
        <div>
            <h2 class="text-xl font-bold">مقایسه نمرات ماهانه</h2>
            <?php if ($student): ?>
                <p class="text-xs text-muted" style="margin-top:4px">
                    <?php echo clean(trim((string)$student['first_name'] . ' ' . (string)$student['last_name'])); ?>
                    — <?php echo clean(trim((string)($student['grade_level'] ?? '')) ?: '—'); ?> /
                    <?php echo clean(trim((string)($student['class_name'] ?? '')) ?: '—'); ?>
                </p>
            <?php endif; ?>
        </div>
        <div class="flex flex-wrap gap-2">
            <?php if (!$isStudentView): ?>
                <a class="btn btn-secondary text-xs" href="reports.php?student_id=<?php echo (int)$studentId; ?>">کارنامه‌های این دانش‌آموز</a>
            <?php endif; ?>
            <?php if ($isStudentView): ?>
                <a class="btn btn-secondary text-xs" href="student-reports.php">کارنامه‌های من</a>
            <?php endif; ?>
            <button type="button" class="btn btn-primary text-xs" onclick="window.print()">چاپ مقایسه</button>
        </div>
    </div>

    <?php if (!$student): ?>
        <div class="card text-center text-muted">دانش‌آموز یافت نشد.</div>
    <?php elseif (!$monthColumns): ?>
        <div class="card text-center text-muted" style="line-height:2">
            برای این دانش‌آموز هنوز کارنامه‌ای ثبت نشده است؛ پس از ثبت اولین کارنامه، مقایسهٔ ماه‌به‌ماه همین‌جا ساخته می‌شود.
        </div>
    <?php else: ?>
        <div class="card">
            <h3 class="font-bold text-sm" style="margin-bottom:8px">معدل هر ماه</h3>
            <div class="src-bars">
                <?php foreach ($monthColumns as $col):
                    $gpa = $col['gpa'];
                    $status = $gpa !== null ? report_grade_status($gpa) : null;
                    $color = $status ? $status['dot'] : '#cbd5e1';
                    $pct = $gpa !== null ? max(2, min(100, ($gpa / 20) * 100)) : 0; ?>
                    <div class="src-bar-col" title="<?php echo clean($col['month'] . ' — ' . ($gpa !== null ? src_fa_number($gpa) : 'بدون معدل') . ($status ? ' — ' . $status['label'] : '')); ?>">
                        <span class="src-bar-value" style="color:#111;font-weight:800"><?php echo $gpa !== null ? src_fa_number($gpa) : '—'; ?></span>
                        <span class="src-bar-track"><span class="src-bar-fill" style="height:<?php echo $pct; ?>%;background:<?php echo $color; ?>"></span></span>
                        <span class="src-bar-label"><?php echo clean($col['month']); ?><?php if ($col['term'] !== ''): ?><small><?php echo clean($col['term']); ?></small><?php endif; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="src-legend">
                <?php foreach (report_grade_status_bands() as $band): ?>
                    <span class="src-legend-item"><i style="background:<?php echo $band['dot']; ?>"></i><?php echo clean($band['label']); ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card table-container src-table-card">
            <div class="flex items-center justify-between" style="margin-bottom:8px">
                <h3 class="font-bold text-sm">همهٔ درس‌ها در همهٔ ماه‌ها</h3>
                <span class="text-xs text-muted">رنگِ هر خانه = وضعیت همان نمره · نمره‌ها مشکی</span>
            </div>
            <div class="src-scroll">
                <table class="src-table">
                    <thead>
                        <tr>
                            <th class="src-sticky">درس</th>
                            <?php foreach ($monthColumns as $col): ?>
                                <th>
                                    <span class="src-th-month"><?php echo clean($col['month']); ?></span>
                                    <span class="src-th-year"><?php echo clean(tr_num($col['year'], 'fa')); ?></span>
                                </th>
                            <?php endforeach; ?>
                            <th class="src-avg-col">میانگین</th>
                            <th class="src-avg-col">روند</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subjects as $subject):
                            $stats = $subjectStats[$subject]; ?>
                            <tr>
                                <td class="src-sticky src-subject"><?php echo clean($subject); ?></td>
                                <?php foreach ($monthColumns as $col):
                                    $cell = $subjectMap[$subject][$col['id']] ?? null; ?>
                                    <td class="src-cell">
                                        <?php if (!$cell): ?>
                                            <span class="src-empty">—</span>
                                        <?php else:
                                            $band = report_grade_status($cell['score']);
                                            $isAbsence = report_grade_is_absence($cell['score']);
                                            $dot = $isAbsence ? '#f59e0b' : ($band ? $band['dot'] : '#cbd5e1');
                                            $bg  = $isAbsence ? '#fffbeb' : ($band ? $band['bg'] : '#f8fafc'); ?>
                                            <span class="src-score-chip" style="background:<?php echo $bg; ?>;border-color:<?php echo $isAbsence ? '#fde68a' : ($band ? $band['border'] : '#e2e8f0'); ?>"
                                                  title="<?php echo clean(report_grade_status_label($cell['score'])); ?>">
                                                <i style="background:<?php echo $dot; ?>"></i>
                                                <b style="<?php echo report_grade_score_style(); ?>"><?php echo display_score($cell['score']); ?></b>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="src-avg-col"><b><?php echo src_fa_number($stats['avg']); ?></b></td>
                                <td class="src-avg-col">
                                    <?php if ($stats['delta'] === null): ?>
                                        <span class="src-empty">—</span>
                                    <?php elseif ($stats['delta'] > 0): ?>
                                        <span class="src-trend src-trend-up" title="پیشرفت نسبت به اولین ماه">▲ <?php echo src_fa_number($stats['delta']); ?></span>
                                    <?php elseif ($stats['delta'] < 0): ?>
                                        <span class="src-trend src-trend-down" title="افت نسبت به اولین ماه">▼ <?php echo src_fa_number(abs($stats['delta'])); ?></span>
                                    <?php else: ?>
                                        <span class="src-trend src-trend-flat">ثابت</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th class="src-sticky">معدل ماه</th>
                            <?php foreach ($monthColumns as $col): ?>
                                <td><b style="<?php echo report_grade_score_style(); ?>"><?php echo src_fa_number($col['gpa']); ?></b></td>
                            <?php endforeach; ?>
                            <td class="src-avg-col"><b><?php echo src_fa_number($avgGpa); ?></b></td>
                            <td class="src-avg-col">
                                <?php if ($gpaDelta === null): ?>
                                    <span class="src-empty">—</span>
                                <?php elseif ($gpaDelta > 0): ?>
                                    <span class="src-trend src-trend-up">▲ <?php echo src_fa_number($gpaDelta); ?></span>
                                <?php elseif ($gpaDelta < 0): ?>
                                    <span class="src-trend src-trend-down">▼ <?php echo src_fa_number(abs($gpaDelta)); ?></span>
                                <?php else: ?>
                                    <span class="src-trend src-trend-flat">ثابت</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="card src-analysis">
            <div class="flex flex-wrap items-center justify-between gap-2" style="margin-bottom:10px">
                <h3 class="font-bold text-sm">تحلیل عملکرد کلی</h3>
                <?php if ($overallStatus): ?>
                    <?php echo report_grade_status_badge($avgGpa); ?>
                <?php endif; ?>
            </div>
            <ul class="src-analysis-list">
                <?php foreach ($analysis as $line): ?>
                    <li><?php echo $line; ?></li>
                <?php endforeach; ?>
            </ul>
            <div class="src-facts">
                <div><span>تعداد کارنامه‌ها</span><b><?php echo tr_num(count($monthColumns), 'fa'); ?></b></div>
                <div><span>تعداد درس‌ها</span><b><?php echo tr_num(count($subjects), 'fa'); ?></b></div>
                <div><span>میانگین معدل ماه‌ها</span><b><?php echo src_fa_number($avgGpa); ?></b></div>
                <div><span>بهترین ماه</span><b><?php echo $bestCol ? clean($bestCol['month']) : '—'; ?></b></div>
                <div><span>ضعیف‌ترین ماه</span><b><?php echo $worstCol ? clean($worstCol['month']) : '—'; ?></b></div>
                <div><span>نمرهٔ زیر ۱۰</span><b><?php echo tr_num($below10, 'fa'); ?></b></div>
            </div>
        </div>
    <?php endif; ?>
</div>
<style>
.src-wrap{--src-border:#e2e8f0}
.src-wrap .src-bars{display:flex;gap:10px;align-items:flex-end;overflow-x:auto;padding:6px 2px 2px}
.src-wrap .src-bar-col{display:flex;flex-direction:column;align-items:center;gap:6px;min-width:62px;flex:0 0 auto}
.src-wrap .src-bar-value{font-size:12px}
.src-wrap .src-bar-track{position:relative;display:flex;align-items:flex-end;justify-content:center;width:26px;height:130px;border-radius:8px;background:#f1f5f9;border:1px solid #e2e8f0;overflow:hidden}
.src-wrap .src-bar-fill{position:absolute;bottom:0;width:100%;border-radius:8px 8px 0 0;display:block}
.src-wrap .src-bar-label{font-size:11px;font-weight:800;color:#334155;text-align:center;display:flex;flex-direction:column;gap:2px}
.src-wrap .src-bar-label small{font-weight:600;color:#64748b;font-size:10px}
.src-wrap .src-legend{display:flex;flex-wrap:wrap;gap:8px 14px;margin-top:10px;padding-top:10px;border-top:1px dashed #e2e8f0}
.src-wrap .src-legend-item{display:inline-flex;align-items:center;gap:5px;font-size:11px;color:#475569;font-weight:700}
.src-wrap .src-legend-item i{width:8px;height:8px;border-radius:50%;display:inline-block}
.src-wrap .src-table-card{overflow:hidden}
.src-wrap .src-scroll{overflow:auto;max-width:100%}
.src-wrap .src-table{width:100%;border-collapse:separate;border-spacing:0;font-size:12px}
.src-wrap .src-table th,.src-wrap .src-table td{border-bottom:1px solid var(--src-border);padding:7px 9px;text-align:center;white-space:nowrap}
.src-wrap .src-table thead th{background:#f8fafc;color:#475569;font-size:11px;font-weight:800;position:sticky;top:0;z-index:2}
.src-wrap .src-table .src-th-month{display:block}
.src-wrap .src-table .src-th-year{display:block;font-weight:600;color:#94a3b8;font-size:10px}
.src-wrap .src-table .src-sticky{position:sticky;right:0;background:#fff;text-align:right;z-index:1;box-shadow:-1px 0 0 var(--src-border)}
.src-wrap .src-table thead .src-sticky{background:#f8fafc;z-index:3}
.src-wrap .src-subject{font-weight:800;color:#0f172a;min-width:120px}
.src-wrap .src-cell{min-width:64px}
.src-wrap .src-score-chip{display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:999px;border:1px solid #e2e8f0;font-size:12px}
.src-wrap .src-score-chip i{width:7px;height:7px;border-radius:50%;flex:none}
.src-wrap .src-empty{color:#94a3b8}
.src-wrap .src-avg-col{background:#fbfdff;font-weight:700}
.src-wrap .src-trend{font-size:11px;font-weight:800;padding:2px 7px;border-radius:999px}
.src-wrap .src-trend-up{color:#166534;background:#f0fdf4;border:1px solid #bbf7d0}
.src-wrap .src-trend-down{color:#991b1b;background:#fef2f2;border:1px solid #fecaca}
.src-wrap .src-trend-flat{color:#475569;background:#f8fafc;border:1px solid #e2e8f0}
.src-wrap .src-table tfoot th,.src-wrap .src-table tfoot td{background:#f8fafc;font-weight:800;border-top:2px solid #e2e8f0}
.src-wrap .src-analysis-list{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:8px}
.src-wrap .src-analysis-list li{position:relative;padding:8px 12px;border-radius:10px;background:#f8fafc;border:1px solid #eef2f7;font-size:12.5px;line-height:2;color:#1e293b}
.src-wrap .src-facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;margin-top:12px}
.src-wrap .src-facts>div{display:flex;flex-direction:column;gap:2px;border:1px solid #eef2f7;border-radius:10px;padding:7px 10px;background:#fff}
.src-wrap .src-facts span{font-size:10.5px;color:#64748b;font-weight:700}
.src-wrap .src-facts b{font-size:13px;color:#0f172a}
@media print{
    .src-wrap .src-scroll{overflow:visible}
    .src-wrap .src-table .src-sticky{position:static;box-shadow:none}
    .src-wrap .btn{display:none!important}
    .src-wrap .src-table th,.src-wrap .src-table td{font-size:9px;padding:4px 5px}
}
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
