<?php
/**
 * Mobile / App Internal API Endpoint (api/index.php)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(__DIR__) . '/includes/functions.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Helper to send JSON response
function sendJson($status, $data = null, $message = '', $code = 200) {
    http_response_code($code);
    echo json_encode([
        'status'  => $status,
        'message' => $message,
        'data'    => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Helper to authenticate request via Token
function authenticateApi() {
    $headers = getallheaders();
    $token = '';
    if (isset($headers['Authorization']) && preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
        $token = $matches[1];
    } elseif (isset($_GET['token']) || isset($_POST['token'])) {
        $token = $_GET['token'] ?? $_POST['token'];
    }

    if (empty($token)) {
        sendJson('error', null, 'توکن احراز هویت ارسال نشده است (Missing Authorization Token)', 401);
    }

    $tokenRecord = DB::fetch("SELECT * FROM api_tokens WHERE access_token = ? AND expires_at > NOW()", [$token]);
    if (!$tokenRecord) {
        sendJson('error', null, 'توکن نامعتبر یا منقضی شده است (Invalid or Expired Token)', 401);
    }

    return $tokenRecord;
}

// 1) config action
if ($action === 'config') {
    sendJson('success', [
        'app_name'    => get_setting('school_name', 'سیستم مدیریت کارنامه دانش‌آموزی'),
        'version'     => get_setting('system_version', '2.5.0'),
        'theme_color' => get_setting('theme_color', '#2563eb'),
        'logo_url'    => get_setting('logo_url', ''),
        'jalali_date' => jdate('Y/m/d H:i')
    ], 'تنظیمات عمومی دریافت شد.');
}

// 2) student_login action
if ($action === 'student_login') {
    $nid  = tr_num(trim($_POST['national_id'] ?? json_decode(file_get_contents('php://input'), true)['national_id'] ?? ''), 'en');
    $pass = $_POST['password'] ?? json_decode(file_get_contents('php://input'), true)['password'] ?? '';

    $st = DB::fetch("SELECT * FROM students WHERE national_id = ? AND status='active'", [$nid]);
    if ($st && (password_verify($pass, $st['password']) || $pass === $st['password'] || $pass === $st['national_id'])) {
        $access  = bin2hex(random_bytes(32));
        $refresh = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + (86400 * 30)); // 30 days

        DB::execute("INSERT INTO api_tokens (user_id, user_type, access_token, refresh_token, expires_at) VALUES (?, 'student', ?, ?, ?)",
        [$st['id'], $access, $refresh, $expires]);

        sendJson('success', [
            'access_token'  => $access,
            'refresh_token' => $refresh,
            'expires_at'    => $expires,
            'user' => [
                'id'          => $st['id'],
                'name'        => $st['first_name'] . ' ' . $st['last_name'],
                'national_id' => $st['national_id'],
                'class_name'  => $st['class_name']
            ]
        ], 'ورود دانش‌آموز با موفقیت انجام شد.');
    }
    sendJson('error', null, 'کد ملی یا کلمه عبور نادرست است.', 401);
}

// 3) admin_login action
if ($action === 'admin_login') {
    $user = trim($_POST['username'] ?? json_decode(file_get_contents('php://input'), true)['username'] ?? '');
    $pass = $_POST['password'] ?? json_decode(file_get_contents('php://input'), true)['password'] ?? '';

    $adm = DB::fetch("SELECT * FROM admins WHERE username = ? AND status=1", [$user]);
    if ($adm && (password_verify($pass, $adm['password']) || $pass === $adm['password'])) {
        $access  = bin2hex(random_bytes(32));
        $refresh = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + (86400 * 30));

        DB::execute("INSERT INTO api_tokens (user_id, user_type, access_token, refresh_token, expires_at) VALUES (?, 'admin', ?, ?, ?)",
        [$adm['id'], $access, $refresh, $expires]);

        sendJson('success', [
            'access_token'  => $access,
            'refresh_token' => $refresh,
            'expires_at'    => $expires,
            'user' => [
                'id'       => $adm['id'],
                'name'     => $adm['name'],
                'username' => $adm['username'],
                'role'     => $adm['role']
            ]
        ], 'ورود مدیر با موفقیت انجام شد.');
    }
    sendJson('error', null, 'نام کاربری یا کلمه عبور مدیریت نادرست است.', 401);
}

// 4) refresh_token action
if ($action === 'refresh_token') {
    $rt = trim($_POST['refresh_token'] ?? json_decode(file_get_contents('php://input'), true)['refresh_token'] ?? '');
    $tok = DB::fetch("SELECT * FROM api_tokens WHERE refresh_token = ?", [$rt]);
    if ($tok) {
        $access  = bin2hex(random_bytes(32));
        $refresh = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + (86400 * 30));

        DB::execute("UPDATE api_tokens SET access_token=?, refresh_token=?, expires_at=? WHERE id=?",
        [$access, $refresh, $expires, $tok['id']]);

        sendJson('success', [
            'access_token'  => $access,
            'refresh_token' => $refresh,
            'expires_at'    => $expires
        ], 'توکن جدید صادر شد.');
    }
    sendJson('error', null, 'توکن بازیابی نامعتبر است.', 401);
}

// Authenticated Actions
$auth = authenticateApi();

// 5) me action
if ($action === 'me') {
    if ($auth['user_type'] === 'student') {
        $st = DB::fetch("SELECT id, national_id, first_name, last_name, father_name, class_name, grade_level FROM students WHERE id = ?", [$auth['user_id']]);
        sendJson('success', $st);
    } else {
        $adm = DB::fetch("SELECT id, username, name, role, email, mobile FROM admins WHERE id = ?", [$auth['user_id']]);
        sendJson('success', $adm);
    }
}

// 6) reports action
if ($action === 'reports') {
    if ($auth['user_type'] === 'student') {
        $reps = DB::fetchAll("SELECT id, academic_year, term, report_month, total_score, gpa, is_locked FROM reports WHERE student_id = ? ORDER BY id DESC", [$auth['user_id']]);
        sendJson('success', $reps);
    } else {
        $reps = DB::fetchAll("SELECT r.*, s.first_name, s.last_name FROM reports r JOIN students s ON r.student_id = s.id ORDER BY r.id DESC LIMIT 50");
        sendJson('success', $reps);
    }
}

// 7) report_detail action
if ($action === 'report_detail') {
    $rid = (int)($_GET['id'] ?? 0);
    $rep = DB::fetch("SELECT * FROM reports WHERE id = ?", [$rid]);
    if (!$rep) sendJson('error', null, 'کارنامه یافت نشد.', 404);

    if ($auth['user_type'] === 'student' && ($rep['student_id'] != $auth['user_id'] || $rep['is_locked'])) {
        sendJson('error', null, 'غیرمجاز یا مسدود', 403);
    }

    $grades = DB::fetchAll("SELECT subject_name, score, max_score, coefficient, status FROM report_grades WHERE report_id = ?", [$rid]);
    sendJson('success', [
        'info'   => $rep,
        'grades' => $grades
    ]);
}

// 8) notifications action
if ($action === 'notifications') {
    if ($auth['user_type'] === 'student') {
        $st = DB::fetch("SELECT class_name FROM students WHERE id = ?", [$auth['user_id']]);
        $notifs = DB::fetchAll("SELECT id, title, message, created_by, created_at FROM notifications WHERE target_type='all' OR (target_type='class' AND target_value=?) ORDER BY id DESC LIMIT 20", [$st['class_name']]);
    } else {
        $notifs = DB::fetchAll("SELECT * FROM notifications ORDER BY id DESC LIMIT 50");
    }
    sendJson('success', $notifs);
}

// 9) admin_dashboard action
if ($action === 'admin_dashboard') {
    if ($auth['user_type'] !== 'admin') sendJson('error', null, 'عدم دسترسی', 403);
    $stats = [
        'students_count' => DB::fetch("SELECT COUNT(*) as c FROM students WHERE status='active'")['c'] ?? 0,
        'classes_count'  => DB::fetch("SELECT COUNT(*) as c FROM classes")['c'] ?? 0,
        'reports_count'  => DB::fetch("SELECT COUNT(*) as c FROM reports")['c'] ?? 0,
    ];
    sendJson('success', $stats);
}

sendJson('error', null, 'عملیات نامشخص (Unknown Action)', 400);
