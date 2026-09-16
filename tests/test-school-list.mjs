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
function nodeShape($node, $numbers = false) {
    if ($node->nodeType === XML_TEXT_NODE) return $numbers ? preg_replace('/[0-9]+/', '#', $node->nodeValue) : $node->nodeValue;
    $attrs=[];
    if ($node->hasAttributes()) foreach ($node->attributes as $a) {
        // Editor identity metadata is not layout; libxml may rebind it on clones.
        if (in_array($a->localName, ['paraId','textId'], true)) continue;
        if ($a->namespaceURI !== 'http://www.w3.org/2000/xmlns/') $attrs[$a->namespaceURI . ':' . $a->localName]=$a->value;
    }
    ksort($attrs); $children=[];
    foreach ($node->childNodes as $child) $children[]=nodeShape($child, $numbers);
    return [$node->namespaceURI, $node->localName, $attrs, $children];
}
// Independent numeric budget for the supplied source: class/footer 704 twips,
// header 544, each student 544; two title lines 1600, terminal paragraph 40.
function expectedScale($rows=33,$paper='A4') { return min(1, (($paper==='A3'?16839:11906)-280)/(3552+544*($rows-3))); }
function expectedXScale($paper='A4') { return (($paper==='A3'?23814:16838)-120)/23747; }
function expectedScaledNode($node,$factor,$paper='A4') {
    $copy=$node->cloneNode(true); $xp=srl_xpath($copy->ownerDocument);
    foreach($xp->query('.//w:rPr/w:sz | .//w:rPr/w:szCs',$copy) as $e) $e->setAttributeNS(SRL_W,'w:val',(string)max(2,(int)floor((int)$e->getAttributeNS(SRL_W,'val')*$factor)));
    foreach($xp->query('.//w:tcW | .//w:gridCol',$copy) as $e) $e->setAttributeNS(SRL_W,'w:w',(string)(int)round((int)$e->getAttributeNS(SRL_W,'w')*expectedXScale($paper)));
    return $copy;
}
// These are deliberately overridden for exact one-page printing, and are checked
// separately below. Keep every other property (RTL, fonts, borders, run order...).
function semanticShape($node,$numbers=false) {
    $copy=$node->cloneNode(true);$xp=srl_xpath($copy->ownerDocument);
    foreach(iterator_to_array($xp->query('.//w:pPr/w:spacing | .//w:pPr/w:ind | .//w:pPr/w:keepNext | .//w:pPr/w:keepLines | .//w:pPr/w:pageBreakBefore | .//w:pPr/w:widowControl | .//w:pPr/w:snapToGrid | .//w:tcPr/w:tcMar | .//w:tcPr/w:noWrap | .//w:tcPr/w:vAlign | .//w:pPr/w:textAlignment | .//w:tc/w:p/w:pPr/w:rPr/w:sz | .//w:tc/w:p/w:pPr/w:rPr/w:szCs | ./w:p/w:pPr/w:rPr/w:sz | ./w:p/w:pPr/w:rPr/w:szCs',$copy)) as $e)$e->parentNode->removeChild($e);
    return nodeShape($copy,$numbers);
}
function inspectDoc($bytes, $mode = 'split', $paper='A4') {
    $path='/harness/inspect.docx'; file_put_contents($path,$bytes); $z=new ZipArchive(); $z->open($path);
    $xml=$z->getFromName('word/document.xml'); $doc=new DOMDocument(); $valid=$doc->loadXML($xml);
    $xp=srl_xpath($doc); $text=$xp->evaluate('string(/w:document/w:body/w:p[1])');
    $t=$xp->query('//w:tbl')->item(0); $rows=$xp->query('./w:tr',$t);
    $expectedCols=$xp->query('./w:tblGrid/w:gridCol',$t)->length;
    $factor=expectedScale($rows->length,$paper);
    $numberColumns=[];$colIndex=0;
    foreach($xp->query('./w:tr[1]/w:tc',$t) as $c){
        if(strpos($c->textContent,'ردیف')!==false)$numberColumns[]=$colIndex;
        $colIndex+=(int)$xp->evaluate('string(./w:tcPr/w:gridSpan/@w:val)',$c)?:1;
    }
    $width=0;foreach($xp->query('./w:tblGrid/w:gridCol',$t) as $col)$width+=(int)$col->getAttributeNS(SRL_W,'w');
    $spans=true;
    foreach($xp->query('//w:tr') as $row) {
        $sum=0; foreach($xp->query('./w:tc',$row) as $cell){$s=$xp->query('./w:tcPr/w:gridSpan',$cell)->item(0);$sum+=$s?(int)$s->getAttributeNS(SRL_W,'val'):1;} if($sum!==$expectedCols)$spans=false;
    }
    // Inspect every header/name cell, including empty cells and continuation sheets.
    $nameCells = 0; $naturalNames = true; $singleLineNames = true; $minNameSize=PHP_INT_MAX; $fallbackJustified=true;
    foreach ($xp->query('//w:tbl/w:tr[position() >= 2 and position() < last()]') as $row) {
        foreach ($xp->query('./w:tc', $row) as $i => $cell) {
            if (in_array($i,$numberColumns,true)) continue; // The three row-number columns.
            $nameCells++;
            // Combined-mode header cells are left exactly as in the source.
            if ($mode === 'combined' && $row->isSameNode($row->parentNode->getElementsByTagNameNS(SRL_W, 'tr')->item(1))) continue;
            if ($xp->query('./w:tcPr/w:noWrap', $cell)->length !== 1) $singleLineNames = false;
            foreach (['left','right'] as $side) {
                if ($xp->evaluate('string(./w:tcPr/w:tcMar/w:' . $side . '/@w:w)', $cell) !== '0') $singleLineNames = false;
            }
            $nameText = $xp->evaluate('string(./w:p)', $cell);
            if (strpos($nameText, ' ') !== false || strpos($nameText, chr(10)) !== false) $singleLineNames = false;
            $half = (int)$xp->evaluate('string(./w:p/w:r/w:rPr/w:szCs/@w:val)', $cell);
            $cellWidth = (int)$xp->evaluate('string(./w:tcPr/w:tcW/@w:w)', $cell);
            if (srl_name_width_em($nameText) * $half / 2 * 1.10 > $cellWidth / 20 - 1.0) $singleLineNames = false;
            $isHeader=$row->isSameNode($row->parentNode->getElementsByTagNameNS(SRL_W,'tr')->item(1));
            if(!$isHeader && trim($nameText)!==''){
                $minNameSize=min($minNameSize,$half);
                $rh=(int)$xp->evaluate('string(./w:trPr/w:trHeight/@w:val)',$row);
                $availableHeight=$rh-max(4,(int)ceil(48*$factor));
                if($half<16 && srl_name_width_em($nameText)*8*1.10<=$cellWidth/20-1 && $availableHeight>=16*18)$fallbackJustified=false;
            }
            if ($xp->query('./w:tcPr/w:tcFitText | .//w:rPr/w:fitText | .//w:rPr/w:spacing | .//w:rPr/w:w', $cell)->length !== 0) $naturalNames = false;
            if ($xp->evaluate('string(./w:p/w:pPr/w:jc/@w:val)', $cell) !== 'center') $naturalNames = false;
        }
    }
    $original = new ZipArchive(); $original->open(srl_template_path()); $unchanged=true;
    for($i=0;$i<$original->numFiles;$i++){ $n=$original->getNameIndex($i);if(!in_array($n,['word/document.xml','word/styles.xml'],true) && $original->getFromName($n)!==$z->getFromName($n))$unchanged=false; }
    $ox = new DOMDocument(); $ox->loadXML($original->getFromName('word/document.xml'));$oxp=srl_xpath($ox);
    $size=$xp->query('//w:sectPr/w:pgSz')->item(0); $mar=$xp->query('//w:sectPr/w:pgMar')->item(0);
    $targetW=$paper==='A3'?23814:16838;$targetH=$paper==='A3'?16839:11906;
    $layout=$size->getAttributeNS(SRL_W,'w')===(string)$targetW && $size->getAttributeNS(SRL_W,'h')===(string)$targetH && $size->getAttributeNS(SRL_W,'orient')==='landscape' && $size->getAttributeNS(SRL_W,'code')===($paper==='A3'?'8':'9');
    foreach(['top','bottom','left','right'] as $a) if($mar->getAttributeNS(SRL_W,$a)!=='0')$layout=false;
    $fits=true; $cellsAligned=true; $exact=true; $centered=true; $marksMatch=true; $zeroPadding=true; $totalHeight=0;
    foreach($xp->query('//w:tbl') as $tb) {
        $grid=[];foreach($xp->query('./w:tblGrid/w:gridCol',$tb) as $col)$grid[]=(int)$col->getAttributeNS(SRL_W,'w');
        if(array_sum($grid)>$targetW-100)$fits=false;
        if((int)$xp->evaluate('string(./w:tblPr/w:tblW/@w:w)',$tb)!==array_sum($grid))$cellsAligned=false;
        $rowHeight=0;
        foreach($xp->query('./w:tr',$tb) as $row) {
            $h=(int)$xp->evaluate('string(./w:trPr/w:trHeight/@w:val)',$row);$rowHeight+=$h;
            if($xp->evaluate('string(./w:trPr/w:trHeight/@w:hRule)',$row)!=='exact')$exact=false;
            $offset=0;
            foreach($xp->query('./w:tc',$row) as $cell){
                $n=(int)$xp->evaluate('string(./w:tcPr/w:gridSpan/@w:val)',$cell)?:1;
                if((int)$xp->evaluate('string(./w:tcPr/w:tcW/@w:w)',$cell)!==array_sum(array_slice($grid,$offset,$n)))$cellsAligned=false;
                $offset+=$n;
                foreach(['top','left','bottom','right'] as $side)if($xp->evaluate('string(./w:tcPr/w:tcMar/w:'.$side.'/@w:w)',$cell)!=='0')$zeroPadding=false;
                foreach($xp->query('./w:p',$cell) as $p){
                    $line=(int)$xp->evaluate('string(./w:pPr/w:spacing/@w:line)',$p);
                    if($h<srl_max_font($p)*18 || $line!==240 || $xp->evaluate('string(./w:pPr/w:spacing/@w:lineRule)',$p)!=='auto')$exact=false;
                    if($xp->evaluate('string(./w:tcPr/w:vAlign/@w:val)',$cell)!=='center' || $xp->evaluate('string(./w:pPr/w:textAlignment/@w:val)',$p)!=='center')$centered=false;
                    $runMax=0;foreach($xp->query('./w:r[w:t]/w:rPr/w:sz | ./w:r[w:t]/w:rPr/w:szCs',$p) as $sz)$runMax=max($runMax,(int)$sz->getAttributeNS(SRL_W,'val'));
                    if($runMax)foreach(['sz','szCs'] as $tag)if((int)$xp->evaluate('string(./w:pPr/w:rPr/w:'.$tag.'/@w:val)',$p)!==$runMax)$marksMatch=false;
                    foreach(['before','after'] as $a)if($xp->evaluate('string(./w:pPr/w:spacing/@w:'.$a.')',$p)!=='0')$exact=false;
                    foreach(['keepNext','keepLines','pageBreakBefore','snapToGrid','widowControl'] as $a)if($xp->evaluate('string(./w:pPr/w:'.$a.'/@w:val)',$p)!=='0')$exact=false;
                }
            }
        }
        $totalHeight+=$rowHeight;
    }
    foreach($xp->query('/w:document/w:body/w:p') as $p)$totalHeight+=(trim($p->textContent)===''?1:2)*srl_max_font($p)*20;
    if($totalHeight>$targetH-180)$fits=false;
    $styleDoc=new DOMDocument();$styleDoc->loadXML($z->getFromName('word/styles.xml'));$sx=srl_xpath($styleDoc);
    $stylesScaled=$sx->evaluate('string(//w:docDefaults/w:rPrDefault/w:rPr/w:sz/@w:val)')===(string)(int)floor(22*$factor)
        && $sx->evaluate('string(//w:style[@w:styleId="LightGrid"]/w:tblPr/w:tblBorders/w:top/@w:sz)')===(string)(int)round(8*$factor);
    $allSingle=true;
    foreach($xp->query('//w:body//w:p') as $p)if($xp->evaluate('string(./w:pPr/w:spacing/@w:lineRule)',$p)!=='auto'||$xp->evaluate('string(./w:pPr/w:spacing/@w:line)',$p)!=='240')$allSingle=false;
    foreach($sx->query('//w:pPr/w:spacing') as $spacing)if($spacing->getAttributeNS(SRL_W,'line')!=='240'||$spacing->getAttributeNS(SRL_W,'lineRule')!=='auto')$allSingle=false;
    $autoFit=$xp->query('//w:tblPr/w:tblLayout[@w:type="autofit"]')->length===$xp->query('//w:tbl')->length;
    $breaks=$xp->query('//w:pageBreakBefore[not(@w:val) or @w:val!="0"] | //w:br[@w:type="page"]')->length;
    // Comparing source paragraph structure after replacing numbers only detects
    // moved runs, altered bidi controls and changed spacing (including continuation titles).
    $sourceTitle = $oxp->query('/w:document/w:body/w:p[1]')->item(0);
    $titleShape = function ($p) {
        $copy = $p->cloneNode(true); $cx = srl_xpath($p->ownerDocument);
        foreach (iterator_to_array($cx->query('./w:pPr/w:pageBreakBefore', $copy)) as $br) $br->parentNode->removeChild($br);
        foreach ($cx->query('.//w:t', $copy) as $textNode) $textNode->nodeValue = preg_replace('/[0-9]+/', '#', $textNode->textContent);
        return semanticShape($copy, true);
    };
    $titles = $xp->query('/w:document/w:body/w:p[w:r/w:t[contains(., "اسامی")]]');
    $titleMatches = $titles->length === $xp->query('//w:tbl')->length;
    foreach ($titles as $p) {
        if ($titleShape($p) !== $titleShape(expectedScaledNode($sourceTitle,$factor,$paper))) $titleMatches = false;
    }
    $gridMatches = nodeShape($xp->query('./w:tblGrid',$t)->item(0)) === nodeShape(expectedScaledNode($oxp->query('//w:tbl/w:tblGrid')->item(0),$factor,$paper));
    $headersMatch = true;
    foreach ($xp->query('./w:tr[2]/w:tc', $t) as $i=>$cell) {
        $sourceCell = $oxp->query('//w:tbl/w:tr[2]/w:tc')->item($i);
        if (!$sourceCell || semanticShape(expectedScaledNode($sourceCell,$factor,$paper)) !== semanticShape($cell)) $headersMatch = false;
    }
    $z->close();$original->close();
    return ['lastNumber'=>$xp->evaluate('string(./w:tr[last()-1]/w:tc[1])',$t),'autoFit'=>$autoFit,'allSingle'=>$allSingle,'minNameSize'=>$minNameSize,'fallbackJustified'=>$fallbackJustified,'centered'=>$centered,'marksMatch'=>$marksMatch,'yearRuns'=>array_map(function($n){return $n->textContent;},iterator_to_array($xp->query('/w:document/w:body/w:p[1]//w:t[string-length(.)=4 and (starts-with(.,"140"))]'))),'exact'=>$exact,'zeroPadding'=>$zeroPadding,'height'=>$totalHeight,'fits'=>$fits,'cellsAligned'=>$cellsAligned,'stylesScaled'=>$stylesScaled,'breaks'=>$breaks,'valid'=>$valid,'title'=>$text,'tables'=>$xp->query('//w:tbl')->length,'cols'=>$xp->query('./w:tblGrid/w:gridCol',$t)->length,'rows'=>$rows->length,
        'titleMatches'=>$titleMatches,'gridMatches'=>$gridMatches,'headersMatch'=>$headersMatch,
        'singleLineNames'=>$singleLineNames, 'nameCells'=>$nameCells, 'naturalNames'=>$naturalNames,
        'width'=>$width,'spans'=>$spans,'unchanged'=>$unchanged,'layout'=>$layout,
        'firstCode'=>$xp->evaluate('string(./w:tr[1]/w:tc[2])',$t),
        'codeLTR'=>$xp->evaluate('string(./w:tr[1]/w:tc[2]/w:p/w:r/w:rPr/w:rtl/@w:val)',$t),
        'lastHeader'=>$xp->evaluate('string(./w:tr[2]/w:tc[2])',$t),'firstHeader'=>$xp->evaluate('string(./w:tr[2]/w:tc[3])',$t),
        'last'=>$xp->evaluate('string(./w:tr[3]/w:tc[2])',$t),'first'=>$xp->evaluate('string(./w:tr[3]/w:tc[3])',$t),
        'footer'=>$xp->evaluate('string(./w:tr[last()])',$t), 'xml'=>$xml];
}
$base=inspectDoc($bytes);
$combinedBytes=srl_generate($d,null,'combined');
$combined=inspectDoc($combinedBytes,'combined');
file_put_contents('/harness/school-combined.docx',$combinedBytes);
// Fill all 29 rows in all nine classes; empty-row fixtures alone cannot detect text overflow.
$filled=$d; $filled['total']=261;
foreach($filled['groups'] as &$group){
    $group['total']=87;
    foreach($group['classes'] as &$class){
        $class['students']=[];
        for($i=0;$i<29;$i++) $class['students'][]=['last_name'=>'حسینی‌نژاد موسوی','first_name'=>'سید محمد طاها'];
    }
    unset($class);
}
unset($group);
$filledSplit=srl_generate($filled);$filledCombined=srl_generate($filled,null,'combined');
file_put_contents('/harness/school-full-split.docx',$filledSplit);
file_put_contents('/harness/school-full-combined.docx',$filledCombined);
$full=inspectDoc($filledSplit);$fullCombined=inspectDoc($filledCombined,'combined');
// Isolated long-name fixture, so class/student counts in the main regression stay fixed.
$longData = $d;
$longData['groups'][0]['classes'][0]['students'][0]['first_name'] = 'سید محمد طاها';
$longData['groups'][0]['classes'][0]['students'][0]['last_name'] = 'حسینی‌نژاد موسوی';
$longBytes = srl_generate($longData);
file_put_contents('/harness/school-long-names.docx', $longBytes);
$longDoc = inspectDoc($longBytes);
$ld = new DOMDocument(); $ld->loadXML($longDoc['xml']); $lx=srl_xpath($ld);
$firstPath = '//w:tbl[1]/w:tr[3]/w:tc[3]';
$longInfo = [
 'text'=>$lx->evaluate('string(' . $firstPath . '/w:p)'),
 'size'=>$lx->evaluate('string(' . $firstPath . '/w:p/w:r/w:rPr/w:szCs/@w:val)'),
 'sz'=>$lx->evaluate('string(' . $firstPath . '/w:p/w:r/w:rPr/w:sz/@w:val)'),
 'shortSize'=>$lx->evaluate('string(//w:tbl[1]/w:tr[4]/w:tc[3]/w:p/w:r/w:rPr/w:szCs/@w:val)'),
 'headerSize'=>$lx->evaluate('string(//w:tbl[1]/w:tr[2]/w:tc[2]/w:p/w:r/w:rPr/w:szCs/@w:val)'),
 'last'=>$lx->evaluate('string(//w:tbl[1]/w:tr[3]/w:tc[2]/w:p)'),
 'natural'=>$longDoc['naturalNames'], 'singleLine'=>$longDoc['singleLineNames']
];
// Unequal class counts, numeric class names and >29 students. No name may disappear.
DB::execute('INSERT INTO classes(name,grade,academic_year) VALUES(?,?,?)',['7/4','7','1405/1406']);
for($i=1;$i<=36;$i++)student('7/4','7','نام'.$i,'خانوادگی'.$i);
student('هفتم1','هفتم','<علی & رضا>','آزمون XML');
$more=srl_collect('1405/1406');$moreBytes=srl_generate($more);$overflow=inspectDoc($moreBytes);
file_put_contents('/harness/school-overflow.docx',$moreBytes);
$combinedOverflowBytes=srl_generate($more,null,'combined');
$combinedOverflow=inspectDoc($combinedOverflowBytes,'combined');
file_put_contents('/harness/school-combined-overflow.docx',$combinedOverflowBytes);
$invalidMode=false;try{srl_generate($d,null,'invalid');}catch(RuntimeException $e){$invalidMode=true;}
$paperResults=[];$extraFiles=[];
$cases=['sample'=>$d,'full'=>$filled,'overflow'=>$more];
foreach([30,60] as $count){
    $case=$filled;$case['total']=9*$count;
    foreach($case['groups'] as &$group){$group['total']=3*$count;
        foreach($group['classes'] as &$class){$class['students']=array_fill(0,$count,['last_name'=>'حسینی‌نژاد موسوی','first_name'=>'سید محمد طاها']);}
        unset($class);
    }unset($group);$cases['rows'.$count]=$case;
}
foreach(['A4','A3'] as $paper) foreach(['split','combined'] as $mode) foreach($cases as $label=>$case){
    $output=srl_generate($case,null,$mode,$paper);$info=inspectDoc($output,$mode,$paper);
    if(strpos($label,'rows')===0)$info['namesRetained']=substr_count($info['xml'],'حسینی‌نژاد')===$case['total'];
    unset($info['xml']);$paperResults[$paper][$mode][$label]=$info;
    $file='one-page-'.$paper.'-'.$mode.'-'.$label.'.docx';file_put_contents('/harness/'.$file,$output);$extraFiles[]=$file;
}
$badPaper=false;try{srl_generate($d,null,'split','A2');}catch(RuntimeException $e){$badPaper=true;}
// Capacity limits must fail explicitly rather than silently clipping/truncating text.
$tooLong=$d;$tooLong['groups'][0]['classes'][0]['students'][0]['first_name']=str_repeat('محمد',300);
$tooLongBlocked=false;try{srl_generate($tooLong);}catch(RuntimeException $e){$tooLongBlocked=true;}

