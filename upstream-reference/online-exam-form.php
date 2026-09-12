<?php
// File: online-exam-form.php - Create/edit online exam with Shamsi dates & Iran-compatible GPS
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/academic_year_helpers.php';
require_once __DIR__ . '/includes/online_exam_helpers.php';
require_once __DIR__ . '/includes/school_roles.php';
require_once __DIR__ . '/includes/jdf.php';
ensure_school_roles_schema();
ensure_online_exams_schema();

if (!empty($_SESSION['admin_id'])) {
    require_permission('manage_reports');
} elseif (empty($_SESSION['teacher_id'])) {
    redirect('admin-login.php');
}
$teacherId = $_SESSION['teacher_id'] ?? null;
$isExecutive = $teacherId ? (teacher_has_executive($teacherId) || teacher_has_deputy($teacherId)) : false;

$id = (int)($_GET['id'] ?? 0);
$exam = $id ? DB::fetch("SELECT * FROM online_exams WHERE id=?", [$id]) : null;

if ($exam && !empty($_SESSION['teacher_id']) && !$isExecutive) {
    if ((int)$exam['teacher_id'] !== (int)$teacherId) { set_flash_message('error','دسترسی غیرمجاز'); redirect('online-exams.php'); }
}

// Helper to convert Gregorian datetime string to Jalali array
function gregorian_datetime_to_jalali_array($gDatetime) {
    if (!$gDatetime) return null;
    $ts = strtotime($gDatetime);
    if (!$ts) return null;
    list($jy,$jm,$jd) = gregorian_to_jalali(date('Y',$ts), date('n',$ts), date('j',$ts));
    return [
        'y'=>$jy,
        'm'=>$jm,
        'd'=>$jd,
        'h'=>date('H',$ts),
        'i'=>date('i',$ts),
        'gy'=>date('Y',$ts), 'gm'=>date('n',$ts), 'gd'=>date('j',$ts)
    ];
}

