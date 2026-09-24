<?php
// File: online-exams.php - Management of online exams (v4.28.1 fix - robust)
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/academic_year_helpers.php';
require_once __DIR__ . '/includes/online_exam_helpers.php';
require_once __DIR__ . '/includes/school_roles.php';

try {
    ensure_school_roles_schema();
} catch (Exception $e) {
    // ignore
}
try {
    ensure_online_exams_schema();
} catch (Exception $e) {
    // Log but continue - tables may already exist
    error_log('ensure_online_exams_schema failed: '.$e->getMessage());
}

// Permissions: admin or teacher (executive/deputy can view all)
$role = null;
if (!empty($_SESSION['admin_id'])) {
    try { require_permission('manage_reports'); } catch (Exception $ex) {}
    $role = 'admin';
} elseif (!empty($_SESSION['teacher_id'])) {
    $role = 'teacher';
} else {
    redirect('admin-login.php');
}

$teacherId = $_SESSION['teacher_id'] ?? null;
$isExecutive = false;
try {
    $isExecutive = $teacherId ? (teacher_has_executive($teacherId) || teacher_has_deputy($teacherId) || teacher_has_counselor($teacherId)) : false;
} catch (Exception $e) { $isExecutive = false; }

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!verify_csrf($_POST['csrf_token']??'')) { set_flash_message('error','خطای CSRF'); redirect('online-exams.php'); }

    if (isset($_POST['delete_exam'])) {
        $eid = (int)$_POST['exam_id'];
        if ($role==='teacher' && !$isExecutive) {
            $ex = DB::fetch("SELECT teacher_id FROM online_exams WHERE id=?", [$eid]);
            if (!$ex || (int)$ex['teacher_id'] !== (int)$teacherId) { set_flash_message('error','دسترسی غیرمجاز'); redirect('online-exams.php'); }
        }
        try {
            DB::execute("DELETE FROM online_exams WHERE id=?", [$eid]);
            DB::execute("DELETE FROM online_questions WHERE exam_id=?", [$eid]);
        } catch (Exception $e) { error_log($e->getMessage()); }
        set_flash_message('success','آزمون حذف شد');
        redirect('online-exams.php');
    }

    if (isset($_POST['toggle_status'])) {
        $eid = (int)$_POST['exam_id'];
        $newStatus = trim($_POST['new_status']??'draft');
        if (!in_array($newStatus, ['draft','published','archived'])) $newStatus='draft';
        try { DB::execute("UPDATE online_exams SET status=? WHERE id=?", [$newStatus,$eid]); } catch (Exception $e) {}
        set_flash_message('success','وضعیت آزمون تغییر کرد');
        redirect('online-exams.php');
    }
}

