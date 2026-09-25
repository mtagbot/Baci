import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const site = readFileSync(new URL('../update-v4.152.0/exam-print.php', import.meta.url), 'utf8');
const desktop = readFileSync(new URL('../desktop-app-v2/patch/www-exam-print.php', import.meta.url), 'utf8');
const css = readFileSync(new URL('../update-v4.152.0/assets/css/exam-designer-mobile.css', import.meta.url), 'utf8');
const desktopCss = readFileSync(new URL('../desktop-app-v2/patch/www-assets-css-exam-designer-mobile.css', import.meta.url), 'utf8');
let checks = 0;
function ok(condition, message) {
  assert.ok(condition, message);
  checks++;
}

for (const [name, source] of [['site', site], ['desktop', desktop]]) {
  ok(source.includes('assets/css/exam-designer-mobile.css?v=4.160.0'), `${name}: mobile layer is linked`);
  ok(source.includes('data-mobile-control="bank"'), `${name}: touch dock is installed`);
  ok(source.includes('function editorViewportScale()'), `${name}: preview scale helper exists`);
  ok(source.includes('function moveQuestion(id,delta)'), `${name}: touch-safe question ordering exists`);
  ok(source.includes('onclick="moveQuestion(\'${it.id}\',-1)"'), `${name}: move-up action exists`);
  ok(source.includes('onclick="moveQuestion(\'${it.id}\',1)"'), `${name}: move-down action exists`);
  ok(source.includes('@page{size:A4;margin:0}'), `${name}: A4 print model remains`);
  ok(source.includes('width:210mm') && source.includes('height:297mm'), `${name}: millimetre print geometry remains`);
  ok(source.includes('@media print{'), `${name}: print media rules remain`);
  ok(!/designPayload\(\)[\s\S]{0,900}mobileEditorZoom/.test(source), `${name}: screen zoom is not saved into the exam design`);
}

ok(css === desktopCss, 'site and desktop receive byte-identical mobile CSS');
ok(css.includes('@media screen and (max-width: 860px)'), 'phone/tablet breakpoint exists');
ok(css.includes('.mobile-editor-dock'), 'thumb-reachable mobile dock exists');
ok(css.includes('#pagesRoot .page') && css.includes('transform: scale(var(--editorScale'), 'only the screen preview is scaled');
ok(css.includes('grid-template-columns: 1fr !important'), 'bank filters collapse to a touch-friendly column');
ok(css.includes('.q-modal-card') && css.includes('max-height: calc(100dvh'), 'question editor fits the dynamic mobile viewport');
ok(css.includes('touch-action: none'), 'drag/resize controls reserve touch gestures');
ok(css.includes('@media print') && css.includes('.mobile-editor-dock {display:none!important;}'), 'mobile chrome is explicitly absent from print');

console.log(`PASS ${checks} mobile/tablet live-exam designer invariants`);
