<?php
/**
 * Reports & Grades Management (reports.php)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/academic_year_helpers.php';
require_once __DIR__ . '/includes/school_roles.php';
ensure_school_roles_schema();
$canDeputyReports = function_exists('is_teacher_logged_in') && is_teacher_logged_in() && (teacher_has_deputy($_SESSION['teacher_id']) || teacher_has_executive($_SESSION['teacher_id']));
if (!$canDeputyReports) require_permission('manage_reports');
require_once __DIR__ . '/includes/header.php';

$action   = $_GET['action'] ?? '';
$reportId = (int)($_GET['id'] ?? 0);

// Delete Report
if ($action === 'delete' && $reportId > 0) {
    DB::execute("DELETE FROM reports WHERE id = ?", [$reportId]);
    DB::execute("DELETE FROM report_grades WHERE report_id = ?", [$reportId]);
    log_activity($_SESSION['admin_id'], 'حذف کارنامه', "کارنامه ID: $reportId حذف شد.");
    set_flash_message('success', 'کارنامه مورد نظر حذف شد.');
    redirect('reports.php');
}

// Toggle Lock single report via standard request
if ($action === 'toggle_lock' && $reportId > 0) {
    $rep = DB::fetch("SELECT is_locked FROM reports WHERE id = ?", [$reportId]);
    if ($rep) {
        $newLock = $rep['is_locked'] ? 0 : 1;
        DB::execute("UPDATE reports SET is_locked = ? WHERE id = ?", [$newLock, $reportId]);
        log_activity($_SESSION['admin_id'], 'تغییر قفل کارنامه', "کارنامه $reportId وضعیت مسدودسازی به $newLock شد.");
        set_flash_message('success', 'وضعیت مسدودسازی کارنامه تغییر یافت.');
    }
    redirect('reports.php');
}

// Save Manual Report
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_report'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'خطای امنیتی CSRF');
        redirect('reports.php');
    }
    $studentId = (int)($_POST['student_id'] ?? 0);
    $year = unify_academic_year(trim($_POST['academic_year'] ?? '1402-1403'));
    $term      = trim($_POST['term'] ?? 'نوبت اول');
    $month     = trim($_POST['report_month'] ?? 'دی');
    $comments  = trim($_POST['teacher_comments'] ?? '');
    $disciplineScore = ($_POST['discipline_score'] ?? '') !== '' ? (float)str_replace('/', '.', $_POST['discipline_score']) : null;

    $student = DB::fetch("SELECT class_name FROM students WHERE id = ?", [$studentId]);
    if (!$student) {
        set_flash_message('error', 'دانش‌آموز انتخاب‌شده نامعتبر است.');
        redirect('reports.php');
    }

    $subjNames  = $_POST['subject_name'] ?? [];
    $subjScores = $_POST['score'] ?? [];
    $subjMax    = $_POST['max_score'] ?? [];
    $subjCoeff  = $_POST['coefficient'] ?? [];

    $totalWeighted = 0;
    $totalCoeff = 0;
    $gradesData = [];

    foreach ($subjNames as $idx => $sname) {
        $sname = trim($sname);
        if (empty($sname)) continue;
        $rawScore = trim((string)($subjScores[$idx] ?? ''));
        // Empty score means the subject is not included in this report at all.
        if ($rawScore === '') continue;
        $score = (float)str_replace('/', '.', $rawScore);
        $max   = (float)($subjMax[$idx] ?? 20);
        $coef  = (float)($subjCoeff[$idx] ?? 1);

        // Score 21 means absence (غیبت): show in report, but ignore in GPA.
        if ($score != 21.0) {
            $totalWeighted += ($score * $coef);
            $totalCoeff += $coef;
        }
        $gradesData[] = [
            'name' => $sname,
            'score' => $score,
            'max' => $max,
            'coef' => $coef,
            'status' => $score == 21.0 ? 'none' : ($score >= 10 ? 'passed' : 'failed')
        ];
    }

    $gpa = $totalCoeff > 0 ? round($totalWeighted / $totalCoeff, 2) : 0;

    if ($reportId > 0) {
        DB::execute("UPDATE reports SET student_id=?, class_name=?, academic_year=?, term=?, report_month=?, total_score=?, gpa=?, discipline_score=?, teacher_comments=? WHERE id=?",
        [$studentId, $student['class_name'], $year, $term, $month, $totalWeighted, $gpa, $disciplineScore, $comments, $reportId]);
        DB::execute("DELETE FROM report_grades WHERE report_id = ?", [$reportId]);
        foreach ($gradesData as $g) {
            DB::execute("INSERT INTO report_grades (report_id, subject_name, score, max_score, coefficient, status) VALUES (?, ?, ?, ?, ?, ?)",
            [$reportId, $g['name'], $g['score'], $g['max'], $g['coef'], $g['status']]);
        }
        log_activity($_SESSION['admin_id'] ?? null, 'ویرایش کارنامه', "کارنامه ID: $reportId ویرایش شد.");
        set_flash_message('success', 'کارنامه با موفقیت ویرایش و محاسبه شد.');
    } else {
        DB::execute("INSERT INTO reports (student_id, class_name, academic_year, term, report_month, total_score, gpa, discipline_score, teacher_comments) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$studentId, $student['class_name'], $year, $term, $month, $totalWeighted, $gpa, $disciplineScore, $comments]);
        $newRepId = DB::lastInsertId();
        foreach ($gradesData as $g) {
            DB::execute("INSERT INTO report_grades (report_id, subject_name, score, max_score, coefficient, status) VALUES (?, ?, ?, ?, ?, ?)",
            [$newRepId, $g['name'], $g['score'], $g['max'], $g['coef'], $g['status']]);
        }
        log_activity($_SESSION['admin_id'] ?? null, 'ثبت دستی کارنامه', "کارنامه جدید برای دانش‌آموز ID: $studentId ثبت شد.");
        set_flash_message('success', 'کارنامه جدید با موفقیت ایجاد و معدل محاسبه گردید.');
    }
    $targetReportId = $reportId > 0 ? $reportId : ($newRepId ?? 0);
    if ($targetReportId > 0) redirect('reports.php?action=edit&id=' . $targetReportId);
    redirect('reports.php');
}

if ($action === 'new' || $action === 'edit'):
    $repData = null;
    $gradesList = [];
    if ($action === 'edit' && $reportId > 0) {
        $repData = DB::fetch("SELECT * FROM reports WHERE id = ?", [$reportId]);
        $gradesList = DB::fetchAll("SELECT * FROM report_grades WHERE report_id = ?", [$reportId]);
    }
    $preselectStudentId = (int)($_GET['student_id'] ?? 0);
    $selectedFormStudentId = (int)($repData['student_id'] ?? $preselectStudentId);
    
    // Academic year options - from settings and existing data (Shamsi)
    $currentDefaultYear = get_setting('current_academic_year','1404/1405');
    $yearOptions = get_academic_years_for_filter();
    if (empty($yearOptions)) $yearOptions = [['academic_year'=>$currentDefaultYear], ['academic_year'=>'1404/1405'], ['academic_year'=>'1403/1404']];
    
    // Month options - fixed Persian months + terms
    $monthOptions = ['مهر','آبان','آذر','دی','بهمن','اسفند','فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','نوبت اول','نوبت دوم'];
    
    // Selected year and month for form - hierarchical: year -> month -> student
    $selectedYear = trim($_GET['academic_year'] ?? $repData['academic_year'] ?? $currentDefaultYear);
    $selectedMonth = trim($_GET['report_month'] ?? $repData['report_month'] ?? 'دی');
    
    // Fetch all active students with academic year for filtering - FIXED to show ALL students of that year with normalization
    $allStudents = DB::fetchAll("SELECT id, national_id, first_name, last_name, class_name, grade_level, academic_year FROM students WHERE status='active' ORDER BY academic_year DESC, last_name ASC, first_name ASC");
    // Group students by NORMALIZED academic year for JS (to handle 1405/1406 vs 1405-1406 vs spaces)
    $studentsByYear = [];
    $studentsByYearNormalized = [];
    foreach ($allStudents as $s) {
        $ayRaw = $s['academic_year'] ?: '';
        $ay = $ayRaw ?: 'بدون سال';
        $ayNorm = $ayRaw ? (function($y){ $y=trim($y); $y=str_replace(['-','–','—',' '], ['/','/','/',''], $y); $y=preg_replace('#/+#','/',$y); return trim($y,'/'); })($ayRaw) : 'بدون سال';
        if (!isset($studentsByYear[$ay])) $studentsByYear[$ay] = [];
        $studentsByYear[$ay][] = $s;
        if (!isset($studentsByYearNormalized[$ayNorm])) $studentsByYearNormalized[$ayNorm] = [];
        $studentsByYearNormalized[$ayNorm][] = $s;
    }
    // For initial display, filter students by NORMALIZED selected year - show ALL of that year
    $selectedYearNorm = $selectedYear ? (function($y){ $y=trim($y); $y=str_replace(['-','–','—',' '], ['/','/','/',''], $y); $y=preg_replace('#/+#','/',$y); return trim($y,'/'); })($selectedYear) : '';
    $students = [];
    if ($selectedYearNorm !== '') {
        // Get all students whose normalized year equals selected normalized year
        $students = $studentsByYearNormalized[$selectedYearNorm] ?? [];
        // Also include students with empty academic_year? No, per requirement only that year, but include empty if selected is empty
        if (empty($students)) {
            // Fallback: filter manually with normalization
            $students = array_filter($allStudents, function($s) use ($selectedYearNorm) {
                $sNorm = $s['academic_year'] ? (function($y){ $y=trim($y); $y=str_replace(['-','–','—',' '], ['/','/','/',''], $y); $y=preg_replace('#/+#','/',$y); return trim($y,'/'); })($s['academic_year']) : '';
                return $sNorm === $selectedYearNorm;
            });
        }
    } else {
        $students = $allStudents;
    }
    if (empty($students)) $students = $allStudents; // fallback if no match to avoid empty list
    
    $selectedStudent = $selectedFormStudentId ? DB::fetch("SELECT class_name, grade_level FROM students WHERE id=?", [$selectedFormStudentId]) : null;
    if ($selectedStudent) {
        $defaultSubjects = DB::fetchAll("SELECT DISTINCT subject_name AS name, 1 AS coefficient FROM class_schedules WHERE class_name=? AND subject_name<>'' ORDER BY subject_name", [$selectedStudent['class_name']]);
        if (!$defaultSubjects) {
            $defaultSubjects = DB::fetchAll("SELECT name, coefficient FROM subjects WHERE grade_level=? OR grade_level='عمومی' OR grade_level='' ORDER BY name", [$selectedStudent['grade_level']]);
        }
    } else {
        $defaultSubjects = [];
    }
    $gradeRows = [];
    $existingBySubject = [];
    foreach ($gradesList as $gr) {
        $existingBySubject[$gr['subject_name']] = $gr;
        $gradeRows[] = [
            'subject_name' => $gr['subject_name'],
            'score' => $gr['score'],
            'max_score' => $gr['max_score'],
            'coefficient' => $gr['coefficient']
        ];
    }
    foreach ($defaultSubjects as $sub) {
        $subName = $sub['name'] ?? $sub['subject_name'] ?? '';
        if ($subName !== '' && !isset($existingBySubject[$subName])) {
            $gradeRows[] = [
                'subject_name' => $subName,
                'score' => '',
                'max_score' => 20,
                'coefficient' => $sub['coefficient'] ?? 1
            ];
        }
    }
?>
<div class="max-w-3xl mx-auto card p-6">
    <h3 class="text-lg font-bold mb-6 text-primary border-b pb-2">
        <?php echo $action === 'edit' ? 'ویرایش نمرات و کارنامه' : 'ثبت دستی کارنامه جدید'; ?>
        <span class="text-xs text-muted">(سال تحصیلی → ماه → دانش‌آموز)</span>
    </h3>
    <form method="POST" action="reports.php?id=<?php echo $reportId; ?>" id="reportForm">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="save_report" value="1">

        <!-- Hierarchical Selectors: Year -> Month -> Student -->
        <div class="space-y-4 mb-6 p-4 border-2 border-indigo-100 rounded-xl bg-indigo-50/30">
            <h4 class="font-bold text-sm text-indigo-700">۱. انتخاب سال تحصیلی (شمسی) → ۲. ماه → ۳. دانش‌آموز</h4>
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-bold mb-1">۱. سال تحصیلی *</label>
                    <select name="academic_year" id="academicYearSelect" class="form-select font-bold" required onchange="filterStudentsByYear(); updateMonthOptions();">
                        <option value="">انتخاب سال تحصیلی...</option>
                        <?php foreach ($yearOptions as $yo): $ay = $yo['academic_year']; $ayNorm = $ay ? (function($y){ $y=trim($y); $y=str_replace(['-','–','—',' '], ['/','/','/',''], $y); $y=preg_replace('#/+#','/',$y); return trim($y,'/'); })($ay) : ''; $cntNorm = isset($studentsByYearNormalized[$ayNorm]) ? count($studentsByYearNormalized[$ayNorm]) : (isset($studentsByYear[$ay]) ? count($studentsByYear[$ay]) : 0); ?>
                            <option value="<?php echo clean($ay); ?>" <?php echo $selectedYear===$ay ? 'selected' : ''; ?>><?php echo clean($ay); ?> (<?php echo $cntNorm; ?> نفر)</option>
                        <?php endforeach; ?>
                    </select>
                    <div class="text-[10px] text-muted mt-1">سال جاری سیستم: <?php echo clean($currentDefaultYear); ?></div>
                </div>
                <div>
                    <label class="block text-xs font-bold mb-1">۲. ماه / نوبت تحصیلی *</label>
                    <select name="report_month" id="reportMonthSelect" class="form-select font-bold" required>
                        <option value="">انتخاب ماه...</option>
                        <?php foreach ($monthOptions as $mo): ?>
                            <option value="<?php echo clean($mo); ?>" <?php echo $selectedMonth===$mo ? 'selected' : ''; ?>><?php echo clean($mo); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold mb-1">عنوان نوبت (اختیاری)</label>
                    <input type="text" name="term" class="form-input" value="<?php echo clean($repData['term'] ?? $selectedMonth); ?>" placeholder="نوبت اول / دوم / ماهانه" id="termInput">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold mb-1">۳. انتخاب دانش‌آموز * <span class="text-[10px] text-muted" id="studentCountLabel">(فقط دانش‌آموزان سال انتخاب شده)</span></label>
                <select name="student_id" id="studentSelect" class="form-select" required onchange="if(!<?php echo $reportId ? 'true' : 'false'; ?>){location.href='reports.php?action=new&academic_year='+encodeURIComponent(document.getElementById('academicYearSelect').value)+'&report_month='+encodeURIComponent(document.getElementById('reportMonthSelect').value)+'&student_id='+this.value;}">
                    <option value="">ابتدا سال تحصیلی را انتخاب کنید...</option>
                </select>
                <div class="text-[10px] text-muted mt-1">فقط دانش‌آموزان سال تحصیلی انتخاب شده نمایش داده می‌شوند. برای تغییر سال، سال را عوض کنید.</div>
            </div>
        </div>

        <script>
        // All students grouped by year - for filtering - FIXED with normalized map
        window.studentsByYear = <?php echo json_encode($studentsByYear, JSON_UNESCAPED_UNICODE); ?>;
        window.studentsByYearNormalized = <?php echo json_encode($studentsByYearNormalized, JSON_UNESCAPED_UNICODE); ?>;
        window.allStudents = <?php echo json_encode($allStudents, JSON_UNESCAPED_UNICODE); ?>;
        window.selectedStudentId = <?php echo (int)$selectedFormStudentId; ?>;

        function normalizeYearJS(y){
            if(!y) return '';
            y = y.trim().replace(/[-–—\s]+/g, '/').replace(/\/+/g, '/').replace(/^\/|\/$/g, '');
            return y;
        }

        function filterStudentsByYear(){
            let yearRaw = document.getElementById('academicYearSelect').value;
            let year = normalizeYearJS(yearRaw);
            let studentSelect = document.getElementById('studentSelect');
            let countLabel = document.getElementById('studentCountLabel');
            studentSelect.innerHTML = '';
            
            if(!yearRaw){
                studentSelect.innerHTML = '<option value="">ابتدا سال تحصیلی را انتخاب کنید...</option>';
                countLabel.textContent = '(ابتدا سال را انتخاب کنید)';
                return;
            }

            // Try normalized map first - shows ALL students of that normalized year (handles 1405/1406 vs 1405-1406)
            let students = window.studentsByYearNormalized[year] || [];
            if(students.length===0){
                // Fallback to exact raw map
                students = window.studentsByYear[yearRaw] || [];
            }
            if(students.length===0){
                let alt1 = yearRaw.replace('/', '-');
                let alt2 = yearRaw.replace('-', '/');
                students = window.studentsByYear[alt1] || window.studentsByYear[alt2] || window.studentsByYearNormalized[normalizeYearJS(alt1)] || [];
            }
            // If still empty, filter manually with normalization - shows ALL matching
            if(students.length===0){
                students = window.allStudents.filter(s=> {
                    let sNorm = s.academic_year ? normalizeYearJS(s.academic_year) : '';
                    return sNorm === year;
                });
            }
            if(students.length===0){
                // Last fallback: show all to avoid empty
                students = window.allStudents;
                countLabel.textContent = `(هشدار: ${students.length} دانش‌آموز - سال ${yearRaw} یافت نشد، همه نمایش داده شدند)`;
            } else {
                countLabel.textContent = `(نمایش تمام دانش‌آموزان سال ${yearRaw} - ${students.length} نفر)`;
            }

            studentSelect.add(new Option('انتخاب دانش‌آموز...', ''));
            students.forEach(s=>{
                let text = `${s.last_name}، ${s.first_name} (${s.national_id} - ${s.class_name} - ${s.academic_year||'بدون سال'})`;
                let opt = new Option(text, s.id, false, parseInt(s.id)===window.selectedStudentId);
                studentSelect.add(opt);
            });

            if(students.length===0){
                studentSelect.innerHTML = '<option value="">دانش‌آموزی در این سال یافت نشد</option>';
            }
        }

        function updateMonthOptions(){
            let month = document.getElementById('reportMonthSelect').value;
            let termInput = document.getElementById('termInput');
            if(month && !termInput.value){
                termInput.value = month;
            } else if(month){
                // If term is same as previous month, update to new month
                // Keep custom term if user edited
            }
        }

        document.addEventListener('DOMContentLoaded', function(){
            filterStudentsByYear();
            // Set month change to update term
            document.getElementById('reportMonthSelect').addEventListener('change', function(){
                document.getElementById('termInput').value = this.value;
            });
        });
        </script>

        <div class="grid grid-cols-2 gap-4 mb-6" style="display:none;">
            <!-- Old fields kept hidden for fallback -->
            <div>
                <label class="block text-xs font-semibold mb-1">عنوان نوبت تحصیلی</label>
                <select name="term_old" class="form-select"><?php $troSel = trim($repData['term'] ?? 'نوبت اول'); $troOpts = ['ماهانه','نوبت اول','نوبت دوم']; if ($troSel !== '' && !in_array($troSel, $troOpts, true)) $troOpts[] = $troSel; foreach ($troOpts as $troT): ?><option value="<?php echo clean($troT); ?>" <?php echo $troSel===$troT?'selected':''; ?>><?php echo clean($troT); ?></option><?php endforeach; ?></select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">ماه کارنامه</label>
                <select name="report_month_old" class="form-select"><?php $rmoSel = trim($repData['report_month'] ?? 'دی'); $rmoOpts = ['مهر','آبان','آذر','دی','بهمن','اسفند','فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','نوبت اول','نوبت دوم']; if ($rmoSel !== '' && !in_array($rmoSel, $rmoOpts, true)) $rmoOpts[] = $rmoSel; foreach ($rmoOpts as $rmoM): ?><option value="<?php echo clean($rmoM); ?>" <?php echo $rmoSel===$rmoM?'selected':''; ?>><?php echo clean($rmoM); ?></option><?php endforeach; ?></select>
            </div>
        </div>

        <h4 class="font-bold mb-3 text-sm border-b pb-1">نمرات دروس:</h4>
        <div id="subjectsBox" class="space-y-2 mb-4">
            <?php foreach ($gradeRows as $gr): ?>
            <div class="grid grid-cols-4 gap-2 items-center">
                <input type="text" name="subject_name[]" class="form-input text-xs" value="<?php echo clean($gr['subject_name']); ?>" placeholder="نام درس" required>
                <input type="number" step="0.25" min="0" max="21" name="score[]" class="form-input text-xs font-bold" value="<?php echo clean($gr['score']); ?>" placeholder="خالی=عدم ثبت، 21=غیبت">
                <input type="number" step="0.25" min="1" max="100" name="max_score[]" class="form-input text-xs" value="<?php echo clean($gr['max_score']); ?>" placeholder="حداکثر">
                <input type="number" step="0.5" min="0.5" max="10" name="coefficient[]" class="form-input text-xs" value="<?php echo clean($gr['coefficient']); ?>" placeholder="ضریب">
            </div>
            <?php endforeach; if (empty($gradeRows)): ?>
            <div class="text-center text-muted text-xs p-3 border rounded">برای این دانش‌آموز/کلاس درسی یافت نشد؛ می‌توانید ردیف درس را دستی اضافه کنید.</div>
            <?php endif; ?>
        </div>

        <button type="button" onclick="addGradeRow()" class="btn btn-outline text-xs mb-6 w-full py-2">+ افزودن ردیف درس دیگر</button>

        <div class="mb-4">
            <label class="block text-xs font-semibold mb-1">نمره انضباط</label>
            <input type="number" step="0.25" min="0" max="21" name="discipline_score" class="form-input" value="<?php echo clean($repData['discipline_score'] ?? ''); ?>" placeholder="مثلاً 20">
        </div>

        <div class="mb-6">
            <label class="block text-xs font-semibold mb-1">نظر یا پیام معلم راهنما</label>
            <textarea name="teacher_comments" rows="2" class="form-textarea"><?php echo clean($repData['teacher_comments'] ?? ''); ?></textarea>
        </div>

        <div class="flex justify-between items-center">
            <a href="reports.php" class="btn btn-secondary">&rarr; انصراف</a>
            <button type="submit" class="btn btn-success px-8 py-2.5 font-bold">💾 محاسبه معدل و ذخیره کارنامه</button>
        </div>
    </form>
</div>
<script>
function addGradeRow() {
    const box = document.getElementById('subjectsBox');
    const div = document.createElement('div');
    div.className = 'grid grid-cols-4 gap-2 items-center';
    div.innerHTML = `
        <input type="text" name="subject_name[]" class="form-input text-xs" placeholder="نام درس" required>
        <input type="number" step="0.25" min="0" max="21" name="score[]" class="form-input text-xs font-bold" placeholder="خالی=عدم ثبت، 21=غیبت">
        <input type="number" step="0.25" min="1" max="100" name="max_score[]" class="form-input text-xs" value="20" placeholder="حداکثر">
        <input type="number" step="0.5" min="0.5" max="10" name="coefficient[]" class="form-input text-xs" value="2" placeholder="ضریب">
    `;
    box.appendChild(div);
}
</script>
<?php
else:
    $filterStudent = trim($_GET['student_query'] ?? '');
    $filterClass   = trim($_GET['class_name'] ?? '');
    $filterYear    = trim($_GET['year'] ?? '');
    $filterMonth   = trim($_GET['month'] ?? '');

    $where = ["1=1"];
    $params = [];
    if (!empty($filterStudent)) {
        $where[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.national_id LIKE ?)";
        $params[] = "%$filterStudent%"; $params[] = "%$filterStudent%"; $params[] = "%$filterStudent%";
    }
    if (!empty($filterClass)) { $where[] = "r.class_name = ?"; $params[] = $filterClass; }
    if (!empty($filterYear)) { $where[] = "r.academic_year = ?"; $params[] = $filterYear; }
    if (!empty($filterMonth)) { $where[] = "r.report_month = ?"; $params[] = $filterMonth; }

    $whereSql = implode(' AND ', $where);
    $reports = DB::fetchAll("SELECT r.*, s.first_name, s.last_name, s.national_id FROM reports r JOIN students s ON r.student_id = s.id WHERE $whereSql ORDER BY r.id DESC LIMIT 100", $params);
    $classes = DB::fetchAll("SELECT DISTINCT class_name FROM reports");
    $years = get_academic_years_for_filter(); // Unified - only from master table
    $months  = DB::fetchAll("SELECT DISTINCT report_month FROM reports");
    $students = DB::fetchAll("SELECT id, first_name, last_name, national_id, class_name FROM students WHERE status='active' ORDER BY last_name, first_name");
?>
<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold">مدیریت و نظارت بر کارنامه‌ها</h2>
            <p class="text-sm text-muted">مشاهده، مسدودسازی گروهی یا تکی، خروجی اکسل و ثبت کارنامه</p>
        </div>
        <div class="flex gap-2">
            <a href="bulk-print.php" class="btn btn-primary gap-1 shadow text-xs">🖨️ چاپ گروهی انتخابی (۱، ۲ یا ۴ در صفحه)</a>
            <button onclick="document.getElementById('bulkLockModal').style.display='flex'" class="btn btn-outline border-amber-500 text-amber-700 text-xs">🔒 مسدودسازی گروهی</button>
            <a href="reports.php?action=new" class="btn btn-success gap-1 text-xs">
                <span>+ ثبت دستی کارنامه</span>
            </a>
        </div>
    </div>

    <!-- Hierarchical Filter & Live Search Bar -->
    <div class="card p-4 shadow-md">
        <form method="GET" class="grid grid-cols-5 gap-3 items-end">
            <div>
                <label class="block text-xs font-semibold mb-1">۱. سال تحصیلی</label>
                <select name="year" class="form-select text-xs font-bold" onchange="this.form.submit()">
                    <option value="">همه سال‌ها</option>
                    <?php foreach ($years as $y): ?>
                        <option value="<?php echo clean($y['academic_year']); ?>" <?php echo $filterYear === $y['academic_year'] ? 'selected' : ''; ?>><?php echo clean($y['academic_year']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">۲. ماه / نوبت</label>
                <select name="month" class="form-select text-xs font-bold" onchange="this.form.submit()">
                    <option value="">همه ماه‌ها</option>
                    <?php foreach ($months as $m): ?>
                        <option value="<?php echo clean($m['report_month']); ?>" <?php echo $filterMonth === $m['report_month'] ? 'selected' : ''; ?>><?php echo clean($m['report_month']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">۳. کلاس</label>
                <select name="class_name" class="form-select text-xs font-bold" onchange="this.form.submit()">
                    <option value="">همه کلاس‌ها</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo clean($c['class_name']); ?>" <?php echo $filterClass === $c['class_name'] ? 'selected' : ''; ?>><?php echo clean($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">۴. جستجوی دانش‌آموز</label>
                <input type="text" name="student_query" class="form-input text-xs" placeholder="نام یا کد ملی..." value="<?php echo clean($filterStudent); ?>">
            </div>
            <div class="flex gap-1">
                <button type="submit" class="btn btn-primary w-full text-xs font-bold">فیلتر / جستجو</button>
                <?php if ($filterClass || $filterYear || $filterMonth || $filterStudent): ?>
                <a href="reports.php" class="btn btn-secondary text-xs px-2">حذف</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Reports Table -->
    <div class="card">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>شناسه</th>
                        <th>دانش‌آموز</th>
                        <th>کلاس</th>
                        <th>نوبت / ماه</th>
                        <th>معدل کل</th>
                        <th>وضعیت دسترسی</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $rep): ?>
                    <tr>
                        <td class="font-mono text-xs">#<?php echo $rep['id']; ?></td>
                        <td class="font-bold"><?php echo clean($rep['first_name'] . ' ' . $rep['last_name']); ?></td>
                        <td><?php echo clean($rep['class_name']); ?></td>
                        <td><?php echo clean($rep['term'] . ' (' . $rep['report_month'] . ')'); ?></td>
                        <td><span class="badge badge-info text-sm"><?php echo format_score($rep['gpa']); ?></span></td>
                        <td>
                            <?php if ($rep['is_locked']): ?>
                                <span class="badge badge-danger">مسدود برای دانش‌آموز 🔒</span>
                            <?php else: ?>
                                <span class="badge badge-success">آزاد و قابل مشاهده 🔓</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="flex gap-1 justify-end">
                                <a href="report-view.php?id=<?php echo $rep['id']; ?>" class="btn btn-primary text-xs px-2 py-1">مشاهده</a>
                                <a href="export-excel.php?id=<?php echo $rep['id']; ?>" class="btn btn-outline text-xs px-2 py-1 text-green-700">Excel</a>
                                <a href="export-pdf.php?id=<?php echo $rep['id']; ?>" class="btn btn-outline text-xs px-2 py-1 text-red-600">PDF</a>
                                <a href="reports.php?action=toggle_lock&id=<?php echo $rep['id']; ?>" class="btn btn-secondary text-xs px-2 py-1">
                                    <?php echo $rep['is_locked'] ? 'رفع مسدودیت' : 'مسدود کردن'; ?>
                                </a>
                                <a href="reports.php?action=edit&id=<?php echo $rep['id']; ?>" class="btn btn-secondary text-xs px-2 py-1">ویرایش</a>
                                <a href="reports.php?action=delete&id=<?php echo $rep['id']; ?>" onclick="return confirm('حذف این کارنامه؟')" class="btn btn-danger text-xs px-2 py-1">حذف</a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; if (empty($reports)): ?>
                    <tr><td colspan="7" class="text-center text-muted">کارنامه‌ای یافت نشد.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Bulk Lock Modal -->
<div id="bulkLockModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5);">
    <div class="card max-w-md w-full p-6 shadow-xl">
        <h3 class="font-bold text-lg mb-4 text-amber-700">🔒 مسدودسازی یا آزادسازی گروهی کارنامه‌ها</h3>
        <p class="text-xs text-muted mb-4">با این ابزار می‌توانید مشاهده یا دریافت کارنامه را برای کل یک کلاس در یک ماه یا نوبت خاص مسدود کنید (مثلاً به دلیل عدم تسویه بدهی مالی).</p>
        <form id="bulkLockForm" data-no-ajax="1" onsubmit="submitBulkLock(event)">
            <div class="mb-4">
                <label class="block text-xs font-semibold mb-1">انتخاب کلاس</label>
                <select id="bulkClass" class="form-select">
                    <option value="">همه کلاس‌ها</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo clean($c['class_name']); ?>"><?php echo clean($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-4">
                <label class="block text-xs font-semibold mb-1">انتخاب دانش‌آموز خاص (اختیاری)</label>
                <select id="bulkStudent" class="form-select">
                    <option value="0">همه دانش‌آموزان فیلتر شده</option>
                    <?php foreach ($students ?? [] as $s): ?>
                        <option value="<?php echo (int)$s['id']; ?>"><?php echo clean($s['last_name'].'، '.$s['first_name'].' - '.$s['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-2 mb-4">
                <div>
                    <label class="block text-xs font-semibold mb-1">سال تحصیلی</label>
                    <select id="bulkYear" class="form-select font-bold">
                        <?php foreach ($years as $y): ?>
                            <option value="<?php echo clean($y['academic_year']); ?>"><?php echo clean($y['academic_year']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1">ماه / نوبت</label>
                    <select id="bulkMonth" class="form-select font-bold">
                        <?php foreach ($months as $m): ?>
                            <option value="<?php echo clean($m['report_month']); ?>"><?php echo clean($m['report_month']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mb-4">
                <label class="block text-xs font-semibold mb-1">عملیات مورد نظر</label>
                <select id="bulkIsLocked" class="form-select">
                    <option value="1">مسدود کردن مشاهده کارنامه 🔒</option>
                    <option value="0">آزادسازی و رفع مسدودیت 🔓</option>
                </select>
            </div>
            <div class="mb-6">
                <label class="block text-xs font-semibold mb-1">علت مسدودسازی (جهت نمایش به اولیا)</label>
                <input type="text" id="bulkReason" class="form-input" value="نقص پرونده یا عدم تسویه حساب مالی">
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('bulkLockModal').style.display='none'" class="btn btn-secondary">انصراف</button>
                <button type="submit" class="btn btn-primary px-6">اعمال گروهی</button>
            </div>
        </form>
    </div>
</div>
<script>
function submitBulkLock(e) {
    e.preventDefault();
    const formData = new URLSearchParams();
    formData.append('action', 'bulk_lock');
    formData.append('class_name', document.getElementById('bulkClass').value);
    formData.append('student_id', document.getElementById('bulkStudent').value);
    formData.append('academic_year', document.getElementById('bulkYear').value);
    formData.append('report_month', document.getElementById('bulkMonth').value);
    formData.append('is_locked', document.getElementById('bulkIsLocked').value);
    formData.append('reason', document.getElementById('bulkReason').value);

    fetch('admin-ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    }).then(r => r.json()).then(data => {
        if(data.status === 'success') {
            alert('عملیات با موفقیت انجام شد.');
            location.reload();
        } else {
            alert('خطایی رخ داد');
        }
    });
}
</script>
<?php endif; require_once __DIR__ . '/includes/footer.php'; ?>
