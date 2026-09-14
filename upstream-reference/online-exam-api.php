<?php
// File: online-exam-api.php - API for online exam proctoring & live
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/online_exam_helpers.php';
ensure_online_exams_schema();

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function json_response($ok, $data = [], $msg = '') {
    echo json_encode(['ok'=>$ok,'msg'=>$msg,'data'=>$data], JSON_UNESCAPED_UNICODE);
    exit;
}

// Helper: get current teacher/admin/student
function current_online_role() {
    if (!empty($_SESSION['admin_id'])) return ['type'=>'admin','id'=>$_SESSION['admin_id']];
    if (!empty($_SESSION['teacher_id'])) return ['type'=>'teacher','id'=>$_SESSION['teacher_id']];
    if (!empty($_SESSION['student_id'])) return ['type'=>'student','id'=>$_SESSION['student_id']];
    return null;
}

$role = current_online_role();
if (!$role) json_response(false, [], 'دسترسی غیرمجاز - ورود لازم است');

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

if ($action === 'heartbeat' && $role['type'] === 'student') {
    $attemptId = (int)($_POST['attempt_id'] ?? 0);
    $examId = (int)($_POST['exam_id'] ?? 0);
    $lat = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float)$_POST['lat'] : null;
    $lng = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float)$_POST['lng'] : null;
    $acc = isset($_POST['accuracy']) && $_POST['accuracy'] !== '' ? (float)$_POST['accuracy'] : null;
    $camera_ok = isset($_POST['camera_ok']) ? (int)$_POST['camera_ok'] : 0;
    $mic_ok = isset($_POST['mic_ok']) ? (int)$_POST['mic_ok'] : 0;
    $location_ok = isset($_POST['location_ok']) ? (int)$_POST['location_ok'] : 0;
    $internet_quality = trim($_POST['internet_quality'] ?? '');

    if ($attemptId <=0) json_response(false, [], 'attempt نامعتبر');

    $attempt = DB::fetch("SELECT * FROM online_exam_attempts WHERE id=? AND student_id=?", [$attemptId, $role['id']]);
    if (!$attempt) json_response(false, [], 'attempt یافت نشد');

    // If exam ended, mark offline?
    $now = date('Y-m-d H:i:s');
    DB::execute("UPDATE online_exam_attempts SET ip_address=?, geo_lat=?, geo_lng=?, geo_accuracy=?, camera_ok=?, mic_ok=?, location_ok=?, internet_quality=? WHERE id=?", [$ip, $lat, $lng, $acc, $camera_ok, $mic_ok, $location_ok, $internet_quality, $attemptId]);

    // Live session upsert
    $live = DB::fetch("SELECT id FROM online_exam_live_sessions WHERE attempt_id=?", [$attemptId]);
    if ($live) {
        DB::execute("UPDATE online_exam_live_sessions SET last_heartbeat=?, ip_address=?, lat=?, lng=?, accuracy=?, camera_ok=?, mic_ok=?, location_ok=?, status='active', updated_at=? WHERE attempt_id=?", [$now,$ip,$lat,$lng,$acc,$camera_ok,$mic_ok,$location_ok,$now,$attemptId]);
    } else {
        DB::execute("INSERT INTO online_exam_live_sessions (attempt_id, student_id, exam_id, last_heartbeat, ip_address, lat, lng, accuracy, camera_ok, mic_ok, location_ok, status) VALUES (?,?,?,?,?,?,?,?,?,?,?, 'active')", [$attemptId, $role['id'], $examId ?: $attempt['exam_id'], $now,$ip,$lat,$lng,$acc,$camera_ok,$mic_ok,$location_ok]);
    }

    // Check pending webcam requests for this attempt
    $pending = DB::fetchAll("SELECT * FROM online_exam_webcam_requests WHERE attempt_id=? AND status='pending' ORDER BY id DESC", [$attemptId]);

    // Cleanup old snapshots occasionally (10% chance)
    if (rand(1,10)===1) online_exam_cleanup_old_snapshots(10);

    json_response(true, ['pending_webcam_requests'=>$pending, 'server_time'=>$now], 'ok');
}

