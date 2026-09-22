<?php
// File: exam-design-api.php
/**
 * JSON API for saving/loading exam live designs and question bank.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/exams_helper.php';
ensure_exams_schema();
header('Content-Type: application/json; charset=utf-8');

function json_out($ok, $data = []) { echo json_encode(array_merge(['ok'=>$ok], $data), JSON_UNESCAPED_UNICODE); exit; }
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$examId = (int)($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0);
$designTokenOk = verify_exam_design_token($_GET['dt'] ?? $_POST['dt'] ?? '', $examId);
if (!$examId || (!$designTokenOk && !exam_can_design($examId))) json_out(false, ['error'=>'دسترسی غیرمجاز']);
$exam = DB::fetch("SELECT es.*, COALESCE(t.full_name, es.teacher_name) AS t_name FROM exam_schedules es LEFT JOIN teachers t ON t.id=es.teacher_id WHERE es.id=?", [$examId]);
if (!$exam) json_out(false, ['error'=>'آزمون یافت نشد']);

if ($action === 'load') {
    $design = DB::fetch("SELECT * FROM exam_designs WHERE exam_id=?", [$examId]);
    $params = [];
    $where = ["1=1"];
    if (!empty($_GET['subject'])) { $where[] = "(subject_name LIKE ? OR question_html LIKE ?)"; $params[] = '%' . trim($_GET['subject']) . '%'; $params[] = '%' . trim($_GET['subject']) . '%'; }
    if (!empty($_GET['year'])) { $where[] = "academic_year=?"; $params[] = trim($_GET['year']); }
    if (!empty($_GET['month'])) { $where[] = "exam_month=?"; $params[] = trim($_GET['month']); }
    if (!empty($_GET['type'])) { $where[] = "question_type=?"; $params[] = trim($_GET['type']); }
    if (!empty($_GET['designer'])) { $where[] = "designer_name LIKE ?"; $params[] = '%' . trim($_GET['designer']) . '%'; }
    $bank = DB::fetchAll("SELECT * FROM exam_question_bank WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT 300", $params);
    json_out(true, ['design'=>$design ? json_decode($design['design_json'], true) : null, 'bank'=>$bank]);
}

if ($action === 'save') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!$payload) json_out(false, ['error'=>'داده نامعتبر']);
    $design = $payload['design'] ?? [];
    $questions = $design['questions'] ?? [];
    $designerTeacherId = !empty($_SESSION['teacher_id']) ? (int)$_SESSION['teacher_id'] : ($exam['teacher_id'] ?: null);
    $designerName = $exam['t_name'] ?: ($_SESSION['teacher_name'] ?? '');
    if (!is_admin_logged_in() && !empty($_SESSION['teacher_name'])) $designerName = $_SESSION['teacher_name'];
    $json = json_encode($design, JSON_UNESCAPED_UNICODE);
    $exists = DB::fetch("SELECT id FROM exam_designs WHERE exam_id=?", [$examId]);
    if ($exists) DB::execute("UPDATE exam_designs SET design_json=?, designer_teacher_id=?, designer_name=?, saved_by_admin_id=?, updated_at_jalali=? WHERE exam_id=?", [$json,$designerTeacherId,$designerName,$_SESSION['admin_id']??null,jalali_now(),$examId]);
    else DB::execute("INSERT INTO exam_designs (exam_id, design_json, designer_teacher_id, designer_name, saved_by_admin_id, updated_at_jalali) VALUES (?,?,?,?,?,?)", [$examId,$json,$designerTeacherId,$designerName,$_SESSION['admin_id']??null,jalali_now()]);

    // Archive every designed question in the bank while preserving original designer.
    // Deduplication: exact/near-exact question bodies are stored only once per subject/type/score.
    $normalizeQuestion = function($html) {
        $html = preg_replace('/<div class="q-actions".*?<\/div>/is', '', (string)$html);
        $html = preg_replace('/\s+/', ' ', trim($html));
        return sha1(mb_strtolower($html, 'UTF-8'));
    };
    foreach ($questions as $q) {
        if (($q['type'] ?? '') !== 'q') continue;
        $qid = $q['bank_id'] ?? null;
        $qhtml = $q['html'] ?? '';
        if (trim(strip_tags($qhtml)) === '' && strpos($qhtml, '<img') === false) continue;
        if ($qid && is_admin_logged_in()) {
            DB::execute("UPDATE exam_question_bank SET question_html=?, score=?, question_type=?, meta_json=?, updated_at_jalali=? WHERE id=?", [$qhtml,$q['score']??'', $q['qtype']??'text', json_encode($q,JSON_UNESCAPED_UNICODE), jalali_now(), (int)$qid]);
        } elseif (!$qid) {
            $hash = $normalizeQuestion($qhtml);
            $candidates = DB::fetchAll("SELECT id, question_html FROM exam_question_bank WHERE subject_name=? AND question_type=? AND COALESCE(score,'')=? ORDER BY id DESC LIMIT 500", [$exam['subject_name'], $q['qtype']??'text', $q['score']??'']);
            $duplicateId = null;
            foreach ($candidates as $cand) {
                if ($normalizeQuestion($cand['question_html']) === $hash) { $duplicateId = (int)$cand['id']; break; }
            }
            if (!$duplicateId) {
                DB::execute("INSERT INTO exam_question_bank (source_exam_id, academic_year, exam_month, subject_name, teacher_id, designer_name, question_type, question_html, score, meta_json, created_at_jalali) VALUES (?,?,?,?,?,?,?,?,?,?,?)", [$examId,$exam['academic_year'],$exam['exam_month'],$exam['subject_name'],$designerTeacherId,$designerName,$q['qtype']??'text',$qhtml,$q['score']??'',json_encode($q,JSON_UNESCAPED_UNICODE),jalali_now()]);
            }
        }
    }
    json_out(true, ['message'=>'طراحی آزمون و سوالات در بانک اطلاعاتی ذخیره شد.']);
}

if ($action === 'delete_bank') {
    if (!is_admin_logged_in()) json_out(false, ['error'=>'فقط مدیر مجاز است']);
    DB::execute("DELETE FROM exam_question_bank WHERE id=?", [(int)($_POST['question_id'] ?? 0)]);
    json_out(true);
}

json_out(false, ['error'=>'عملیات نامعتبر']);
