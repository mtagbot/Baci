<?php
// File: exam-bank-import.php
/**
 * v4.104.0 — بارگذاری/ایمپورت سوالات آزمون به بانک اطلاعاتی سوالات.
 *
 * فایل ایمپورت (JSON با فرمت baci-bank-import@1) که از تحلیل PDF نمونه سوالات
 * ساخته شده را دریافت می‌کند؛ مدیر سال تحصیلی، ماه آزمون و دبیر طراح را انتخاب
 * می‌کند، پیش‌نمایش کامل سوالات (متن + گزینه‌ها + تصاویر) را می‌بیند و با تأیید،
 * همه سوالات در exam_question_bank ثبت می‌شوند.
 * تصاویر داخل سوال (data:base64) علاوه بر ماندن داخل HTML سوال (سازگار با
 * طراحی زنده و PDF ربات)، به‌صورت فایل در uploads/exams/bank-import/ هم
 * ذخیره می‌شوند.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/exams_helper.php';
require_once __DIR__ . '/includes/school_roles.php';
ensure_school_roles_schema();
require_permission('manage_reports');
ensure_exams_schema();

$monthsList = ['مهر','آبان','آذر','دی','بهمن','اسفند','فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','نوبت اول','نوبت دوم'];
$pendingDir = __DIR__ . '/uploads/exams/bank-import';
if (!is_dir($pendingDir)) @mkdir($pendingDir, 0775, true);

/* پاکسازی فایل‌های موقت قدیمی‌تر از ۲۴ ساعت */
foreach (glob($pendingDir . '/pending_*.json') ?: [] as $old) { if (filemtime($old) < time() - 86400) @unlink($old); }

function bank_import_parse($raw) {
    $j = json_decode($raw, true);
    if (!is_array($j)) return [null, 'فایل انتخابی JSON معتبر نیست.'];
    if (($j['format'] ?? '') !== 'baci-bank-import@1') return [null, 'فرمت فایل ایمپورت شناخته نشد (format باید baci-bank-import@1 باشد).'];
    $qs = $j['questions'] ?? [];
    if (!is_array($qs) || !count($qs)) return [null, 'هیچ سوالی در فایل ایمپورت پیدا نشد.'];
    $clean = [];
    foreach ($qs as $q) {
        $html = trim((string)($q['question_html'] ?? ''));
        if ($html === '') continue;
        $clean[] = [
            'no'            => trim((string)($q['no'] ?? '')),
            'question_type' => in_array($q['question_type'] ?? '', ['text','mcq','blank','tf'], true) ? $q['question_type'] : 'text',
            'score'         => trim((string)($q['score'] ?? '')),
            'height'        => max(10, min(120, (int)($q['height'] ?? 18))),
            'question_html' => $html,
        ];
    }
    if (!count($clean)) return [null, 'سوال معتبری در فایل ایمپورت وجود ندارد.'];
    return [['subject_name' => trim((string)($j['subject_name'] ?? '')),
             'grade_level'  => trim((string)($j['grade_level'] ?? '')),
             'source_pdf'   => trim((string)($j['source_pdf'] ?? '')),
             'questions'    => $clean], null];
}

