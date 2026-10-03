// آلبوم عکس: لیستی، یک برگه برای هر کلاس + کاربران متصل بله + ترتیب گزارش — v4.176.0
import assert from 'node:assert/strict';
import { run, req, loginAdmin } from './harness/lib.mjs';

let n = 0;
const check = (v, m) => { assert(v, m); n++; };

/* داده: یک کلاس با ۹ دانش‌آموز (برگه‌اش باید یکی باشد) و یک کلاس ۴۰ نفره */
await run(String.raw`<?php require_once '/www/includes/functions.php';
DB::execute("DELETE FROM students WHERE national_id LIKE '077%'");
$rows = [];
for ($i=1;$i<=9;$i++) $rows[] = DB::execute("INSERT INTO students (first_name,last_name,father_name,national_id,class_name,grade_level,academic_year,status,password,photo_url) VALUES (?,?,?,?,?,?,?,?,?,?)",
  ['نام'.$i,'نام‌خانوادگی'.$i,'پدر'.$i,'07700000'.($i<10?'0'.$i:$i),'۷/۱','هفتم','1403/1404','active','x', $i%2 ? '' : 'uploads/photos/07700000'.$i.'.jpg']);
for ($i=1;$i<=40;$i++) DB::execute("INSERT INTO students (first_name,last_name,father_name,national_id,class_name,grade_level,academic_year,status,password) VALUES (?,?,?,?,?,?,?,?,?)",
  ['ب'.$i,'خانوادگی'.$i,'پدر'.$i,'0780000'.sprintf('%03d',$i),'۷/۲','هفتم','1403/1404','active','x']);
echo 'SEEDED';`);

