# CalcBuro — deployment op Cloud86/Plesk (PHP 8.3)

**Status 29-10-2026: live op https://calcburo.nl.** Fase A–C hieronder zijn uitgevoerd en op de echte server getest
(PHP 8.3.36 met fileinfo/zip, schrijfrechten private; testaanvraag + PDF CB-JPLL7M3J en CB-8KTY2K6V met beide mails
in de inbox van calculatie@; browseraanvraag CB-ZDFWANDY met correct ontvangstbewijs; private URL → 404; dagelijkse
opschoontaak 03:17 actief). Hoofdstuk 0 beschrijft hoe je een volgende wijziging publiceert; hoofdstuk 1–5 blijven als
naslag voor een herinstallatie.
Geen Supabase, geen Resend, geen API-sleutels, geen accountwachtwoorden. Betaling loopt per factuur buiten de website.

## 0. Een wijziging publiceren (na livegang)

1. Wijzig in `hosting/httpdocs` (site) of `hosting/calcburo-app/src` (aanvraagflow).
2. Gewijzigde CSS/JS? `node tools/asset-versies.mjs` — zet `?v=<SHA-256, 10 tekens>` in alle HTML en in
   `Weergave.php`. De server cachet JS/CSS 7 dagen; HTML revalideert (`no-cache`), PHP is `no-store`.
3. Testen vanuit `hosting/`: `php tests/selftest.php && node --test tests/*.test.mjs && tools/e2e-lokaal.sh`
   (productielijn: `PHP=8.3 npx -y @php-wasm/cli@latest tests/selftest.php`).
4. Back-up maken (Plesk) en alleen de gewijzigde bestanden uploaden naar dezelfde paden.
5. **Nooit `calcburo-app/config.php` uit de repository uploaden.** In de repository staat die bewust op `'uit'`;
   op de server staat de live-configuratie. Wijzig instellingen alleen in het serverbestand.
6. Nooit iets in `private/calcburo/` overschrijven of verwijderen (aanvragen, `geheim.key`, sloten).

### 0a. Wijziging cookietoestemming + Google Ads-conversie (29-10-2026, gepubliceerd en gecontroleerd)

Gepubliceerd na back-up calcburo-voor-ads-meting-20261029.zip buiten httpdocs. Live controle: Google-verzoeken na toestemming 200/204, _gcl_aw gezet; geen Google-verzoeken zonder toestemming of na weigering/intrekken. CSP bevat ook het daadwerkelijk gebruikte ad.doubleclick.net. Live API-test CB-7F8DW64G slaagt; geen testconversie naar Google verstuurd. Eerste echte toegeschreven Ads-conversie nog te bevestigen.

Bij opnieuw publiceren alleen deze bestanden; **niet** `config.php`:

```
httpdocs/assets/js/toestemming.js        NIEUW
httpdocs/assets/js/aanvraag.js           GEWIJZIGD  (event na bevestigde opslag, formulier geleegd)
httpdocs/assets/css/styles.css           GEWIJZIGD  (keuzekaart, footerknop)
httpdocs/*.html (alle 10)                GEWIJZIGD  (CSP met Google Ads-domeinen, toestemming.js, Cookie-instellingen; privacy.html nieuwe sectie)
calcburo-app/src/Http.php                GEWIJZIGD  (CSP_PAGINA; testmodus strikt)
calcburo-app/src/Weergave.php            GEWIJZIGD  (toestemming.js, footerknop, assetversies)
calcburo-app/src/Verwerker.php           GEWIJZIGD  (live + conversie_id in JSON-succes)
calcburo-app/src/Idempotentie.php        GEWIJZIGD  (conversieId = HMAC)
```