$previewData = null; $previewToken = ''; $importedCount = 0; $importErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $act = $_POST['import_action'] ?? '';
    if ($act === 'preview') {
        $raw = '';
        if (!empty($_FILES['import_file']['tmp_name']) && is_uploaded_file($_FILES['import_file']['tmp_name'])) {
            $raw = file_get_contents($_FILES['import_file']['tmp_name']);
        } elseif (($_POST['server_file'] ?? '') !== '') {
            /* فایل‌های ایمپورت موجود در پوشه exams روی هاست */
            $sf = basename((string)$_POST['server_file']);
            foreach ([__DIR__ . '/exams/' . $sf, dirname(__DIR__) . '/exams/' . $sf] as $cand) {
                if (is_file($cand) && strtolower(pathinfo($cand, PATHINFO_EXTENSION)) === 'json') { $raw = file_get_contents($cand); break; }
            }
        }
        if ($raw === '' || $raw === false) { $importErr = 'فایل ایمپورت انتخاب نشده است.'; }
        else {
            list($parsed, $err) = bank_import_parse($raw);
            if ($err) $importErr = $err;
            else {
                $previewToken = bin2hex(random_bytes(10));
                file_put_contents($pendingDir . '/pending_' . $previewToken . '.json', json_encode($parsed, JSON_UNESCAPED_UNICODE));
                $previewData = $parsed;
            }
        }
    } elseif ($act === 'import') {
        $tok = preg_replace('/[^a-f0-9]/', '', (string)($_POST['pending_token'] ?? ''));
        $pf = $pendingDir . '/pending_' . $tok . '.json';
        if ($tok === '' || !is_file($pf)) { $importErr = 'نشست ایمپورت منقضی شده — دوباره فایل را انتخاب کنید.'; }
        else {
            $parsed = json_decode(file_get_contents($pf), true);
            $year = unify_academic_year(trim($_POST['academic_year'] ?? ''));
            $month = trim($_POST['exam_month'] ?? '');
            $subjectSel = trim($_POST['subject_name'] ?? '');
            if ($subjectSel === '__manual') $subjectSel = trim($_POST['subject_name_manual'] ?? '');
            $subject = $subjectSel !== '' ? $subjectSel : ($parsed['subject_name'] ?: 'نامشخص');
            $teacherRaw = (string)($_POST['teacher_id'] ?? '');
            $teacherId = ($teacherRaw !== '' && $teacherRaw !== '__manual') ? ((int)$teacherRaw ?: null) : null;
            $designer = trim($_POST['designer_name'] ?? '');
            if ($teacherId && $designer === '') {
                $t = DB::fetch('SELECT full_name, first_name, last_name FROM teachers WHERE id=?', [$teacherId]);
                if ($t) $designer = trim((string)($t['full_name'] ?? '')) ?: trim(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? ''));
            }
            /* v4.105.0: سوالات ویرایش‌شده در مرحله پیش‌نمایش (متن/بارم/نوع/تصاویر برش‌خورده/حذف) */
            $editedRaw = (string)($_POST['edited_questions'] ?? '');
            if ($editedRaw !== '') {
                $edited = json_decode($editedRaw, true);
                if (is_array($edited) && count($edited)) {
                    $cleanEd = [];
                    foreach ($edited as $eq) {
                        $h = trim((string)($eq['question_html'] ?? ''));
                        if ($h === '' || !empty($eq['deleted'])) continue;
                        $cleanEd[] = [
                            'no'            => trim((string)($eq['no'] ?? '')),
                            'question_type' => in_array($eq['question_type'] ?? '', ['text','mcq','blank','tf'], true) ? $eq['question_type'] : 'text',
                            'score'         => trim((string)($eq['score'] ?? '')),
                            'height'        => max(10, min(120, (int)($eq['height'] ?? 18))),
                            'question_html' => $h,
                        ];
                    }
                    if (count($cleanEd)) $parsed['questions'] = $cleanEd;
                }
            }
            if ($year === '' || $month === '') $importErr = 'سال تحصیلی و ماه آزمون را انتخاب کنید.';
            else {
                $batch = date('Ymd_His') . '_' . substr($tok, 0, 6);
                $imgDir = $pendingDir . '/' . $batch;
                $qNo = 0;
                foreach ($parsed['questions'] as $q) {
                    $qNo++;
                    $html = $q['question_html'];
                    /* ذخیره نسخه فایل تصاویرِ داخل سوال در فولدر بانک (data URI داخل HTML می‌ماند
                       تا با طراحی زنده و PDF ربات ۱۰۰٪ سازگار باشد) */
                    $imgIdx = 0;
                    preg_match_all('/src="data:image\/(webp|png|jpe?g|gif);base64,([^"]+)"/i', $html, $mm, PREG_SET_ORDER);
                    $imgFiles = [];
                    foreach ($mm as $im) {
                        $bin = base64_decode($im[2]);
                        if ($bin === false || $bin === '') continue;
                        if (!is_dir($imgDir)) @mkdir($imgDir, 0775, true);
                        $ext = strtolower($im[1]) === 'jpg' ? 'jpeg' : strtolower($im[1]);
                        $imgIdx++;
                        $fn = 'q' . str_pad((string)$qNo, 3, '0', STR_PAD_LEFT) . '_img' . $imgIdx . '.' . $ext;
                        if (@file_put_contents($imgDir . '/' . $fn, $bin)) $imgFiles[] = 'uploads/exams/bank-import/' . $batch . '/' . $fn;
                    }
                    $meta = ['no' => $q['no'], 'height' => $q['height'], 'import_batch' => $batch,
                             'source_pdf' => $parsed['source_pdf'], 'grade_level' => $parsed['grade_level'],
                             'image_files' => $imgFiles];
                    DB::execute('INSERT INTO exam_question_bank (source_exam_id, academic_year, exam_month, subject_name, teacher_id, designer_name, question_type, question_html, score, meta_json, created_at_jalali) VALUES (NULL,?,?,?,?,?,?,?,?,?,?)',
                        [$year, $month, $subject, $teacherId, $designer, $q['question_type'], $html, $q['score'], json_encode($meta, JSON_UNESCAPED_UNICODE), jalali_now()]);
                    $importedCount++;
                }
                @unlink($pf);
                set_flash_message('success', 'ایمپورت انجام شد: ' . tr_num((string)$importedCount, 'fa') . ' سوال درس «' . $subject . '» به بانک سوالات اضافه شد (' . $year . ' / ' . $month . ').');
                redirect('exam-question-bank.php');
            }
            if ($importErr) { $previewData = $parsed; $previewToken = $tok; }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
$qbYear = resolve_academic_year_request($_GET['year'] ?? get_current_academic_year());
$yearsList = function_exists('get_master_academic_years_list') ? get_master_academic_years_list() : [];
if (!$yearsList) { try { $yearsList = array_values(array_unique(array_filter(array_map(function($r){return $r['year_name'] ?? '';}, DB::fetchAll('SELECT year_name FROM academic_years ORDER BY is_default DESC, year_name DESC'))))); } catch (Exception $e) { $yearsList = [get_current_academic_year()]; } }
$teachers = DB::fetchAll("SELECT id, COALESCE(NULLIF(full_name,''), TRIM(COALESCE(first_name,'')||' '||COALESCE(last_name,''))) AS name FROM teachers WHERE COALESCE(status,'active')='active' ORDER BY name");
/* v4.106.0: فهرست دروس تعریف‌شده (برنامه هفتگی + امتحانات + بانک) برای فیلد انتخابی درس */
$subjectsList = [];
try {
    foreach (DB::fetchAll("SELECT DISTINCT subject_name FROM class_schedules WHERE TRIM(COALESCE(subject_name,''))<>'' UNION SELECT DISTINCT subject_name FROM exam_schedules WHERE TRIM(COALESCE(subject_name,''))<>'' UNION SELECT DISTINCT subject_name FROM exam_question_bank WHERE TRIM(COALESCE(subject_name,''))<>'' ORDER BY 1") as $sr) {
        $sv = trim((string)$sr['subject_name']);
        if ($sv !== '' && !in_array($sv, $subjectsList, true)) $subjectsList[] = $sv;
    }
} catch (Exception $e) {}
/* فایل‌های ایمپورت موجود در پوشه exams (اگر روی هاست آپلود شده باشد) */
$serverFiles = [];
foreach ([__DIR__ . '/exams', dirname(__DIR__) . '/exams'] as $sd) {
    foreach (glob($sd . '/*.json') ?: [] as $sf) $serverFiles[basename($sf)] = basename($sf);
}
?>
<style>
.bank-question-card{position:relative;overflow:auto;max-height:170px;border:1px solid #e2e8f0;border-radius:8px;padding:8px;background:#fff}
.bank-question-card img{position:static!important;display:inline-block!important;max-width:100%!important;height:auto!important;left:auto!important;top:auto!important;transform:none!important}
.imp-badge{display:inline-block;background:#e0e9ff;color:#1d4ed8;border-radius:6px;padding:2px 8px;font-size:11px;font-weight:700}
.imp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:10px}
</style>
<div class="space-y-4">
 <?php if (function_exists('render_teacher_nav')) render_teacher_nav(); ?>
 <div class="page-hero flex justify-between items-center flex-wrap gap-3">
    <div><h2 class="text-2xl font-bold">📥 ایمپورت سوالات آزمون به بانک</h2><p class="text-sm text-muted">بارگذاری فایل ایمپورت تحلیل‌شده از PDF نمونه سوالات و ثبت سوالات (متن، گزینه‌ها و تصاویر) در بانک اطلاعاتی</p></div>
    <div class="flex gap-2 flex-wrap">
        <a class="btn btn-primary" href="exam-question-bank.php">بازگشت به بانک سوالات</a>
        <a class="btn btn-outline" href="exams.php?year=<?php echo urlencode($qbYear); ?>">بخش امتحانات</a>
    </div>
 </div>

 <?php if ($importErr): ?><div class="card" style="border-color:#fca5a5;background:#fef2f2;color:#b91c1c;font-weight:700"><?php echo clean($importErr); ?></div><?php endif; ?>

 <?php if (!$previewData): ?>
 <div class="card">
   <h3 class="font-bold mb-3">۱) انتخاب فایل ایمپورت</h3>
   <form method="POST" enctype="multipart/form-data" class="space-y-3">
     <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
     <input type="hidden" name="import_action" value="preview">
     <div class="grid grid-cols-2 gap-4">
       <div>
         <label class="text-xs font-bold">فایل ایمپورت (JSON ساخته‌شده از تحلیل PDF)</label>
         <input type="file" name="import_file" accept=".json,application/json" class="form-input">
       </div>
       <?php if ($serverFiles): ?>
       <div>
         <label class="text-xs font-bold">یا انتخاب از فایل‌های موجود در پوشه exams روی هاست</label>
         <select name="server_file" class="form-select"><option value="">— انتخاب نشده —</option><?php foreach ($serverFiles as $sf): ?><option value="<?php echo clean($sf); ?>"><?php echo clean($sf); ?></option><?php endforeach; ?></select>
       </div>
       <?php endif; ?>
     </div>
     <button class="btn btn-primary">نمایش پیش‌نمایش سوالات</button>
     <p class="text-xs text-muted">فایل ایمپورت از تحلیل نظیر‌به‌نظیر PDF نمونه سوالات ساخته می‌شود: هر سوال جداگانه با متن، گزینه‌ها، جای‌خالی، صحیح/غلط و تصاویر برش‌خورده (شکل‌ها و عبارات ریاضی غیرقابل تایپ به‌صورت تصویر). پاسخ‌نامه انتهای PDF وارد بانک نمی‌شود.</p>
   </form>
 </div>
 <?php else: ?>
 <div class="card">
   <h3 class="font-bold mb-3">۲) مشخصات ایمپورت و تأیید نهایی</h3>
   <form method="POST" class="space-y-3">
     <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
     <input type="hidden" name="import_action" value="import">
     <input type="hidden" name="pending_token" value="<?php echo clean($previewToken); ?>">
     <div class="grid grid-cols-4 gap-3">
       <div><label class="text-xs font-bold">سال تحصیلی</label>
         <select name="academic_year" class="form-select" required>
           <?php foreach ($yearsList as $y): $yv = is_array($y) ? ($y['year_name'] ?? '') : $y; if ($yv==='') continue; ?>
           <option value="<?php echo clean($yv); ?>" <?php echo $yv===$qbYear?'selected':''; ?>><?php echo clean(tr_num($yv,'fa')); ?></option>
           <?php endforeach; ?>
         </select></div>
       <div><label class="text-xs font-bold">ماه آزمون</label>
         <select name="exam_month" class="form-select" required>
           <?php foreach ($monthsList as $m): ?><option value="<?php echo $m; ?>"><?php echo $m; ?></option><?php endforeach; ?>
         </select></div>
       <div><label class="text-xs font-bold">دبیر طراح</label>
         <select name="teacher_id" id="teacherSelect" class="form-select" onchange="document.getElementById('designerManualWrap').style.display=this.value==='__manual'?'block':'none'">
           <option value="">— انتخاب دبیر —</option>
           <?php foreach ($teachers as $t): ?><option value="<?php echo $t['id']; ?>"><?php echo clean($t['name']); ?></option><?php endforeach; ?>
           <option value="__manual">✏ نام طراح دیگر (ورود دستی)…</option>
         </select>
         <div id="designerManualWrap" style="display:none;margin-top:6px">
           <input name="designer_name" class="form-input" placeholder="مثلاً خانم محمدی">
         </div></div>
     </div>
     <div class="grid grid-cols-4 gap-3">
       <div><label class="text-xs font-bold">درس</label>
         <select name="subject_name" id="subjectSelect" class="form-select" onchange="document.getElementById('subjectManualWrap').style.display=this.value==='__manual'?'block':'none'">
           <?php $pdSub = trim((string)$previewData['subject_name']); $found = $pdSub !== '' && in_array($pdSub, $subjectsList, true); ?>
           <?php if ($pdSub !== '' && !$found): ?><option value="<?php echo clean($pdSub); ?>" selected><?php echo clean($pdSub); ?> (از فایل)</option><?php endif; ?>
           <?php foreach ($subjectsList as $sb): ?>
           <option value="<?php echo clean($sb); ?>" <?php echo ($found && $sb === $pdSub) ? 'selected' : ''; ?>><?php echo clean($sb); ?></option>
           <?php endforeach; ?>
           <option value="__manual">✏ درس دیگر (ورود دستی)…</option>
         </select>
         <div id="subjectManualWrap" style="display:none;margin-top:6px">
           <input name="subject_name_manual" class="form-input" placeholder="نام درس را تایپ کنید">
         </div></div>
       <div class="col-span-3 flex items-end gap-2 flex-wrap">
         <span class="imp-badge">تعداد سوالات: <?php echo tr_num((string)count($previewData['questions']),'fa'); ?></span>
         <?php if ($previewData['grade_level']): ?><span class="imp-badge">پایه: <?php echo clean($previewData['grade_level']); ?></span><?php endif; ?>
         <?php if ($previewData['source_pdf']): ?><span class="imp-badge">منبع: <?php echo clean($previewData['source_pdf']); ?></span><?php endif; ?>
       </div>
     </div>
     <div class="flex gap-2">
       <button class="btn btn-success" onclick="return impCollectEdits(this.form)">تأیید و ثبت همه سوالات در بانک</button>
       <a class="btn btn-outline" href="exam-bank-import.php">انصراف / فایل دیگر</a>
     </div>
     <input type="hidden" name="edited_questions" id="editedQuestions">
   </form>
 </div>
 <div class="card">
   <div class="flex justify-between items-center mb-3 flex-wrap gap-2">
     <h3 class="font-bold">پیش‌نمایش و ویرایش سوالات فایل ایمپورت</h3>
     <button type="button" class="btn btn-outline text-xs" onclick="impResetAll()" title="بازگرداندن همه سوالات به حالت اولیه فایل ایمپورت">↺ ریست همه تغییرات</button>
   </div>
   <p class="text-xs text-muted mb-3">متن هر سوال مستقیماً قابل ویرایش است (روی متن کلیک کنید و تایپ کنید). با دکمه «برش تصویر» می‌توانید تصاویر سوال را دقیق‌تر برش بزنید یا حذف کنید. تغییرات هنگام «تأیید و ثبت» اعمال می‌شوند.</p>
   <div class="imp-grid" id="impGrid">
     <?php foreach ($previewData['questions'] as $iqIdx => $iq): ?>
     <div class="imp-q" data-idx="<?php echo $iqIdx; ?>">
       <div class="flex justify-between items-center mb-1 imp-head">
         <span class="flex items-center gap-1">
           <b class="text-sm">سوال</b>
           <input class="form-input imp-no" style="width:52px;padding:2px 6px;font-size:12px" value="<?php echo clean($iq['no']); ?>" title="شماره سوال">
         </span>
         <span class="flex items-center gap-1">
           <select class="form-select imp-type" style="width:auto;padding:2px 6px;font-size:11px">
             <?php foreach (['text'=>'تشریحی','mcq'=>'چندگزینه‌ای','blank'=>'جای خالی','tf'=>'صحیح/غلط'] as $tv=>$tl): ?>
             <option value="<?php echo $tv; ?>" <?php echo $iq['question_type']===$tv?'selected':''; ?>><?php echo $tl; ?></option>
             <?php endforeach; ?>
           </select>
           <input class="form-input imp-score" style="width:56px;padding:2px 6px;font-size:12px" value="<?php echo clean($iq['score']); ?>" placeholder="بارم" title="بارم">
           <button type="button" class="btn btn-danger text-xs imp-del" onclick="impToggleDelete(this)" title="حذف این سوال از ایمپورت">حذف</button>
         </span>
       </div>
       <div class="bank-question-card imp-html" contenteditable="true" data-height="<?php echo (int)$iq['height']; ?>" onfocus="impSetActive(this)" onclick="impSetActive(this)"><?php echo $iq['question_html']; ?></div>
       <div class="imp-tools">
         <button type="button" class="imp-tb" title="پررنگ" onclick="impCmd(this,'bold')"><b>ب</b></button>
         <button type="button" class="imp-tb" title="زیرخط" onclick="impCmd(this,'underline')"><u>ز</u></button>
         <button type="button" class="imp-tb" title="کج" onclick="impCmd(this,'italic')"><i>ک</i></button>
         <button type="button" class="imp-tb" title="بزرگ‌تر" onclick="impFontSize(this,1)">A+</button>
         <button type="button" class="imp-tb" title="کوچک‌تر" onclick="impFontSize(this,-1)">A−</button>
         <span class="imp-sep"></span>
         <button type="button" class="imp-tb" title="راست‌چین" onclick="impAlign(this,'right')">⇥</button>
         <button type="button" class="imp-tb" title="وسط‌چین" onclick="impAlign(this,'center')">⇔</button>
         <button type="button" class="imp-tb" title="چپ‌چین" onclick="impAlign(this,'left')">⇤</button>
         <button type="button" class="imp-tb" title="راست به چپ (متن انتخابی)" onclick="impDirection(this,'rtl')">ر→چ</button>
         <button type="button" class="imp-tb" title="چپ به راست (متن انتخابی)" onclick="impDirection(this,'ltr')">L→R</button>
         <span class="imp-sep"></span>
         <button type="button" class="imp-tb" title="جای خالی نقطه‌چین" onclick="impInsertHTML(this,'<span class=&quot;blank-line&quot;></span>&nbsp;')">جای‌خالی</button>
         <button type="button" class="imp-tb" title="مربع صحیح/غلط" onclick="impInsertHTML(this,'<span class=&quot;tf-square&quot;></span>&nbsp;')">□</button>
         <button type="button" class="imp-tb" title="درج کسر ایرانی" onclick="impInsertFrac(this)">کسر</button>
         <button type="button" class="imp-tb" title="معادله ریاضی (مثل Word)" onclick="impOpenEquation(this)">π معادله</button>
         <button type="button" class="imp-tb" title="نمادها (مثل Word)" onclick="impOpenSymbols(this)">Ω نماد</button>
         <span class="imp-sep"></span>
         <label class="imp-tb" title="بارگذاری تصویر در سوال" style="cursor:pointer">🖼 تصویر<input type="file" accept="image/*" style="display:none" onchange="impUploadImage(this)"></label>
         <button type="button" class="imp-tb" onclick="impOpenCropper(this)">✂ برش تصویر</button>
         <button type="button" class="imp-tb" title="واگرد" onclick="impCmd(this,'undo')">↶</button>
         <button type="button" class="imp-tb" title="ازنو" onclick="impCmd(this,'redo')">↷</button>
       </div>

     </div>
     <?php endforeach; ?>
   </div>
 </div>

 <!-- مودال نمادها (مثل Word) -->
 <div id="impSymModal" class="imp-crop-modal">
   <div class="imp-crop-card" style="width:min(640px,94vw)">
     <div class="flex justify-between items-center mb-2">
       <b>درج نماد (Symbols)</b>
       <button type="button" onclick="impCloseSym()" style="background:#ef4444;color:#fff;border:0;border-radius:8px;padding:4px 12px">×</button>
     </div>
     <div class="imp-sym-tabs" id="impSymTabs"></div>
     <div class="imp-sym-grid" id="impSymGrid"></div>
   </div>
 </div>

 <!-- مودال معادله (مثل Word) -->
 <div id="impEqModal" class="imp-crop-modal">
   <div class="imp-crop-card" style="width:min(700px,94vw)">
     <div class="flex justify-between items-center mb-2">
       <b>معادله ریاضی (Equation)</b>
       <button type="button" onclick="impCloseEq()" style="background:#ef4444;color:#fff;border:0;border-radius:8px;padding:4px 12px">×</button>
     </div>
     <p class="text-xs text-muted mb-2">یک قالب را انتخاب کنید — جاهای نقطه‌چین (□) بعد از درج در خود سوال قابل تایپ هستند (روی آن کلیک کنید و بنویسید).</p>
     <div class="imp-eq-grid" id="impEqGrid"></div>
     <div class="mt-2">
       <label class="text-xs font-bold">یا عبارت دلخواه (چپ به راست):</label>
       <div class="flex gap-2 mt-1">
         <input id="impEqCustom" class="form-input" style="direction:ltr;text-align:left" placeholder="مثلاً: (−۳) × (+۴) =">
         <button type="button" class="btn btn-primary" onclick="impInsertEqCustom()">درج</button>
       </div>
     </div>
   </div>
 </div>

 <!-- ویرایشگر برش تصویر سوال -->
 <div id="impCropModal" class="imp-crop-modal">
   <div class="imp-crop-card">
     <div class="flex justify-between items-center mb-2">
       <b>برش و تمیزکاری تصویر سوال</b>
       <button type="button" onclick="impCloseCropper()" style="background:#ef4444;color:#fff;border:0;border-radius:8px;padding:4px 12px">×</button>
     </div>
     <div class="flex items-center gap-2 mb-2" id="impImgPicker" style="flex-wrap:wrap"></div>
     <div class="imp-crop-stage"><canvas id="impCropCanvas"></canvas></div>
     <div class="imp-crop-controls">
       <label>برش از راست <input type="range" id="impCropR" min="0" max="45" value="0" oninput="impRenderCrop()"></label>
       <label>برش از چپ <input type="range" id="impCropL" min="0" max="45" value="0" oninput="impRenderCrop()"></label>
       <label>برش از بالا <input type="range" id="impCropT" min="0" max="45" value="0" oninput="impRenderCrop()"></label>
       <label>برش از پایین <input type="range" id="impCropB" min="0" max="45" value="0" oninput="impRenderCrop()"></label>
       <label>روشن‌سازی پس‌زمینه <input type="range" id="impCropWhite" min="120" max="255" value="200" oninput="impRenderCrop()"></label>
       <label>عرض نمایش (px) <input type="range" id="impCropW" min="80" max="460" value="300" oninput="impRenderCrop()"></label>
     </div>
     <div class="flex gap-2 justify-end mt-2">
       <button type="button" class="btn btn-danger" onclick="impRemoveImage()">حذف این تصویر از سوال</button>
       <button type="button" class="btn btn-success" onclick="impApplyCrop()">اعمال برش روی سوال</button>
       <button type="button" class="btn btn-outline" onclick="impCloseCropper()">بستن</button>
     </div>
   </div>
 </div>

