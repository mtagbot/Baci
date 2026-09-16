/* A revoked foreground panel leaves the screen within ~15 seconds.
   Security is enforced on every server request, independently of this UI helper. */
(function () {
  'use strict';
  if (!window.fetch) return;
  var timer = null, busy = false;
  function schedule() { clearTimeout(timer); timer = setTimeout(check, 15000); }
  function check() {
    if (busy) return;
    if (document.visibilityState === 'hidden') { schedule(); return; }
    busy = true;
    fetch('session-status.php', {credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}})
      .then(function (r) {
        if (r.status === 401) { window.location.replace('index.php?view=login&session_closed=1'); return null; }
        return r.ok ? r.json() : null;
      }).then(function (data) {
        if (data && data.authenticated === false) window.location.replace('index.php?view=login&session_closed=1');
      }).catch(function () { /* Network failure is not evidence of logout. */ })
      .then(function () { busy = false; schedule(); });
  }
  document.addEventListener('visibilitychange', check);
  window.addEventListener('pageshow', check);
  window.addEventListener('focus', check);
  schedule();
})();
