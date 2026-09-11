<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Medien (ADR-012)
|------------------------------------------------------------------------------
|
| Uploads liegen außerhalb des Webroots und werden über signierte, an die
| Sitzung gebundene Routen ausgeliefert. Grenzwerte laut Architektur
| Abschnitt 3 (ADR-012) und Datenvertrag Abschnitt 2.6.
|
*/

return [

    'root' => env('MEDIA_ROOT') ?: storage_path('app/private/media'),

    'max_file_bytes' => 15 * 1024 * 1024,

    'max_files_per_listing' => 40,

    'allowed_mimes' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ],

];
