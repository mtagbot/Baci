/**
 * بررسی نحوی همهٔ فایل‌های PHP سایت.
 *
 * در این محیط php -l وجود ندارد، پس از token_get_all($src, TOKEN_PARSE)
 * استفاده می‌شود که همان تجزیه‌گر واقعی PHP است.
 */
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
import { readFileSync, readdirSync } from 'node:fs';
import { join, relative } from 'node:path';
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

/* v4.129.0 — PATCH باید اینجا هم اورلی شود.
   تا پیش از این lint فقط سورس پایه را می‌دید؛ یعنی `PATCH=update-vX node lint.mjs`
   «۰ خطا» چاپ می‌کرد در حالی که فایل‌های وصله اصلاً بررسی نشده بودند. */
if (process.env.PATCH) {
  const PD = join(process.cwd(), '..', process.env.PATCH);
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
    console.log(`      lint ← وصله: ${rel}`);
  }
}

console.log(`>>> ${files.length} فایل PHP در ${SITE}${process.env.PATCH ? ' (با وصلهٔ ' + process.env.PATCH + ')' : ''}`);

const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } }));
php.mkdirTree('/lint');
const manifest = [];
const risky = [];   // فایل‌هایی که با short_open_tag=On می‌شکنند

/* بستهٔ دسکتاپ در php/php.ini صریحاً `short_open_tag = Off` دارد. با Off،
   هر <? که php یا = نباشد (مثل پردازشگر XML در export-excel.php) متن معمولی
   است و PHP اصلاً آن را نمی‌بیند. php-wasm پیش‌فرض On است و چون این تنظیم
   PHP_INI_PERDIR است، با ini_set عوض نمی‌شود؛ پس همان چیزی را که PHP در تولید
   می‌بیند بازسازی می‌کنیم: آن پردازشگرها را با متن معمولی جایگزین می‌کنیم. */
const PI = /<\?(?!php\b|=)[a-zA-Z][^>]*\?>/g;

files.forEach((abs, i) => {
  const rel = relative(SITE, abs);
  let code = readFileSync(abs, 'utf8');
  const hits = code.match(PI);
  if (hits) {
    risky.push([rel, hits.length]);
    code = code.replace(PI, '<!-- xml-pi -->');
  }
  const p = '/lint/f' + i + '.php';
  php.writeFile(p, code);
  manifest.push([p, rel]);
});
php.writeFile('/lint/manifest.json', JSON.stringify(manifest));

const r = await php.runStream({ code: `<?php
$m = json_decode(file_get_contents('/lint/manifest.json'), true);
$bad = 0;
foreach ($m as [$p, $rel]) {
  try { token_get_all(file_get_contents($p), TOKEN_PARSE); }
  catch (Throwable $e) { $bad++; echo "ERROR  $rel  ->  " . $e->getMessage() . "\\n"; }
}
echo ($bad ? "$bad فایل خطای نحوی دارد\\n" : "همهٔ فایل‌ها بدون خطای نحوی\\n");
echo "COUNT=" . count($m) . " BAD=$bad\\n";` });

const out = await r.stdoutText;
console.log(out.trim());

if (risky.length) {
  console.log('\n⚠️  هشدار قابلیت حمل (خطا نیست): این فایل‌ها پردازشگر XML خام دارند که');
  console.log('   فقط با short_open_tag=Off سالم می‌ماند — همان تنظیمی که بستهٔ دسکتاپ دارد.');
  console.log('   اگر روزی روی میزبانی با short_open_tag=On برود، fatal parse error می‌گیرد:');
  risky.forEach(([rel, n]) => console.log(`   · ${rel} (${n} مورد)`));
}

const m = out.match(/BAD=(\d+)/);
process.exit(m && m[1] === '0' ? 0 : 1);
