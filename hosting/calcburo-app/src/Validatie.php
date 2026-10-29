<?php
declare(strict_types=1);

namespace CalcBuro;

/**
 * Validatie van tekstvelden en uploads. Alles wat niet expliciet is toegestaan, wordt geweigerd.
 */
final class Validatie
{
    public const MAX_BESTANDEN = 10;
    public const MAX_BESTAND_BYTES = 20 * 1024 * 1024;
    public const MAX_TOTAAL_BYTES = 50 * 1024 * 1024;

    public const ROLLEN = ['aannemer', 'ontwikkelaar', 'publieke-opdrachtgever', 'anders'];
    public const UITWERKINGEN = [
        'inschrijfbegroting', 'hoeveelhedenstaat', 'begrotingscontrole',
        'volledige-calculatie', 'extra-capaciteit', 'nog-afstemmen',
    ];

    /** Veldnaam => [verplicht, maximale lengte in tekens, meerregelig]. */
    public const VELDEN = [
        'naam' => [true, 150, false],
        'bedrijf' => [false, 150, false],
        'email' => [true, 200, false],
        'telefoon' => [false, 50, false],
        'rol' => [false, 40, false],
        'uitwerking' => [false, 40, false],
        'projectlocatie' => [false, 200, false],
        'planning' => [false, 150, false],
        'project' => [true, 2000, true],
    ];

    /**
     * Extensie => [toegestane MIME-typen volgens finfo, inhoudscontrole].
     * De inhoudscontrole is een eigen signatuurcheck, onafhankelijk van finfo.
     */
    public const TYPEN = [
        'pdf' => [['application/pdf'], 'pdf'],
        'ifc' => [['text/plain', 'application/octet-stream', 'application/x-step', 'model/ifc'], 'ifc'],
        'xlsx' => [['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'], 'zip'],
        'docx' => [['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'], 'zip'],
        'xls' => [['application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream'], 'ole'],
        'csv' => [['text/csv', 'text/plain', 'application/csv'], 'tekst'],
        'jpg' => [['image/jpeg'], 'jpeg'],
        'jpeg' => [['image/jpeg'], 'jpeg'],
        'png' => [['image/png'], 'png'],
    ];

