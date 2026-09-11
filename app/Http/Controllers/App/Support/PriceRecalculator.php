<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Support;

use App\Domain\Listing\RentCalculator;
use App\Enums\HeizkostenVersorgung;
use App\Models\Listing;

/**
 * Hält die gespeicherte Warmmiete konsistent, wenn sich die
 * Heizkostenversorgung außerhalb von Schritt 4 ändert (Prüfbericht
 * 2026-09-11, Befund 2). Schritt 2 speichert `heizkosten_versorgung`
 * unabhängig von Schritt 4; wechselt der Wert auf "dezentral", dürfen keine
 * Heizkosten mehr in der Warmmiete stecken, sonst wirft der RentCalculator
 * beim nächsten Aufruf (z. B. in CompletenessCheck) eine
 * InvalidRentInputException.
 */
final class PriceRecalculator
{
    public function __construct(
        private readonly RentCalculator $rentCalculator = new RentCalculator,
    ) {}

    /**
     * Nullt bei Wechsel auf "dezentral" etwaige Heizkosten und berechnet die
     * Warmmiete neu. Ohne gespeicherte Preise oder außerhalb der Miete ist
     * nichts zu tun.
     */
    public function heizkostenVersorgungGeaendert(Listing $listing, HeizkostenVersorgung $versorgung): void
    {
        if ($versorgung !== HeizkostenVersorgung::Dezentral || ! $listing->istMiete()) {
            return;
        }

        $preis = $listing->price;

        if ($preis === null || $preis->kaltmiete_cent === null || $preis->nebenkosten_cent === null) {
            return;
        }

        if ($preis->heizkosten_cent === null && ! $preis->heizkosten_in_nebenkosten_enthalten) {
            // Bereits konsistent, keine Heizkosten hinterlegt.
            return;
        }

        $ergebnis = $this->rentCalculator->calculate(
            kaltmieteCent: $preis->kaltmiete_cent,
            nebenkostenCent: $preis->nebenkosten_cent,
            heizkostenCent: null,
            heizkostenInNebenkostenEnthalten: false,
            versorgung: $versorgung,
        );

        $preis->heizkosten_cent = null;
        $preis->heizkosten_in_nebenkosten_enthalten = false;
        $preis->warmmiete_cent = $ergebnis->warmmieteCent;
        $preis->save();
    }
}
