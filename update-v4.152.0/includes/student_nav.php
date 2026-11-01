<?php
// File: includes/student_nav.php (v4.210.0)
/**
 * V4.210.0 — «منوی کاشی» پنل دانش‌آموز
 *
 * پیش از این همهٔ اطلاعات (برنامهٔ امتحانی، موارد انضباطی، کارنامه‌ها، آزمون
 * مجازی) در یک صفحهٔ طولانی زیر هم می‌آمد و دانش‌آموز باید برای هر مورد
 * صفحه را تا پایین اسکرول می‌کرد. حالا همان الگوی کاشی‌های پنل دبیر
 * (includes/teacher_nav.php) برای دانش‌آموز هم استفاده می‌شود: هر موضوع
 * صفحهٔ مستقل خودش را دارد و این فایل فقط سربرگ + کاشی‌ها را می‌سازد.
 *
 *   student-schedule.php        برنامه هفتگی (بر اساس کلاس دانش‌آموز)
 *   student-exam-schedule.php   برنامه امتحانی (ماهِ انتخابی مدیر یا جدیدترین ماه)
 *   student-discipline.php      موارد انضباطی
 *   student-reports.php         کارنامه‌ها + نمودار روند
 *   student-online-exams.php    آزمون مجازی (صفحهٔ موجود)
 *
 * هیچ داده‌ای این‌جا نوشته نمی‌شود؛ فقط خواندن برای شمارندهٔ کاشی‌ها.
 */

if (!function_exists('student_nav_num')) {
    /** رقم‌های فارسی برای کد ملی/سال/تعداد؛ همان تابع مشترک سایت. */
    function student_nav_num($value) {
        return function_exists('tr_num') ? tr_num((string)$value, 'fa') : (string)$value;
    }
}

if (!function_exists('student_panel_year')) {
    /**
     * سال تحصیلیِ نمایش‌داده‌شده برای دانش‌آموز.
     * اول سالِ خودِ دانش‌آموز، و اگر خالی بود سال پیش‌فرض مدرسه — همان
     * قاعده‌ای که exam_student_schedule() برای انتخاب برنامهٔ امتحانی دارد.
     */
    function student_panel_year($student) {
        $year = function_exists('normalize_academic_year')
            ? normalize_academic_year($student['academic_year'] ?? '')
            : trim((string)($student['academic_year'] ?? ''));
        if ($year === '' && function_exists('get_current_academic_year')) {
            $year = normalize_academic_year(get_current_academic_year());
        }
        return $year;
    }
}

if (!function_exists('student_nav_tiles')) {
    /**
     * کاشی‌های پنل دانش‌آموز.
     * $counts اختیاری است؛ در صفحهٔ داشبورد پر می‌شود و در صفحه‌های داخلی
     * خالی می‌ماند تا هیچ کوئری اضافه‌ای برای شمارنده اجرا نشود.
     */
    function student_nav_tiles($active = '', $counts = []) {
        $n = function ($key, $unit) use ($counts) {
            if (!isset($counts[$key])) return '';
            return '<span class="sn-tile-n">' . student_nav_num((int)$counts[$key]) . ' ' . $unit . '</span>';
        };
        return [
            ['schedule',   'student-schedule.php',      '📅', 'برنامه هفتگی',   $n('schedule', 'زنگ')],
            ['exams',      'student-exam-schedule.php', '📝', 'برنامه امتحانی', $n('exams', 'آزمون')],
            ['discipline', 'student-discipline.php',    '⚠️', 'موارد انضباطی',  $n('discipline', 'مورد')],
            ['reports',    'student-reports.php',       '📊', 'کارنامه‌ها',      $n('reports', 'کارنامه')],
            ['online',     'student-online-exams.php',  '🧪', 'آزمون مجازی',    $n('online', 'آزمون')],
        ];
    }
}

