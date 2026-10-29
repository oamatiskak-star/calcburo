/* Calcburo — mobiel menu en transparante e-mailconceptflow. Geen opslag, geen tracking. */
(function () {
  'use strict';

  document.documentElement.classList.add('js');

  var MOBILE_QUERY = '(max-width: 960px)';
  var MAIL_TO = 'calculatie@calcburo.nl';
  var MAIL_SUBJECT = 'Calculatieaanvraag via Calcburo';
  var STATUS_TEXT = 'Je e-mailconcept is voorbereid. Controleer en verstuur het in je e-mailprogramma. Werkt dat niet? Mail naar calculatie@calcburo.nl.';
  var NOT_PROVIDED = 'niet opgegeven';

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

  var FIELD_MESSAGES = {
    naam: { valueMissing: 'Vul je naam in.' },
    email: {
      valueMissing: 'Vul je zakelijke e-mailadres in.',
      typeMismatch: 'Vul een geldig e-mailadres in, bijvoorbeeld naam@bedrijf.nl.'
    },
    project: { valueMissing: 'Beschrijf kort je project en je calculatievraag.' }
  };

  function messageFor(field) {
    var messages = FIELD_MESSAGES[field.name] || {};
    var validity = field.validity;
    if (validity.valueMissing) return messages.valueMissing || 'Dit veld is verplicht.';
    if (validity.typeMismatch) return messages.typeMismatch || 'Controleer de invoer.';
    if (validity.tooLong) return 'Deze invoer is te lang.';
    return 'Controleer de invoer.';
  }

  function showError(field, text) {
    var error = document.getElementById('fout-' + field.name);
    field.setAttribute('aria-invalid', text ? 'true' : 'false');
    if (!error) return;
    error.textContent = text;
    error.hidden = !text;
  }

  function validateField(field) {
    if (field.value.trim() === '' && field.required) field.value = '';
    var valid = field.checkValidity();
    showError(field, valid ? '' : messageFor(field));
    return valid;
  }

  function valueOf(form, name) {
    var raw = form.elements[name] ? form.elements[name].value.trim() : '';
    return raw === '' ? NOT_PROVIDED : raw;
  }

  function buildMailto(form) {
    var body = [
      'Naam: ' + valueOf(form, 'naam'),
      'Bedrijf: ' + valueOf(form, 'bedrijf'),
      'E-mail: ' + valueOf(form, 'email'),
      'Gewenste planning: ' + valueOf(form, 'planning'),
      '',
      'Projectomschrijving:',
      valueOf(form, 'project')
    ].join('\r\n');

    return 'mailto:' + MAIL_TO +
      '?subject=' + encodeURIComponent(MAIL_SUBJECT) +
      '&body=' + encodeURIComponent(body);
  }

  function initForm() {
    var form = document.getElementById('aanvraag');
    var status = document.getElementById('status');
    if (!form || !status) return;

    form.noValidate = true;
    var validated = Array.prototype.slice.call(form.querySelectorAll('[required]'));

    validated.forEach(function (field) {
      field.addEventListener('blur', function () {
        if (field.getAttribute('aria-invalid') === 'true' || field.value !== '') validateField(field);
      });
      field.addEventListener('input', function () {
        if (field.getAttribute('aria-invalid') === 'true') validateField(field);
      });
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      status.textContent = '';

      var invalid = validated.filter(function (field) {
        return !validateField(field);
      });

      if (invalid.length > 0) {
        invalid[0].focus();
        return;
      }

      window.location.href = buildMailto(form);
      status.textContent = STATUS_TEXT;
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initMenu();
      initForm();
    });
  } else {
    initMenu();
    initForm();
  }
})();
