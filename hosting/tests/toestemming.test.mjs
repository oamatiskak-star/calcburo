// Cookietoestemming en Google Ads-conversie (toestemming.js). Geen afhankelijkheden, geen netwerk.
// Een minimale nep-DOM registreert elk element dat de pagina toevoegt en elke dataLayer-regel: Google kan
// alleen via het <script>-element van toestemming.js laden, dus "geen script + geen dataLayer" = geen verzoek.
//   node --test tests/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const web = join(root, 'httpdocs');
const require = createRequire(import.meta.url);
const T = require(join(web, 'assets/js/toestemming.js'));
const A = require(join(web, 'assets/js/aanvraag.js'));

const NU = Date.UTC(2026, 9, 29, 12, 0, 0);
const DAG = 24 * 60 * 60 * 1000;
const ID = '0123456789abcdef0123456789abcdef';
const GTAG = 'https://www.googletagmanager.com/gtag/js?id=AW-18516347577';

class El {
  constructor(doc, tag) {
    this.ownerDocument = doc;
    this.tagName = tag.toUpperCase();
    this.attrs = {};
    this.children = [];
    this.parentNode = null;
    this.listeners = {};
    this.hidden = false;
    this.ownText = '';
    const klassen = new Set();
    this.classList = { add: (k) => klassen.add(k), remove: (k) => klassen.delete(k), contains: (k) => klassen.has(k) };
  }
  setAttribute(k, v) { this.attrs[k] = String(v); if (k === 'class') String(v).split(' ').forEach((c) => this.classList.add(c)); }
  getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
  appendChild(c) { return this.insertBefore(c, null); }
  insertBefore(c, ref) {
    c.parentNode = this;
    const i = ref ? this.children.indexOf(ref) : -1;
    if (i < 0) this.children.push(c); else this.children.splice(i, 0, c);
    this.ownerDocument.toegevoegd.push(c);
    return c;
  }
  removeChild(c) { this.children = this.children.filter((x) => x !== c); c.parentNode = null; return c; }
  get firstChild() { return this.children[0] || null; }
  get nextSibling() { const s = this.parentNode ? this.parentNode.children : []; return s[s.indexOf(this) + 1] || null; }
  set textContent(t) { this.ownText = String(t); this.children = []; }
  get textContent() { return this.ownText + this.children.map((c) => c.textContent).join(''); }
  addEventListener(t, f) { (this.listeners[t] ||= []).push(f); }
  click() { (this.listeners.click || []).forEach((f) => f({})); }
  focus() { this.ownerDocument.activeElement = this; }
  *alles() { for (const c of this.children) { yield c; yield* c.alles(); } }
}

function maakDocument({ referrer = '', formulier = null } = {}) {
  const doc = { toegevoegd: [], cookieSchrijf: [], listeners: {}, referrer, readyState: 'complete', activeElement: null };
  doc.createElement = (tag) => new El(doc, tag);
  doc.documentElement = new El(doc, 'html');
  doc.head = new El(doc, 'head');
  doc.body = new El(doc, 'body');
  const skip = doc.createElement('a');
  skip.setAttribute('class', 'skip-link');
  doc.body.appendChild(skip);
  const li = doc.createElement('li');
  li.hidden = true;
  const instellingen = doc.createElement('button');
  instellingen.setAttribute('data-cookie-instellingen', '');
  li.appendChild(instellingen);
  doc.body.appendChild(li);
  doc.toegevoegd = [];
  const zoek = (pred) => [doc.head, doc.body].flatMap((r) => [...r.alles()]).filter(pred);
  doc.querySelector = (sel) => (sel === '.skip-link' ? zoek((e) => e.classList.contains('skip-link'))[0] || null : null);
  doc.querySelectorAll = (sel) => (sel === '[data-cookie-instellingen]' ? zoek((e) => e.getAttribute('data-cookie-instellingen') !== null) : []);
  doc.getElementById = (id) => (id === 'aanvraagformulier' ? formulier : zoek((e) => e.getAttribute('id') === id)[0] || null);
  doc.addEventListener = (t, f) => { (doc.listeners[t] ||= []).push(f); };
  doc.dispatch = (t, detail) => (doc.listeners[t] || []).forEach((f) => f({ detail }));
  let koekjes = { _gcl_au: '1.1.111', _gcl_aw: 'GCL.1.abc', cb: 'x' };
  Object.defineProperty(doc, 'cookie', {
    get: () => Object.entries(koekjes).map(([k, v]) => `${k}=${v}`).join('; '),
    set: (s) => { doc.cookieSchrijf.push(s); const [naam] = s.split('='); if (/max-age=0/.test(s)) { koekjes = { ...koekjes }; delete koekjes[naam]; } },
  });
  return { doc, instellingen, li };
}

