<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Support;

use App\Domain\Settings\SettingsRepository;
use App\Models\Listing;

/**
 * Vorschlag für die interne Bezeichnung nach dem im Adminbereich hinterlegten
 * Muster (Masterprompt-Abgleich B.1 Schritt 7, Masterprompt Abschnitt 15).
 *
 * Platzhalter: [Objektnummer], [Straße], [Hausnummer], [Einheit], [Etage],
 * [Ort]. Fehlende Werte werden durch einen leeren Text ersetzt, überflüssige
 * Trenner (" | ") an den Rändern und doppelte Trenner werden entfernt.
 */
final class InternalNamePattern
{
    public const string EINSTELLUNGSSCHLUESSEL = 'defaults.interne_bezeichnung_muster';

    public const string STANDARD_MUSTER = '[Objektnummer] | [Straße] | [Einheit] | [Etage]';

    public function __construct(
        private readonly SettingsRepository $settings = new SettingsRepository,
    ) {}

    public function muster(): string
    {
        $wert = $this->settings->get(self::EINSTELLUNGSSCHLUESSEL);

        return is_string($wert) && trim($wert) !== '' ? $wert : self::STANDARD_MUSTER;
    }

    public function vorschlag(Listing $listing): string
    {
        return self::anwenden($this->muster(), $listing);
    }

    public static function anwenden(string $muster, Listing $listing): string
    {
        $werte = [
            '[Objektnummer]' => (string) $listing->objektnummer,
            '[Straße]' => (string) ($listing->strasse ?? ''),
            '[Hausnummer]' => (string) ($listing->hausnummer ?? ''),
            '[Einheit]' => (string) ($listing->internal?->einheitsnummer ?? ''),
            '[Etage]' => $listing->etage !== null ? (string) $listing->etage : '',
            '[Ort]' => (string) ($listing->ort ?? ''),
        ];

        $ersetzt = strtr($muster, $werte);

        return self::bereinigen($ersetzt);
    }

    /**
     * @return list<string>
     */
    public static function platzhalter(): array
    {
        return ['[Objektnummer]', '[Straße]', '[Hausnummer]', '[Einheit]', '[Etage]', '[Ort]'];
    }

    /**
     * Entfernt leere Segmente zwischen "|"-Trennern sowie Trenner am Rand,
     * damit fehlende Angaben keine doppelten Trennzeichen hinterlassen.
     */
    private static function bereinigen(string $text): string
    {
        $segmente = array_map(trim(...), explode('|', $text));
        $segmente = array_values(array_filter($segmente, static fn (string $segment): bool => $segment !== ''));

        return implode(' | ', $segmente);
    }
}
