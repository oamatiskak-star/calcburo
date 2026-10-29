# CalcBuro — live website en aanvraagflow (Cloud86/Plesk, PHP 8.3)

Canonieke bron van https://calcburo.nl. **Live sinds 29-10-2026**, op de echte server getest (zie [DEPLOY.md](DEPLOY.md)).
Geen Supabase, geen externe diensten, geen secrets in deze map: `calcburo-app/config.php` staat in de repository op `'uit'`.
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
| Privacy | Verantwoordelijke (alleen op de privacypagina), Cloud86, Jortt, mailbox. Nieuw: aanvraagformulier gebruikt geen AI; hulpmiddelen bij uitvoering worden vooraf afgestemd (geen providernamen, geen doorgifteclaims). Bewaren: "90 dagen; daarna verwijderd bij de dagelijkse opschoning"; meldingsmail handmatig. Zin over bezoekadres verwijderd. |
| Overig | Assetversies via `tools/asset-versies.mjs` (`--check` in de tests). Open Graph/Twitter-card op alle pagina's (titel, beschrijving, canonical, `bouwkundige-controle.jpg`). `aanvraag.php` geïndexeerd en in sitemap. Geen tracking, geen captcha, geen externe diensten. |

## Verificatie (lokaal, 29-10-2026)

| Test | Resultaat |
|------|-----------|
| `php tests/selftest.php` op PHP 8.5.12 (native) | **51/51** |
| Zelfde zelftest op **PHP 8.3.33** (WebAssembly, = productieversie-lijn) + `php -l` alle bestanden | **51/51**, geen syntaxfouten |
| `node --test tests/` (incl. assetversies) | **16/16** |
| `tools/e2e-lokaal.sh` (PHP-server + curl, mail uit) | **17/17** |
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

## Mappen

```
httpdocs/        → /var/www/vhosts/calcburo.nl/httpdocs
calcburo-app/    → /var/www/vhosts/calcburo.nl/calcburo-app   (buiten docroot)
private/         → /var/www/vhosts/calcburo.nl/private        (buiten docroot)
private/calcburo/LEESMIJ.txt   alleen uitleg; runtime-inhoud (aanvragen, geheim.key) staat nooit in git
tests/           selftest.php, *.test.mjs, http-rooktest.php
tools/           php.sh (native of WASM), e2e-lokaal.sh, asset-versies.mjs
```
