/**
 * سوئیت ۱ — راندن کامل آزمون روی کد واقعی سرور
 *
 * همهٔ ۱۲ نوع سوال، تصحیح خودکار و دستی، سناریوهای «آزمون نامعتبر»،
 * بازیابی نشست، و رفتار بعد از ارسال.
 */
import { php, req, db, api, login, examId, qmap, student } from './harness/lib.mjs';

const T = 'online-exam-take.php';
let pass = 0, fail = 0;
function ok(name, cond, detail = '') {
  if (cond) { pass++; console.log(`  ✅ ${name}`); }
  else { fail++; console.log(`  ❌ ${name}${detail ? '  → ' + detail : ''}`); }
}

console.log('\n══════════ فاز ۱: جریان عادی + هر ۱۲ نوع سوال ══════════');
const first = await req('ورود اول با exam_id', { method: 'GET', file: T, query: `exam_id=${examId}` });
ok('برگهٔ آزمون رندر شد', first.res.has_exam_ui, 'output=' + first.res.output_len);
ok('هر ۱۲ باکس سوال رندر شد', first.res.q_boxes === 12, 'q_boxes=' + first.res.q_boxes);
ok('هدر و منو رندر شد', first.res.has_header === true);
ok('هیچ خطای PHP در رندر نیست', first.res.php_issues === '', first.res.php_issues.slice(0, 200));
ok('هیچ فلش خطایی نیست', first.res.flash === null, JSON.stringify(first.res.flash));
ok('فرم action صریح و شناسه دارد', /action="online-exam-take\.php\?exam_id=\d+/.test(first.res.form_action),
  first.res.form_action);

const att = (await db(`SELECT id,status,is_timer_started FROM online_exam_attempts WHERE student_id=${student.id} ORDER BY id DESC LIMIT 1`))[0];
ok('attempt خودکار ساخته شد', !!att && att.status === 'in_progress', JSON.stringify(att));

const started = await api('start_attempt', { action: 'start_attempt', attempt_id: att.id });
ok('شروع تایمر موفق بود', started && started.ok === true, JSON.stringify(started));

console.log('\n──── ذخیرهٔ پاسخ هر ۱۲ نوع ────');
const answers = {
  radio: { selected: 'b' }, checkbox: { selected: ['a', 'c'] }, dropdown: { selected: 'b' },
  number: { value: '42' }, short_text: { value: 'تهران' }, text: { value: 'پاسخ تشریحی' },
  fill_blank: { blanks: { '0': 'تهران', '1': '۴۲' } }, matching: { matches: { '0': 'تهران', '1': 'خزر' } },
  file_upload: { value: '' }, voice_upload: { value: '' }, whiteboard: { whiteboard: true },
};
const saved = [];
for (const [type, qid] of Object.entries(qmap)) {
  if (type === 'info') continue;
  const j = await api(null, { action: 'save_answer', attempt_id: att.id, question_id: qid, answer_data: JSON.stringify(answers[type] || {}) });
  if (j && j.ok) saved.push(type); else console.log(`     ❌ ${type}: ${j ? j.msg : 'بدون پاسخ'}`);
}
ok('هر ۱۱ نوع پاس‌داشتنی ذخیره شد', saved.length === 11, saved.join(','));

const rows = await db(`SELECT q.question_type, a.is_correct, a.points_earned, a.needs_manual
  FROM online_exam_answers a JOIN online_questions q ON q.id=a.question_id WHERE a.attempt_id=${att.id}`);
const by = t => rows.find(r => r.question_type === t);
console.log('     · نمرهٔ خودکار: ' + rows.filter(r => r.needs_manual == 0)
  .reduce((s, r) => s + parseFloat(r.points_earned || 0), 0));

ok('رادیو درست تصحیح شد', by('radio')?.is_correct == 1, JSON.stringify(by('radio')));
ok('چندگزینه‌ای درست تصحیح شد', by('checkbox')?.is_correct == 1);
ok('کشویی درست تصحیح شد', by('dropdown')?.is_correct == 1);
ok('عددی درست تصحیح شد', by('number')?.is_correct == 1);
ok('جای خالی خودکار تصحیح شد', by('fill_blank')?.is_correct == 1 && by('fill_blank')?.needs_manual == 0);
ok('تطبیقی درست تصحیح شد', by('matching')?.is_correct == 1);
ok('انواع دستی علامت خورد', ['short_text', 'text', 'file_upload', 'voice_upload', 'whiteboard']
  .every(t => by(t)?.needs_manual == 1));
