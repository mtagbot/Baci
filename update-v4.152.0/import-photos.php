<?php
/** Restored from Maxess/mtagbot code.zip (15b59c2); permission aligned with the recovery hub, embedded redirects preserved, no public side effects beyond uploads/photos + students.photo_url. */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/academic_year_helpers.php';
require_permission('import_data');

$embedded = (($_GET['embedded'] ?? '') === '1');
function iph_go($query = '') {
    $q = $query . (($GLOBALS['iphEmbedded'] ?? false) ? (($query !== '') ? '&embedded=1' : '?embedded=1') : '');
    redirect('import-photos.php' . ($q !== '' ? '?' . $q : ''));
}
$GLOBALS['iphEmbedded'] = $embedded;

$step = (int)($_GET['step'] ?? 1);
if (!in_array($step, [1, 2], true)) $step = 1;
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_zip'])) {
    if (!is_string($_POST['csrf_token'] ?? null) || !verify_csrf($_POST['csrf_token'])) {
        set_flash_message('error', 'خطای امنیتی CSRF');
        iph_go();
    } else {
        $file = $_FILES['photos_zip'] ?? [];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_file($file['tmp_name'] ?? '')) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext !== 'zip') {
                set_flash_message('error', 'فرمت فایل مجاز نیست. لطفاً یک فایل ZIP بارگذاری کنید.');
                iph_go();
            } elseif (!class_exists('ZipArchive')) {
                set_flash_message('error', 'افزونه ZipArchive روی سرور فعال نیست.');
                iph_go();
            } else {
                $importYear = unify_academic_year(trim($_POST['academic_year'] ?? get_current_academic_year()));
                $photoDir = __DIR__ . '/uploads/photos';
                if (!is_dir($photoDir)) mkdir($photoDir, 0777, true);
                @chmod($photoDir, 0777);
                $zip = new ZipArchive();
                if ($zip->open($file['tmp_name']) === true) {
                    $matchedCount = 0;
                    $unmatchedCount = 0;
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $stat = $zip->statIndex($i);
                        $filename = basename($stat['name']);
                        $fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                        if (!in_array($fileExt, ['jpg', 'jpeg', 'png', 'webp'], true)) continue;
                        $rawBase = tr_num(pathinfo($filename, PATHINFO_FILENAME), 'en');
                        $digitsOnly = preg_replace('/[^0-9]/', '', $rawBase);
                        // Robust matching: 10-digit national ID, or 9-digit files with a leading zero.
                        if (strlen($digitsOnly) === 9) $nid = '0' . $digitsOnly;
                        elseif (strlen($digitsOnly) === 10) $nid = $digitsOnly;
                        else {
                            $unmatchedCount++;
                            $results[] = ['status' => 'skipped', 'nid' => $digitsOnly ?: '---', 'name' => 'نام فایل نامعتبر؛ عبور شد', 'file' => $filename];
                            continue;
                        }
                        // Check if student exists (year-scoped first, then any active year).
                        $st = null;
                        try {
                            if (!empty($importYear)) {
                                $st = DB::fetch("SELECT id, first_name, last_name, national_id FROM students WHERE national_id = ? AND academic_year = ? AND status='active' ORDER BY id DESC LIMIT 1", [$nid, $importYear]);
                                if (!$st) $st = DB::fetch("SELECT id, first_name, last_name, national_id FROM students WHERE national_id = ? AND status='active' ORDER BY academic_year DESC LIMIT 1", [$nid]);
                            } else {
                                $st = DB::fetch("SELECT id, first_name, last_name, national_id FROM students WHERE national_id = ? AND status='active' ORDER BY academic_year DESC LIMIT 1", [$nid]);
                            }
                        } catch (Exception $e) {
                            $st = DB::fetch("SELECT id, first_name, last_name, national_id FROM students WHERE national_id = ?", [$nid]);
                        }
                        if ($st) {
                            $newFilename = $nid . '.' . $fileExt;
                            $written = @file_put_contents($photoDir . '/' . $newFilename, $zip->getFromIndex($i));
                            if ($written !== false) {
                                DB::execute("UPDATE students SET photo_url = ? WHERE id = ?", ['uploads/photos/' . $newFilename, $st['id']]);
                                $matchedCount++;
                                $results[] = ['status' => 'matched', 'nid' => $nid, 'name' => $st['first_name'] . ' ' . $st['last_name'], 'file' => $filename];
                            } else {
                                $unmatchedCount++;
                                $results[] = ['status' => 'unmatched', 'nid' => $nid, 'name' => 'ذخیره تصویر ناموفق (پوشه فقط‌خواندنی است)', 'file' => $filename];
                            }
                        } else {
                            $unmatchedCount++;
                            $results[] = ['status' => 'unmatched', 'nid' => $nid, 'name' => 'نامشخص در سیستم', 'file' => $filename];
                        }
                    }
                    $zip->close();
                    $_SESSION['photo_import_results'] = $results;
                    $_SESSION['photo_summary'] = ['matched' => $matchedCount, 'unmatched' => $unmatchedCount];
                    log_activity($_SESSION['admin_id'] ?? null, 'ایمپورت دسته‌جمعی تصاویر دانش‌آموزان', "تعداد $matchedCount تصویر تطبیق داده شد.");
                    iph_go('step=2');
                } else {
                    set_flash_message('error', 'خطا در باز کردن فایل ZIP.');
                    iph_go();
                }
            }
        } else {
            set_flash_message('error', 'فایلی دریافت نشد؛ فایل ZIP را دوباره انتخاب کنید.');
            iph_go();
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold">آپلود دسته‌جمعی تصاویر دانش‌آموزان (ZIP)</h2>
            <p class="text-sm text-muted">تخصیص خودکار تصاویر ۳×۴ به پرونده دانش‌آموزان بر اساس نام فایل (کد ملی)</p>
        </div>
        <a href="students.php" class="btn btn-secondary text-sm">&larr; بازگشت به مدیریت دانش‌آموزان</a>
    </div>

    <div class="wizard-steps card">
        <div class="wizard-step <?php echo $step >= 1 ? 'active' : ''; ?>">۱. آپلود آرشیو تصاویر (ZIP)</div>
        <div class="wizard-step <?php echo $step >= 2 ? 'completed' : ''; ?>">۲. گزارش تخصیص و تطبیق تصاویر</div>
    </div>

    <?php if ($step == 1): ?>
    <div class="grid grid-cols-2 gap-6">
        <div class="card shadow-lg">
            <h3 class="font-bold mb-4 text-primary">بارگذاری فایل ZIP تصاویر</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="upload_zip" value="1">
                <div class="mb-4 p-3 border-2 border-indigo-100 rounded bg-indigo-50/30">
                    <label class="block text-xs font-bold mb-1">سال تحصیلی تصاویر (انتخابی - فرمت جامع)</label>
                    <select name="academic_year" class="form-select font-bold w-full">
                        <?php foreach (get_academic_years_for_filter() as $yy): ?>
                            <option value="<?php echo clean($yy['academic_year']); ?>" <?php echo get_current_academic_year() === $yy['academic_year'] ? 'selected' : ''; ?>><?php echo clean($yy['academic_year']); ?><?php echo !empty($yy['is_default']) ? ' - پیش‌فرض' : ''; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="text-[10px] text-muted">تصاویر فقط به دانش‌آموزان سال انتخابی تخصیص می‌یابد</span>
                </div>
                <div class="mb-4">
                    <label class="block text-xs font-semibold mb-2">انتخاب فایل آرشیو (.zip)</label>
                    <input type="file" name="photos_zip" class="form-input p-2" accept=".zip" required>
                    <small class="text-xs text-muted block mt-1">حداکثر حجم فایل: ۲۰ مگابایت</small>
                </div>
                <div class="p-4 bg-blue-50 dark:bg-slate-800 rounded-lg border border-blue-200 text-xs mb-4 space-y-2">
                    <p class="font-bold text-blue-800 dark:text-blue-300">دستورالعمل نام‌گذاری تصاویر در فایل ZIP:</p>
                    <p>نام هر فایل عکس داخل پوشه زیپ باید دقیقاً <b>کد ملی ۱۰ رقمی دانش‌آموز</b> باشد. مثال:</p>
                    <ul class="list-disc list-inside font-mono">
                        <li>0153383216.jpg</li>
                        <li>0153201460.png</li>
                        <li>4902282372.jpg</li>
                    </ul>
                </div>
                <button type="submit" class="btn btn-primary w-full py-3 font-bold text-sm shadow">پردازش فایل زیپ و تخصیص تصاویر &larr;</button>
            </form>
        </div>

        <div class="card shadow-lg space-y-4">
            <h3 class="font-bold">مزایای آپلود دسته‌جمعی تصاویر</h3>
            <p class="text-xs text-muted leading-relaxed">سیستم به‌صورت خودکار هر فایل عکس را با جدول دانش‌آموزان تطبیق می‌کند و تصویر را در سمت چپ سربرگ کارنامه‌های تحصیلی و چاپی نمایش می‌دهد. فرمت‌های مجاز تصویر: <code>JPG, JPEG, PNG, WEBP</code>.</p>
        </div>
    </div>

    <?php elseif ($step == 2):
        $results = $_SESSION['photo_import_results'] ?? [];
        $summary = $_SESSION['photo_summary'] ?? ['matched' => 0, 'unmatched' => 0];
    ?>
    <div class="card bg-blue-50 dark:bg-slate-800 border-blue-200 mb-6 flex justify-between items-center shadow">
        <div>
            <h3 class="font-bold text-blue-900 dark:text-blue-300">نتیجه پردازش فایل ZIP تصاویر</h3>
            <p class="text-xs text-blue-800 dark:text-blue-200 mt-1">
                تخصیص موفق: <span class="badge bg-green-600 text-white"><?php echo tr_num($summary['matched'], 'fa'); ?> دانش‌آموز</span> |
                تصاویر بدون تطبیق: <span class="badge bg-red-600 text-white"><?php echo tr_num($summary['unmatched'], 'fa'); ?> مورد</span>
            </p>
        </div>
        <a href="students.php" class="btn btn-primary px-6">مشاهده لیست پرونده‌ها</a>
    </div>

    <div class="card shadow-lg">
        <h4 class="font-bold mb-4">جزئیات تطبیق فایل‌های داخل ZIP:</h4>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>نام فایل عکس در ZIP</th>
                        <th>کد ملی شناسایی‌شده</th>
                        <th>نام دانش‌آموز در سیستم</th>
                        <th>وضعیت تخصیص</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $res): ?>
                    <tr class="<?php echo $res['status'] === 'matched' ? 'bg-green-50 dark:bg-slate-800' : 'bg-red-50 dark:bg-slate-800'; ?>">
                        <td class="font-mono text-xs dir-ltr text-left"><?php echo clean($res['file']); ?></td>
                        <td class="font-mono text-xs font-bold"><?php echo tr_num($res['nid'], 'fa'); ?></td>
                        <td class="font-bold"><?php echo clean($res['name']); ?></td>
                        <td>
                            <?php if ($res['status'] === 'matched'): ?>
                                <span class="badge bg-green-600 text-white text-xs">تخصیص موفق</span>
                            <?php elseif ($res['status'] === 'skipped'): ?>
                                <span class="badge bg-amber-500 text-white text-xs">نامعتبر؛ عبور شد</span>
                            <?php else: ?>
                                <span class="badge bg-red-600 text-white text-xs">دانش‌آموز یافت نشد</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; if (empty($results)): ?>
                    <tr><td colspan="4" class="text-center text-muted">فایلی پردازش نشد.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
