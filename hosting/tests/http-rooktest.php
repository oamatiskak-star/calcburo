<?php
declare(strict_types=1);

/*
 * Scenario's voor de rooktest van de entrypoints in httpdocs met de echte bootstrap/config-keten
 * (CLI, geen webserver). Aangestuurd door tests/http-rooktest.mjs: elk scenario draait in een eigen
 * PHP-proces zodat sessie- en headerstatus niet lekken.
 */

$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'scenario') {
    [$scenario, $configPad] = [$argv[2], $argv[3]];
    putenv('CALCBURO_CONFIG=' . $configPad);
    $_SERVER['DOCUMENT_ROOT'] = $root . '/httpdocs';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $_GET = $_POST = $_FILES = [];
    switch ($scenario) {
        case 'pagina':
            $_SERVER['REQUEST_METHOD'] = 'GET';
            require $root . '/httpdocs/aanvraag.php';
            break;
        case 'pagina-test':
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_GET = ['test' => str_repeat('s', 32), 'uitwerking' => 'hoeveelhedenstaat'];
            require $root . '/httpdocs/aanvraag.php';
            break;
        case 'api-get':
            $_SERVER['REQUEST_METHOD'] = 'GET';
            require $root . '/httpdocs/api/aanvraag.php';
            break;
        case 'api-post':
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['HTTP_ORIGIN'] = 'https://calcburo.nl';
            $_SERVER['REMOTE_ADDR'] = '198.51.100.1';
            $_POST = ['csrf' => 'zonder-sessie', 'naam' => 'x', 'email' => 'x@y.nl', 'project' => 'p'];
            require $root . '/httpdocs/api/aanvraag.php';
            break;
    }
    exit(0);
}

fwrite(STDERR, "Gebruik: node --test tests/http-rooktest.test.mjs (stuurt de scenario's aan)\n");
exit(2);
