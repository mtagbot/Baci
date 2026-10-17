import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
import { readFileSync } from 'node:fs';

const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } }));
php.mkdirTree('/q');
const files = process.argv.slice(2);
const manifest = [];
files.forEach((f, i) => {
  const p = '/q/f' + i + '.php';
  php.writeFile(p, readFileSync(f, 'utf8'));
  manifest.push([p, f]);
});
php.writeFile('/q/manifest.json', JSON.stringify(manifest));
const r = await php.runStream({ code: `<?php
$m = json_decode(file_get_contents('/q/manifest.json'), true);
$bad = 0;
foreach ($m as [$p, $rel]) {
  try { token_get_all(file_get_contents($p), TOKEN_PARSE); echo "OK  $rel\\n"; }
  catch (Throwable $e) { $bad++; echo "ERR $rel -> " . $e->getMessage() . "\\n"; }
}
echo "BAD=$bad\\n";` });
const out = await r.stdoutText;
process.stdout.write(out);
const m = out.match(/BAD=(\d+)/);
process.exit(m && m[1] === '0' ? 0 : 1);
