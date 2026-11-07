import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
for (const path of ['update-v4.218.0/settings.php', 'desktop-app-v2/patch/www-settings.php']) {
  const source = read(path);
  const logoPreview = source.match(/<\?php if \(\$s_logo\): \?>[\s\S]*?<\?php else:/)?.[0] ?? '';
  const faviconPreview = source.match(/<\?php if \(\$s_fav\): \?>[\s\S]*?<\?php else:/)?.[0] ?? ''; 
  assert.ok(logoPreview, `${path}: logo preview exists`);
  assert.ok(faviconPreview, `${path}: favicon preview exists`);
  for (const [label, preview] of [['logo', logoPreview], ['favicon', faviconPreview]]) {
    assert.match(preview, /width:84px;height:84px;max-width:84px;max-height:84px/);
    assert.match(preview, /max-width:64px;max-height:64px/);
    assert.match(preview, /overflow:hidden/);
    assert.doesNotMatch(preview, /class="[^"]*w-12|class="[^"]*h-12/);
  }
}
console.log('V4.218.0 compact image preview checks passed.');
