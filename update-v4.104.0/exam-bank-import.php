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
       <button class="btn btn-success">تأیید و ثبت همه سوالات در بانک</button>
       <a class="btn btn-outline" href="exam-bank-import.php">انصراف / فایل دیگر</a>
     </div>
   </form>
 </div>
 <div class="card">
   <h3 class="font-bold mb-3">پیش‌نمایش سوالات فایل ایمپورت</h3>
   <div class="imp-grid">
     <?php foreach ($previewData['questions'] as $iq): ?>
     <div>
       <div class="flex justify-between items-center mb-1">
         <b class="text-sm">سوال <?php echo clean(tr_num($iq['no'] !== '' ? $iq['no'] : '—','fa')); ?></b>
         <small><?php echo ['text'=>'تشریحی','mcq'=>'چندگزینه‌ای','blank'=>'جای خالی','tf'=>'صحیح/غلط'][$iq['question_type']] ?? 'تشریحی'; ?><?php if ($iq['score']!==''): ?> | بارم: <?php echo clean(tr_num($iq['score'],'fa')); ?><?php endif; ?></small>
       </div>
       <div class="bank-question-card"><?php echo $iq['question_html']; ?></div>
     </div>
     <?php endforeach; ?>
   </div>
 </div>
 <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
