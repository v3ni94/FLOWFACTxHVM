<?php

declare(strict_types=1);

namespace App\Domain\Listing;

use App\Enums\HeizkostenVersorgung;

/**
 * Berechnet die Warmmiete serverseitig und deterministisch
 * (Datenvertrag Abschnitt 3, ADR-010). Die Warmmiete wird bei jedem
 * Speichern neu berechnet und nie aus dem Formular übernommen.
 */
final class RentCalculator
{
    /**
     * @throws InvalidRentInputException bei ungültigen Eingabekombinationen
     */
    public function calculate(
        int $kaltmieteCent,
        int $nebenkostenCent,
        ?int $heizkostenCent,
        bool $heizkostenInNebenkostenEnthalten,
        HeizkostenVersorgung $versorgung,
    ): RentResult {
        $this->pruefeNichtNegativ($kaltmieteCent, $nebenkostenCent, $heizkostenCent);

        if ($versorgung === HeizkostenVersorgung::Dezentral) {
            if ($heizkostenCent !== null && $heizkostenCent > 0) {
                throw new InvalidRentInputException(
                    'Bei dezentraler Heizkostenversorgung dürfen keine Heizkosten erfasst werden. Der Mieter schließt selbst einen Versorgungsvertrag.'
                );
            }

            return new RentResult(
                warmmieteCent: $kaltmieteCent + $nebenkostenCent,
                hinweise: [RentHinweis::HeizkostenBeimVersorger],
            );
        }

        if ($heizkostenInNebenkostenEnthalten) {
            if ($heizkostenCent !== null && $heizkostenCent > $nebenkostenCent) {
                throw new InvalidRentInputException(
                    'Die erfassten Heizkosten dürfen nicht größer als die Nebenkosten sein, wenn die Heizkosten bereits in den Nebenkosten enthalten sind.'
                );
            }

            return new RentResult(warmmieteCent: $kaltmieteCent + $nebenkostenCent);
        }

        if ($heizkostenCent === null) {
            return new RentResult(
                warmmieteCent: $kaltmieteCent + $nebenkostenCent,
                hinweise: [RentHinweis::HeizkostenNichtAngegeben],
            );
        }

        return new RentResult(warmmieteCent: $kaltmieteCent + $nebenkostenCent + $heizkostenCent);
    }

    private function pruefeNichtNegativ(int $kaltmieteCent, int $nebenkostenCent, ?int $heizkostenCent): void
    {
        if ($kaltmieteCent < 0 || $nebenkostenCent < 0 || ($heizkostenCent !== null && $heizkostenCent < 0)) {
            throw new InvalidRentInputException('Beträge zur Mietberechnung dürfen nicht negativ sein.');
        }
    }
}
