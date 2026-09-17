<?php
/** Persistent class-exam groups. Originals remain intact; detach makes an independent snapshot. */
require_once __DIR__.'/class_exam_helpers.php';
function ceg_schema() {
    static $done=false; if($done)return;
    DB::execute("CREATE TABLE IF NOT EXISTS class_exam_groups (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, identity_key varchar(64) NOT NULL, teacher_id int NOT NULL, academic_year varchar(20) NOT NULL, grade_level varchar(50) NOT NULL, subject_name varchar(150) NOT NULL, design_exam_id int NOT NULL, source_exam_id int DEFAULT NULL, UNIQUE KEY unique_identity(identity_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    DB::execute("CREATE TABLE IF NOT EXISTS class_exam_group_members (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, group_id int NOT NULL, exam_id int NOT NULL, class_name varchar(100) NOT NULL, excluded tinyint NOT NULL DEFAULT 0, detached_exam_id int DEFAULT NULL, UNIQUE KEY unique_member(exam_id), KEY group_members(group_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done=true;
    // New desktop tables use the existing sync protocol's numeric primary keys and ID range.
    $pdo=DB::getInstance()->getPdo();
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') {
        foreach(['class_exam_groups','class_exam_group_members'] as $table) {
            $seq=DB::fetch('SELECT seq FROM sqlite_sequence WHERE name=?',[$table]);
            if(!$seq)DB::execute('INSERT INTO sqlite_sequence(name,seq) VALUES (?,?)',[$table,5000000]);
            elseif((int)$seq['seq']<5000000)DB::execute('UPDATE sqlite_sequence SET seq=? WHERE name=?',[5000000,$table]);
            if(DB::fetch("SELECT name FROM sqlite_master WHERE type='table' AND name='desk_change_log'") && DB::fetch("SELECT name FROM sqlite_master WHERE type='table' AND name='desk_sync_suppress'")) {
                foreach([['i','INSERT','NEW'],['u','UPDATE','NEW'],['d','DELETE','OLD']] as $a) {
                    $pdo->exec("CREATE TRIGGER IF NOT EXISTS trg_sync_{$table}_{$a[0]} AFTER {$a[1]} ON {$table} WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress) BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('{$table}',{$a[2]}.id,'".substr($a[1],0,1)."',strftime('%s','now')); END");
                }
            }
        }
    }
}
function ceg_key($teacher,$year,$grade,$subject) {return hash('sha256',json_encode([(int)$teacher,$year,trim($grade),trim($subject)],JSON_UNESCAPED_UNICODE));}
function ceg_find($teacher,$year,$grade,$subject) {return DB::fetch('SELECT * FROM class_exam_groups WHERE identity_key=?',[ceg_key($teacher,$year,$grade,$subject)]);}
function ceg_assignments($teacher,$year,$grade,$subject) {
    $out=[];
    foreach(class_exam_assignments($teacher,$year) as $as) if(trim($as['grade_level'])===trim($grade) && trim($as['subject_name'])===trim($subject)) $out[norm_class_str($as['class_name'])]=$as;
    return array_values($out);
}
function ceg_sources($teacher,$year,$grade,$subject) {
    $out=[];
    foreach(ceg_assignments($teacher,$year,$grade,$subject) as $as) foreach(class_exam_existing($teacher,$year,$as['class_name'],$subject) as $ex) $out[(int)$ex['id']]=$ex;
    return $out;
}
function ceg_sync_conflict($table,$row) {
    $key=$table==='class_exam_groups'?'identity_key':($table==='class_exam_group_members'?'exam_id':'');
    if($key==='' || !isset($row[$key],$row['id']))return false;
    $other=DB::fetch("SELECT id FROM $table WHERE $key=?",[$row[$key]]);
    return $other && (int)$other['id']!==(int)$row['id'];
}
function ceg_pending_sync($exam,$group) {return !$group && ($exam['exam_kind']??'')==='class' && ($exam['exam_month']??'')==='آزمون مشترک پایه' && ($exam['class_name']??'')==='';}
function ceg_for_exam($exam) {
    if(($exam['exam_kind']??'')!=='class')return null;
    $canonical=DB::fetch('SELECT *, 0 AS excluded, 0 AS member_exam_id FROM class_exam_groups WHERE design_exam_id=?',[(int)$exam['id']]);
    if($canonical)return $canonical;
    $rows=DB::fetchAll('SELECT g.*, m.exam_id AS member_exam_id, m.class_name AS member_class, m.excluded, m.detached_exam_id FROM class_exam_groups g JOIN class_exam_group_members m ON m.group_id=g.id JOIN exam_schedules gd ON gd.id=g.design_exam_id AND gd.exam_kind=\'class\' WHERE g.teacher_id=? AND g.academic_year=? AND g.subject_name=?',[$exam['teacher_id'],$exam['academic_year'],$exam['subject_name']]);
    // Exact persisted identities win over normalized legacy class aliases.
    foreach($rows as $r)if((int)$r['member_exam_id']===(int)$exam['id'])return $r;
    foreach($rows as $r)if((int)($r['detached_exam_id']??0)===(int)$exam['id'])return $r;
    foreach($rows as $r) if(norm_class_str($r['member_class'])===norm_class_str($exam['class_name']))return $r;
    return null;
}
function ceg_classes($group) {
    $allowed=[];foreach(ceg_assignments($group['teacher_id'],$group['academic_year'],$group['grade_level'],$group['subject_name']) as $a) $allowed[norm_class_str($a['class_name'])]=true;
    $classes=[];
    foreach(DB::fetchAll('SELECT class_name FROM class_exam_group_members WHERE group_id=? AND excluded=0 ORDER BY exam_id',[$group['id']]) as $m) if(isset($allowed[norm_class_str($m['class_name'])]))$classes[]=$m['class_name'];
    $norm=array_map('norm_class_str',$classes);
    list($sql,$params)=exam_year_students_sql($group['academic_year']);
    foreach(DB::fetchAll("SELECT DISTINCT s.class_name FROM students s WHERE s.status='active' AND $sql",$params) as $c) if(in_array(norm_class_str($c['class_name']),$norm,true))$classes[]=$c['class_name'];
    return array_values(array_unique($classes));
}
function ceg_scope($group) {return hash('sha256',json_encode(ceg_classes($group),JSON_UNESCAPED_UNICODE));}
function ceg_begin($teacher) {
    $pdo=DB::getInstance()->getPdo();$sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    if($sqlite)$pdo->exec('BEGIN IMMEDIATE');else $pdo->beginTransaction();
    try {
        if(!DB::fetch('SELECT id FROM teachers WHERE id=?'.($sqlite?'':' FOR UPDATE'),[(int)$teacher]))throw new RuntimeException('دبیر یافت نشد.');
    }catch(Throwable $e){if($sqlite)$pdo->exec('ROLLBACK');else $pdo->rollBack();throw $e;}
    return [$pdo,$sqlite];
}
function ceg_end($tx,$ok){if($tx[1])$tx[0]->exec($ok?'COMMIT':'ROLLBACK');elseif($ok)$tx[0]->commit();elseif($tx[0]->inTransaction())$tx[0]->rollBack();}
/** Serialize class-design mutations with start/detach; caller runs schema DDL first. */
function ceg_write_begin($exam) {
    if(!$exam || ($exam['exam_kind']??'')!=='class' || !empty($GLOBALS['ceg_write_tx']))return;
    $GLOBALS['ceg_write_tx']=ceg_begin((int)$exam['teacher_id']);
    register_shutdown_function(function(){try{ceg_write_end(false);}catch(Throwable $e){error_log('class design rollback: '.$e->getMessage());}});
}
function ceg_write_end($ok) {
    if(empty($GLOBALS['ceg_write_tx']))return;
    $tx=$GLOBALS['ceg_write_tx'];$GLOBALS['ceg_write_tx']=null;ceg_end($tx,$ok);
}
function ceg_validate_member($group,$memberId) {
    if(!$memberId)return true;
    $m=DB::fetch('SELECT class_name FROM class_exam_group_members WHERE group_id=? AND exam_id=? AND excluded=0',[$group['id'],(int)$memberId]);
    return $m && in_array($m['class_name'],ceg_classes($group),true);
}
function ceg_insert_exam($teacher,$year,$grade,$subject,$class) {
    $t=DB::fetch('SELECT full_name FROM teachers WHERE id=?',[$teacher]);
    DB::execute('INSERT INTO exam_schedules (academic_year,exam_month,grade_level,class_name,subject_name,teacher_id,teacher_name,exam_date_jalali,exam_day_name,start_time,duration_minutes,exam_room_default,question_file,header_config,is_active,exam_kind,created_at_jalali) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$year,$class===''?'آزمون مشترک پایه':'آزمون کلاسی',$grade,$class,$subject,$teacher,$t['full_name'],'','','',45,'',null,'{}',1,'class',jalali_now()]);
    return (int)DB::lastInsertId();
}
function ceg_cleanup($paths){foreach(array_reverse($paths) as $p){if(is_file($p))@unlink($p);elseif(is_dir($p))@rmdir($p);}}
function ceg_copy_design($src,$dst,&$created) {
    $root=dirname(__DIR__);$from=DB::fetch('SELECT * FROM exam_schedules WHERE id=?',[$src]);
    if(!$from)throw new RuntimeException('آزمون منبع یافت نشد.');
    $replacements=[];$file=(string)($from['question_file']??'');$newFile=$file;
    $oldCache='uploads/exams/pdf-pages/exam_'.$src.'/';$newCache='uploads/exams/pdf-pages/exam_'.$dst.'/';
    $cacheFiles=glob($root.'/'.$oldCache.'*')?:[];
    // A converted PDF may intentionally be absent while its cached page images exist.
    if($file!=='' && !is_file($root.'/'.$file) && !glob($root.'/'.$oldCache.'page_*.jpg'))throw new RuntimeException('فایل سؤال/صفحات منبع در دسترس نیست؛ ابتدا آن را دریافت کنید.');
    if($file!=='' && is_file($root.'/'.$file)) {
        $real=realpath($root.'/'.$file);$uploads=realpath($root.'/uploads');
        if(!$uploads || strpos($real,$uploads.DIRECTORY_SEPARATOR)!==0)throw new RuntimeException('مسیر فایل سؤال نامعتبر است.');
        $newFile='uploads/exams/group_'.$dst.'_'.bin2hex(random_bytes(6)).'.'.pathinfo($file,PATHINFO_EXTENSION);
        if(!copy($real,$root.'/'.$newFile))throw new RuntimeException('کپی فایل سؤال انجام نشد.');
        $created[]=$root.'/'.$newFile;
    } elseif($file!=='') $newFile='uploads/exams/group_'.$dst.'.pdf';
    if($file!=='')$replacements[$file]=$newFile;
    if($cacheFiles) {
        $dir=$root.'/'.$newCache;
        if(is_dir($dir))throw new RuntimeException('پوشهٔ مقصد از قبل وجود دارد؛ برای حفاظت از فایل‌ها عملیات متوقف شد.');
        if(!mkdir($dir,0755,true))throw new RuntimeException('ایجاد پوشهٔ صفحات ممکن نیست.');$created[]=rtrim($dir,'/');
        foreach($cacheFiles as $p)if(is_file($p)){
            $dest=$dir.basename($p);if(!copy($p,$dest))throw new RuntimeException('کپی صفحات سؤال کامل نشد.');$created[]=$dest;
        }
    }
    $replacements[$oldCache]=$newCache;
    $rewrite=function($value)use(&$rewrite,$replacements){
        if(is_array($value)){$out=[];foreach($value as $k=>$v)$out[is_string($k)?strtr($k,$replacements):$k]=$rewrite($v);return $out;}
        return is_string($value)?strtr($value,$replacements):$value;
    };
    $header=json_decode($from['header_config']?:'{}',true);
    if(!is_array($header))throw new RuntimeException('تنظیمات سربرگ منبع قابل خواندن نیست.');
    // Only destination data is written. Source exam, JSON, files and cache are untouched.
    DB::execute('UPDATE exam_schedules SET question_file=?,header_config=?,duration_minutes=? WHERE id=?',[$newFile?:null,json_encode($rewrite($header),JSON_UNESCAPED_UNICODE),$from['duration_minutes'],$dst]);
    $design=DB::fetch('SELECT * FROM exam_designs WHERE exam_id=?',[$src]);
    if($design) {
        $decoded=json_decode($design['design_json'],true);
        if(!is_array($decoded))throw new RuntimeException('طراحی منبع قابل خواندن نیست.');
        DB::execute('INSERT INTO exam_designs (exam_id,design_json,designer_teacher_id,designer_name,saved_by_admin_id,updated_at_jalali) VALUES (?,?,?,?,?,?)',[$dst,json_encode($rewrite($decoded),JSON_UNESCAPED_UNICODE),$design['designer_teacher_id'],$design['designer_name'],$design['saved_by_admin_id'],jalali_now()]);
    }
}
function ceg_start($teacher,$year,$grade,$subject,$mode,$source=0) {
    if(!in_array($mode,['copy','fresh'],true))throw new RuntimeException('روش شروع را انتخاب کنید.');
    $tx=ceg_begin($teacher);$created=[];
    try {
        $group=ceg_find($teacher,$year,$grade,$subject);
        if($group){ceg_end($tx,true);return $group;}// Repeated/parallel submit must never reset a live group.
        $assignments=ceg_assignments($teacher,$year,$grade,$subject);
        if(!$assignments || trim($grade)==='')throw new RuntimeException('کلاسی برای این دبیر، درس و پایه تخصیص ندارد.');
        $sources=ceg_sources($teacher,$year,$grade,$subject);
        if($mode==='copy' && !isset($sources[$source]))throw new RuntimeException('آزمون منبع باید متعلق به همین دبیر، سال، درس و پایه باشد.');
        $designId=ceg_insert_exam($teacher,$year,$grade,$subject,'');
        if($mode==='copy')ceg_copy_design($source,$designId,$created);
        DB::execute('INSERT INTO class_exam_groups (identity_key,teacher_id,academic_year,grade_level,subject_name,design_exam_id,source_exam_id) VALUES (?,?,?,?,?,?,?)',[ceg_key($teacher,$year,$grade,$subject),$teacher,$year,$grade,$subject,$designId,$mode==='copy'?$source:null]);
        $gid=(int)DB::lastInsertId();
        foreach($assignments as $as) {
            $existing=class_exam_existing($teacher,$year,$as['class_name'],$subject);
            $id=$existing?(int)$existing[0]['id']:ceg_insert_exam($teacher,$year,$grade,$subject,$as['class_name']);
            if($mode==='copy' && norm_class_str($sources[$source]['class_name'])===norm_class_str($as['class_name']))$id=$source;
            DB::execute('INSERT INTO class_exam_group_members (group_id,exam_id,class_name) VALUES (?,?,?)',[$gid,$id,$as['class_name']]);
        }
        ceg_end($tx,true);return ceg_find($teacher,$year,$grade,$subject);
    }catch(Throwable $e){ceg_end($tx,false);ceg_cleanup($created);throw $e;}
}
function ceg_detach($teacher,$groupId,$memberId) {
    $tx=ceg_begin($teacher);$created=[];
    try {
        $group=DB::fetch('SELECT * FROM class_exam_groups WHERE id=? AND teacher_id=?',[$groupId,$teacher]);
        $member=DB::fetch('SELECT * FROM class_exam_group_members WHERE group_id=? AND exam_id=?',[$groupId,$memberId]);
        if(!$group||!$member)throw new RuntimeException('کلاس عضو این گروه نیست یا دسترسی ندارید.');
        if($member['excluded']){if(empty($member['detached_exam_id']))throw new RuntimeException('آزمون این عضو حذف شده است.');ceg_end($tx,true);return (int)$member['detached_exam_id'];}
        $fork=ceg_insert_exam($teacher,$group['academic_year'],$group['grade_level'],$group['subject_name'],$member['class_name']);
        ceg_copy_design($group['design_exam_id'],$fork,$created);
        DB::execute('UPDATE class_exam_group_members SET excluded=1,detached_exam_id=? WHERE group_id=? AND exam_id=?',[$fork,$groupId,$memberId]);
        $saved=DB::fetch('SELECT excluded,detached_exam_id FROM class_exam_group_members WHERE group_id=? AND exam_id=?',[$groupId,$memberId]);
        if(!$saved || !(int)$saved['excluded'] || (int)$saved['detached_exam_id']!==$fork)throw new RuntimeException('استثنای کلاس ثبت نشد؛ دوباره تلاش کنید.');
        ceg_end($tx,true);return $fork;
    }catch(Throwable $e){ceg_end($tx,false);ceg_cleanup($created);throw $e;}
}

/** Delete only an explicitly selected class exam; keep recoverable design/assets and other classes intact. */
function ceg_delete_class_exam($examId) {
    $exam=DB::fetch('SELECT * FROM exam_schedules WHERE id=?',[(int)$examId]);
    if(!$exam || !in_array($exam['exam_kind']??'',['class','class_deleted'],true) || trim($exam['class_name'])==='')throw new RuntimeException('فقط آزمون یک کلاس را می‌توان از این مسیر حذف کرد.');
    $tx=ceg_begin((int)$exam['teacher_id']);
    try {
        $exam=DB::fetch('SELECT * FROM exam_schedules WHERE id=?',[(int)$examId]);
        if($exam['exam_kind']==='class_deleted'){ceg_end($tx,true);return;}
        $group=ceg_for_exam($exam);$ids=[(int)$examId];
        if($group && (!$group['excluded'] || (int)$group['member_exam_id']===(int)$examId || (int)($group['detached_exam_id']??0)===(int)$examId)) {
            $ids[]=(int)$group['member_exam_id'];
            if(!empty($group['detached_exam_id']))$ids[]=(int)$group['detached_exam_id'];
            DB::execute('UPDATE class_exam_group_members SET excluded=1,detached_exam_id=NULL WHERE group_id=? AND exam_id=?',[$group['id'],$group['member_exam_id']]);
        }
        foreach(array_unique($ids) as $id)DB::execute("UPDATE exam_schedules SET exam_kind='class_deleted',is_active=0 WHERE id=? AND teacher_id=? AND class_name<>''",[$id,$exam['teacher_id']]);
        if($group){
            $remaining=DB::fetch("SELECT COUNT(*) n FROM class_exam_group_members m JOIN exam_schedules e ON e.id=m.exam_id WHERE m.group_id=? AND (m.excluded=0 OR m.detached_exam_id IS NOT NULL OR e.exam_kind<>'class_deleted')",[$group['id']]);
            if((int)$remaining['n']===0){
                // Retain historical records but free the identity for an explicit new group.
                DB::execute('UPDATE class_exam_groups SET identity_key=? WHERE id=?',[hash('sha256','retired:'.$group['id'].':'.$group['identity_key']),$group['id']]);
                DB::execute("UPDATE exam_schedules SET exam_kind='class_deleted',is_active=0 WHERE id=?",[$group['design_exam_id']]);
            }
        }

        ceg_end($tx,true);
    }catch(Throwable $e){ceg_end($tx,false);throw $e;}
}
function ceg_render_delete_form($id,$year,$management=false,$selectFromRow=false) {
    ?><form method="POST" action="class-exam-delete.php" class="no-ajax class-exam-delete" onsubmit="<?php if($selectFromRow): ?>var s=this.closest('tr').querySelector('select[name=exam_id]');if(s)this.elements.exam_id.value=s.value;<?php endif; ?>return confirm('آزمون انتخاب‌شده از فهرست حذف شود؟ فقط همین کلاس از گروه خارج می‌شود؛ طراحی سایر کلاس‌ها باقی می‌ماند.');">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="exam_id" value="<?php echo (int)$id; ?>"><input type="hidden" name="year" value="<?php echo clean($year); ?>"><input type="hidden" name="return_to" value="<?php echo $management?'management':'teacher'; ?>"><button type="submit" class="btn btn-danger text-xs">حذف آزمون کلاسی</button></form><?php
}