// Helper to convert Jalali inputs to Gregorian datetime string
function jalali_inputs_to_gregorian_datetime($y,$m,$d,$h,$i) {
    $y = (int)$y; $m = (int)$m; $d = (int)$d; $h = (int)$h; $i = (int)$i;
    if ($y<1300 || $y>1500) return null;
    if ($m<1 || $m>12) return null;
    if ($d<1 || $d>31) return null;
    if ($h<0 || $h>23) $h=0;
    if ($i<0 || $i>59) $i=0;
    list($gy,$gm,$gd) = jalali_to_gregorian($y,$m,$d);
    return sprintf('%04d-%02d-%02d %02d:%02d:00', $gy,$gm,$gd,$h,$i);
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!verify_csrf($_POST['csrf_token']??'')) { set_flash_message('error','CSRF'); redirect('online-exams.php'); }

    // Handle Shamsi dates
    $start_datetime = null;
    $end_datetime = null;

    // If Shamsi inputs provided (new way)
    if (isset($_POST['start_j_y']) && trim($_POST['start_j_y'])!=='') {
        $start_datetime = jalali_inputs_to_gregorian_datetime($_POST['start_j_y'], $_POST['start_j_m'], $_POST['start_j_d'], $_POST['start_h'], $_POST['start_i']);
    } elseif (trim($_POST['start_datetime']??'')!=='') {
        // fallback old Gregorian datetime-local
        $start_datetime = date('Y-m-d H:i:s', strtotime(trim($_POST['start_datetime'])));
    }

    if (isset($_POST['end_j_y']) && trim($_POST['end_j_y'])!=='') {
        $end_datetime = jalali_inputs_to_gregorian_datetime($_POST['end_j_y'], $_POST['end_j_m'], $_POST['end_j_d'], $_POST['end_h'], $_POST['end_i']);
    } elseif (trim($_POST['end_datetime']??'')!=='') {
        $end_datetime = date('Y-m-d H:i:s', strtotime(trim($_POST['end_datetime'])));
    }

    $data = [
        'title'=>trim($_POST['title']??''),
        'description'=>trim($_POST['description']??''),
        'category_id'=> (int)($_POST['category_id']??0) ?: null,
        'academic_year'=>unify_academic_year(trim($_POST['academic_year']?? get_setting('current_academic_year','1404/1405'))),
        'grade_level'=>trim($_POST['grade_level']??''),
        'class_name'=>trim($_POST['class_name']??''),
        'subject_name'=>trim($_POST['subject_name']??''),
        'duration_minutes'=> (int)($_POST['duration_minutes']??60),
        'max_attempts'=> (int)($_POST['max_attempts']??1),
        'passing_score'=> (float)($_POST['passing_score']??0),
        'randomize_questions'=> isset($_POST['randomize_questions'])?1:0,
        'randomize_answers'=> isset($_POST['randomize_answers'])?1:0,
        'show_results'=> trim($_POST['show_results']??'after_submit'),
        'start_datetime'=> $start_datetime,
        'end_datetime'=> $end_datetime,
        'status'=> trim($_POST['status']??'draft'),
        'allow_copy'=> isset($_POST['allow_copy'])?1:0,
        'enable_webcam'=> isset($_POST['enable_webcam'])?1:0,
        'enable_location'=> isset($_POST['enable_location'])?1:0,
        'enable_proctoring'=> isset($_POST['enable_proctoring'])?1:0,
        'enable_watermark'=> isset($_POST['enable_watermark'])?1:0,
        'watermark_text'=> trim($_POST['watermark_text']??''),
    ];

    if ($data['title']==='') { set_flash_message('error','عنوان الزامی است'); redirect($_SERVER['REQUEST_URI']); }

    $settings = [
        'proctoring'=>[
            'detect_tab_switch'=> isset($_POST['detect_tab_switch'])?1:0,
            'detect_copy'=> isset($_POST['detect_copy'])?1:0,
            'detect_right_click'=> isset($_POST['detect_right_click'])?1:0,
            'detect_printscreen'=> isset($_POST['detect_printscreen'])?1:0,
            'require_camera'=> isset($_POST['require_camera'])?1:0,
            'require_mic'=> isset($_POST['require_mic'])?1:0,
            'require_location'=> isset($_POST['require_location'])?1:0,
            'location_mode'=> trim($_POST['location_mode']??'balanced'), // high, balanced, low, disabled - Iran compatible
            'allow_ip_fallback'=> isset($_POST['allow_ip_fallback'])?1:0,
            'allow_location_retry'=> isset($_POST['allow_location_retry'])?1:0,
            'check_internet'=> isset($_POST['check_internet'])?1:0,
            'watermark_anti_ai'=> isset($_POST['watermark_anti_ai'])?1:0,
            'same_ip_alert'=> isset($_POST['same_ip_alert'])?1:0,
            'proximity_alert'=> isset($_POST['proximity_alert'])?1:0,
        ],
        'display'=>[
            'show_progress_bar'=> isset($_POST['show_progress_bar'])?1:0,
            'show_question_numbers'=> isset($_POST['show_question_numbers'])?1:0,
            'one_question_per_page'=> isset($_POST['one_question_per_page'])?1:0,
            'prevent_back'=> isset($_POST['prevent_back'])?1:0,
        ]
    ];

    $settingsJson = json_encode($settings, JSON_UNESCAPED_UNICODE);

    if ($exam) {
        DB::execute("UPDATE online_exams SET title=?, description=?, category_id=?, academic_year=?, grade_level=?, class_name=?, subject_name=?, duration_minutes=?, max_attempts=?, passing_score=?, randomize_questions=?, randomize_answers=?, show_results=?, start_datetime=?, end_datetime=?, status=?, allow_copy=?, enable_webcam=?, enable_location=?, enable_proctoring=?, enable_watermark=?, watermark_text=?, settings_json=?, updated_at=? WHERE id=?", [
            $data['title'],$data['description'],$data['category_id'],$data['academic_year'],$data['grade_level'],$data['class_name'],$data['subject_name'],$data['duration_minutes'],$data['max_attempts'],$data['passing_score'],$data['randomize_questions'],$data['randomize_answers'],$data['show_results'],$data['start_datetime'],$data['end_datetime'],$data['status'],$data['allow_copy'],$data['enable_webcam'],$data['enable_location'],$data['enable_proctoring'],$data['enable_watermark'],$data['watermark_text'],$settingsJson,date('Y-m-d H:i:s'),$exam['id']
        ]);
        set_flash_message('success','آزمون بروزرسانی شد (تاریخ‌های شمسی ذخیره شد)');
        redirect('online-exam-questions.php?exam_id='.$exam['id']);
    } else {
        $creatorTeacher = $teacherId;
        $creatorAdmin = $_SESSION['admin_id'] ?? null;
        DB::execute("INSERT INTO online_exams (title, description, category_id, academic_year, grade_level, class_name, subject_name, teacher_id, created_by_admin_id, duration_minutes, max_attempts, passing_score, randomize_questions, randomize_answers, show_results, start_datetime, end_datetime, status, allow_copy, enable_webcam, enable_location, enable_proctoring, enable_watermark, watermark_text, settings_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", [
            $data['title'],$data['description'],$data['category_id'],$data['academic_year'],$data['grade_level'],$data['class_name'],$data['subject_name'],$creatorTeacher,$creatorAdmin,$data['duration_minutes'],$data['max_attempts'],$data['passing_score'],$data['randomize_questions'],$data['randomize_answers'],$data['show_results'],$data['start_datetime'],$data['end_datetime'],$data['status'],$data['allow_copy'],$data['enable_webcam'],$data['enable_location'],$data['enable_proctoring'],$data['enable_watermark'],$data['watermark_text'],$settingsJson
        ]);
        $newId = DB::lastInsertId();
        set_flash_message('success','آزمون ایجاد شد با تاریخ شمسی، حالا سوالات را طراحی کنید');
        redirect('online-exam-questions.php?exam_id='.$newId);
    }
}

