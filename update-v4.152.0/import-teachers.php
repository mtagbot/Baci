<?php
/**
 * Bulk Teachers Importer (import-teachers.php)
 * Parses teacher.csv (Dataemployees_nationalCode, Dataemployees_code, Dataemployees_fullName, Dataemployees_mobile).
 * v4.40.0 - Teachers are YEAR-INDEPENDENT: one record per person, usable in all academic years.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/academic_year_helpers.php';
require_once __DIR__ . '/includes/school_roles.php';
require_permission('manage_classes');
ensure_school_roles_schema();

$step = $_GET['step'] ?? 1;

function parseTeacherCsv($filepath) {
    if (!file_exists($filepath)) return [];
    
    $rows = [];
    if (($handle = fopen($filepath, "r")) !== false) {
        $firstLine = fgets($handle);
        if (substr($firstLine, 0, 3) == "\xEF\xBB\xBF") {
            $firstLine = substr($firstLine, 3);
        }
        $headers = str_getcsv(trim($firstLine));
        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) == count($headers)) {
                $rows[] = array_combine($headers, $data);
            } elseif (count($data) > 1) {
                $row = [];
                foreach ($headers as $idx => $h) {
                    $row[$h] = $data[$idx] ?? '';
                }
                $rows[] = $row;
            }
        }
        fclose($handle);
    }

    $parsed = [];
    foreach ($rows as $r) {
        $nid = tr_num(trim($r['Dataemployees_nationalCode'] ?? $r['national_id'] ?? ''), 'en');
        if (empty($nid)) continue;

        $code = trim($r['Dataemployees_code'] ?? $r['personnel_code'] ?? $nid);
        $name = trim($r['Dataemployees_fullName'] ?? $r['full_name'] ?? '');
        $mob  = tr_num(trim($r['Dataemployees_mobile'] ?? $r['mobile'] ?? ''), 'en');

        $exists = DB::fetch("SELECT id, full_name, mobile FROM teachers WHERE national_id = ?", [$nid]);
        $parsed[] = [
            'national_id' => $nid,
            'code'        => $code,
            'full_name'   => $name,
            'mobile'      => $mob,
            'exists'      => $exists ? true : false,
            'old_data'    => $exists
        ];
    }
    return $parsed;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_teachers') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'خطای امنیتی CSRF');
        redirect('import-teachers.php');
    }
    if (isset($_FILES['teacher_file']) && $_FILES['teacher_file']['error'] === UPLOAD_ERR_OK) {
        $destFolder = __DIR__ . '/uploads';
        if (!is_dir($destFolder)) mkdir($destFolder, 0777, true);
        
        $filename = 'teachers_' . time() . '.csv';
        move_uploaded_file($_FILES['teacher_file']['tmp_name'], $destFolder . '/' . $filename);

        $_SESSION['import_teachers_file'] = $filename;
        redirect('import-teachers.php?step=2');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'execute_teachers') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'خطای امنیتی CSRF');
        redirect('import-teachers.php');
    }
    $selectedIds = $_POST['selected_nids'] ?? [];
    $filepath = __DIR__ . '/uploads/' . ($_SESSION['import_teachers_file'] ?? '');

    if (!file_exists($filepath) || empty($selectedIds)) {
        set_flash_message('error', 'فایلی انتخاب نشده است.');
        redirect('import-teachers.php');
    }

    $allParsed = parseTeacherCsv($filepath);
    $updatedCount = 0;
    $insertedCount = 0;

    foreach ($allParsed as $t) {
        if (!in_array($t['national_id'], $selectedIds)) continue;

        $nid  = $t['national_id'];
        $code = $t['code'];
        $name = $t['full_name'];
        $mob  = $t['mobile'];
        $pass = password_hash($code ?: $nid, PASSWORD_DEFAULT);
        /* v4.90.0: تفکیک نام/نام خانوادگی — قالب فایل پرسنلی «نام‌خانوادگی نام» است */
        $impToks = preg_split('/\s+/u', trim($name));
        $impLast = array_shift($impToks) ?: '';
        $impFirst = trim(implode(' ', $impToks));

        $exists = DB::fetch("SELECT id FROM teachers WHERE national_id = ?", [$nid]);
        if ($exists) {
            DB::execute("UPDATE teachers SET personnel_code=?, full_name=?, first_name=?, last_name=?, mobile=?, password=? WHERE national_id=?",
                [$code, $name, $impFirst, $impLast, $mob, $pass, $nid]);
            $updatedCount++;
        } else {
            DB::execute("INSERT INTO teachers (national_id, personnel_code, full_name, first_name, last_name, mobile, password, status) VALUES (?, ?, ?, ?, ?, ?, ?, 1)",
                [$nid, $code, $name, $impFirst, $impLast, $mob, $pass]);
            $insertedCount++;
        }
    }

    set_flash_message('success', "عملیات با موفقیت انجام شد: $updatedCount دبیر بروزرسانی و $insertedCount دبیر جدید ثبت شد.");
    redirect('import-teachers.php?step=3&updated=' . $updatedCount . '&inserted=' . $insertedCount);
}

