<?php
// File: report-broadcast.php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/academic_year_helpers.php';
require_once __DIR__ . '/includes/bot_helpers.php';
require_permission('manage_reports');
ensure_bot_schema('bale');
ensure_bot_schema('telegram');

function broadcast_report_text($reportId) {
    $report = DB::fetch("SELECT r.*, s.first_name, s.last_name FROM reports r JOIN students s ON s.id=r.student_id WHERE r.id=?", [$reportId]);
    if (!$report) return '';
    $grades = DB::fetchAll("SELECT subject_name, score FROM report_grades WHERE report_id=? ORDER BY id", [$reportId]);
    $txt = "🎓 کارنامه " . trim($report['first_name'].' '.$report['last_name']) . "\n";
    $txt .= "📅 " . $report['term'] . ' - ' . $report['report_month'] . "\n⭐ معدل: " . format_score($report['gpa']) . "\n";
    if ($report['discipline_score'] !== null && $report['discipline_score'] !== '') $txt .= "✨ انضباط: " . format_score($report['discipline_score']) . "\n";
    foreach ($grades as $g) $txt .= "\n▪️ " . $g['subject_name'] . ': ' . display_score($g['score']);
    return $txt;
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if(!verify_csrf($_POST['csrf_token']??'')){set_flash_message('error','CSRF');redirect('report-broadcast.php');}
    $year=trim($_POST['academic_year']??'');
    $month=trim($_POST['report_month']??'');
    $platforms=$_POST['platforms']??[];
    if (!$platforms) { set_flash_message('error','حداقل یک ربات را انتخاب کنید.'); redirect('report-broadcast.php'); }
    $reports=DB::fetchAll("SELECT r.*,s.first_name,s.last_name FROM reports r JOIN students s ON s.id=r.student_id WHERE r.academic_year=? AND r.report_month=? AND r.is_locked=0 ORDER BY s.class_name,s.last_name",[$year,$month]);
    $sent=0; $failed=0; $noLink=0; $attempts=0;
    foreach($reports as $r){
        foreach($platforms as $pf){
            $pf=bot_valid_platform($pf); $table=bot_user_table($pf); $chatCol=$pf==='telegram'?'telegram_chat_id':'bale_chat_id';
            $links=DB::fetchAll("SELECT DISTINCT `$chatCol` chat_id FROM `$table` WHERE student_id=?",[$r['student_id']]);
            if (!$links) { $noLink++; continue; }
            foreach($links as $ln){
                $attempts++;
                try{
                    $txt = broadcast_report_text($r['id']);
                    if ($txt) bot_send_message($pf, $ln['chat_id'], $txt);
                    bot_send_report_image_to_chat($pf,$ln['chat_id'],$r['id']);
                    $sent++;
                } catch(Exception $e){
                    $failed++;
                    bot_log_send($pf, $ln['chat_id'] ?? '', $r['student_id'], 'report_broadcast', 'report_id='.$r['id'], 'failed', $e->getMessage());
                }
            }
        }
    }
    set_flash_message($failed?'warning':'success',"اعلام سراسری انجام شد. کارنامه‌ها: ".count($reports)."، تلاش ارسال: $attempts، موفق: $sent، ناموفق: $failed، بدون اتصال ربات: $noLink");
    redirect('report-broadcast.php');
}
require_once __DIR__ . '/includes/header.php';
$years = get_academic_years_for_filter();
$months=DB::fetchAll("SELECT DISTINCT report_month FROM reports WHERE report_month<>'' ORDER BY report_month DESC");
?>
<div class="card"><form method="POST" data-no-ajax="1" class="grid grid-cols-4 gap-3 items-end"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><div><label>سال تحصیلی</label><select name="academic_year" class="form-select"><?php foreach($years as $y):?><option value="<?php echo clean($y['academic_year']);?>"><?php echo clean($y['academic_year']);?></option><?php endforeach;?></select></div><div><label>ماه</label><select name="report_month" class="form-select"><?php foreach($months as $m):?><option value="<?php echo clean($m['report_month']);?>"><?php echo clean($m['report_month']);?></option><?php endforeach;?></select></div><label><input type="checkbox" name="platforms[]" value="bale" checked> بله</label><label><input type="checkbox" name="platforms[]" value="telegram" checked> تلگرام</label><button class="btn btn-success">ارسال کارنامه‌های ماه انتخابی</button></form><p class="text-xs text-muted mt-3">برای هر کارنامه ابتدا متن نمرات و سپس تصویر کارنامه همراه دکمه «بررسی شد» ارسال می‌شود.</p></div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
