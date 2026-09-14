/**
 * سوئیت ۷ — حریم خصوصی گزارش پایش (v4.129.0)
 *
 * خواستهٔ کاربر:
 *   دانش‌آموز : فقط متن «خلاصه». بدون IP، بدون مختصات، بدون فهرست رویداد،
 *               بدون کد خامِ لاتین مثل permission_revoked_location.
 *   کادر مدرسه: ردیف رویدادها، ولی فقط متن فارسی (نه کد خام).
 *
 * هر دو نما با رندر واقعی online-exam-result.php سنجیده می‌شوند.
 */
import { req, db, dbExec, login, loginAdmin, examId, student } from './harness/lib.mjs';

let pass = 0, fail = 0;
const ok = (n, c, d = '') => c ? (pass++, console.log(`  ✅ ${n}`)) : (fail++, console.log(`  ❌ ${n}${d ? '  → ' + d : ''}`));

/* کدهای خامی که کاربر صریحاً گفت نباید دیده شوند */
const RAW_CODES = [
  'permission_revoked_location', 'window_focused', 'location_ip_fallback',
  'tab_hidden', 'page_unload', 'voice_note_played', 'window_blurred',
  'tab_visible', 'copy_attempt', 'devtools_attempt', 'location_acquired',
];
const FAKE_IP  = '203.0.113.77';
const FAKE_LAT = '35.68920', FAKE_LNG = '51.38900';

/* ── ۱) ساخت attempt و ریختن لاگ ──────────────────────────────────── */
await login();
await req('ورود و ساخت attempt', { method: 'GET', file: 'online-exam-take.php', query: `exam_id=${examId}` });
const att = (await db(`SELECT id FROM online_exam_attempts WHERE student_id=${student.id} ORDER BY id DESC LIMIT 1`))[0];
const attemptId = att.id;
console.log(`     · attempt #${attemptId}`);

/* داده‌های فنی که باید از دید دانش‌آموز پنهان بمانند */
await dbExec(`UPDATE online_exam_attempts SET ip_address='${FAKE_IP}', geo_lat='${FAKE_LAT}', geo_lng='${FAKE_LNG}', exit_count=2, tab_switch_count=3, copy_attempts=1 WHERE id=${attemptId}`);

/* مقادیر واقعی event_data که کلاینت می‌فرستد — شامل رشته‌های فنی و حتی
   یکی که عمداً کد خامِ لاتین دارد، تا ثابت شود هیچ مسیری آن را نشان نمی‌دهد. */
const events = [
  ['tab_hidden',                 'تب عوض شد'],
  ['window_blurred',             'Minimize'],
  ['window_focused',             'فوکوس'],
  ['permission_revoked_location','permission_revoked_location'],   /* متن آزادِ مخرب */
  ['location_ip_fallback',       'IP-approx'],
  ['page_unload',                'خروج'],
  ['voice_note_played',          'voice_id=42'],
  ['copy_attempt',               'Ctrl+C'],
  ['location_resolved',          'mode=high'],
];
for (const [ev, data] of events) {
  await dbExec(`INSERT INTO online_exam_proctoring_logs (attempt_id, student_id, exam_id, event_type, event_data, ip_address, created_at)
                VALUES (${attemptId}, ${student.id}, ${examId}, '${ev}', '${data}', '${FAKE_IP}', datetime('now','localtime'))`);
}
const rows = (await db(`SELECT COUNT(*) c FROM online_exam_proctoring_logs WHERE attempt_id=${attemptId}`))[0];
ok('لاگ‌های نمونه در دیتابیس نشستند', Number(rows.c) === events.length, 'تعداد=' + rows.c);

/* ── ۲) نمای دانش‌آموز ────────────────────────────────────────────── */
console.log('\n══ نمای دانش‌آموز ══');
await login();
const stu = await req('نتیجه از دید دانش‌آموز', { method: 'GET', file: 'online-exam-result.php', query: `attempt_id=${attemptId}` });
const sp = stu.res.page || '';

ok('صفحه رندر شد', sp.length > 500, 'طول=' + sp.length);
ok('عنوان «گزارش اقدامات شما در آزمون» است', sp.includes('گزارش اقدامات شما در آزمون'), 'عنوان پیدا نشد');
ok('عنوان قدیمی «(ثبت خودکار ضدتقلب)» حذف شده', !sp.includes('ثبت خودکار ضدتقلب'));

