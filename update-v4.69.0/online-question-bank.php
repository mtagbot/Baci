<?php
// File: online-question-bank.php - Question bank management
// v4.61.0 fix: schema calls were NOT wrapped in try/catch here (unlike
// online-exams.php, where the author explicitly guards them because they can
// throw on hosts where tables already exist in an older shape) => the page
// died with HTTP 500 for teachers. Now fully guarded, same as online-exams.php.
ini_set('display_errors', 0);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/online_exam_helpers.php';
require_once __DIR__ . '/includes/school_roles.php';
try { ensure_school_roles_schema(); } catch (Throwable $e) { error_log('qbank roles schema: ' . $e->getMessage()); }
try { ensure_online_exams_schema();

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
 } catch (Throwable $e) { error_log('qbank exams schema: ' . $e->getMessage()); }

if (!empty($_SESSION['admin_id'])) require_permission('manage_reports');
elseif (empty($_SESSION['teacher_id'])) redirect('admin-login.php');
$teacherId = $_SESSION['teacher_id'] ?? null;
$isExecutive = false;
try { $isExecutive = $teacherId ? (teacher_has_executive($teacherId) || teacher_has_deputy($teacherId)) : false; } catch (Throwable $e) { $isExecutive = false; }

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!verify_csrf($_POST['csrf_token']??'')) { set_flash_message('error','CSRF'); redirect('online-question-bank.php'); }
    if (isset($_POST['delete_bank'])) {
        $bid = (int)$_POST['bank_id'];
        $bq = DB::fetch("SELECT teacher_id FROM online_question_bank WHERE id=?", [$bid]);
        if ($bq && ($teacherId && (int)$bq['teacher_id'] !== (int)$teacherId && !$isExecutive && empty($_SESSION['admin_id']))) { set_flash_message('error','غیرمجاز'); redirect('online-question-bank.php'); }
        DB::execute("DELETE FROM online_question_bank WHERE id=?", [$bid]);
        set_flash_message('success','از بانک حذف شد'); redirect('online-question-bank.php');
    }
    if (isset($_POST['toggle_public'])) {
        $bid=(int)$_POST['bank_id']; DB::execute("UPDATE online_question_bank SET is_public = 1 - is_public WHERE id=?", [$bid]); set_flash_message('success','وضعیت اشتراک تغییر کرد'); redirect('online-question-bank.php');
    }
    // category management — v4.62.0: ADMIN ONLY. Teachers may only pick a
    // category from the dropdowns; creating/deleting is a manager task.
    if (isset($_POST['add_category'])) {
        if (empty($_SESSION['admin_id'])) { set_flash_message('error','مدیریت دسته‌بندی فقط برای مدیر سیستم مجاز است.'); redirect('online-question-bank.php'); }
        $name=trim($_POST['cat_name']??''); if($name!==''){ DB::execute("INSERT INTO online_question_categories (name, teacher_id) VALUES (?,?)", [$name, $teacherId]); set_flash_message('success','دسته ایجاد شد'); } redirect('online-question-bank.php');
    }
    if (isset($_POST['delete_category'])) {
        if (empty($_SESSION['admin_id'])) { set_flash_message('error','مدیریت دسته‌بندی فقط برای مدیر سیستم مجاز است.'); redirect('online-question-bank.php'); }
        $cid=(int)$_POST['cat_id']; DB::execute("DELETE FROM online_question_categories WHERE id=?", [$cid]); set_flash_message('success','دسته حذف شد'); redirect('online-question-bank.php');
    }
}

$search = trim($_GET['search']??'');
$typeFilter = trim($_GET['type']??'');
$catFilter = trim($_GET['category']??'');

$where = ["1=1"]; $params=[];
if ($teacherId && !$isExecutive && empty($_SESSION['admin_id'])) { $where[]="(teacher_id=? OR is_public=1)"; $params[]=$teacherId; }
if ($search!=='') { $where[]="question_text LIKE ?"; $params[]="%$search%"; }
if ($typeFilter!=='') { $where[]="question_type=?"; $params[]=$typeFilter; }
if ($catFilter!=='') { $where[]="category_id=?"; $params[]=(int)$catFilter; }
$whereSql=implode(' AND ',$where);
/* v4.61.0: guarded queries — a missing/older table must never 500 the page */
try {
    $banks = DB::fetchAll("SELECT b.*, c.name as cat_name, t.full_name as teacher_name FROM online_question_bank b LEFT JOIN online_question_categories c ON c.id=b.category_id LEFT JOIN teachers t ON t.id=b.teacher_id WHERE $whereSql ORDER BY b.id DESC LIMIT 300", $params);
} catch (Throwable $e) { $banks = []; error_log('qbank list: ' . $e->getMessage()); }
try {
    $categories = DB::fetchAll("SELECT * FROM online_question_categories ORDER BY name");
} catch (Throwable $e) { $categories = []; error_log('qbank cats: ' . $e->getMessage()); }
$types = online_question_types();