if ($action === 'proctoring_log' && $role['type'] === 'student') {
    $attemptId = (int)($_POST['attempt_id'] ?? 0);
    $examId = (int)($_POST['exam_id'] ?? 0);
    $eventType = trim($_POST['event_type'] ?? '');
    $eventData = trim($_POST['event_data'] ?? '');
    if ($attemptId <=0 || $eventType==='') json_response(false, [], 'پارامتر ناقص');

    $allowed = ['tab_hidden','tab_visible','window_blurred','window_focused','fullscreen_exit','fullscreen_enter','copy_attempt','paste_attempt','right_click','printscreen','permission_revoked_camera','permission_revoked_mic','permission_revoked_location','permission_granted_camera','permission_granted_mic','permission_granted_location','ip_changed','location_changed','page_unload','minimize','contextmenu_blocked'];
    // allow any but log
    DB::execute("INSERT INTO online_exam_proctoring_logs (attempt_id, student_id, exam_id, event_type, event_data, ip_address) VALUES (?,?,?,?,?,?)", [$attemptId, $role['id'], $examId, $eventType, $eventData, $ip]);

    // Update counts in attempts and live
    if (in_array($eventType, ['tab_hidden','window_blurred','minimize','fullscreen_exit'])) {
        DB::execute("UPDATE online_exam_attempts SET tab_switch_count = tab_switch_count + 1, exit_count = exit_count + 1 WHERE id=?", [$attemptId]);
        DB::execute("UPDATE online_exam_live_sessions SET tab_switch_count = tab_switch_count + 1, exit_count = exit_count + 1 WHERE attempt_id=?", [$attemptId]);
    }
    if (in_array($eventType, ['copy_attempt','paste_attempt','contextmenu_blocked'])) {
        DB::execute("UPDATE online_exam_attempts SET copy_attempts = copy_attempts + 1 WHERE id=?", [$attemptId]);
        DB::execute("UPDATE online_exam_live_sessions SET copy_attempts = copy_attempts + 1 WHERE attempt_id=?", [$attemptId]);
    }

    json_response(true, [], 'logged');
}

