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
