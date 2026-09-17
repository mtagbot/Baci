<?php
// Existing storage columns and cascading class data remain the source of truth.
$st=$editData??[];
$sp=student_serial_parts(student_record_serial($st));
if($submittedStudent!==null){$sp=['number'=>$st['serial_number']??'','letter'=>$st['serial_letter']??'','suffix'=>$st['serial_suffix']??'','recognized'=>true];}
function st_input($key,$label,$st,$attrs='') {
    echo '<div class="student-field"><label for="st-'.$key.'">'.clean($label).'</label><input id="st-'.$key.'" name="'.$key.'" class="form-input" value="'.clean($st[$key]??'').'" '.$attrs.'></div>';
}
function st_check($key,$label,$st) {
    echo '<label class="student-check"><input type="checkbox" name="'.$key.'" value="1" '.(!empty($st[$key])?'checked':'').'>'.clean($label).'</label>';
}
function st_date($prefix,$label,$st) {
    $parts=explode('/',student_ascii_digits($st[$prefix.'_date']??''));
    echo '<div class="student-field"><span id="'.$prefix.'-label">'.clean($label).'</span><div class="student-date" role="group" aria-labelledby="'.$prefix.'-label" dir="ltr">';
    foreach(['year'=>['سال',4,1300,1500],'month'=>['ماه',2,1,12],'day'=>['روز',2,1,31]] as $part=>$spec){
        $k=$prefix.'_'.$part;$val=$st[$k]??($parts[array_search($part,['year','month','day'])]??'');if($val===0||$val==='0')$val='';
        echo '<input class="form-input" type="text" name="'.$k.'" aria-label="'.clean($label.' — '.$spec[0]).'" placeholder="'.$spec[0].'" inputmode="numeric" data-digits pattern="[0-9۰-۹٠-٩]{'.($part==='year'?'4':'1,2').'}" data-auto-length="'.$spec[1].'" data-min="'.$spec[2].'" data-max="'.$spec[3].'" maxlength="'.$spec[1].'" value="'.clean($val??'').'">';
    }
    echo '</div></div>';
}
?>
<link rel="stylesheet" href="assets/css/student-workflow.css?v=20260917c">
<script defer src="assets/js/student-workflow.js?v=20260917c"></script>
<div class="student-editor card">
 <div class="student-editor-title"><div><p class="text-muted">پروندهٔ دانش‌آموز</p><h1><?php echo $action==='edit'?'ویرایش اطلاعات دانش‌آموز':'ثبت دانش‌آموز جدید'; ?></h1></div></div>
 <p id="student-entry-help">فیلدهای ستاره‌دار الزامی‌اند. Tab: بعدی، Shift+Tab: قبلی، Enter در ورودی متنی: بعدی. ذخیره فقط با دکمهٔ ذخیره انجام می‌شود.</p>
 <label class="student-check"><input id="student-auto-focus" type="checkbox" checked> انتقال خودکار پس از تکمیل فیلدهای عددی با طول ثابت و حرف سریال</label>
 <nav class="student-section-nav" aria-label="بخش‌های پرونده"><a href="#st-identity">هویت</a><a href="#st-education">تحصیل</a><a href="#st-parents">والدین</a><a href="#st-contact">تماس و نشانی</a><a href="#st-health">سلامت</a></nav>
 <?php if($formErrors): ?><div class="alert alert-danger" role="alert" tabindex="-1" id="student-errors"><strong>اطلاعات ذخیره نشد؛ موارد زیر را اصلاح کنید:</strong><ul><?php foreach($formErrors as $error): ?><li><?php echo clean($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
 <form id="student-profile-form" method="POST" action="students.php?id=<?php echo $studentId; ?>" aria-describedby="student-entry-help">
 <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="save_student" value="1">
 <fieldset id="st-identity"><legend>۱. مشخصات هویتی</legend><div class="student-fields">
 <?php st_input('first_name','نام *',$st,'required autocomplete="given-name"');st_input('last_name','نام خانوادگی *',$st,'required autocomplete="family-name"');
 st_input('national_id','کد ملی *',$st,'required inputmode="numeric" data-digits data-auto-length="10" maxlength="20" dir="ltr"');
 st_input('student_code','کد دانش‌آموزی (مستقل از سریال)',$st,'dir="ltr"'); ?>
 <div class="student-field student-wide"><span id="serial-label">سریال شناسنامه — به ترتیب شماره، حرف، عدد دورقمی</span><div class="student-serial" role="group" aria-labelledby="serial-label">
 <?php st_input('serial_number','شماره (۶ رقم)',['serial_number'=>$sp['number']],'inputmode="numeric" data-digits data-auto-length="6" maxlength="6" pattern="[0-9۰-۹٠-٩]{6}" dir="ltr" placeholder="356750"');
 st_input('serial_letter','حرف',['serial_letter'=>$sp['letter']],'data-auto-length="1" maxlength="1" placeholder="ب"');
 st_input('serial_suffix','عدد (۲ رقم)',['serial_suffix'=>$sp['suffix']],'inputmode="numeric" data-digits data-auto-length="2" maxlength="2" pattern="[0-9۰-۹٠-٩]{2}" dir="ltr" placeholder="35"'); ?>
 </div><?php if(!$sp['recognized']): ?><p class="student-legacy">مقدار قدیمی: <bdi><?php echo clean($sp['raw']); ?></bdi> — قالب آن تشخیص داده نشد؛ تا ورود سریال جدید، عین مقدار قبلی حفظ می‌شود.</p><?php endif; ?></div>
 <?php st_date('birth','تاریخ تولد دانش‌آموز',$st);st_input('birth_place','محل تولد',$st);st_input('place_issued','محل صدور',$st);st_input('religion_title','دین',$st);st_input('nationality_title','ملیت',$st+['nationality_title'=>'ایرانی']); ?>
 <div class="student-field"><label for="st-gender">جنسیت</label><select id="st-gender" name="gender" class="form-select"><option value="">انتخاب کنید</option><?php foreach(['male'=>'پسر','female'=>'دختر'] as $v=>$t): ?><option value="<?php echo $v; ?>" <?php echo ($st['gender']??'')===$v?'selected':''; ?>><?php echo $t; ?></option><?php endforeach; ?></select></div>
 <?php st_check('is_foreign','دانش‌آموز اتباع',$st); ?>
 </div></fieldset>
 <fieldset id="st-education"><legend>۲. وضعیت تحصیلی</legend><div class="student-fields">
 <div class="student-field"><label for="stYearSelect">سال تحصیلی *</label><select name="academic_year" id="stYearSelect" class="form-select" onchange="refreshStGrades()" required><?php foreach($formYearOptions as $yo): ?><option value="<?php echo clean($yo['academic_year']); ?>" <?php echo $formSelectedYear===$yo['academic_year']?'selected':''; ?>><?php echo clean($yo['academic_year']); ?></option><?php endforeach; ?></select></div>
 <div class="student-field"><label for="stGradeSelect">پایه *</label><select name="grade_level" id="stGradeSelect" class="form-select" onchange="refreshStClasses()" required><option value="<?php echo clean($formSelectedGrade); ?>"><?php echo clean($formSelectedGrade?:'انتخاب پایه'); ?></option></select></div>
 <div class="student-field"><label for="stClassSelect">کلاس *</label><select name="class_name" id="stClassSelect" class="form-select" required><option value="<?php echo clean($formSelectedClass); ?>"><?php echo clean($formSelectedClass?:'انتخاب کلاس'); ?></option></select></div>
 <?php st_input('last_year_average','معدل سال قبل',$st,'inputmode="decimal"'); ?>
 <div class="student-field"><label for="st-status">وضعیت فعالیت</label><select id="st-status" name="status" class="form-select"><option value="active">فعال (در حال تحصیل)</option><option value="inactive" <?php echo ($st['status']??'')==='inactive'?'selected':''; ?>>غیرفعال / فارغ‌التحصیل</option></select></div>
 </div><p class="text-muted">کلاس‌ها از سال و پایهٔ انتخاب‌شده می‌آیند؛ تعریف کلاس در بخش مدیریت دروس / کلاس‌ها انجام می‌شود.</p></fieldset>
 <fieldset id="st-parents"><legend>۳. والدین و سرپرستی</legend><div class="student-fields">
 <?php st_input('father_name','نام پدر',$st);st_date('father_birth','تاریخ تولد پدر',$st);st_input('father_qualification','تحصیلات پدر',$st);st_input('father_job','شغل پدر',$st);st_check('father_deceased','پدر فوت شده',$st);st_check('father_guardian','سرپرست پدر',$st); ?>
 </div><hr><div class="student-fields"><?php st_input('mother_name','نام مادر',$st);st_input('mother_last_name','نام خانوادگی مادر',$st);st_date('mother_birth','تاریخ تولد مادر',$st);st_input('mother_qualification','تحصیلات مادر',$st);st_input('mother_job','شغل مادر',$st);st_check('mother_deceased','مادر فوت شده',$st);st_check('mother_guardian','سرپرست مادر',$st); ?></div></fieldset>
 <fieldset id="st-contact"><legend>۴. تماس و محل سکونت</legend><div class="student-fields">
 <?php foreach(['phone'=>'شماره تماس اصلی','father_phone'=>'موبایل پدر','mother_phone'=>'موبایل مادر','student_mobile'=>'موبایل دانش‌آموز','home_phone'=>'تلفن منزل'] as $k=>$t)st_input($k,$t,$st,'type="tel" inputmode="tel" data-digits dir="ltr" maxlength="20"'.(in_array($k,['father_phone','mother_phone','student_mobile'],true)?' data-auto-length="11" data-local-mobile':''));
 st_input('postal_code','کد پستی (۱۰ رقم)',$st,'inputmode="numeric" data-digits data-auto-length="10" maxlength="10" pattern="[0-9۰-۹٠-٩]{10}" dir="ltr"');st_input('housing_title','وضعیت مسکن',$st); ?>
 <div class="student-field student-wide"><label for="st-home_address">نشانی کامل منزل</label><textarea id="st-home_address" name="home_address" class="form-textarea" rows="3" autocomplete="street-address"><?php echo clean($st['home_address']??''); ?></textarea></div>
 </div></fieldset>
 <fieldset id="st-health"><legend>۵. سلامت و پوشش</legend><div class="student-fields">
 <?php st_input('cover_title','پوشش / بیمه',$st);st_check('has_sport_limitation','محدودیت ورزش',$st); ?>
 <div class="student-field"><label for="st-disease">بیماری خاص</label><textarea id="st-disease" name="specific_disease_title" class="form-textarea" rows="3"><?php echo clean($st['specific_disease_title']??''); ?></textarea></div>
 <div class="student-field"><label for="st-sport">شرح محدودیت ورزش</label><textarea id="st-sport" name="sport_limitation_desc" class="form-textarea" rows="3"><?php echo clean($st['sport_limitation_desc']??''); ?></textarea></div>
 </div></fieldset>
 <div class="student-save-bar"><a class="btn btn-secondary" href="students.php">انصراف و بازگشت</a><button type="submit" class="btn btn-success">ذخیره اطلاعات دانش‌آموز</button></div>
 </form>
</div>