// Handle Delete Teacher
if (isset($_GET['del_teacher'])) {
    DB::execute("DELETE FROM teachers WHERE id = ?", [(int)$_GET['del_teacher']]);
    set_flash_message('success', 'حساب دبیر مورد نظر حذف شد.');
    redirect('import-teachers.php?tab=list');
}

// v4.40.0 - 'transfer teachers to other year' removed: teachers are year-independent now.

// Handle Bulk Delete Teachers
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['bulk_delete_teachers'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { set_flash_message('error','CSRF'); redirect('import-teachers.php?tab=list'); }
    $ids = array_values(array_filter(array_map('intval', $_POST['teacher_ids'] ?? [])));
    if (!$ids) { set_flash_message('error','هیچ دبیری انتخاب نشده'); redirect('import-teachers.php?tab=list'); }
    $deleted=0; $names=[];
    foreach ($ids as $tid) {
        $t = DB::fetch("SELECT full_name FROM teachers WHERE id=?", [$tid]);
        DB::execute("DELETE FROM teachers WHERE id=?", [$tid]);
        $deleted++; if($t) $names[]=$t['full_name'];
    }
    set_flash_message('success', "✅ $deleted دبیر حذف شد: ".implode('، ', $names));
    redirect('import-teachers.php?tab=list');
}

// Handle Manual Teacher Edit / Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_teacher_manual'])) {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        $tId  = (int)($_POST['teacher_id'] ?? 0);
        $nid  = tr_num(trim($_POST['national_id'] ?? ''), 'en');
        $code = trim($_POST['personnel_code'] ?? '');
        /* v4.90.0: نام و نام خانوادگی دبیر دو فیلد مجزا هستند؛ full_name برای
           سازگاری با بقیه سیستم از همین دو ساخته می‌شود. */
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name'] ?? '');
        $name = trim($firstName . ' ' . $lastName);
        if ($name === '') $name = trim($_POST['full_name'] ?? '');
        $mob  = tr_num(trim($_POST['mobile'] ?? ''), 'en');
        $st   = (int)($_POST['status'] ?? 1);
        $isDeputy = isset($_POST['is_deputy']) ? 1 : 0;
        $isCounselor = isset($_POST['is_counselor']) ? 1 : 0;
        $isExecutive = isset($_POST['is_executive']) ? 1 : 0;

        if ($tId > 0) {
            $q = "UPDATE teachers SET national_id=?, personnel_code=?, full_name=?, first_name=?, last_name=?, mobile=?, status=?, is_deputy=?, is_counselor=?, is_executive=? WHERE id=?";
            $p = [$nid, $code, $name, $firstName, $lastName, $mob, $st, $isDeputy, $isCounselor, $isExecutive, $tId];
            if (!empty($_POST['password'])) {
                $q = "UPDATE teachers SET national_id=?, personnel_code=?, full_name=?, first_name=?, last_name=?, mobile=?, status=?, is_deputy=?, is_counselor=?, is_executive=?, password=? WHERE id=?";
                $p = [$nid, $code, $name, $firstName, $lastName, $mob, $st, $isDeputy, $isCounselor, $isExecutive, password_hash($_POST['password'], PASSWORD_DEFAULT), $tId];
            }
            DB::execute($q, $p);
            set_flash_message('success', 'اطلاعات دبیر و نقش‌های معاون/مشاور بروزرسانی شد.');
        } else {
            $dupNid = DB::fetch("SELECT id, full_name FROM teachers WHERE national_id=?", [$nid]);
            if ($dupNid) {
                set_flash_message('error', "دبیری با این کد ملی قبلاً ثبت شده است ({$dupNid['full_name']}). دبیران مستقل از سال تحصیلی هستند و نیازی به ثبت مجدد نیست.");
            } else {
                $pass = password_hash($_POST['password'] ?: ($code ?: $nid), PASSWORD_DEFAULT);
                DB::execute("INSERT INTO teachers (national_id, personnel_code, full_name, first_name, last_name, mobile, password, status, is_deputy, is_counselor, is_executive) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$nid, $code, $name, $firstName, $lastName, $mob, $pass, $st, $isDeputy, $isCounselor, $isExecutive]);
                set_flash_message('success', 'دبیر جدید ثبت شد (قابل استفاده در همه سال‌های تحصیلی).');
            }
        }
    }
    redirect('import-teachers.php?tab=list');
}

