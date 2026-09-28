<?php
// File: counselor-panel.php
/**
 * Counselor panel: requests, replies, and student academic/discipline overview.
 *
 * v4.169.0:
 *  - فهرست دانش‌آموزان فیلتر سال تحصیلی دارد (پیش‌فرض: سال جاری انتخاب‌شده).
 *  - دکمهٔ «یادداشت مشاور» (پروندهٔ خصوصی مشاور) پیش از سایر دکمه‌ها.
 *  - هر درخواست مشاوره: برچسب نام دانش‌آموز + عکس شناسنامه‌ای.
 *  - پاسخ مشاور از طریق ربات برای ولی ارسال می‌شود (صف اعلان‌ها) با دکمهٔ
 *    «پاسخ»؛ مکالمهٔ ولی و مشاور در همان درخواست ادامه می‌یابد و تاریخچهٔ
 *    کامل پیام‌ها روی کارت درخواست نمایش داده می‌شود.
 *  - لینک‌های خروجی، آدرس فعلیِ با فیلتر را در back= نگه می‌دارند.
 *
 * v4.170.0:
 *  - لیست دانش‌آموزان در موبایل/تبلت کارت‌به‌کارت و تک‌ستونی می‌شود
 *    (بدون ردیف دوسطری) — assets/css/counselor-panel.css.
 *  - درخواست‌ها: فیلتر سال تحصیلی + وضعیت + پایه؛ هر درخواست جمع‌شونده
 *    است (کلیک → باز می‌شود) و پیام‌های ولی/مشاور مثل پیام‌رسان حبابی
 *    نمایش داده می‌شوند. درخواست «جدید» از قبل باز است.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/school_roles.php';
require_once __DIR__ . '/includes/bot_helpers.php';
require_once __DIR__ . '/includes/academic_year_helpers.php';
ensure_school_roles_schema();
ensure_counseling_schema();
if (empty($_SESSION['teacher_id']) || !teacher_has_counselor($_SESSION['teacher_id'])) redirect('admin-login.php?tab=teacher');
$teacherId=(int)$_SESSION['teacher_id'];

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['reply_request'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { set_flash_message('error','خطای CSRF'); redirect('counselor-panel.php'); }
    $id=(int)$_POST['request_id']; $reply=trim($_POST['reply']??''); $status=$_POST['status']??'replied';
    if (!in_array($status,['new','in_progress','replied','closed'],true)) $status='replied';
    DB::execute("UPDATE counseling_requests SET counselor_id=?, counselor_reply=?, status=?, replied_at_jalali=? WHERE id=?", [$teacherId,$reply,$status,jalali_now(),$id]);
    /* v4.169.0: پاسخ هم در تاریخچهٔ مکالمه ثبت می‌شود و هم از طریق صف
       اعلان‌های ربات (bot_send_message → bot_outbox) برای ولی می‌رود، با
       دکمهٔ «پاسخ» تا مکالمه در همان درخواست ادامه یابد. */
    $reqRow = DB::fetch("SELECT platform, chat_id FROM counseling_requests WHERE id=?", [$id]);
    if ($reply !== '') {
        DB::execute("INSERT INTO counseling_messages (request_id, sender, sender_id, body, created_at_jalali) VALUES (?, 'counselor', ?, ?, ?)", [$id, $teacherId, $reply, jalali_now()]);
    }
    if ($reqRow && in_array($reqRow['platform'], ['bale','telegram'], true) && trim((string)$reqRow['chat_id']) !== '') {
        $msgBody = "🧭 پاسخ مشاور به درخواست #" . tr_num($id,'fa') . ":\n" . ($reply !== '' ? $reply : '(بدون متن — وضعیت: ' . $status . ')') . "\n\nبرای ادامهٔ گفتگو دکمهٔ «پاسخ» را بزنید.";
        $kbReply = ['inline_keyboard' => [[['text' => '💬 پاسخ', 'callback_data' => 'counselreply_' . $id]]]];
        try { bot_send_message($reqRow['platform'], $reqRow['chat_id'], $msgBody, $kbReply); } catch (Throwable $e) {}
    }
    set_flash_message('success','پاسخ/وضعیت درخواست مشاوره ذخیره شد.' . ($reply !== '' && $reqRow && in_array($reqRow['platform'], ['bale','telegram'], true) ? ' پیام پاسخ در صف ارسال ربات قرار گرفت.' : ''));
    redirect('counselor-panel.php?tab=requests');
}

