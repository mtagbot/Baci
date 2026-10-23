<?php
// File: discipline-report-management.php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/school_roles.php';
require_once __DIR__ . '/includes/academic_year_helpers.php';
require_once __DIR__ . '/includes/school_sort.php';
ensure_school_roles_schema();
$canDeputyReports = is_teacher_logged_in() && (teacher_has_deputy((int)$_SESSION['teacher_id']) || teacher_has_executive((int)$_SESSION['teacher_id']));
if (!$canDeputyReports) require_permission('manage_reports');

function drm_months() {
    return ['مهر','آبان','آذر','دی','بهمن','اسفند','فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','نوبت اول','نوبت دوم'];
}
function drm_term_for_month($month) {
    return in_array($month, ['بهمن','اسفند','فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','نوبت دوم'], true) ? 'نوبت دوم' : 'نوبت اول';
}
function drm_class_sql($column) {
    return "REPLACE(REPLACE(REPLACE(REPLACE($column,' ',''),'-',''),'/',''),'_','') = ?";
}
function drm_score($value) {
    $value = trim(strtr((string)$value, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','٫'=>'.','،'=>'.','/'=>'.']));
    if ($value === '') return null;
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) return false;
    $score = (float)$value;
    return ($score >= 0 && $score <= 21) ? round($score, 2) : false;
}
function drm_is_attendance_entry($internalNote) {
    $note = trim((string)$internalNote);
    if ($note === '') return false;
    return stripos($note, 'ثبت‌شده از سوابق حضور و غیاب') !== false
        || preg_match('/(?:\\[)?att#\\d+(?:\\])?/i', $note) === 1;
}
function drm_age_label($jalaliDate) {
    $date = trim(tr_num((string)$jalaliDate, 'en'));
    // Attendance entries may prefix the Jalali date with a weekday, e.g. "پنجشنبه 1405/07/16".
    if (!preg_match('~(\d{4})[/-](\d{1,2})[/-](\d{1,2})~u', $date, $m)) return 'تاریخ نامشخص';
    if (!function_exists('jalali_to_gregorian')) return 'تاریخ نامشخص';
    $jy=(int)$m[1];$jm=(int)$m[2];$jd=(int)$m[3];
    if($jm<1||$jm>12||$jd<1||$jd>31)return 'تاریخ نامشخص';
    $g = jalali_to_gregorian($jy, $jm, $jd);
    $event = mktime(12, 0, 0, (int)$g[1], (int)$g[2], (int)$g[0]);
    $nowParts = explode('/', tr_num(jdate('Y/m/d'), 'en'));
    if (count($nowParts) !== 3) return 'تاریخ نامشخص';
    $todayG = jalali_to_gregorian((int)$nowParts[0], (int)$nowParts[1], (int)$nowParts[2]);
    $today = mktime(12, 0, 0, (int)$todayG[1], (int)$todayG[2], (int)$todayG[0]);
    $days = (int)floor(($today - $event) / 86400);
    if ($days < 0) return 'تاریخ آینده';
    if ($days > 391) return 'سال‌های گذشته'; // سقف نمایش: ۱۲ ماه و ۳۱ روز
    if ($days === 0) return 'امروز';
    $months = $days > 360 ? 12 : intdiv($days, 30);
    $remaining = $days > 360 ? $days - 360 : $days % 30;
    if ($months === 0) return tr_num($days, 'fa') . ' روز پیش';
    $label = tr_num($months, 'fa') . ' ماه';
    if ($remaining > 0) $label .= ' و ' . tr_num($remaining, 'fa') . ' روز';
    return $label . ' پیش';
}
function drm_year_options() {
    $years = [];
    foreach (get_academic_years_for_filter() as $row) {
        $value = is_array($row) ? (string)($row['academic_year'] ?? '') : (string)$row;
        $value = unify_academic_year($value);
        if ($value !== '') $years[] = $value;
    }
    $current = unify_academic_year(get_current_academic_year());
    if ($current !== '' && !in_array($current, $years, true)) $years[] = $current;
    return array_values(array_unique($years));
}
function drm_current_month() {
    $months = [1=>'فروردین',2=>'اردیبهشت',3=>'خرداد',4=>'تیر',5=>'مرداد',6=>'شهریور',7=>'مهر',8=>'آبان',9=>'آذر',10=>'دی',11=>'بهمن',12=>'اسفند'];
    return $months[(int)(function_exists('jdate') ? jdate('n') : 7)] ?? 'مهر';
}
function drm_class_options($year) {
    $out = [];
    foreach (get_unified_class_options($year) as $r) {
        $name = trim((string)($r['class_name'] ?? ''));
        if ($name !== '') $out[norm_class_str($name)] = ['name'=>$name,'grade'=>trim((string)($r['grade_level'] ?? ''))];
    }
    return $out;
}
function drm_visible_students($year, $grade, $class) {
    $where = ["s.status='active'", "(s.academic_year=? OR s.academic_year IS NULL OR s.academic_year='' OR EXISTS (SELECT 1 FROM reports yr WHERE yr.student_id=s.id AND yr.academic_year=?))"];
    $params = [$year, $year];
    if ($grade !== '') { $where[] = 's.grade_level=?'; $params[] = $grade; }
    if ($class !== '') {
        $where[] = '(' . drm_class_sql('s.class_name') . ' OR EXISTS (SELECT 1 FROM reports cr WHERE cr.student_id=s.id AND cr.academic_year=? AND cr.report_month=? AND ' . drm_class_sql('cr.class_name') . '))';
        $params[] = norm_class_str($class); $params[] = $year; $params[] = (string)($_GET['month'] ?? $_POST['month'] ?? ''); $params[] = norm_class_str($class);
    }
    $rows = DB::fetchAll('SELECT DISTINCT s.id,s.first_name,s.last_name,s.class_name,s.grade_level FROM students s WHERE '.implode(' AND ',$where).' ORDER BY s.last_name,s.first_name,s.id',$params);
    if (function_exists('persian_usort_students')) persian_usort_students($rows);
    return $rows;
}

$years = drm_year_options();
$year = unify_academic_year(trim((string)($_GET['year'] ?? $_POST['year'] ?? get_current_academic_year())));
if ($year === '' && $years) $year = $years[0];
if ($year !== '' && !in_array($year, $years, true)) $years[] = $year;
$months = drm_months();
$month = trim((string)($_GET['month'] ?? $_POST['month'] ?? drm_current_month()));
if (!in_array($month, $months, true)) $months[] = $month;
$grade = trim((string)($_GET['grade'] ?? $_POST['grade'] ?? ''));
$class = trim((string)($_GET['class'] ?? $_POST['class'] ?? ''));
$embedded = (($_GET['embedded'] ?? $_POST['embedded'] ?? '') === '1');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_discipline_scores'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'اعتبار فرم منقضی شده است؛ دوباره تلاش کنید.');
    } else {
        $students = drm_visible_students($year, $grade, $class);
        $scoreInputs = is_array($_POST['scores'] ?? null) ? $_POST['scores'] : [];
        $pdo = DB::getInstance()->getPdo();
        $saved = 0; $locked = 0;
        try {
            $pdo->beginTransaction();
            foreach ($students as $student) {
                $sid = (int)$student['id'];
                if (!array_key_exists((string)$sid, $scoreInputs) && !array_key_exists($sid, $scoreInputs)) continue;
                $score = drm_score($scoreInputs[$sid] ?? $scoreInputs[(string)$sid] ?? '');
                if ($score === false) throw new RuntimeException('نمرهٔ انضباط باید عددی بین ۰ تا ۲۱ باشد.');
                $reportClass = $class !== '' ? $class : trim((string)$student['class_name']);
                if ($reportClass !== '') {
                    $report = DB::fetch('SELECT id,is_locked FROM reports WHERE student_id=? AND academic_year=? AND report_month=? AND ' . drm_class_sql('class_name') . ' ORDER BY id DESC LIMIT 1', [$sid,$year,$month,norm_class_str($reportClass)]);
                } else {
                    $report = DB::fetch('SELECT id,is_locked FROM reports WHERE student_id=? AND academic_year=? AND report_month=? ORDER BY id DESC LIMIT 1', [$sid,$year,$month]);
                }
                if ($report && (int)$report['is_locked'] === 1) { $locked++; continue; }
                if (!$report) {
                    DB::execute('INSERT INTO reports (student_id,class_name,academic_year,term,report_month,total_score,gpa) VALUES (?,?,?,?,?,0,0)', [$sid,$reportClass,$year,drm_term_for_month($month),$month]);
                    $report = ['id'=>(int)DB::lastInsertId()];
                }
                DB::execute('UPDATE reports SET discipline_score=? WHERE id=?', [$score,(int)$report['id']]);
                $saved++;
            }
            $pdo->commit();
            log_activity($_SESSION['admin_id'] ?? null, 'ثبت انضباط کارنامه', "سال {$year} / {$month} — ذخیره {$saved}، قفل‌شده {$locked}");
            set_flash_message('success', 'نمرات انضباط ذخیره شد؛ ' . tr_num($saved,'fa') . ' دانش‌آموز ثبت شد.' . ($locked ? ' ' . tr_num($locked,'fa') . ' کارنامهٔ قفل‌شده تغییر نکرد.' : ''));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_flash_message('error', $e instanceof RuntimeException ? $e->getMessage() : 'ذخیرهٔ گروهی انجام نشد؛ هیچ تغییری ثبت نشد.');
        }
    }
    $redirect = ['embedded'=>$embedded?'1':null,'year'=>$year,'month'=>$month,'grade'=>$grade,'class'=>$class];
    $redirect = array_filter($redirect, static function($v){ return $v !== null; });
    redirect('discipline-report-management.php?' . http_build_query($redirect));
}

