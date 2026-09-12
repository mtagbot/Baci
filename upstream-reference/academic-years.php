<?php
// File: academic-years.php - v4.28.19 with cascade delete for academic year
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/academic_year_helpers.php';
require_permission('manage_classes');

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if(!verify_csrf($_POST['csrf_token']??'')){set_flash_message('error','خطای امنیتی CSRF');redirect('academic-years.php');}
    DB::execute("CREATE TABLE IF NOT EXISTS academic_years (id int(11) NOT NULL AUTO_INCREMENT, year_name varchar(20) NOT NULL, is_default tinyint(1) NOT NULL DEFAULT 0, status tinyint(1) NOT NULL DEFAULT 1, created_at datetime DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id), UNIQUE KEY uniq_year (year_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if(isset($_POST['save_year'])){
        $id=(int)($_POST['id']??0);
        $name=unify_academic_year(trim($_POST['year_name']??''));
        $st=(int)($_POST['status']??1);
        if($name){
            if($id) DB::execute('UPDATE academic_years SET year_name=?,status=? WHERE id=?',[$name,$st,$id]);
            else DB::execute('INSERT IGNORE INTO academic_years (year_name,status) VALUES (?,?)',[$name,$st]);
            set_flash_message('success', "سال تحصیلی $name ثبت شد (فرمت جامع)");
        }
        redirect('academic-years.php');
    }

    if(isset($_POST['default_year'])){
        DB::execute('UPDATE academic_years SET is_default=0');
        DB::execute('UPDATE academic_years SET is_default=1 WHERE id=?',[(int)$_POST['id']]);
        $r=DB::fetch('SELECT year_name FROM academic_years WHERE id=?',[(int)$_POST['id']]);
        if($r){
            $unified = unify_academic_year($r['year_name']);
            set_setting('current_academic_year', $unified ?: $r['year_name']);
            set_flash_message('success', "سال پیش‌فرض به $unified تغییر کرد");
        }
        redirect('academic-years.php');
    }

    if(isset($_POST['delete_year'])){
        // Simple delete - only academic_years table, not cascade
        $id = (int)$_POST['id'];
        $row = DB::fetch("SELECT year_name, is_default FROM academic_years WHERE id=?", [$id]);
        if($row){
            if(!empty($row['is_default'])){
                // If trying to delete default year, first unset default and clear setting
                DB::execute('UPDATE academic_years SET is_default=0 WHERE id=?', [$id]);
                $current = get_setting('current_academic_year','');
                if($current === $row['year_name']){
                    set_setting('current_academic_year','');
                }
                set_flash_message('warning', "سال پیش‌فرض {$row['year_name']} از حالت پیش‌فرض خارج شد و سپس حذف شد (فقط از لیست سال‌ها)");
            }
            DB::execute('DELETE FROM academic_years WHERE id=?',[$id]);
            set_flash_message('success', "سال تحصیلی {$row['year_name']} از لیست حذف شد (بدون حذف داده‌های وابسته)");
        }
        redirect('academic-years.php');
    }

    if(isset($_POST['delete_year_cascade'])){
        // Cascade delete - delete year and ALL its related data: students, reports, classes, schedules, teachers, exams, online exams, etc.
        $id = (int)$_POST['id'];
        $row = DB::fetch("SELECT year_name FROM academic_years WHERE id=?", [$id]);
        if(!$row){
            set_flash_message('error','سال یافت نشد');
            redirect('academic-years.php');
        }
        $yearRaw = $row['year_name'];
        $yearUnified = unify_academic_year($yearRaw);
        $yearDash = str_replace('/', '-', $yearUnified ?: $yearRaw);
        $yearSlash = str_replace('-', '/', $yearUnified ?: $yearRaw);
        $yearVariants = array_unique([$yearRaw, $yearUnified, $yearDash, $yearSlash, str_replace('/', '-', $yearRaw), str_replace('-', '/', $yearRaw)]);
        $yearVariants = array_filter($yearVariants, fn($v)=>$v!=='');
        
        $deletedCounts = [];
        // First, get student IDs that will be deleted for this year (to clean their related records)
        $studentIdsToDelete = [];
        try {
            $ph = implode(',', array_fill(0, count($yearVariants), '?'));
            $studentsToDel = DB::fetchAll("SELECT id FROM students WHERE academic_year IN ($ph)", $yearVariants);
            $studentIdsToDelete = array_column($studentsToDel, 'id');
        } catch (Exception $e) {}

        // List of tables with academic_year column to delete
        $tablesToClean = [
            'students' => 'academic_year',
            'classes' => 'academic_year',
            'class_schedules' => 'academic_year',
            'exam_schedules' => 'academic_year',
            'exam_student_seating' => 'academic_year',
            'online_exams' => 'academic_year',
            'online_question_categories' => 'academic_year',
            'teachers' => 'academic_year',
            'grade_entry_permissions' => 'academic_year',
            'report_locks' => 'academic_year',
            'exam_question_bank' => 'academic_year',
        ];

        // For reports, we need to delete report_grades first, then reports
        try {
            $placeholders = implode(',', array_fill(0, count($yearVariants), '?'));
            $reports = DB::fetchAll("SELECT id FROM reports WHERE academic_year IN ($placeholders)", $yearVariants);
            $reportIds = array_column($reports, 'id');
            if(!empty($reportIds)){
                $ph2 = implode(',', array_fill(0, count($reportIds), '?'));
                DB::execute("DELETE FROM report_grades WHERE report_id IN ($ph2)", $reportIds);
                $deletedCounts['report_grades'] = count($reportIds);
                DB::execute("DELETE FROM reports WHERE id IN ($ph2)", $reportIds);
                $deletedCounts['reports'] = count($reportIds);
            }
        } catch (Exception $e) { error_log('Cascade delete reports failed: '.$e->getMessage()); }

        // Delete discipline records for students of this year
        if (!empty($studentIdsToDelete)) {
            try {
                $ph = implode(',', array_fill(0, count($studentIdsToDelete), '?'));
                DB::execute("DELETE FROM student_discipline_records WHERE student_id IN ($ph)", $studentIdsToDelete);
                $deletedCounts['discipline_records'] = count($studentIdsToDelete);
                DB::execute("DELETE FROM bale_bot_users WHERE student_id IN ($ph)", $studentIdsToDelete);
                DB::execute("DELETE FROM telegram_bot_users WHERE student_id IN ($ph)", $studentIdsToDelete);
                DB::execute("DELETE FROM grade_messages WHERE student_id IN ($ph)", $studentIdsToDelete);
                DB::execute("DELETE FROM counseling_requests WHERE student_id IN ($ph)", $studentIdsToDelete);
            } catch (Exception $e) { error_log('Cascade delete student related failed: '.$e->getMessage()); }
        }

        // For online exams, delete questions, attempts, answers, etc.
        try {
            $onlineExams = DB::fetchAll("SELECT id FROM online_exams WHERE academic_year IN ($placeholders)", $yearVariants);
            $oeIds = array_column($onlineExams, 'id');
            if(!empty($oeIds)){
                $ph = implode(',', array_fill(0, count($oeIds), '?'));
                // Delete questions
                DB::execute("DELETE FROM online_questions WHERE exam_id IN ($ph)", $oeIds);
                // Delete attempts and related
                $attempts = DB::fetchAll("SELECT id FROM online_exam_attempts WHERE exam_id IN ($ph)", $oeIds);
                $attIds = array_column($attempts, 'id');
                if(!empty($attIds)){
                    $phAtt = implode(',', array_fill(0, count($attIds), '?'));
                    DB::execute("DELETE FROM online_exam_answers WHERE attempt_id IN ($phAtt)", $attIds);
                    DB::execute("DELETE FROM online_exam_proctoring_logs WHERE attempt_id IN ($phAtt)", $attIds);
                    DB::execute("DELETE FROM online_exam_live_sessions WHERE attempt_id IN ($phAtt)", $attIds);
                    DB::execute("DELETE FROM online_exam_webcam_requests WHERE attempt_id IN ($phAtt)", $attIds);
                    DB::execute("DELETE FROM online_exam_webcam_snapshots WHERE attempt_id IN ($phAtt)", $attIds);
                    DB::execute("DELETE FROM online_exam_attempts WHERE id IN ($phAtt)", $attIds);
                    $deletedCounts['online_attempts'] = count($attIds);
                }
                DB::execute("DELETE FROM online_exams WHERE id IN ($ph)", $oeIds);
                $deletedCounts['online_exams'] = count($oeIds);
            }
        } catch (Exception $e) { error_log('Cascade delete online exams failed: '.$e->getMessage()); }

        // Delete from other tables with academic_year
        foreach ($tablesToClean as $table=>$col) {
            if ($table==='reports' || $table==='online_exams') continue; // already handled
            try {
                $ph = implode(',', array_fill(0, count($yearVariants), '?'));
                $before = DB::fetch("SELECT COUNT(*) c FROM `$table` WHERE `$col` IN ($ph)", $yearVariants);
                DB::execute("DELETE FROM `$table` WHERE `$col` IN ($ph)", $yearVariants);
                $deletedCounts[$table] = $before['c'] ?? 0;
            } catch (Exception $e) {
                // Table may not exist
            }
        }

        // Finally delete the academic_years entry itself
        try {
            DB::execute('DELETE FROM academic_years WHERE id=?', [$id]);
            // If this was default year, clear setting
            $current = get_setting('current_academic_year','');
            if (in_array($current, $yearVariants)) {
                set_setting('current_academic_year','');
            }
        } catch (Exception $e) {}

        $msg = "سال تحصیلی $yearUnified (".implode('، ', $yearVariants).") به صورت کامل با تمام زیرمجموعه‌ها حذف شد:\n";
        foreach ($deletedCounts as $tbl=>$cnt) {
            $msg .= "- $tbl: $cnt رکورد\n";
        }
        set_flash_message('success', $msg);
        redirect('academic-years.php');
    }
}

