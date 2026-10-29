<?php
declare(strict_types=1);

namespace CalcBuro;

use RuntimeException;

/**
 * Bestandshulpen: private mappen, atomair schrijven en vergrendeld lezen/schrijven van JSON.
 */
final class Bestand
{
    public static function privateMap(string $pad): void
    {
        if (is_link($pad)) {
            throw new RuntimeException('map_is_link');
        }
        if (!is_dir($pad) && !@mkdir($pad, 0700, true) && !is_dir($pad)) {
            throw new RuntimeException('map_aanmaken');
        }
        @chmod($pad, 0700);
    }

    /** Schrijft via een tijdelijk bestand en rename, zodat lezers nooit een half bestand zien. */
    public static function atomairSchrijven(string $pad, string $inhoud): void
    {
        $tmp = dirname($pad) . '/.tmp-' . bin2hex(random_bytes(8));
        $h = @fopen($tmp, 'xb');
        if ($h === false) {
            throw new RuntimeException('schrijven');
        }
        $ok = fwrite($h, $inhoud) === strlen($inhoud) && fflush($h);
        fclose($h);
        @chmod($tmp, 0600);
        if (!$ok || !@rename($tmp, $pad)) {
            @unlink($tmp);
            throw new RuntimeException('schrijven');
        }
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * Opent een bestand met exclusieve vergrendeling. De aanroeper moet ontgrendel() aanroepen.
     *
     * @return resource
     */
    public static function vergrendel(string $pad)
    {
        $h = @fopen($pad, 'c+b');
        if ($h === false) {
            throw new RuntimeException('slot_openen');
        }
        @chmod($pad, 0600);
        if (!flock($h, LOCK_EX)) {
            fclose($h);
            throw new RuntimeException('slot');
        }
        return $h;
    }

    /**
     * @param resource $h
     * @return array<string, mixed>|null null = leeg bestand; RuntimeException bij onleesbare inhoud
     */
    public static function leesVergrendeld($h): ?array
    {
        rewind($h);
        $inhoud = stream_get_contents($h);
        if ($inhoud === false) {
            throw new RuntimeException('lezen');
        }
        if (trim($inhoud) === '') {
            return null;
        }
        $data = json_decode($inhoud, true);
        if (!is_array($data)) {
            throw new RuntimeException('beschadigd');
        }
        return $data;
    }

    /**
     * @param resource $h
     * @param array<string, mixed> $data
     */
    public static function schrijfVergrendeld($h, array $data): void
    {
        $inhoud = self::json($data);
        if (!rewind($h) || !ftruncate($h, 0) || fwrite($h, $inhoud) !== strlen($inhoud) || !fflush($h)) {
            throw new RuntimeException('schrijven');
        }
    }

    /** @param resource $h */
    public static function ontgrendel($h): void
    {
        flock($h, LOCK_UN);
        fclose($h);
    }

    /** Verwijdert een map recursief zonder symbolische links te volgen. */
    public static function verwijderBoom(string $pad): bool
    {
        if (is_link($pad) || is_file($pad)) {
            return @unlink($pad);
        }
        if (!is_dir($pad)) {
            return true;
        }
        $ok = true;
        foreach (scandir($pad) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $ok = self::verwijderBoom($pad . '/' . $item) && $ok;
        }
        return @rmdir($pad) && $ok;
    }
}
