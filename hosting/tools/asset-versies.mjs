#!/usr/bin/env node
// Zet ?v=<inhoudshash> achter CSS/JS-verwijzingen, zodat browsers na een publicatie direct de nieuwe
// versie laden (de server cachet JS/CSS 7 dagen; HTML revalideert, PHP is no-store).
// Hash = eerste 10 hex-tekens van SHA-256 van het bestand.
//
//   node tools/asset-versies.mjs            werk verwijzingen bij in httpdocs/*.html en Weergave.php
//   node tools/asset-versies.mjs --check    alleen controleren; exit 1 als een verwijzing verouderd is
//   --root <map>                            andere hostingmap (standaard: map boven tools/)
import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync, readdirSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const args = process.argv.slice(2);
const alleenControleren = args.includes('--check');
const rootIndex = args.indexOf('--root');
const root = rootIndex >= 0 ? args[rootIndex + 1] : dirname(dirname(fileURLToPath(import.meta.url)));
const web = join(root, 'httpdocs');

const hashes = {};
for (const soort of ['css', 'js']) {
  const map = join(web, 'assets', soort);
  for (const naam of existsSync(map) ? readdirSync(map) : []) {
    if (naam.endsWith('.' + soort)) {
      hashes[`assets/${soort}/${naam}`] = createHash('sha256').update(readFileSync(join(map, naam))).digest('hex').slice(0, 10);
    }
  }
}

const doelen = [
  ...readdirSync(web).filter((f) => f.endsWith('.html')).map((f) => join(web, f)),
  join(root, 'calcburo-app/src/Weergave.php'),
].filter(existsSync);

const PATROON = /(\/?)(assets\/(?:css|js)\/[A-Za-z0-9._-]+\.(?:css|js))(\?v=[0-9a-f]*)?(?=["'])/g;
const verouderd = [];
let gewijzigd = 0;

for (const pad of doelen) {
  const oud = readFileSync(pad, 'utf8');
  const nieuw = oud.replace(PATROON, (geheel, slash, asset, versie) => {
    const hash = hashes[asset];
    if (!hash) {
      verouderd.push(`${pad}: onbekend asset ${asset}`);
      return geheel;
    }
    const juist = `${slash}${asset}?v=${hash}`;
    if (geheel !== juist) verouderd.push(`${pad.slice(root.length + 1)}: ${asset} ${versie || '(geen versie)'} → ?v=${hash}`);
    return juist;
  });
  if (nieuw !== oud && !alleenControleren) {
    writeFileSync(pad, nieuw);
    gewijzigd++;
  }
}

if (alleenControleren) {
  if (verouderd.length) {
    console.error('Verouderde assetversies:\n  ' + verouderd.join('\n  '));
    console.error('Oplossen: node tools/asset-versies.mjs');
    process.exit(1);
  }
  console.log(`Assetversies actueel (${Object.keys(hashes).length} assets, ${doelen.length} bestanden).`);
} else {
  const onbekend = verouderd.filter((r) => r.includes('onbekend asset'));
  if (onbekend.length) {
    console.error(onbekend.join('\n'));
    process.exit(1);
  }
  console.log(`${gewijzigd} bestand(en) bijgewerkt; ${verouderd.length} verwijzing(en) aangepast.`);
}
