<?php
declare(strict_types=1);

/*
 * Opschoning volgens bewaartermijn. Alleen via de commandoregel (Plesk Geplande taken).
 *   php calcburo-app/bin/opschonen.php              proefdraaien: toont wat verwijderd zou worden
 *   php calcburo-app/bin/opschonen.php --uitvoeren  verwijdert echt (maximaal 200 aanvragen per run)
 * Mappen met een bestand BEWAREN worden nooit verwijderd.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

$config = CalcBuro\Config::laad(APP_DIR);
if (isset($config['config_fouten'])) {
    fwrite(STDERR, 'Config ongeldig: ' . implode(', ', $config['config_fouten']) . PHP_EOL);
    exit(2);
}
$uitvoeren = in_array('--uitvoeren', $argv, true);
$r = CalcBuro\Opschoning::voerUit(
    (string) $config['data_dir'],
    (int) $config['bewaartermijn_dagen'],
    (int) $config['idempotentie_dagen'],
    time(),
    $uitvoeren,
);

echo ($uitvoeren ? 'Verwijderd' : 'Proefdraaien, zou verwijderen') . ': ' . count($r['verwijderd'])
    . ($r['verwijderd'] !== [] ? ' (' . implode(', ', $r['verwijderd']) . ')' : '') . PHP_EOL;
echo 'Bewaard (BEWAREN): ' . count($r['bewaard']) . PHP_EOL;
echo 'Overgeslagen: ' . count($r['overgeslagen']) . ($r['overgeslagen'] !== [] ? ' (' . implode(', ', $r['overgeslagen']) . ')' : '') . PHP_EOL;
echo 'Idempotentierecords ' . ($uitvoeren ? 'verwijderd' : 'te verwijderen') . ': ' . $r['idempotentie'] . PHP_EOL;
exit($r['overgeslagen'] === [] ? 0 : 1);