$missingPart=$d;
$missingPart['groups'][0]['classes'][0]['students']=[['last_name'=>'خانوادگی','first_name'=>''],['last_name'=>'','first_name'=>'نام']];
$missingDoc=inspectDoc(srl_generate($missingPart,null,'combined'),'combined');

$blocked=false;student('','هفتم','بی کلاس','آزمایشی');
try{srl_generate(srl_collect('1405/1406'));}catch(RuntimeException $e){$blocked=true;}
DB::execute("DELETE FROM students WHERE class_name = ''");
$missing=false;try{srl_generate($d,'/not-found.docx');}catch(RuntimeException $e){$missing=true;}
$invalid=false;try{srl_year('بدون سال');}catch(RuntimeException $e){$invalid=true;}
$html=dcl_render_print_html(dcl_class_code('هفتم1','هفتم'),[], '',false);
file_put_contents('/harness/teacher-print.html',$html);
echo json_encode(['paperResults'=>$paperResults,'extraFiles'=>$extraFiles,'badPaper'=>$badPaper,'tooLongBlocked'=>$tooLongBlocked,'full'=>$full,'fullCombined'=>$fullCombined,'combined'=>$combined,'combinedOverflow'=>$combinedOverflow,'invalidMode'=>$invalidMode,'missingPart'=>$missingDoc,'long'=>$longInfo,'data'=>$d,'base'=>$base,'more'=>$more,'overflow'=>$overflow,'blocked'=>$blocked,'missing'=>$missing,'invalid'=>$invalid,
    'numeric'=>srl_class_info('۷/۴','۷'),'reversed'=>srl_class_info('4/7','هفتم'),
    'pdfLTR'=>strpos($html,'<bdi dir="ltr" class="class-code">7/1</bdi>')!==false,
    'pdfTitle'=>strpos($html,'.title-row{height:8mm}')!==false],JSON_UNESCAPED_UNICODE);
