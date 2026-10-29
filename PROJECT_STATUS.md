# CalcBuro — projectstatus

## 🔴 HERSTEL HIER NA CRASH
Live op https://calcburo.nl sinds 29-10-2026. Canonieke bron: `hosting/` in deze repository. Volgende wijziging: zie README "Deployment in het kort" en `hosting/DEPLOY.md` §0 (assetversies, tests, alleen gewijzigde bestanden uploaden, nooit `config.php` uit git uploaden).

## Live status (29-10-2026, door eigenaar/integratie op productie vastgesteld)
- Cloud86/Plesk, PHP 8.3.36 met fileinfo en zip; private map schrijfbaar; private URL geeft 404.
- Aanvraagflow live: testaanvraag met PDF (CB-JPLL7M3J) en definitieve aanvraag (CB-8KTY2K6V) ontvangen; interne melding en bevestiging beide in de inbox van calculatie@; browseraanvraag CB-ZDFWANDY toont correct ontvangstbewijs.
- Dagelijkse opschoontaak 03:17 actief (PHP 8.3, `--uitvoeren`, fouten naar calculatie@).
- Cachefix: JS/CSS 7 dagen browsercache met `?v=<inhoudshash>`; HTML `no-cache`; PHP `no-store`.
- Nieuwe hoofdfoto live. Maildomein: DKIM hersteld.

## Modules
- ✅ Website (homepage, landingspagina's, voorbeeldcalculatie, privacyverklaring)
- ✅ Aanvraagflow PHP: validatie incl. Office-ZIP-controle, limieten, opslagcapaciteit 1 GiB, idempotentie op inhoud, crashherstel, mail via hosting
- ✅ Bewaartermijn 90 dagen via dagelijkse opschoning; meldingsmail handmatig verwijderen
- ✅ Tests: PHP 51 (8.3 en 8.5), Node 16, e2e 17; root-preview 840
- ⏳ Organisatorisch: verwerkersovereenkomsten Cloud86/Jortt, handelsnaam "CalcBuro B.V." juridisch toetsen

## Afgesloten (historisch)
Herpositionering, landingspagina's en preview-reviews: zie REVIEWSTATUS.md, ACCEPTATIE.md en `archief/`.
