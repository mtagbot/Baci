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
  ok(source.includes('assets/css/exam-designer-mobile.css?v=4.161.0'), `${name}: mobile layer is linked`);
  ok(source.includes('data-mobile-control="bank"'), `${name}: touch dock is installed`);
  ok(source.includes('function editorViewportScale()'), `${name}: preview scale helper exists`);
  ok(source.includes('source-crop-handle'), `${name}: source pages expose four direct crop handles`);
  ok(source.includes('function sourceCropHandleDown'), `${name}: direct crop drag handler exists`);
  ok(source.includes('function sourceCropApply'), `${name}: crop changes update the live page`);
  ok(source.includes('function normalizeQuestionHtml'), `${name}: saved/bank question images are normalized for touch editing`);
  ok(source.includes('function imageLayoutScale'), `${name}: scaled preview converts touch movement to page pixels`);
  ok(source.includes('function finishImageResize'), `${name}: image resize has a touch-safe completion path`);
  ok(source.includes('function moveSelectedImage') && source.includes('mobileImageTools'), `${name}: mobile image nudge tools exist`);
  ok(source.includes('let imageEditOriginal=null, imageEditTarget=null'), `${name}: image editor can reopen a selected image`);
  ok(source.includes('imageSettings'), `${name}: image editor settings remain revisitable`);
  ok((source.match(/function updateModelFromDomImage\(\)/g)||[]).length===1, `${name}: image model updater is not duplicated`);
  ok(source.includes('offsetWidth||sf.clientWidth'), `${name}: crop height uses unscaled image geometry`);
  ok(source.includes('function moveQuestion(id,delta)'), `${name}: touch-safe question ordering exists`);
  ok(source.includes('onclick="moveQuestion(\'${it.id}\',-1)"'), `${name}: move-up action exists`);
  ok(source.includes('onclick="moveQuestion(\'${it.id}\',1)"'), `${name}: move-down action exists`);
  ok(source.includes('@page{size:A4;margin:0}'), `${name}: A4 print model remains`);
  ok(source.includes('width:210mm') && source.includes('height:297mm'), `${name}: millimetre print geometry remains`);
  ok(source.includes('@media print{'), `${name}: print media rules remain`);
  ok(source.includes("document.execCommand('fontName'"), `${name}: selected text receives font changes without rewriting the whole editor`);
  ok(source.includes('q-direction-block'), `${name}: whole-question direction is persisted in question HTML`);
  ok(source.includes('mobile-range-preview'), `${name}: range drag can reveal the page preview`);
  ok(!/designPayload\(\)[\s\S]{0,900}mobileEditorZoom/.test(source), `${name}: screen zoom is not saved into the exam design`);
}

ok(css === desktopCss, 'site and desktop receive byte-identical mobile CSS');
ok(css.includes('@media screen and (max-width: 860px)'), 'phone/tablet breakpoint exists');
ok(css.includes('.mobile-editor-dock') && css.includes('display: none;'), 'thumb-reachable mobile dock exists but stays hidden on desktop');
ok(css.includes('.mobile-image-tools') && css.includes('mobile-image-tools-edit'), 'selected question images get a touch toolbar');
ok(css.includes('.img-handle') && css.includes('width: 42px'), 'image resize handles have larger mobile hit areas');
ok(css.includes('#pagesRoot .page') && css.includes('transform: scale(var(--editorScale'), 'only the screen preview is scaled');
ok(css.includes('transform-origin: top left') && css.includes('--editorPageOffset') && css.includes('direction: ltr'), 'the scaled A4 page is centred from a phone-safe left gutter');
ok(css.includes('source-crop-handle') && css.includes('touch-action: none'), 'source-page crop handles are touch-safe');
ok(css.includes('mobile-range-preview .editor-panel'), 'settings panel can disappear while a range is held');
ok(css.includes('grid-template-columns: 1fr !important'), 'bank filters collapse to a touch-friendly column');
ok(css.includes('.q-modal-card') && css.includes('max-height: calc(100dvh'), 'question editor fits the dynamic mobile viewport');
ok(css.includes('touch-action: none'), 'drag/resize controls reserve touch gestures');
ok(css.includes('@media print') && css.includes('mobile-editor-dock,') && css.includes('mobile-image-tools {display:none!important;}'), 'mobile chrome is explicitly absent from print');

console.log(`PASS ${checks} mobile/tablet live-exam designer invariants`);
