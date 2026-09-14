import { run, php, req, loginAdmin } from './harness/lib.mjs';
import { writeFileSync, mkdirSync } from 'node:fs';
import { REPO } from './harness/site.mjs';
import { join } from 'node:path';

let pass = 0, fail = 0;
const ok = (name, value) => { console.log(`${value ? 'PASS' : 'FAIL'} ${name}`); value ? pass++ : fail++; };
php.writeFile('/harness/school-test.php', `<?php
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/school_sort.php';
require_once '/www/includes/class_schedule_sync.php';
require_once '/www/includes/academic_year_helpers.php';
require_once '/www/includes/docx_school_list.php';
DB::execute('DELETE FROM students'); DB::execute('DELETE FROM classes'); DB::execute('DELETE FROM class_schedules');
DB::execute("INSERT OR REPLACE INTO settings(key_name,key_value) VALUES('current_academic_year','1405/1406')");
$seq = 1000000000;
function student($class, $grade, $first, $last, $year = '1405/1406', $status = 'active') {
    global $seq;
    DB::execute('INSERT INTO students(national_id,password,first_name,last_name,class_name,grade_level,academic_year,status) VALUES(?,?,?,?,?,?,?,?)',
        [(string)$seq++, '', $first, $last, $class, $grade, $year, $status]);
}
foreach ([7=>'هفتم',8=>'هشتم',9=>'نهم'] as $num=>$grade) {
    for ($c=1;$c<=3;$c++) {
        DB::execute('INSERT INTO classes(name,grade,academic_year) VALUES(?,?,?)', [$grade.$c,$grade,'1405/1406']);
        student($grade.$c,$grade,'علی','باقری');
        student($grade.$c,$grade,'محمد','آبادی');
    }
}
student('هفتم1','هفتم','سارا','گذشته','1404/1405');
student('هفتم1','هفتم','مینا','غیرفعال','1405/1406','inactive');
student('کلاس قدیمی','هفتم','بیتا','بدون سال','');
$d = srl_collect('۱۴۰۵–۱۴۰۶');
$bytes = srl_generate($d); file_put_contents('/harness/school-sample.docx',$bytes);
function inspectDoc($bytes) {
    $path='/harness/inspect.docx'; file_put_contents($path,$bytes); $z=new ZipArchive(); $z->open($path);
    $xml=$z->getFromName('word/document.xml'); $doc=new DOMDocument(); $valid=$doc->loadXML($xml);
    $xp=srl_xpath($doc); $text=$xp->evaluate('string(/w:document/w:body/w:p[1])');
    $t=$xp->query('//w:tbl')->item(0); $rows=$xp->query('./w:tr',$t);
    $width=0;foreach($xp->query('./w:tblGrid/w:gridCol',$t) as $col)$width+=(int)$col->getAttributeNS(SRL_W,'w');
    $spans=true;
    foreach($xp->query('//w:tr') as $row) {
        $sum=0; foreach($xp->query('./w:tc',$row) as $cell){$s=$xp->query('./w:tcPr/w:gridSpan',$cell)->item(0);$sum+=$s?(int)$s->getAttributeNS(SRL_W,'val'):1;} if($sum!==21)$spans=false;
    }
    // Inspect every header/name cell, including empty cells and continuation sheets.
    $nameCells = 0; $naturalNames = true;
    foreach ($xp->query('//w:tbl/w:tr[position() >= 2 and position() <= 31]') as $row) {
        foreach ($xp->query('./w:tc', $row) as $i => $cell) {
            if ($i % 7 === 0) continue; // The three row-number columns.
            $nameCells++;
            if ($xp->query('./w:tcPr/w:tcFitText | .//w:rPr/w:fitText | .//w:rPr/w:spacing | .//w:rPr/w:w', $cell)->length !== 0) $naturalNames = false;
            if ($xp->evaluate('string(./w:p/w:pPr/w:jc/@w:val)', $cell) !== 'center') $naturalNames = false;
        }
    }
    $original = new ZipArchive(); $original->open(srl_template_path()); $unchanged=true;
    for($i=0;$i<$original->numFiles;$i++){ $n=$original->getNameIndex($i);if($n!=='word/document.xml' && $original->getFromName($n)!==$z->getFromName($n))$unchanged=false; }
    $ox = new DOMDocument(); $ox->loadXML($original->getFromName('word/document.xml'));$oxp=srl_xpath($ox);
    $layout=$doc->saveXML($xp->query('//w:sectPr')->item(0))===$ox->saveXML($oxp->query('//w:sectPr')->item(0));
    $z->close();$original->close();
    return ['valid'=>$valid,'title'=>$text,'tables'=>$xp->query('//w:tbl')->length,'cols'=>$xp->query('./w:tblGrid/w:gridCol',$t)->length,'rows'=>$rows->length,
        'nameCells'=>$nameCells, 'naturalNames'=>$naturalNames,
        'width'=>$width,'spans'=>$spans,'unchanged'=>$unchanged,'layout'=>$layout,
        'firstCode'=>$xp->evaluate('string(./w:tr[1]/w:tc[2])',$t),
        'codeLTR'=>$xp->evaluate('string(./w:tr[1]/w:tc[2]/w:p/w:r/w:rPr/w:rtl/@w:val)',$t),
        'lastHeader'=>$xp->evaluate('string(./w:tr[2]/w:tc[2])',$t),'firstHeader'=>$xp->evaluate('string(./w:tr[2]/w:tc[3])',$t),
        'last'=>$xp->evaluate('string(./w:tr[3]/w:tc[2])',$t),'first'=>$xp->evaluate('string(./w:tr[3]/w:tc[3])',$t),
        'footer'=>$xp->evaluate('string(./w:tr[last()])',$t), 'xml'=>$xml];
}
$base=inspectDoc($bytes);
// Unequal class counts, numeric class names and >29 students. No name may disappear.
DB::execute('INSERT INTO classes(name,grade,academic_year) VALUES(?,?,?)',['7/4','7','1405/1406']);
for($i=1;$i<=36;$i++)student('7/4','7','نام'.$i,'خانوادگی'.$i);
student('هفتم1','هفتم','<علی & رضا>','آزمون XML');
$more=srl_collect('1405/1406');$moreBytes=srl_generate($more);$overflow=inspectDoc($moreBytes);
file_put_contents('/harness/school-overflow.docx',$moreBytes);
$blocked=false;student('','هفتم','بی کلاس','آزمایشی');
try{srl_generate(srl_collect('1405/1406'));}catch(RuntimeException $e){$blocked=true;}
DB::execute("DELETE FROM students WHERE class_name = ''");
$missing=false;try{srl_generate($d,'/not-found.docx');}catch(RuntimeException $e){$missing=true;}
$invalid=false;try{srl_year('بدون سال');}catch(RuntimeException $e){$invalid=true;}
$html=dcl_render_print_html(dcl_class_code('هفتم1','هفتم'),[], '',false);
file_put_contents('/harness/teacher-print.html',$html);
echo json_encode(['data'=>$d,'base'=>$base,'more'=>$more,'overflow'=>$overflow,'blocked'=>$blocked,'missing'=>$missing,'invalid'=>$invalid,
    'numeric'=>srl_class_info('۷/۴','۷'),'reversed'=>srl_class_info('4/7','هفتم'),
    'pdfLTR'=>strpos($html,'<bdi dir="ltr" class="class-code">1/7</bdi>')!==false,
    'pdfTitle'=>strpos($html,'.title-row{height:8mm}')!==false],JSON_UNESCAPED_UNICODE);
`);
const output = await run("<?php require '/harness/school-test.php';");
let r;
try { r = JSON.parse(output.out.trim()); } catch { console.log(output); process.exit(1); }
const b = r.base, o = r.overflow;
ok('DOCX parses as XML', b.valid);
ok('all 540 name/header cells have natural unscaled centered text', b.nameCells === 540 && b.naturalNames);
ok('all 1620 continuation name/header cells have natural unscaled centered text', o.nameCells === 1620 && o.naturalNames);
ok('academic year range explicitly LTR', b.xml.includes('<w:dir w:val="ltr">'));
ok('exact current-year active students only', r.data.total === 18);
ok('unknown year counted separately', r.data.unassigned_year === 1);
ok('three grades and nine classes', r.data.groups.length === 3 && r.data.groups.every(g => g.classes.length === 3));
ok('each grade total = 6', r.data.groups.every(g => g.total === 6));
ok('year and school total replace placeholders', b.title.includes('1405') && b.title.includes('1406') && b.title.includes('18 نفر') && !b.title.includes('000'));
ok('original layout: 1 table, 32 rows', b.tables === 1 && b.rows === 32);
ok('name columns split: 21 grid columns', b.cols === 21);
ok('all row spans match grid, including footer', b.spans && o.spans);
ok('sum of column widths unchanged', b.width === 23747);
ok('page size, margins and section properties unchanged', b.layout);
ok('every non-document ZIP part byte-identical', b.unchanged);
ok('family name first on RTL table', b.lastHeader === 'نام خانوادگی' && b.firstHeader === 'نام');
ok('Persian sorting: آبادی before باقری', b.last === 'آبادی' && b.first === 'محمد');
ok('explicit numeric class 1/7 in LTR run', b.firstCode === '1/7' && b.codeLTR === '0');
ok('grade footers contain totals', (b.footer.match(/جمع کل 6/g) || []).length === 3 && !b.footer.includes('00'));
ok('numeric Persian class parsing', r.numeric.code === '4/7' && r.reversed.code === '4/7');
ok('full original Titr family retained (no B Titr substitution)', b.xml.includes('2  Titr') && !b.xml.includes('B Titr'));
ok('continuation sheets for fourth class AND 36 students', o.tables === 3);
ok('overflow total = 55, grade-seven total = 43', r.more.total === 55 && r.more.groups[0].total === 43);
ok('every overflow student appears exactly once', Array.from({length:36},(_,i)=>`نام${i+1}`).every(name => o.xml.split(`>${name}<`).length - 1 === 1));
ok('XML-special names safely escaped', o.xml.includes('&lt;علی &amp; رضا&gt;'));
ok('no unclassified students silently dropped', r.blocked);
ok('missing template gives controlled error', r.missing);
ok('invalid current year gives controlled error', r.invalid);
ok('PDF class code isolated LTR', r.pdfLTR);
ok('PDF title row raised to 8 mm', r.pdfTitle);
mkdirSync(join(REPO,'.cache/roster-tests'), {recursive:true});
for (const f of ['school-sample.docx','school-overflow.docx','teacher-print.html']) writeFileSync(join(REPO,'.cache/roster-tests',f),php.readFileAsBuffer('/harness/'+f));
await loginAdmin();
const admin = await req('school tab', {file:'reports-lists.php',query:'tab=school',sid:'harnessAdm0001'});
ok('admin can open new school tab', !admin.res.fatal && admin.res.page.includes('دریافت لیست کل مدرسه (Word)'));
const unauth = await req('student denied export',{file:'reports-lists.php',query:'action=school_list_docx'});
ok('student cannot export roster', !unauth.res.page.startsWith('PK') && !unauth.res.page.includes('دریافت لیست کل مدرسه (Word)'));
console.log(`School roster: ${pass} PASS / ${fail} FAIL`);
process.exit(fail ? 1 : 0);
