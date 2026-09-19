/* Reversible scanner hotfix. ES5 + XHR: no fetch, Promise constructor/finally,
 * framework, CDN, Worker or native detector required by the fallback path.
 * Attendance rules/API are deliberately unchanged. Never queue camera opens,
 * frame buffers or attendance writes without bounds. */
(function () {
  'use strict';
  // jsQR uses .from only for the literal arrays [0] and [1]. Older camera-
  // capable browsers may have typed arrays but not this ES2015 static method.
  if (typeof Uint8ClampedArray !== 'undefined' && !Uint8ClampedArray.from) {
    Uint8ClampedArray.from = function (values) { return new Uint8ClampedArray(values); };
  }

  var config = window.ATT_SCANNER_CONFIG || {};
  var API = config.api || 'attendance-scan-api.php', KEY = config.key || '';
  function el(id) { return document.getElementById(id); }
  function noop() {}
  function now() { return Date.now(); }
  function observe(value, yes, no) {
    if (value && typeof value.then === 'function') value.then(yes, no);
    else yes(value);
  }
  var video = el('cam'), canvas = el('qrCanvas'), ctx;
  try { ctx = canvas.getContext('2d', { willReadFrequently: true }); }
  catch (e) { ctx = canvas.getContext('2d'); }
  var camMsg = el('camMsg'), camBtn = el('camSwitch'), camRetry = el('camRetry'), camLabel = el('camLabel');
  var resBox = el('resBox'), resIcon = el('resIcon'), resName = el('resName'), resStat = el('resStat'), resSub = el('resSub');
  var busy = false;
  function faDigits(s) { return String(s).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
  function clock() {
    if (document.hidden) return;
    var d = new Date();
    el('clock').textContent = faDigits(('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2) + ':' + ('0' + d.getSeconds()).slice(-2));
  }
  clock(); setInterval(clock, 1000);

  var actx = null;
  function audioCtx(){
    if (!actx) {
      try { actx = new (window.AudioContext || window.webkitAudioContext)(); } catch(e){ return null; }
    }
    if (actx && actx.state === 'suspended') { try { observe(actx.resume(), noop, noop); } catch(e){} }
    return actx;
  }
  function unlockAudio(){
    var c = audioCtx();
    if (c) {
      /* play a silent blip so iOS/Android fully unlock the output */
      try {
        var o = c.createOscillator(), g = c.createGain();
        g.gain.value = 0.0001; o.connect(g); g.connect(c.destination);
        o.start(); o.stop(c.currentTime + 0.03);
      } catch(e){}
    }
    var hint = document.getElementById('sndHint');
    if (hint) hint.style.display = 'none';
  }
  /* A tap is a safe moment to repair a camera that never opened (blocked
     autoplay, a permission prompt dismissed early, a browser that needs the
     interaction). The listeners stay attached, rate-limited, because a scanning
     page must never be one dead tap away from doing nothing. */
  var lastGestureRepair = 0;
  function onGesture() {
    unlockAudio();
    if (camState !== 'idle' && camState !== 'error') return;
    if (now() - lastGestureRepair < 10000) return;
    lastGestureRepair = now(); recoveryAttempts = 0;
    openCamera(0, false);
  }
  document.addEventListener('touchstart', onGesture, false);
  document.addEventListener('click', onGesture, false);

  /* one tone with attack/decay envelope */
  function tone(freq, startAt, dur, type, vol, slideTo){
    var c = audioCtx(); if (!c) return;
    try {
      var t0 = c.currentTime + startAt;
      var o = c.createOscillator(), g = c.createGain();
      o.type = type || 'sine';
      o.frequency.setValueAtTime(freq, t0);
      if (slideTo) o.frequency.exponentialRampToValueAtTime(slideTo, t0 + dur);
      g.gain.setValueAtTime(0.0001, t0);
      g.gain.exponentialRampToValueAtTime(vol || 0.5, t0 + 0.012);   // fast attack
      g.gain.setValueAtTime(vol || 0.5, t0 + dur * 0.7);
      g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);          // release
      o.connect(g); g.connect(c.destination);
      o.start(t0); o.stop(t0 + dur + 0.02);
    } catch(e){}
  }

  /* ✅ ACCEPT: rising two-tone chime — E6 then B6 (positive, bright) */
  function buzzOk(){
    tone(1318.5, 0,    0.09, 'sine', 0.55);
    tone(1975.5, 0.09, 0.22, 'sine', 0.55);
  }
  /* ⏰ WARN (late/duplicate): two mid beeps — attention, not failure */
  function buzzWarn(){
    tone(740, 0,    0.14, 'square', 0.30);
    tone(740, 0.22, 0.14, 'square', 0.30);
  }
  /* ❌ REJECT/ERROR: harsh low double BZZT — sawtooth + downward slide */
  function buzzErr(){
    tone(160, 0,    0.28, 'sawtooth', 0.60, 110);
    tone(160, 0.34, 0.34, 'sawtooth', 0.60, 90);
  }

  function showResult(kind, icon, name, stat, sub){
    resBox.className = 'result ' + kind;
    resIcon.textContent = icon; resName.textContent = name; resStat.textContent = stat; resSub.textContent = sub || '';
    if (kind === 'ok') buzzOk();
    else if (kind === 'warn') buzzWarn();
    else buzzErr();
    if (navigator.vibrate) {
      try { navigator.vibrate(kind === 'ok' ? 80 : kind === 'warn' ? [80,60,80] : [180,80,180]); } catch(e){}
    }
  }



  /* XHR bounds the ENTIRE response, including body reads, on older browsers.
   * The explicit timer also covers implementations without xhr.timeout. */
  function request(method, url, body, timeout, callback) {
    var xhr, timer, done = false;
    function finish(error, data) {
      if (done) return;
      done = true; clearTimeout(timer);
      if (xhr) {
        xhr.onreadystatechange = xhr.onerror = xhr.ontimeout = xhr.onabort = null;
        if (error) { try { xhr.abort(); } catch (e) {} }
      }
      callback(error, data);
    }
    try {
      xhr = new XMLHttpRequest();
      xhr.open(method, url, true);
      if (body !== null) xhr.setRequestHeader('Content-Type', 'text/plain;charset=UTF-8');
      try { xhr.timeout = timeout; } catch (e) {}
      xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) return;
        if (!xhr.status) { finish({ network: true }); return; }
        if (xhr.status < 200 || xhr.status >= 300) {
          finish({ server: true, message: 'خطای سرور HTTP ' + xhr.status }); return;
        }
        var data;
        try { data = JSON.parse(xhr.responseText); } catch (e) {}
        if (!data || typeof data.ok !== 'boolean') {
          finish({ server: true, message: 'پاسخ سرور قابل خواندن نیست (خروجی غیر JSON)' }); return;
        }
        finish(null, data);
      };
      xhr.onerror = function () { finish({ network: true }); };
      xhr.ontimeout = function () { finish({ timeout: true }); };
      xhr.onabort = function () { finish({ network: true }); };
      timer = setTimeout(function () { finish({ timeout: true }); }, timeout);
      xhr.send(body);
    } catch (e) { finish({ network: true }); }
    return function () { finish({ cancelled: true }); };
  }

  // At most one registration; no offline queue or premature success sound.
  // Per-tag cooldown replaces the 600 ms pause imposed on EVERY next student.
  var recentTags = [], sendAfter = 0;
  function seen(payload) {
    for (var i = recentTags.length - 1; i >= 0; i--) {
      if (now() - recentTags[i].time > 5000) recentTags.splice(i, 1);
      else if (recentTags[i].value === payload) return true;
    }
    return false;
  }
  function forget(payload) {
    for (var i = recentTags.length - 1; i >= 0; i--) if (recentTags[i].value === payload) recentTags.splice(i, 1);
  }
  function sendScan(payload, manual) {
    if (busy || (!manual && (now() < sendAfter || seen(payload)))) return false;
    busy = true; lastTagAt = now();
    // Immediate, neutral acknowledgement is NOT attendance confirmation.
    resBox.className = 'result'; resIcon.textContent = '⏳';
    resName.textContent = 'کد خوانده شد'; resStat.textContent = 'در حال ثبت…';
    resSub.textContent = '';
    forget(payload); recentTags.push({ value: payload, time: now() });
    if (recentTags.length > 32) recentTags.shift();
    function send(attempt) {
      request('POST', API, JSON.stringify({ action: 'scan', payload: payload, key: KEY }), 20000, function (error, j) {
        if (error && !error.server && attempt === 0) { send(1); return; }
        busy = false;
        if (error) {
          forget(payload); sendAfter = now() + 1500;
          if (error.server) showResult('err', '⚠️', 'خطای سرور', error.message, 'اسکن دوباره را امتحان کنید');
          else showResult('err', '📡', error.timeout ? 'پاسخ سرور دیر شد' : 'خطای شبکه', 'ثبت حضور تأیید نشد — دوباره اسکن کنید', 'ممکن است ثبت انجام شده باشد؛ اسکن مجدد «تکراری» نشان می‌دهد');
          return;
        }
        // Cooldown starts at confirmation, not before a possibly slow request.
        forget(payload); recentTags.push({ value: payload, time: now() });
        /* Only the name and whether the student made it on time — nothing else
           competes with the camera for attention. */
        if (j.ok && j.code === 'present') showResult('ok', '✅', j.student, 'ورود به موقع — ' + j.time, j['class'] || '');
        else if (j.ok && j.code === 'late') showResult('warn', '⏰', j.student, 'تأخیر — ' + j.time, j['class'] || '');
        else if (j.code === 'duplicate') showResult('warn', '🔁', j.student || 'تکراری', 'قبلاً ثبت شده: ' + (j.status || ''), j['class'] || '');
        else { forget(payload); sendAfter = now() + 1500; showResult('err', '❌', 'ناموفق', j.message || 'کد نامعتبر', ''); }
      });
    }
    send(0); return true;
  }
  /* ── Fast decode path ────────────────────────────────────────────────
   * One frame in flight, zero artificial pause: the instant a decode finishes,
   * the next camera frame is analysed. The pause that used to be added after
   * every pass was pure added latency for the next student.
   *
   * Most passes read a tight centre crop (that is where the operator holds the
   * tag, per the one-line hint under the camera) at 480–640 px, which is a much
   * cheaper jsQR job than a full 1080p frame. Every fifth pass reads the whole
   * frame with inversion attempts enabled, so an off-centre or inverted tag is
   * still found — without paying that cost on every frame.
   *
   * `imageSmoothingEnabled` is set explicitly on every pass: it must be true
   * when down-scaling (aliased QR modules stop decoding), false when the crop
   * is already small enough. */
  var mem = navigator.deviceMemory || 0, cores = navigator.hardwareConcurrency || 0;
  var LOW_END = (mem && mem <= 3) || (cores && cores <= 4);
  var FAST_DIM = LOW_END ? 480 : 640, CROP_RATIO = 0.72, FULL_EVERY = 5;
  var DECODE_TIMEOUT_MS = 1500, MAX_REST_MS = 40, FRAME_POLL_MS = 10, FORCE_DECODE_MS = 120;
  /* A stream can stop delivering frames without any error event: some browsers
   * keep video.currentTime at 0 for a live MediaStream, applyConstraints may
   * freeze the capture session, or the decoder may simply never be asked again.
   * Scanning must never depend on a signal that may never come, so the loop has
   * three independent frame signals and two self-healing steps. */
  var STALL_CHECK_MS = 1000, STALL_REATTACH_MS = 3000, STALL_REOPEN_MS = 9000;
  var scanTimer = null, scanning = false, decodeBusy = false;
  var scanEpoch = 0, passCounter = 0, lastFrameTime = -1, decodeJob = 0;
  var frameToken = 0, frameCallback = null, lastFrameToken = -1, lastDecodeAt = 0, lastTagAt = 0;
  var stallTicks = 0, sigCanvas = null, lastSignature = '';
  var worker = null, workerBroken = false, workerDone = null;
  var nativeDetector = null, nativeBroken = false;
  var decoderLoading = false, decoderWaiters = [], decoderRetryAt = 0, activeDecodeCancel = null;
  function loadDecoder(callback) {
    if (typeof window.jsQR === 'function') { callback(true); return; }
    decoderWaiters.push(callback);
    if (decoderLoading) return;
    decoderLoading = true;
    var script = document.createElement('script'), done = false;
    var timer = setTimeout(function () { finish(false); }, 12000);
    function finish(ok) {
      if (done) return; done = true; clearTimeout(timer); decoderLoading = false;
      if (!ok) decoderRetryAt = now() + 30000;
      script.onload = script.onerror = null;
      if (script.parentNode) script.parentNode.removeChild(script);
      var callbacks = decoderWaiters; decoderWaiters = [];
      for (var i = 0; i < callbacks.length; i++) callbacks[i](ok);
    }
    script.onload = function () { finish(typeof window.jsQR === 'function'); };
    script.onerror = function () { finish(false); };
    script.src = config.decoder || 'assets/js/jsqr.min.js';
    document.head.appendChild(script);
  }
  // Native detection (hardware accelerated) is the fast path when the browser
  // has it; jsQR in a Worker is the fallback and the default on most browsers.
  try {
    if (window.BarcodeDetector && typeof window.BarcodeDetector.getSupportedFormats === 'function') {
      observe(window.BarcodeDetector.getSupportedFormats(), function (formats) {
        if (!formats || formats.indexOf('qr_code') < 0) return;
        try { nativeDetector = new window.BarcodeDetector({ formats: ['qr_code'] }); } catch (e) {}
      }, noop);
    }
  } catch (e) {}
  function discardWorker() {
    if (worker) { worker.onmessage = worker.onerror = null; try { worker.terminate(); } catch (e) {} }
    worker = null; workerDone = null;
  }
  // Load/compile while the camera is opening, not after the first tag arrives.
  function prepareDecoder() {
    if (!workerBroken && window.Worker) {
      try {
        if (!worker) {
          worker = new Worker(config.worker || 'assets/js/attendance-decoder-worker.js');
          var owner = worker;
          worker.onmessage = function (event) {
            if (worker !== owner || !workerDone || event.data.id !== workerDone.id) return;
            var job = workerDone; workerDone = null;
            if (event.data.error) { workerBroken = true; discardWorker(); job.callback(null); prepareDecoder(); }
            else job.callback(event.data.data || null);
          };
          worker.onerror = function () {
            if (worker !== owner) return;
            var job = workerDone; workerBroken = true; discardWorker();
            if (job) job.callback(null);
            prepareDecoder();
          };
        }
        return;
      } catch (e) { workerBroken = true; discardWorker(); }
    }
    if (!decoderLoading && typeof window.jsQR !== 'function' && now() >= decoderRetryAt) loadDecoder(noop);
  }
  function jsDecode(image, invert, callback) {
    prepareDecoder();
    if (worker) {
      try {
        var id = ++decodeJob;
        workerDone = { id: id, callback: callback };
        worker.postMessage({ id: id, pixels: image.data.buffer, width: image.width, height: image.height, invert: !!invert }, [image.data.buffer]);
        return;
      } catch (e) {
        workerBroken = true; discardWorker();
        // A transferred/detached buffer must not be decoded on the main thread.
        callback(null); prepareDecoder(); return;
      }
    }
    var code = null;
    try { code = window.jsQR(image.data, image.width, image.height, { inversionAttempts: invert ? 'attemptBoth' : 'dontInvert' }); } catch (e) {}
    callback(code && code.data);
  }

  function frameImage(p) {
    var w = video.videoWidth, h = video.videoHeight, full = (p % FULL_EVERY) === 0;
    var sw = w, sh = h, sx = 0, sy = 0;
    if (!full) {
      sw = Math.round(w * CROP_RATIO); sh = Math.round(h * CROP_RATIO);
      sx = Math.round((w - sw) / 2); sy = Math.round((h - sh) / 2);
    }
    var ratio = sw / sh, cw = FAST_DIM, ch = Math.round(FAST_DIM / ratio);
    if (ch > FAST_DIM) { ch = FAST_DIM; cw = Math.round(FAST_DIM * ratio); }
    if (canvas.width !== cw) canvas.width = cw;
    if (canvas.height !== ch) canvas.height = ch;
    ctx.imageSmoothingEnabled = sw > cw;
    ctx.drawImage(video, sx, sy, sw, sh, 0, 0, cw, ch);
    return { width: cw, height: ch, full: full };
  }
  /* Signal 1 (exact, when the browser has it): one token per presented frame. */
  function armFrameCallback() {
    if (!scanning || document.hidden || frameCallback !== null) return;
    if (typeof video.requestVideoFrameCallback !== 'function') return;
    try {
      frameCallback = video.requestVideoFrameCallback(function () {
        frameCallback = null;
        if (!scanning || document.hidden) return;
        frameToken++;
        scheduleScan(0);
        armFrameCallback();
      });
    } catch (e) { frameCallback = null; }
  }
  /* Signal 2: video.currentTime. Signal 3: bounded re-decode of the standing
   * frame, so a browser that never advances a signal cannot stop scanning. */
  function frameFresh() {
    if (video.readyState < 2 || !video.videoWidth || !video.videoHeight) return false;
    if (video.currentTime !== lastFrameTime) return true;
    if (frameToken !== lastFrameToken) return true;
    return now() - lastDecodeAt >= FORCE_DECODE_MS;
  }
  /* A cheap 16x12 fingerprint: if the pixels keep changing, frames are arriving
   * even when no time signal moves. */
  function frameSignature() {
    try {
      if (!sigCanvas) { sigCanvas = document.createElement('canvas'); sigCanvas.width = 16; sigCanvas.height = 12; }
      var sctx = sigCanvas.getContext('2d');
      sctx.drawImage(video, 0, 0, 16, 12);
      var data = sctx.getImageData(0, 0, 16, 12).data, sum = 0, sig = '';
      for (var i = 0; i < data.length; i += 4) sum += data[i] + data[i + 1] + data[i + 2];
      sig = String(Math.round(sum / 48));
      for (var k = 0; k < data.length; k += 68) sig += ':' + data[k];
      return sig;
    } catch (e) { return ''; }
  }
  function reattachStream() {
    if (!currentStream || document.hidden || camState !== 'ready') return;
    camMsg.textContent = 'تصویر دوربین ثابت ماند؛ در حال بازیابی…';
    try {
      if ('srcObject' in video) { video.srcObject = null; video.srcObject = currentStream; }
      else if ('mozSrcObject' in video) video.mozSrcObject = currentStream;
    } catch (e) {}
    lastFrameTime = -1; lastFrameToken = -1; lastVideoTime = -1; lastDecodeAt = 0;
    try { observe(video.play(), function () { if (scanning) scheduleScan(0); }, noop); } catch (e) { scheduleScan(0); }
  }
  /* Last resort: the stream itself is dead. Reopen the same camera, and never
   * let the lens tuning freeze the scanner again for this session. */
  function recoverStalledCamera() {
    if (!currentStream || document.hidden || camState !== 'ready') return;
    if (recoveryAttempts >= 3) return;
    stallTicks = 0; recoveryAttempts++; opticsSafeMode = true;
    camMsg.textContent = 'دوربین پاسخ نمی‌دهد؛ بازکردن دوبارهٔ همان دوربین…';
    openCamera(0, false);
  }
  function stallCheck() {
    if (!scanning || document.hidden || camState !== 'ready' || pendingOpen || busy || decodeBusy) return;
    if (!trackLive()) return;
    if (lastTagAt && now() - lastTagAt < 3000) { stallTicks = 0; return; }        // it just worked
    var sig = frameSignature();
    if (sig === '') return;                                                      // canvas unavailable: cannot judge
    if (sig !== lastSignature) { lastSignature = sig; stallTicks = 0; return; }  // pixels are moving
    if (video.currentTime !== lastFrameTime || frameToken !== lastFrameToken) { stallTicks = 0; lastSignature = sig; return; }
    stallTicks++;
    var stalledFor = stallTicks * STALL_CHECK_MS;
    if (stalledFor >= STALL_REOPEN_MS) { recoverStalledCamera(); return; }
    // Re-attach the live stream every few seconds first; only a stream that
    // stays frozen for the whole window justifies touching the camera itself.
    if (stalledFor % STALL_REATTACH_MS === 0) reattachStream();
  }
  function scheduleScan(rest) {
    if (!scanning || scanTimer || decodeBusy || document.hidden) return;
    if (rest > 0) {
      scanTimer = setTimeout(function () { scanTimer = null; scanTick(); }, rest);
      return;
    }
    // No rest: a frame that is already newer than the last decoded one is used
    // immediately. Otherwise a short poll picks up the next presented frame.
    if (frameFresh()) { scanTick(); return; }
    scheduleScan(FRAME_POLL_MS);
  }
  function scanTick() {
    if (!scanning || document.hidden || camState !== 'ready') return;
    if (!ctx) { scanning = false; camMsg.textContent = 'پردازش تصویر در این مرورگر ممکن نیست.'; return; }
    if (busy || decodeBusy || !frameFresh()) { scheduleScan(FRAME_POLL_MS); return; }
    if ((workerBroken || !window.Worker) && typeof window.jsQR !== 'function' && !nativeDetector) {
      if (now() < decoderRetryAt) { scheduleScan(1000); return; }
      var loadingEpoch = scanEpoch;
      decodeBusy = true;
      loadDecoder(function (ok) {
        if (loadingEpoch !== scanEpoch) return;
        decodeBusy = false;
        if (!ok) camMsg.textContent = 'بارگذاری تشخیص QR ناموفق بود.';
        scheduleScan(ok ? 0 : 1000);
      });
      return;
    }
    lastFrameTime = video.currentTime; lastFrameToken = frameToken; lastDecodeAt = now();
    var epoch = scanEpoch, generation = cameraGeneration, started = now(), finished = false;
    var useNative = !!(nativeDetector && !nativeBroken);
    decodeBusy = true;
    var timer = setTimeout(function () {
      if (useNative) { nativeBroken = true; nativeDetector = null; }
      else { workerBroken = true; discardWorker(); }
      complete(null);
    }, DECODE_TIMEOUT_MS);
    activeDecodeCancel = function () {
      if (finished) return;
      finished = true; clearTimeout(timer);
      // Native promises cannot be cancelled. Do not overlap a new native call.
      if (useNative) { nativeBroken = true; nativeDetector = null; }
    };
    function complete(data) {
      if (finished) return; finished = true; clearTimeout(timer);
      if (epoch !== scanEpoch) return;
      activeDecodeCancel = null; decodeBusy = false;
      if (generation === cameraGeneration && camState === 'ready' && !document.hidden && data) sendScan(data, false);
      // Tiny adaptive rest only when decoding itself is slower than the camera:
      // keeps a weak phone cool without adding a fixed pause to every student.
      var spent = now() - started;
      scheduleScan(spent > 25 ? Math.min(MAX_REST_MS, Math.round(spent * 0.1)) : 0);
    }
    try {
      var size = frameImage(passCounter++);
      if (useNative) {
        observe(nativeDetector.detect(canvas), function (codes) {
          complete(codes && codes.length ? codes[0].rawValue : null);
        }, function () { nativeBroken = true; nativeDetector = null; complete(null); });
      } else jsDecode(ctx.getImageData(0, 0, size.width, size.height), size.full, complete);
    } catch (e) { complete(null); }
  }
  function startScanLoop() {
    scanning = true; stallTicks = 0; lastSignature = ''; lastDecodeAt = 0;
    lastFrameTime = video.currentTime; lastFrameToken = frameToken;
    armFrameCallback(); scheduleScan(0);
  }
  function stopScanLoop() {
    scanning = false; scanEpoch++; decodeBusy = false;
    if (activeDecodeCancel) { activeDecodeCancel(); activeDecodeCancel = null; }
    clearTimeout(scanTimer); scanTimer = null;
    if (frameCallback !== null) { try { video.cancelVideoFrameCallback(frameCallback); } catch (e) {} frameCallback = null; }
    // Do not retain work/frame buffers from the old camera or a hidden page.
    discardWorker(); lastFrameTime = -1; passCounter = 0;
  }

  /* ── Optics: the lens is parked inside the 5–20 cm band and stays there ──
   *
   * What used to cost the time: autofocus. Every time a student moved the tag
   * closer or farther the lens swept, and each sweep is tens to hundreds of
   * milliseconds during which nothing can be decoded. So the lens is set once —
   * as soon as the camera is ready, before any tag — to a fixed distance inside
   * the band the operator scans at, and it is never moved again by a scan.
   *
   * The band is 5–20 cm; 12 cm is requested (the middle of the band, so both a
   * 5–10 cm and a 15–20 cm tag stay inside the depth of field). A device whose
   * range does not reach the band is parked at its closest reachable point and
   * the label says so honestly instead of pretending.
   *
   * Exposure is the second half of speed: a long shutter smears the tag while
   * the student walks past. The shortest shutter the device accepts is requested
   * with compensating gain, once, right after the focus lock — not after the
   * first tag, and never on the scan path. */
  var opticsEnabled = true, opticsSafeMode = false, optics = null, meterCanvas = null, optTorch = el('opticsTorch'), optStatus = el('opticsStatus');
  var NEAR_METERS = 0.12, BAND_MIN = 0.05, BAND_MAX = 0.20, FOCUS_WATCHDOG_MS = 5000;
  var SHUTTER_FACTOR = 8, ISO_CEILING = 1600, SHUTTER_MIN_BRIGHTNESS = 45, DARK_BRIGHTNESS = 22;
  function finiteNumber(n) { return typeof n === 'number' && isFinite(n); }
  function cameraSettings(track) { try { return track.getSettings ? track.getSettings() : {}; } catch (e) { return {}; } }
  function hasMode(caps, key, value) { return caps[key] && caps[key].indexOf(value) >= 0; }
  function validRange(r) { return r && finiteNumber(r.min) && finiteNumber(r.max) && r.max > r.min; }
  function inRange(n, r) { return finiteNumber(n) && validRange(r) && n >= r.min && n <= r.max; }
  /* Quantise away float noise (0.1 + 2*0.01 must be 0.12, not 0.1200000001)
   * so the value sent to the device and the value shown to the operator agree. */
  function tidy(n) { return finiteNumber(n) ? Math.round(n * 1e6) / 1e6 : n; }
  /* Nearest legal step of the device grid, never outside its own range. */
  function snapToRange(n, r) {
    n = Math.max(r.min, Math.min(r.max, n));
    var step = finiteNumber(r.step) && r.step > 0 ? r.step : 0;
    if (!step) return tidy(n);
    return tidy(Math.max(r.min, Math.min(r.max, r.min + Math.round((n - r.min) / step) * step)));
  }
  function roundUpRange(n, r) {
    var step = finiteNumber(r.step) && r.step > 0 ? r.step : 0;
    n = Math.max(r.min, Math.min(r.max, n));
    return tidy(Math.min(r.max, step ? r.min + Math.ceil((n - r.min) / step - 0.000001) * step : n));
  }
  function closeSetting(actual, expected, range) {
    var tolerance = Math.max(0.000001, Math.abs(expected) * 0.15);
    return finiteNumber(actual) && Math.abs(actual - expected) <= tolerance;
  }
  /* focusDistance is reported in metres; the tag is read at 5–20 cm. */
  function inBand(d) { return finiteNumber(d) && d >= BAND_MIN * 0.8 && d <= BAND_MAX * 1.3; }
  function nearFocusTarget(o) {
    var caps = o.caps, range = caps.focusDistance;
    if (hasMode(caps, 'focusMode', 'manual')) {
      if (validRange(range)) {
        var lo = Math.max(range.min, BAND_MIN), hi = Math.min(range.max, BAND_MAX);
        if (lo <= hi) {
          o.bandExact = true;
          return { focusMode: 'manual', focusDistance: snapToRange(Math.max(lo, Math.min(hi, NEAR_METERS)), range) };
        }
        // A lens that cannot reach the band: park at its closest reachable point.
        o.bandExact = false;
        return { focusMode: 'manual', focusDistance: range.min > BAND_MAX ? range.min : range.max };
      }
      if (range && finiteNumber(range.min) && !finiteNumber(range.max)) {
        o.bandExact = false;
        return { focusMode: 'manual', focusDistance: Math.max(NEAR_METERS, range.min) };
      }
      o.bandExact = null;   // the device never reports a distance: freeze in place
      return { focusMode: 'manual' };
    }
    if (hasMode(caps, 'focusMode', 'single-shot')) { o.bandExact = null; return { focusMode: 'single-shot' }; }
    return null;
  }
  /* The lock counts when the device reported manual mode AND a position that is
   * either inside the requested band or the best the lens can physically reach.
   * A readback that contradicts the request is never called locked. */
  function focusLockSatisfied(o, expected, s) {
    if (!expected || s.focusMode !== expected.focusMode) return false;
    if (expected.focusMode !== 'manual' || expected.focusDistance === undefined) return true;
    var d = s.focusDistance;
    if (inBand(d)) return true;
    if (o.bandExact === false) return true;
    return closeSetting(d, expected.focusDistance, o.caps.focusDistance);
  }
  function focusReading(o) {
    var s = cameraSettings(o.track), d = s.focusDistance;
    if (!o.focusTarget) return 'کنترل فوکوس در دسترس نیست (خودکار)';
    if (o.focusTarget.focusMode === 'single-shot') return s.focusMode === 'single-shot' ? 'قفل‌شده (تک‌مرحله‌ای)' : 'خودکار (قفل نشده)';
    if (s.focusMode !== 'manual') return 'خودکار (قفل نشده)';
    if (!o.confirmedFocus) return 'در حال بررسی';
    if (o.focusTarget.focusDistance === undefined) return 'قفل‌شده روی فاصلهٔ فعلی';
    if (!finiteNumber(d)) return 'فاصله گزارش نشد (نامعلوم)';
    if (o.bandExact === false && !inBand(d)) return 'نزدیک‌ترین فاصلهٔ ممکن — ' + faDigits(Math.round(d * 100)) + ' سانتی‌متر';
    return 'قفل روی ' + faDigits(Math.round(d * 100)) + ' سانتی‌متر';
  }
  function currentOptics(o) { return optics === o && o.generation === cameraGeneration && currentStream && currentStream.getVideoTracks()[0] === o.track && !document.hidden; }
  function stopOptics() {
    if (optics) { clearTimeout(optics.timer); clearTimeout(optics.verifyTimer); clearTimeout(optics.lightTimer); }
    optics = null;
    if (optTorch) optTorch.disabled = true;
  }
  function renderOptics(o) {
    if (!currentOptics(o)) return;
    var s = cameraSettings(o.track);
    // The verbose line is diagnostics only (hidden); the operator sees one short
    // status sentence under the camera.
    if (optStatus) optStatus.textContent = 'focus=' + focusReading(o) + ' sys=' + String(s.focusMode) + ' d=' + String(s.focusDistance) + ' fps=' + String(s.frameRate) + ' shutter=' + String(s.exposureTime) + ' iso=' + String(s.iso) + ' state=' + o.state;
    if (optTorch) {
      optTorch.disabled = !o.torchAvailable || !!o.pending;
      optTorch.textContent = s.torch === true ? 'چراغ روشن' : 'چراغ';
    }
  }
  function normalOptics(o) {
    var f = {};
    if (hasMode(o.caps, 'focusMode', 'continuous')) f.focusMode = 'continuous';
    else if (o.original.focusMode) {
      f.focusMode = o.original.focusMode;
      if (o.original.focusMode === 'manual' && inRange(o.original.focusDistance, o.caps.focusDistance)) f.focusDistance = o.original.focusDistance;
    }
    if (hasMode(o.caps, 'exposureMode', 'continuous')) f.exposureMode = 'continuous';
    else {
      if (o.original.exposureMode) f.exposureMode = o.original.exposureMode;
      if (inRange(o.original.exposureTime, o.caps.exposureTime)) f.exposureTime = o.original.exposureTime;
      if (inRange(o.original.iso, o.caps.iso)) f.iso = o.original.iso;
    }
    return f;
  }
  function opticConstraints(o) {
    var base = JSON.parse(JSON.stringify(o.base)), fields = {}, key;
    if (o.enabled) {
      if (o.focusTarget) for (key in o.focusTarget) if (Object.prototype.hasOwnProperty.call(o.focusTarget, key)) fields[key] = o.focusTarget[key];
      if (o.exposureTarget) for (key in o.exposureTarget) if (Object.prototype.hasOwnProperty.call(o.exposureTarget, key)) fields[key] = o.exposureTarget[key];
      if (o.caps.frameRate && validRange(o.caps.frameRate)) {
        var want = o.caps.frameRate.max >= 60 ? 60 : o.caps.frameRate.max;
        base.frameRate = { ideal: want, max: o.caps.frameRate.max };
      }
    }
    if (fields.focusMode !== 'manual') delete fields.focusDistance;
    if (o.torchAvailable) fields.torch = o.torchWanted;
    // Replace only our fields in advanced constraints, retaining other options
    // and the original device/resolution. Do not leave contradictory old AF/AE.
    var managed = { focusMode: 1, focusDistance: 1, exposureMode: 1, exposureTime: 1, iso: 1, torch: 1 };
    for (key in managed) if (Object.prototype.hasOwnProperty.call(managed, key)) delete base[key];
    base.advanced = (base.advanced || []).map(function (old) {
      var clean = {};
      for (var k in old) if (Object.prototype.hasOwnProperty.call(old, k) && !managed[k]) clean[k] = old[k];
      return clean;
    });
    base.advanced.push(fields);
    return base;
  }
  /* If the device refuses to hold the near lock, never fall back to continuous
   * autofocus (that reintroduces the sweep we removed). One-shot hold is the
   * fallback; if even that is unavailable the camera stays untouched and the
   * short status line says the lock was not confirmed. */
  function fallbackFocus(o, reason) {
    o.exposureTarget = null; o.confirmedExposure = false;
    if (o.focusTarget && o.focusTarget.focusMode === 'manual' && hasMode(o.caps, 'focusMode', 'single-shot')) {
      o.focusTarget = { focusMode: 'single-shot' }; o.bandExact = null;
      o.confirmedFocus = false; o.state = 'single-shot';
      o.shortMessage = reason + ' — فوکوس تک‌مرحله‌ای (بدون فوکوس مجدد)';
      o.revision++; return true;
    }
    o.enabled = false; o.state = 'unconfirmed';
    o.shortMessage = reason + ' — فوکوس دست‌نخورده می‌ماند';
    setControls();
    return false;
  }
  function flushOptics(o) {
    if (!currentOptics(o) || !o.supported || o.pending || o.appliedRevision === o.revision || o.state === 'safe' || o.state === 'unconfirmed') return;
    var revision = o.revision, expectedFocus = o.enabled && o.focusTarget, expectedExposure = o.enabled && o.exposureTarget;
    var expectedTorch = o.torchWanted, request = {}, constraints = opticConstraints(o);
    o.pending = request; renderOptics(o);
    o.timer = setTimeout(function () {
      if (!currentOptics(o) || o.pending !== request) return;
      o.stalled = true;
      // applyConstraints cannot be cancelled: retain the slot until it settles.
      renderOptics(o);
    }, 2500);
    function complete(error) {
      if (!currentOptics(o) || o.pending !== request) return;
      clearTimeout(o.timer); o.pending = null; o.appliedRevision = revision;
      var s = cameraSettings(o.track), failed = !!error || o.stalled;
      if (revision === o.revision && !failed) {
        if (expectedFocus) {
          o.confirmedFocus = focusLockSatisfied(o, expectedFocus, s);
          if (!o.confirmedFocus) failed = true;
        }
        if (expectedExposure) {
          o.confirmedExposure = s.exposureMode === 'manual' && closeSetting(s.exposureTime, expectedExposure.exposureTime, o.caps.exposureTime) && closeSetting(s.iso, expectedExposure.iso, o.caps.iso);
          if (!o.confirmedExposure) failed = true;
        }
        if (o.torchAvailable && s.torch !== expectedTorch) failed = true;
      }
      o.stalled = false;
      if (failed && revision === o.revision && o.enabled) {
        var reason = o.exposureTarget && !expectedFocus ? 'نوردهی سریع تأیید نشد' : 'قفل فوکوس ' + faDigits(Math.round(NEAR_METERS * 100)) + ' سانتی‌متر تأیید نشد';
        fallbackFocus(o, reason);
      }
      if (revision === o.revision && !failed && o.confirmedFocus) startFastExposure(o);
      if (revision === o.revision) {
        if (o.confirmedFocus) o.shortMessage = 'فوکوس ثابت روی ' + faDigits(Math.round((finiteNumber(s.focusDistance) ? s.focusDistance : NEAR_METERS) * 100)) + ' سانتی‌متر'
          + (o.confirmedExposure ? ' — شاتر سریع' : '')
          + (o.notice ? ' — ' + o.notice : '')
          + ' — تگ را وسط تصویر بگیرید';
        // Unsupported hardware keeps the plain operating hint; a failed lock
        // must say so instead of pretending.
        if (o.shortMessage && (o.enabled || o.state === 'unconfirmed')) camMsg.textContent = o.shortMessage;
      }
      renderOptics(o);
      // One brightness sample decides whether the shorter shutter is safe.
      if (!failed && revision === o.revision && o.confirmedExposure && !o.lightChecked) {
        o.lightChecked = true;
        o.lightTimer = setTimeout(function () {
          if (!currentOptics(o) || !o.confirmedExposure || !o.enabled) return;
          var brightness = opticsBrightness();
          if (brightness !== null && ((brightness < DARK_BRIGHTNESS && o.referenceBrightness > SHUTTER_MIN_BRIGHTNESS && brightness < o.referenceBrightness * 0.35) || (brightness > 250 && o.referenceBrightness !== null && o.referenceBrightness < 200))) {
            o.exposureTarget = null; o.confirmedExposure = false;
            o.notice = 'تصویر تاریک/بیش‌ازحد روشن شد — نوردهی خودکار برگشت (فوکوس ثابت ماند)';
            o.revision++; flushOptics(o);
          }
        }, 500);
      }
      flushOptics(o);
    }
    try {
      observe(o.track.applyConstraints(constraints), function () {
        if (!currentOptics(o) || o.pending !== request) return;
        // Give settings a short opportunity to reflect the acknowledged request.
        o.verifyTimer = setTimeout(function () { complete(null); }, 60);
      }, complete);
    } catch (error) { complete(error); }
  }
  function configureOptics(track, generation) {
    stopOptics();
    var caps = {}, base = {};
    try { caps = track.getCapabilities ? track.getCapabilities() : {}; } catch (e) {}
    try { base = track.getConstraints ? track.getConstraints() : {}; } catch (e) {}
    var o = { track: track, generation: generation, caps: caps, base: base, original: cameraSettings(track),
      enabled: opticsEnabled && !opticsSafeMode, focusTarget: null, exposureTarget: null, bandExact: null,
      confirmedFocus: false, confirmedExposure: false, exposureAttempted: false, notice: '', pending: null, revision: 1, appliedRevision: 0, stalled: false,
      state: 'starting', shortMessage: 'در حال تنظیم فوکوس ۵ تا ۲۰ سانتی‌متر…' };
    o.torchAvailable = !!(caps.torch === true || (caps.torch && typeof caps.torch.indexOf === 'function' && caps.torch.indexOf(true) >= 0 && caps.torch.indexOf(false) >= 0));
    o.torchWanted = o.original.torch === true;
    o.focusTarget = o.enabled ? nearFocusTarget(o) : null;
    o.supported = !!track.applyConstraints && !!(o.focusTarget || o.torchAvailable || (caps.frameRate && caps.frameRate.max >= 60) || hasMode(caps, 'exposureMode', 'manual'));
    if (opticsSafeMode) {
      o.state = 'safe';
      o.shortMessage = 'تنظیم لنز برای این نشست کنار گذاشته شد تا اسکن قطع نشود';
    } else if (!o.focusTarget) {
      o.enabled = false; o.state = 'unavailable';
      o.shortMessage = 'این مرورگر کنترل فوکوس ندارد — دوربین دست‌نخورده می‌ماند';
    } else if (!o.supported) {
      o.enabled = false; o.state = 'unavailable';
      o.shortMessage = 'تنظیم دوربین در این مرورگر در دسترس نیست';
    } else if (o.focusTarget.focusMode === 'single-shot') {
      o.state = 'single-shot';
      o.shortMessage = 'فوکوس یک‌بار تنظیم می‌شود و دیگر جابه‌جا نمی‌شود';
    } else if (o.focusTarget.focusDistance === undefined) {
      o.state = 'frozen';
      o.shortMessage = 'فوکوس روی فاصلهٔ فعلی ثابت می‌شود';
    } else {
      o.state = 'near';
      o.shortMessage = 'در حال قفل فوکوس روی ' + faDigits(Math.round(o.focusTarget.focusDistance * 100)) + ' سانتی‌متر…';
    }
    optics = o; renderOptics(o); flushOptics(o);
  }
  /* Shortest usable shutter: the biggest single speed win after the focus lock.
   * Applied once, right after the lock — never per scan, never after a tag. */
  function startFastExposure(o) {
    if (!o.confirmedFocus || o.exposureAttempted || !o.enabled) return;
    o.exposureAttempted = true;
    var c = o.caps, s = o.original, time = s.exposureTime, iso = s.iso;
    if (!hasMode(c, 'exposureMode', 'manual')) return;
    if (!finiteNumber(time) || !finiteNumber(iso) || !(time > 0) || !(iso > 0)) return;
    if (!inRange(time, c.exposureTime) || !inRange(iso, c.iso)) return;
    var brightness = opticsBrightness();
    if (brightness !== null && brightness < SHUTTER_MIN_BRIGHTNESS) return;   // already dark: keep AE
    var gainLimit = Math.min(c.iso.max, Math.max(iso, ISO_CEILING)), factor = Math.min(SHUTTER_FACTOR, gainLimit / iso);
    var targetTime = roundUpRange(time / factor, c.exposureTime);
    var targetISO = roundUpRange(iso * time / targetTime, c.iso);
    if (!(targetTime < time * 0.8) || targetISO > gainLimit) return;
    o.exposureTarget = { exposureMode: 'manual', exposureTime: targetTime, iso: targetISO };
    o.referenceBrightness = brightness; o.lightChecked = false;
    o.revision++; flushOptics(o);
  }
  function focusWatchdog() {
    var o = optics;
    if (!o || !currentOptics(o) || !o.enabled || !o.focusTarget || o.pending || document.hidden) return;
    var s = cameraSettings(o.track);
    if (focusLockSatisfied(o, o.focusTarget, s)) {
      o.confirmedFocus = true; renderOptics(o); startFastExposure(o); return;
    }
    o.confirmedFocus = false; o.revision++; flushOptics(o);
  }
  // Registered unconditionally: a page that ships an older #opticsStatus (or
  // none) must still get the drift watchdog, not only the diagnostics.
  setInterval(focusWatchdog, FOCUS_WATCHDOG_MS);
  function opticsBrightness() {
    try {
      if (!meterCanvas) { meterCanvas = document.createElement('canvas'); meterCanvas.width = 16; meterCanvas.height = 12; }
      var context = meterCanvas.getContext('2d');
      context.drawImage(video, 0, 0, 16, 12);
      var pixels = context.getImageData(0, 0, 16, 12).data, sum = 0;
      for (var i = 0; i < pixels.length; i += 4) sum += (pixels[i] + pixels[i + 1] + pixels[i + 2]) / 3;
      return sum / (pixels.length / 4);
    } catch (e) { return null; }
  }
  if (optTorch) {
    optTorch.addEventListener('click', function () {
      var o = optics; if (!o || !currentOptics(o) || !o.torchAvailable || o.pending) return;
      o.torchWanted = !o.torchWanted;
      // Lighting changed: let AE settle, then measure the shutter again.
      o.exposureTarget = null; o.exposureAttempted = false; o.confirmedExposure = false;
      o.revision++; flushOptics(o);
    });
  }
  /* Camera lifecycle. A getUserMedia request cannot be cancelled by JS.
   * Keep its slot until it settles, even after our UI timeout. A retry while
   * the browser is still stuck offers a page reload, never an overlapping open.
   * Every callback/list/track/frame is tied to a generation. */
  var camState = 'idle', cameraGeneration = 0, currentStream = null, pendingOpen = null;
  var desiredId = null, camList = [], readyTimer = null, recoveryTimer = null, objectURL = null, readyCheck = null;
  video.addEventListener('loadeddata', function () { if (readyCheck) readyCheck(); });
  video.addEventListener('playing', function () { if (readyCheck) readyCheck(); });
  var recoveryAttempts = 0, stableTicks = 0, deadTicks = 0, lastVideoTime = -1, wasSuspended = false;
  try { desiredId = localStorage.getItem('mtag_scanner_cam') || null; } catch (e) {}
  function stopTracks(stream) {
    if (!stream) return;
    stream.getTracks().forEach(function (track) { track.onended = null; try { track.stop(); } catch (e) {} });
  }
  function stopStream() {
    stopOptics();
    stopTracks(currentStream); currentStream = null;
    try { video.pause(); } catch (e) {}
    try { if ('srcObject' in video) video.srcObject = null; } catch (e) {}
    try { if ('mozSrcObject' in video) video.mozSrcObject = null; video.removeAttribute('src'); } catch (e) {}
    if (objectURL) { try { (window.URL || window.webkitURL).revokeObjectURL(objectURL); } catch (e) {} objectURL = null; }
  }
  function clearRecovery() { clearTimeout(recoveryTimer); recoveryTimer = null; }
  function setControls() {
    camBtn.disabled = !!pendingOpen || camState === 'opening' || camState === 'warming';
    camBtn.style.display = camList.length > 1 || (camList.length === 1 && camList[0].deviceId !== desiredId) ? 'block' : 'none';
    camRetry.style.display = camState === 'error' || (optics && (optics.stalled || optics.state === 'unconfirmed')) ? 'block' : 'none';
    camRetry.textContent = pendingOpen ? 'بازکردن دوباره صفحه' : 'تلاش مجدد همین دوربین';
    for (var i = 0; i < camList.length; i++) {
      if (camList[i].deviceId === desiredId) {
        camLabel.style.display = 'block'; camLabel.textContent = camList[i].label || ('دوربین ' + faDigits(i + 1)); return;
      }
    }
  }
  function listCams(generation) {
    function apply(devices) {
      if (generation !== cameraGeneration) return;
      camList = devices.filter(function (d) { return d.kind === 'videoinput'; }); setControls();
    }
    try {
      if (navigator.mediaDevices && navigator.mediaDevices.enumerateDevices) observe(navigator.mediaDevices.enumerateDevices(), apply, noop);
      else if (window.MediaStreamTrack && window.MediaStreamTrack.getSources) {
        window.MediaStreamTrack.getSources(function (sources) {
          apply(sources.filter(function (s) { return s.kind === 'video'; }).map(function (s) { return { kind: 'videoinput', deviceId: s.id, label: s.label }; }));
        });
      }
    } catch (e) {}
  }
  function cameraError(message) {
    cameraGeneration++; clearRecovery(); clearTimeout(readyTimer); readyTimer = null;
    readyCheck = null; stopScanLoop(); stopStream(); camState = 'error';
    camMsg.textContent = message + ' — دوربین انتخابی خودکار عوض نمی‌شود.'; setControls();
  }
  function openTimeout(req) {
    if (pendingOpen !== req) return;
    req.expired = true;
    cameraError('پاسخ دوربین طولانی شد؛ اجازهٔ دوربین را بررسی کنید یا صفحه را دوباره باز کنید');
  }
  function armOpenTimeout(req) {
    clearTimeout(req.timer);
    req.timer = setTimeout(function () { openTimeout(req); }, 15000);
  }
  function trackLive() {
    if (!currentStream) return false;
    var tracks = currentStream.getVideoTracks();
    return !!tracks.length && tracks[0].readyState !== 'ended' && !tracks[0].muted;
  }
  function openCamera(attempt, basic) {
    if (document.hidden) return;
    if (pendingOpen) { if (!pendingOpen.timer) armOpenTimeout(pendingOpen); setControls(); return; }
    clearRecovery(); clearTimeout(readyTimer); readyCheck = null; stopScanLoop(); stopStream();
    prepareDecoder();
    var generation = ++cameraGeneration;
    camState = 'opening'; stableTicks = 0; lastVideoTime = -1;
    camMsg.textContent = 'در حال راه‌اندازی دوربین انتخابی...';
    var req = { generation: generation, id: desiredId, expired: false, timer: null };
    pendingOpen = req; setControls(); armOpenTimeout(req);
    function settled() {
      clearTimeout(req.timer);
      if (pendingOpen === req) pendingOpen = null;
    }
    function stale(stream) {
      stopTracks(stream); setControls();
      if (!document.hidden && camState === 'suspended') {
        clearRecovery(); recoveryTimer = setTimeout(function () { recoveryTimer = null; openCamera(0, false); }, 300);
      }
    }
    function failed(error) {
      settled();
      if (generation !== cameraGeneration || req.expired || document.hidden) { stale(null); return; }
      var name = error && error.name || 'CameraError';
      // Release/busy and optional quality failures retry ONCE, SAME device ID.
      var quality = name === 'OverconstrainedError' && error.constraint !== 'deviceId';
      if (!attempt && (name === 'NotReadableError' || name === 'TrackStartError' || quality)) {
        recoveryTimer = setTimeout(function () { recoveryTimer = null; openCamera(1, quality); }, 400); return;
      }
      var message = 'بازکردن دوربین ناموفق بود (' + name + ')';
      if (name === 'NotAllowedError' || name === 'PermissionDeniedError') message = 'اجازهٔ دوربین داده نشده؛ مجوز مرورگر و اتصال امن را بررسی کنید';
      if (name === 'NotFoundError' || name === 'OverconstrainedError') message = 'دوربین انتخابی در دسترس نیست؛ دوباره تلاش کنید یا دوربین دیگری انتخاب کنید';
      if (name === 'CameraAPIUnavailable') message = 'دوربین در این مرورگر یا آدرس قابل دسترسی نیست؛ HTTPS یا اسکنر قبلی را امتحان کنید';
      cameraError(message); listCams(cameraGeneration);
    }
    function opened(stream) {
      settled();
      if (generation !== cameraGeneration || req.expired || document.hidden) { stale(stream); return; }
      currentStream = stream; camState = 'warming'; setControls();
      var tracks = stream.getVideoTracks(), track = tracks[0], settings = {};
      try { settings = track && track.getSettings ? track.getSettings() : {}; } catch (e) {}
      if (req.id && settings.deviceId && settings.deviceId !== req.id) {
        cameraError('مرورگر دوربین دیگری برگرداند؛ انتخاب شما حفظ شد'); return;
      }
      var playing = false, deadline = now() + 15000;
      function checkReady() {
        if (generation !== cameraGeneration || currentStream !== stream || document.hidden || camState !== 'warming') return;
        clearTimeout(readyTimer); readyTimer = null;
        if (playing && video.readyState >= 2 && video.videoWidth && video.videoHeight && !video.paused && trackLive()) {
          camState = 'ready'; readyCheck = null;
          if (!desiredId && settings.deviceId) desiredId = settings.deviceId;
          if (desiredId) { try { localStorage.setItem('mtag_scanner_cam', desiredId); } catch (e) {} }
          camMsg.textContent = 'تگ را وسط تصویر، در فاصلهٔ حدود ۵ تا ۲۰ سانتی‌متر بگیرید';
          setControls(); listCams(generation); configureOptics(track, generation); startScanLoop(); return;
        }
        if (now() >= deadline) { cameraError('تصویر دوربین آماده نشد؛ تلاش مجدد را بزنید'); return; }
        readyTimer = setTimeout(checkReady, 25);
      }
      readyCheck = checkReady;
      try {
        video.muted = true;
        if ('srcObject' in video) video.srcObject = stream;
        else if ('mozSrcObject' in video) video.mozSrcObject = stream;
        else { objectURL = (window.URL || window.webkitURL).createObjectURL(stream); video.src = objectURL; }
        tracks.forEach(function (t) {
          t.onended = function () { if (generation === cameraGeneration && currentStream === stream) scheduleRecovery(); };
        });
        observe(video.play(), function () { playing = true; checkReady(); }, function () {
          if (generation === cameraGeneration) cameraError('پخش تصویر شروع نشد؛ دکمهٔ تلاش مجدد را لمس کنید');
        });
        checkReady();
      } catch (e) { if (generation === cameraGeneration) cameraError('نمایش تصویر در این مرورگر ممکن نشد؛ اسکنر قبلی را امتحان کنید'); }
    }
    try {
      var media = navigator.mediaDevices;
      var videoConstraints = req.id ? { deviceId: { exact: req.id } } : { facingMode: 'environment' };
      if (!basic) {
        // 720p is the sweet spot here: the sensor can actually deliver a high
        // frame rate at this size, and a high frame rate is what makes a tag
        // that is moving land in the very next frame.
        videoConstraints.width = { ideal: LOW_END ? 960 : 1280 };
        videoConstraints.height = { ideal: LOW_END ? 540 : 720 };
        videoConstraints.frameRate = { ideal: 60, max: 60 };
      }
      if (media && typeof media.getUserMedia === 'function') observe(media.getUserMedia({ video: videoConstraints, audio: false }), opened, failed);
      else {
        var legacy = navigator.getUserMedia || navigator.webkitGetUserMedia || navigator.mozGetUserMedia;
        if (!legacy) { failed({ name: 'CameraAPIUnavailable' }); return; }
        // Callback-only camera API; no global Promise polyfill required.
        var oldConstraints = req.id ? { optional: [{ sourceId: req.id }] } : true;
        legacy.call(navigator, { video: oldConstraints, audio: false }, opened, failed);
      }
    } catch (e) { failed(e); }
  }
  camBtn.addEventListener('click', function () {
    if (camBtn.disabled || camList.length < 1) return;
    var index = -1;
    for (var i = 0; i < camList.length; i++) if (camList[i].deviceId === desiredId) index = i;
    desiredId = camList[(index + 1) % camList.length].deviceId;
    recoveryAttempts = 0; setControls(); openCamera(0, false);
  });
  camRetry.addEventListener('click', function () {
    if (pendingOpen) { window.location.reload(); return; }
    recoveryAttempts = 0; openCamera(0, false);
  });
  function scheduleRecovery() {
    if (document.hidden || pendingOpen || recoveryTimer || camState !== 'ready') return;
    if (recoveryAttempts >= 3) { cameraError('دوربین چند بار قطع شد؛ مجوز و اتصال آن را بررسی و تلاش مجدد کنید'); return; }
    camMsg.textContent = 'بازیابی همان دوربین انتخابی...';
    recoveryTimer = setTimeout(function () {
      recoveryTimer = null; recoveryAttempts++; openCamera(0, false);
    }, 700);
  }
  function suspend() {
    cameraGeneration++; clearRecovery(); clearTimeout(readyTimer); readyTimer = null;
    if (pendingOpen) { clearTimeout(pendingOpen.timer); pendingOpen.timer = null; }
    readyCheck = null; stopScanLoop(); stopStream(); camState = 'suspended';
    wasSuspended = true;
    setControls();
  }
  function resume() {
    if (document.hidden) return;
    clock();
    /* Coming back to a page that failed (error, suspended, stalled) must end in
     * a working scanner without the operator touching anything. A plain window
     * focus must not re-ask for a camera that was never granted, so the automatic
     * retry only happens after the page was really away (hidden/minimised). */
    var returning = wasSuspended; wasSuspended = false;
    if (camState === 'error') { if (returning) { recoveryAttempts = 0; openCamera(0, false); } return; }
    if (camState === 'opening' || camState === 'warming') return;
    if (camState === 'suspended' || camState === 'idle') { openCamera(0, false); return; }
    if (!trackLive()) { scheduleRecovery(); return; }
    if (video.paused) {
      try { observe(video.play(), function () { startScanLoop(); }, scheduleRecovery); } catch (e) { scheduleRecovery(); }
    } else startScanLoop();
  }
  document.addEventListener('visibilitychange', function () { if (document.hidden) suspend(); else resume(); });
  window.addEventListener('pagehide', suspend);
  window.addEventListener('pageshow', resume);
  window.addEventListener('focus', resume);
  window.addEventListener('orientationchange', resume);
  window.addEventListener('online', function () {});
  try { if (navigator.mediaDevices && navigator.mediaDevices.addEventListener) navigator.mediaDevices.addEventListener('devicechange', function () { listCams(cameraGeneration); }); } catch (e) {}
  setInterval(stallCheck, STALL_CHECK_MS);
  setInterval(function () {
    if (document.hidden || camState !== 'ready' || pendingOpen || recoveryTimer || (optics && optics.pending && !optics.stalled)) return;
    if (!trackLive() || video.readyState < 2) {
      // Muted/ended track or a video that stopped delivering data: the stream
      // itself is dead, so recover the SAME selected camera.
      deadTicks++;
      if (deadTicks >= 2) { deadTicks = 0; scheduleRecovery(); }
      return;
    }
    deadTicks = 0;
    if (video.currentTime !== lastVideoTime) { stableTicks++; recoveryAttempts = 0; }
    lastVideoTime = video.currentTime;
  }, 4000);
  openCamera(0, false);
})();
