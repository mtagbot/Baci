// Workaround for Node 22.22 + @php-wasm 3.1.53: the emscripten glue
// (node-8-*/asyncify|jspi/php_8_3.js) decides its directory with
//   typeof __dirname !== 'undefined' ? __dirname : dirname(fileURLToPath(import.meta.url))
// assuming __dirname is undefined in ESM. In this runtime such ESM files
// (CJS-scoped package, syntax-detected ESM) expose __dirname as the bogus
// value '.', so the php .wasm resolves relative to the process CWD and the
// runtime aborts with ENOENT '8_3_33/php_8_3.wasm'. Force the
// import.meta.url derivation. Idempotent; runs on every npm install/ci.
const fs = require('fs'), path = require('path');
const root = path.join(__dirname, 'node_modules', '@php-wasm');
let patched = 0;
if (!fs.existsSync(root)) process.exit(0);
const re = /const currentDirPath =\s*typeof __dirname !== 'undefined'\s*\?\s*__dirname\s*:\s*path\.dirname\(fileURLToPath\(import\.meta\.url\)\);/;
for (const pkg of fs.readdirSync(root)) {
  if (!/^node-8-/.test(pkg)) continue;
  for (const sub of ['asyncify', 'jspi']) {
    const dir = path.join(root, pkg, sub);
    if (!fs.existsSync(dir)) continue;
    for (const f of fs.readdirSync(dir)) {
      if (!f.endsWith('.js') || f === 'index.js' || f === 'glue-check.mjs') continue;
      const p = path.join(dir, f);
      let src;
      try { src = fs.readFileSync(p, 'utf8'); } catch { continue; }
      if (!re.test(src)) continue;
      fs.writeFileSync(p, src.replace(re, "const currentDirPath = path.dirname(fileURLToPath(import.meta.url));"));
      patched++;
      console.log('patched', p);
    }
  }
}
console.log('php-wasm glue patched:', patched);
