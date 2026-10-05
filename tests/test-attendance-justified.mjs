// v4.179.0 — ستون «موجه» در سوابق حضور و غیاب → ثبت غیبت/تأخیر موجه در پرونده انضباطی
//
// چه چیزی اینجا «اجرا» می‌شود، نه ادعا:
//   ۱) جدول سوابق، ستون «موجه» را دقیقاً بعد از «نوع» دارد.
//   ۲) چک‌باکس موجه فقط برای ردیف غیبت/تأخیر رندر می‌شود، نه برای حضور.
//   ۳) ثبت دسته‌جمعی: ردیفِ تیک‌خورده → is_justified=1 و عنوان «غیبت موجه»،
//      ردیفِ تیک‌نخورده → is_justified=0 و عنوان «غیبت» (همان رفتار قبلی).
//   ۴) یادداشت داخلی برای مورد موجه عبارت «موجه شده است» می‌گیرد.
//   ۵) پیام صف ربات عیناً عنوان را می‌فرستد («عنوان: غیبت موجه») — بدون تغییر
//      در کد ربات.
//   ۶) تأخیر هم همان‌طور: «تأخیر موجه در ورود به مدرسه».
//   ۷) دادهٔ موجود خراب نمی‌شود: رکورد انضباطیِ از قبل موجود دست‌نخورده می‌ماند.
//   ۸) ثبت دوبارهٔ همان رکورد حضور و غیاب، تکراری نمی‌سازد (مارکر [att#N]).
//   ۹) حتی اگر شناسهٔ یک ردیف «حضور» در justified_ids بیاید، چیزی ثبت نمی‌شود.
import assert from 'node:assert/strict';
import { run, req, loginAdmin } from './harness/lib.mjs';

let n = 0;
const check = (v, m) => { assert(v, m); n++; };

/* ═══════ دادهٔ آزمایشی ═══════ */
const seed = await run(String.raw`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/attendance_helpers.php';
require_once '/www/includes/school_roles.php';
ensure_attendance_schema_v2(); ensure_school_roles_schema();
try { DB::getInstance()->getPdo()->exec("CREATE TABLE IF NOT EXISTS bot_outbox (job_id VARCHAR(32) PRIMARY KEY, platform VARCHAR(16) NOT NULL, payload TEXT NOT NULL, payload_hash VARCHAR(64) NOT NULL, owner VARCHAR(8) NOT NULL, state VARCHAR(16) NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0, next_try BIGINT NOT NULL DEFAULT 0, lease_until BIGINT NOT NULL DEFAULT 0, claim_token VARCHAR(32) NOT NULL DEFAULT '', created_at BIGINT NOT NULL, sent_at BIGINT NOT NULL DEFAULT 0, message_id VARCHAR(80) NOT NULL DEFAULT '', last_error TEXT NOT NULL)"); } catch (Exception $e) {}
DB::execute("DELETE FROM student_discipline_records");
DB::execute("DELETE FROM student_attendance");
DB::execute("DELETE FROM bot_outbox");
$s = DB::fetch("SELECT id FROM students ORDER BY id LIMIT 1");
$sid = (int)$s['id'];
/* The records table is filtered to the DEFAULT academic year (att_year_sql).
   The harness student has academic_year=NULL, so pin it to the current default
   year or every row we insert would be filtered out before rendering. */
list($ySql, $yP) = att_year_sql('s');
$year = (string)($yP[0] ?? '');
DB::execute("UPDATE students SET academic_year=? WHERE id=?", [$year, $sid]);
/* یک رکورد انضباطیِ از قبل موجود — باید دست‌نخورده بماند */
DB::execute("INSERT INTO student_discipline_records (student_id,title_id,title_text,internal_note,occurred_at_jalali,notify_parents,is_justified,review_status,created_at_jalali) VALUES (?,?,?,?,'1405/06/01',0,1,'reviewed','1405/06/01')",
  [$sid, null, 'مورد قدیمی', 'یادداشت قدیمی']);
$old = DB::fetch("SELECT id,title_text,internal_note,is_justified,review_status FROM student_discipline_records WHERE title_text='مورد قدیمی'");
/* چهار رکورد حضور و غیاب: دو غیبت، یک تأخیر، یک حضور */
$mk = function($status,$day,$minutes=0) use ($sid, $year) {
  DB::execute("INSERT INTO student_attendance (student_id,academic_year,date_jalali,status,minutes_late,note,notified_chats,review_status,created_at_jalali) VALUES (?,?,?,?,?,NULL,0,'pending',?)",
    [$sid,$year,'1405/07/'.$day,$status,$minutes,jalali_now()]);
  return (int)DB::lastInsertId();
};
$a1 = $mk('absent','10');   // غیبت — موجه خواهد شد
$a2 = $mk('absent','11');   // غیبت — معمولی
$l1 = $mk('late','12',17);  // تأخیر — موجه خواهد شد
$p1 = $mk('present','13');  // حضور — هرگز نباید ثبت شود
/* ولی متصل تا پیام ربات واقعاً در صف بنشیند */
DB::execute("CREATE TABLE IF NOT EXISTS bale_bot_users (id INTEGER PRIMARY KEY AUTOINCREMENT, bale_chat_id TEXT NOT NULL, bale_username TEXT DEFAULT NULL, student_id int(11) NOT NULL, created_at TEXT)");
DB::execute("DELETE FROM bale_bot_users WHERE student_id=?", [$sid]);
DB::execute("INSERT INTO bale_bot_users (bale_chat_id,student_id) VALUES ('77001',?)", [$sid]);
echo 'sid='.$sid.';a1='.$a1.';a2='.$a2.';l1='.$l1.';p1='.$p1.';old='.$old['id'].';oldjust='.$old['is_justified']
  .';visible='.count(DB::fetchAll("SELECT a.id FROM student_attendance a JOIN students s ON s.id=a.student_id WHERE $ySql",$yP));`);
