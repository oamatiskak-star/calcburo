<?php
declare(strict_types=1);

/*
 * Zelftest CalcBuro-aanvraagflow. Geen afhankelijkheden, geen netwerk, geen echte mail.
 *   php tests/selftest.php
 * Gebruikt een tijdelijke map tests/.tmp-* die na afloop wordt verwijderd.
 */

require dirname(__DIR__) . '/calcburo-app/bootstrap.php';

use CalcBuro\Bestand;
use CalcBuro\Config;
use CalcBuro\Idempotentie;
use CalcBuro\Limiet;
use CalcBuro\Log;
use CalcBuro\Mailer;
use CalcBuro\Opschoning;
use CalcBuro\Opslag;
use CalcBuro\Validatie;
use CalcBuro\Verwerker;
use CalcBuro\Weergave;

Log::$stil = true;

final class MockMailer implements Mailer
{
    /** @var list<array{aan: string, onderwerp: string, tekst: string, headers: array<string, string>}> */
    public array $verstuurd = [];
    public bool $accepteer = true;

    public function verstuur(string $aan, string $onderwerp, string $tekst, array $headers): bool
    {
        $this->verstuurd[] = ['aan' => $aan, 'onderwerp' => $onderwerp, 'tekst' => $tekst, 'headers' => $headers];
        return $this->accepteer;
    }
}

$geslaagd = 0;
$mislukt = [];
function test(string $naam, callable $f): void
{
    global $geslaagd, $mislukt;
    try {
        $f();
        $geslaagd++;
        echo "  ok   {$naam}\n";
    } catch (Throwable $e) {
        $mislukt[] = $naam;
        echo "  FOUT {$naam}: " . $e->getMessage() . ' (regel ' . $e->getLine() . ")\n";
    }
}
function zeker(bool $voorwaarde, string $melding = 'verwachting niet gehaald'): void
{
    if (!$voorwaarde) {
        throw new RuntimeException($melding);
    }
}
function gelijk(mixed $verwacht, mixed $werkelijk, string $wat = ''): void
{
    if ($verwacht !== $werkelijk) {
        throw new RuntimeException($wat . ' verwacht ' . var_export($verwacht, true) . ', kreeg ' . var_export($werkelijk, true));
    }
}

$basis = __DIR__ . '/.tmp-' . bin2hex(random_bytes(4));
mkdir($basis, 0700);
register_shutdown_function(static fn () => Bestand::verwijderBoom($basis));

/** Maakt een testbestand en geeft een $_FILES-achtige invoer terug. */
function upload(string $naam, string $inhoud, ?int $grootte = null): array
{
    global $basis;
    Bestand::privateMap($basis . '/uploads');
    $tmp = $basis . '/uploads/php' . bin2hex(random_bytes(5));
    file_put_contents($tmp, $inhoud);
    if ($grootte !== null) {
        $h = fopen($tmp, 'r+');
        ftruncate($h, $grootte);
        fclose($h);
    }
    return ['name' => $naam, 'tmp_name' => $tmp, 'size' => (int) filesize($tmp), 'error' => UPLOAD_ERR_OK];
}
/** @param list<array> $lijst */
function filesVeld(array $lijst): array
{
    $veld = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
    foreach ($lijst as $b) {
        foreach (['name', 'tmp_name', 'error', 'size'] as $k) {
            $veld[$k][] = $b[$k];
        }
        $veld['type'][] = 'application/octet-stream';
    }
    return ['documenten' => $veld];
}

const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
const PNG = "\x89PNG\r\n\x1A\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x02\0\0\0\x90wS\xDE\0\0\0\x0cIDATx\x9cc\xf8\x0f\0\0\x01\x01\0\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";
const IFC = "ISO-10303-21;\nHEADER;\nFILE_DESCRIPTION(('ViewDefinition [CoordinationView]'),'2;1');\nENDSEC;\nDATA;\nENDSEC;\nEND-ISO-10303-21;\n";
const CSV = "post;omschrijving;hoeveelheid\n21.10;metselwerk;125\n";