$pageCss = 'assets/css/counselor-panel.css?v=4.170.0';
require_once __DIR__ . '/includes/header.php';
$tab=$_GET['tab']??'requests'; $q=trim($_GET['q']??''); $class=trim($_GET['class']??'');
/* v4.169.0: فیلتر سال تحصیلی — وقتی بدون پارامتر وارد لیست می‌شوید، سال
   تحصیلی پیش‌فرض از قبل انتخاب‌شده است. گزینهٔ «همهٔ سال‌ها» هم هست. */
$yearF=trim($_GET['year']??'');
$defaultYear=get_setting('current_academic_year','1404/1405');
$yearExplicit = isset($_GET['year']) && $_GET['year'] !== '';
if (!$yearExplicit) $yearF=$defaultYear;
$yearOptions = function_exists('get_academic_years_for_filter') ? get_academic_years_for_filter() : [];
/* v4.170.0: فیلترهای تب درخواست‌ها — وضعیت و پایه */
$stF=trim($_GET['rstatus']??'');
$grF=trim($_GET['grade']??'');

$reqs=DB::fetchAll("SELECT cr.*, s.first_name, s.last_name, s.national_id FROM counseling_requests cr LEFT JOIN students s ON s.id=cr.student_id ORDER BY cr.id DESC LIMIT 300");
/* v4.169.0: دانش‌آموز هر درخواست (برچسب + عکس) و تاریخچهٔ مکالمه — دسته‌ای */
$reqStudents = counseling_resolve_students($reqs);
$msgsByReq = [];
if ($reqs) {
    $rids=[]; foreach ($reqs as $r) $rids[]=(int)$r['id'];
    $marks=implode(',', array_fill(0,count($rids),'?'));
    try {
        $allMsgs = DB::fetchAll("SELECT * FROM counseling_messages WHERE request_id IN ($marks) ORDER BY id ASC", $rids);
        foreach ($allMsgs as $m) $msgsByReq[(int)$m['request_id']][] = $m;
    } catch (Throwable $e) {}
}
/* v4.170.0: سال تحصیلیِ هر درخواست از تاریخ شمسی‌اش استخراج می‌شود:
   ماه‌های ۰۶..۱۲ → y/(y+1) و ماه‌های ۰۱..۰۵ → (y-1)/y. */
