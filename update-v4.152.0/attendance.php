<?php
// File: attendance.php  (v4.50.0)
/**
 * Attendance management: register daily absence/tardiness per class and
 * automatically notify linked parent chats on Bale & Telegram bots.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bot_helpers.php';
require_once __DIR__ . '/includes/attendance_helpers.php';
require_once __DIR__ . '/includes/school_roles.php';
require_once __DIR__ . '/includes/school_sort.php';

$isAdminAtt = is_admin_logged_in() && has_permission('manage_students');
$isDeputyAtt = is_teacher_logged_in() && teacher_has_deputy($_SESSION['teacher_id'] ?? 0);
if (!$isAdminAtt && !$isDeputyAtt) redirect('admin-login.php?tab=teacher');
ensure_attendance_schema_v2();
ensure_school_roles_schema();

$today = att_today();   // v4.75.0: canonical English-digit date everywhere

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { set_flash_message('error', 'خطای امنیتی CSRF.'); redirect('attendance.php'); }

    if (isset($_POST['save_attendance'])) {
        $sid = (int)($_POST['student_id'] ?? 0);
        $status = ($_POST['att_status'] ?? 'absent') === 'late' ? 'late' : 'absent';
        $date = att_norm($_POST['date_jalali'] ?? $today);   // Persian-typed digits accepted
        $minutes = max(0, (int)($_POST['minutes_late'] ?? 0));
        $note = trim($_POST['note'] ?? '');
        $notify = isset($_POST['notify_parents']) ? 1 : 0;
        // v4.76.0: only students of the default academic year are valid targets
        list($attYearSql, $attYearParams) = att_year_sql('s');
        $stu = $sid ? DB::fetch("SELECT s.* FROM students s WHERE s.id=? AND s.status='active' AND $attYearSql", array_merge([$sid], $attYearParams)) : null;
        if (!$stu || $date === '') {
            set_flash_message('error', 'دانش‌آموز یا تاریخ نامعتبر است.');
            redirect('attendance.php');
        }
        $dfDup = att_day_forms($date);
        $dup = DB::fetch("SELECT id FROM student_attendance WHERE student_id=? AND date_jalali IN (" . implode(',', array_fill(0, count($dfDup), '?')) . ")", array_merge([$sid], $dfDup));
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
        $msgTail = $notify ? ($sentChats ? ' و به ' . tr_num($sentChats, 'fa') . ' حساب ربات ولی، اعلان ارسال یا در صف پایدار ثبت شد.' : ' اما هیچ حساب ربات متصلی برای اطلاع‌رسانی یافت نشد.') : '.';
        set_flash_message($notify && !$sentChats ? 'warning' : 'success', ($status === 'late' ? 'تأخیر' : 'غیبت') . ' ثبت شد' . $msgTail);
        redirect('attendance.php?date=' . urlencode($date));
    }

    if (isset($_POST['delete_attendance'])) {
        DB::execute("DELETE FROM student_attendance WHERE id=?", [(int)$_POST['record_id']]);
        set_flash_message('success', 'رکورد حضور و غیاب حذف شد.');
        redirect('attendance.php?date=' . urlencode($_POST['back_date'] ?? ''));
    }

    // v4.75.0: bulk actions on selected attendance records
    if (isset($_POST['bulk_action'])) {
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['rec_ids'] ?? []))));
        $back = 'attendance.php?date=' . urlencode($_POST['back_date'] ?? '');
        if (!$ids) { set_flash_message('warning', 'هیچ رکوردی انتخاب نشده است.'); redirect($back); }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $action = $_POST['bulk_action'];

        if ($action === 'delete') {
            DB::execute("DELETE FROM student_attendance WHERE id IN ($ph)", $ids);
            set_flash_message('success', tr_num(count($ids), 'fa') . ' رکورد انتخاب‌شده حذف شد.');
            redirect($back);
        }

        if ($action === 'file_absents' || $action === 'file_lates') {
            // ثبت در پرونده انضباطی: absences / tardies from the SELECTED rows
            // are copied into student_discipline_records and parents are
            // notified through the bots. Independent from the time-rule
            // notifications (which stay untouched).
            // v4.76.0: filed records go into the MAIN discipline dossier
            // exactly like manual ones — official saved titles «غیبت» /
            // «تأخیر در ورود به مدرسه» (with title_id), so they show up
            // identically in student management and «موارد انضباطی».
            $target = $action === 'file_absents' ? 'absent' : 'late';
            $title = $target === 'late' ? 'تأخیر در ورود به مدرسه' : 'غیبت';
            $titleId = att_discipline_title_id($title);
            $rows = DB::fetchAll("SELECT a.*, s.first_name, s.last_name FROM student_attendance a JOIN students s ON s.id=a.student_id WHERE a.id IN ($ph) AND a.status=?", array_merge($ids, [$target]));
            $filed = 0; $skipped = 0; $notifiedChats = 0;
            foreach ($rows as $rec) {
                // one filing per attendance record — never duplicate.
                // Marker [att#N] lives inside the internal note (also matches
                // v4.75.0 records that stored the bare 'att#N').
                $marker = '[att#' . (int)$rec['id'] . ']';
                $dup = DB::fetch("SELECT id FROM student_discipline_records WHERE student_id=? AND (internal_note=? OR internal_note LIKE ?)",
                    [(int)$rec['student_id'], 'att#' . (int)$rec['id'], '%' . $marker . '%']);
                if ($dup) { $skipped++; continue; }
                // تاریخ و روز همان روز + ساعت دقیق ورود (در تأخیر)
                $dayName = att_day_name($rec['date_jalali']);
                $occur = trim(($dayName !== '' ? $dayName . ' ' : '') . att_norm($rec['date_jalali'])
                        . (!empty($rec['scan_time']) ? ' ' . att_norm($rec['scan_time']) : ''));
                $detail = 'ثبت‌شده از سوابق حضور و غیاب'
                        . ($target === 'late' ? ' — ' . tr_num((string)max(1, (int)$rec['minutes_late']), 'fa') . ' دقیقه تأخیر' : '')
                        . (trim((string)$rec['note']) !== '' ? ' — ' . trim((string)$rec['note']) : '')
                        . ' ' . $marker;
                DB::execute("INSERT INTO student_discipline_records (student_id,title_id,title_text,internal_note,occurred_at_jalali,notify_parents,is_justified,review_status,review_note,created_by_teacher_id,created_at_jalali) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                    [(int)$rec['student_id'], $titleId ?: null, $title, $detail,
                     $occur, 1, 0, 'pending', null,
                     $isDeputyAtt ? (int)$_SESSION['teacher_id'] : null, jalali_now()]);
                $newRid = (int)DB::lastInsertId();
                $filed++;
                try {
                    notify_student_discipline_bots((int)$rec['student_id'], $title, $occur, $newRid);
                    $notifiedChats++;
                } catch (Exception $e) { error_log('file-to-record notify failed: ' . $e->getMessage()); }
            }
            $label = $target === 'late' ? 'تأخیر' : 'غیبت';
            if ($filed) {
                $msg = tr_num($filed, 'fa') . ' مورد ' . $label . ' در پرونده انضباطی ثبت شد؛ اعلان حساب‌های متصل ارسال یا در صف قرار گرفت.';
                if ($skipped) $msg .= ' (' . tr_num($skipped, 'fa') . ' مورد قبلاً ثبت شده بود.)';
                set_flash_message('success', $msg);
            } elseif ($skipped) {
                set_flash_message('info', 'همه موارد انتخاب‌شده قبلاً در پرونده ثبت شده بودند.');
            } else {
                set_flash_message('warning', 'در بین انتخاب‌شده‌ها رکوردی از نوع ' . $label . ' وجود نداشت.');
            }
            log_activity($_SESSION['admin_id'] ?? null, 'ثبت ' . $label . ' در پرونده', tr_num($filed, 'fa') . ' مورد از سوابق حضور و غیاب');
            redirect($back);
        }
        redirect($back);
    }

    if (isset($_POST['renotify'])) {
        $rid = (int)$_POST['record_id'];
        $rec = DB::fetch("SELECT * FROM student_attendance WHERE id=?", [$rid]);
        if ($rec) {
            $sentChats = notify_student_attendance_bots((int)$rec['student_id'], $rec['status'], $rec['date_jalali'], (int)$rec['minutes_late'], (string)$rec['note'], $rid, (string)($rec['scan_time'] ?? ''));
            DB::execute("UPDATE student_attendance SET notified_chats=? WHERE id=?", [$sentChats, $rid]);
            set_flash_message($sentChats ? 'success' : 'warning', $sentChats ? 'اعلان مجدد به ' . tr_num($sentChats, 'fa') . ' حساب ربات ارسال یا در صف پایدار ثبت شد.' : 'حساب ربات متصلی برای این دانش‌آموز یافت نشد.');
        }
        redirect('attendance.php?date=' . urlencode($_POST['back_date'] ?? ''));
    }

    // v4.51.0: smart QR system controls
    if (isset($_POST['save_att_settings'])) {
        $pu = trim($_POST['present_until'] ?? '08:40');
        $aa = trim($_POST['absent_at'] ?? '09:00');
        if (!preg_match('/^\d{1,2}:\d{2}$/', tr_num($pu, 'en'))) $pu = '08:40';
        if (!preg_match('/^\d{1,2}:\d{2}$/', tr_num($aa, 'en'))) $aa = '09:00';
        set_setting('att_present_until', tr_num($pu, 'en'));
        set_setting('att_absent_at', tr_num($aa, 'en'));
        set_setting('att_notify_present', isset($_POST['notify_present']) ? '1' : '0');
        set_setting('att_notify_late', isset($_POST['notify_late']) ? '1' : '0');
        set_setting('att_notify_absent', isset($_POST['notify_absent']) ? '1' : '0');
        // v4.75.0: no auto-absents on off days (holidays) — critical guard
        $offSel = array_intersect((array)($_POST['off_days'] ?? []), ['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه','جمعه']);
        set_setting('att_off_days', $offSel ? implode(',', $offSel) : 'جمعه');
        set_flash_message('success', 'تنظیمات سیستم هوشمند حضور و غیاب ذخیره شد.');
        redirect('attendance.php');
    }

    // v4.77.0: pause/resume switch for the automatic absent-marking
    if (isset($_POST['toggle_auto_finalize'])) {
        $on = $_POST['toggle_auto_finalize'] === '1' ? '1' : '0';
        set_setting('att_auto_finalize', $on);
        set_flash_message('success', $on === '1'
            ? 'اجرای خودکار ثبت غیبت فعال شد — رأس ساعت تنظیم‌شده غیبت‌ها ثبت می‌شوند.'
            : 'اجرای خودکار ثبت غیبت متوقف شد. تا فعال‌سازی مجدد، غیبت خودکار ثبت نمی‌شود (اجرای دستی همچنان در دسترس است).');
        log_activity($_SESSION['admin_id'] ?? null, 'تغییر وضعیت غیبت خودکار', $on === '1' ? 'فعال' : 'متوقف');
        redirect('attendance.php');
    }

    if (isset($_POST['regen_scanner_key'])) {
        require_once __DIR__.'/includes/security_confirmation.php';
        if (!attendance_confirm_admin($_POST['admin_password'] ?? '', $_POST['admin_username'] ?? '')) {
            set_flash_message('error', 'تأیید رمز مدیر انجام نشد؛ کلید قبلی تغییر نکرد. پس از ۵ تلاش ناموفق، ۱۰ دقیقه صبر کنید.');
            redirect('attendance.php');
        }
        att_scanner_key(true);
        log_activity($_SESSION['admin_id'] ?? null, 'تغییر کلید اسکنر', 'کلید اسکنر پس از تأیید رمز مدیر تغییر کرد.');
        set_flash_message('success', 'کلید اسکنر جدید ساخته شد. لینک قبلی اسکنر باطل است.');
        redirect('attendance.php');
    }

    if (isset($_POST['run_finalize'])) {
        $res = att_finalize_absents(true);
        set_flash_message($res['marked'] ? 'success' : 'info', 'ثبت غیبت خودکار اجرا شد: ' . tr_num($res['marked'], 'fa') . ' غیبت جدید، اطلاع‌رسانی به اولیای ' . tr_num($res['notified'], 'fa') . ' دانش‌آموز.');
        redirect('attendance.php');
    }
}

// Opportunistic auto-absent run on each page view after the cutoff.
try { att_finalize_absents(false); } catch (Exception $e) {}

require_once __DIR__ . '/includes/header.php';

$fDate = att_norm($_GET['date'] ?? $today);
$fClass = trim($_GET['class'] ?? '');
$fStatus = trim($_GET['status'] ?? '');
$year = get_setting('current_academic_year', '1404/1405');
$classes = get_unified_class_options($year);
// v4.76.0: attendance operates ONLY on students of the default academic year
list($attYearSql, $attYearParams) = att_year_sql('s');
$students = DB::fetchAll("SELECT s.id, s.first_name, s.last_name, s.national_id, s.class_name, s.grade_level FROM students s WHERE s.status='active' AND $attYearSql", $attYearParams);
persian_usort_students($students);

$where = ['1=1']; $params = [];
if ($fDate !== '') { $dfF = att_day_forms($fDate); $where[] = 'a.date_jalali IN (' . implode(',', array_fill(0, count($dfF), '?')) . ')'; foreach ($dfF as $dfv) $params[] = $dfv; }
if ($fClass !== '') { $where[] = 's.class_name=?'; $params[] = $fClass; }
if (in_array($fStatus, ['present', 'absent', 'late'], true)) { $where[] = 'a.status=?'; $params[] = $fStatus; }
$where[] = $attYearSql; foreach ($attYearParams as $ayp) $params[] = $ayp;   // v4.76.0: current-year students only
$records = DB::fetchAll("SELECT a.*, s.first_name, s.last_name, s.class_name, s.national_id FROM student_attendance a JOIN students s ON s.id=a.student_id WHERE " . implode(' AND ', $where) . " ORDER BY a.id DESC LIMIT 300", $params);

$dfS = att_day_forms($fDate !== '' ? $fDate : $today);
$statTotals = DB::fetch("SELECT SUM(a.status='present') presents, SUM(a.status='absent') absents, SUM(a.status='late') lates, SUM(a.review_status='acknowledged') acked FROM student_attendance a JOIN students s ON s.id=a.student_id WHERE a.date_jalali IN (" . implode(',', array_fill(0, count($dfS), '?')) . ") AND $attYearSql", array_merge($dfS, $attYearParams));
$attTimes = att_setting_times();
$scannerKey = att_scanner_key();
$attProto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$attBase = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '')), '/');
if ($attBase === '.') $attBase = '';
$scannerUrl = $attProto . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $attBase . '/attendance-scanner.php?key=' . urlencode($scannerKey);
?>
<div class="space-y-6">
    <div class="page-hero flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold">حضور و غیاب هوشمند (QR) و اطلاع‌رسانی به اولیا</h2>
            <p class="text-sm text-muted">اسکن تگ QR هنگام ورود، ثبت خودکار حضور/تأخیر/غیبت و اعلان فوری به ربات‌های بله و تلگرام</p>
        </div>
        <div class="flex gap-2">
            <span class="badge badge-success">حضور: <?php echo tr_num((int)($statTotals['presents'] ?? 0), 'fa'); ?></span>
            <span class="badge badge-warning">تأخیر: <?php echo tr_num((int)($statTotals['lates'] ?? 0), 'fa'); ?></span>
            <span class="badge badge-danger">غیبت: <?php echo tr_num((int)($statTotals['absents'] ?? 0), 'fa'); ?></span>
            <span class="badge badge-info">تأیید اولیا: <?php echo tr_num((int)($statTotals['acked'] ?? 0), 'fa'); ?></span>
        </div>
    </div>

    <!-- v4.51.0: smart QR system panel -->
    <div class="grid grid-cols-3 gap-6 responsive-grid">
        <section class="card shadow-lg space-y-3">
            <h3 class="font-bold text-primary border-b pb-2">اسکنر ورودی مدرسه</h3>
            <p class="text-xs text-muted">این لینک را روی رایانه/تبلت دم در مدرسه باز کنید؛ دانش‌آموزان هر صبح تگ خود را مقابل دوربین می‌گیرند.</p>
            <code class="block dir-ltr text-left break-all text-xs soft-panel" style="padding:8px"><?php echo clean($scannerUrl); ?></code>
            <div class="flex gap-2">
                <a href="<?php echo clean($scannerUrl); ?>" target="_blank" class="btn btn-primary text-xs w-full">باز کردن اسکنر</a>
            </div>
            <details class="soft-panel" style="padding:8px">
                <summary class="btn btn-outline text-xs">کلید جدید</summary>
                <form method="POST" class="space-y-2 mt-2" onsubmit="return confirm('کلید اسکنر عوض شود؟ لینک قبلی از کار می‌افتد.')">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="regen_scanner_key" value="1">
                    <p class="text-xs text-muted">صدور کلید جدید فقط با تأیید رمز مدیر انجام می‌شود.</p>
                    <?php if (!$isAdminAtt): ?>
                    <label class="block text-xs">نام کاربری مدیر<input name="admin_username" type="text" required autocomplete="username" class="input w-full" dir="ltr"></label>
                    <?php endif; ?>
                    <label class="block text-xs">رمز عبور مدیر<input name="admin_password" type="password" required autocomplete="current-password" class="input w-full" dir="ltr"></label>
                    <button type="submit" class="btn btn-danger text-xs">تأیید رمز و ساخت کلید جدید</button>
                </form>
            </details>
            <a href="attendance-tags.php" class="btn btn-secondary text-xs w-full">چاپ تگ‌های QR دانش‌آموزان</a>
            <?php /* v4.136.0: کارت ورود دانش‌آموز — بخش جداگانه کنار تگ‌ها */ ?>
            <a href="entry-cards.php" class="btn btn-accent text-xs w-full" style="margin-top:6px">چاپ کارت ورود دانش‌آموزان</a>
        </section>
        <section class="card shadow-lg space-y-3">
            <h3 class="font-bold text-primary border-b pb-2">قوانین زمانی</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="save_att_settings" value="1">
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs font-semibold mb-1">حضور تا ساعت</label>
                        <input name="present_until" class="form-input dir-ltr text-left" value="<?php echo clean($attTimes['present_until']); ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">غیبت از ساعت</label>
                        <input name="absent_at" class="form-input dir-ltr text-left" value="<?php echo clean($attTimes['absent_at']); ?>">
                    </div>
                </div>
                <p class="text-xs text-muted">اسکن بعد از «حضور تا ساعت» = تأخیر (دقیقه‌شمار)؛ نبود اسکن تا «غیبت از ساعت» = غیبت خودکار.</p>
                <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="notify_late" <?php echo get_setting('att_notify_late', '1') === '1' ? 'checked' : ''; ?>> اعلان تأخیر به اولیا</label>
                <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="notify_absent" <?php echo get_setting('att_notify_absent', '1') === '1' ? 'checked' : ''; ?>> اعلان غیبت به اولیا</label>
                <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="notify_present" <?php echo get_setting('att_notify_present', '0') === '1' ? 'checked' : ''; ?>> اعلان «رسیدم مدرسه» برای حضور به‌موقع</label>
                <div>
                    <label class="block text-xs font-semibold mb-1">روزهای تعطیل (غیبت خودکار اجرا نمی‌شود)</label>
                    <div class="flex flex-wrap gap-2">
                        <?php $offNow = array_map('trim', explode(',', get_setting('att_off_days', 'جمعه')));
                        foreach (['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه','جمعه'] as $d): ?>
                            <label class="text-xs flex items-center gap-1"><input type="checkbox" name="off_days[]" value="<?php echo $d; ?>" <?php echo in_array($d, $offNow, true) ? 'checked' : ''; ?>> <?php echo $d; ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button class="btn btn-success w-full text-xs">ذخیره تنظیمات</button>
            </form>
        </section>
        <section class="card shadow-lg space-y-3">
            <h3 class="font-bold text-primary border-b pb-2">غیبت خودکار</h3>
            <?php $autoFinalizeOn = get_setting('att_auto_finalize', '1') === '1'; ?>
            <!-- v4.77.0: on/off control for the automatic absent-marking -->
            <div class="soft-panel" style="padding:10px;border-radius:10px;display:flex;justify-content:space-between;align-items:center;gap:8px;<?php echo $autoFinalizeOn ? 'background:#f0fdf4;border:1px solid #86efac;' : 'background:#fef2f2;border:1px solid #fca5a5;'; ?>">
                <div>
                    <div class="text-xs font-bold"><?php echo $autoFinalizeOn ? 'اجرای خودکار: فعال' : 'اجرای خودکار: متوقف'; ?></div>
                    <div class="text-xs text-muted"><?php echo $autoFinalizeOn ? 'رأس ساعت ' . tr_num($attTimes['absent_at'], 'fa') . ' غیبت‌ها ثبت و به اولیا اطلاع داده می‌شود.' : 'ثبت خودکار غیبت انجام نمی‌شود تا دوباره فعال شود. اجرای دستی همچنان کار می‌کند.'; ?></div>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="toggle_auto_finalize" value="<?php echo $autoFinalizeOn ? '0' : '1'; ?>">
                    <button class="btn <?php echo $autoFinalizeOn ? 'btn-danger' : 'btn-success'; ?> text-xs"><?php echo $autoFinalizeOn ? 'توقف اجرای خودکار' : 'فعال‌سازی اجرای خودکار'; ?></button>
                </form>
            </div>
            <p class="text-xs text-muted">رأس ساعت <?php echo tr_num($attTimes['absent_at'], 'fa'); ?> هر دانش‌آموزی که تگ نزده باشد، خودکار «غیبت» می‌خورد و به ولی اطلاع داده می‌شود. این کار با اولین اسکن یا بازدید بعد از آن ساعت انجام می‌شود؛ برای اجرای فوری دستی:</p>
            <form method="POST" onsubmit="return confirm('همه دانش‌آموزانی که امروز ثبت ورود ندارند غیبت بخورند و به اولیا اطلاع داده شود؟')">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="run_finalize" value="1">
                <button class="btn btn-danger w-full text-xs">اجرای ثبت غیبت خودکار الان</button>
            </form>
            <p class="text-xs text-muted">برای اجرای تمام‌خودکار بدون نیاز به باز بودن صفحه، این آدرس را در Cron هاست (مثلاً ساعت ۰۹:۰۵) تنظیم کنید:</p>
            <code class="block dir-ltr text-left break-all text-xs soft-panel" style="padding:8px"><?php echo clean($attProto . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $attBase . '/attendance-scan-api.php?action=finalize&key=' . urlencode($scannerKey)); ?></code>
        </section>
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
            <div class="flex flex-wrap justify-between items-center gap-2 border-b pb-2">
                <h3 class="font-bold text-primary">سوابق ثبت‌شده</h3>
                <div class="flex gap-2">
                    <button type="submit" form="attBulkForm" name="bulk_action" value="file_absents" class="btn btn-danger text-xs"
                        onclick="return attBulkConfirm(this, 'غیبت‌های دانش‌آموزان انتخاب‌شده در پرونده انضباطی آن‌ها ثبت و به اولیا اطلاع داده شود؟')">ثبت غیبت‌ها در پرونده</button>
                    <button type="submit" form="attBulkForm" name="bulk_action" value="file_lates" class="btn btn-warning text-xs"
                        onclick="return attBulkConfirm(this, 'تأخیرهای دانش‌آموزان انتخاب‌شده در پرونده انضباطی آن‌ها ثبت و به اولیا اطلاع داده شود؟')">ثبت تأخیرها در پرونده</button>
                    <button type="submit" form="attBulkForm" name="bulk_action" value="delete" class="btn btn-outline text-xs"
                        onclick="return attBulkConfirm(this, 'رکوردهای انتخاب‌شده حذف شوند؟')">حذف انتخاب شده‌ها</button>
                </div>
            </div>
            <form method="GET" class="grid grid-cols-4 gap-2 items-end">
                <div><label class="text-xs">تاریخ</label><input name="date" class="form-input dir-ltr text-left" value="<?php echo clean($fDate); ?>" placeholder="خالی = همه"></div>
                <div><label class="text-xs">کلاس</label><select name="class" class="form-select"><option value="">همه</option><?php foreach ($classes as $c): ?><option value="<?php echo clean($c['class_name']); ?>" <?php echo $fClass === $c['class_name'] ? 'selected' : ''; ?>><?php echo clean($c['class_name']); ?></option><?php endforeach; ?></select></div>
                <div><label class="text-xs">نوع</label><select name="status" class="form-select"><option value="">همه</option><option value="present" <?php echo $fStatus === 'present' ? 'selected' : ''; ?>>حضور</option><option value="absent" <?php echo $fStatus === 'absent' ? 'selected' : ''; ?>>غیبت</option><option value="late" <?php echo $fStatus === 'late' ? 'selected' : ''; ?>>تأخیر</option></select></div>
                <button class="btn btn-secondary">فیلتر</button>
            </form>
            <form method="POST" id="attBulkForm">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="back_date" value="<?php echo clean($fDate); ?>">
            </form>
            <div class="table-container max-h-[480px]">
                <table>
                    <thead><tr><th style="width:34px"><input type="checkbox" id="attCheckAll" onclick="attToggleAll(this)"></th><th>دانش‌آموز</th><th>کلاس</th><th>تاریخ</th><th>نوع</th><th>ورود</th><th>منبع</th><th>اعلان ربات</th><th>وضعیت ولی</th><th>عملیات</th></tr></thead>
                    <tbody>
                    <?php foreach ($records as $r): ?>
                        <tr>
                            <td><input type="checkbox" class="att-rec-check" name="rec_ids[]" value="<?php echo (int)$r['id']; ?>" form="attBulkForm"></td>
                            <td class="font-bold"><?php echo clean($r['first_name'] . ' ' . $r['last_name']); ?></td>
                            <td><span class="badge badge-info"><?php echo clean($r['class_name']); ?></span></td>
                            <td><?php echo tr_num($r['date_jalali'], 'fa'); ?></td>
                            <td>
                                <?php if ($r['status'] === 'late'): ?>
                                    <span class="badge badge-warning">تأخیر <?php echo tr_num((int)$r['minutes_late'], 'fa'); ?> دقیقه</span>
                                <?php elseif ($r['status'] === 'present'): ?>
                                    <span class="badge badge-success">حضور</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">غیبت</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-xs dir-ltr"><?php echo !empty($r['scan_time']) ? tr_num($r['scan_time'], 'fa') : '---'; ?></td>
                            <td class="text-xs"><?php echo ($r['source'] ?? 'manual') === 'qr' ? 'اسکن QR' : (($r['source'] ?? '') === 'auto' ? 'خودکار' : 'دستی'); ?></td>
                            <td class="text-xs"><?php echo (int)$r['notified_chats'] > 0 ? ('ارسال/صف برای ' . tr_num((int)$r['notified_chats'], 'fa') . ' چت') : '<span class="text-muted">ارسال نشده</span>'; ?></td>
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
                        <tr><td colspan="10" class="text-center text-muted py-6">رکوردی با این فیلتر یافت نشد.</td></tr>
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
function attToggleAll(box){
  document.querySelectorAll('.att-rec-check').forEach(function(c){ c.checked = box.checked; });
}
function attBulkConfirm(btn, msg){
  var n = document.querySelectorAll('.att-rec-check:checked').length;
  if (!n) { alert('ابتدا رکوردهای مورد نظر را از لیست انتخاب کنید.'); return false; }
  return confirm(msg + ' (' + n + ' رکورد انتخاب شده)');
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
