/* CalcBuro — cookietoestemming en Google Ads-conversiemeting.
 * Zonder toestemming (geen keuze, geweigerd, verlopen, ingetrokken) laadt deze pagina niets van Google en
 * stuurt zij niets naar Google. Alleen de keuze zelf staat in de lokale opslag van de browser.
 * Na "Accepteren": Consent Mode v2 eerst alles 'denied', daarna alleen ad_storage en ad_user_data 'granted';
 * ad_personalization en analytics_storage blijven 'denied'. Geen remarketing, geen enhanced conversions,
 * geen persoonsgegevens. Een conversie volgt uitsluitend op een bevestigde, nieuw opgeslagen live-aanvraag
 * (event 'calcburo:aanvraag-opgeslagen' uit aanvraag.js) met een opaque conversie-ID van de server. */
(function (root, factory) {
  'use strict';
  var api = factory();
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    root.CalcBuroToestemming = api;
    api.start(root);
  }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  var ADS_ID = 'AW-18516347577';
  var CONVERSIE_DOEL = ADS_ID + '/7tJyCMmp1Z4dELmVpP1E';
  var GTAG_URL = 'https://www.googletagmanager.com/gtag/js?id=' + ADS_ID;
  var SLEUTEL = 'calcburo-cookiekeuze';
  var VERZONDEN_SLEUTEL = 'calcburo-conversies';
  var VERSIE = 1;
  var TERMIJN_MS = 180 * 24 * 60 * 60 * 1000; // zes maanden; daarna opnieuw vragen
  var KEUZES = ['geaccepteerd', 'geweigerd'];
  var KLIKPARAMETERS = ['gclid', 'gbraid', 'wbraid', 'gad_source', 'gad_campaignid'];
  var CONVERSIE_ID = /^[0-9a-f]{32}$/;
  var EVENT = 'calcburo:aanvraag-opgeslagen';
  var MAX_VERZONDEN = 20;

  /** Geldige, niet-verlopen keuze of null. Ongeldige of verlopen keuzes worden verwijderd. */
  function leesKeuze(opslag, nu) {
    var ruw = null;
    try { ruw = opslag ? opslag.getItem(SLEUTEL) : null; } catch (e) { return null; }
    if (ruw === null) return null;
    var k = null;
    try { k = JSON.parse(ruw); } catch (e) { k = null; }
    var geldig = k && k.v === VERSIE && KEUZES.indexOf(k.keuze) !== -1 && typeof k.tijd === 'number' && k.tijd <= nu && nu - k.tijd < TERMIJN_MS;
    if (!geldig) {
      try { opslag.removeItem(SLEUTEL); } catch (e) { /* niet beschikbaar: gedraagt zich als geen keuze */ }
      return null;
    }
    return k.keuze;
  }

  function bewaarKeuze(opslag, keuze, nu) {
    try {
      opslag.setItem(SLEUTEL, JSON.stringify({ v: VERSIE, keuze: keuze, tijd: nu }));
      return true;
    } catch (e) {
      return false;
    }
  }

  /** Pagina-adres voor Google: zonder zoekparameters en fragment, behalve de advertentieklik-ID's. */
  function veiligeLocatie(href) {
    var u;
    try { u = new URL(href); } catch (e) { return ''; }
    var houden = [];
    KLIKPARAMETERS.forEach(function (naam) {
      var waarde = u.searchParams.get(naam);
      if (waarde !== null && /^[A-Za-z0-9_-]{1,200}$/.test(waarde)) houden.push(naam + '=' + waarde);
    });
    return u.origin + u.pathname + (houden.length ? '?' + houden.join('&') : '');
  }

  /** Verwijzer voor Google: alleen herkomst en pad, nooit zoekparameters of fragment. */
  function veiligeVerwijzer(ref) {
    if (!ref) return '';
    try {
      var u = new URL(ref);
      return /^https?:$/.test(u.protocol) ? u.origin + u.pathname : '';
    } catch (e) {
      return '';
    }
  }

  /** Alleen een bevestigde, nieuw opgeslagen live-aanvraag met een geldig opaque ID telt. */
  function magConversie(detail) {
    return !!detail && detail.live === true && detail.duplicaat === false && typeof detail.conversieId === 'string' && CONVERSIE_ID.test(detail.conversieId);
  }

  function maak(o) {
    var win = o.window;
    var doc = o.document;
    var opslag = o.opslag;
    var sessie = o.sessie;
    var nu = o.nu || function () { return Date.now(); };
    var herlaad = o.herlaad || function () { win.location.reload(); };
    var googleGeladen = false;
    var uitgeschakeld = false;
    var kaart = null;
    var terugFocus = null;

    function gtag() { win.dataLayer.push(arguments); }

    function laadGoogle() {
      if (googleGeladen || uitgeschakeld) return;
      googleGeladen = true;
      win.dataLayer = win.dataLayer || [];
      win.gtag = gtag;
      // Consent Mode v2: standaard alles geweigerd, vóórdat de bibliotheek laadt.
      gtag('consent', 'default', { ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied' });
      gtag('set', 'ads_data_redaction', true);
      gtag('set', 'url_passthrough', false);
      gtag('set', 'allow_ad_personalization_signals', false);
      gtag('set', 'allow_google_signals', false);
      gtag('consent', 'update', { ad_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'denied', analytics_storage: 'denied' });
      gtag('js', new Date(nu()));
      gtag('config', ADS_ID, {
        page_location: veiligeLocatie(win.location.href),
        page_referrer: veiligeVerwijzer(doc.referrer),
        send_page_view: false,
        allow_ad_personalization_signals: false,
        allow_google_signals: false,
        allow_enhanced_conversions: false
      });
      var s = doc.createElement('script');
      s.async = true;
      s.src = GTAG_URL;
      doc.head.appendChild(s);
    }

    /** Verwijdert first-party Google Ads-cookies (_gcl_*) van calcburo.nl. */
    function verwijderGoogleCookies() {
      var namen = String(doc.cookie || '').split(';').map(function (c) { return c.split('=')[0].trim(); })
        .filter(function (n) { return /^_gcl_/.test(n); });
      var host = win.location.hostname;
      namen.forEach(function (naam) {
        ['', '; domain=' + host, '; domain=.' + host.replace(/^www\./, '')].forEach(function (domein) {
          doc.cookie = naam + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; max-age=0; path=/' + domein;
        });
      });
    }

    function schakelGoogleUit() {
      uitgeschakeld = true;
      if (googleGeladen) {
        win['ga-disable-' + ADS_ID] = true;
        if (typeof win.gtag === 'function') {
          win.gtag('consent', 'update', { ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied' });
        }
      }
      verwijderGoogleCookies();
    }

    /** Formulier met ingevulde gegevens: niet herladen, dan gaat invoer verloren. */
    function formulierIngevuld() {
      var form = doc.getElementById('aanvraagformulier');
      if (!form || form.hidden) return false;
      return Array.prototype.some.call(form.elements || [], function (veld) {
        if (veld.type === 'hidden' || veld.type === 'submit' || veld.type === 'button') return false;
        if (veld.type === 'file') return !!(veld.files && veld.files.length);
        if (veld.tagName === 'SELECT') return veld.selectedIndex > 0;
        return String(veld.value || '').trim() !== '';
      });
    }

    function el(tag, attrs, tekst) {
      var e = doc.createElement(tag);
      Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
      if (tekst) e.textContent = tekst;
      return e;
    }

    function sluitKaart() {
      if (!kaart) return;
      if (kaart.parentNode) kaart.parentNode.removeChild(kaart);
      kaart = null;
      doc.documentElement.classList.remove('cookie-keuze-open');
      if (terugFocus && terugFocus.focus) terugFocus.focus();
      terugFocus = null;
    }

    function toonKaart(focus) {
      if (kaart) {
        if (focus) kaart.focus();
        return;
      }
      kaart = el('section', { class: 'cookie-keuze', 'aria-labelledby': 'cookie-keuze-titel', tabindex: '-1' });
      kaart.appendChild(el('h2', { id: 'cookie-keuze-titel', class: 'cookie-keuze-titel' }, 'Cookies voor advertentiemeting'));
      var p = el('p', { id: 'cookie-keuze-tekst' }, 'Mogen wij Google Ads-cookies plaatsen? Daarmee meten wij alleen of een advertentie tot een aanvraag leidt. Zonder toestemming sturen wij niets naar Google. Geen gepersonaliseerde advertenties, geen analytics en geen namen, e-mailadressen of projectgegevens. U kunt uw keuze altijd wijzigen via ‘Cookie-instellingen’ onderaan de pagina. ');
      p.appendChild(el('a', { href: '/privacy.html#cookies' }, 'Meer in de privacyverklaring'));
      kaart.appendChild(p);
      var knoppen = el('div', { class: 'cookie-keuze-knoppen' });
      [['geaccepteerd', 'Accepteren'], ['geweigerd', 'Weigeren']].forEach(function (k) {
        var knop = el('button', { type: 'button', class: 'button cookie-knop', 'data-keuze': k[0], 'aria-describedby': 'cookie-keuze-tekst' }, k[1]);
        knop.addEventListener('click', function () { kies(k[0]); });
        knoppen.appendChild(knop);
      });
      kaart.appendChild(knoppen);
      var skip = doc.querySelector('.skip-link');
      doc.body.insertBefore(kaart, skip ? skip.nextSibling : doc.body.firstChild);
      doc.documentElement.classList.add('cookie-keuze-open');
      if (focus) kaart.focus();
    }

    function meldStatus(tekst) {
      var m = doc.getElementById('cookie-keuze-status');
      if (!m) {
        m = el('p', { id: 'cookie-keuze-status', class: 'cookie-keuze-status', role: 'status' });
        doc.body.appendChild(m);
      }
      m.textContent = tekst;
    }

    function kies(keuze) {
      var opgeslagen = bewaarKeuze(opslag, keuze, nu());
      sluitKaart();
      if (keuze === 'geaccepteerd') {
        uitgeschakeld = false;
        if (!opgeslagen) meldStatus('Uw toestemming geldt alleen voor deze pagina, omdat uw browser de keuze niet kan bewaren.');
        if (googleGeladen && win['ga-disable-' + ADS_ID]) {
          // Eerder op deze pagina ingetrokken en nu opnieuw toegestaan.
          win['ga-disable-' + ADS_ID] = false;
          win.gtag('consent', 'update', { ad_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'denied', analytics_storage: 'denied' });
        }
        laadGoogle();
        return;
      }
      var wasGeladen = googleGeladen;
      schakelGoogleUit();
      if (!wasGeladen) return;
      // De al geladen Google-bibliotheek is uitgeschakeld; herladen verwijdert hem volledig.
      if (formulierIngevuld()) meldStatus('Uw keuze is opgeslagen. Google Ads is uitgeschakeld en wordt volledig verwijderd zodra u deze pagina verlaat of herlaadt.');
      else herlaad();
    }

    /** Conversie voor een bevestigde live-aanvraag. Geeft true als er een conversie is gemeld. */
    function meldAanvraag(detail) {
      if (!magConversie(detail) || uitgeschakeld) return false;
      if (leesKeuze(opslag, nu()) !== 'geaccepteerd' && !(googleGeladen && !opslagBeschikbaar())) return false;
      var verzonden = [];
      try { verzonden = JSON.parse(sessie.getItem(VERZONDEN_SLEUTEL) || '[]'); } catch (e) { verzonden = []; }
      if (!Array.isArray(verzonden)) verzonden = [];
      if (verzonden.indexOf(detail.conversieId) !== -1) return false;
      laadGoogle();
      win.gtag('event', 'conversion', { send_to: CONVERSIE_DOEL, transaction_id: detail.conversieId });
      try { sessie.setItem(VERZONDEN_SLEUTEL, JSON.stringify(verzonden.concat(detail.conversieId).slice(-MAX_VERZONDEN))); } catch (e) { /* ontdubbeling door Google via transaction_id */ }
      return true;
    }

    function opslagBeschikbaar() {
      try {
        opslag.getItem(SLEUTEL);
        return true;
      } catch (e) {
        return false;
      }
    }

    function koppelInstellingen() {
      Array.prototype.forEach.call(doc.querySelectorAll('[data-cookie-instellingen]'), function (knop) {
        knop.hidden = false;
        if (knop.parentNode && knop.parentNode.hidden) knop.parentNode.hidden = false;
        knop.addEventListener('click', function () {
          terugFocus = knop;
          toonKaart(true);
        });
      });
    }

    function init() {
      var ruw = null;
      try { ruw = opslag.getItem(SLEUTEL); } catch (e) { ruw = null; }
      var keuze = leesKeuze(opslag, nu());
      if (keuze === null && ruw !== null) verwijderGoogleCookies(); // verlopen keuze: oude meetcookies weg
      if (keuze === 'geweigerd') verwijderGoogleCookies();
      koppelInstellingen();
      doc.addEventListener(EVENT, function (e) { meldAanvraag(e.detail); });
      if (keuze === 'geaccepteerd') laadGoogle();
      else if (keuze === null) toonKaart(false);
    }

    return {
      init: init,
      kies: kies,
      meldAanvraag: meldAanvraag,
      toonKaart: toonKaart,
      googleGeladen: function () { return googleGeladen; }
    };
  }

  function veiligeOpslag(win, naam) {
    try { return win[naam]; } catch (e) { return null; }
  }

  function start(win) {
    if (!win || !win.document) return;
    var doc = win.document;
    function go() {
      maak({ window: win, document: doc, opslag: veiligeOpslag(win, 'localStorage'), sessie: veiligeOpslag(win, 'sessionStorage') }).init();
    }
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', go);
    else go();
  }

  return {
    ADS_ID: ADS_ID,
    CONVERSIE_DOEL: CONVERSIE_DOEL,
    SLEUTEL: SLEUTEL,
    TERMIJN_MS: TERMIJN_MS,
    EVENT: EVENT,
    leesKeuze: leesKeuze,
    bewaarKeuze: bewaarKeuze,
    veiligeLocatie: veiligeLocatie,
    veiligeVerwijzer: veiligeVerwijzer,
    magConversie: magConversie,
    maak: maak,
    start: start
  };
});