/* ── ۱) آلبوم: جدول لیستی، یک برگه، ستون‌های درست ───────────────────── */
const album = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/photo_album.php';
$students = pab_students_of_class('۷/۱','1403/1404');
echo 'count=' . count($students) . ';';
echo 'has_father=' . (isset($students[0]['father_name']) ? 'yes' : 'no') . ';';
echo 'has_nid=' . (isset($students[0]['national_id']) ? 'yes' : 'no') . ';';
$html = pab_render_print_html('۷/۱','هفتم',$students,'مدرسهٔ آزمایشی',false);
echo 'sheets=' . substr_count($html, 'class="sheet"') . ';';
echo 'table=' . (strpos($html, 'class="list"') !== false ? 'yes' : 'no') . ';';
echo 'grid=' . (strpos($html, 'class="grid"') !== false ? 'yes' : 'no') . ';';
echo 'halves=' . substr_count($html, 'class="half"') . ';';
echo 'thead=' . substr_count($html, '<thead>') . ';';
echo 'cols=' . substr_count($html, '<th>') . ';';
echo 'rows=' . substr_count($html, '<tr style="height:') . ';';
echo 'nid=' . (strpos($html, 'کد ملی') !== false ? 'present' : 'gone') . ';';
echo 'father=' . (strpos($html, 'نام پدر') !== false ? 'present' : 'gone') . ';';
echo 'note=' . (strpos($html, 'یادداشت') !== false ? 'yes' : 'no') . ';';
echo 'footer1=' . (strpos($html, 'یک برگه برای هر کلاس') !== false ? 'present' : 'gone') . ';';
echo 'footer2=' . (strpos($html, 'توضیح کوتاه کنار هر نام') !== false ? 'present' : 'gone') . ';';
preg_match('/<tr style="height:([\d.]+)mm/U', $html, $m1); echo 'rowH=' . $m1[1] . ';';
preg_match('/class="ph" style="height:([\d.]+)mm;width:([\d.]+)mm"/', $html, $m2); echo 'photo=' . $m2[1] . 'x' . $m2[2] . ';';`);
check(album.out.includes('count=9'), 'the class students are found: ' + album.out.slice(0, 60));
check(album.out.includes('has_father=yes') && album.out.includes('has_nid=yes'), 'father name and national id are available for the list');
check(album.out.includes('sheets=1'), 'ONE sheet per class: ' + album.out.match(/sheets=\d+/)[0]);
check(album.out.includes('table=yes') && album.out.includes('grid=no'), 'the album is a list now, not a grid');
/* v4.177.0: چیدمان دوستونه — دو جدول کنار هم، بدون کد ملی و نام پدر */
check(album.out.includes('halves=2'), 'two columns side by side: ' + album.out.match(/halves=\d+/)[0]);
check(album.out.includes('thead=2'), 'each half has its own header row: ' + album.out.match(/thead=\d+/)[0]);
check(album.out.includes('cols=8'), 'four columns per half (ردیف، عکس، نام، یادداشت): ' + album.out.match(/cols=\d+/)[0]);
check(album.out.includes('rows=9'), 'one row per student: ' + album.out.match(/rows=\d+/)[0]);
check(album.out.includes('nid=gone'), 'national id is no longer printed');
check(album.out.includes('father=gone'), 'father name is no longer printed');
check(album.out.includes('note=yes'), 'the note column is still there');
check(album.out.includes('footer1=gone') && album.out.includes('footer2=gone'), 'the two footer sentences are removed');
const albumRowH = Number(album.out.match(/rowH=([\d.]+)/)[1]);
check(albumRowH >= 10, 'two columns make every row twice as tall: ' + albumRowH + 'mm');
const photo1 = album.out.match(/photo=([\d.]+)x([\d.]+)/);
check(photo1 && Number(photo1[1]) >= 14, 'photos are bigger than the old 14.6mm: ' + (photo1 ? photo1[0] : '?'));

/* ── ۲) کلاس ۴۰ نفره هم فقط یک برگه می‌شود ─────────────────────────── */
const big = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/photo_album.php';
$students = pab_students_of_class('۷/۲','1403/1404');
echo 'count=' . count($students) . ';';
$html = pab_render_print_html('۷/۲','هفتم',$students,'مدرسهٔ آزمایشی',false);
echo 'sheets=' . substr_count($html, 'class="sheet"') . ';';
echo 'rows=' . substr_count($html, '<tr style="height:') . ';';
preg_match('/<tr style="height:([\d.]+)mm/U', $html, $m); echo 'rowH=' . $m[1] . ';';`);
check(big.out.includes('count=40'), 'a 40-student class exists in the fixture');
check(big.out.includes('sheets=1'), 'a 40-student class is STILL one sheet: ' + big.out.match(/sheets=\d+/)[0]);
check(big.out.includes('rows=40'), 'all 40 rows are on that one sheet');
const rowH = Number(big.out.match(/rowH=([\d.]+)/)[1]);
check(rowH >= 4.5 && rowH <= 26, 'row height adapts but stays readable: ' + rowH + 'mm');

/* ── ۳) کلاس خالی هم یک برگه با پیام ───────────────────────────────── */
const empty = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/photo_album.php';
$html = pab_render_print_html('۹/۹','نهم',[],'مدرسهٔ آزمایشی',false);
echo 'sheets=' . substr_count($html, 'class="sheet"') . ';';
echo 'msg=' . (strpos($html, 'دانش‌آموزی در این کلاس ثبت نشده است') !== false ? 'yes' : 'no') . ';';`);
check(empty.out.includes('sheets=1') && empty.out.includes('msg=yes'), 'an empty class still prints one sheet with a message');

/* ── ۴) کاربران متصل بله: عدد واقعی، نه سقف ۲۰۰ ───────────────────── */
await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
ensure_bot_schema('bale');
$ut = bot_user_table('bale');
DB::execute('DELETE FROM ' . $ut . " WHERE bale_chat_id LIKE '77%'");
DB::execute("DELETE FROM students WHERE national_id LIKE '079%'");
$ids = [];
for ($i=1;$i<=205;$i++) { DB::execute("INSERT INTO students (first_name,last_name,father_name,national_id,class_name,grade_level,academic_year,status,password) VALUES (?,?,?,?,?,?,?,?,?)",
  ['ربات'.$i,'کاربر'.$i,'پدر'.$i,'079000'.sprintf('%04d',$i),'۷/۳','هفتم','1403/1404','active','x']); $ids[] = (int)DB::getInstance()->lastInsertId(); }
for ($i=1;$i<=215;$i++) DB::execute('INSERT INTO ' . $ut . ' (bale_chat_id, bale_username, student_id, created_at) VALUES (?,?,?,datetime(\'now\'))', ['77'.$i, 'u'.$i, $ids[($i-1) % count($ids)]]);
echo 'SEEDED_BALE=' . DB::fetch('SELECT COUNT(*) AS c FROM ' . $ut)['c'];`);

