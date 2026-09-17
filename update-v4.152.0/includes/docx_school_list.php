<?php
/** Full-school roster, v4.152.0. Transform the school's DOCX, never rebuild its design.
 * Source template: A3 landscape. Outputs: one A4 or A3 landscape sheet, zero margins.
 * At least 30 student rows; rows and class columns grow dynamically; every student remains on the same sheet.
 * Font proportions, column proportions and explicit row/line heights are fitted together.
 * No writes to the database. Only active students explicitly assigned to the selected year.
 */
require_once __DIR__ . '/docx_class_list.php';

function srl_template_path() {
    return __DIR__ . '/../assets/templates/school-students.docx';
}

function srl_digits($text) {
    return strtr((string)$text, array_combine(
        preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY),
        str_split('01234567890123456789')
    ));
}

function srl_year($year) {
    $year = unify_academic_year(srl_digits($year));
    if ($year === '') throw new RuntimeException('سال تحصیلی پیش‌فرض معتبر نیست. ابتدا تنظیمات سال تحصیلی را بررسی کنید.');
    return $year;
}

/** School-roster display codes use grade/section, e.g. 7/1 (not teacher-report codes). */
function srl_class_info($name, $grade = '') {
    $en = trim(srl_digits($name));
    $gradeEn = trim(srl_digits($grade));
    $g = ctype_digit($gradeEn) ? (int)$gradeEn : dcl_grade_number($grade);
    if (!$g) $g = dcl_grade_number($name);
    if (preg_match('~^(\d+)\s*/\s*(\d+)$~u', $en, $m)) {
        $a = (int)$m[1]; $b = (int)$m[2];
        if (!$g) $g = $a; // Stored numeric class names use grade/section.
        $section = ($a === $g) ? $b : $a;
    } else {
        $section = preg_match('/(\d+)\s*$/u', $en, $m) ? (int)$m[1] : 0;
    }
    return ['grade' => $g, 'code' => ($g > 0 && $section > 0) ? $g . '/' . $section : $name];
}

function srl_collect($year) {
    $year = srl_year($year);
    // Unlike get_unified_class_options, do NOT import student classes from other years.
    $options = DB::fetchAll('SELECT name AS class_name, grade AS grade_level FROM classes WHERE academic_year = ?', [$year]);
    $options = array_merge($options, DB::fetchAll("SELECT DISTINCT class_name, '' AS grade_level FROM class_schedules WHERE academic_year = ?", [$year]));
    $students = DB::fetchAll("SELECT id, class_name, grade_level, last_name, first_name FROM students WHERE status = 'active' AND academic_year = ?", [$year]);
    $classes = []; $missing = 0;
    foreach (array_merge($options, $students) as $row) {
        $name = norm_class_str($row['class_name'] ?? '');
        if ($name === '') { if (isset($row['id'])) $missing++; continue; }
        if (!isset($classes[$name])) $classes[$name] = ['class_name' => $name, 'grade_level' => '', 'students' => []];
        if ($classes[$name]['grade_level'] === '' && trim($row['grade_level'] ?? '') !== '') {
            $classes[$name]['grade_level'] = $row['grade_level'];
        }
        if (isset($row['id'])) $classes[$name]['students'][] = $row;
    }
    $classes = array_values($classes);
    persian_usort_classes($classes);
    $groups = [];
    foreach ($classes as $class) {
        $info = srl_class_info($class['class_name'], $class['grade_level']);
        $key = $info['grade'] ?: ($class['grade_level'] ?: 'بدون پایه');
        if (!isset($groups[$key])) $groups[$key] = ['grade' => $key, 'classes' => [], 'total' => 0];
        persian_usort_students($class['students']);
        $class['code'] = $info['code'];
        $groups[$key]['classes'][] = $class;
        $groups[$key]['total'] += count($class['students']);
    }
    uksort($groups, function ($a, $b) { return strnatcmp((string)$a, (string)$b); });
    // Unknown-year students must not silently inflate the current-year totals.
    $legacy = DB::fetch("SELECT COUNT(*) AS n FROM students WHERE status = 'active' AND (academic_year IS NULL OR academic_year = '')");
    return ['year' => $year, 'groups' => array_values($groups), 'total' => count($students),
        'missing_class' => $missing, 'unassigned_year' => (int)($legacy['n'] ?? 0)];
}

const SRL_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

function srl_xpath(DOMDocument $doc) {
    $xp = new DOMXPath($doc); $xp->registerNamespace('w', SRL_W); return $xp;
}

function srl_prop(DOMNode $parent, $name, array $attrs = []) {
    $el = $parent->ownerDocument->createElementNS(SRL_W, 'w:' . $name);
    foreach ($attrs as $k => $v) $el->setAttributeNS(SRL_W, 'w:' . $k, (string)$v);
    $parent->appendChild($el); return $el;
}

