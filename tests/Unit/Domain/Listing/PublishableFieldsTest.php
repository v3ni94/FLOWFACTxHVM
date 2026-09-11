<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Listing;

use App\Domain\Listing\PublishableFields;
use PHPUnit\Framework\TestCase;

/**
 * Stellt sicher, dass die Positivliste nie interne Felder enthält
 * (Datenvertrag Abschnitt 1.1, ADR-003).
 */
final class PublishableFieldsTest extends TestCase
{
    /**
     * Felder der Tabelle listing_internals (Datenvertrag Abschnitt 2.5), die
     * in keiner Positivliste erscheinen dürfen.
     *
     * @return list<string>
     */
    private function interneFelder(): array
    {
        return [
            'eigentuemer_name',
            'eigentuemer_kontakt',
            'verwaltungsobjekt_referenz',
            'interne_notizen',
            'schluessel_hinweis',
            'besichtigung_intern',
            'kalkulation_notiz',
        ];
    }

    public function test_kein_internes_feld_erscheint_in_der_listing_positivliste(): void
    {
        foreach ($this->interneFelder() as $internesFeld) {
            $this->assertNotContains($internesFeld, PublishableFields::LISTING);
        }
    }

    public function test_kein_internes_feld_erscheint_in_der_preis_positivliste(): void
    {
        foreach ($this->interneFelder() as $internesFeld) {
            $this->assertNotContains($internesFeld, PublishableFields::PRICE);
        }
    }

    public function test_kein_internes_feld_erscheint_in_der_energie_positivliste(): void
    {
        foreach ($this->interneFelder() as $internesFeld) {
            $this->assertNotContains($internesFeld, PublishableFields::ENERGY);
        }
    }
}
