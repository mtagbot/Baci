/**
 * سوئیت ۵ — قفل تک‌دستگاهی (v4.128.0)
 *
 * یک دانش‌آموز، دو مرورگر (دو نشست مستقل با دو شناسهٔ دستگاه مختلف).
 * خواسته: وقتی دستگاهی در حال آزمون است، دستگاه دوم نتواند وارد شود.
 *
 * همه با درخواست واقعی به online-exam-api.php روی PHP + SQLite سنجیده می‌شود.
 */
import { req, api, db, dbExec, login, examId, student } from './harness/lib.mjs';

let pass = 0, fail = 0;
const ok = (n, c, d = '') => c ? (pass++, console.log(`  ✅ ${n}`)) : (fail++, console.log(`  ❌ ${n}${d ? '  → ' + d : ''}`));

const DEV_A = 'devAAAA1111222233334444';
const DEV_B = 'devBBBB5555666677778888';
const DEV_C = 'devCCCC9999000011112222';
const SID_A = 'harnessDevA001', SID_B = 'harnessDevB001', SID_C = 'harnessDevC001';

/* سه نشست مستقل، هر سه همان دانش‌آموز — یعنی سه دستگاه */
for (const sid of [SID_A, SID_B, SID_C]) await login(sid);

/* attempt تازه برای این دانش‌آموز */
await req('ورود و ساخت attempt', { method: 'GET', file: 'online-exam-take.php', query: `exam_id=${examId}` });
const att = (await db(`SELECT id, status FROM online_exam_attempts WHERE student_id=${student.id} ORDER BY id DESC LIMIT 1`))[0];
console.log(`     · attempt #${att.id} (${att.status})`);

console.log('\n══ ۱) دستگاه اول ثبت می‌شود ══');
const cA = await api('claim از دستگاه A', { action: 'claim_device', attempt_id: att.id, device_token: DEV_A, device_label: 'Chrome/Windows' }, { sid: SID_A });
ok('دستگاه A پذیرفته شد', cA && cA.ok && cA.data.allowed === true, JSON.stringify(cA));
ok('در دیتابیس به نام A ثبت شد',
  (await db(`SELECT device_token FROM online_exam_live_sessions WHERE attempt_id=${att.id}`))[0]?.device_token === DEV_A);

console.log('\n══ ۲) دستگاه دوم رد می‌شود ══');
const cB = await api('claim از دستگاه B', { action: 'claim_device', attempt_id: att.id, device_token: DEV_B, device_label: 'Safari/iPhone' }, { sid: SID_B });
ok('claim دستگاه B رد شد', cB && cB.ok && cB.data.allowed === false, JSON.stringify(cB && cB.data));
ok('دلیل other_device اعلام شد', cB?.data?.reason === 'other_device');
ok('زمان انتظار تا آزادشدن قفل داده شد', Number(cB?.data?.retry_after) > 0, 'retry_after=' + cB?.data?.retry_after);
ok('پیام فارسی روشن دارد', (cB?.msg || '').includes('دستگاه دیگری'), cB?.msg);
ok('مالکیت در دیتابیس عوض نشد',
  (await db(`SELECT device_token FROM online_exam_live_sessions WHERE attempt_id=${att.id}`))[0]?.device_token === DEV_A);

console.log('\n══ ۳) دستگاه دوم نمی‌تواند تایمر را شروع کند ══');
const sB = await api('start_attempt از دستگاه B', { action: 'start_attempt', attempt_id: att.id, device_token: DEV_B }, { sid: SID_B });
ok('start_attempt دستگاه B رد شد', sB && sB.ok === false, JSON.stringify(sB));
ok('دلیل other_device اعلام شد', sB?.data?.reason === 'other_device');
ok('تایمر شروع نشد', (await db(`SELECT is_timer_started FROM online_exam_attempts WHERE id=${att.id}`))[0]?.is_timer_started == 0);

console.log('\n══ ۴) دستگاه اول عادی ادامه می‌دهد ══');
const sA = await api('start_attempt از دستگاه A', { action: 'start_attempt', attempt_id: att.id, device_token: DEV_A }, { sid: SID_A });
ok('start_attempt دستگاه A موفق بود', sA && sA.ok === true, JSON.stringify(sA));
ok('تایمر شروع شد', (await db(`SELECT is_timer_started FROM online_exam_attempts WHERE id=${att.id}`))[0]?.is_timer_started == 1);

