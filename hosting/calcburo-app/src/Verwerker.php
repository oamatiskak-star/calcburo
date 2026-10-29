<?php
declare(strict_types=1);

namespace CalcBuro;

use Throwable;

/**
 * Verwerkt één aanvraag. Volgorde: transportcontroles → validatie → idempotentie → limiet →
 * opslag → mail. Succes wordt alleen gemeld als de opslag volledig is gelukt; de mailstatus
 * wordt apart vastgelegd en eerlijk teruggegeven.
 */
final class Verwerker
{
    public const MELDINGEN = [
        'methode' => 'Deze aanvraag kon niet worden verwerkt.',
        'niet_actief' => 'Online aanvragen is op dit moment niet beschikbaar. Mail uw aanvraag naar calculatie@calcburo.nl.',
        'herkomst' => 'Deze aanvraag kon niet worden verwerkt. Verstuur het formulier vanaf calcburo.nl.',
        'sessie' => 'Uw sessie is verlopen. Herlaad de pagina en verstuur het formulier opnieuw.',
        'te_groot' => 'De aanvraag is te groot. Samen maximaal 50 MB, per bestand maximaal 20 MB.',
        'niet_verwerkt' => 'Deze aanvraag kon niet worden verwerkt.',
        'velden' => 'Controleer de gemarkeerde velden.',
        'aanvraag_id' => 'Herlaad de pagina en verstuur het formulier opnieuw.',
        'te_veel_bestanden' => 'U kunt maximaal 10 bestanden meesturen.',
        'bestand_te_groot' => 'Een bestand is groter dan 20 MB.',
        'totaal_te_groot' => 'De bestanden zijn samen groter dan 50 MB.',
        'bestandstype' => 'Een bestand heeft een niet-toegestaan type of klopt niet met de extensie. Toegestaan: PDF, IFC, XLSX, XLS, CSV, DOCX, JPG en PNG.',
        'upload_onvolledig' => 'Een bestand is niet volledig ontvangen. Probeer het opnieuw.',
        'mime_controle_niet_beschikbaar' => 'Documenten kunnen nu niet worden gecontroleerd. Verstuur de aanvraag zonder documenten of mail ze naar calculatie@calcburo.nl.',
        'bestanden_misvormd' => 'De bestanden konden niet worden gelezen. Probeer het opnieuw.',
        'conflict' => 'Dit formulier is al eerder met andere gegevens verstuurd. Herlaad de pagina om een nieuwe aanvraag te doen.',
        'limiet' => 'Er zijn te veel aanvragen verstuurd. Probeer het later opnieuw of mail naar calculatie@calcburo.nl. Uw aanvraag is niet opgeslagen.',
        'opslag' => 'Uw aanvraag kon niet worden opgeslagen. Er is niets ontvangen. Probeer het later opnieuw of mail naar calculatie@calcburo.nl.',
        'capaciteit' => 'Uw aanvraag kan op dit moment niet worden verwerkt. Er is niets opgeslagen. Probeer het later opnieuw of mail naar calculatie@calcburo.nl.',
    ];

    /**
     * @param array<string, mixed> $config
     * @param callable(string): bool $isUpload
     */
    public function __construct(
        private array $config,
        private Opslag $opslag,
        private Limiet $limiet,
        private Idempotentie $idempotentie,
        private Mailer $mailer,
        private $isUpload,
    ) {
    }