if ($action === 'save_answer' && $role['type'] === 'student') {
    $attemptId = (int)($_POST['attempt_id'] ?? 0);
    $questionId = (int)($_POST['question_id'] ?? 0);
    $answerJson = $_POST['answer_data'] ?? '';
    if ($attemptId <=0 || $questionId<=0) json_response(false, [], 'پارامتر نامعتبر');

    // Validate attempt owner and status
    $attempt = DB::fetch("SELECT * FROM online_exam_attempts WHERE id=? AND student_id=? AND status='in_progress'", [$attemptId, $role['id']]);
    if (!$attempt) json_response(false, [], 'آزمون فعال یافت نشد یا به پایان رسیده');

    // Check if question belongs to exam
    $q = DB::fetch("SELECT * FROM online_questions WHERE id=? AND exam_id=?", [$questionId, $attempt['exam_id']]);
    if (!$q) json_response(false, [], 'سوال نامعتبر');

    // answer_data may be JSON string or array
    if (is_array($answerJson)) $answerDataArr = $answerJson;
    else {
        $answerDataArr = json_decode($answerJson, true);
        if (!$answerDataArr) $answerDataArr = ['value'=>$answerJson];
    }

    // Handle file upload if present
    if (!empty($_FILES['answer_file']) && $_FILES['answer_file']['error']===UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['answer_file']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','webp','pdf','mp3','wav','ogg','webm','mp4','zip','doc','docx'];
        if (!in_array($ext, $allowed)) json_response(false, [], 'فرمت فایل مجاز نیست');
        $dir = __DIR__.'/uploads/online-exams/answers/attempt_'.$attemptId;
        if (!is_dir($dir)) mkdir($dir,0755,true);
        $fn = 'q'.$questionId.'_'.time().'_'.bin2hex(random_bytes(3)).'.'.$ext;
        $dest = $dir.'/'.$fn;
        if (move_uploaded_file($_FILES['answer_file']['tmp_name'], $dest)) {
            $rel = 'uploads/online-exams/answers/attempt_'.$attemptId.'/'.$fn;
            $answerDataArr['file_path'] = $rel;
            $answerDataArr['file_name'] = $_FILES['answer_file']['name'];
        }
    }

    $answerDataJson = json_encode($answerDataArr, JSON_UNESCAPED_UNICODE);

    // Calculate temporary score
    $qData = json_decode($q['question_data'], true) ?: [];
    $qData['points'] = $q['points'];
    $calc = calc_online_question_score($q['question_type'], $qData, $answerDataArr);
    $needsManual = !empty($calc['needs_manual']) ? 1 : 0;
    // For manual grading types, points_earned initially 0 until teacher grades
    $manualTypes = ['text','short_text','fill_blank','file_upload','voice_upload','whiteboard'];
    if (in_array($q['question_type'], $manualTypes)) {
        $needsManual = 1;
    }

    $existing = DB::fetch("SELECT id FROM online_exam_answers WHERE attempt_id=? AND question_id=?", [$attemptId,$questionId]);
    if ($existing) {
        DB::execute("UPDATE online_exam_answers SET answer_data=?, is_correct=?, points_earned=?, needs_manual=?, answered_at=? WHERE id=?", [$answerDataJson, $calc['correct']===null?null:($calc['correct']?1:0), $needsManual ? 0 : $calc['points'], $needsManual, date('Y-m-d H:i:s'), $existing['id']]);
    } else {
        DB::execute("INSERT INTO online_exam_answers (attempt_id, question_id, answer_data, is_correct, points_earned, needs_manual, answered_at) VALUES (?,?,?,?,?,?,?)", [$attemptId,$questionId,$answerDataJson,$calc['correct']===null?null:($calc['correct']?1:0),$needsManual ? 0 : $calc['points'],$needsManual,date('Y-m-d H:i:s')]);
    }

    json_response(true, ['score'=>$calc['points'],'correct'=>$calc['correct']], 'ذخیره شد');
}

if ($action === 'submit_exam' && $role['type'] === 'student') {
    try {
    $attemptId = (int)($_POST['attempt_id'] ?? 0);
    if ($attemptId<=0) json_response(false, [], 'attempt نامعتبر');
    $attempt = DB::fetch("SELECT * FROM online_exam_attempts WHERE id=? AND student_id=?", [$attemptId, $role['id']]);
    if (!$attempt) json_response(false, [], 'آزمون یافت نشد');
    if (in_array($attempt['status'], ['submitted','auto_submitted'])) {
        json_response(true, ['score'=>$attempt['score'],'max'=>$attempt['max_score']], 'آزمون قبلا ارسال شده');
    }
    // Allow in_progress, expired, etc
    // Calculate final score
    try {
        $answers = DB::fetchAll("SELECT * FROM online_exam_answers WHERE attempt_id=?", [$attemptId]);
    } catch (Exception $e) { $answers=[]; }
    $total = 0; $max = 0;
    foreach ($answers as $a) $total += (float)($a['points_earned'] ?? 0);
    try {
        $questions = DB::fetchAll("SELECT * FROM online_questions WHERE exam_id=?", [$attempt['exam_id']]);
        foreach ($questions as $q) {
            if (($q['question_type']??'')==='info') continue;
            $max += (float)($q['points'] ?? 0);
        }
    } catch (Exception $e) { $max = $total; }

    try {
        DB::execute("UPDATE online_exam_attempts SET status='submitted', end_time=?, submitted_at=?, score=?, max_score=? WHERE id=?", [date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $total, $max, $attemptId]);
    } catch (Exception $e) {
        // Fallback without max_score
        try { DB::execute("UPDATE online_exam_attempts SET status='submitted', end_time=?, submitted_at=?, score=? WHERE id=?", [date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $total, $attemptId]); } catch (Exception $ee) {}
    }
    try { DB::execute("UPDATE online_exam_live_sessions SET status='offline' WHERE attempt_id=?", [$attemptId]); } catch (Exception $e) {}

    json_response(true, ['score'=>$total,'max'=>$max], 'آزمون با موفقیت ارسال شد');
    } catch (Exception $e) {
        error_log('submit_exam error: '.$e->getMessage());
        json_response(false, [], 'خطا در ارسال آزمون: '.$e->getMessage());
    }
}