console.log('\n══ ۵) heartbeat دستگاه مزاحم، خودش را بیرون می‌اندازد ══');
const hB = await api('heartbeat از دستگاه B', { action: 'heartbeat', attempt_id: att.id, exam_id: examId, device_token: DEV_B }, { sid: SID_B });
ok('به دستگاه B علامت kicked داده شد', hB?.data?.kicked === true, JSON.stringify(hB && hB.data));
const hA = await api('heartbeat از دستگاه A', { action: 'heartbeat', attempt_id: att.id, exam_id: examId, device_token: DEV_A }, { sid: SID_A });
ok('دستگاه A بیرون انداخته نشد', hA?.ok === true && !hA?.data?.kicked, JSON.stringify(hA && hA.data));

console.log('\n══ ۶) بعد از بی‌خبری، قفل آزاد می‌شود (تا دانش‌آموز قفل نماند) ══');
/* شبیه‌سازی: دستگاه A خاموش شده و ۵ دقیقه است heartbeat نداده */
await dbExec(`UPDATE online_exam_live_sessions SET last_heartbeat=datetime('now','localtime','-5 minutes') WHERE attempt_id=${att.id}`);
const cB2 = await api('claim مجدد از دستگاه B', { action: 'claim_device', attempt_id: att.id, device_token: DEV_B, device_label: 'Safari/iPhone' }, { sid: SID_B });
ok('بعد از ۵ دقیقه بی‌خبری، دستگاه B پذیرفته شد', cB2 && cB2.data.allowed === true, JSON.stringify(cB2 && cB2.data));
ok('مالکیت به B منتقل شد',
  (await db(`SELECT device_token FROM online_exam_live_sessions WHERE attempt_id=${att.id}`))[0]?.device_token === DEV_B);
const sA2 = await api('start_attempt از دستگاه A (حالا مزاحم است)', { action: 'start_attempt', attempt_id: att.id, device_token: DEV_A }, { sid: SID_A });
ok('حالا دستگاه A رد می‌شود', sA2?.ok === false && sA2?.data?.reason === 'other_device', JSON.stringify(sA2));

console.log('\n══ ۷) رفرش همان دستگاه مجاز است ══');
const cB3 = await api('claim دوباره از همان دستگاه B', { action: 'claim_device', attempt_id: att.id, device_token: DEV_B }, { sid: SID_B });
ok('رفرش همان دستگاه رد نمی‌شود', cB3?.data?.allowed === true, JSON.stringify(cB3 && cB3.data));
ok('دلیل refreshed است', cB3?.data?.reason === 'refreshed');

console.log('\n══ ۸) ورود بدون شناسهٔ دستگاه ══');
const cNo = await api('claim بدون token', { action: 'claim_device', attempt_id: att.id }, { sid: SID_C });
ok('شناسهٔ نامعتبر رد می‌شود', cNo?.ok === false, JSON.stringify(cNo));

console.log('\n══ ۹) بعد از ارسال، قفل آزاد می‌شود ══');
await api('submit از دستگاه B', { action: 'submit_exam', attempt_id: att.id, device_token: DEV_B }, { sid: SID_B });
const lock = (await db(`SELECT device_token, status FROM online_exam_live_sessions WHERE attempt_id=${att.id}`))[0];
ok('قفل آزاد شد (device_token تهی)', !lock?.device_token, JSON.stringify(lock));
ok('وضعیت ended شد', lock?.status === 'ended', JSON.stringify(lock));
const cC = await api('claim از دستگاه C بعد از ارسال', { action: 'claim_device', attempt_id: att.id, device_token: DEV_C }, { sid: SID_C });
ok('آزمون ارسال‌شده دیگر قفل ندارد', cC?.data?.allowed === true, JSON.stringify(cC && cC.data));
ok('دلیل already_submitted است', cC?.data?.reason === 'already_submitted');

console.log('\n════════════════════════════════');
console.log(`  سوئیت قفل دستگاه: ${pass} PASS / ${fail} FAIL`);
console.log('════════════════════════════════');
process.exit(fail ? 1 : 0);
