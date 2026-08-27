<?php
// File: class-exam-create.php
/**
 * Creates a new teacher class-exam or opens an existing one and redirects to the live designer.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/exams_helper.php';
if (empty($_SESSION['teacher_id']) && !is_admin_logged_in()) redirect('admin-login.php?tab=teacher');
ensure_exams_schema();
$teacherId = (int)($_SESSION['teacher_id'] ?? ($_GET['teacher_id'] ?? 0));
$class = norm_class_str($_GET['class'] ?? '');
$subject = trim($_GET['subject'] ?? '');
$year = resolve_academic_year_request($_GET['year'] ?? get_current_academic_year()); // v4.38.0 unified
$requestedExamId = (int)($_GET['exam_id'] ?? 0);
$forceNew = isset($_GET['new']) && $_GET['new'] === '1';
if (!$teacherId || $class==='' || $subject==='') { set_flash_message('error','اطلاعات آزمون کلاسی کامل نیست.'); redirect('teacher-panel.php?tab=grading'); }
$t = DB::fetch('SELECT full_name FROM teachers WHERE id=?',[$teacherId]);
$grade = infer_grade_from_class_name($class);
$examId = 0;

if ($requestedExamId > 0) {
    $exam = DB::fetch("SELECT id FROM exam_schedules WHERE id=? AND exam_kind='class' AND teacher_id=?", [$requestedExamId, $teacherId]);
    if (!$exam && !is_admin_logged_in()) {
        set_flash_message('error','شما مجاز به ویرایش این آزمون کلاسی نیستید.');
        redirect('teacher-panel.php?tab=grading&year=' . urlencode($year));
    }
    $examId = $requestedExamId;
} elseif ($forceNew) {
    // A teacher may design multiple class exams for the same class/subject over time.
    DB::execute("INSERT INTO exam_schedules (academic_year,exam_month,grade_level,class_name,subject_name,teacher_id,teacher_name,exam_date_jalali,exam_day_name,start_time,duration_minutes,exam_room_default,question_file,header_config,is_active,exam_kind,created_at_jalali) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",[
        $year,
        'آزمون کلاسی ' . jdate('Y/m/d H:i'),
        $grade,
        $class,
        $subject,
        $teacherId,
        $t['full_name'] ?? '',
        '', '', '',
        45,
        '',
        null,
        '{}',
        1,
        'class',
        jdate('Y/m/d H:i')
    ]);
    $examId = DB::lastInsertId();
} else {
    // Backward-compatible default: open the latest existing class exam, otherwise create one.
    $exam = DB::fetch("SELECT id FROM exam_schedules WHERE exam_kind='class' AND academic_year=? AND class_name=? AND subject_name=? AND teacher_id=? ORDER BY id DESC LIMIT 1",[$year,$class,$subject,$teacherId]);
    if (!$exam) {
        DB::execute("INSERT INTO exam_schedules (academic_year,exam_month,grade_level,class_name,subject_name,teacher_id,teacher_name,exam_date_jalali,exam_day_name,start_time,duration_minutes,exam_room_default,question_file,header_config,is_active,exam_kind,created_at_jalali) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",[
            $year,'آزمون کلاسی ' . jdate('Y/m/d H:i'),$grade,$class,$subject,$teacherId,$t['full_name']??'','','','',45,'',null,'{}',1,'class',jdate('Y/m/d H:i')
        ]);
        $examId = DB::lastInsertId();
    } else $examId = (int)$exam['id'];
}
$token = make_exam_design_token($examId, 'teacher', $teacherId);
redirect('exam-print.php?type=questions&exam_id='.$examId.'&dt='.urlencode($token));