$categories = DB::fetchAll("SELECT * FROM online_exam_categories ORDER BY name");
$classes = DB::fetchAll("SELECT DISTINCT class_name FROM students WHERE status='active' ORDER BY class_name");
$grades = DB::fetchAll("SELECT DISTINCT grade_level FROM students WHERE grade_level<>'' ORDER BY grade_level");
$years = get_academic_years_for_filter();
if (!$years) $years = [['academic_year'=>get_setting('current_academic_year','1404/1405')]];

$defaultSettings = $exam && $exam['settings_json'] ? json_decode($exam['settings_json'], true) : [];
$proctor = $defaultSettings['proctoring'] ?? [];
$display = $defaultSettings['display'] ?? [];

$startJalali = $exam && !empty($exam['start_datetime']) ? gregorian_datetime_to_jalali_array($exam['start_datetime']) : null;
$endJalali = $exam && !empty($exam['end_datetime']) ? gregorian_datetime_to_jalali_array($exam['end_datetime']) : null;

// Default Jalali now for empty
$nowJalali = gregorian_datetime_to_jalali_array(date('Y-m-d H:i:s'));
if (!$startJalali) { $startJalali = ['y'=>$nowJalali['y'], 'm'=>$nowJalali['m'], 'd'=>$nowJalali['d'], 'h'=>'08', 'i'=>'00']; }
if (!$endJalali) { $endJalali = ['y'=>$nowJalali['y'], 'm'=>$nowJalali['m'], 'd'=>$nowJalali['d'], 'h'=>'10', 'i'=>'00']; }

