<?php
// File: grade-columns.php  (v4.181.0)
/**
 * «مدیریت ستونی» — کنترل نمرات یک کلاس در یک ماه.
 *
 * هر دانش‌آموز کلاس یک ردیف، هر درسِ آن کلاس (طبق برنامهٔ هفتگی) یک ستون.
 * سرستون: نام درس و زیر آن فقط نام خانوادگی دبیر. سلول‌ها ویرایش‌شونده‌اند و
 * هر تغییر بلافاصله در همان کارنامهٔ ماه ذخیره می‌شود (ساخت کارنامه در صورت
 * نبودن). دانش‌آموزی که در درسی نمره نگرفته «-» می‌گیرد. ستون آخر معدل است.
 *
 * ترتیب پیش‌فرض ردیف‌ها الفبایی (نام خانوادگی) است؛ کلیک روی سرستون هر درس
 * جدول را بر پایهٔ همان درس صعودی/نزولی می‌کند (نمرهٔ نداشته همیشه آخر).
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('manage_reports');
require_once __DIR__ . '/includes/school_sort.php';
require_once __DIR__ . '/includes/academic_year_helpers.php';

$GC_MONTHS = ['مهر','آبان','آذر','دی','بهمن','اسفند','فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','نوبت اول','نوبت دوم'];

if (!function_exists('gc_month_now')) {
    /** نام ماه جاری شمسی برای پیش‌فرض فیلتر (بدون وابستگی به jdate اگر نبود). */
    function gc_month_now() {
        $idx = (int)(function_exists('jdate') ? jdate('n') : 0);
        $names = [1=>'فروردین',2=>'اردیبهشت',3=>'خرداد',4=>'تیر',5=>'مرداد',6=>'شهریور',7=>'مهر',8=>'آبان',9=>'آذر',10=>'دی',11=>'بهمن',12=>'اسفند'];
        return $names[$idx] ?? 'مهر';
    }
}
if (!function_exists('gc_term_for_month')) {
    /** نوبت پیش‌فرض برای ماهی که کارنامه‌اش ساخته می‌شود. */
    function gc_term_for_month($month) {
        $second = ['بهمن','اسفند','فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','نوبت دوم'];
        return in_array((string)$month, $second, true) ? 'نوبت دوم' : 'نوبت اول';
    }
}
if (!function_exists('gc_fa_to_latin')) {
    /** رقم‌های فارسی/عربی، جداکنندهٔ اعشار فارسی و ممیز را یکدست می‌کند. */
    function gc_fa_to_latin($value) {
        $value = strtr((string)$value, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
                                       '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        return trim(str_replace(['٫', '/', '،'], ['.', '.', ''], $value));
    }
}
if (!function_exists('gc_parse_score')) {
    /** '' ⇒ null (نمره‌ای ثبت نیست) · عدد نامعتبر ⇒ false · در غیر این صورت float */
    function gc_parse_score($raw) {
        $raw = gc_fa_to_latin($raw);
        if ($raw === '' || $raw === '-' || $raw === '—') return null;
        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $raw)) return false;
        $n = (float)$raw;
        if ($n < 0 || $n > 21) return false;
        return round($n, 2);
    }
}
if (!function_exists('gc_day_weight')) {
    function gc_day_weight($day) {
        $d = str_replace([' ', "\u{200C}", '-'], '', (string)$day);
        $map = ['شنبه'=>1,'یکشنبه'=>2,'دوشنبه'=>3,'سهشنبه'=>4,'چهارشنبه'=>5,'پنجشنبه'=>6,'جمعه'=>7];
        return $map[$d] ?? 8;
    }
}
if (!function_exists('gc_period_weight')) {
    function gc_period_weight($period) {
        if (preg_match('/\d+/', gc_fa_to_latin($period), $m)) return (int)$m[0];
        if (mb_strpos((string)$period, 'اول') !== false) return 1;
        if (mb_strpos((string)$period, 'دوم') !== false) return 2;
        if (mb_strpos((string)$period, 'سوم') !== false) return 3;
        if (mb_strpos((string)$period, 'چهارم') !== false) return 4;
        return 9;
    }
}
if (!function_exists('gc_teacher_label')) {
    /**
     * برچسب دبیر برای زیرنویس سرستون: **فقط نام خانوادگی**.
     * teacher_family_name() عمداً پیشوند احترام را نگه می‌دارد («آقای محمدی») چون
     * جای دیگر برای پیام‌های محترمانه لازم است؛ اینجا کارفرما فقط نام خانوادگی
     * را خواسته، پس پیشوندها برداشته می‌شود و اگر چند توکن ماند، نام خانوادگی
     * در فارسی آخرین توکن است.
     */
    function gc_teacher_label($name) {
        $fam = function_exists('teacher_family_name') ? teacher_family_name($name) : trim((string)$name);
        $fam = trim(preg_replace('/\s+/u', ' ', (string)$fam));
        if ($fam === '') return '';
        $prefixes = ['استاد', 'آقای', 'آقا', 'خانم', 'بانو', 'جناب', 'دکتر', 'مهندس', 'حاج', 'حاجی', 'سید', 'سیده', 'سرکار'];
        $tokens = preg_split('/\s+/u', $fam);
        while (count($tokens) > 1 && in_array($tokens[0], $prefixes, true)) array_shift($tokens);
        if ($tokens && in_array($tokens[0], $prefixes, true)) array_shift($tokens);
        if (count($tokens) > 1) $tokens = [$tokens[count($tokens) - 1]];
        return trim(implode(' ', $tokens));
    }
}
if (!function_exists('gc_class_match_sql')) {
    /**
     * شرط SQL برای تطبیق نام کلاس، بی‌اعتنا به فاصله/خط تیره/اسلش/زیرخط.
     * چرا؟ norm_class_str('۸/۲') می‌شود '۸۲' و اگر مستقیم با class_name مقایسه
     * شود، کلاسی که با اسلش ذخیره شده هیچ‌وقت پیدا نمی‌شود (جدول خالی می‌ماند).
     */
    function gc_class_match_sql($column) {
        return "REPLACE(REPLACE(REPLACE(REPLACE($column,' ',''),'-',''),'/',''),'_','') = ?";
    }
}
if (!function_exists('gc_subjects_of_class')) {
    /**
     * درس‌های کلاس با نام خانوادگی دبیر، به ترتیب برنامهٔ هفتگی
     * (اولین زنگ هفته تعیین‌کنندهٔ ترتیب ستون است).
     */
    function gc_subjects_of_class($year, $className) {
        $rows = DB::fetchAll(
            "SELECT cs.subject_name, cs.teacher_id, cs.teacher_name, cs.day_of_week, cs.period_num,
                    COALESCE(t.full_name, cs.teacher_name, '') AS full_name
             FROM class_schedules cs LEFT JOIN teachers t ON t.id = cs.teacher_id
             WHERE cs.academic_year = ? AND " . gc_class_match_sql('cs.class_name') . " AND cs.subject_name <> ''",
            [$year, norm_class_str($className)]
        );
        $map = [];
        foreach ($rows as $r) {
            $subject = trim((string)$r['subject_name']);
            if ($subject === '') continue;
            $weight = gc_day_weight($r['day_of_week']) * 100 + gc_period_weight($r['period_num']);
            if (!isset($map[$subject]) || $weight < $map[$subject]['weight']) {
                $map[$subject] = ['name' => $subject, 'weight' => $weight, 'teacher' => trim((string)$r['full_name'])];
            }
        }
        $out = array_values($map);
        usort($out, function ($a, $b) {
            if ($a['weight'] !== $b['weight']) return $a['weight'] <=> $b['weight'];
            return strcmp($a['name'], $b['name']);
        });
        foreach ($out as &$o) {
            $o['teacher_label'] = $o['teacher'] === '' ? '' : gc_teacher_label($o['teacher']);
        }
        unset($o);
        return $out;
    }
}
if (!function_exists('gc_students_of_class')) {
    function gc_students_of_class($year, $className) {
        $rows = DB::fetchAll(
            "SELECT id, first_name, last_name, class_name, grade_level FROM students
             WHERE status = 'active' AND " . gc_class_match_sql('class_name') . "
               AND (academic_year = ? OR academic_year IS NULL OR academic_year = '')",
            [norm_class_str($className), $year]
        );
        if (function_exists('persian_usort_students')) persian_usort_students($rows);
        return $rows;
    }
}
if (!function_exists('gc_grade_map')) {
    /**
     * نمره‌های ماه انتخابی: [student_id => ['report_id'=>…, 'gpa'=>…, 'locked'=>…, 'scores'=>[درس=>نمره]]]
     * اگر برای دانش‌آموزی چند کارنامه در آن ماه باشد، جدیدترین مبنا است.
     */
    function gc_grade_map($year, $month, array $studentIds) {
        $out = [];
        if (!$studentIds) return $out;
        $marks = implode(',', array_fill(0, count($studentIds), '?'));
        $reports = DB::fetchAll(
            "SELECT * FROM reports WHERE academic_year = ? AND report_month = ? AND student_id IN ($marks) ORDER BY id ASC",
            array_merge([$year, $month], $studentIds)
        );
        foreach ($reports as $r) {
            $sid = (int)$r['student_id'];
            $out[$sid] = ['report_id' => (int)$r['id'], 'gpa' => $r['gpa'], 'locked' => (int)$r['is_locked'], 'scores' => []];
        }
        $ids = array_column($out, 'report_id');
        if ($ids) {
            $m2 = implode(',', array_fill(0, count($ids), '?'));
            foreach (DB::fetchAll("SELECT report_id, subject_name, score, coefficient FROM report_grades WHERE report_id IN ($m2)", $ids) as $g) {
                foreach ($out as $sid => $info) {
                    if ((int)$info['report_id'] === (int)$g['report_id']) {
                        $out[$sid]['scores'][(string)$g['subject_name']] = $g['score'];
                        break;
                    }
                }
            }
        }
        /* معدل هر ردیف از نمره‌های همین جدول حساب می‌شود، نه از مقدار ذخیره‌شدهٔ
           کارنامه؛ چون ممکن است نمره‌ها جای دیگری عوض شده باشد و کاربر انتظار
           دارد ستون معدل با همان چیزی که می‌بیند بخواند. (۲۱ = غیبت، بی‌اثر) */
        foreach ($out as $sid => $info) {
            $weighted = 0.0; $coeff = 0.0;
            foreach ($info['scores'] as $value) {
                $score = (float)$value;
                if ($score == 21.0) continue;
                $c = 1.0;
                $weighted += $score * $c; $coeff += $c;
            }
            $out[$sid]['gpa_calc'] = $coeff > 0 ? round($weighted / $coeff, 2) : 0.0;
            $out[$sid]['count'] = count($info['scores']);
        }
        return $out;
    }
}
if (!function_exists('gc_recalc_report')) {
    /** جمع وزنی و معدل یک کارنامه را از نمره‌های موجودش دوباره می‌سازد (منطق reports.php؛ ۲۱ = غیبت و بی‌اثر). */
    function gc_recalc_report($reportId) {
        $rows = DB::fetchAll("SELECT score, coefficient FROM report_grades WHERE report_id = ?", [$reportId]);
        $weighted = 0.0; $coeff = 0.0;
        foreach ($rows as $r) {
            $score = (float)$r['score'];
            $c = (float)$r['coefficient'];
            if ($c <= 0) $c = 1.0;
            if ($score != 21.0) { $weighted += $score * $c; $coeff += $c; }
        }
        $gpa = $coeff > 0 ? round($weighted / $coeff, 2) : 0.0;
        DB::execute("UPDATE reports SET total_score = ?, gpa = ? WHERE id = ?", [$weighted, $gpa, $reportId]);
        return ['gpa' => $gpa, 'total' => $weighted, 'count' => count($rows)];
    }
}
if (!function_exists('gc_save_cell')) {
    /**
     * ذخیرهٔ یک سلول: کارنامهٔ ماه را در صورت نبودن می‌سازد، نمرهٔ آن درس را
     * می‌نویسد (یا حذف می‌کند) و معدل را بازمحاسبه می‌کند.
     */
    function gc_save_cell($year, $month, $className, $studentId, $subject, $rawScore) {
        $score = gc_parse_score($rawScore);
        if ($score === false) return ['ok' => false, 'message' => 'نمرهٔ «' . $rawScore . '» معتبر نیست؛ عددی بین ۰ تا ۲۱ بنویسید.'];
        $subject = trim((string)$subject);
        if ($subject === '') return ['ok' => false, 'message' => 'نام درس مشخص نیست.'];

        $student = DB::fetch("SELECT id, class_name FROM students WHERE id = ?", [$studentId]);
        if (!$student) return ['ok' => false, 'message' => 'دانش‌آموز یافت نشد.'];

        $report = DB::fetch("SELECT * FROM reports WHERE student_id = ? AND academic_year = ? AND report_month = ? ORDER BY id DESC LIMIT 1",
            [$studentId, $year, $month]);
        if (!$report) {
            DB::execute("INSERT INTO reports (student_id, class_name, academic_year, term, report_month, total_score, gpa) VALUES (?,?,?,?,?,0,0)",
                [$studentId, (string)$student['class_name'], $year, gc_term_for_month($month), $month]);
            $report = DB::fetch("SELECT * FROM reports WHERE id = ?", [DB::lastInsertId()]);
        }
        if (!$report) return ['ok' => false, 'message' => 'ساخت کارنامهٔ ماه انجام نشد.'];

        $existing = DB::fetch("SELECT * FROM report_grades WHERE report_id = ? AND subject_name = ?", [(int)$report['id'], $subject]);
        if ($score === null) {
            if ($existing) DB::execute("DELETE FROM report_grades WHERE id = ?", [(int)$existing['id']]);
        } else {
            $status = $score == 21.0 ? 'none' : ($score >= 10 ? 'passed' : 'failed');
            if ($existing) {
                DB::execute("UPDATE report_grades SET score = ?, status = ? WHERE id = ?", [$score, $status, (int)$existing['id']]);
            } else {
                DB::execute("INSERT INTO report_grades (report_id, subject_name, score, max_score, coefficient, status) VALUES (?,?,?,20,1,?)",
                    [(int)$report['id'], $subject, $score, $status]);
            }
        }
        $calc = gc_recalc_report((int)$report['id']);
        log_activity($_SESSION['admin_id'] ?? null, 'نمرهٔ مدیریت ستونی',
            "کارنامه {$report['id']} — {$subject}: " . ($score === null ? 'حذف' : $score) . " (معدل {$calc['gpa']})");
        return [
            'ok' => true,
            'value' => $score === null ? '' : rtrim(rtrim(number_format($score, 2, '.', ''), '0'), '.'),
            'gpa' => $calc['gpa'],
            /* اگر تنها نمره‌های موجود «غیبت» باشند، معدل معنا ندارد و خالی می‌ماند
               (هم‌خوان با ستون معدل جدول که «-» نشان می‌دهد). */
            'gpa_display' => ($calc['count'] > 0 && $calc['gpa'] > 0) ? tr_num(number_format($calc['gpa'], 2, '.', ''), 'fa') : '',
            'term' => (string)$report['term'],
            'report_id' => (int)$report['id'],
            'message' => $score === null ? 'نمرهٔ درس پاک شد.' : 'ذخیره شد.',
        ];
    }
}

