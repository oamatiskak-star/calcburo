// Frontend- en statische controles. Geen afhankelijkheden, geen netwerk.
//   node --test tests/
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const web = join(root, 'httpdocs');
const require = createRequire(import.meta.url);
const A = require(join(web, 'assets/js/aanvraag.js'));
const grenzen = { maxBestanden: 10, maxBestand: 20 * 1024 * 1024, maxTotaal: 50 * 1024 * 1024 };
const MB = 1024 * 1024;
const html = readdirSync(web).filter((f) => f.endsWith('.html'));
const lees = (f) => readFileSync(join(web, f), 'utf8');

test('bestandscontrole vooraf: types, aantallen en groottes', () => {
  assert.equal(A.bestandFout([{ name: 'a.PDF', size: 10 }, { name: 'm.ifc', size: 10 }, { name: 'b.xlsx', size: 10 }], grenzen), '');
  assert.match(A.bestandFout([{ name: 'x.exe', size: 10 }], grenzen), /niet-toegestaan/);
  assert.match(A.bestandFout([{ name: 'x.docm', size: 10 }], grenzen), /niet-toegestaan/);
  assert.match(A.bestandFout([{ name: 'leeg.pdf', size: 0 }], grenzen), /leeg/);
  assert.match(A.bestandFout(Array.from({ length: 11 }, (_, i) => ({ name: i + '.pdf', size: 1 })), grenzen), /maximaal 10/);
  assert.match(A.bestandFout([{ name: 'g.pdf', size: 20 * MB + 1 }], grenzen), /20 MB/);
  assert.equal(A.bestandFout([{ name: 'g.pdf', size: 20 * MB }], grenzen), '');
  assert.match(A.bestandFout([1, 2, 3].map((i) => ({ name: i + '.pdf', size: 18 * MB })), grenzen), /50 MB/);
});

test('succes alleen bij bevestigde opslag met geldige referentie', () => {
  const goed = { ok: true, opgeslagen: true, referentie: 'CB-ABCDEFGH', documenten: 2, mail: { intern: 'geaccepteerd', bevestiging: 'geaccepteerd' } };
  assert.equal(A.klassificeer(201, goed).soort, 'succes');
  assert.equal(A.klassificeer(200, { ...goed, duplicaat: true }).soort, 'succes');
  assert.equal(A.klassificeer(201, { ...goed, opgeslagen: false }).soort, 'fout');
  assert.equal(A.klassificeer(201, { ...goed, ok: 'true' }).soort, 'fout');
  assert.equal(A.klassificeer(201, { ...goed, referentie: 'CB-ABC' }).soort, 'fout');
  assert.equal(A.klassificeer(201, { ...goed, referentie: '<img src=x>' }).soort, 'fout');
  assert.equal(A.klassificeer(500, goed).soort, 'fout');
  assert.equal(A.klassificeer(200, null).soort, 'fout');
  const net = A.klassificeer(0, null);
  assert.equal(net.code, 'netwerk');
  assert.match(net.bericht, /geen ontvangst bevestigd/);
  const f = A.klassificeer(429, { ok: false, opgeslagen: false, fout: { code: 'limiet', bericht: 'Te veel.' } });
  assert.deepEqual([f.soort, f.code, f.bericht], ['fout', 'limiet', 'Te veel.']);
});

