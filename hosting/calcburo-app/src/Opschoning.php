<?php
declare(strict_types=1);

namespace CalcBuro;

/**
 * Begrensde opschoning van aanvragen ouder dan de bewaartermijn.
 * - Standaard proefdraaien: er wordt niets verwijderd zonder $uitvoeren = true.
 * - Een map met een bestand BEWAREN wordt nooit verwijderd (opdracht/dossier op instructie eigenaar).
 * - Alleen mappen met een geldige referentienaam direct onder aanvragen/; symbolische links worden overgeslagen.
 * - Maximaal $max aanvragen per run.
 */
final class Opschoning
{
    /**
     * @return array{verwijderd: list<string>, bewaard: list<string>, overgeslagen: list<string>, idempotentie: int}
     */
    public static function voerUit(string $dataDir, int $bewaarDagen, int $idemDagen, int $nu, bool $uitvoeren, int $max = 200): array
    {
        $resultaat = ['verwijderd' => [], 'bewaard' => [], 'overgeslagen' => [], 'idempotentie' => 0];
        $grens = $nu - $bewaarDagen * 86400;
        $map = $dataDir . '/aanvragen';

        foreach (is_dir($map) && !is_link($map) ? (scandir($map) ?: []) : [] as $naam) {
            if (count($resultaat['verwijderd']) >= $max) {
                break;
            }
            $pad = $map . '/' . $naam;
            if ($naam === '.' || $naam === '..' || is_link($pad) || !is_dir($pad) || !Opslag::isReferentie($naam)) {
                continue;
            }
            if (file_exists($pad . '/BEWAREN')) {
                $resultaat['bewaard'][] = $naam;
                continue;
            }
            $tijd = self::aangemaakt($pad);
            if ($tijd === null) {
                $resultaat['overgeslagen'][] = $naam;
                continue;
            }
            if ($tijd < $grens) {
                if (!$uitvoeren || Bestand::verwijderBoom($pad)) {
                    $resultaat['verwijderd'][] = $naam;
                } else {
                    $resultaat['overgeslagen'][] = $naam;
                }
            }
        }

        $idemGrens = $nu - $idemDagen * 86400;
        foreach (array_merge(glob($dataDir . '/idempotentie/*.json') ?: [], glob($dataDir . '/idempotentie/*.lock') ?: []) as $bestand) {
            if (!is_link($bestand) && (int) filemtime($bestand) < $idemGrens) {
                $resultaat['idempotentie']++;
                if ($uitvoeren) {
                    @unlink($bestand);
                }
            }
        }
        return $resultaat;
    }

    private static function aangemaakt(string $pad): ?int
    {
        $json = @file_get_contents($pad . '/aanvraag.json');
        if ($json === false) {
            // half aangemaakte map zonder gegevens: leeftijd van de map zelf
            $m = @filemtime($pad);
            return $m === false ? null : $m;
        }
        $data = json_decode($json, true);
        return is_array($data) && is_int($data['aangemaakt_unix'] ?? null) ? $data['aangemaakt_unix'] : null;
    }
}
