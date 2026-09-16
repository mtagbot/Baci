<?php
// Create once per teacher/year/class/subject, or open any owned legacy exam.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/class_exam_helpers.php';
if (empty($_SESSION['teacher_id']) && !is_admin_logged_in()) redirect('admin-login.php?tab=teacher');
ensure_exams_schema();
$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$teacherId = (int)($_SESSION['teacher_id'] ?? ($input['teacher_id'] ?? 0));
$class = norm_class_str($input['class'] ?? '');
$subject = trim($input['subject'] ?? '');
$year = resolve_academic_year_request($input['year'] ?? get_current_academic_year());
$requestedExamId = (int)($input['exam_id'] ?? 0);
$gradeAll = ($input['grade_all'] ?? '') === '1';
$back = 'teacher-panel.php?tab=exams&year='.urlencode($year);
if (!$teacherId || $class === '' || $subject === '') { set_flash_message('error','اطلاعات آزمون کلاسی کامل نیست.'); redirect($back); }
if (isset($input['year']) && unify_academic_year($input['year']) !== $year) { set_flash_message('error','سال تحصیلی نامعتبر است.'); redirect($back); }
$existing = class_exam_existing($teacherId,$year,$class,$subject);
$examId = 0;
if ($requestedExamId) {
    foreach ($existing as $ex) if ((int)$ex['id'] === $requestedExamId) $examId = $requestedExamId;
    if (!$examId) { set_flash_message('error','شما مجاز به ویرایش این آزمون نیستید.'); redirect($back); }
} elseif ($existing) {
    // Including old ?new=1 links: never insert a second exam.
    $examId = (int)$existing[0]['id'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($input['csrf_token'] ?? '')) { set_flash_message('error','خطای امنیتی؛ صفحه را تازه‌سازی کنید.'); redirect($back); }
    try { $examId = class_exam_get_or_create($teacherId,$year,$class,$subject); }
    catch (Throwable $e) { set_flash_message('error', $e instanceof PDOException ? 'آزمون ساخته نشد؛ اطلاعات قبلی محفوظ است.' : $e->getMessage()); redirect($back); }
} else {
    // GETs, bookmarks and browser prefetch must not mutate exam data.
    set_flash_message('info','برای ایجاد آزمون از دکمهٔ طراحی آزمون جدید در این صفحه استفاده کنید.');
    redirect($back);
}
$exam = DB::fetch('SELECT * FROM exam_schedules WHERE id=?',[$examId]);
if ($gradeAll && !class_exam_grade_classes($exam)) { set_flash_message('error','کلاس تخصیص‌یافته‌ای برای طراحی این پایه و درس یافت نشد.'); redirect($back); }
$token = make_exam_design_token($examId,'teacher',$teacherId);
redirect('exam-print.php?type=questions&exam_id='.$examId.($gradeAll ? '&grade_all=1' : '').'&dt='.urlencode($token));