function maakOpslag(begin = {}) {
  const m = new Map(Object.entries(begin));
  return { getItem: (k) => (m.has(k) ? m.get(k) : null), setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k), map: m };
}
const kapotteOpslag = { getItem() { throw new Error('geblokkeerd'); }, setItem() { throw new Error('geblokkeerd'); }, removeItem() { throw new Error('geblokkeerd'); } };
const keuze = (k, tijd = NU) => ({ [T.SLEUTEL]: JSON.stringify({ v: 1, keuze: k, tijd }) });

/** Laadt een "pagina" en houdt bij wat er richting netwerk zou kunnen gaan. */
function pagina({ opslag = maakOpslag(), sessie = maakOpslag(), href = 'https://calcburo.nl/aanvraag.php', referrer = '', nu = NU, formulier = null } = {}) {
  const { doc, instellingen, li } = maakDocument({ referrer, formulier });
  const netwerk = [];
  const win = {
    location: { href, hostname: new URL(href).hostname },
    fetch: () => netwerk.push('fetch'),
    navigator: { sendBeacon: () => netwerk.push('beacon') },
  };
  let herladen = 0;
  const t = T.maak({ window: win, document: doc, opslag, sessie, nu: () => nu, herlaad: () => { herladen++; } });
  t.init();
  const dl = () => (win.dataLayer || []).map((a) => Array.from(a));
  return {
    t, win, doc, opslag, sessie, instellingen, li, netwerk, dl,
    herladen: () => herladen,
    scripts: () => doc.toegevoegd.filter((e) => ['SCRIPT', 'IMG', 'IFRAME', 'LINK'].includes(e.tagName)),
    kaart: () => [...doc.body.alles()].find((e) => e.classList.contains('cookie-keuze')) || null,
    knop: (k) => [...doc.body.alles()].find((e) => e.getAttribute('data-keuze') === k),
    conversies: () => dl().filter((r) => r[0] === 'event' && r[1] === 'conversion'),
  };
}

function geenGoogle(p, wat) {
  assert.deepEqual(p.scripts(), [], wat + ': geen script/img/iframe toegevoegd');
  assert.equal(p.win.dataLayer, undefined, wat + ': geen dataLayer');
  assert.equal(p.win.gtag, undefined, wat + ': geen gtag');
  assert.deepEqual(p.netwerk, [], wat + ': geen fetch/beacon');
}

test('vóór toestemming: keuzekaart met gelijke knoppen, niets van Google geladen of verstuurd', () => {
  const p = pagina();
  geenGoogle(p, 'geen keuze');
  const kaart = p.kaart();
  assert.ok(kaart, 'kaart getoond');
  assert.equal(p.doc.body.children[1], kaart, 'direct na de skiplink (vroeg in de tabvolgorde)');
  assert.equal(kaart.getAttribute('aria-labelledby'), 'cookie-keuze-titel');
  const [ja, nee] = [p.knop('geaccepteerd'), p.knop('geweigerd')];
  assert.deepEqual([ja.textContent, nee.textContent], ['Accepteren', 'Weigeren']);
  assert.equal(ja.getAttribute('class'), nee.getAttribute('class'), 'gelijke vormgeving');
  assert.equal(ja.getAttribute('type'), 'button');
  assert.match(kaart.textContent, /Zonder toestemming sturen wij niets naar Google/);
  assert.ok([...kaart.alles()].some((e) => e.getAttribute('href') === '/privacy.html#cookies'));
  assert.equal(p.li.hidden, false, 'Cookie-instellingen in de footer zichtbaar met JavaScript');
  assert.equal(p.doc.cookieSchrijf.length, 0, 'geen cookies gezet');
  p.doc.dispatch(T.EVENT, { live: true, duplicaat: false, conversieId: ID });
  geenGoogle(p, 'aanvraag zonder keuze');
});

