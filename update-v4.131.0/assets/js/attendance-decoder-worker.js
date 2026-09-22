/* One transferable RGBA buffer in flight. No network API/key/student list.
 * The caller decides the inversion cost: the centre-crop passes (most frames)
 * use dontInvert because a mirrored/inverted tag is rare and the inversion
 * search roughly doubles decode time — the periodic full-frame pass asks for
 * attemptBoth so an inverted tag is still found. */
'use strict';
  // jsQR uses .from only for the literal arrays [0] and [1]. Older camera-
  // capable browsers may have typed arrays but not this ES2015 static method.
  if (typeof Uint8ClampedArray !== 'undefined' && !Uint8ClampedArray.from) {
    Uint8ClampedArray.from = function (values) { return new Uint8ClampedArray(values); };
  }

importScripts('jsqr.min.js');
self.onmessage = function (event) {
  var m = event.data, code;
  try {
    code = jsQR(new Uint8ClampedArray(m.pixels), m.width, m.height, { inversionAttempts: m.invert ? 'attemptBoth' : 'dontInvert' });
    self.postMessage({ id: m.id, data: code && code.data || null });
  } catch (e) { self.postMessage({ id: m.id, error: true }); }
};
