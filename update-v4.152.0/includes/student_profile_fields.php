<?php
/** Explicit public profile field registry: never export credentials or internal tokens. */
function student_profile_groups() {
    return [
        'identity'=>['اطلاعات هویتی',[
            'first_name'=>'نام','last_name'=>'نام خانوادگی','national_id'=>'کد ملی','student_code'=>'کد دانش‌آموزی',
            'serial_number'=>'شماره شش‌رقمی سریال','serial_letter'=>'حرف سریال','serial_suffix'=>'عدد دورقمی سریال',
            'gender'=>'جنسیت','birth_date'=>'تاریخ تولد','birth_year'=>'سال تولد','birth_month'=>'ماه تولد','birth_day'=>'روز تولد',
            'birth_place'=>'محل تولد','place_issued'=>'محل صدور','religion_title'=>'دین','nationality_title'=>'ملیت','is_foreign'=>'اتباع']],
        'family'=>['والدین و سرپرستی',[
            'father_name'=>'نام پدر','father_birth_date'=>'تاریخ تولد پدر','father_qualification'=>'تحصیلات پدر','father_job'=>'شغل پدر',
            'mother_name'=>'نام مادر (ثبت‌شده)','mother_first_name'=>'نام کوچک مادر','mother_last_name'=>'نام خانوادگی مادر',
            'mother_birth_date'=>'تاریخ تولد مادر','mother_qualification'=>'تحصیلات مادر','mother_job'=>'شغل مادر',
            'father_deceased'=>'پدر فوت شده','mother_deceased'=>'مادر فوت شده','father_guardian'=>'سرپرست پدر','mother_guardian'=>'سرپرست مادر']],
        'contact'=>['اطلاعات تماس و سکونت',[
            'phone'=>'شماره تماس اصلی','father_phone'=>'موبایل پدر','mother_phone'=>'موبایل مادر','student_mobile'=>'موبایل دانش‌آموز',
            'home_phone'=>'تلفن منزل','postal_code'=>'کد پستی','home_address'=>'نشانی منزل','housing_title'=>'وضعیت مسکن']],
        'health'=>['سلامت و پوشش',[
            'cover_title'=>'پوشش / بیمه','specific_disease_title'=>'بیماری خاص','has_sport_limitation'=>'محدودیت ورزش','sport_limitation_desc'=>'شرح محدودیت ورزش']],
        'education'=>['اطلاعات تحصیلی',[
            'academic_year'=>'سال تحصیلی','grade_level'=>'پایه','class_name'=>'کلاس','last_year_average'=>'معدل سال قبل','status'=>'وضعیت فعالیت']],
        'academic'=>['کارنامه و نمرات',['latest_gpa'=>'آخرین معدل','latest_report'=>'آخرین کارنامه','failed_grades'=>'نمرات / نمرات زیر ۱۰']],
        'discipline'=>['انضباط',['discipline_count'=>'تعداد موارد انضباطی','discipline_details'=>'موارد انضباطی']]
    ];
}
function student_ascii_digits($s) {
    return strtr(trim((string)$s),array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩',-1,PREG_SPLIT_NO_EMPTY),str_split('01234567890123456789')));
}
/** Read legacy permutations without writing/migrating the database. Preserve unknown values. */
function student_serial_parts($raw) {
    $text=preg_replace('/[\x{200e}\x{200f}\x{202a}-\x{202e}\x{2066}-\x{2069}]/u','',student_ascii_digits($raw));
    $out=['number'=>'','letter'=>'','suffix'=>'','recognized'=>false,'raw'=>(string)$raw];
    if($text===''){$out['recognized']=true;return $out;}
    if(preg_match('/^\d{6}$/D',$text)){$out['number']=$text;$out['recognized']=true;return $out;}
    $tokens=preg_split('~[\s/\\\\-]+~u',$text,-1,PREG_SPLIT_NO_EMPTY);
    if(count($tokens)!==3)return $out;
    foreach($tokens as $v){
        if(preg_match('/^\d{6}$/D',$v)&&$out['number']==='')$out['number']=$v;
        elseif(preg_match('/^\d{2}$/D',$v)&&$out['suffix']==='')$out['suffix']=$v;
        elseif(preg_match('/^\p{L}$/uD',$v)&&$out['letter']==='')$out['letter']=$v;
        else return ['number'=>'','letter'=>'','suffix'=>'','recognized'=>false,'raw'=>(string)$raw];
    }
    $out['recognized']=$out['number']!==''&&$out['letter']!==''&&$out['suffix']!=='';return $out;
}
function student_record_serial($row) {
    if(trim($row['serial_number']??'')!=='')return $row['serial_number'];
    $code=$row['student_code']??'';$parts=student_serial_parts($code);
    return $parts['recognized']&&$parts['letter']!==''?$code:'';
}
function student_serial_from_form($post,$previous='') {
    if(!array_key_exists('serial_letter',$post)&&!array_key_exists('serial_suffix',$post))return trim($post['serial_number']??''); // existing clients
    $n=student_ascii_digits($post['serial_number']??'');$l=trim($post['serial_letter']??'');$s=student_ascii_digits($post['serial_suffix']??'');
    $old=student_serial_parts($previous);
    if($n===''&&$l===''&&$s==='')return $old['recognized']?'':$previous;
    if(!preg_match('/^\d{6}$/D',$n)||($l!==''&&!preg_match('/^\p{L}$/uD',$l))||($s!==''&&!preg_match('/^\d{2}$/D',$s))||(($l==='')!==($s==='')))
        throw new InvalidArgumentException('سریال باید شش رقم باشد؛ حرف و عدد دورقمی را با هم تکمیل کنید.');
    if($old['recognized']&&$n===$old['number']&&$l===$old['letter']&&$s===$old['suffix'])return $previous;
    return $l===''?$n:$l.'/'.$s.'/'.$n;
}
function student_report_columns($requested) {
    $groups=student_profile_groups();$all=[];foreach($groups as $g)$all+=$g[1];
    $wanted=[];foreach((array)$requested as $key){if(!is_string($key))continue;if(isset($groups[$key]))$wanted=array_merge($wanted,array_keys($groups[$key][1]));elseif(isset($all[$key]))$wanted[]=$key;}
    return array_intersect_key($all,array_flip($wanted)); // fixed safe order, not SQL identifiers supplied by a client
}
function student_profile_value($s,$key) {
    if(in_array($key,['serial_number','serial_letter','serial_suffix'],true)){
        $p=student_serial_parts(student_record_serial($s));if(!$p['recognized'])return $key==='serial_number'?$p['raw']:'';
        return $p[['serial_number'=>'number','serial_letter'=>'letter','serial_suffix'=>'suffix'][$key]];
    }
    $v=$s[$key]??'';
    if(in_array($key,['is_foreign','has_sport_limitation','father_deceased','mother_deceased','father_guardian','mother_guardian'],true))return $v?'بله':'خیر';
    if($key==='status')return ['active'=>'فعال','inactive'=>'غیرفعال'][$v]??$v;
    if($key==='gender')return ['male'=>'پسر','female'=>'دختر'][$v]??$v;
    return (string)$v;
}
