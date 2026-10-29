// Assetversies: de opgeleverde bestanden zijn actueel, en het script herkent en herstelt een verouderde versie.
//   node --test tests/
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, cpSync, readFileSync, writeFileSync, appendFileSync, rmSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const script = join(root, 'tools/asset-versies.mjs');
const draai = (...args) => spawnSync(process.execPath, [script, ...args], { encoding: 'utf8' });
const hash = (pad) => createHash('sha256').update(readFileSync(pad)).digest('hex').slice(0, 10);

test('opgeleverde HTML en Weergave.php verwijzen naar de actuele inhoudshash', () => {
  const r = draai('--check');
  assert.equal(r.status, 0, r.stderr);
  const css = hash(join(root, 'httpdocs/assets/css/styles.css'));
  assert.match(readFileSync(join(root, 'httpdocs/index.html'), 'utf8'), new RegExp(`assets/css/styles\\.css\\?v=${css}"`));
  assert.match(readFileSync(join(root, 'calcburo-app/src/Weergave.php'), 'utf8'), new RegExp(`/assets/css/styles\\.css\\?v=${css}"`));
  // Verwijzingen binnen PHP-strings (\") worden ook bijgewerkt.
  const weergave = readFileSync(join(root, 'calcburo-app/src/Weergave.php'), 'utf8');
  for (const js of ['aanvraag.js', 'toestemming.js']) {
    assert.ok(weergave.includes(`/assets/js/${js}?v=${hash(join(root, 'httpdocs/assets/js', js))}\\"`), js + ' in Weergave.php actueel');
  }
});

test('gewijzigd asset: --check faalt, bijwerken herstelt alle verwijzingen (met en zonder voorloop-/)', () => {
  const tmp = mkdtempSync(join(root, 'tests/.tmp-assets-'));
  try {
    cpSync(join(root, 'httpdocs'), join(tmp, 'httpdocs'), { recursive: true, filter: (p) => !p.includes('/img/') });
    mkdirSync(join(tmp, 'calcburo-app/src'), { recursive: true });
    cpSync(join(root, 'calcburo-app/src/Weergave.php'), join(tmp, 'calcburo-app/src/Weergave.php'));
    appendFileSync(join(tmp, 'httpdocs/assets/css/aanvraag.css'), '\n/* nieuwe versie */\n');
    const nieuw = hash(join(tmp, 'httpdocs/assets/css/aanvraag.css'));

    const check = draai('--check', '--root', tmp);
    assert.equal(check.status, 1);
    assert.match(check.stderr, /aanvraag\.css/);

    assert.equal(draai('--root', tmp).status, 0);
    assert.equal(draai('--check', '--root', tmp).status, 0);
    assert.match(readFileSync(join(tmp, 'httpdocs/index.html'), 'utf8'), new RegExp(`href="assets/css/aanvraag\\.css\\?v=${nieuw}"`));
    assert.match(readFileSync(join(tmp, 'calcburo-app/src/Weergave.php'), 'utf8'), new RegExp(`href="/assets/css/aanvraag\\.css\\?v=${nieuw}"`));

    writeFileSync(join(tmp, 'httpdocs/extra.html'), '<link rel="stylesheet" href="assets/css/bestaat-niet.css">');
    assert.equal(draai('--root', tmp).status, 1, 'verwijzing naar onbekend asset wordt gemeld');
  } finally {
    rmSync(tmp, { recursive: true, force: true });
  }
});
