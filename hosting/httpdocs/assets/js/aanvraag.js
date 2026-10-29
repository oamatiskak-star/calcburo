/* CalcBuro — aanvraagformulier (progressive enhancement).
 * Zonder JavaScript verstuurt de browser het formulier gewoon naar /api/aanvraag.php en toont de server
 * een resultaatpagina. Met JavaScript: validatie vooraf, uploadvoortgang en het ontvangstbewijs op de pagina.
 * Succes wordt alleen getoond bij ok === true, opgeslagen === true en een geldig referentienummer. */
(function (root, factory) {
  'use strict';
  var api = factory();
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    root.CalcBuroAanvraag = api;
    api.start(root.document);
  }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  var TOEGESTAAN = ['pdf', 'ifc', 'xlsx', 'xls', 'csv', 'docx', 'jpg', 'jpeg', 'png'];
  var REF_PATROON = /^CB-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}$/;
  var MAIL_ROUTE = 'Mail uw aanvraag naar calculatie@calcburo.nl.';
  var VELDMELDINGEN = {
    naam: { verplicht: 'Vul uw naam in.', te_lang: 'Uw naam is te lang.', ongeldig: 'Controleer uw naam.' },
    email: { verplicht: 'Vul uw zakelijke e-mailadres in.', te_lang: 'Het e-mailadres is te lang.', ongeldig: 'Vul een geldig e-mailadres in, bijvoorbeeld naam@bedrijf.nl.' },
    project: { verplicht: 'Beschrijf kort uw project en uw calculatievraag.', te_lang: 'Maximaal 2000 tekens.', ongeldig: 'Controleer de omschrijving.' },
    telefoon: { ongeldig: 'Gebruik alleen cijfers, spaties en + ( ) - .', te_lang: 'Het telefoonnummer is te lang.' },
    bedrijf: { te_lang: 'De bedrijfsnaam is te lang.', ongeldig: 'Controleer de bedrijfsnaam.' }
  };

  function extensie(naam) {
    var punt = naam.lastIndexOf('.');
    return punt < 1 ? '' : naam.slice(punt + 1).toLowerCase();
  }

  /** Controle vooraf; de server controleert opnieuw (inclusief MIME en inhoud). */
  function bestandFout(bestanden, grenzen) {
    if (bestanden.length > grenzen.maxBestanden) return 'U kunt maximaal ' + grenzen.maxBestanden + ' bestanden meesturen.';
    var totaal = 0;
    for (var i = 0; i < bestanden.length; i++) {
      var b = bestanden[i];
      if (TOEGESTAAN.indexOf(extensie(b.name)) === -1) return '“' + b.name + '” heeft een niet-toegestaan bestandstype. Toegestaan: PDF, IFC, XLSX, XLS, CSV, DOCX, JPG en PNG.';
      if (b.size === 0) return '“' + b.name + '” is leeg.';
      if (b.size > grenzen.maxBestand) return '“' + b.name + '” is groter dan 20 MB.';
      totaal += b.size;
    }
    if (totaal > grenzen.maxTotaal) return 'De bestanden zijn samen groter dan 50 MB.';
    return '';
  }

  function grootte(bytes) {
    return bytes >= 1048576 ? (bytes / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.ceil(bytes / 1024)) + ' kB';
  }

  /** Publieke tekst over de bevestigingsmail; gelijk aan Weergave::mailTekst in PHP. */
  function mailTekst(intern, bevestiging) {
    var delen = [];
    if (bevestiging === 'geaccepteerd') delen.push('De ontvangstbevestiging is verstuurd. Controleer eventueel uw map met ongewenste e-mail en bewaar dit referentienummer.');
    else if (bevestiging === 'uitgeschakeld' || bevestiging === 'in_behandeling') delen.push('Bewaar dit referentienummer.');
    else delen.push('Uw aanvraag is ontvangen, maar de ontvangstbevestiging per e-mail kon niet worden verstuurd. Bewaar dit referentienummer.');
    if (['geaccepteerd', 'in_behandeling', 'uitgeschakeld'].indexOf(intern) === -1) delen.push('Mogelijk hebben wij uw aanvraag niet direct gezien. Mail uw referentienummer naar calculatie@calcburo.nl, dan pakken wij hem direct op.');
    return delen.join(' ');
  }

  /** Vertaalt een serverantwoord naar wat de pagina toont. Alles wat geen bevestigde opslag is, is een fout. */
  function klassificeer(status, body) {
    if (body && body.ok === true && body.opgeslagen === true && typeof body.referentie === 'string' && REF_PATROON.test(body.referentie) && (status === 200 || status === 201)) {
      var mail = body.mail || {};
      return { soort: 'succes', referentie: body.referentie, documenten: Number(body.documenten) || 0, mail: mailTekst(String(mail.intern), String(mail.bevestiging)) };
    }
    var fout = body && body.fout && typeof body.fout.bericht === 'string' ? body.fout : null;
    if (fout) return { soort: 'fout', code: String(fout.code || ''), bericht: fout.bericht, velden: fout.velden || {} };
    if (status === 0) return { soort: 'fout', code: 'netwerk', bericht: 'Verzenden is niet gelukt; er is geen ontvangst bevestigd. Controleer uw verbinding en probeer het opnieuw, of ' + MAIL_ROUTE.toLowerCase(), velden: {} };
    return { soort: 'fout', code: 'onbekend', bericht: 'Er is geen ontvangst bevestigd. Probeer het opnieuw of ' + MAIL_ROUTE.toLowerCase(), velden: {} };
  }

  function documentenTekst(n) {
    if (n === 0) return 'Er zijn geen documenten meegestuurd.';
    return n === 1 ? '1 document is ontvangen.' : n + ' documenten zijn ontvangen.';
  }

  function start(doc) {
    if (!doc) return;
    if (doc.readyState === 'loading') {
      doc.addEventListener('DOMContentLoaded', function () { koppel(doc); });
    } else {
      koppel(doc);
    }
  }

  function koppel(doc) {
    var form = doc.getElementById('aanvraagformulier');
    if (!form || !window.FormData || !window.XMLHttpRequest) return;
    var status = doc.getElementById('status');
    var knop = doc.getElementById('verstuur');
    var invoer = doc.getElementById('veld-documenten');
    var lijst = doc.getElementById('bestandenlijst');
    var grenzen = {
      maxBestanden: Number(form.getAttribute('data-max-bestanden')),
      maxBestand: Number(form.getAttribute('data-max-bestand')),
      maxTotaal: Number(form.getAttribute('data-max-totaal'))
    };
    var bezig = false;
    form.noValidate = true;

    function veldFout(naam, tekst) {
      var veld = form.elements[naam === 'documenten' ? 'documenten[]' : naam];
      var fout = doc.getElementById('fout-' + naam);
      if (veld && veld.setAttribute) veld.setAttribute('aria-invalid', tekst ? 'true' : 'false');
      if (fout) { fout.textContent = tekst; fout.hidden = !tekst; }
    }

    function toonStatus(tekst, soort) {
      status.textContent = tekst;
      status.className = 'form-status' + (soort ? ' is-' + soort : '');
    }

    function toonBestanden() {
      lijst.textContent = '';
      var bestanden = Array.prototype.slice.call(invoer.files || []);
      bestanden.forEach(function (b) {
        var li = doc.createElement('li');
        var naam = doc.createElement('span');
        var maat = doc.createElement('span');
        naam.textContent = b.name;
        maat.className = 'grootte';
        maat.textContent = grootte(b.size);
        li.appendChild(naam);
        li.appendChild(maat);
        lijst.appendChild(li);
      });
      veldFout('documenten', bestandFout(bestanden, grenzen));
    }

    function valideer() {
      var eerste = null;
      ['naam', 'email', 'project', 'telefoon'].forEach(function (naam) {
        var veld = form.elements[naam];
        var waarde = veld.value.trim();
        var code = '';
        if (veld.required && waarde === '') code = 'verplicht';
        else if (naam === 'email' && waarde !== '' && !/^[^\s@<>,;:"()[\]\\]+@[^\s@<>,;:"()[\]\\]+\.[^\s@<>,;:"()[\]\\]+$/.test(waarde)) code = 'ongeldig';
        else if (naam === 'telefoon' && waarde !== '' && !/^[0-9+() .-]{6,50}$/.test(waarde)) code = 'ongeldig';
        veldFout(naam, code ? VELDMELDINGEN[naam][code] : '');
        if (code && !eerste) eerste = veld;
      });
      var bf = bestandFout(Array.prototype.slice.call(invoer.files || []), grenzen);
      veldFout('documenten', bf);
      if (bf && !eerste) eerste = invoer;
      return eerste;
    }

    function toonOntvangst(r) {
      var bewijs = doc.getElementById('ontvangst');
      doc.getElementById('ontvangst-ref').textContent = r.referentie;
      doc.getElementById('ontvangst-docs').textContent = documentenTekst(r.documenten);
      doc.getElementById('ontvangst-mail').textContent = r.mail;
      form.hidden = true;
      bewijs.hidden = false;
      bewijs.focus();
    }

    function toonFout(r) {
      Object.keys(r.velden).forEach(function (naam) {
        var meldingen = VELDMELDINGEN[naam] || {};
        veldFout(naam, meldingen[r.velden[naam]] || 'Controleer dit veld.');
      });
      toonStatus(r.bericht, 'fout');
      if (r.code === 'sessie' || r.code === 'conflict' || r.code === 'aanvraag_id') knop.textContent = 'Pagina herladen en opnieuw proberen';
    }

    invoer.addEventListener('change', toonBestanden);

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (bezig) return;
      if (knop.textContent.indexOf('herladen') !== -1) { window.location.reload(); return; }
      toonStatus('', '');
      var eerste = valideer();
      if (eerste) { eerste.focus(); toonStatus('Controleer de gemarkeerde velden.', 'fout'); return; }

      bezig = true;
      knop.disabled = true;
      toonStatus('Bezig met verzenden…', 'bezig');
      var xhr = new XMLHttpRequest();
      xhr.open('POST', form.getAttribute('action'));
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.withCredentials = true;
      xhr.timeout = 600000;
      xhr.upload.addEventListener('progress', function (e) {
        if (e.lengthComputable && e.total > 0) toonStatus('Bezig met verzenden… ' + Math.floor((e.loaded / e.total) * 100) + '%', 'bezig');
      });
      function klaar(statusCode, tekst) {
        var body = null;
        try { body = tekst ? JSON.parse(tekst) : null; } catch (e) { body = null; }
        var r = klassificeer(statusCode, body);
        bezig = false;
        knop.disabled = false;
        if (r.soort === 'succes') toonOntvangst(r); else toonFout(r);
      }
      xhr.addEventListener('load', function () { klaar(xhr.status, xhr.responseText); });
      xhr.addEventListener('error', function () { klaar(0, ''); });
      xhr.addEventListener('timeout', function () { klaar(0, ''); });
      xhr.send(new FormData(form));
    });
  }

  return { bestandFout: bestandFout, klassificeer: klassificeer, mailTekst: mailTekst, documentenTekst: documentenTekst, extensie: extensie, start: start };
});