require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-6 max-w-5xl mx-auto">
    <div class="flex justify-between items-center">
        <h2 class="text-xl font-bold"><?php echo $exam?'✏️ ویرایش آزمون آنلاین':'➕ ایجاد آزمون آنلاین جدید'; ?> <span class="text-xs text-muted">(تاریخ‌ها شمسی)</span></h2>
        <a href="online-exams.php" class="btn btn-secondary text-xs">↩️ بازگشت به لیست</a>
    </div>

    <form method="POST" class="space-y-6" data-no-ajax="1">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <div class="card space-y-4">
            <h3 class="font-bold border-b pb-2">📝 اطلاعات اصلی آزمون</h3>
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2"><label class="text-xs">عنوان آزمون *</label><input name="title" class="form-input font-bold" required value="<?php echo clean($exam['title']??''); ?>" placeholder="مثلا: آزمون میان‌ترم ریاضی پایه هفتم"></div>
                <div class="col-span-2"><label class="text-xs">توضیحات</label><textarea name="description" class="form-input" rows="3" placeholder="توضیحات اختیاری برای دانش‌آموزان"><?php echo clean($exam['description']??''); ?></textarea></div>
                <div><label class="text-xs">دسته آزمون</label><select name="category_id" class="form-select"><option value="0">بدون دسته</option><?php foreach($categories as $c): ?><option value="<?php echo $c['id']; ?>" <?php echo ($exam['category_id']??0)==$c['id']?'selected':''; ?>><?php echo clean($c['name']); ?></option><?php endforeach; ?></select></div>
                <div><label class="text-xs">سال تحصیلی</label><select name="academic_year" class="form-select"><?php foreach($years as $y): ?><option value="<?php echo clean($y['academic_year']); ?>" <?php echo ($exam['academic_year']??'1404/1405')=== $y['academic_year']?'selected':''; ?>><?php echo clean($y['academic_year']); ?></option><?php endforeach; ?></select></div>
                <div><label class="text-xs">پایه</label><select name="grade_level" class="form-select"><option value="">همه پایه‌ها</option><?php foreach($grades as $g): ?><option value="<?php echo clean($g['grade_level']); ?>" <?php echo ($exam['grade_level']??'')===$g['grade_level']?'selected':''; ?>><?php echo clean($g['grade_level']); ?></option><?php endforeach; ?></select></div>
                <div><label class="text-xs">کلاس</label><select name="class_name" class="form-select"><option value="">همه کلاس‌ها</option><?php foreach($classes as $cl): ?><option value="<?php echo clean($cl['class_name']); ?>" <?php echo ($exam['class_name']??'')===$cl['class_name']?'selected':''; ?>><?php echo clean($cl['class_name']); ?></option><?php endforeach; ?></select></div>
                <div><label class="text-xs">درس</label><input name="subject_name" class="form-input" value="<?php echo clean($exam['subject_name']??''); ?>" placeholder="مثلا ریاضی"></div>
                <div><label class="text-xs">مدت آزمون (دقیقه)</label><input type="number" name="duration_minutes" class="form-input" min="5" max="300" value="<?php echo $exam['duration_minutes']??60; ?>"></div>
                <div><label class="text-xs">تعداد دفعات مجاز شرکت</label><input type="number" name="max_attempts" class="form-input" min="1" max="10" value="<?php echo $exam['max_attempts']??1; ?>"></div>
                <div><label class="text-xs">نمره قبولی (اختیاری)</label><input type="number" step="0.5" name="passing_score" class="form-input" value="<?php echo $exam['passing_score']??0; ?>"></div>
            </div>

            <!-- Shamsi Date Pickers with Calendar -->
            <div class="grid grid-cols-2 gap-4 border-t pt-4">
                <div class="space-y-2 p-3 border rounded bg-green-50/50 relative">
                    <label class="text-xs font-bold">🟢 تاریخ و زمان شروع آزمون (شمسی - ایرانی) 
                        <button type="button" onclick="openJalaliCalendar('start')" class="btn btn-primary text-[10px] ml-2">📅 انتخاب از تقویم</button>
                    </label>
                    <div class="grid grid-cols-5 gap-1">
                        <div><label class="text-[10px]">سال</label><input type="number" name="start_j_y" class="form-input text-xs" min="1300" max="1500" value="<?php echo $startJalali['y']; ?>" required></div>
                        <div><label class="text-[10px]">ماه</label><select name="start_j_m" class="form-select text-xs"><?php for($m=1;$m<=12;$m++): $months=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند']; ?><option value="<?php echo $m; ?>" <?php echo $startJalali['m']==$m?'selected':''; ?>><?php echo $m.'-'.$months[$m-1]; ?></option><?php endfor; ?></select></div>
                        <div><label class="text-[10px]">روز</label><input type="number" name="start_j_d" class="form-input text-xs" min="1" max="31" value="<?php echo $startJalali['d']; ?>" required></div>
                        <div><label class="text-[10px]">ساعت</label><input type="number" name="start_h" class="form-input text-xs" min="0" max="23" value="<?php echo $startJalali['h']; ?>"></div>
                        <div><label class="text-[10px]">دقیقه</label><input type="number" name="start_i" class="form-input text-xs" min="0" max="59" value="<?php echo $startJalali['i']; ?>"></div>
                    </div>
                    <div class="text-[11px] text-muted" id="start_gregorian_preview"></div>
                    <!-- Calendar Widget Start -->
                    <div id="calendar_start" class="jalali-calendar hidden absolute z-50 top-[70px] left-0 bg-white border-2 border-indigo-200 rounded-xl shadow-2xl p-3 w-[300px]">
                        <div class="flex justify-between items-center mb-2">
                            <button type="button" onclick="changeCalYear(-1)" class="btn btn-secondary text-[10px]">« سال</button>
                            <button type="button" onclick="changeCalMonth(-1)" class="btn btn-secondary text-[10px]">‹ ماه</button>
                            <span id="calendar_start_title" class="font-bold text-xs"></span>
                            <button type="button" onclick="changeCalMonth(1)" class="btn btn-secondary text-[10px]">ماه ›</button>
                            <button type="button" onclick="changeCalYear(1)" class="btn btn-secondary text-[10px]">سال »</button>
                        </div>
                        <div id="calendar_start_days"></div>
                        <button type="button" onclick="closeCalendar('start')" class="btn btn-danger text-[10px] w-full mt-2">بستن</button>
                    </div>
                </div>

                <div class="space-y-2 p-3 border rounded bg-red-50/50 relative">
                    <label class="text-xs font-bold">🔴 تاریخ و زمان پایان آزمون (شمسی - ایرانی)
                        <button type="button" onclick="openJalaliCalendar('end')" class="btn btn-danger text-[10px] ml-2">📅 انتخاب از تقویم</button>
                    </label>
                    <div class="grid grid-cols-5 gap-1">
                        <div><label class="text-[10px]">سال</label><input type="number" name="end_j_y" class="form-input text-xs" min="1300" max="1500" value="<?php echo $endJalali['y']; ?>" required></div>
                        <div><label class="text-[10px]">ماه</label><select name="end_j_m" class="form-select text-xs"><?php for($m=1;$m<=12;$m++): $months=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند']; ?><option value="<?php echo $m; ?>" <?php echo $endJalali['m']==$m?'selected':''; ?>><?php echo $m.'-'.$months[$m-1]; ?></option><?php endfor; ?></select></div>
                        <div><label class="text-[10px]">روز</label><input type="number" name="end_j_d" class="form-input text-xs" min="1" max="31" value="<?php echo $endJalali['d']; ?>" required></div>
                        <div><label class="text-[10px]">ساعت</label><input type="number" name="end_h" class="form-input text-xs" min="0" max="23" value="<?php echo $endJalali['h']; ?>"></div>
                        <div><label class="text-[10px]">دقیقه</label><input type="number" name="end_i" class="form-input text-xs" min="0" max="59" value="<?php echo $endJalali['i']; ?>"></div>
                    </div>
                    <div class="text-[11px] text-muted" id="end_gregorian_preview"></div>
                    <!-- Calendar Widget End -->
                    <div id="calendar_end" class="jalali-calendar hidden absolute z-50 top-[70px] left-0 bg-white border-2 border-red-200 rounded-xl shadow-2xl p-3 w-[300px]">
                        <div class="flex justify-between items-center mb-2">
                            <button type="button" onclick="changeCalYear(-1)" class="btn btn-secondary text-[10px]">« سال</button>
                            <button type="button" onclick="changeCalMonth(-1)" class="btn btn-secondary text-[10px]">‹ ماه</button>
                            <span id="calendar_end_title" class="font-bold text-xs"></span>
                            <button type="button" onclick="changeCalMonth(1)" class="btn btn-secondary text-[10px]">ماه ›</button>
                            <button type="button" onclick="changeCalYear(1)" class="btn btn-secondary text-[10px]">سال »</button>
                        </div>
                        <div id="calendar_end_days"></div>
                        <button type="button" onclick="closeCalendar('end')" class="btn btn-danger text-[10px] w-full mt-2">بستن</button>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div><label class="text-xs">نحوه نمایش نتایج</label><select name="show_results" class="form-select"><option value="after_submit" <?php echo ($exam['show_results']??'')==='after_submit'?'selected':''; ?>>بعد از ارسال</option><option value="after_end" <?php echo ($exam['show_results']??'')==='after_end'?'selected':''; ?>>بعد از پایان مهلت</option><option value="immediately" <?php echo ($exam['show_results']??'')==='immediately'?'selected':''; ?>>فوری بعد هر سوال</option><option value="never" <?php echo ($exam['show_results']??'')==='never'?'selected':''; ?>>عدم نمایش</option></select></div>
                <div><label class="text-xs">وضعیت</label><select name="status" class="form-select"><option value="draft" <?php echo ($exam['status']??'draft')==='draft'?'selected':''; ?>>پیش‌نویس</option><option value="published" <?php echo ($exam['status']??'')==='published'?'selected':''; ?>>منتشر شده</option><option value="archived" <?php echo ($exam['status']??'')==='archived'?'selected':''; ?>>بایگانی</option></select></div>
            </div>
        </div>

        <div class="card space-y-4">
            <h3 class="font-bold border-b pb-2">⚙️ تنظیمات نمایش و تصادفی‌سازی</h3>
            <div class="grid grid-cols-3 gap-3 text-sm">
                <label><input type="checkbox" name="randomize_questions" <?php echo !empty($exam['randomize_questions'])?'checked':''; ?>> تصادفی‌سازی ترتیب سوالات</label>
                <label><input type="checkbox" name="randomize_answers" <?php echo !empty($exam['randomize_answers'])?'checked':''; ?>> تصادفی‌سازی گزینه‌ها</label>
                <label><input type="checkbox" name="allow_copy" <?php echo !empty($exam['allow_copy'])?'checked':''; ?>> اجازه کپی متن</label>
                <label><input type="checkbox" name="show_progress_bar" <?php echo ($display['show_progress_bar']??1)?'checked':''; ?>> نمایش نوار پیشرفت</label>
                <label><input type="checkbox" name="show_question_numbers" <?php echo ($display['show_question_numbers']??1)?'checked':''; ?>> نمایش شماره سوالات</label>
                <label><input type="checkbox" name="one_question_per_page" <?php echo !empty($display['one_question_per_page'])?'checked':''; ?>> هر سوال در صفحه جدا</label>
                <label><input type="checkbox" name="prevent_back" <?php echo !empty($display['prevent_back'])?'checked':''; ?>> جلوگیری از بازگشت</label>
            </div>
        </div>

        <div class="card space-y-4 border-2 border-red-100 bg-red-50/30">
            <h3 class="font-bold border-b pb-2 text-red-700">🛡️ سیستم ضدتقلب - سازگار با ایران</h3>
            <div class="grid grid-cols-3 gap-3 text-sm">
                <label><input type="checkbox" name="enable_webcam" <?php echo ($exam['enable_webcam']??1)?'checked':''; ?>> وب‌کم گوشه</label>
                <label><input type="checkbox" name="enable_location" <?php echo ($exam['enable_location']??1)?'checked':''; ?>> موقعیت مکانی</label>
                <label><input type="checkbox" name="enable_proctoring" <?php echo ($exam['enable_proctoring']??1)?'checked':''; ?>> ردیابی کامل</label>
                <label><input type="checkbox" name="require_camera" <?php echo ($proctor['require_camera']??1)?'checked':''; ?>> الزام دوربین</label>
                <label><input type="checkbox" name="require_mic" <?php echo !empty($proctor['require_mic'])?'checked':''; ?>> الزام میکروفون</label>
                <label><input type="checkbox" name="require_location" <?php echo ($proctor['require_location']??1)?'checked':''; ?>> الزام موقعیت</label>
            </div>

            <div class="p-3 border rounded bg-white space-y-2">
                <label class="text-xs font-bold">📍 حالت موقعیت‌یابی (سازگاری با ایران)</label>
                <select name="location_mode" class="form-select text-xs">
                    <option value="balanced" <?php echo ($proctor['location_mode']??'balanced')==='balanced'?'selected':''; ?>>متعادل - شبکه + GPS (پیشنهادی برای ایران - بدون نیاز ماهواره خارجی)</option>
                    <option value="low" <?php echo ($proctor['location_mode']??'')==='low'?'selected':''; ?>>کم‌دقت - فقط شبکه/وای‌فای (سازگارترین - بدون GPS ماهواره‌ای)</option>
                    <option value="high" <?php echo ($proctor['location_mode']??'')==='high'?'selected':''; ?>>دقیق - GPS ماهواره‌ای (نیاز به آسمان باز + ماهواره خارجی)</option>
                    <option value="disabled" <?php echo ($proctor['location_mode']??'')==='disabled'?'selected':''; ?>>غیرفعال - فقط IP</option>
                </select>
                <p class="text-[11px] text-muted">در ایران به دلیل محدودیت یا کندی GPS ماهواره‌ای، حالت <b>متعادل</b> یا <b>کم‌دقت</b> توصیه می‌شود. این حالت‌ها از دکل‌های موبایل و وای‌فای استفاده می‌کنند نه ماهواره خارجی.</p>
                <div class="flex gap-3">
                    <label class="text-xs"><input type="checkbox" name="allow_ip_fallback" <?php echo ($proctor['allow_ip_fallback']??1)?'checked':''; ?>> اجازه Fallback به IP اگر GPS نگرفت</label>
                    <label class="text-xs"><input type="checkbox" name="allow_location_retry" <?php echo ($proctor['allow_location_retry']??1)?'checked':''; ?>> اجازه تلاش مجدد موقعیت تا 3 بار</label>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-3 text-sm mt-3">
                <label><input type="checkbox" name="detect_tab_switch" <?php echo ($proctor['detect_tab_switch']??1)?'checked':''; ?>> ردیابی تعویض تب</label>
                <label><input type="checkbox" name="detect_copy" <?php echo ($proctor['detect_copy']??1)?'checked':''; ?>> ردیابی کپی</label>
                <label><input type="checkbox" name="detect_right_click" <?php echo ($proctor['detect_right_click']??1)?'checked':''; ?>> مسدود کلیک راست</label>
                <label><input type="checkbox" name="detect_printscreen" <?php echo ($proctor['detect_printscreen']??1)?'checked':''; ?>> مسدود PrintScreen</label>
                <label><input type="checkbox" name="check_internet" <?php echo ($proctor['check_internet']??1)?'checked':''; ?>> بررسی اینترنت</label>
                <label><input type="checkbox" name="same_ip_alert" <?php echo ($proctor['same_ip_alert']??1)?'checked':''; ?>> هشدار IP تکراری</label>
                <label><input type="checkbox" name="proximity_alert" <?php echo ($proctor['proximity_alert']??1)?'checked':''; ?>> هشدار نزدیکی</label>
            </div>
            <div class="space-y-2 mt-3">
                <label class="flex gap-2 items-center"><input type="checkbox" name="enable_watermark" <?php echo ($exam['enable_watermark']??1)?'checked':''; ?>> Overlay ضد AI</label>
                <div><label class="text-xs">متن Watermark</label><input name="watermark_text" class="form-input text-xs" value="<?php echo clean($exam['watermark_text']??''); ?>" placeholder="آزمون هوشمند - تقلب ممنوع"></div>
            </div>
        </div>

        <button class="btn btn-success w-full py-3 font-bold text-base">💾 ذخیره آزمون با تاریخ شمسی</button>
    </form>
