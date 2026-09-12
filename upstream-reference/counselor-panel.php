<?php
// File: counselor-panel.php
/**
 * Counselor panel: requests, replies, and student academic/discipline overview.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/school_roles.php';
ensure_school_roles_schema();
if (empty($_SESSION['teacher_id']) || !teacher_has_counselor($_SESSION['teacher_id'])) redirect('admin-login.php?tab=teacher');
$teacherId=(int)$_SESSION['teacher_id'];

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['reply_request'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { set_flash_message('error','خطای CSRF'); redirect('counselor-panel.php'); }
    $id=(int)$_POST['request_id']; $reply=trim($_POST['reply']??''); $status=$_POST['status']??'replied';
    if (!in_array($status,['new','in_progress','replied','closed'],true)) $status='replied';
    DB::execute("UPDATE counseling_requests SET counselor_id=?, counselor_reply=?, status=?, replied_at_jalali=? WHERE id=?", [$teacherId,$reply,$status,jalali_now(),$id]);
    set_flash_message('success','پاسخ/وضعیت درخواست مشاوره ذخیره شد.'); redirect('counselor-panel.php');
}

require_once __DIR__ . '/includes/header.php';
$tab=$_GET['tab']??'requests'; $q=trim($_GET['q']??''); $class=trim($_GET['class']??'');
$reqs=DB::fetchAll("SELECT cr.*, s.first_name, s.last_name, s.national_id FROM counseling_requests cr LEFT JOIN students s ON s.id=cr.student_id ORDER BY cr.id DESC LIMIT 300");
$where=["status='active'"]; $params=[]; if($q!==''){ $where[]="(first_name LIKE ? OR last_name LIKE ? OR national_id LIKE ?)"; $params[]="%$q%"; $params[]="%$q%"; $params[]="%$q%";} if($class!==''){ $where[]="class_name=?"; $params[]=$class;}
$students=DB::fetchAll("SELECT * FROM students WHERE ".implode(' AND ',$where)." ORDER BY class_name,last_name LIMIT 500",$params);
$classes=DB::fetchAll("SELECT DISTINCT class_name FROM students WHERE class_name<>'' ORDER BY class_name");
?>
<div class="space-y-6">
 <div class="page-hero flex justify-between items-center"><div><h2 class="text-2xl font-bold">🧭 پنل مشاور مدرسه</h2><p class="text-sm text-muted">مدیریت درخواست‌های مشاوره و دسترسی به پرونده تحصیلی/انضباطی دانش‌آموزان</p></div><div class="flex gap-2"><a class="btn <?php echo $tab==='requests'?'btn-primary':'btn-outline'; ?>" href="counselor-panel.php?tab=requests">درخواست‌ها</a><a class="btn <?php echo $tab==='students'?'btn-primary':'btn-outline'; ?>" href="counselor-panel.php?tab=students">دانش‌آموزان</a><a class="btn btn-outline" href="teacher-panel.php">پنل دبیر</a></div></div>
 <?php if($tab==='students'): ?>
 <div class="card"><form method="GET" class="flex gap-3 items-end"><input type="hidden" name="tab" value="students"><div class="flex-1"><label class="text-xs">جستجو</label><input name="q" class="form-input" value="<?php echo clean($q); ?>"></div><div><label class="text-xs">کلاس</label><select name="class" class="form-select"><option value="">همه</option><?php foreach($classes as $c): ?><option value="<?php echo clean($c['class_name']); ?>" <?php echo $class===$c['class_name']?'selected':''; ?>><?php echo clean($c['class_name']); ?></option><?php endforeach; ?></select></div><button class="btn btn-primary">فیلتر</button></form></div>
 <div class="card"><div class="table-container"><table><thead><tr><th>نام</th><th>کد ملی</th><th>کلاس</th><th>دسترسی</th></tr></thead><tbody><?php foreach($students as $s): ?><tr><td class="font-bold"><?php echo clean($s['first_name'].' '.$s['last_name']); ?></td><td><?php echo tr_num($s['national_id'],'fa'); ?></td><td><?php echo clean($s['class_name']); ?></td><td><a class="btn btn-outline text-xs" href="staff-student-file.php?tab=info&id=<?php echo $s['id']; ?>">اطلاعات</a><a class="btn btn-primary text-xs" href="staff-student-file.php?tab=reports&id=<?php echo $s['id']; ?>">کارنامه‌ها</a><a class="btn btn-warning text-xs" href="staff-student-file.php?tab=discipline&id=<?php echo $s['id']; ?>">موارد انضباطی</a></td></tr><?php endforeach; ?></tbody></table></div></div>
 <?php else: ?>
 <div class="card"><h3 class="font-bold mb-3">درخواست‌های مشاوره</h3><div class="space-y-4"><?php foreach($reqs as $r): ?><div class="soft-panel"><div class="flex justify-between"><b>#<?php echo tr_num($r['id'],'fa'); ?> - <?php echo clean($r['topic']); ?></b><span class="badge badge-info"><?php echo clean($r['status']); ?></span></div><p class="text-sm">درخواست‌دهنده: <?php echo clean($r['requester_name']); ?> | تلفن: <?php echo clean($r['requester_phone']); ?> | دانش‌آموز: <?php echo clean($r['student_name'] ?: (($r['first_name']??'').' '.($r['last_name']??''))); ?> | <?php echo tr_num($r['created_at_jalali'],'fa'); ?></p><p><?php echo nl2br(clean($r['description'])); ?></p><?php if($r['counselor_reply']): ?><div class="p-3 bg-green-50 rounded border"><b>پاسخ:</b> <?php echo nl2br(clean($r['counselor_reply'])); ?></div><?php endif; ?><form method="POST" class="grid grid-cols-3 gap-3 mt-3"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="reply_request" value="1"><input type="hidden" name="request_id" value="<?php echo $r['id']; ?>"><select name="status" class="form-select"><option value="in_progress">درحال پیگیری</option><option value="replied">پاسخ داده شد</option><option value="closed">بسته شد</option></select><input name="reply" class="form-input col-span-2" placeholder="پاسخ مشاور..." value="<?php echo clean($r['counselor_reply']); ?>"><button class="btn btn-success">ذخیره پاسخ</button></form></div><?php endforeach; if(!$reqs): ?><p class="text-center text-muted">درخواستی ثبت نشده است.</p><?php endif; ?></div></div>
 <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
