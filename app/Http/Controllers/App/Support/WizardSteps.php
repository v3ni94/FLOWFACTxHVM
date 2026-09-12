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
     * Reihenfolge und Titel nach der neuen Schrittfolge
     * (Masterprompt-Abgleich B.1). Der Abschluss "Prüfen und veröffentlichen"
     * ist kein Assistentenschritt mehr, sondern eine eigene Seite
     * (app.listings.review) und wird in der Kopfzeile zusätzlich angezeigt.
     *
     * @var array<int, string>
     */
    public const array TITEL = [
        1 => 'Vermietung oder Verkauf',
        2 => 'Adresse und Lage',
        3 => 'Flächen und Objektdaten',
        4 => 'Preise und Heizung',
        5 => 'Ausstattung und Energieausweis',
        6 => 'Bilder und Unterlagen',
        7 => 'Überschrift und interne Bezeichnung',
        8 => 'Beschreibungen',
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