require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-6">
    <?php
    /* v4.61.0: teacher hero + app-style grid on every teacher page */
    require_once __DIR__ . '/includes/teacher_nav.php';
    render_teacher_nav();
    ?>
    <div class="flex justify-between items-center">
        <h2 class="text-xl font-bold">📚 بانک سوالات شخصی و عمومی</h2>
        <div class="flex gap-2"><a href="online-exams.php" class="btn btn-secondary text-xs">لیست آزمون‌ها</a><a href="online-exam-form.php" class="btn btn-success text-xs">➕ آزمون جدید</a></div>
    </div>

    <div class="grid grid-cols-4 gap-4">
        <div class="card col-span-3">
            <form method="GET" class="grid grid-cols-4 gap-2 mb-3">
                <input name="search" class="form-input text-xs" placeholder="جستجو متن سوال" value="<?php echo clean($search); ?>">
                <select name="type" class="form-select text-xs"><option value="">همه انواع</option><?php foreach($types as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $typeFilter===$k?'selected':''; ?>><?php echo $v['label']; ?></option><?php endforeach; ?></select>
                <select name="category" class="form-select text-xs"><option value="">همه دسته‌ها</option><?php foreach($categories as $cc): ?><option value="<?php echo $cc['id']; ?>" <?php echo $catFilter==$cc['id']?'selected':''; ?>><?php echo clean($cc['name']); ?></option><?php endforeach; ?></select>
                <button class="btn btn-primary text-xs">فیلتر</button>
            </form>
            <div class="space-y-3 max-h-[70vh] overflow-auto">
                <?php foreach($banks as $b): $qd=json_decode($b['question_data'], true); ?>
                <div class="border rounded p-3 hover:shadow-sm bg-white">
                    <div class="flex justify-between">
                        <div class="text-xs flex gap-2 items-center"><span><?php echo $types[$b['question_type']]['icon']??'❓'; ?></span><span class="font-bold"><?php echo $types[$b['question_type']]['label']??$b['question_type']; ?></span><span class="badge"><?php echo tr_num($b['points'],'fa'); ?> نمره</span><span class="badge badge-info"><?php echo clean($b['cat_name']??'بدون دسته'); ?></span><span class="text-muted"><?php echo clean($b['teacher_name']??''); ?></span><span class="text-muted">استفاده: <?php echo tr_num($b['usage_count'],'fa'); ?></span><?php if($b['is_public']): ?><span class="badge badge-success">عمومی</span><?php endif; ?></div>
                        <div class="flex gap-1">
                            <form method="POST" class="inline"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="bank_id" value="<?php echo $b['id']; ?>"><button name="toggle_public" class="btn btn-secondary text-[10px]"><?php echo $b['is_public']?'🔒 خصوصی':'🌐 عمومی'; ?></button></form>
                            <form method="POST" onsubmit="return confirm('حذف؟')" class="inline"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="bank_id" value="<?php echo $b['id']; ?>"><button name="delete_bank" class="btn btn-danger text-[10px]">🗑️</button></form>
                        </div>
                    </div>
                    <div class="mt-2 text-sm"><?php echo $b['question_text']; ?></div>
                    <?php if(in_array($b['question_type'],['radio','checkbox','dropdown'])): ?><div class="mt-1 text-xs space-y-1"><?php foreach(($qd['options']??[]) as $o): ?><div class="<?php echo !empty($o['is_correct'])?'text-green-600 font-bold':''; ?>"><?php echo !empty($o['is_correct'])?'✅':'○'; ?> <?php echo clean($o['text']); ?></div><?php endforeach; ?></div><?php endif; ?>
                </div>
                <?php endforeach; if(empty($banks)): ?><p class="text-center text-muted py-8 text-xs">بانک خالی است</p><?php endif; ?>
            </div>
        </div>
        <div class="space-y-4">
            <?php if (!empty($_SESSION['admin_id'])): /* v4.62.0: مدیریت دسته‌بندی فقط برای مدیر */ ?>
            <div class="card">
                <h3 class="font-bold text-sm mb-2">📂 دسته‌بندی سوالات</h3>
                <form method="POST" class="flex gap-1 mb-2"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input name="cat_name" class="form-input text-xs flex-1" placeholder="نام دسته جدید" required><button name="add_category" class="btn btn-success text-xs">➕</button></form>
                <div class="space-y-1 max-h-[300px] overflow-auto">
                    <?php foreach($categories as $cat): ?><div class="flex justify-between items-center p-1 border rounded text-xs"><span><?php echo clean($cat['name']); ?></span><form method="POST" onsubmit="return confirm('حذف دسته؟')"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="cat_id" value="<?php echo $cat['id']; ?>"><button name="delete_category" class="text-red-500">×</button></form></div><?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="card">
                <h3 class="font-bold text-sm mb-2">📂 دسته‌بندی‌های موجود</h3>
                <div class="space-y-1 max-h-[300px] overflow-auto">
                    <?php foreach($categories as $cat): ?><div class="p-1 border rounded text-xs"><?php echo clean($cat['name']); ?></div><?php endforeach; if (empty($categories)): ?><p class="text-muted text-xs">هنوز دسته‌ای تعریف نشده است.</p><?php endif; ?>
                </div>
                <p class="text-[10px] text-muted mt-2">تعریف و ویرایش دسته‌بندی توسط مدیر سیستم انجام می‌شود.</p>
            </div>
            <?php endif; ?>
            <div class="card bg-blue-50 border-blue-200 text-xs leading-6">
                <h4 class="font-bold">راهنما:</h4>
                <p>• سوالات شخصی شما فقط برای خودتان قابل مشاهده است مگر اینکه عمومی کنید.</p>
                <p>• در صفحه طراحی آزمون می‌توانید از این بانک سوال وارد آزمون کنید.</p>
                <p>• تیک «ذخیره در بانک» هنگام ایجاد سوال، سوال را اینجا هم ذخیره می‌کند.</p>
                <?php if (!empty($_SESSION['admin_id'])): ?><p>• می‌توانید دسته‌بندی سوالات را مدیریت کنید.</p><?php else: ?><p>• دسته‌بندی سوال را هنگام ذخیره انتخاب می‌کنید؛ مدیریت دسته‌ها با مدیر است.</p><?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