$cns_req_year = function($jal){
    $y=(int)substr((string)$jal,0,4); $m=(int)substr((string)$jal,5,2);
    if($y<1300 || $m<1 || $m>12) return '';
    return $m>=6 ? $y.'/'.($y+1) : ($y-1).'/'.$y;
};
$gradeOptions=[];
try { $gradeOptions = DB::fetchAll("SELECT DISTINCT grade_level FROM students WHERE status='active' AND grade_level<>'' ORDER BY grade_level"); } catch (Throwable $e) {}
$reqsFiltered=[];
foreach($reqs as $r){
    if($stF!=='' && ($r['status']??'')!==$stF) continue;
    $rs=$reqStudents[(int)$r['id']]??null;
    if($grF!=='' && (!$rs || (string)($rs['grade_level']??'')!==$grF)) continue;
    if($yearF!=='' && $yearF!=='all'){ $ry=$cns_req_year($r['created_at_jalali']??''); if($ry!=='' && $ry!==$yearF) continue; }
    $reqsFiltered[]=$r;
}
$where=["status='active'"]; $params=[];
if($q!==''){ $where[]="(first_name LIKE ? OR last_name LIKE ? OR national_id LIKE ?)"; $params[]="%$q%"; $params[]="%$q%"; $params[]="%$q%";}
if($class!==''){ $where[]="class_name=?"; $params[]=$class;}
if($yearF!=='' && $yearF!=='all'){ $where[]="academic_year=?"; $params[]=$yearF;}
$students=DB::fetchAll("SELECT * FROM students WHERE ".implode(' AND ',$where)." ORDER BY class_name,last_name LIMIT 500",$params);
persian_usort_by($students, ['class_name','last_name','first_name']);   // v4.77.0: آ قبل از ا
$classes=DB::fetchAll("SELECT DISTINCT class_name FROM students WHERE class_name<>'' ORDER BY class_name");
/* v4.169.0: بازگشت با فیلتر — آدرس کامل همین صفحه در back= پیوست می‌شود */
$backUrl='counselor-panel.php?'.http_build_query(['tab'=>'students','q'=>$q,'class'=>$class,'year'=>$yearF]);
$backEnc=urlencode($backUrl);
$statusFa=['new'=>'جدید','in_progress'=>'درحال پیگیری','replied'=>'پاسخ داده شد','closed'=>'بسته شد'];
$cns_year_select = function($name,$yearF,$yearOptions){
    ob_start();
    ?><select name="<?php echo $name; ?>" class="form-select"><?php if($yearF==='all'): ?><option value="all" selected>همهٔ سال‌ها</option><?php else: ?><option value="all">همهٔ سال‌ها</option><?php endif; ?><?php foreach($yearOptions as $yo): ?><option value="<?php echo clean($yo['academic_year']); ?>" <?php echo $yearF===$yo['academic_year']?'selected':''; ?>><?php echo clean($yo['academic_year']); ?><?php echo !empty($yo['is_default'])?' - پیش‌فرض':''; ?></option><?php endforeach; ?><?php if($yearF!=='' && $yearF!=='all'): ?><?php $found=false; foreach($yearOptions as $yo){ if($yo['academic_year']===$yearF) $found=true; } ?><?php if(!$found): ?><option value="<?php echo clean($yearF); ?>" selected><?php echo clean($yearF); ?></option><?php endif; ?><?php endif; ?></select><?php
    return ob_get_clean();
};
?>
<div class="space-y-6">
 <div class="page-hero flex justify-between items-center flex-wrap gap-2"><div><h2 class="text-2xl font-bold"><svg data-ui-icon="location" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 22S4 13 4 9a8 8 0 0 1 16 0c0 4-8 13-8 13Z"/><circle cx="12" cy="9" r="3"/></svg> پنل مشاور مدرسه</h2><p class="text-sm text-muted">مدیریت درخواست‌های مشاوره و دسترسی به پرونده تحصیلی/انضباطی دانش‌آموزان</p></div><div class="flex gap-2 flex-wrap"><a class="btn <?php echo $tab==='requests'?'btn-primary':'btn-outline'; ?>" href="counselor-panel.php?tab=requests">درخواست‌ها</a><a class="btn <?php echo $tab==='students'?'btn-primary':'btn-outline'; ?>" href="counselor-panel.php?tab=students">دانش‌آموزان</a><a class="btn btn-outline" href="teacher-panel.php">پنل دبیر</a></div></div>
 <?php if($tab==='students'): ?>
 <div class="card"><form method="GET" class="cns-filters"><input type="hidden" name="tab" value="students"><div class="cns-grow"><label class="text-xs">جستجو</label><input name="q" class="form-input" value="<?php echo clean($q); ?>"></div><div><label class="text-xs">کلاس</label><select name="class" class="form-select"><option value="">همه</option><?php foreach($classes as $c): ?><option value="<?php echo clean($c['class_name']); ?>" <?php echo $class===$c['class_name']?'selected':''; ?>><?php echo clean($c['class_name']); ?></option><?php endforeach; ?></select></div><div><label class="text-xs">سال تحصیلی</label><?php echo $cns_year_select('year',$yearF,$yearOptions); ?></div><button class="btn btn-primary">فیلتر</button></form></div>
 <?php /* v4.170.0: data-label + کلاس cns-students — در موبایل هر ردیف کارت
        تک‌ستونی می‌شود و دکمه‌ها دوتا-دوتا کنار هم می‌نشینند. */ ?>
 <div class="card"><div class="table-container"><table class="cns-students"><thead><tr><th>نام</th><th>کد ملی</th><th>کلاس</th><th>دسترسی</th></tr></thead><tbody><?php foreach($students as $s): ?><tr><td data-label="نام" data-role="name"><?php echo clean($s['first_name'].' '.$s['last_name']); ?></td><td data-label="کد ملی"><?php echo tr_num($s['national_id'],'fa'); ?></td><td data-label="کلاس"><?php echo clean($s['class_name']); ?></td><td data-label="دسترسی" data-role="actions"><div class="cns-actions"><?php /* v4.169.0: «یادداشت مشاور» پیش از همهٔ دکمه‌ها — پروندهٔ خصوصی مشاور */ ?><a class="btn btn-accent text-xs" href="counselor-file.php?id=<?php echo $s['id']; ?>&back=<?php echo $backEnc; ?>">یادداشت مشاور</a><a class="btn btn-outline text-xs" href="staff-student-file.php?tab=info&id=<?php echo $s['id']; ?>&back=<?php echo $backEnc; ?>">اطلاعات</a><a class="btn btn-primary text-xs" href="staff-student-file.php?tab=reports&id=<?php echo $s['id']; ?>&back=<?php echo $backEnc; ?>">کارنامه‌ها</a><a class="btn btn-warning text-xs" href="staff-student-file.php?tab=discipline&id=<?php echo $s['id']; ?>&back=<?php echo $backEnc; ?>">موارد انضباطی</a></div></td></tr><?php endforeach; ?></tbody></table></div></div>
 <?php else: ?>
 <?php /* v4.170.0: فیلترهای درخواست‌ها — سال تحصیلی، وضعیت، پایه */ ?>
 <div class="card"><form method="GET" class="cns-filters"><input type="hidden" name="tab" value="requests">
   <div><label class="text-xs">سال تحصیلی</label><?php echo $cns_year_select('year',$yearF,$yearOptions); ?></div>
   <div><label class="text-xs">وضعیت درخواست</label><select name="rstatus" class="form-select"><option value="">همه</option><?php foreach($statusFa as $k=>$v): ?><option value="<?php echo clean($k); ?>" <?php echo $stF===$k?'selected':''; ?>><?php echo clean($v); ?></option><?php endforeach; ?></select></div>
   <div><label class="text-xs">پایه</label><select name="grade" class="form-select"><option value="">همه</option><?php foreach($gradeOptions as $g): ?><option value="<?php echo clean($g['grade_level']); ?>" <?php echo $grF===$g['grade_level']?'selected':''; ?>><?php echo clean($g['grade_level']); ?></option><?php endforeach; ?></select></div>
   <button class="btn btn-primary">فیلتر</button>
 </form></div>
 <div class="card"><div class="flex justify-between items-center flex-wrap gap-2 mb-3"><h3 class="font-bold">درخواست‌های مشاوره</h3><span class="text-xs text-muted"><?php echo tr_num(count($reqsFiltered),'fa'); ?> درخواست — برای دیدن گفتگو روی هر درخواست بزنید</span></div><div>
 <?php foreach($reqsFiltered as $r): $rs=$reqStudents[(int)$r['id']]??null; $thread=$msgsByReq[(int)$r['id']]??[]; ?>
 <?php /* v4.170.0: کارت جمع‌شونده — خلاصه: عکس + نام + موضوع + وضعیت؛
        باز شده: کل گفتگوی حبابی + فرم پاسخ. «جدید» از قبل باز است. */ ?>
 <details class="cns-req" <?php echo ($r['status']??'')==='new'?'open':''; ?>>
   <summary>
     <div class="cns-photo"><?php if($rs && !empty($rs['photo_url'])): ?><img src="<?php echo clean($rs['photo_url']); ?>" alt=""><?php else: ?><span style="font-size:20px">👤</span><?php endif; ?></div>
     <div class="cns-req-main">
       <b>#<?php echo tr_num($r['id'],'fa'); ?> — <?php echo clean($r['topic']); ?></b>
       <div class="cns-sub"><?php if($rs): ?><?php echo clean(trim($rs['first_name'].' '.$rs['last_name'])); ?><?php echo !empty($rs['class_name'])?' — '.clean($rs['class_name']):''; ?><?php else: ?><?php echo clean($r['student_name'] ?: (($r['first_name']??'').' '.($r['last_name']??'')) ?: 'دانش‌آموز نامشخص'); ?><?php endif; ?> · <?php echo clean($r['requester_name']); ?> · <?php echo tr_num($r['created_at_jalali'],'fa'); ?></div>
     </div>
     <span class="badge cns-st-<?php echo clean($r['status']); ?>"><?php echo clean($statusFa[$r['status']] ?? $r['status']); ?></span>
     <svg class="cns-chev" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="6 9 12 15 18 9"/></svg>
   </summary>
   <div class="cns-req-body">
     <?php if($rs): ?><div class="mb-2"><a class="badge badge-info" style="text-decoration:none" href="counselor-file.php?id=<?php echo (int)$rs['id']; ?>&back=<?php echo urlencode('counselor-panel.php?tab=requests'); ?>">پروندهٔ مشاورهٔ <?php echo clean(trim($rs['first_name'].' '.$rs['last_name'])); ?></a></div><?php endif; ?>
     <p class="text-xs text-muted">درخواست‌دهنده: <?php echo clean($r['requester_name']); ?> | تلفن: <?php echo clean($r['requester_phone']); ?> | <?php echo tr_num($r['created_at_jalali'],'fa'); ?></p>
     <?php /* v4.170.0: متن درخواست + همهٔ پیام‌ها به‌شکل حباب — ولی چپ، مشاور راست */ ?>
     <div class="cns-thread">
       <div class="cns-bubble-row parent"><div class="cns-bubble parent"><?php echo nl2br(clean($r['description'])); ?><span class="cns-meta">درخواست اولیهٔ ولی · <?php echo tr_num($r['created_at_jalali'],'fa'); ?></span></div></div>
       <?php foreach($thread as $m): $isC=$m['sender']==='counselor'; ?>
       <div class="cns-bubble-row <?php echo $isC?'counselor':'parent'; ?>"><div class="cns-bubble <?php echo $isC?'counselor':'parent'; ?>"><?php echo nl2br(clean($m['body'])); ?><span class="cns-meta"><?php echo $isC?'مشاور':'ولی'; ?> · <?php echo tr_num($m['created_at_jalali'],'fa'); ?></span></div></div>
       <?php endforeach; ?>
       <?php if(!$thread && $r['counselor_reply']): ?>
       <div class="cns-bubble-row counselor"><div class="cns-bubble counselor"><?php echo nl2br(clean($r['counselor_reply'])); ?><span class="cns-meta">مشاور<?php echo $r['replied_at_jalali']?' · '.tr_num($r['replied_at_jalali'],'fa'):''; ?></span></div></div>
       <?php endif; ?>
     </div>
     <form method="POST" class="grid grid-cols-3 gap-3 mt-3"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="reply_request" value="1"><input type="hidden" name="request_id" value="<?php echo $r['id']; ?>"><select name="status" class="form-select"><option value="in_progress" <?php echo $r['status']==='in_progress'?'selected':''; ?>>درحال پیگیری</option><option value="replied" <?php echo $r['status']==='replied'?'selected':''; ?>>پاسخ داده شد</option><option value="closed" <?php echo $r['status']==='closed'?'selected':''; ?>>بسته شد</option></select><input name="reply" class="form-input col-span-2" placeholder="پاسخ مشاور... (برای ولی در ربات ارسال می‌شود)"><button class="btn btn-success">ارسال پاسخ</button></form>
   </div>
 </details>
 <?php endforeach; if(!$reqsFiltered): ?><p class="text-center text-muted"><?php echo $reqs?'درخواستی با این فیلترها پیدا نشد.':'درخواستی ثبت نشده است.'; ?></p><?php endif; ?>
 </div></div>
 <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
