<?php
declare(strict_types=1);

namespace CalcBuro;

/**
 * Aanvraaglimiet met vast tijdvenster in één vergrendeld JSON-bestand.
 * Sleutels zijn HMAC's van netwerkadres en e-mailadres met een lokale geheime sleutel;
 * het adres zelf wordt nergens opgeslagen.
 */
final class Limiet
{
    /**
     * @param array<string, array{0: int, 1: int}> $limieten soort => [maximum, venster in seconden]
     */
    public function __construct(
        private string $bestand,
        private string $geheim,
        private array $limieten,
    ) {
    }

    public function sleutel(string $soort, string $waarde): string
    {
        return $soort . ':' . substr(hash_hmac('sha256', $soort . '|' . $waarde, $this->geheim), 0, 40);
    }

    /** IPv6 wordt op /64 gegroepeerd: één aansluiting heeft vaak een heel /64-blok. */
    public static function netwerkBron(string $remoteAddr): ?string
    {
        $bin = @inet_pton($remoteAddr);
        if ($bin === false) {
            return null;
        }
        return strlen($bin) === 16 ? bin2hex(substr($bin, 0, 8)) . '::/64' : $remoteAddr;
    }

    /**
     * Toetst en verhoogt alle tellers in één vergrendelde bewerking.
     * Een geweigerde poging verhoogt niets, zodat de globale teller niet door afwijzingen opraakt.
     *
     * @return array{0: bool, 1: ?string, 2: int} [toegestaan, overschreden soort, seconden tot vrij]
     */
    public function neem(string $ip, string $email, int $nu): array
    {
        $sleutels = [
            'ip' => $this->sleutel('ip', $ip),
            'email' => $this->sleutel('email', strtolower($email)),
            'globaal' => 'globaal',
        ];
        $h = Bestand::vergrendel($this->bestand);
        try {
            $tellers = Bestand::leesVergrendeld($h) ?? [];
            $tellers = $this->opgeruimd($tellers, $nu);
            foreach ($sleutels as $soort => $sleutel) {
                [$max, $venster] = $this->limieten[$soort];
                [$start, $aantal] = $tellers[$sleutel] ?? [$nu, 0];
                if ($nu - $start >= $venster) {
                    [$start, $aantal] = [$nu, 0];
                }
                if ($aantal >= $max) {
                    return [false, $soort, max(1, $start + $venster - $nu)];
                }
            }
            foreach ($sleutels as $soort => $sleutel) {
                $venster = $this->limieten[$soort][1];
                [$start, $aantal] = $tellers[$sleutel] ?? [$nu, 0];
                $tellers[$sleutel] = ($nu - $start >= $venster) ? [$nu, 1] : [$start, $aantal + 1];
            }
            Bestand::schrijfVergrendeld($h, $tellers);
            return [true, null, 0];
        } finally {
            Bestand::ontgrendel($h);
        }
    }

    /**
     * Verwijdert verlopen tellers zodat het bestand begrensd blijft.
     *
     * @param array<string, mixed> $tellers
     * @return array<string, array{0: int, 1: int}>
     */
    private function opgeruimd(array $tellers, int $nu): array
    {
        $langste = max(array_map(static fn (array $l): int => $l[1], $this->limieten));
        $schoon = [];
        foreach ($tellers as $sleutel => $waarde) {
            if (is_string($sleutel) && is_array($waarde) && count($waarde) === 2
                && is_int($waarde[0]) && is_int($waarde[1]) && $nu - $waarde[0] < $langste) {
                $schoon[$sleutel] = $waarde;
            }
        }
        return $schoon;
    }
}
