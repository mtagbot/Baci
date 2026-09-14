<?php
/**
 * User Profile & Password Change (profile.php)
 */

require_once __DIR__ . '/includes/auth.php';

if (is_student_logged_in()) {
    set_flash_message('error', 'امکان تغییر رمز عبور برای دانش‌آموزان غیرفعال است.');
    redirect('student-panel.php');
}

if (!is_admin_logged_in() && !isset($_SESSION['teacher_id'])) {
    redirect('index.php?view=login');
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        $oldPass = $_POST['old_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';

        if (is_student_logged_in()) {
            $st = current_student();
            if (verify_user_password($oldPass, $st['password'], ['table'=>'students','id'=>$st['id']])) {
                $hashed = password_hash($newPass, PASSWORD_DEFAULT);
                DB::execute("UPDATE students SET password = ? WHERE id = ?", [$hashed, $st['id']]);
                set_flash_message('success', 'کلمه عبور شما با موفقیت تغییر کرد.');
            } else {
                set_flash_message('error', 'کلمه عبور فعلی نادرست است.');
            }
        } elseif (is_admin_logged_in()) {
            $adm = current_admin();
            if (verify_user_password($oldPass, $adm['password'], ['table'=>'admins','id'=>$adm['id']])) {
                $hashed = password_hash($newPass, PASSWORD_DEFAULT);
                DB::execute("UPDATE admins SET password = ? WHERE id = ?", [$hashed, $adm['id']]);
                log_activity($adm['id'], 'تغییر رمز عبور', 'مدیر رمز عبور خود را تغییر داد.');
                set_flash_message('success', 'کلمه عبور مدیریت با موفقیت تغییر کرد.');
            } else {
                set_flash_message('error', 'کلمه عبور فعلی نادرست است.');
            }
        }
    }
    redirect('profile.php');
}
?>
<div class="max-w-md mx-auto card p-6">
    <h2 class="text-xl font-bold mb-6 text-primary border-b pb-2">پروفایل کاربری و تغییر رمز عبور</h2>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="update_profile" value="1">
        
        <div class="mb-4">
            <label class="block text-xs font-semibold mb-1">رمز عبور فعلی *</label>
            <input type="password" name="old_password" class="form-input dir-ltr text-left" required>
        </div>
        <div class="mb-6">
            <label class="block text-xs font-semibold mb-1">رمز عبور جدید *</label>
            <input type="password" name="new_password" class="form-input dir-ltr text-left" required>
        </div>
        
        <button type="submit" class="btn btn-primary w-full py-2.5 font-bold">🔑 تغییر رمز عبور</button>
    </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
