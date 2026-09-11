<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| FLOWFACT-Anbindung
|------------------------------------------------------------------------------
|
| ADR-008: Der API-Token wird NICHT hier hinterlegt, sondern verschlüsselt in
| der Tabelle "settings" (Schlüssel flowfact.api_token), ausschließlich über
| den Adminbereich. Diese Datei enthält nur unkritische Betriebsparameter.
|
*/

return [

    // production oder development, siehe Datenvertrag Abschnitt 2.11.
    'stage' => env('FLOWFACT_STAGE', 'production'),

    'base_url' => env('FLOWFACT_BASE_URL', 'https://api.production.cloudios.flowfact-prod.cloud'),

    'timeout' => (int) env('FLOWFACT_TIMEOUT_SECONDS', 20),

];
