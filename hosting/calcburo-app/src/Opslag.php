<?php
declare(strict_types=1);

namespace CalcBuro;

use RuntimeException;
use Throwable;

/** Opslaglimiet bereikt: niets opgeslagen, bestaande aanvragen onaangeroerd. */
final class CapaciteitVol extends RuntimeException
{
}

/** De referentie is al in gebruik door een andere aanvraag. */
final class ReferentieBezet extends RuntimeException
{
}

/**
 * Private opslag buiten de documentroot:
 *   <data>/aanvragen/<REF>/aanvraag.json      gegevens; als laatste atomair geschreven = aanvraag compleet
 *   <data>/aanvragen/<REF>/mailstatus.json    mailstatus, apart van de opslag
 *   <data>/aanvragen/<REF>/documenten/<random>.<ext>
 * Bestandsnamen zijn willekeurig; de oorspronkelijke naam staat alleen (opgeschoond) in aanvraag.json.
 *
 * Capaciteit: meten en opslaan gebeuren onder één globaal slot (<data>/opslag.lock), zodat
 * gelijktijdige aanvragen samen nooit boven de limiet uitkomen. Er wordt nooit iets verwijderd
 * om ruimte te maken.
 */
final class Opslag
{
    private const REF_TEKENS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    /** Ruimte voor aanvraag.json, mailstatus.json en mapadministratie per aanvraag. */
    public const OVERHEAD_BYTES = 65536;

    /** @param callable(string, string): bool $verplaats verplaatst een upload (productie: move_uploaded_file) */
    public function __construct(private string $dataDir, private $verplaats, private int $maxBytes)
    {
    }

    public function aanvragenMap(): string
    {
        return $this->dataDir . '/aanvragen';
    }

    public static function nieuweReferentie(): string
    {
        $ref = 'CB-';
        for ($i = 0; $i < 8; $i++) {
            $ref .= self::REF_TEKENS[random_int(0, strlen(self::REF_TEKENS) - 1)];
        }
        return $ref;
    }

    public static function isReferentie(string $ref): bool
    {
        return preg_match('/^CB-[' . self::REF_TEKENS . ']{8}$/', $ref) === 1;
    }