/** Keep paragraph/run properties (including the exact '2  Titr' family and point sizes). */
function srl_cell_text(DOMElement $cell, $text, $numeric = false) {
    $xp = srl_xpath($cell->ownerDocument);
    $p = $xp->query('./w:p', $cell)->item(0);
    $pr = $xp->query('./w:r/w:rPr', $p)->item(0) ?: $xp->query('./w:pPr/w:rPr', $p)->item(0);
    $pr = $pr ? $pr->cloneNode(true) : $cell->ownerDocument->createElementNS(SRL_W, 'w:rPr');
    foreach (iterator_to_array($p->childNodes) as $child) {
        if ($child->localName !== 'pPr') $p->removeChild($child);
    }
    foreach (iterator_to_array($xp->query('./w:p', $cell)) as $extra) {
        if ($extra !== $p) $cell->removeChild($extra);
    }
    $run = srl_prop($p, 'r'); $run->appendChild($pr);
    $rtl = $xp->query('./w:rtl', $pr)->item(0) ?: srl_prop($pr, 'rtl');
    $rtl->setAttributeNS(SRL_W, 'w:val', $numeric ? '0' : '1');
    // Explicit LTR run for numbers avoids relying on renderer-specific RTL slash handling.
    if ($numeric) {
        $sizeCs = $xp->query('./w:szCs', $pr)->item(0);
        $size = $xp->query('./w:sz', $pr)->item(0);
        if ($sizeCs && $size) $size->setAttributeNS(SRL_W, 'w:val', $sizeCs->getAttributeNS(SRL_W, 'val'));
        $font = $xp->query('./w:rFonts', $pr)->item(0);
        if ($font) {
            $family = $font->getAttributeNS(SRL_W, 'cs');
            if ($family !== '') foreach (['ascii','hAnsi'] as $a) $font->setAttributeNS(SRL_W, 'w:' . $a, $family);
        }
    }
    $t = srl_prop($run, 't');
    $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
    // XML 1.0 rejects these controls, even when names were imported from a spreadsheet.
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string)$text);
    $t->appendChild($cell->ownerDocument->createTextNode($text));
}

function srl_span(DOMElement $cell, $span) {
    $xp = srl_xpath($cell->ownerDocument); $pr = $xp->query('./w:tcPr', $cell)->item(0);
    $el = $xp->query('./w:gridSpan', $pr)->item(0);
    if (!$el) {
        $el = $cell->ownerDocument->createElementNS(SRL_W, 'w:gridSpan');
        $width = $xp->query('./w:tcW', $pr)->item(0);
        $pr->insertBefore($el, $width->nextSibling);
    }
    $el->setAttributeNS(SRL_W, 'w:val', (string)$span);
}

/** Conservative width estimate in ems, without modifying the displayed text.
 * Advances (rounded UP to 1/1000 em) come from the bundled B Titr's isolated,
 * final, initial and medial glyphs. They are a measurement reference only:
 * the document keeps its original 2 Titr font, which is not bundled.
 * Contextual forms avoid treating every joined Persian letter as a wide isolated glyph.
 * No dependency on GD/Intl or a locally installed font on either platform.
 */
function srl_name_width_em($text) {
    static $forms = [
        'ا' => [260, 301, null, null],
        'ب' => [742, 810, 230, 282],
        'ت' => [742, 810, 230, 282],
        'ث' => [742, 810, 230, 282],
        'ج' => [629, 661, 593, 608],
        'ح' => [629, 661, 593, 608],
        'خ' => [629, 661, 593, 608],
        'د' => [473, 578, null, null],
        'ذ' => [473, 578, null, null],
        'ر' => [438, 476, null, null],
        'ز' => [438, 476, null, null],
        'ژ' => [438, 476, null, null],
        'س' => [978, 1024, 514, 571],
        'ش' => [978, 1024, 514, 573],
        'ص' => [1049, 1084, 609, 646],
        'ض' => [1049, 1084, 609, 646],
        'ط' => [759, 797, 594, 634],
        'ظ' => [759, 797, 594, 634],
        'ع' => [643, 616, 430, 401],
        'غ' => [638, 616, 430, 400],
        'ف' => [757, 816, 329, 335],
        'ق' => [633, 661, 329, 335],
        'ک' => [893, 931, 437, 491],
        'ك' => [893, 931, 437, 491],
        'ل' => [612, 664, 263, 275],
        'م' => [508, 518, 384, 448],
        'ن' => [647, 684, 230, 282],
        'و' => [432, 464, null, null],
        'ه' => [430, 462, 467, 375],
        'ة' => [430, 462, null, null],
        'ی' => [728, 786, 265, 282],
        'ي' => [728, 786, 265, 282],
        'ئ' => [728, 786, 230, 282],
        'أ' => [240, 294, null, null],
        'إ' => [240, 296, null, null],
        'آ' => [473, 399, null, null],
        'ؤ' => [432, 464, null, null],
        'ء' => [388, null, null, null],
        'ۀ' => [430, 462, null, null],
        'پ' => [742, 810, 230, 282],
        'چ' => [629, 661, 593, 608],
        'گ' => [899, 931, 412, 464],
    ];
    // Marks/ZWJ do not add advance; ZWNJ must remain a joining boundary.
    $letters = preg_split('//u', preg_replace('/[\p{M}\x{200D}]/u', '', $text), -1, PREG_SPLIT_NO_EMPTY);
    $total = 0;
    foreach ($letters as $i => $char) {
        if (isset($forms[$char])) {
            $f = $forms[$char];
            $prev = $forms[$letters[$i - 1] ?? ''] ?? null;
            $next = $forms[$letters[$i + 1] ?? ''] ?? null;
            $joinPrev = $prev && $prev[2] !== null && $f[1] !== null;
            $joinNext = $next && $next[1] !== null && $f[2] !== null;
            $form = $joinPrev ? ($joinNext ? 3 : 1) : ($joinNext ? 2 : 0);
            $total += $f[$form] ?? max(array_filter($f, 'is_numeric'));
        } elseif (preg_match('/[\s\p{Zs}]/u', $char)) {
            $total += 200;
        } elseif (preg_match('/[\p{Cf}]/u', $char)) {
            // Includes the ZWNJ joining boundary: no advance, no joining across it.
        } elseif (preg_match('/[0-9۰-۹٠-٩]/u', $char)) {
            $total += 650;
        } else {
            $total += 1100; // Conservative fallback for Latin, punctuation and rare characters.
        }
    }
    return $total / 1000;
}

