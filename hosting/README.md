# CalcBuro — live website en aanvraagflow (Cloud86/Plesk, PHP 8.3)

Canonieke bron van https://calcburo.nl. **Live sinds 29-10-2026**, op de echte server getest (zie [DEPLOY.md](DEPLOY.md)).
Geen Supabase, geen secrets in deze map: `calcburo-app/config.php` staat in de repository op `'uit'`. Enige externe dienst: Google Ads-conversiemeting, uitsluitend na cookietoestemming (zie hieronder).
Publiceren van wijzigingen: [DEPLOY.md §0](DEPLOY.md#0-een-wijziging-publiceren-na-livegang).

## Wat er is gebouwd

| Onderdeel | Gedrag |
|-----------|--------|
| `httpdocs/aanvraag.php` | Formulier in huisstijl, optionele documenten. Desktop: intro en processtappen links, formulier rechts. Mobiel: titel en intro, direct het formulier, processtappen ingeklapt eronder (`<details>`; zonder JS open). Betaling: "Betaling per factuur, volgens de afspraken in uw voorstel." Geen privacycheckbox, wel een privacylink bij verzenden. Werkt zonder JavaScript. Open Graph met de stockfoto. |
| `httpdocs/api/aanvraag.php` | POST-eindpunt; JSON bij `Accept: application/json`, anders HTML. Geen CORS-headers (alleen same-origin). |
| Transport | `Origin` exact `https://calcburo.nl` (of zonder Origin alleen `Sec-Fetch-Site: same-origin`), CSRF-token in sessie (`__Host-cbsessie`, Secure/HttpOnly/SameSite=Strict), honeypot, beveiligingsheaders + CSP. |
| Validatie | Velden (lengte in tekens, UTF-8, stuurtekens, keuzelijsten, e-mail zonder header-injectie). Documenten PDF/IFC/XLSX/XLS/CSV/DOCX/JPG/PNG, max 10 × 20 MB, 50 MB totaal; finfo-MIME + signatuur. **XLSX/DOCX:** centrale ZIP-directory gelezen zonder uitpakken: verplichte onderdelen aanwezig, geen `vbaProject.bin` (macro's, ook bij hernoemde .xlsm/.docm), geen ActiveX/OLE-objecten, geen `..`/absolute paden, geen versleutelde of afgekapte pakketten. Geen virusscan. |
| Limiet | 10/uur per netwerkadres (IPv6 per /64), 5/uur per e-mail, 60/uur globaal; `flock`; alleen HMAC-sleutels; afwijzingen tellen niet mee. |
| **Opslagcapaciteit** | `opslag_max_bytes` = 1 GiB. Meten en opslaan onder één globaal slot (`opslag.lock`), dus gelijktijdige aanvragen kunnen de limiet samen niet overschrijden. Vol → 503 "Er is niets opgeslagen", niets verwijderd, waarschuwing aan `calculatie@` max. eens per 6 uur. Dezelfde verzending slaagt na het vrijmaken van ruimte. |
| **Idempotentie** | Record per formulier-ID met referentie en HMAC over **alle velden én documentinhoud**. Wordt als reservering (`bezig`) vastgelegd vóór de opslag, atomair geschreven (tmp + rename) naast een apart slotbestand. Identieke herhaling → zelfde referentie (200). Andere inhoud (project, document gewijzigd/toegevoegd/weggelaten, ander e-mailadres) bij een opgeslagen aanvraag → 409. Was er nog niets opgeslagen (tijdelijke fout), dan mag de gecorrigeerde inhoud alsnog. |
| **Crashconsistentie** | `aanvraag.json` wordt als laatste geschreven en geldt als "compleet". Schrijffouten ná de opslag (record, mailstatus) worden gelogd maar geven **nooit** `opgeslagen=false`. Herhaling na crash: complete aanvraag → duplicaat; halve map → opgeruimd en opnieuw opgeslagen onder dezelfde referentie; mails alleen als `mailstatus.json` ontbreekt (geen dubbele mail). |
| Opslag | `private/calcburo` buiten httpdocs; willekeurige bestandsnamen (0600), mappen 0700; bij fout volledige rollback. Docroot-controle canoniek: symlinks opgelost, `..` geweigerd, onbekende docroot = uit. Geldt voor data- én app-map. |
| Mail | PHP `mail()`: intern naar `calculatie@` vanaf `info@`, Reply-To = aanvrager, locatie + referentie, geen links/bijlagen. Bevestiging: alleen referentie en vaste tekst. Publiek: "De ontvangstbevestiging is verstuurd. Controleer eventueel uw map met ongewenste e-mail en bewaar dit referentienummer." Bij mislukking eerlijk: ontvangen, maar bevestiging niet verstuurd. Technische status alleen in `mailstatus.json`. |
| Privacy | Verantwoordelijke (alleen op de privacypagina), Cloud86, Jortt, mailbox, Google (alleen met toestemming; sectie `#cookies` "Cookies en Google Ads": wat Google wel/niet ontvangt, intrekken, Google Ireland Limited, doorgifte "volgens Google" op DPF/SCC's, links naar Google-privacybeleid). Grondslag toestemming; bewaartermijnen keuze (6 mnd) en `_gcl`-cookies ("volgens Google max. 90 dagen"). Nieuw: aanvraagformulier gebruikt geen AI; hulpmiddelen bij uitvoering worden vooraf afgestemd (geen providernamen, geen doorgifteclaims). Bewaren: "90 dagen; daarna verwijderd bij de dagelijkse opschoning"; meldingsmail handmatig. Zin over bezoekadres verwijderd. |
| **Cookietoestemming** | `assets/js/toestemming.js` op alle pagina's en `aanvraag.php` (niet in testmodus, niet op de resultaatpagina zonder JS). Niet-modale keuzekaart met gelijkwaardige knoppen **Accepteren** / **Weigeren**; later wijzigen via **Cookie-instellingen** in de footer (knop verschijnt alleen met JS). Keuze in `localStorage` (`calcburo-cookiekeuze`, 6 maanden geldig, daarna opnieuw vragen). Geen keuze, geweigerd, verlopen of ingetrokken → **geen Google-script, geen `dataLayer`, geen verzoek naar Google**. |
| **Google Ads-conversie** | Alleen na Accepteren: Consent Mode v2 `default` alles `denied`, dan `update` met `ad_storage` + `ad_user_data` `granted`, `ad_personalization` + `analytics_storage` `denied`; daarna pas `gtag.js` (`AW-18516347577`). `config`: `send_page_view: false`, `allow_ad_personalization_signals: false`, `allow_google_signals: false`, `allow_enhanced_conversions: false`; `page_location` zonder query/fragment behalve `gclid`/`gbraid`/`wbraid`/`gad_source`/`gad_campaignid`; `page_referrer` alleen herkomst + pad. Conversie `AW-18516347577/7tJyCMmp1Z4dELmVpP1E` uitsluitend bij een JSON-antwoord met `ok`, `opgeslagen`, `live: true`, `duplicaat: false` en geldig `conversie_id`; `transaction_id` = `conversie_id` = HMAC(geheim, referentie), 32 hex — Google ziet nooit het CB-nummer. Formulier wordt vóór de conversie geleegd; per sessie per ID maximaal één conversie. Nooit bij formulierklik, validatie-/serverfout, testmodus, duplicaat of in de route zonder JavaScript. Intrekken: `ga-disable-AW-…`, consent `denied`, `_gcl_*`-cookies van calcburo.nl verwijderd, pagina herladen (niet bij een ingevuld formulier). CSP van statische pagina's en `aanvraag.php` staat alleen de Google Ads-domeinen toe (geen `frame-src`, geen inline); API en resultaatpagina houden de strikte CSP. Referer naar Google: geen (`no-referrer` / `same-origin`). |
| Overig | Assetversies via `tools/asset-versies.mjs` (`--check` in de tests; ook verwijzingen in PHP-strings met `\"`). Open Graph/Twitter-card op alle pagina's (titel, beschrijving, canonical, `bouwkundige-controle.jpg`). `aanvraag.php` geïndexeerd en in sitemap. Geen analytics, geen captcha. |

## Verificatie (lokaal, 29-10-2026)

| Test | Resultaat |
|------|-----------|
| `php tests/selftest.php` op PHP 8.5.12 (native) | **56/56** (29-10-2026, na cookietoestemming) |
| Zelfde zelftest op **PHP 8.3** (WebAssembly, = productieversie-lijn) + `php -l` alle bestanden | **56/56**, geen syntaxfouten |
| `node --test tests/*.test.mjs` (incl. assetversies, toestemming) | **29/29** |
| `tools/e2e-lokaal.sh` (PHP-server + curl, mail uit) | **20/20** |
| Mutatiecontrole toestemming/conversie: default-denied weg, Google zonder keuze, duplicaat-/live-/consentcheck weg, verloop genegeerd, `ad_personalization` granted, query niet gestript, intrekken zonder `ga-disable`, conversie-ID in testmodus, ongesleutelde hash | elke mutatie → ≥1 test faalt |
| Mutatiecontrole: capaciteitscheck uit, recordfout na opslag propageren, payloadvergelijking uit, mailstatus-herstel uit, Office-ZIP-check uit, symlinkresolutie uit, halve map niet opruimen | elke mutatie → ≥1 test faalt |

Browseracceptatie, echte mail en Plesk-controles zijn op productie uitgevoerd door eigenaar/integratie (DEPLOY.md, status). Cachefix na oplevering: assets met `?v=<inhoudshash>`, HTML `no-cache`, PHP `no-store`; bij te houden met `tools/asset-versies.mjs`.

## Risico's

1. **Mail**: `mail()` = overdracht aan de lokale MTA. Op productie geslaagd (beide mails in de inbox van calculatie@, DKIM hersteld). Aflevering blijft afhankelijk van SPF/DKIM/DMARC; mislukte mail staat in `mailstatus.json` en de foutlog.
2. **Bewaartermijn** hangt van de dagelijkse geplande taak af; de meldingsmail moet handmatig worden verwijderd. Plesk-back-ups kunnen langer bewaren.
3. **Capaciteit**: aanvragen met `BEWAREN` blijven meetellen in de 1 GiB; tijdig naar het dossier verplaatsen. Misbruik kan de opslag vullen tot de limiet; dan worden nieuwe aanvragen tijdelijk geweigerd (eigenaar krijgt een waarschuwing), nooit bestaande verwijderd.
4. **Geen virusscan**. XLS (oud formaat) kan macro's bevatten — die worden niet gedetecteerd; CSV kan formules bevatten. Openen met actuele software, macro's uit.
5. **Limiet per bron**: gedeelde netwerken delen een teller; de globale limiet kan door misbruik worden opgemaakt (e-mailroute blijft zichtbaar). Controleer in Fase B (B8) dat REMOTE_ADDR het echte client-IP is.
6. **Privacy**: bij uitvoering gebruikte hulpmiddelen moeten echt vooraf met de klant worden afgestemd, zoals nu beloofd. Verwerkersovereenkomsten Cloud86/Jortt en de handelsnaam "CalcBuro B.V." blijven juridische aandachtspunten.
7. **Configuratie**: de repository-`config.php` staat op `'uit'`. Upload die nooit over de live-configuratie (DEPLOY §0).
8. **Google Ads**: gedrag van `gtag.js` zelf is niet lokaal getest (geen verzoeken naar Google in tests). Na publicatie controleren met Tag Assistant (DEPLOY §0a). In het Ads-account moeten **automatische/handmatige enhanced conversions uit** blijven; dat kan de website niet afdwingen. Conversies van bezoekers zonder toestemming of met verloren JSON-antwoord worden bewust niet gemeten (geen omweg).

## Mappen

```
httpdocs/        → /var/www/vhosts/calcburo.nl/httpdocs
calcburo-app/    → /var/www/vhosts/calcburo.nl/calcburo-app   (buiten docroot)
private/         → /var/www/vhosts/calcburo.nl/private        (buiten docroot)
private/calcburo/LEESMIJ.txt   alleen uitleg; runtime-inhoud (aanvragen, geheim.key) staat nooit in git
tests/           selftest.php, *.test.mjs (o.a. toestemming.test.mjs), http-rooktest.php
tools/           php.sh (native of WASM), e2e-lokaal.sh, asset-versies.mjs
```
