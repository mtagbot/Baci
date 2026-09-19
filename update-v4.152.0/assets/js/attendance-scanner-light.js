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
    document.removeEventListener('touchstart', unlockAudio);
    document.removeEventListener('click', unlockAudio);
  }
  document.addEventListener('touchstart', unlockAudio, false);
  document.addEventListener('click', unlockAudio, false);

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
    busy = true;
    // Immediate, neutral acknowledgement is NOT attendance confirmation.
    resBox.className = 'result'; resIcon.textContent = '⏳';
    resName.textContent = 'کد خوانده شد'; resStat.textContent = 'در حال ثبت حضور…';
    resSub.textContent = 'منتظر تأیید سرور باشید';
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
        if (j.ok && j.code === 'present') showResult('ok', '✅', j.student, 'حضور ثبت شد — ' + j.time, j['class'] + ' — ' + j.message);
        else if (j.ok && j.code === 'late') showResult('warn', '⏰', j.student, j.status + ' — ' + j.time, j['class'] + ' — ' + j.message);
        else if (j.code === 'duplicate') showResult('warn', '🔁', j.student || 'تکراری', 'قبلاً ثبت شده: ' + (j.status || ''), j.message);
        else { forget(payload); sendAfter = now() + 1500; showResult('err', '❌', 'ناموفق', j.message || 'کد نامعتبر', ''); }
        refreshStatus(true);
      });
    }
    send(0); return true;
  }
  window.manualSubmit = function () {
    var input = el('manualInp'), value = input.value.replace(/^\s+|\s+$/g, '');
    if (value && sendScan(value, true)) input.value = '';
    // Busy input is intentionally retained, not silently discarded.
  };
  el('manualInp').addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.keyCode === 13) window.manualSubmit(); });

  /* Coalesce status updates; a scan occurring during a status request gets
   * exactly one follow-up. A stale poll cannot overwrite that follow-up. */
  var statusBusy = false, statusDirty = false, statusTimer = null, statusCancel = null;
  var lastStatusAt = -10000, statusSnapshot = '';
  function refreshStatus(force) {
    if (force === true) statusDirty = true;
    if (document.hidden || statusBusy || statusTimer) return;
    var delay = Math.max(0, 1500 - (now() - lastStatusAt));
    statusTimer = setTimeout(function () {
      statusTimer = null;
      if (document.hidden) return;
      statusBusy = true; statusDirty = false; lastStatusAt = now();
      statusCancel = request('GET', API + '?action=status&key=' + encodeURIComponent(KEY) + '&_=' + now(), null, 10000, function (error, j) {
        statusBusy = false; statusCancel = null;
        if (!error && j.ok && !document.hidden) renderStatus(j);
        if (statusDirty && !document.hidden) refreshStatus();
      });
    }, delay);
  }
  function renderStatus(j) {
    var snapshot = JSON.stringify([j.present, j.late, j.absent, j.recent || []]);
    if (snapshot === statusSnapshot) return;
    statusSnapshot = snapshot;
    el('stP').textContent = faDigits(j.present); el('stL').textContent = faDigits(j.late); el('stA').textContent = faDigits(j.absent);
    var list = el('recentList'); list.innerHTML = '';
    if (!j.recent || !j.recent.length) { list.textContent = 'هنوز ترددی ثبت نشده است.'; return; }
    j.recent.forEach(function (r) {
      var row = document.createElement('div'); row.className = 'row';
      var who = document.createElement('div'); who.className = 'who';
      var b = document.createElement('b'); b.textContent = r.name;
      var s = document.createElement('span'); s.textContent = r['class'] + ' — ' + r.time;
      who.appendChild(b); who.appendChild(s);
      var tag = document.createElement('span'); tag.className = 'tagstat ' + (r.late ? 'l' : 'p'); tag.textContent = r.status;
      row.appendChild(who); row.appendChild(tag); list.appendChild(row);
    });
  }

  /* Preserve the original full / center / full / overlapping corner pyramid
   * and attemptBoth inversion. Speed-first cadence; no lower-resolution guess
   * solely because an older browser hides its hardware information.
   * Adapt rest time, not away the pixels needed to read small/distant tags. */
  var mem = navigator.deviceMemory || 0, cores = navigator.hardwareConcurrency || 0;
  var LOW_END = (mem && mem <= 3) || (cores && cores <= 4);
  var FULL_DIM = LOW_END ? 560 : 900, BASE_REST = LOW_END ? 25 : 12;
  var scanTimer = null, frameCallback = null, scanning = false, decodeBusy = false;
  var scanEpoch = 0, passCounter = 0, lastFrameTime = -1, decodeJob = 0;
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
  // Detection is optional and never blocks boot or the jsQR fallback.
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
  function jsDecode(image, callback) {
    prepareDecoder();
    if (worker) {
      try {
        var id = ++decodeJob;
        workerDone = { id: id, callback: callback };
        worker.postMessage({ id: id, pixels: image.data.buffer, width: image.width, height: image.height }, [image.data.buffer]);
        return;
      } catch (e) {
        workerBroken = true; discardWorker();
        // A transferred/detached buffer must not be decoded on the main thread.
        callback(null); prepareDecoder(); return;
      }
    }
    var code = null;
    try { code = window.jsQR(image.data, image.width, image.height, { inversionAttempts: 'attemptBoth' }); } catch (e) {}
    callback(code && code.data);
  }

  function frameImage(p) {
    var w = video.videoWidth, h = video.videoHeight, sx = 0, sy = 0, sw = w, sh = h;
    if (p % 4 === 1) {
      sw = Math.round(w * 0.55); sh = Math.round(h * 0.55);
      sx = Math.round((w - sw) / 2); sy = Math.round((h - sh) / 2);
    } else if (p % 4 === 3) {
      sw = Math.round(w * 0.62); sh = Math.round(h * 0.62);
      var quadrant = (p >> 2) % 4;
      sx = (quadrant % 2) ? w - sw : 0; sy = quadrant > 1 ? h - sh : 0;
    }
    var ratio = sw / sh, cw = FULL_DIM, ch = Math.round(FULL_DIM / ratio);
    if (ch > FULL_DIM) { ch = FULL_DIM; cw = Math.round(FULL_DIM * ratio); }
    if (canvas.width !== cw) canvas.width = cw;
    if (canvas.height !== ch) canvas.height = ch;
    ctx.imageSmoothingEnabled = sw > cw;
    ctx.drawImage(video, sx, sy, sw, sh, 0, 0, cw, ch);
    return { width: cw, height: ch };
  }
  function scheduleScan(rest) {
    if (!scanning || scanTimer || frameCallback !== null || decodeBusy || document.hidden) return;
    scanTimer = setTimeout(function () {
      scanTimer = null;
      if (!scanning || document.hidden) return;
      // A fresh frame may already have arrived during decode/rest. Use it now
      // instead of unconditionally waiting for yet ANOTHER camera frame.
      if (video.readyState >= 2 && video.currentTime !== lastFrameTime) { scanTick(); return; }
      if (typeof video.requestVideoFrameCallback === 'function' && typeof video.cancelVideoFrameCallback === 'function') {
        frameCallback = video.requestVideoFrameCallback(function () { frameCallback = null; scanTick(); });
      } else scanTick();
    }, rest);
  }
  function scanTick() {
    if (!scanning || document.hidden || camState !== 'ready') return;
    if (!ctx) { scanning = false; camMsg.textContent = 'پردازش تصویر در این مرورگر ممکن نیست؛ ورود دستی یا اسکنر قبلی را امتحان کنید.'; return; }
    if (busy || decodeBusy || video.readyState < 2 || !video.videoWidth || !video.videoHeight || video.currentTime === lastFrameTime) {
      scheduleScan(BASE_REST); return;
    }
    if ((workerBroken || !window.Worker) && typeof window.jsQR !== 'function') {
      if (now() < decoderRetryAt) { scheduleScan(1000); return; }
      var loadingEpoch = scanEpoch;
      decodeBusy = true;
      loadDecoder(function (ok) {
        if (loadingEpoch !== scanEpoch) return;
        decodeBusy = false;
        if (!ok) camMsg.textContent = 'بارگذاری تشخیص QR ناموفق بود؛ ورود دستی یا اسکنر قبلی را امتحان کنید.';
        scheduleScan(ok ? BASE_REST : 1000);
      });
      return;
    }
    lastFrameTime = video.currentTime;
    var epoch = scanEpoch, generation = cameraGeneration, started = now(), finished = false;
    var capturedOptics = opticsFrameSettings();
    var useNative = nativeDetector && !nativeBroken && passCounter % 4 === 0;
    decodeBusy = true;
    var timer = setTimeout(function () {
      if (useNative) { nativeBroken = true; nativeDetector = null; }
      else { workerBroken = true; discardWorker(); }
      complete(null);
    }, 8000);
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
      if (generation === cameraGeneration && camState === 'ready' && !document.hidden && data) { opticsDecoded(data, capturedOptics); sendScan(data, false); }
      scheduleScan(Math.max(BASE_REST, Math.min(2000, Math.round((now() - started) * 0.35))));
    }
    try {
      var size = frameImage(passCounter++);
      if (useNative) {
        observe(nativeDetector.detect(canvas), function (codes) {
          complete(codes && codes.length ? codes[0].rawValue : null);
        }, function () { nativeBroken = true; nativeDetector = null; complete(null); });
      } else jsDecode(ctx.getImageData(0, 0, size.width, size.height), complete);
    } catch (e) { complete(null); }
  }
  function startScanLoop() { scanning = true; scheduleScan(0); }
  function stopScanLoop() {
    scanning = false; scanEpoch++; decodeBusy = false;
    if (activeDecodeCancel) { activeDecodeCancel(); activeDecodeCancel = null; }
    clearTimeout(scanTimer); scanTimer = null;
    if (frameCallback !== null) { try { video.cancelVideoFrameCallback(frameCallback); } catch (e) {} frameCallback = null; }
    // Do not retain work/frame buffers from the old camera or a hidden page.
    discardWorker(); lastFrameTime = -1; passCounter = 0;
  }

  /* Focus is pinned to INFINITY for the whole session: it is applied the moment
   * the camera becomes ready — before any tag — and it is never derived from a
   * scan. The lens therefore cannot hunt between students: moving the tag
   * closer or farther no longer triggers a new autofocus sweep (that sweep was
   * what blurred the tag right after the first read). applyConstraints is only
   * re-issued when the device itself drops the lock (watchdog / camera
   * reopen), never per scan. Exposure shortening stays a separate step. */
  var opticsEnabled = true, optics = null, meterCanvas = null, optMode = el('opticsMode'), optFocus = el('opticsFocus'), optTorch = el('opticsTorch');
  /* focusDistance is reported in meters, so the LARGEST supported value is the
   * farthest focus (Android infinity is 0 diopters, reported as an unbounded
   * range). When that range has no upper bound we request a finite distance
   * beyond any phone hyperfocal distance; the camera maps it to infinity. */
  var INFINITY_METERS = 1000, FOCUS_WATCHDOG_MS = 5000;
  function finiteNumber(n) { return typeof n === 'number' && isFinite(n); }
  function cameraSettings(track) { try { return track.getSettings ? track.getSettings() : {}; } catch (e) { return {}; } }
  function hasMode(caps, key, value) { return caps[key] && caps[key].indexOf(value) >= 0; }
  function validRange(r) { return r && finiteNumber(r.min) && finiteNumber(r.max) && r.max > r.min; }
  function inRange(n, r) { return finiteNumber(n) && validRange(r) && n >= r.min && n <= r.max; }
  function roundUpRange(n, r) {
    var step = finiteNumber(r.step) && r.step > 0 ? r.step : 0;
    n = Math.max(r.min, Math.min(r.max, n));
    return Math.min(r.max, step ? r.min + Math.ceil((n - r.min) / step - 0.000001) * step : n);
  }
  function closeSetting(actual, expected, range) {
    // Requests for exposure/ISO are already rounded to the device grid. A
    // whole-step tolerance could wrongly accept a very different near focus.
    var tolerance = Math.max(0.000001, Math.abs(expected) * 0.015);
    return finiteNumber(actual) && Math.abs(actual - expected) <= tolerance;
  }
  /* Fixed infinity position for this camera, or null when the device offers no
   * way to take focus away from continuous autofocus. Layers, in order:
   * manual + farthest reported distance → manual without distance control
   * (freezes the current position) → single-shot (one sweep, then hold). */
  function infinityFocusTarget(o) {
    var caps = o.caps, range = caps.focusDistance;
    if (hasMode(caps, 'focusMode', 'manual')) {
      if (validRange(range)) return { focusMode: 'manual', focusDistance: range.max };
      if (range && finiteNumber(range.min) && !finiteNumber(range.max)) return { focusMode: 'manual', focusDistance: Math.max(INFINITY_METERS, range.min) };
      return { focusMode: 'manual' };
    }
    if (hasMode(caps, 'focusMode', 'single-shot')) return { focusMode: 'single-shot' };
    return null;
  }
  /* The lock counts only when the device reported the requested mode AND a
   * position at least as far as requested. A non-finite readback IS the device
   * reporting infinity; a nearer readback is never claimed as infinity. */
  function focusLockSatisfied(o, expected, s) {
    if (!expected || s.focusMode !== expected.focusMode) return false;
    if (expected.focusMode !== 'manual' || expected.focusDistance === undefined) return true;
    var d = s.focusDistance;
    if (!finiteNumber(d)) return true;
    return d >= expected.focusDistance * 0.9;
  }
  function focusReading(o) {
    var s = cameraSettings(o.track), d = s.focusDistance;
    if (!o.focusTarget) return 'خودکار (قفل نشده)';
    if (o.focusTarget.focusMode === 'single-shot') return s.focusMode === 'single-shot' ? 'تک‌مرحله‌ای (قفل‌شده)' : 'خودکار (قفل نشده)';
    if (s.focusMode !== 'manual') return 'خودکار (قفل نشده)';
    if (!o.confirmedFocus) return 'در حال بررسی';
    if (o.focusTarget.focusDistance === undefined) return 'قفل‌شده روی فاصلهٔ فعلی';
    if (!finiteNumber(d) || d >= 100) return 'بی‌نهایت (قفل‌شده)';
    if (d >= 2) return 'دور (قفل‌شده) — ' + faDigits(d.toFixed(1)) + ' متر';
    return 'قفل‌شده در ' + faDigits(d.toFixed(2)) + ' متر';
  }
  function currentOptics(o) { return optics === o && o.generation === cameraGeneration && currentStream && currentStream.getVideoTracks()[0] === o.track && !document.hidden; }
  function stopOptics() {
    if (optics) { clearTimeout(optics.timer); clearTimeout(optics.verifyTimer); clearTimeout(optics.lightTimer); }
    optics = null;
    if (optMode) { optMode.disabled = true; optFocus.disabled = true; optTorch.disabled = true; }
  }
  function renderOptics(o) {
    if (!currentOptics(o) || !optMode) return;
    var s = cameraSettings(o.track);
    var text = 'گزارش دوربین: فوکوس: ' + focusReading(o) + ' | تصویر: ' + (finiteNumber(s.frameRate) ? faDigits(Math.round(s.frameRate)) + ' فریم' : 'نرخ گزارش نشده');
    if (o.confirmedExposure && finiteNumber(s.exposureTime)) text += ' | نوردهی کوتاه‌تر';
    else text += ' | نوردهی: ' + (s.exposureMode === 'continuous' ? 'خودکار' : 'کنترل سریع تأیید نشده');
    el('opticsStatus').textContent = o.message;
    el('opticsDetails').textContent = text;
    optMode.checked = o.enabled;
    optMode.disabled = !o.supported || (!!o.pending && !o.stalled);
    optFocus.disabled = !o.enabled || !o.focusTarget || !!o.pending;
    optTorch.disabled = !o.torchAvailable || !!o.pending;
    optTorch.textContent = s.torch === true ? 'خاموش‌کردن چراغ' : 'روشن‌کردن چراغ';
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
    var base = JSON.parse(JSON.stringify(o.base)), fields = normalOptics(o), key;
    if (o.enabled) {
      if (o.focusTarget) for (key in o.focusTarget) if (Object.prototype.hasOwnProperty.call(o.focusTarget, key)) fields[key] = o.focusTarget[key];
      if (o.exposureTarget) for (key in o.exposureTarget) if (Object.prototype.hasOwnProperty.call(o.exposureTarget, key)) fields[key] = o.exposureTarget[key];
      if (o.caps.frameRate && o.caps.frameRate.max >= 60) base.frameRate = { ideal: 60, max: 60 };
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
  function restoreOptics(o, message) {
    o.enabled = false; o.focusTarget = null; o.exposureTarget = null;
    o.confirmedFocus = false; o.confirmedExposure = false;
    o.torchWanted = o.original.torch === true;
    o.restoreReason = message; o.message = message + '؛ بازگشت به حالت عادی درخواست شد.';
    o.revision++; renderOptics(o);
  }
  function flushOptics(o) {
    if (!currentOptics(o) || !o.supported || o.pending || o.appliedRevision === o.revision) return;
    var revision = o.revision, wasEnabled = o.enabled, expectedFocus = o.enabled && o.focusTarget, expectedExposure = o.enabled && o.exposureTarget;
    var expectedTorch = o.torchWanted, request = {}, constraints = opticConstraints(o), normal = normalOptics(o);
    o.pending = request; renderOptics(o);
    o.timer = setTimeout(function () {
      if (!currentOptics(o) || o.pending !== request) return;
      o.stalled = true; o.message = 'پاسخ تنظیم دوربین طولانی شد؛ می‌توانید همین دوربین را دوباره باز کنید';
      // applyConstraints cannot be cancelled: retain the slot until it settles.
      renderOptics(o); setControls();
    }, 2500);
    function complete(error) {
      if (!currentOptics(o) || o.pending !== request) return;
      clearTimeout(o.timer); o.pending = null; o.appliedRevision = revision;
      var s = cameraSettings(o.track), failed = !!error || o.stalled;
      if (revision === o.revision && !failed) {
        if (expectedFocus) {
          o.confirmedFocus = focusLockSatisfied(o, expectedFocus, s);
          if (!o.confirmedFocus) {
            failed = true;
            // Never claim a far lock the camera did not confirm.
            o.lockRejected = finiteNumber(s.focusDistance) ? 'فاصلهٔ گزارش‌شده نزدیک‌تر از درخواست بود' : 'حالت فوکوس تأیید نشد';
          }
        }
        if (expectedExposure) {
          o.confirmedExposure = s.exposureMode === 'manual' && closeSetting(s.exposureTime, expectedExposure.exposureTime, o.caps.exposureTime) && closeSetting(s.iso, expectedExposure.iso, o.caps.iso);
          if (!o.confirmedExposure) failed = true;
        }
        if (!wasEnabled) {
          if (normal.focusMode && s.focusMode !== normal.focusMode) failed = true;
          if (normal.focusMode === 'manual' && finiteNumber(normal.focusDistance) && !closeSetting(s.focusDistance, normal.focusDistance, o.caps.focusDistance)) failed = true;
          if (normal.exposureMode && s.exposureMode !== normal.exposureMode) failed = true;
          if (normal.exposureMode === 'manual' && (!closeSetting(s.exposureTime, normal.exposureTime, o.caps.exposureTime) || !closeSetting(s.iso, normal.iso, o.caps.iso))) failed = true;
        }
        if (o.torchAvailable && s.torch !== expectedTorch) failed = true;
      }
      o.stalled = false;
      if (failed && wasEnabled && revision === o.revision) restoreOptics(o, 'قفل فوکوس بین‌هایت توسط دوربین تأیید نشد' + (o.lockRejected ? ' (' + o.lockRejected + ')' : ''));
      else if (failed && revision === o.revision) { o.restoreFailed = true; o.message = 'بازگشت تنظیم دوربین تأیید نشد؛ برای بازنشانی، همین دوربین یا اسکنر قبلی را دوباره باز کنید'; }
      else if (revision === o.revision && o.enabled && o.confirmedFocus && !o.exposureWarning) o.message = 'فوکوس روی بین‌هایت قفل شد؛ با نزدیک یا دور شدن تگ، دوربین دیگر فوکوس نمی‌کند و اسکن ادامه دارد';
      else if (revision === o.revision && !o.enabled) { o.restoreFailed = false; o.message = (o.restoreReason ? o.restoreReason + '؛ ' : '') + 'حالت عادی؛ قفل فوکوس خودکار غیرفعال است و اسکن ادامه دارد'; }
      if (!failed && revision === o.revision && o.confirmedExposure && !o.lightChecked) {
        o.lightChecked = true;
        o.lightTimer = setTimeout(function () {
          if (!currentOptics(o) || !o.confirmedExposure || !o.enabled) return;
          var brightness = opticsBrightness();
          if (brightness !== null && ((brightness < 22 && o.referenceBrightness > 45 && brightness < o.referenceBrightness * 0.35) || (brightness > 250 && o.referenceBrightness !== null && o.referenceBrightness < 200))) {
            o.exposureTarget = null; o.confirmedExposure = false; o.exposureWarning = true;
            o.message = 'تصویر پس از تنظیم نوردهی تاریک یا بیش‌ازحد روشن شد؛ نوردهی خودکار برمی‌گردد. نور محیط و بازتاب چراغ را بررسی کنید';
            o.revision++; flushOptics(o);
          }
        }, 500);
      }
      renderOptics(o); setControls(); flushOptics(o);
    }
    try {
      observe(o.track.applyConstraints(constraints), function () {
        if (!currentOptics(o) || o.pending !== request) return;
        // Give settings a short opportunity to reflect the acknowledged request.
        o.verifyTimer = setTimeout(function () { complete(null); }, 100);
      }, complete);
    } catch (error) { complete(error); }
  }
  function configureOptics(track, generation) {
    stopOptics();
    if (!optMode) return;
    var caps = {}, base = {};
    try { caps = track.getCapabilities ? track.getCapabilities() : {}; } catch (e) {}
    try { base = track.getConstraints ? track.getConstraints() : {}; } catch (e) {}
    var o = { track: track, generation: generation, caps: caps, base: base, original: cameraSettings(track),
      enabled: opticsEnabled, focusTarget: null, exposureTarget: null, exposureAttempted: false,
      confirmedFocus: false, confirmedExposure: false, pending: null, revision: 1, appliedRevision: 0, stalled: false,
      lockAttempts: 0, lockRejected: '', message: 'تنظیم اولیه دوربین…' };
    if (!o.base.frameRate) o.base.frameRate = { ideal: 30, max: 30 };
    o.focusAvailable = hasMode(caps, 'focusMode', 'manual') || hasMode(caps, 'focusMode', 'single-shot');
    o.torchAvailable = caps.torch === true || (caps.torch && typeof caps.torch.indexOf === 'function' && caps.torch.indexOf(true) >= 0 && caps.torch.indexOf(false) >= 0);
    o.torchWanted = o.original.torch === true;
    o.focusTarget = o.enabled ? infinityFocusTarget(o) : null;
    o.supported = !!track.applyConstraints && !!(o.focusAvailable || o.torchAvailable || (caps.frameRate && caps.frameRate.max >= 60) || hasMode(caps, 'exposureMode', 'manual'));
    if (!opticsEnabled) { o.enabled = false; o.message = 'حالت قفل فوکوس انتخاب نشده است؛ فوکوس خودکار و نوردهی خودکار فعال‌اند'; }
    else if (!o.supported) { o.enabled = false; o.message = 'این مرورگر کنترل فوکوس/شاتر را ارائه نمی‌کند؛ اسکن عادی ادامه دارد.'; }
    else if (!o.focusTarget) { o.enabled = false; o.supported = false; o.message = 'قفل فوکوس در این مرورگر قابل کنترل نیست؛ دوربین دست‌نخورده می‌ماند و اسکن عادی ادامه دارد.'; }
    else if (o.focusTarget.focusMode === 'single-shot') o.message = 'قفل فوکوس تک‌مرحله‌ای پیش از اولین اسکن؛ پس از آن دوربین دوباره فوکوس نمی‌کند';
    else if (o.focusTarget.focusDistance === undefined) o.message = 'قفل فوکوس روی فاصلهٔ فعلی (این دوربین فاصله را گزارش نمی‌کند); پس از آن دوربین دوباره فوکوس نمی‌کند';
    else if (o.focusTarget.focusDistance >= 100) o.message = 'قفل فوکوس روی بین‌هایت پیش از اولین اسکن؛ پس از آن دوربین دوباره فوکوس نمی‌کند';
    else o.message = 'قفل فوکوس روی دورترین فاصلهٔ ممکن (بین‌هایت) پیش از اولین اسکن؛ پس از آن دوربین دوباره فوکوس نمی‌کند';
    optics = o; renderOptics(o); flushOptics(o);
  }
  /* The lock must survive the whole session on every phone. Devices can drop it
   * after a driver reset, an app switch or a rotation; this watchdog restores
   * the exact same fixed position. It never runs on the scan path. */
  function focusWatchdog() {
    var o = optics;
    if (!o || !currentOptics(o) || !o.enabled || !o.focusTarget || o.pending || document.hidden) return;
    var s = cameraSettings(o.track);
    if (focusLockSatisfied(o, o.focusTarget, s)) {
      // Keep the reported focus position truthful while the lock holds.
      o.confirmedFocus = true; renderOptics(o);
      return;
    }
    o.lockAttempts++;
    o.confirmedFocus = false;
    o.message = 'قفل فوکوس حفظ نشده بود؛ دوباره روی همان حالت قفل می‌شود';
    o.revision++; flushOptics(o);
  }
  if (optMode) setInterval(focusWatchdog, FOCUS_WATCHDOG_MS);
  function opticsFrameSettings() {
    var o = optics;
    return o && currentOptics(o) && o.enabled && !o.exposureAttempted ? cameraSettings(o.track) : null;
  }
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
  /* A decoded tag NEVER moves the lens again: only exposure is calibrated, and
   * only on the very first read of this camera session. */
  function opticsDecoded(data, captured) {
    var o = optics;
    if (!o || !currentOptics(o) || !o.enabled || !captured || o.exposureAttempted || !/^MTAG-ATT:\d+:[a-f0-9]{16,64}$/i.test(data)) return;
    o.exposureAttempted = true;
    var c = o.caps, time = captured.exposureTime, iso = captured.iso, changed = false;
    // At most 4x shorter with compensating gain. Do not underexpose blindly,
    // or push a previously clean image above ISO 1600 just to shorten shutter.
    if (hasMode(c, 'exposureMode', 'manual') && (hasMode(c, 'exposureMode', 'continuous') || o.original.exposureMode === 'manual') && time > 0 && iso > 0 && inRange(time, c.exposureTime) && inRange(iso, c.iso)) {
      var gainLimit = Math.min(c.iso.max, Math.max(iso, 1600)), factor = Math.min(4, gainLimit / iso);
      var targetTime = roundUpRange(time / factor, c.exposureTime);
      var targetISO = roundUpRange(iso * time / targetTime, c.iso);
      if (targetTime < time * 0.8 && targetISO <= gainLimit) {
        o.exposureTarget = { exposureMode: 'manual', exposureTime: targetTime, iso: targetISO };
        o.referenceBrightness = opticsBrightness(); o.lightChecked = false; changed = true;
      }
    }
    if (changed) { o.message = 'در حال درخواست نوردهی سریع‌تر؛ قفل فوکوس بین‌هایت دست‌نخورده است'; o.revision++; flushOptics(o); }
    else renderOptics(o);
  }
  if (optMode) {
    optMode.addEventListener('change', function () {
      var o = optics; if (!o || !currentOptics(o) || (o.pending && !o.stalled)) return;
      opticsEnabled = optMode.checked;
      if (o.stalled) { openCamera(0, false); return; }
      o.restoreReason = ''; o.restoreFailed = false; o.lockRejected = '';
      o.enabled = opticsEnabled; o.exposureWarning = false; o.focusTarget = o.enabled ? infinityFocusTarget(o) : null;
      o.exposureTarget = null; o.exposureAttempted = false; o.confirmedFocus = false; o.confirmedExposure = false;
      o.message = o.enabled ? 'در حال قفل‌کردن فوکوس روی بین‌هایت…' : 'در حال بازگرداندن حالت عادی';
      o.revision++; flushOptics(o);
    });
    optFocus.addEventListener('click', function () {
      var o = optics; if (!o || !currentOptics(o) || !o.enabled || o.pending) return;
      o.exposureWarning = false; o.lockRejected = ''; o.restoreFailed = false;
      o.exposureTarget = null; o.exposureAttempted = false; o.confirmedExposure = false;
      o.focusTarget = infinityFocusTarget(o);
      if (!o.focusTarget) { o.enabled = false; o.message = 'قفل فوکوس در این مرورگر قابل کنترل نیست؛ فوکوس خودکار می‌ماند'; renderOptics(o); return; }
      o.confirmedFocus = false; o.message = 'قفل دوبارهٔ فوکوس روی بین‌هایت درخواست شد';
      o.revision++; flushOptics(o);
    });
    optTorch.addEventListener('click', function () {
      var o = optics; if (!o || !currentOptics(o) || !o.torchAvailable || o.pending) return;
      o.torchWanted = !o.torchWanted; o.exposureWarning = false;
      // Lighting changed: let AE settle and recalibrate shutter after a read.
      o.exposureTarget = null; o.exposureAttempted = false; o.confirmedExposure = false;
      o.message = 'در حال تغییر چراغ؛ مراقب بازتاب نور روی تگ براق باشید'; o.revision++; flushOptics(o);
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
  var recoveryAttempts = 0, stableTicks = 0, frozenCount = 0, lastVideoTime = -1;
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
    camRetry.style.display = camState === 'error' || (optics && (optics.stalled || optics.restoreFailed)) ? 'block' : 'none';
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
    camState = 'opening'; frozenCount = 0; stableTicks = 0; lastVideoTime = -1;
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
      if (name === 'CameraAPIUnavailable') message = 'دوربین در این مرورگر یا آدرس قابل دسترسی نیست؛ HTTPS، اسکنر قبلی یا ورود دستی را امتحان کنید';
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
          camMsg.textContent = 'تگ را هر جای تصویر بگیرید — لازم نیست داخل کادر باشد';
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
      } catch (e) { if (generation === cameraGeneration) cameraError('نمایش تصویر در این مرورگر ممکن نشد؛ اسکنر قبلی یا ورود دستی را امتحان کنید'); }
    }
    try {
      var media = navigator.mediaDevices;
      var videoConstraints = req.id ? { deviceId: { exact: req.id } } : { facingMode: 'environment' };
      if (!basic) {
        videoConstraints.width = { ideal: LOW_END ? 1280 : 1920 };
        videoConstraints.height = { ideal: LOW_END ? 720 : 1080 };
        videoConstraints.frameRate = { ideal: 30, max: 30 };
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
    clearTimeout(statusTimer); statusTimer = null;
    if (statusCancel) statusCancel();
    setControls();
  }
  function resume() {
    if (document.hidden) return;
    clock(); refreshStatus();
    if (camState === 'opening' || camState === 'warming' || camState === 'error') return;
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
  window.addEventListener('online', function () { refreshStatus(true); });
  try { if (navigator.mediaDevices && navigator.mediaDevices.addEventListener) navigator.mediaDevices.addEventListener('devicechange', function () { listCams(cameraGeneration); }); } catch (e) {}
  setInterval(function () {
    if (document.hidden || camState !== 'ready' || pendingOpen || recoveryTimer || (optics && optics.pending && !optics.stalled)) return;
    if (!trackLive() || video.readyState < 2 || video.currentTime === lastVideoTime) { frozenCount++; stableTicks = 0; }
    else { frozenCount = 0; if (++stableTicks >= 3) recoveryAttempts = 0; }
    lastVideoTime = video.currentTime;
    if (frozenCount >= 2) { frozenCount = 0; scheduleRecovery(); }
  }, 4000);
  setInterval(function () { if (!document.hidden) refreshStatus(); }, 30000);
  openCamera(0, false); refreshStatus();
})();
