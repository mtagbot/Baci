<?php
/**
 * Internal Admin AJAX Endpoint (admin-ajax.php)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/academic_year_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_admin_logged_in()) {
    echo json_encode(['status' => 'error', 'message' => 'غیرمجاز (Unauthorized)']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'search_students') {
    if (!has_permission('manage_students')) {
        echo json_encode(['status' => 'error', 'message' => 'عدم دسترسی']);
        exit;
    }

    $q = trim($_GET['q'] ?? '');
    $className = trim($_GET['class_name'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 10;
    $offset = ($page - 1) * $limit;

    $where = ["1=1"];
    $params = [];

    if (!empty($q)) {
        $where[] = "(first_name LIKE ? OR last_name LIKE ? OR national_id LIKE ? OR student_code LIKE ?)";
        $params[] = "%$q%";
        $params[] = "%$q%";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    if (!empty($className)) {
        $where[] = "class_name = ?";
        $params[] = $className;
    }

    $whereSql = implode(' AND ', $where);
    $totalCount = DB::fetch("SELECT COUNT(*) as c FROM students WHERE $whereSql", $params)['c'] ?? 0;

    $students = DB::fetchAll("SELECT id, national_id, student_code, first_name, last_name, class_name, grade_level, status, is_temp FROM students WHERE $whereSql ORDER BY id DESC LIMIT $limit OFFSET $offset", $params);

    echo json_encode([
        'status' => 'success',
        'data' => $students,
        'pagination' => [
            'total' => (int)$totalCount,
            'page'  => $page,
            'limit' => $limit,
            'pages' => ceil($totalCount / $limit)
        ]
    ]);
    exit;
}

if ($action === 'toggle_lock') {
    if (!has_permission('manage_reports')) {
        echo json_encode(['status' => 'error', 'message' => 'عدم دسترسی']);
        exit;
    }

    $reportId = (int)($_POST['report_id'] ?? 0);
    $report = DB::fetch("SELECT is_locked FROM reports WHERE id = ?", [$reportId]);
    if (!$report) {
        echo json_encode(['status' => 'error', 'message' => 'کارنامه یافت نشد']);
        exit;
    }

    $newLock = $report['is_locked'] ? 0 : 1;
    DB::execute("UPDATE reports SET is_locked = ? WHERE id = ?", [$newLock, $reportId]);
    log_activity($_SESSION['admin_id'], 'تغییر وضعیت مسدودسازی کارنامه', "کارنامه ID: $reportId وضعیت مسدودسازی به $newLock تغییر یافت.");

    echo json_encode(['status' => 'success', 'is_locked' => $newLock]);
    exit;
}

if ($action === 'bulk_lock') {
    if (!has_permission('manage_reports')) {
        echo json_encode(['status' => 'error', 'message' => 'عدم دسترسی']);
        exit;
    }

    $className   = trim($_POST['class_name'] ?? '');
    $studentId   = (int)($_POST['student_id'] ?? 0);
    $year = unify_academic_year(trim($_POST['academic_year'] ?? ''));
    $month       = trim($_POST['report_month'] ?? '');
    $isLocked    = (int)($_POST['is_locked'] ?? 1);
    $reason      = trim($_POST['reason'] ?? 'عدم تسویه حساب یا نقص پرونده');

    $where = ["1=1"];
    $params = [];
    if (!empty($className)) { $where[] = "class_name = ?"; $params[] = $className; }
    if ($studentId > 0) { $where[] = "student_id = ?"; $params[] = $studentId; }
    if (!empty($year)) { $where[] = "academic_year = ?"; $params[] = $year; }
    if (!empty($month)) { $where[] = "report_month = ?"; $params[] = $month; }

    $whereSql = implode(' AND ', $where);
    DB::execute("UPDATE reports SET is_locked = ? WHERE $whereSql", array_merge([$isLocked], $params));

    if ($isLocked) {
        DB::execute("INSERT INTO report_locks (academic_year, report_month, class_name, student_id, is_locked, reason) VALUES (?, ?, ?, ?, ?, ?)", [$year, $month, $className, $studentId ?: null, 1, $reason]);
    } else {
        DB::execute("DELETE FROM report_locks WHERE class_name = ? AND academic_year = ? AND report_month = ?", [$className, $year, $month]);
    }

    log_activity($_SESSION['admin_id'], 'مسدودسازی گروهی کارنامه', "کلاس: $className | وضعیت: $isLocked");
    echo json_encode(['status' => 'success']);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'عملیات نامعتبر']);
exit;
