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
   * and attemptBoth inversion. Unknown hardware starts conservatively.
   * Adapt rest time, not away the pixels needed to read small/distant tags. */
  var mem = navigator.deviceMemory || 0, cores = navigator.hardwareConcurrency || 0;
  var LOW_END = (!mem && !cores) || (mem && mem <= 3) || (cores && cores <= 4);
  var FULL_DIM = LOW_END ? 560 : 900, BASE_REST = LOW_END ? 150 : 65;
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
  function jsDecode(image, callback) {
    if (!workerBroken && window.Worker) {
      try {
        if (!worker) {
          worker = new Worker(config.worker || 'assets/js/attendance-decoder-worker.js');
          var owner = worker;
          worker.onmessage = function (event) {
            if (worker !== owner) return;
            if (!workerDone || event.data.id !== workerDone.id) return;
            var job = workerDone; workerDone = null;
            if (event.data.error) { workerBroken = true; discardWorker(); job.callback(null); }
            else job.callback(event.data.data || null);
          };
          worker.onerror = function () {
            if (worker !== owner) return;
            var job = workerDone; workerBroken = true; discardWorker();
            if (job) job.callback(null);
          };
        }
        var id = ++decodeJob;
        workerDone = { id: id, callback: callback };
        worker.postMessage({ id: id, pixels: image.data.buffer, width: image.width, height: image.height }, [image.data.buffer]);
        return;
      } catch (e) {
        workerBroken = true; discardWorker();
        // A transferred/detached buffer must not be decoded on the main thread.
        callback(null); return;
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
      if (generation === cameraGeneration && camState === 'ready' && !document.hidden && data) sendScan(data, false);
      scheduleScan(Math.max(BASE_REST, Math.min(2000, Math.round((now() - started) * 1.5))));
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
  function startScanLoop() { scanning = true; scheduleScan(BASE_REST); }
  function stopScanLoop() {
    scanning = false; scanEpoch++; decodeBusy = false;
    if (activeDecodeCancel) { activeDecodeCancel(); activeDecodeCancel = null; }
    clearTimeout(scanTimer); scanTimer = null;
    if (frameCallback !== null) { try { video.cancelVideoFrameCallback(frameCallback); } catch (e) {} frameCallback = null; }
    // Do not retain work/frame buffers from the old camera or a hidden page.
    discardWorker(); lastFrameTime = -1; passCounter = 0;
  }

  /* Camera lifecycle. A getUserMedia request cannot be cancelled by JS.
   * Keep its slot until it settles, even after our UI timeout. A retry while
   * the browser is still stuck offers a page reload, never an overlapping open.
   * Every callback/list/track/frame is tied to a generation. */
  var camState = 'idle', cameraGeneration = 0, currentStream = null, pendingOpen = null;
  var desiredId = null, camList = [], readyTimer = null, recoveryTimer = null, objectURL = null;
  var recoveryAttempts = 0, stableTicks = 0, frozenCount = 0, lastVideoTime = -1;
  try { desiredId = localStorage.getItem('mtag_scanner_cam') || null; } catch (e) {}
  function stopTracks(stream) {
    if (!stream) return;
    stream.getTracks().forEach(function (track) { track.onended = null; try { track.stop(); } catch (e) {} });
  }
  function stopStream() {
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
    camRetry.style.display = camState === 'error' ? 'block' : 'none';
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
    stopScanLoop(); stopStream(); camState = 'error';
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
    clearRecovery(); clearTimeout(readyTimer); stopScanLoop(); stopStream();
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
        if (generation !== cameraGeneration || currentStream !== stream || document.hidden) return;
        if (playing && video.readyState >= 2 && video.videoWidth && video.videoHeight && !video.paused && trackLive()) {
          camState = 'ready'; readyTimer = null;
          if (!desiredId && settings.deviceId) desiredId = settings.deviceId;
          if (desiredId) { try { localStorage.setItem('mtag_scanner_cam', desiredId); } catch (e) {} }
          camMsg.textContent = 'تگ را هر جای تصویر بگیرید — لازم نیست داخل کادر باشد';
          setControls(); listCams(generation); startScanLoop(); return;
        }
        if (now() >= deadline) { cameraError('تصویر دوربین آماده نشد؛ تلاش مجدد را بزنید'); return; }
        readyTimer = setTimeout(checkReady, 120);
      }
      try {
        video.muted = true;
        if ('srcObject' in video) video.srcObject = stream;
        else if ('mozSrcObject' in video) video.mozSrcObject = stream;
        else { objectURL = (window.URL || window.webkitURL).createObjectURL(stream); video.src = objectURL; }
        tracks.forEach(function (t) {
          t.onended = function () { if (generation === cameraGeneration && currentStream === stream) scheduleRecovery(); };
        });
        try {
          var caps = track && track.getCapabilities ? track.getCapabilities() : {};
          if (caps.focusMode && caps.focusMode.indexOf('continuous') >= 0 && track.applyConstraints) observe(track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }), noop, noop);
        } catch (e) {}
        observe(video.play(), function () { playing = true; }, function () {
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
        videoConstraints.frameRate = { ideal: LOW_END ? 15 : 20, max: 30 };
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
    stopScanLoop(); stopStream(); camState = 'suspended';
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
    if (document.hidden || camState !== 'ready' || pendingOpen || recoveryTimer) return;
    if (!trackLive() || video.readyState < 2 || video.currentTime === lastVideoTime) { frozenCount++; stableTicks = 0; }
    else { frozenCount = 0; if (++stableTicks >= 3) recoveryAttempts = 0; }
    lastVideoTime = video.currentTime;
    if (frozenCount >= 2) { frozenCount = 0; scheduleRecovery(); }
  }, 4000);
  setInterval(function () { if (!document.hidden) refreshStatus(); }, 30000);
  openCamera(0, false); refreshStatus();
})();
