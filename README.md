# calcburo — homepage preview

Buildless, responsive homepage voor Calcburo, gebouwd op basis van `COMMERCIELE-SPECIFICATIE.md` en `homepage-visualisatie.html`.

**Status:** preview voor commerciële en visuele acceptatie (ChatGPT). Niet gepubliceerd, `noindex,nofollow`. Geen deployment, geen domeinkoppeling.

Repository: `calcburo` (door gebruiker aangemaakt; vervangt de in de specificatie genoemde naam `calcburo-nl-web`).

## Structuur

```
index.html                 Homepage (alle copy uit de specificatie)
assets/css/styles.css      Lokale stijlen, design tokens, responsive, reduced-motion
assets/js/main.js          Mobiel menu + transparante mailto-conceptflow
assets/img/favicon.svg     Lokale favicon
tests/static-check.mjs     Statische controles (Node, geen dependencies)
tests/browser-check.mjs    Browsercontroles via lokale Chrome headless (CDP, geen dependencies)
tests/screenshots/         Screenshots van de laatste browsertestrun
```

Geen externe fonts, scripts, afbeeldingen, cookies, analytics of tracking. Een Content-Security-Policy (meta) staat alleen `'self'`, `data:`-afbeeldingen en `mailto:` als formulierdoel toe.

## Lokale preview

Optie 1 — direct openen:

```bash
open index.html
```

Optie 2 — lokale webserver (aanbevolen, gelijk aan hosting):

```bash
python3 -m http.server 8080
# open http://localhost:8080
```

Responsive controle: Chrome DevTools → apparaatmodus → 375, 768 en 1440 px.

## Controles uitvoeren

```bash
node tests/static-check.mjs    # 90 controles
node tests/browser-check.mjs   # 47 controles, vereist Google Chrome
```

`browser-check.mjs` start Chrome headless met een tijdelijk profiel in `.tmp-chrome-profile/` (wordt na afloop verwijderd). Ander Chrome-pad: `CHROME_PATH=/pad/naar/chrome node tests/browser-check.mjs`.

## Testnotities (laatste run: 2026-10-29)

| Onderdeel | Resultaat |
|---|---|
| Statische controles (metadata, noindex, copy, ankers, ARIA-referenties, labels, unieke id's, geen externe assets, verboden claims, contrast) | 90/90 geslaagd |
| Browsercontroles (Chrome headless) | 47/47 geslaagd |
| JS-syntax (`node --check`) | OK |

Getest in de browsercontrole:

- **Breakpoints 320 / 375 / 768 / 1440 px:** geen horizontale overflow, geen afgekapte CTA-tekst. Screenshots: `tests/screenshots/`.
- **Mobiele CTA-wrapping:** onder 600 px staan hero-CTA's onder elkaar, primaire knop volle breedte en ≥ 44 px hoog; de header-CTA verhuist naar het mobiele menu in plaats van te krimpen naast het logo.
- **Mobiel menu (≤ 960 px):** knop met `aria-expanded`/`aria-controls`, label wisselt Menu/Sluiten; opent met klik en Enter; Tab gaat naar eerste menulink; Escape sluit en zet focus terug op de knop; klik op link of buiten het menu sluit; bij vergroten naar desktop wordt het menu hersteld. Zonder JavaScript blijven alle links zichtbaar.
- **Toetsenbord:** eerste Tab = skip-link "Direct naar de inhoud" met zichtbare focus; daarna logo (`#top`).
- **CTA-ankers:** nav-CTA en hero-CTA → `#aanvragen`, secundaire CTA → `#werkwijze`; sectie komt onder de sticky header in beeld.
- **FAQ:** native `<details>/<summary>`, opent met muis en Enter.
- **Formulierfouten:** lege verzending toont 3 inline foutmeldingen (gekoppeld via `aria-describedby`, `aria-invalid`), focus naar eerste fout, géén statusmelding en géén mailto. Ongeldig e-mailadres krijgt eigen melding; alleen spaties in projectomschrijving telt als leeg.
- **Lange invoer:** projectomschrijving begrensd op 2000 tekens (`maxlength`), overige velden 150/200.
- **Mailto-conceptflow:** adres `calculatie@calcburo.nl`, onderwerp en body via `encodeURIComponent`; speciale tekens (`& # ? = + " ë é ü`) en regeleinden komen ongeschonden door; lege optionele velden als "niet opgegeven". Statustekst exact volgens specificatie, pas ná geldige invoer. Geen opslag, geen netwerkverzoek.
- **Beide mailadressen:** `calculatie@calcburo.nl` (aanvraagblok + formulier) en `info@calcburo.nl` (aanvraagblok + footer) als `mailto:`-links.
- **Reduced motion:** `scroll-behavior: auto` en transities uitgeschakeld.
- **Console:** geen JS-fouten.

Contrast (WCAG AA, tekst ≥ 4,5:1) berekend voor alle tekst/achtergrondcombinaties, inclusief knoppen, donkere technologiesectie en foutmeldingen; focusring ≥ 3:1 op licht en donker.

### Bekende beperkingen / handmatig te controleren

- **Mailto-lengte:** bij maximale invoer is de mailto-URL ca. 3.000–4.000 tekens (gemeten: 3.764 tekens bij 2000 tekens testtekst met speciale tekens). Moderne mailclients (Apple Mail, Gmail via handler, Outlook nieuw) verwerken dit; oudere Outlook-versies op Windows kunnen lange mailto-links afkappen. Daarom staat het directe adres altijd in de statusmelding.
- **Zonder mailclient** opent er niets; de statustekst verwijst dan naar `calculatie@calcburo.nl`.
- **Echte apparaten:** iOS Safari en Android Chrome nog handmatig controleren (menu, mailto-handler, zoom bij focus in velden).
- Firefox en Safari zijn niet geautomatiseerd getest; alleen Chrome headless.

## Open punten vóór productie (uit specificatie)

- Werkelijke bedrijfsgegevens (KvK, adres) en privacy-informatie aanvullen; nu bewust weggelaten.
- Kiezen tussen deze transparante mailflow of geteste serververzending met privacytekst en foutafhandeling.
- Documentbeveiliging en gebruikte AI-diensten vaststellen; geen uploadfunctie in preview.
- `noindex,nofollow` en de previewbalk pas verwijderen na visuele/copy-acceptatie.
- Security-headers (HSTS, `X-Content-Type-Options`, `frame-ancestors`) instellen op hostingniveau.