/** Fit by reducing point size uniformly, never by stretching glyphs or tracking.
 * Original font sizes are a ceiling, not a target to fill the cell with.
 */
function srl_single_line_name(DOMElement $cell, $text) {
    $xp = srl_xpath($cell->ownerDocument);
    $pr = $xp->query('./w:tcPr', $cell)->item(0);
    $width = (int)$xp->query('./w:tcW', $pr)->item(0)->getAttributeNS(SRL_W, 'w');
    // 20 twips per side (~0.35 mm), instead of Word's implicit 108 twips.
    foreach (iterator_to_array($xp->query('./w:noWrap | ./w:tcMar | ./w:tcFitText', $pr)) as $old) $pr->removeChild($old);
    $before = $xp->query('./w:textDirection | ./w:vAlign | ./w:hideMark', $pr)->item(0);
    $wrap = $cell->ownerDocument->createElementNS(SRL_W, 'w:noWrap');
    $pr->insertBefore($wrap, $before);
    $mar = $cell->ownerDocument->createElementNS(SRL_W, 'w:tcMar');
    $pr->insertBefore($mar, $before);
    foreach (['left', 'right'] as $side) srl_prop($mar, $side, ['w' => 20, 'type' => 'dxa']);

    // NBSP keeps multi-word names together without changing visible word spacing.
    // No ZWNJ removal, kashida insertion, character scaling or fitText.
    $display = preg_replace('/[\s\p{Zs}]+/u', "\u{00A0}", trim((string)$text));
    srl_cell_text($cell, $display);
    $runPr = $xp->query('./w:p/w:r/w:rPr', $cell)->item(0);
    $sizeNode = $xp->query('./w:szCs', $runPr)->item(0) ?: $xp->query('./w:sz', $runPr)->item(0);
    $original = $sizeNode ? (int)$sizeNode->getAttributeNS(SRL_W, 'val') : 24;
    $ems = srl_name_width_em($display);
    // Reserve 10% for differences between Titr variants plus 1pt for ink/borders.
    $usablePt = max(1.0, ($width - 40) / 20 - 1.0);
    $halfPoints = $ems > 0 ? min($original, max(2, (int)floor(2 * $usablePt / ($ems * 1.10)))) : $original;
    if ($halfPoints < $original) {
        foreach ($xp->query('./w:p/w:r/w:rPr | ./w:p/w:pPr/w:rPr', $cell) as $rp) {
            foreach (['sz', 'szCs'] as $tag) {
                $size = $xp->query('./w:' . $tag, $rp)->item(0);
                if (!$size) {
                    $size = $cell->ownerDocument->createElementNS(SRL_W, 'w:' . $tag);
                    $anchor = $tag === 'sz'
                        ? './w:szCs | ./w:rtl | ./w:lang'
                        : './w:rtl | ./w:lang';
                    $rp->insertBefore($size, $xp->query($anchor, $rp)->item(0));
                }
                $size->setAttributeNS(SRL_W, 'w:val', (string)$halfPoints);
            }
        }
    }
}

function srl_split_cell(DOMElement $cell, $last, $first) {
    $xp = srl_xpath($cell->ownerDocument);
    $width = $xp->query('./w:tcPr/w:tcW', $cell)->item(0);
    $w = (int)$width->getAttributeNS(SRL_W, 'w');
    $lastW = (int)round($w * 0.62);
    $copy = $cell->cloneNode(true);
    $cell->parentNode->insertBefore($copy, $cell->nextSibling);
    $width->setAttributeNS(SRL_W, 'w:w', (string)$lastW);
    $xp->query('./w:tcPr/w:tcW', $copy)->item(0)->setAttributeNS(SRL_W, 'w:w', (string)($w - $lastW));
    srl_single_line_name($cell, $last);
    srl_single_line_name($copy, $first);
}