$embedded = (($_GET['embedded'] ?? '') === '1');
$year    = resolve_academic_year_request($_GET['year'] ?? get_current_academic_year());
$years   = get_academic_years_for_filter();
$classes = get_unified_class_options($year);
$classRaw  = trim((string)($_GET['class'] ?? ''));
$className = norm_class_str($classRaw);
if ($className === '' && $classes) { $classRaw = trim((string)$classes[0]['class_name']); $className = norm_class_str($classRaw); }
if ($classRaw === '') $classRaw = $className;
$month = trim((string)($_GET['month'] ?? ''));
if ($month === '') $month = gc_month_now();
if (!in_array($month, $GC_MONTHS, true)) $GC_MONTHS[] = $month;

/* ───────── ذخیره‌سازی سلول (AJAX، پاسخ JSON) ───────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['gc_action'] ?? '') === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    $answer = null;
    try {
        if (!is_string($_POST['csrf_token'] ?? null) || !verify_csrf($_POST['csrf_token'])) {
            $answer = ['ok' => false, 'message' => 'اعتبار فرم منقضی شده است؛ صفحه را تازه کنید.'];
        } else {
            $postYear  = unify_academic_year(trim((string)($_POST['year'] ?? $year)));
            $postMonth = trim((string)($_POST['month'] ?? $month));
            $answer = gc_save_cell($postYear, $postMonth, norm_class_str($_POST['class_name'] ?? $className),
                (int)($_POST['student_id'] ?? 0), (string)($_POST['subject'] ?? ''), (string)($_POST['score'] ?? ''));
        }
    } catch (Throwable $e) {
        error_log('Grade column save failed: ' . $e->getMessage());
        $answer = ['ok' => false, 'message' => 'ذخیره انجام نشد؛ اتصال پایگاه‌داده را بررسی کنید.'];
    }
    echo json_encode($answer, JSON_UNESCAPED_UNICODE);
    exit;
}

$subjects = $className !== '' ? gc_subjects_of_class($year, $className) : [];
$students = $className !== '' ? gc_students_of_class($year, $className) : [];
$grades   = gc_grade_map($year, $month, array_map('intval', array_column($students, 'id')));

require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-4 gc-wrap">
    <h2 class="font-bold text-lg">مدیریت ستونی نمرات</h2>
    <p class="text-xs text-muted" style="line-height:2">
        نمرات یک کلاس در یک ماه، ستون‌به‌ستون. هر سلول ویرایش‌شونده است و با هر تغییر، همان نمره
        در کارنامهٔ آن ماه ذخیره و معدل بازمحاسبه می‌شود. برای دانش‌آموزی که در درسی نمره ندارد «-»
        می‌ماند. با کلیک روی سرستون هر درس، جدول بر پایهٔ همان درس مرتب می‌شود؛ ترتیب پیش‌فرض الفبایی است.
        با کلید Tab و کلیدهای جهت‌دار (↑ ↓ ← →) بین سلول‌ها جابه‌جا شوید.
    </p>

    <form class="card gc-filters" method="GET" action="grade-columns.php">
        <?php if ($embedded): ?><input type="hidden" name="embedded" value="1"><?php endif; ?>
        <div>
            <label class="text-xs" for="gcYear">سال تحصیلی</label>
            <select id="gcYear" name="year" class="form-select" onchange="this.form.submit()">
                <?php if (!$years): ?><option value="<?php echo clean($year); ?>"><?php echo clean($year); ?></option><?php endif; ?>
                <?php foreach ($years as $y): $yv = is_array($y) ? (string)($y['academic_year'] ?? '') : (string)$y; ?>
                    <option value="<?php echo clean($yv); ?>" <?php echo $yv === $year ? 'selected' : ''; ?>><?php echo clean(tr_num($yv, 'fa')); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="text-xs" for="gcMonth">ماه</label>
            <select id="gcMonth" name="month" class="form-select" onchange="this.form.submit()">
                <?php foreach ($GC_MONTHS as $m): ?>
                    <option value="<?php echo clean($m); ?>" <?php echo $m === $month ? 'selected' : ''; ?>><?php echo clean($m); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="text-xs" for="gcClass">کلاس</label>
            <select id="gcClass" name="class" class="form-select" onchange="this.form.submit()">
                <?php if (!$classes): ?><option value="">کلاسی ثبت نشده</option><?php endif; ?>
                <?php foreach ($classes as $c): ?>
                    <option value="<?php echo clean($c['class_name']); ?>" <?php echo norm_class_str($c['class_name']) === $className ? 'selected' : ''; ?>>
                        <?php echo clean($c['class_name']); ?><?php echo $c['grade_level'] !== '' ? ' — ' . clean($c['grade_level']) : ''; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary text-xs">نمایش</button>
        <span id="gcStatus" class="gc-status" role="status" aria-live="polite"></span>
    </form>

    <?php if ($className === ''): ?>
        <div class="card text-center text-muted">کلاسی برای این سال تحصیلی ثبت نشده است.</div>
    <?php elseif (!$subjects): ?>
        <div class="card text-center" style="line-height:2">
            برای کلاس <b><?php echo clean($classRaw); ?></b> در سال <b><?php echo clean(tr_num($year, 'fa')); ?></b>
            درسی در برنامهٔ هفتگی ثبت نشده است؛ ابتدا از «کلاس‌ها و دروس ← برنامه هفتگی» درس‌ها را ثبت کنید.
        </div>
    <?php elseif (!$students): ?>
        <div class="card text-center text-muted">برای کلاس <?php echo clean($classRaw); ?> دانش‌آموز فعالی ثبت نشده است.</div>
    <?php else: ?>
        <div class="card">
            <div class="gc-legend text-xs text-muted">
                <span><b><?php echo clean($classRaw); ?></b> — <?php echo clean($month); ?> <?php echo clean(tr_num($year, 'fa')); ?></span>
                <span><?php echo clean(tr_num(count($students), 'fa')); ?> دانش‌آموز · <?php echo clean(tr_num(count($subjects), 'fa')); ?> درس</span>
                <span class="gc-legend-key"><i class="gc-dot gc-dot-fail"></i> زیر ۱۰</span>
                <span class="gc-legend-key"><i class="gc-dot gc-dot-abs"></i> ۲۱ = غیبت (بی‌اثر در معدل)</span>
            </div>
            <div class="gc-scroll">
                <table class="gc-table" id="gcTable"
                       data-year="<?php echo clean($year); ?>"
                       data-month="<?php echo clean($month); ?>"
                       data-class="<?php echo clean($classRaw); ?>">
                    <thead>
                        <tr>
                            <th class="gc-row-num">ردیف</th>
                            <th class="gc-sort gc-name" data-key="name" title="مرتب‌سازی الفبایی">نام دانش‌آموز <span class="gc-arrow">↕</span></th>
                            <?php foreach ($subjects as $i => $s): ?>
                                <th class="gc-sort" data-key="<?php echo clean($s['name']); ?>" title="مرتب‌سازی بر پایهٔ <?php echo clean($s['name']); ?>">
                                    <span class="gc-subject"><?php echo clean($s['name']); ?></span>
                                    <?php if ($s['teacher_label'] !== ''): ?><span class="gc-teacher"><?php echo clean($s['teacher_label']); ?></span><?php endif; ?>
                                    <span class="gc-arrow">↕</span>
                                </th>
                            <?php endforeach; ?>
                            <th class="gc-sort gc-avg-head" data-key="avg" title="مرتب‌سازی بر پایهٔ معدل">معدل <span class="gc-arrow">↕</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($students as $idx => $st):
                        $sid = (int)$st['id'];
                        $info = $grades[$sid] ?? null;
                        $fullName = trim((string)$st['first_name'] . ' ' . (string)$st['last_name']);
                        $gpaValue   = ($info && (float)($info['gpa_calc'] ?? 0) > 0) ? (float)$info['gpa_calc'] : 0.0;
                        $gpaDisplay = $gpaValue > 0 ? tr_num(number_format($gpaValue, 2, '.', ''), 'fa') : '';
                    ?>
                        <tr data-sid="<?php echo $sid; ?>"
                            data-name="<?php echo clean(trim((string)$st['last_name'] . ' ' . (string)$st['first_name'])); ?>"
                            data-avg="<?php echo $gpaValue > 0 ? $gpaValue : ''; ?>">
                            <td class="gc-row-num"><?php echo clean(tr_num($idx + 1, 'fa')); ?></td>
                            <td class="gc-name">
                                <span class="gc-student"><?php echo clean($fullName); ?></span>
                                <?php if ($info && $info['locked']): ?><span class="gc-lock" title="کارنامهٔ این ماه مسدود (قفل) است">🔒</span><?php endif; ?>
                            </td>
                            <?php foreach ($subjects as $s):
                                $value = $info['scores'][$s['name']] ?? '';
                                $num = $value === '' ? null : (float)$value;
                                $cls = $num === null ? '' : ($num == 21.0 ? ' is-absence' : ($num < 10 ? ' is-fail' : ''));
                            ?>
                                <td class="gc-cell-wrap">
                                    <input class="gc-cell<?php echo $cls; ?>" type="text" inputmode="decimal" autocomplete="off" spellcheck="false"
                                           aria-label="نمرهٔ <?php echo clean($s['name']); ?> برای <?php echo clean($fullName); ?>"
                                           data-key="<?php echo clean($s['name']); ?>"
                                           data-student="<?php echo $sid; ?>"
                                           data-saved="<?php echo $num === null ? '' : clean(rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.')); ?>"
                                           value="<?php echo $num === null ? '' : clean(rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.')); ?>"
                                           placeholder="-">
                                </td>
                            <?php endforeach; ?>
                            <td class="gc-avg" data-sid="<?php echo $sid; ?>"><?php echo $gpaDisplay === '' ? '-' : clean($gpaDisplay); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-muted" style="margin-top:8px;line-height:2">
                تغییر هر سلول بلافاصله ذخیره می‌شود؛ سبز شدن کوتاه کادر یعنی ذخیره انجام شده است.
                خالی‌کردن یک سلول، نمرهٔ آن درس را از کارنامهٔ ماه حذف می‌کند. عدد ۲۱ یعنی «غیبت» و در معدل اثر ندارد.
            </p>
        </div>
    <?php endif; ?>
</div>

<style>
.gc-filters{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.gc-filters>div{min-width:150px}
.gc-status{font-size:12px;padding:2px 6px;border-radius:6px}
.gc-status.is-ok{color:#065f46;background:#d1fae5}
.gc-status.is-err{color:#991b1b;background:#fee2e2}
.gc-legend{display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:8px}
.gc-legend-key{display:inline-flex;align-items:center;gap:5px}
.gc-dot{width:10px;height:10px;border-radius:3px;display:inline-block}
.gc-dot-fail{background:#fee2e2;border:1px solid #ef4444}
.gc-dot-abs{background:#fef3c7;border:1px solid #d97706}
.gc-scroll{overflow:auto;max-height:70vh}
.gc-table{border-collapse:separate;border-spacing:0;width:100%;font-size:12.5px}
.gc-table th,.gc-table td{border:1px solid #e2e8f0;padding:4px 6px;text-align:center;white-space:nowrap}
.gc-table thead th{position:sticky;top:0;background:#f1f5f9;z-index:2;cursor:pointer;user-select:none;line-height:1.7}
.gc-table thead th.gc-row-num,.gc-table thead th.gc-name{cursor:default}
.gc-table thead th.gc-name{cursor:pointer}
.gc-subject{display:block;font-weight:700}
.gc-teacher{display:block;font-size:10.5px;color:#475569;font-weight:400}
.gc-arrow{font-size:10px;color:#94a3b8}
.gc-table thead th.is-asc .gc-arrow,.gc-table thead th.is-desc .gc-arrow{color:#1d4ed8}
.gc-table tbody tr:nth-child(even){background:#f8fafc}
.gc-table td.gc-name{text-align:right;min-width:130px}
.gc-table td.gc-cell-wrap{padding:2px}
.gc-row-num{width:44px;color:#64748b}
.gc-lock{font-size:10px;margin-inline-start:4px}
.gc-cell{width:74px;border:1px solid #cbd5e1;border-radius:6px;padding:4px 2px;text-align:center;
         font:inherit;font-weight:700;background:#fff;color:#0f172a;transition:background .2s,box-shadow .2s}
.gc-cell:focus{outline:2px solid #2563eb;outline-offset:0;background:#eff6ff}
.gc-cell:disabled{opacity:.6}
.gc-cell.is-fail{background:#fee2e2;border-color:#ef4444;color:#991b1b}
.gc-cell.is-absence{background:#fef3c7;border-color:#d97706;color:#92400e}
.gc-cell.is-saved{box-shadow:0 0 0 2px #34d399 inset;background:#ecfdf5}
.gc-cell.is-error{box-shadow:0 0 0 2px #f87171 inset}
.gc-avg{font-weight:700;background:#f0fdf4;color:#166534;min-width:70px}
.gc-avg-head{background:#dcfce7!important}
@media (max-width:900px){.gc-cell{width:64px}.gc-table{font-size:12px}}
</style>

<script>
(function () {
  var table = document.getElementById('gcTable');
  if (!table) return;
  var tbody = table.tBodies[0];
  var status = document.getElementById('gcStatus');
  var csrf = <?php echo json_encode(csrf_token(), JSON_UNESCAPED_UNICODE); ?>;
  var year = table.dataset.year, month = table.dataset.month, cls = table.dataset.class;
  var timer = null;

  function note(text, ok) {
    if (!status) return;
    status.textContent = text || '';
    status.className = 'gc-status' + (text ? (ok ? ' is-ok' : ' is-err') : '');
    if (timer) clearTimeout(timer);
    if (text && ok) timer = setTimeout(function () { if (status.textContent === text) { status.textContent = ''; status.className = 'gc-status'; } }, 2500);
  }
  /* @gc-logic-start — منطق نابِ مرتب‌سازی/خواندن سلول (تست واقعی از همین متن اجرا می‌کند) */
  function toNumber(raw) {
    var v = String(raw == null ? '' : raw).trim()
      .replace(/[۰-۹]/g, function (d) { return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)); })
      .replace(/[٠-٩]/g, function (d) { return String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)); })
      .replace('٫', '.').replace('/', '.');
    if (v === '' || v === '-' || v === '—') return null;
    var n = parseFloat(v);
    return isNaN(n) ? null : n;
  }
  function rows() { return Array.prototype.slice.call(tbody.rows); }
  function cellsOf(row) { return Array.prototype.slice.call(row.querySelectorAll('input.gc-cell')); }
  function valueOf(row, key) {
    if (key === 'name') return row.dataset.name || '';
    if (key === 'avg') { var a = row.dataset.avg; return (a === '' || a == null) ? null : parseFloat(a); }
    var ins = cellsOf(row);
    for (var i = 0; i < ins.length; i++) {
      if (ins[i].dataset.key === key) return toNumber(ins[i].value);
    }
    return null;
  }
  function renumber() {
    rows().forEach(function (row, i) {
      var cell = row.querySelector('.gc-row-num');
      if (cell) cell.textContent = String(i + 1).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; });
    });
  }
  function sortBy(key, dir) {
    var list = rows();
    list.sort(function (a, b) {
      var va = valueOf(a, key), vb = valueOf(b, key);
      if (key === 'name') {
        var r = String(va).localeCompare(String(vb), 'fa');
        return dir === 'asc' ? r : -r;
      }
      if (va === null && vb === null) return 0;
      if (va === null) return 1;      /* نمرهٔ نداشته همیشه آخر */
      if (vb === null) return -1;
      if (va === vb) return 0;
      return dir === 'asc' ? (va < vb ? -1 : 1) : (va > vb ? -1 : 1);
    });
    list.forEach(function (row) { tbody.appendChild(row); });
    renumber();
  }
  /* نقشهٔ حرکت با کلیدهای جهت‌دار.
     قرارداد: idx = شمارهٔ سلول در «ترتیب خواندن» (۰ = بالا-راستِ جدول، چون جدول
     راست‌به‌چپ است)، perRow = تعداد سلول هر ردیف و total = کل سلول‌ها.

       ↑ / ↓  → همان ستون، یک ردیف بالا/پایین (لبه که رسید، هیچ حرکتی نمی‌کند)
       ←      → یک سلول به سمت چپ دیداری = یک قدم جلو در ترتیب خواندن
       →      → یک سلول به سمت راست دیداری = یک قدم عقب در ترتیب خواندن
       Enter  → مثل ↓ (ذخیره و رفتن به نفر بعدِ همان درس)

     نسخهٔ پیشین با شمارهٔ ستونِ td حساب می‌کرد و وسط ردیف نام دانش‌آموز
     مبدأ می‌گرفت؛ نتیجه‌اش همان جابه‌جایی اشتباه بود که کارفرما دید. حالا
     حساب روی همین ترتیب خواندن است، پس هر کلید دقیقاً یک قدم در جهت خودش
     می‌رود. خروجی: شمارهٔ مقصد، یا -1 وقتی جایی برای رفتن نیست. */
  function gcTargetIndex(key, idx, perRow, total) {
    if (idx < 0 || idx >= total) return -1;
    if (key === 'ArrowDown' || key === 'Enter') {
      var down = idx + perRow;
      return (perRow > 0 && down < total) ? down : -1;
    }
    if (key === 'ArrowUp') {
      var up = idx - perRow;
      return up >= 0 ? up : -1;
    }
    if (key === 'ArrowLeft')  return (idx + 1 < total) ? idx + 1 : -1;
    if (key === 'ArrowRight') return (idx - 1 >= 0) ? idx - 1 : -1;
    return -1;
  }
  /* @gc-logic-end */
  var heads = Array.prototype.slice.call(table.querySelectorAll('th.gc-sort'));
  heads.forEach(function (th) {
    th.addEventListener('click', function () {
      var dir = th.dataset.dir === 'asc' ? 'desc' : 'asc';
      heads.forEach(function (o) {
        if (o === th) return;
        delete o.dataset.dir;
        o.classList.remove('is-asc', 'is-desc');
        var a = o.querySelector('.gc-arrow'); if (a) a.textContent = '↕';
      });
      th.dataset.dir = dir;
      th.classList.toggle('is-asc', dir === 'asc');
      th.classList.toggle('is-desc', dir === 'desc');
      var arrow = th.querySelector('.gc-arrow'); if (arrow) arrow.textContent = dir === 'asc' ? '↑' : '↓';
      sortBy(th.dataset.key, dir);
      note('مرتب شد: ' + th.textContent.trim() + (dir === 'asc' ? ' (صعودی)' : ' (نزولی)'), true);
    });
  });

  function mark(input) {
    var v = toNumber(input.value);
    input.classList.toggle('is-fail', v !== null && v !== 21 && v < 10);
    input.classList.toggle('is-absence', v === 21);
  }
  function save(input) {
    if (input.dataset.busy === '1') return;
    if (input.value.trim() === (input.dataset.saved || '').trim()) return;
    var body = new URLSearchParams();
    body.set('gc_action', 'save');
    body.set('csrf_token', csrf);
    body.set('year', year);
    body.set('month', month);
    body.set('class_name', cls);
    body.set('student_id', input.dataset.student);
    body.set('subject', input.dataset.key);
    body.set('score', input.value.trim());
    input.dataset.busy = '1';
    input.disabled = true;
    fetch('grade-columns.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString()
    }).then(function (r) { return r.json(); }).then(function (j) {
      input.disabled = false; input.dataset.busy = '';
      if (!j || !j.ok) {
        input.value = input.dataset.saved || '';
        input.classList.add('is-error');
        note((j && j.message) || 'ذخیره نشد.', false);
        mark(input);
        return;
      }
      input.value = j.value;
      input.dataset.saved = j.value;
      input.classList.remove('is-error');
      input.classList.add('is-saved');
      setTimeout(function () { input.classList.remove('is-saved'); }, 900);
      mark(input);
      var row = input.closest('tr');
      if (row) {
        row.dataset.avg = (j.gpa && j.gpa > 0) ? j.gpa : '';
        var avg = row.querySelector('.gc-avg');
        if (avg) avg.textContent = j.gpa_display && j.gpa_display !== '' ? j.gpa_display : '-';
      }
      note(j.message || 'ذخیره شد.', true);
    }).catch(function () {
      input.disabled = false; input.dataset.busy = '';
      input.value = input.dataset.saved || '';
      input.classList.add('is-error');
      mark(input);
      note('خطای شبکه؛ ذخیره نشد.', false);
    });
  }

  table.addEventListener('change', function (e) {
    var input = e.target.closest('.gc-cell');
    if (input) save(input);
  });
  table.addEventListener('blur', function (e) {
    var input = e.target.closest && e.target.closest('.gc-cell');
    if (input) save(input);
  }, true);
  function allCells() { return Array.prototype.slice.call(table.querySelectorAll('input.gc-cell')); }
  function focusIndex(li) {
    if (li < 0) return false;
    var list = allCells();
    if (!list[li]) return false;
    list[li].focus();
    if (list[li].select) list[li].select();
    return true;
  }
  table.addEventListener('keydown', function (e) {
    var input = e.target.closest && e.target.closest('.gc-cell');
    if (!input) return;
    var keys = ['ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight', 'Enter'];
    if (keys.indexOf(e.key) === -1 && e.key !== 'Escape') return;
    e.preventDefault();
    if (e.key === 'Escape') { input.value = input.dataset.saved || ''; mark(input); input.blur(); return; }
    var row = input.closest('tr');
    var perRow = row ? row.querySelectorAll('input.gc-cell').length : 0;
    var list = allCells();
    var target = gcTargetIndex(e.key, list.indexOf(input), perRow, list.length);
    if (e.key === 'Enter') save(input);
    focusIndex(target);
  });
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
