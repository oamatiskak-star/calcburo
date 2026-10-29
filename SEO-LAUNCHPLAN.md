# SEO-launchplan — CalcBuro

Status: **plan, niet uitgevoerd.** De preview blijft `noindex,nofollow`. Er is geen `sitemap.xml`, `robots.txt`, canonical, redirect of domeinmigratie aangemaakt. Dit plan wordt pas uitgevoerd na (1) commerciële en visuele acceptatie, (2) keuze van de definitieve URL-vorm en (3) een expliciet publicatiebesluit.

## 1. Besluiten vóór livegang

| Besluit | Opties | Gevolg |
|---|---|---|
| URL-vorm | `/aannemers.html` (huidig, buildless) of `/aannemers/` (mapstructuur of hostingrewrite) | Bepaalt canonicals, sitemap en interne links. Kies één vorm en houd die vast. |
| Hoofddomein | `calcburo.nl` met of zonder `www` | Eén variant canoniek; de andere via 301 op hostingniveau. |
| Contactverwerking | Huidige mailto-conceptflow of geteste serververzending | Bij serververzending: privacytekst, foutafhandeling en spambescherming eerst. |
| Bedrijfsgegevens | Werkelijke KvK, adres, privacyverklaring | Pas daarna eventueel Organization-schema. Geen fictieve gegevens. |

## 2. Pagina's en zoekintentie

| Pagina | Primaire zoekintentie |
|---|---|
| `index.html` | Calculatiebureau voor aanbestedingen en bouwkosten |
| `aannemers.html` | Calculatie uitbesteden voor een aanbesteding |
| `ontwikkelaars.html` | Bouwkosten inzichtelijk maken tijdens planontwikkeling |
| `publieke-opdrachtgevers.html` | Bouwkosten ramen of laten toetsen voor publieke projecten |
| `inschrijfbegroting.html` | Inschrijfbegroting laten maken |
| `hoeveelhedenstaat.html` | Hoeveelhedenstaat laten opstellen |
| `begrotingscontrole.html` | Begrotingscontrole laten uitvoeren |

Elke pagina heeft een eigen titel, beschrijving en H1 (gecontroleerd in `tests/static-check.mjs`).

## 3. Uitvoering bij livegang (in deze volgorde)

1. `<meta name="robots" content="noindex,nofollow">` en de previewbalk verwijderen op alle pagina's.
2. Per pagina een absolute canonical toevoegen in de gekozen URL-vorm, bijvoorbeeld `<link rel="canonical" href="https://calcburo.nl/aannemers.html">`. CSP en statische check daarop aanpassen.
3. `sitemap.xml` aanmaken met alleen de zeven gepubliceerde, indexeerbare URL's en een `lastmod` per pagina.
4. `robots.txt` aanmaken: alles toestaan, verwijzing naar de sitemap. Geen previewpaden publiceren.
5. Hostingniveau: 301 van niet-canonieke host (www/non-www, http → https), security-headers (HSTS, `X-Content-Type-Options`, `frame-ancestors`/`X-Frame-Options`, `Referrer-Policy`).
6. Redirects van eventuele bestaande domeinen of oude URL's pas na een afzonderlijk besluit, met een volledige lijst van bron- en doel-URL's.
7. Search Console: domein verifiëren, sitemap indienen, indexering per pagina controleren.
8. Optioneel, alleen met echte gegevens: Organization- en Service-schema. Geen beoordelingen, adressen of referenties zonder bron.

## 4. Productieafhankelijkheden

- Lichtere webversie van `assets/img/constructiemodel.png` (nu ca. 10 MB) vóór livegang.
- Privacy-informatie en werkelijke bedrijfsgegevens.
- Browsertests opnieuw uitvoeren op de definitieve versie (incl. Safari en Firefox).
