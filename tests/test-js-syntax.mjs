/**
 * بررسی نحوی جاوااسکریپتِ درون فایل‌های PHP.
 *
 * چرا لازم است: lint.mjs با token_get_all فقط syntax خودِ PHP را می‌سنجد.
 * یک خطای نحوی در <script> برای PHP کاملاً نامرئی است ولی در مرورگر کل
 * بلوک اسکریپت را از کار می‌اندازد — یعنی هیچ تابعی تعریف نمی‌شود و صفحه
 * «ساکت» می‌ماند. دقیقاً همان چیزی که در v4.129.0 رخ داد: سه خط
 * emState(...) پرانتز بسته نداشتند و مرحلهٔ «بررسی دسترسی‌های آزمون»
 * برای همیشه روی «در انتظار درخواست…» می‌ماند.
 *
 * روش: هر بلوک <script> بیرون کشیده می‌شود، تگ‌های PHP با مقدار بی‌ضرر
 * جایگزین می‌شوند، و node --check روی نتیجه اجرا می‌شود.
 */
import { readFileSync, readdirSync, writeFileSync, unlinkSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join, relative, isAbsolute } from 'node:path';
import { resolveSite } from './harness/site.mjs';

const SITE = resolveSite();
const files = [];
(function walk(d) {
  for (const e of readdirSync(d, { withFileTypes: true })) {
    const abs = join(d, e.name);
    if (e.isDirectory()) walk(abs);
    else if (e.name.endsWith('.php')) files.push(abs);
  }
})(SITE);

/* وصله هم باید بررسی شود — وگرنه همان اشتباه lint.mjs تکرار می‌شود. */
if (process.env.PATCH) {
  /* PATCH هم می‌تواند مطلق باشد (مثل SITE) — برای رجRESSION test مفید است */
  const PD = isAbsolute(process.env.PATCH) ? process.env.PATCH : join(process.cwd(), '..', process.env.PATCH);
  const patched = [];
  (function walk(d) {
    for (const e of readdirSync(d, { withFileTypes: true })) {
      const abs = join(d, e.name);
      if (e.isDirectory()) walk(abs);
      else if (e.name.endsWith('.php')) patched.push(abs);
    }
  })(PD);
  for (const p of patched) {
    const rel = relative(PD, p);
    const hit = files.findIndex((f) => relative(SITE, f) === rel);
    if (hit >= 0) files[hit] = p; else files.push(p);
  }
}
console.log(`>>> ${files.length} فایل PHP در ${SITE}${process.env.PATCH ? ' (با وصلهٔ ' + process.env.PATCH + ')' : ''}`);

/* تگ PHP داخل <script> را خنثی می‌کند تا node بتواند پارس کند.
   ترتیب مهم است: اول حالت «echo …» (که یک مقدار می‌خواهد)، بعد بقیه. */
function dephp(js) {
  /* دو حالت را باید از هم تشخیص داد، وگرنه خطای کاذب می‌سازیم:
       جای عبارت : let a = <?php echo $x; ?>;   → باید «۱» شود
       جای جمله  : <?php foreach(...): ?>\n     → باید خالی شود
     اگر جای جمله «۱» شود، خط بعدی به آن می‌چسبد (۱addOption(...)) و اگر
     جای عبارت کامنت شود، «let a = ;» ساخته می‌شود. هر دو خطای کاذب‌اند.
     معیار: اگر تگ در ابتدای خط باشد یا بعدش تا آخر خط فقط فاصله باشد،
     جای جمله است. هر دو لازم‌اند؛ مثلاً
         <?php endforeach; if(...): ?>addOption('',false);
     ابتدای خط است ولی بلافاصله کد بعدش می‌آید — باز هم جای جمله است. */
  return js.replace(/<\?(?:php\b|=)?[\s\S]*?\?>/g, (m, at) => {
    const before = js.slice(0, at);
    const atLineStart = /(?:^|\r?\n)[ \t]*$/.test(before);
    const atLineEnd = /^[ \t]*(?:\r?\n|$)/.test(js.slice(at + m.length));
    return (atLineStart || atLineEnd) ? '' : '1';
  });
}

let checked = 0, bad = 0;
const TMP = join(process.cwd(), '.tmp-jssyntax.js');

for (const abs of files) {
  const src = readFileSync(abs, 'utf8');
  const blocks = [...src.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map((m) => m[1]);
  if (!blocks.length) continue;
  checked++;
  const rel = process.env.PATCH && abs.includes(process.env.PATCH)
    ? process.env.PATCH + '/' + relative(join(process.cwd(), '..', process.env.PATCH), abs)
    : relative(SITE, abs);
  /* هر بلوک جدا پارس می‌شود — همان‌طور که مرورگر انجام می‌دهد. چسباندنشان
     به هم «Identifier X has already been declared» کاذب می‌سازد، چون در
     مرورگر هر <script> اسکریپت مستقلی است. */
  blocks.forEach((raw, bi) => {
    writeFileSync(TMP, dephp(raw));
    try {
      execFileSync(process.execPath, ['--check', TMP], { stdio: 'pipe' });
    } catch (e) {
      bad++;
      const msg = (e.stderr ? e.stderr.toString() : String(e)).split('\n').filter(Boolean).slice(0, 4);
      console.log(`\n❌ ${rel} (بلوک ${bi + 1} از ${blocks.length})`);
      msg.forEach((l) => console.log('     ' + l));
    }
  });
}

try { unlinkSync(TMP); } catch (e) {}

console.log(`\nبررسی شد: ${checked} فایل دارای <script> · خطا: ${bad}`);
console.log(bad === 0 ? 'همهٔ بلوک‌های جاوااسکریپت بدون خطای نحوی' : '❌ دست‌کم یک فایل جاوااسکریپت خراب دارد');
process.exit(bad ? 1 : 0);