const countOnly = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/bot_admin_ui.php';
$src = file_get_contents('/www/includes/bot_admin_ui.php');
echo 'has_count=' . (strpos($src, 'connectedTotal') !== false ? 'yes' : 'no') . ';';
echo 'has_paging=' . (strpos($src, 'users_page') !== false ? 'yes' : 'no') . ';';
echo 'list_is_paged=' . (strpos($src, 'LIMIT $usersPerPage OFFSET $usersOffset') !== false ? 'yes' : 'no') . ';';`);
check(countOnly.out.includes('has_count=yes'), 'the page computes a real total instead of counting a capped list');
check(countOnly.out.includes('has_paging=yes') && countOnly.out.includes('list_is_paged=yes'),
  'the connected-users list is paginated instead of truncated at 200: ' + countOnly.out.slice(0, 120));

/* ── ۵) صفحهٔ بله، عدد واقعی را نشان می‌دهد ─────────────────────────── */
await loginAdmin('baleStat01');
const balePage = await req('صفحهٔ ربات بله', { file: 'bale-bot.php', sid: 'baleStat01', query: '' });
check(/کاربران متصل/.test(balePage.res.page), 'the connected-users stat exists on the Bale page');
/* عدد باید بزرگ‌تر از ۲۰۰ نشان داده شود (۲۱۵ کاربر در دادهٔ آزمایشی) */
const faNums = { '۰':'0','۱':'1','۲':'2','۳':'3','۴':'4','۵':'5','۶':'6','۷':'7','۸':'8','۹':'9' };
const statArea = (balePage.res.page.match(/کاربران متصل[\s\S]{0,200}?<b>([^<]+)<\/b>/) || [])[1] || '';
const shown = statArea.replace(/[^۰-۹0-9]/g, '').replace(/[۰-۹]/g, (d) => faNums[d]);
const snippet = (balePage.res.page.match(/کاربران متصل[\s\S]{0,160}/) || [''])[0].replace(/\s+/g, ' ');
const usersCount = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/bot_helpers.php';
$ut = bot_user_table('bale');
echo 'n=' . DB::fetch('SELECT COUNT(*) AS c FROM ' . $ut)['c'] . ';';`);
check(Number(shown) === 215, 'the Bale page shows the real total 215, not the 200 cap: shown=' + shown + ' fixture=' + usersCount.out.trim() + ' | html: ' + snippet.slice(0, 120));