DB::execute("CREATE TABLE IF NOT EXISTS academic_years (id int(11) NOT NULL AUTO_INCREMENT, year_name varchar(20) NOT NULL, is_default tinyint(1) NOT NULL DEFAULT 0, status tinyint(1) NOT NULL DEFAULT 1, created_at datetime DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id), UNIQUE KEY uniq_year (year_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Seed only if academic_years table is empty - to avoid re-inserting deleted years
$existingCount = DB::fetch("SELECT COUNT(*) c FROM academic_years");
if (($existingCount['c'] ?? 0) == 0) {
    $seed=DB::fetchAll("SELECT academic_year FROM reports WHERE academic_year<>'' UNION SELECT academic_year FROM classes WHERE academic_year<>'' UNION SELECT academic_year FROM class_schedules WHERE academic_year<>'' UNION SELECT academic_year FROM students WHERE academic_year<>''");
    foreach($seed as $s) {
        $uy = unify_academic_year($s['academic_year']);
        if($uy) DB::execute('INSERT IGNORE INTO academic_years (year_name,status) VALUES (?,1)',[$uy]);
    }
    // If still empty, insert current default
    $existingCount2 = DB::fetch("SELECT COUNT(*) c FROM academic_years");
    if (($existingCount2['c'] ?? 0) == 0) {
        $def = get_current_academic_year();
        if ($def) DB::execute('INSERT IGNORE INTO academic_years (year_name,status,is_default) VALUES (?,1,1)',[$def]);
    }
}

require_once __DIR__ . '/includes/header.php';
$years=DB::fetchAll('SELECT * FROM academic_years ORDER BY year_name DESC');
?>
<div class="space-y-4">
    <div class="card">
        <h3 class="font-bold mb-2">ثبت سال تحصیلی جدید (فرمت جامع: 1405/1406)</h3>
        <form method="POST" class="grid grid-cols-4 gap-3 items-end">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="save_year" value="1">
            <div><label class="text-xs">سال تحصیلی (هر فرمتی: 1405-1406 یا 1405/1406 یا 1405)</label><input name="year_name" class="form-input font-bold" placeholder="1405/1406" required></div>
            <div><label class="text-xs">وضعیت</label><select name="status" class="form-select"><option value="1">فعال</option><option value="0">غیرفعال</option></select></div>
            <button class="btn btn-success">ثبت سال با فرمت جامع</button>
        </form>
        <p class="text-[11px] text-muted mt-2">هر فرمتی وارد کنید (1405-1406، 1405 / 1406، 1405) خودکار به فرمت جامع 1405/1406 تبدیل و ذخیره می‌شود. برای نرمال‌سازی همه داده‌های قدیمی: <a href="migration-academic-year-normalizer.php" class="text-primary">اجرای مایگریشن جامع</a></p>
    </div>

    <div class="card">
        <h3 class="font-bold mb-3">لیست سال‌های تحصیلی (فرمت جامع)</h3>
        <div class="table-container">
            <table>
                <thead><tr><th>سال (فرمت جامع)</th><th>پیش‌فرض سیستم</th><th>وضعیت</th><th>تعداد دانش‌آموز / کلاس / کارنامه</th><th>عملیات</th></tr></thead>
                <tbody>
                <?php foreach($years as $y): 
                    $yn = $y['year_name'];
                    $ynUnified = unify_academic_year($yn);
                    $variants = array_unique([$yn, $ynUnified, str_replace('/', '-', $ynUnified ?: $yn), str_replace('-', '/', $ynUnified ?: $yn), str_replace('/', '-', $yn), str_replace('-', '/', $yn)]);
                    $variants = array_filter($variants, fn($v)=>$v!=='');
                    $ph = implode(',', array_fill(0, count($variants), '?'));
                    // Count students with unified logic: academic_year = year OR (academic_year IS NULL and has reports/classes/schedules in that year) OR academic_year normalized matches
                    try {
                        // First try direct count with normalized variants
                        $cntStudents = DB::fetch("SELECT COUNT(*) c FROM students WHERE academic_year IN ($ph)", $variants)['c'] ?? 0;
                        // Also count students with NULL academic_year but class exists in this year (legacy)
                        $cntStudentsLegacy = DB::fetch("SELECT COUNT(*) c FROM students s WHERE (s.academic_year IS NULL OR s.academic_year='') AND EXISTS(SELECT 1 FROM classes c WHERE c.academic_year IN ($ph) AND c.name=s.class_name)", $variants)['c'] ?? 0;
                        $cntStudents += $cntStudentsLegacy;
                        // If still 0 and this is default year, count all students with empty academic_year as belonging to default (for backward compat)
                        if ($cntStudents==0 && !empty($y['is_default'])) {
                            $cntEmpty = DB::fetch("SELECT COUNT(*) c FROM students WHERE academic_year IS NULL OR academic_year=''")['c'] ?? 0;
                            $cntStudents += $cntEmpty;
                        }
                    } catch(Exception $e){ $cntStudents=0; }
                    try { $cntClasses = DB::fetch("SELECT COUNT(*) c FROM classes WHERE academic_year IN ($ph)", $variants)['c'] ?? 0; } catch(Exception $e){ $cntClasses=0; }
                    try { $cntReports = DB::fetch("SELECT COUNT(*) c FROM reports WHERE academic_year IN ($ph)", $variants)['c'] ?? 0; } catch(Exception $e){ $cntReports=0; }
                    try { $cntTeachers = DB::fetch("SELECT COUNT(*) c FROM teachers WHERE academic_year IN ($ph)", $variants)['c'] ?? 0; } catch(Exception $e){ $cntTeachers=0; }
                    try { $cntExams = DB::fetch("SELECT COUNT(*) c FROM exam_schedules WHERE academic_year IN ($ph)", $variants)['c'] ?? 0; } catch(Exception $e){ $cntExams=0; }
                    try { $cntOnline = DB::fetch("SELECT COUNT(*) c FROM online_exams WHERE academic_year IN ($ph)", $variants)['c'] ?? 0; } catch(Exception $e){ $cntOnline=0; }
                ?>
                    <tr>
                        <td class="font-bold"><?php echo clean($y['year_name']); ?></td>
                        <td><?php echo $y['is_default']?'✅ پیش‌فرض جاری':''; ?></td>
                        <td><?php echo $y['status']?'فعال':'غیرفعال'; ?></td>
                        <td class="text-xs">
                            دانش‌آموز: <?php echo $cntStudents; ?> |
                            کلاس: <?php echo $cntClasses; ?> |
                            کارنامه: <?php echo $cntReports; ?> |
                            دبیر: <?php echo $cntTeachers; ?> |
                            امتحان حضوری: <?php echo $cntExams; ?> |
                            آزمون مجازی: <?php echo $cntOnline; ?>
                        </td>
                        <td>
                            <div class="flex gap-1 flex-wrap">
                                <form method="POST" class="inline"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="default_year" value="1"><input type="hidden" name="id" value="<?php echo $y['id']; ?>"><button class="btn btn-primary text-xs">پیش‌فرض</button></form>
                                <form method="POST" class="inline" onsubmit="return confirm('آیا از حذف این سال از لیست اطمینان دارید؟ (فقط لیست، بدون حذف داده‌ها)');"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="delete_year" value="1"><input type="hidden" name="id" value="<?php echo $y['id']; ?>"><button class="btn btn-secondary text-xs">حذف از لیست</button></form>
                                <form method="POST" class="inline" onsubmit="return confirm('⚠️ هشدار: حذف کامل سال تحصیلی <?php echo clean($y['year_name']); ?> با تمام زیرمجموعه‌ها!\n\nاین عملیات حذف می‌کند:\n- دانش‌آموزان: <?php echo $cntStudents; ?>\n- کلاس‌ها: <?php echo $cntClasses; ?>\n- کارنامه‌ها: <?php echo $cntReports; ?>\n- دبیران: <?php echo $cntTeachers; ?>\n- امتحانات حضوری: <?php echo $cntExams; ?>\n- آزمون‌های مجازی: <?php echo $cntOnline; ?>\n\nقابل بازگشت نیست! آیا مطمئن هستید؟');"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="delete_year_cascade" value="1"><input type="hidden" name="id" value="<?php echo $y['id']; ?>"><button class="btn btn-danger text-xs">🗑️ حذف کامل با تمام داده‌ها</button></form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; if(empty($years)): ?><tr><td colspan="5" class="text-center text-muted">سالی ثبت نشده</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="text-[11px] text-muted mt-2">حذف از لیست: فقط از جدول سال‌ها حذف می‌شود. حذف کامل: تمام دانش‌آموزان، کلاس‌ها، کارنامه‌ها، دبیران، امتحانات حضوری و مجازی آن سال هم حذف می‌شوند.</p>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
