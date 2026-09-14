<?php
// File: online-exam-questions.php - Professional question designer
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/online_exam_helpers.php';
require_once __DIR__ . '/includes/school_roles.php';
ensure_school_roles_schema();
ensure_online_exams_schema();

if (!empty($_SESSION['admin_id'])) {
    require_permission('manage_reports');
} elseif (empty($_SESSION['teacher_id'])) {
    redirect('admin-login.php');
}
$teacherId = $_SESSION['teacher_id'] ?? null;
$isExecutive = $teacherId ? (teacher_has_executive($teacherId) || teacher_has_deputy($teacherId)) : false;

$examId = (int)($_GET['exam_id'] ?? 0);
if ($examId<=0) { set_flash_message('error','آزمون نامعتبر'); redirect('online-exams.php'); }
$exam = DB::fetch("SELECT * FROM online_exams WHERE id=?", [$examId]);
if (!$exam) { set_flash_message('error','آزمون یافت نشد'); redirect('online-exams.php'); }
if ($teacherId && !$isExecutive && (int)$exam['teacher_id'] !== (int)$teacherId) { set_flash_message('error','دسترسی غیرمجاز'); redirect('online-exams.php'); }

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!verify_csrf($_POST['csrf_token']??'')) { set_flash_message('error','CSRF'); redirect('online-exam-questions.php?exam_id='.$examId); }

    // Delete question
    if (isset($_POST['delete_question'])) {
        $qid = (int)$_POST['question_id'];
        DB::execute("DELETE FROM online_questions WHERE id=? AND exam_id=?", [$qid,$examId]);
        set_flash_message('success','سوال حذف شد');
        redirect('online-exam-questions.php?exam_id='.$examId);
    }

    // Reorder
    if (isset($_POST['save_order'])) {
        $order = $_POST['order'] ?? [];
        foreach ($order as $idx=>$qid) {
            DB::execute("UPDATE online_questions SET order_index=? WHERE id=? AND exam_id=?", [(int)$idx, (int)$qid, $examId]);
        }
        set_flash_message('success','ترتیب ذخیره شد');
        redirect('online-exam-questions.php?exam_id='.$examId);
    }

    // Add from bank
    if (isset($_POST['add_from_bank'])) {
        $bankIds = $_POST['bank_ids'] ?? [];
        $added=0;
        $maxOrder = DB::fetch("SELECT COALESCE(MAX(order_index),0) m FROM online_questions WHERE exam_id=?", [$examId]);
        $nextOrder = (int)($maxOrder['m']??0)+1;
        foreach ($bankIds as $bid) {
            $bq = DB::fetch("SELECT * FROM online_question_bank WHERE id=?", [(int)$bid]);
            if (!$bq) continue;
            DB::execute("INSERT INTO online_questions (exam_id, bank_question_id, category_id, question_type, question_text, question_data, points, order_index) VALUES (?,?,?,?,?,?,?,?)", [$examId,$bq['id'],$bq['category_id'],$bq['question_type'],$bq['question_text'],$bq['question_data'],$bq['points'],$nextOrder++]);
            DB::execute("UPDATE online_question_bank SET usage_count=usage_count+1 WHERE id=?", [(int)$bid]);
            $added++;
        }
        set_flash_message('success', tr_num($added,'fa').' سوال از بانک به آزمون اضافه شد');
        redirect('online-exam-questions.php?exam_id='.$examId);
    }

    // Save question (new or edit)
    if (isset($_POST['save_question'])) {
        $qId = (int)($_POST['question_id']??0);
        $qType = trim($_POST['question_type']??'radio');
        $allowedTypes = array_keys(online_question_types());
        if (!in_array($qType,$allowedTypes)) $qType='radio';
        $qText = trim($_POST['question_text_html']??$_POST['question_text']??'');
        if ($qText==='') $qText = trim($_POST['question_text']??'');

        $points = (float)($_POST['points']??1);
        $categoryId = (int)($_POST['category_id']??0) ?: null;
        $orderIndex = (int)($_POST['order_index']??0);

        $qData = [];
        $qData['points'] = $points;
        $qData['media'] = [];

        // Handle media uploads for question
        if (!empty($_FILES['question_image']) && $_FILES['question_image']['error']===UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['question_image']['name'], PATHINFO_EXTENSION));
            $allowedImg = ['jpg','jpeg','png','webp','gif'];
            if (in_array($ext,$allowedImg)) {
                $dir = __DIR__.'/uploads/online-exams/questions';
                if (!is_dir($dir)) mkdir($dir,0755,true);
                $fn = 'q_'.time().'_'.bin2hex(random_bytes(3)).'.'.$ext;
                move_uploaded_file($_FILES['question_image']['tmp_name'],$dir.'/'.$fn);
                $qData['media']['image'] = 'uploads/online-exams/questions/'.$fn;
            }
        }
        // Video
        if (trim($_POST['question_video']??'')!=='') $qData['media']['video'] = trim($_POST['question_video']);
        if (trim($_POST['question_audio']??'')!=='') $qData['media']['audio'] = trim($_POST['question_audio']);

        // Type specific
        if (in_array($qType, ['radio','checkbox','dropdown'])) {
            $opts = [];
            $optTexts = $_POST['option_text'] ?? [];
            $optCorrect = $_POST['option_correct'] ?? []; // array of indices or values
            $optPoints = $_POST['option_points'] ?? [];
            foreach ($optTexts as $idx=>$txt) {
                $txt = trim($txt);
                if ($txt==='') continue;
                $isCorrect = false;
                if ($qType==='radio' || $qType==='dropdown') {
                    $isCorrect = isset($_POST['correct_radio']) && (int)$_POST['correct_radio'] === (int)$idx;
                } else {
                    $isCorrect = in_array((string)$idx, array_map('strval', $optCorrect));
                }
                $opts[] = [
                    'id'=>'opt_'.($idx+1),
                    'text'=>$txt,
                    'is_correct'=>$isCorrect,
                    'points'=> (float)($optPoints[$idx]??($isCorrect?1:0))
                ];
            }
            $qData['options'] = $opts;
        } elseif (in_array($qType, ['short_text','text','number'])) {
            $qData['correct_answer'] = trim($_POST['correct_answer']??'');
            $qData['case_sensitive'] = isset($_POST['case_sensitive'])?1:0;
            $qData['tolerance'] = (float)($_POST['tolerance']??0);
        } elseif ($qType==='fill_blank') {
            $blanks = [];
            $blankAnswers = $_POST['blank_answer'] ?? [];
            foreach ($blankAnswers as $b) {
                $b=trim($b);
                if ($b!=='') $blanks[] = ['answer'=>$b];
            }
            $qData['blanks'] = $blanks;
            $qData['blank_text'] = trim($_POST['blank_text']??'');
        } elseif ($qType==='matching') {
            $pairs = [];
            $lefts = $_POST['match_left'] ?? [];
            $rights = $_POST['match_right'] ?? [];
            foreach ($lefts as $i=>$l) {
                $l=trim($l); $r=trim($rights[$i]??'');
                if ($l!=='' && $r!=='') $pairs[] = ['left'=>$l,'right'=>$r];
            }
            $qData['pairs'] = $pairs;
        } elseif (in_array($qType, ['file_upload','voice_upload','whiteboard'])) {
            $qData['allowed_ext'] = trim($_POST['allowed_ext']??'');
            $qData['max_size'] = (int)($_POST['max_size']??5);
        }

        // save to bank if requested
        $saveToBank = isset($_POST['save_to_bank']);

        $qDataJson = json_encode($qData, JSON_UNESCAPED_UNICODE);

        if ($qId>0) {
            DB::execute("UPDATE online_questions SET question_type=?, question_text=?, question_data=?, points=?, category_id=? WHERE id=? AND exam_id=?", [$qType,$qText,$qDataJson,$points,$categoryId,$qId,$examId]);
            $savedId = $qId;
        } else {
            if ($orderIndex===0) {
                $max = DB::fetch("SELECT COALESCE(MAX(order_index),0) m FROM online_questions WHERE exam_id=?", [$examId]);
                $orderIndex = (int)($max['m']??0)+1;
            }
            DB::execute("INSERT INTO online_questions (exam_id, category_id, question_type, question_text, question_data, points, order_index) VALUES (?,?,?,?,?,?,?)", [$examId,$categoryId,$qType,$qText,$qDataJson,$points,$orderIndex]);
            $savedId = DB::lastInsertId();
        }

        if ($saveToBank) {
            DB::execute("INSERT INTO online_question_bank (teacher_id, category_id, question_type, question_text, question_data, points) VALUES (?,?,?,?,?,?)", [$teacherId ?: $exam['teacher_id'], $categoryId, $qType, $qText, $qDataJson, $points]);
        }

        set_flash_message('success','سوال ذخیره شد');
        redirect('online-exam-questions.php?exam_id='.$examId);
    }
}