/* ── ۶) گزارش دانش‌آموزان: ترتیبِ فهرست حفظ می‌شود ─────────────────── */
const rep = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/student_profile_fields.php';
echo 'cols=' . implode(',', array_keys(student_report_columns(['identity']))) . ';';`);
check(rep.out.includes('first_name') && rep.out.includes('national_id'), 'report columns available: ' + rep.out.slice(0, 90));

await loginAdmin('repOrder001');
/* ترتیب دلخواه: معکوسِ الفبا — باید در خروجی حفظ شود */
const pick = await run(String.raw`<?php require_once '/www/includes/functions.php';
$rows = DB::fetchAll("SELECT id, first_name, last_name, national_id FROM students WHERE national_id LIKE '077%' ORDER BY id ASC");
usort($rows, fn($a,$b) => strcasecmp($a['last_name'], $b['last_name']));
$rows = array_reverse($rows);
echo json_encode(array_map(fn($r) => ['id'=>(int)$r['id'], 'nid'=>$r['national_id'], 'name'=>$r['first_name'].' '.$r['last_name']], $rows), JSON_UNESCAPED_UNICODE);`);
const chosen = JSON.parse(pick.out.match(/\[[\s\S]*\]/)[0]);
check(chosen.length >= 5, 'fixture students for the report: ' + chosen.length);

const form = await req('فرم دانش‌آموزان (توکن)', { file: 'students.php', sid: 'repOrder001', query: '' });
const csrf = (form.res.page.match(/name="csrf_token" value="([^"]+)"/) || [])[1] || '';
check(csrf !== '', 'a CSRF token is available for the report form');

const out = await req('گزارش با ترتیب دلخواه', {
  file: 'student-bulk-report.php', sid: 'repOrder001', method: 'POST',
  post: {
    csrf_token: csrf, report_type: 'info', format: 'html', fields: ['identity'],
    'student_ids': chosen.map((r) => r.id),
    student_order: chosen.map((r) => r.id),
  },
});
const body = out.res.page || '';
const nids = Array.from(body.matchAll(/dir="ltr"[^>]*>([0-9۰-۹]+)</g)).map((m) => m[1]);
check(nids.length >= 5, 'the report rendered rows: ' + nids.length);
const fa2en = (t) => t.replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
const expected = chosen.map((r) => fa2en(String(r.nid)));
const actual = nids.map(fa2en);
check(JSON.stringify(actual) === JSON.stringify(expected),
  'the report keeps the list order (here: reverse alphabetical): ' + actual.slice(0, 5).join(',') + ' vs ' + expected.slice(0, 5).join(','));

/* اگر ترتیب نفرستند، رفتار قدیمی (الفبا) برمی‌گردد */
const legacy = await req('گزارش بدون ترتیب (سازگاری)', {
  file: 'student-bulk-report.php', sid: 'repOrder001', method: 'POST',
  post: { csrf_token: csrf, report_type: 'info', format: 'html', fields: ['identity'], 'student_ids': chosen.map((r) => r.id) },
});
const legacyNids = Array.from((legacy.res.page || '').matchAll(/dir="ltr"[^>]*>([0-9۰-۹]+)</g)).map((m) => fa2en(m[1]));
const alpha = chosen.map((r) => r).sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0)).map((r) => fa2en(String(r.nid)));
check(JSON.stringify(legacyNids) !== JSON.stringify(expected) && JSON.stringify(legacyNids) === JSON.stringify(alpha),
  'without an explicit order the report falls back to the old alphabetical behaviour');

/* فهرست دانش‌آموزان: چینش بر اساس کلاس کار می‌کند */
const sorted = await req('فهرست چیده‌شده بر اساس کلاس', { file: 'students.php', sid: 'repOrder001', query: 'sort=class&dir=asc' });
check(sorted.res.page.includes('name="sort"') && sorted.res.page.includes('sort=class') && sorted.res.page.includes('name="dir"'),
  'the sort control is on the students page | len=' + sorted.res.page.length + ' | has_filter_form=' + (sorted.res.page.includes('filters-line') ? 'yes' : 'no') + ' | has_sort_select=' + (sorted.res.page.includes('name="sort"') ? 'yes' : 'no') + ' | redirect=' + (sorted.res.redirect || '-'));
check(/value="class"/.test(sorted.res.page) && /value="grade"/.test(sorted.res.page), 'the class and grade sort options exist');
const sortedDesc = await req('فهرست نزولی بر اساس کلاس', { file: 'students.php', sid: 'repOrder001', query: 'sort=class&dir=desc' });
check(/value="desc"\s+selected/.test(sortedDesc.res.page) && /value="class"\s+selected/.test(sortedDesc.res.page),
  'descending order is selectable and remembered: ' + (sortedDesc.res.redirect || sortedDesc.res.page.length));

console.log('PASS ' + n + ' checks (album as a one-page list, real connected-user count, report order preserved)');
process.exit(0);