$year = trim($_GET['year'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$classFilter = trim($_GET['class'] ?? '');
$search = trim($_GET['search'] ?? '');

$where = ["1=1"];
$params = [];
if ($year !== '') {
    $where[] = "(e.academic_year=? OR e.academic_year='' OR e.academic_year IS NULL)";
    $params[] = $year;
}
if ($role==='teacher' && !$isExecutive) {
    $where[] = "e.teacher_id=?";
    $params[] = $teacherId;
}
if ($categoryFilter!=='') { $where[]="e.category_id=?"; $params[]=(int)$categoryFilter; }
if ($classFilter!=='') { $where[]="e.class_name=?"; $params[]=$classFilter; }
if ($search!=='') { $where[]="(e.title LIKE ? OR e.subject_name LIKE ?)"; $params[]="%$search%"; $params[]="%$search%"; }

$whereSql = implode(' AND ', $where);

$exams = [];
$categories = [];
$classes = [];
$years = [];

try {
    $exams = DB::fetchAll("SELECT e.*, c.name as category_name, t.full_name as teacher_name FROM online_exams e LEFT JOIN online_exam_categories c ON c.id=e.category_id LEFT JOIN teachers t ON t.id=e.teacher_id WHERE $whereSql ORDER BY e.id DESC", $params);
} catch (Exception $e) {
    $exams = [];
    error_log('online-exams fetch failed: '.$e->getMessage());
    // Try to recreate schema again
    try { ensure_online_exams_schema(); } catch (Exception $ee) {}
}

try {
    $categories = DB::fetchAll("SELECT * FROM online_exam_categories ORDER BY sort_order, name");
} catch (Exception $e) { $categories = []; }

try {
    $classes = DB::fetchAll("SELECT DISTINCT class_name FROM students WHERE status='active' ORDER BY class_name");
} catch (Exception $e) { $classes = []; }

try {
    $years = get_academic_years_for_filter();
    if (empty($years)) $years = [['academic_year'=>$year]];
} catch (Exception $e) {
    $years = [['academic_year'=>$year]];
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-6">
    <?php
    /* v4.61.0: teacher hero + app-style grid on every teacher page */
    require_once __DIR__ . '/includes/teacher_nav.php';
    render_teacher_nav();
    ?>
    <div class="page-hero flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold">🧪 آزمون‌های آنلاین</h2>
            <p class="text-sm text-muted">مدیریت آزمون‌های مجازی - الگوبرداری از Quiz Maker Pro با امکانات ضدتقلب پیشرفته</p>
        </div>
        <div class="flex gap-2">
            <a href="online-exam-form.php" class="btn btn-success">➕ ایجاد آزمون جدید</a>
            <a href="online-question-bank.php" class="btn btn-primary">📚 بانک سوالات</a>
<?php if ($role === 'admin'): /* v4.62.0: مدیریت دسته‌بندی فقط برای مدیر — دبیر فقط دسته را «انتخاب» می‌کند */ ?><a href="online-exam-categories.php" class="btn btn-outline">📂 دسته‌بندی‌ها</a><?php endif; ?>
        </div>
    </div>

    <?php if (empty($exams) && !empty($_GET)): ?>
    <div class="card bg-amber-50 border-amber-200 text-xs">فیلتری نتیجه‌ای نداشت - همه آزمون‌ها را می‌بینید؟ <a href="online-exams.php" class="text-primary">نمایش همه</a></div>
    <?php endif; ?>

    <div class="card">
        <form method="GET" class="grid grid-cols-5 gap-3 items-end">
            <div>
                <label class="text-xs">سال تحصیلی (شمسی)</label>
                <select name="year" class="form-select">
                    <option value="">همه سال‌ها (نمایش همه آزمون‌ها)</option>
                    <?php foreach($years as $y): ?><option value="<?php echo clean($y['academic_year']); ?>" <?php echo $year===$y['academic_year']?'selected':''; ?>><?php echo clean($y['academic_year']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-xs">دسته آزمون</label>
                <select name="category" class="form-select"><option value="">همه</option><?php foreach($categories as $cat): ?><option value="<?php echo $cat['id']; ?>" <?php echo $categoryFilter==$cat['id']?'selected':''; ?>><?php echo clean($cat['name']); ?></option><?php endforeach; ?></select>
            </div>
            <div>
                <label class="text-xs">کلاس</label>
                <select name="class" class="form-select"><option value="">همه کلاس‌ها</option><?php foreach($classes as $cl): ?><option value="<?php echo clean($cl['class_name']); ?>" <?php echo $classFilter===$cl['class_name']?'selected':''; ?>><?php echo clean($cl['class_name']); ?></option><?php endforeach; ?></select>
            </div>
            <div>
                <label class="text-xs">جستجو</label>
                <input name="search" class="form-input" placeholder="عنوان / درس" value="<?php echo clean($search); ?>">
            </div>
            <button class="btn btn-primary">فیلتر</button>
        </form>
    </div>

    <div class="grid grid-cols-1 gap-4">
        <?php foreach($exams as $ex): 
            try { $qCount = DB::fetch("SELECT COUNT(*) c FROM online_questions WHERE exam_id=?", [$ex['id']]); } catch (Exception $e) { $qCount=['c'=>0]; }
            try { $attemptCount = DB::fetch("SELECT COUNT(*) c FROM online_exam_attempts WHERE exam_id=?", [$ex['id']]); } catch (Exception $e) { $attemptCount=['c'=>0]; }
            try { $liveCount = DB::fetch("SELECT COUNT(*) c FROM online_exam_live_sessions WHERE exam_id=? AND status='active' AND last_heartbeat > DATE_SUB(NOW(), INTERVAL 3 MINUTE)", [$ex['id']]); } catch (Exception $e) { $liveCount=['c'=>0]; }
            $isLive = $liveCount && $liveCount['c']>0;
        ?>
        <div class="card border-l-4 <?php echo $ex['status']=='published'?'border-green-500':'border-gray-300'; ?> hover:shadow-md transition">
            <div class="flex justify-between items-start">
                <div class="flex-1">
                    <div class="flex items-center gap-2 flex-wrap">
                        <h3 class="font-bold text-lg"><?php echo clean($ex['title']); ?></h3>
                        <span class="badge <?php echo $ex['status']=='published'?'badge-success':($ex['status']=='draft'?'badge-warning':'badge-secondary'); ?>"><?php echo online_exam_status_label($ex['status']); ?></span>
                        <?php if($isLive): ?><span class="badge bg-red-500 text-white animate-pulse">🔴 زنده: <?php echo tr_num($liveCount['c'],'fa'); ?> نفر در حال آزمون</span><?php endif; ?>
                        <?php if(!empty($ex['category_name'])): ?><span class="badge badge-info"><?php echo clean($ex['category_name']); ?></span><?php endif; ?>
                        <span class="text-xs text-muted"><?php echo clean($ex['academic_year']); ?> | <?php echo clean($ex['grade_level'].' '.$ex['class_name'].' '.$ex['subject_name']); ?></span>
                    </div>
                    <p class="text-sm text-muted mt-1"><?php echo clean(mb_substr($ex['description']??'',0,150)); ?></p>
                    <div class="flex gap-3 mt-2 text-xs text-muted flex-wrap">
                        <span>⏱️ مدت: <?php echo tr_num($ex['duration_minutes'],'fa'); ?> دقیقه</span>
                        <span>❓ تعداد سوال: <?php echo tr_num($qCount['c'],'fa'); ?></span>
                        <span>👥 شرکت‌کنندگان: <?php echo tr_num($attemptCount['c'],'fa'); ?></span>
                        <span>👨‍🏫 طراح: <?php echo clean($ex['teacher_name'] ? (is_admin_logged_in() ? $ex['teacher_name'] : teacher_family_name($ex['teacher_name'])) : 'مدیر'); ?></span>
                        <?php if(!empty($ex['start_datetime'])): ?><span>🟢 شروع: <?php echo tr_num(jdate('Y/m/d H:i', strtotime($ex['start_datetime'])),'fa'); ?></span><?php endif; ?>
                        <?php if(!empty($ex['end_datetime'])): ?><span>🔴 پایان: <?php echo tr_num(jdate('Y/m/d H:i', strtotime($ex['end_datetime'])),'fa'); ?></span><?php endif; ?>
                    </div>
                </div>
                <div class="flex flex-col gap-1 ml-4">
                    <a href="online-exam-form.php?id=<?php echo $ex['id']; ?>" class="btn btn-secondary text-xs">✏️ ویرایش</a>
                    <a href="online-exam-questions.php?exam_id=<?php echo $ex['id']; ?>" class="btn btn-primary text-xs">🧩 طراحی سوالات (<?php echo tr_num($qCount['c'],'fa'); ?>)</a>
                    <a href="online-exam-monitor.php?exam_id=<?php echo $ex['id']; ?>" class="btn <?php echo $isLive?'btn-danger':'btn-warning'; ?> text-xs">📡 مانیتورینگ زنده<?php if($isLive) echo ' ('.tr_num($liveCount['c'],'fa').')'; ?></a>
                    <a href="online-exam-results.php?exam_id=<?php echo $ex['id']; ?>" class="btn btn-outline text-xs">📊 نتایج</a>
                    <form method="POST" class="inline" onsubmit="return confirm('تغییر وضعیت؟')">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="exam_id" value="<?php echo $ex['id']; ?>">
                        <input type="hidden" name="new_status" value="<?php echo $ex['status']=='published'?'draft':'published'; ?>">
                        <button name="toggle_status" class="btn btn-warning text-xs w-full"><?php echo $ex['status']=='published'?'⏸️ پیش‌نویس':'✅ انتشار'; ?></button>
                    </form>
                    <form method="POST" onsubmit="return confirm('حذف آزمون؟ این عمل قابل بازگشت نیست')">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="exam_id" value="<?php echo $ex['id']; ?>">
                        <button name="delete_exam" class="btn btn-danger text-xs w-full">🗑️ حذف</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; if(empty($exams)): ?>
        <div class="card text-center py-12 text-muted">
            هنوز آزمون آنلاینی ثبت نشده. روی «ایجاد آزمون جدید» کلیک کنید.
            <?php if ($role === 'admin'): /* v4.61.0: فقط برای مدیر — دبیر نباید ابزار عیب‌یابی سیستم را ببیند */ ?>
            <div class="mt-3 text-xs">اگر تازه نصب کرده‌اید، یک بار صفحه را رفرش کنید تا جداول ساخته شوند.</div>
            <div class="mt-2"><a href="online-exam-diagnose.php" class="btn btn-secondary text-xs">بررسی سلامت سیستم</a></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
