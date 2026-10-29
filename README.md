# CalcBuro — website en aanvraagflow

**Live sinds 29 oktober 2026 op https://calcburo.nl** (Cloud86/Plesk, PHP 8.3). Calculatiebureau voor aanbestedingen en bouwkosten, voor aannemers, ontwikkelaars en publieke opdrachtgevers. Aanvragen lopen via een eigen PHP-formulier op de hosting; betaling per factuur buiten de website.

Repository: `oamatiskak-star/calcburo` (publiek). Er staan geen secrets, testsleutels of klantgegevens in.

## Wat is canoniek

| Map | Rol |
|-----|-----|
| **`hosting/`** | **Canonieke bron van de live site.** Spiegelt de hostingindeling één-op-één. |
| `hosting/httpdocs/` | → `/var/www/vhosts/calcburo.nl/httpdocs` (documentroot): pagina's, `aanvraag.php`, `api/aanvraag.php`, `privacy.html`, assets, `.htaccess`, `.user.ini` |
| `hosting/calcburo-app/` | → `/var/www/vhosts/calcburo.nl/calcburo-app` (**buiten** de documentroot): PHP-code van de aanvraagflow, `config.php` (in git op `'uit'`), opschoonscript `bin/opschonen.php` |
| `hosting/private/calcburo/` | → `/var/www/vhosts/calcburo.nl/private/calcburo` (buiten de documentroot): in git alleen `LEESMIJ.txt`; aanvragen, documenten en `geheim.key` ontstaan op de server en komen nooit in git |
| `hosting/tests/`, `hosting/tools/` | Tests en hulpscripts (niet uploaden) |
| root (`index.html`, landingspagina's, `assets/`) | **Root-preview**, ongewijzigd bewaard voor compatibiliteit met eerdere links en reviews. Niet de live bron. |
| `archief/` | Historische controles en screenshots van de eerste homepage |

Technische details van de aanvraagflow: [hosting/README.md](hosting/README.md). Volledige deployment, testprocedure en terugdraaien: [hosting/DEPLOY.md](hosting/DEPLOY.md).

## Deployment in het kort

Geen build. Publiceren = gewijzigde bestanden uit `hosting/` uploaden naar dezelfde paden op de server (Plesk Bestandsbeheer of SFTP), na een back-up.

```bash
cd hosting
node tools/asset-versies.mjs          # na wijziging van CSS/JS: ?v=<inhoudshash> bijwerken in HTML en Weergave.php
php tests/selftest.php                # 51 PHP-tests (validatie, limieten, idempotentie, capaciteit, crashherstel, mail-mock, opschoning)
node --test tests/                    # 16 Node-tests (frontend, copy, Open Graph, assetversies, rooktest entrypoints)
tools/e2e-lokaal.sh                   # 17 end-to-end checks met PHP-server + curl (mail uit)
```

Regels:
- **Nooit `hosting/calcburo-app/config.php` uploaden over de live-configuratie.** De repository-versie staat op `'uit'`; instellingen alleen op de server wijzigen.
- Nooit iets in `private/calcburo/` op de server overschrijven of verwijderen.
- Productie draait PHP 8.3: `PHP=8.3 npx -y @php-wasm/cli@latest tests/selftest.php` test op dezelfde lijn zonder lokale 8.3.
- De dagelijkse opschoontaak (`calcburo-app/bin/opschonen.php --uitvoeren`, 03:17) staat in Plesk; zie DEPLOY.md.

## Root-preview (compatibiliteit)

Buildless preview zoals gereviewd vóór de livegang: homepage plus zes landingspagina's, voorbeeldcalculatie en aanvullende diensten. Pagina's staan op `noindex,nofollow`, het formulier opent een mailto-concept. De live versie in `hosting/httpdocs` heeft daarnaast: aanvraagformulier met documentupload, privacyverklaring, Open Graph, `robots.txt`/`sitemap.xml`, assetversies en security-headers.

```
index.html, aannemers.html, ontwikkelaars.html, publieke-opdrachtgevers.html,
inschrijfbegroting.html, hoeveelhedenstaat.html, begrotingscontrole.html,
voorbeeldcalculatie.html, aanvullendediensten.html, homepage-visualisatie.html
assets/css/styles.css, assets/js/main.js, assets/img/*   (hoofdfoto: gekocht iStock-beeld, webformaten; zie ASSET-LICENSES.md)
ASSET-LICENSES.md              Maker, bron en licentie van gebruikte beelden
REVIEWSTATUS.md                Reviewstatus van de herpositionering (historisch na livegang)
SEO-LAUNCHPLAN.md              SEO-plan vóór livegang (historisch; live staan canonicals, sitemap en robots in hosting/httpdocs)
ACCEPTATIE.md                  Historisch acceptatielogboek eerste homepage en beeldwijzigingen
COMMERCIELE-SPECIFICATIE.md    Historische specificatie eerste homepage
CLAUDE-BOUWOPDRACHT.md         Historische bouwopdracht
tests/static-check.mjs         Statische controles root-preview (840 checks, Node, geen dependencies)
archief/                       browser-check-eerste-homepage.mjs + screenshots (historisch, niet meer bijgehouden)
```

```bash
node tests/static-check.mjs    # 840/840: metadata, CSP, koppen, links, labels, verboden claims, contrast (WCAG AA), JS-syntax
python3 -m http.server 8080    # lokale preview van de root
```