    public static function isEmail(string $waarde): bool
    {
        if ($waarde === '' || strlen($waarde) > 200 || preg_match('/[\x00-\x20\x7f<>,;:"\\\\()\[\]]/', $waarde)) {
            return false;
        }
        return filter_var($waarde, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function lengte(string $tekst): int
    {
        return (int) preg_match_all('/./us', $tekst);
    }

    /**
     * @param array<string, mixed> $post
     * @return array{0: array<string, string>, 1: array<string, string>} [schone waarden, fouten per veld]
     */
    public static function velden(array $post): array
    {
        $schoon = [];
        $fouten = [];
        foreach (self::VELDEN as $naam => [$verplicht, $max, $meerregelig]) {
            $ruw = $post[$naam] ?? '';
            if (!is_string($ruw)) {
                $fouten[$naam] = 'ongeldig';
                continue;
            }
            if ($ruw !== '' && preg_match('//u', $ruw) !== 1) {
                $fouten[$naam] = 'ongeldig';
                continue;
            }
            $waarde = str_replace(["\r\n", "\r"], "\n", $ruw);
            $patroon = $meerregelig ? '/[\x00-\x09\x0b-\x1f\x7f]/u' : '/[\x00-\x1f\x7f]/u';
            $waarde = trim((string) preg_replace($patroon, ' ', $waarde));
            if ($waarde === '') {
                if ($verplicht) {
                    $fouten[$naam] = 'verplicht';
                }
                $schoon[$naam] = '';
                continue;
            }
            if (self::lengte($waarde) > $max) {
                $fouten[$naam] = 'te_lang';
                continue;
            }
            $schoon[$naam] = $waarde;
        }

        if (($schoon['email'] ?? '') !== '' && !self::isEmail($schoon['email'])) {
            $fouten['email'] = 'ongeldig';
        }
        if (($schoon['telefoon'] ?? '') !== '' && !preg_match('/^[0-9+() .\-]{6,50}$/', $schoon['telefoon'])) {
            $fouten['telefoon'] = 'ongeldig';
        }
        if (($schoon['rol'] ?? '') !== '' && !in_array($schoon['rol'], self::ROLLEN, true)) {
            $fouten['rol'] = 'ongeldig';
        }
        if (($schoon['uitwerking'] ?? '') !== '' && !in_array($schoon['uitwerking'], self::UITWERKINGEN, true)) {
            $fouten['uitwerking'] = 'ongeldig';
        }
        return [$schoon, $fouten];
    }

    public static function isUuidV4(mixed $waarde): bool
    {
        return is_string($waarde)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $waarde) === 1;
    }

    /**
     * Zet $_FILES['documenten'] om naar een lijst. Elke afwijkende structuur (geneste arrays,
     * ontbrekende sleutels) geldt als misvormd en wordt geweigerd.
     *
     * @param mixed $veld
     * @return list<array{name: string, tmp_name: string, size: int, error: int}>|null null = misvormd
     */
    public static function normaliseerBestanden(mixed $veld): ?array
    {
        if ($veld === null) {
            return [];
        }
        if (!is_array($veld)) {
            return null;
        }
        foreach (['name', 'tmp_name', 'size', 'error'] as $sleutel) {
            if (!array_key_exists($sleutel, $veld)) {
                return null;
            }
        }
        if (!is_array($veld['name'])) {
            return null; // het formulierveld heet documenten[]; een enkelvoudig veld is niet toegestaan
        }
        $lijst = [];
        foreach (array_keys($veld['name']) as $i) {
            $naam = $veld['name'][$i] ?? null;
            $tmp = $veld['tmp_name'][$i] ?? null;
            $grootte = $veld['size'][$i] ?? null;
            $fout = $veld['error'][$i] ?? null;
            if (!is_int($i) || !is_string($naam) || !is_string($tmp) || !is_int($grootte) || !is_int($fout)) {
                return null;
            }
            if ($fout === UPLOAD_ERR_NO_FILE && $naam === '') {
                continue; // leeg bestandsveld zonder keuze
            }
            $lijst[] = ['name' => $naam, 'tmp_name' => $tmp, 'size' => $grootte, 'error' => $fout];
        }
        return $lijst;
    }

    /**
     * Controleert de opgegeven bestandsnaam. Paden, stuurtekens en meervoudige extensies
     * met een uitvoerbaar deel worden geweigerd.
     */
    public static function extensie(string $naam): ?string
    {
        if ($naam === '' || strlen($naam) > 255 || preg_match('//u', $naam) !== 1) {
            return null;
        }
        if (preg_match('#[\x00-\x1f\x7f/\\\\]#', $naam) || str_contains($naam, '..') || $naam[0] === '.') {
            return null;
        }
        $delen = explode('.', strtolower($naam));
        if (count($delen) < 2) {
            return null;
        }
        $ext = (string) end($delen);
        if (!array_key_exists($ext, self::TYPEN)) {
            return null;
        }
        $uitvoerbaar = '/^(php\d*|phtml|phar|pht|phps|cgi|pl|py|sh|bash|exe|com|bat|cmd|js|mjs|htm|html|shtml|svg|htaccess|asp|aspx|jsp)$/';
        foreach (array_slice($delen, 1, -1) as $tussendeel) {
            if (preg_match($uitvoerbaar, $tussendeel)) {
                return null;
            }
        }
        return $ext;
    }

    /** Veilige weergavenaam voor de interne mail en de administratie (geen pad, geen stuurtekens). */
    public static function weergaveNaam(string $naam): string
    {
        $schoon = (string) preg_replace('/[^\p{L}\p{N} ._()\-]/u', '_', $naam);
        if (self::lengte($schoon) > 120) {
            $schoon = (string) preg_replace('/^(.{0,120}).*$/us', '$1', $schoon);
        }
        return $schoon;
    }

    /** Signatuurcontrole op de eerste bytes van het bestand. */
    public static function inhoudKlopt(string $soort, string $kop): bool
    {
        return match ($soort) {
            'pdf' => str_starts_with($kop, '%PDF-'),
            'ifc' => str_starts_with(ltrim($kop, "\xEF\xBB\xBF \t\r\n"), 'ISO-10303-21'),
            'zip' => str_starts_with($kop, "PK\x03\x04"),
            'ole' => str_starts_with($kop, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
            'jpeg' => str_starts_with($kop, "\xFF\xD8\xFF"),
            'png' => str_starts_with($kop, "\x89PNG\r\n\x1A\n"),
            'tekst' => !str_contains($kop, "\0") && preg_match('//u', self::heleTekens($kop)) === 1
                && !preg_match('/<\?php|<script|^#!/i', $kop),
            default => false,
        };
    }

    /** Knipt een eventueel half UTF-8-teken aan het einde van een buffer af. */
    private static function heleTekens(string $buffer): string
    {
        for ($i = 0; $i < 4 && $buffer !== '' && preg_match('//u', $buffer) !== 1; $i++) {
            $buffer = substr($buffer, 0, -1);
        }
        return $buffer;
    }

    /**
     * Valideert alle bestanden. Geeft per bestand de bepaalde extensie en MIME terug,
     * of een foutcode voor de hele aanvraag.
     *
     * @param list<array{name: string, tmp_name: string, size: int, error: int}> $bestanden
     * @param callable(string): bool $isUpload controle dat tmp_name een echte PHP-upload is
     * @return array{0: list<array{naam: string, tmp: string, grootte: int, ext: string, mime: string}>, 1: ?string}
     */
    public static function bestanden(array $bestanden, callable $isUpload): array
    {
        if (count($bestanden) > self::MAX_BESTANDEN) {
            return [[], 'te_veel_bestanden'];
        }
        if (!class_exists(\finfo::class)) {
            return $bestanden === [] ? [[], null] : [[], 'mime_controle_niet_beschikbaar'];
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $totaal = 0;
        $goed = [];
        foreach ($bestanden as $b) {
            if (in_array($b['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                return [[], 'bestand_te_groot'];
            }
            if ($b['error'] !== UPLOAD_ERR_OK) {
                return [[], 'upload_onvolledig'];
            }
            $ext = self::extensie($b['name']);
            if ($ext === null) {
                return [[], 'bestandstype'];
            }
            if (!$isUpload($b['tmp_name']) || !is_file($b['tmp_name'])) {
                return [[], 'upload_onvolledig'];
            }
            $echt = filesize($b['tmp_name']);
            if ($echt === false || $echt !== $b['size'] || $echt === 0) {
                return [[], 'upload_onvolledig'];
            }
            if ($echt > self::MAX_BESTAND_BYTES) {
                return [[], 'bestand_te_groot'];
            }
            $totaal += $echt;
            if ($totaal > self::MAX_TOTAAL_BYTES) {
                return [[], 'totaal_te_groot'];
            }
            [$mimes, $soort] = self::TYPEN[$ext];
            $mime = (string) $finfo->file($b['tmp_name']);
            if (!in_array($mime, $mimes, true)) {
                return [[], 'bestandstype'];
            }
            $kop = (string) file_get_contents($b['tmp_name'], false, null, 0, 4096);
            if (!self::inhoudKlopt($soort, $kop)) {
                return [[], 'bestandstype'];
            }
            if ($soort === 'zip' && !self::officeZipKlopt($b['tmp_name'], $ext)) {
                return [[], 'bestandstype'];
            }
            $goed[] = ['naam' => self::weergaveNaam($b['name']), 'tmp' => $b['tmp_name'], 'grootte' => $echt, 'ext' => $ext, 'mime' => $mime];
        }
        return [$goed, null];
    }

    /** Verplichte onderdelen per Office-type in het ZIP-pakket. */
    private const OFFICE_ONDERDELEN = [
        'xlsx' => ['[Content_Types].xml', 'xl/workbook.xml'],
        'docx' => ['[Content_Types].xml', 'word/document.xml'],
    ];

    /**
     * Structuurcontrole van XLSX/DOCX zonder uitpakken: leest de centrale directory van het ZIP-pakket.
     * Weigert: onleesbare/ZIP64/versleutelde pakketten, ontbrekende verplichte onderdelen,
     * macro-onderdelen (vbaProject.bin, ook als het bestand is hernoemd van .xlsm/.docm),
     * ingesloten ActiveX/OLE-objecten, absolute paden en '..' in namen. Dit is geen virusscan.
     */
    public static function officeZipKlopt(string $pad, string $ext): bool
    {
        $namen = self::zipNamen($pad);
        if ($namen === null || !isset(self::OFFICE_ONDERDELEN[$ext])) {
            return false;
        }
        foreach (self::OFFICE_ONDERDELEN[$ext] as $verplicht) {
            if (!in_array($verplicht, $namen, true)) {
                return false;
            }
        }
        foreach ($namen as $naam) {
            $klein = strtolower($naam);
            if ($naam === '' || $naam[0] === '/' || str_contains($naam, '\\') || in_array('..', explode('/', $naam), true)
                || str_ends_with($klein, 'vbaproject.bin') || str_contains($klein, '/activex/') || str_contains($klein, '/embeddings/oleobject')) {
                return false;
            }
        }
        return true;
    }

    /**
     * Namen uit de centrale directory, of null als het pakket niet klopt.
     *
     * @return list<string>|null
     */
    private static function zipNamen(string $pad): ?array
    {
        $grootte = filesize($pad);
        $h = @fopen($pad, 'rb');
        if ($h === false || $grootte === false || $grootte < 22) {
            return null;
        }
        try {
            $staartLengte = min($grootte, 65557);
            fseek($h, $grootte - $staartLengte);
            $staart = (string) fread($h, $staartLengte);
            $eocd = strrpos($staart, "PK\x05\x06");
            if ($eocd === false || strlen($staart) - $eocd < 22) {
                return null;
            }
            $e = unpack('vschijf/vcdschijf/vaantalHier/vaantal/Vcdgrootte/Vcdstart', substr($staart, $eocd + 4, 16));
            if ($e === false || $e['schijf'] !== 0 || $e['aantal'] !== $e['aantalHier'] || $e['aantal'] === 0
                || $e['aantal'] === 0xFFFF || $e['aantal'] > 5000 || $e['cdstart'] + $e['cdgrootte'] > $grootte) {
                return null;
            }
            fseek($h, $e['cdstart']);
            $cd = (string) fread($h, $e['cdgrootte']);
            if (strlen($cd) !== $e['cdgrootte']) {
                return null;
            }
            $namen = [];
            $pos = 0;
            for ($i = 0; $i < $e['aantal']; $i++) {
                if (substr($cd, $pos, 4) !== "PK\x01\x02" || $pos + 46 > strlen($cd)) {
                    return null;
                }
                $r = unpack('vvlag', substr($cd, $pos + 8, 2)) + unpack('vnaam/vextra/vcommentaar', substr($cd, $pos + 28, 6));
                if (($r['vlag'] & 0x1) === 0x1) {
                    return null; // versleuteld
                }
                $namen[] = substr($cd, $pos + 46, $r['naam']);
                $pos += 46 + $r['naam'] + $r['extra'] + $r['commentaar'];
            }
            return $namen;
        } finally {
            fclose($h);
        }
    }
}
