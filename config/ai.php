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
| "models" enthält die Listenpreise der unterstützten Anthropic-Modelle in
| US-Dollar je Million Token, für die Kostenschätzung im Adminbereich. Es
| handelt sich um eine statische Näherung, keine abgerechneten Werte.
|
*/

return [

    'provider' => env('AI_PROVIDER', 'fake'),

    'model' => env('AI_MODEL') ?: 'claude-opus-5',

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY', ''),
    ],

    'models' => [
        'claude-opus-5' => [
            'label' => 'Claude Opus 5',
            'preis_input_je_million_usd' => 5.00,
            'preis_output_je_million_usd' => 25.00,
        ],
        'claude-sonnet-5' => [
            'label' => 'Claude Sonnet 5',
            'preis_input_je_million_usd' => 2.00,
            'preis_output_je_million_usd' => 10.00,
        ],
        'claude-haiku-4-5' => [
            'label' => 'Claude Haiku 4.5',
            'preis_input_je_million_usd' => 1.00,
            'preis_output_je_million_usd' => 5.00,
        ],
    ],

];
