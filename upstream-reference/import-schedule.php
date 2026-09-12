<?php
/**
 * Weekly Schedule Importer (import-schedule.php)
 * Parses barname.csv (180+ schedule items across Saturday to Thursday), maps subjects/classes to teachers!
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/academic_year_helpers.php';
require_once __DIR__ . '/includes/class_schedule_sync.php';
require_permission('manage_classes');

$step = $_GET['step'] ?? 1;

function parseScheduleCsv($filepath) {
    if (!file_exists($filepath)) return [];
    
    $lines = [];
    if (($handle = fopen($filepath, "r")) !== false) {
        while (($data = fgetcsv($handle)) !== false) {
            $lines[] = $data;
        }
        fclose($handle);
    }

    $schedules = [];
    $totalLines = count($lines);

    $day_map = [
        'شنبه' => [27, 26, 25, 24],
        'یک‌شنبه' => [22, 21, 20, 19],
        'دوشنبه' => [17, 16, 15, 14],
        'سه‌شنبه' => [12, 11, 10],
        'چهارشنبه' => [8, 7, 6, 5],
        'پنجشنبه' => [3, 2, 1]
    ];

    for ($i = 3; $i < $totalLines - 1; $i += 2) {
        $subj_row = $lines[$i];
        $teach_row = ($i + 1 < $totalLines) ? $lines[$i + 1] : [];

        $cls_name = '';
        for ($c = count($subj_row) - 1; $c >= 0; $c--) {
            $val = trim($subj_row[$c]);
            if (strpos($val, 'هفتم') !== false || strpos($val, 'هشتم') !== false || strpos($val, 'نهم') !== false) {
                $cls_name = norm_class_str($val);
                break;
            }
        }
        if (empty($cls_name)) continue;

        foreach ($day_map as $dayName => $cols) {
            foreach ($cols as $pIdx => $colIdx) {
                if ($colIdx < count($subj_row)) {
                    $rawSubj = trim($subj_row[$colIdx]);
                    $rawTeach = isset($teach_row[$colIdx]) ? trim($teach_row[$colIdx]) : '';

                    if (!empty($rawSubj) && $rawSubj !== '---') {
                        // Split combined subjects like "املاء2/عربی2"
                        $subjs = preg_split('/[\/\n]+/', $rawSubj);
                        $teachs = preg_split('/[\/\n]+/', $rawTeach);

                        foreach ($subjs as $sIdx => $sName) {
                            $sName = trim($sName);
                            if (empty($sName)) continue;
                            $tName = trim($teachs[$sIdx] ?? $teachs[0] ?? '');

                            $tObj = null;
                            if (!empty($tName)) {
                                $tObj = DB::fetch("SELECT id, full_name FROM teachers WHERE full_name LIKE ? OR full_name LIKE ? LIMIT 1", ["%$tName%", "$tName%"]);
                            }

                            $schedules[] = [
                                'class_name'   => $cls_name,
                                'day_of_week'  => $dayName,
                                'period_num'   => 'زنگ ' . ($pIdx + 1),
                                'subject_name' => $sName,
                                'teacher_name' => $tName,
                                'teacher_id'   => $tObj ? $tObj['id'] : null,
                                'matched_name' => $tObj ? $tObj['full_name'] : 'عدم تطبیق'
                            ];
                        }
                    }
                }
            }
        }
    }
    return $schedules;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_schedule') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'خطای امنیتی CSRF');
        redirect('import-schedule.php');
    }
    if (isset($_FILES['schedule_file']) && $_FILES['schedule_file']['error'] === UPLOAD_ERR_OK) {
        $destFolder = __DIR__ . '/uploads';
        if (!is_dir($destFolder)) mkdir($destFolder, 0777, true);
        
        $filename = 'schedule_' . time() . '.csv';
        move_uploaded_file($_FILES['schedule_file']['tmp_name'], $destFolder . '/' . $filename);

        $_SESSION['import_schedule_file'] = $filename;
        redirect('import-schedule.php?step=2');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'execute_schedule') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'خطای امنیتی CSRF');
        redirect('import-schedule.php');
    }
    $year = unify_academic_year(trim($_POST['academic_year'] ?? '1404/1405'));
    $filepath = __DIR__ . '/uploads/' . ($_SESSION['import_schedule_file'] ?? '');

    if (!file_exists($filepath)) {
        set_flash_message('error', 'فایلی یافت نشد.');
        redirect('import-schedule.php');
    }

    $allParsed = parseScheduleCsv($filepath);
    DB::execute("DELETE FROM class_schedules WHERE academic_year = ?", [$year]);
    $count = 0;

    foreach ($allParsed as $sc) {
        DB::execute("INSERT INTO class_schedules (academic_year, class_name, day_of_week, period_num, subject_name, teacher_name, teacher_id) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$year, $sc['class_name'], $sc['day_of_week'], $sc['period_num'], $sc['subject_name'], $sc['teacher_name'], $sc['teacher_id']]);
        $count++;
    }

    // Keep Classes and Subjects tabs consistent with the newly imported weekly schedule.
    $sync = sync_schedule_to_classes_subjects($year);

    set_flash_message('success', "برنامه هفتگی با موفقیت ثبت شد: $count زنگ کلاسی تخصیص یافت. همگام‌سازی: {$sync['classes']} کلاس و {$sync['subjects']} درس جدید ایجاد شد.");
    redirect('import-schedule.php?step=3&count=' . $count . '&classes=' . $sync['classes'] . '&subjects=' . $sync['subjects']);
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold">📅 ایمپورت و تایید برنامه هفتگی دبیران (barname.csv)</h2>
            <p class="text-sm text-muted">تخصیص خودکار ۱۸۰+ زنگ کلاسی از شنبه تا پنجشنبه به دبیران جهت دسترسی ثبت نمره</p>
        </div>
        <a href="barname.csv" download class="btn btn-outline text-xs border-blue-500 text-blue-600">📥 دانلود نمونه فایل (barname.csv)</a>
    </div>

    <div class="wizard-steps card">
        <div class="wizard-step <?php echo $step >= 1 ? 'active' : ''; ?>">۱. آپلود برنامه هفتگی</div>
        <div class="wizard-step <?php echo $step >= 2 ? 'active' : ''; ?>">۲. بازبینی و تایید تخصیص دبیران</div>
        <div class="wizard-step <?php echo $step >= 3 ? 'completed' : ''; ?>">۳. نتیجه ثبت</div>
    </div>

    <?php if ($step == 1): ?>
    <div class="card max-w-xl mx-auto shadow-lg p-6">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="action" value="upload_schedule">
            <div class="mb-4">
                <label class="block text-xs font-bold mb-2">فایل برنامه هفتگی (CSV)</label>
                <input type="file" name="schedule_file" class="form-input p-2" accept=".csv" required>
            </div>
            <button type="submit" class="btn btn-primary w-full py-3 font-bold text-sm">آپلود و تحلیل تخصیص دروس &larr;</button>
        </div>

    <?php elseif ($step == 2):
        $parsed = parseScheduleCsv(__DIR__ . '/uploads/' . ($_SESSION['import_schedule_file'] ?? ''));
    ?>
    <form method="POST" action="import-schedule.php?step=2">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="action" value="execute_schedule">
        <div class="card mb-4 flex justify-between items-center bg-blue-50 dark:bg-slate-800 p-4 border-2 border-indigo-100">
            <div>
                <label class="text-xs font-bold mr-2">📅 سال تحصیلی برنامه هفتگی (انتخابی - فرمت جامع):</label>
                <select name="academic_year" class="form-select w-60 inline-block font-bold">
                    <?php foreach(get_academic_years_for_filter() as $yy): ?>
                        <option value="<?php echo clean($yy['academic_year']); ?>" <?php echo get_current_academic_year()===$yy['academic_year']?'selected':''; ?>><?php echo clean($yy['academic_year']); ?><?php echo !empty($yy['is_default'])?' - پیش‌فرض':''; ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="text-[11px] text-muted mr-2">تمام برنامه در سال انتخابی ذخیره می‌شود</span>
            </div>
            <button type="submit" class="btn btn-success px-6 py-3 font-bold shadow">✅ تایید نهایی و ثبت برنامه در پایگاه داده &larr;</button>
        </div>
        <div class="card">
            <div class="table-container max-h-[600px]">
                <table>
                    <thead>
                        <tr>
                            <th>کلاس</th>
                            <th>روز هفته</th>
                            <th>زنگ</th>
                            <th>نام درس</th>
                            <th>نام دبیر در فایل</th>
                            <th>تطبیق با پرونده دبیر در سیستم</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($parsed as $sc): ?>
                        <tr>
                            <td class="font-bold text-primary"><?php echo clean($sc['class_name']); ?></td>
                            <td><?php echo clean($sc['day_of_week']); ?></td>
                            <td><span class="badge badge-info"><?php echo clean($sc['period_num']); ?></span></td>
                            <td class="font-bold"><?php echo clean($sc['subject_name']); ?></td>
                            <td><?php echo clean($sc['teacher_name']); ?></td>
                            <td>
                                <?php if ($sc['teacher_id']): ?>
                                    <span class="badge bg-green-600 text-white text-xs">تطبیق شد: <?php echo clean($sc['matched_name']); ?> ✔</span>
                                <?php else: ?>
                                    <span class="badge bg-amber-500 text-white text-xs">ثبت عمومی (دبیر تخصیص نیافت)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </form>
    <?php elseif ($step == 3): ?>
    <div class="card text-center py-8">
        <div class="text-5xl mb-4">🎉</div>
        <h2 class="text-2xl font-bold text-green-600 mb-2">برنامه هفتگی با موفقیت ثبت شد!</h2>
        <p class="text-sm text-muted mb-2">تعداد <b><?php echo tr_num($_GET['count'] ?? 0, 'fa'); ?></b> زنگ کلاسی در پایگاه داده ثبت گردید.</p>
        <p class="text-sm text-muted mb-4">همگام‌سازی خودکار: <b><?php echo tr_num($_GET['classes'] ?? 0, 'fa'); ?></b> کلاس و <b><?php echo tr_num($_GET['subjects'] ?? 0, 'fa'); ?></b> درس به تب‌های کلاس‌ها/دروس اضافه شد.</p>
        <a href="classes.php?tab=classes" class="btn btn-primary px-6 mt-4">مشاهده کلاس‌ها و دروس هماهنگ‌شده</a>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
