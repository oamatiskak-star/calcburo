/* CalcBuro — mobiel menu en inklapbare blokken op mobiel. Geen opslag, geen tracking. Het aanvraagformulier staat in aanvraag.js. */
(function () {
  'use strict';

  document.documentElement.classList.add('js');

  var MOBILE_QUERY = '(max-width: 960px)';
  var STACK_QUERY = '(max-width: 700px)';

  function initMenu() {
    var toggle = document.querySelector('.menu-toggle');
    var panel = document.getElementById('hoofdmenu');
    if (!toggle || !panel) return;

    var mobile = window.matchMedia(MOBILE_QUERY);
    var label = toggle.querySelector('.menu-toggle-label');

    function setOpen(isOpen) {
      toggle.setAttribute('aria-expanded', String(isOpen));
      panel.classList.toggle('is-open', isOpen);
      if (label) label.textContent = isOpen ? 'Sluiten' : 'Menu';
    }

    function isOpen() {
      return toggle.getAttribute('aria-expanded') === 'true';
    }

    function syncToViewport() {
      toggle.hidden = !mobile.matches;
      if (!mobile.matches) setOpen(false);
    }

    toggle.addEventListener('click', function () {
      setOpen(!isOpen());
    });

    panel.addEventListener('click', function (event) {
      if (mobile.matches && event.target.closest('a')) setOpen(false);
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && isOpen()) {
        setOpen(false);
        toggle.focus();
      }
    });

    document.addEventListener('click', function (event) {
      if (isOpen() && !event.target.closest('.nav')) setOpen(false);
    });

    if (mobile.addEventListener) {
      mobile.addEventListener('change', syncToViewport);
    } else if (mobile.addListener) {
      mobile.addListener(syncToViewport);
    }

    syncToViewport();
  }

  /* details[data-mobiel-dicht]: op mobiel ingeklapt (formulier eerst), op desktop altijd open.
     Zonder JavaScript blijft het blok open. */
  function initIngeklapt() {
    var blokken = document.querySelectorAll('details[data-mobiel-dicht]');
    if (!blokken.length || !window.matchMedia) return;
    var stack = window.matchMedia(STACK_QUERY);

    function sync() {
      Array.prototype.forEach.call(blokken, function (blok) {
        var kop = blok.querySelector('summary');
        blok.open = !stack.matches;
        if (!kop) return;
        if (stack.matches) kop.removeAttribute('tabindex'); else kop.setAttribute('tabindex', '-1');
      });
    }

    Array.prototype.forEach.call(blokken, function (blok) {
      blok.addEventListener('toggle', function () {
        if (!stack.matches && !blok.open) blok.open = true;
      });
    });

    if (stack.addEventListener) {
      stack.addEventListener('change', sync);
    } else if (stack.addListener) {
      stack.addListener(sync);
    }
    sync();
  }

  function init() {
    initMenu();
    initIngeklapt();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
