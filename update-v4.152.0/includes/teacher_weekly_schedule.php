<?php
// Read-only projection of the selected year's actual schedule; retain every half/week-alternate entry.
$weekDays=['شنبه','یک‌شنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه'];
$dayKey=function($s){return str_replace(["\u{200C}",' ','ي','ك'],['','','ی','ک'],trim((string)$s));};
$week=[];
foreach(DB::fetchAll('SELECT * FROM class_schedules WHERE teacher_id=? AND academic_year=? ORDER BY id',[$teacherId,$teacherYear]) as $lesson){
    $period=(int)preg_replace('/[^0-9]/','',tr_num((string)$lesson['period_num'],'en'));
    $week[$dayKey($lesson['day_of_week'])][$period][]=$lesson;
}
?>
<div class="card shadow-lg">
<h3 class="font-bold mb-4"><svg data-ui-icon="calendar" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 11h18M8 15h2m4 0h2m-8 3h2"/></svg> جدول برنامه هفتگی تدریس شما</h3>
<div class="table-container"><table id="teacherWeeklySchedule">
<thead><tr><th>روز هفته</th><th>زنگ اول</th><th>زنگ دوم</th><th>زنگ سوم</th><th>زنگ چهارم</th></tr></thead><tbody>
<?php foreach($weekDays as $day): ?><tr data-day="<?php echo clean($day); ?>">
<td class="font-bold bg-slate-100 dark:bg-slate-800"><?php echo clean($day); ?></td>
<?php for($period=1;$period<=4;$period++): $lessons=$week[$dayKey($day)][$period]??[]; ?>
<td class="text-center" data-period="<?php echo $period; ?>">
<?php foreach($lessons as $lesson): ?><div class="teacher-week-lesson mb-2">
<span class="badge badge-info block mb-1"><?php echo clean($lesson['class_name']); ?></span>
<span class="font-bold text-xs block"><?php echo clean($lesson['subject_name']); ?></span>
</div><?php endforeach; if(!$lessons): ?><span class="text-muted text-xs">---</span><?php endif; ?>
</td><?php endfor; ?></tr><?php endforeach; ?></tbody></table></div>
<p class="text-xs text-muted">سال <?php echo clean($teacherYear); ?> — همهٔ درس‌های ثبت‌شدهٔ هر زنگ، از جمله برنامه‌های هفته‌درمیان، نمایش داده می‌شوند.</p>
</div>
