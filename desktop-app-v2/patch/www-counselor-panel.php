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

require_once __DIR__ . '/includes/header.php';
$tab=$_GET['tab']??'requests'; $q=trim($_GET['q']??''); $class=trim($_GET['class']??'');
/* v4.169.0: فیلتر سال تحصیلی — وقتی بدون پارامتر وارد لیست می‌شوید، سال
   تحصیلی پیش‌فرض از قبل انتخاب‌شده است. گزینهٔ «همهٔ سال‌ها» هم هست. */
$yearF=trim($_GET['year']??'');
$defaultYear=get_setting('current_academic_year','1404/1405');
$yearExplicit = isset($_GET['year']) && $_GET['year'] !== '';
if (!$yearExplicit) $yearF=$defaultYear;
$yearOptions = function_exists('get_academic_years_for_filter') ? get_academic_years_for_filter() : [];

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
?>
<div class="space-y-6">
 <div class="page-hero flex justify-between items-center"><div><h2 class="text-2xl font-bold"><svg data-ui-icon="location" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 22S4 13 4 9a8 8 0 0 1 16 0c0 4-8 13-8 13Z"/><circle cx="12" cy="9" r="3"/></svg> پنل مشاور مدرسه</h2><p class="text-sm text-muted">مدیریت درخواست‌های مشاوره و دسترسی به پرونده تحصیلی/انضباطی دانش‌آموزان</p></div><div class="flex gap-2"><a class="btn <?php echo $tab==='requests'?'btn-primary':'btn-outline'; ?>" href="counselor-panel.php?tab=requests">درخواست‌ها</a><a class="btn <?php echo $tab==='students'?'btn-primary':'btn-outline'; ?>" href="counselor-panel.php?tab=students">دانش‌آموزان</a><a class="btn btn-outline" href="teacher-panel.php">پنل دبیر</a></div></div>
 <?php if($tab==='students'): ?>
 <div class="card"><form method="GET" class="flex gap-3 items-end flex-wrap"><input type="hidden" name="tab" value="students"><div class="flex-1"><label class="text-xs">جستجو</label><input name="q" class="form-input" value="<?php echo clean($q); ?>"></div><div><label class="text-xs">کلاس</label><select name="class" class="form-select"><option value="">همه</option><?php foreach($classes as $c): ?><option value="<?php echo clean($c['class_name']); ?>" <?php echo $class===$c['class_name']?'selected':''; ?>><?php echo clean($c['class_name']); ?></option><?php endforeach; ?></select></div><div><label class="text-xs">سال تحصیلی</label><select name="year" class="form-select"><?php if($yearF==='all'): ?><option value="all" selected>همهٔ سال‌ها</option><?php else: ?><option value="all">همهٔ سال‌ها</option><?php endif; ?><?php foreach($yearOptions as $yo): ?><option value="<?php echo clean($yo['academic_year']); ?>" <?php echo $yearF===$yo['academic_year']?'selected':''; ?>><?php echo clean($yo['academic_year']); ?><?php echo !empty($yo['is_default'])?' - پیش‌فرض':''; ?></option><?php endforeach; ?><?php if($yearF!=='' && $yearF!=='all'): ?><?php $found=false; foreach($yearOptions as $yo){ if($yo['academic_year']===$yearF) $found=true; } ?><?php if(!$found): ?><option value="<?php echo clean($yearF); ?>" selected><?php echo clean($yearF); ?></option><?php endif; ?><?php endif; ?></select></div><button class="btn btn-primary">فیلتر</button></form></div>
 <div class="card"><div class="table-container"><table><thead><tr><th>نام</th><th>کد ملی</th><th>کلاس</th><th>دسترسی</th></tr></thead><tbody><?php foreach($students as $s): ?><tr><td class="font-bold"><?php echo clean($s['first_name'].' '.$s['last_name']); ?></td><td><?php echo tr_num($s['national_id'],'fa'); ?></td><td><?php echo clean($s['class_name']); ?></td><td><div class="flex gap-1 flex-wrap"><?php /* v4.169.0: «یادداشت مشاور» پیش از همهٔ دکمه‌ها — پروندهٔ خصوصی مشاور */ ?><a class="btn btn-accent text-xs" href="counselor-file.php?id=<?php echo $s['id']; ?>&back=<?php echo $backEnc; ?>">یادداشت مشاور</a><a class="btn btn-outline text-xs" href="staff-student-file.php?tab=info&id=<?php echo $s['id']; ?>&back=<?php echo $backEnc; ?>">اطلاعات</a><a class="btn btn-primary text-xs" href="staff-student-file.php?tab=reports&id=<?php echo $s['id']; ?>&back=<?php echo $backEnc; ?>">کارنامه‌ها</a><a class="btn btn-warning text-xs" href="staff-student-file.php?tab=discipline&id=<?php echo $s['id']; ?>&back=<?php echo $backEnc; ?>">موارد انضباطی</a></div></td></tr><?php endforeach; ?></tbody></table></div></div>
 <?php else: ?>
 <div class="card"><h3 class="font-bold mb-3">درخواست‌های مشاوره</h3><div class="space-y-4"><?php foreach($reqs as $r): $rs=$reqStudents[(int)$r['id']]??null; $thread=$msgsByReq[(int)$r['id']]??[]; ?><div class="soft-panel">
   <?php /* v4.169.0: برچسب نام دانش‌آموز + عکس شناسنامه‌ای */ ?>
   <div class="flex justify-between items-start gap-3"><div class="flex items-center gap-3">
     <div style="width:44px;height:58px;border-radius:6px;border:1px solid #cbd5e1;overflow:hidden;background:#f1f5f9;display:flex;align-items:center;justify-content:center;flex-shrink:0"><?php if($rs && !empty($rs['photo_url'])): ?><img src="<?php echo clean($rs['photo_url']); ?>" alt="" style="width:100%;height:100%;object-fit:cover"><?php else: ?><span style="font-size:20px">👤</span><?php endif; ?></div>
     <div><b>#<?php echo tr_num($r['id'],'fa'); ?> - <?php echo clean($r['topic']); ?></b><br>
     <?php if($rs): ?><a class="badge badge-info" style="text-decoration:none" href="counselor-file.php?id=<?php echo (int)$rs['id']; ?>&back=<?php echo urlencode('counselor-panel.php?tab=requests'); ?>"><?php echo clean(trim($rs['first_name'].' '.$rs['last_name'])); ?><?php echo !empty($rs['class_name'])?' — '.clean($rs['class_name']):''; ?></a><?php else: ?><span class="badge badge-warning"><?php echo clean($r['student_name'] ?: (($r['first_name']??'').' '.($r['last_name']??'')) ?: 'دانش‌آموز نامشخص'); ?></span><?php endif; ?></div>
   </div><span class="badge badge-info"><?php echo clean($statusFa[$r['status']] ?? $r['status']); ?></span></div>
   <p class="text-sm">درخواست‌دهنده: <?php echo clean($r['requester_name']); ?> | تلفن: <?php echo clean($r['requester_phone']); ?> | <?php echo tr_num($r['created_at_jalali'],'fa'); ?></p><p><?php echo nl2br(clean($r['description'])); ?></p>
   <?php /* v4.169.0: تاریخچهٔ مکالمهٔ همین درخواست */ ?>
   <?php if($thread): ?><div class="space-y-2 mt-2" style="border-right:3px solid #c7d2fe;padding-right:10px"><b class="text-xs">تاریخچهٔ مکالمه:</b><?php foreach($thread as $m): ?><div class="text-xs p-2 rounded" style="background:<?php echo $m['sender']==='counselor'?'#f0fdf4':'#eff6ff'; ?>"><b><?php echo $m['sender']==='counselor'?'مشاور':'ولی'; ?></b> · <?php echo tr_num($m['created_at_jalali'],'fa'); ?><br><?php echo nl2br(clean($m['body'])); ?></div><?php endforeach; ?></div>
   <?php elseif($r['counselor_reply']): ?><div class="p-3 bg-green-50 rounded border"><b>پاسخ:</b> <?php echo nl2br(clean($r['counselor_reply'])); ?></div><?php endif; ?>
   <form method="POST" class="grid grid-cols-3 gap-3 mt-3"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="reply_request" value="1"><input type="hidden" name="request_id" value="<?php echo $r['id']; ?>"><select name="status" class="form-select"><option value="in_progress" <?php echo $r['status']==='in_progress'?'selected':''; ?>>درحال پیگیری</option><option value="replied" <?php echo $r['status']==='replied'?'selected':''; ?>>پاسخ داده شد</option><option value="closed" <?php echo $r['status']==='closed'?'selected':''; ?>>بسته شد</option></select><input name="reply" class="form-input col-span-2" placeholder="پاسخ مشاور... (برای ولی در ربات ارسال می‌شود)"><button class="btn btn-success">ارسال پاسخ</button></form>
 </div><?php endforeach; if(!$reqs): ?><p class="text-center text-muted">درخواستی ثبت نشده است.</p><?php endif; ?></div></div>
 <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
