<?php
declare(strict_types=1);

// Aanvraagpagina. De applicatiecode staat buiten de documentroot: <vhost>/calcburo-app.
$calcburoApp = getenv('CALCBURO_APP_DIR') ?: dirname(__DIR__) . '/calcburo-app';
if (!is_file($calcburoApp . '/bootstrap.php')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Online aanvragen is op dit moment niet beschikbaar. Mail uw aanvraag naar calculatie@calcburo.nl.\n";
    exit;
}
require $calcburoApp . '/bootstrap.php';
CalcBuro\Http::pagina($_GET);