</div>

<script>
// --- Jalali conversion (ported from PHP jdf.php) ---
function jalali_to_gregorian_js(jy, jm, jd){
    jy=parseInt(jy); jm=parseInt(jm); jd=parseInt(jd);
    let d4 = (jy+1)%4;
    let doy_j = jm<7 ? ((jm-1)*31+jd) : ((jm-7)*30+jd+186);
    let d33 = Math.floor(((jy-55)%132)*0.0305);
    let a = (d33!=3 && d4<=d33) ? 287 : 286;
    let b = ((d33==1||d33==2) && (d33==d4||d4==1)) ? 78 : ((d33==3 && d4==0) ? 80 : 79);
    if(Math.floor((jy-19)/63)==20){ a=286; b=78; }
    let gy, gd;
    if(doy_j <= a){ gy=jy+621; gd=doy_j+b; } else { gy=jy+622; gd=doy_j-a; }
    let g_a = [0,31,(gy%4==0?29:28),31,30,31,30,31,31,30,31,30,31];
    let gm=0;
    for(gm=0; gm<13; gm++){ let v=g_a[gm]; if(gd<=v) break; gd-=v; }
    return [gy, gm, gd];
}
function isJalaliLeap(jy){
    // 33-year cycle leap years: 1,5,9,13,17,22,26,30
    let r = jy % 33;
    return [1,5,9,13,17,22,26,30].includes(r);
}
function jalaliMonthLength(jy, jm){
    if(jm<=6) return 31;
    if(jm<=11) return 30;
    return isJalaliLeap(jy) ? 30 : 29;
}
function gregorianToWeekday(gy,gm,gd){
    let d = new Date(gy, gm-1, gd);
    return d.getDay(); // 0=Sun
}

