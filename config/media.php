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

    /*
    | Pixelgrenzen gegen Dekompressionsbomben (Prüfbericht 2026-09-11,
    | Befund 9): ein kleines Bild kann per IHDR beliebige Abmessungen
    | vortäuschen. GD dekodiert unabhängig vom PHP-Speicherlimit auf
    | Prozessebene, daher wird die Grenze anhand der von getimagesize
    | gelesenen Abmessungen geprüft, bevor irgendein Bild dekodiert wird.
    | Beide Werte gelten sowohl beim Upload (MediaUploadService,
    | ImagePreviewGenerator) als auch bei der Übertragung (ImageResizer).
    */
    'max_pixels' => (int) env('MEDIA_MAX_PIXELS', 40_000_000),

    'max_side' => (int) env('MEDIA_MAX_SIDE', 10_000),

    'allowed_mimes' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ],

];
