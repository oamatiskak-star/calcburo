// Statische controles voor de CalcBuro-preview (homepage, zes landingspagina's, voorbeeldcalculatie, aanvullende diensten).
// Uitvoeren: node tests/static-check.mjs — geen dependencies.
import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const read = (file) => readFileSync(join(root, file), 'utf8');
const css = read('assets/css/styles.css');
const js = read('assets/js/main.js');

const results = [];
const check = (name, ok, detail = '') => results.push({ name, ok: Boolean(ok), detail });

const CSP = `<meta http-equiv="Content-Security-Policy" content="default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; connect-src 'none'; object-src 'none'; base-uri 'self'; form-action mailto:">`;

const LANDING = {
  'aannemers.html': 'Calculatie uitbesteden voor uw volgende aanbesteding.',
  'ontwikkelaars.html': 'Bouwkosten inzichtelijk maken vóór het volgende projectbesluit.',
  'publieke-opdrachtgevers.html': 'Onderbouwde bouwkosten voor publieke projecten.',
  'inschrijfbegroting.html': 'Inschrijfbegroting laten maken.',
  'hoeveelhedenstaat.html': 'Hoeveelheden laten uittrekken voor uw calculatie.',
  'begrotingscontrole.html': 'Begrotingscontrole voor inzicht in kosten en aannames.'
};
const EXTRA = ['voorbeeldcalculatie.html', 'aanvullendediensten.html'];
const PAGES = ['index.html', ...Object.keys(LANDING), ...EXTRA];
const html = Object.fromEntries(PAGES.map((p) => [p, read(p)]));
const index = html['index.html'];

