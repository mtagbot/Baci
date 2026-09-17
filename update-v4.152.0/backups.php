<?php
/**
 * Database Backup and Restore Manager (backups.php)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/header.php';

require_permission('manage_backups');

$backupDir = __DIR__ . '/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0777, true);
}

$action = $_GET['action'] ?? '';

// Handle Create Backup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_backup'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'خطای امنیتی CSRF رخ داد.');
        redirect('backups.php');
    }
    try {
        $pdo = DB::getInstance()->getPdo();
        $tables = [];
        $q = $pdo->query("SHOW TABLES");
        while ($row = $q->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }

        $sqlDump = "-- Student Report Card System Backup\n-- Generated: " . jdate('Y/m/d H:i:s') . "\n-- Version: " . get_setting('system_version', '2.5.0') . "\n\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

        foreach ($tables as $table) {
            $row2 = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
            $sqlDump .= "-- Table structure for `$table`\n";
            $sqlDump .= "DROP TABLE IF EXISTS `$table`;\n";
            $sqlDump .= $row2[1] . ";\n\n";

            $rows = DB::fetchAll("SELECT * FROM `$table`");
            if (count($rows) > 0) {
                $sqlDump .= "-- Dumping data for `$table`\n";
                foreach ($rows as $r) {
                    $cols = array_keys($r);
                    $vals = array_values($r);
                    $escapedVals = array_map(function($v) use ($pdo) {
                        return $v === null ? 'NULL' : $pdo->quote($v);
                    }, $vals);
                    $sqlDump .= "INSERT INTO `$table` (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $escapedVals) . ");\n";
                }
                $sqlDump .= "\n";
            }
        }
        $sqlDump .= "SET FOREIGN_KEY_CHECKS = 1;\n";

        $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        file_put_contents($backupDir . '/' . $filename, $sqlDump);

        log_activity($_SESSION['admin_id'], 'ایجاد فایل بکاپ', "فایل بکاپ $filename ایجاد شد.");
        set_flash_message('success', "فایل پشتیبان $filename با موفقیت ایجاد شد.");
        redirect('backups.php');
    } catch (Exception $e) {
        set_flash_message('error', 'خطا در پشتیبان‌گیری: ' . $e->getMessage());
        redirect('backups.php');
    }
}

// Handle Delete Backup
if ($action === 'delete' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $filePath = $backupDir . '/' . $file;
    if (file_exists($filePath)) {
        unlink($filePath);
        log_activity($_SESSION['admin_id'], 'حذف فایل بکاپ', "فایل بکاپ $file حذف شد.");
        set_flash_message('success', 'فایل پشتیبان حذف شد.');
    }
    redirect('backups.php');
}

// Handle Download Backup
if ($action === 'download' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $filePath = $backupDir . '/' . $file;
    if (file_exists($filePath)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename=' . $file);
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
    redirect('backups.php');
}

// Handle Restore Backup (Full or Selective)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_backup'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'خطای امنیتی CSRF');
        redirect('backups.php');
    }
    $file = basename($_POST['backup_file'] ?? '');
    $filePath = $backupDir . '/' . $file;
    $selectedTables = $_POST['restore_tables'] ?? ['all'];

    if (file_exists($filePath)) {
        try {
            $pdo = DB::getInstance()->getPdo();
            $sqlContent = file_get_contents($filePath);

            if (in_array('all', $selectedTables)) {
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
                $statements = array_filter(array_map('trim', explode(';', $sqlContent)));
                foreach ($statements as $stmt) {
                    if (!empty($stmt)) {
                        $pdo->exec($stmt);
                    }
                }
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
                log_activity($_SESSION['admin_id'], 'بازیابی کامل دیتابیس', "دیتابیس از بکاپ $file بازیابی شد.");
                set_flash_message('success', 'پایگاه داده به‌صورت کامل بازیابی شد.');
            } else {
                // Parse specific tables from SQL dump
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
                foreach ($selectedTables as $table) {
                    // Extract drop and create and insert for this table
                    if (preg_match("/DROP TABLE IF EXISTS `$table`;[^;]+;/i", $sqlContent, $m)) {
                        $pdo->exec($m[0]);
                    }
                    preg_match_all("/INSERT INTO `$table` [^\n;]+;/i", $sqlContent, $matches);
                    if (!empty($matches[0])) {
                        foreach ($matches[0] as $insStmt) {
                            $pdo->exec($insStmt);
                        }
                    }
                }
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
                log_activity($_SESSION['admin_id'], 'بازیابی انتخابی جداول', "جداول (" . implode(',', $selectedTables) . ") از بکاپ $file بازیابی شدند.");
                set_flash_message('success', 'جداول انتخابی با موفقیت از فایل بکاپ بازیابی شدند.');
            }
        } catch (Exception $e) {
            set_flash_message('error', 'خطا در بازیابی: ' . $e->getMessage());
        }
    }
    redirect('backups.php');
}

// List available backup files
$backups = [];
foreach (glob($backupDir . '/*.sql') as $file) {
    $backups[] = [
        'name' => basename($file),
        'size' => round(filesize($file) / 1024, 2),
        'date' => filemtime($file)
    ];
}
usort($backups, fn($a, $b) => $b['date'] <=> $a['date']);
?>
<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold">پشتیبان‌گیری و بازیابی دیتابیس</h2>
            <p class="text-sm text-muted">ایجاد نسخه پشتیبان کامل از اطلاعات یا بازیابی کامل و انتخابی جداول</p>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="create_backup" value="1">
            <button type="submit" class="btn btn-primary gap-1">
                <span><svg data-ui-icon="save" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 3h15l3 3v15H3Zm4 0v7h10V3M7 21v-7h10v7"/></svg> ایجاد بکاپ جدید (SQL Dump)</span>
            </button>
        </form>
    </div>

    <div class="card">
        <h3 class="font-bold mb-4">فایل‌های پشتیبان موجود در سرور</h3>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>نام فایل پشتیبان</th>
                        <th>حجم (کیلوبایت)</th>
                        <th>تاریخ ایجاد</th>
                        <th>عملیات بازیابی / دانلود</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $bk): ?>
                    <tr>
                        <td class="font-mono text-left dir-ltr"><?php echo clean($bk['name']); ?></td>
                        <td><?php echo tr_num($bk['size'], 'fa'); ?> KB</td>
                        <td><?php echo jdate('Y/m/d H:i:s', $bk['date']); ?></td>
                        <td>
                            <div class="flex gap-2 justify-end">
                                <a href="backups.php?action=download&file=<?php echo urlencode($bk['name']); ?>" class="btn btn-secondary text-xs px-3">دانلود</a>
                                <button onclick="openRestoreModal('<?php echo clean($bk['name']); ?>')" class="btn btn-success text-xs px-3">بازیابی (Restore)</button>
                                <a href="backups.php?action=delete&file=<?php echo urlencode($bk['name']); ?>" onclick="return confirm('آیا از حذف این بکاپ اطمینان دارید؟')" class="btn btn-danger text-xs px-3">حذف</a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; if (empty($backups)): ?>
                    <tr><td colspan="4" class="text-center text-muted">هیچ فایل پشتیبانی ثبت نشده است. دکمه ایجاد بکاپ جدید را بزنید.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Restore Modal -->
<div id="restoreModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5);">
    <div class="card max-w-lg w-full p-6 shadow-xl">
        <h3 class="font-bold text-lg mb-4 text-green-700">بازیابی پایگاه داده از بکاپ</h3>
        <form method="POST" action="backups.php">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="restore_backup" value="1">
            <input type="hidden" name="backup_file" id="modalBackupFile" value="">

            <p class="text-sm mb-4">فایل انتخاب‌شده: <code id="displayBackupFile" class="font-bold"></code></p>

            <div class="mb-4">
                <label class="block text-xs font-semibold mb-2">انتخاب حالت بازیابی:</label>
                <div class="space-y-2 text-sm">
                    <label class="flex items-center gap-2">
                        <input type="radio" name="restore_mode" value="all" checked onclick="toggleTableSelect(false)">
                        <span>بازیابی کامل همه جداول (Full Database Restore)</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" name="restore_mode" value="selective" onclick="toggleTableSelect(true)">
                        <span>بازیابی انتخابی جدول‌ها (Selective Tables Restore)</span>
                    </label>
                </div>
            </div>

            <div id="selectiveTablesBox" class="mb-6 p-3 bg-gray-50 border rounded hidden" style="display:none;">
                <label class="block text-xs font-semibold mb-2">جدول‌های مورد نظر برای بازیابی را تیک بزنید:</label>
                <div class="grid grid-cols-2 gap-2 text-xs">
                    <label class="flex items-center gap-1"><input type="checkbox" name="restore_tables[]" value="students"> دانش‌آموزان (students)</label>
                    <label class="flex items-center gap-1"><input type="checkbox" name="restore_tables[]" value="reports"> کارنامه‌ها (reports)</label>
                    <label class="flex items-center gap-1"><input type="checkbox" name="restore_tables[]" value="report_grades"> نمرات دروس (report_grades)</label>
                    <label class="flex items-center gap-1"><input type="checkbox" name="restore_tables[]" value="classes"> کلاس‌ها (classes)</label>
                    <label class="flex items-center gap-1"><input type="checkbox" name="restore_tables[]" value="subjects"> دروس (subjects)</label>
                    <label class="flex items-center gap-1"><input type="checkbox" name="restore_tables[]" value="settings"> تنظیمات (settings)</label>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('restoreModal').style.display='none'" class="btn btn-secondary">انصراف</button>
                <button type="submit" onclick="return confirm('آیا از بازیابی اطلاعات اطمینان دارید؟ اطلاعات فعلی جداول انتخاب‌شده جایگزین خواهند شد.')" class="btn btn-success">تایید و شروع بازیابی</button>
            </div>
        </form>
    </div>
</div>

<script>
function openRestoreModal(fileName) {
    document.getElementById('modalBackupFile').value = fileName;
    document.getElementById('displayBackupFile').innerText = fileName;
    document.getElementById('restoreModal').style.display = 'flex';
}
function toggleTableSelect(show) {
    document.getElementById('selectiveTablesBox').style.display = show ? 'block' : 'none';
    if (!show) {
        // Uncheck all when full is selected
        document.querySelectorAll('input[name="restore_tables[]"]').forEach(cb => cb.checked = false);
    }
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
