<?php
declare(strict_types=1);

namespace CalcBuro;

/**
 * Laadt en controleert de configuratie. Elke afwijking leidt tot modus 'uit' (fail-closed),
 * zodat een kapotte of onvolledige config nooit een half werkend formulier oplevert.
 */
final class Config
{
    public const MODI = ['uit', 'test', 'live'];
    public const MAIL_MODI = ['uit', 'php'];

    /** @return array<string, mixed> */
    public static function standaard(string $appDir): array
    {
        return [
            'modus' => 'uit',
            'test_sleutel' => '',
            'origin' => 'https://calcburo.nl',
            'data_dir' => dirname($appDir) . '/private/calcburo',
            'mail' => [
                'modus' => 'uit',
                'intern_aan' => 'calculatie@calcburo.nl',
                'van' => 'info@calcburo.nl',
                'van_naam' => 'CalcBuro',
                'envelope_afzender' => true,
            ],
            'limieten' => [
                'ip' => [10, 3600],
                'email' => [5, 3600],
                'globaal' => [60, 3600],
            ],
            'bewaartermijn_dagen' => 90,
            'idempotentie_dagen' => 7,
            'opslag_max_bytes' => 1073741824, // 1 GiB voor aanvragen + documenten
        ];
    }

    /**
     * @param array<string, mixed> $overschrijving
     * @return array<string, mixed>
     */
    public static function samenvoegen(string $appDir, array $overschrijving): array
    {
        $basis = self::standaard($appDir);
        $resultaat = array_replace($basis, $overschrijving);
        $resultaat['mail'] = array_replace($basis['mail'], is_array($overschrijving['mail'] ?? null) ? $overschrijving['mail'] : []);
        $resultaat['limieten'] = array_replace($basis['limieten'], is_array($overschrijving['limieten'] ?? null) ? $overschrijving['limieten'] : []);
        $fouten = self::fouten($resultaat);
        if ($fouten !== []) {
            $resultaat['modus'] = 'uit';
            $resultaat['config_fouten'] = $fouten;
        }
        return $resultaat;
    }

    /** @return array<string, mixed> */
    public static function laad(string $appDir): array
    {
        $pad = getenv('CALCBURO_CONFIG') ?: $appDir . '/config.php';
        if (!is_file($pad)) {
            return self::samenvoegen($appDir, ['modus' => 'uit']) + ['config_fouten' => ['config_ontbreekt']];
        }
        $geladen = require $pad;
        return self::samenvoegen($appDir, is_array($geladen) ? $geladen : ['modus' => 'uit']);
    }

    /**
     * @param array<string, mixed> $c
     * @return list<string>
     */
    public static function fouten(array $c): array
    {
        $fouten = [];
        if (!in_array($c['modus'], self::MODI, true)) {
            $fouten[] = 'modus';
        }
        if ($c['modus'] === 'test' && (!is_string($c['test_sleutel']) || strlen($c['test_sleutel']) < 24)) {
            $fouten[] = 'test_sleutel';
        }
        if (!is_string($c['origin']) || !preg_match('#^https://[a-z0-9.-]+$#', $c['origin'])) {
            $fouten[] = 'origin';
        }
        if (!is_string($c['data_dir']) || self::canoniek($c['data_dir']) === null) {
            $fouten[] = 'data_dir';
        }
        $mail = $c['mail'];
        if (!in_array($mail['modus'], self::MAIL_MODI, true)) {
            $fouten[] = 'mail.modus';
        }
        foreach (['intern_aan', 'van'] as $veld) {
            if (!Validatie::isEmail((string) $mail[$veld])) {
                $fouten[] = 'mail.' . $veld;
            }
        }
        if (!is_string($mail['van_naam']) || !preg_match('/^[A-Za-z0-9 .\-]{1,60}$/', $mail['van_naam'])) {
            $fouten[] = 'mail.van_naam';
        }
        foreach (['ip', 'email', 'globaal'] as $soort) {
            $l = $c['limieten'][$soort] ?? null;
            if (!is_array($l) || count($l) !== 2 || !is_int($l[0]) || !is_int($l[1]) || $l[0] < 1 || $l[1] < 60) {
                $fouten[] = 'limieten.' . $soort;
            }
        }
        if (!is_int($c['bewaartermijn_dagen']) || $c['bewaartermijn_dagen'] < 1) {
            $fouten[] = 'bewaartermijn_dagen';
        }
        if (!is_int($c['idempotentie_dagen']) || $c['idempotentie_dagen'] < 1) {
            $fouten[] = 'idempotentie_dagen';
        }
        if (!is_int($c['opslag_max_bytes']) || $c['opslag_max_bytes'] < 64 * 1024 * 1024) {
            $fouten[] = 'opslag_max_bytes';
        }
        return $fouten;
    }

    /**
     * Canoniek absoluut pad: symbolische links in het bestaande deel worden opgelost, het
     * (nog) niet bestaande deel wordt erachter geplakt. '.' en '..' worden niet geaccepteerd.
     */
    public static function canoniek(string $pad): ?string
    {
        if ($pad === '' || $pad[0] !== '/' || str_contains($pad, "\0")) {
            return null;
        }
        $delen = array_values(array_filter(explode('/', $pad), static fn (string $d): bool => $d !== ''));
        if (in_array('.', $delen, true) || in_array('..', $delen, true)) {
            return null;
        }
        $rest = [];
        while ($delen !== []) {
            $echt = realpath('/' . implode('/', $delen));
            if ($echt !== false) {
                return rtrim($echt, '/') . ($rest === [] ? '' : '/' . implode('/', $rest));
            }
            array_unshift($rest, array_pop($delen));
        }
        return '/' . implode('/', $rest);
    }

    /**
     * De data-map moet buiten de documentroot liggen. Beide paden worden canoniek vergeleken
     * (symbolische links opgelost, '..' geweigerd). Bij twijfel: false (fail-closed).
     */
    public static function buitenDocroot(string $dataDir, string $docRoot): bool
    {
        $data = self::canoniek($dataDir);
        $root = self::canoniek($docRoot);
        if ($data === null || $root === null || $root === '/' || realpath($docRoot) === false) {
            return false;
        }
        return !str_starts_with($data . '/', $root . '/');
    }
}
