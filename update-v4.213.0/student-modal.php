<?php
// File: student-modal.php
/**
 * AJAX modal/tooltip content for admin student list.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/school_roles.php';
require_permission('manage_students');
ensure_school_roles_schema();
header('Content-Type: application/json; charset=utf-8');
$type = $_GET['type'] ?? $_POST['type'] ?? 'hover';
$studentId = (int)($_GET['student_id'] ?? $_POST['student_id'] ?? 0);
$student = DB::fetch("SELECT * FROM students WHERE id=?", [$studentId]);
if (!$student) { echo json_encode(['ok'=>false,'html'=>'دانش‌آموز یافت نشد.'], JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { echo json_encode(['ok'=>false,'html'=>'خطای امنیتی CSRF'], JSON_UNESCAPED_UNICODE); exit; }
    if ($type === 'discipline_save') {
        $titleId=(int)($_POST['title_id']??0); $tr=$titleId?DB::fetch('SELECT title FROM discipline_titles WHERE id=?',[$titleId]):null;
        $title = trim($tr['title'] ?? ($_POST['title_text'] ?? ''));
        $note = trim($_POST['internal_note'] ?? '');
        $occur = trim($_POST['occurred_at_jalali'] ?? jalali_now());
        $notify = isset($_POST['notify_parents']) ? 1 : 0; $just=isset($_POST['is_justified'])?1:0; $review=trim($_POST['review_status']??'pending'); $reviewNote=trim($_POST['review_note']??'');
        if ($title !== '') {
            DB::execute("INSERT INTO student_discipline_records (student_id,title_id,title_text,internal_note,occurred_at_jalali,notify_parents,is_justified,review_status,review_note,created_by_teacher_id,created_at_jalali) VALUES (?,?,?,?,?,?,?,?,?,?,?)", [$studentId,$titleId?:null,$title,$note,$occur,$notify,$just,$review,$reviewNote,null,jalali_now()]);
            $newRid = DB::lastInsertId();
            if ($notify) notify_student_discipline_bots($studentId,$title,$occur,$newRid);
        }
        $type = 'discipline';
    }
    if ($type === 'discipline_update') {
        /* V4.213.0 — «متن بررسی» هم ذخیره می‌شود؛ قبلاً فقط در فرم ثبت بود و در ویرایش گم می‌شد. */
        DB::execute("UPDATE student_discipline_records SET title_text=?, occurred_at_jalali=?, internal_note=?, is_justified=?, review_status=?, review_note=?, updated_at_jalali=? WHERE id=? AND student_id=?", [trim($_POST['title_text']??''), trim($_POST['occurred_at_jalali']??''), trim($_POST['internal_note']??''), isset($_POST['is_justified'])?1:0, trim($_POST['review_status']??'pending'), trim($_POST['review_note']??''), jalali_now(), (int)$_POST['record_id'], $studentId]);
        $type = 'discipline';
    }
    if ($type === 'discipline_delete') {
        DB::execute("DELETE FROM student_discipline_records WHERE id=? AND student_id=?", [(int)$_POST['record_id'],$studentId]);
        $type = 'discipline';
    }
}

