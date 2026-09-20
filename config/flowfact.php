<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| FLOWFACT-Anbindung
|------------------------------------------------------------------------------
|
| ADR-008: Der API-Token wird NICHT hier hinterlegt, sondern verschlüsselt in
| der Tabelle "settings" (Schlüssel flowfact.api_token), ausschließlich über
| den Adminbereich. Diese Datei enthält nur unkritische Betriebsparameter
| (docs/connector.md Abschnitt 2, 3 und 5).
|
*/

return [

    // production oder development, siehe Datenvertrag Abschnitt 2.11.
    'stage' => env('FLOWFACT_STAGE', 'production'),

    // Externe Basis-URL ohne Service-Segment (flowfact-api.md Abschnitt 2).
    'base_url' => env('FLOWFACT_BASE_URL', 'https://api.production.cloudios.flowfact-prod.cloud'),

    // Zeitlimit je API-Aufruf in Sekunden.
    'timeout' => (int) env('FLOWFACT_TIMEOUT_SECONDS', 20),

    // Zeitlimit für den Binärupload an die vorsignierte URL.
    'upload_timeout' => (int) env('FLOWFACT_UPLOAD_TIMEOUT_SECONDS', 60),

    // Rückfall-Gültigkeitsdauer des Cognito-Tokens (Sekunden), falls sein
    // exp-Anspruch nicht gelesen werden kann. FLOWFACT nennt "ca. 30 Minuten"
    // (developers.flowfact.com/api); 25 Minuten lassen Sicherheitsabstand.
    'cognito_ttl_fallback_seconds' => (int) env('FLOWFACT_COGNITO_TTL_FALLBACK_SECONDS', 1500),

    // Lease gegen parallele Übertragungen desselben Objekts (Minuten).
    'lease_minutes' => 3,

    // Zeitlimit des synchronen Wegs "Jetzt übertragen" (Sekunden). Bilder,
    // die darüber hinausgehen, laufen als Job weiter.
    'sync_time_limit_seconds' => 25,

    // Kürzung der Nutzdaten im Übertragungsprotokoll (Byte).
    'log_body_limit' => 4096,

    // Bildgröße für die Übertragung (ADR-012).
    'image' => [
        'max_side' => 2000,
        'jpeg_quality' => 85,
    ],

    // Portalstatus (docs/connector.md Abschnitt 5).
    'portal_status' => [
        'angefordert_unbekannt_nach_minuten' => 30,
        'aktiv_pruefintervall_minuten' => 60,
    ],

    // Wiederholungen der Jobs (docs/connector.md Abschnitt 3, Schritt 9).
    'job' => [
        'tries' => 3,
        'backoff' => [30, 120, 300],
    ],

];