/** Preserve title spacing and mixed run formatting; change only zero placeholders. */
function srl_title(DOMElement $p, $year, $total) {
    $years = array_reverse(explode('/', $year)); $i = 0;
    foreach (srl_xpath($p->ownerDocument)->query('.//w:t', $p) as $t) {
        if ($t->textContent === '0000') {
            $t->nodeValue = $years[$i++] ?? '';
        } elseif (strpos($t->textContent, '000') !== false) {
            $t->nodeValue = str_replace('000', (string)$total, $t->textContent);
        }
    }
    if ($i !== 2) throw new RuntimeException('ساختار عنوان قالب لیست مدرسه معتبر نیست.');
    // Keep the source paragraph's exact run order, RTL properties and spaces.
    // The added w:dir/LTR wrapper changed how Word placed the year versus the
    // total block. Only replace the placeholders; never regroup header runs.
}

/** Keep the source three-grade design, but grow rows/columns instead of adding pages. */
function srl_table(DOMElement $table, array $groups, $classOffset = 0, $studentOffset = 0, $layout = 'split') {
    $split = $layout === 'split'; $doc = $table->ownerDocument; $xp = srl_xpath($doc);
    $cols = iterator_to_array($xp->query('./w:tblGrid/w:gridCol', $table));
    $rows = iterator_to_array($xp->query('./w:tr', $table));
    if (count($cols) !== 12 || count($rows) !== 32) throw new RuntimeException('ساختار جدول قالب لیست مدرسه معتبر نیست.');
    $slots = []; $widths = []; $studentRows = 30;
    for ($b=0; $b<3; $b++) {
        $classes = $groups[$b]['classes'] ?? [];
        $slots[$b] = max(3, count($classes));
        foreach ($classes as $class) $studentRows = max($studentRows, count($class['students']));
        $total = 0;
        for ($c=1; $c<=3; $c++) $total += (int)$cols[$b*4+$c]->getAttributeNS(SRL_W, 'w');
        for ($c=0; $c<$slots[$b]; $c++) $widths[$b][$c] = (int)floor($total*($c+1)/$slots[$b]) - (int)floor($total*$c/$slots[$b]);
    }
    // Reuse the last source student row for every additional student, with no truncation.
    for ($i=29; $i<$studentRows; $i++) $table->insertBefore($rows[30]->cloneNode(true), $rows[31]);
    $grid = $xp->query('./w:tblGrid', $table)->item(0);
    foreach (iterator_to_array($grid->childNodes) as $old) $grid->removeChild($old);
    for ($b=0; $b<3; $b++) {
        srl_prop($grid, 'gridCol', ['w'=>$cols[$b*4]->getAttributeNS(SRL_W, 'w')]);
        foreach ($widths[$b] as $w) {
            $last = (int)round($w*.62);
            srl_prop($grid, 'gridCol', ['w'=>$split ? $last : $w]);
            if ($split) srl_prop($grid, 'gridCol', ['w'=>$w-$last]);
        }
    }
    $rows = iterator_to_array($xp->query('./w:tr', $table));
    foreach ($rows as $r=>$row) {
        $cells = iterator_to_array($xp->query('./w:tc', $row));
        $trPr = $xp->query('./w:trPr', $row)->item(0);
        if (!$xp->query('./w:cantSplit', $trPr)->length) $trPr->insertBefore($doc->createElementNS(SRL_W, 'w:cantSplit'), $xp->query('./w:trHeight', $trPr)->item(0));
        if ($r === count($rows)-1) {
            foreach ([0,2,4] as $b=>$idx) {
                srl_span($cells[$idx], $slots[$b]*($split ? 2 : 1) + ($b===0 ? 1 : 0));
                foreach ($xp->query('.//w:t', $cells[$idx]) as $t) if (strpos($t->textContent,'00')!==false) $t->nodeValue=str_replace('00',(string)($groups[$b]['total']??0),$t->textContent);
            }
            continue;
        }
        foreach ($cells as $cell) $row->removeChild($cell);
        for ($b=0; $b<3; $b++) {
            $number = $cells[$b*4]->cloneNode(true); $row->appendChild($number);
            if ($r>=2) srl_cell_text($number, $r-1, true);
            for ($c=0; $c<$slots[$b]; $c++) {
                $cell = $cells[$b*4+min($c,2)+1]->cloneNode(true); $row->appendChild($cell);
                $xp->query('./w:tcPr/w:tcW', $cell)->item(0)->setAttributeNS(SRL_W, 'w:w', (string)$widths[$b][$c]);
                $class = $groups[$b]['classes'][$c] ?? null;
                if ($r===0) {
                    if ($split) srl_span($cell,2);
                    srl_cell_text($cell,$class['code']??'',true);
                } elseif ($r===1) {
                    if ($split) srl_split_cell($cell,'نام خانوادگی','نام');
                } else {
                    $student = $class['students'][$r-2] ?? [];
                    if ($split) srl_split_cell($cell,$student['last_name']??'',$student['first_name']??'');
                    else srl_single_line_name($cell,trim(trim($student['last_name']??'').' '.trim($student['first_name']??'')));
                }
            }
        }
    }
}

/** Scale physical OOXML dimensions, not character spacing or horizontal glyph scale.
 * Also used on styles.xml so inherited cell padding/paragraph spacing cannot stay A3-sized.
 */
