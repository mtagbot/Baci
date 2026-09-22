<?php
// File: staff-student-file.php
/**
 * Read-only student dossier for deputy/counselor staff.
 * معاون/ناظم و مشاور بدون نیاز به رمز دانش‌آموز به اطلاعات، کارنامه‌ها و موارد انضباطی دسترسی دارند.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/school_roles.php';
ensure_school_roles_schema();
if (!is_admin_logged_in()) {
    require_student_file_staff_access();
}

$studentId = (int)($_GET['id'] ?? $_GET['student_id'] ?? 0);
$tab = $_GET['tab'] ?? 'info';
$student = DB::fetch("SELECT * FROM students WHERE id = ?", [$studentId]);
if (!$student) {
    set_flash_message('error', 'دانش‌آموز مورد نظر یافت نشد.');
    redirect(teacher_has_counselor($_SESSION['teacher_id']) ? 'counselor-panel.php?tab=students' : 'deputy-panel.php?tab=students');
}

$canDeputy = is_admin_logged_in() ? true : teacher_has_deputy($_SESSION['teacher_id']);
$canCounselor = is_admin_logged_in() ? false : teacher_has_counselor($_SESSION['teacher_id']);
$backUrl = is_admin_logged_in() ? 'deputy-panel.php?tab=students' : ($canCounselor && !$canDeputy ? 'counselor-panel.php?tab=students' : 'deputy-panel.php?tab=students');
require_once __DIR__ . '/includes/header.php';
$reports = DB::fetchAll("SELECT * FROM reports WHERE student_id = ? ORDER BY id DESC", [$studentId]);
$discipline = DB::fetchAll("SELECT * FROM student_discipline_records WHERE student_id = ? ORDER BY id DESC", [$studentId]);
?>
<div class="space-y-6">
    <div class="page-hero flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold">پرونده دانش‌آموز: <?php echo clean($student['first_name'] . ' ' . $student['last_name']); ?></h2>
            <p class="text-sm text-muted">دسترسی کادر مجاز: <?php echo $canDeputy ? 'معاون/ناظم' : 'مشاور'; ?> — بدون نیاز به ورود دانش‌آموز</p>
        </div>
        <div class="flex gap-2 flex-wrap">
            <a class="btn <?php echo $tab === 'info' ? 'btn-primary' : 'btn-outline'; ?>" href="staff-student-file.php?id=<?php echo $studentId; ?>&tab=info">اطلاعات</a>
            <a class="btn <?php echo $tab === 'reports' ? 'btn-primary' : 'btn-outline'; ?>" href="staff-student-file.php?id=<?php echo $studentId; ?>&tab=reports">کارنامه‌ها</a>
            <a class="btn <?php echo $tab === 'discipline' ? 'btn-primary' : 'btn-outline'; ?>" href="staff-student-file.php?id=<?php echo $studentId; ?>&tab=discipline">موارد انضباطی</a>
            <a class="btn btn-secondary" href="<?php echo clean($backUrl); ?>">بازگشت</a>
        </div>
    </div>

    <div class="card flex gap-4 items-center">
        <div class="avatar-lg" style="width:90px;height:120px;border-radius:.75rem"><?php if(!empty($student['photo_url'])): ?><img src="<?php echo clean($student['photo_url']); ?>"><?php else: ?>👤<?php endif; ?></div>
        <div class="grid grid-cols-3 gap-3 flex-1 text-sm">
            <div><b>نام:</b> <?php echo clean($student['first_name'].' '.$student['last_name']); ?></div>
            <div><b>کلاس:</b> <?php echo clean($student['class_name']); ?></div>
            <div><b>پایه:</b> <?php echo clean($student['grade_level']); ?></div>
            <div><b>کد ملی:</b> <?php echo tr_num($student['national_id'],'fa'); ?></div>
            <div><b>موبایل پدر:</b> <?php echo tr_num($student['father_phone'] ?: '---','fa'); ?></div>
            <div><b>موبایل مادر:</b> <?php echo tr_num($student['mother_phone'] ?: '---','fa'); ?></div>
        </div>
    </div>

    <?php if ($tab === 'reports'): ?>
    <div class="card">
        <h3 class="font-bold mb-4">کارنامه‌های صادرشده</h3>
        <div class="table-container"><table><thead><tr><th>سال</th><th>نوبت/ماه</th><th>معدل</th><th>رتبه کلاس</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
            <?php foreach ($reports as $r): ?><tr>
                <td><?php echo clean($r['academic_year']); ?></td>
                <td><?php echo clean($r['term'] . ' / ' . $r['report_month']); ?></td>
                <td><span class="badge badge-info"><?php echo format_score($r['gpa']); ?></span></td>
                <td><?php echo tr_num($r['rank_in_class'] ?: '---', 'fa'); ?></td>
                <td><?php echo $r['is_locked'] ? '<span class="badge badge-danger">قفل</span>' : '<span class="badge badge-success">آزاد</span>'; ?></td>
                <td><a class="btn btn-primary text-xs" href="report-view.php?id=<?php echo $r['id']; ?>&staff=1">مشاهده</a> <a class="btn btn-secondary text-xs" target="_blank" href="report-print.php?id=<?php echo $r['id']; ?>&staff=1">چاپ</a></td>
            </tr><?php endforeach; if (!$reports): ?><tr><td colspan="6" class="text-center text-muted">کارنامه‌ای ثبت نشده است.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
    <?php elseif ($tab === 'discipline'): ?>
    <div class="card">
        <div class="flex justify-between items-center mb-4"><h3 class="font-bold">موارد انضباطی</h3><?php if ($canDeputy): ?><a class="btn btn-warning text-xs" href="deputy-panel.php?tab=records&student_id=<?php echo $studentId; ?>">ثبت/ویرایش توسط ناظم</a><?php endif; ?></div>
        <div class="table-container"><table><thead><tr><th>عنوان</th><th>تاریخ و زمان شمسی</th><?php if ($canDeputy): ?><th>یادداشت داخلی</th><th>اعلان</th><?php endif; ?></tr></thead><tbody>
            <?php foreach ($discipline as $d): ?><tr>
                <td class="font-bold"><?php echo clean($d['title_text']); ?></td>
                <td><?php echo tr_num($d['occurred_at_jalali'], 'fa'); ?></td>
                <?php if ($canDeputy): ?><td><?php echo nl2br(clean($d['internal_note'])); ?></td><td><?php echo $d['notify_parents'] ? 'ارسال شده/فعال' : 'خیر'; ?></td><?php endif; ?>
            </tr><?php endforeach; if (!$discipline): ?><tr><td colspan="<?php echo $canDeputy ? 4 : 2; ?>" class="text-center text-muted">موردی ثبت نشده است.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-2 gap-6">
        <div class="card space-y-3">
            <h3 class="font-bold text-primary border-b pb-2">اطلاعات هویتی و آموزشی</h3>
            <p><b>نام:</b> <?php echo clean($student['first_name'] . ' ' . $student['last_name']); ?></p>
            <p><b>کد ملی:</b> <?php echo tr_num($student['national_id'], 'fa'); ?></p>
            <p><b>کد دانش‌آموزی:</b> <?php echo clean($student['student_code'] ?: '---'); ?></p>
            <p><b>نام پدر:</b> <?php echo clean($student['father_name'] ?: '---'); ?><?php if(!empty($student['father_deceased'])): ?> <span class="badge badge-dark text-xs">فوت شده</span><?php endif; ?></p>
            <p><b>نام مادر:</b> <?php echo clean(($student['mother_name'] ?? '') ?: '---'); ?><?php if(!empty($student['mother_deceased'])): ?> <span class="badge badge-dark text-xs">فوت شده</span><?php endif; ?></p>
            <p><b>تاریخ تولد:</b> <?php echo tr_num($student['birth_date'] ?: '---', 'fa'); ?></p>
            <p><b>محل تولد:</b> <?php echo clean(($student['birth_place'] ?? '') ?: '---'); ?> — <b>محل صدور:</b> <?php echo clean(($student['place_issued'] ?? '') ?: '---'); ?></p>
            <p><b>ملیت:</b> <?php echo clean(($student['nationality_title'] ?? '') ?: 'ایرانی'); ?><?php if(!empty($student['is_foreign'])): ?> <span class="badge badge-danger text-xs">اتباع</span><?php endif; ?></p>
            <p><b>پایه:</b> <?php echo clean($student['grade_level']); ?> — <b>کلاس:</b> <?php echo clean($student['class_name']); ?></p>
            <p><b>معدل سال قبل:</b> <?php echo tr_num(($student['last_year_average'] ?? '') ?: '---', 'fa'); ?></p>
        </div>
        <div class="card space-y-3">
            <h3 class="font-bold text-primary border-b pb-2">اطلاعات تماس و وضعیت</h3>
            <p><b>موبایل دانش‌آموز:</b> <?php echo tr_num(($student['student_mobile'] ?? '') ?: ($student['phone'] ?: '---'), 'fa'); ?></p>
            <p><b>موبایل پدر:</b> <?php echo tr_num($student['father_phone'] ?: '---', 'fa'); ?></p>
            <p><b>موبایل مادر:</b> <?php echo tr_num($student['mother_phone'] ?: '---', 'fa'); ?></p>
            <p><b>تلفن منزل:</b> <?php echo tr_num(($student['home_phone'] ?? '') ?: '---', 'fa'); ?></p>
            <p><b>کد پستی:</b> <?php echo tr_num(($student['postal_code'] ?? '') ?: '---', 'fa'); ?></p>
            <p><b>آدرس منزل:</b> <?php echo clean(($student['home_address'] ?? '') ?: '---'); ?></p>
            <p><b>وضعیت:</b> <?php echo clean($student['status']); ?></p>
            <p><b>تاریخ ثبت:</b> <?php echo tr_num($student['created_at'] ?: '---', 'fa'); ?></p>
        </div>
        <div class="card space-y-3">
            <h3 class="font-bold text-primary border-b pb-2">اطلاعات والدین</h3>
            <p><b>تحصیلات پدر:</b> <?php echo clean(($student['father_qualification'] ?? '') ?: '---'); ?> — <b>شغل پدر:</b> <?php echo clean(($student['father_job'] ?? '') ?: '---'); ?></p>
            <p><b>تحصیلات مادر:</b> <?php echo clean(($student['mother_qualification'] ?? '') ?: '---'); ?> — <b>شغل مادر:</b> <?php echo clean(($student['mother_job'] ?? '') ?: '---'); ?></p>
            <p><b>وضعیت مسکن:</b> <?php echo clean(($student['housing_title'] ?? '') ?: '---'); ?></p>
        </div>
        <div class="card space-y-3">
            <h3 class="font-bold text-primary border-b pb-2">سلامت و بیمه</h3>
            <p><b>بیماری خاص:</b> <?php echo clean(($student['specific_disease_title'] ?? '') ?: '---'); ?></p>
            <p><b>محدودیت ورزش:</b> <?php echo !empty($student['has_sport_limitation']) ? '<span class="badge badge-warning">بله</span>' : 'خیر'; ?></p>
            <p><b>پوشش / بیمه:</b> <?php echo clean(($student['cover_title'] ?? '') ?: '---'); ?></p>
            <p><b>دین:</b> <?php echo clean(($student['religion_title'] ?? '') ?: '---'); ?></p>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
