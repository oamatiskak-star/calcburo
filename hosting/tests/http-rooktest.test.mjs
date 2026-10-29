// Rooktest van httpdocs/aanvraag.php en httpdocs/api/aanvraag.php via de echte bootstrap en config.
//   node --test tests/http-rooktest.test.mjs
// PHP: lokale php, anders PHP 8 in WebAssembly (tools/php.sh). Geen netwerkverzoeken naar calcburo.nl.
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, rmSync, readFileSync, existsSync, readdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
let tmp;
const cfg = {};

function phpArray(obj) {
  return 'array(' + Object.entries(obj).map(([k, v]) =>
    `'${k}' => ` + (typeof v === 'object' ? phpArray(v) : `'${String(v).replace(/'/g, "\\'")}'`)).join(', ') + ')';
}

function draai(scenario, config) {
  return execFileSync(join(root, 'tools/php.sh'), ['tests/http-rooktest.php', 'scenario', scenario, config], {
    cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], timeout: 180000,
  });
}

before(() => {
  tmp = mkdtempSync(join(root, 'tests/.tmp-http-'));
  for (const [naam, c] of Object.entries({
    uit: { modus: 'uit', data_dir: join(tmp, 'data') },
    test: { modus: 'test', test_sleutel: 's'.repeat(32), data_dir: join(tmp, 'data'), mail: { modus: 'uit' } },
  })) {
    cfg[naam] = join(tmp, naam + '.php');
    writeFileSync(cfg[naam], '<?php return ' + phpArray(c) + ';');
  }
});
after(() => rmSync(tmp, { recursive: true, force: true }));

test('pagina in modus uit: e-mailroute, geen formulier', () => {
  const html = draai('pagina', cfg.uit);
  assert.match(html, /niet beschikbaar/);
  assert.doesNotMatch(html, /<form/);
});

test('pagina in testmodus met sleutel: formulier, CSRF-token, voorinvulling, geheim buiten httpdocs', () => {
  const html = draai('pagina-test', cfg.test);
  assert.match(html, /id="aanvraagformulier"/);
  assert.match(html, /name="csrf" value="[0-9a-f]{64}"/);
  assert.match(html, /name="aanvraag_id" value="[0-9a-f-]{36}"/);
  assert.match(html, /value="hoeveelhedenstaat" selected/);
  const intro = html.indexOf('class="intake-intro"');
  const formulier = html.indexOf('id="aanvraagformulier"');
  const stappen = html.indexOf('class="intake-proces"');
  assert.ok(intro > 0 && intro < formulier && formulier < stappen, 'volgorde: intro, formulier, processtappen');
  assert.match(html, /<details class="intake-proces" open data-mobiel-dicht>/);
  assert.match(html, /Betaling per factuur, volgens de afspraken in uw voorstel\./);
  assert.doesNotMatch(html, /type="checkbox"/, 'geen verplichte privacycheckbox');
  assert.match(html, /href="\/privacy\.html">privacyverklaring<\/a>/);
  assert.doesNotMatch(html, /reserveert nog geen capaciteit|mailserver|afgerekend|Jortt|boekhoud|opgeslagen<\/h2>/);
  assert.match(html, /<h2 id="ontvangst-titel">Uw aanvraag is ontvangen<\/h2>/);
  assert.match(html, /<meta property="og:image" content="https:\/\/calcburo\.nl\/assets\/img\/bouwkundige-controle\.jpg">/);
  assert.match(html, /<meta name="robots" content="index,follow">/);
  assert.match(html, /<script src="\/assets\/js\/aanvraag\.js\?v=[a-f0-9]{10}" defer><\/script>/);
  const geheim = join(tmp, 'data/geheim.key');
  assert.ok(existsSync(geheim));
  assert.equal(readFileSync(geheim, 'utf8').trim().length, 64);
});

test('API: GET 405, POST in modus uit niet_actief, POST zonder sessie geweigerd', () => {
  assert.equal(JSON.parse(draai('api-get', cfg.test)).fout.code, 'methode');
  const uit = JSON.parse(draai('api-post', cfg.uit));
  assert.equal(uit.fout.code, 'niet_actief');
  assert.equal(uit.opgeslagen, false);
  assert.equal(JSON.parse(draai('api-post', cfg.test)).fout.code, 'sessie');
  const aanvragen = join(tmp, 'data/aanvragen');
  assert.ok(!existsSync(aanvragen) || readdirSync(aanvragen).length === 0, 'geen aanvraag opgeslagen');
});