function geldigePost(array $extra = []): array
{
    return array_replace([
        'csrf' => str_repeat('a', 64),
        'aanvraag_id' => CalcBuro\Http::uuidV4(),
        'naam' => 'Jan Tester',
        'bedrijf' => 'Bouwbedrijf Proef',
        'email' => 'jan@voorbeeld.nl',
        'telefoon' => '+31 6 1234 5678',
        'rol' => 'aannemer',
        'uitwerking' => 'inschrijfbegroting',
        'projectlocatie' => 'Utrecht',
        'planning' => '15 december',
        'project' => "Twaalf grondgebonden woningen.\nInschrijving voor nota van inlichtingen.",
        'website' => '',
    ], $extra);
}
function server(array $extra = []): array
{
    return array_replace(['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'https://calcburo.nl', 'REMOTE_ADDR' => '203.0.113.7', 'CONTENT_LENGTH' => '1000'], $extra);
}

/** Idempotentie met geïnjecteerde schrijffouten op de opgegeven aanroepnummers van vastleggen(). */
final class FoutIdempotentie extends Idempotentie
{
    private int $aanroep = 0;

    /** @param list<int> $faalBij */
    public function __construct(string $map, string $geheim, private array $faalBij)
    {
        parent::__construct($map, $geheim);
    }

    public function vastleggen(array $record): void
    {
        $this->aanroep++;
        if (in_array($this->aanroep, $this->faalBij, true)) {
            throw new RuntimeException('schrijven');
        }
        parent::vastleggen($record);
    }
}

const GEHEIM = 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk';

/**
 * @param list<int> $idemFouten aanroepnummers van Idempotentie::vastleggen() die falen
 * @return array{0: Verwerker, 1: MockMailer, 2: string}
 */
function opzet(array $configExtra = [], ?callable $verplaats = null, array $idemFouten = [], ?string $data = null): array
{
    global $basis;
    $data ??= $basis . '/data-' . bin2hex(random_bytes(3));
    $config = Config::samenvoegen(APP_DIR, array_replace_recursive([
        'modus' => 'live',
        'data_dir' => $data,
        'mail' => ['modus' => 'php'],
    ], $configExtra));
    gelijk(false, isset($config['config_fouten']), 'config geldig');
    foreach (['aanvragen', 'idempotentie', 'limiet'] as $sub) {
        Bestand::privateMap($data . '/' . $sub);
    }
    $mailer = new MockMailer();
    $geheim = GEHEIM;
    $verwerker = new Verwerker(
        $config,
        new Opslag($data, $verplaats ?? static fn (string $van, string $naar): bool => rename($van, $naar), (int) $config['opslag_max_bytes']),
        new Limiet($data . '/limiet/tellers.json', $geheim, $config['limieten']),
        $idemFouten === [] ? new Idempotentie($data . '/idempotentie', $geheim) : new FoutIdempotentie($data . '/idempotentie', $geheim, $idemFouten),
        $mailer,
        static fn (string $tmp): bool => str_starts_with($tmp, $basis . '/uploads/'),
    );
    return [$verwerker, $mailer, $data];
}
function aanvraagMappen(string $data): array
{
    return array_values(array_filter(scandir($data . '/aanvragen') ?: [], static fn ($n) => $n[0] !== '.'));
}
$TOKEN = str_repeat('a', 64);
$NU = 1_800_000_000;

echo "Validatie\n";
test('geldige velden geven geen fouten', function () {
    [$schoon, $fouten] = Validatie::velden(geldigePost());
    gelijk([], $fouten);
    gelijk('jan@voorbeeld.nl', $schoon['email']);
});
test('verplichte velden ontbreken', function () {
    [, $fouten] = Validatie::velden(['naam' => '  ', 'email' => '', 'project' => '']);
    gelijk(['naam' => 'verplicht', 'email' => 'verplicht', 'project' => 'verplicht'], $fouten);
});
test('e-mail met header-injectie en ongeldige adressen worden geweigerd', function () {
    foreach (["jan@voorbeeld.nl\r\nBcc: x@y.nl", 'jan@', 'a b@c.nl', 'x@y.nl, z@q.nl', '<a@b.nl>'] as $email) {
        [, $f] = Validatie::velden(geldigePost(['email' => $email]));
        gelijk('ongeldig', $f['email'] ?? null, $email);
    }
});
test('lengte telt tekens, niet bytes', function () {
    [, $f] = Validatie::velden(geldigePost(['project' => str_repeat('é', 2000)]));
    gelijk([], $f);
    [, $f] = Validatie::velden(geldigePost(['project' => str_repeat('a', 2001)]));
    gelijk('te_lang', $f['project'] ?? null);
});
test('keuzelijsten, arrays en ongeldige UTF-8 worden geweigerd', function () {
    [, $f] = Validatie::velden(geldigePost(['rol' => 'hacker', 'naam' => ['x'], 'bedrijf' => "\xC3\x28"]));
    gelijk(['naam' => 'ongeldig', 'bedrijf' => 'ongeldig', 'rol' => 'ongeldig'], $f);
});
test('stuurtekens worden geneutraliseerd, regeleinden alleen in projectomschrijving', function () {
    [$s] = Validatie::velden(geldigePost(['naam' => "Jan\nBcc: x", 'project' => "regel 1\r\nregel 2\x07"]));
    gelijk('Jan Bcc: x', $s['naam']);
    gelijk("regel 1\nregel 2", $s['project']);
});
test('bestandsnamen: pad, verborgen, dubbele extensie en uitvoerbaar geweigerd', function () {
    gelijk('pdf', Validatie::extensie('Tekening rev.B.pdf'));
    gelijk('xlsx', Validatie::extensie('BEGROTING.XLSX'));
    foreach (['../etc/passwd.pdf', 'map/tekening.pdf', 'a\\b.pdf', '.htaccess', 'shell.php.pdf', 'x.phtml', 'x.svg', 'x.exe', 'zonderextensie', "nul\0.pdf", 'x.pdf.', 'x..pdf', 'x.docm', 'x.xlsm', str_repeat('a', 252) . '.pdf'] as $naam) {
        gelijk(null, Validatie::extensie($naam), $naam);
    }
});
test('misvormde $_FILES-structuren worden geweigerd', function () {
    gelijk(null, Validatie::normaliseerBestanden(['name' => 'a.pdf', 'tmp_name' => '/tmp/x', 'size' => 1, 'error' => 0]));
    gelijk(null, Validatie::normaliseerBestanden(['name' => [['a.pdf']], 'tmp_name' => [['x']], 'size' => [[1]], 'error' => [[0]]]));
    gelijk(null, Validatie::normaliseerBestanden(['name' => ['a.pdf']]));
    gelijk([], Validatie::normaliseerBestanden(['name' => [''], 'tmp_name' => [''], 'size' => [0], 'error' => [UPLOAD_ERR_NO_FILE]]));
});
$echteUpload = static fn (string $tmp): bool => str_contains($tmp, '/uploads/');
test('geldige PDF, PNG, IFC en CSV worden geaccepteerd met MIME', function () use ($echteUpload) {
    $lijst = [upload('a.pdf', PDF), upload('b.png', PNG), upload('model.ifc', IFC), upload('staat.csv', CSV)];
    [$goed, $fout] = Validatie::bestanden($lijst, $echteUpload);
    gelijk(null, $fout);
    gelijk(['application/pdf', 'image/png', 'text/plain', 'text/plain'], array_column($goed, 'mime'));
});
test('inhoud die niet bij de extensie past wordt geweigerd', function () use ($echteUpload) {
    foreach ([['nep.pdf', "MZ\x90\0\x03 uitvoerbaar"], ['nep.png', PDF], ['nep.csv', "<?php system('id');"], ['nep.csv', "a,b\0c"], ['nep.ifc', 'geen step'], ['nep.xlsx', PDF], ['nep.jpg', PNG]] as [$naam, $inhoud]) {
        [, $fout] = Validatie::bestanden([upload($naam, $inhoud)], $echteUpload);
        gelijk('bestandstype', $fout, $naam);
    }
});
test('limieten: 11 bestanden, >20 MB per bestand, >50 MB totaal', function () use ($echteUpload) {
    $elf = array_map(static fn ($i) => upload("d{$i}.pdf", PDF), range(1, 11));
    gelijk('te_veel_bestanden', Validatie::bestanden($elf, $echteUpload)[1]);
    gelijk('bestand_te_groot', Validatie::bestanden([upload('groot.pdf', PDF, Validatie::MAX_BESTAND_BYTES + 1)], $echteUpload)[1]);
    $drie = array_map(static fn ($i) => upload("t{$i}.pdf", PDF, 18 * 1024 * 1024), range(1, 3));
    gelijk('totaal_te_groot', Validatie::bestanden($drie, $echteUpload)[1]);
    gelijk(null, Validatie::bestanden([upload('precies.pdf', PDF, Validatie::MAX_BESTAND_BYTES)], $echteUpload)[1]);
});
test('onvolledige upload, PHP-groottefout en niet-geüploade bestanden geweigerd', function () use ($echteUpload) {
    $b = upload('a.pdf', PDF);
    gelijk('upload_onvolledig', Validatie::bestanden([['size' => 999] + $b], $echteUpload)[1]);
    gelijk('bestand_te_groot', Validatie::bestanden([['error' => UPLOAD_ERR_INI_SIZE] + $b], $echteUpload)[1]);
    gelijk('upload_onvolledig', Validatie::bestanden([['error' => UPLOAD_ERR_PARTIAL] + $b], $echteUpload)[1]);
    gelijk('upload_onvolledig', Validatie::bestanden([$b], static fn (): bool => false)[1]);
});

echo "Aanvraaglimiet\n";
test('limiet per netwerkadres, e-mail en globaal; afwijzing telt niet mee', function () use ($basis, $NU) {
    $l = new Limiet($basis . '/l1.json', str_repeat('g', 64), ['ip' => [3, 3600], 'email' => [2, 3600], 'globaal' => [5, 3600]]);
    gelijk(true, $l->neem('1.1.1.1', 'a@x.nl', $NU)[0]);
    gelijk(true, $l->neem('1.1.1.1', 'A@X.NL', $NU)[0]);
    gelijk([false, 'email', 3600], $l->neem('2.2.2.2', 'a@x.nl', $NU));
    gelijk(true, $l->neem('1.1.1.1', 'b@x.nl', $NU)[0]);
    gelijk('ip', $l->neem('1.1.1.1', 'c@x.nl', $NU)[1]);
    gelijk(true, $l->neem('3.3.3.3', 'd@x.nl', $NU)[0]);
    gelijk(true, $l->neem('4.4.4.4', 'e@x.nl', $NU)[0]); // globaal 5: afwijzingen hierboven telden niet mee
    gelijk('globaal', $l->neem('5.5.5.5', 'f@x.nl', $NU)[1]);
    gelijk(true, $l->neem('5.5.5.5', 'f@x.nl', $NU + 3600)[0]); // nieuw venster
});
test('tellerbestand bevat geen netwerk- of e-mailadres', function () use ($basis) {
    $inhoud = file_get_contents($basis . '/l1.json');
    zeker(!str_contains($inhoud, '1.1.1.1') && !str_contains($inhoud, '@'), 'klare tekst in tellers');
    gelijk(sprintf('%o', 0600), sprintf('%o', fileperms($basis . '/l1.json') & 0777), 'rechten');
});
test('IPv6 wordt per /64 gegroepeerd; ongeldig adres geweigerd', function () {
    gelijk(Limiet::netwerkBron('2001:db8:1:2::1'), Limiet::netwerkBron('2001:db8:1:2:ffff::9'));
    zeker(Limiet::netwerkBron('2001:db8:1:2::1') !== Limiet::netwerkBron('2001:db8:1:3::1'));
    gelijk(null, Limiet::netwerkBron(''));
    gelijk(null, Limiet::netwerkBron('1.2.3.4, 5.6.7.8'));
});
test('tellerbestand is exclusief vergrendeld tijdens bijwerken', function () use ($basis) {
    $pad = $basis . '/slot.json';
    $a = Bestand::vergrendel($pad);
    $b = fopen($pad, 'c+');
    $vrij = flock($b, LOCK_EX | LOCK_NB);
    Bestand::ontgrendel($a);
    fclose($b);
    gelijk(false, $vrij, 'tweede slot moet geweigerd worden zolang het eerste vastzit');
});
test('beschadigd tellerbestand: verwerker meldt geen succes (fail-closed)', function () use ($TOKEN, $NU) {
    [$v, $mailer, $data] = opzet();
    file_put_contents($data . '/limiet/tellers.json', '{kapot');
    $r = $v->verwerk(server(), geldigePost(), [], $TOKEN, $NU);
    gelijk(500, $r['status']);
    gelijk(false, $r['body']['opgeslagen']);
    gelijk([], aanvraagMappen($data));
    gelijk(0, count($mailer->verstuurd));
});

echo "Verwerking, opslag en mail\n";
test('volledige aanvraag met documenten: opslag buiten webroot, willekeurige namen, eerlijke mailstatus', function () use ($TOKEN, $NU) {
    [$v, $mailer, $data] = opzet();
    $files = filesVeld([upload('Tekening A.pdf', PDF), upload('staat.csv', CSV)]);
    $r = $v->verwerk(server(), geldigePost(), $files, $TOKEN, $NU);
    gelijk(201, $r['status']);
    gelijk(true, $r['body']['opgeslagen']);
    zeker(Opslag::isReferentie($r['body']['referentie']), 'referentieformaat');
    gelijk(2, $r['body']['documenten']);
    gelijk(['intern' => 'geaccepteerd', 'bevestiging' => 'geaccepteerd'], $r['body']['mail']);
    $map = $data . '/aanvragen/' . $r['body']['referentie'];
    $json = json_decode(file_get_contents($map . '/aanvraag.json'), true);
    gelijk('Jan Tester', $json['velden']['naam']);
    foreach ($json['documenten'] as $d) {
        zeker(preg_match('/^[0-9a-f]{32}\.(pdf|csv)$/', $d['opslagnaam']) === 1, 'willekeurige opslagnaam');
        gelijk(sprintf('%o', 0600), sprintf('%o', fileperms($map . '/documenten/' . $d['opslagnaam']) & 0777), 'documentrechten');
    }
    gelijk('Tekening A.pdf', $json['documenten'][0]['oorspronkelijke_naam']);
    gelijk(sprintf('%o', 0700), sprintf('%o', fileperms($map) & 0777), 'maprechten');
    $status = json_decode(file_get_contents($map . '/mailstatus.json'), true);
    gelijk('geaccepteerd', $status['mail_intern']);
});
test('interne mail: van info@, naar calculatie@, Reply-To aanvrager, locatie zonder link', function () use ($TOKEN, $NU) {
    [$v, $mailer] = opzet();
    $r = $v->verwerk(server(), geldigePost(), filesVeld([upload('a.pdf', PDF)]), $TOKEN, $NU);
    $intern = $mailer->verstuurd[0];
    gelijk('calculatie@calcburo.nl', $intern['aan']);
    gelijk('CalcBuro <info@calcburo.nl>', $intern['headers']['From']);
    gelijk('jan@voorbeeld.nl', $intern['headers']['Reply-To']);
    zeker(str_contains($intern['onderwerp'], $r['body']['referentie']));
    zeker(str_contains($intern['tekst'], '/aanvragen/' . $r['body']['referentie'] . '/'), 'locatie in mail');
    zeker(!preg_match('#https?://#', $intern['tekst']), 'geen links in interne mail');
    zeker(str_contains($intern['tekst'], 'Twaalf grondgebonden woningen'));
});
test('bevestiging aan aanvrager bevat alleen referentie en generieke tekst', function () use ($TOKEN, $NU) {
    [$v, $mailer] = opzet();
    $post = geldigePost(['naam' => 'Spam <b>Klik hier</b>', 'bedrijf' => 'http://kwaadaardig.example', 'project' => 'Win een prijs op http://phish.example']);
    $r = $v->verwerk(server(), $post, [], $TOKEN, $NU);
    $bev = $mailer->verstuurd[1];
    gelijk('jan@voorbeeld.nl', $bev['aan']);
    gelijk('calculatie@calcburo.nl', $bev['headers']['Reply-To']);
    zeker(str_contains($bev['tekst'], $r['body']['referentie']));
    foreach (['Spam', 'Klik', 'kwaadaardig', 'phish', 'Utrecht', '15 december', 'Jan'] as $invoer) {
        zeker(!str_contains($bev['tekst'] . $bev['onderwerp'], $invoer), "gebruikersinvoer '{$invoer}' in bevestiging");
    }
});
test('dubbele verzending (zelfde aanvraag-ID) geeft zelfde referentie, geen tweede opslag of mail', function () use ($TOKEN, $NU) {
    [$v, $mailer, $data] = opzet();
    $post = geldigePost();
    $a = $v->verwerk(server(), $post, [], $TOKEN, $NU);
    $b = $v->verwerk(server(), $post, [], $TOKEN, $NU + 5);
    gelijk(200, $b['status']);
    gelijk(true, $b['body']['duplicaat']);
    gelijk($a['body']['referentie'], $b['body']['referentie']);
    gelijk(1, count(aanvraagMappen($data)));
    gelijk(2, count($mailer->verstuurd));
});
test('zelfde aanvraag-ID met ander e-mailadres: 409, niets opgeslagen', function () use ($TOKEN, $NU) {
    [$v, , $data] = opzet();
    $post = geldigePost();
    $v->verwerk(server(), $post, [], $TOKEN, $NU);
    $r = $v->verwerk(server(), ['email' => 'ander@voorbeeld.nl'] + $post, [], $TOKEN, $NU);
    gelijk(409, $r['status']);
    gelijk(1, count(aanvraagMappen($data)));
});
test('geweigerde poging (limiet/opslagfout) laat geen idempotentiebestand achter', function () use ($TOKEN, $NU) {
    [$v, , $data] = opzet(['limieten' => ['email' => [1, 3600]]]);
    $v->verwerk(server(), geldigePost(), [], $TOKEN, $NU);
    for ($i = 0; $i < 3; $i++) {
        gelijk(429, $v->verwerk(server(), geldigePost(), [], $TOKEN, $NU)['status']);
    }
    gelijk(1, count(glob($data . '/idempotentie/*.json')), 'alleen het geslaagde record');
});
test('ongeldig aanvraag-ID geweigerd', function () use ($TOKEN, $NU) {
    [$v] = opzet();
    gelijk(400, $v->verwerk(server(), geldigePost(['aanvraag_id' => '../../x']), [], $TOKEN, $NU)['status']);
});
test('honeypot: geen opslag, geen mail, geen succes', function () use ($TOKEN, $NU) {
    [$v, $mailer, $data] = opzet();
    $r = $v->verwerk(server(), geldigePost(['website' => 'http://spam']), [], $TOKEN, $NU);
    gelijk(400, $r['status']);
    gelijk(false, $r['body']['ok']);
    gelijk([], aanvraagMappen($data));
    gelijk(0, count($mailer->verstuurd));
});
test('CSRF-token ontbreekt, is fout of sessie ontbreekt: 403', function () use ($TOKEN, $NU) {
    [$v] = opzet();
    gelijk(403, $v->verwerk(server(), geldigePost(['csrf' => str_repeat('b', 64)]), [], $TOKEN, $NU)['status']);
    gelijk(403, $v->verwerk(server(), geldigePost(['csrf' => null]), [], $TOKEN, $NU)['status']);
    gelijk(403, $v->verwerk(server(), geldigePost(), [], null, $NU)['status']);
    gelijk('sessie', $v->verwerk(server(), geldigePost(), [], null, $NU)['body']['fout']['code']);
});
test('herkomst: vreemde Origin 403; zonder Origin alleen Sec-Fetch-Site same-origin', function () use ($TOKEN, $NU) {
    [$v] = opzet();
    gelijk(403, $v->verwerk(server(['HTTP_ORIGIN' => 'https://evil.example']), geldigePost(), [], $TOKEN, $NU)['status']);
    gelijk(403, $v->verwerk(server(['HTTP_ORIGIN' => 'null']), geldigePost(), [], $TOKEN, $NU)['status']);
    $zonder = server();
    unset($zonder['HTTP_ORIGIN']);
    gelijk(403, $v->verwerk($zonder, geldigePost(), [], $TOKEN, $NU)['status']);
    gelijk(201, $v->verwerk($zonder + ['HTTP_SEC_FETCH_SITE' => 'same-origin'], geldigePost(), [], $TOKEN, $NU)['status']);
});
test('modus uit: 503; GET: 405 met Allow', function () use ($TOKEN, $NU) {
    [$v] = opzet(['modus' => 'uit']);
    gelijk(503, $v->verwerk(server(), geldigePost(), [], $TOKEN, $NU)['status']);
    [$v] = opzet();
    $r = $v->verwerk(server(['REQUEST_METHOD' => 'GET']), [], [], $TOKEN, $NU);
    gelijk([405, 'POST'], [$r['status'], $r['headers']['Allow']]);
});
test('testmodus: zonder geldige testsleutel 503, met sleutel opgeslagen en [TEST] in onderwerp', function () use ($TOKEN, $NU) {
    $sleutel = str_repeat('t', 32);
    [$v, $mailer, $data] = opzet(['modus' => 'test', 'test_sleutel' => $sleutel]);
    gelijk(503, $v->verwerk(server(), geldigePost(['test_sleutel' => 'fout']), [], $TOKEN, $NU)['status']);
    gelijk([], aanvraagMappen($data));
    $r = $v->verwerk(server(), geldigePost(['test_sleutel' => $sleutel]), [], $TOKEN, $NU);
    gelijk(201, $r['status']);
    zeker(str_starts_with($mailer->verstuurd[0]['onderwerp'], '[TEST] '));
});
test('opslagfout: 500, geen halve map, geen mail; herhaalpoging met zelfde ID slaagt daarna', function () use ($TOKEN, $NU) {
    $kapot = true;
    [$v, $mailer, $data] = opzet([], static function (string $van, string $naar) use (&$kapot): bool {
        return $kapot ? false : rename($van, $naar);
    });
    $post = geldigePost();
    $r = $v->verwerk(server(), $post, filesVeld([upload('a.pdf', PDF)]), $TOKEN, $NU);
    gelijk(500, $r['status']);
    gelijk(false, $r['body']['opgeslagen']);
    gelijk([], aanvraagMappen($data));
    gelijk(0, count($mailer->verstuurd));
    $kapot = false;
    $r = $v->verwerk(server(), $post, filesVeld([upload('a.pdf', PDF)]), $TOKEN, $NU);
    gelijk(201, $r['status']);
});
test('mail geweigerd door mailserver: aanvraag wel opgeslagen, status mislukt eerlijk gemeld', function () use ($TOKEN, $NU) {
    [$v, $mailer, $data] = opzet();
    $mailer->accepteer = false;
    $r = $v->verwerk(server(), geldigePost(), [], $TOKEN, $NU);
    gelijk(201, $r['status']);
    gelijk(['intern' => 'mislukt', 'bevestiging' => 'mislukt'], $r['body']['mail']);
    $status = json_decode(file_get_contents($data . '/aanvragen/' . $r['body']['referentie'] . '/mailstatus.json'), true);
    gelijk('mislukt', $status['mail_bevestiging']);
    zeker(str_contains(Weergave::mailTekst('mislukt', 'mislukt'), 'calculatie@calcburo.nl'));
});
test('mail uitgeschakeld: niets verstuurd, status uitgeschakeld', function () use ($TOKEN, $NU) {
    [$v, $mailer] = opzet(['mail' => ['modus' => 'uit']]);
    $r = $v->verwerk(server(), geldigePost(), [], $TOKEN, $NU);
    gelijk(['intern' => 'uitgeschakeld', 'bevestiging' => 'uitgeschakeld'], $r['body']['mail']);
    gelijk(0, count($mailer->verstuurd));
});
test('post_max_size overschreden (lege POST met inhoud): 413', function () use ($TOKEN, $NU) {
    [$v] = opzet();
    gelijk(413, $v->verwerk(server(['CONTENT_LENGTH' => '60000000']), [], [], $TOKEN, $NU)['status']);
});
test('misvormde of extra bestandsvelden: 400', function () use ($TOKEN, $NU) {
    [$v] = opzet();
    $b = upload('a.pdf', PDF);
    gelijk(400, $v->verwerk(server(), geldigePost(), ['documenten' => $b], $TOKEN, $NU)['status']);
    gelijk(400, $v->verwerk(server(), geldigePost(), filesVeld([$b]) + ['ander' => $b], $TOKEN, $NU)['status']);
});
test('limiet bereikt: 429 met Retry-After, niets opgeslagen', function () use ($TOKEN, $NU) {
    [$v, , $data] = opzet(['limieten' => ['email' => [1, 3600]]]);
    gelijk(201, $v->verwerk(server(), geldigePost(), [], $TOKEN, $NU)['status']);
    $r = $v->verwerk(server(), geldigePost(), [], $TOKEN, $NU);
    gelijk(429, $r['status']);
    zeker((int) $r['headers']['Retry-After'] > 0);
    gelijk(1, count(aanvraagMappen($data)));
});
test('logregels bevatten geen persoonsgegevens', function () {
    $alles = implode("\n", Log::$opgevangen);
    zeker($alles !== '', 'er is gelogd');
    foreach (['jan@voorbeeld.nl', 'Jan Tester', '203.0.113.7', 'Tekening'] as $pii) {
        zeker(!str_contains($alles, $pii), "PII '{$pii}' in log");
    }
});

echo "Idempotentie op inhoud, capaciteit en herstel\n";
test('zelfde aanvraag-ID: identieke inhoud = duplicaat; gewijzigd project, document of extra document = 409', function () use ($TOKEN, $NU) {
    [$v, $mailer, $data] = opzet();
    $post = geldigePost();
    $a = $v->verwerk(server(), $post, filesVeld([upload('a.pdf', PDF)]), $TOKEN, $NU);
    gelijk(201, $a['status']);
    $dup = $v->verwerk(server(), $post, filesVeld([upload('a.pdf', PDF)]), $TOKEN, $NU);
    gelijk([200, true, $a['body']['referentie']], [$dup['status'], $dup['body']['duplicaat'], $dup['body']['referentie']], 'identiek');
    gelijk(409, $v->verwerk(server(), ['project' => 'Ander project'] + $post, filesVeld([upload('a.pdf', PDF)]), $TOKEN, $NU)['status'], 'project');
    gelijk(409, $v->verwerk(server(), $post, filesVeld([upload('a.pdf', PDF . '% andere versie')]), $TOKEN, $NU)['status'], 'documentinhoud');
    gelijk(409, $v->verwerk(server(), $post, filesVeld([upload('a.pdf', PDF), upload('b.png', PNG)]), $TOKEN, $NU)['status'], 'extra document');
    gelijk(409, $v->verwerk(server(), $post, [], $TOKEN, $NU)['status'], 'document weggelaten');
    gelijk(1, count(aanvraagMappen($data)));
    gelijk(2, count($mailer->verstuurd), 'geen extra mails');
});

/** Vult de opslag tot vlak onder de limiet met een ijl bestand (neemt geen echte schijfruimte). */
function vulOpslag(string $data, int $bytes): string
{
    $map = $data . '/aanvragen/CB-ZZZZZZZZ/documenten';
    Bestand::privateMap($map);
    file_put_contents($data . '/aanvragen/CB-ZZZZZZZZ/aanvraag.json', json_encode(['aangemaakt_unix' => 1]));
    $h = fopen($map . '/vol.pdf', 'c');
    ftruncate($h, $bytes);
    fclose($h);
    return $map . '/vol.pdf';
}
test('opslaglimiet: duidelijke tijdelijke fout, niets opgeslagen of verwijderd, eigenaar eenmalig gewaarschuwd, herstelbaar', function () use ($TOKEN, $NU) {
    $max = 64 * 1024 * 1024;
    [$v, $mailer, $data] = opzet(['opslag_max_bytes' => $max]);
    $vol = vulOpslag($data, $max - Opslag::OVERHEAD_BYTES - 10);
    $post = geldigePost();
    $r = $v->verwerk(server(), $post, filesVeld([upload('a.pdf', PDF)]), $TOKEN, $NU);
    gelijk([503, false, 'capaciteit'], [$r['status'], $r['body']['opgeslagen'], $r['body']['fout']['code']]);
    zeker(str_contains($r['body']['fout']['bericht'], 'Er is niets opgeslagen'), 'eerlijke melding');
    gelijk(['CB-ZZZZZZZZ'], aanvraagMappen($data), 'geen nieuwe of halve map');
    gelijk($max - Opslag::OVERHEAD_BYTES - 10, filesize($vol), 'bestaand bestand onaangeroerd');
    gelijk(1, count($mailer->verstuurd), 'waarschuwing aan eigenaar');
    gelijk('calculatie@calcburo.nl', $mailer->verstuurd[0]['aan']);
    zeker(!str_contains($mailer->verstuurd[0]['tekst'], 'jan@voorbeeld.nl'), 'waarschuwing zonder persoonsgegevens');
    gelijk(503, $v->verwerk(server(), geldigePost(), [], $TOKEN, $NU + 60)['status']);
    gelijk(1, count($mailer->verstuurd), 'niet opnieuw gewaarschuwd binnen 6 uur');
    // Eigenaar maakt ruimte; dezelfde verzending (zelfde ID) slaagt nu, ook met gecorrigeerde tekst.
    unlink($vol);
    $r = $v->verwerk(server(), ['project' => 'Aangevulde omschrijving'] + $post, filesVeld([upload('a.pdf', PDF)]), $TOKEN, $NU + 120);
    gelijk([201, true], [$r['status'], $r['body']['opgeslagen']]);
});
test('opslaglimiet telt documenten van gelijktijdige aanvragen mee (meten en opslaan onder één slot)', function () use ($TOKEN, $NU, $basis) {
    $data = $basis . '/cap-slot';
    Bestand::privateMap($data);
    $slot = Bestand::vergrendel($data . '/opslag.lock');
    $b = fopen($data . '/opslag.lock', 'c+');
    $vrij = flock($b, LOCK_EX | LOCK_NB);
    Bestand::ontgrendel($slot);
    fclose($b);
    gelijk(false, $vrij, 'opslagslot is exclusief');
    gelijk(1073741824, Config::standaard(APP_DIR)['opslag_max_bytes']);
    gelijk('uit', Config::samenvoegen(APP_DIR, ['modus' => 'live', 'opslag_max_bytes' => 1000])['modus'], 'te kleine limiet valt uit');
});
test('schrijffout bij reservering: 500 en niets opgeslagen; herhaalpoging slaagt', function () use ($TOKEN, $NU) {
    [$v, $mailer, $data] = opzet([], null, [1]);
    $post = geldigePost();
    $r = $v->verwerk(server(), $post, [], $TOKEN, $NU);
    gelijk([500, false], [$r['status'], $r['body']['opgeslagen']]);
    gelijk([], aanvraagMappen($data));
    gelijk(0, count($mailer->verstuurd));
    [$v2] = opzet([], null, [], $data);
    gelijk(201, $v2->verwerk(server(), $post, [], $TOKEN, $NU)['status']);
});
test('schrijffout ná opslag (record en mailstatus-record): toch opgeslagen=true; herhaling = zelfde referentie, geen dubbele mail', function () use ($TOKEN, $NU) {
    [$v, $mailer, $data] = opzet([], null, [2, 3]);
    $post = geldigePost();
    $r = $v->verwerk(server(), $post, filesVeld([upload('a.pdf', PDF)]), $TOKEN, $NU);
    gelijk([201, true], [$r['status'], $r['body']['opgeslagen']], 'opslag gelukt ondanks recordfout');
    gelijk(2, count($mailer->verstuurd));
    [$v2, $mailer2] = opzet([], null, [], $data);
    $herhaal = $v2->verwerk(server(), $post, filesVeld([upload('a.pdf', PDF)]), $TOKEN, $NU + 5);
    gelijk([200, true, $r['body']['referentie']], [$herhaal['status'], $herhaal['body']['duplicaat'], $herhaal['body']['referentie']]);
    gelijk(1, count(aanvraagMappen($data)), 'geen tweede aanvraag');
    gelijk(0, count($mailer2->verstuurd), 'mails niet opnieuw verstuurd');
    gelijk('geaccepteerd', $herhaal['body']['mail']['bevestiging']);
});
test('schrijffout op elk afzonderlijk record-moment: nooit opgeslagen=false terwijl de aanvraag bestaat', function () use ($TOKEN, $NU) {
    foreach ([2, 3] as $moment) {
        [$v, , $data] = opzet([], null, [$moment]);
        $r = $v->verwerk(server(), geldigePost(), [], $TOKEN, $NU);
        gelijk(count(aanvraagMappen($data)) === 1, $r['body']['opgeslagen'], "moment {$moment}");
    }
});
test('crash tijdens opslag (halve map, record bezig): herhaling slaat op onder dezelfde referentie', function () use ($TOKEN, $NU) {
    [$v, $mailer, $data] = opzet();
    $post = geldigePost();
    $r = $v->verwerk(server(), $post, filesVeld([upload('a.pdf', PDF)]), $TOKEN, $NU);
    $ref = $r['body']['referentie'];
    // Simuleer: proces stierf na het verplaatsen van documenten, vóór aanvraag.json en mails.
    unlink("{$data}/aanvragen/{$ref}/aanvraag.json");
    unlink("{$data}/aanvragen/{$ref}/mailstatus.json");
    $recordPad = $data . '/idempotentie/' . (new Idempotentie('', GEHEIM))->idHash($post['aanvraag_id']) . '.json';
    $record = json_decode(file_get_contents($recordPad), true);
    file_put_contents($recordPad, json_encode(['status' => 'bezig', 'mail_intern' => 'in_behandeling', 'mail_bevestiging' => 'in_behandeling'] + $record));
    $mailer->verstuurd = [];
    $herhaal = $v->verwerk(server(), $post, filesVeld([upload('a.pdf', PDF)]), $TOKEN, $NU + 5);
    gelijk([201, $ref], [$herhaal['status'], $herhaal['body']['referentie']]);
    gelijk([$ref], aanvraagMappen($data));
    gelijk(1, count(glob("{$data}/aanvragen/{$ref}/documenten/*")), 'geen achtergebleven halve documenten');
    gelijk(2, count($mailer->verstuurd), 'mails alsnog verstuurd');
});
test('crash na opslag vóór mail (record bezig, aanvraag compleet): duplicaat en mails precies één keer', function () use ($TOKEN, $NU) {
    [$v, $mailer, $data] = opzet();
    $post = geldigePost();
    $r = $v->verwerk(server(), $post, [], $TOKEN, $NU);
    $ref = $r['body']['referentie'];
    unlink("{$data}/aanvragen/{$ref}/mailstatus.json");
    $recordPad = $data . '/idempotentie/' . (new Idempotentie('', GEHEIM))->idHash($post['aanvraag_id']) . '.json';
    $record = json_decode(file_get_contents($recordPad), true);
    file_put_contents($recordPad, json_encode(['status' => 'bezig', 'mail_intern' => 'in_behandeling', 'mail_bevestiging' => 'in_behandeling'] + $record));
    $mailer->verstuurd = [];
    $h = $v->verwerk(server(), $post, [], $TOKEN, $NU + 5);
    gelijk([200, $ref], [$h['status'], $h['body']['referentie']]);
    gelijk(2, count($mailer->verstuurd));
    $v->verwerk(server(), $post, [], $TOKEN, $NU + 10);
    gelijk(2, count($mailer->verstuurd), 'derde poging mailt niet opnieuw');
});

/** Minimaal ZIP-pakket zonder compressie (zelfde structuur als Office-bestanden). */
function zipBytes(array $bestanden): string
{
    $lokaal = '';
    $cd = '';
    foreach ($bestanden as $naam => $inhoud) {
        $crc = crc32($inhoud);
        $len = strlen($inhoud);
        $offset = strlen($lokaal);
        $lokaal .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $len, $len, strlen($naam), 0) . $naam . $inhoud;
        $cd .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $len, $len, strlen($naam), 0, 0, 0, 0, 0, $offset) . $naam;
    }
    return $lokaal . $cd . pack('VvvvvVVv', 0x06054b50, 0, 0, count($bestanden), count($bestanden), strlen($cd), strlen($lokaal), 0);
}
test('Office-ZIP: geldige XLSX/DOCX geaccepteerd; macro, ontbrekend onderdeel, pad-truc en kapot pakket geweigerd', function () use ($echteUpload) {
    $ct = ['[Content_Types].xml' => '<?xml version="1.0"?><Types/>'];
    $xlsx = $ct + ['xl/workbook.xml' => '<workbook/>', 'xl/worksheets/sheet1.xml' => '<worksheet/>'];
    $docx = $ct + ['word/document.xml' => '<document/>'];
    gelijk(null, Validatie::bestanden([upload('begroting.xlsx', zipBytes($xlsx))], $echteUpload)[1], 'xlsx');
    gelijk(null, Validatie::bestanden([upload('bestek.docx', zipBytes($docx))], $echteUpload)[1], 'docx');
    $fout = [
        'macro.xlsx' => zipBytes($xlsx + ['xl/vbaProject.bin' => 'VBA']),
        'activex.docx' => zipBytes($docx + ['word/activeX/activeX1.bin' => 'x']),
        'docx-als-xlsx.xlsx' => zipBytes($docx),
        'leeg.docx' => zipBytes($ct),
        'pad.xlsx' => zipBytes($xlsx + ['../../evil.php' => '<?php']),
        'absoluut.docx' => zipBytes($docx + ['/etc/x' => 'x']),
        'afgekapt.xlsx' => substr(zipBytes($xlsx), 0, -30),
        'alleen-kop.xlsx' => "PK\x03\x04" . str_repeat("\0", 40),
    ];
    foreach ($fout as $naam => $inhoud) {
        gelijk('bestandstype', Validatie::bestanden([upload($naam, $inhoud)], $echteUpload)[1], $naam);
    }
});

