<?php
// File: academic-years.php - v4.40.0 unified year structure (teachers are year-independent)
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
        // v4.38.0 - unified structure: deleting a year ALWAYS removes its whole chain.
        // (The old "delete from list only" behavior left orphan data behind and is removed.)
        $_POST['delete_year_cascade'] = 1;
    }

    if(isset($_POST['delete_year_cascade'])){
        // v4.38.0 - Cascade delete via central helper: year and ALL its chained data
        $id = (int)$_POST['id'];
        $row = DB::fetch("SELECT year_name FROM academic_years WHERE id=?", [$id]);
        if(!$row){
            set_flash_message('error','سال یافت نشد');
            redirect('academic-years.php');
        }
        $yearRaw = $row['year_name'];
        $yearUnified = unify_academic_year($yearRaw) ?: $yearRaw;

        $deletedCounts = cascade_delete_academic_year_data($yearRaw);

        // Delete the academic_years entry itself and self-heal the default year
        try {
            DB::execute('DELETE FROM academic_years WHERE id=?', [$id]);
            $current = get_setting('current_academic_year','');
            $curU = unify_academic_year($current) ?: $current;
            if ($curU === $yearUnified || $current === $yearRaw) {
                set_setting('current_academic_year','');
                // pick default/newest remaining year automatically
                $next = DB::fetch("SELECT year_name FROM academic_years ORDER BY is_default DESC, year_name DESC LIMIT 1");
                if ($next) {
                    $nu = unify_academic_year($next['year_name']) ?: $next['year_name'];
                    set_setting('current_academic_year', $nu);
                    DB::execute('UPDATE academic_years SET is_default=1 WHERE year_name=?', [$next['year_name']]);
                }
            }
        } catch (Exception $e) {}

        $msg = "سال تحصیلی $yearUnified به صورت کامل با تمام زیرمجموعه‌ها حذف شد:\n";
        foreach ($deletedCounts as $tbl=>$cnt) {
            $msg .= "- $tbl: $cnt رکورد\n";
        }
        if (empty($deletedCounts)) $msg .= "- داده وابسته‌ای وجود نداشت\n";
        set_flash_message('success', $msg);
        redirect('academic-years.php');
    }

    if(isset($_POST['cleanup_orphan_year'])){
        // v4.38.0 - purge chained data of a year that no longer exists in the master list
        $oy = trim($_POST['orphan_year'] ?? '');
        if ($oy !== '') {
            $deletedCounts = cascade_delete_academic_year_data($oy);
            $msg = "داده‌های یتیم سال $oy پاکسازی شد:\n";
            foreach ($deletedCounts as $tbl=>$cnt) $msg .= "- $tbl: $cnt رکورد\n";
            if (empty($deletedCounts)) $msg .= "- رکوردی یافت نشد\n";
            set_flash_message('success', $msg);
        }
        redirect('academic-years.php');
    }

    if(isset($_POST['restore_orphan_year'])){
        // v4.38.0 - re-register an orphan year in the master list (adopt its data back)
        $oy = unify_academic_year(trim($_POST['orphan_year'] ?? ''));
        if ($oy !== '') {
            DB::execute('INSERT IGNORE INTO academic_years (year_name,status) VALUES (?,1)', [$oy]);
            set_flash_message('success', "سال $oy به لیست سال‌های تحصیلی بازگردانده شد و داده‌های آن دوباره در تمام فیلترها در دسترس است.");
        }
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
                <thead><tr><th>سال (فرمت جامع)</th><th>پیش‌فرض سیستم</th><th>وضعیت</th><th>تعداد دانش‌آموز / کلاس / کارنامه (دبیران مستقل از سال‌اند)</th><th>عملیات</th></tr></thead>
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
                    $cntTeachers = 0; // v4.40.0 teachers are year-independent
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
                            امتحان حضوری: <?php echo $cntExams; ?> |
                            آزمون مجازی: <?php echo $cntOnline; ?>
                        </td>
                        <td>
                            <div class="flex gap-1 flex-wrap">
                                <form method="POST" class="inline"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="default_year" value="1"><input type="hidden" name="id" value="<?php echo $y['id']; ?>"><button class="btn btn-primary text-xs">پیش‌فرض</button></form>
                                <form method="POST" class="inline" onsubmit="return confirm('⚠️ حذف کامل سال تحصیلی <?php echo clean($y['year_name']); ?> با تمام زیرمجموعه‌ها؟\n\nطبق ساختار یکپارچه سیستم، با حذف سال، تمام داده‌های زنجیرشده به آن نیز حذف می‌شوند:\n- دانش‌آموزان: <?php echo $cntStudents; ?>\n- کلاس‌ها: <?php echo $cntClasses; ?>\n- کارنامه‌ها: <?php echo $cntReports; ?>\n- امتحانات حضوری: <?php echo $cntExams; ?>\n- آزمون‌های مجازی: <?php echo $cntOnline; ?>\n- برنامه هفتگی، صندلی‌بندی، بانک سوال و ...\n\nقابل بازگشت نیست! آیا مطمئن هستید؟');"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="delete_year_cascade" value="1"><input type="hidden" name="id" value="<?php echo $y['id']; ?>"><button class="btn btn-danger text-xs">حذف کامل سال و زیرمجموعه‌ها</button></form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; if(empty($years)): ?><tr><td colspan="5" class="text-center text-muted">سالی ثبت نشده</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="text-[11px] text-muted mt-2">ساختار یکپارچه: سال تحصیلی در راس زنجیره است؛ با حذف یک سال، تمام دانش‌آموزان، کلاس‌ها، برنامه هفتگی، کارنامه‌ها، امتحانات حضوری و آزمون‌های مجازی آن سال نیز حذف می‌شوند (دبیران سراسری‌اند و حذف نمی‌شوند) و آن سال از تمام فیلترهای سیستم ناپدید می‌شود.</p>
    </div>

    <?php $orphanYears = find_orphan_academic_years(); if (!empty($orphanYears)): ?>
    <div class="card" style="border:1px solid #f59e0b;background:linear-gradient(180deg,#fffbeb,#fff)">
        <h3 class="font-bold mb-2" style="color:#b45309">داده‌های بدون سال تحصیلی معتبر (یتیم)</h3>
        <p class="text-xs text-muted mb-3">این داده‌ها به سال‌هایی متصل هستند که دیگر در لیست سال‌های تحصیلی وجود ندارند (مثلاً سال قبلاً فقط «از لیست» حذف شده است). برای یکپارچگی سیستم، یا داده‌ها را کامل پاک کنید یا سال را به لیست بازگردانید.</p>
        <div class="table-container">
            <table>
                <thead><tr><th>سال تحصیلی</th><th>جزئیات رکوردهای باقی‌مانده</th><th>مجموع</th><th>عملیات</th></tr></thead>
                <tbody>
                <?php foreach($orphanYears as $oy=>$info): ?>
                    <tr>
                        <td class="font-bold"><?php echo clean($oy); ?></td>
                        <td class="text-xs">
                            <?php $parts=[]; $faNames=['students'=>'دانش‌آموز','classes'=>'کلاس','class_schedules'=>'برنامه هفتگی','reports'=>'کارنامه','exam_schedules'=>'امتحان حضوری','exam_student_seating'=>'صندلی‌بندی','online_exams'=>'آزمون مجازی','online_question_categories'=>'دسته سوال مجازی','grade_entry_permissions'=>'مجوز ثبت نمره','report_locks'=>'قفل کارنامه','exam_question_bank'=>'بانک سوال'];
                            foreach($info['tables'] as $t=>$c){ $parts[] = ($faNames[$t] ?? $t).': '.tr_num($c,'fa'); }
                            echo clean(implode(' | ', $parts)); ?>
                        </td>
                        <td class="font-bold"><?php echo tr_num($info['total'],'fa'); ?></td>
                        <td>
                            <div class="flex gap-1 flex-wrap">
                                <form method="POST" class="inline" onsubmit="return confirm('⚠️ تمام <?php echo $info['total']; ?> رکورد باقی‌مانده سال <?php echo clean($oy); ?> برای همیشه حذف شود؟ قابل بازگشت نیست!');"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="cleanup_orphan_year" value="1"><input type="hidden" name="orphan_year" value="<?php echo clean($oy); ?>"><button class="btn btn-danger text-xs">پاکسازی کامل داده‌ها</button></form>
                                <form method="POST" class="inline"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="restore_orphan_year" value="1"><input type="hidden" name="orphan_year" value="<?php echo clean($oy); ?>"><button class="btn btn-secondary text-xs">بازگرداندن سال به لیست</button></form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
