<?php
declare(strict_types=1);

namespace CalcBuro;

/**
 * HTML voor de aanvraagpagina en de resultaatpagina (route zonder JavaScript).
 * Kop en voet volgen de bestaande huisstijl van calcburo.nl; alle dynamische waarden worden ge-escaped.
 */
final class Weergave
{
    public const VELDLABELS = [
        'naam' => 'Naam', 'bedrijf' => 'Bedrijf', 'email' => 'Zakelijk e-mailadres', 'telefoon' => 'Telefoon',
        'rol' => 'Uw rol', 'uitwerking' => 'Gewenste uitwerking', 'projectlocatie' => 'Projectlocatie',
        'planning' => 'Gewenste oplevering / inschrijfdatum', 'project' => 'Projectomschrijving',
    ];

    public const VELDFOUTEN = ['verplicht' => 'is verplicht', 'te_lang' => 'is te lang', 'ongeldig' => 'is ongeldig'];

    public static function e(string $tekst): string
    {
        return htmlspecialchars($tekst, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Publieke tekst over de bevestigingsmail. Claimt verzending, geen aflevering; technische
     * status blijft in de backend (mailstatus.json).
     */
    public static function mailTekst(string $intern, string $bevestiging): string
    {
        $delen = [];
        $delen[] = match ($bevestiging) {
            'geaccepteerd' => 'De ontvangstbevestiging is verstuurd. Controleer eventueel uw map met ongewenste e-mail en bewaar dit referentienummer.',
            'uitgeschakeld', 'in_behandeling' => 'Bewaar dit referentienummer.',
            default => 'Uw aanvraag is ontvangen, maar de ontvangstbevestiging per e-mail kon niet worden verstuurd. Bewaar dit referentienummer.',
        };
        if (!in_array($intern, ['geaccepteerd', 'in_behandeling', 'uitgeschakeld'], true)) {
            $delen[] = 'Mogelijk hebben wij uw aanvraag niet direct gezien. Mail uw referentienummer naar calculatie@calcburo.nl, dan pakken wij hem direct op.';
        }
        return implode(' ', $delen);
    }

    public static function kop(string $titel, string $beschrijving, bool $metAanvraagScript, bool $indexeren = false): string
    {
        $script = $metAanvraagScript ? "\n  <script src=\"/assets/js/aanvraag.js?v=8be18622bb\" defer></script>" : '';
        $robots = $indexeren ? 'index,follow' : 'noindex,follow';
        return <<<HTML
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="{$robots}">
  <link rel="canonical" href="https://calcburo.nl/aanvraag.php">
  <title>{$titel}</title>
  <meta name="description" content="{$beschrijving}">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="CalcBuro">
  <meta property="og:locale" content="nl_NL">
  <meta property="og:title" content="{$titel}">
  <meta property="og:description" content="{$beschrijving}">
  <meta property="og:url" content="https://calcburo.nl/aanvraag.php">
  <meta property="og:image" content="https://calcburo.nl/assets/img/bouwkundige-controle.jpg">
  <meta property="og:image:width" content="1600">
  <meta property="og:image:height" content="844">
  <meta property="og:image:alt" content="Twee professionals beoordelen digitale bouwtekeningen en papieren plannen">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="theme-color" content="#f7f6f1">
  <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="/assets/css/styles.css?v=6ba9faa0dc">
  <link rel="stylesheet" href="/assets/css/aanvraag.css?v=9e4e249329">
  <script src="/assets/js/main.js?v=31b582f31d" defer></script>{$script}
</head>
<body id="top">
  <a class="skip-link" href="#inhoud">Direct naar de inhoud</a>

  <header class="site-header">
    <div class="wrap">
      <nav class="nav" aria-label="Hoofdnavigatie">
        <a class="brand" href="/index.html" aria-label="CalcBuro, naar de homepage">
          <svg class="brand-mark" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 21V10l9-7 9 7v11" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M3 21h18M9 21v-6h6v6" fill="none" stroke="currentColor" stroke-width="2"/></svg>
          <span class="brand-word">Calc<span>Buro</span></span>
        </a>
        <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="hoofdmenu" hidden>
          <span class="menu-toggle-icon" aria-hidden="true"><span></span><span></span><span></span></span>
          <span class="menu-toggle-label">Menu</span>
        </button>
        <div class="nav-panel" id="hoofdmenu">
          <ul class="nav-links">
            <li><a href="/index.html#diensten">Diensten</a></li>
            <li><a href="/index.html#werkwijze">Werkwijze</a></li>
            <li><a href="/index.html#tarieven">Tarieven</a></li>
            <li><a href="/index.html#technologie">Technologie</a></li>
            <li><a href="/index.html#vragen">Veelgestelde vragen</a></li>
          </ul>
          <a class="button nav-cta" href="/aanvraag.php" aria-current="page">Calculatie aanvragen <span class="arrow" aria-hidden="true">↗</span></a>
        </div>
      </nav>
    </div>
  </header>

HTML;
    }

    public static function voet(): string
    {
        return <<<HTML

  <footer class="site-footer">
    <div class="wrap foot">
      <div class="foot-brand">
        <a class="brand" href="/index.html" aria-label="CalcBuro, naar de homepage">
          <svg class="brand-mark" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 21V10l9-7 9 7v11" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M3 21h18M9 21v-6h6v6" fill="none" stroke="currentColor" stroke-width="2"/></svg>
          <span class="brand-word">Calc<span>Buro</span></span>
        </a>
        <p>Calculatiebureau voor aanbestedingen en bouwkosten.</p>
        <p class="foot-company">CalcBuro B.V. · KvK 97494380</p>
        <p class="small">Btw: NL868076314B01<br>IBAN: NL19 INGB 0113 8268 50</p>
      </div>
      <nav class="foot-nav" aria-label="Voettekst">
        <div>
          <p class="foot-head">Voor wie</p>
          <ul class="foot-links">
            <li><a href="/aannemers.html">Aannemers</a></li>
            <li><a href="/ontwikkelaars.html">Ontwikkelaars</a></li>
            <li><a href="/publieke-opdrachtgevers.html">Publieke opdrachtgevers</a></li>
          </ul>
        </div>
        <div>
          <p class="foot-head">Diensten</p>
          <ul class="foot-links">
            <li><a href="/inschrijfbegroting.html">Inschrijfbegroting</a></li>
            <li><a href="/hoeveelhedenstaat.html">Hoeveelhedenstaat</a></li>
            <li><a href="/begrotingscontrole.html">Begrotingscontrole</a></li>
          </ul>
        </div>
        <div>
          <p class="foot-head">Contact</p>
          <ul class="foot-links">
            <li><a href="/index.html#werkwijze">Werkwijze</a></li>
            <li><a href="/aanvraag.php">Calculatie aanvragen</a></li>
            <li><a href="mailto:info@calcburo.nl">info@calcburo.nl</a></li>
            <li><a href="tel:+31852009601">085 200 96 01</a></li>
          </ul>
        </div>
        <div>
          <p class="foot-head">Meer</p>
          <ul class="foot-links">
            <li><a href="/voorbeeldcalculatie.html">Voorbeeldcalculatie</a></li>
            <li><a href="/index.html#tarieven">Tarieven</a></li>
            <li><a href="/privacy.html">Privacyverklaring</a></li>
          </ul>
        </div>
      </nav>
    </div>
  </footer>
</body>
</html>

HTML;
    }

    /** Processtappen. Op mobiel onder het formulier en ingeklapt (main.js); op desktop altijd open. */
    private static function werkwijze(): string
    {
        return <<<HTML
          <details class="intake-proces" open data-mobiel-dicht>
            <summary class="intake-sub">Wat gebeurt er na uw aanvraag?</summary>
            <ol class="intake-steps">
              <li><strong>Ontvangst.</strong> U ziet direct een referentienummer.</li>
              <li><strong>Beoordeling.</strong> Wij beoordelen uw vraag, de stukken en de planning.</li>
              <li><strong>Voorstel.</strong> U ontvangt een voorstel met scope, planning en vergoeding.</li>
              <li><strong>Akkoord.</strong> Pas na uw akkoord op het voorstel starten wij.</li>
              <li><strong>Factuur.</strong> Betaling per factuur, volgens de afspraken in uw voorstel.</li>
            </ol>
          </details>
HTML;
    }

    /**
     * Vooraf gekozen rol/uitwerking uit links als aanvraag.php?rol=aannemer. Alleen bekende waarden.
     *
     * @param array<string, mixed> $get
     * @return list<string>
     */
    public static function voorkeuze(array $get): array
    {
        $keuzes = [];
        foreach (['rol' => Validatie::ROLLEN, 'uitwerking' => Validatie::UITWERKINGEN] as $naam => $toegestaan) {
            $waarde = $get[$naam] ?? null;
            if (is_string($waarde) && in_array($waarde, $toegestaan, true)) {
                $keuzes[] = $waarde;
            }
        }
        return $keuzes;
    }

    /** @param list<string> $voorkeuze */
    public static function aanvraagPagina(bool $actief, string $csrf, string $aanvraagId, string $testSleutel, bool $testmodus, array $voorkeuze = []): string
    {
        $html = self::kop('Calculatie aanvragen | CalcBuro', 'Vraag een bouwcalculatie, hoeveelhedenstaat of begrotingscontrole aan bij CalcBuro. Beschrijf uw project en stuur tekeningen of bestek direct mee.', $actief, true);
        $html .= "  <main id=\"inhoud\" tabindex=\"-1\">\n    <section class=\"section band contact intake\" aria-labelledby=\"aanvragen-titel\">\n      <div class=\"wrap intake-grid\">\n";
        $html .= "        <div class=\"intake-intro\">\n";
        $html .= "          <p class=\"eyebrow\">Calculatie aanvragen</p>\n";
        $html .= "          <h1 id=\"aanvragen-titel\">Vertel ons wat u wilt laten calculeren.</h1>\n";
        $html .= "          <p>Beschrijf uw project, uw rol en de gewenste uitwerking. Tekeningen, bestek of een hoeveelhedenstaat kunt u direct meesturen; dat is niet verplicht.</p>\n        </div>\n";
        $formulier = $actief ? self::formulier($csrf, $aanvraagId, $testSleutel, $testmodus) : self::nietBeschikbaar();
        foreach ($voorkeuze as $waarde) {
            $formulier = str_replace('<option value="' . $waarde . '">', '<option value="' . $waarde . '" selected>', $formulier);
        }
        $html .= "        <div class=\"intake-formulier\">\n" . $formulier . "        </div>\n";
        $html .= "        <div class=\"intake-info\">\n" . self::werkwijze();
        $html .= "\n          <p class=\"small\">Liever direct contact? Mail naar <a href=\"mailto:calculatie@calcburo.nl\">calculatie@calcburo.nl</a> of bel <a href=\"tel:+31852009601\">085 200 96 01</a>.</p>\n        </div>\n";
        $html .= "      </div>\n    </section>\n  </main>\n";
        return $html . self::voet();
    }

    private static function nietBeschikbaar(): string
    {
        return <<<HTML
          <div class="intake-notice" role="note">
            <p><strong>Online aanvragen is op dit moment niet beschikbaar.</strong></p>
            <p>Mail uw aanvraag en eventuele documenten naar <a href="mailto:calculatie@calcburo.nl">calculatie@calcburo.nl</a> of bel <a href="tel:+31852009601">085 200 96 01</a>.</p>
          </div>

HTML;
    }

    private static function formulier(string $csrf, string $aanvraagId, string $testSleutel, bool $testmodus): string
    {
        $csrf = self::e($csrf);
        $id = self::e($aanvraagId);
        $test = $testSleutel !== '' ? "\n            <input type=\"hidden\" name=\"test_sleutel\" value=\"" . self::e($testSleutel) . '">' : '';
        $testBanner = $testmodus ? "\n          <p class=\"intake-test\" role=\"note\">Testmodus: alleen voor de acceptatietest door CalcBuro.</p>" : '';
        $max = Validatie::MAX_BESTANDEN;
        return <<<HTML
{$testBanner}
          <form class="request-form" id="aanvraagformulier" action="/api/aanvraag.php" method="post" enctype="multipart/form-data" aria-labelledby="aanvragen-titel" data-max-bestanden="{$max}" data-max-bestand="20971520" data-max-totaal="52428800">
            <input type="hidden" name="csrf" value="{$csrf}">
            <input type="hidden" name="aanvraag_id" value="{$id}">{$test}
            <p class="small form-note">Velden met * zijn verplicht.</p>
            <div class="row">
              <div class="field">
                <label for="veld-naam">Naam *</label>
                <input id="veld-naam" name="naam" type="text" autocomplete="name" required maxlength="150" aria-describedby="fout-naam">
                <p class="field-error" id="fout-naam" hidden></p>
              </div>
              <div class="field">
                <label for="veld-bedrijf">Bedrijf</label>
                <input id="veld-bedrijf" name="bedrijf" type="text" autocomplete="organization" maxlength="150" aria-describedby="fout-bedrijf">
                <p class="field-error" id="fout-bedrijf" hidden></p>
              </div>
            </div>
            <div class="row">
              <div class="field">
                <label for="veld-email">Zakelijk e-mailadres *</label>
                <input id="veld-email" name="email" type="email" autocomplete="email" inputmode="email" required maxlength="200" aria-describedby="fout-email">
                <p class="field-error" id="fout-email" hidden></p>
              </div>
              <div class="field">
                <label for="veld-telefoon">Telefoon</label>
                <input id="veld-telefoon" name="telefoon" type="tel" autocomplete="tel" maxlength="50" aria-describedby="fout-telefoon">
                <p class="field-error" id="fout-telefoon" hidden></p>
              </div>
            </div>
            <div class="row">
              <div class="field">
                <label for="veld-rol">Uw rol</label>
                <select id="veld-rol" name="rol">
                  <option value="">Maak een keuze</option>
                  <option value="aannemer">Aannemer</option>
                  <option value="ontwikkelaar">Ontwikkelaar</option>
                  <option value="publieke-opdrachtgever">Publieke opdrachtgever</option>
                  <option value="anders">Anders</option>
                </select>
              </div>
              <div class="field">
                <label for="veld-uitwerking">Gewenste uitwerking</label>
                <select id="veld-uitwerking" name="uitwerking">
                  <option value="">Maak een keuze</option>
                  <option value="inschrijfbegroting">Inschrijfbegroting</option>
                  <option value="hoeveelhedenstaat">Hoeveelhedenstaat</option>
                  <option value="begrotingscontrole">Begrotingscontrole</option>
                  <option value="volledige-calculatie">Volledige STABU-calculatie</option>
                  <option value="extra-capaciteit">Extra calculatiecapaciteit</option>
                  <option value="nog-afstemmen">Nog afstemmen</option>
                </select>
              </div>
            </div>
            <div class="row">
              <div class="field">
                <label for="veld-locatie">Projectlocatie</label>
                <input id="veld-locatie" name="projectlocatie" type="text" maxlength="200">
              </div>
              <div class="field">
                <label for="veld-planning">Gewenste oplevering / inschrijfdatum</label>
                <input id="veld-planning" name="planning" type="text" maxlength="150">
              </div>
            </div>
            <div class="field">
              <label for="veld-project">Projectomschrijving *</label>
              <textarea id="veld-project" name="project" required maxlength="2000" rows="6" aria-describedby="hint-project fout-project"></textarea>
              <p class="field-hint" id="hint-project">Maximaal 2000 tekens. Vermeld ook vragenrondes of nota's van inlichtingen.</p>
              <p class="field-error" id="fout-project" hidden></p>
            </div>
            <div class="field">
              <label for="veld-documenten">Documenten (optioneel)</label>
              <input id="veld-documenten" name="documenten[]" type="file" multiple accept=".pdf,.ifc,.xlsx,.xls,.csv,.docx,.jpg,.jpeg,.png" aria-describedby="hint-documenten fout-documenten">
              <p class="field-hint" id="hint-documenten">PDF, IFC, XLSX, XLS, CSV, DOCX, JPG of PNG. Maximaal 10 bestanden, elk maximaal 20 MB, samen maximaal 50 MB. Uw documenten worden vertrouwelijk en afgeschermd opgeslagen.</p>
              <p class="field-error" id="fout-documenten" hidden></p>
              <ul class="file-list" id="bestandenlijst" aria-live="polite"></ul>
            </div>
            <div class="hp" aria-hidden="true">
              <label for="veld-website">Laat dit veld leeg</label>
              <input id="veld-website" name="website" type="text" tabindex="-1" autocomplete="off">
            </div>
            <p class="small form-explain" id="uitleg-verzending">Na verzenden ziet u direct uw referentienummer. Lees in de <a href="/privacy.html">privacyverklaring</a> hoe wij met uw gegevens en documenten omgaan.</p>
            <button class="button" id="verstuur" type="submit" aria-describedby="uitleg-verzending">Aanvraag versturen <span class="arrow" aria-hidden="true">↗</span></button>
            <p class="form-status" id="status" role="status" aria-live="polite"></p>
          </form>
          <section class="receipt" id="ontvangst" hidden tabindex="-1" aria-labelledby="ontvangst-titel">
            <h2 id="ontvangst-titel">Uw aanvraag is ontvangen</h2>
            <p>Uw referentienummer:</p>
            <p class="receipt-ref" id="ontvangst-ref"></p>
            <p id="ontvangst-docs"></p>
            <p id="ontvangst-mail"></p>
            <p>Vermeld dit nummer bij contact. Wij beoordelen uw aanvraag en nemen contact met u op. U ontvangt eerst een voorstel; pas na uw akkoord starten wij.</p>
          </section>

HTML;
    }

    /** @param array<string, mixed> $body */
    public static function resultaat(array $body): string
    {
        $ok = ($body['ok'] ?? false) === true && ($body['opgeslagen'] ?? false) === true;
        $html = self::kop($ok ? 'Aanvraag ontvangen | CalcBuro' : 'Aanvraag niet verwerkt | CalcBuro', 'Resultaat van uw calculatieaanvraag bij CalcBuro.', false);
        $html .= "  <main id=\"inhoud\" tabindex=\"-1\">\n    <section class=\"section intake-result\" aria-labelledby=\"resultaat-titel\">\n      <div class=\"wrap\">\n";
        if ($ok) {
            $ref = self::e((string) $body['referentie']);
            $docs = (int) $body['documenten'];
            $mail = self::e(self::mailTekst((string) $body['mail']['intern'], (string) $body['mail']['bevestiging']));
            $docTekst = $docs === 0 ? 'Er zijn geen documenten meegestuurd.' : ($docs === 1 ? '1 document is ontvangen.' : $docs . ' documenten zijn ontvangen.');
            $html .= "        <div class=\"receipt\">\n          <h1 id=\"resultaat-titel\">Uw aanvraag is ontvangen</h1>\n          <p>Uw referentienummer:</p>\n          <p class=\"receipt-ref\">{$ref}</p>\n";
            $html .= "          <p>{$docTekst}</p>\n          <p>{$mail}</p>\n";
            $html .= "          <p>Vermeld dit nummer bij contact. Wij beoordelen uw aanvraag en nemen contact met u op. U ontvangt eerst een voorstel; pas na uw akkoord starten wij.</p>\n";
            $html .= "          <p><a class=\"text-link\" href=\"/index.html\">Terug naar de homepage <span class=\"arrow\" aria-hidden=\"true\">→</span></a></p>\n        </div>\n";
        } else {
            $bericht = self::e((string) ($body['fout']['bericht'] ?? Verwerker::MELDINGEN['niet_verwerkt']));
            $html .= "        <div class=\"intake-notice is-fout\">\n          <h1 id=\"resultaat-titel\">Uw aanvraag is niet verwerkt</h1>\n          <p>{$bericht}</p>\n";
            $velden = $body['fout']['velden'] ?? [];
            if (is_array($velden) && $velden !== []) {
                $html .= "          <ul>\n";
                foreach ($velden as $veld => $code) {
                    $label = self::VELDLABELS[$veld] ?? 'Een veld';
                    $html .= '            <li>' . self::e($label . ' ' . (self::VELDFOUTEN[$code] ?? 'is ongeldig')) . ".</li>\n";
                }
                $html .= "          </ul>\n";
            }
            $html .= "          <p>Ga met de terugknop van uw browser terug naar het formulier, of <a href=\"/aanvraag.php\">open een nieuw formulier</a>. U kunt ook mailen naar <a href=\"mailto:calculatie@calcburo.nl\">calculatie@calcburo.nl</a>.</p>\n        </div>\n";
        }
        $html .= "      </div>\n    </section>\n  </main>\n";
        return $html . self::voet();
    }
}
