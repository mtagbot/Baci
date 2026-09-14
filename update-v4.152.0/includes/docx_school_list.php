<?php
/** Full-school roster, v4.152.0. Transform the school's DOCX, never rebuild its design.
 * Template: A3 landscape, three grade blocks, three classes/block, 29 student rows.
 * Additional classes/students use continuation sheets, not narrower columns or truncation.
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

/** Numeric class codes are deliberately section/grade, e.g. 1/7. */
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
    return ['grade' => $g, 'code' => ($g > 0 && $section > 0) ? $section . '/' . $g : $name];
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

function srl_split_cell(DOMElement $cell, $last, $first) {
    $xp = srl_xpath($cell->ownerDocument);
    $width = $xp->query('./w:tcPr/w:tcW', $cell)->item(0);
    $w = (int)$width->getAttributeNS(SRL_W, 'w');
    $lastW = (int)round($w * 0.62);
    $copy = $cell->cloneNode(true);
    $cell->parentNode->insertBefore($copy, $cell->nextSibling);
    $width->setAttributeNS(SRL_W, 'w:w', (string)$lastW);
    $xp->query('./w:tcPr/w:tcW', $copy)->item(0)->setAttributeNS(SRL_W, 'w:w', (string)($w - $lastW));
    srl_cell_text($cell, $last); srl_cell_text($copy, $first);
    // Do not add w:tcFitText: Word fits text by changing character spacing,
    // which stretches short Persian names and even the two column headers.
    // Keep the template's centered paragraphs, natural glyph widths and font sizes.
    // Long names may wrap normally; never stretch or condense them to fill a cell.
}

/** Preserve title spacing and mixed run formatting; change only zero placeholders. */
function srl_title(DOMElement $p, $year, $total) {
    $years = explode('/', $year); $i = 0; $yearRuns = [];
    foreach (srl_xpath($p->ownerDocument)->query('.//w:t', $p) as $t) {
        if ($t->textContent === '0000') {
            $t->nodeValue = $years[$i++] ?? '';
            $yearRuns[] = $t->parentNode;
        } elseif (strpos($t->textContent, '000') !== false) {
            $t->nodeValue = str_replace('000', (string)$total, $t->textContent);
        }
    }
    if ($i !== 2) throw new RuntimeException('ساختار عنوان قالب لیست مدرسه معتبر نیست.');
    // Keep the two years, original en dash and original spaces as one LTR range.
    // Independent RTL runs would reorder 1405 – 1406 in some Word renderers.
    $dir = $p->ownerDocument->createElementNS(SRL_W, 'w:dir');
    $dir->setAttributeNS(SRL_W, 'w:val', 'ltr');
    $p->insertBefore($dir, $yearRuns[0]);
    $run = $yearRuns[0];
    while ($run) {
        $next = $run->nextSibling;
        foreach (srl_xpath($p->ownerDocument)->query('./w:rPr/w:rtl', $run) as $rtl) $rtl->setAttributeNS(SRL_W, 'w:val', '0');
        $dir->appendChild($run);
        if ($run === $yearRuns[1]) break;
        $run = $next;
    }
}

function srl_table(DOMElement $table, array $groups, $classOffset, $studentOffset) {
    $xp = srl_xpath($table->ownerDocument);
    $cols = iterator_to_array($xp->query('./w:tblGrid/w:gridCol', $table));
    if (count($cols) !== 12) throw new RuntimeException('ستون‌های قالب لیست مدرسه معتبر نیست.');
    foreach ($cols as $i => $col) {
        if ($i % 4 === 0) continue;
        $w = (int)$col->getAttributeNS(SRL_W, 'w'); $last = (int)round($w * .62);
        $col->setAttributeNS(SRL_W, 'w:w', (string)$last);
        $new = $col->cloneNode(true); $new->setAttributeNS(SRL_W, 'w:w', (string)($w - $last));
        $col->parentNode->insertBefore($new, $col->nextSibling);
    }
    $rows = iterator_to_array($xp->query('./w:tr', $table));
    if (count($rows) !== 32) throw new RuntimeException('ردیف‌های قالب لیست مدرسه معتبر نیست.');
    foreach ($rows as $r => $row) {
        $cells = iterator_to_array($xp->query('./w:tc', $row));
        $trPr = $xp->query('./w:trPr', $row)->item(0);
        $height = $xp->query('./w:trHeight', $trPr)->item(0);
        $cant = $table->ownerDocument->createElementNS(SRL_W, 'w:cantSplit');
        $trPr->insertBefore($cant, $height);
        if ($r === 31) {
            // Footer gridSpans must cover the new 21-column grid, keeping all original borders.
            foreach ([0, 2, 4] as $b => $idx) {
                srl_span($cells[$idx], $b === 0 ? 7 : 6);
                foreach ($xp->query('.//w:t', $cells[$idx]) as $t) {
                    if (strpos($t->textContent, '00') !== false) $t->nodeValue = str_replace('00', (string)($groups[$b]['total'] ?? 0), $t->textContent);
                }
            }
            continue;
        }
        foreach ($cells as $i => $cell) {
            $block = intdiv($i, 4); $slot = $i % 4;
            if ($slot === 0) {
                if ($r >= 2) srl_cell_text($cell, $studentOffset + $r - 1, true);
                continue;
            }
            $class = $groups[$block]['classes'][$classOffset + $slot - 1] ?? null;
            if ($r === 0) {
                srl_span($cell, 2); srl_cell_text($cell, $class['code'] ?? '', true);
            } elseif ($r === 1) {
                srl_split_cell($cell, 'نام خانوادگی', 'نام');
            } else {
                $student = $class['students'][$studentOffset + $r - 2] ?? [];
                srl_split_cell($cell, $student['last_name'] ?? '', $student['first_name'] ?? '');
            }
        }
    }
}

function srl_generate(array $data, $template = null) {
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
        $page = 0;
        foreach (array_chunk($data['groups'], 3) as $groups) {
            $maxClasses = max(array_map(function ($g) { return count($g['classes']); }, $groups));
            for ($c = 0; $c < $maxClasses; $c += 3) {
                $maxStudents = 0;
                foreach ($groups as $g) foreach (array_slice($g['classes'], $c, 3) as $class) $maxStudents = max($maxStudents, count($class['students']));
                for ($offset = 0; $offset < max(1, $maxStudents); $offset += 29) {
                    $p = $title->cloneNode(true); srl_title($p, $data['year'], $data['total']);
                    if ($page++ > 0) {
                        $pr = $xp->query('./w:pPr', $p)->item(0);
                        $pr->insertBefore($doc->createElementNS(SRL_W, 'w:pageBreakBefore'), $pr->firstChild);
                    }
                    $t = $table->cloneNode(true); srl_table($t, $groups, $c, $offset);
                    $body->insertBefore($p, $section); $body->insertBefore($t, $section);
                    $body->insertBefore($tail->cloneNode(true), $section);
                }
            }
        }
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