<style>
.imp-q{border:1px solid #dbe3ef;border-radius:10px;padding:8px;background:#fbfcff}
.imp-q.imp-deleted{opacity:.38;filter:grayscale(1)}
.imp-q.imp-deleted .imp-del{background:#16a34a!important}
.imp-html{min-height:44px;max-height:260px;outline:none}
.imp-html:focus{border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.18)}
.imp-html img{cursor:pointer}
.imp-html img.imp-img-active{outline:3px solid #2563eb;outline-offset:2px}
.imp-tools{margin-top:6px;display:flex;gap:4px;flex-wrap:wrap;align-items:center}
.imp-tb{background:#fff;border:1px solid #cbd5e1;border-radius:7px;padding:3px 8px;font-size:11.5px;cursor:pointer;display:inline-flex;align-items:center;gap:3px;color:#0f172a;transition:all .12s}
.imp-tb:hover{border-color:#2563eb;background:#eff6ff;color:#1d4ed8}
.imp-symbols{display:flex;flex-wrap:wrap;gap:3px;margin-top:5px;background:#f1f5f9;border:1px solid #dbe3ef;border-radius:8px;padding:6px}
.imp-sym{background:#fff;border:1px solid #cbd5e1;border-radius:6px;min-width:30px;padding:4px 6px;font-size:15px;cursor:pointer}
.imp-sym:hover{border-color:#2563eb;background:#eff6ff}
.imp-sep{width:1px;height:18px;background:#cbd5e1;margin:0 3px}
.imp-sym-tabs{display:flex;gap:4px;flex-wrap:wrap;margin-bottom:8px}
.imp-sym-tab{background:#f1f5f9;border:1px solid #cbd5e1;border-radius:8px;padding:4px 10px;font-size:12px;cursor:pointer}
.imp-sym-tab.active{background:#2563eb;color:#fff;border-color:#2563eb}
.imp-sym-grid{display:flex;flex-wrap:wrap;gap:4px;max-height:44vh;overflow:auto;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px}
.imp-eq-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px;max-height:46vh;overflow:auto}
.imp-eq-card{background:#fff;border:1px solid #cbd5e1;border-radius:10px;padding:10px 6px;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:6px;min-height:64px;justify-content:center}
.imp-eq-card:hover{border-color:#2563eb;background:#eff6ff}
.imp-eq-card small{color:#64748b;font-size:10.5px}
.imp-html .eq-slot{display:inline-block;min-width:22px;border:1px dashed #94a3b8;border-radius:4px;padding:0 4px;text-align:center}
.imp-html .eq-slot:focus{outline:2px solid #2563eb}
.imp-crop-modal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:1000;align-items:center;justify-content:center}
.imp-crop-modal.open{display:flex}
.imp-crop-card{background:#fff;border-radius:14px;padding:16px;width:min(720px,94vw);max-height:92vh;overflow:auto;box-shadow:0 24px 70px rgba(0,0,0,.35)}
.imp-crop-stage{background:repeating-conic-gradient(#eee 0 25%,#fff 0 50%) 0 0/16px 16px;border:1px solid #cbd5e1;border-radius:8px;display:flex;align-items:center;justify-content:center;min-height:180px;max-height:46vh;overflow:auto}
.imp-crop-controls{display:grid;grid-template-columns:1fr 1fr;gap:6px 14px;margin-top:10px;font-size:12px}
.imp-crop-controls label{display:flex;align-items:center;gap:8px;justify-content:space-between}
.imp-crop-controls input[type=range]{flex:1}
.imp-pick-thumb{width:54px;height:40px;object-fit:contain;border:2px solid #cbd5e1;border-radius:6px;cursor:pointer;background:#fff}
.imp-pick-thumb.active{border-color:#2563eb}
.pfrac{direction:ltr}
</style>
<script>
/* ============ v4.108.0: ریست پیش‌نمایش به حالت اولیه ============ */
let impInitial=[];
window.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('.imp-q').forEach(q=>{
    impInitial.push({
      html:q.querySelector('.imp-html').innerHTML,
      no:q.querySelector('.imp-no').value,
      type:q.querySelector('.imp-type').value,
      score:q.querySelector('.imp-score').value
    });
  });
});
function impResetAll(){
  if(!confirm('همه تغییرات (متن، شماره، نوع، بارم، تصاویر، حذف‌ها) به حالت اولیه فایل ایمپورت برگردد؟'))return;
  document.querySelectorAll('.imp-q').forEach((q,i)=>{
    const init=impInitial[i]; if(!init)return;
    q.querySelector('.imp-html').innerHTML=init.html;
    q.querySelector('.imp-no').value=init.no;
    q.querySelector('.imp-type').value=init.type;
    q.querySelector('.imp-score').value=init.score;
    q.classList.remove('imp-deleted');
    const db=q.querySelector('.imp-del'); if(db)db.textContent='حذف';
  });
}
/* ============ v4.105.0: ویرایش سوالات در پیش‌نمایش ایمپورت ============ */
function impToggleDelete(btn){
  const q=btn.closest('.imp-q'); q.classList.toggle('imp-deleted');
  btn.textContent=q.classList.contains('imp-deleted')?'بازگردانی':'حذف';
}
function impCollectEdits(form){
  const out=[];
  document.querySelectorAll('.imp-q').forEach(q=>{
    /* v4.108.0: کادر خط‌چین معادله فقط راهنمای ویرایش است — در HTML نهایی حذف می‌شود */
    const tmp=document.createElement('div');
    tmp.innerHTML=q.querySelector('.imp-html').innerHTML;
    tmp.querySelectorAll('.eq-slot').forEach(x=>{
      x.classList.remove('eq-slot');
      if(!x.getAttribute('class')) x.removeAttribute('class');
      const txt=x.textContent.replace(/\u200b/g,'').trim();
      if(txt==='') x.remove();                        /* جای خالی پرنشده حذف */
      else if(x.attributes.length===0) x.replaceWith(...x.childNodes);
    });
    out.push({
      no:q.querySelector('.imp-no').value.trim(),
      question_type:q.querySelector('.imp-type').value,
      score:q.querySelector('.imp-score').value.trim(),
      height:parseInt(q.querySelector('.imp-html').dataset.height||'18',10),
      question_html:tmp.innerHTML,
      deleted:q.classList.contains('imp-deleted')
    });
  });
  if(!out.filter(x=>!x.deleted).length){alert('همه سوالات حذف شده‌اند — حداقل یک سوال لازم است.');return false;}
  document.getElementById('editedQuestions').value=JSON.stringify(out);
  return true;
}
/* ---------- ویرایشگر برش تصویر ---------- */
let impQ=null, impImgs=[], impImgIdx=0, impSrcImage=null;
function impOpenCropper(btn){
  impQ=btn.closest('.imp-q');
  impImgs=[...impQ.querySelectorAll('.imp-html img')];
  if(!impImgs.length){alert('این سوال تصویری ندارد.');return;}
  const pick=document.getElementById('impImgPicker'); pick.innerHTML='';
  impImgs.forEach((im,i)=>{
    const th=document.createElement('img'); th.src=im.src; th.className='imp-pick-thumb'+(i===0?' active':'');
    th.onclick=()=>impSelectImage(i);
    pick.appendChild(th);
  });
  document.getElementById('impCropModal').classList.add('open');
  impSelectImage(0);
}
function impSelectImage(i){
  impImgIdx=i;
  document.querySelectorAll('.imp-pick-thumb').forEach((t,k)=>t.classList.toggle('active',k===i));
  ['impCropR','impCropL','impCropT','impCropB'].forEach(id=>document.getElementById(id).value=0);
  document.getElementById('impCropWhite').value=200;
  const im=new Image();
  im.onload=()=>{impSrcImage=im;
    const w=parseInt((impImgs[i].style.width||'300').replace('px',''),10)||300;
    document.getElementById('impCropW').value=Math.max(80,Math.min(460,w));
    impRenderCrop();};
  im.src=impImgs[i].src;
}
function impRenderCrop(){
  if(!impSrcImage)return;
  const c=document.getElementById('impCropCanvas'), ctx=c.getContext('2d');
  const cr={r:+impCropR.value/100,l:+impCropL.value/100,t:+impCropT.value/100,b:+impCropB.value/100};
  const sx=Math.round(impSrcImage.width*cr.l), sy=Math.round(impSrcImage.height*cr.t);
  const sw=Math.max(8,Math.round(impSrcImage.width*(1-cr.l-cr.r)));
  const sh=Math.max(8,Math.round(impSrcImage.height*(1-cr.t-cr.b)));
  c.width=sw; c.height=sh;
  ctx.drawImage(impSrcImage,sx,sy,sw,sh,0,0,sw,sh);
  /* روشن‌سازی پس‌زمینه (حذف واترمارک/خاکستری) */
  const thr=+document.getElementById('impCropWhite').value;
  if(thr<255){
    const d=ctx.getImageData(0,0,sw,sh);
    for(let i=0;i<d.data.length;i+=4){
      const lum=(d.data[i]+d.data[i+1]+d.data[i+2])/3;
      if(lum>thr){d.data[i]=d.data[i+1]=d.data[i+2]=255;}
    }
    ctx.putImageData(d,0,0);
  }
  const dispW=+document.getElementById('impCropW').value;
  c.style.width=Math.min(dispW*1.4,660)+'px'; c.style.height='auto';
}
function impApplyCrop(){
  const c=document.getElementById('impCropCanvas');
  const img=impImgs[impImgIdx];
  img.src=c.toDataURL('image/png');
  img.style.width=(+document.getElementById('impCropW').value)+'px';
  impCloseCropper();
}
function impRemoveImage(){
  if(!confirm('این تصویر از سوال حذف شود؟'))return;
  impImgs[impImgIdx].remove();
  impCloseCropper();
}
function impCloseCropper(){document.getElementById('impCropModal').classList.remove('open'); impQ=null; impSrcImage=null;}
document.addEventListener('keydown',e=>{if(e.key==='Escape'){impCloseCropper();impCloseSym();impCloseEq();}});

/* ============ v4.106.0: ویرایشگر حرفه‌ای سوالات ============ */
let impActiveHtml=null, impSavedRange=null;
function impSetActive(el){impActiveHtml=el;}
function impSaveSel(){
  const sel=window.getSelection();
  if(sel.rangeCount && impActiveHtml && impActiveHtml.contains(sel.anchorNode)) impSavedRange=sel.getRangeAt(0).cloneRange();
}
document.addEventListener('selectionchange',impSaveSel);
function impTargetHtml(btn){
  const q=btn.closest('.imp-q');
  return q?q.querySelector('.imp-html'):impActiveHtml;
}
function impFocusTarget(el){
  el.focus();
  if(impSavedRange && el.contains(impSavedRange.commonAncestorContainer)){
    const sel=window.getSelection(); sel.removeAllRanges(); sel.addRange(impSavedRange);
  }
}
function impCmd(btn,cmd){
  const el=impTargetHtml(btn); if(!el)return;
  impFocusTarget(el);
  document.execCommand(cmd,false,null);
}
function impFontSize(btn,dir){
  const el=impTargetHtml(btn); if(!el)return;
  impFocusTarget(el);
  const sel=window.getSelection();
  if(!sel.rangeCount||sel.isCollapsed){ /* بدون انتخاب: کل سوال */
    const cur=parseFloat(getComputedStyle(el).fontSize)||14;
    el.style.fontSize=Math.max(9,Math.min(26,cur+dir))+'px';
    return;
  }
  document.execCommand('fontSize',false,'7');
  el.querySelectorAll('font[size="7"]').forEach(f=>{
    const span=document.createElement('span');
    const base=parseFloat(getComputedStyle(f.parentElement).fontSize)||14;
    span.style.fontSize=Math.max(9,Math.min(28,base+dir*2))+'px';
    span.innerHTML=f.innerHTML; f.replaceWith(span);
  });
}
function impInsertHTML(btn,html){
  const el=impTargetHtml(btn); if(!el)return;
  impFocusTarget(el);
  document.execCommand('insertHTML',false,html);
}
function impInsertFrac(btn){
  const num=prompt('صورت کسر:','۱'); if(num===null)return;
  const den=prompt('مخرج کسر:','۲'); if(den===null)return;
  const f='<span dir="ltr" style="unicode-bidi:isolate"><span class="pfrac" style="display:inline-block;vertical-align:middle;text-align:center;margin:0 2px"><span style="display:block;padding:0 4px;border-bottom:1.2px solid currentColor;line-height:1.15">'+num.replace(/</g,'&lt;')+'</span><span style="display:block;padding:0 4px;line-height:1.15">'+den.replace(/</g,'&lt;')+'</span></span></span>&nbsp;';
  impInsertHTML(btn,f);
}
/* ---------- چین و جهت ---------- */
function impAlign(btn,dir){
  const el=impTargetHtml(btn); if(!el)return;
  impFocusTarget(el);
  document.execCommand(dir==='center'?'justifyCenter':(dir==='left'?'justifyLeft':'justifyRight'),false,null);
}
function impDirection(btn,dir){
  const el=impTargetHtml(btn); if(!el)return;
  impFocusTarget(el);
  const sel=window.getSelection();
  if(sel.rangeCount && !sel.isCollapsed && el.contains(sel.anchorNode)){
    /* v4.108.0: فقط متن انتخاب‌شده — بدون inline-block (که فاصله عمودی می‌انداخت)
       و با extract+wrap همیشه (surroundContents روی انتخاب چندگره‌ای بی‌اثر می‌شد) */
    const range=sel.getRangeAt(0);
    const span=document.createElement('span');
    span.setAttribute('dir',dir);
    span.style.unicodeBidi='isolate';
    const frag=range.extractContents();
    span.appendChild(frag);
    range.insertNode(span);
    /* span های جهت‌دار تودرتو داخل انتخاب پاک شوند تا جهت جدید واقعاً اثر کند */
    span.querySelectorAll('span[dir]').forEach(x=>{
      x.removeAttribute('dir');
      if(x.style){x.style.unicodeBidi='';x.style.direction='';}
      if(x.attributes.length===0 && !x.getAttribute('style')) x.replaceWith(...x.childNodes);
    });
    const nr=document.createRange(); nr.selectNodeContents(span);
    sel.removeAllRanges(); sel.addRange(nr);
    impSaveSel();
  }else{
    /* بدون انتخاب: کل سوال */
    el.setAttribute('dir',dir);
    el.style.textAlign=dir==='ltr'?'left':'right';
  }
}
/* ---------- نمادها (مثل Word) — تب‌دار ---------- */
const impSymTabs={
  'ریاضی':['+','−','×','÷','=','≠','≈','≡','≤','≥','±','∓','√','∛','∜','π','∞','∑','∏','∫','∂','∇','°','٪','‰','²','³','⁴','½','⅓','¼','⅔','¾','∠','⊥','∥','≅','∼','∝','∈','∉','⊂','⊃','⊆','∪','∩','∅','→','←','↔','⇒','⇔','∀','∃','∴','∵','¬','∧','∨'],
  'یونانی':['α','β','γ','δ','ε','ζ','η','θ','ι','κ','λ','μ','ν','ξ','ο','ρ','σ','τ','υ','φ','χ','ψ','ω','Γ','Δ','Θ','Λ','Ξ','Π','Σ','Φ','Ψ','Ω'],
  'لاتین بزرگ':['A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','U','V','W','X','Y','Z'],
  'Script 𝒜':['𝒜','ℬ','𝒞','𝒟','ℰ','ℱ','𝒢','ℋ','ℐ','𝒥','𝒦','ℒ','ℳ','𝒩','𝒪','𝒫','𝒬','ℛ','𝒮','𝒯','𝒰','𝒱','𝒲','𝒳','𝒴','𝒵','𝒶','𝒷','𝒸','𝒹','ℯ','𝒻','ℊ','𝒽','𝒾','𝒿','𝓀','𝓁','𝓂','𝓃','ℴ','𝓅','𝓆','𝓇','𝓈','𝓉','𝓊','𝓋','𝓌','𝓍','𝓎','𝓏'],
  'دونگاشت 𝔸':['𝔸','𝔹','ℂ','𝔻','𝔼','𝔽','𝔾','ℍ','𝕀','𝕁','𝕂','𝕃','𝕄','ℕ','𝕆','ℙ','ℚ','ℝ','𝕊','𝕋','𝕌','𝕍','𝕎','𝕏','𝕐','ℤ'],
  'هندسه/فلش':['△','▷','▽','◁','□','▭','◇','○','◯','●','■','◆','∟','⌒','⊙','↑','↓','↗','↘','↖','↙','⤴','⤵','↺','↻'],
  'عمومی':['«','»','،','؛','؟','…','—','–','٪','※','†','‡','•','◦','✓','✗','☑','☐','★','☆','♦','♠','♥','♣']
};
let impSymCat='ریاضی';
function impOpenSymbols(btn){
  impActiveHtml=impTargetHtml(btn);
  impSaveSel();
  const tabs=document.getElementById('impSymTabs'); tabs.innerHTML='';
  Object.keys(impSymTabs).forEach(cat=>{
    const b=document.createElement('button'); b.type='button';
    b.className='imp-sym-tab'+(cat===impSymCat?' active':''); b.textContent=cat;
    b.onclick=()=>{impSymCat=cat;impRenderSymCat();document.querySelectorAll('.imp-sym-tab').forEach(x=>x.classList.toggle('active',x.textContent===cat));};
    tabs.appendChild(b);
  });
  impRenderSymCat();
  document.getElementById('impSymModal').classList.add('open');
}
function impRenderSymCat(){
  const grid=document.getElementById('impSymGrid'); grid.innerHTML='';
  (impSymTabs[impSymCat]||[]).forEach(sy=>{
    const b=document.createElement('button'); b.type='button'; b.className='imp-sym'; b.textContent=sy;
    b.onclick=()=>{impInsertAtSaved(sy.replace(/&/g,'&amp;').replace(/</g,'&lt;'));};
    grid.appendChild(b);
  });
}
function impInsertAtSaved(html){
  const el=impActiveHtml; if(!el)return;
  el.focus();
  if(impSavedRange && el.contains(impSavedRange.commonAncestorContainer)){
    const sel=window.getSelection(); sel.removeAllRanges(); sel.addRange(impSavedRange);
  }
  document.execCommand('insertHTML',false,html);
  impSaveSel();
}
function impCloseSym(){document.getElementById('impSymModal').classList.remove('open');}
/* ---------- معادله (مثل Word) — قالب‌های آماده با جای‌نگهدار قابل تایپ ---------- */
/* v4.108.0: کادر خط‌چین eq-slot فقط با CSS ویرایشگر نمایش داده می‌شود (استایل inline ندارد)
   و هنگام ثبت، کلاس آن حذف می‌شود — بنابراین در چاپ نهایی/برگه هرگز دیده نمی‌شود */
const EQ_BOX='<span class="eq-slot">&#8203;</span>';
const impEqTemplates=[
 ['کسر ساده', '<span class="pfrac" style="display:inline-block;vertical-align:middle;text-align:center;margin:0 2px"><span style="display:block;padding:0 4px;border-bottom:1.2px solid currentColor;line-height:1.15">'+EQ_BOX+'</span><span style="display:block;padding:0 4px;line-height:1.15">'+EQ_BOX+'</span></span>'],
 ['جمع دو کسر', '<span class="pfrac" style="display:inline-block;vertical-align:middle;text-align:center;margin:0 2px"><span style="display:block;padding:0 4px;border-bottom:1.2px solid currentColor;line-height:1.15">'+EQ_BOX+'</span><span style="display:block;padding:0 4px;line-height:1.15">'+EQ_BOX+'</span></span> + <span class="pfrac" style="display:inline-block;vertical-align:middle;text-align:center;margin:0 2px"><span style="display:block;padding:0 4px;border-bottom:1.2px solid currentColor;line-height:1.15">'+EQ_BOX+'</span><span style="display:block;padding:0 4px;line-height:1.15">'+EQ_BOX+'</span></span> = '+EQ_BOX],
 ['توان', EQ_BOX+'<sup style="font-size:.72em">'+EQ_BOX+'</sup>'],
 ['اندیس', EQ_BOX+'<sub style="font-size:.72em">'+EQ_BOX+'</sub>'],
 ['رادیکال', '√<span style="border-top:1.2px solid currentColor;padding:0 3px">'+EQ_BOX+'</span>'],
 ['رادیکال با فرجه', '<sup style="font-size:.7em">'+EQ_BOX+'</sup>√<span style="border-top:1.2px solid currentColor;padding:0 3px">'+EQ_BOX+'</span>'],
 ['قدر مطلق', '|'+EQ_BOX+'|'],
 ['پرانتز عملیات', '('+EQ_BOX+') × ('+EQ_BOX+') ='],
 ['جمع علامت‌دار', '(−'+EQ_BOX+') + (+'+EQ_BOX+') ='],
 ['معادله خطی', EQ_BOX+' x + '+EQ_BOX+' = '+EQ_BOX],
 ['نسبت و تناسب', '<span class="pfrac" style="display:inline-block;vertical-align:middle;text-align:center;margin:0 2px"><span style="display:block;padding:0 4px;border-bottom:1.2px solid currentColor;line-height:1.15">'+EQ_BOX+'</span><span style="display:block;padding:0 4px;line-height:1.15">'+EQ_BOX+'</span></span> = <span class="pfrac" style="display:inline-block;vertical-align:middle;text-align:center;margin:0 2px"><span style="display:block;padding:0 4px;border-bottom:1.2px solid currentColor;line-height:1.15">'+EQ_BOX+'</span><span style="display:block;padding:0 4px;line-height:1.15">'+EQ_BOX+'</span></span>'],
 ['مجموعه', '{ '+EQ_BOX+' , '+EQ_BOX+' , '+EQ_BOX+' }'],
 ['زاویه', '∠'+EQ_BOX+' = '+EQ_BOX+'°'],
 ['درصد', EQ_BOX+' ٪ × '+EQ_BOX+' = '],
];
function impOpenEquation(btn){
  impActiveHtml=impTargetHtml(btn);
  impSaveSel();
  const grid=document.getElementById('impEqGrid');
  if(!grid.dataset.built){
    impEqTemplates.forEach(([name,html])=>{
      const card=document.createElement('button'); card.type='button'; card.className='imp-eq-card';
      card.innerHTML='<span dir="ltr" style="unicode-bidi:isolate;pointer-events:none">'+html.replace(/eq-slot/g,'eq-slot-demo')+'</span><small>'+name+'</small>';
      card.onclick=()=>{impInsertAtSaved('<span dir="ltr" style="unicode-bidi:isolate">'+html+'</span>&nbsp;');impCloseEq();};
      grid.appendChild(card);
    });
    grid.dataset.built='1';
  }
  document.getElementById('impEqModal').classList.add('open');
}
function impInsertEqCustom(){
  const v=document.getElementById('impEqCustom').value.trim();
  if(!v)return;
  impInsertAtSaved('<span dir="ltr" style="unicode-bidi:isolate">'+v.replace(/&/g,'&amp;').replace(/</g,'&lt;')+'</span>&nbsp;');
  document.getElementById('impEqCustom').value='';
  impCloseEq();
}
function impCloseEq(){document.getElementById('impEqModal').classList.remove('open');}
function impUploadImage(inp){
  const file=inp.files&&inp.files[0]; if(!file)return;
  const q=inp.closest('.imp-q'); const el=q.querySelector('.imp-html');
  const rd=new FileReader();
  rd.onload=()=>{
    const im=new Image();
    im.onload=()=>{
      /* فشرده‌سازی سمت مرورگر: حداکثر 800px، خروجی PNG/WebP */
      const scale=Math.min(1,800/im.width);
      const c=document.createElement('canvas');
      c.width=Math.round(im.width*scale); c.height=Math.round(im.height*scale);
      c.getContext('2d').drawImage(im,0,0,c.width,c.height);
      const src=c.toDataURL('image/png');
      const w=Math.min(420,c.width);
      impFocusTarget(el);
      document.execCommand('insertHTML',false,'<img src="'+src+'" style="width:'+w+'px;height:auto;display:block;margin:4px 0">');
      inp.value='';
    };
    im.src=rd.result;
  };
  rd.readAsDataURL(file);
}
</script>
 <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
