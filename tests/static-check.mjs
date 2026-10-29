// Statische controles voor de Calcburo-preview. Uitvoeren: node tests/static-check.mjs
import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const html = readFileSync(join(root, 'index.html'), 'utf8');
const css = readFileSync(join(root, 'assets/css/styles.css'), 'utf8');
const js = readFileSync(join(root, 'assets/js/main.js'), 'utf8');

const results = [];
const check = (name, ok, detail = '') => results.push({ name, ok: Boolean(ok), detail });

// Metadata
check('lang="nl"', /<html lang="nl">/.test(html));
check('robots noindex,nofollow', html.includes('<meta name="robots" content="noindex,nofollow">'));
check('titel conform specificatie', html.includes('<title>Calcburo | Bouwkundige calculatie met inzicht</title>'));
check('description conform specificatie', html.includes('content="Inzicht in hoeveelheden en bouwkosten. Calcburo combineert digitale hulpmiddelen met menselijke bouwkundige controle. Bespreek je calculatievraag."'));
check('viewport meta', html.includes('name="viewport"'));

// Externe afhankelijkheden (geladen bronnen; gewone hyperlinks zoals de bronvermelding tellen niet mee)
const external = [...html.matchAll(/\ssrc="(https?:)?\/\//g)].length
  + [...html.matchAll(/<link[^>]*\shref="(https?:)?\/\//g)].length
  + [...css.matchAll(/url\(\s*["']?https?:/g)].length
  + [...css.matchAll(/@import/g)].length
  + [...js.matchAll(/https?:\/\//g)].length;
check('geen externe assets of URL\'s', external === 0, `${external} gevonden`);
check('geen inline <style>/<script>-blokken', !/<style[\s>]/.test(html) && !/<script>(?!<\/script>)[^<]/.test(html));
check('geen inline style-attributen (CSP)', !/\sstyle="/.test(html));
check('geen fetch/XHR/storage/cookies in JS', !/fetch\(|XMLHttpRequest|localStorage|sessionStorage|document\.cookie|sendBeacon/.test(js));

// Lokale bestanden bestaan
for (const m of html.matchAll(/(?:src|href)="(assets\/[^"]+)"/g)) {
  check(`bestand bestaat: ${m[1]}`, existsSync(join(root, m[1])));
}

// Unieke id's
const ids = [...html.matchAll(/\sid="([^"]+)"/g)].map((m) => m[1]);
const dupes = ids.filter((id, i) => ids.indexOf(id) !== i);
check('id\'s uniek', dupes.length === 0, dupes.join(', '));

// Interne ankers en ARIA-referenties
for (const m of html.matchAll(/href="#([^"]*)"/g)) {
  check(`anker #${m[1]} bestaat`, m[1] !== '' && ids.includes(m[1]));
}
for (const m of html.matchAll(/aria-(?:controls|describedby|labelledby)="([^"]+)"/g)) {
  for (const ref of m[1].split(/\s+/)) check(`ARIA-referentie ${ref} bestaat`, ids.includes(ref));
}
for (const m of html.matchAll(/<label for="([^"]+)"/g)) {
  check(`label for=${m[1]} gekoppeld`, ids.includes(m[1]));
}

// Velden: elk input/textarea heeft een label
for (const m of html.matchAll(/<(?:input|textarea)[^>]*\sid="([^"]+)"/g)) {
  check(`veld ${m[1]} heeft label`, html.includes(`for="${m[1]}"`));
}

// CTA's
const primaryCtas = [...html.matchAll(/href="#aanvragen"/g)].length;
check('primaire CTA\'s naar #aanvragen (nav, hero, footer)', primaryCtas >= 3, `${primaryCtas}`);
check('secundaire CTA naar #werkwijze', html.includes('class="text-link" href="#werkwijze"'));

// Mailadressen
check('mailto calculatie@calcburo.nl aanwezig', html.includes('href="mailto:calculatie@calcburo.nl"'));
check('mailto info@calcburo.nl aanwezig', html.includes('href="mailto:info@calcburo.nl"'));
check('mailto gebruikt encodeURIComponent', /encodeURIComponent\(MAIL_SUBJECT\)/.test(js) && /encodeURIComponent\(body\)/.test(js));

// Copy-sleutels uit specificatie
const copy = [
  'Sterke bouwprojecten beginnen met een <em>betrouwbare calculatie</em>',
  'Een stevige basis voor je volgende stap.',
  'Niet alleen een bedrag. Ook het inzicht erachter.',
  'Van eerste vraag naar een heldere calculatie.',
  'Slim met data.<br>Zorgvuldig met jouw project.',
  'Technologie helpt bij het verwerken.<br>Mensen beoordelen de samenhang.',
  'Vertel ons wat je wilt bouwen.',
  'Je aanvraag opent als concept in je eigen e-mailprogramma. Er wordt niets automatisch verstuurd. Stuur tekeningen of andere documenten pas mee na afstemming.',
  'Bouwkundige calculatie met inzicht.',
  'Veelgestelde vragen',
  'Aanvraag opstellen'
];
for (const c of copy) check(`copy: ${c.replace(/<[^>]+>/g, ' ').slice(0, 50)}`, html.includes(c));
check('statustekst conform specificatie', js.includes('Je e-mailconcept is voorbereid. Controleer en verstuur het in je e-mailprogramma. Werkt dat niet? Mail naar calculatie@calcburo.nl.'));
check('5 FAQ-items als <details>', [...html.matchAll(/<details>/g)].length === 5);

// Verboden claims
const forbidden = /(gegarandeerd|garantie|gecertificeerd|foutloos|volledig automatisch|ISO ?\d|KvK|\+31|tel:|€)/i;
const visibleText = html.replace(/<[^>]+>/g, ' ');
check('geen verboden claims/fictieve gegevens', !forbidden.test(visibleText), (visibleText.match(forbidden) || [''])[0]);
// Hero-afbeelding en bronvermelding
check('hero-foto lokaal met afmetingen', html.includes('src="assets/img/bim-bibliotheek.jpg" width="5000" height="2650"'));
check('hero-foto alt-tekst', html.includes('alt="BIM-model van een bibliotheek met gebouwonderdelen en terreininrichting"'));
check('hero-figcaption zichtbaar', html.includes('Voorbeeld van een BIM-model · geen Calcburo-project'));
check('bronvermelding Archdraw, Wikimedia Commons', html.includes('href="https://commons.wikimedia.org/wiki/File:Architectural_BIM_3D_Modeling.jpg">Archdraw, Wikimedia Commons</a>'));
check('licentielink CC BY-SA 4.0', html.includes('href="https://creativecommons.org/licenses/by-sa/4.0/" rel="license">CC BY-SA 4.0</a>'));
check('oude lijnillustratie verwijderd', !html.includes('art-titel') && !html.includes('ILLUSTRATIE · GEEN KLANTPROJECT'));
check('ASSET-LICENSES.md aanwezig', existsSync(join(root, 'ASSET-LICENSES.md')));
check('geen bestandsupload', !/type="file"/.test(html));

// Toegankelijkheid / beweging
check('prefers-reduced-motion aanwezig', css.includes('prefers-reduced-motion: reduce'));
check(':focus-visible stijl aanwezig', css.includes(':focus-visible'));
check('menuknop aria-expanded + aria-controls', /class="menu-toggle"[^>]*aria-expanded="false"[^>]*aria-controls="hoofdmenu"/.test(html));
check('skip-link aanwezig', html.includes('class="skip-link" href="#inhoud"'));

// Contrast (WCAG AA 4.5:1 tekst)
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
