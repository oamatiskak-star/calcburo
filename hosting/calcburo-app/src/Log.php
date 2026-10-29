<?php
declare(strict_types=1);

namespace CalcBuro;

use Throwable;

/**
 * Logt alleen foutcodes en referenties naar de PHP-foutlog van de hosting.
 * Nooit namen, e-mailadressen, IP-adressen, projectinhoud of bestandsnamen.
 */
final class Log
{
    /** @var list<string> alleen voor tests */
    public static array $opgevangen = [];
    public static bool $stil = false;

    public static function melding(string $code): void
    {
        $regel = 'calcburo: ' . preg_replace('/[^A-Za-z0-9_ =\-]/', '', $code);
        if (self::$stil) {
            self::$opgevangen[] = $regel;
            return;
        }
        error_log($regel);
    }

    public static function fout(string $fase, Throwable $e): void
    {
        $codes = [];
        for ($x = $e; $x !== null && count($codes) < 3; $x = $x->getPrevious()) {
            $codes[] = (new \ReflectionClass($x))->getShortName() . ':' . preg_replace('/[^a-z_]/', '', $x->getMessage());
        }
        self::melding($fase . ' ' . implode(' ', $codes));
    }
}
