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
        DB::execute("UPDATE reports SET is_locked = ? WHERE id = ?", [$newLock, $reportId]);
        $repYears = report_year_variants($rep['academic_year'] ?? '');
        $yMarks = implode(',', array_fill(0, count($repYears), '?'));
        DB::execute(
            "DELETE FROM report_locks WHERE student_id = ? AND report_month = ? AND academic_year IN ($yMarks)",
            array_merge([(int)$rep['student_id'], (string)$rep['report_month']], $repYears)
        );
        if ($newLock === 0 && is_report_prelocked($rep['academic_year'] ?? '', $rep['report_month'] ?? '', $rep['class_name'] ?? '', 0)) {
            DB::execute(
                "INSERT INTO report_locks (academic_year, term, report_month, class_name, student_id, is_locked, reason) VALUES (?, ?, ?, ?, ?, 0, '')",
                [$rep['academic_year'], $rep['term'], $rep['report_month'], $rep['class_name'], (int)$rep['student_id']]
            );
        }
        unset($GLOBALS['__sdp_report_locks_cache']);
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

    set_report_bulk_lock($year, $month, $className, $studentId, $isLocked, $reason);

    log_activity($_SESSION['admin_id'] ?? null, 'مسدودسازی پیشینی/گروهی کارنامه', "وضعیت قفل برای کلاس '$className' در ماه '$month' سال '$year' به $isLocked تغییر کرد.");
    echo json_encode(['status' => 'success']);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