if (!function_exists('student_nav_counts')) {
    /**
     * شمارندهٔ کاشی‌ها — هر کوئری جدا محافظت شده است تا نصب قدیمی که یکی از
     * جدول‌ها را هنوز ندارد، به‌خاطر یک عدد، داشبورد را نشکند.
     */
    function student_nav_counts($student) {
        $out = ['schedule' => 0, 'exams' => 0, 'discipline' => 0, 'reports' => 0, 'online' => 0];
        $sid = (int)($student['id'] ?? 0);
        if ($sid <= 0) return $out;
        $countOne = function ($sql, array $params) {
            try {
                $row = DB::fetch($sql, $params);
                return is_array($row) ? (int)($row['c'] ?? 0) : 0;
            } catch (Throwable $e) {
                return 0;
            }
        };
        $out['reports']    = $countOne("SELECT COUNT(*) c FROM reports WHERE student_id = ?", [$sid]);
        $out['discipline'] = $countOne("SELECT COUNT(*) c FROM student_discipline_records WHERE student_id = ?", [$sid]);
        $out['online']     = $countOne("SELECT COUNT(*) c FROM online_exams WHERE status='published' AND is_active=1 AND (class_name='' OR class_name=? OR class_name IS NULL)", [(string)($student['class_name'] ?? '')]);

        $year  = student_panel_year($student);
        $class = (string)($student['class_name'] ?? '');
        $out['schedule'] = $countOne(
            "SELECT COUNT(*) c FROM class_schedules WHERE class_name = ? AND (academic_year = ? OR academic_year = ?)",
            [$class, $year, str_replace('/', '-', $year)]
        );

        if (function_exists('exam_student_schedule_selection')) {
            try {
                $selection = exam_student_schedule_selection($sid);
                $out['exams'] = count($selection['rows'] ?? []);
            } catch (Throwable $e) {
                $out['exams'] = 0;
            }
        }
        return $out;
    }
}

if (!function_exists('render_student_nav')) {
    /**
     * سربرگ + کاشی‌های پنل دانش‌آموز.
     * $active یکی از dashboard|schedule|exams|discipline|reports|online
     */
    function render_student_nav($active = 'dashboard', $student = null, $counts = []) {
        if (!function_exists('is_student_logged_in') || !is_student_logged_in()) return;
        if ($student === null) {
            $student = DB::fetch("SELECT * FROM students WHERE id = ?", [(int)($_SESSION['student_id'] ?? 0)]);
        }
        if (!$student) return;

        $name  = trim((string)($student['first_name'] ?? '') . ' ' . (string)($student['last_name'] ?? ''));
        $year  = student_panel_year($student);
        $tiles = student_nav_tiles($active, $counts);
        $meta  = [];
        if (trim((string)($student['national_id'] ?? '')) !== '') {
            $meta[] = 'کد ملی: <span class="font-mono">' . clean(student_nav_num($student['national_id'])) . '</span>';
        }
        if (trim((string)($student['class_name'] ?? '')) !== '') {
            $meta[] = 'کلاس: <b>' . clean($student['class_name']) . '</b>';
        }
        if (trim((string)($student['grade_level'] ?? '')) !== '') {
            $meta[] = 'پایه: <b>' . clean($student['grade_level']) . '</b>';
        }
        if ($year !== '') {
            $meta[] = 'سال تحصیلی: <b>' . clean(student_nav_num($year)) . '</b>';
        }
        ?>
    <style>
    .sn-hero{background:linear-gradient(135deg,#4338ca,#1d4ed8);color:#fff;border-radius:18px;padding:22px;box-shadow:0 10px 25px rgba(29,78,216,.28);margin-bottom:24px}
    .sn-hero .sn-badge{display:inline-block;background:rgba(255,255,255,.2);color:#fff;border-radius:999px;padding:4px 12px;font-size:.68rem;font-weight:700;margin-bottom:8px}
    .sn-hero h2{color:#fff !important}
    .sn-hero .sn-meta{font-size:.72rem;color:#dbeafe}
    .sn-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(118px,1fr));gap:10px;margin-top:18px}
    .sn-tile{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.28);border-radius:14px;padding:14px 8px;color:#fff !important;text-decoration:none !important;font-size:.72rem;font-weight:700;text-align:center;transition:transform .15s ease,background .15s ease;min-height:92px}
    .sn-tile:hover{background:rgba(255,255,255,.28);transform:translateY(-2px)}
    .sn-tile.active{background:rgba(255,255,255,.32);border-color:#fff}
    .sn-tile .ic{font-size:1.55rem;line-height:1}
    .sn-tile .sn-tile-n{font-size:.62rem;font-weight:600;color:#dbeafe}
    .sn-tile.active .sn-tile-n{color:#fff}
    .dark .sn-tile .sn-tile-n{color:#c7d2fe}
    @media(max-width:480px){.sn-grid{grid-template-columns:repeat(3,1fr)}.sn-tile{min-height:84px}}
    </style>
    <div class="sn-hero">
        <div>
            <span class="sn-badge">پنل اختصاصی دانش‌آموز</span>
            <h2 class="text-2xl font-bold mb-1"><?php echo clean($name); ?></h2>
            <?php if ($meta): ?><p class="sn-meta"><?php echo implode(' | ', $meta); ?></p><?php endif; ?>
        </div>
        <div class="sn-grid">
            <?php foreach ($tiles as $t): ?>
            <a href="<?php echo clean($t[1]); ?>" class="sn-tile<?php echo $active === $t[0] ? ' active' : ''; ?>">
                <span class="ic"><?php echo $t[2]; ?></span>
                <span class="t"><?php echo clean($t[3]); ?></span>
                <?php echo $t[4]; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
        <?php
    }
}
