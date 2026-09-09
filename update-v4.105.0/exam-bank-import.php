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
            $subject = trim($_POST['subject_name'] ?? '') !== '' ? trim($_POST['subject_name']) : ($parsed['subject_name'] ?: 'نامشخص');
            $teacherId = (int)($_POST['teacher_id'] ?? 0) ?: null;
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
         <select name="teacher_id" class="form-select">
           <option value="">— بدون دبیر (نام دستی) —</option>
           <?php foreach ($teachers as $t): ?><option value="<?php echo $t['id']; ?>"><?php echo clean($t['name']); ?></option><?php endforeach; ?>
         </select></div>
       <div><label class="text-xs font-bold">نام طراح (اختیاری — جایگزین نام دبیر)</label>
         <input name="designer_name" class="form-input" placeholder="مثلاً خانم محمدی"></div>
     </div>
     <div class="grid grid-cols-4 gap-3">
       <div><label class="text-xs font-bold">درس</label>
         <input name="subject_name" class="form-input" value="<?php echo clean($previewData['subject_name']); ?>" placeholder="نام درس"></div>
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
   <h3 class="font-bold mb-3">پیش‌نمایش و ویرایش سوالات فایل ایمپورت</h3>
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
       <div class="bank-question-card imp-html" contenteditable="true" data-height="<?php echo (int)$iq['height']; ?>"><?php echo $iq['question_html']; ?></div>
       <div class="imp-tools">
         <button type="button" class="btn btn-outline text-xs" onclick="impOpenCropper(this)">برش تصویر</button>
       </div>
     </div>
     <?php endforeach; ?>
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
.imp-tools{margin-top:6px;display:flex;gap:6px}
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
/* ============ v4.105.0: ویرایش سوالات در پیش‌نمایش ایمپورت ============ */
function impToggleDelete(btn){
  const q=btn.closest('.imp-q'); q.classList.toggle('imp-deleted');
  btn.textContent=q.classList.contains('imp-deleted')?'بازگردانی':'حذف';
}
function impCollectEdits(form){
  const out=[];
  document.querySelectorAll('.imp-q').forEach(q=>{
    out.push({
      no:q.querySelector('.imp-no').value.trim(),
      question_type:q.querySelector('.imp-type').value,
      score:q.querySelector('.imp-score').value.trim(),
      height:parseInt(q.querySelector('.imp-html').dataset.height||'18',10),
      question_html:q.querySelector('.imp-html').innerHTML,
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
document.addEventListener('keydown',e=>{if(e.key==='Escape')impCloseCropper();});
</script>
 <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
