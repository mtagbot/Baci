<?php
// File: counselor-file.php (v4.169.0)
/**
 * پروندهٔ مشاورهٔ یک دانش‌آموز — مخصوص نقش مشاور.
 *
 * دو بخش:
 *  ۱) یادداشت‌های مشاور: موارد مشاوره‌ای و یادداشت‌های خصوصی که فقط نقش
 *     مشاور در پنل خودش می‌بیند (هیچ نقش دیگری به این صفحه/جدول دسترسی
 *     ندارد؛ صفحه با teacher_has_counselor محافظت می‌شود).
 *  ۲) بستهٔ مکالمات: تمام درخواست‌های مشاورهٔ دانش‌آموز به‌همراه کل
 *     تاریخچهٔ پیام‌های ولی و مشاور، مرتب و قابل بررسی.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/school_roles.php';
require_once __DIR__ . '/includes/bot_helpers.php';
ensure_school_roles_schema();
ensure_counseling_schema();
if (empty($_SESSION['teacher_id']) || !teacher_has_counselor($_SESSION['teacher_id'])) redirect('admin-login.php?tab=teacher');
$teacherId=(int)$_SESSION['teacher_id'];
$sid=(int)($_GET['id'] ?? $_POST['student_id'] ?? 0);
$student=$sid?DB::fetch("SELECT * FROM students WHERE id=?",[$sid]):null;
if(!$student){ set_flash_message('error','دانش‌آموز یافت نشد.'); redirect('counselor-panel.php?tab=students'); }

/* بازگشت: فقط آدرس نسبیِ امن (بدون پروتکل و بدون کاراکتر خطرناک) */
$backUrl='counselor-panel.php?tab=students';
$backParam=trim((string)($_GET['back'] ?? ''));
if($backParam!=='' && strpos($backParam,'.php')!==false && strpos($backParam,'://')===false && $backParam[0]!=='/' && preg_match('~^[A-Za-z0-9_.\-/?=&%]+$~',$backParam)) $backUrl=$backParam;

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf($_POST['csrf_token'] ?? '')){ set_flash_message('error','خطای CSRF'); redirect('counselor-file.php?id='.$sid); }
    if(isset($_POST['add_note'])){
        $body=trim($_POST['body']??'');
        if($body!==''){
            DB::execute("INSERT INTO counselor_notes (student_id, teacher_id, body, created_at_jalali) VALUES (?,?,?,?)", [$sid,$teacherId,$body,jalali_now()]);
            set_flash_message('success','یادداشت مشاور ثبت شد.');
        } else set_flash_message('error','متن یادداشت خالی است.');
        redirect('counselor-file.php?id='.$sid.'&back='.urlencode($backUrl));
    }
    if(isset($_POST['delete_note'])){
        /* فقط یادداشت خودش یا در نقش همان مشاور — برای سادگی و امنیت،
           حذف با تطبیق student_id و teacher_id انجام می‌شود. */
        DB::execute("DELETE FROM counselor_notes WHERE id=? AND student_id=? AND teacher_id=?", [(int)$_POST['note_id'],$sid,$teacherId]);
        set_flash_message('success','یادداشت حذف شد.');
        redirect('counselor-file.php?id='.$sid.'&back='.urlencode($backUrl));
    }
}

$notes=DB::fetchAll("SELECT n.*, t.full_name AS teacher_name FROM counselor_notes n LEFT JOIN teachers t ON t.id=n.teacher_id WHERE n.student_id=? ORDER BY n.id DESC",[$sid]);
/* همهٔ درخواست‌های مشاورهٔ این دانش‌آموز: هم آن‌هایی که student_id دارند،
   هم آن‌هایی که از چت ولی به این دانش‌آموز می‌رسند. */
$reqs=[];
try{
    $links=[];
    foreach(['bale','telegram'] as $p){
        try{
            ensure_bot_schema($p);
            $table=bot_user_table($p); $chatCol=$p==='telegram'?'telegram_chat_id':'bale_chat_id';
            $lrows=DB::fetchAll("SELECT `$chatCol` AS chat_id FROM `$table` WHERE student_id=?",[$sid]);
            foreach($lrows as $lr) $links[$p][]=(string)$lr['chat_id'];
        }catch(Throwable $e){}
    }
    $sql="SELECT * FROM counseling_requests WHERE student_id=?"; $prm=[$sid];
    foreach($links as $p=>$chats){
        if(!$chats) continue;
        $marks=implode(',',array_fill(0,count($chats),'?'));
        $sql.=" OR (platform=? AND chat_id IN ($marks))";
        $prm[]=$p; foreach($chats as $c) $prm[]=$c;
    }
    $reqs=DB::fetchAll($sql." ORDER BY id DESC LIMIT 200",$prm);
}catch(Throwable $e){}
$msgsByReq=[];
if($reqs){
    $rids=[]; foreach($reqs as $r) $rids[]=(int)$r['id'];
    $marks=implode(',',array_fill(0,count($rids),'?'));
    $allMsgs=DB::fetchAll("SELECT * FROM counseling_messages WHERE request_id IN ($marks) ORDER BY id ASC",$rids);
    foreach($allMsgs as $m) $msgsByReq[(int)$m['request_id']][]=$m;
}
$statusFa=['new'=>'جدید','in_progress'=>'درحال پیگیری','replied'=>'پاسخ داده شد','closed'=>'بسته شد'];