const g = (k) => (seed.out.match(new RegExp(k + '=(-?\\d+)')) || [])[1];
const SID = +g('sid'), A1 = +g('a1'), A2 = +g('a2'), L1 = +g('l1'), P1 = +g('p1'), OLD = +g('old');
check(SID > 0 && A1 && A2 && L1 && P1 && OLD, 'test fixtures were created: ' + seed.out.trim().slice(0, 140));
check(+(seed.out.match(/visible=(\d+)/) || [, '0'])[1] === 4,
  'all four seeded attendance rows survive the default-academic-year filter: ' + seed.out.trim());

/* ═══════ ۱ و ۲) جدول سوابق ═══════ */
await loginAdmin('attJust01');
const page = await req('جدول سوابق حضور و غیاب', { file: 'attendance.php', sid: 'attJust01', query: 'date=' });
check(!page.res.fatal, 'attendance.php renders: ' + (page.res.fatal || ''));

const head = page.res.page;
const iNoe = head.indexOf('<th>نوع</th>');
const iMojah = head.indexOf('>موجه</th>');
const iVorood = head.indexOf('<th>ورود</th>');
check(iNoe !== -1 && iMojah !== -1 && iVorood !== -1, 'all three headers exist');
check(iNoe < iMojah && iMojah < iVorood,
  'the «موجه» column sits immediately AFTER «نوع» and before «ورود» (' + iNoe + ' < ' + iMojah + ' < ' + iVorood + ')');

const justBoxes = [...head.matchAll(/name="justified_ids\[\]" value="(\d+)"/g)].map(m => +m[1]);
check(justBoxes.includes(A1) && justBoxes.includes(A2) && justBoxes.includes(L1),
  'absent and late rows get a justified checkbox');
check(!justBoxes.includes(P1), 'a PRESENT row never gets a justified checkbox');
check(head.includes('class="att-just-check"'), 'the checkbox has its own class, separate from the row-selection one');
check(/attToggleAll[\s\S]{0,220}\.att-rec-check/.test(head) && !/attToggleAll[\s\S]{0,220}att-just-check/.test(head),
  '«select all» does NOT tick the justified boxes (justified must be explicit)');

