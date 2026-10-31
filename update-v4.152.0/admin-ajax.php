<?php
/**
 * Admin AJAX Endpoints for Live Operations (admin-ajax.php)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/school_roles.php';
ensure_school_roles_schema();
header('Content-Type: application/json; charset=utf-8');

$canDeputyReports = function_exists('is_teacher_logged_in') && is_teacher_logged_in() && (teacher_has_deputy($_SESSION['teacher_id']) || teacher_has_executive($_SESSION['teacher_id']));
if (!is_admin_logged_in() && !$canDeputyReports) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'toggle_lock') {
    $reportId = (int)($_POST['report_id'] ?? 0);
    $rep = DB::fetch("SELECT * FROM reports WHERE id = ?", [$reportId]);
    if ($rep) {
        ensure_report_locks_schema();
        $currentlyLocked = is_report_locked_for_student($rep);
        $newLock = $currentlyLocked ? 0 : 1;
        /* V4.209.4 — همان قاعدهٔ reports.php: قفل این ردیف یک مسدودیت فردیِ
           مستقل است (با آزادسازی گروهی باز نمی‌شود) و رفع مسدودیت، آزادسازی
           کامل همین دانش‌آموز در همین ماه است. */
        if ($newLock === 1) {
            DB::execute("UPDATE reports SET is_locked = 1 WHERE id = ?", [$reportId]);
            unset($GLOBALS['__sdp_report_locks_cache']);
        } else {
            set_report_bulk_lock($rep['academic_year'] ?? '', $rep['report_month'] ?? '', $rep['class_name'] ?? '', (int)$rep['student_id'], 0, '');
        }
        log_activity($_SESSION['admin_id'] ?? null, 'تغییر قفل کارنامه (AJAX)', "وضعیت قفل کارنامه $reportId به $newLock تغییر کرد.");
        echo json_encode(['status' => 'success', 'is_locked' => $newLock]);
        exit;
    }
    echo json_encode(['status' => 'error', 'message' => 'کارنامه یافت نشد']);
    exit;
}

if ($action === 'bulk_lock') {
    $className = trim($_POST['class_name'] ?? '');
    $year      = trim($_POST['academic_year'] ?? '');
    $month     = trim($_POST['report_month'] ?? '');
    $studentId = (int)($_POST['student_id'] ?? 0);
    $isLocked  = (int)($_POST['is_locked'] ?? 1);
    $reason    = trim($_POST['reason'] ?? 'در حال تکمیل نمرات توسط دبیران');
    /* V4.209.4 — پیش‌فرض «آزادسازی گروهی» است: مسدودیت‌های فردی (بدهی مالی و…)
       دست‌نخورده می‌مانند. فقط اگر مدیر صریحاً تیک «آزادسازی کامل» بزند،
       مسدودیت‌های فردیِ همین محدوده هم برداشته می‌شود. */
    $includeIndividual = (int)($_POST['include_individual'] ?? 0) === 1;

    set_report_bulk_lock($year, $month, $className, $studentId, $isLocked, $reason, $includeIndividual);

    log_activity($_SESSION['admin_id'] ?? null, 'مسدودسازی پیشینی/گروهی کارنامه', "وضعیت قفل برای کلاس '$className' در ماه '$month' سال '$year' به $isLocked تغییر کرد." . ($includeIndividual && $isLocked === 0 ? ' (آزادسازی کامل شامل مسدودیت‌های فردی)' : ''));
    echo json_encode(['status' => 'success', 'include_individual' => $includeIndividual ? 1 : 0]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
