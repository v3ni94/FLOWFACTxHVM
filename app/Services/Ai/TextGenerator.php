<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\TextFeld;
use App\Models\Listing;

/**
 * Erzeugt Textvorschläge für Inseratsfelder aus den strukturierten Objektdaten
 * (ADR-009). Interne Felder gehen nie in den Prompt. Der Aufruf erfolgt nur auf
 * ausdrückliche Anforderung des Benutzers, Ergebnisse werden gespeichert.
 */
interface TextGenerator extends KiFaehig
{
    public function modell(): string;

    /**
     * @param  list<TextFeld>  $felder
     * @return array<string,string> Schlüssel: TextFeld->value, Wert: Vorschlag
     *
     * @throws TextGenerationException
     */
    public function generate(Listing $listing, array $felder): array;
}
