<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Support;

use App\Enums\AdressFreigabe;
use App\Enums\MerkmalWert;
use App\Models\Listing;

/**
 * Vorschläge für die Überschrift in Schritt 7 (Masterprompt-Abgleich B.1
 * Schritt 7, Masterprompt Abschnitt 15).
 *
 * Baut ausschließlich aus bestätigten Daten: Zimmerzahl (wenn erfasst),
 * Objektart, "mit Balkon" nur wenn das Merkmal balkon ausdrücklich "ja" ist,
 * und den Ort nur, wenn die Adressfreigabe mindestens die Stadt erlaubt (das
 * ist immer der Fall, Straße und Hausnummer werden hier nie verwendet, auch
 * nicht bei vollständiger Freigabe).
 */
final class TitleSuggestions
{
    public const int MAX_LAENGE = 100;

    /**
     * @return list<string>
     */
    public static function fuer(Listing $listing): array
    {
        $objektart = $listing->objektart->label();
        $ort = trim((string) $listing->ort);
        $hatBalkon = $listing->merkmal('balkon') === MerkmalWert::Ja;
        $zimmer = self::zimmerText($listing);

        // Die Straße wird für Vorschläge grundsätzlich nicht gelesen; die
        // Adressfreigabe entscheidet hier nur, ob der Ort verwendet wird
        // (bei "nur PLZ und Ort" ist der Ort weiterhin öffentlich).
        $ortErlaubt = $listing->adress_freigabe instanceof AdressFreigabe && $ort !== '';

        $basis = [];
        $basis[] = $objektart;

        if ($zimmer !== null) {
            $basis[] = $zimmer.'-Zimmer-'.$objektart;
        }

        if ($hatBalkon) {
            $basis[] = $objektart.' mit Balkon';

            if ($zimmer !== null) {
                $basis[] = $zimmer.'-Zimmer-'.$objektart.' mit Balkon';
            }
        }

        $vorschlaege = [];

        foreach ($basis as $satz) {
            $mitOrt = $ortErlaubt ? $satz.' in '.$ort : $satz;
            $vorschlaege[] = self::kuerzen($mitOrt);

            if ($ortErlaubt) {
                // Auch ohne Ort anbieten, falls der Bearbeiter den Ort selbst
                // ergänzen möchte.
                $vorschlaege[] = self::kuerzen($satz);
            }
        }

        $vorschlaege = array_values(array_unique(array_filter(
            $vorschlaege,
            static fn (string $satz): bool => trim($satz) !== '',
        )));

        return array_slice($vorschlaege, 0, 5);
    }

    private static function zimmerText(Listing $listing): ?string
    {
        if ($listing->zimmer === null) {
            return null;
        }

        $zimmer = (float) $listing->zimmer;
        $formatiert = number_format($zimmer, 1, ',', '.');

        return rtrim(rtrim($formatiert, '0'), ',');
    }

    private static function kuerzen(string $text): string
    {
        $text = trim($text);

        return mb_strlen($text) > self::MAX_LAENGE ? rtrim(mb_substr($text, 0, self::MAX_LAENGE)) : $text;
    }
}
