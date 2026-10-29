<?php
declare(strict_types=1);

namespace CalcBuro;

use Throwable;

/**
 * Transportlaag: sessie/CSRF, beveiligingsheaders, opbouw van de diensten en het antwoord
 * (JSON voor de JavaScript-route, HTML voor de route zonder JavaScript).
 */
final class Http
{
    public const SESSIE = '__Host-cbsessie';

    /** API en resultaatpagina (route zonder JavaScript): nooit Google. */
    public const CSP = "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; "
        . "connect-src 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; base-uri 'self'";

    /**
     * Aanvraagpagina: staat de Google-tag toe. Of die ook laadt, beslist toestemming.js (alleen na
     * toestemming). Geen frame-src: iframes van Google (remarketing) blijven geblokkeerd.
     */
    public const CSP_PAGINA = "default-src 'self'; img-src 'self' data: " . self::GOOGLE_IMG . "; style-src 'self'; "
        . "script-src 'self' " . self::GOOGLE_SCRIPT . "; font-src 'self'; connect-src 'self' " . self::GOOGLE_CONNECT . "; "
        . "form-action 'self'; frame-ancestors 'none'; object-src 'none'; base-uri 'self'";

    public const GOOGLE_SCRIPT = 'https://www.googletagmanager.com https://www.googleadservices.com https://googleads.g.doubleclick.net https://www.google.com';
    public const GOOGLE_IMG = 'https://www.googletagmanager.com https://googleads.g.doubleclick.net https://ad.doubleclick.net https://www.google.com https://www.google.nl';
    public const GOOGLE_CONNECT = 'https://www.googletagmanager.com https://www.googleadservices.com https://googleads.g.doubleclick.net https://ad.doubleclick.net https://www.google.com';

    public static function beveiligingsHeaders(string $csp = self::CSP): void
    {
        header('Content-Security-Policy: ' . $csp);
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin'); // same-origin: de browser stuurt Origin mee bij het verzenden
        header('Cache-Control: no-store');
        header('Cross-Origin-Resource-Policy: same-origin');
    }

    private static function sessieInstellingen(): void
    {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', '1');
        session_name(self::SESSIE);
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
    }

    /** Pagina: start of hervat de sessie en zorgt voor een CSRF-token. */
    public static function sessieToken(): string
    {
        self::sessieInstellingen();
        session_start();
        if (!is_string($_SESSION['csrf'] ?? null) || strlen($_SESSION['csrf']) !== 64) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        $token = $_SESSION['csrf'];
        session_write_close();
        return $token;
    }

    /** API: leest alleen een bestaande sessie, maakt er geen aan en houdt de sessie niet vergrendeld. */
    public static function bestaandToken(): ?string
    {
        if (!isset($_COOKIE[self::SESSIE]) || !is_string($_COOKIE[self::SESSIE])) {
            return null;
        }
        self::sessieInstellingen();
        session_start(['read_and_close' => true]);
        $token = $_SESSION['csrf'] ?? null;
        return is_string($token) ? $token : null;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{0: string, 1: ?string} [data-map, geheim] ; geheim null = niet beschikbaar
     */
    public static function geheim(array $config): array
    {
        $dir = (string) $config['data_dir'];
        try {
            Bestand::privateMap($dir);
            $pad = $dir . '/geheim.key';
            if (!is_file($pad)) {
                $h = @fopen($pad, 'xb');
                if ($h !== false) {
                    fwrite($h, bin2hex(random_bytes(32)));
                    fclose($h);
                    @chmod($pad, 0600);
                }
            }
            $geheim = trim((string) @file_get_contents($pad));
            return [$dir, strlen($geheim) === 64 ? $geheim : null];
        } catch (Throwable $e) {
            Log::fout('geheim', $e);
            return [$dir, null];
        }
    }

    /** @return array<string, mixed> */
    public static function config(): array
    {
        $config = Config::laad(APP_DIR);
        $docRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($config['modus'] !== 'uit' && (!Config::buitenDocroot((string) $config['data_dir'], $docRoot) || !Config::buitenDocroot(APP_DIR, $docRoot))) {
            Log::melding('data_of_app_binnen_documentroot');
            $config['modus'] = 'uit';
        }
        if (isset($config['config_fouten'])) {
            Log::melding('config_ongeldig ' . implode(' ', $config['config_fouten']));
        }
        return $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function verwerker(array $config, string $dataDir, string $geheim): Verwerker
    {
        foreach (['aanvragen', 'idempotentie', 'limiet'] as $sub) {
            Bestand::privateMap($dataDir . '/' . $sub);
        }
        $mc = $config['mail'];
        return new Verwerker(
            $config,
            new Opslag($dataDir, static fn (string $van, string $naar): bool => move_uploaded_file($van, $naar), (int) $config['opslag_max_bytes']),
            new Limiet($dataDir . '/limiet/tellers.json', $geheim, $config['limieten']),
            new Idempotentie($dataDir . '/idempotentie', $geheim),
            new PhpMailer($mc['envelope_afzender'] ? (string) $mc['van'] : ''),
            static fn (string $tmp): bool => is_uploaded_file($tmp),
        );
    }

    public static function api(): void
    {
        self::beveiligingsHeaders();
        $config = self::config();
        $wilJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            self::stuur(Verwerker::fout(405, 'methode', [], ['Allow' => 'POST']), $wilJson);
            return;
        }
        [$dataDir, $geheim] = $config['modus'] === 'uit' ? ['', null] : self::geheim($config);
        if ($geheim === null) {
            self::stuur(Verwerker::fout(503, 'niet_actief'), $wilJson);
            return;
        }
        try {
            $antwoord = self::verwerker($config, $dataDir, $geheim)
                ->verwerk($_SERVER, $_POST, $_FILES, self::bestaandToken(), time());
        } catch (Throwable $e) {
            Log::fout('api', $e);
            $antwoord = Verwerker::fout(500, 'opslag');
        }
        self::stuur($antwoord, $wilJson);
    }

    /** @param array{status: int, body: array<string, mixed>, headers: array<string, string>} $antwoord */
    public static function stuur(array $antwoord, bool $json): void
    {
        http_response_code($antwoord['status']);
        foreach ($antwoord['headers'] as $naam => $waarde) {
            header($naam . ': ' . $waarde);
        }
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($antwoord['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo Weergave::resultaat($antwoord['body']);
    }

    /** @param array<string, mixed> $get */
    public static function pagina(array $get): void
    {
        $config = self::config();
        // Testmodus: geen Google-tag en strikte CSP, zodat een acceptatietest nooit als conversie telt.
        self::beveiligingsHeaders($config['modus'] === 'test' ? self::CSP : self::CSP_PAGINA);
        header('Content-Type: text/html; charset=utf-8');
        $testSleutel = '';
        $actief = $config['modus'] === 'live';
        if ($config['modus'] === 'test') {
            $gegeven = $get['test'] ?? null;
            $actief = is_string($gegeven) && hash_equals((string) $config['test_sleutel'], $gegeven);
            $testSleutel = $actief ? $gegeven : '';
        }
        if ($actief && self::geheim($config)[1] === null) {
            $actief = false;
        }
        $token = $actief ? self::sessieToken() : '';
        echo Weergave::aanvraagPagina($actief, $token, self::uuidV4(), $testSleutel, $config['modus'] === 'test', Weergave::voorkeuze($get));
    }

    public static function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }
}