const textOf = (source) => source
  .replace(/<head>[\s\S]*?<\/head>/, (head) => (head.match(/<title>[^<]*<\/title>|content="[^"]*"/g) || []).join(' '))
  .replace(/<svg[\s\S]*?<\/svg>/g, ' ')
  .replace(/<[^>]+>/g, ' ')
  .replace(/&[a-z]+;/g, ' ')
  .replace(/\s+/g, ' ');
const idsOf = (source) => [...source.matchAll(/\sid="([^"]+)"/g)].map((m) => m[1]);
const optionValues = (name) => {
  const select = index.match(new RegExp(`<select[^>]*name="${name}"[\\s\\S]*?</select>`));
  return select ? [...select[0].matchAll(/<option value="([^"]+)"/g)].map((m) => m[1]) : [];
};

const FORBIDDEN = /(gegarandeerd|garantie|garanderen|gecertificeerd|certific|foutloos|volledig automatisch|onafhankelijk|EU[- ]?hosting|niet (?:ge)?train|\bCUF\b|\bIBIS\b|goedkoopst|binnen \d+ (?:uur|dagen|werkdagen)|ISO ?\d|jaar ervaring|ld\+json|tevreden klanten|klanten zoals|\breviews?\b|sterren)/i;
const INFORMAL = /\b(je|jouw|jij|jullie)\b/i;

// ---------- Per pagina ----------
for (const page of PAGES) {
  const src = html[page];
  const ids = idsOf(src);
  const text = textOf(src);
  const tag = `[${page}]`;

  check(`${tag} lang="nl"`, /<html lang="nl">/.test(src));
  check(`${tag} robots noindex,nofollow`, src.includes('<meta name="robots" content="noindex,nofollow">'));
  check(`${tag} CSP-meta gelijk aan homepage`, src.includes(CSP));
  check(`${tag} geen canonical of sitemapverwijzing`, !/rel="canonical"|sitemap/i.test(src));
  check(`${tag} precies één <h1>`, [...src.matchAll(/<h1[\s>]/g)].length === 1);
  check(`${tag} previewbalk 'Bijgewerkte merkpreview'`, src.includes('<p class="review-bar">Merkpreview · voor beoordeling</p>'));
  check(`${tag} woordmerk CalcBuro`, src.includes('<span class="brand-word">Calc<span>Buro</span></span>') && !/calc<span>buro/.test(src) && !/Calcburo/.test(src));
  check(`${tag} e-mailadressen lowercase`, [...src.matchAll(/mailto:([^"?]+)/g)].every((m) => m[1] === m[1].toLowerCase()));
  check(`${tag} bedrijfsregel CalcBuro B.V. KvK 97494380`, src.includes('CalcBuro B.V. · KvK 97494380'));
  check(`${tag} telefoon 085 200 96 01`, src.includes('<a href="tel:+31852009601">085 200 96 01</a>'));
  check(`${tag} geen PDF-links`, !/href="[^"]*\.pdf"/i.test(src));
  check(`${tag} navigatie bevat Tarieven`, /class="nav-links"[\s\S]*#tarieven">Tarieven</.test(src));
  check(`${tag} gedeelde CSS en JS`, src.includes('href="assets/css/styles.css"') && src.includes('src="assets/js/main.js" defer'));
  check(`${tag} menuknop aria-expanded + aria-controls`, /class="menu-toggle"[^>]*aria-expanded="false"[^>]*aria-controls="hoofdmenu"/.test(src));
  check(`${tag} skip-link`, src.includes('class="skip-link" href="#inhoud"') && ids.includes('inhoud'));

  const external = [...src.matchAll(/\ssrc="(https?:)?\/\//g)].length + [...src.matchAll(/<link[^>]*\shref="(https?:)?\/\//g)].length;
  check(`${tag} geen externe assets`, external === 0, `${external}`);
  check(`${tag} geen inline style/script`, !/\sstyle="/.test(src) && !/<style[\s>]/.test(src) && !/<script>(?!<\/script>)[^<]/.test(src));
  check(`${tag} geen bestandsupload`, !/type="file"/.test(src));

  const dupes = ids.filter((id, i) => ids.indexOf(id) !== i);
  check(`${tag} id's uniek`, dupes.length === 0, dupes.join(', '));

  for (const m of src.matchAll(/(?:src|href)="(assets\/[^"]+)"/g)) check(`${tag} bestand bestaat: ${m[1]}`, existsSync(join(root, m[1])));
  for (const m of src.matchAll(/href="#([^"]*)"/g)) check(`${tag} anker #${m[1]}`, m[1] !== '' && ids.includes(m[1]));
  for (const m of src.matchAll(/href="([a-z-]+\.html)(\?[^"#]*)?(#[^"]*)?"/g)) {
    const [, file, query, hash] = m;
    const ok = existsSync(join(root, file)) && (!hash || idsOf(read(file)).includes(hash.slice(1)));
    check(`${tag} link ${m[0].slice(6, -1)}`, ok);
    if (query) {
      const params = new URLSearchParams(query.slice(1));
      for (const [key, value] of params) check(`${tag} voorinvulling ${key}=${value} is bestaande optie`, optionValues(key).includes(value));
    }
  }
  for (const m of src.matchAll(/aria-(?:controls|describedby|labelledby)="([^"]+)"/g)) {
    for (const ref of m[1].split(/\s+/)) check(`${tag} ARIA-referentie ${ref}`, ids.includes(ref));
  }
  for (const m of src.matchAll(/<label for="([^"]+)"/g)) check(`${tag} label ${m[1]}`, ids.includes(m[1]));

  for (const target of [...Object.keys(LANDING), "voorbeeldcalculatie.html"]) check(`${tag} footer linkt naar ${target}`, new RegExp(`class="foot-nav"[\\s\\S]*href="${target}"`).test(src));

  check(`${tag} geen verboden claims`, !FORBIDDEN.test(text), (text.match(FORBIDDEN) || [''])[0]);
  check(`${tag} consequent u/uw (geen je/jouw)`, !INFORMAL.test(text), (text.match(INFORMAL) || [''])[0]);
}

// ---------- Landingspagina's ----------
const titles = new Set();
const descriptions = new Set();
for (const page of PAGES) {
  titles.add((html[page].match(/<title>([^<]+)<\/title>/) || [])[1]);
  descriptions.add((html[page].match(/<meta name="description" content="([^"]+)"/) || [])[1]);
}
check('alle titels uniek', titles.size === PAGES.length);
check('alle beschrijvingen uniek', descriptions.size === PAGES.length);

for (const [page, h1] of Object.entries(LANDING)) {
  const src = html[page];
  const tag = `[${page}]`;
  check(`${tag} H1 conform briefing`, src.includes(`<h1 id="pagina-titel">${h1}</h1>`));
  check(`${tag} titel eindigt op | CalcBuro`, /<title>[^<]+ \| CalcBuro<\/title>/.test(src));
  check(`${tag} logo naar homepage`, src.includes('class="brand" href="index.html"'));
  check(`${tag} kruimelpad`, src.includes('aria-label="Kruimelpad"'));
  check(`${tag} ≥ 4 inhoudsblokken`, [...src.matchAll(/<section /g)].length >= 4);
  check(`${tag} 4 gebruikssituaties`, [...src.matchAll(/class="situation"/g)].length === 4);
  check(`${tag} wat u aanlevert en ontvangt`, src.includes('Wat u aanlevert') && src.includes('Wat u ontvangt'));
  check(`${tag} proces met 4 stappen`, [...src.matchAll(/class="step"/g)].length === 4);
  check(`${tag} 3 FAQ's`, [...src.matchAll(/<details>/g)].length === 3);
  check(`${tag} CTA naar index.html#aanvragen`, /href="index\.html\?[^"]+#aanvragen"/.test(src) && src.includes('href="index.html#aanvragen"'));
  const related = [...(src.match(/class="related-list"[\s\S]*?<\/ul>/) || [''])[0].matchAll(/href="([a-z-]+\.html)"/g)].map((m) => m[1]);
  check(`${tag} ≥ 2 gerelateerde landingspagina's`, related.filter((r) => r in LANDING && r !== page).length >= 2, related.join(', '));
  check(`${tag} footer markeert huidige pagina`, src.includes(`href="${page}" aria-current="page"`));
}

check('[aannemers.html] links naar inschrijfbegroting en hoeveelhedenstaat', /related-list[\s\S]*inschrijfbegroting\.html[\s\S]*hoeveelhedenstaat\.html/.test(html['aannemers.html']));
check('[aannemers.html] aannemer bepaalt inschrijfprijs en marges', html['aannemers.html'].includes('De definitieve inschrijfprijs, opslagen en marges bepaalt uw eigen team.'));
check('[ontwikkelaars.html] links naar begrotingscontrole en hoeveelhedenstaat', /related-list[\s\S]*begrotingscontrole\.html[\s\S]*hoeveelhedenstaat\.html/.test(html['ontwikkelaars.html']));
check('[ontwikkelaars.html] haalbaarheid buiten opdracht', html['ontwikkelaars.html'].includes('financieel haalbaarheidsonderzoek, constructieve berekeningen en duurzaamheidsberekeningen vallen buiten deze opdracht'));
check('[publieke-opdrachtgevers.html] CTA "Bespreek uw kostenopdracht"', html['publieke-opdrachtgevers.html'].includes('>Bespreek uw kostenopdracht <span'));
check('[publieke-opdrachtgevers.html] rol en belangenconflicten vóór start', html['publieke-opdrachtgevers.html'].includes('Vóór de start bespreken we uw rol en mogelijke belangenconflicten'));
check('[inschrijfbegroting.html] opslagen alleen voor zover afgesproken', html['inschrijfbegroting.html'].includes('Bouwplaatskosten en opslagen nemen we alleen op voor zover dat in de opdracht is afgesproken.'));
check('[inschrijfbegroting.html] gunning door aanbestedende dienst', html['inschrijfbegroting.html'].includes('Of een inschrijving wordt gegund, beslist de aanbestedende dienst.'));
check('[hoeveelhedenstaat.html] modelkwaliteit bepaalt bruikbaarheid', html['hoeveelhedenstaat.html'].includes('De kwaliteit van het model bepaalt hoe bruikbaar de hoeveelheden zijn.'));
check('[hoeveelhedenstaat.html] hoeveelheid ≠ kostprijs', html['hoeveelhedenstaat.html'].includes('Hoeveelheid is geen kostprijs'));
check('[begrotingscontrole.html] geen besparing of juridische goedkeuring', html['begrotingscontrole.html'].includes('Ze leidt niet vanzelf tot een lagere prijs en is geen juridische goedkeuring van de begroting.'));

// Geen bijna identieke pagina's: overlap van woorddrietallen in de hoofdinhoud
const trigrams = (page) => {
  const main = (html[page].match(/<main[\s\S]*<\/main>/) || [''])[0];
  const words = textOf(main).toLowerCase().split(' ').filter(Boolean);
  return new Set(words.slice(2).map((w, i) => `${words[i]} ${words[i + 1]} ${w}`));
};
const grams = Object.fromEntries(Object.keys(LANDING).map((p) => [p, trigrams(p)]));
let maxOverlap = 0;
let maxPair = '';
const landingPages = Object.keys(LANDING);
for (let i = 0; i < landingPages.length; i += 1) {
  for (let j = i + 1; j < landingPages.length; j += 1) {
    const a = grams[landingPages[i]];
    const b = grams[landingPages[j]];
    const shared = [...a].filter((g) => b.has(g)).length;
    const overlap = shared / Math.min(a.size, b.size);
    if (overlap > maxOverlap) { maxOverlap = overlap; maxPair = `${landingPages[i]} ↔ ${landingPages[j]}`; }
  }
}
check(`landingspagina's inhoudelijk verschillend (max. overlap ${(maxOverlap * 100).toFixed(0)}%, ${maxPair})`, maxOverlap < 0.3);

// ---------- Homepage ----------
check('[index] titel conform briefing', index.includes('<title>Calculatiebureau voor aanbestedingen | CalcBuro</title>'));
check('[index] beschrijving conform briefing', index.includes('content="Bouwcalculaties, hoeveelhedenstaten en begrotingscontroles voor aannemers, ontwikkelaars en publieke opdrachtgevers. Bespreek uw project met CalcBuro."'));
const homeCopy = [
  '<p class="eyebrow">Calculatiebureau voor aanbestedingen en bouwkosten</p>',
  'Sterke bouwprojecten beginnen met een <em>betrouwbare calculatie</em>',
  'CalcBuro ondersteunt aannemers, ontwikkelaars en publieke opdrachtgevers met bouwcalculaties, hoeveelheden en begrotingscontroles. U krijgt inzicht in de kostenopbouw en de uitgangspunten, zodat u uw inschrijving of bouwbesluit kunt onderbouwen.',
  '<a class="button" href="#aanvragen">Calculatie aanvragen',
  '<a class="text-link" href="#werkwijze">Onze werkwijze',
  'Eerst stemmen we scope, planning en vergoeding af.',
  'Inschrijven met een onderbouwde begroting',
  'Kosten afwegen tijdens de planontwikkeling',
  'Ramingen en begrotingen inhoudelijk laten toetsen',
  '<h3>Inschrijfbegroting</h3>',
  '<h3>Hoeveelhedenstaat</h3>',
  '<h3>Begrotingscontrole</h3>',
  'Van tekeningen en bestek naar een inzichtelijke kostenopbouw voor uw inschrijving. Scope en uitgangspunten spreken we vooraf af.',
  'Hoeveelheden per afgesproken bouwonderdeel, met eenheden en uitgangspunten voor verdere calculatie.',
  'Een bestaande begroting naast de projectstukken leggen en verschillen, aannames en aandachtspunten benoemen.',
  'Wat u vooraf wilt weten.',
  'Deze afspraken maken we vóór de start. Zo weet u welke uitwerking u ontvangt en waar nog afstemming nodig is.',
  'Inhoudsopzet · geen projectbegroting',
  'Niet alleen een bedrag. Ook het inzicht erachter.',
  'De gunning is een besluit van de opdrachtgever. Een AI-systeem neemt geen van deze beslissingen.',
  'Slim met data.<br>Zorgvuldig met uw project.',
  'Technologie helpt bij het verwerken.<br>Mensen beoordelen de samenhang.',
  'Kunt u een aanbesteding met een vaste deadline oppakken?',
  'Vermeld de inschrijfdatum en eventuele vragenrondes. We beoordelen de scope en beschikbare stukken en spreken af wat binnen de planning mogelijk is. Een aanvraag reserveert nog geen capaciteit.',
  'Werkt u voor aannemers én opdrachtgevers?',
  'Ja. Vermeld bij uw aanvraag vanuit welke rol u betrokken bent. Voor de start stemmen we de opdracht en mogelijke belangenconflicten af.',
  'Een volledige calculatie ontvangt u als PDF-rapport en doorrekenbaar Excel-bestand. Geef door met welk calculatiepakket u werkt',
  'Een volledige STABU-calculatie voor woningbouw, verbouw of renovatie kost € 1.500 – 2.500 excl. btw; tender- en aanvullend werk € 85 per uur excl. btw.',
  '<a class="text-link" href="voorbeeldcalculatie.html">Bekijk een voorbeeldcalculatie',
  'STABU-begroting, PDF-rapport en doorrekenbaar Excel.',
  'Calculatiewerk uitbesteden als uw eigen calculator vol zit.',
  'per project of als terugkerende calculatiecapaciteit',
  'Vooraf duidelijk wat het kost.',
  'Alle bedragen zijn exclusief 21% btw.',
  '<p class="tariff-price">€ 1.500 – 2.500 <span>excl. btw</span></p>',
  '<p class="tariff-price">Prijs op maat</p>',
  '<p class="tariff-price">€ 85 per uur <span>excl. btw</span></p>',
  '<p class="tariff-price">Op aanvraag</p>',
  'CalcBuro bouwt voort op SterkCalc.',
  'gaat verder onder de naam CalcBuro',
  'De vergoeding hangt af van de omvang van het project, de kwaliteit en volledigheid van de stukken, het gewenste detailniveau en de deadline. U ontvangt vooraf een voorstel',
  '<summary>Hulp bij uw aanvraag</summary>',
  '<label for="veld-rol">Uw rol</label>',
  '<label for="veld-uitwerking">Gewenste uitwerking</label>',
  '<label for="veld-planning">Gewenste oplevering / inschrijfdatum</label>',
  'Uw aanvraag opent als concept in uw eigen e-mailprogramma. Er wordt niets automatisch verstuurd. Stuur tekeningen of andere documenten pas mee na afstemming.',
  'Aanvraag opstellen'
];
for (const c of homeCopy) check(`[index] copy: ${c.replace(/<[^>]+>/g, ' ').trim().slice(0, 60)}`, index.includes(c));
check('[index] doelgroeplinks naar drie pagina\'s', ['aannemers', 'ontwikkelaars', 'publieke-opdrachtgevers'].every((p) => index.includes(`class="audience-link" href="${p}.html"`)));
check('[index] dienstlinks naar drie pagina\'s', ['inschrijfbegroting', 'hoeveelhedenstaat', 'begrotingscontrole'].every((p) => index.includes(`class="service-link" href="${p}.html"`)));
check('[index] 7 FAQ-items', [...index.matchAll(/<details>/g)].length === 7);
check('[index] hulp-uitklapper met calculatie@calcburo.nl', /class="help"[\s\S]*mailto:calculatie@calcburo\.nl[\s\S]*<\/details>/.test(index));
check('[index] rol-opties', JSON.stringify(optionValues('rol')) === JSON.stringify(['aannemer', 'ontwikkelaar', 'publieke-opdrachtgever', 'anders']));
check('[index] uitwerking-opties', JSON.stringify(optionValues('uitwerking')) === JSON.stringify(['inschrijfbegroting', 'hoeveelhedenstaat', 'begrotingscontrole', 'volledige-calculatie', 'extra-capaciteit', 'nog-afstemmen']));
check('[index] selectvelden optioneel', !/<select[^>]*required/.test(index));
check('[index] verplichte velden ongewijzigd (naam, e-mail, project)', [...index.matchAll(/\srequired[\s>]/g)].length === 3);
check('[index] mailto calculatie@ en info@', index.includes('href="mailto:calculatie@calcburo.nl"') && index.includes('href="mailto:info@calcburo.nl"'));
check('[index] formulier alleen mailto', index.includes('action="mailto:calculatie@calcburo.nl"'));
check('[index] geen chatbot of ontvangstbevestiging', !/chat|ontvangen wij|is verzonden|bedankt voor uw aanvraag/i.test(textOf(index)));
check('[index] hero-beeld lokaal met afmetingen', index.includes('src="assets/img/constructiemodel.png" width="3151" height="2633"'));
check('[index] hero-bijschrift en bronvermelding', index.includes('Voorbeeld van een constructiemodel · geen CalcBuro-project') && index.includes('Patmacgin, Wikimedia Commons</a>') && index.includes('rel="license">CC BY-SA 4.0</a>'));
check('ASSET-LICENSES.md aanwezig', existsSync(join(root, 'ASSET-LICENSES.md')));
check('SEO-LAUNCHPLAN.md aanwezig', existsSync(join(root, 'SEO-LAUNCHPLAN.md')));
check('geen sitemap.xml of robots.txt in preview', !existsSync(join(root, 'sitemap.xml')) && !existsSync(join(root, 'robots.txt')));

// ---------- Voorbeeldcalculatie ----------
const vb = html['voorbeeldcalculatie.html'];
check('[voorbeeld] één <h1> en titel', vb.includes('<h1 id="pagina-titel">Zo bouwen wij een calculatie op.</h1>'));
check('[voorbeeld] expliciet geen klantreferentie/aanbieding', vb.includes('Geen klantopdracht, geen klantreferentie en geen referentieproject.') && vb.includes('geen aanbieding'));
check('[voorbeeld] tabel met 9 posten STABU 33', [...vb.matchAll(/<th scope="row">33\.0\d<\/th>/g)].length === 9);
const vbAmounts = ['€&nbsp;13.796', '€&nbsp;9.818', '€&nbsp;2.808', '€&nbsp;3.413', '€&nbsp;16.975', '€&nbsp;17.588', '€&nbsp;14.088', '€&nbsp;3.410', '€&nbsp;4.512',
  '€&nbsp;23.135', '€&nbsp;46.135', '€&nbsp;17.138', '€&nbsp;86.408', '420,6', '€&nbsp;1.787.581', '€&nbsp;82.556', '€&nbsp;89.379', '€&nbsp;98.317',
  '€&nbsp;26.814', '€&nbsp;53.627', '€&nbsp;2.055.718', '€&nbsp;146.837 / €&nbsp;2.215', 'prijspeil Q4 2026'];
for (const a of vbAmounts) check(`[voorbeeld] bedrag uit bron: ${a.replace(/&nbsp;/g, ' ')}`, vb.includes(a));
check('[voorbeeld] 34 hoofdstukken', [...vb.matchAll(/<div><dt>\d\d [^<]+<\/dt><dd>€&nbsp;[\d.]+<\/dd><\/div>/g)].length === 34);
const chapterSum = [...(vb.match(/class="chapter-list"[\s\S]*?<\/dl>/) || [''])[0].matchAll(/€&nbsp;([\d.]+)/g)].reduce((t, m) => t + Number(m[1].replace(/\./g, '')), 0);
check(`[voorbeeld] som hoofdstukken = directe kosten (${chapterSum})`, chapterSum === 1787581);
check('[voorbeeld] tabel scrollbaar en toetsenbordbereikbaar', vb.includes('class="table-scroll" role="region" aria-labelledby="detail-titel" tabindex="0"'));

// Verwijderde diensten mogen nergens meer als aanbod verschijnen.
for (const [page, source] of Object.entries(html)) check(`[${page}] verwijderd aanbod afwezig`, !/snelcalculatie|bouwdepot|inspectie/i.test(source));
check('[voorbeeld] footer markeert huidige pagina', vb.includes('href="voorbeeldcalculatie.html" aria-current="page"'));

// ---------- JavaScript ----------
const syntax = spawnSync(process.execPath, ['--check', join(root, 'assets/js/main.js')], { encoding: 'utf8' });
check('JS-syntax (node --check)', syntax.status === 0, syntax.stderr);
check('statustekst in u-vorm', js.includes("'Uw e-mailconcept is voorbereid. Controleer en verstuur het in uw e-mailprogramma. Werkt dat niet? Mail naar calculatie@calcburo.nl.'"));
check('foutmeldingen in u-vorm', !INFORMAL.test(js.match(/'[^']*'/g).join(' ')));
check('mailto bevat rol en uitwerking', js.includes("'Rol: ' + choiceOf(form, 'rol')") && js.includes("'Gewenste uitwerking: ' + choiceOf(form, 'uitwerking')"));
check('mailto planninglabel bijgewerkt', js.includes("'Gewenste oplevering / inschrijfdatum: ' + valueOf(form, 'planning')"));
check('voorinvulling alleen bekende opties', /option\.value !== '' && option\.value === wanted/.test(js) && js.includes("PREFILL_FIELDS = ['rol', 'uitwerking']"));
check('mailto gebruikt encodeURIComponent', /encodeURIComponent\(MAIL_SUBJECT\)/.test(js) && /encodeURIComponent\(body\)/.test(js));
check('geen fetch/XHR/storage/cookies/innerHTML', !/fetch\(|XMLHttpRequest|localStorage|sessionStorage|document\.cookie|sendBeacon|innerHTML/.test(js));
check('geen externe URL\'s in JS/CSS', !/https?:\/\//.test(js) && !/url\(\s*["']?https?:|@import/.test(css));

// ---------- CSS / toegankelijkheid ----------
check('prefers-reduced-motion aanwezig', css.includes('prefers-reduced-motion: reduce'));
check(':focus-visible stijl aanwezig', css.includes(':focus-visible'));
check('selectvelden gestyled en met focus', css.includes('.field select,') && css.includes('.field select:focus-visible'));
check('knop in contactband behoudt witte tekst', css.includes('.contact a:not(.button)'));
check('hero-afbeelding volledig (geen object-fit/uitsnede)', !/\.hero-art img\s*\{[^}]*(object-fit|aspect-ratio|clip-path)/.test(css));
check('geen verouderde .trust-stijlen', !css.includes('.trust'));

const lum = (hex) => {
  const c = hex.match(/\w\w/g).map((h) => parseInt(h, 16) / 255).map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
  return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
};
const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };
const pairs = [
  ['ink op paper', '#183344', '#f7f6f1'],
  ['muted op paper', '#4c5e66', '#f7f6f1'],
  ['muted op sand', '#4c5e66', '#e9dfcd'],
  ['muted op sage-soft', '#4c5e66', '#e6ebe3'],
  ['petrol op paper', '#305f60', '#f7f6f1'],
  ['petrol op sand', '#305f60', '#e9dfcd'],
  ['petrol op sage-soft', '#305f60', '#e6ebe3'],
  ['wit op petrol (knop)', '#ffffff', '#305f60'],
  ['tech-tekst op ink', '#d3dedf', '#183344'],
  ['tech-eyebrow op ink', '#c1d5c8', '#183344'],
  ['foutkleur op sage-soft', '#9b2c1f', '#e6ebe3'],
  ['ink op sand (reviewbalk)', '#183344', '#e9dfcd']
];
for (const [n, f, b] of pairs) { const r = ratio(f, b); check(`contrast ${n} ${r.toFixed(2)}:1`, r >= 4.5); }
for (const [n, f, b] of [['focusring op paper', '#8a5f2c', '#f7f6f1'], ['focusring op ink', '#e9c48f', '#183344']]) {
  const r = ratio(f, b); check(`contrast ${n} ${r.toFixed(2)}:1 (≥3)`, r >= 3);
}

const failed = results.filter((r) => !r.ok);
for (const r of results) console.log(`${r.ok ? 'OK  ' : 'FAIL'} ${r.name}${r.detail && !r.ok ? ` — ${r.detail}` : ''}`);
console.log(`\n${results.length - failed.length}/${results.length} geslaagd`);
process.exit(failed.length ? 1 : 0);