    /**
     * @param array<string, mixed> $server $_SERVER
     * @param array<string, mixed> $post $_POST
     * @param array<string, mixed> $files $_FILES
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    public function verwerk(array $server, array $post, array $files, ?string $sessieToken, int $nu): array
    {
        if (($server['REQUEST_METHOD'] ?? '') !== 'POST') {
            return self::fout(405, 'methode', [], ['Allow' => 'POST']);
        }
        if ($this->config['modus'] === 'uit') {
            return self::fout(503, 'niet_actief');
        }
        if (!self::zelfdeHerkomst($server, (string) $this->config['origin'])) {
            return self::fout(403, 'herkomst');
        }
        if ($post === [] && $files === [] && (int) ($server['CONTENT_LENGTH'] ?? 0) > 0) {
            return self::fout(413, 'te_groot'); // post_max_size overschreden: PHP heeft alles weggegooid
        }
        $token = $post['csrf'] ?? null;
        if (!is_string($token) || $sessieToken === null || strlen($sessieToken) < 32 || !hash_equals($sessieToken, $token)) {
            return self::fout(403, 'sessie');
        }
        if ($this->config['modus'] === 'test') {
            $sleutel = $post['test_sleutel'] ?? null;
            if (!is_string($sleutel) || !hash_equals((string) $this->config['test_sleutel'], $sleutel)) {
                return self::fout(503, 'niet_actief');
            }
        }
        if (($post['website'] ?? '') !== '') {
            return self::fout(400, 'niet_verwerkt'); // honeypot: nooit succes melden, niets opslaan
        }

        [$velden, $veldfouten] = Validatie::velden($post);
        if ($veldfouten !== []) {
            return self::fout(422, 'velden', ['velden' => $veldfouten]);
        }
        $lijst = Validatie::normaliseerBestanden($files['documenten'] ?? null);
        if ($lijst === null || count($files) > 1 || ($files !== [] && !isset($files['documenten']))) {
            return self::fout(400, 'bestanden_misvormd');
        }
        [$bestanden, $bestandFout] = Validatie::bestanden($lijst, $this->isUpload);
        if ($bestandFout !== null) {
            $status = in_array($bestandFout, ['bestand_te_groot', 'totaal_te_groot'], true) ? 413 : 422;
            return self::fout($bestandFout === 'mime_controle_niet_beschikbaar' ? 503 : $status, $bestandFout);
        }
        $aanvraagId = $post['aanvraag_id'] ?? null;
        if (!Validatie::isUuidV4($aanvraagId)) {
            return self::fout(400, 'aanvraag_id');
        }

        try {
            return $this->verwerkGeldig($server, $velden, $bestanden, (string) $aanvraagId, $nu);
        } catch (Throwable $e) {
            Log::fout('verwerking', $e);
            return self::fout(500, 'opslag');
        } finally {
            $this->idempotentie->vrijgeven();
        }
    }

    /**
     * Volgorde, gericht op herstelbaarheid zonder dubbele aanvragen:
     *  1. slot op aanvraag-ID; bestaand record → zelfde inhoud: duplicaat (en eventueel herstel), andere inhoud: 409;
     *  2. nieuw: limiet, daarna reservering (status 'bezig' + referentie) vóór de opslag;
     *  3. opslag onder die referentie (capaciteit onder globaal slot); aanvraag.json als laatste;
     *  4. vanaf hier is de aanvraag opgeslagen: latere schrijffouten (record, mailstatus) worden gelogd,
     *     maar veranderen het antwoord nooit in "niet opgeslagen". Een herhaalpoging vindt de aanvraag
     *     terug via referentie + aanvraag.json.
     *
     * @param array<string, mixed> $server
     * @param array<string, string> $velden
     * @param list<array{naam: string, tmp: string, grootte: int, ext: string, mime: string}> $bestanden
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    private function verwerkGeldig(array $server, array $velden, array $bestanden, string $aanvraagId, int $nu): array
    {
        $idHash = $this->idempotentie->idHash($aanvraagId);
        $payload = $this->idempotentie->payloadHash($velden, $bestanden);
        $record = $this->idempotentie->claim($aanvraagId);

        if ($record !== null) {
            $bestaand = $this->opslag->lees((string) ($record['referentie'] ?? ''), $idHash);
            $isOpgeslagen = $bestaand !== null || ($record['status'] ?? '') === 'opgeslagen';
            if ($isOpgeslagen && !hash_equals((string) ($record['payload_hash'] ?? ''), $payload)) {
                return self::fout(409, 'conflict');
            }
            if ($bestaand !== null) {
                return $this->succes(200, $this->rondAf($record, $bestaand, $velden, $nu), true);
            }
            if ($isOpgeslagen) {
                // Record zegt opgeslagen, maar de aanvraag is weg (handmatig verwijderd of verplaatst).
                Log::melding('record_zonder_aanvraag ' . (string) $record['referentie']);
                return $this->succes(200, $record, true);
            }
            // Status 'bezig' zonder complete aanvraag: eerdere poging afgebroken of tijdelijk geweigerd
            // (capaciteit/opslagfout). Er is niets opgeslagen, dus ook gecorrigeerde inhoud is toegestaan.
            // Opnieuw opslaan onder dezelfde referentie, zonder de limiet nogmaals te belasten.
            $record['payload_hash'] = $payload;
            $record['documenten'] = count($bestanden);
            try {
                $this->idempotentie->vastleggen($record);
            } catch (Throwable $e) {
                Log::fout('reservering', $e);
                return self::fout(500, 'opslag');
            }
        } else {
            $ip = Limiet::netwerkBron((string) ($server['REMOTE_ADDR'] ?? ''));
            if ($ip === null) {
                return self::fout(403, 'herkomst');
            }
            [$toegestaan, $soort, $wacht] = $this->limiet->neem($ip, $velden['email'], $nu);
            if (!$toegestaan) {
                Log::melding('limiet_' . $soort);
                return self::fout(429, 'limiet', [], ['Retry-After' => (string) $wacht]);
            }
            $record = [
                'status' => 'bezig',
                'referentie' => Opslag::nieuweReferentie(),
                'payload_hash' => $payload,
                'documenten' => count($bestanden),
                'mail_intern' => 'in_behandeling',
                'mail_bevestiging' => 'in_behandeling',
                'aangemaakt_unix' => $nu,
            ];
            try {
                $this->idempotentie->vastleggen($record); // reservering: zonder deze geen opslag
            } catch (Throwable $e) {
                Log::fout('reservering', $e);
                return self::fout(500, 'opslag');
            }
        }

        $opgeslagen = null;
        for ($poging = 0; $poging < 3 && $opgeslagen === null; $poging++) {
            try {
                $opgeslagen = $this->opslag->bewaar((string) $record['referentie'], $idHash, $velden, $bestanden, (string) $this->config['modus'], $nu);
            } catch (ReferentieBezet) {
                $record['referentie'] = Opslag::nieuweReferentie();
                try {
                    $this->idempotentie->vastleggen($record);
                } catch (Throwable $e) {
                    Log::fout('reservering', $e);
                    return self::fout(500, 'opslag');
                }
            } catch (CapaciteitVol) {
                Log::melding('capaciteit_vol');
                $this->meldCapaciteit($nu);
                return self::fout(503, 'capaciteit', [], ['Retry-After' => '3600']);
            } catch (Throwable $e) {
                Log::fout('opslag', $e);
                return self::fout(500, 'opslag');
            }
        }
        if ($opgeslagen === null) {
            return self::fout(500, 'opslag');
        }
        return $this->succes(201, $this->rondAf($record, $opgeslagen, $velden, $nu), false);
    }

    /**
     * Na een geslaagde opslag: record op 'opgeslagen', mails versturen als dat nog niet gebeurde.
     * Schrijffouten hier worden gelogd en nooit als opslagfout teruggegeven.
     *
     * @param array<string, mixed> $record
     * @param array{referentie: string, map: string, documenten: list<array<string, mixed>>} $opgeslagen
     * @param array<string, string> $velden
     * @return array<string, mixed>
     */
    private function rondAf(array $record, array $opgeslagen, array $velden, int $nu): array
    {
        $record = array_replace($record, [
            'status' => 'opgeslagen',
            'referentie' => $opgeslagen['referentie'],
            'documenten' => count($opgeslagen['documenten']),
        ]);
        if (($record['mail_intern'] ?? 'in_behandeling') !== 'in_behandeling') {
            return $record; // mails zijn al afgehandeld
        }
        $eerder = $this->opslag->leesMailstatus($opgeslagen['map']);
        if ($eerder !== null) {
            // Mails zijn verstuurd, alleen het record kon toen niet worden bijgewerkt: niet opnieuw mailen.
            $record = array_replace($record, $eerder);
            $this->veiligVastleggen($record);
            return $record;
        }
        $this->veiligVastleggen($record);
        $mail = $this->verstuurMails($opgeslagen, $velden, $nu);
        $record = array_replace($record, $mail);
        if (!$this->opslag->bewaarMailstatus($opgeslagen['map'], $mail, $nu)) {
            Log::melding('mailstatus_niet_bewaard ' . $opgeslagen['referentie']);
        }
        $this->veiligVastleggen($record);
        return $record;
    }

