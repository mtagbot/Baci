import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { php, run, db } from './harness/lib.mjs';
import { REPO } from './harness/site.mjs';

// Exercise the exact helper shipped in the site/desktop update overlay.
php.writeFile('/www/includes/exams_helper.php', new Uint8Array(readFileSync(`${REPO}/update-v4.152.0/includes/exams_helper.php`)));
const seed = await run(`<?php
require_once '/www/includes/functions.php';
require_once '/www/includes/exams_helper.php';
ensure_exams_schema();
set_setting('current_academic_year','1405/1406');
foreach ([
 [5011,'A','هفتم','1405/1406'],
 [5012,'B','هشتم','1405/1406'],
 [5013,'A','هفتم','1404/1405'],
] as $s) DB::execute('INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year) VALUES (?,?,?,?,?,?,?,?)',[$s[0],'Schedule'.$s[0],'دانش‌آموز','آزمایشی',$s[1],$s[2],'active',$s[3]]);
function schedule_fixture($year,$grade,$class,$subject,$date,$time,$kind='official',$active=1) {
 DB::execute('INSERT INTO exam_schedules (academic_year,exam_month,grade_level,class_name,subject_name,teacher_name,exam_date_jalali,exam_day_name,start_time,duration_minutes,exam_room_default,header_config,is_active,exam_kind,created_at_jalali) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$year,'خرداد',$grade,$class,$subject,'دبیر',$date,'شنبه',$time,90,'','{}',$active,$kind,'1405/01/01']);
 return (int)DB::lastInsertId();
}
$validA=schedule_fixture('1405/1406','هفتم','A','ریاضی','1405/03/01','08:00');
$validB=schedule_fixture('1405/1406','هشتم','B','علوم','1405/03/02','10:00');
$old=schedule_fixture('1404/1405','هفتم','A','تاریخِ قدیمی','1404/03/01','08:00');
$class=schedule_fixture('1405/1406','هفتم','A','آزمون کلاسی','2026/03/02','09:00','class');
$noDate=schedule_fixture('1405/1406','هفتم','A','بدون تاریخ','','08:00');
$noTime=schedule_fixture('1405/1406','هفتم','A','بدون ساعت','1405/03/03','');
$inactive=schedule_fixture('1405/1406','هفتم','A','غیرفعال','1405/03/04','08:00','official',0);
$assigned=schedule_fixture('1405/1406','هفتم','کلاس دیگر','آزمون تخصیصی','1405/03/05','11:00');
// Simulate a legacy table without its newer unique key and duplicate assignment rows.
DB::execute('DROP TABLE exam_assignments');
DB::execute('CREATE TABLE exam_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT,exam_id INTEGER,student_id INTEGER,seat_number TEXT,exam_room TEXT,created_at_jalali TEXT)');
DB::execute('INSERT INTO exam_assignments (exam_id,student_id,seat_number,exam_room,created_at_jalali) VALUES (?,?,?,?,?)',[$assigned,5011,'A-1','سالن ۱','1405/01/01']);
DB::execute('INSERT INTO exam_assignments (exam_id,student_id,seat_number,exam_room,created_at_jalali) VALUES (?,?,?,?,?)',[$assigned,5011,'A-2','سالن ۲','1405/01/02']);
$a=exam_student_schedule(5011); $b=exam_student_schedule(5012); $c=exam_student_schedule(5013);
$assignedRow=array_values(array_filter($a,fn($r)=>$r['subject_name']==='آزمون تخصیصی'))[0]??[];
echo json_encode(['a'=>array_column($a,'subject_name'),'b'=>array_column($b,'subject_name'),'c'=>array_column($c,'subject_name'),'assignment_seat'=>$assignedRow['seat_number']??null,'ids'=>compact('validA','validB','old','class','noDate','noTime','inactive','assigned')],JSON_UNESCAPED_UNICODE);
`);
assert.equal(seed.err, '', seed.err);
const payload = JSON.parse(seed.out.trim());
assert.deepEqual(payload.a, ['ریاضی','آزمون تخصیصی']);
assert.equal(payload.assignment_seat, 'A-2', 'the latest legacy duplicate assignment is used once');
assert.deepEqual(payload.b, ['علوم']);
assert.deepEqual(payload.c, ['تاریخِ قدیمی']);
// The query only reads: malformed/old/class records remain untouched in storage.
const rows = await db('SELECT id,is_active,exam_kind,exam_date_jalali,start_time FROM exam_schedules ORDER BY id');
for (const id of [payload.ids.class,payload.ids.noDate,payload.ids.noTime,payload.ids.inactive]) assert(rows.some(r => +r.id===id));
console.log('✓ برنامهٔ دانش‌آموز: سال/کلاس، تاریخ و ساعت خالی، آزمون کلاسی، assignment تکراری و چند دانش‌آموز');
process.exit(0);