if ($action === 'request_webcam' && in_array($role['type'], ['admin','teacher'])) {
    $attemptId = (int)($_POST['attempt_id'] ?? 0);
    $examId = (int)($_POST['exam_id'] ?? 0);
    if ($attemptId<=0) json_response(false, [], 'attempt نامعتبر');

    $attempt = DB::fetch("SELECT * FROM online_exam_attempts WHERE id=?", [$attemptId]);
    if (!$attempt) json_response(false, [], 'attempt یافت نشد');
    if ($examId===0) $examId = (int)$attempt['exam_id'];

    // Check teacher permission for this exam
    if ($role['type']==='teacher') {
        $exam = DB::fetch("SELECT * FROM online_exams WHERE id=?", [$examId]);
        if (!$exam || (int)$exam['teacher_id'] !== (int)$role['id']) {
            // Allow if executive? Check school_roles
            require_once __DIR__.'/includes/school_roles.php';
            if (!current_teacher_is_executive() && !teacher_has_deputy($role['id'])) {
                json_response(false, [], 'شما مجاز به مشاهده وب‌کم این آزمون نیستید');
            }
        }
    }

    $requestType = trim($_POST['request_type'] ?? 'snapshot');
    if (!in_array($requestType, ['snapshot','video_10sec'])) $requestType='snapshot';
    $duration = (int)($_POST['duration'] ?? ($requestType==='video_10sec' ? 10 : 0));
    try {
        DB::execute("INSERT INTO online_exam_webcam_requests (attempt_id, exam_id, student_id, requested_by_type, requested_by_id, status, request_type, duration_seconds) VALUES (?,?,?,?,?, 'pending', ?, ?)", [$attemptId, $attempt['exam_id'], $attempt['student_id'], $role['type'], $role['id'], $requestType, $duration]);
    } catch (Exception $e) {
        // Fallback if columns not exist yet
        DB::execute("INSERT INTO online_exam_webcam_requests (attempt_id, exam_id, student_id, requested_by_type, requested_by_id, status) VALUES (?,?,?,?,?, 'pending')", [$attemptId, $attempt['exam_id'], $attempt['student_id'], $role['type'], $role['id']]);
    }
    $reqId = DB::lastInsertId();

    json_response(true, ['request_id'=>$reqId], 'درخواست ثبت شد');
}