    /** @param array<string, mixed> $record */
    private function veiligVastleggen(array $record): void
    {
        try {
            $this->idempotentie->vastleggen($record);
        } catch (Throwable $e) {
            Log::fout('record_bijwerken ' . (string) $record['referentie'], $e);
        }
    }

    /** Waarschuwt de eigenaar (zonder persoonsgegevens) dat de opslaglimiet is bereikt; maximaal eens per 6 uur. */
    private function meldCapaciteit(int $nu): void
    {
        $mc = $this->config['mail'];
        $marker = rtrim((string) $this->config['data_dir'], '/') . '/capaciteit-gemeld';
        if ($mc['modus'] === 'uit' || (is_file($marker) && $nu - (int) @filemtime($marker) < 21600)) {
            return;
        }
        @touch($marker, $nu);
        $this->veiligVersturen((string) $mc['intern_aan'], [
            'onderwerp' => 'Aanvraagformulier calcburo.nl: opslaglimiet bereikt',
            'tekst' => "De opslaglimiet van het aanvraagformulier is bereikt. Nieuwe aanvragen worden geweigerd met een melding
"
                . "dat er niets is opgeslagen. Bestaande aanvragen zijn niet aangeraakt.

"
                . "Actie: verplaats afgeronde aanvragen naar het dossier of verwijder aanvragen zonder opdracht,
"
                . "of verhoog 'opslag_max_bytes' in calcburo-app/config.php binnen het hostingquotum.
",
        ], Berichten::basisHeaders($mc));
    }