ob_start();
$profileCard = '<div class="soft-panel flex gap-3 items-center mb-3"><div class="avatar-lg">' . (!empty($student['photo_url']) ? '<img src="'.clean($student['photo_url']).'">' : '👤') . '</div><div><b>'.clean($student['first_name'].' '.$student['last_name']).'</b><br><span class="text-xs">'.clean($student['class_name'].' / '.$student['grade_level']).'</span><br><span class="text-xs">پدر: '.tr_num($student['father_phone'] ?: '---','fa').' | مادر: '.tr_num($student['mother_phone'] ?: '---','fa').'</span></div></div>';
if ($type === 'info'):
?>
<?php echo $profileCard; ?><h3 class="font-bold text-primary mb-3">اطلاعات پرونده دانش‌آموز</h3>
<div class="grid grid-cols-2 gap-3">
  <div class="soft-panel"><b>نام:</b> <?php echo clean($student['first_name'].' '.$student['last_name']); ?><br><b>کد ملی:</b> <?php echo tr_num($student['national_id'],'fa'); ?><br><b>نام پدر:</b> <?php echo clean($student['father_name'] ?: '---'); ?><br><b>نام مادر:</b> <?php echo clean(($student['mother_name'] ?? '') ?: '---'); ?><br><b>تاریخ تولد:</b> <?php echo tr_num($student['birth_date'] ?: '---','fa'); ?><br><b>محل تولد:</b> <?php echo clean(($student['birth_place'] ?? '') ?: '---'); ?><br><b>محل صدور:</b> <?php echo clean(($student['place_issued'] ?? '') ?: '---'); ?></div>
  <div class="soft-panel"><b>پایه/کلاس:</b> <?php echo clean($student['grade_level'].' / '.$student['class_name']); ?><br><b>تلفن:</b> <?php echo tr_num($student['phone'] ?: '---','fa'); ?><br><b>پدر:</b> <?php echo tr_num($student['father_phone'] ?: '---','fa'); ?><br><b>مادر:</b> <?php echo tr_num($student['mother_phone'] ?: '---','fa'); ?><br><b>تلفن منزل:</b> <?php echo tr_num(($student['home_phone'] ?? '') ?: '---','fa'); ?><br><b>کد پستی:</b> <?php echo tr_num(($student['postal_code'] ?? '') ?: '---','fa'); ?></div>
  <div class="soft-panel"><b>تحصیلات پدر:</b> <?php echo clean(($student['father_qualification'] ?? '') ?: '---'); ?><br><b>شغل پدر:</b> <?php echo clean(($student['father_job'] ?? '') ?: '---'); ?><br><b>تحصیلات مادر:</b> <?php echo clean(($student['mother_qualification'] ?? '') ?: '---'); ?><br><b>شغل مادر:</b> <?php echo clean(($student['mother_job'] ?? '') ?: '---'); ?><br><b>معدل سال قبل:</b> <?php echo tr_num(($student['last_year_average'] ?? '') ?: '---','fa'); ?></div>
  <div class="soft-panel"><b>آدرس منزل:</b> <?php echo clean(($student['home_address'] ?? '') ?: '---'); ?><br><b>بیماری خاص:</b> <?php echo clean(($student['specific_disease_title'] ?? '') ?: '---'); ?><br>
  <?php if(!empty($student['is_foreign'])): ?><span class="badge badge-danger text-xs">اتباع</span> <?php endif; ?>
  <?php if(!empty($student['has_sport_limitation'])): ?><span class="badge badge-warning text-xs">محدودیت ورزش</span> <?php endif; ?>
  <?php if(!empty($student['mother_deceased'])): ?><span class="badge badge-dark text-xs">مادر فوت شده</span> <?php endif; ?>
  <?php if(!empty($student['father_deceased'])): ?><span class="badge badge-dark text-xs">پدر فوت شده</span> <?php endif; ?>
  </div>