if ($action === 'upload_webcam_snapshot' && $role['type'] === 'student') {
    $attemptId = (int)($_POST['attempt_id'] ?? 0);
    $requestId = (int)($_POST['request_id'] ?? 0);
    if ($attemptId<=0) json_response(false, [], 'attempt نامعتبر');

    $attempt = DB::fetch("SELECT * FROM online_exam_attempts WHERE id=? AND student_id=? AND status='in_progress'", [$attemptId, $role['id']]);
    if (!$attempt) json_response(false, [], 'آزمون فعال نیست');

    // Validate request if provided
    if ($requestId>0) {
        $req = DB::fetch("SELECT * FROM online_exam_webcam_requests WHERE id=? AND attempt_id=? AND status='pending'", [$requestId,$attemptId]);
        if (!$req) json_response(false, [], 'درخواست نامعتبر یا منقضی');
    }

    $data = $_POST['image_data'] ?? '';
    $filePath = '';
    $fileSize = 0;

    if (!empty($_FILES['snapshot']) && $_FILES['snapshot']['error']===UPLOAD_ERR_OK) {
        $dir = __DIR__.'/uploads/online-exams/webcam/temp';
        if (!is_dir($dir)) mkdir($dir,0755,true);
        $fn = 'snap_'.$attemptId.'_'.time().'_'.bin2hex(random_bytes(3)).'.jpg';
        $dest = $dir.'/'.$fn;
        move_uploaded_file($_FILES['snapshot']['tmp_name'], $dest);
        $filePath = 'uploads/online-exams/webcam/temp/'.$fn;
        $fileSize = filesize($dest);
    } elseif (!empty($data)) {
        // base64 dataURL
        if (strpos($data, 'data:image')===0) {
            $parts = explode(',', $data);
            $data = end($parts);
        }
        $decoded = base64_decode($data);
        if ($decoded) {
            $dir = __DIR__.'/uploads/online-exams/webcam/temp';
            if (!is_dir($dir)) mkdir($dir,0755,true);
            $fn = 'snap_'.$attemptId.'_'.time().'_'.bin2hex(random_bytes(3)).'.jpg';
            $dest = $dir.'/'.$fn;
            file_put_contents($dest, $decoded);
            $filePath = 'uploads/online-exams/webcam/temp/'.$fn;
            $fileSize = strlen($decoded);
        }
    }

    if ($filePath==='') json_response(false, [], 'فایل تصویر دریافت نشد');

    // Save snapshot record
    DB::execute("INSERT INTO online_exam_webcam_snapshots (attempt_id, exam_id, student_id, file_path, file_size) VALUES (?,?,?,?,?)", [$attemptId, $attempt['exam_id'], $role['id'], $filePath, $fileSize]);
    $snapId = DB::lastInsertId();

    if ($requestId>0) {
        DB::execute("UPDATE online_exam_webcam_requests SET status='completed', snapshot_path=? WHERE id=?", [$filePath, $requestId]);
    }

    json_response(true, ['snapshot_id'=>$snapId,'file_path'=>$filePath], 'تصویر دریافت شد');
}


if ($action === 'upload_webcam_video' && $role['type'] === 'student') {
    $attemptId = (int)($_POST['attempt_id'] ?? 0);
    $requestId = (int)($_POST['request_id'] ?? 0);
    if ($attemptId<=0) json_response(false, [], 'attempt نامعتبر');
    $attempt = DB::fetch("SELECT * FROM online_exam_attempts WHERE id=? AND student_id=? AND status='in_progress'", [$attemptId, $role['id']]);
    if (!$attempt) json_response(false, [], 'آزمون فعال نیست');
    if ($requestId>0) {
        $req = DB::fetch("SELECT * FROM online_exam_webcam_requests WHERE id=? AND attempt_id=? AND status='pending'", [$requestId,$attemptId]);
        if (!$req) json_response(false, [], 'درخواست نامعتبر');
    }
    if (empty($_FILES['video']) || $_FILES['video']['error']!==UPLOAD_ERR_OK) json_response(false, [], 'فایل ویدیو دریافت نشد');
    $dir = __DIR__.'/uploads/online-exams/webcam/temp';
    if (!is_dir($dir)) mkdir($dir,0755,true);
    $ext = pathinfo($_FILES['video']['name'], PATHINFO_EXTENSION) ?: 'webm';
    $fn = 'video_'.$attemptId.'_'.time().'_'.bin2hex(random_bytes(3)).'.'.$ext;
    $dest = $dir.'/'.$fn;
    move_uploaded_file($_FILES['video']['tmp_name'], $dest);
    $filePath = 'uploads/online-exams/webcam/temp/'.$fn;
    $fileSize = filesize($dest);
    DB::execute("INSERT INTO online_exam_webcam_snapshots (attempt_id, exam_id, student_id, file_path, file_size) VALUES (?,?,?,?,?)", [$attemptId, $attempt['exam_id'], $role['id'], $filePath, $fileSize]);
    $snapId = DB::lastInsertId();
    if ($requestId>0) {
        DB::execute("UPDATE online_exam_webcam_requests SET status='completed', snapshot_path=? WHERE id=?", [$filePath, $requestId]);
    }
    json_response(true, ['snapshot_id'=>$snapId,'file_path'=>$filePath], 'ویدیو 10 ثانیه دریافت شد');
}

