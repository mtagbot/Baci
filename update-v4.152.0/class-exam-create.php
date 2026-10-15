<?php
// Create once per teacher/year/class/subject, or open any owned legacy exam.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/class_exam_groups.php';
$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
/* v4.180.0 — همان کاری که توکن طراحی آزمون می‌کند، برای ویرایش/ساخت کلاسی:
   پنجرهٔ تازه (مرورگر داخلی بله یا پنجرهٔ جدا) نشست پنل ندارد؛ با توکن امضاشده
   کاربر به صفحهٔ ورود نمی‌افتد. بدون توکن، رفتار قبلی (نشست) دست‌نخورده است. */
$entryToken = verify_class_exam_entry_token($input['ct'] ?? '');
if ($entryToken && ($input['class'] ?? '') !== '' && norm_class_str($entryToken['c']) !== norm_class_str($input['class'])) $entryToken = false;
if (!$entryToken && empty($_SESSION['teacher_id']) && !is_admin_logged_in()) redirect('admin-login.php?tab=teacher');
ensure_exams_schema(); ceg_schema();
$teacherId = (int)($_SESSION['teacher_id'] ?? 0);
if (!$teacherId && $entryToken) $teacherId = (int)$entryToken['t'];
if (!$teacherId) $teacherId = (int)($input['teacher_id'] ?? 0);
$class = norm_class_str($input['class'] ?? ($entryToken['c'] ?? ''));
$subject = trim($input['subject'] ?? ($entryToken['s'] ?? ''));
$year = resolve_academic_year_request($input['year'] ?? ($entryToken['y'] ?? get_current_academic_year()));
$requestedExamId = (int)($input['exam_id'] ?? ($entryToken['e'] ?? 0));
$gradeAll = ($input['grade_all'] ?? '') === '1';
$back = 'teacher-panel.php?tab=exams&year='.urlencode($year);
if (!$teacherId || $class === '' || $subject === '') { set_flash_message('error','اطلاعات آزمون کلاسی کامل نیست.'); redirect($back); }
if ($entryToken && (norm_class_str($entryToken['c']) !== $class || trim($entryToken['s']) !== $subject || (int)$entryToken['t'] !== $teacherId)) {
    set_flash_message('error','توکن ویرایش با این آزمون هم‌خوان نیست.'); redirect($back);
}
if (isset($input['year']) && unify_academic_year($input['year']) !== $year) { set_flash_message('error','سال تحصیلی نامعتبر است.'); redirect($back); }
if ($gradeAll) {
    $grade = '';
    foreach(class_exam_assignments($teacherId,$year) as $as) if(norm_class_str($as['class_name'])===$class && $as['subject_name']===$subject) $grade=$as['grade_level'];
    $gqs = ['year'=>$year,'grade'=>$grade,'subject'=>$subject];
    if ($entryToken) $gqs['ct'] = (string)$input['ct'];   // v4.180.0: توکن را با خود ببر تا در پنجرهٔ تازه هم کار کند
    redirect('class-exam-group.php?'.http_build_query($gqs));
}
$existing = class_exam_existing($teacherId,$year,$class,$subject);
$examId = 0;
if ($requestedExamId) {
    foreach ($existing as $ex) if ((int)$ex['id'] === $requestedExamId) $examId = $requestedExamId;
    if (!$examId) { set_flash_message('error','شما مجاز به ویرایش این آزمون نیستید.'); redirect($back); }
} elseif ($existing) {
    // Including old ?new=1 links: never insert a second exam.
    $examId = (int)$existing[0]['id'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' || !empty($entryToken['n'])) {
    /* ساخت آزمون: با CSRF (مسیر نشست) یا با توکنِ امضاشدهٔ «طراحی آزمون جدید».
       GET بی‌توکن همچنان فقط بازمی‌گردد تا پیش‌بارگذاری مرورگر داده را عوض نکند. */
    if (!$entryToken && !verify_csrf($input['csrf_token'] ?? '')) { set_flash_message('error','خطای امنیتی؛ صفحه را تازه‌سازی کنید.'); redirect($back); }
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