    /** Totale grootte van alle bestanden onder aanvragen/ (symbolische links tellen niet mee). */
    public function gebruik(): int
    {
        $totaal = 0;
        $map = $this->aanvragenMap();
        if (!is_dir($map)) {
            return 0;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($map, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iter as $bestand) {
            if (!$bestand->isLink() && $bestand->isFile()) {
                $totaal += (int) $bestand->getSize();
            }
        }
        return $totaal;
    }

    /**
     * Leest een complete aanvraag van deze aanvraag-ID terug, of null als die er (nog) niet is.
     *
     * @return array{referentie: string, map: string, documenten: list<array<string, mixed>>}|null
     */
    public function lees(string $referentie, string $idHash): ?array
    {
        if (!self::isReferentie($referentie)) {
            return null;
        }
        $map = $this->aanvragenMap() . '/' . $referentie;
        $data = json_decode((string) @file_get_contents($map . '/aanvraag.json'), true);
        if (!is_array($data) || !hash_equals((string) ($data['aanvraag_id_hash'] ?? ''), $idHash)) {
            return null;
        }
        return ['referentie' => $referentie, 'map' => $map, 'documenten' => is_array($data['documenten'] ?? null) ? $data['documenten'] : []];
    }

    /**
     * Slaat de aanvraag volledig op onder de gegeven referentie, of gooit een uitzondering.
     * Een eerder half achtergebleven map van dezelfde referentie (crash) wordt eerst opgeruimd;
     * een complete map van een andere aanvraag geeft ReferentieBezet. Bij een fout wordt alles
     * wat deze poging schreef verwijderd.
     *
     * @param array<string, string> $velden
     * @param list<array{naam: string, tmp: string, grootte: int, ext: string, mime: string}> $bestanden
     * @return array{referentie: string, map: string, documenten: list<array<string, mixed>>}
     */
    public function bewaar(string $referentie, string $idHash, array $velden, array $bestanden, string $modus, int $nu): array
    {
        if (!self::isReferentie($referentie)) {
            throw new RuntimeException('referentie');
        }
        Bestand::privateMap($this->aanvragenMap());
        $slot = Bestand::vergrendel($this->dataDir . '/opslag.lock');
        try {
            $map = $this->aanvragenMap() . '/' . $referentie;
            if (is_link($map)) {
                throw new RuntimeException('map_is_link');
            }
            if (is_file($map . '/aanvraag.json')) {
                throw new ReferentieBezet('referentie_bezet');
            }
            if (is_dir($map)) {
                Bestand::verwijderBoom($map); // half achtergebleven door een eerdere, afgebroken poging
            }

            $nodig = self::OVERHEAD_BYTES + array_sum(array_column($bestanden, 'grootte'));
            if ($this->gebruik() + $nodig > $this->maxBytes) {
                throw new CapaciteitVol('capaciteit');
            }
            if (!@mkdir($map, 0700)) {
                throw new RuntimeException('map_aanmaken');
            }
            return $this->schrijf($map, $referentie, $idHash, $velden, $bestanden, $modus, $nu);
        } finally {
            Bestand::ontgrendel($slot);
        }
    }

    /**
     * @param array<string, string> $velden
     * @param list<array{naam: string, tmp: string, grootte: int, ext: string, mime: string}> $bestanden
     * @return array{referentie: string, map: string, documenten: list<array<string, mixed>>}
     */
    private function schrijf(string $map, string $referentie, string $idHash, array $velden, array $bestanden, string $modus, int $nu): array
    {
        try {
            $documenten = [];
            if ($bestanden !== []) {
                Bestand::privateMap($map . '/documenten');
            }
            foreach ($bestanden as $b) {
                $opslagnaam = bin2hex(random_bytes(16)) . '.' . $b['ext'];
                $doel = $map . '/documenten/' . $opslagnaam;
                if (!($this->verplaats)($b['tmp'], $doel) || !is_file($doel) || filesize($doel) !== $b['grootte']) {
                    throw new RuntimeException('document');
                }
                @chmod($doel, 0600);
                $documenten[] = [
                    'opslagnaam' => $opslagnaam,
                    'oorspronkelijke_naam' => $b['naam'],
                    'grootte' => $b['grootte'],
                    'mime' => $b['mime'],
                    'sha256' => hash_file('sha256', $doel),
                ];
            }
            // Als laatste: pas met dit bestand geldt de aanvraag als compleet.
            Bestand::atomairSchrijven($map . '/aanvraag.json', Bestand::json([
                'referentie' => $referentie,
                'aanvraag_id_hash' => $idHash,
                'modus' => $modus,
                'aangemaakt_op' => gmdate('c', $nu),
                'aangemaakt_unix' => $nu,
                'velden' => $velden,
                'documenten' => $documenten,
                'bewaren' => 'Verwijderd na de bewaartermijn tenzij er een bestand BEWAREN in deze map staat.',
            ]));
            return ['referentie' => $referentie, 'map' => $map, 'documenten' => $documenten];
        } catch (Throwable $e) {
            Bestand::verwijderBoom($map);
            throw new RuntimeException('opslag', 0, $e);
        }
    }

    /**
     * Eerder vastgelegde mailstatus van deze aanvraag, of null.
     *
     * @return array{mail_intern: string, mail_bevestiging: string}|null
     */
    public function leesMailstatus(string $map): ?array
    {
        $data = json_decode((string) @file_get_contents($map . '/mailstatus.json'), true);
        if (!is_array($data) || !is_string($data['mail_intern'] ?? null) || !is_string($data['mail_bevestiging'] ?? null)) {
            return null;
        }
        return ['mail_intern' => $data['mail_intern'], 'mail_bevestiging' => $data['mail_bevestiging']];
    }

    /** @param array<string, string> $status */
    public function bewaarMailstatus(string $map, array $status, int $nu): bool
    {
        try {
            Bestand::atomairSchrijven($map . '/mailstatus.json', Bestand::json($status + ['bijgewerkt_op' => gmdate('c', $nu)]));
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
