// Browsercontroles via lokale Google Chrome (headless) en het Chrome DevTools Protocol.
// Geen npm-dependencies. Uitvoeren: node tests/browser-check.mjs
// Optioneel: CHROME_PATH=/pad/naar/chrome node tests/browser-check.mjs
import { spawn } from 'node:child_process';
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const pageUrl = pathToFileURL(join(root, 'index.html')).href;
const shotsDir = join(root, 'tests', 'screenshots');
const profileDir = join(root, '.tmp-chrome-profile');
const chromePath = process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const PORT = 9333;

const results = [];
const check = (name, ok, detail = '') => results.push({ name, ok: Boolean(ok), detail });
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

mkdirSync(shotsDir, { recursive: true });
rmSync(profileDir, { recursive: true, force: true });

const chrome = spawn(chromePath, [
  '--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profileDir}`,
  '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--hide-scrollbars', 'about:blank'
], { stdio: 'ignore' });

async function getTarget() {
  for (let i = 0; i < 50; i += 1) {
    try {
      const list = await (await fetch(`http://127.0.0.1:${PORT}/json`)).json();
      const page = list.find((t) => t.type === 'page');
      if (page) return page;
    } catch { /* Chrome start nog */ }
    await wait(200);
  }
  throw new Error('Chrome DevTools niet bereikbaar');
}

function connect(wsUrl) {
  const ws = new WebSocket(wsUrl);
  let id = 0;
  const pending = new Map();
  const listeners = [];
  ws.onmessage = (e) => {
    const msg = JSON.parse(e.data);
    if (msg.id && pending.has(msg.id)) {
      const { resolve, reject } = pending.get(msg.id);
      pending.delete(msg.id);
      msg.error ? reject(new Error(msg.error.message)) : resolve(msg.result);
    } else if (msg.method) {
      listeners.forEach((l) => l(msg));
    }
  };
  const send = (method, params = {}) => new Promise((resolve, reject) => {
    id += 1;
    pending.set(id, { resolve, reject });
    ws.send(JSON.stringify({ id, method, params }));
  });
  return new Promise((resolve) => { ws.onopen = () => resolve({ send, on: (fn) => listeners.push(fn), close: () => ws.close() }); });
}

