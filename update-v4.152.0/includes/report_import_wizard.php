<?php
/** Restored Wizard's safe execution layer. Never writes student identities or queues pretend jobs. */
require_once __DIR__.'/functions.php';
require_once __DIR__.'/academic_year_helpers.php';
require_once __DIR__.'/importer.php';

function ri_text($value, $limit = 255) {
    if (!is_scalar($value) && $value !== null) throw new InvalidArgumentException('ساختار اطلاعات نامعتبر است.');
    $value = trim((string)$value);
    if (!preg_match('//u',$value) || preg_match_all('/./us',$value) > $limit) throw new InvalidArgumentException('طول یکی از فیلدها بیش از حد مجاز است.');
    return $value;
}
function ri_number($raw) {
    $value = str_replace(['/', '٫'], '.', tr_num(ri_text($raw, 30), 'en'));
    if (!preg_match('/^\d+(?:\.\d+)?$/D', $value)) throw new InvalidArgumentException('نمره یا ضریب عدد معتبر نیست؛ خانهٔ خالی نمرهٔ صفر نیست.');
    return (float)$value;
}
function ri_year($raw) {
    $raw = tr_num(ri_text($raw, 30), 'en');
    $year = unify_academic_year($raw);
    if (!$year) throw new InvalidArgumentException('سال تحصیلی معتبر انتخاب کنید.');
    $master = get_master_academic_years_list();
    if ($master && !in_array($year, $master, true)) throw new InvalidArgumentException('سال تحصیلی در تنظیمات سال‌ها وجود ندارد.');
    return $year;
}
function ri_xml($xml) {
    if (!is_string($xml) || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) throw new InvalidArgumentException('ساختار XML فایل مجاز نیست.');
    $old = libxml_use_internal_errors(true);
    try { $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
    if ($doc === false) throw new InvalidArgumentException('فایل Excel قابل خواندن نیست.');
    return $doc;
}
function ri_xlsx_rows($path) {
    if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string')) throw new InvalidArgumentException('برای XLSX افزونه‌های ZIP و SimpleXML لازم است؛ فایل را از Excel به CSV UTF-8 ذخیره کنید.');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new InvalidArgumentException('فایل XLSX معتبر نیست.');
    try {
        $total = 0;
        if ($zip->numFiles > 2000) throw new InvalidArgumentException('ساختار Excel بیش از حد بزرگ است.');
        for ($i=0; $i<$zip->numFiles; $i++) {
            $stat=$zip->statIndex($i); $total += $stat['size'];
            if ($total > 40*1024*1024) throw new InvalidArgumentException('حجم بازشدهٔ Excel بیش از ۴۰ مگابایت است.');
        }
        $wb=ri_xml($zip->getFromName('xl/workbook.xml'));
        $sheets=$wb->xpath('//*[local-name()="sheet"]');
        if (count($sheets)!==1) throw new InvalidArgumentException('فقط یک برگهٔ داده نگه دارید و فایل Excel را دوباره ذخیره کنید.');
        $attrs=$sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid=(string)$attrs['id']; $target='';
        $rels=ri_xml($zip->getFromName('xl/_rels/workbook.xml.rels'));
        foreach ($rels->xpath('//*[local-name()="Relationship"]') as $rel) if ((string)$rel['Id']===$rid && (string)$rel['TargetMode']!=='External') $target=(string)$rel['Target'];
        $target=ltrim($target,'/'); if (strpos($target,'xl/')!==0) $target='xl/'.$target;
        if (!preg_match('~^xl/worksheets/[A-Za-z0-9_.-]+\.xml$~D',$target)) throw new InvalidArgumentException('مسیر برگهٔ Excel معتبر نیست.');
        $strings=[]; $shared=$zip->getFromName('xl/sharedStrings.xml');
        if ($shared!==false) foreach (ri_xml($shared)->xpath('//*[local-name()="si"]') as $si) {
            $text=''; foreach ($si->xpath('.//*[local-name()="t"]') as $t) $text.=(string)$t; $strings[]=$text;
        }
        $out=[];
        foreach (ri_xml($zip->getFromName($target))->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
            $cells=[];
            foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
                if ($cell->xpath('./*[local-name()="f"]')) throw new InvalidArgumentException('سلول فرمول‌دار را به مقدار تبدیل کنید یا CSV بگیرید.');
                if (!preg_match('/^([A-Z]+)[0-9]+$/D',(string)$cell['r'],$m)) throw new InvalidArgumentException('نشانی سلول نامعتبر است.');
                $index=0; foreach (str_split($m[1]) as $c) $index=$index*26+ord($c)-64;
                if ($index>64) throw new InvalidArgumentException('حداکثر ۶۴ ستون مجاز است.');
                $values=$cell->xpath('./*[local-name()="v"]'); $v=$values?(string)$values[0]:'';
                if ((string)$cell['t']==='s') $v=$strings[(int)$v]??'';
                elseif ((string)$cell['t']==='inlineStr') { $v=''; foreach ($cell->xpath('.//*[local-name()="t"]') as $t) $v.=(string)$t; }
                elseif ((string)$cell['t']==='e') throw new InvalidArgumentException('فایل دارای سلول خطای Excel است.');
                $cells[$index-1]=$v;
            }
            if ($cells) { $line=array_fill(0,max(array_keys($cells))+1,''); foreach ($cells as $i=>$v) $line[$i]=$v; $out[]=$line; }
            if (count($out)>5001) throw new InvalidArgumentException('حداکثر ۵۰۰۰ ردیف داده مجاز است؛ فایل را تقسیم کنید.');
        }
        return $out;
    } finally { $zip->close(); }
}
function ri_parse_file($path, $extension) {
    if (!is_file($path) || filesize($path)>10*1024*1024) throw new InvalidArgumentException('حداکثر حجم فایل ۱۰ مگابایت است.');
    if (!in_array($extension,['csv','xlsx'],true)) throw new InvalidArgumentException('فقط CSV یا XLSX مجاز است؛ XLS قدیمی را به یکی از این قالب‌ها تبدیل کنید.');
    $lines=[];
    if ($extension==='xlsx') $lines=ri_xlsx_rows($path);
    else {
        $content=file_get_contents($path);
        if (strpos($content,"\0")!==false || !preg_match('//u',$content)) throw new InvalidArgumentException('فایل باید CSV متنی با کدگذاری UTF-8 باشد.');
        $f=fopen($path,'rb');
        try { while (($r=fgetcsv($f,0,',','"',''))!==false) { if (count($r)>64 || count($lines)>10000) throw new InvalidArgumentException('فایل بیش از حد بزرگ است.'); $lines[]=$r; } }
        finally { fclose($f); }
    }
    if (!$lines) throw new InvalidArgumentException('فایل خالی است.');
    // The original two-column school Report.csv format, with raw scores validated BEFORE legacy casting.
    $block=false; foreach (array_slice($lines,0,30) as $line) if (strpos(implode(' ',$line),'نام و نام خانوادگی:')!==false) $block=true;
    if ($block) {
        foreach ($lines as &$line) {
            foreach ($line as &$v) $v=tr_num((string)$v,'en'); unset($v);
            foreach ([[10,9,6],[25,24,21]] as $cols) {
                $subject=trim($line[$cols[0]]??'');
                if ($subject!=='' && $subject!=='نام درس' && strpos($subject,'معدل')===false && strpos($subject,'مــعدل')===false) {
                    // Only grade rows: other block headers contain these labels in the same columns.
                    if (strpos(implode(' ',$line),'نام و نام خانوادگی:')!==false || strpos(implode(' ',$line),'کلاس:')!==false || strpos(implode(' ',$line),'سال تحصیلی:')!==false) continue;
                    ri_number($line[$cols[1]]??'');
                    if (trim($line[$cols[2]]??'')!=='') ri_number($line[$cols[2]]);
                }
            }
        } unset($line);
        $temp=tempnam(sys_get_temp_dir(),'ri-');
        try {
            $f=fopen($temp,'wb'); foreach ($lines as $line) fputcsv($f,$line,',','"',''); fclose($f);
            $rows=parse_any_import_file($temp);
        } finally { if (is_file($temp)) unlink($temp); }
    } else {
        $headers=array_map(function($v){return trim(str_replace("\xEF\xBB\xBF",'',(string)$v));},array_shift($lines));
        if (count($headers)!==count(array_unique($headers))) throw new InvalidArgumentException('ستون تکراری در سرستون فایل وجود دارد.');
        foreach (['subject_name','score','academic_year','term','report_month'] as $name) if (!in_array($name,$headers,true)) throw new InvalidArgumentException('ستون الزامی یافت نشد: '.$name);
        if (!in_array('national_id',$headers,true) && !(in_array('first_name',$headers,true)&&in_array('last_name',$headers,true)&&in_array('class_name',$headers,true))) throw new InvalidArgumentException('کد ملی یا نام، نام خانوادگی و کلاس لازم است.');
        $rows=[];
        foreach ($lines as $i=>$line) {
            if (trim(implode('',$line))==='') continue;
            if (count($line)!==count($headers)) throw new InvalidArgumentException('تعداد ستون‌های ردیف '.($i+2).' با سرستون یکسان نیست.');
            $rows[]=array_combine($headers,$line);
        }
    }
    if (!$rows || count($rows)>5000) throw new InvalidArgumentException('فایل باید بین ۱ تا ۵۰۰۰ ردیف نمره داشته باشد.');
    return $rows;
}
function ri_prepare($rows, $targetYear='') {
    $targetYear=$targetYear!==''?ri_year($targetYear):'';
    $students=DB::fetchAll('SELECT id,national_id,first_name,last_name,class_name FROM students');
    $byNid=[]; $byName=[];
    foreach ($students as $s) {
        $byNid[tr_num(trim($s['national_id']),'en')][]=$s;
        $key=norm_persian_str($s['first_name'].' '.$s['last_name']).'|'.norm_class_str($s['class_name']); $byName[$key][]=$s;
    }
    $prepared=[]; $errors=[]; $seen=[];
    foreach ($rows as $i=>$r) {
        try {
            $nid=tr_num(ri_text($r['national_id']??'',30),'en');
            $name=ri_text($r['full_name']??(($r['first_name']??'').' '.($r['last_name']??'')));
            $class=norm_class_str(ri_text($r['class_name']??'',100));
            $matches=($nid!==''&&strpos($nid,'TEMP-')!==0)?($byNid[$nid]??[]):($byName[norm_persian_str($name).'|'.$class]??[]);
            if (count($matches)!==1) throw new InvalidArgumentException('دانش‌آموز به‌طور یکتا شناسایی نشد؛ کد ملی یا نام و کلاس را اصلاح کنید. دانش‌آموز جدید ساخته نمی‌شود.');
            $student=$matches[0];
            $r['academic_year']=$targetYear!==''?$targetYear:ri_year($r['academic_year']??'');
            foreach (['term'=>50,'report_month'=>50,'subject_name'=>150] as $field=>$limit) {
                $r[$field]=ri_text($r[$field]??'',$limit); if ($r[$field]==='') throw new InvalidArgumentException('نوبت، ماه و نام درس نباید خالی باشند.');
            }
            $r['score']=ri_number($r['score']??''); $r['max_score']=ri_number($r['max_score']??20); $r['coefficient']=ri_number($r['coefficient']??1);
            if ($r['max_score']<=0 || $r['max_score']>20 || ($r['score']>$r['max_score'] && $r['score']!=21) || $r['coefficient']<=0 || $r['coefficient']>100) throw new InvalidArgumentException('نمره خارج از بازه یا ضریب نامعتبر است؛ عدد ۲۱ علامت غیبت است.');
            $r['_student_id']=(int)$student['id']; $r['class_name']=$class!==''?$class:$student['class_name'];
            $r['first_name']=$student['first_name']; $r['last_name']=$student['last_name']; $r['national_id']=$student['national_id'];
            $key=json_encode([$r['_student_id'],$r['academic_year'],$r['term'],$r['report_month'],$r['subject_name']],JSON_UNESCAPED_UNICODE);
            if (isset($seen[$key])) throw new InvalidArgumentException('درس برای همین دانش‌آموز و دوره در فایل تکرار شده است.');
            $seen[$key]=true; $prepared[]=$r;
        } catch (InvalidArgumentException $e) { $errors[]=['row_index'=>$i,'message'=>$e->getMessage(),'data'=>['first_name'=>ri_text($r['first_name']??''),'last_name'=>ri_text($r['last_name']??'')]]; }
    }
    return ['rows'=>$prepared,'errors'=>$errors];
}
function ri_execute($sessionId, $adminId, $targetYear) {
    $pdo=DB::getInstance()->getPdo();
    if (!$pdo) throw new RuntimeException('Database unavailable');
    $pdo->beginTransaction();
    try {
        // Atomic claim: duplicate requests cannot both write. MySQL locking and SQLite transactions supported.
        $claim=DB::query("UPDATE import_sessions SET status='resolving' WHERE id=? AND admin_id=? AND status='preview'",[$sessionId,$adminId]);
        if ($claim->rowCount()!==1) throw new InvalidArgumentException('نشست معتبرِ در انتظار تأیید یافت نشد؛ ممکن است قبلاً اجرا شده باشد.');
        $session=DB::fetch('SELECT * FROM import_sessions WHERE id=? AND admin_id=?',[$sessionId,$adminId]);
        $raw=json_decode($session['preview_data'],true);
        if (!is_array($raw) || !$raw || count($raw)!==(int)$session['total_rows']) throw new InvalidArgumentException('این نشست قدیمی یا ناقص است؛ فایل را دوباره بارگذاری کنید.');
        $check=ri_prepare($raw,$targetYear);
        if ($check['errors']) throw new InvalidArgumentException('فایل ابهام دارد؛ هیچ نمره‌ای ثبت نشد. نخست اطلاعات فایل را اصلاح و دوباره بارگذاری کنید.');
        $reports=[]; $count=0;
        foreach ($check['rows'] as $r) {
            $key=json_encode([$r['_student_id'],$r['academic_year'],$r['term'],$r['report_month']]);
            if (!isset($reports[$key])) {
                $existing=DB::fetchAll('SELECT id,is_locked FROM reports WHERE student_id=? AND academic_year=? AND term=? AND report_month=?',[$r['_student_id'],$r['academic_year'],$r['term'],$r['report_month']]);
                if (count($existing)>1) throw new InvalidArgumentException('برای یک دانش‌آموز چند کارنامهٔ هم‌دوره وجود دارد؛ ابتدا تعارض را در کارنامه‌ها رفع کنید.');
                if ($existing && $existing[0]['is_locked']) throw new InvalidArgumentException('کارنامهٔ مقصد قفل است؛ هیچ نمره‌ای ثبت نشد.');
                if ($existing) $id=$existing[0]['id'];
                else {
                    DB::execute('INSERT INTO reports (student_id,class_name,academic_year,term,report_month,total_score,gpa) VALUES (?,?,?,?,?,0,0)',[$r['_student_id'],$r['class_name'],$r['academic_year'],$r['term'],$r['report_month']]); $id=DB::lastInsertId();
                }
                $reports[$key]=$id;
            }
            $id=$reports[$key];
            // Replace only the named subject; preserve every other grade and student/profile field.
            DB::execute('DELETE FROM report_grades WHERE report_id=? AND subject_name=?',[$id,$r['subject_name']]);
            DB::execute('INSERT INTO report_grades (report_id,subject_name,score,max_score,coefficient,status) VALUES (?,?,?,?,?,?)',[$id,$r['subject_name'],$r['score'],$r['max_score'],$r['coefficient'],$r['score']==21?'none':($r['score']>=10?'passed':'failed')]); $count++;
        }
        foreach ($reports as $id) {
            $grades=DB::fetchAll('SELECT * FROM report_grades WHERE report_id=?',[$id]);
            $calc=extract_clean_grades_and_discipline($grades,null);
            DB::execute('UPDATE reports SET total_score=?,gpa=?,discipline_score=COALESCE(?,discipline_score) WHERE id=?',[$calc['calculated_gpa']*$calc['count'],$calc['calculated_gpa'],$calc['discipline_score'],$id]);
        }
        DB::execute("UPDATE import_sessions SET status='completed',processed_rows=? WHERE id=?",[$count,$sessionId]);
        log_activity($adminId,'اجرای ایمپورت کارنامه','نشست '.$sessionId.'؛ تعداد نمره: '.$count);
        $pdo->commit(); return $count;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
