# CalcBuro — website preview

Buildless, responsive preview van de CalcBuro-website: homepage plus zes landingspagina's.

**Status:** voorlopige commerciële richting — calculatiebureau voor aanbestedingen en bouwkosten, voor aannemers, ontwikkelaars en publieke opdrachtgevers (u-vorm). Nog niet geaccepteerd; zie `REVIEWSTATUS.md`. Niet gepubliceerd, alle pagina's `noindex,nofollow`. Geen deployment, geen domeinkoppeling.

Repository: `calcburo` (door gebruiker aangemaakt; vervangt de in de oorspronkelijke specificatie genoemde naam `calcburo-nl-web`).

## Structuur

```
index.html                     Homepage: hero, doelgroepkeuze, diensten, afspraken vooraf, inhoudsopzet,
                               werkwijze, AI-uitleg, FAQ, aanvraagformulier (mailto-concept)
aannemers.html                 Landingspagina doelgroep aannemers
ontwikkelaars.html             Landingspagina doelgroep ontwikkelaars
publieke-opdrachtgevers.html   Landingspagina doelgroep publieke opdrachtgevers
inschrijfbegroting.html        Dienstpagina inschrijfbegroting
hoeveelhedenstaat.html         Dienstpagina hoeveelhedenstaat
begrotingscontrole.html        Dienstpagina begrotingscontrole
assets/css/styles.css          Lokale stijlen, design tokens, responsive, reduced-motion
assets/js/main.js              Mobiel menu + transparante mailto-conceptflow + optionele voorinvulling
assets/img/favicon.svg         Lokale favicon
assets/img/constructiemodel.png  Hero-beeld constructiemodel (CC BY-SA 4.0, zie ASSET-LICENSES.md)
ASSET-LICENSES.md              Maker, bron en licentie van gebruikte beelden
REVIEWSTATUS.md                Actuele reviewstatus en open punten
SEO-LAUNCHPLAN.md              Canonicals, sitemap en robots ná keuze URL-vorm en livegang (niet uitgevoerd)
ACCEPTATIE.md                  Historisch acceptatielogboek eerste homepage en beeldwijzigingen
COMMERCIELE-SPECIFICATIE.md    Historische specificatie eerste homepage
tests/static-check.mjs         Statische controles alle pagina's (Node, geen dependencies)
tests/browser-check.mjs        HISTORISCH: browsercontroles eerste homepage (Chrome headless, CDP)
tests/screenshots/             HISTORISCH: screenshots eerste homepage
```

Alle pagina's delen header, footer, CSS, JS en dezelfde Content-Security-Policy (meta: alleen `'self'`, `data:`-afbeeldingen en `mailto:` als formulierdoel). Geen externe fonts, scripts, afbeeldingen, cookies, analytics of tracking. Het aanvraagformulier staat alleen op de homepage; landingspagina's linken ernaar.

## Aanvraagformulier

- Verplicht: naam, zakelijk e-mailadres, projectomschrijving. Optioneel: bedrijf, **Uw rol**, **Gewenste uitwerking**, **Gewenste oplevering / inschrijfdatum**.
- Verzenden opent alleen een e-mailconcept naar `calculatie@calcburo.nl` (`encodeURIComponent`); rol en uitwerking staan in de mailbody. Geen opslag, netwerkverzoek, upload of ontvangstbevestiging.
- Voorinvulling: `index.html?rol=aannemer#aanvragen` of `?uitwerking=begrotingscontrole`. Alleen bestaande optiewaarden worden overgenomen; andere waarden worden genegeerd.
  - `rol`: `aannemer`, `ontwikkelaar`, `publieke-opdrachtgever`, `anders`
  - `uitwerking`: `inschrijfbegroting`, `hoeveelhedenstaat`, `begrotingscontrole`, `nog-afstemmen`
- ‘Hulp bij uw aanvraag’ is een gewone `<details>`-uitklapper; geen chatbot.

## Lokale preview

```bash
python3 -m http.server 8080
# open http://localhost:8080
```

Of direct `open index.html` (querystring-voorinvulling werkt ook via `file://`).

## Controles uitvoeren

```bash
node tests/static-check.mjs    # 591 controles, alle zeven pagina's, incl. node --check van main.js
```

De statische controle dekt per pagina: metadata, `noindex,nofollow`, CSP, één H1, unieke titels/beschrijvingen, ankers en cross-page links (incl. doel-id), voorinvulwaarden tegen bestaande opties, ARIA-referenties, labels, footerlinks, u-vorm, verboden claims (garanties, certificering, onafhankelijkheid, pakketintegraties, hosting/trainingsclaims, tarieven, fictieve bedrijfsgegevens), ≥ 4 inhoudsblokken en 3 FAQ's per landingspagina, onderlinge tekstoverlap < 30 %, JS-gedrag op broncodeniveau en contrast (WCAG AA).

`tests/browser-check.mjs` is historisch: geschreven voor de eerste homepage en niet bijgewerkt; copygebonden controles falen bewust. In deze ronde is geen browserautomatisering uitgevoerd; visuele beoordeling gebeurt door ChatGPT in de bestaande browser.

### Bekende beperkingen / handmatig te controleren

- **Mailto-lengte:** bij maximale invoer ca. 3.800 tekens; oudere Outlook-versies op Windows kunnen lange mailto-links afkappen. Daarom staat het directe adres altijd in de statusmelding.
- **Zonder mailclient** opent er niets; de statustekst verwijst dan naar `calculatie@calcburo.nl`.
- Mobiel menu, formulier en nieuwe secties nog visueel controleren op 320 / 375 / 768 / 1440 px en op echte iOS/Android-apparaten.

## Open punten vóór productie

- Werkelijke bedrijfsgegevens (KvK, adres) en privacy-informatie; nu bewust weggelaten.
- Kiezen tussen deze transparante mailflow of geteste serververzending met privacytekst en foutafhandeling.
- Documentbeveiliging en gebruikte AI-diensten vaststellen; geen uploadfunctie in preview.
- Lichtere webversie van het hero-beeld (nu ca. 10 MB).
- Definitieve URL-vorm kiezen en `SEO-LAUNCHPLAN.md` uitvoeren; `noindex,nofollow` en previewbalk pas dan verwijderen.
- Security-headers (HSTS, `X-Content-Type-Options`, `frame-ancestors`) op hostingniveau.