const leaked = RAW_CODES.filter(c => sp.includes(c));
ok('هیچ کد خامِ لاتینی به دانش‌آموز نشان داده نمی‌شود', leaked.length === 0, 'نشت: ' + leaked.join(', '));
ok('IP به دانش‌آموز نشان داده نمی‌شود', !sp.includes(FAKE_IP), 'IP پیدا شد');
ok('مختصات جغرافیایی نشان داده نمی‌شود', !sp.includes(FAKE_LAT) && !sp.includes(FAKE_LNG));
ok('برچسب «IP:» هم نیست', !/IP\s*:/.test(sp));
ok('فهرست رویدادها برای دانش‌آموز رندر نمی‌شود', !sp.includes('em-log__k'), 'کلاس em-log__k پیدا شد');
ok('خلاصه به دانش‌آموز نشان داده می‌شود',
   sp.includes('خروج از صفحهٔ آزمون') && sp.includes('جابجایی تب') && sp.includes('تلاش برای کپی'),
   'متن خلاصه پیدا نشد');
ok('خلاصه با عدد فارسی است', /۲ بار خروج/.test(sp) && /۳ بار جابجایی/.test(sp) && /۱ تلاش/.test(sp));
ok('زمان‌بندی رویدادها (created_at) به دانش‌آموز نشان داده نمی‌شود', !sp.includes('em-log__t'));

/* ── ۳) نمای کادر مدرسه ──────────────────────────────────────────── */
console.log('\n══ نمای کادر مدرسه ══');
await loginAdmin('harnessAdm0001');
const adm = await req('نتیجه از دید مدیر', { method: 'GET', file: 'online-exam-result.php', query: `attempt_id=${attemptId}`, sid: 'harnessAdm0001' });
const ap = adm.res.page || '';

ok('صفحه رندر شد', ap.length > 500, 'طول=' + ap.length);
const aLeaked = RAW_CODES.filter(c => ap.includes(c));
ok('کد خامِ لاتین به دبیر هم نشان داده نمی‌شود', aLeaked.length === 0, 'نشت: ' + aLeaked.join(', '));
ok('ردیف رویدادها برای دبیر رندر می‌شود', ap.includes('em-log__k') && ap.includes('em-log__t'));
ok('برچسب فارسی رویدادها هست',
   ap.includes('خروج از صفحهٔ آزمون / جابجایی تب') && ap.includes('خارج شدن از فوکوس پنجره')
   && ap.includes('موقعیت مکانی قطع شد') && ap.includes('پیام صوتی مراقب پخش شد'),
   'برچسب‌های فارسی کامل نیستند');
ok('IP برای دبیر قابل دیدن است (دادهٔ پایش مال کادر است)', ap.includes(FAKE_IP));
ok('خلاصه برای دبیر هم هست', ap.includes('خلاصه:'));
const TECH = ['IP-approx', 'voice_id=42', 'Ctrl+C', 'mode=high', 'Minimize'];
const tLeak = TECH.filter(t => ap.includes(t));
ok('رشته‌های فنی event_data هم نشان داده نمی‌شوند', tLeak.length === 0, 'نشت: ' + tLeak.join(', '));

/* ── ۴) رویداد ناشناخته هرگز کد خام برنمی‌گرداند ─────────────────── */
console.log('\n══ رویداد ناشناخته ══');
await dbExec(`INSERT INTO online_exam_proctoring_logs (attempt_id, student_id, exam_id, event_type, created_at)
              VALUES (${attemptId}, ${student.id}, ${examId}, 'some_brand_new_event_xyz', datetime('now','localtime'))`);
const adm2 = await req('نتیجه از دید مدیر (با رویداد ناشناخته)', { method: 'GET', file: 'online-exam-result.php', query: `attempt_id=${attemptId}`, sid: 'harnessAdm0001' });
const ap2 = adm2.res.page || '';
ok('رویداد ناشناخته به‌صورت کد خام نشان داده نمی‌شود', !ap2.includes('some_brand_new_event_xyz'));
ok('به‌جایش برچسب فارسی عمومی می‌آید', ap2.includes('رویداد ثبت‌شده در آزمون'));

console.log('\n════════════════════════════════');
console.log(`  سوئیت حریم خصوصی گزارش: ${pass} PASS / ${fail} FAIL`);
console.log('════════════════════════════════');
process.exit(fail ? 1 : 0);