test('weigeren: keuze opgeslagen, niets van Google, ook niet op volgende pagina of na een aanvraag', () => {
  const p = pagina();
  p.knop('geweigerd').click();
  assert.equal(p.kaart(), null, 'kaart weg');
  assert.equal(JSON.parse(p.opslag.getItem(T.SLEUTEL)).keuze, 'geweigerd');
  assert.equal(p.herladen(), 0, 'niet herladen als Google nooit laadde');
  p.doc.dispatch(T.EVENT, { live: true, duplicaat: false, conversieId: ID });
  geenGoogle(p, 'na weigeren');
  const volgende = pagina({ opslag: p.opslag });
  assert.equal(volgende.kaart(), null, 'niet opnieuw gevraagd');
  volgende.doc.dispatch(T.EVENT, { live: true, duplicaat: false, conversieId: ID });
  geenGoogle(volgende, 'volgende pagina na weigeren');
});

test('accepteren: Consent Mode v2 eerst alles denied, dan alleen ad_storage/ad_user_data granted; daarna pas de bibliotheek', () => {
  const p = pagina({
    href: 'https://calcburo.nl/aanvraag.php?rol=aannemer&test=geheimesleutel&email=jan%40voorbeeld.nl&gclid=Cj0K_abc-1#formulier',
    referrer: 'https://calcburo.nl/inschrijfbegroting.html?naam=Jan&gclid=x',
  });
  p.knop('geaccepteerd').click();
  assert.equal(JSON.parse(p.opslag.getItem(T.SLEUTEL)).keuze, 'geaccepteerd');
  const s = p.scripts();
  assert.equal(s.length, 1, 'precies één script');
  assert.deepEqual([s[0].tagName, s[0].src, s[0].async, s[0].parentNode], ['SCRIPT', GTAG, true, p.doc.head]);
  const dl = p.dl();
  assert.deepEqual(dl[0], ['consent', 'default', { ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied' }], 'eerste regel: alles denied');
  const update = dl.findIndex((r) => r[0] === 'consent' && r[1] === 'update');
  assert.deepEqual(dl[update][2], { ad_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'denied', analytics_storage: 'denied' });
  const config = dl.find((r) => r[0] === 'config');
  assert.ok(dl.indexOf(config) > update, 'config na de consent-update');
  assert.equal(config[1], 'AW-18516347577');
  assert.deepEqual(config[2], {
    page_location: 'https://calcburo.nl/aanvraag.php?gclid=Cj0K_abc-1',
    page_referrer: 'https://calcburo.nl/inschrijfbegroting.html',
    send_page_view: false,
    allow_ad_personalization_signals: false,
    allow_google_signals: false,
    allow_enhanced_conversions: false,
  });
  assert.ok(dl.some((r) => r[0] === 'set' && r[1] === 'allow_ad_personalization_signals' && r[2] === false));
  const alles = JSON.stringify(dl);
  for (const verboden of ['geheimesleutel', 'jan', 'Jan', 'rol=', '"user_data"', 'email', 'formulier', 'remarketing']) {
    assert.ok(!alles.includes(verboden), `'${verboden}' gaat niet naar Google`);
  }
  assert.equal(p.conversies().length, 0, 'accepteren alleen is geen conversie');
  p.knop('geaccepteerd'); // kaart is weg
  assert.equal(p.kaart(), null);
});

test('veilige pagina-adressen: alleen herkomst, pad en advertentieklik-ID’s', () => {
  assert.equal(T.veiligeLocatie('https://calcburo.nl/aanvraag.php?test=abc&rol=aannemer'), 'https://calcburo.nl/aanvraag.php');
  assert.equal(T.veiligeLocatie('https://calcburo.nl/?gbraid=a1&wbraid=b2&gad_source=1&utm_term=jan%40x.nl'), 'https://calcburo.nl/?gbraid=a1&wbraid=b2&gad_source=1');
  assert.equal(T.veiligeLocatie('https://calcburo.nl/?gclid=%3Cscript%3E'), 'https://calcburo.nl/', 'vreemde tekens in gclid weg');
  assert.equal(T.veiligeVerwijzer('https://www.google.com/search?q=calculatie+jan'), 'https://www.google.com/search');
  assert.equal(T.veiligeVerwijzer('javascript:alert(1)'), '');
  assert.equal(T.veiligeVerwijzer(''), '');
});

test('toestemming geldt op volgende pagina’s zonder opnieuw vragen', () => {
  const p = pagina({ opslag: maakOpslag(keuze('geaccepteerd', NU - 10 * DAG)) });
  assert.equal(p.kaart(), null);
  assert.equal(p.scripts().length, 1);
  assert.equal(p.dl()[0][1], 'default');
});

test('verlopen keuze (6 maanden): opnieuw vragen, niets van Google, oude meetcookies verwijderd', () => {
  const grens = pagina({ opslag: maakOpslag(keuze('geaccepteerd', NU - T.TERMIJN_MS + DAG)) });
  assert.equal(grens.scripts().length, 1, 'vlak voor de termijn nog geldig');

  const p = pagina({ opslag: maakOpslag(keuze('geaccepteerd', NU - T.TERMIJN_MS)) });
  geenGoogle(p, 'verlopen');
  assert.ok(p.kaart(), 'opnieuw gevraagd');
  assert.equal(p.opslag.getItem(T.SLEUTEL), null, 'verlopen keuze verwijderd');
  assert.doesNotMatch(p.doc.cookie, /_gcl_/, '_gcl-cookies verwijderd');
  p.doc.dispatch(T.EVENT, { live: true, duplicaat: false, conversieId: ID });
  geenGoogle(p, 'aanvraag na verlopen keuze');

  for (const ruw of [JSON.stringify({ v: 1, keuze: 'geaccepteerd', tijd: NU + DAG }), JSON.stringify({ v: 2, keuze: 'geaccepteerd', tijd: NU }), JSON.stringify({ v: 1, keuze: 'ja', tijd: NU }), '{kapot']) {
    const q = pagina({ opslag: maakOpslag({ [T.SLEUTEL]: ruw }) });
    geenGoogle(q, 'ongeldige keuze ' + ruw);
    assert.ok(q.kaart());
  }
});

test('intrekken via footer: Google uitgeschakeld, consent denied, _gcl-cookies weg, pagina herladen', () => {
  const p = pagina({ opslag: maakOpslag(keuze('geaccepteerd')) });
  assert.equal(p.scripts().length, 1);
  p.instellingen.click();
  assert.ok(p.kaart(), 'kaart via Cookie-instellingen');
  assert.equal(p.doc.activeElement, p.kaart(), 'focus naar de kaart');
  p.knop('geweigerd').click();
  assert.equal(p.doc.activeElement, p.instellingen, 'focus terug naar de footerknop');
  assert.equal(p.win['ga-disable-AW-18516347577'], true);
  assert.deepEqual(p.dl().at(-1), ['consent', 'update', { ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied' }]);
  assert.doesNotMatch(p.doc.cookie, /_gcl_/);
  assert.match(p.doc.cookie, /cb=x/, 'eigen cookies onaangetast');
  assert.ok(p.doc.cookieSchrijf.some((c) => c.startsWith('_gcl_aw=;') && c.includes('domain=.calcburo.nl')));
  assert.equal(p.herladen(), 1);
  assert.equal(JSON.parse(p.opslag.getItem(T.SLEUTEL)).keuze, 'geweigerd');
  const voor = p.dl().length;
  p.doc.dispatch(T.EVENT, { live: true, duplicaat: false, conversieId: ID });
  assert.equal(p.dl().length, voor, 'na intrekken geen enkele gtag-aanroep meer');
  assert.equal(p.scripts().length, 1, 'geen nieuw script');
  geenGoogle(pagina({ opslag: p.opslag }), 'herladen pagina na intrekken');
});

test('intrekken met ingevuld formulier: niet herladen (invoer blijft), wel uitgeschakeld en gemeld', () => {
  const formulier = { hidden: false, elements: [{ type: 'hidden', value: 'csrf' }, { type: 'text', tagName: 'INPUT', value: 'Jan' }] };
  const p = pagina({ opslag: maakOpslag(keuze('geaccepteerd')), formulier });
  p.instellingen.click();
  p.knop('geweigerd').click();
  assert.equal(p.herladen(), 0);
  assert.equal(p.win['ga-disable-AW-18516347577'], true);
  assert.match(p.doc.getElementById('cookie-keuze-status').textContent, /uitgeschakeld/);
  p.doc.dispatch(T.EVENT, { live: true, duplicaat: false, conversieId: ID });
  assert.equal(p.conversies().length, 0);
});

test('één conversie na een bevestigde live-aanvraag: alleen send_to en opaque transaction_id', () => {
  const sessie = maakOpslag();
  const p = pagina({ opslag: maakOpslag(keuze('geaccepteerd')), sessie });
  p.doc.dispatch(T.EVENT, { live: true, duplicaat: false, conversieId: ID });
  assert.deepEqual(p.conversies(), [['event', 'conversion', { send_to: 'AW-18516347577/7tJyCMmp1Z4dELmVpP1E', transaction_id: ID }]]);
  p.doc.dispatch(T.EVENT, { live: true, duplicaat: false, conversieId: ID });
  assert.equal(p.conversies().length, 1, 'zelfde ID niet opnieuw');
  const herladen = pagina({ opslag: p.opslag, sessie });
  herladen.doc.dispatch(T.EVENT, { live: true, duplicaat: false, conversieId: ID });
  assert.equal(herladen.conversies().length, 0, 'ook niet na herladen in dezelfde sessie');
  assert.equal(p.scripts().length, 1, 'geen tweede bibliotheek');
});

test('geen conversie bij fout, validatiefout, testmodus, duplicaat of ontbrekend/ongeldig ID', () => {
  const p = pagina({ opslag: maakOpslag(keuze('geaccepteerd')) });
  const gevallen = [
    undefined,
    null,
    { live: true, duplicaat: true, conversieId: ID },
    { live: false, duplicaat: false, conversieId: ID },
    { live: true, duplicaat: false, conversieId: '' },
    { live: true, duplicaat: false, conversieId: 'CB-ABCDEFGH' },
    { live: true, duplicaat: false, conversieId: ID.toUpperCase() },
    { live: 'true', duplicaat: false, conversieId: ID },
    { live: true, conversieId: ID },
  ];
  for (const d of gevallen) {
    assert.equal(p.t.meldAanvraag(d), false, JSON.stringify(d));
    p.doc.dispatch(T.EVENT, d);
  }
  assert.equal(p.conversies().length, 0);
});

test('keten met aanvraag.js: alleen een nieuw opgeslagen live-antwoord levert een geldig conversiedetail', () => {
  const live = { ok: true, opgeslagen: true, duplicaat: false, live: true, conversie_id: ID, referentie: 'CB-ABCDEFGH', documenten: 0, mail: { intern: 'geaccepteerd', bevestiging: 'geaccepteerd' } };
  const r = A.klassificeer(201, live);
  assert.deepEqual(r.conversie, { live: true, duplicaat: false, conversieId: ID });
  assert.equal(T.magConversie(r.conversie), true);
  assert.ok(!JSON.stringify(r.conversie).includes('CB-'), 'geen referentie in het conversiedetail');
  assert.equal(T.magConversie(A.klassificeer(200, { ...live, duplicaat: true }).conversie), false, 'replay');
  assert.equal(T.magConversie(A.klassificeer(201, { ...live, live: false, conversie_id: null }).conversie), false, 'testmodus');
  assert.equal(T.magConversie(A.klassificeer(201, { ...live, duplicaat: undefined }).conversie), false, 'onbekend = duplicaat');
  assert.equal(T.magConversie(A.klassificeer(201, { ...live, conversie_id: 'CB-ABCDEFGH' }).conversie), false);
  for (const [status, body] of [[422, { ok: false, opgeslagen: false, fout: { code: 'velden', bericht: 'x', velden: { email: 'ongeldig' } } }], [0, null], [201, { ...live, opgeslagen: false }], [500, live]]) {
    const f = A.klassificeer(status, body);
    assert.equal(f.soort, 'fout');
    assert.equal(f.conversie, undefined, 'fout heeft geen conversiedetail');
  }
  const aanvraagJs = readFileSync(join(web, 'assets/js/aanvraag.js'), 'utf8');
  assert.ok(aanvraagJs.includes(`'${T.EVENT}'`), 'zelfde eventnaam in aanvraag.js');
  const succes = aanvraagJs.slice(aanvraagJs.indexOf('function toonOntvangst'), aanvraagJs.indexOf('function toonFout'));
  assert.ok(succes.indexOf('form.reset()') < succes.indexOf('dispatchEvent'), 'formulier leeg vóór het event');
  assert.equal((aanvraagJs.match(/dispatchEvent/g) || []).length, 1, 'event alleen vanuit het ontvangstbewijs');
});

test('opslag geblokkeerd: elke pagina opnieuw vragen; toestemming geldt alleen voor die pagina', () => {
  const p = pagina({ opslag: kapotteOpslag, sessie: kapotteOpslag });
  geenGoogle(p, 'geblokkeerde opslag');
  assert.ok(p.kaart());
  p.knop('geaccepteerd').click();
  assert.equal(p.scripts().length, 1);
  assert.match(p.doc.getElementById('cookie-keuze-status').textContent, /alleen voor deze pagina/);
  p.doc.dispatch(T.EVENT, { live: true, duplicaat: false, conversieId: ID });
  assert.equal(p.conversies().length, 1, 'conversie op deze pagina na toestemming');
  geenGoogle(pagina({ opslag: kapotteOpslag }), 'volgende pagina');
});

test('statisch: Google alleen via toestemming.js; elke pagina laadt die, heeft Cookie-instellingen en een CSP zonder inline', () => {
  const html = readdirSync(web).filter((f) => f.endsWith('.html'));
  for (const f of html) {
    const s = readFileSync(join(web, f), 'utf8');
    assert.match(s, /<script src="assets\/js\/toestemming\.js\?v=[0-9a-f]{10}" defer><\/script>/, f);
    assert.doesNotMatch(s, /googletagmanager\.com\/gtag|gtag\(|dataLayer|<noscript>|<img[^>]+google/, f + ': geen Google-code of no-JS-pixel in HTML');
    assert.match(s, /<li hidden><button class="foot-knop" type="button" data-cookie-instellingen>Cookie-instellingen<\/button><\/li>/, f);
    const csp = (s.match(/http-equiv="Content-Security-Policy" content="([^"]+)"/) || [])[1];
    assert.match(csp, /script-src 'self' https:\/\/www\.googletagmanager\.com/, f);
    assert.doesNotMatch(csp, /unsafe-|frame-src|\*/, f);
    assert.match(s, /<meta name="referrer" content="no-referrer">/, f + ': geen Referer naar Google');
  }
  const js = readdirSync(join(web, 'assets/js')).filter((f) => f !== 'toestemming.js');
  for (const f of js) assert.doesNotMatch(readFileSync(join(web, 'assets/js', f), 'utf8'), /googletagmanager|google\.com|gtag\(|dataLayer/i, f);
  const t = readFileSync(join(web, 'assets/js/toestemming.js'), 'utf8');
  assert.doesNotMatch(t, /innerHTML|document\.write|[^_]user_data|enhanced_conversions:\s*true|ad_personalization:\s*'granted'|analytics_storage:\s*'granted'/);
});
