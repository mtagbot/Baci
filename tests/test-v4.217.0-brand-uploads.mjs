import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const siteSettings = read('update-v4.217.0/settings.php');
const desktopSettings = read('desktop-app-v2/patch/www-settings.php');
const desktopHeader = read('desktop-app-v2/patch/includes-header.php');
const siteHeader = read('update-v4.217.0/includes/header.php');
for (const [label, source] of [['site', siteSettings], ['desktop', desktopSettings]]) {
  assert.match(source, /<input type="file" name="logo_file"/);
  assert.match(source, /<input type="file" name="favicon_file"/);
  assert.doesNotMatch(source, /<input type="text" name="logo_url"/);
  assert.doesNotMatch(source, /<input type="text" name="favicon_url"/);
  assert.match(source, /getimagesize\(/);
  assert.match(source, /finfo_file\(/);
  assert.match(source, /move_uploaded_file\(/);
  assert.match(source, /uploads\/branding\//);
  assert.match(source, /set_setting\(\$settingKey, \$relativePath\)/);
  assert.ok(source.includes("clean($s_logo)"));
  assert.ok(source.includes("clean($s_fav)"));
  assert.match(source, /if \(!isset\(\$_FILES\[\$field\]\).*UPLOAD_ERR_NO_FILE/);
}
assert.match(siteHeader, /<link rel="icon"/);
assert.match(desktopHeader, /<link rel="icon"/);
assert.match(siteHeader, /get_setting\('logo_url'/);
assert.match(desktopHeader, /get_setting\('logo_url'/);
console.log('V4.217.0 logo and favicon upload checks passed.');