const csrf = (head.match(/name="csrf_token" value="([^"]+)"/) || [])[1] || '';
check(csrf !== '', 'a CSRF token is available on the page');

/* ═══════ ۳ و ۴) ثبت دسته‌جمعی غیبت‌ها ═══════ */
const filedAbs = await req('ثبت غیبت‌ها در پرونده (یکی موجه)', {
  file: 'attendance.php', sid: 'attJust01', method: 'POST',
  post: { csrf_token: csrf, bulk_action: 'file_absents', back_date: '', rec_ids: [A1, A2], justified_ids: [A1] },
});
check(!filedAbs.res.fatal, 'the filing request runs: ' + (filedAbs.res.fatal || ''));

const rows = await run(String.raw`<?php require_once '/www/includes/functions.php';
foreach (DB::fetchAll("SELECT id,title_text,internal_note,is_justified,review_status FROM student_discipline_records ORDER BY id") as $r)
  echo $r['id'].'|'.$r['title_text'].'|'.(int)$r['is_justified'].'|'.$r['review_status'].'|'.$r['internal_note']."\n";`);
const recs = rows.out.trim().split('\n').filter(Boolean).map(l => {
  const [id, title, just, review, note] = l.split('|');
  return { id: +id, title, just: +just, review, note: note || '' };
});
const byTitle = (t) => recs.find(r => r.title === t);

const justRec = byTitle('غیبت موجه');
check(!!justRec, 'a record titled «غیبت موجه» was filed. Got: ' + recs.map(r => r.title).join(', '));
check(justRec && justRec.just === 1, 'the justified record has is_justified=1');
check(justRec && justRec.note.includes('موجه شده است'),
  'the internal note says «موجه شده است»: ' + (justRec ? justRec.note : ''));
check(justRec && justRec.note.includes('[att#' + A1 + ']'), 'the dedupe marker is preserved on the justified record');

const plainRec = byTitle('غیبت');
check(!!plainRec, 'an unmarked absence is still filed as plain «غیبت» (unchanged behaviour)');
check(plainRec && plainRec.just === 0, 'the unmarked record keeps is_justified=0');
check(plainRec && !plainRec.note.includes('موجه شده است'),
  'the unmarked record does NOT claim to be justified');

/* ═══════ ۵) پیام ربات ═══════ */
const outbox = await run(String.raw`<?php require_once '/www/includes/functions.php';
foreach (DB::fetchAll("SELECT payload FROM bot_outbox ORDER BY created_at") as $r) {
  $p = json_decode($r['payload'], true); echo ($p['text'] ?? '') . "\n----\n"; }`);
check(outbox.out.includes('عنوان: غیبت موجه'),
  'the bot message carries the justified title verbatim — no bot-code change needed: '
  + (outbox.out.match(/عنوان: [^\n]*/g) || []).join(' / '));
check(/عنوان: غیبت\s*\n/.test(outbox.out),
  'the plain absence message still says just «غیبت»');


/* ═══════ ۶) تأخیر ═══════ */
const page2 = await req('بازخوانی صفحه برای توکن تازه', { file: 'attendance.php', sid: 'attJust01', query: 'date=' });
const csrf2 = (page2.res.page.match(/name="csrf_token" value="([^"]+)"/) || [])[1] || csrf;
await req('ثبت تأخیرها در پرونده (موجه)', {
  file: 'attendance.php', sid: 'attJust01', method: 'POST',
  post: { csrf_token: csrf2, bulk_action: 'file_lates', back_date: '', rec_ids: [L1], justified_ids: [L1] },
});
const lateRows = await run(String.raw`<?php require_once '/www/includes/functions.php';
$r = DB::fetch("SELECT title_text,is_justified,internal_note FROM student_discipline_records WHERE title_text LIKE 'تأخیر%' ORDER BY id DESC LIMIT 1");
echo $r['title_text'].'|'.(int)$r['is_justified'].'|'.$r['internal_note'];`);
const [lt, lj, ln] = lateRows.out.split('|');
check(lt === 'تأخیر موجه در ورود به مدرسه', 'a justified late is filed under «تأخیر موجه در ورود به مدرسه»: ' + lt);
check(+lj === 1, 'the justified late has is_justified=1');
check((ln || '').includes('موجه شده است') && (ln || '').includes('دقیقه تأخیر'),
  'the late note keeps the minutes AND gains «موجه شده است»: ' + ln);