echo "Opschoning en configuratie\n";
test('opschoning: proefdraaien verwijdert niets; uitvoeren alleen oud en zonder BEWAREN; begrensd', function () use ($basis, $NU) {
    $data = $basis . '/opschoon';
    $maak = static function (string $ref, int $tijd) use ($data): void {
        Bestand::privateMap("{$data}/aanvragen/{$ref}/documenten");
        file_put_contents("{$data}/aanvragen/{$ref}/aanvraag.json", json_encode(['aangemaakt_unix' => $tijd]));
        file_put_contents("{$data}/aanvragen/{$ref}/documenten/x.pdf", PDF);
    };
    $oud = $NU - 91 * 86400;
    $maak('CB-AAAAAAAA', $oud);
    $maak('CB-BBBBBBBB', $oud);
    $maak('CB-CCCCCCCC', $NU - 10 * 86400);
    $maak('CB-DDDDDDDD', $oud);
    touch("{$data}/aanvragen/CB-DDDDDDDD/BEWAREN");
    Bestand::privateMap("{$data}/aanvragen/geen-referentie");
    $r = Opschoning::voerUit($data, 90, 7, $NU, false);
    gelijk(['CB-AAAAAAAA', 'CB-BBBBBBBB'], $r['verwijderd']);
    zeker(is_dir("{$data}/aanvragen/CB-AAAAAAAA"), 'proefdraaien mag niets verwijderen');
    $r = Opschoning::voerUit($data, 90, 7, $NU, true, 1);
    gelijk(['CB-AAAAAAAA'], $r['verwijderd']);
    $r = Opschoning::voerUit($data, 90, 7, $NU, true);
    gelijk(['CB-BBBBBBBB'], $r['verwijderd']);
    gelijk(['CB-DDDDDDDD'], $r['bewaard']);
    zeker(is_dir("{$data}/aanvragen/CB-CCCCCCCC") && is_dir("{$data}/aanvragen/CB-DDDDDDDD") && is_dir("{$data}/aanvragen/geen-referentie"));
});
test('ongeldige config valt terug op modus uit', function () {
    $c = Config::samenvoegen(APP_DIR, ['modus' => 'live', 'mail' => ['van' => "x@y.nl\r\nBcc: z"]]);
    gelijk('uit', $c['modus']);
    $c = Config::samenvoegen(APP_DIR, ['modus' => 'test', 'test_sleutel' => 'kort']);
    gelijk('uit', $c['modus']);
    $c = Config::samenvoegen(APP_DIR, ['modus' => 'live', 'data_dir' => 'relatief/pad']);
    gelijk('uit', $c['modus']);
});
test('meegeleverde config.php staat uit', function () {
    gelijk('uit', Config::laad(APP_DIR)['modus']);
    gelijk('uit', (require APP_DIR . '/config.php')['mail']['modus']);
});
test('data-map binnen documentroot wordt herkend, ook via .. en symbolische links', function () use ($basis) {
    $vhost = $basis . '/vhost';
    Bestand::privateMap($vhost . '/httpdocs/api');
    Bestand::privateMap($vhost . '/private');
    $root = $vhost . '/httpdocs';
    gelijk(true, Config::buitenDocroot($vhost . '/private/calcburo', $root), 'standaardindeling');
    gelijk(true, Config::buitenDocroot($vhost . '/httpdocs-private/calcburo', $root), 'naam lijkt op docroot');
    gelijk(false, Config::buitenDocroot($root . '/private', $root), 'direct binnen docroot');
    gelijk(false, Config::buitenDocroot($root, $root), 'gelijk aan docroot');
    gelijk(false, Config::buitenDocroot($vhost . '/private/../httpdocs/data', $root), '.. naar binnen');
    symlink($root . '/api', $vhost . '/stiekem');
    gelijk(false, Config::buitenDocroot($vhost . '/stiekem/data', $root), 'symlink naar binnen');
    symlink($root, $vhost . '/root-link');
    gelijk(false, Config::buitenDocroot($root . '/x', $vhost . '/root-link'), 'docroot zelf via symlink');
    gelijk(false, Config::buitenDocroot($vhost . '/private', $vhost . '/bestaat-niet'), 'onbekende docroot: fail-closed');
    gelijk(false, Config::buitenDocroot('relatief/pad', $root));
    gelijk(false, Config::buitenDocroot('/x', '/'));
    gelijk('uit', Config::samenvoegen(APP_DIR, ['modus' => 'live', 'data_dir' => $vhost . '/private/../httpdocs'])['modus'], 'config met .. valt uit');
    gelijk(dirname(APP_DIR) . '/private/calcburo', Config::standaard(APP_DIR)['data_dir']);
});