if ($action === 'get_live_sessions' && in_array($role['type'], ['admin','teacher'])) {
    try {
    $examId = (int)($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0);
    if ($examId<=0) json_response(false, [], 'exam_id نامعتبر');

    // Permission check for teacher
    if ($role['type']==='teacher') {
        try {
            $exam = DB::fetch("SELECT * FROM online_exams WHERE id=?", [$examId]);
            if (!$exam) json_response(false, [], 'آزمون یافت نشد');
            require_once __DIR__.'/includes/school_roles.php';
            $isExec = current_teacher_is_executive() || teacher_has_deputy($role['id']) || teacher_has_counselor($role['id']);
            if ((int)$exam['teacher_id'] !== (int)$role['id'] && !$isExec) {
                if (!$isExec) json_response(false, [], 'دسترسی غیرمجاز');
            }
        } catch (Exception $e) { /* ignore permission check errors, allow */ }
    }

    try {
        $sessions = DB::fetchAll("SELECT ls.*, s.first_name, s.last_name, s.national_id, s.class_name, a.status as attempt_status, a.score, a.tab_switch_count, a.exit_count, a.copy_attempts FROM online_exam_live_sessions ls JOIN students s ON s.id=ls.student_id JOIN online_exam_attempts a ON a.id=ls.attempt_id WHERE ls.exam_id=? ORDER BY ls.last_heartbeat DESC", [$examId]);
    } catch (Exception $e) {
        // Fallback: try without join to attempts if that fails
        try {
            $sessions = DB::fetchAll("SELECT ls.*, s.first_name, s.last_name, s.national_id, s.class_name, 'in_progress' as attempt_status, 0 as score, 0 as tab_switch_count, 0 as exit_count, 0 as copy_attempts FROM online_exam_live_sessions ls JOIN students s ON s.id=ls.student_id WHERE ls.exam_id=? ORDER BY ls.last_heartbeat DESC", [$examId]);
        } catch (Exception $e2) {
            $sessions = [];
        }
    }


    // Detect same IP and proximity
    $ipGroups = [];
    $proximityAlerts = [];
    $now = time();
    foreach ($sessions as $i=>$s) {
        $ip = $s['ip_address'] ?? '';
        if ($ip) $ipGroups[$ip][] = $s;
    }
    $sameIpList = [];
    foreach ($ipGroups as $ip=>$list) {
        if (count($list)>1) {
            $sameIpList[] = ['ip'=>$ip,'students'=>array_map(fn($x)=>$x['first_name'].' '.$x['last_name'].' ('.$x['class_name'].')', $list)];
        }
    }

    // Proximity: check each pair distance < 100 meters
    for ($i=0;$i<count($sessions);$i++) {
        for ($j=$i+1;$j<count($sessions);$j++) {
            $a = $sessions[$i]; $b = $sessions[$j];
            if ($a['lat'] && $a['lng'] && $b['lat'] && $b['lng']) {
                $dist = haversine_distance((float)$a['lat'], (float)$a['lng'], (float)$b['lat'], (float)$b['lng']);
                if ($dist < 150) { // 150 meters threshold
                    $proximityAlerts[] = [
                        'student1'=> $a['first_name'].' '.$a['last_name'],
                        'student2'=> $b['first_name'].' '.$b['last_name'],
                        'distance'=> round($dist,1),
                        'distance_text'=> round($dist,1).' متر'
                    ];
                }
            }
        }
    }

    // Add offline detection >2 min
    foreach ($sessions as &$s) {
        $last = strtotime($s['last_heartbeat']);
        $diff = $now - $last;
        if ($diff > 120) $s['is_stale'] = true;
        else $s['is_stale'] = false;
        $s['last_heartbeat_fa'] = tr_num(jdate('H:i:s', $last), 'fa');
    }

    } catch (Exception $e) {
        error_log('get_live_sessions error: '.$e->getMessage());
        json_response(false, [], 'خطا در دریافت لیست زنده: '.$e->getMessage());
    }
    json_response(true, ['sessions'=>$sessions,'same_ip'=>$sameIpList,'proximity'=>$proximityAlerts], 'ok');
}

