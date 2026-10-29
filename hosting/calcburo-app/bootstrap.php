<?php
declare(strict_types=1);

/*
 * CalcBuro aanvraagflow — staat BUITEN de documentroot (naast httpdocs).
 * Geen externe afhankelijkheden; alleen PHP 8.0+ met fileinfo.
 */

if (PHP_VERSION_ID < 80000) {
    http_response_code(503);
    exit;
}

const APP_DIR = __DIR__;

foreach (['Config', 'Validatie', 'Bestand', 'Log', 'Limiet', 'Idempotentie', 'Opslag', 'Mail', 'Verwerker', 'Opschoning', 'Weergave', 'Http'] as $klasse) {
    require_once __DIR__ . '/src/' . $klasse . '.php';
}
