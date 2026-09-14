<?php
/**
 * System Notifications & Announcements (notifications.php)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/header.php';

require_permission('send_sms');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_notification'])) {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        $title = trim($_POST['title'] ?? '');
        $msg   = trim($_POST['message'] ?? '');
        $type  = $_POST['target_type'] ?? 'all';
        $val   = trim($_POST['target_value'] ?? '');
        $author = $_SESSION['admin_name'] ?? 'مدیریت';

        if ($title && $msg) {
            DB::execute("INSERT INTO notifications (title, message, target_type, target_value, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())",
            [$title, $msg, $type, $val ?: null, $author]);
            log_activity($_SESSION['admin_id'], 'ثبت اعلان سیستمی', "اعلان با عنوان $title منتشر شد.");
            set_flash_message('success', 'اعلان جدید با موفقیت ثبت و منتشر گردید.');
        }
    }
    redirect('notifications.php');
}

if (isset($_GET['del']) && $_GET['del'] > 0) {
    DB::execute("DELETE FROM notifications WHERE id = ?", [(int)$_GET['del']]);
    set_flash_message('success', 'اعلان مورد نظر حذف شد.');
    redirect('notifications.php');
}

$notifications = DB::fetchAll("SELECT * FROM notifications ORDER BY id DESC LIMIT 50");
$classes = DB::fetchAll("SELECT DISTINCT name FROM classes");
?>
<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold">اعلان‌ها و اطلاعیه‌های سیستمی</h2>
            <p class="text-sm text-muted">انتشار پیام و اطلاعیه در پنل کاربری دانش‌آموزان</p>
        </div>
    </div>

    <div class="grid grid-cols-3 gap-6">
        <div class="card col-span-1">
            <h3 class="font-bold mb-4 text-primary">ثبت اطلاعیه جدید</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="send_notification" value="1">

                <div class="mb-4">
                    <label class="block text-xs font-semibold mb-1">عنوان اطلاعیه *</label>
                    <input type="text" name="title" class="form-input" placeholder="مثال: توزیع کارنامه نوبت اول" required>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-semibold mb-1">مخاطبین اطلاعیه</label>
                    <select name="target_type" class="form-select" id="notifType" onchange="toggleNotifTarget()">
                        <option value="all">همه دانش‌آموزان</option>
                        <option value="class">یک کلاس خاص</option>
                    </select>
                </div>

                <div id="classTargetBox" class="mb-4 hidden" style="display:none;">
                    <label class="block text-xs font-semibold mb-1">انتخاب کلاس</label>
                    <select name="target_value" class="form-select">
                        <?php foreach ($classes as $c): ?>
                            <option value="<?php echo clean($c['name']); ?>"><?php echo clean($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-6">
                    <label class="block text-xs font-semibold mb-1">متن کامل اطلاعیه *</label>
                    <textarea name="message" rows="4" class="form-textarea" required></textarea>
                </div>

                <button type="submit" class="btn btn-primary w-full py-2.5 font-bold">📢 انتشار اطلاعیه &larr;</button>
            </form>
        </div>

        <div class="card col-span-2">
            <h3 class="font-bold mb-4">لیست اطلاعیه‌های منتشرشده</h3>
            <div class="space-y-4">
                <?php foreach ($notifications as $n): ?>
                <div class="p-4 rounded-lg border border-color bg-gray-50 flex justify-between items-start">
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <h4 class="font-bold text-main"><?php echo clean($n['title']); ?></h4>
                            <span class="badge badge-info"><?php echo $n['target_type'] === 'all' ? 'عمومی' : 'کلاس ' . clean($n['target_value']); ?></span>
                        </div>
                        <p class="text-xs text-gray-600 leading-relaxed"><?php echo nl2br(clean($n['message'])); ?></p>
                        <span class="text-[10px] text-muted block mt-2">ثبت‌کننده: <?php echo clean($n['created_by']); ?> | مورخ: <?php echo jdate('Y/m/d H:i', strtotime($n['created_at'])); ?></span>
                    </div>
                    <a href="notifications.php?del=<?php echo $n['id']; ?>" onclick="return confirm('حذف این اطلاعیه؟')" class="text-red-500 font-bold px-2">&times;</a>
                </div>
                <?php endforeach; if (empty($notifications)): ?>
                <p class="text-center text-muted py-6">اطلاعیه‌ای موجود نیست.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>
function toggleNotifTarget() {
    const val = document.getElementById('notifType').value;
    document.getElementById('classTargetBox').style.display = val === 'class' ? 'block' : 'none';
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