test('mailtekst in JS is gelijk aan PHP en belooft geen aflevering', () => {
  const combis = [['geaccepteerd', 'geaccepteerd'], ['mislukt', 'geaccepteerd'], ['geaccepteerd', 'mislukt'], ['uitgeschakeld', 'uitgeschakeld'], ['in_behandeling', 'in_behandeling']];
  const php = execFileSync(join(root, 'tools/php.sh'), ['-r', `require '${join(root, 'calcburo-app/bootstrap.php')}'; foreach (json_decode($argv[1], true) as [$i, $b]) echo CalcBuro\\Weergave::mailTekst($i, $b), "\\n";`, JSON.stringify(combis)], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim().split('\n');
  combis.forEach(([i, b], n) => assert.equal(A.mailTekst(i, b), php[n], `${i}/${b}`));
  assert.equal(A.mailTekst('geaccepteerd', 'geaccepteerd'), 'De ontvangstbevestiging is verstuurd. Controleer eventueel uw map met ongewenste e-mail en bewaar dit referentienummer.');
  assert.match(A.mailTekst('geaccepteerd', 'mislukt'), /ontvangen, maar de ontvangstbevestiging per e-mail kon niet worden verstuurd/);
  assert.match(A.mailTekst('mislukt', 'geaccepteerd'), /calculatie@calcburo\.nl/);
  for (const [i, b] of combis) assert.doesNotMatch(A.mailTekst(i, b), /mailserver|garanderen|opgeslagen/);
});

test('alle pagina’s: privacylink in de footer, geen mailto-formulier, CTA naar aanvraag.php', () => {
  assert.ok(html.length >= 10, 'verwacht ten minste 10 HTML-pagina’s');
  for (const f of html) {
    const s = lees(f);
    const footer = s.slice(s.indexOf('<footer'));
    assert.match(footer, /href="privacy\.html"/, f + ': privacylink');
    assert.doesNotMatch(s, /action="mailto:/, f + ': mailto-formulier');
    assert.doesNotMatch(s, /#aanvragen"|index\.html\?(rol|uitwerking)=/, f + ': oude aanvraaglink');
    assert.match(s, /href="aanvraag\.php/, f + ': CTA');
    assert.doesNotMatch(s, /supabase|resend/i, f + ': oude backend');
    assert.match(s, /connect-src 'none'/, f + ': statische pagina maakt geen verbindingen');
  }
});

test('homepage: aanvraagsectie behoudt huisstijl en noemt betaling per factuur', () => {
  const s = lees('index.html');
  assert.match(s, /id="aanvragen"/);
  assert.match(s, /class="request-cta"/);
  assert.match(s, /Betaling per factuur, volgens de afspraken in uw voorstel\./);
  assert.doesNotMatch(s, /afgerekend|betaalfunctie|Jortt|boekhoud/);
  assert.match(s, /assets\/css\/aanvraag\.css/);
});

test('privacyverklaring: verantwoordelijke, verwerkers, bewaartermijnen, geen open punten', () => {
  const s = lees('privacy.html');
  assert.match(s, /Modiwerijo Financial Management BV, handelend onder de naam CalcBuro B\.V\./);
  assert.match(s, /Cloud86/);
  assert.match(s, /Jortt/);
  assert.match(s, /90 dagen; daarna verwijderd bij de dagelijkse opschoning/);
  assert.match(s, /handmatig uit onze mailbox/);
  assert.doesNotMatch(s, /bezoekadres/);
  assert.match(s, /Het aanvraagformulier gebruikt geen AI\./);
  assert.match(s, /stemmen wij vooraf met u af/);
  assert.doesNotMatch(s, /OpenAI|ChatGPT|Anthropic|Claude|Gemini|Copilot|Azure|Google|Amazon|doorgifte|buiten de (EU|EER)/i);
  assert.match(s, /7 jaar/);
  assert.doesNotMatch(s, /\[\[OPEN|ISO 27001|certific/i);
  assert.doesNotMatch(s, /Supabase|Resend/);
  const sitemap = readFileSync(join(web, 'sitemap.xml'), 'utf8');
  assert.match(sitemap, /privacy\.html/);
});

test('main.js bevat geen mailto-logica meer; aanvraag.js maakt alleen same-origin verzoeken', () => {
  assert.doesNotMatch(readFileSync(join(web, 'assets/js/main.js'), 'utf8'), /mailto|initForm/);
  const js = readFileSync(join(web, 'assets/js/aanvraag.js'), 'utf8');
  assert.doesNotMatch(js, /innerHTML|https?:\/\//);
  assert.match(js, /form\.getAttribute\('action'\)/);
});

test('hosting: HTTPS-redirect intact, .user.ini afgeschermd, uploadgrenzen, api alleen eindpunt', () => {
  const ht = lees('.htaccess');
  assert.match(ht, /RewriteCond %\{HTTPS\} !=on/);
  assert.match(ht, /<Files "\.user\.ini">\s*Require all denied/);
  const ini = lees('.user.ini');
  assert.match(ini, /upload_max_filesize = 20M/);
  assert.match(ini, /post_max_size = 52M/);
  assert.match(ini, /max_file_uploads = 20/);
  assert.match(readFileSync(join(web, 'api/.htaccess'), 'utf8'), /aanvraag\\\.php/);
  assert.deepEqual(readdirSync(join(web, 'api')).sort(), ['.htaccess', 'aanvraag.php']);
});

test('geen applicatiecode, config of opslag in httpdocs; geen geheimen in config', () => {
  const alles = execFileSync('find', [web, '-type', 'f'], { encoding: 'utf8' }).trim().split('\n');
  const php = alles.filter((f) => f.endsWith('.php')).map((f) => f.slice(web.length));
  assert.deepEqual(php.sort(), ['/aanvraag.php', '/api/aanvraag.php']);
  assert.ok(!alles.some((f) => /config|geheim|\.key$|\.json$/.test(f.slice(web.length))));
  const cfg = readFileSync(join(root, 'calcburo-app/config.php'), 'utf8');
  assert.match(cfg, /'modus' => 'uit'/);
  assert.doesNotMatch(cfg, /password|wachtwoord'\s*=>|api_key|smtp/i);
});

test('alle pagina’s: Open Graph met feitelijke titel, beschrijving, canonical en de stockfoto', () => {
  for (const f of html) {
    const s = lees(f);
    const meta = (eig) => (s.match(new RegExp(`<meta (?:property|name)="${eig}" content="([^"]*)">`)) || [])[1];
    const canonical = (s.match(/<link rel="canonical" href="([^"]+)">/) || [])[1];
    assert.equal(meta('og:url'), canonical, f + ': og:url = canonical');
    assert.equal(meta('og:title'), (s.match(/<title>([^<]+)<\/title>/) || [])[1], f + ': og:title');
    assert.equal(meta('og:description'), (s.match(/<meta name="description" content="([^"]+)">/) || [])[1], f + ': og:description');
    assert.equal(meta('og:image'), 'https://calcburo.nl/assets/img/bouwkundige-controle.jpg', f + ': og:image');
    assert.equal(meta('twitter:card'), 'summary_large_image', f);
  }
  assert.ok(readFileSync(join(web, 'assets/img/bouwkundige-controle.jpg')).length > 10000, 'stockfoto aanwezig');
});

test('main.js: processtappen op mobiel ingeklapt, op desktop open', () => {
  const js = readFileSync(join(web, 'assets/js/main.js'), 'utf8');
  assert.match(js, /details\[data-mobiel-dicht\]/);
  assert.match(js, /blok\.open = !stack\.matches/);
  const css = readFileSync(join(web, 'assets/css/aanvraag.css'), 'utf8');
  assert.match(css, /"intro"\s*"formulier"\s*"info"/, 'mobiel: formulier vóór de stappen');
  assert.match(css, /"intro formulier"\s*"info formulier"/, 'desktop: stappen links, formulier rechts');
});