if ($action === 'get_webcam_snapshots' && in_array($role['type'], ['admin','teacher'])) {
    $attemptId = (int)($_GET['attempt_id'] ?? $_POST['attempt_id'] ?? 0);
    $examId = (int)($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0);
    if ($attemptId>0) {
        $snaps = DB::fetchAll("SELECT ws.*, s.first_name, s.last_name FROM online_exam_webcam_snapshots ws JOIN students s ON s.id=ws.student_id WHERE ws.attempt_id=? ORDER BY ws.id DESC LIMIT 50", [$attemptId]);
    } elseif ($examId>0) {
        $snaps = DB::fetchAll("SELECT ws.*, s.first_name, s.last_name FROM online_exam_webcam_snapshots ws JOIN students s ON s.id=ws.student_id WHERE ws.exam_id=? ORDER BY ws.id DESC LIMIT 100", [$examId]);
    } else {
        $snaps = [];
    }
    json_response(true, ['snapshots'=>$snaps], 'ok');
}

if ($action === 'get_pending_webcam_requests' && $role['type']==='student') {
    $attemptId = (int)($_GET['attempt_id'] ?? 0);
    $pending = DB::fetchAll("SELECT * FROM online_exam_webcam_requests WHERE attempt_id=? AND student_id=? AND status='pending'", [$attemptId, $role['id']]);
    json_response(true, ['pending'=>$pending], 'ok');
}

if ($action === 'get_webcam_request_status' && in_array($role['type'], ['admin','teacher'])) {
    $requestId = (int)($_GET['request_id'] ?? 0);
    $req = DB::fetch("SELECT * FROM online_exam_webcam_requests WHERE id=?", [$requestId]);
    json_response(true, ['request'=>$req], 'ok');
}

if ($action === 'save_webcam_snapshot' && in_array($role['type'], ['admin','teacher'])) {
    $snapId = (int)($_POST['snapshot_id'] ?? 0);
    $snap = DB::fetch("SELECT * FROM online_exam_webcam_snapshots WHERE id=?", [$snapId]);
    if (!$snap) json_response(false, [], 'تصویر یافت نشد');

    $src = __DIR__.'/'.$snap['file_path'];
    $destDir = __DIR__.'/uploads/online-exams/webcam/saved';
    if (!is_dir($destDir)) mkdir($destDir,0755,true);
    $fn = basename($snap['file_path']);
    $dest = $destDir.'/'.$fn;
    $destRel = 'uploads/online-exams/webcam/saved/'.$fn;
    if (file_exists($src)) copy($src, $dest);

    DB::execute("UPDATE online_exam_webcam_snapshots SET is_saved_by_teacher=1, saved_by_type=?, saved_by_id=?, file_path=? WHERE id=?", [$role['type'],$role['id'],$destRel,$snapId]);

    json_response(true, ['file_path'=>$destRel], 'ذخیره شد');
}

