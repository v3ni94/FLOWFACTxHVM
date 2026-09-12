<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AdressFreigabe;
use App\Enums\TextFeld;
use App\Models\Listing;

/**
 * Letzte Sicherung gegen eine ausgeblendete Adresse in erzeugten Texten
 * (Masterprompt-Abgleich B.7, Masterprompt Abschnitt 16). PromptBuilder
 * erhält bei Adressfreigabe "nur PLZ und Ort" bereits keine Straße, doch ein
 * KI-Anbieter kann Angaben trotzdem erfinden oder aus Beispielen übernehmen.
 * Enthält ein erzeugter Text dennoch die Straße oder die Hausnummer (jeweils
 * normalisiert verglichen), wird der gesamte Vorschlag verworfen: keiner der
 * angeforderten Texte wird zurückgegeben, statt nur das betroffene Feld
 * stillschweigend zu entfernen.
 */
final class HiddenAddressGuard
{
    /**
     * @param  array<string, string>  $texte  Schlüssel: TextFeld->value
     *
     * @throws TextGenerationException
     */
    public static function pruefe(Listing $listing, array $texte): void
    {
        if ($listing->adress_freigabe !== AdressFreigabe::NurPlzOrt) {
            return;
        }

        $strasse = trim((string) ($listing->strasse ?? ''));
        $hausnummer = trim((string) ($listing->hausnummer ?? ''));

        foreach ($texte as $feldWert => $text) {
            $label = TextFeld::tryFrom((string) $feldWert)?->label() ?? (string) $feldWert;

            if ($strasse !== '' && self::enthaeltNormalisiert($text, $strasse)) {
                throw new TextGenerationException(sprintf(
                    'Die ausgeblendete Straße erscheint im erzeugten Text für "%s". Der Vorschlag wurde verworfen.',
                    $label,
                ));
            }

            if ($hausnummer !== '' && self::enthaeltHausnummer($text, $hausnummer)) {
                throw new TextGenerationException(sprintf(
                    'Die ausgeblendete Hausnummer erscheint im erzeugten Text für "%s". Der Vorschlag wurde verworfen.',
                    $label,
                ));
            }
        }
    }

    private static function enthaeltNormalisiert(string $text, string $wert): bool
    {
        $normalisierterText = self::normalisiere($text);
        $normalisierterWert = self::normalisiere($wert);

        return $normalisierterWert !== '' && str_contains($normalisierterText, $normalisierterWert);
    }

    /**
     * Die Hausnummer wird zusätzlich mit Wortgrenzen verglichen, damit z. B.
     * "12" nicht bereits in "112" oder in einer Postleitzahl anschlägt.
     */
    private static function enthaeltHausnummer(string $text, string $hausnummer): bool
    {
        $normalisierterWert = self::normalisiere($hausnummer);

        if ($normalisierterWert === '') {
            return false;
        }

        $muster = '/(?<![\p{L}\d])'.preg_quote($normalisierterWert, '/').'(?![\p{L}\d])/u';

        return preg_match($muster, self::normalisiere($text)) === 1;
    }

    private static function normalisiere(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace(['-', '.', ','], ' ', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }
}
