<?php
declare(strict_types=1);

namespace CalcBuro;

use RuntimeException;

/**
 * Idempotentie per aanvraag-ID (UUID v4 uit het formulier).
 *
 * Het record koppelt het ID duurzaam aan een referentie en een hash van de volledige inhoud
 * (velden + documenten). Het record (<hash>.json) staat naast een apart slotbestand (<hash>.lock)
 * en wordt al vóór de opslag vastgelegd met status 'bezig', zodat een herhaalpoging na een crash of schrijffout dezelfde referentie hergebruikt in plaats van een
 * tweede aanvraag aan te maken. Het slotbestand blijft vergrendeld zolang de aanvraag wordt
 * verwerkt; een gelijktijdige dubbele verzending wacht en krijgt daarna het resultaat.
 *
 * Niet final: de zelftest injecteert hier schrijffouten.
 */
class Idempotentie
{
    /** @var resource|null */
    private $slot = null;
    private string $pad = '';
    private string $recordPad = '';
    private bool $vastgelegd = false;

    public function __construct(private string $map, private string $geheim)
    {
    }

    public function idHash(string $aanvraagId): string
    {
        return hash_hmac('sha256', 'idem|' . $aanvraagId, $this->geheim);
    }

    /**
     * Hash over alle ingevulde velden en de inhoud van de documenten (in volgorde).
     * Gelijke hash = identieke herhaling; andere hash met hetzelfde ID = conflict.
     *
     * @param array<string, string> $velden
     * @param list<array{naam: string, tmp: string, grootte: int}> $bestanden
     */
    public function payloadHash(array $velden, array $bestanden): string
    {
        ksort($velden);
        $docs = [];
        foreach ($bestanden as $b) {
            $inhoud = hash_file('sha256', $b['tmp']);
            if ($inhoud === false) {
                throw new RuntimeException('document_lezen');
            }
            $docs[] = [$b['naam'], $b['grootte'], $inhoud];
        }
        $data = json_encode([$velden, $docs], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return hash_hmac('sha256', 'payload|' . $data, $this->geheim);
    }

    /**
     * Vergrendelt het record en geeft het bestaande record terug (of null als het nieuw is).
     *
     * @return array<string, mixed>|null
     */
    public function claim(string $aanvraagId): ?array
    {
        $basis = $this->map . '/' . $this->idHash($aanvraagId);
        $this->pad = $basis . '.lock';
        $this->recordPad = $basis . '.json';
        $this->vastgelegd = false;
        for ($poging = 0; $poging < 5; $poging++) {
            $h = Bestand::vergrendel($this->pad);
            // Een vorige houder kan een leeg slot hebben verwijderd terwijl wij wachtten:
            // alleen doorgaan als ons handvat nog naar het bestand op dit pad wijst.
            $huidig = @stat($this->pad);
            $eigen = fstat($h);
            if ($huidig !== false && $eigen !== false && $huidig['ino'] === $eigen['ino'] && $huidig['dev'] === $eigen['dev']) {
                $this->slot = $h;
                return $this->leesRecord();
            }
            Bestand::ontgrendel($h);
        }
        throw new RuntimeException('slot');
    }

    /** @return array<string, mixed>|null */
    private function leesRecord(): ?array
    {
        if (!is_file($this->recordPad)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($this->recordPad), true);
        if (!is_array($data)) {
            throw new RuntimeException('record_beschadigd');
        }
        return $data;
    }

    /**
     * Schrijft het record atomair (tijdelijk bestand + rename): na een crash staat er
     * altijd het vorige of het nieuwe record, nooit een half record.
     *
     * @param array<string, mixed> $record
     */
    public function vastleggen(array $record): void
    {
        if ($this->slot === null) {
            throw new RuntimeException('geen_slot');
        }
        Bestand::atomairSchrijven($this->recordPad, Bestand::json($record));
        $this->vastgelegd = true;
    }

    public function vrijgeven(): void
    {
        if ($this->slot === null) {
            return;
        }
        // Niets vastgelegd (weigering of limiet): leeg slot opruimen zodat geweigerde pogingen
        // geen bestanden achterlaten. Verwijderen gebeurt onder het slot.
        if (!$this->vastgelegd && !is_file($this->recordPad)) {
            @unlink($this->pad);
        }
        Bestand::ontgrendel($this->slot);
        $this->slot = null;
    }
}
