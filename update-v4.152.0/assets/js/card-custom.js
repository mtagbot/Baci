/* Shrink text only as needed, after fonts load. No stretching, letter spacing or QR resizing. */
(function () {
  'use strict';
  window.fitCustomCards = function (root) {
    var cards = (root || document).querySelectorAll('.card-id.custom');
    for (var i = 0; i < cards.length; i++) {
      var labels = cards[i].querySelectorAll('.card-name,.card-school,.card-meta,.card-row,.card-custom-site');
      for (var j = 0; j < labels.length; j++) {
        var el = labels[j];
        el.style.fontSize = '';
        el.setAttribute('data-custom-fit','1');
        if (!el.clientWidth) continue;
        var size = parseFloat(window.getComputedStyle(el).fontSize);
        for (var step = 0; step < 4 && el.scrollWidth > el.clientWidth; step++) {
          size *= el.clientWidth / el.scrollWidth * 0.98;
          el.style.fontSize = size + 'px';
        }
      }
    }
  };
  window.addEventListener('beforeprint',function () { window.fitCustomCards(document); });
})();

/* Persist the custom logo on this installation, and refresh both live cards and clone sources. */
(function () {
  'use strict';
  var busy = false;
  function status(text, error) {
    var el = document.getElementById('cLogoStatus');
    if (el) { el.textContent = text; el.style.color = error ? '#b91c1c' : '#166534'; }
  }
  function lock(value) {
    busy = value;
    document.getElementById('cCustomLogo').disabled = value;
    document.getElementById('cLogoReset').disabled = value;
  }
  function send(action, data) {
    var input = document.getElementById('cCustomLogo'), xhr = new XMLHttpRequest();
    var body = new FormData();
    body.append('csrf_token', input.getAttribute('data-csrf'));
    body.append('action', action);
    if (data) body.append('image_data', data);
    xhr.open('POST','entry-card-logo.php',true);
    xhr.setRequestHeader('Accept','application/json');
    xhr.timeout = 30000;
    xhr.onload = function () {
      lock(false); input.value = '';
      var result;
      try { result = JSON.parse(xhr.responseText); } catch (e) { status('پاسخ سرور معتبر نبود؛ دوباره تلاش کنید.',true); return; }
      if (xhr.status < 200 || xhr.status >= 300 || !result.ok) { status(result.message || result.error || 'ذخیره ناموفق بود.',true); return; }
      var cards = document.querySelectorAll('.card-id');
      for (var i = 0; i < cards.length; i++) {
        cards[i].classList.toggle('has-custom-logo',!!result.url);
        var img = cards[i].querySelector('.card-custom-logo');
        if (img) { if (result.url) img.setAttribute('src',result.url); else img.removeAttribute('src'); }
      }
      status(result.message,false);
      if (typeof window.applyCardDesign === 'function') window.applyCardDesign();
    };
    xhr.onerror = xhr.ontimeout = function () { lock(false); input.value = ''; status('ارتباط کامل نشد؛ وضعیت ذخیره را پس از تازه‌سازی بررسی کنید.',true); };
    xhr.send(body);
  }
  window.uploadCustomCardLogo = function (input) {
    if (busy || !input.files || !input.files[0]) return;
    var file = input.files[0], type = file.type;
    if (!type) { var ext = file.name.split('.').pop().toLowerCase(); type = {png:'image/png',jpg:'image/jpeg',jpeg:'image/jpeg',webp:'image/webp'}[ext]; }
    if (['image/png','image/jpeg','image/webp'].indexOf(type) < 0 || file.size > 2*1024*1024 || !file.size) { status('فقط PNG، JPG یا WebP تا ۲ مگابایت انتخاب کنید.',true); input.value = ''; return; }
    if (!window.FileReader) { status('مرورگر از خواندن فایل پشتیبانی نمی‌کند.',true); return; }
    lock(true); status('در حال ذخیره لوگو…',false);
    var reader = new FileReader();
    reader.onload = function () { send('upload','data:'+type+';base64,'+String(reader.result).split(',')[1]); };
    reader.onerror = function () { lock(false); input.value = ''; status('خواندن فایل ممکن نیست.',true); };
    reader.readAsDataURL(file);
  };
  window.resetCustomCardLogo = function () {
    if (busy) return;
    if (!window.confirm('لوگوی کارت سفارشی به پیش‌فرض برگردد؟')) return;
    lock(true); status('در حال بازگردانی…',false); send('reset');
  };
})();