$pageCss = 'assets/css/counselor-panel.css?v=4.170.0';
require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-6">
 <div class="page-hero flex justify-between items-center flex-wrap gap-2">
   <div class="flex items-center gap-3">
     <div style="width:52px;height:68px;border-radius:8px;border:1px solid #cbd5e1;overflow:hidden;background:#f1f5f9;display:flex;align-items:center;justify-content:center"><?php if(!empty($student['photo_url'])): ?><img src="<?php echo clean($student['photo_url']); ?>" alt="" style="width:100%;height:100%;object-fit:cover"><?php else: ?><span style="font-size:24px">👤</span><?php endif; ?></div>
     <div><h2 class="text-2xl font-bold">پروندهٔ مشاورهٔ <?php echo clean($student['first_name'].' '.$student['last_name']); ?></h2><p class="text-sm text-muted"><?php echo clean($student['class_name'].' — '.$student['grade_level']); ?> · کد ملی <?php echo tr_num($student['national_id'],'fa'); ?></p></div>
   </div>
   <?php /* v4.170.0: میان‌برهای پرونده — از یادداشت‌ها مستقیم به اطلاعات،
          کارنامه‌ها و موارد انضباطی همان دانش‌آموز (و بازگشت به همین صفحه). */ ?>
   <?php $fileBack=urlencode('counselor-file.php?id='.$sid.'&back='.urlencode($backUrl)); ?>
   <div class="flex gap-2 flex-wrap">
     <a class="btn btn-outline text-xs" href="staff-student-file.php?tab=info&id=<?php echo $sid; ?>&back=<?php echo $fileBack; ?>">اطلاعات</a>
     <a class="btn btn-primary text-xs" href="staff-student-file.php?tab=reports&id=<?php echo $sid; ?>&back=<?php echo $fileBack; ?>">کارنامه‌ها</a>
     <a class="btn btn-warning text-xs" href="staff-student-file.php?tab=discipline&id=<?php echo $sid; ?>&back=<?php echo $fileBack; ?>">موارد انضباطی</a>
     <a class="btn btn-secondary" href="<?php echo clean($backUrl); ?>">بازگشت</a>
   </div>
 </div>

 <div class="grid grid-cols-2 gap-6 responsive-grid">
  <section class="card" aria-labelledby="cnotes-title">
   <h3 id="cnotes-title" class="font-bold mb-2">یادداشت‌های مشاور</h3>
   <p class="text-xs text-muted mb-3">این یادداشت‌ها پروندهٔ خصوصی مشاور است و فقط نقش مشاور در پنل خودش آن‌ها را می‌بیند.</p>
   <form method="POST" class="space-y-2 mb-4"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="add_note" value="1"><input type="hidden" name="student_id" value="<?php echo $sid; ?>"><textarea name="body" class="form-textarea" placeholder="مورد مشاوره‌ای، مشاهده، پیگیری..."></textarea><button class="btn btn-success">ثبت یادداشت</button></form>
   <div class="space-y-2"><?php foreach($notes as $n): ?><div class="soft-panel text-sm"><div class="flex justify-between items-start gap-2"><div><b class="text-xs"><?php echo clean($n['teacher_name'] ?: 'مشاور'); ?></b> · <span class="text-xs text-muted"><?php echo tr_num($n['created_at_jalali'],'fa'); ?></span><p class="mt-1"><?php echo nl2br(clean($n['body'])); ?></p></div><?php if((int)$n['teacher_id']===$teacherId): ?><form method="POST" onsubmit="return confirm('یادداشت حذف شود؟')"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="delete_note" value="1"><input type="hidden" name="note_id" value="<?php echo (int)$n['id']; ?>"><button class="btn btn-danger text-xs">حذف</button></form><?php endif; ?></div></div><?php endforeach; if(!$notes): ?><p class="text-center text-muted text-sm">یادداشتی ثبت نشده است.</p><?php endif; ?></div>
  </section>

  <section class="card" aria-labelledby="cthread-title">
   <h3 id="cthread-title" class="font-bold mb-2">بستهٔ درخواست‌ها و مکالمات</h3>
   <p class="text-xs text-muted mb-3">تمام درخواست‌های مشاورهٔ این دانش‌آموز با تاریخچهٔ کامل پیام‌های ولی و مشاور.</p>
   <div class="space-y-4"><?php foreach($reqs as $r): $thread=$msgsByReq[(int)$r['id']]??[]; ?><div class="soft-panel">
     <div class="flex justify-between"><b>#<?php echo tr_num($r['id'],'fa'); ?> — <?php echo clean($r['topic']); ?></b><span class="badge badge-info"><?php echo clean($statusFa[$r['status']] ?? $r['status']); ?></span></div>
     <p class="text-xs text-muted">ولی: <?php echo clean($r['requester_name']); ?> · <?php echo tr_num($r['created_at_jalali'],'fa'); ?></p>
     <p class="text-sm"><?php echo nl2br(clean($r['description'])); ?></p>
     <?php /* v4.170.0: گفتگوی حبابی — یکدست با تب درخواست‌ها */ ?>
     <?php if($thread): ?><div class="cns-thread mt-2"><?php foreach($thread as $m): $isC=$m['sender']==='counselor'; ?><div class="cns-bubble-row <?php echo $isC?'counselor':'parent'; ?>"><div class="cns-bubble <?php echo $isC?'counselor':'parent'; ?>"><?php echo nl2br(clean($m['body'])); ?><span class="cns-meta"><?php echo $isC?'مشاور':'ولی'; ?> · <?php echo tr_num($m['created_at_jalali'],'fa'); ?></span></div></div><?php endforeach; ?></div>
     <?php elseif($r['counselor_reply']): ?><div class="p-2 bg-green-50 rounded border text-xs mt-2"><b>پاسخ:</b> <?php echo nl2br(clean($r['counselor_reply'])); ?></div><?php endif; ?>
   </div><?php endforeach; if(!$reqs): ?><p class="text-center text-muted text-sm">درخواست مشاوره‌ای برای این دانش‌آموز ثبت نشده است.</p><?php endif; ?></div>
  </section>
 </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
