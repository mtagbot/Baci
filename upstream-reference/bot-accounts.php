<?php
// File: bot-accounts.php
/**
 * Admin management of Bale/Telegram accounts linked to student profiles.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bot_helpers.php';
require_permission('manage_students');
ensure_bot_schema('bale'); ensure_bot_schema('telegram');

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_link'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { set_flash_message('error','خطای CSRF'); redirect('bot-accounts.php'); }
    $platform = bot_valid_platform($_POST['platform'] ?? 'bale');
    $table=bot_user_table($platform);
    DB::execute("DELETE FROM `$table` WHERE id=?", [(int)$_POST['link_id']]);
    set_flash_message('success','اتصال ربات حذف شد.'); redirect('bot-accounts.php');
}
require_once __DIR__ . '/includes/header.php';
$q=trim($_GET['q']??''); $class=trim($_GET['class']??''); $platform=($_GET['platform']??'all');
$classes=DB::fetchAll("SELECT DISTINCT class_name FROM students WHERE class_name<>'' ORDER BY class_name");
function bot_accounts_rows($platform,$q,$class){
    $table=bot_user_table($platform); $chatCol=$platform==='telegram'?'telegram_chat_id':'bale_chat_id'; $userCol=$platform==='telegram'?'telegram_username':'bale_username';
    $where=['1=1']; $params=[];
    if($q!==''){ $where[]="(s.first_name LIKE ? OR s.last_name LIKE ? OR s.national_id LIKE ? OR b.`$chatCol` LIKE ? OR b.`$userCol` LIKE ?)"; for($i=0;$i<5;$i++) $params[]="%$q%"; }
    if($class!==''){ $where[]='s.class_name=?'; $params[]=$class; }
    $sql="SELECT b.id,b.`$chatCol` chat_id,b.`$userCol` username,b.created_at,s.first_name,s.last_name,s.national_id,s.class_name,s.grade_level FROM `$table` b JOIN students s ON s.id=b.student_id WHERE ".implode(' AND ',$where)." ORDER BY b.id DESC";
    $rows=DB::fetchAll($sql,$params); foreach($rows as &$r)$r['platform']=$platform; return $rows;
}
$rows=[]; if($platform==='all'||$platform==='bale') $rows=array_merge($rows,bot_accounts_rows('bale',$q,$class)); if($platform==='all'||$platform==='telegram') $rows=array_merge($rows,bot_accounts_rows('telegram',$q,$class));
?>
<div class="space-y-6"><div class="page-hero"><h2 class="text-2xl font-bold">🔗 مدیریت اکانت‌های متصل ربات‌ها</h2><p class="text-sm text-muted">مشاهده، فیلتر و حذف اتصال‌های بله و تلگرام به حساب‌های دانش‌آموزی</p></div>
<div class="card"><form method="GET" class="grid grid-cols-4 gap-3 items-end"><div><label class="text-xs">پیام‌رسان</label><select name="platform" class="form-select"><option value="all">همه</option><option value="bale" <?php echo $platform==='bale'?'selected':''; ?>>بله</option><option value="telegram" <?php echo $platform==='telegram'?'selected':''; ?>>تلگرام</option></select></div><div><label class="text-xs">کلاس</label><select name="class" class="form-select"><option value="">همه</option><?php foreach($classes as $c): ?><option value="<?php echo clean($c['class_name']); ?>" <?php echo $class===$c['class_name']?'selected':''; ?>><?php echo clean($c['class_name']); ?></option><?php endforeach; ?></select></div><div><label class="text-xs">جستجو</label><input name="q" class="form-input" value="<?php echo clean($q); ?>" placeholder="نام، کد ملی، چت‌آیدی..."></div><button class="btn btn-primary">فیلتر</button></form></div>
<div class="card"><div class="table-container"><table><thead><tr><th>پیام‌رسان</th><th>Chat ID</th><th>Username</th><th>دانش‌آموز</th><th>کد ملی</th><th>پایه/کلاس</th><th>تاریخ اتصال</th><th>حذف</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><span class="badge badge-info"><?php echo $r['platform']==='telegram'?'تلگرام':'بله'; ?></span></td><td class="dir-ltr font-mono text-xs"><?php echo clean($r['chat_id']); ?></td><td class="dir-ltr font-mono text-xs"><?php echo clean($r['username']); ?></td><td class="font-bold"><?php echo clean($r['first_name'].' '.$r['last_name']); ?></td><td><?php echo tr_num($r['national_id'],'fa'); ?></td><td><?php echo clean($r['grade_level'].' / '.$r['class_name']); ?></td><td><?php echo clean($r['created_at']); ?></td><td><form method="POST" onsubmit="return confirm('این اتصال حذف شود؟')"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="delete_link" value="1"><input type="hidden" name="platform" value="<?php echo clean($r['platform']); ?>"><input type="hidden" name="link_id" value="<?php echo (int)$r['id']; ?>"><button class="btn btn-danger text-xs">حذف</button></form></td></tr><?php endforeach; if(!$rows): ?><tr><td colspan="8" class="text-center text-muted">رکوردی یافت نشد.</td></tr><?php endif; ?></tbody></table></div></div></div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