if ($action === 'start_attempt' && $role['type'] === 'student') {
    $attemptId = (int)($_POST['attempt_id'] ?? 0);
    if ($attemptId<=0) json_response(false, [], 'attempt نامعتبر');
    $attempt = DB::fetch("SELECT a.*, e.duration_minutes FROM online_exam_attempts a JOIN online_exams e ON e.id=a.exam_id WHERE a.id=? AND a.student_id=?", [$attemptId, $role['id']]);
    if (!$attempt) json_response(false, [], 'attempt یافت نشد');

    // Check if timer already started - if so, DO NOT reset (fix for timer reset bug)
    if (!empty($attempt['is_timer_started']) && !empty($attempt['first_started_at'])) {
        // Timer already started before, calculate remaining from first_started_at - DO NOT RESET
        $firstStart = strtotime($attempt['first_started_at']);
        $duration = (int)$attempt['duration_minutes'] * 60;
        $elapsed = time() - $firstStart;
        $remaining = max(0, $duration - $elapsed);
        // Ensure live session exists for monitoring
        try {
            $existingLive = DB::fetch("SELECT id FROM online_exam_live_sessions WHERE attempt_id=?", [$attemptId]);
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $nowLive = date('Y-m-d H:i:s');
            if ($existingLive) {
                DB::execute("UPDATE online_exam_live_sessions SET last_heartbeat=?, ip_address=?, status='active' WHERE attempt_id=?", [$nowLive, $ip, $attemptId]);
            } else {
                DB::execute("INSERT INTO online_exam_live_sessions (attempt_id, student_id, exam_id, last_heartbeat, ip_address, status) VALUES (?,?,?,?,?, 'active')", [$attemptId, $attempt['student_id'], $attempt['exam_id'], $nowLive, $ip]);
            }
        } catch (Exception $e) {}
        json_response(true, ['start_time'=>$attempt['first_started_at'],'remaining'=>$remaining,'is_continuation'=>true], 'تایمر قبلا شروع شده - ادامه (زمان ریست نمی‌شود)');
    }

    // First time start - set timer
    $now = date('Y-m-d H:i:s');
    try {
        DB::execute("UPDATE online_exam_attempts SET start_time=?, first_started_at=COALESCE(first_started_at, ?), timer_started_at=?, is_timer_started=1 WHERE id=?", [$now, $now, $now, $attemptId]);
    } catch (Exception $e) {
        // Fallback if columns not exist yet, try only start_time
        try { DB::execute("UPDATE online_exam_attempts SET start_time=? WHERE id=?", [$now, $attemptId]); } catch (Exception $ee) {}
    }
    $remaining = (int)$attempt['duration_minutes']*60;
    // Create live session immediately so monitoring shows student right away
    try {
        $existingLive = DB::fetch("SELECT id FROM online_exam_live_sessions WHERE attempt_id=?", [$attemptId]);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($existingLive) {
            DB::execute("UPDATE online_exam_live_sessions SET last_heartbeat=?, ip_address=?, status='active' WHERE attempt_id=?", [$now, $ip, $attemptId]);
        } else {
            DB::execute("INSERT INTO online_exam_live_sessions (attempt_id, student_id, exam_id, last_heartbeat, ip_address, status) VALUES (?,?,?,?,?, 'active')", [$attemptId, $attempt['student_id'], $attempt['exam_id'], $now, $ip]);
        }
    } catch (Exception $e) { error_log('live session create failed: '.$e->getMessage()); }
    json_response(true, ['start_time'=>$now,'remaining'=>$remaining,'is_continuation'=>false], 'تایمر آزمون شروع شد - از حالا محاسبه می‌شود');
}


if ($action === 'get_attempts_in_progress' && in_array($role['type'], ['admin','teacher'])) {
    try {
        $examId = (int)($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0);
        if ($examId<=0) json_response(false, [], 'exam_id نامعتبر');
        $attempts = DB::fetchAll("SELECT a.*, s.first_name, s.last_name, s.class_name, s.national_id FROM online_exam_attempts a JOIN students s ON s.id=a.student_id WHERE a.exam_id=? AND a.status='in_progress' ORDER BY a.id DESC", [$examId]);
        json_response(true, ['attempts'=>$attempts], 'ok');
    } catch (Exception $e) {
        json_response(false, [], 'خطا: '.$e->getMessage());
    }
}

if ($action === 'get_proctoring_logs' && in_array($role['type'], ['admin','teacher'])) {
    $attemptId = (int)($_GET['attempt_id'] ?? 0);
    $examId = (int)($_GET['exam_id'] ?? 0);
    if ($attemptId>0) $logs = DB::fetchAll("SELECT pl.*, s.first_name, s.last_name FROM online_exam_proctoring_logs pl JOIN students s ON s.id=pl.student_id WHERE pl.attempt_id=? ORDER BY pl.id DESC LIMIT 200", [$attemptId]);
    elseif ($examId>0) $logs = DB::fetchAll("SELECT pl.*, s.first_name, s.last_name FROM online_exam_proctoring_logs pl JOIN students s ON s.id=pl.student_id WHERE pl.exam_id=? ORDER BY pl.id DESC LIMIT 500", [$examId]);
    else $logs = [];
    json_response(true, ['logs'=>$logs], 'ok');
}

json_response(false, [], 'action نامعتبر');
?>
