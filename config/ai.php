<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| KI-Texte (ADR-009)
|------------------------------------------------------------------------------
|
| Der Provider "fake" verhindert jeden externen Aufruf und ist die Vorgabe für
| Tests und lokale Entwicklung ohne Schlüssel. Das Modell ist im Adminbereich
| änderbar (Datenvertrag Abschnitt 2.11, Schlüssel ki.provider, ki.modell).
|
*/

return [

    'provider' => env('AI_PROVIDER', 'fake'),

    'model' => env('AI_MODEL', ''),

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY', ''),
    ],

];