// Calendar widget
let currentCalendarType = null; // 'start' or 'end'
let calYear, calMonth;

function openJalaliCalendar(type){
    currentCalendarType = type;
    let yInput = document.querySelector(`[name=${type}_j_y]`);
    let mInput = document.querySelector(`[name=${type}_j_m]`);
    let y = parseInt(yInput.value) || 1404;
    let m = parseInt(mInput.value) || 1;
    calYear = y; calMonth = m;
    renderCalendar();
    document.getElementById(`calendar_${type}`).classList.remove('hidden');
}

function closeCalendar(type){
    document.getElementById(`calendar_${type}`).classList.add('hidden');
}

function changeCalMonth(delta){
    calMonth += delta;
    if(calMonth<1){ calMonth=12; calYear--; }
    if(calMonth>12){ calMonth=1; calYear++; }
    renderCalendar();
}

function changeCalYear(delta){
    calYear += delta;
    renderCalendar();
}

function renderCalendar(){
    if(!currentCalendarType) return;
    let type = currentCalendarType;
    let container = document.getElementById(`calendar_${type}_days`);
    let title = document.getElementById(`calendar_${type}_title`);
    let months = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
    title.textContent = months[calMonth-1] + ' ' + calYear;

    let g = jalali_to_gregorian_js(calYear, calMonth, 1);
    let weekday = gregorianToWeekday(g[0], g[1], g[2]);
    let irWeekday = (weekday+1)%7;

    let daysInMonth = jalaliMonthLength(calYear, calMonth);
    let html = '';
    let weekDays = ['ش','ی','د','س','چ','پ','ج'];
    html += '<div style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px;margin-bottom:6px;">';
    weekDays.forEach(w=>{ html+=`<div style="font-size:10px;text-align:center;font-weight:bold;color:#666;">${w}</div>`; });
    html+='</div>';
    html+='<div style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px;">';

    for(let i=0;i<irWeekday;i++){ html+='<div></div>'; }

    let selectedDay = parseInt(document.querySelector(`[name=${type}_j_d]`).value) || 0;
    let selectedMonth = parseInt(document.querySelector(`[name=${type}_j_m]`).value) || 0;
    let selectedYear = parseInt(document.querySelector(`[name=${type}_j_y]`).value) || 0;

    for(let d=1; d<=daysInMonth; d++){
        let isSelected = (d===selectedDay && calMonth===selectedMonth && calYear===selectedYear);
        let bg = isSelected ? '#4f46e5' : '#ffffff';
        let color = isSelected ? '#ffffff' : '#000000';
        let border = isSelected ? '2px solid #4f46e5' : '1px solid #e2e8f0';
        html+=`<button type="button" onclick="selectJalaliDay(${d})" style="padding:6px 2px;font-size:12px;border-radius:6px;background:${bg};color:${color};border:${border};cursor:pointer;text-align:center;">${d}</button>`;
    }
    html+='</div>';
    container.innerHTML = html;
}

