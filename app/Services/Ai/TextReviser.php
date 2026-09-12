<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Listing;

/**
 * Gezielte Überarbeitung eines vorhandenen Textes (Masterprompt Abschnitt 16):
 * kürzer, sachlicher, sprachlich verbessern. Inhaltliche Aussagen dürfen nicht
 * hinzukommen; der Aufruf erfolgt nur auf Anforderung des Benutzers.
 */
interface TextReviser extends KiFaehig
{
    public const string KUERZER = 'kuerzer';

    public const string SACHLICHER = 'sachlicher';

    public const string SPRACHLICH = 'sprachlich';

    public function modell(): string;

    /**
     * @param  self::KUERZER|self::SACHLICHER|self::SPRACHLICH  $anweisung
     *
     * @throws TextGenerationException
     */
    public function revise(Listing $listing, string $text, string $anweisung): string;
}