let cdp;
const evaluate = async (expression) => {
  const r = await cdp.send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
  if (r.exceptionDetails) throw new Error(r.exceptionDetails.text + ' ' + (r.exceptionDetails.exception?.description || ''));
  return r.result.value;
};
const KEY_TEXT = { Enter: '\r', ' ': ' ' };
const key = async (k, code, keyCode, modifiers = 0) => {
  const text = KEY_TEXT[k];
  await cdp.send('Input.dispatchKeyEvent', { type: 'keyDown', key: k, code, windowsVirtualKeyCode: keyCode, modifiers, ...(text ? { text } : {}) });
  await cdp.send('Input.dispatchKeyEvent', { type: 'keyUp', key: k, code, windowsVirtualKeyCode: keyCode, modifiers });
};
const clickSelector = async (selector) => {
  const box = await evaluate(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); const r0 = el.getBoundingClientRect(); if (r0.top < 80 || r0.bottom > innerHeight) el.scrollIntoView({ block: 'center', behavior: 'instant' }); const r = el.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; })()`);
  for (const type of ['mousePressed', 'mouseReleased']) {
    await cdp.send('Input.dispatchMouseEvent', { type, x: box.x, y: box.y, button: 'left', clickCount: 1 });
  }
};

async function load(width, height = 900) {
  await cdp.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: width < 600 });
  await cdp.send('Page.navigate', { url: pageUrl });
  await wait(600);
}

async function viewportShot(name) {
  const shot = await cdp.send('Page.captureScreenshot', { format: 'png' });
  writeFileSync(join(shotsDir, name), Buffer.from(shot.data, 'base64'));
}

async function screenshot(name) {
  const { cssContentSize } = await cdp.send('Page.getLayoutMetrics');
  const shot = await cdp.send('Page.captureScreenshot', {
    format: 'png', captureBeyondViewport: true,
    clip: { x: 0, y: 0, width: cssContentSize.width, height: cssContentSize.height, scale: 1 }
  });
  writeFileSync(join(shotsDir, name), Buffer.from(shot.data, 'base64'));
}

try {
  const target = await getTarget();
  cdp = await connect(target.webSocketDebuggerUrl);
  const consoleErrors = [];
  const navigations = [];
  cdp.on((msg) => {
    if (msg.method === 'Runtime.exceptionThrown') consoleErrors.push(msg.params.exceptionDetails.text);
    if (msg.method === 'Log.entryAdded' && msg.params.entry.level === 'error' && !msg.params.entry.text.includes('mailto:')) consoleErrors.push(msg.params.entry.text);
    if (msg.method === 'Page.frameRequestedNavigation') navigations.push(msg.params.url);
    const text = msg.params?.entry?.text || msg.params?.message?.text || '';
    const found = text.match(/mailto:[^'"\s]+/);
    if (found) navigations.push(found[0]);
  });
  await cdp.send('Page.enable');
  await cdp.send('Network.enable');
  await cdp.send('Runtime.enable');
  await cdp.send('Log.enable');

  // 1. Breakpoints: overflow, menuknop, CTA-wrapping, screenshots
  for (const width of [320, 375, 768, 1440]) {
    await load(width);
    const m = await evaluate(`(() => {
      const de = document.documentElement;
      const wide = [...document.querySelectorAll('body *')].filter((el) => { const r = el.getBoundingClientRect(); return r.width > 0 && (r.right > innerWidth + 1 || r.left < -1) && !el.closest('.skip-link'); }).map((el) => el.className || el.tagName).slice(0, 5);
      const toggle = document.querySelector('.menu-toggle');
      const ctas = [...document.querySelectorAll('.button')].map((b) => { const r = b.getBoundingClientRect(); return { w: r.width, h: r.height, fits: b.scrollWidth <= b.clientWidth + 1 }; });
      return { scrollW: de.scrollWidth, innerW: innerWidth, wide, toggleVisible: toggle.offsetParent !== null, panelVisible: document.getElementById('hoofdmenu').offsetParent !== null, ctas };
    })()`);
    check(`${width}px: geen horizontale overflow (${m.scrollW} ≤ ${m.innerW})`, m.scrollW <= m.innerW && m.wide.length === 0, m.wide.join(', '));
    const expectMobile = width <= 960;
    check(`${width}px: menuknop ${expectMobile ? 'zichtbaar' : 'verborgen'}`, m.toggleVisible === expectMobile);
    check(`${width}px: navigatiepaneel ${expectMobile ? 'ingeklapt' : 'zichtbaar'}`, m.panelVisible === !expectMobile);
    check(`${width}px: geen afgekapte CTA-tekst`, m.ctas.every((c) => !c.h || c.fits), JSON.stringify(m.ctas));
    if (width <= 600) {
      const hero = await evaluate(`(() => { const b = document.querySelector('.actions .button').getBoundingClientRect(); const a = document.querySelector('.actions').getBoundingClientRect(); return { bw: b.width, aw: a.width, bh: b.height }; })()`);
      check(`${width}px: hero-CTA volle breedte en ≥ 44px hoog`, Math.abs(hero.bw - hero.aw) < 2 && hero.bh >= 44, JSON.stringify(hero));
    }
    await viewportShot(`boven-de-vouw-${width}.png`);
    await screenshot(`homepage-${width}.png`);
  }

  // 2. Mobiel menu (375px)
  await load(375);
  await clickSelector('.menu-toggle');
  let s = await evaluate(`({ exp: document.querySelector('.menu-toggle').getAttribute('aria-expanded'), vis: document.getElementById('hoofdmenu').offsetParent !== null, label: document.querySelector('.menu-toggle-label').textContent })`);
  check('menu opent via klik (aria-expanded=true, paneel zichtbaar, label Sluiten)', s.exp === 'true' && s.vis && s.label === 'Sluiten');
  await wait(300);
  await viewportShot('homepage-375-menu-open.png');
  await key('Escape', 'Escape', 27);
  s = await evaluate(`({ exp: document.querySelector('.menu-toggle').getAttribute('aria-expanded'), focus: document.activeElement.className })`);
  check('Escape sluit menu en zet focus terug op menuknop', s.exp === 'false' && s.focus === 'menu-toggle');
  await key('Enter', 'Enter', 13);
  s = await evaluate(`document.querySelector('.menu-toggle').getAttribute('aria-expanded')`);
  check('menu opent met Enter op menuknop', s === 'true');
  await key('Tab', 'Tab', 9);
  s = await evaluate(`document.activeElement.getAttribute('href')`);
  check('Tab na openen gaat naar eerste menulink', s === '#diensten', s);
  await clickSelector('#hoofdmenu a[href="#werkwijze"]');
  await wait(300);
  s = await evaluate(`({ exp: document.querySelector('.menu-toggle').getAttribute('aria-expanded'), hash: location.hash })`);
  check('klik op menulink sluit menu en navigeert naar #werkwijze', s.exp === 'false' && s.hash === '#werkwijze', JSON.stringify(s));
  await clickSelector('.menu-toggle');
  await clickSelector('main h2');
  s = await evaluate(`document.querySelector('.menu-toggle').getAttribute('aria-expanded')`);
  check('klik buiten menu sluit menu', s === 'false');

  // 3. Menu herstelt bij vergroten naar desktop
  await clickSelector('.menu-toggle');
  await cdp.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
  await wait(200);
  s = await evaluate(`({ exp: document.querySelector('.menu-toggle').getAttribute('aria-expanded'), hidden: document.querySelector('.menu-toggle').hidden, vis: document.getElementById('hoofdmenu').offsetParent !== null })`);
  check('resize naar desktop: menuknop verborgen, menu gesloten, links zichtbaar', s.exp === 'false' && s.hidden && s.vis);

  // 4. Toetsenbord: skip-link en focusvolgorde (desktop)
  await load(1440);
  await key('Tab', 'Tab', 9);
  s = await evaluate(`({ cls: document.activeElement.className, outline: getComputedStyle(document.activeElement).outlineStyle })`);
  check('eerste Tab: skip-link met zichtbare focus', s.cls === 'skip-link' && s.outline !== 'none', JSON.stringify(s));
  await key('Tab', 'Tab', 9);
  s = await evaluate(`document.activeElement.getAttribute('href')`);
  check('tweede Tab: logo naar #top', s === '#top', s);

  // 5. CTA-ankers
  for (const [sel, hash] of [['.nav-cta', '#aanvragen'], ['.actions .button', '#aanvragen'], ['.actions .text-link', '#werkwijze']]) {
    await load(1440);
    await clickSelector(sel);
    await wait(900);
    s = await evaluate(`({ hash: location.hash, top: Math.round(document.querySelector('${hash}').getBoundingClientRect().top) })`);
    check(`CTA ${sel} → ${hash} (sectie in beeld, top ${s.top}px)`, s.hash === hash && s.top >= 0 && s.top < 200, JSON.stringify(s));
  }

  // 6. FAQ
  await load(1440);
    await clickSelector('#vragen summary');
  s = await evaluate(`document.querySelector('#vragen details').open`);
  check('FAQ opent met klik', s === true);
  await evaluate(`document.querySelectorAll('#vragen summary')[1].focus()`);
  await key('Enter', 'Enter', 13);
  s = await evaluate(`document.querySelectorAll('#vragen details')[1].open`);
  check('FAQ opent met Enter (toetsenbord)', s === true);

  // 7. Formulier: lege verzending
  await load(375);
    await clickSelector('#aanvraag button[type="submit"]');
  await wait(100);
  s = await evaluate(`({
    errors: [...document.querySelectorAll('.field-error')].filter((e) => !e.hidden).map((e) => e.textContent),
    invalid: [...document.querySelectorAll('[aria-invalid="true"]')].map((e) => e.name),
    focus: document.activeElement.name,
    status: document.getElementById('status').textContent
  })`);
  check('lege verzending: 3 foutmeldingen, focus op naam, geen status', s.errors.length === 3 && s.invalid.length === 3 && s.focus === 'naam' && s.status === '', JSON.stringify(s));
  check('lege verzending: geen mailto-navigatie', navigations.filter((u) => u.startsWith('mailto:')).length === 0);
  await screenshot('homepage-375-formulierfouten.png');

  // 8. Ongeldig e-mailadres en alleen spaties
  await evaluate(`document.getElementById('veld-naam').focus()`);
  await cdp.send('Input.insertText', { text: 'Test Persoon' });
  await evaluate(`document.getElementById('veld-email').focus()`);
  await cdp.send('Input.insertText', { text: 'geen-adres' });
  await evaluate(`document.getElementById('veld-project').focus()`);
  await cdp.send('Input.insertText', { text: '    ' });
  await clickSelector('#aanvraag button[type="submit"]');
  s = await evaluate(`({ email: document.getElementById('fout-email').textContent, project: document.getElementById('fout-project').hidden, focus: document.activeElement.name, naamInvalid: document.getElementById('veld-naam').getAttribute('aria-invalid') })`);
  check('ongeldig e-mailadres krijgt specifieke melding', s.email.startsWith('Vul een geldig e-mailadres in'), s.email);
  check('projectomschrijving met alleen spaties is ongeldig', s.project === false);
  check('focus naar eerste ongeldige veld (email)', s.focus === 'email', s.focus);
  check('naam is na correctie niet meer ongeldig', s.naamInvalid === 'false');

  // 9. Lange invoer wordt begrensd door maxlength
  await evaluate(`(() => { const f = document.getElementById('veld-email'); f.value = ''; f.focus(); })()`);
  await cdp.send('Input.insertText', { text: 'aanvrager@bouwbedrijf-voorbeeld.nl' });
  await evaluate(`(() => { const f = document.getElementById('veld-project'); f.value = ''; f.focus(); })()`);
  const longText = 'Nieuwbouw van 12 appartementen; "kozijnen" & funderingen #3 ?=+ ëéü\n'.repeat(40);
  await cdp.send('Input.insertText', { text: longText });
  s = await evaluate(`document.getElementById('veld-project').value.length`);
  check(`lange invoer begrensd op 2000 tekens (${s})`, s === 2000);
  await screenshot('homepage-375-lange-invoer.png');

  // 10. Geldige verzending → mailto-concept
  await clickSelector('#aanvraag button[type="submit"]');
  await wait(400);
  s = await evaluate(`({ status: document.getElementById('status').textContent, invalid: document.querySelectorAll('[aria-invalid="true"]').length })`);
  check('geldige verzending: statustekst conform specificatie', s.status === 'Je e-mailconcept is voorbereid. Controleer en verstuur het in je e-mailprogramma. Werkt dat niet? Mail naar calculatie@calcburo.nl.' && s.invalid === 0, JSON.stringify(s));
  const mailto = navigations.find((u) => u.startsWith('mailto:'));
  if (mailto) {
    const u = new URL(mailto);
    const body = u.searchParams.get('body') || '';
    check('mailto naar calculatie@calcburo.nl', u.pathname === 'calculatie@calcburo.nl', u.pathname);
    check('mailto onderwerp correct gecodeerd', u.searchParams.get('subject') === 'Calculatieaanvraag via Calcburo');
    check('mailto body bevat speciale tekens ongeschonden (&, #, ?, =, +, ë)', body.includes('"kozijnen" & funderingen #3 ?=+ ëéü'));
    check('mailto body: lege optionele velden als "niet opgegeven"', body.includes('Bedrijf: niet opgegeven') && body.includes('Gewenste planning: niet opgegeven'));
    check(`mailto URL-lengte ${mailto.length} tekens (info)`, true);
  } else {
    check('mailto-navigatie waargenomen via CDP', false, 'Page.frameRequestedNavigation niet ontvangen; controleer handmatig');
  }

  // 11. Reduced motion
  await cdp.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
  await load(1440);
  s = await evaluate(`({ sb: getComputedStyle(document.documentElement).scrollBehavior, td: getComputedStyle(document.querySelector('.button')).transitionDuration })`);
  check('reduced-motion: scroll-behavior auto en transities uit', s.sb === 'auto' && s.td.split(',').every((d) => parseFloat(d) < 0.001), JSON.stringify(s));
  await cdp.send('Emulation.setEmulatedMedia', { features: [] });

  // 12. Console
  check('geen console-fouten of JS-exceptions', consoleErrors.length === 0, consoleErrors.join(' | '));

  cdp.close();
} catch (err) {
  check('testrun zonder crash', false, err.message);
} finally {
  chrome.kill();
  await wait(300);
  rmSync(profileDir, { recursive: true, force: true });
}

const failed = results.filter((r) => !r.ok);
for (const r of results) console.log(`${r.ok ? 'OK  ' : 'FAIL'} ${r.name}${r.detail && !r.ok ? ` — ${r.detail}` : ''}`);
console.log(`\n${results.length - failed.length}/${results.length} geslaagd · screenshots in tests/screenshots/`);
process.exit(failed.length ? 1 : 0);