$questions = DB::fetchAll("SELECT oq.*, c.name as category_name FROM online_questions oq LEFT JOIN online_question_categories c ON c.id=oq.category_id WHERE oq.exam_id=? ORDER BY oq.order_index ASC, oq.id ASC", [$examId]);
$questionCategories = DB::fetchAll("SELECT * FROM online_question_categories ORDER BY name");
$bankQuestions = DB::fetchAll("SELECT b.*, c.name as category_name FROM online_question_bank b LEFT JOIN online_question_categories c ON c.id=b.category_id WHERE b.teacher_id=? OR b.is_public=1 ORDER BY b.id DESC LIMIT 200", [$teacherId ?: $exam['teacher_id']]);

$editQuestion = null;
if (isset($_GET['edit'])) {
    $editQuestion = DB::fetch("SELECT * FROM online_questions WHERE id=? AND exam_id=?", [(int)$_GET['edit'],$examId]);
}

require_once __DIR__ . '/includes/header.php';
?>
<style>
.q-editor-toolbar { display:flex; flex-wrap:wrap; gap:4px; padding:6px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px 8px 0 0; }
.q-editor-toolbar button { padding:4px 8px; font-size:12px; background:white; border:1px solid #e2e8f0; border-radius:6px; cursor:pointer; }
.q-editor-toolbar button:hover { background:#eef2ff; }
.q-contenteditable { min-height:120px; border:1px solid #e2e8f0; border-top:0; padding:12px; border-radius:0 0 8px 8px; background:white; outline:none; }
.q-contenteditable:focus { border-color:#6366f1; box-shadow:0 0 0 2px rgba(99,102,241,0.2); }
.question-card { border:1px solid #e2e8f0; border-radius:12px; padding:14px; background:white; margin-bottom:12px; }
.option-row { display:flex; gap:8px; align-items:center; margin-bottom:6px; }
</style>

<div class="space-y-6 max-w-6xl mx-auto">
    <div class="flex justify-between items-center">
        <div>
            <h2 class="text-xl font-bold">طراحی سوالات: <?php echo clean($exam['title']); ?></h2>
            <p class="text-xs text-muted">تعداد سوالات فعلی: <?php echo tr_num(count($questions),'fa'); ?> | مدت: <?php echo tr_num($exam['duration_minutes'],'fa'); ?> دقیقه</p>
        </div>
        <div class="flex gap-2">
            <a href="online-exams.php" class="btn btn-secondary text-xs">لیست آزمون‌ها</a>
            <a href="online-exam-form.php?id=<?php echo $examId; ?>" class="btn btn-outline text-xs">ویرایش آزمون</a>
            <a href="online-exam-monitor.php?exam_id=<?php echo $examId; ?>" class="btn btn-warning text-xs">مانیتورینگ</a>
            <a href="online-exam-preview.php?exam_id=<?php echo $examId; ?>" target="_blank" class="btn btn-primary text-xs">پیش‌نمایش دانش‌آموز</a>
        </div>
    </div>

    <div class="grid grid-cols-3 gap-6">
        <!-- Questions list -->
        <div class="col-span-2 space-y-4">
            <div class="card">
                <div class="flex justify-between items-center mb-3">
                    <h3 class="font-bold">لیست سوالات (<?php echo tr_num(count($questions),'fa'); ?>)</h3>
                    <button onclick="document.getElementById('addQuestionForm').scrollIntoView({behavior:'smooth'})" class="btn btn-success text-xs">افزودن سوال جدید</button>
                </div>

                <?php if(empty($questions)): ?>
                    <p class="text-center text-muted py-8">هنوز سوالی ثبت نشده. از فرم سمت چپ استفاده کنید.</p>
                <?php else: ?>
                <form method="POST" id="orderForm">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="save_order" value="1">
                    <div id="questionsSortable">
                    <?php foreach($questions as $idx=>$q): $qd=json_decode($q['question_data'], true); ?>
                        <div class="question-card" data-id="<?php echo $q['id']; ?>">
                            <input type="hidden" name="order[]" value="<?php echo $q['id']; ?>">
                            <div class="flex justify-between items-start">
                                <div class="flex gap-2 items-center">
                                    <span class="badge badge-info"><?php echo tr_num($idx+1,'fa'); ?></span>
                                    <span class="text-xs"><?php echo online_question_types()[$q['question_type']]['icon'] ?? '❓'; ?> <?php echo online_question_types()[$q['question_type']]['label'] ?? $q['question_type']; ?></span>
                                    <span class="badge"><?php echo tr_num($q['points'],'fa'); ?> نمره</span>
                                    <?php if($q['category_name']): ?><span class="badge badge-secondary"><?php echo clean($q['category_name']); ?></span><?php endif; ?>
                                </div>
                                <div class="flex gap-1">
                                    <a href="?exam_id=<?php echo $examId; ?>&edit=<?php echo $q['id']; ?>" class="btn btn-secondary text-xs">ویرایش</a>
                                    <form method="POST" onsubmit="return confirm('حذف سوال؟')" class="inline"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="question_id" value="<?php echo $q['id']; ?>"><button name="delete_question" class="btn btn-danger text-xs">حذف</button></form>
                                </div>
                            </div>
                            <div class="mt-2 text-sm prose max-w-none"><?php echo $q['question_text']; // allow HTML ?>
                                <?php if(!empty($qd['media']['image'])): ?><div class="mt-2"><img src="<?php echo clean($qd['media']['image']); ?>" class="max-w-[300px] rounded border"></div><?php endif; ?>
                            </div>
                            <?php if(in_array($q['question_type'],['radio','checkbox','dropdown'])): ?>
                                <div class="mt-2 space-y-1">
                                    <?php foreach(($qd['options']??[]) as $opt): ?>
                                        <div class="text-xs p-1 rounded <?php echo !empty($opt['is_correct'])?'bg-green-50 border border-green-200':''; ?>"><?php echo !empty($opt['is_correct'])?'✅':'○'; ?> <?php echo clean($opt['text']); ?> <?php if(!empty($opt['points'])) echo '('.tr_num($opt['points'],'fa').' نمره)'; ?></div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    <button class="btn btn-primary text-xs mt-2">ذخیره ترتیب</button>
                </form>
                <?php endif; ?>
            </div>

            <!-- Bank import -->
            <div class="card">
                <h3 class="font-bold mb-3">درج از بانک سوالات</h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <div class="max-h-[300px] overflow-auto border rounded p-2 space-y-2">
                        <?php foreach($bankQuestions as $bq): ?>
                            <label class="flex gap-2 items-start p-2 border rounded hover:bg-slate-50 cursor-pointer">
                                <input type="checkbox" name="bank_ids[]" value="<?php echo $bq['id']; ?>">
                                <div class="flex-1">
                                    <div class="text-xs font-bold flex gap-1"><span><?php echo online_question_types()[$bq['question_type']]['icon'] ?? ''; ?></span><span><?php echo clean(mb_substr(strip_tags($bq['question_text']),0,80)); ?></span><span class="badge text-[10px]"><?php echo clean($bq['category_name']??'بدون دسته'); ?></span></div>
                                    <div class="text-[11px] text-muted"><?php echo tr_num($bq['points'],'fa'); ?> نمره | استفاده: <?php echo tr_num($bq['usage_count'],'fa'); ?></div>
                                </div>
                            </label>
                        <?php endforeach; if(empty($bankQuestions)): ?><p class="text-xs text-muted text-center">بانک خالی است</p><?php endif; ?>
                    </div>
                    <button name="add_from_bank" class="btn btn-primary text-xs mt-2 w-full">افزودن انتخاب‌شده‌ها به آزمون</button>
                </form>
            </div>
        </div>

        <!-- Add/Edit form -->
        <div class="space-y-4" id="addQuestionForm">
            <div class="card border-2 border-indigo-100">
                <h3 class="font-bold mb-3"><?php echo $editQuestion?'ویرایش سوال #'.tr_num($editQuestion['id'],'fa'):'افزودن سوال جدید حرفه‌ای'; ?></h3>
                <form method="POST" enctype="multipart/form-data" onsubmit="return prepareQuestionSubmit(this)">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="save_question" value="1">
                    <input type="hidden" name="question_id" value="<?php echo $editQuestion['id']??0; ?>">
                    
                    <div class="space-y-3">
                        <div>
                            <label class="text-xs">نوع سوال</label>
                            <select name="question_type" id="question_type" class="form-select" onchange="onTypeChange()" required>
                                <?php foreach(online_question_types() as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo ($editQuestion['question_type']??'radio')===$k?'selected':''; ?>><?php echo $v['icon'].' '.$v['label']; ?></option><?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="text-xs">دسته سوال</label>
                            <select name="category_id" class="form-select"><option value="0">بدون دسته</option><?php foreach($questionCategories as $qc): ?><option value="<?php echo $qc['id']; ?>" <?php echo ($editQuestion['category_id']??0)==$qc['id']?'selected':''; ?>><?php echo clean($qc['name']); ?></option><?php endforeach; ?></select>
                        </div>

                        <div>
                            <label class="text-xs">متن سوال (ویرایشگر حرفه‌ای - تصویر، ویدیو، صدا پشتیبانی می‌شود)</label>
                            <div class="q-editor-toolbar" id="qToolbar">
                                <button type="button" onclick="qExec('bold')"><b>B</b></button>
                                <button type="button" onclick="qExec('italic')"><i>I</i></button>
                                <button type="button" onclick="qExec('insertUnorderedList')">• لیست</button>
                                <button type="button" onclick="qExec('insertOrderedList')">1. لیست</button>
                                <button type="button" onclick="insertQImage()">تصویر</button>
                                <button type="button" onclick="insertQVideo()">ویدیو</button>
                                <button type="button" onclick="insertQAudio()">صدا</button>
                                <button type="button" onclick="qExec('removeFormat')">پاک‌فرمت</button>
                            </div>
                            <div id="qEditor" class="q-contenteditable" contenteditable="true"><?php echo $editQuestion['question_text']??''; ?></div>
                            <textarea name="question_text_html" id="question_text_html" class="hidden"></textarea>
                            <textarea name="question_text" id="question_text_fallback" class="hidden"></textarea>
                            <input type="file" id="qImageInput" class="hidden" accept="image/*" onchange="uploadQMedia(this,'image')">
                            <div class="mt-1 flex gap-2 text-xs">
                                <input type="file" name="question_image" class="form-input text-xs" accept="image/*"><span class="text-muted">عکس اصلی سوال</span>
                            </div>
                            <div class="grid grid-cols-2 gap-2 mt-1">
                                <input name="question_video" class="form-input text-xs" placeholder="لینک ویدیو یا مسیر (mp4)" value="<?php $qdE = $editQuestion?json_decode($editQuestion['question_data'],true):[]; echo clean($qdE['media']['video']??''); ?>">
                                <input name="question_audio" class="form-input text-xs" placeholder="لینک صدا یا مسیر (mp3)" value="<?php echo clean($qdE['media']['audio']??''); ?>">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div><label class="text-xs">نمره سوال</label><input name="points" type="number" step="0.5" class="form-input" value="<?php echo $editQuestion['points']??1; ?>"></div>
                            <div><label class="text-xs">ترتیب نمایش</label><input name="order_index" type="number" class="form-input" value="<?php echo $editQuestion['order_index']??0; ?>" placeholder="0 = خودکار آخر"></div>
                        </div>

                        <!-- Dynamic type sections -->
                        <div id="type_options" class="space-y-2 border-t pt-3">
                            <!-- Options for radio/checkbox/dropdown -->
                            <div id="section_choices" class="space-y-2">
                                <div class="flex justify-between items-center"><label class="text-xs font-bold">گزینه‌های پاسخ</label><button type="button" onclick="addOption()" class="btn btn-secondary text-xs">افزودن گزینه</button></div>
                                <div id="optionsContainer"></div>
                                <p class="text-[11px] text-muted">برای تک‌گزینه‌ای، یکی را به عنوان صحیح انتخاب کنید. برای چندگزینه‌ای، چندتا را تیک بزنید.</p>
                            </div>

                            <div id="section_text" class="space-y-2 hidden">
                                <label class="text-xs">پاسخ صحیح (برای تصحیح خودکار)</label><input name="correct_answer" class="form-input" value="<?php echo clean($qdE['correct_answer']??''); ?>">
                                <label class="text-xs flex gap-1"><input type="checkbox" name="case_sensitive" <?php echo !empty($qdE['case_sensitive'])?'checked':''; ?>> حساس به حروف بزرگ/کوچک</label>
                                <div id="number_extra"><label class="text-xs">تلرانس عددی (برای نوع عددی)</label><input name="tolerance" type="number" step="0.01" class="form-input" value="<?php echo $qdE['tolerance']??0; ?>"></div>
                            </div>

                            <div id="section_fillblank" class="space-y-2 hidden">
                                <label class="text-xs">متن جای خالی‌ها (هر خط یک پاسخ صحیح برای یک جای خالی)</label>
                                <div id="blankContainer"></div>
                                <button type="button" onclick="addBlank()" class="btn btn-secondary text-xs">افزودن جای خالی</button>
                            </div>

                            <div id="section_matching" class="space-y-2 hidden">
                                <label class="text-xs font-bold">جفت‌های تطبیقی (چپ = سوال، راست = پاسخ)</label>
                                <div id="matchingContainer"></div>
                                <button type="button" onclick="addMatching()" class="btn btn-secondary text-xs">افزودن جفت</button>
                            </div>

                            <div id="section_upload" class="space-y-2 hidden">
                                <label class="text-xs">فرمت‌های مجاز (مثلا jpg,png,pdf,mp3)</label><input name="allowed_ext" class="form-input" value="<?php echo clean($qdE['allowed_ext']??'jpg,png,pdf,mp3,wav'); ?>">
                                <label class="text-xs">حداکثر حجم (مگابایت)</label><input name="max_size" type="number" class="form-input" value="<?php echo $qdE['max_size']??5; ?>">
                            </div>
                        </div>

                        <div class="flex gap-2 items-center border-t pt-3">
                            <label class="text-xs flex gap-1"><input type="checkbox" name="save_to_bank" checked> ذخیره همزمان در بانک سوالات شخصی</label>
                        </div>

                        <button class="btn btn-success w-full py-2 font-bold">ذخیره سوال</button>
                        <?php if($editQuestion): ?><a href="?exam_id=<?php echo $examId; ?>" class="btn btn-secondary w-full mt-1 text-xs">لغو ویرایش</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let optionCount = 0;
function qExec(cmd){ document.execCommand(cmd,false,null); document.getElementById('qEditor').focus(); }
function insertQImage(){ document.getElementById('qImageInput').click(); }
function insertQVideo(){ let url=prompt('لینک ویدیو (mp4/webm) یا کد embed:'); if(url){ document.execCommand('insertHTML',false,'<video controls style=\"max-width:100%;\"><source src=\"'+url+'\"></video>'); } }
function insertQAudio(){ let url=prompt('لینک صدا (mp3/wav):'); if(url){ document.execCommand('insertHTML',false,'<audio controls src=\"'+url+'\"></audio>'); } }
function uploadQMedia(input, type){
  let file = input.files[0]; if(!file) return;
  /* v4.131.0: آپلود رسانه حالا CSRF می‌خواهد و سقف ۱۰ مگابایت دارد. */
  if(file.size > 10*1024*1024){ alert('حجم فایل بیش از ۱۰ مگابایت است.'); input.value=''; return; }
  let fd = new FormData(); fd.append('file', file);
  fd.append('csrf_token', <?php echo json_encode(csrf_token()); ?>);
  fetch('online-exam-media-upload.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{
    if(j.ok){ document.execCommand('insertHTML',false,'<img src=\"'+j.path+'\" style=\"max-width:300px;border-radius:8px;\">'); }
    else alert(j.msg||'خطا در آپلود');
  }).catch(()=>alert('ارتباط با سرور برقرار نشد.'));
  input.value='';
}
function addOption(text='', isCorrect=false){
  let cont = document.getElementById('optionsContainer');
  let idx = optionCount++;
  let div = document.createElement('div'); div.className='option-row';
  div.innerHTML = '<input type=\"text\" name=\"option_text[]\" class=\"form-input flex-1 text-xs\" placeholder=\"متن گزینه\" value=\"'+text.replace(/\"/g,'&quot;')+'\">' +
    '<label class=\"flex gap-1 items-center text-xs\"><input type=\"checkbox\" name=\"option_correct[]\" value=\"'+idx+'\" '+(isCorrect?'checked':'')+' class=\"cb-correct\"> صحیح</label>' +
    '<label class=\"flex gap-1 items-center text-xs\"><input type=\"radio\" name=\"correct_radio\" value=\"'+idx+'\" '+(isCorrect?'checked':'')+'> تک صحیح</label>' +
    '<button type=\"button\" onclick=\"this.parentElement.remove()\" class=\"btn btn-danger text-xs\">×</button>';
  cont.appendChild(div);
}
function addBlank(val=''){
  let cont = document.getElementById('blankContainer');
  let div = document.createElement('div'); div.className='flex gap-2 mb-1';
  div.innerHTML = '<input name=\"blank_answer[]\" class=\"form-input flex-1 text-xs\" placeholder=\"پاسخ صحیح جای خالی\" value=\"'+val.replace(/\"/g,'&quot;')+'\"><button type=\"button\" onclick=\"this.parentElement.remove()\" class=\"btn btn-danger text-xs\">×</button>';
  cont.appendChild(div);
}
function addMatching(l='', r=''){
  let cont = document.getElementById('matchingContainer');
  let div = document.createElement('div'); div.className='grid grid-cols-2 gap-2 mb-1';
  div.innerHTML = '<input name=\"match_left[]\" class=\"form-input text-xs\" placeholder=\"سمت چپ\" value=\"'+l.replace(/\"/g,'&quot;')+'\"><div class=\"flex gap-1\"><input name=\"match_right[]\" class=\"form-input flex-1 text-xs\" placeholder=\"سمت راست (پاسخ)\" value=\"'+r.replace(/\"/g,'&quot;')+'\"><button type=\"button\" onclick=\"this.parentElement.parentElement.remove()\" class=\"btn btn-danger text-xs\">×</button></div>';
  cont.appendChild(div);
}
function onTypeChange(){
  let t = document.getElementById('question_type').value;
  document.getElementById('section_choices').classList.add('hidden');
  document.getElementById('section_text').classList.add('hidden');
  document.getElementById('section_fillblank').classList.add('hidden');
  document.getElementById('section_matching').classList.add('hidden');
  document.getElementById('section_upload').classList.add('hidden');
  if(['radio','checkbox','dropdown'].includes(t)) document.getElementById('section_choices').classList.remove('hidden');
  if(['short_text','text','number'].includes(t)) { document.getElementById('section_text').classList.remove('hidden'); document.getElementById('number_extra').style.display = t==='number'?'block':'none'; }
  if(t==='fill_blank') document.getElementById('section_fillblank').classList.remove('hidden');
  if(t==='matching') document.getElementById('section_matching').classList.remove('hidden');
  if(['file_upload','voice_upload','whiteboard'].includes(t)) document.getElementById('section_upload').classList.remove('hidden');
}
function prepareQuestionSubmit(form){
  let html = document.getElementById('qEditor').innerHTML.trim();
  document.getElementById('question_text_html').value = html;
  document.getElementById('question_text_fallback').value = document.getElementById('qEditor').innerText;
  if(html==='' && document.getElementById('qEditor').innerText.trim()===''){ alert('متن سوال الزامی است'); return false; }
  return true;
}
document.addEventListener('DOMContentLoaded', function(){
  onTypeChange();
  // Load existing data if editing
  <?php if($editQuestion): $qdE = json_decode($editQuestion['question_data'], true) ?: []; ?>
    <?php if(in_array($editQuestion['question_type'], ['radio','checkbox','dropdown'])): foreach(($qdE['options']??[]) as $opt): ?>
      addOption(<?php echo json_encode($opt['text']??'', JSON_UNESCAPED_UNICODE); ?>, <?php echo !empty($opt['is_correct'])?'true':'false'; ?>);
    <?php endforeach; if(empty($qdE['options'])): ?>addOption('',false); addOption('',false);
    <?php endif; endif; ?>
    <?php if($editQuestion['question_type']==='fill_blank'): foreach(($qdE['blanks']??[]) as $b): ?>addBlank(<?php echo json_encode($b['answer']??'', JSON_UNESCAPED_UNICODE); ?>);
    <?php endforeach; if(empty($qdE['blanks'])): ?>addBlank('');
    <?php endif; endif; ?>
    <?php if($editQuestion['question_type']==='matching'): foreach(($qdE['pairs']??[]) as $p): ?>addMatching(<?php echo json_encode($p['left']??'', JSON_UNESCAPED_UNICODE); ?>, <?php echo json_encode($p['right']??'', JSON_UNESCAPED_UNICODE); ?>);
    <?php endforeach; if(empty($qdE['pairs'])): ?>addMatching('','');
    <?php endif; endif; ?>
  <?php else: ?>
    addOption('',true); addOption('',false); addBlank(''); addMatching('','');
  <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
