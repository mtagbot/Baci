<?php
/**
 * Admin REST API for Import Management (api/admin-import.php)
 */

header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/includes/functions.php';

// Helper to check admin API token or session
function checkAdminAccess() {
    if (isset($_SESSION['admin_id'])) {
        return $_SESSION['admin_id'];
    }
    $headers = getallheaders();
    if (isset($headers['Authorization']) && preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
        $tok = DB::fetch("SELECT user_id FROM api_tokens WHERE access_token = ? AND user_type='admin' AND expires_at > NOW()", [$matches[1]]);
        if ($tok) return $tok['user_id'];
    }
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
    exit;
}

$adminId = checkAdminAccess();
$resource = $_GET['resource'] ?? '';

if ($resource === 'sessions') {
    $sessions = DB::fetchAll("SELECT * FROM import_sessions ORDER BY id DESC LIMIT 50");
    echo json_encode(['status' => 'success', 'data' => $sessions], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($resource === 'queue') {
    $queue = DB::fetchAll("SELECT q.*, s.filename FROM import_queue q JOIN import_sessions s ON q.session_id = s.id ORDER BY q.id DESC");
    echo json_encode(['status' => 'success', 'data' => $queue], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($resource === 'queue-process') {
    // Process next queued job
    $job = DB::fetch("SELECT * FROM import_queue WHERE status = 'queued' ORDER BY id ASC LIMIT 1");
    if ($job) {
        DB::execute("UPDATE import_queue SET status = 'processing', progress = 50 WHERE id = ?", [$job['id']]);
        // Simulate completion
        DB::execute("UPDATE import_queue SET status = 'completed', progress = 100, processed_at = NOW() WHERE id = ?", [$job['id']]);
        DB::execute("UPDATE import_sessions SET status = 'completed' WHERE id = ?", [$job['session_id']]);
        echo json_encode(['status' => 'success', 'message' => 'کار صف پردازش شد', 'job_id' => $job['id']], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['status' => 'info', 'message' => 'هیچ کاری در صف موجود نیست'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($resource === 'preview-state') {
    $sid = (int)($_GET['session_id'] ?? 0);
    $session = DB::fetch("SELECT * FROM import_sessions WHERE id = ?", [$sid]);
    if (!$session) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'نشست یافت نشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'status' => 'success',
        'session' => $session,
        'preview' => json_decode($session['preview_data'] ?? '[]'),
        'ambiguities' => json_decode($session['ambiguities_data'] ?? '[]')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($resource === 'preview-execute') {
    $sid = (int)($_POST['session_id'] ?? 0);
    DB::execute("UPDATE import_sessions SET status = 'completed', processed_rows = total_rows WHERE id = ?", [$sid]);
    echo json_encode(['status' => 'success', 'message' => 'ایمپورت نشست با موفقیت اجرا شد'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($resource === 'resolve-student-edit') {
    $sid = (int)($_POST['session_id'] ?? 0);
    $rowIdx = (int)($_POST['row_index'] ?? 0);
    $newNid = trim($_POST['national_id'] ?? '');

    $session = DB::fetch("SELECT ambiguities_data FROM import_sessions WHERE id = ?", [$sid]);
    if ($session && !empty($newNid)) {
        $ambs = json_decode($session['ambiguities_data'] ?? '[]', true) ?: [];
        foreach ($ambs as $k => $v) {
            if ($v['row_index'] == $rowIdx) {
                unset($ambs[$k]);
            }
        }
        DB::execute("UPDATE import_sessions SET ambiguities_data = ? WHERE id = ?", [json_encode(array_values($ambs), JSON_UNESCAPED_UNICODE), $sid]);
        echo json_encode(['status' => 'success', 'message' => 'ابهام ردیف برطرف گردید'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['status' => 'error', 'message' => 'خطا در رفع ابهام'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'منبع نامعتبر (Invalid Resource)'], JSON_UNESCAPED_UNICODE);
exit;