</div>
<div class="mt-3"><a class="btn btn-secondary text-xs" target="_blank" href="students.php?action=edit&id=<?php echo $studentId; ?>">ویرایش پرونده</a></div>
<?php
elseif ($type === 'hover'):
?>
<?php
// v4.34.0: hover card — phones only (large font) + special flags. No national id / father name.
$specialFlags = [];
if (!empty($student['has_sport_limitation'])) $specialFlags[] = ['🏃', 'محدودیت ورزش', '#b45309', '#fffbeb', '#fde68a'];
if (!empty($student['mother_deceased']))      $specialFlags[] = ['🖤', 'مادر فوت شده', '#374151', '#f3f4f6', '#d1d5db'];
if (!empty($student['father_deceased']))      $specialFlags[] = ['🖤', 'پدر فوت شده', '#374151', '#f3f4f6', '#d1d5db'];
if (!empty($student['father_guardian']))      $specialFlags[] = ['🛡️', 'سرپرست: پدر', '#1d4ed8', '#eff6ff', '#bfdbfe'];
if (!empty($student['mother_guardian']))      $specialFlags[] = ['🛡️', 'سرپرست: مادر', '#be185d', '#fdf2f8', '#fbcfe8'];
?>
<div class="student-hover-card">
    <div class="flex gap-3 items-center">
        <div class="avatar-lg"><?php if(!empty($student['photo_url'])): ?><img src="<?php echo clean($student['photo_url']); ?>"><?php else: ?><svg data-ui-icon="user" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="7" r="4"/><path d="M4 21v-2c0-8 16-8 16 0v2"/></svg><?php endif; ?></div>
        <div><b><?php echo clean($student['first_name'].' '.$student['last_name']); ?></b><br><span class="text-xs text-muted"><?php echo clean($student['class_name'].' / '.$student['grade_level']); ?></span></div>
    </div>
    <hr>
    <div style="display:flex;flex-direction:column;gap:6px;">
        <div style="display:flex;justify-content:space-between;align-items:center;"><span style="font-size:11px;color:var(--text-muted);font-weight:700;"><svg data-ui-icon="device" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg> موبایل دانش‌آموز</span><b style="font-size:17px;letter-spacing:.5px;direction:ltr;"><?php echo tr_num(($student['student_mobile'] ?? '') ?: ($student['phone'] ?: '---'),'fa'); ?></b></div>
        <div style="display:flex;justify-content:space-between;align-items:center;"><span style="font-size:11px;color:var(--text-muted);font-weight:700;"><svg data-ui-icon="phone" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m3 2 5 1 1 5-3 2c2 4 4 6 8 8l2-3 5 1 1 5C11 26-2 13 3 2Z"/></svg> پدر</span><b style="font-size:17px;letter-spacing:.5px;direction:ltr;"><?php echo tr_num($student['father_phone'] ?: '---','fa'); ?></b></div>
        <div style="display:flex;justify-content:space-between;align-items:center;"><span style="font-size:11px;color:var(--text-muted);font-weight:700;"><svg data-ui-icon="phone" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m3 2 5 1 1 5-3 2c2 4 4 6 8 8l2-3 5 1 1 5C11 26-2 13 3 2Z"/></svg> مادر</span><b style="font-size:17px;letter-spacing:.5px;direction:ltr;"><?php echo tr_num($student['mother_phone'] ?: '---','fa'); ?></b></div>
        <?php if (!empty($student['home_phone'])): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;"><span style="font-size:11px;color:var(--text-muted);font-weight:700;"><svg data-ui-icon="home" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m2 10 10-8 10 8M5 8v14h14V8M9 22v-8h6v8"/></svg> منزل</span><b style="font-size:17px;letter-spacing:.5px;direction:ltr;"><?php echo tr_num($student['home_phone'],'fa'); ?></b></div>
        <?php endif; ?>
    </div>
    <?php if (!empty($specialFlags)): ?>
    <hr>
    <div style="display:flex;flex-wrap:wrap;gap:5px;">
        <span style="font-size:10px;font-weight:800;color:var(--text-muted);width:100%;"><svg data-ui-icon="warning" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3 2 21h20Z"/><path d="M12 9v5m0 3v.1"/></svg> موارد خاص:</span>
        <?php foreach ($specialFlags as [$ic, $lbl, $fg, $bg, $bd]): ?>
        <span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:800;color:<?php echo $fg; ?>;background:<?php echo $bg; ?>;border:1px solid <?php echo $bd; ?>;border-radius:999px;padding:3px 9px;"><?php echo $ic; ?> <?php echo $lbl; ?></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php elseif ($type === 'reports'):
