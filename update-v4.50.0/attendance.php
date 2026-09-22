<?php
// File: attendance.php  (v4.50.0)
/**
 * Attendance management: register daily absence/tardiness per class and
 * automatically notify linked parent chats on Bale & Telegram bots.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bot_helpers.php';
require_once __DIR__ . '/includes/school_roles.php';
require_once __DIR__ . '/includes/school_sort.php';

$isAdminAtt = is_admin_logged_in() && has_permission('manage_students');
$isDeputyAtt = is_teacher_logged_in() && teacher_has_deputy($_SESSION['teacher_id'] ?? 0);
if (!$isAdminAtt && !$isDeputyAtt) redirect('admin-login.php?tab=teacher');
ensure_attendance_schema();
ensure_school_roles_schema();

$today = jdate('Y/m/d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { set_flash_message('error', 'خطای امنیتی CSRF.'); redirect('attendance.php'); }

    if (isset($_POST['save_attendance'])) {
        $sid = (int)($_POST['student_id'] ?? 0);
        $status = ($_POST['att_status'] ?? 'absent') === 'late' ? 'late' : 'absent';
        $date = trim($_POST['date_jalali'] ?? $today);
        $minutes = max(0, (int)($_POST['minutes_late'] ?? 0));
        $note = trim($_POST['note'] ?? '');
        $notify = isset($_POST['notify_parents']) ? 1 : 0;
        $stu = $sid ? DB::fetch("SELECT * FROM students WHERE id=? AND status='active'", [$sid]) : null;
        if (!$stu || $date === '') {
            set_flash_message('error', 'دانش‌آموز یا تاریخ نامعتبر است.');
            redirect('attendance.php');
        }
        $dup = DB::fetch("SELECT id FROM student_attendance WHERE student_id=? AND date_jalali=?", [$sid, $date]);
        if ($dup) {
            set_flash_message('warning', 'برای این دانش‌آموز در این تاریخ قبلاً رکورد ثبت شده است.');
            redirect('attendance.php?date=' . urlencode($date));
        }
        DB::execute("INSERT INTO student_attendance (student_id, academic_year, date_jalali, status, minutes_late, note, created_by_admin_id, created_by_teacher_id, created_at_jalali) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$sid, $stu['academic_year'] ?: get_setting('current_academic_year', ''), $date, $status, $status === 'late' ? $minutes : 0, $note !== '' ? $note : null, $isAdminAtt ? (int)($_SESSION['admin_id'] ?? 0) : null, $isDeputyAtt ? (int)$_SESSION['teacher_id'] : null, jalali_now()]);
        $rid = (int)DB::lastInsertId();
        $sentChats = 0;
        if ($notify) {
            $sentChats = notify_student_attendance_bots($sid, $status, $date, $minutes, $note, $rid);
            DB::execute("UPDATE student_attendance SET notified_chats=? WHERE id=?", [$sentChats, $rid]);
        }
        log_activity($_SESSION['admin_id'] ?? null, 'ثبت ' . ($status === 'late' ? 'تأخیر' : 'غیبت'), 'دانش‌آموز #' . $sid . ' تاریخ ' . $date . ' اعلان به ' . $sentChats . ' چت');
        $msgTail = $notify ? ($sentChats ? ' و به ' . tr_num($sentChats, 'fa') . ' حساب ربات ولی اطلاع‌رسانی شد.' : ' اما هیچ حساب ربات متصلی برای اطلاع‌رسانی یافت نشد.') : '.';
        set_flash_message($notify && !$sentChats ? 'warning' : 'success', ($status === 'late' ? 'تأخیر' : 'غیبت') . ' ثبت شد' . $msgTail);
        redirect('attendance.php?date=' . urlencode($date));
    }

    if (isset($_POST['delete_attendance'])) {
        DB::execute("DELETE FROM student_attendance WHERE id=?", [(int)$_POST['record_id']]);
        set_flash_message('success', 'رکورد حضور و غیاب حذف شد.');
        redirect('attendance.php?date=' . urlencode($_POST['back_date'] ?? ''));
    }

    if (isset($_POST['renotify'])) {
        $rid = (int)$_POST['record_id'];
        $rec = DB::fetch("SELECT * FROM student_attendance WHERE id=?", [$rid]);
        if ($rec) {
            $sentChats = notify_student_attendance_bots((int)$rec['student_id'], $rec['status'], $rec['date_jalali'], (int)$rec['minutes_late'], (string)$rec['note'], $rid);
            DB::execute("UPDATE student_attendance SET notified_chats=? WHERE id=?", [$sentChats, $rid]);
            set_flash_message($sentChats ? 'success' : 'warning', $sentChats ? 'اعلان مجدد به ' . tr_num($sentChats, 'fa') . ' حساب ربات ارسال شد.' : 'حساب ربات متصلی برای این دانش‌آموز یافت نشد.');
        }
        redirect('attendance.php?date=' . urlencode($_POST['back_date'] ?? ''));
    }
}

require_once __DIR__ . '/includes/header.php';

$fDate = trim($_GET['date'] ?? $today);
$fClass = trim($_GET['class'] ?? '');
$fStatus = trim($_GET['status'] ?? '');
$year = get_setting('current_academic_year', '1404/1405');
$classes = get_unified_class_options($year);
$students = DB::fetchAll("SELECT id, first_name, last_name, national_id, class_name, grade_level FROM students WHERE status='active'");
persian_usort_students($students);

$where = ['1=1']; $params = [];
if ($fDate !== '') { $where[] = 'a.date_jalali=?'; $params[] = $fDate; }
if ($fClass !== '') { $where[] = 's.class_name=?'; $params[] = $fClass; }
if ($fStatus === 'absent' || $fStatus === 'late') { $where[] = 'a.status=?'; $params[] = $fStatus; }
$records = DB::fetchAll("SELECT a.*, s.first_name, s.last_name, s.class_name, s.national_id FROM student_attendance a JOIN students s ON s.id=a.student_id WHERE " . implode(' AND ', $where) . " ORDER BY a.id DESC LIMIT 300", $params);

$statTotals = DB::fetch("SELECT SUM(status='absent') absents, SUM(status='late') lates, SUM(review_status='acknowledged') acked FROM student_attendance WHERE date_jalali=?", [$fDate]);
?>
<div class="space-y-6">
    <div class="page-hero flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold">حضور و غیاب و اطلاع‌رسانی به اولیا</h2>
            <p class="text-sm text-muted">ثبت غیبت و تأخیر روزانه با ارسال خودکار اعلان به ربات‌های بله و تلگرام اولیا</p>
        </div>
        <div class="flex gap-2">
            <span class="badge badge-danger">غیبت امروز: <?php echo tr_num((int)($statTotals['absents'] ?? 0), 'fa'); ?></span>
            <span class="badge badge-warning">تأخیر امروز: <?php echo tr_num((int)($statTotals['lates'] ?? 0), 'fa'); ?></span>
            <span class="badge badge-success">تأیید اولیا: <?php echo tr_num((int)($statTotals['acked'] ?? 0), 'fa'); ?></span>
        </div>
    </div>

    <div class="grid grid-cols-3 gap-6 responsive-grid">
        <section class="card shadow-lg space-y-3">
            <h3 class="font-bold text-primary border-b pb-2">ثبت مورد جدید</h3>
            <form method="POST" class="space-y-3" id="attForm">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="save_attendance" value="1">
                <div>
                    <label class="block text-xs font-semibold mb-1">دانش‌آموز</label>
                    <select name="student_id" class="form-select" required>
                        <option value="">انتخاب کنید...</option>
                        <?php foreach ($students as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>"><?php echo clean($s['first_name'] . ' ' . $s['last_name'] . ' — ' . $s['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs font-semibold mb-1">نوع</label>
                        <select name="att_status" id="attStatus" class="form-select" onchange="attToggleMinutes()">
                            <option value="absent">غیبت</option>
                            <option value="late">تأخیر</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">تاریخ</label>
                        <input name="date_jalali" class="form-input dir-ltr text-left" value="<?php echo clean($fDate ?: $today); ?>" required>
                    </div>
                </div>
                <div id="attMinutesBox" style="display:none">
                    <label class="block text-xs font-semibold mb-1">دقیقه تأخیر</label>
                    <input type="number" name="minutes_late" min="1" max="600" class="form-input dir-ltr text-left" value="15">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1">توضیحات (اختیاری — برای ولی ارسال می‌شود)</label>
                    <textarea name="note" rows="2" class="form-textarea text-xs"></textarea>
                </div>
                <label class="flex items-center gap-2 text-xs font-semibold">
                    <input type="checkbox" name="notify_parents" checked>
                    ارسال اعلان فوری به ربات‌های بله و تلگرام ولی
                </label>
                <button type="submit" class="btn btn-primary w-full">ثبت و اطلاع‌رسانی</button>
            </form>
        </section>

        <section class="card col-span-2 shadow-lg space-y-4">
            <h3 class="font-bold text-primary border-b pb-2">سوابق ثبت‌شده</h3>
            <form method="GET" class="grid grid-cols-4 gap-2 items-end">
                <div><label class="text-xs">تاریخ</label><input name="date" class="form-input dir-ltr text-left" value="<?php echo clean($fDate); ?>" placeholder="خالی = همه"></div>
                <div><label class="text-xs">کلاس</label><select name="class" class="form-select"><option value="">همه</option><?php foreach ($classes as $c): ?><option value="<?php echo clean($c['class_name']); ?>" <?php echo $fClass === $c['class_name'] ? 'selected' : ''; ?>><?php echo clean($c['class_name']); ?></option><?php endforeach; ?></select></div>
                <div><label class="text-xs">نوع</label><select name="status" class="form-select"><option value="">همه</option><option value="absent" <?php echo $fStatus === 'absent' ? 'selected' : ''; ?>>غیبت</option><option value="late" <?php echo $fStatus === 'late' ? 'selected' : ''; ?>>تأخیر</option></select></div>
                <button class="btn btn-secondary">فیلتر</button>
            </form>
            <div class="table-container max-h-[480px]">
                <table>
                    <thead><tr><th>دانش‌آموز</th><th>کلاس</th><th>تاریخ</th><th>نوع</th><th>اعلان ربات</th><th>وضعیت ولی</th><th>عملیات</th></tr></thead>
                    <tbody>
                    <?php foreach ($records as $r): ?>
                        <tr>
                            <td class="font-bold"><?php echo clean($r['first_name'] . ' ' . $r['last_name']); ?></td>
                            <td><span class="badge badge-info"><?php echo clean($r['class_name']); ?></span></td>
                            <td><?php echo tr_num($r['date_jalali'], 'fa'); ?></td>
                            <td>
                                <?php if ($r['status'] === 'late'): ?>
                                    <span class="badge badge-warning">تأخیر <?php echo tr_num((int)$r['minutes_late'], 'fa'); ?> دقیقه</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">غیبت</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-xs"><?php echo (int)$r['notified_chats'] > 0 ? ('ارسال به ' . tr_num((int)$r['notified_chats'], 'fa') . ' چت') : '<span class="text-muted">ارسال نشده</span>'; ?></td>
                            <td class="text-xs"><?php echo $r['review_status'] === 'acknowledged' ? '<span class="badge badge-success">اطلاع یافت</span>' : '<span class="text-muted">در انتظار</span>'; ?></td>
                            <td>
                                <div class="flex gap-1">
                                    <form method="POST" onsubmit="return confirm('اعلان مجدد برای ولی ارسال شود؟')">
                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                        <input type="hidden" name="renotify" value="1">
                                        <input type="hidden" name="record_id" value="<?php echo (int)$r['id']; ?>">
                                        <input type="hidden" name="back_date" value="<?php echo clean($fDate); ?>">
                                        <button class="btn btn-secondary text-xs">اعلان مجدد</button>
                                    </form>
                                    <form method="POST" onsubmit="return confirm('این رکورد حذف شود؟')">
                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                        <input type="hidden" name="delete_attendance" value="1">
                                        <input type="hidden" name="record_id" value="<?php echo (int)$r['id']; ?>">
                                        <input type="hidden" name="back_date" value="<?php echo clean($fDate); ?>">
                                        <button class="btn btn-danger text-xs">حذف</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; if (!$records): ?>
                        <tr><td colspan="7" class="text-center text-muted py-6">رکوردی با این فیلتر یافت نشد.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<script>
function attToggleMinutes(){
  var s = document.getElementById('attStatus');
  var b = document.getElementById('attMinutesBox');
  if (s && b) b.style.display = s.value === 'late' ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', attToggleMinutes);
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
