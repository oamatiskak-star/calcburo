<?php
/*
 * CalcBuro aanvraagflow — configuratie (buiten de documentroot).
 *
 * UITGESCHAKELD. Zo opgeleverd: het formulier toont alleen de e-mailroute en de API weigert alles (503).
 *
 * Acceptatietest (zie DEPLOY.md stap 6):
 *   'modus' => 'test', 'test_sleutel' => '<minimaal 24 willekeurige tekens>', mail 'modus' => 'php'
 *   en open https://calcburo.nl/aanvraag.php?test=<test_sleutel>
 * Productie (pas na geslaagde test): 'modus' => 'live' en 'test_sleutel' => ''.
 *
 * Er staan hier geen wachtwoorden of API-sleutels in. De HMAC-sleutel voor limiet en idempotentie
 * wordt bij het eerste gebruik automatisch aangemaakt in <data_dir>/geheim.key (0600).
 */
return [
    'modus' => 'uit',            // 'uit' | 'test' | 'live'
    'test_sleutel' => '',
    'origin' => 'https://calcburo.nl',

    // Private opslag, buiten httpdocs. Standaard: <vhost>/private/calcburo (naast calcburo-app).
    // Alleen aanpassen als de hosting een andere map vereist; moet absoluut zijn en buiten httpdocs liggen.
    // 'data_dir' => '/var/www/vhosts/calcburo.nl/private/calcburo',

    'mail' => [
        'modus' => 'uit',        // 'uit' | 'php' (PHP mail() via de mailserver van de hosting)
        'intern_aan' => 'calculatie@calcburo.nl',
        'van' => 'info@calcburo.nl',
        'van_naam' => 'CalcBuro',
        'envelope_afzender' => true, // -f info@calcburo.nl; op false zetten als de hosting -f weigert
    ],

    // [maximum, venster in seconden]
    'limieten' => [
        'ip' => [10, 3600],
        'email' => [5, 3600],
        'globaal' => [60, 3600],
    ],

    'bewaartermijn_dagen' => 90,
    'idempotentie_dagen' => 7,

    // Maximale opslag voor aanvragen + documenten (bytes). Hosting: 5 GB totaal; 1 GiB laat ruimte voor site,
    // mail en back-ups. Bij overschrijding: nette foutmelding, niets opgeslagen, niets verwijderd.
    'opslag_max_bytes' => 1073741824,
];