$reports = DB::fetchAll("SELECT r.*, (SELECT COUNT(*) FROM report_parent_reviews rv WHERE rv.report_id=r.id) AS review_count FROM reports r WHERE r.student_id=? ORDER BY r.id DESC", [$studentId]);
?>
<?php echo $profileCard; ?><h3 class="font-bold text-primary mb-3">کارنامه‌های <?php echo clean($student['first_name'].' '.$student['last_name']); ?></h3>
<div class="table-container modal-table"><table><thead><tr><th>سال</th><th>ماه/نوبت</th><th>معدل</th><th>بررسی والدین</th><th>وضعیت</th><th>مدیریت</th></tr></thead><tbody>
<?php foreach($reports as $r): ?><tr><td><?php echo clean($r['academic_year']); ?></td><td><?php echo clean($r['term'].' / '.$r['report_month']); ?></td><td><?php echo format_score($r['gpa']); ?></td><td><?php echo ((int)$r['review_count']>0)?'<span class="badge badge-success">بررسی شده</span>':'<span class="badge badge-danger">بررسی نشده</span>'; ?></td><td><?php $smLockReason=''; echo is_report_locked_for_student($r, $smLockReason) ? '<span title="'.clean($smLockReason).'">قفل</span>' : 'آزاد'; ?></td><td><a class="btn btn-primary text-xs" target="_blank" href="report-view.php?id=<?php echo $r['id']; ?>&staff=1">مشاهده</a> <a class="btn btn-secondary text-xs" target="_blank" href="reports.php?action=edit&id=<?php echo $r['id']; ?>">ویرایش</a> <a class="btn btn-outline text-xs" target="_blank" href="report-print.php?id=<?php echo $r['id']; ?>&staff=1">چاپ</a></td></tr><?php endforeach; if(!$reports): ?><tr><td colspan="5" class="text-center text-muted">کارنامه‌ای ثبت نشده است.</td></tr><?php endif; ?>
</tbody></table></div><div class="mt-3 flex flex-wrap gap-2">
  <a class="btn btn-success text-xs" target="_blank" href="reports.php?action=new&student_id=<?php echo $studentId; ?>">+ ثبت کارنامه جدید</a>
  <?php /* V4.213.0 — مقایسهٔ ماه‌به‌ماه همهٔ درس‌ها + روند معدل، در صفحه‌ای مستقل. */ ?>
  <a class="btn btn-primary text-xs" target="_blank" href="student-report-compare.php?student_id=<?php echo (int)$studentId; ?>">مقایسه نمرات ماهانه</a>
</div>
<?php elseif ($type === 'discipline'):
$records=DB::fetchAll("SELECT * FROM student_discipline_records WHERE student_id=? ORDER BY id DESC",[$studentId]);
$titlesForModal=function_exists('discipline_titles_ranked')?discipline_titles_ranked():DB::fetchAll('SELECT * FROM discipline_titles WHERE status=1 ORDER BY title');
$smReviewLabels=['pending'=>'در انتظار بررسی','reviewed'=>'بررسی‌شده','closed'=>'مختومه'];
$smRecordCount=is_array($records)?count($records):0;
?>
<?php /* V4.213.0 — مدیریت انضباط در دو پنل تمام‌عرض:
      راست (اول در DOM) = ثبت مورد تازه · چپ = فهرست موارد ثبت‌شده با یک ردیف برای هر مورد.
      در نمایشگر باریک/عمودی، دو پنل زیر هم می‌آیند و همان‌طور که خواسته شد پنل ثبت اول است. */ ?>
