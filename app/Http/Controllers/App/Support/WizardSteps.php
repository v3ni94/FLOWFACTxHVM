<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Support;

/**
 * Feste Reihenfolge und Bezeichnung der Schritte des Erfassungsassistenten
 * (Datenvertrag Abschnitt 5).
 */
final class WizardSteps
{
    /**
     * @var array<int, string>
     */
    public const array TITEL = [
        1 => 'Grunddaten',
        2 => 'Flächen und Ausstattung',
        3 => 'Energieausweis',
        4 => 'Preise',
        5 => 'Bilder',
        6 => 'Texte',
        7 => 'Intern',
        8 => 'Prüfen und Veröffentlichen',
    ];

    public const int ERSTER_SCHRITT = 1;

    public const int LETZTER_SCHRITT = 8;

    public static function istGueltig(int $schritt): bool
    {
        return $schritt >= self::ERSTER_SCHRITT && $schritt <= self::LETZTER_SCHRITT;
    }

    public static function titel(int $schritt): string
    {
        return self::TITEL[$schritt] ?? '';
    }
}
