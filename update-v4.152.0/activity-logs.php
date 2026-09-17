<?php
/**
 * Admin Activity Logs Viewer (activity-logs.php)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/header.php';

require_permission('view_logs');

// Handle Clear Logs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_logs'])) {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        DB::execute("TRUNCATE TABLE activity_logs");
        log_activity($_SESSION['admin_id'], 'پاکسازی لاگ‌ها', 'تمام لاگ‌های فعالیت سیستم پاکسازی شدند.');
        set_flash_message('success', 'لاگ‌های فعالیت با موفقیت پاکسازی شدند.');
    }
    redirect('activity-logs.php');
}

$search = trim($_GET['q'] ?? '');
$adminFilter = trim($_GET['admin'] ?? '');

$where = ["1=1"];
$params = [];
if (!empty($search)) {
    $where[] = "(action LIKE ? OR description LIKE ? OR ip_address LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if (!empty($adminFilter)) {
    $where[] = "admin_username = ?";
    $params[] = $adminFilter;
}

$whereSql = implode(' AND ', $where);
$logs = DB::fetchAll("SELECT * FROM activity_logs WHERE $whereSql ORDER BY id DESC LIMIT 100", $params);
$distinctAdmins = DB::fetchAll("SELECT DISTINCT admin_username FROM activity_logs WHERE admin_username IS NOT NULL");
?>
<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold">لاگ فعالیت مدیران سیستم</h2>
            <p class="text-sm text-muted">پیگیری و نظارت بر تمامی اقدامات و تغییرات انجام‌شده در سامانه</p>
        </div>
        <?php if ($_SESSION['admin_role'] === 'super_admin'): ?>
        <form method="POST" onsubmit="return confirm('آیا از پاکسازی تمامی لاگ‌ها اطمینان دارید؟');">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="clear_logs" value="1">
            <button type="submit" class="btn btn-danger gap-1 text-xs">
                <span><svg data-ui-icon="delete" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 6h18M9 6V3h6v3M5 6l1 16h12l1-16M10 10v8m4-8v8"/></svg> پاکسازی تاریخچه لاگ‌ها</span>
            </button>
        </form>
        <?php endif; ?>
    </div>

    <!-- Filter Bar -->
    <div class="card p-4">
        <form method="GET" class="flex gap-4 items-end">
            <div class="flex-1">
                <label class="block text-xs font-semibold mb-1">جستجو در عملیات یا توضیحات</label>
                <input type="text" name="q" class="form-input" placeholder="جستجو..." value="<?php echo clean($search); ?>">
            </div>
            <div class="w-48">
                <label class="block text-xs font-semibold mb-1">فیلتر مدیر</label>
                <select name="admin" class="form-select">
                    <option value="">همه مدیران</option>
                    <?php foreach ($distinctAdmins as $adm): ?>
                        <option value="<?php echo clean($adm['admin_username']); ?>" <?php echo $adminFilter === $adm['admin_username'] ? 'selected' : ''; ?>>
                            <?php echo clean($adm['admin_username']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="btn btn-primary px-6">فیلتر</button>
                <?php if ($search || $adminFilter): ?>
                <a href="activity-logs.php" class="btn btn-secondary px-3">حذف فیلتر</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Logs Table -->
    <div class="card">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>شناسه</th>
                        <th>نام کاربری مدیر</th>
                        <th>عنوان عملیات</th>
                        <th>توضیحات جزئیات</th>
                        <th>آی‌پی کاربر</th>
                        <th>تاریخ و ساعت</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="font-mono text-xs"><?php echo tr_num($log['id'], 'fa'); ?></td>
                        <td>
                            <span class="badge badge-info"><?php echo clean($log['admin_username'] ?: 'سیستم'); ?></span>
                        </td>
                        <td class="font-bold text-sm"><?php echo clean($log['action']); ?></td>
                        <td class="text-xs text-muted max-w-md truncate"><?php echo clean($log['description']); ?></td>
                        <td class="font-mono text-xs dir-ltr"><?php echo clean($log['ip_address']); ?></td>
                        <td class="text-xs whitespace-nowrap"><?php echo jdate('Y/m/d H:i:s', strtotime($log['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; if (empty($logs)): ?>
                    <tr><td colspan="6" class="text-center text-muted">هیچ فعالیت ثبت‌شده‌ای یافت نشد.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