<div class="disc-modal">
    <?php echo $profileCard; ?>
    <div class="disc-grid">
        <section class="disc-panel disc-panel-entry" aria-labelledby="discEntryTitle">
            <div class="disc-panel-head">
                <h3 id="discEntryTitle">ثبت مورد انضباطی تازه</h3>
                <span class="disc-hint">برای این دانش‌آموز</span>
            </div>
            <form class="ajax-discipline-form disc-form" onsubmit="return saveDisciplineAjax(this)">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="type" value="discipline_save">
                <input type="hidden" name="student_id" value="<?php echo $studentId; ?>">
                <div class="disc-field">
                    <label for="discTitleId">عنوان ذخیره‌شده</label>
                    <select id="discTitleId" name="title_id" class="form-select">
                        <option value="">انتخاب از عنوان‌های آماده…</option>
                        <?php foreach($titlesForModal as $tt): ?><option value="<?php echo (int)$tt['id']; ?>"><?php echo clean($tt['title']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="disc-field">
                    <label for="discTitleText">یا عنوان جدید</label>
                    <input id="discTitleText" name="title_text" class="form-input" placeholder="مثلاً بی‌نظمی در کلاس" autocomplete="off">
                </div>
                <div class="disc-field">
                    <label for="discDate">تاریخ (شمسی)</label>
                    <input id="discDate" name="occurred_at_jalali" class="form-input" value="<?php echo jalali_now(); ?>" inputmode="numeric" autocomplete="off">
                </div>
                <div class="disc-field">
                    <label for="discReview">وضعیت بررسی</label>
                    <select id="discReview" name="review_status" class="form-select">
                        <option value="pending">در انتظار بررسی</option>
                        <option value="reviewed">بررسی‌شده</option>
                        <option value="closed">مختومه</option>
                    </select>
                </div>
                <div class="disc-field disc-span-all">
                    <label for="discNote">یادداشت داخلی</label>
                    <textarea id="discNote" name="internal_note" class="form-textarea" rows="2" placeholder="توضیح کوتاه برای پرونده (اختیاری)"></textarea>
                </div>
                <div class="disc-field disc-span-all">
                    <label for="discReviewNote">متن بررسی</label>
                    <textarea id="discReviewNote" name="review_note" class="form-textarea" rows="2" placeholder="نتیجهٔ بررسی (اختیاری)"></textarea>
                </div>
                <div class="disc-checks disc-span-all">
                    <label><input type="checkbox" name="is_justified"> مورد موجه است</label>
                    <label><input type="checkbox" name="notify_parents"> ارسال اعلان به والدین</label>
                </div>
                <div class="disc-actions disc-span-all">
                    <button class="btn btn-success" type="submit">ثبت مورد</button>
                </div>
            </form>
        </section>

        <section class="disc-panel disc-panel-list" aria-labelledby="discListTitle">
            <div class="disc-panel-head">
                <h3 id="discListTitle">موارد ثبت‌شده</h3>
                <span class="badge badge-info text-xs"><?php echo tr_num($smRecordCount, 'fa'); ?> مورد</span>
            </div>
            <div class="disc-table-wrap">
                <table class="disc-table">
                    <thead>
                        <tr>
                            <th>عنوان</th>
                            <th>تاریخ</th>
                            <th>موجه</th>
                            <th>بررسی</th>
                            <th>یادداشت داخلی</th>
                            <th>اعلان</th>
                            <th>اقدام</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($records as $r): $rid=(int)$r['id']; $rfid='disc-row-'.$rid; ?>
                        <tr>
                            <td class="disc-col-title">
                                <?php /* فرم هر ردیف با ویژگی form به ورودی‌هایش وصل می‌شود؛ این‌طور
                                      یک جدول واقعی داریم، نه یک فرم غول‌آسا داخل یک سلول. */ ?>
                                <form id="<?php echo $rfid; ?>" onsubmit="return updateDisciplineAjax(this)">
                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                    <input type="hidden" name="type" value="discipline_update">
                                    <input type="hidden" name="student_id" value="<?php echo $studentId; ?>">
                                    <input type="hidden" name="record_id" value="<?php echo $rid; ?>">
                                </form>
                                <input form="<?php echo $rfid; ?>" name="title_text" class="form-input text-xs" value="<?php echo clean($r['title_text']); ?>" aria-label="عنوان مورد">
                            </td>
                            <td><input form="<?php echo $rfid; ?>" name="occurred_at_jalali" class="form-input text-xs disc-date" value="<?php echo clean($r['occurred_at_jalali']); ?>" inputmode="numeric" aria-label="تاریخ"></td>
                            <td class="disc-col-check"><label class="disc-check" title="موجه"><input form="<?php echo $rfid; ?>" type="checkbox" name="is_justified" <?php echo !empty($r['is_justified'])?'checked':''; ?>> <span>موجه</span></label></td>
                            <td>
                                <select form="<?php echo $rfid; ?>" name="review_status" class="form-select text-xs" aria-label="وضعیت بررسی">
                                    <?php foreach($smReviewLabels as $rv=>$rl): ?><option value="<?php echo $rv; ?>" <?php echo ($r['review_status']??'')===$rv?'selected':''; ?>><?php echo clean($rl); ?></option><?php endforeach; ?>
                                </select>
                                <?php /* V4.213.0 — «متن بررسی» هم مثل بقیهٔ داده‌های همان مورد در همین ردیف
                                      ذخیره می‌شود؛ اگر این ورودی نبود، ذخیرهٔ ردیف آن را خالی می‌کرد. */ ?>
                                <textarea form="<?php echo $rfid; ?>" name="review_note" class="form-textarea text-xs disc-review-note" rows="2" placeholder="متن بررسی" aria-label="متن بررسی"><?php echo clean((string)($r['review_note'] ?? '')); ?></textarea>
                            </td>
                            <td><textarea form="<?php echo $rfid; ?>" name="internal_note" class="form-textarea text-xs disc-note" rows="2" aria-label="یادداشت داخلی"><?php echo clean((string)($r['internal_note'] ?? '')); ?></textarea></td>
                            <td class="disc-col-notify"><?php echo !empty($r['notify_parents']) ? '<span class="badge badge-success text-xs" title="اعلان به والدین ارسال شده">ارسال شد</span>' : '<span class="text-muted text-xs">—</span>'; ?></td>
                            <td class="disc-col-actions">
                                <button form="<?php echo $rfid; ?>" class="btn btn-success text-xs" type="submit">ذخیره</button>
                                <button type="button" class="btn btn-danger text-xs" onclick="deleteDisciplineAjax(<?php echo $studentId; ?>,<?php echo $rid; ?>)">حذف</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(!$records): ?>
                            <tr><td colspan="7" class="text-center text-muted">هنوز موردی برای این دانش‌آموز ثبت نشده است.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<style>
/* V4.213.0 — چیدمان دو پنلی مدیریت انضباط داخل مودال */
.disc-modal{width:100%;min-width:0}
#studentAjaxModal .modal-card,.modal-backdrop .modal-card{width:min(1180px,96vw);max-width:calc(100vw - 16px)}
.disc-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.3fr);gap:14px;align-items:start;margin-top:6px}
@media (max-width:980px){.disc-grid{grid-template-columns:minmax(0,1fr)}}
.disc-panel{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;min-width:0;box-shadow:0 1px 2px rgba(15,23,42,.04)}
.disc-panel-entry{border-top:3px solid #16a34a}
.disc-panel-list{border-top:3px solid #2563eb}
.disc-panel-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px}
.disc-panel-head h3{font-size:14px;font-weight:800;color:#0f172a;margin:0}
.disc-hint{font-size:11px;color:#64748b;font-weight:700}
.disc-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
@media (max-width:560px){.disc-form{grid-template-columns:minmax(0,1fr)}}
.disc-field{display:flex;flex-direction:column;gap:4px;min-width:0}
.disc-field label{font-size:11px;font-weight:800;color:#475569}
.disc-span-all{grid-column:1/-1}
.disc-checks{display:flex;flex-wrap:wrap;gap:14px}
.disc-checks label{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:#334155}
.disc-actions{display:flex;justify-content:flex-end}
.disc-table-wrap{overflow:auto;max-height:56vh;border:1px solid #eef2f7;border-radius:10px}
.disc-table{width:100%;border-collapse:collapse;font-size:12px}
.disc-table th{position:sticky;top:0;background:#f8fafc;color:#475569;font-size:11px;text-align:right;padding:8px;border-bottom:1px solid #e2e8f0;white-space:nowrap;z-index:1}
.disc-table td{padding:6px;border-bottom:1px solid #eef2f7;vertical-align:top}
.disc-table tr:last-child td{border-bottom:0}
.disc-col-title{min-width:150px}
.disc-date{min-width:110px;direction:ltr;text-align:left}
.disc-note{min-width:150px;resize:vertical}
.disc-review-note{min-width:130px;resize:vertical;margin-top:4px}
.disc-col-check,.disc-col-notify{white-space:nowrap;text-align:center}
.disc-col-actions{white-space:nowrap;text-align:center}
.disc-col-actions .btn{margin:1px}
.disc-check{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;color:#334155}
@media (max-width:980px){.disc-table-wrap{max-height:none}}
</style>
<?php endif;
$html = ob_get_clean();
/* v4.169.0: شمارشگرهای تازهٔ همان دانش‌آموز — لیست بدون رفرش صفحه،
   ستون انضباط و بررسی‌نشدهٔ ردیف را با این مقادیر به‌روز می‌کند. */
$rowStats = null;
try {
    $cnt = DB::fetch("SELECT COUNT(*) AS c, SUM(CASE WHEN COALESCE(review_status,'pending')='pending' THEN 1 ELSE 0 END) AS u FROM student_discipline_records WHERE student_id=?", [$studentId]);
    $rowStats = ['student_id'=>$studentId, 'discipline_count'=>(int)($cnt['c']??0), 'unreviewed_count'=>(int)($cnt['u']??0)];
} catch (Exception $e) {}
echo json_encode(['ok'=>true,'html'=>$html,'stats'=>$rowStats], JSON_UNESCAPED_UNICODE);