ok('info پاسخی نگرفت', by('info') === undefined);

console.log('\n══════════ فاز ۲: سناریوهای «آزمون نامعتبر» ══════════');
const bad = [
  await req('GET بدون هیچ پارامتر', { method: 'GET', file: T, query: '' }),
  await req('POST بدون پارامتر', { method: 'POST', file: T, query: '', post: { exam_id: '', attempt_id: '' } }),
  await req('attempt_id ناموجود', { method: 'GET', file: T, query: 'attempt_id=999999' }),
  await req('exam_id=0', { method: 'GET', file: T, query: 'exam_id=0' }),
];
const names = ['GET بدون پارامتر', 'POST بدون پارامتر', 'attempt_id ناموجود', 'exam_id=0'];
bad.forEach((r, i) => {
  const isDeadEnd = r.res.flash && r.res.flash.type === 'error' && r.res.flash.message.includes('آزمون نامعتبر');
  ok(`${names[i]} → بن‌بست «آزمون نامعتبر» ندارد`, !isDeadEnd, JSON.stringify(r.res.flash));
  ok(`${names[i]} → خودکار به همان آزمون برگشت`, r.res.has_exam_ui,
    'redirect=' + r.res.redirect + ' out=' + r.res.output_len);
});

console.log('\n══════════ فاز ۳: API بررسی زمان ══════════');
const ct = await api('check_time', { action: 'check_time', attempt_id: att.id });
ok('check_time پاسخ می‌دهد', ct && ct.ok === true, JSON.stringify(ct));
ok('زمان باقی‌مانده منطقی است', ct && ct.data.remaining > 3000 && ct.data.remaining <= 3600, JSON.stringify(ct && ct.data));
ok('expired=false وقتی وقت هست', ct && ct.data.expired === false);
const ctBad = await api('check_time با attempt نامعتبر', { action: 'check_time', attempt_id: 999999 });
ok('attempt ناموجود → خطای شفاف نه کرش', ctBad && ctBad.ok === false && !!ctBad.msg, JSON.stringify(ctBad));

console.log('\n══════════ فاز ۴: ارسال نهایی و ورود بعد از آن ══════════');
await api('submit_exam', { action: 'submit_exam', attempt_id: att.id });
const after = (await db(`SELECT status,score,max_score FROM online_exam_attempts WHERE id=${att.id}`))[0];
console.log('     · attempt بعد از ارسال:', JSON.stringify(after));
ok('وضعیت submitted ثبت شد', ['submitted', 'auto_submitted'].includes(after.status), after.status);
ok('نمرهٔ واقعی ثبت شد (نه صفر)', parseFloat(after.score) > 0, 'score=' + after.score);

const r11 = await req('ورود با exam_id بعد از ارسال', { method: 'GET', file: T, query: `exam_id=${examId}` });
ok('ورود مجدد با exam_id جلوش گرفته شد', r11.res.flash && r11.res.flash.message.includes('حداکثر'),
  JSON.stringify(r11.res.flash));

const r12 = await req('ورود با attempt_id بعد از ارسال', { method: 'GET', file: T, query: `attempt_id=${att.id}` });
ok('attempt ارسال‌شده به صفحهٔ نتیجه می‌رود', !r12.res.has_exam_ui && /online-exam-result\.php/.test(r12.res.redirect || ''),
  'redirect=' + r12.res.redirect);

console.log('\n══════════ فاز ۵: نشست تازه بدون سابقه ══════════');
await login('freshSession01');
const rFresh = await req('GET بدون پارامتر با نشست جدید', { sid: 'freshSession01', method: 'GET', file: T, query: '' });
ok('خطای بن‌بست نیست', !(rFresh.res.flash && rFresh.res.flash.type === 'error'), JSON.stringify(rFresh.res.flash));
ok('با هشدار به فهرست آزمون‌ها می‌رود', rFresh.res.redirect === 'student-online-exams.php',
  'redirect=' + rFresh.res.redirect + ' flash=' + JSON.stringify(rFresh.res.flash));

console.log('\n════════════════════════════════');
console.log(`  سوئیت سرور: ${pass} PASS / ${fail} FAIL`);
console.log('════════════════════════════════');
export { pass, fail };
process.exit(fail ? 1 : 0);
