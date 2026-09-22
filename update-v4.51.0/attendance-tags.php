<?php
// File: attendance-tags.php  (v4.51.0)
/**
 * Printable QR attendance tags for students (card layout, per class or all).
 * QR codes are rendered client-side with the bundled qrcode-generator lib.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/attendance_helpers.php';
require_once __DIR__ . '/includes/school_roles.php';
require_once __DIR__ . '/includes/school_sort.php';

$isAdminAtt = is_admin_logged_in() && has_permission('manage_students');
$isDeputyAtt = is_teacher_logged_in() && teacher_has_deputy($_SESSION['teacher_id'] ?? 0);
if (!$isAdminAtt && !$isDeputyAtt) redirect('admin-login.php?tab=teacher');
ensure_attendance_schema_v2();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['regen_tag'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { set_flash_message('error', 'خطای CSRF'); redirect('attendance-tags.php'); }
    att_regenerate_tag((int)$_POST['student_id']);
    set_flash_message('success', 'تگ جدید برای دانش‌آموز ساخته شد (تگ قبلی باطل شد).');
    redirect('attendance-tags.php?class=' . urlencode($_POST['back_class'] ?? ''));
}

$fClass = trim($_GET['class'] ?? '');
$year = get_setting('current_academic_year', '1404/1405');
$classes = get_unified_class_options($year);
$where = ["s.status='active'"]; $params = [];
if ($fClass !== '') { $where[] = 's.class_name=?'; $params[] = $fClass; }
$students = DB::fetchAll("SELECT s.id, s.first_name, s.last_name, s.national_id, s.class_name, s.grade_level FROM students s WHERE " . implode(' AND ', $where), $params);
persian_usort_students($students);
foreach ($students as &$s) { $tag = att_get_or_create_tag((int)$s['id']); $s['qr'] = att_qr_payload((int)$s['id'], $tag['token']); }
unset($s);
$schoolName = get_setting('school_name', 'آموزشگاه');

require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-6">
    <div class="page-hero flex justify-between items-center no-print">
        <div>
            <h2 class="text-2xl font-bold">تگ‌های QR حضور و غیاب</h2>
            <p class="text-sm text-muted">برای هر دانش‌آموز یک تگ دائمی ساخته می‌شود؛ چاپ کنید و به دانش‌آموزان بدهید</p>
        </div>
        <div class="flex gap-2">
            <a href="attendance.php" class="btn btn-outline text-xs">بازگشت به حضور و غیاب</a>
            <button onclick="window.print()" class="btn btn-primary text-xs">چاپ تگ‌ها</button>
        </div>
    </div>

    <div class="card no-print">
        <form method="GET" class="grid grid-cols-3 gap-3 items-end">
            <div>
                <label class="text-xs">کلاس</label>
                <select name="class" class="form-select">
                    <option value="">همه کلاس‌ها</option>
                    <?php foreach ($classes as $c): ?><option value="<?php echo clean($c['class_name']); ?>" <?php echo $fClass === $c['class_name'] ? 'selected' : ''; ?>><?php echo clean($c['class_name']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-secondary">نمایش</button>
            <div class="text-xs text-muted">تعداد: <?php echo tr_num(count($students), 'fa'); ?> دانش‌آموز</div>
        </form>
    </div>

    <div class="qr-grid" id="qrGrid">
        <?php foreach ($students as $s): ?>
        <div class="qr-card">
            <div class="qr-head"><?php echo clean($schoolName); ?></div>
            <div class="qr-box" data-qr="<?php echo clean($s['qr']); ?>"></div>
            <div class="qr-name"><?php echo clean($s['first_name'] . ' ' . $s['last_name']); ?></div>
            <div class="qr-meta"><?php echo clean($s['class_name'] ?: '---'); ?> — <?php echo tr_num($s['national_id'], 'fa'); ?></div>
            <div class="qr-note">تگ حضور و غیاب — هر روز صبح مقابل اسکنر بگیرید</div>
            <form method="POST" class="no-print qr-regen" onsubmit="return confirm('تگ فعلی باطل و تگ جدید ساخته شود؟ (تگ چاپ‌شده قبلی دیگر کار نمی‌کند)')">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="regen_tag" value="1">
                <input type="hidden" name="student_id" value="<?php echo (int)$s['id']; ?>">
                <input type="hidden" name="back_class" value="<?php echo clean($fClass); ?>">
                <button class="btn btn-outline text-xs">تگ جدید</button>
            </form>
        </div>
        <?php endforeach; if (!$students): ?>
        <div class="card text-center text-muted">دانش‌آموزی یافت نشد.</div>
        <?php endif; ?>
    </div>
</div>

<style>
.qr-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:14px}
.qr-card{background:#fff;border:2px solid #cbd5e1;border-radius:14px;padding:12px;text-align:center;page-break-inside:avoid;break-inside:avoid}
.qr-head{font-size:.66rem;font-weight:700;color:#2563eb;border-bottom:1px dashed #e2e8f0;padding-bottom:6px;margin-bottom:8px}
.qr-box{display:flex;justify-content:center;padding:4px}
.qr-box img,.qr-box canvas{width:150px;height:150px;image-rendering:pixelated}
.qr-name{font-weight:800;font-size:.9rem;margin-top:6px;color:#0f172a}
.qr-meta{font-size:.68rem;color:#64748b;margin-top:2px}
.qr-note{font-size:.58rem;color:#94a3b8;margin-top:6px;border-top:1px dashed #e2e8f0;padding-top:6px}
.qr-regen{margin-top:6px}
@media print{
  .no-print,.sidebar,.navbar,header,nav,.page-hero,footer{display:none !important}
  body{background:#fff !important}
  .qr-grid{grid-template-columns:repeat(3,1fr);gap:8mm}
  .qr-card{border:1.5px solid #000;border-radius:4mm}
}
</style>
<script src="assets/js/qrcode-generator.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('.qr-box[data-qr]').forEach(function(box){
    try {
      var qr = qrcode(0, 'M');
      qr.addData(box.getAttribute('data-qr'));
      qr.make();
      box.innerHTML = qr.createImgTag(5, 8);
    } catch(e) {
      box.textContent = 'خطا در ساخت QR';
    }
  });
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
