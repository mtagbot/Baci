<?php
// File: online-exam-categories.php - Manage exam and question categories
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/online_exam_helpers.php';
require_once __DIR__ . '/includes/school_roles.php';
ensure_school_roles_schema();
ensure_online_exams_schema();

/* v4.69.0: دسته‌بندی‌های پیش‌فرض — اگر جدول‌ها خالی باشند یک‌بار ساخته می‌شوند.
   دسته آزمون‌ها: ۱۲ ماه سال | دسته سوالات: ۱۴ درس اصلی متوسطه اول */
if (!function_exists('seed_default_online_categories')) {
    function seed_default_online_categories() {
        try {
            $cnt = DB::fetch("SELECT COUNT(*) c FROM online_exam_categories");
            if ((int)($cnt['c'] ?? 0) === 0) {
                $monthColors = ['#f59e0b','#f97316','#ef4444','#0ea5e9','#2563eb','#7c3aed','#16a34a','#22c55e','#84cc16','#eab308','#d97706','#dc2626'];
                $months = ['مهر','آبان','آذر','دی','بهمن','اسفند','فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور'];
                foreach ($months as $i => $mn) {
                    DB::execute("INSERT INTO online_exam_categories (name, description, color) VALUES (?,?,?)",
                        [$mn, 'آزمون‌های ماه ' . $mn, $monthColors[$i % 12]]);
                }
            }
            $cnt2 = DB::fetch("SELECT COUNT(*) c FROM online_question_categories");
            if ((int)($cnt2['c'] ?? 0) === 0) {
                $subjects = ['آموزش قرآن','پیام‌های آسمان','فارسی','نگارش','عربی','زبان انگلیسی','ریاضی','علوم تجربی','مطالعات اجتماعی','فرهنگ و هنر','کار و فناوری','تفکر و سبک زندگی','تربیت بدنی و سلامت','آمادگی دفاعی'];
                foreach ($subjects as $sn) {
                    DB::execute("INSERT INTO online_question_categories (name, teacher_id) VALUES (?, NULL)", [$sn]);
                }
            }
        } catch (Throwable $e) { error_log('seed categories: ' . $e->getMessage()); }
    }
}
seed_default_online_categories();


/* v4.62.0: مدیریت دسته‌بندی‌ها فقط برای مدیر سیستم — دبیر فقط دسته را
   انتخاب می‌کند و نباید بتواند بسازد/حذف کند. */
if (!empty($_SESSION['admin_id'])) require_permission('manage_reports');
elseif (!empty($_SESSION['teacher_id'])) { set_flash_message('error', 'مدیریت دسته‌بندی‌ها فقط برای مدیر سیستم فعال است.'); redirect('online-exams.php'); }
else redirect('admin-login.php');

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!verify_csrf($_POST['csrf_token']??'')) { set_flash_message('error','CSRF'); redirect('online-exam-categories.php'); }
    if (isset($_POST['add_exam_cat'])) {
        $name=trim($_POST['name']??''); $desc=trim($_POST['description']??''); $color=trim($_POST['color']??'#3b82f6');
        if($name!==''){ DB::execute("INSERT INTO online_exam_categories (name, description, color) VALUES (?,?,?)", [$name,$desc,$color]); set_flash_message('success','دسته آزمون افزوده شد'); }
        redirect('online-exam-categories.php');
    }
    if (isset($_POST['delete_exam_cat'])) { DB::execute("DELETE FROM online_exam_categories WHERE id=?", [(int)$_POST['id']]); set_flash_message('success','حذف شد'); redirect('online-exam-categories.php'); }
    if (isset($_POST['add_q_cat'])) {
        $name=trim($_POST['name']??''); if($name!==''){ DB::execute("INSERT INTO online_question_categories (name, teacher_id) VALUES (?,?)", [$name, $_SESSION['teacher_id']??null]); set_flash_message('success','دسته سوال افزوده شد'); }
        redirect('online-exam-categories.php');
    }
    if (isset($_POST['delete_q_cat'])) { DB::execute("DELETE FROM online_question_categories WHERE id=?", [(int)$_POST['id']]); set_flash_message('success','حذف شد'); redirect('online-exam-categories.php'); }
}

$examCats = DB::fetchAll("SELECT * FROM online_exam_categories ORDER BY id DESC");
$qCats = DB::fetchAll("SELECT * FROM online_question_categories ORDER BY id DESC");

require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-6 max-w-5xl mx-auto">
    <h2 class="text-xl font-bold">مدیریت دسته‌بندی آزمون‌ها و سوالات</h2>
    <div class="grid grid-cols-2 gap-6">
        <div class="card">
            <h3 class="font-bold mb-3">دسته‌بندی آزمون‌ها (Quiz Categories)</h3>
            <form method="POST" class="flex gap-2 mb-3"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input name="name" class="form-input flex-1 text-xs" placeholder="نام دسته" required><input name="color" type="color" value="#3b82f6" class="w-10 h-8"><button name="add_exam_cat" class="btn btn-success text-xs">افزودن</button></form>
            <div class="space-y-1 max-h-[400px] overflow-auto">
                <?php foreach($examCats as $c): ?><div class="flex justify-between items-center p-2 border rounded text-xs"><div class="flex gap-2 items-center"><span class="w-3 h-3 rounded-full" style="background:<?php echo clean($c['color']); ?>"></span> <?php echo clean($c['name']); ?></div><form method="POST" onsubmit="return confirm('حذف؟')"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="id" value="<?php echo $c['id']; ?>"><button name="delete_exam_cat" class="text-red-500">×</button></form></div><?php endforeach; ?>
            </div>
        </div>
        <div class="card">
            <h3 class="font-bold mb-3">دسته‌بندی سوالات (Question Categories)</h3>
            <form method="POST" class="flex gap-2 mb-3"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input name="name" class="form-input flex-1 text-xs" placeholder="نام دسته سوال" required><button name="add_q_cat" class="btn btn-success text-xs">افزودن</button></form>
            <div class="space-y-1 max-h-[400px] overflow-auto">
                <?php foreach($qCats as $c): ?><div class="flex justify-between items-center p-2 border rounded text-xs"><span><?php echo clean($c['name']); ?></span><form method="POST" onsubmit="return confirm('حذف؟')"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="id" value="<?php echo $c['id']; ?>"><button name="delete_q_cat" class="text-red-500">×</button></form></div><?php endforeach; ?>
            </div>
        </div>
    </div>
    <a href="online-exams.php" class="btn btn-secondary text-xs">بازگشت</a>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