    /**
     * @param array{referentie: string, map: string, documenten: list<array<string, mixed>>} $opgeslagen
     * @param array<string, string> $velden
     * @return array{mail_intern: string, mail_bevestiging: string}
     */
    private function verstuurMails(array $opgeslagen, array $velden, int $nu): array
    {
        $mc = $this->config['mail'];
        if ($mc['modus'] === 'uit') {
            return ['mail_intern' => 'uitgeschakeld', 'mail_bevestiging' => 'uitgeschakeld'];
        }
        $ref = $opgeslagen['referentie'];
        $basis = Berichten::basisHeaders($mc);

        $intern = Berichten::intern($ref, (string) $this->config['modus'], $velden, $opgeslagen['documenten'], $opgeslagen['map'] . '/', $nu);
        $internOk = $this->veiligVersturen((string) $mc['intern_aan'], $intern, $basis + ['Reply-To' => $velden['email']]);

        $bevestiging = Berichten::bevestiging($ref);
        $bevestigingOk = $this->veiligVersturen($velden['email'], $bevestiging, $basis + ['Reply-To' => (string) $mc['intern_aan']]);

        if (!$internOk || !$bevestigingOk) {
            Log::melding('mail_niet_geaccepteerd ' . $ref . ' intern=' . (int) $internOk . ' bevestiging=' . (int) $bevestigingOk);
        }
        return [
            'mail_intern' => $internOk ? 'geaccepteerd' : 'mislukt',
            'mail_bevestiging' => $bevestigingOk ? 'geaccepteerd' : 'mislukt',
        ];
    }

    /**
     * @param array{onderwerp: string, tekst: string} $bericht
     * @param array<string, string> $headers
     */
    private function veiligVersturen(string $aan, array $bericht, array $headers): bool
    {
        foreach ($headers + ['To' => $aan, 'Subject' => $bericht['onderwerp']] as $waarde) {
            if (preg_match('/[\r\n\0]/', $waarde)) {
                return false;
            }
        }
        if (!Validatie::isEmail($aan)) {
            return false;
        }
        try {
            return $this->mailer->verstuur($aan, $bericht['onderwerp'], $bericht['tekst'], $headers);
        } catch (Throwable $e) {
            Log::fout('mail', $e);
            return false;
        }
    }

    /**
     * Same-origin-controle. Origin moet exact overeenkomen; zonder Origin alleen
     * Sec-Fetch-Site: same-origin. Dit is een browserfilter (CSRF-verdediging naast het token),
     * geen authenticatie.
     *
     * @param array<string, mixed> $server
     */
    public static function zelfdeHerkomst(array $server, string $origin): bool
    {
        $o = $server['HTTP_ORIGIN'] ?? null;
        if (is_string($o)) {
            return hash_equals($origin, $o);
        }
        return ($server['HTTP_SEC_FETCH_SITE'] ?? null) === 'same-origin';
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, string> $headers
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    public static function fout(int $status, string $code, array $extra = [], array $headers = []): array
    {
        return [
            'status' => $status,
            'body' => ['ok' => false, 'opgeslagen' => false, 'fout' => ['code' => $code, 'bericht' => self::MELDINGEN[$code]] + $extra],
            'headers' => $headers,
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    private function succes(int $status, array $record, bool $duplicaat): array
    {
        // 'live' en 'conversie_id' alleen voor de JavaScript-route: daar beslist de browser (met
        // toestemming) over een Google Ads-conversie. Testmodus krijgt nooit een conversie-ID.
        $live = $this->config['modus'] === 'live';
        return [
            'status' => $status,
            'body' => [
                'ok' => true,
                'opgeslagen' => true,
                'duplicaat' => $duplicaat,
                'referentie' => $record['referentie'],
                'documenten' => $record['documenten'],
                'mail' => ['intern' => $record['mail_intern'], 'bevestiging' => $record['mail_bevestiging']],
                'live' => $live,
                'conversie_id' => $live ? $this->idempotentie->conversieId((string) $record['referentie']) : null,
            ],
            'headers' => [],
        ];
    }
}