Volgorde: eerst `calcburo-app/src/*` (oude pagina's negeren de nieuwe JSON-velden), dan assets, dan HTML.

Controle na publicatie (eigen browser, privévenster):
1. Homepage: keuzekaart zichtbaar; DevTools → Netwerk: **geen** verzoek naar `google*`/`doubleclick`. Weigeren → nog steeds niets; volgende pagina geen kaart.
2. Footer → Cookie-instellingen → Accepteren: één verzoek `gtag/js?id=AW-18516347577`; Tag Assistant toont consent `ad_storage`/`ad_user_data` granted, `ad_personalization`/`analytics_storage` denied.
3. Landing met `?gclid=TEST123`: cookie `_gcl_aw` gezet (controleer dat `send_page_view: false` de koppeling niet hindert; zo niet, melden).
4. Eén echte live-aanvraag → precies één conversiehit met `transaction_id` (32 hex, geen `CB-`); geen naam/e-mail/`em=` in de hit; zelfde formulier opnieuw versturen → geen tweede hit. Daarna die aanvraag verwijderen. In Google Ads de conversie pas na enkele uren zichtbaar.
5. Cookie-instellingen → Weigeren: pagina herlaadt, `_gcl_*` weg, geen nieuwe Google-verzoeken.
6. Google Ads → Conversies → instellingen: enhanced conversions (ook "automatisch") **uit** laten.

Terugdraaien: de vorige versies van bovenstaande bestanden terugzetten (back-up). Alleen `toestemming.js` weghalen is onvoldoende, omdat de HTML ernaar verwijst.

## 1. Deploytree

Plesk-standaard: de vhost-root is `/var/www/vhosts/calcburo.nl/` (controleer in Plesk → Websites & Domains → Hosting-instellingen → Documentroot = `httpdocs`).

```
/var/www/vhosts/calcburo.nl/
├── httpdocs/                         ← hosting/httpdocs/
│   ├── .htaccess                     GEWIJZIGD  (+ .user.ini afgeschermd, nosniff)
│   ├── .user.ini                     NIEUW      uploadgrenzen PHP-FPM
│   ├── aanvraag.php                  NIEUW      aanvraagpagina (formulier + CSRF-sessie)
│   ├── privacy.html                  NIEUW
│   ├── api/
│   │   ├── .htaccess                 NIEUW      alleen aanvraag.php bereikbaar
│   │   └── aanvraag.php              NIEUW      eindpunt (POST)
│   ├── assets/css/aanvraag.css       NIEUW
│   ├── assets/js/aanvraag.js         NIEUW
│   ├── assets/js/main.js             GEWIJZIGD  (mailto-code verwijderd, menu ongewijzigd)
│   ├── index.html                    GEWIJZIGD  (aanvraagsectie → CTA, privacylink)
│   ├── aannemers.html, ontwikkelaars.html, publieke-opdrachtgevers.html,
│   │   inschrijfbegroting.html, hoeveelhedenstaat.html, begrotingscontrole.html,
│   │   voorbeeldcalculatie.html, aanvullendediensten.html
│   │                                 GEWIJZIGD  (CTA → aanvraag.php, privacylink in footer)
│   ├── sitemap.xml                   GEWIJZIGD  (+ privacy.html)
│   └── robots.txt, assets/img/*, assets/css/styles.css   ongewijzigd (nieuwe foto zit erin)
├── calcburo-app/                     ← hosting/calcburo-app/   BUITEN httpdocs
│   ├── bootstrap.php
│   ├── config.php                    modus 'uit', mail 'uit'
│   ├── src/*.php                     Config, Validatie, Bestand, Log, Limiet, Idempotentie,
│   │                                 Opslag, Mail, Verwerker, Opschoning, Weergave, Http
│   └── bin/opschonen.php             bewaartermijn (alleen CLI)
└── private/calcburo/                 ← hosting/private/calcburo/LEESMIJ.txt
    ├── LEESMIJ.txt                   BUITEN httpdocs; PHP maakt de rest aan (0700/0600):
    ├── geheim.key                    HMAC-sleutel (automatisch, 64 hex)
    ├── limiet/tellers.json
    ├── idempotentie/*.json
    └── aanvragen/CB-XXXXXXXX/{aanvraag.json, mailstatus.json, documenten/<random>.<ext>}
```

Padbepaling in de code (geen aanpassing nodig bij de standaardindeling):
- `httpdocs/aanvraag.php` → `dirname(__DIR__) . '/calcburo-app'`
- `httpdocs/api/aanvraag.php` → `dirname(__DIR__, 2) . '/calcburo-app'`
- opslag → `dirname(calcburo-app) . '/private/calcburo'`; afwijkend pad via `'data_dir'` in `config.php` (absoluut). Ligt `data_dir` binnen de documentroot, dan schakelt de code zichzelf uit.
- Optioneel: omgevingsvariabelen `CALCBURO_APP_DIR` en `CALCBURO_CONFIG`.

## 2. Vooraf controleren in Plesk (eenmalig)

1. **PHP** — vastgesteld door integratie: productie PHP **8.3.36**, `upload_max_filesize`/`post_max_size` 256M, `open_basedir` niet ingesteld. Code getest op PHP 8.3.33 en 8.5. Extensie **fileinfo** moet aan staan (zonder fileinfo weigert de flow documenten; aanvragen zonder documenten blijven werken).
2. **Uploadgrenzen**: `httpdocs/.user.ini` verlaagt voor deze site naar `upload_max_filesize 20M`, `post_max_size 52M`, `max_file_uploads 20` (te grote aanvragen worden dan vroeg en netjes geweigerd). Wordt `.user.ini` niet gelezen, dan gelden de 256M van de server; de applicatie handhaaft 20/50 MB toch zelf.
3. **Opslag**: hostingpakket 5 GB. `opslag_max_bytes` in `config.php` staat op **1 GiB** voor aanvragen + documenten. Bij overschrijding: melding "Uw aanvraag kan op dit moment niet worden verwerkt. Er is niets opgeslagen…", niets verwijderd, en maximaal eens per 6 uur een waarschuwingsmail aan `calculatie@` (zonder persoonsgegevens). Dezelfde verzending kan na het vrijmaken van ruimte opnieuw worden verstuurd.
4. **Mail** (bepaalt of PHP `mail()` werkt):
   - Waar staan de mailboxen `calculatie@` en `info@`? Controleer de MX-records van calcburo.nl.
   - Staan ze **bij Cloud86/Plesk**: Mail-service aan; DKIM aan (Mail-instellingen); SPF bevat de server.
   - Staan ze **elders** (bijv. Microsoft 365/Google): in Plesk de mailservice voor calcburo.nl **uitzetten** (anders levert de server `calculatie@calcburo.nl` lokaal af en komt de melding nooit aan), en het SPF-record van de externe provider uitbreiden met het IP van de Cloud86-server.
   - DMARC-beleid van calcburo.nl noteren; bij `p=reject` zonder DKIM vanaf de webserver wordt de bevestiging geweigerd.
5. **Back-up**: download de huidige `httpdocs` (of Plesk-back-up) vóór elke stap. Referentie: `outputs/calcburo-live`.

## 3. Fase A — onzichtbaar plaatsen (productie blijft gelijk)

Alleen **nieuwe** bestanden; bestaande pagina's niet overschrijven.

1. Upload `calcburo-app/` naar de vhost-root (naast `httpdocs`). `config.php` staat op `'modus' => 'uit'`.
2. Maak `private/calcburo/` aan (Plesk Bestandsbeheer) en plaats `LEESMIJ.txt`. Rechten 0700 op de map.
3. Upload naar `httpdocs/`: `aanvraag.php`, `privacy.html`, `.user.ini`, `api/` (beide bestanden), `assets/css/aanvraag.css`, `assets/js/aanvraag.js`, en de nieuwe `.htaccess` (bestaande regels zijn ongewijzigd, er komen alleen afschermregels bij).
4. Controle (verwacht):
   - `https://calcburo.nl/aanvraag.php` → pagina "Online aanvragen is op dit moment niet beschikbaar" + e-mailroute.
   - `https://calcburo.nl/api/aanvraag.php` (GET) → 405 JSON.
   - `https://calcburo.nl/.user.ini` → 403. `https://calcburo.nl/api/.htaccess` → 403.
   - `https://calcburo.nl/calcburo-app/config.php` en `/private/calcburo/LEESMIJ.txt` → 404.
   - Homepage en dienstpagina's onveranderd (oude mailto-formulier nog actief).

## 4. Fase B — echte acceptatietest (testmodus, alleen met sleutel)

1. Genereer lokaal een testsleutel: `php -r 'echo bin2hex(random_bytes(16)), PHP_EOL;'`.
2. In `calcburo-app/config.php`: `'modus' => 'test'`, `'test_sleutel' => '<sleutel>'`, mail `'modus' => 'php'`. (Mail wordt pas hier geactiveerd, na Fase A.)
3. Open `https://calcburo.nl/aanvraag.php?test=<sleutel>` (zonder sleutel blijft de pagina uit). Test met een eigen extern adres (bijv. privé-Gmail) als aanvrager:

| # | Handeling | Verwacht |
|---|-----------|----------|
| B1 | Aanvraag zonder documenten | Referentie `CB-XXXXXXXX`; map in `private/calcburo/aanvragen/`; `mailstatus.json` = `geaccepteerd` |
| B2 | Aanvraag met PDF + XLSX + IFC | 3 bestanden onder willekeurige namen in `documenten/`; interne mail noemt locatie, geen link/bijlage |
| B3 | Interne mail in `calculatie@` | Afzender `info@calcburo.nl`, onderwerp `[TEST] …`, Antwoorden gaat naar het aanvragersadres |
| B4 | Bevestiging bij extern adres | Alleen referentie + generieke tekst; kopteksten: SPF/DKIM `pass` (ook spammap controleren) |
| B5 | Hernoemde `.exe` als `.pdf`, 11 bestanden, bestand > 20 MB | Duidelijke foutmelding, niets opgeslagen |
| B6 | Zelfde formulier twee keer versturen (dubbelklik/terug) | Zelfde referentie, geen tweede map |
| B6b | Na verzenden via terugknop de omschrijving wijzigen en opnieuw versturen | Melding "al eerder met andere gegevens verstuurd" (409); pagina herladen geeft een nieuw formulier |
| B6c | Mobiel (eigen telefoon) | Titel, korte intro, dan direct het formulier; "Wat gebeurt er na uw aanvraag?" ingeklapt onder het formulier |
| B7 | JavaScript uit in de browser, aanvraag versturen | Resultaatpagina met referentie (noJS-route) |
| B8 | Plesk → Logs: regel `POST /api/aanvraag.php` | Toont **uw eigen publieke IP**, niet 127.0.0.1/proxy (anders delen alle bezoekers één limiet) |
| B9 | `php calcburo-app/bin/opschonen.php` (Plesk Geplande taak "nu uitvoeren" of SSH) | Proefdraaien, niets verwijderd |

4. Testaanvragen daarna verwijderen: de mappen `aanvragen/CB-…` van de test in Bestandsbeheer wissen.
5. Mislukt B3/B4 (`mislukt` in `mailstatus.json` of mail komt niet aan): §2.4 oplossen; `'envelope_afzender' => false` proberen als de hosting `-f` weigert. **Niet live gaan zolang B3 niet aankomt** — de interne melding is het enige signaal van een nieuwe aanvraag.

## 5. Fase C — livegang (pas na geslaagde Fase B, besluit eigenaar)

1. `config.php`: `'modus' => 'live'`, `'test_sleutel' => ''`.
2. Upload de **gewijzigde** bestaande bestanden: alle `*.html`, `assets/js/main.js`, `sitemap.xml`.
3. Plesk → Geplande taken → "PHP-script uitvoeren": `calcburo-app/bin/opschonen.php` met argument `--uitvoeren`, dagelijks (bijv. 03:15), meldingen bij fouten aan. **Verplicht**: de privacyverklaring belooft verwijdering na 90 dagen.
4. Eén echte aanvraag als controle (B1 + B3 + B4), daarna die aanvraag verwijderen.

## 6. Terugdraaien

- Formulier direct uit: `config.php` → `'modus' => 'uit'`. De pagina toont dan de e-mailroute; de API weigert alles (503).
- Oude site terug: bestanden uit `outputs/calcburo-live` terugzetten (overschrijft de gewijzigde pagina's en `main.js`). `private/calcburo/aanvragen` niet verwijderen zonder bewaarbesluit.

## 7. Werkwijze na livegang

- Nieuwe aanvraag: interne mail aan `calculatie@` met referentie en maplocatie. Documenten via Plesk Bestandsbeheer of SFTP openen; er zijn geen downloadlinks.
- Opdracht: map naar het eigen opdrachtdossier verplaatsen, of een leeg bestand `BEWAREN` in de aanvraagmap zetten. Daarna voorstel → akkoord → factuur via Jortt (handmatig).
- Geen opdracht: niets doen; na 90 dagen verwijdert de dagelijkse geplande taak de aanvraag. **Handmatig (beheerinstructie):** verwijder de interne meldingsmail van een aanvraag zonder opdracht na 90 dagen uit de mailbox `calculatie@` (staat zo in de privacyverklaring).
- Controleer af en toe `mailstatus.json` op `mislukt`.

## 8. Lokaal testen

```
php tests/selftest.php                    # 56 tests: validatie, Office-ZIP, limiet, idempotentie op inhoud, capaciteit, foutinjectie/crashherstel, opslag, mail (mock), opschoning, docroot, weergave
node --test tests/*.test.mjs              # 29 tests: frontend-JS, copy, Open Graph, layoutvolgorde, assetversies, rooktest, cookietoestemming/Google Ads (nep-DOM, geen netwerk)
tools/e2e-lokaal.sh                       # 20 checks: PHP-server + curl: sessie/CSRF/upload, duplicaat vs. 409, opslag vol, noJS, testmodus zonder Google (mail uit)
```
Op PHP 8.3 (= productie) zonder lokale 8.3: `PHP=8.3 npx -y @php-wasm/cli@latest tests/selftest.php`. Zonder lokale `php` draait `tools/php.sh` PHP 8 in WebAssembly via `npx @php-wasm/cli` (zelftest en rooktest; de e2e vereist native php).