function srl_scale_dimensions(DOMDocument $doc, $factor) {
    $xp = srl_xpath($doc);
    $scaleAttr = function ($node, $name, $minimum = 0, $floor = false) use ($factor) {
        if (!$node->hasAttributeNS(SRL_W, $name)) return;
        $old = (float)$node->getAttributeNS(SRL_W, $name);
        $scaled = $floor ? floor($old * $factor) : round($old * $factor);
        if ($old > 0) $scaled = max($minimum, $scaled);
        $node->setAttributeNS(SRL_W, 'w:' . $name, (string)(int)$scaled);
    };
    foreach ($xp->query('//w:rPr/w:sz | //w:rPr/w:szCs') as $el) $scaleAttr($el, 'val', 2, true);
    foreach ($xp->query('//w:gridCol') as $el) $scaleAttr($el, 'w', 1);
    foreach ($xp->query('//w:tcW | //w:tblW | //w:tblInd | //w:tblCellSpacing | //w:tcMar/* | //w:tblCellMar/*') as $el) {
        if ($el->getAttributeNS(SRL_W, 'type') === 'dxa') $scaleAttr($el, 'w');
    }
    foreach ($xp->query('//w:trHeight') as $el) $scaleAttr($el, 'val', 1);
    foreach ($xp->query('//w:pPr/w:ind') as $el) {
        foreach (['left','right','start','end','firstLine','hanging'] as $a) $scaleAttr($el, $a);
    }
    foreach ($xp->query('//w:pPr/w:spacing') as $el) {
        foreach (['before','after'] as $a) $scaleAttr($el, $a);
        // Auto line spacing is in 240ths of a line, NOT twips. It follows the font size.
        if (in_array($el->getAttributeNS(SRL_W, 'lineRule'), ['exact','atLeast'], true)) $scaleAttr($el, 'line', 1);
    }
    foreach ($xp->query('//w:tabs/w:tab') as $el) $scaleAttr($el, 'pos');
    foreach ($xp->query('//w:tblBorders/* | //w:tcBorders/* | //w:pBdr/*') as $el) {
        $scaleAttr($el, 'sz', 2); $scaleAttr($el, 'space');
    }
    foreach ($xp->query('//w:docGrid') as $el) $scaleAttr($el, 'linePitch', 1);
    foreach ($xp->query('//w:cols') as $el) $scaleAttr($el, 'space');
}

/** Set paragraph properties in OOXML schema order; override inherited pagination. */
function srl_print_paragraph(DOMElement $p, $cell = false) {
    $xp=srl_xpath($p->ownerDocument);
    $pr=$xp->query('./w:pPr',$p)->item(0);
    if (!$pr) { $pr=$p->ownerDocument->createElementNS(SRL_W,'w:pPr'); $p->insertBefore($pr,$p->firstChild); }
    $order=explode(' ','pStyle keepNext keepLines pageBreakBefore framePr widowControl numPr suppressLineNumbers pBdr shd tabs suppressAutoHyphens kinsoku wordWrap overflowPunct topLinePunct autoSpaceDE autoSpaceDN bidi adjustRightInd snapToGrid spacing ind contextualSpacing mirrorIndents suppressOverlap jc textDirection textAlignment textboxTightWrap outlineLvl divId cnfStyle rPr sectPr pPrChange');
    $props=['keepNext'=>['val'=>0],'keepLines'=>['val'=>0],'pageBreakBefore'=>['val'=>0],
        'widowControl'=>['val'=>0],'snapToGrid'=>['val'=>0],
        'spacing'=>['before'=>0,'after'=>0,'beforeAutospacing'=>0,'afterAutospacing'=>0,'line'=>240,'lineRule'=>'auto'],
        'ind'=>['left'=>0,'right'=>0,'firstLine'=>0]];
    if ($cell) $props['textAlignment']=['val'=>'center'];
    foreach ($props as $tag=>$attrs) {
        foreach (iterator_to_array($xp->query('./w:'.$tag,$pr)) as $old) $pr->removeChild($old);
        $node=srl_prop($pr,$tag,$attrs); $rank=array_search($tag,$order,true);
        foreach (iterator_to_array($pr->childNodes) as $other) {
            if ($other===$node) continue;
            $otherRank=array_search($other->localName,$order,true);
            if ($otherRank!==false && $otherRank>$rank) { $pr->insertBefore($node,$other); break; }
        }
    }
}

/** Center the natural text line, not a row-sized exact line box.
 * Word uses the paragraph mark's font when measuring a line, even for a small
 * fitted name. Match it to the visible runs to avoid a hidden larger baseline.
 * Rows remain exact; the font-fit pass reserves room for natural Titr leading.
 */