function selectJalaliDay(day){
    if(!currentCalendarType) return;
    let type = currentCalendarType;
    document.querySelector(`[name=${type}_j_y]`).value = calYear;
    document.querySelector(`[name=${type}_j_m]`).value = calMonth;
    document.querySelector(`[name=${type}_j_d]`).value = day;
    closeCalendar(type);
    updateGregorianPreview();
}

function updateGregorianPreview(){
    let sy = document.querySelector('[name=start_j_y]').value;
    let sm = document.querySelector('[name=start_j_m]').value;
    let sd = document.querySelector('[name=start_j_d]').value;
    let sh = document.querySelector('[name=start_h]').value;
    let si = document.querySelector('[name=start_i]').value;
    let g = jalali_to_gregorian_js(sy,sm,sd);
    document.getElementById('start_gregorian_preview').innerHTML = `📅 شمسی: ${sy}/${String(sm).padStart(2,'0')}/${String(sd).padStart(2,'0')} ${String(sh).padStart(2,'0')}:${String(si).padStart(2,'0')} → میلادی: ${g[0]}-${String(g[1]).padStart(2,'0')}-${String(g[2]).padStart(2,'0')} (ذخیره خودکار)`;

    let ey = document.querySelector('[name=end_j_y]').value;
    let em = document.querySelector('[name=end_j_m]').value;
    let ed = document.querySelector('[name=end_j_d]').value;
    let eh = document.querySelector('[name=end_h]').value;
    let ei = document.querySelector('[name=end_i]').value;
    let g2 = jalali_to_gregorian_js(ey,em,ed);
    document.getElementById('end_gregorian_preview').innerHTML = `📅 شمسی: ${ey}/${String(em).padStart(2,'0')}/${String(ed).padStart(2,'0')} ${String(eh).padStart(2,'0')}:${String(ei).padStart(2,'0')} → میلادی: ${g2[0]}-${String(g2[1]).padStart(2,'0')}-${String(g2[2]).padStart(2,'0')}`;
}

document.querySelectorAll('[name^=start_j], [name^=end_j], [name=start_h], [name=start_i], [name=end_h], [name=end_i]').forEach(el=>el.addEventListener('input', updateGregorianPreview));
document.addEventListener('DOMContentLoaded', updateGregorianPreview);
document.addEventListener('click', function(e){
    // Close calendar if click outside
    if(!e.target.closest('.jalali-calendar') && !e.target.closest('[onclick^=openJalaliCalendar]')){
        document.querySelectorAll('.jalali-calendar').forEach(el=>el.classList.add('hidden'));
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