/* ═══════ ۷) دادهٔ موجود دست‌نخورده ═══════ */
const oldChk = await run(`<?php require_once '/www/includes/functions.php';
$r = DB::fetch("SELECT title_text,internal_note,is_justified,review_status FROM student_discipline_records WHERE id=${OLD}");
echo $r['title_text'].'|'.$r['internal_note'].'|'.(int)$r['is_justified'].'|'.$r['review_status'];`);
check(oldChk.out === 'مورد قدیمی|یادداشت قدیمی|1|reviewed',
  'the pre-existing discipline record is byte-identical after filing: ' + oldChk.out);

/* ═══════ ۸) ثبت دوباره تکراری نمی‌سازد ═══════ */
const before = recs.length + 1; /* + the late record */
const page3 = await req('بازخوانی برای توکن', { file: 'attendance.php', sid: 'attJust01', query: 'date=' });
const csrf3 = (page3.res.page.match(/name="csrf_token" value="([^"]+)"/) || [])[1] || csrf;
const again = await req('ثبت دوبارهٔ همان غیبت‌ها', {
  file: 'attendance.php', sid: 'attJust01', method: 'POST',
  post: { csrf_token: csrf3, bulk_action: 'file_absents', back_date: '', rec_ids: [A1, A2], justified_ids: [A1] },
});
const cnt = await run(String.raw`<?php require_once '/www/includes/functions.php';
echo 'n='.DB::fetch("SELECT COUNT(*) c FROM student_discipline_records")['c'];`);
check(+cnt.out.match(/n=(\d+)/)[1] === before,
  're-filing the same attendance rows adds nothing (expected ' + before + '): ' + cnt.out);

/* ═══════ ۹) ردیف «حضور» هرگز ثبت نمی‌شود ═══════ */
const page4 = await req('بازخوانی برای توکن', { file: 'attendance.php', sid: 'attJust01', query: 'date=' });
const csrf4 = (page4.res.page.match(/name="csrf_token" value="([^"]+)"/) || [])[1] || csrf;
await req('تلاش برای ثبت ردیف حضور به‌عنوان موجه', {
  file: 'attendance.php', sid: 'attJust01', method: 'POST',
  post: { csrf_token: csrf4, bulk_action: 'file_absents', back_date: '', rec_ids: [P1], justified_ids: [P1] },
});
const cnt2 = await run(String.raw`<?php require_once '/www/includes/functions.php';
echo 'n='.DB::fetch("SELECT COUNT(*) c FROM student_discipline_records")['c'];`);
check(+cnt2.out.match(/n=(\d+)/)[1] === before,
  'a present row cannot be filed even when posted as justified: ' + cnt2.out);

/* ═══════ ۱۰) عنوان موجه در فهرست عنوان‌های ذخیره‌شده ═══════ */
const titles = await run(String.raw`<?php require_once '/www/includes/functions.php';
foreach (DB::fetchAll("SELECT title FROM discipline_titles WHERE status=1 ORDER BY title") as $t) echo $t['title']."\n";`);
check(titles.out.includes('غیبت موجه') && titles.out.includes('تأخیر موجه در ورود به مدرسه'),
  'the justified titles are now saved school titles the deputy can pick directly');

console.log('PASS ' + n + ' justified-attendance checks');
process.exit(0);