function srl_center_cell(DOMElement $cell) {
    $doc=$cell->ownerDocument; $xp=srl_xpath($doc);
    $pr=$xp->query('./w:tcPr',$cell)->item(0);
    foreach (iterator_to_array($xp->query('./w:vAlign',$pr)) as $old) $pr->removeChild($old);
    $align=$doc->createElementNS(SRL_W,'w:vAlign');$align->setAttributeNS(SRL_W,'w:val','center');
    $pr->insertBefore($align,$xp->query('./w:hideMark | ./w:headers | ./w:tcPrChange',$pr)->item(0));
    foreach ($xp->query('./w:p',$cell) as $p) {
        srl_print_paragraph($p,true);
        $max=0;
        foreach ($xp->query('./w:r[w:t]/w:rPr/w:sz | ./w:r[w:t]/w:rPr/w:szCs',$p) as $sz) $max=max($max,(int)$sz->getAttributeNS(SRL_W,'val'));
        if (!$max) continue; // Empty vertical-merge continuation, no visible baseline.
        $pPr=$xp->query('./w:pPr',$p)->item(0);
        $mark=$xp->query('./w:rPr',$pPr)->item(0);
        if (!$mark) $mark=srl_prop($pPr,'rPr');
        foreach (['sz','szCs'] as $tag) {
            $sz=$xp->query('./w:'.$tag,$mark)->item(0);
            if (!$sz) {
                $sz=$doc->createElementNS(SRL_W,'w:'.$tag);
                $mark->insertBefore($sz,$xp->query($tag==='sz'?'./w:szCs | ./w:rtl | ./w:lang':'./w:rtl | ./w:lang',$mark)->item(0));
            }
            $sz->setAttributeNS(SRL_W,'w:val',(string)$max);
        }
    }
}

function srl_max_font(DOMNode $node) {
    $xp=srl_xpath($node->ownerDocument); $max=2;
    foreach ($xp->query('.//w:sz | .//w:szCs',$node) as $sz) $max=max($max,(int)$sz->getAttributeNS(SRL_W,'val'));
    return $max;
}

/** Keep the page width budget, but let paired name columns follow their content.
 * Give AutoFit room for 8pt names before resorting to smaller text.
 */
function srl_balance_name_grid(DOMElement $table, array $grid) {
    $xp=srl_xpath($table->ownerDocument);
    $needed=array_fill(0,count($grid),20);
    foreach ($xp->query('./w:tr[position()>2 and position()<last()]',$table) as $row) {
        foreach ($xp->query('./w:tc',$row) as $i=>$cell) {
            $needed[$i]=max($needed[$i],(int)ceil(20*(8*srl_name_width_em($cell->textContent)*1.10+1)));
        }
    }
    $offset=0;
    foreach ($xp->query('./w:tr[1]/w:tc',$table) as $cell) {
        $span=$xp->query('./w:tcPr/w:gridSpan',$cell)->item(0);
        $n=$span?(int)$span->getAttributeNS(SRL_W,'val'):1;
        if ($n===2) {
            $total=$grid[$offset]+$grid[$offset+1];
            $last=$needed[$offset];$first=$needed[$offset+1];
            if ($total>40) {
                $w=$last+$first<=$total
                    ? max($last,min($grid[$offset],$total-$first))
                    : (int)round($total*$last/($last+$first));
                $grid[$offset]=max(20,min($total-20,$w));
                $grid[$offset+1]=$total-$grid[$offset];
            }
        }
        $offset+=$n;
    }
    return $grid;
}

/** One physical sheet with at least 30 numbered student rows.
 * Budget all tables, title lines and the terminal paragraph before fitting.
 * Start with a 2em row budget; the 8pt target can use available space down to
 * 1.8em natural leading plus borders, otherwise reduce text to keep one page.
 */
