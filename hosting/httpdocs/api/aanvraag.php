<?php
declare(strict_types=1);

// Verwerkt het aanvraagformulier (POST). Code en opslag staan buiten de documentroot.
$calcburoApp = getenv('CALCBURO_APP_DIR') ?: dirname(__DIR__, 2) . '/calcburo-app';
if (!is_file($calcburoApp . '/bootstrap.php')) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"ok":false,"opgeslagen":false,"fout":{"code":"niet_actief","bericht":"Online aanvragen is op dit moment niet beschikbaar. Mail uw aanvraag naar calculatie@calcburo.nl."}}';
    exit;
}
require $calcburoApp . '/bootstrap.php';
CalcBuro\Http::api();
