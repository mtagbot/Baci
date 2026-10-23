import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const root = new URL('../', import.meta.url);
const assetUrl = new URL('update-v4.152.0/assets/vendor/chart.umd.min.js', root);
const footerUrl = new URL('update-v4.152.0/includes/footer.php', root);
const analyticsUrl = new URL('update-v4.152.0/analytics.php', root);
const asset = readFileSync(assetUrl, 'utf8');
const footer = readFileSync(footerUrl, 'utf8');
const analytics = readFileSync(analyticsUrl, 'utf8');
const Chart = require(assetUrl.pathname);

assert.match(asset, /Chart\.js v4\.6\.1/);
assert.equal(Chart.Chart.version, '4.6.1');
assert.match(footer, /assets\/vendor\/chart\.umd\.min\.js\?v=4\.6\.1/);
assert.match(analytics, /new Chart\(classCtx/);
assert.match(analytics, /new Chart\(monthCtx/);
assert.match(analytics, /new Chart\(crossCtx/);
assert.match(analytics, /new Chart\(yearCtx/);
console.log('PASS local Chart.js 4.6.1 loads and analytics initializes all four charts');