function srl_fit_page(DOMDocument $doc, DOMDocument $styles, $paper) {
    $xp=srl_xpath($doc);
    [$targetW,$targetH,$code]=$paper==='A3' ? [23814,16839,8] : [16838,11906,9];
    $tables=iterator_to_array($xp->query('//w:tbl'));
    $sourceW=0; $sourceH=0; $sourceGrids=[];
    foreach ($tables as $i=>$table) {
        $grid=[];
        foreach ($xp->query('./w:tblGrid/w:gridCol',$table) as $col) $grid[]=(int)$col->getAttributeNS(SRL_W,'w');
        $sourceGrids[$i]=$grid; $sourceW=max($sourceW,array_sum($grid));
        foreach ($xp->query('./w:tr',$table) as $row) {
            $height=$xp->query('./w:trPr/w:trHeight',$row)->item(0);
            $h=max((int)$height->getAttributeNS(SRL_W,'val'),srl_max_font($row)*20+64); // 2x font leading plus border clearance.
            $height->setAttributeNS(SRL_W,'w:val',(string)$h); $sourceH+=$h;
        }
    }
    $body=$xp->query('/w:document/w:body')->item(0);
    $titles=[]; $tails=[];
    foreach ($xp->query('./w:p',$body) as $p) {
        if (trim($p->textContent)!=='') { $titles[]=$p; $sourceH+=2*srl_max_font($p)*20; }
        else $tails[]=$p;
    }
    if (!$sourceW || !$sourceH) throw new RuntimeException('ابعاد جدول مدرسه معتبر نیست.');
    // Margins and padding are truly zero. Reserves are content-size safety, not margins.
    $safeMargin=340; // 6 mm safe border on all four sides.
    $xFactor=($targetW-2*$safeMargin-120)/$sourceW;
    $factor=min(1.0,$xFactor,($targetH-2*$safeMargin-240-40*count($tails))/$sourceH);
    if ($factor<0.12) throw new RuntimeException('حجم فهرست برای یک صفحه بیش از حد زیاد است؛ اندازهٔ A3 را انتخاب کنید. هیچ نامی حذف نشد.');
    srl_scale_dimensions($doc,$factor); srl_scale_dimensions($styles,$factor);
    // Single also in inherited styles, not just the table's visible paragraphs.
    foreach (srl_xpath($styles)->query('//w:pPr/w:spacing') as $spacing) {
        $spacing->setAttributeNS(SRL_W,'w:line','240');
        $spacing->setAttributeNS(SRL_W,'w:lineRule','auto');
    }
    foreach ($xp->query('//w:sectPr') as $section) {
        $size=$xp->query('./w:pgSz',$section)->item(0);
        if (!$size) throw new RuntimeException('اندازهٔ صفحه در قالب مشخص نشده است.');
        foreach (['w'=>$targetW,'h'=>$targetH,'orient'=>'landscape','code'=>$code] as $a=>$v) $size->setAttributeNS(SRL_W,'w:'.$a,(string)$v);
        $mar=$xp->query('./w:pgMar',$section)->item(0);
        if (!$mar) { $mar=$doc->createElementNS(SRL_W,'w:pgMar');$section->insertBefore($mar,$size->nextSibling); }
        foreach (['top','bottom','left','right','header','footer','gutter'] as $a) $mar->setAttributeNS(SRL_W,'w:'.$a,in_array($a,['top','bottom','left','right'],true)?(string)$safeMargin:'0');
        foreach ($xp->query('./w:docGrid',$section) as $g) $g->setAttributeNS(SRL_W,'w:type','none');
    }
    foreach (iterator_to_array($xp->query('//w:br[@w:type="page"] | //w:lastRenderedPageBreak')) as $br) $br->parentNode->removeChild($br);
    foreach ($titles as $p) srl_print_paragraph($p);
    foreach ($tails as $p) {
        srl_print_paragraph($p);
        foreach ($xp->query('.//w:sz | .//w:szCs',$p) as $sz) $sz->setAttributeNS(SRL_W,'w:val','2');
    }
    foreach ($tables as $i=>$table) {
        $grid=[];
        foreach ($xp->query('./w:tblGrid/w:gridCol',$table) as $c=>$col) {
            $grid[$c]=(int)round($sourceGrids[$i][$c]*$xFactor);
            $col->setAttributeNS(SRL_W,'w:w',(string)$grid[$c]);
        }
        $grid=srl_balance_name_grid($table,$grid);
        foreach ($xp->query('./w:tblGrid/w:gridCol',$table) as $c=>$col) $col->setAttributeNS(SRL_W,'w:w',(string)$grid[$c]);
        // Word Table Options: Automatically resize to fit contents = checked.
        // Retain the preferred page-bounded width; fit text before AutoFit runs.
        $layout=$xp->query('./w:tblPr/w:tblLayout',$table)->item(0);
        $layout->setAttributeNS(SRL_W,'w:type','autofit');
        $tblW=$xp->query('./w:tblPr/w:tblW',$table)->item(0);
        $tblW->setAttributeNS(SRL_W,'w:type','dxa'); $tblW->setAttributeNS(SRL_W,'w:w',(string)array_sum($grid));
        $rows=$xp->query('./w:tr',$table);
        foreach ($rows as $r=>$row) {
            $height=$xp->query('./w:trPr/w:trHeight',$row)->item(0);
            $height->setAttributeNS(SRL_W,'w:hRule','exact');
            $offset=0;
            foreach ($xp->query('./w:tc',$row) as $cell) {
                $pr=$xp->query('./w:tcPr',$cell)->item(0);
                $span=$xp->query('./w:gridSpan',$pr)->item(0); $n=$span?(int)$span->getAttributeNS(SRL_W,'val'):1;
                $width=$xp->query('./w:tcW',$pr)->item(0);
                $width->setAttributeNS(SRL_W,'w:w',(string)array_sum(array_slice($grid,$offset,$n)));
                $width->setAttributeNS(SRL_W,'w:type','dxa'); $offset+=$n;
                foreach (iterator_to_array($xp->query('./w:tcMar',$pr)) as $old) $pr->removeChild($old);
                $mar=$doc->createElementNS(SRL_W,'w:tcMar');
                $pr->insertBefore($mar,$xp->query('./w:textDirection | ./w:vAlign | ./w:hideMark',$pr)->item(0));
                foreach (['top','left','bottom','right'] as $a) srl_prop($mar,$a,['w'=>0,'type'=>'dxa']);
                foreach (iterator_to_array($xp->query('./w:noWrap',$pr)) as $old) $pr->removeChild($old);
                $pr->insertBefore($doc->createElementNS(SRL_W,'w:noWrap'),$mar);
                // Extra class columns can be narrower than the original header/name cells.
                // Fit every horizontal cell (including class codes and headers), never clip.
                if (!$xp->query('./w:textDirection',$pr)->length) {
                    $ems=srl_name_width_em($cell->textContent);
                    $limit=$ems>0 ? (int)floor(2*max(0,(int)$width->getAttributeNS(SRL_W,'w')/20-1)/($ems*1.10)) : PHP_INT_MAX;
                    if ($limit<2) throw new RuntimeException('متن یک سلول برای چاپ تک‌صفحه‌ای بیش از حد بلند است؛ A3 را انتخاب کنید. هیچ متنی حذف نشد.');
                    $max=srl_max_font($cell);
                    $isName=$r>=2 && $r<$rows->length-1 && $xp->evaluate('string(./w:p/w:r/w:rPr/w:rtl/@w:val)',$cell)==='1';
                    if ($isName) {
                        // Target >=8pt. User explicitly prioritizes one page when
                        // 8pt cannot fit. 1.8em covers B Titr's 1.7603em Windows
                        // leading; border clearance is reserved separately.
                        $heightLimit=(int)floor(((int)$height->getAttributeNS(SRL_W,'val')-max(4,(int)ceil(48*$factor)))/18);
                        $size=min(max(16,$max),$limit,$heightLimit);
                        if ($size<2) throw new RuntimeException('فضای چاپ تک‌صفحه‌ای برای متن کافی نیست؛ A3 را انتخاب کنید.');
                        foreach ($xp->query('.//w:sz | .//w:szCs',$cell) as $sz) $sz->setAttributeNS(SRL_W,'w:val',(string)$size);
                    } elseif ($limit<$max) {
                        foreach ($xp->query('.//w:sz | .//w:szCs',$cell) as $sz) {
                            $sz->setAttributeNS(SRL_W,'w:val',(string)max(2,(int)floor((int)$sz->getAttributeNS(SRL_W,'val')*$limit/$max)));
                        }
                    }
                }
                srl_center_cell($cell);
            }
        }
    }
    return $factor;
}