echo "Weergave\n";
test('pagina uit: geen formulier, wel e-mailroute; aan: token, ID, voorinvulling', function () {
    $uit = Weergave::aanvraagPagina(false, '', '', '', false);
    zeker(!str_contains($uit, '<form') && str_contains($uit, 'mailto:calculatie@calcburo.nl') && str_contains($uit, 'privacy.html'));
    $aan = Weergave::aanvraagPagina(true, str_repeat('c', 64), 'id"><script>', '', false, Weergave::voorkeuze(['rol' => 'aannemer', 'uitwerking' => '"><x']));
    zeker(str_contains($aan, 'value="' . str_repeat('c', 64) . '"'));
    zeker(str_contains($aan, 'id&quot;&gt;&lt;script&gt;') && !str_contains($aan, 'id"><script>'), 'escaping');
    zeker(str_contains($aan, '<option value="aannemer" selected>'));
    zeker(str_contains($aan, 'enctype="multipart/form-data"') && str_contains($aan, 'name="documenten[]"'));
    zeker(str_contains($aan, 'Betaling per factuur'));
});
test('resultaatpagina escapet en meldt geen succes bij fout', function () {
    $fout = Weergave::resultaat(Verwerker::fout(422, 'velden', ['velden' => ['email' => 'ongeldig', '<b>' => 'x']])['body']);
    zeker(str_contains($fout, 'niet verwerkt') && !str_contains($fout, 'is opgeslagen') && !str_contains($fout, '<b>'));
    $ok = Weergave::resultaat(['ok' => true, 'opgeslagen' => true, 'referentie' => 'CB-ABCDEFGH', 'documenten' => 1, 'mail' => ['intern' => 'geaccepteerd', 'bevestiging' => 'geaccepteerd']]);
    zeker(str_contains($ok, 'CB-ABCDEFGH') && str_contains($ok, 'Uw aanvraag is ontvangen') && str_contains($ok, 'De ontvangstbevestiging is verstuurd'));
    foreach (['mailserver', 'garanderen', 'opgeslagen', 'afgerekend', 'Jortt'] as $woord) {
        zeker(!str_contains($ok, $woord), "intern woord '{$woord}' op publieke resultaatpagina");
    }
});

echo "\n{$geslaagd} geslaagd, " . count($mislukt) . " mislukt\n";
exit($mislukt === [] ? 0 : 1);