`);
const output = await run("<?php require '/harness/school-test.php';");
let r;
try { r = JSON.parse(output.out.trim()); } catch { console.log(output); process.exit(1); }
const b = r.base, o = r.overflow;
ok('DOCX parses as XML', b.valid);
ok('all main-sheet name cells: narrow padding, no-wrap, size within width budget', b.singleLineNames);
ok('all continuation name cells: narrow padding, no-wrap, size within width budget', o.singleLineNames);
ok('multi-word example keeps exact visible letters and word spacing', r.long.text === 'سید\u00a0محمد\u00a0طاها');
ok('long example uses smaller uniform point size, not tracking or glyph scaling', +r.long.size < +r.long.shortSize && +r.long.size >= 2 && r.long.size === r.long.sz && r.long.natural && r.long.singleLine);
ok('ordinary A4 names reach the requested 8pt target', r.long.shortSize === '16');
ok('headers keep the original font ratio after fitting', r.long.headerSize === '10');
ok('ZWNJ and surname spelling preserved', r.long.last === 'حسینی‌نژاد\u00a0موسوی');
ok('all 558 name/header cells have natural unscaled centered text', b.nameCells === 558 && b.naturalNames);
ok('all 740 expanded-table name/header cells have natural unscaled centered text', o.nameCells === 740 && o.naturalNames);
ok('source header run order/spacing/RTL retained in both layouts and continuation pages', b.titleMatches && o.titleMatches && r.combined.titleMatches && r.combinedOverflow.titleMatches);
ok('exact current-year active students only', r.data.total === 18);
ok('unknown year counted separately', r.data.unassigned_year === 1);
ok('three grades and nine classes', r.data.groups.length === 3 && r.data.groups.every(g => g.classes.length === 3));
ok('each grade total = 6', r.data.groups.every(g => g.total === 6));
ok('year and school total replace placeholders', b.title.includes('1405') && b.title.includes('1406') && b.title.includes('18 نفر') && !b.title.includes('000'));
ok('1 table with 30 student rows plus 3 header/footer rows', b.tables === 1 && b.rows === 33);
ok('name columns split: 21 grid columns', b.cols === 21);
ok('all row spans match grid, including footer', b.spans && o.spans);
ok('table shrunk proportionally to fit A4', Math.abs(b.width-(16838-120))<12 && b.width<16838);
ok('native A4 landscape, paper code 9 and ZERO margins', b.layout);
ok('ZIP parts other than document and scaled styles stay byte-identical', b.unchanged);
ok('family name first on RTL table', b.lastHeader.replaceAll('\u00a0',' ') === 'نام خانوادگی' && b.firstHeader === 'نام');
ok('Persian sorting: آبادی before باقری', b.last === 'آبادی' && b.first === 'محمد');
ok('explicit numeric class 7/1 in LTR run', b.firstCode === '7/1' && b.codeLTR === '0');
ok('grade footers contain totals', (b.footer.match(/جمع کل 6/g) || []).length === 3 && !b.footer.includes('00'));
ok('numeric Persian class parsing', r.numeric.code === '7/4' && r.reversed.code === '7/4');
ok('full original Titr family retained (no B Titr substitution)', b.xml.includes('2  Titr') && !b.xml.includes('B Titr'));
ok('fourth class AND all 36 students in ONE expanded table', o.tables === 1 && o.cols===23 && o.rows===39);
ok('overflow total = 55, grade-seven total = 43', r.more.total === 55 && r.more.groups[0].total === 43);
ok('every overflow student appears exactly once', Array.from({length:36},(_,i)=>`نام${i+1}`).every(name => o.xml.split(`>${name}<`).length - 1 === 1));
ok('XML-special names safely escaped', o.xml.replaceAll('\u00a0',' ').includes('&lt;علی &amp; رضا&gt;'));
ok('no unclassified students silently dropped', r.blocked);
ok('missing template gives controlled error', r.missing);
ok('invalid current year gives controlled error', r.invalid);
ok('PDF class code isolated LTR', r.pdfLTR);
ok('PDF title row raised to 8 mm', r.pdfTitle);
const c=r.combined, co=r.combinedOverflow;
ok('combined DOCX has original 12 columns and 30 student rows', c.valid && c.cols===12 && c.rows===33 && c.tables===1);
ok('combined grid and name headers match proportionally scaled source', c.gridMatches && c.headersMatch);
ok('combined headers say family name and first name in ONE cell', c.lastHeader === 'نام خانوادگی نام');
ok('combined names are family-first with natural spacing', c.last === 'آبادی\u00a0محمد');
ok('combined header code is 7/1', c.firstCode==='7/1' && c.codeLTR==='0');
ok('combined footer spans and total width correct', c.spans && co.spans && Math.abs(c.width-b.width)<12);
ok('both layouts show same year and school total', c.title===b.title && co.title===o.title);
ok('combined grade footers match split footers', c.footer===b.footer && co.footer===o.footer);
ok('combined pages retain section margins and non-document ZIP parts', c.layout && c.unchanged && co.layout && co.unchanged);
ok('combined one-page table keeps fourth class and all 36 students', co.tables===1 && co.cols===13 && co.rows===39 && Array.from({length:36},(_,i)=>`نام${i+1}`).every(n=>co.xml.split(`\u00a0${n}<`).length-1===1));
ok('combined students remain natural single-line names', c.singleLineNames && co.singleLineNames && c.naturalNames && co.naturalNames);
ok('missing first or family name creates no leading/trailing separator', r.missingPart.xml.includes('>خانوادگی<') && r.missingPart.xml.includes('>نام<'));
ok('unknown layout rejected', r.invalidMode);
ok('both modes fit inside A4 with title space reserved', b.fits && o.fits && c.fits && co.fits);
ok('every scaled cell/merged footer aligns exactly with the grid', b.cellsAligned && o.cellsAligned && c.cellsAligned && co.cellsAligned);
ok('inherited font sizes and borders also scaled, not just document.xml', b.stylesScaled && c.stylesScaled);
ok('A4 conversion adds no forced page breaks', b.breaks===0 && c.breaks===0 && o.breaks===0 && co.breaks===0);
ok('all nine classes x 29 long names fit the same logical A4 sheet in both modes', r.full.tables===1 && r.fullCombined.tables===1 && r.full.fits && r.fullCombined.fits && r.full.singleLineNames && r.fullCombined.singleLineNames);
ok('exact rows retain natural-font clearance with Single spacing', b.exact && c.exact && o.exact && co.exact);
ok('all cell padding is zero, including inherited padding overridden by tcMar', b.zeroPadding && c.zeroPadding && o.zeroPadding && co.zeroPadding);
ok('all 261 full-roster names retained', (r.full.xml.match(/حسینی‌نژاد/g)||[]).length===261 && (r.fullCombined.xml.match(/حسینی‌نژاد/g)||[]).length===261 && r.full.title.includes('261'));
for (const paper of ['A4','A3']) for (const mode of ['split','combined']) {
    const docs=r.paperResults[paper][mode];
    ok(paper+' '+mode+': Single for title, year, every cell, terminal paragraph and inherited styles',Object.values(docs).every(x=>x.allSingle));
    ok(paper+' '+mode+': Word automatically resize to fit content enabled',Object.values(docs).every(x=>x.autoFit));
    ok(paper+' '+mode+': minimum 30 numbered student rows even with fewer students',docs.sample.rows===33&&docs.sample.lastNumber==='30'&&docs.full.lastNumber==='30');
    ok(paper+' '+mode+': ordinary names at least 8pt, reductions only when the width/height budget requires it',docs.sample.minNameSize>=16&&Object.values(docs).every(x=>x.fallbackJustified));
    ok(paper+' '+mode+': every cell centered with paragraph mark matching visible font',Object.values(docs).every(x=>x.centered&&x.marksMatch));
    ok(paper+' '+mode+': year slots swapped without moving the title or total',Object.values(docs).every(x=>JSON.stringify(x.yearRuns)===JSON.stringify(['1406','1405'])));
    ok(paper+' '+mode+': all sample/full/expanded documents have native paper size and zero margins',Object.values(docs).every(x=>x.layout&&x.zeroPadding));
    ok(paper+' '+mode+': complete height fits ONE sheet, no enabled breaks, exact rows',Object.values(docs).every(x=>x.fits&&x.exact&&x.breaks===0&&x.tables===1));
    ok(paper+' '+mode+': dynamic grid and merged footers align, natural single-line names retained',Object.values(docs).every(x=>x.cellsAligned&&x.spans&&x.naturalNames&&x.singleLineNames));
    ok(paper+' '+mode+': 30 and 60 students/class add rows, not pages or missing names',docs.rows30.rows===33&&docs.rows60.rows===63&&docs.rows30.namesRetained&&docs.rows60.namesRetained);
}
ok('A3 font sizes grow proportionally, not just a paper-size label', r.paperResults.A3.split.sample.height > r.paperResults.A4.split.sample.height);
ok('invalid paper rejected',r.badPaper);
ok('impossible cell content rejected explicitly rather than clipped or omitted',r.tooLongBlocked);
mkdirSync(join(REPO,'.cache/roster-tests'), {recursive:true});
for (const file of r.extraFiles) writeFileSync(join(REPO,'.cache/roster-tests',file),php.readFileAsBuffer('/harness/'+file));
for (const f of ['school-sample.docx','school-overflow.docx','school-long-names.docx','school-combined.docx','school-combined-overflow.docx','school-full-split.docx','school-full-combined.docx','teacher-print.html']) writeFileSync(join(REPO,'.cache/roster-tests',f),php.readFileAsBuffer('/harness/'+f));
await loginAdmin();
const admin = await req('school tab', {file:'reports-lists.php',query:'tab=school',sid:'harnessAdm0001'});
ok('admin can open new school tab', !admin.res.fatal && admin.res.page.includes('action=school_list_docx&amp;layout=split') && admin.res.page.includes('action=school_list_docx&amp;layout=combined'));
// Exercise all four authenticated download buttons, including paper selection.
for (const paper of ['A4','A3']) for (const mode of ['split','combined']) {
    php.writeFile('/harness/export-layout.php', `<?php
