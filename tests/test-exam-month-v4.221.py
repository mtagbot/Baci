#!/usr/bin/env python3
"""Structural acceptance checks for v4.221 exam-design changes and packages."""
from pathlib import Path
from zipfile import ZipFile
import hashlib, re
ROOT=Path(__file__).resolve().parents[1]
site=ROOT/'update-v4.221.0'
checks={
 'teacher-panel.php':['طراحی کلاسی','طراحی پایه','تدریس دارید','تدریس ندارید','grade_classes[]','<th>تاریخ</th>'],
 'exam-print.php':['grade_classes','seatLayout','چینش','examMonth'],
 'exam-design-api.php':['exam_catalog_current_month()','resolved_grade','es.grade_level=?','exam_design_bank_has_grade_column'],
 'includes/class_exam_groups.php':['ceg_grade_class_options','selectedClasses','class_exam_group_members'],
 'includes/class_exam_group_choice.php':['selected_classes[]','تدریس ندارید'],
 'includes/bot_helpers.php':['$dayEmoji',' روز: '],
}
for filename,needles in checks.items():
 text=(site/filename).read_text(encoding='utf-8')
 for needle in needles: assert needle in text, f'{filename} missing {needle!r}'
assert 'bankMonth' not in (site/'exam-print.php').read_text(encoding='utf-8')
assert 'سال/ماه' not in (site/'teacher-panel.php').read_text(encoding='utf-8')
assert ':: روز:' not in (site/'includes/bot_helpers.php').read_text(encoding='utf-8')
for name,expected in [('MODIFIED-FILES-V4.221.0.zip',''),('SchoolDesk-FIX-v2.145.0.zip','SchoolDeskPro/www/')]:
 path=ROOT/name
 assert path.is_file() and path.stat().st_size>1000, f'missing {name}'
 with ZipFile(path) as z:
  assert z.testzip() is None
  names=z.namelist()
  assert (all(expected in n for n in names) if expected else all(not n.startswith('/') for n in names)), f'unexpected archive root in {name}'
  assert len(names)==8, (name,names)
  digest=hashlib.sha256(path.read_bytes()).hexdigest()
  assert digest in (ROOT/'V4.221.0-SHA256SUMS.txt').read_text()
print('OK v4.221 feature structure and both correction packages')