$classes = drm_class_options($year);
$grades = [];
foreach ($classes as $row) if ($row['grade'] !== '') $grades[$row['grade']] = $row['grade'];
$studentRows = drm_visible_students($year,$grade,$class);
$ids = array_map(static function($s){ return (int)$s['id']; }, $studentRows);
$reportMap = []; $disciplineMap = [];
if ($ids) {
    $marks = implode(',', array_fill(0,count($ids),'?'));
    $studentClassMap = [];
    foreach ($studentRows as $student) $studentClassMap[(int)$student['id']] = $class !== '' ? $class : trim((string)$student['class_name']);
    foreach (DB::fetchAll("SELECT id,student_id,class_name,discipline_score,is_locked FROM reports WHERE academic_year=? AND report_month=? AND student_id IN ($marks) ORDER BY id ASC", array_merge([$year,$month],$ids)) as $r) {
        $wantedClass = $studentClassMap[(int)$r['student_id']] ?? '';
        if ($wantedClass !== '' && norm_class_str((string)$r['class_name']) !== norm_class_str($wantedClass)) continue;
        $reportMap[(int)$r['student_id']] = $r;
    }
    foreach (DB::fetchAll("SELECT student_id,title_text,internal_note,occurred_at_jalali FROM student_discipline_records WHERE student_id IN ($marks) ORDER BY occurred_at_jalali DESC,id DESC",$ids) as $r) $disciplineMap[(int)$r['student_id']][] = $r;
}
require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-4 drm-wrap">
    <h2 class="font-bold text-lg">مدیریت انضباطی کارنامه</h2>
    <p class="text-xs text-muted">برای هر دانش‌آموز، موارد انضباطی ثبت‌شده و زمان گذشته از ثبت نمایش داده می‌شود. نمرهٔ انضباط در کارنامهٔ ماه انتخاب‌شده ذخیره خواهد شد.</p>
    <form method="GET" class="card grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
        <?php if($embedded): ?><input type="hidden" name="embedded" value="1"><?php endif; ?>
        <label>سال تحصیلی<select name="year" class="form-select" required><?php foreach($years as $y): ?><option value="<?php echo clean($y); ?>" <?php echo $year===$y?'selected':''; ?>><?php echo clean($y); ?></option><?php endforeach; ?></select></label>
        <label>ماه<select name="month" class="form-select" required><?php foreach($months as $m): ?><option value="<?php echo clean($m); ?>" <?php echo $month===$m?'selected':''; ?>><?php echo clean($m); ?></option><?php endforeach; ?></select></label>
        <label>پایه<select name="grade" class="form-select"><option value="">همهٔ پایه‌ها</option><?php foreach($grades as $g): ?><option value="<?php echo clean($g); ?>" <?php echo $grade===$g?'selected':''; ?>><?php echo clean($g); ?></option><?php endforeach; ?></select></label>
        <label>کلاس<select name="class" class="form-select"><option value="">همهٔ کلاس‌ها</option><?php foreach($classes as $key=>$row): if($grade!=='' && $row['grade']!==$grade)continue; ?><option value="<?php echo clean($row['name']); ?>" <?php echo norm_class_str($class)===$key?'selected':''; ?>><?php echo clean($row['name']); ?></option><?php endforeach; ?></select></label>
        <button class="btn btn-primary">اعمال فیلترها</button>
    </form>
    <form method="POST" action="discipline-report-management.php<?php echo $embedded?'?embedded=1':''; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="save_discipline_scores" value="1">
        <input type="hidden" name="embedded" value="<?php echo $embedded?'1':'0'; ?>"><input type="hidden" name="year" value="<?php echo clean($year); ?>"><input type="hidden" name="month" value="<?php echo clean($month); ?>"><input type="hidden" name="grade" value="<?php echo clean($grade); ?>"><input type="hidden" name="class" value="<?php echo clean($class); ?>">
        <div class="card drm-repeat-card">
            <label for="drmRepeatScore" class="font-bold">تکرار نمره برای همهٔ دانش‌آموزان</label>
            <input id="drmRepeatScore" class="form-input drm-repeat-score" type="number" min="0" max="21" step="0.25" placeholder="یک نمره وارد کنید">
            <small class="text-muted">این فیلد فقط نمره را در ورودی‌های دانش‌آموزانِ قابل‌ویرایش قرار می‌دهد و خودش ذخیره نمی‌شود؛ سپس می‌توانید نمرهٔ هر دانش‌آموز را جداگانه تغییر دهید.</small>
        </div>
        <div class="card table-container"><table id="disciplineReportTable"><thead><tr><th>دانش‌آموز</th><th>موارد انضباطی</th><th>نمرهٔ انضباط</th></tr></thead><tbody>
        <?php foreach($studentRows as $s): $sid=(int)$s['id'];$rep=$reportMap[$sid]??null; ?>
        <tr data-student-id="<?php echo $sid; ?>"><td><b class="drm-student-name"><?php echo clean(trim($s['first_name'].' '.$s['last_name'])); ?></b><span class="drm-student-meta"><span>پایه: <?php echo clean($s['grade_level']??'—'); ?></span><span>کلاس: <?php echo clean($s['class_name']??'—'); ?></span></span></td><td class="drm-items">
            <?php if(!empty($disciplineMap[$sid])): foreach($disciplineMap[$sid] as $item): $internalNote=trim((string)($item['internal_note']??'')); $attendanceEntry=drm_is_attendance_entry($internalNote); ?><div class="drm-item"><small class="text-muted"><?php echo clean(drm_age_label($item['occurred_at_jalali'])); ?></small> — <span><?php echo clean($item['title_text']); ?></span><?php if($internalNote!==''&&!$attendanceEntry): ?> — <span class="drm-internal-note"><?php echo nl2br(clean($internalNote)); ?></span><?php endif; ?></div><?php endforeach; else: ?><span class="text-muted">موردی ثبت نشده است.</span><?php endif; ?>
        </td><td><input class="form-input drm-score" type="number" min="0" max="21" step="0.25" name="scores[<?php echo $sid; ?>]" value="<?php echo clean($rep['discipline_score']??''); ?>" placeholder="—" <?php echo !empty($rep['is_locked'])?'disabled title="کارنامه قفل است"':''; ?>></td></tr>
        <?php endforeach; if(!$studentRows): ?><tr><td colspan="3" class="text-center text-muted">دانش‌آموزی با این فیلترها یافت نشد.</td></tr><?php endif; ?>
        </tbody></table></div>
        <div class="flex justify-end mt-3"><button class="btn btn-success px-8" type="submit">ثبت</button></div>
    </form>
</div>
<style>.drm-wrap .drm-items{min-width:300px;line-height:1.9}.drm-wrap .drm-item{padding:2px 0;border-bottom:1px dashed #e2e8f0}.drm-wrap .drm-item:last-child{border-bottom:0}.drm-wrap .drm-score{max-width:130px}.drm-wrap .drm-repeat-card{display:grid;gap:8px;margin-bottom:12px}.drm-wrap .drm-repeat-score{max-width:180px}.drm-wrap .drm-student-meta{display:flex;flex-wrap:wrap;gap:2px 12px;margin-top:6px;padding-inline-start:4px;line-height:1.8;color:#64748b;font-size:.85em}</style>
<script defer src="assets/js/discipline-report-management.js?v=4.194.0"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