ini_set('session.save_path','/tmp/sess');session_name('BACI_TEST');session_id('harnessAdm0001');session_start();
$_SERVER['REQUEST_METHOD']='GET';$_SERVER['PHP_SELF']='/reports-lists.php';
$_GET=['action'=>'school_list_docx','layout'=>'${mode}','paper'=>'${paper}'];
chdir('/www');
ob_start(function($bytes){file_put_contents('/harness/download-${mode}.docx',$bytes);return '';});
require '/www/reports-lists.php';`);
    const download=await run("<?php require '/harness/export-layout.php';");
    const raw=php.readFileAsBuffer('/harness/download-'+mode+'.docx');
    ok('authenticated '+mode+' route returns a DOCX without HTML prefix', !download.err && raw[0]===80 && raw[1]===75);
    const inspected=await run(`<?php
require '/www/includes/docx_school_list.php';
$z=new ZipArchive();$z->open('/harness/download-${mode}.docx');
$doc=new DOMDocument();$doc->loadXML($z->getFromName('word/document.xml'));$xp=srl_xpath($doc);
echo json_encode(['paper'=>$xp->evaluate('string(//w:pgSz/@w:code)'),'cols'=>$xp->query('//w:tbl[1]/w:tblGrid/w:gridCol')->length,'code'=>$xp->evaluate('string(//w:tbl[1]/w:tr[1]/w:tc[2])')]);`);
    const info=JSON.parse(inspected.out);
    ok(mode+' route passes the layout through to the renderer', info.cols===(mode==='combined'?13:23) && info.code==='7/1' && info.paper===(paper==='A3'?'8':'9'));
}
const badPaperDownload=await req('reject unknown paper',{file:'reports-lists.php',query:'action=school_list_docx&paper=A2',sid:'harnessAdm0001'});
ok('invalid paper route is a controlled error, not a download', !badPaperDownload.res.fatal && badPaperDownload.res.flash?.type==='error');
ok('all four A4/A3 download choices are visible', ['split','combined'].every(m=>['A4','A3'].every(p=>admin.res.page.includes('layout='+m+'&amp;paper='+p))));
const badLayout=await req('reject unknown layout',{file:'reports-lists.php',query:'action=school_list_docx&layout=wrong',sid:'harnessAdm0001'});
ok('invalid layout produces controlled error, not a download', !badLayout.res.fatal && badLayout.res.flash?.type==='error');
const deniedCombined=await req('student denied combined export',{file:'reports-lists.php',query:'action=school_list_docx&layout=combined'});
ok('student cannot download combined report', !deniedCombined.res.page.startsWith('PK') && !!deniedCombined.res.redirect);
const unauth = await req('student denied export',{file:'reports-lists.php',query:'action=school_list_docx'});
ok('student cannot export roster', !unauth.res.page.startsWith('PK') && !unauth.res.page.includes('action=school_list_docx&amp;layout=combined'));
console.log(`School roster: ${pass} PASS / ${fail} FAIL`);
process.exit(fail ? 1 : 0);