require_once __DIR__ . '/includes/header.php';
$tab = $_GET['tab'] ?? 'import';
$filterQ    = trim($_GET['q'] ?? '');

?>
<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold"><svg data-ui-icon="users" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="9" cy="7" r="3"/><path d="M2 21v-3c0-6 14-6 14 0v3M16 4c5 0 5 6 1 6M19 14c3 1 3 4 3 7"/></svg> مدیریت و ایمپورت پرونده دبیران (teacher.csv)</h2>
            <p class="text-sm text-muted">ثبت و ویرایش کادر آموزشی — دبیران مستقل از سال تحصیلی هستند و در همه سال‌ها قابل استفاده‌اند</p>
        </div>
        <div class="flex gap-2">
            <a href="import-teachers.php?tab=import" class="btn <?php echo $tab === 'import' ? 'btn-primary font-bold' : 'btn-outline'; ?> text-xs"><svg data-ui-icon="bolt" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m14 2-11 12h8l-1 8 11-12h-8Z"/></svg> ایمپورت از فایل CSV</a>
            <a href="import-teachers.php?tab=list" class="btn <?php echo $tab === 'list' || $tab === 'edit' ? 'btn-primary font-bold' : 'btn-outline'; ?> text-xs"><svg data-ui-icon="exam" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="5" y="4" width="14" height="18" rx="2"/><path d="M9 2h6v4H9Zm0 8h6m-6 4h3m-3 4h6"/></svg> لیست و مدیریت دبیران</a>
            <a href="teacher.csv" download class="btn btn-outline text-xs border-blue-500 text-blue-600"><svg data-ui-icon="download" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 16v5h18v-5M12 3v13m-5-5 5 5 5-5"/></svg> دانلود نمونه فایل</a>
        </div>
    </div>

    <?php if ($tab === 'list' || $tab === 'edit'): 
        $editT = null;
        if ($tab === 'edit' && isset($_GET['id'])) {
            $editT = DB::fetch("SELECT * FROM teachers WHERE id = ?", [(int)$_GET['id']]);
        }
        $where = ["1=1"];
        $params = [];
        if (!empty($filterQ)) { $where[] = "(full_name LIKE ? OR national_id LIKE ? OR personnel_code LIKE ?)"; $params[] = "%$filterQ%"; $params[] = "%$filterQ%"; $params[] = "%$filterQ%"; }
        $whereSql = implode(' AND ', $where);
        $teachersList = DB::fetchAll("SELECT * FROM teachers WHERE $whereSql ORDER BY full_name ASC", $params);
        usort($teachersList, fn($a,$b)=>persian_compare($a["full_name"],$b["full_name"]));   // v4.77.0: آ قبل از ا
    ?>
    <div class="grid grid-cols-3 gap-6">
        <!-- Form -->
        <div class="card col-span-1 shadow-lg">
            <h3 class="font-bold mb-4 text-primary border-b pb-2"><?php echo $editT ? 'ویرایش اطلاعات دبیر' : 'ثبت دستی دبیر جدید'; ?></h3>
            <form method="POST" action="import-teachers.php">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="save_teacher_manual" value="1">
                <input type="hidden" name="teacher_id" value="<?php echo $editT['id'] ?? 0; ?>">
                <?php
                    /* v4.90.0: نام و نام خانوادگی مجزا؛ برای رکوردهای قدیمی که
                       فیلد مجزا ندارند، همان حدس backfill نمایش داده می‌شود. */
                    $edFirst = trim($editT['first_name'] ?? '');
                    $edLast  = trim($editT['last_name'] ?? '');
                    if ($edFirst === '' && $edLast === '' && !empty($editT['full_name'])) {
                        $edToks = preg_split('/\s+/u', trim($editT['full_name']));
                        $edLast = array_shift($edToks) ?: '';
                        $edFirst = trim(implode(' ', $edToks));
                    }
                ?>
                <div class="grid grid-cols-2 gap-2 mb-3">
                    <div>
                        <label class="block text-xs font-semibold mb-1">نام *</label>
                        <input type="text" name="first_name" class="form-input text-xs" value="<?php echo clean($edFirst); ?>" required>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">نام خانوادگی *</label>
                        <input type="text" name="last_name" class="form-input text-xs" value="<?php echo clean($edLast); ?>" required>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2 mb-3">
                    <div>
                        <label class="block text-xs font-semibold mb-1">کد ملی (نام کاربری) *</label>
                        <input type="text" name="national_id" class="form-input text-xs font-mono" value="<?php echo clean($editT['national_id'] ?? ''); ?>" required>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">کد پرسنلی (پیش‌فرض رمز)</label>
                        <input type="text" name="personnel_code" class="form-input text-xs font-mono" value="<?php echo clean($editT['personnel_code'] ?? ''); ?>">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2 mb-3">
                    <div>
                        <label class="block text-xs font-semibold mb-1">موبایل</label>
                        <input type="text" name="mobile" class="form-input text-xs font-mono dir-ltr" value="<?php echo clean($editT['mobile'] ?? ''); ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">سال تحصیلی</label>
                        <div class="form-input text-xs font-bold" style="background:var(--bg-secondary,#f1f5f9);cursor:default">همه سال‌ها</div>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2 mb-4">
                    <div>
                        <label class="block text-xs font-semibold mb-1"><?php echo $editT ? 'رمز جدید (اختیاری)' : 'رمز عبور *'; ?></label>
                        <input type="password" name="password" class="form-input text-xs font-mono" <?php echo !$editT ? 'required' : ''; ?>>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">وضعیت حساب</label>
                        <select name="status" class="form-select text-xs">
                            <option value="1" <?php echo ($editT['status'] ?? 1) == 1 ? 'selected' : ''; ?>>فعال</option>
                            <option value="0" <?php echo ($editT['status'] ?? 1) == 0 ? 'selected' : ''; ?>>غیرفعال</option>
                        </select>
                    </div>
                </div>
                <div class="soft-panel mb-6 space-y-2 text-xs">
                    <label class="flex gap-2 items-center"><input type="checkbox" name="is_deputy" <?php echo !empty($editT['is_deputy']) ? 'checked' : ''; ?>> فعال‌سازی نقش معاون/ناظم برای این دبیر</label>
                    <label class="flex gap-2 items-center"><input type="checkbox" name="is_counselor" <?php echo !empty($editT['is_counselor']) ? 'checked' : ''; ?>> فعال‌سازی نقش مشاور برای این دبیر</label>
                    <label class="flex gap-2 items-center"><input type="checkbox" name="is_executive" <?php echo !empty($editT['is_executive']) ? 'checked' : ''; ?>> فعال‌سازی نقش معاون اجرایی برای این دبیر</label>
                </div>
                <div class="flex gap-2">
                    <?php if ($editT): ?><a href="import-teachers.php?tab=list" class="btn btn-secondary text-xs flex-1">انصراف</a><?php endif; ?>
                    <button type="submit" class="btn btn-success text-xs font-bold py-2.5 flex-1"><svg data-ui-icon="save" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 3h15l3 3v15H3Zm4 0v7h10V3M7 21v-7h10v7"/></svg> ذخیره اطلاعات دبیر</button>
                </div>
            </form>
        </div>

        <!-- Table -->
        <div class="card col-span-2 shadow-lg space-y-4">
            <form method="GET" class="flex gap-3 items-end bg-slate-50 dark:bg-slate-800 p-3 rounded border">
                <input type="hidden" name="tab" value="list">
                <div class="flex-1">
                    <label class="block text-xs font-semibold mb-1">جستجوی نام یا کد ملی دبیر</label>
                    <input type="text" name="q" class="form-input text-xs" placeholder="نام، کد پرسنلی یا کد ملی..." value="<?php echo clean($filterQ); ?>">
                </div>
                <button type="submit" class="btn btn-primary text-xs font-bold px-4">فیلتر</button>
                <?php if ($filterQ): ?><a href="import-teachers.php?tab=list" class="btn btn-secondary text-xs px-2">حذف</a><?php endif; ?>
            </form>

            <form method="POST" id="bulkTeachersForm">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <div class="flex gap-2 mb-3 flex-wrap">
                    <button type="button" class="btn btn-outline text-xs" onclick="document.querySelectorAll('.teacher-check').forEach(c=>c.checked=true)">انتخاب همه</button>
                    <button type="button" class="btn btn-outline text-xs" onclick="document.querySelectorAll('.teacher-check').forEach(c=>c.checked=false)">لغو انتخاب</button>
                    <button type="submit" name="bulk_delete_teachers" value="1" class="btn btn-danger text-xs" onclick="return confirm('حذف دسته‌جمعی دبیران انتخاب شده؟');">حذف دسته‌جمعی</button>
                    <span class="text-xs text-muted self-center">دبیران مستقل از سال تحصیلی هستند و در همه سال‌ها معتبرند.</span>
                </div>
            <div class="table-container max-h-[500px]">
                <table>
                    <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.teacher-check').forEach(c=>c.checked=this.checked)"></th><th>شناسه</th><th>کد ملی</th><th>کد پرسنلی</th><th>نام</th><th>نقش‌ها</th><th>موبایل</th><th>عملیات</th></tr></thead>
                    <tbody>
                        <?php foreach ($teachersList as $t): ?>
                        <tr>
                            <td><input type="checkbox" class="teacher-check" name="teacher_ids[]" value="<?php echo $t['id']; ?>"></td>
                            <td class="font-mono text-xs">#<?php echo $t['id']; ?></td>
                            <td class="font-mono font-bold"><?php echo tr_num($t['national_id'], 'fa'); ?></td>
                            <td class="font-mono"><?php echo clean($t['personnel_code']); ?></td>
                            <td class="font-bold"><?php echo clean($t['full_name']); ?></td>
                            <td><?php if(!empty($t['is_deputy'])): ?><span class="badge badge-warning text-xs">معاون</span><?php endif; ?> <?php if(!empty($t['is_counselor'])): ?><span class="badge badge-info text-xs">مشاور</span><?php endif; ?> <?php if(!empty($t['is_executive'])): ?><span class="badge badge-success text-xs">معاون اجرایی</span><?php endif; ?></td>
                            <td class="font-mono dir-ltr text-xs"><?php echo clean($t['mobile'] ?: '---'); ?></td>
                            <td>
                                <div class="flex gap-1 justify-end">
                                    <a href="import-teachers.php?tab=edit&id=<?php echo $t['id']; ?>" class="btn btn-secondary text-xs px-2 py-1">ویرایش</a>
                                    <a href="import-teachers.php?tab=list&del_teacher=<?php echo $t['id']; ?>" onclick="return confirm('حذف این دبیر؟')" class="btn btn-danger text-xs px-2 py-1">حذف</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; if (empty($teachersList)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-6">دبیری یافت نشد.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            </form>
        </div>
    </div>

    <?php elseif ($tab === 'import'): ?>

    <div class="wizard-steps card">
        <div class="wizard-step <?php echo $step >= 1 ? 'active' : ''; ?>">۱. آپلود فایل دبیران</div>
        <div class="wizard-step <?php echo $step >= 2 ? 'active' : ''; ?>">۲. بررسی و تایید آپدیت</div>
        <div class="wizard-step <?php echo $step >= 3 ? 'completed' : ''; ?>">۳. نتیجه ثبت</div>
    </div>

    <?php if ($step == 1): ?>
    <div class="card max-w-xl mx-auto shadow-lg p-6">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="action" value="upload_teachers">
            <div class="mb-4">
                <label class="block text-xs font-bold mb-2">فایل مشخصات دبیران (CSV)</label>
                <input type="file" name="teacher_file" class="form-input p-2" accept=".csv" required>
            </div>
            <button type="submit" class="btn btn-primary w-full py-3 font-bold text-sm">آپلود و بررسی &larr;</button>
        </form>
    </div>

    <?php elseif ($step == 2):
        $parsed = parseTeacherCsv(__DIR__ . '/uploads/' . ($_SESSION['import_teachers_file'] ?? ''));
    ?>
    <form method="POST" action="import-teachers.php?step=2">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="action" value="execute_teachers">
        <div class="card mb-4 flex justify-between items-center bg-blue-50 dark:bg-slate-800">
            <div>
                <span class="text-xs font-bold">دبیران به صورت سراسری ثبت می‌شوند و در تمام سال‌های تحصیلی قابل استفاده‌اند.</span>
            </div>
            <button type="submit" class="btn btn-success px-6 py-3 font-bold shadow"><svg data-ui-icon="check" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="m7 12 3 3 7-7"/></svg> تایید نهایی و ثبت دبیران &larr;</button>
        </div>
        <div class="card">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>انتخاب</th>
                            <th>کد ملی (نام کاربری)</th>
                            <th>کد پرسنلی (رمز ورود)</th>
                            <th>نام و نام خانوادگی دبیر</th>
                            <th>شماره موبایل</th>
                            <th>وضعیت در سیستم</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($parsed as $t): ?>
                        <tr>
                            <td><input type="checkbox" name="selected_nids[]" value="<?php echo clean($t['national_id']); ?>" checked></td>
                            <td class="font-mono font-bold"><?php echo tr_num($t['national_id'], 'fa'); ?></td>
                            <td class="font-mono"><?php echo clean($t['code']); ?></td>
                            <td class="font-bold"><?php echo clean($t['full_name']); ?></td>
                            <td class="font-mono dir-ltr"><?php echo clean($t['mobile']); ?></td>
                            <td><?php echo $t['exists'] ? '<span class="badge bg-amber-500 text-white text-xs">موجود - آپدیت 🔄</span>' : '<span class="badge bg-green-600 text-white text-xs">جدید - ایجاد ➕</span>'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </form>
    <?php elseif ($step == 3): ?>
    <div class="card text-center py-8">
        <div class="text-5xl mb-4"><svg data-ui-icon="award" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="8" r="6"/><path d="m8 13-2 9 6-3 6 3-2-9"/></svg></div>
        <h2 class="text-2xl font-bold text-green-600 mb-2">ایمپورت دبیران با موفقیت انجام شد!</h2>
        <a href="classes.php" class="btn btn-primary px-6 mt-4">مشاهده دروس و کلاس‌ها</a>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