function srl_generate(array $data, $template = null, $layout = 'split', $paper = 'A4') {
    if (!in_array($paper, ['A4','A3'], true)) throw new RuntimeException('اندازهٔ کاغذ معتبر نیست.');
    if (!in_array($layout, ['split', 'combined'], true)) throw new RuntimeException('نوع چیدمان لیست معتبر نیست.');
    if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) throw new RuntimeException('افزونه‌های Zip و DOM در PHP باید فعال باشند.');
    if (!empty($data['missing_class'])) throw new RuntimeException('تعدادی دانش‌آموز در سال جاری کلاس ندارند. ابتدا کلاس آن‌ها را تعیین کنید تا از فهرست حذف نشوند.');
    if (empty($data['groups'])) throw new RuntimeException('برای سال تحصیلی پیش‌فرض کلاسی ثبت نشده است.');
    $template = $template ?: srl_template_path();
    if (!is_file($template)) throw new RuntimeException('قالب assets/templates/school-students.docx پیدا نشد.');
    $tmp = tempnam(sys_get_temp_dir(), 'srl');
    if ($tmp === false) throw new RuntimeException('ساخت فایل موقت ممکن نشد.');
    $zip = new ZipArchive(); $opened = false;
    try {
        if (!copy($template, $tmp) || $zip->open($tmp) !== true) throw new RuntimeException('بازکردن قالب Word ممکن نشد.');
        $opened = true;
        $xml = $zip->getFromName('word/document.xml');
        $doc = new DOMDocument(); $doc->preserveWhiteSpace = true;
        if (!$xml || !$doc->loadXML($xml, LIBXML_NONET)) throw new RuntimeException('قالب Word معتبر نیست.');
        $xp = srl_xpath($doc);
        $body = $xp->query('/w:document/w:body')->item(0);
        $title = $xp->query('./w:p', $body)->item(0);
        $table = $xp->query('./w:tbl', $body)->item(0);
        $tail = $xp->query('./w:p', $body)->item(1);
        $section = $xp->query('./w:sectPr', $body)->item(0);
        if (!$title || !$table || !$tail || !$section) throw new RuntimeException('ساختار قالب Word معتبر نیست.');
        foreach (iterator_to_array($body->childNodes) as $child) if ($child !== $section) $body->removeChild($child);
        // All students and all classes are on the same sheet; never paginate at 29/3.
        foreach (array_chunk($data['groups'], 3) as $groups) {
            $p = $title->cloneNode(true); srl_title($p, $data['year'], $data['total']);
            $t = $table->cloneNode(true); srl_table($t, $groups, 0, 0, $layout);
            $body->insertBefore($p, $section); $body->insertBefore($t, $section);
            $body->insertBefore($tail->cloneNode(true), $section);
        }
        $styleXml = $zip->getFromName('word/styles.xml');
        $styles = new DOMDocument(); $styles->preserveWhiteSpace = true;
        if (!$styleXml || !$styles->loadXML($styleXml, LIBXML_NONET)) throw new RuntimeException('قالب‌بندی Word معتبر نیست.');
        srl_fit_page($doc, $styles, $paper);
        if (!$zip->addFromString('word/styles.xml', $styles->saveXML())) throw new RuntimeException('ذخیرهٔ قالب‌بندی چاپ ممکن نشد.');
        if (!$zip->addFromString('word/document.xml', $doc->saveXML())) throw new RuntimeException('ذخیرهٔ سند ممکن نشد.');
        if (!$zip->close()) throw new RuntimeException('بستن فایل Word ممکن نشد.');
        $opened = false;
        $result = file_get_contents($tmp);
        if ($result === false) throw new RuntimeException('خواندن فایل Word ممکن نشد.');
        return $result;
    } finally {
        if ($opened) $zip->close();
        @unlink($tmp);
    }
}
