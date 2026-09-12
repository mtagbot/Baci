<?php
/**
 * Admins & Permissions Management (admins.php)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/header.php';
$defaults = require __DIR__ . '/config/defaults.php';

if ($_SESSION['admin_role'] !== 'super_admin') {
    set_flash_message('error', 'تنها مدیر ارشد (سوپر ادمین) به این بخش دسترسی دارد.');
    redirect('index.php?view=dashboard');
}

$action = $_GET['action'] ?? '';
$adminId = (int)($_GET['id'] ?? 0);

// Delete Admin
if ($action === 'delete' && $adminId > 1) {
    DB::execute("DELETE FROM admins WHERE id = ?", [$adminId]);
    log_activity($_SESSION['admin_id'], 'حذف مدیر', "مدیر ID: $adminId حذف شد.");
    set_flash_message('success', 'حساب مدیریت با موفقیت حذف شد.');
    redirect('admins.php');
}

// Save Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_admin'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'خطای امنیتی CSRF');
        redirect('admins.php');
    }
    $username = trim($_POST['username'] ?? '');
    $name     = trim($_POST['name'] ?? '');
    $role     = $_POST['role'] ?? 'edu_admin';
    $email    = trim($_POST['email'] ?? '');
    $mobile   = tr_num(trim($_POST['mobile'] ?? ''), 'en');
    $status   = (int)($_POST['status'] ?? 1);
    $perms    = $_POST['permissions'] ?? [];

    if ($role === 'super_admin') {
        $perms = ['all'];
    }

    $permsJson = json_encode($perms, JSON_UNESCAPED_UNICODE);

    if ($adminId > 0) {
        $query = "UPDATE admins SET username=?, name=?, role=?, permissions=?, email=?, mobile=?, status=? WHERE id=?";
        $params = [$username, $name, $role, $permsJson, $email, $mobile, $status, $adminId];
        if (!empty($_POST['password'])) {
            $hashed = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $query = "UPDATE admins SET username=?, password=?, name=?, role=?, permissions=?, email=?, mobile=?, status=? WHERE id=?";
            $params = [$username, $hashed, $name, $role, $permsJson, $email, $mobile, $status, $adminId];
        }
        DB::execute($query, $params);
        log_activity($_SESSION['admin_id'], 'ویرایش مدیر', "اطلاعات مدیر $username ویرایش شد.");
        set_flash_message('success', 'اطلاعات مدیر با موفقیت بروزرسانی شد.');
    } else {
        $pass = password_hash($_POST['password'] ?: '123456', PASSWORD_DEFAULT);
        DB::execute("INSERT INTO admins (username, password, name, role, permissions, email, mobile, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        [$username, $pass, $name, $role, $permsJson, $email, $mobile, $status]);
        log_activity($_SESSION['admin_id'], 'افزودن مدیر جدید', "مدیر جدید $username ایجاد شد.");
        set_flash_message('success', 'مدیر جدید با موفقیت ایجاد گردید.');
    }
    redirect('admins.php');
}

if ($action === 'add' || $action === 'edit'):
    $adminData = null;
    $currentPerms = [];
    if ($action === 'edit' && $adminId > 0) {
        $adminData = DB::fetch("SELECT * FROM admins WHERE id = ?", [$adminId]);
        $currentPerms = json_decode($adminData['permissions'] ?? '[]', true) ?: [];
    }
?>
<div class="max-w-2xl mx-auto card p-6">
    <h3 class="text-lg font-bold mb-6 text-purple-700 border-b pb-2">
        <?php echo $action === 'edit' ? 'ویرایش اطلاعات و سطوح دسترسی مدیر' : 'تعریف مدیر یا ناظر جدید'; ?>
    </h3>
    <form method="POST" action="admins.php?id=<?php echo $adminId; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="save_admin" value="1">

        <div class="grid grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-xs font-semibold mb-1">نام و نام خانوادگی *</label>
                <input type="text" name="name" class="form-input" value="<?php echo clean($adminData['name'] ?? ''); ?>" required>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">نام کاربری ورود (Username) *</label>
                <input type="text" name="username" class="form-input dir-ltr text-left" value="<?php echo clean($adminData['username'] ?? ''); ?>" required>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-xs font-semibold mb-1"><?php echo $action === 'edit' ? 'رمز عبور جدید (خالی بگذارید تا تغییر نکند)' : 'رمز عبور *'; ?></label>
                <input type="password" name="password" class="form-input dir-ltr text-left" <?php echo $action === 'add' ? 'required' : ''; ?>>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">نقش مدیریتی *</label>
                <select name="role" class="form-select">
                    <option value="edu_admin" <?php echo ($adminData['role'] ?? '') === 'edu_admin' ? 'selected' : ''; ?>>مدیر آموزشی</option>
                    <option value="observer" <?php echo ($adminData['role'] ?? '') === 'observer' ? 'selected' : ''; ?>>ناظر / مشاهده‌گر</option>
                    <option value="super_admin" <?php echo ($adminData['role'] ?? '') === 'super_admin' ? 'selected' : ''; ?>>سوپر ادمین (دسترسی کامل)</option>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4 mb-6">
            <div>
                <label class="block text-xs font-semibold mb-1">موبایل</label>
                <input type="text" name="mobile" class="form-input dir-ltr text-left" value="<?php echo clean($adminData['mobile'] ?? ''); ?>">
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">وضعیت حساب</label>
                <select name="status" class="form-select">
                    <option value="1" <?php echo ($adminData['status'] ?? 1) == 1 ? 'selected' : ''; ?>>فعال</option>
                    <option value="0" <?php echo ($adminData['status'] ?? 1) == 0 ? 'selected' : ''; ?>>غیرفعال</option>
                </select>
            </div>
        </div>

        <h4 class="font-bold text-sm mb-3 text-gray-700">مجوزهای تکمیلی و اختصاصی سیستم:</h4>
        <div class="grid grid-cols-2 gap-3 mb-6 p-3 bg-gray-50 rounded border text-xs">
            <?php foreach ($defaults['permissions'] as $pkey => $plabel): ?>
            <label class="flex items-center gap-2">
                <input type="checkbox" name="permissions[]" value="<?php echo clean($pkey); ?>" <?php echo in_array('all', $currentPerms) || in_array($pkey, $currentPerms) ? 'checked' : ''; ?>>
                <span><?php echo clean($plabel); ?></span>
            </label>
            <?php endforeach; ?>
        </div>

        <div class="flex justify-between items-center">
            <a href="admins.php" class="btn btn-secondary">&rarr; بازگشت</a>
            <button type="submit" class="btn btn-success px-8 py-2.5 font-bold">💾 ذخیره اطلاعات مدیر</button>
        </div>
    </form>
</div>
<?php
else:
    $adminsList = DB::fetchAll("SELECT * FROM admins ORDER BY id ASC");
?>
<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold">مدیریت مدیران و سطوح دسترسی</h2>
            <p class="text-sm text-muted">تعریف نقش‌های سوپر ادمین، مدیر آموزشی، ناظر و مجوزهای جزئی</p>
        </div>
        <a href="admins.php?action=add" class="btn btn-primary gap-1">
            <span>+ تعریف مدیر جدید</span>
        </a>
    </div>

    <div class="card">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>شناسه</th>
                        <th>نام کاربری</th>
                        <th>نام و نام خانوادگی</th>
                        <th>نقش سیستم</th>
                        <th>موبایل</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($adminsList as $adm): ?>
                    <tr>
                        <td class="font-mono text-xs">#<?php echo $adm['id']; ?></td>
                        <td class="font-mono dir-ltr text-xs font-bold"><?php echo clean($adm['username']); ?></td>
                        <td class="font-bold"><?php echo clean($adm['name']); ?></td>
                        <td>
                            <?php
                            $roleBadge = match($adm['role']) {
                                'super_admin' => '<span class="badge bg-purple-600 text-white">سوپر ادمین</span>',
                                'edu_admin'   => '<span class="badge bg-blue-600 text-white">مدیر آموزشی</span>',
                                default       => '<span class="badge bg-gray-500 text-white">ناظر</span>'
                            };
                            echo $roleBadge;
                            ?>
                        </td>
                        <td class="font-mono text-xs dir-ltr"><?php echo clean($adm['mobile'] ?? '---'); ?></td>
                        <td><?php echo $adm['status'] ? '<span class="badge badge-success">فعال</span>' : '<span class="badge badge-danger">غیرفعال</span>'; ?></td>
                        <td>
                            <div class="flex gap-2 justify-end">
                                <a href="admins.php?action=edit&id=<?php echo $adm['id']; ?>" class="btn btn-secondary text-xs px-3 py-1">ویرایش</a>
                                <?php if ($adm['id'] != 1 && $adm['id'] != $_SESSION['admin_id']): ?>
                                <a href="admins.php?action=delete&id=<?php echo $adm['id']; ?>" onclick="return confirm('حذف این حساب؟')" class="btn btn-danger text-xs px-3 py-1">حذف</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; require_once __DIR__ . '/includes/footer.php'; ?>
