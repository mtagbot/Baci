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
    preloadSounds();                        /* same tap unlocks the voices */
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
    if (camState === 'warming') { if (gesturePlay) gesturePlay(); return; }
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
  function buzzOk(at){
    at = at || 0;
    tone(1318.5, at,      0.09, 'sine', 0.55);
    tone(1975.5, at+0.09, 0.22, 'sine', 0.55);
  }
  /* ⏰ WARN (late/duplicate): two mid beeps — attention, not failure */
  function buzzWarn(at){
    at = at || 0;
    tone(740, at,      0.14, 'square', 0.30);
    tone(740, at+0.22, 0.14, 'square', 0.30);
  }
  /* ❌ REJECT/ERROR: harsh low double BZZT — sawtooth + downward slide */
  function buzzErr(at){
    at = at || 0;
    tone(160, at,      0.28, 'sawtooth', 0.60, 110);
    tone(160, at+0.34, 0.34, 'sawtooth', 0.60, 90);
  }

  /* ── Recorded voice alerts ─────────────────────────────────────────────
   * Three recordings live in the install's upload area (uploads/sounds/).
   * The full installer keeps a read-only copy in assets/audio/, so an update
   * can still work on a clean install before the upload area is populated.
   *
   *   حضور به موقع  →  buzzer first, then hzr.ogg
   *   تأخیر         →  buzzer first, then tkhr.ogg
   *   خطای شبکه     →  net.ogg ON ITS OWN: the recording replaces the buzzer,
   *                    so a scan that never reached the server sounds clearly
   *                    different from a tag the server rejected.
   *
   * They travel through the same AudioContext as the buzzer (both are unlocked
   * by the same tap), are downloaded once and decoded once. If the browser
   * cannot decode Vorbis, a plain <audio> element is tried; if that fails too,
   * the scan is untouched and only the sound is lost — never a lost scan.
   */
  var SOUND_FILES = { net: 'net.ogg', present: 'hzr.ogg', late: 'tkhr.ogg' };
  var SOUND_GAP = 0.06;                      /* silence between buzzer and voice */
  var BUZZ_END = { ok: 0.31, warn: 0.36, err: 0.70 };
  var soundBase = 'uploads/sounds/', soundMap = null;
  (function () {
    var cfg = config.sounds;                 /* page or config override, optional */
    if (typeof cfg === 'string' && cfg) soundBase = cfg.charAt(cfg.length - 1) === '/' ? cfg : cfg + '/';
    else if (cfg && typeof cfg === 'object') soundMap = cfg;
  })();
  function soundUrl(name){
    if (soundMap && soundMap[name]) return String(soundMap[name]);
    return SOUND_FILES[name] ? soundBase + SOUND_FILES[name] : '';
  }
  var sounds = {};                           /* name → {loading|data|buffer|element|failed} */

  /* The corrective ZIP carries both locations. The upload location is the
   * primary one because it is the location an administrator can replace
   * without rebuilding the application; assets/audio is the safe fallback
   * used by a fresh/full install and by older deployments. Custom URL maps
   * remain authoritative and are never silently rewritten. */
  function alternateSoundUrl(name, url){
    if (soundMap && soundMap[name]) return '';
    if (!SOUND_FILES[name]) return '';
    var current = String(url || ''), base = '';
    if (current.indexOf('uploads/sounds/') !== -1) base = 'assets/audio/';
    else if (current.indexOf('assets/audio/') !== -1) base = 'uploads/sounds/';
    return base ? base + SOUND_FILES[name] : '';
  }

  function soundElement(name, url, st){
    st.loading = false;
    if (st.element || st.failed || !url || typeof Audio === 'undefined') { st.failed = !st.element; return; }
    try {
      var a = new Audio(url);
      if (a) {
        a.preload = 'auto';
        st.element = a;
        if (a.addEventListener) {
          a.addEventListener('error', function () {
            var altUrl = alternateSoundUrl(name, url);
            if (st.elemFallback || !altUrl) { st.failed = true; return; }
            st.elemFallback = true;
            try {
              var a2 = new Audio(altUrl); a2.preload = 'auto'; st.element = a2;
              if (a2.addEventListener) a2.addEventListener('error', function () { st.failed = true; }, false);
            } catch(e) { st.failed = true; }
          }, false);
        }
      } else st.failed = true;
    }
    catch (e) { st.failed = true; }
  }
  function decodeSound(name, st){
    if (!st || st.buffer || st.element || st.failed || !st.data || st.decoding) return;
    var c = audioCtx();
    if (!c || !c.decodeAudioData) { soundElement(name, st.url || soundUrl(name), st); return; }
    st.decoding = true;
    var ok = function (buf) {
      st.decoding = false; st.data = null; st.buffer = buf;
      /* A scan asked for this voice while the file was still being prepared. */
      if (st.queued && now() - st.queuedAt < 1500) { st.queued = false; startVoice(st, 0); }
    };
    var no = function () { st.decoding = false; st.data = null; soundElement(name, st.url || soundUrl(name), st); };
    try { c.decodeAudioData(st.data, ok, no); } catch (e) { no(); }
  }
  function requestSound(name, st, url){
    var retry = function () {
      var altUrl = alternateSoundUrl(name, url);
      if (!altUrl || st.triedFallback) return false;
      st.triedFallback = true;
      requestSound(name, st, altUrl);
      return true;
    };
    try {
      var xhr = new XMLHttpRequest();
      xhr.open('GET', url, true);
      xhr.responseType = 'arraybuffer';
      xhr.onload = function () {
        var body = xhr.response;
        if (!body || (xhr.status && (xhr.status < 200 || xhr.status >= 300))) {
          if (retry()) return;
          st.loading = false; st.failed = true; return;
        }
        st.loading = false; st.url = url; st.data = body; decodeSound(name, st);
      };
      xhr.onerror = function () { if (retry()) return; st.loading = false; st.failed = true; };
      xhr.send(null);
    } catch (e) { st.loading = false; st.failed = true; }
  }
  function loadSound(name){
    var st = sounds[name];
    if (st) { decodeSound(name, st); return st; }
    st = sounds[name] = { loading: true };
    var url = soundUrl(name);
    if (!url || typeof XMLHttpRequest === 'undefined') { st.loading = false; soundElement(name, url, st); return st; }
    requestSound(name, st, url);
    return st;
  }
  function preloadSounds(){
    for (var name in SOUND_FILES) if (SOUND_FILES.hasOwnProperty(name)) loadSound(name);
  }
  /* the voice itself, at `delay` seconds from now */
  function startVoice(st, delay){
    var d = delay || 0, c = audioCtx();
    if (c && st.buffer && c.createBufferSource) {
      try {
        var src = c.createBufferSource(), g = c.createGain();
        src.buffer = st.buffer;
        if (g && g.gain && g.gain.setValueAtTime) g.gain.setValueAtTime(1, c.currentTime + d);
        src.connect(g || c.destination); if (g) g.connect(c.destination);
        src.start(c.currentTime + d);
        return true;
      } catch (e) {}
    }
    if (st.element) {
      var play = function () { try { var p = st.element.play(); if (p && p.catch) p.catch(noop); } catch (e) {} };
      if (d > 0) setTimeout(play, Math.round(d * 1000)); else play();
      return true;
    }
    return false;
  }
  function playSound(name, delay){
    var st = loadSound(name);
    if (st.buffer || st.element) return startVoice(st, delay);
    if (st.loading || st.data) { st.queued = true; st.queuedAt = now(); decodeSound(name, st); }
    return false;
  }
  /* The operator hears the buzzer and the recording, in that order — except for
     a network failure, where the recording is the only sound (it replaces the
     buzzer). `voice` of '' or undefined keeps the plain buzzer of `kind`. */
  var RESULT_AUDIO = {
    present: { buzz: 'ok',   voice: 'present' },
    late:    { buzz: 'warn', voice: 'late' },
    net:     { buzz: '',     voice: 'net' }
  };
  function playAudio(kind, voice){
    var plan = voice ? RESULT_AUDIO[voice] : null;
    var buzzKind = plan ? plan.buzz : kind;
    var delay = 0;
    if (buzzKind === 'ok') { buzzOk(0); delay = BUZZ_END.ok + SOUND_GAP; }
    else if (buzzKind === 'warn') { buzzWarn(0); delay = BUZZ_END.warn + SOUND_GAP; }
    else if (buzzKind === 'err') { buzzErr(0); delay = BUZZ_END.err + SOUND_GAP; }
    if (plan) playSound(plan.voice, delay);
  }

  /* `voice`: 'present' | 'late' | 'net' adds the recorded alert — anything else
     (a duplicate, a rejected tag, a server error) keeps the plain buzzer. */
  function showResult(kind, icon, name, stat, sub, voice){
    resBox.className = 'result ' + kind;
    resIcon.textContent = icon; resName.textContent = name; resStat.textContent = stat; resSub.textContent = sub || '';
    playAudio(kind, voice);
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
          /* The response never came back: the scan did not reach the server.
             That is what net.ogg announces, in place of the buzzer. */
          if (error.server) showResult('err', '⚠️', 'خطای سرور', error.message, 'اسکن دوباره را امتحان کنید');
          else showResult('err', '📡', error.timeout ? 'پاسخ سرور دیر شد' : 'خطای شبکه', 'ثبت حضور تأیید نشد — دوباره اسکن کنید', 'ممکن است ثبت انجام شده باشد؛ اسکن مجدد «تکراری» نشان می‌دهد', 'net');
          return;
        }
        // Cooldown starts at confirmation, not before a possibly slow request.
        forget(payload); recentTags.push({ value: payload, time: now() });
        /* Only the name and whether the student made it on time — nothing else
           competes with the camera for attention. */
        if (j.ok && j.code === 'present') showResult('ok', '✅', j.student, 'ورود به موقع — ' + j.time, j['class'] || '', 'present');
        else if (j.ok && j.code === 'late') showResult('warn', '⏰', j.student, 'تأخیر — ' + j.time, j['class'] || '', 'late');
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
  /* ── Self-healing scan path ──────────────────────────────────────────
   * The scanner must read tags on the very first open of the page, on any
   * phone, with no manual step. The state that keeps breaking it is a live
   * picture with a ready-looking page where not one tag is decoded until the
   * camera is switched by hand. So the scanner now watches its own output -
   * decoded tags - and while none arrives it walks a ladder of repairs that
   * ends with exactly what the operator did by hand: switch to the other
   * camera and come back. Nothing here ever runs once this page session has
   * read a tag, every rung is spaced in time, and the ladder repeats with
   * backoff instead of giving up. */
  var STALL_CHECK_MS = 1000;
  var PIPELINE_GRACE_MS = 900;          // a fresh loop is never judged before this
  var CURE_FIRST_MS = 2500;             // first rung while the session has no read yet
  var CURE_STEP_MS = 2600;              // spacing between the rungs of one cycle
  var CURE_CYCLE_MS = 9000, CURE_CYCLE_CAP = 3;   // every repeated cycle is calmer
  var CURE_BROKEN_GRACE_MS = 900;       // a provably broken picture: act sooner
  var CURE_STEP_BROKEN_MS = 1500;
  var CURE_READ_BROKEN_GRACE_MS = 3000; // after a good read, a brief black frame is not a fault
  var CURE_STEP_READ_BROKEN_MS = 4000;
  var CURE_WARMING_GRACE_MS = 4000;     // a slow camera gets time to negotiate frames
  var MIN_CAMERA_GAP_MS = 2500;         // two camera opens are never closer than this
  var SWAP_HOLD_MS = 1600;              // how long the other camera stays live in the swap rung
  var PASS_STALL_MS = 4000;             // the loop must keep analysing frames
  var BLACK_LUMA = 2, DECODE_ERROR_LIMIT = 8, FROZEN_DEAD_MS = 2500;
  var scanTimer = null, scanning = false, decodeBusy = false;
  var scanEpoch = 0, passCounter = 0, lastFrameTime = -1, decodeJob = 0;
  var frameToken = 0, frameCallback = null, lastFrameToken = -1, lastDecodeAt = 0, lastTagAt = 0;
  var sigCanvas = null, lastSignature = '', lastSignatureAt = 0, scanPasses = 0, scanStartedAt = 0, lastPassAt = 0;
  var decodeErrors = 0, decodeStartedAt = 0, firstCodeAt = 0, lastReadAt = 0, phaseSince = 0;
  var cureRung = 0, cureCycle = 0, cureNextAt = 0, cureActions = 0, lastOpenAt = 0, opticsEverEnabled = false;
  /* A session that had to be repaired keeps the 10-30 cm focus lock but never
     gets the forced shutter: shortening it is the only lens step that can
     darken a picture enough to stop decoding, so it stays off after a repair. */
  var opticsLightMode = false;
  var temporarySwap = false, returnId = null, returnTimer = null, returnTries = 0;
  /* Debug trace: the operator can open the page with ?debug=1 and read the last
   * events on the screen, so a report about a phone that still does not scan
   * names the failing step instead of needing another guess. */
  var debugLines = [], debugBox = null;
  function stamp() {
    var d = new Date();
    return faDigits(('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2) + ':' + ('0' + d.getSeconds()).slice(-2));
  }
  function note(text) {
    if (debugLines.length > 200) debugLines.shift();
    debugLines.push(stamp() + ' ' + text);
  }
  function resetCure() { cureRung = 0; cureCycle = 0; cureNextAt = 0; }
  function cancelSwap() { temporarySwap = false; returnId = null; clearTimeout(returnTimer); returnTimer = null; }
  /* The result box must never claim "ready to scan" while the camera is still
     opening: the operator read that static line as "the scanner is broken"
     while the real state was hidden in a one-line hint under the camera. */
  function showState(name, stat) {
    if (busy || resBox.className !== 'result' || !name) return;
    resName.textContent = name;
    resStat.textContent = stat || '';
  }
  function debugDraw() {
    if (!debugBox) return;
    try {
      debugBox.textContent = 'cam=' + camState + ' scanning=' + scanning + ' gen=' + cameraGeneration + ' id=' + (desiredId || '-')
        + ' pending=' + !!pendingOpen + ' busy=' + busy
        + '\nvideo=' + video.videoWidth + 'x' + video.videoHeight + ' rs=' + video.readyState
        + ' t=' + (typeof video.currentTime === 'number' ? Math.round(video.currentTime * 100) / 100 : '-')
        + ' passes=' + scanPasses + ' err=' + decodeErrors
        + ' read=' + (lastReadAt ? Math.round((now() - lastReadAt) / 1000) + 's' : '-')
        + ' tag=' + (lastTagAt ? Math.round((now() - lastTagAt) / 1000) + 's' : '-')
        + '\ncure rung=' + cureRung + ' cycle=' + cureCycle + ' actions=' + cureActions + ' swap=' + temporarySwap
        + ' optics=' + (optics ? optics.state : (opticsSafeMode ? 'released' : '-'))
        + '\n' + debugLines.slice(-14).join('\n');
    } catch (e) {}
  }
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
    // Only the negotiated frame size is required. Several Android WebViews keep
    // readyState at 1 for a live MediaStream, and that single signal used to be
    // enough to leave the scanner idle until the camera was switched by hand.
    if (!video.videoWidth || !video.videoHeight) return false;
    if (video.currentTime !== lastFrameTime) return true;
    if (frameToken !== lastFrameToken) return true;
    return now() - lastDecodeAt >= FORCE_DECODE_MS;
  }
  /* A cheap 16x12 copy of the frame: average brightness plus a pixel
   * fingerprint. This is the only signal that can tell a live picture apart
   * from a preview that looks alive but delivers nothing to the decoder - the
   * state some phones enter on the very first camera open of a page. */
  function frameProbe() {
    try {
      if (!sigCanvas) { sigCanvas = document.createElement('canvas'); sigCanvas.width = 16; sigCanvas.height = 12; }
      var sctx = sigCanvas.getContext('2d');
      sctx.drawImage(video, 0, 0, 16, 12);
      var data = sctx.getImageData(0, 0, 16, 12).data, sum = 0, sig = '';
      for (var i = 0; i < data.length; i += 4) sum += (data[i] + data[i + 1] + data[i + 2]) / 3;
      for (var k = 0; k < data.length; k += 68) sig += ':' + data[k];
      return { sig: sig, luma: sum / (data.length / 4) };
    } catch (e) { return null; }
  }
  function reattachStream() {
    if (!currentStream || document.hidden || camState !== 'ready') return false;
    camMsg.textContent = 'ترمیم خودکار: تصویر ثابت ماند؛ پیوند دوبارهٔ جریان…';
    note('rung reattach');
    try {
      if ('srcObject' in video) { video.srcObject = null; video.srcObject = currentStream; }
      else if ('mozSrcObject' in video) video.mozSrcObject = currentStream;
    } catch (e) {}
    lastFrameTime = -1; lastFrameToken = -1; lastVideoTime = -1; lastDecodeAt = 0;
    try { observe(video.play(), function () { if (scanning) scheduleScan(0); }, noop); } catch (e) { scheduleScan(0); }
    return true;
  }
  /* The camera is opened again - by its exact device id when there is one, the
   * way the operator's manual camera switch did it - and, on the last rungs,
   * with the simplest constraints the device can be asked for. */
  function reopenRung(basic, message) {
    if (document.hidden) return false;
    recoveryAttempts = 0; warmupRepairs = 0; opticsLightMode = true;
    if (desiredId) warmReopenPending = true;     // a failed exact-id open falls back to facingMode
    openCamera(0, !!basic);
    camMsg.textContent = message;
    note(basic ? 'rung basic constraints' : 'rung reopen same camera');
    return true;
  }
  /* Switch to the other camera and come straight back. This is literally what
   * the operator did by hand, and it is the cure that was reported to work on
   * the phone whose first capture session never decodes. */
  function swapRung() {
    if (camList.length < 2 || document.hidden || pendingOpen) return false;
    var original = desiredId || (camList[0] && camList[0].deviceId), other = null, i;
    for (i = 0; i < camList.length; i++) {
      if (camList[i].deviceId !== original) { other = camList[i].deviceId; break; }
    }
    if (!other || !original) return false;
    temporarySwap = true; returnId = original; returnTries = 0;
    desiredId = other; recoveryAttempts = 0; warmupRepairs = 0; setControls();
    openCamera(0, false);
    camMsg.textContent = 'ترمیم خودکار: دوربین موقتاً تعویض می‌شود و برمی‌گردد…';
    note('rung swap to ' + other);
    clearTimeout(returnTimer);
    returnTimer = setTimeout(returnToOriginal, SWAP_HOLD_MS + 2600);
    return true;
  }
  function returnToOriginal() {
    clearTimeout(returnTimer); returnTimer = null;
    if (!returnId || document.hidden) return;
    // A getUserMedia request cannot be cancelled: wait for the slot to settle.
    if (pendingOpen) { if (returnTries++ < 12) returnTimer = setTimeout(returnToOriginal, 400); return; }
    var id = returnId; returnId = null; temporarySwap = false;
    desiredId = id; recoveryAttempts = 0; warmupRepairs = 0; setControls();
    openCamera(0, false);
    camMsg.textContent = 'دوربین به انتخاب قبلی برگشت؛ تگ را وسط تصویر بگیرید';
    note('return to ' + id);
  }
  /* Release the lens lock and the forced shutter. The lock is a speed feature;
   * if the decoder is what it fights, reading tags matters more. Once released
   * it is never re-applied for this session. */
  function releaseOpticsRung() {
    var o = optics;
    if (!opticsEverEnabled && !o) return false;
    opticsSafeMode = true;
    stopOptics();
    if (o && o.track && currentStream && currentStream.getVideoTracks()[0] === o.track) {
      var want = {};
      if (hasMode(o.caps, 'focusMode', 'continuous')) want.focusMode = 'continuous';
      if (hasMode(o.caps, 'exposureMode', 'continuous')) want.exposureMode = 'continuous';
      try { observe(o.track.applyConstraints({ advanced: [want] }), noop, noop); } catch (e) {}
    }
    camMsg.textContent = 'ترمیم خودکار: قفل لنز آزاد شد تا خواندن تگ در اولویت باشد…';
    note('rung release optics');
    return true;
  }
  function cureSequence(broken, cycle) {
    if (broken) return ['reattach', 'reopen', 'basic', 'swap', 'optics'];
    return cycle === 0 ? ['reattach', 'reopen', 'swap', 'basic', 'optics'] : ['reopen', 'swap', 'basic', 'optics'];
  }
  function runCureRung(rung, broken) {
    if (rung === 'reattach') return reattachStream();
    if (rung === 'reopen') return reopenRung(false, broken
      ? 'ترمیم خودکار: تصویر خوانده نمی‌شود؛ همان دوربین دوباره باز می‌شود…'
      : 'ترمیم خودکار: دوربین یک‌بار دوباره تنظیم می‌شود…');
    if (rung === 'basic') return reopenRung(true, 'ترمیم خودکار: تنظیمات سادهٔ دوربین امتحان می‌شود…');
    if (rung === 'swap') return swapRung();
    if (rung === 'optics') return releaseOpticsRung();
    return false;
  }
  /* Is the path camera -> canvas -> decoder still doing its job? A still scene
   * is not enough to condemn it (a phone on a desk shows a frozen picture for
   * seconds), but nothing here is called before the streak has lasted
   * FROZEN_DEAD_MS, and a session that already read a tag is left alone while
   * its picture is healthy. */
  function pipelineBroken(probe, frozenFor) {
    if (!video.videoWidth || !video.videoHeight) return true;    // no frame size at all
    if (!trackLive()) return true;                               // the track itself ended
    if (decodeErrors >= DECODE_ERROR_LIMIT) return true;         // the canvas cannot be read
    if (!probe) return true;                                     // the probe canvas cannot be read
    if (probe.luma <= BLACK_LUMA) return true;                   // a black picture reads nothing
    if (scanPasses === 0 && now() - scanStartedAt >= PASS_STALL_MS) return true;
    if (lastPassAt && now() - lastPassAt >= PASS_STALL_MS + 2000) return true;   // the loop stopped analysing
    return frozenFor >= FROZEN_DEAD_MS;                          // the picture never changes
  }
  function cureTick() {
    if (document.hidden || busy) return;
    var ready = camState === 'ready', warming = camState === 'warming';
    if (!ready && !warming) return;                    // a failed open has its own explicit retry
    if (ready && !scanning) {                          // a ready page that is not analysing: re-arm it
      note('loop was idle; re-armed');
      startScanLoop();
      return;
    }
    if (decodeBusy && now() - decodeStartedAt > 6000) {   // a decode that never came back
      note('decode stuck; released');
      if (activeDecodeCancel) { activeDecodeCancel(); activeDecodeCancel = null; }
      decodeBusy = false;
    }
    var probe = frameProbe();
    if (probe && probe.sig !== lastSignature) { lastSignature = probe.sig; lastSignatureAt = now(); }
    var frozenFor = probe && lastSignatureAt ? now() - lastSignatureAt : 0;
    var broken = pipelineBroken(probe, frozenFor);
    if (lastTagAt && now() - lastTagAt < 4000) broken = false;   // it just worked
    if (ready && lastReadAt && !broken) return;                  // this session reads tags: never disturb it
    var quiet = now() - Math.max(phaseSince, lastReadAt);
    var grace = warming ? CURE_WARMING_GRACE_MS
      : broken ? (lastReadAt ? CURE_READ_BROKEN_GRACE_MS : CURE_BROKEN_GRACE_MS)
      : CURE_FIRST_MS;
    if (quiet < grace) return;
    if (now() < cureNextAt) return;
    if (now() - lastOpenAt < MIN_CAMERA_GAP_MS) { cureNextAt = lastOpenAt + MIN_CAMERA_GAP_MS; return; }
    if (pendingOpen) return;
    var guard = 0;
    var step = (broken ? (lastReadAt ? CURE_STEP_READ_BROKEN_MS : CURE_STEP_BROKEN_MS) : CURE_STEP_MS)
      + Math.min(cureCycle, CURE_CYCLE_CAP) * CURE_CYCLE_MS;
    while (guard++ < 14) {
      var sequence = cureSequence(broken, cureCycle);
      if (cureRung >= sequence.length) { cureRung = 0; cureCycle++; continue; }
      if (runCureRung(sequence[cureRung++], broken)) { cureActions++; setControls(); cureNextAt = now() + step; return; }
    }
  }
  function stallCheck() {
    try { cureTick(); } catch (e) { note('cure error: ' + ((e && e.message) || e)); }
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
    decodeBusy = true; decodeStartedAt = now();
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
      if (data) {
        // A decoded tag is the proof this session works: from here the ladder
        // stands down and only a broken picture may disturb the camera.
        firstCodeAt = firstCodeAt || now(); lastReadAt = now(); decodeErrors = 0; resetCure();
        note('tag decoded (' + String(data).length + ' chars)');
      }
      if (generation === cameraGeneration && camState === 'ready' && !document.hidden && data) sendScan(data, false);
      // Tiny adaptive rest only when decoding itself is slower than the camera:
      // keeps a weak phone cool without adding a fixed pause to every student.
      var spent = now() - started;
      scheduleScan(spent > 25 ? Math.min(MAX_REST_MS, Math.round(spent * 0.1)) : 0);
    }
    try {
      var size = frameImage(passCounter++);
      scanPasses++; lastPassAt = now(); decodeErrors = 0;
      if (useNative) {
        observe(nativeDetector.detect(canvas), function (codes) {
          complete(codes && codes.length ? codes[0].rawValue : null);
        }, function () { nativeBroken = true; nativeDetector = null; complete(null); });
      } else jsDecode(ctx.getImageData(0, 0, size.width, size.height), size.full, complete);
    } catch (e) { decodeErrors++; complete(null); }
  }
  function startScanLoop() {
    scanning = true; lastSignature = ''; lastSignatureAt = 0; lastDecodeAt = 0;
    scanPasses = 0; scanStartedAt = now(); phaseSince = now(); lastPassAt = now();
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
  var NEAR_METERS = 0.20, BAND_MIN = 0.10, BAND_MAX = 0.30, FOCUS_WATCHDOG_MS = 5000;
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
  /* focusDistance is reported in metres; the tag is read at 10–30 cm. */
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
      state: 'starting', shortMessage: 'در حال تنظیم فوکوس ۱۰ تا ۳۰ سانتی‌متر…' };
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
    if (o.enabled) opticsEverEnabled = true;
    optics = o; renderOptics(o); flushOptics(o);
  }
  /* Shortest usable shutter: the biggest single speed win after the focus lock.
   * Applied once, right after the lock — never per scan, never after a tag. */
  function startFastExposure(o) {
    if (!o.confirmedFocus || o.exposureAttempted || !o.enabled || opticsLightMode) return;
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
  var warmupRepairs = 0, gesturePlay = null, needsGesture = false;
  var warmReopenPending = false, warmReopenFailed = false;
  try { desiredId = localStorage.getItem('mtag_scanner_cam') || null; } catch (e) {}
  function stopTracks(stream) {
    if (!stream) return;
    stream.getTracks().forEach(function (track) { track.onended = null; try { track.stop(); } catch (e) {} });
  }
  function stopStream() {
    stopOptics(); gesturePlay = null; needsGesture = false;
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
    camRetry.style.display = camState === 'error' || (camState === 'warming' && cureActions >= 2) || (optics && (optics.stalled || optics.state === 'unconfirmed')) ? 'block' : 'none';
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
    readyCheck = null; stopScanLoop(); stopStream(); camState = 'error'; warmupRepairs = 0;
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
    // `muted` is deliberately NOT treated as dead: several Android browsers keep
    // reporting muted=true on a track that is delivering frames, and that flag
    // used to block every self-repair path. Only an ended track is a dead one.
    return !!tracks.length && tracks[0].readyState !== 'ended';
  }
  function openCamera(attempt, basic) {
    if (document.hidden) return;
    if (pendingOpen) { if (!pendingOpen.timer) armOpenTimeout(pendingOpen); setControls(); return; }
    clearRecovery(); clearTimeout(readyTimer); readyCheck = null; stopScanLoop(); stopStream();
    prepareDecoder();
    var generation = ++cameraGeneration;
    camState = 'opening'; stableTicks = 0; lastVideoTime = -1;
    phaseSince = now(); lastOpenAt = now();
    camMsg.textContent = 'در حال راه‌اندازی دوربین انتخابی...';
    showState('در حال باز کردن دوربین…', '');
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
      if (warmReopenPending && !warmReopenFailed && (name === 'NotFoundError' || name === 'OverconstrainedError' || name === 'NotReadableError')) {
        // The exact-id re-negotiation failed: go back to the generic open that
        // worked a moment ago and never try the exact id again this session.
        warmReopenPending = false; warmReopenFailed = true; desiredId = '';
        recoveryAttempts = 0; warmupRepairs = 0;
        openCamera(0, false);
        camMsg.textContent = 'دوربین با شناسهٔ دقیق باز نشد؛ همان دوربین خودکار امتحان می‌شود…';
        return;
      }
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
      currentStream = stream; camState = 'warming'; warmReopenPending = false; setControls();
      var tracks = stream.getVideoTracks(), track = tracks[0], settings = {};
      try { settings = track && track.getSettings ? track.getSettings() : {}; } catch (e) {}
      if (req.id && settings.deviceId && settings.deviceId !== req.id) {
        cameraError('مرورگر دوربین دیگری برگرداند؛ انتخاب شما حفظ شد'); return;
      }
      var playing = false, playAttempts = 0, nextPlayAt = 0, deadline = now() + 7000;
      /* Warm-up is decided by what decoding actually needs: a live track and a
       * negotiated frame size. video.paused and a play() promise are not used as
       * gates - several Android browsers keep `paused` true while frames are on
       * screen and never settle the first play() promise, which left the scanner
       * idle until the camera was switched by hand. If frames still do not show
       * up, the same camera is reopened automatically (three times at most),
       * which is the manual workaround, done without the operator. */
      function trackUsable() { return !!track && track.readyState !== 'ended'; }   // muted is unreliable on Android
      function framesReady() {
        // While the browser has explicitly blocked playback there is no picture
        // to analyse, so readiness waits for the tap instead of pretending.
        return !!(video.videoWidth && video.videoHeight) && video.readyState >= 1
          && trackUsable() && !document.hidden && !needsGesture;
      }
      function attemptPlay() {
        try {
          observe(video.play(), function () { playing = true; needsGesture = false; checkReady(); }, function (error) {
            if (generation !== cameraGeneration || currentStream !== stream) return;
            var name = error && error.name || '';
            if (name === 'NotAllowedError' || name === 'SecurityError') {
              // Blocked autoplay is fixed by one tap, not by a dead scanner.
              needsGesture = true; nextPlayAt = 0;
              camMsg.textContent = 'برای شروع تصویر، یک بار صفحه را لمس کنید';
              return;
            }
            if (name === 'AbortError' || name === 'NotSupportedError' || !name) {
              // Replacing srcObject aborts an in-flight play() on several
              // browsers: retry quietly, the warm-up repair is the backstop.
              if (playAttempts++ < 4) nextPlayAt = now() + 300;
              return;
            }
            cameraError('پخش تصویر شروع نشد؛ دکمهٔ تلاش مجدد را لمس کنید');
          });
        } catch (e) { cameraError('نمایش تصویر در این مرورگر ممکن نشد؛ اسکنر قبلی را امتحان کنید'); }
      }
      function becomeReady() {
        camState = 'ready'; readyCheck = null; warmupRepairs = 0; gesturePlay = null;
        // The temporary camera of the swap repair must never become the choice
        // that is remembered for the next visit.
        if (!temporarySwap) {
          if (!desiredId && settings.deviceId) desiredId = settings.deviceId;
          if (desiredId) { try { localStorage.setItem('mtag_scanner_cam', desiredId); } catch (e) {} }
        }
        camMsg.textContent = 'تگ را وسط تصویر، در فاصلهٔ حدود ۱۰ تا ۳۰ سانتی‌متر بگیرید';
        // Analysing frames starts FIRST: no repair or lens step may ever leave a
        // ready-looking page that is not scanning.
        startScanLoop();
        showState('آمادهٔ اسکن', 'تگ را وسط تصویر، حدود ۱۰ تا ۳۰ سانتی‌متر بگیرید');
        try { setControls(); listCams(generation); } catch (e) { note('camera list failed'); }
        try { configureOptics(track, generation); } catch (e) { opticsSafeMode = true; stopOptics(); note('lens setup failed'); }
        if (temporarySwap) { clearTimeout(returnTimer); returnTimer = setTimeout(returnToOriginal, SWAP_HOLD_MS); }
      }
      function checkReady() {
        if (generation !== cameraGeneration || currentStream !== stream || document.hidden || camState !== 'warming') return;
        clearTimeout(readyTimer); readyTimer = null;
        if (framesReady()) { becomeReady(); return; }
        // A blocked autoplay is not a broken camera: wait for the tap.
        if (now() >= deadline && !needsGesture) {
          // The self-healing ladder (cureTick) owns camera repairs from here;
          // this loop only keeps trying to start playback.
          deadline = now() + 7000;
        }
        // Keep trying to start playback while waiting: a rejected or aborted
        // play() must not leave a live camera showing nothing.
        if (!playing && !needsGesture && now() >= nextPlayAt) { nextPlayAt = now() + 2000; attemptPlay(); }
        readyTimer = setTimeout(checkReady, 25);
      }
      gesturePlay = attemptPlay;
      readyCheck = checkReady;
      try {
        video.muted = true;
        if ('srcObject' in video) video.srcObject = stream;
        else if ('mozSrcObject' in video) video.mozSrcObject = stream;
        else { objectURL = (window.URL || window.webkitURL).createObjectURL(stream); video.src = objectURL; }
        tracks.forEach(function (t) {
          t.onended = function () { if (generation === cameraGeneration && currentStream === stream) scheduleRecovery(); };
        });
        attemptPlay(); checkReady();
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
    recoveryAttempts = 0; warmupRepairs = 0; decodeErrors = 0;
    cancelSwap(); resetCure();             // the operator asked for a camera; follow that choice
    setControls(); openCamera(0, false);
  });
  camRetry.addEventListener('click', function () {
    if (pendingOpen) { window.location.reload(); return; }
    recoveryAttempts = 0; warmupRepairs = 0; decodeErrors = 0;
    cancelSwap(); resetCure(); cureActions = 0;
    openCamera(0, false);
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
    resetCure(); cureActions = 0;
    if (returnId) { returnToOriginal(); return; }
    if (camState === 'error') { if (returning) { recoveryAttempts = 0; warmupRepairs = 0; openCamera(0, false); } return; }
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
    if (!trackLive() || !video.videoWidth) {
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
  /* ?debug=1 : live state on the screen, for a phone we cannot hold. */
  try {
    var debugOn = /(^|[?&])debug=1(&|$)/.test(String((window.location && window.location.search) || ''));
    if (debugOn && document.body) {
      debugBox = document.createElement('pre');
      debugBox.setAttribute('id', 'scanDebug');
      debugBox.style.cssText = 'position:fixed;left:0;right:0;bottom:0;max-height:46%;overflow:auto;margin:0;padding:6px 8px;'
        + 'background:rgba(0,0,0,.82);color:#9fe8ff;font:11px/1.45 monospace;direction:ltr;text-align:left;z-index:99;white-space:pre-wrap';
      document.body.appendChild(debugBox);
      setInterval(debugDraw, 500);
      note('debug overlay on');
    }
  } catch (e) { debugBox = null; }
  preloadSounds();                          /* bytes first, decode after the tap */
  openCamera(0, false);
})();
