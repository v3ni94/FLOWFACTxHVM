<?php

declare(strict_types=1);

namespace App\Domain\Listing;

/**
 * Hinweise, die der RentCalculator zu einer Warmmietenberechnung ausgibt
 * (Datenvertrag Abschnitt 3).
 */
enum RentHinweis
{
    /**
     * Heizkosten wurden nicht angegeben, Warmmiete enthält daher nur Kaltmiete
     * und Nebenkosten. Muss vor Veröffentlichung bestätigt werden.
     */
    case HeizkostenNichtAngegeben;

    /**
     * Heizkostenversorgung ist dezentral, der Mieter schließt selbst einen
     * Versorgungsvertrag.
     */
    case HeizkostenBeimVersorger;

    public function label(): string
    {
        return match ($this) {
            self::HeizkostenNichtAngegeben => 'Heizkosten nicht angegeben',
            self::HeizkostenBeimVersorger => 'Heizkosten werden direkt mit dem Versorger abgerechnet und sind nicht enthalten.',
        };
    }
}
